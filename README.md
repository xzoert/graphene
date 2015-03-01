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

## Writing some data

Before writing any data, we have to open a transaction.  

    $db->begin();

Being in a transaction allows you to do all the stuff, having it reflected to the database while you are working with it while avoiding conflicts with concurrent write accesses. Furthermore it allows you to either commit (publish your chnges to the database) or rollback on error (and leave the database untouched).

To commit / rollback use:

     $db->commit();
     $db->rollback();




