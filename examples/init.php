<?php 

/**
This file contains some generic PHP initializations and opens a graphene 
database connection, provided a db.json file exists. 

-- max jacob 2015
*/





/** 
INITIALIZATION

Some PHP stuff:
	- report all errors
	- initialize mb to utf8
	- set some timezone...
	- get rid of magic quotes etc.
	- set a basic error handler that transforms them into exceptions

Nothing of this is really needed, I'm just used to it.

- Max Jacob 02 2015
*/
ERROR_REPORTING(E_ALL);
ini_set('display_errors', 1);
mb_language('uni');
mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');
date_default_timezone_set('Europe/Rome');
if (get_magic_quotes_gpc()) {
	function stripslashes_deep($value) {
		return is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
	}
	$_POST = array_map('stripslashes_deep', $_POST);
	$_GET = array_map('stripslashes_deep', $_GET);
	$_COOKIE = array_map('stripslashes_deep', $_COOKIE);
	$_REQUEST = array_map('stripslashes_deep', $_REQUEST);
}
function basic_exception_error_handler($errno, $errstr, $errfile, $errline ) {
	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler('basic_exception_error_handler');



/**
CHECK FOR DATABASE PARAMS AND OPEN THE DATABASE
*/
$conff=__DIR__.'/db.json';
if( !is_file($conff) ) {
	echo PHP_EOL,PHP_EOL,'ERROR: No database configuration found... please create a db.json file like this:',PHP_EOL,PHP_EOL;
	echo '{"host":"localhost","user":"root","pwd":"root","db":"test","port":null,"prefix":"examples","classpath":"./model"}';
	echo PHP_EOL,PHP_EOL;
	exit;
}
// read the file
$params=json_decode(file_get_contents($conff),true);
if( !$params ) {
	echo PHP_EOL,PHP_EOL,"Error in configuration file.",PHP_EOL,PHP_EOL;
	exit;
}
// include graphene
require_once '../graphene.php';

// open the connection
$db=graphene::open($params);

// open a <pre> tag if called via HTTP
if( isset($_SERVER['HTTP_HOST']) ) echo '<pre>';





