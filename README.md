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





