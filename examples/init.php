<?php 

/*
This file includes Graphene and opens the database connection.
It is included by the examples.

Edit it to set the correct database parameters.

-- max jacob 2015
*/


// include graphene
require_once '../graphene.php';

// open the connection
$db=graphene::open(array(
    "host"=>"localhost",
    "user"=>"dummy",
    "pwd"=>"dummy",
    "db"=>"test",
    "prefix"=>"examples",
    "classpath"=>"./model"
));

// open a <pre> tag if called via HTTP
if( isset($_SERVER['HTTP_HOST']) ) echo '<pre>';





