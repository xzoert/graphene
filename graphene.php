<?php

/*   IMPORTANT NOTE FOR CONTRIBUTORS

Hello, and welcome to Graphene.

1. CODING

    There are some oddities in the code you shuld be warned about.
    
    First of all, and for hystorical reasons, the lower level classes are still
    in slug_case (I adopted the camelCase convention only recently). 
    This does not affect the public API but will look strange to contributors.
    As soon as I have the time to do it, I'll restyle thoses classes (well, if 
    someone else hasn't done it before...).
    
    Secondly I'm aware of the fact that I do not follow many of the best 
    practices in code styling. For example I always write:
    
        if( something ) {
    
    instead of:
    
        if (something) {
        
    I apologize for this, but unfortunately I only recently was told there is 
    a thing called php-fig (http://www.php-fig.org/psr/psr-2/) which I'm willed
    to adopt from now on and so should you if you happen to contribute.


2. TESTING

    There are no formal tests yet, sorry for that. 
    
    For the moment the only tests you can run are the examples. They do test a 
    lot of stuff and if you launch them and they work correctly (no 'Ooops' or 
    mean exceptions in the output) this should be interpreted at least as a very 
    good sign nothing is broken.
    
    On how to run the examples go to the examples directory and read the README 
    file.


3. HAVE FUN

    Those things being said, thanks for your interest, have fun and let me know.


- Max Jacob 02 2015
*/





require_once 'src/Connection.php';

/**
@brief The connection factory.
*/

class graphene 
{
    
    private static $connections=array();
    private static $autoloading=false;
    
    /**
    @brief Opens a database connection.
    
    @param $params An associative array containing the connection params.
    
    @return a graphene::Connection object.
    
    For example:
    
        $db=graphene::open(array(
            "host"=>"localhost",
            "user"=>"dummy",
            "pwd"=>"dummy",
            "db"=>"test",
            "port"=>null,
            "prefix"=>"",
            "classpath"=>"./model"
        ));
     
     The host, user, pwd and db name must e those of a MySql database you have 
     access to.
     
     The port can be omitted or set to null, in which case the default MySql 
     port (3306) will be used. 
     
     The prefix also is optional and only useful if 
     you want several Graphene databases in a single MySql database: if you the
     prefix will be added to the name of all graphene tables, so there can be
     several sets of such tables you connect to with the same database params
     but with a different prefix.
     
     The classpath is where Graphene should store its definition files and where 
     it should search your custom classes, if any.
        
    */
    public static function open($params) 
    {
        $id='k'.count(self::$connections);
        $conn=\graphene\Connection::_open($params,$id);
        self::$connections[$id]=$conn;
        return $conn;
    }
    
    

    public static function loadClass($name) 
    {
        foreach (self::$connections as $id=>$conn) {
            if ($conn->_loadClass($name)) break;
        }
    }
    
    public static function enableAutoload() 
    {
        if (!self::$autoloading) {
            spl_autoload_register(array('\Graphene','loadClass'));
            self::$autoloading=true;
        }
    }
    
    public static function _close($id) 
    {
        unset(self::$connections[$id]);
    }
    
    const ACCESS_NONE=0;
    const ACCESS_READ=1;
    const ACCESS_INSERT=2;
    const ACCESS_DELETE=4;
    const ACCESS_UPDATE=8;
    const ACCESS_WRITE=14;
    const ACCESS_FULL=15;
    
    
}


