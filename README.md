# Asciidoc Book Driven Development CLI tool

## Who is this for?
This is for book authors who are writing a technical book and want a Github repository for their readers to work along with.

## What does it do?
This tool can read your book (in its Asciidoc source format) and extract file includes according to a certain regex. For instance, you could configure it to extract all PHP files in a certain directory. It can then build up a Github repo using tags and commits. The repository can be rebuilt from the ground up, ensuring a logical processing through the tags.

## Configuration
You have to use the asciidoc attribute syntax to tell this tool how to process the include.

Example in Asciidoc;

```
[source,json]
----
include::Mycode/composer.json[bdd-repo=todolist,bdd-filename=composer.json,bdd-commit-msg="Add the composer.json",bdd-tag=chapter-1.0]
----
```

Run the tool like this;

```
$ ./asciidoc-bdd /path/to/your/asciidoc/files /path/to/repositories
```

This tool will then parse the `bdd-` attributes in all `*.adoc` files in the asciidoc files dir, like this:

```
Array                                             
(                                                                                                                    
    [bdd-repo] => todolist
    [bdd-filename] => composer.json
    [bdd-commit-msg] => Add the composer.json
    [bdd-tag] => chapter-7.0                                                            
)  
```

It will then create a repository called `todolist` in the given repositories path and create commits and tags based on the `bdd-` attributes. The following rules apply;

1. When it encounters a number of consecutive identical commit messages, these files will be commited under the same commit;
2. When it encounters a number of consecutive identical tags, a tag will be generated for the last commit.

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

## Credits
Inspired by Fabien Potencier, who talked about _Book Driven Development_ in his book: Symfony 5 - The Fast Track. You can check out his Github repo for the book here: https://github.com/the-fast-track/book-5.0-1
