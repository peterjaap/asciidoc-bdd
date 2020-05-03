<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class BuildCommand extends Command
{
    protected $signature = 'build {bookdir} {reposdir} {--reponame}';
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
     * @var int
     */
    protected $includesFound;

    public function handle()
    {
        $this->bookDir = $this->argument('bookdir');
        $this->reposDir = $this->argument('reposdir');

        // Parse through all adoc files
        $adocs = glob($this->bookDir . '/*.adoc');
        $includes = [];
        foreach ($adocs as $adoc) {
            preg_match_all('#include::(.*)\[(.*bdd.*)\]#', file_get_contents($adoc), $includeLines);
            $includes = array_merge($includes, $this->parseIncludeDirectives($includeLines));
        }

        $this->includesFound = count($includes);
        $this->line('Found ' . $this->includesFound . ' includes to process');

        foreach ($includes as $fileName => $includeData) {
            $repoName = $includeData['bdd-repo'];
            // Create a new Git repo if it doesn't exist in the reposdir
            $this->createRepo($repoName);
            // Copy the source file to the Git repository directory
            $this->copySourceToRepo($fileName, $repoName, $includeData);
            // Add/remove the file to/from the Git repository
            $gitAction = $includeData['bdd-action'] ?: 'add';
            $this->executeOnRepo($repoName, ['git', $gitAction, $includeData['bdd-filename']]);
            // Process commit messages and tags
            $this->processCommitMsgTag($includeData);
        }

        return 0;
    }

    /**
     * @param $includes
     * @return array
     *
     * Parse the Aciidoc include:: directives and return an array of all bdd-* attributes
     */
    private function parseIncludeDirectives($includes)
    {
        $data = [];
        foreach ($includes[1] as $includePath)
        {
            $attributeLists = $includes[2];
            foreach ($attributeLists as $attributeList) {
                $attributes = [];
                $attributeStrings = explode(',', $attributeList);
                foreach ($attributeStrings as $attributeString) {
                    list($key,$value) = explode('=', $attributeString);
                    $attributes[$key] = $value;
                }
                $attributes = array_map(function ($attribute) {
                    return trim($attribute, '"');
                }, $attributes);
                $attributes['bdd-repo'] = $attributes['bdd-repo'] ?: $this->option('reponame');
                $data[$includePath] = $attributes;
            }
        }

        return $data;
    }

    /**
     * @param string $repoName
     * @param array $command
     *
     * Executes an arbitrary command in the given repo directory
     */
    private function executeOnRepo(string $repoName, array $command)
    {
        $process = new Process($command);
        $process->setWorkingDirectory($this->reposDir . '/' . $repoName);
        $process->run();

        $this->line($process->getOutput());
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
        }
    }

    /**
     * @param $fileName
     * @param $repoName
     * @param $includeData
     */
    protected function copySourceToRepo($fileName, $repoName, $includeData): void
    {
        $bookPath = $this->bookDir . '/' . $fileName;
        $repoPath = $this->reposDir . '/' . $repoName . '/' . $includeData['bdd-filename'];
        $newDirectory = dirname($fileName);
        // Create directory recursively if it doesn't exist
        if (!file_exists($newDirectory)) {
            mkdir($newDirectory, 0755, true);
        }
        copy($bookPath, $repoPath);
    }

    /**
     * @param $includeData
     *
     * Process commit messages and tags
     *
     * - If identical consecutive commit msgs are found, all includes will be committed under that commit message
     * - If identical consecutive tags are found, all includes will be under that tag
     */
    private function processCommitMsgTag($includeData)
    {
        $repoName = $includeData['bdd-repo'];
        if (
            $includeData['bdd-commit-msg'] !== $this->lastCommitMessage
            && (!is_null($this->lastCommitMessage) || $this->includesFound === 1)
        ) {
            // Found commit message differs from last; commit it
            $this->executeOnRepo($repoName, ['git','commit','-m',$includeData['bdd-commit-msg']]);
        }

        if (
            $includeData['bdd-tag'] !== $this->lastTag
            && (!is_null($this->lastCommitMessage) || $this->includesFound === 1)
        ) {
            // Found tag differs from last; tag it
            $this->executeOnRepo($repoName, ['git','tag','-a',$includeData['bdd-tag'],'-m',$includeData['bdd-tag']]);
            $this->line('Tag ' . $includeData['bdd-tag'] . ' created');
        }

        // Save the last commit-msg and tag in a property for later retrieval
        $this->lastCommitMessage = $includeData['bdd-commit-msg'];
        $this->lastTag = $includeData['bdd-tag'];
    }

}
