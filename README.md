# graphene
Graph database for PHP + MySql

# Getting started

## Requirements

## Installation

## Connecting

    include 'graphene/graphene.php';
    
    $db=graphene::open(array(
        "host"=>"localhost",
        "user"=>"root",
        "pwd"=>"root",
        "db"=>"test",
        "port"=>null,
        "prefix"=>"",
        "classpath"=>"./model"
    ));

Replace host, user, password and database name by those of an existing database you have access to. The port can be omitted or set to null if it is the default MySql port (3306). The prefix is only useful if you want several Graphene databases in a single MySql database. The classpath is where Graphene should store its definition files and where it should search your custom classes, if any. We already made the /model/ directory in the Installation section, so let's use it.

## Freezing and unfreezing

Graphene can work in two modes: frozen and unfrozen. The first one is good for production, while the second is very handy during development. It will allow you to create types and properties as you name them in your code. Graphene will try to infer some information from how you are using them and create the definition files in a directory called 'definitions' inside the classpath you provided. 

You should periodically have a look at those files, modify them if you want to and eventually freeze some property or the entire definition file, so they will not be touched even if Graphene is in unfrozen mode. 

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

     $db->open();
     try {
        // .... do something ...
        $db->commit();
    } catch( \Exception $e ) {
        $db->rollback();
        throw $e;
    }

    



