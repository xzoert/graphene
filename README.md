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



