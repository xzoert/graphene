# graphene
Graph database for PHP + MySql

# Getting started

## Requirements

This is what you need:

- PHP 5.3 or above (i.e. with namespace support)
- The mysqli driver
- Access to a MySql database
- Some way to run PHP scripts, either through a PHP enabled web server or via the command line interface

## Installation

Download and unzip Graphene. 

Copy the *graphene* directory to some directory where you want to create your PHP scripts.

If you use a web server to run your scripts, make sure the graphene directory is readable by the web server.

Graphene needs a dirctory it can write to, which is called the classpath. When you open a connection to Graphene you must specify as well the location of this directory. It is typically called *model* and placed aside from the *graphene* directory. 

If now you add a *helloworld.php* file where you can write your first script, this is how your script directory should now look like:

     
     /graphene
     /model
     helloworld.php

## Including

That's easy: 

     include 'graphene/graphene.php';

## Connecting

    $db=graphene::open(array(
        "host"=>"localhost",
        "user"=>"root",
        "pwd"=>"root",
        "db"=>"test",
        "port"=>null,
        "prefix"=>"",
        "classpath"=>"./model"
    ));

Replace host, user, password and database name by those of an existing database you have access to. The port can be omitted or set to null if it is the default MySql port (3306). The prefix is only useful if you want several Graphene databases in a single MySql database. The classpath is where Graphene should store its definition files and where it should search your custom classes, if any. We already made the *model* directory in the Installation section, so let's use it.

## Freezing and unfreezing

Graphene can work in two modes: *frozen* and *unfrozen*. The first one is good for production, while the second is very handy during development. It will allow you to create types and properties as you name them in your code. Graphene will try to infer some information from how you are using them and create the definition files in a directory called *definitions* inside the classpath you provided (in our case the *model* directory). 

You should periodically have a look at those files, modify them if you want to, throw away useless stuff and eventually freeze some property or the entire definition file when you're happy with it, so they will not be touched anymore even if Graphene is in *unfrozen* mode. 

Since we have nothing in the database, nor have we written any definition file, let's start unfreezing Graphene:

    $db->unfreeze();
    
By default Graphene is frozen, so in production you simply don't unfreeze it. If however you want to re-freeze it after having unfrozen, you can call:

     $db->freeze();

## Transactions

Before writing any data, we have to open a transaction.  

    $db->begin();

Being in a transaction allows you to do all the stuff, having it reflected to the database while working, but avoiding conflicts with other concurrent write accesses. Furthermore it allows you to either commit (publish your changes to the database) or rollback on error (and leave the database untouched).

To commit / rollback use:

     $db->commit();
     $db->rollback();

A typical write block is thus made like this:

     $db->begin();
     try {
        // .... do something ...
        $db->commit();
    } catch( \Exception $e ) {
        $db->rollback();
        throw $e;
    }

## Writing data

Ok, now we're ready for the fun part. Just to summarize: your *helloworld* file should by now look somewhat like this:

     <?php
     
     include '../graphene.php';
     
     $db=graphene::open(array(
          "host"=>"localhost",
          "user"=>"root",
          "pwd"=>"root",
          "db"=>"test",
          "port"=>null,
          "prefix"=>"",
          "classpath"=>"./model"
     ));
     
     $db->begin();
     
We can avoid the *try / catch* block for the moment, we don't even want to commit at the end of the file. This is a handy way to run the script over and over again without filling your database with junk.

If you try to run the script, it will take some seconds since it has to create the Graphene tables in the targeted database. This will happen only the first time you open the connection.

Ok, let's go.

     $john=$db->Person();
     $john->firstName="John";
     $john->lastName="Smith";
    
     echo "John's first name is: ",$john->firstName,PHP_EOL;
    
     foreach( $db->select("Person#x and #x.firstName like 'J%'") as $person ) {
          echo "Found a person whose first name starts with 'J': ", 
               $person->firstName,' ',$person->lastName,PHP_EOL;
     }

And this should be the output:

     John's first name is: John
     Found a person whose first name starts with 'J': John Smith

If you are calling the script through the browser you'll probably want to surroud the whole thing by a &lt;PRE&gt; tag in order to get a decent output.

Let's add some more objects (or *nodes*) to our story:


     $bookshop=$db->Bookshop();
     $bookshop->owner=$john;
     $bookshop->name="John's bookshop";
     $bookshop->openSince=new DateTime("1986-05-13");
     
     $joyce=$db->Person(array("firstName"=>"James","lastName"=>"Joyce","isFamous"=>1));
     $fwake=$db->Book(array("title:en"=>"Finnegans wake","author"=>$joyce));
     $fwake->set("title:it","La veglia di Finnegan");
     
     $johnsbook=$db->Book(array("title"=>"How to run a bookshop","author"=>$john));
     
     $bookshop->books->add($fwake);
     $bookshop->books->add($johnsbook);


So... we have created two persons: John Smith and James Joyce. The first one is the owner of *John's bookshop* which sells a book written by the second one, who is a famous book writer. This might have inspired John to write a book on his turn about how to run a bookshop, and of course this one is also sold in his bookshop. 

We used on purpose various ways to set properties, either at object creation with an associative array, or by simple assignement, or calling the *set* function and finally using the *add* function on the property itself. And of course there are some more. 

Properties can represent single values, in which case you set and get them as normal PHP object properties:

     // SET
     $john->firstName="John";           // OR   $john->set("firstName","John");
     // GET
     echo $john->firstName;             // OR   echo $john->get("firstName");
     // DELETE
     $john->fisrtName=null;             // OR   $john->set("firstName",null);

But as well they can represent lists, in which case you can access them as if it was a PHP array:

     $books=$bookshop->books;
     // SET
     $books[]=$fwake;              // OR   $books->append($fwake);
     $books[1]=$johnsbook;         // OR   $books->setAt($johnsbook,1);
     // RESET
     $books->reset(array(
          $fwake,
          $johnsbook
     ));                           // OR   $bookshop->books=array($fwake,$johnsbook);  
     // GET
     echo $books[1]->title;        // OR   echo $books->getAt(1);
     // REMOVE
     unset($books[1]);             // OR   $books->setAt(null,1);
     // LOOP
     foreach( $books as $book ) {}
     // COUNT
     echo $books->count();
     // DELETE ALL
     $books->delete();             // OR   $bookshop->books=null;
     
But in many cases what you really want is not a list but what is called a *set*, i.e. a collection without repetitions, which is probably our case in the bookshop books: there is no point in adding the book twice, unless we want to use the number of occurrences as our in-stock counter, what doesn't seem a very briliant solution to me. When you deal with sets, you'd rather like to use a third series of functions on a property:

     $books=$bookshop->books;
     // ADD
     $books->add($fwake);          // ADD IF NOT THERE
     // REMOVE
     $books->remove($fwake);       // REMOVE IT IF THERE
     // CHECK
     echo "Does the set contain 'Finnegans wake'? ",$books->contains($fwake)?"yes":"no";
     // DELETE ALL
     $books->delete();             // OR   $bookshop->books=null;
     
Every property can be one of the following data types:

- int 
- string
- float
- datetime (bound to PHP's DateTime class)
- node (properties that link from one node to another)

These latter properties can be travelled as well the other way around by adding a '@' to the property name, or by assigning an alias in the definition files (see next section).

To get back the owner of bookshop, for example, you can do:

     echo $bookshop->get('@owner')->firstName;



## Querying


## The definition files

Now it is time to have a look to what has happened in the *model/definitions* directory. It should now contain following files:

     Book.def
     Bookshop.def
     Person.def







