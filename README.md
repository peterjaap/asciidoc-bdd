# Asciidoc Book Driven Development CLI tool

## Who is this for?
This is for book authors who are writing a technical book in [Asciidoc](http://asciidoc.org/) and want a Git repository for their readers to work along with.

## What does it do?
This tool can read your book (in its Asciidoc source format) and extract file includes that contain `bdd-` attributes. It can then build up a Git repo with tags and commits. The repository can be rebuilt from the ground up, ensuring a logical order through the tags.

## Configuration
You have to use the Asciidoc attribute syntax to tell this tool how to process the include.

Example in Asciidoc;

```
[source,json]
----
include::Mycode/composer.json[bdd-repo=todolist,bdd-filename=composer.json,bdd-commit-msg="Add the composer.json",bdd-tag=chapter-7.0]
----
```

Run the tool like this;

```
$ ./asciidoc-bdd build /path/to/your/asciidoc/files /path/to/repositories
```

This tool will then parse the `bdd-` attributes in all `*.adoc` files in the Asciidoc files dir, like this:

```
Array                                             
(                                                                                                                    
    [bdd-repo] => todolist
    [bdd-filename] => composer.json
    [bdd-commit-msg] => Add the composer.json
    [bdd-tag] => chapter-7.0                                                            
)  
```

It will then create a repository called `todolist` in the given repositories path and create commits and tags based on the `bdd-` attributes. 

## bdd- attributes
| Attribute  | Required  | Comment |
|---|---|---|
| bdd-repo  | optional  | The repo the include should be committed to. Optional when option `--reponame` is given. `bdd-repo` overrides any given `--reponame` |
| bdd-filename  | required  | The filename (including path) in the repo |
| bdd-commit-msg  | optional  | When it encounters a number of consecutive identical commit messages, these files will be commited under the same commit  |
| bdd-tag  | optional  |  When it encounters a number of consecutive identical tags, a tag will be generated for the last commit |
| bdd-action | optional | The default git command is `add`. If you want to remove a file from the Git repo, use `bdd-action=rm` | 
| tags / bdd-include-tags | optional | Using [Asciidoc's region tags](https://github.com/asciidoctor/asciidoctor.org/blob/master/docs/_includes/include-lines-tags.adoc#by-tagged-regions), allows us to filter processed files based on include tags. Given `tags` can be overruled by `bdd-include-tags` | 

Output:

```
./asciidoc-bdd build /path/to/my/book /path/to/my/repos                                                             
Found 1 includes to process                                                             
Initialized empty Git repository in /path/to/my/repos/todolist/.git/
                                                                                        
                                                                                        
[master (root-commit) 4a605d4] Add the composer.json                                                                                                                             
 1 file changed, 14 insertions(+)
 create mode 100644 composer.json    
                                            
Tag chapter-7.0 created                                           
```

You can see the generated repo here; https://github.com/peterjaap/asciidoc-bdd-example

## Credits
[Inspired](https://twitter.com/PeterJaap/status/1251486796258652160) by [Fabien Potencier](https://twitter.com/fabot), who talked about _Book Driven Development_ in his book: [Symfony 5: The Fast Track](https://symfony.com/book). You can check out his Github repo for the book here: https://github.com/the-fast-track/book-5.0-1
