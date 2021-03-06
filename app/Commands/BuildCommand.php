<?php

namespace App\Commands;

use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class BuildCommand extends Command
{
    protected $signature = 'build {bookdir} {reposdir} {--reponame=} {--drop} {--generate-diffs}';
    protected $description = 'Build a Github repo';
    /**
     * @var array|string|null
     */
    protected $bookDir;
    /**
     * @var array|string|null
     */
    protected $reposDir;
    /**
     * @var mixed
     */
    protected $lastCommitMessage;
    /**
     * @var mixed
     */
    protected $lastTag;
    /**
     * @var bool
     */
    protected $firstRun = true;

    public function handle()
    {
        $this->bookDir = $this->argument('bookdir');
        $this->reposDir = $this->argument('reposdir');

        // Parse through all adoc files
        $adocs = glob($this->bookDir . '/*.adoc');
        $includes = [];
        foreach ($adocs as $adoc) {
            preg_match_all('#(.*)\[(.*bdd-.*)\]#', file_get_contents($adoc), $lines);
            $includes = array_merge($includes, $this->parseBddTags($lines, $adoc));
        }

        foreach ($includes as $identifier => $includeData) {
            $fileName = $includeData['include-path'];
            $repoName = $includeData['bdd-repo'];
            // If reponame is given, only process includes for that repo
            if ($this->option('reponame') !== NULL && $repoName !== $this->option('reponame')) {
                continue;
            }
            // Create a new Git repo if it doesn't exist in the reposdir
            $this->createRepo($repoName);
            $includeData['bdd-action'] = $includeData['bdd-action'] ?? 'add';
            if (isset($includeData['bdd-command'])) {
                // Execute the command
                $this->line('Executing ' . $includeData['bdd-command']);
                $this->executeOnRepo($repoName, $includeData['bdd-command']);
            } elseif (isset($includeData['bdd-filename'])) {
                // Copy the source file to the Git repository directory
                $this->copySourceToRepo($fileName, $repoName, $includeData);
            } else {
                $this->warn('No bdd-filename or bdd-command attribute found; skipping.');
                continue;
            }
            // Add/remove the file to/from the Git repository
            if (stripos($includeData['bdd-filename'], ' ') !== false) {
                $this->executeOnRepo($repoName, 'git' . ' ' . $includeData['bdd-action'] . ' ' . $includeData['bdd-filename']);
            } else {
                $this->executeOnRepo($repoName, ['git', $includeData['bdd-action'], $includeData['bdd-filename']]);
            }
            // Process tags
            $this->processTags($includeData);
            // Process commit message
            $this->processCommitMsg($includeData);
            // Generate diff
            $this->generateDiff($includeData);
            // Wait one second to create unique timestamps
            sleep(1);
        }

        // Process final tag, forcing creation
        if (isset($includeData) && $this->lastTag) {
            $this->processTags($includeData, true);
        }

        return 0;
    }

    /**
     * @param $includes
     * @return array
     *
     * Parse the bdd-* attribute and return an array of all bdd-* attributes
     */
    private function parseBddTags($includes, $adoc)
    {
        $data = [];
        foreach ($includes[1] as $key => $includePath)
        {
            $includePath = Str::after($includePath, 'include::');
            $attributeList = $includes[2][$key];
            $attributes = [];
            $attributeStrings = preg_split('/,(?=([^\"]*\"[^\"]*\")*[^\"]*$)/', $attributeList);
            foreach ($attributeStrings as $attributeString) {
                list($key,$value) = explode('=', $attributeString);
                $attributes[$key] = $value;
            }
            $attributes = array_map(function ($attribute) {
                return trim($attribute, '"');
            }, $attributes);
            $attributes['include-path'] = $includePath;
            $data[] = $attributes;
        }

        return $data;
    }

    /**
     * @param string $repoName
     * @param string|array $command
     *
     * Executes an arbitrary command in the given repo directory
     * @param bool $returnSuccessOutput
     */
    private function executeOnRepo(string $repoName, $command, $returnSuccessOutput = false)
    {
        if (is_array($command)) {
            $process = new Process($command);
        } elseif (is_string($command)) {
            $process = Process::fromShellCommandline($command);
        }
        $process->setWorkingDirectory($this->reposDir . '/' . $repoName);
        $process->run();

        if ($process->getErrorOutput()) {
            $this->error($process->getErrorOutput());
        } elseif ($process->getOutput()) {
            if (!$returnSuccessOutput) $this->line($process->getOutput()); else return $process->getOutput();
        }
    }

    /**
     * @param $repoName
     *
     * Creates the repository in the repos dir if it doesn't exist
     */
    protected function createRepo(string $repoName): void
    {
        if (!file_exists($this->reposDir . '/' . $repoName)) {
            mkdir($this->reposDir . '/' . $repoName, 0755, true);
        }
        if (!file_exists($this->reposDir . '/' . $repoName . '/.git')) {
            $this->executeOnRepo($repoName, ['git', 'init']);
        } elseif ($this->firstRun) {
            $this->warn($repoName . ' repository already initialized.');
            if ($this->confirm('Drop repo and build again?', $this->option('drop'))) {
                $process = new Process(['rm', '-rf', $this->reposDir . '/' . $repoName]);
                $process->run();
                mkdir($this->reposDir . '/' . $repoName, 0755, true);
                $this->executeOnRepo($repoName, ['git', 'init']);
            }
        }
        $this->firstRun = false;
    }

    /**
     * @param $fileName
     * @param $repoName
     * @param $includeData
     */
    protected function copySourceToRepo($fileName, $repoName, $includeData): void
    {
        if ($includeData['bdd-action'] !== 'add') {
            return;
        }
        $bookPath = $this->bookDir . '/' . $fileName;
        $repoPath = $this->reposDir . '/' . $repoName . '/' . $includeData['bdd-filename'];
        $newDirectory = dirname($repoPath);
        // Create directory recursively if it doesn't exist
        if (!file_exists($newDirectory)) {
            mkdir($newDirectory, 0755, true);
        }

        $filteredContent = $this->parseIncludeTags($bookPath, $includeData);
        if ($filteredContent) {
            file_put_contents($repoPath, $filteredContent);
        } else {
            copy($bookPath, $repoPath);
        }
    }

    /**
     * @param $includeData
     * @param bool $force
     *
     * Process tags
     *
     * If identical consecutive tags are found, all includes will be under that tag
     */
    private function processTags($includeData, $force = false)
    {
        $repoName = $includeData['bdd-repo'];
        if (($includeData['bdd-tag'] !== $this->lastTag && $this->lastTag !== null) || $force) {
            // Found tag differs from last; tag previous commits with the last tag
            $this->executeOnRepo($repoName, ['git','tag','-a',$this->lastTag,'-m',$this->lastTag]);
            $this->info('Tag ' . $this->lastTag . ' created');
        }
        $this->lastTag = $includeData['bdd-tag'];
    }

    /**
     * @param $includeData
     *
     * Process commit message
     *
     * If identical consecutive commit msgs are found, all includes will be committed under that commit message
     */
    private function processCommitMsg($includeData)
    {
        $repoName = $includeData['bdd-repo'];
        $commitMsg = $includeData['bdd-commit-msg'];
        if (isset($includeData['bdd-tag']) && Str::startsWith($includeData['bdd-tag'], 'chapter-')) {
            $tag = Str::replaceFirst('chapter-','Chapter ', $includeData['bdd-tag']);
            $commitMsg = $tag . ' - ' . $commitMsg;
        }
        if ($commitMsg !== $this->lastCommitMessage) {
            // Found commit message differs from last; commit it
            $this->info('Committing ' . $includeData['bdd-filename'] . ' with commit message ' . $commitMsg);
            $this->executeOnRepo($repoName, ['git','commit','-m', $commitMsg]);
        }

        // Save the last commit-msg and tag in a property for later retrieval
        $this->lastCommitMessage = $commitMsg;
    }

    /**
     * @param string $bookPath
     * @param $includeData
     *
     * Parse an include block's tag attributes - see https://github.com/asciidoctor/asciidoctor.org/blob/master/docs/_includes/include-lines-tags.adoc
     */
    private function parseIncludeTags(string $bookPath, $includeData)
    {
        if (!isset($includeData['bdd-include-tags']) && !isset($includeData['tags'])) {
            return false;
        }

        $includeTags = $includeData['bdd-include-tags'] ?? $includeData['tags'];
        $includeTags = array_map('trim', (explode(';', $includeTags)));

        $parsedCode = '';
        $tagName = false;
        $tokens = token_get_all(file_get_contents($bookPath));
        foreach ($tokens as $token) {
            if (!is_array($token) && in_array($tagName, $includeTags)) {
                $parsedCode .= $token;
                continue;
            }

            list ($id, $content) = $token;
            if (stripos($content, '// tag::') !== false) {
                if ($tagName !== false) {
                    throw new \Exception('Previous tag ' . $tagName . ' has not been closed!');
                }
                $tagName = substr(trim($content), strlen('// tag::'), -2);
                continue;
            }

            if (stripos($content, '// end::') !== false) {
                $endTagName = substr(trim($content), strlen('// end::'), -2);
                if ($tagName === $endTagName) {
                    $tagName = false;
                }
                continue;
            }

            if (!$tagName) {
                $parsedCode .= $content;
            } elseif ($tagName && in_array($tagName, $includeTags)) {
                $parsedCode .= $content;
            }
        }

        return $parsedCode;
    }

    private function generateDiff($includeData) : void
    {
        if (!$this->option('generate-diffs')) {
            return;
        }

        if (!$includeData['include-path']) {
            return;
        }

        $compactDiff = $this->executeOnRepo($includeData['bdd-repo'], ['git', 'diff', '--no-prefix', 'HEAD~1'], true);
        $fullDiff = $this->executeOnRepo($includeData['bdd-repo'], ['git', 'diff', '--no-prefix', '-U1000', 'HEAD~1'], true);
        if (!$compactDiff || stripos($compactDiff, '/dev/null') !== false) {
            // New file, do nothing
            return;
        }

        // Save diff
        $fullDiffFilename = str_replace('Code/', 'Full/', $includeData['include-path']);
        $compactDiffFilename = str_replace('Code/', 'Compact/', $includeData['include-path']);

        @mkdir(dirname($this->bookDir . '/' . $fullDiffFilename), 0777, true);
        @mkdir(dirname($this->bookDir . '/' . $compactDiffFilename), 0777, true);

        file_put_contents($this->bookDir . '/' . $fullDiffFilename, $fullDiff);
        file_put_contents($this->bookDir . '/' . $compactDiffFilename, $compactDiff);
    }

}
