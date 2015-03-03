<?php

/*
NOTE: the whole code here is still open to the possibility of having untyped nodes. 
This feature is not yet made available since it hasn't been tested enough and as well 
because the use cases aren't yet very clear to me.

- Max Jacob 02 2015
*/


/*

It brings together the DbStorage (MySql) with the whole type mechanism.
It is the public API for the graphene database connection.

- Max Jacob 02 2015
*/

namespace graphene;

require_once 'MySql.php';
require_once 'Type.php';
require_once 'Node.php';
require_once 'Def.php';
require_once 'Query.php';
require_once 'Syntax.php';


/**
@brief The database connection.

\section open_connection Opening

To open a connection you have to call graphene::open():

        $db=graphene::open(array(
            "host"=>"localhost",
            "user"=>"dummy",
            "pwd"=>"dummy",
            "db"=>"test",
            "port"=>null,
            "prefix"=>"",
            "classpath"=>"./model"
        ));

@sa graphene::open

\section transactions Transactions

If you want to write data to the database, you \em have to open a transaction
by calling:

    $db->begin();

Being in a transaction allows you to write date, having them reflected to the 
database while working, but avoiding conflicts with other concurrent write 
accesses. 

Furthermore it allows you to either commit() (publish your changes to the database) 
or rollback() on error (and leave the database untouched).



\subsection nesting_transactions Nesting transactions

Unfortunately MySql, on which Graphene depends, does not support by now nested 
transactions. Graphene attemps to compensate a little bit by adding a counter that
allows you to open and commit transactions in a nested way, but having the transaction
really committed only at the end. This allows you to actually write functions
that open and close transactions with no side effect if the caller has already 
opened a transaction on his turn. 

While for committing the action is performed only at the last commit() (that must 
match the first begin()), for the rolling back Graphene adopts the conservative 
approach of doing it immediately, at the first rollback() call regardless of the 
nesting level, since it can not provide \em real nested transactions.

This is a quite safe approach, and will work flowlessly as long as you take care
of coding your write blocks in a structure like the following:

    $db->begin();
    try {
        .... do something ...
        $db->commit();
    } catch( \Exception $e ) {
        $db->rollback();
        throw $e;
    }

If you really have to, you can as well try to undo in the \em catch bock whatever 
has been done in the \em try block, commit and return without rolling back, but
you'll have to do it manually. 

\section node_types Node types

A connection gives you access to the node types (see Type) from which you can 
create and get back by selecting the nodes of the various types. 

To get a type \em MyType from a connection \em $db you can do either of the 
following:

    $myType=$db->getType('MyType');
    $myType=$db->MyType;

There is also a shortcut for creating directly nodes of a given type:

    $myNode=$db->MyType();

Or, if you want to initialize data fields:

    $myNode=$db->MyType(array(
        "prop1"=>"value1",
        "prop2"=>"value2",
        ...
    ));

And for getting back a node of a given type by it's identifier:

    $myNode=MyType(15627);

\section freeze-unfreeze Freezing and unfreezing

A Connection can work in two modes: \em frozen and \em unfrozen. The first one 
is good for production, while the second is very handy during development. 
It will allow you to create types and properties as you name them in your code. 
Graphene will try to infer some information from how you are using them and 
create the definition files in a directory called \em definitions inside the 
classpath you provided opening the connection (see graphene::open()).

Consider for example following code:

    $db->MyType(array("someField"=>"someProperty"));

If your connection is unfrozen, it will create (if it does not exist) the type 
\em MyType with its .def file in the \em definitions directory, 
along with a basic definition of the property \em someField.

If instead the connection is \em frozen and the type \em MyType has never been 
created or, if it has, there is no definition for a property called \em someField, 
an exception will be thrown.

You should always have a look at the definition files that have been created, so
you can refine or correct them and eventually freeze some specific property
definitions or the whole file, in which case they will not be touched anymore even
if the connection is unfrozen.

@sa \ref def-files

\section querying Querying

The last, but not least, important function of a Connection is to let you query 
the database.

Queries are performed using the \em select() function, as for example:

    $db->select("Person#x and #x.firstName=?","John");


@sa \ref gql

*/

class Connection 
{

    
    private $transaction_level=0;
    private $storage;
    private $types=array();
    private $deleting=array();
    private $queries=array();
    private $frozen=true;
    private $classPath=array();
    private $id;
    private $untypedNode;

    /**
    @brief dummy.
    */
    function dummy() {}
    
    /**
    @brief Add other classpaths.
    
    @param $path 
        A string with the directory path.
    
    If you happen to have several graphene bundles placed in different places,
    you can add their classpaths using this function.
    
    If the connection is unfrozen, the def files generated will be in any case
    placed in the first classpath (the one passed to the Graphene::open function).
    
    */
    public function addClasspath($path) 
    {
        if (!array_key_exists($path,$this->classPath)) {
            if (is_dir($path)) $this->classPath[$path]=1;
        }
    }
    
    public function _loadClass($name) 
    {
        $frag=str_replace('\\','/',$name);
        foreach ($this->classPath as $dir=>$dummy) {
            $fn=$dir.'/classes/'.$frag.'.php';
            if (is_file($fn)) {
                include $fn;
                return true;
            }
        }
    }
    
    public function alterPropertyType($name,$type) 
    {
        try {
            $this->begin();
            $this->storage->setPredType($name,$type);
            $this->commit();
        } catch( \Exception $e ) {
            $this->rollback();
            throw $e;
        }
    }

    public function _findDefFile($name) 
    {
        $frag=str_replace('_','/',$name);
        $first=null;
        foreach ($this->classPath as $dir=>$dummy) {
            $fn=$dir.'/definitions/'.$frag.'.def';
            if (is_file($fn)) {
                return $fn;
            } else if (is_null($first)) {
                $first=$fn;
            }
        }
        return $first;
    }

    
    private $_defs=array();
    
    function _getTypeDefinition($name,$ns='') 
    {
        if ($name[0]!='_') {
            if ($ns) $name=$ns.'_'.$name;
        } else {
            $name=substr($name,1);
        }
        if (array_key_exists($name,$this->_defs)) return $this->_defs[$name];
        $this->_defs[$name]=null;  // paceholder for antiloop in class inheritance
        $def=new Def($name,$this);
        $this->_defs[$name]=$def;
        return $def;
    }

    
    public static function _open($params,$id) 
    {
        return new Connection($params,$id);
    }
    
    function _isDeleting($id) { return array_key_exists($id,$this->deleting); }
    function _setDeleting($id) { $this->deleting[$id]=1; }
    function _unsetDeleting($id) { unset($this->deleting[$id]); }
    
    function __construct($params,$id) 
    {
        $this->id=$id;
        if (!isset($params['port'])) $params['port']=null;
        if (!isset($params['prefix'])) $params['prefix']='';
        if (!isset($params['classpath'])) throw new \Exception("Please set a classpath (a directory the web server can write to.");
        $this->classPath[$params['classpath']]=1;
        unset($params['classpath']);
        $this->storage=new MySql($params);
        $this->untypedNode=new UntypedNode($this,$this->_getTypeDefinition('Untyped'));
        $this->types['Untyped']=$this->untypedNode;
    }
    
    /**
    @brief Close the connection.
    
    On web pages you don't really need it, since at page end all resources will
    be freed anyway. 
    
    */
    function close() 
    {
        try {
            $this->storage->closeConnection();
        } catch( \Exception $e ) {}
        \graphene::_close($this->id);
    }
    
    /**
    @brief Tells if the connection is frozen or not.
    
    @retval int
        1 if frozen, 0 if unfrozen
    
    @sa \ref freeze-unfreeze
    */
    function isFrozen() { return $this->frozen; }
    
    /**
    @brief Freezes the database.
    
    This will \em freeze the connection. The connection is frozen by default, so
    unless you have unfrozen it before, this call is not necessary.
    
    @sa \ref freeze-unfreeze
    
    */
    function freeze() { $this->frozen=true; }
    
    /**
    @brief Unfreezes the database.
    
    By default a connection is frozen, call this function to unfreeze it.
    
    @sa \ref freeze-unfreeze
    */
    function unfreeze() { $this->frozen=false; }

    /**
    @brief Begins a transaction.
    
    @sa \ref transactions
    */
    function begin() 
    {
        if ($this->transaction_level===0) $this->storage->start_writing();
        $this->transaction_level++;
    }

    /**
    @brief Commits the transaction.

    @sa \ref transactions

    */
    public function commit() 
    {
        if ($this->transaction_level>0) {
            $this->transaction_level--;
            if ($this->transaction_level===0) $this->storage->commit();
        }
    }
    
    /**
    @brief Rolls back the transaction.

    @sa \ref transactions

    */
    public function rollback() 
    {
        if ($this->transaction_level>0) {
            $this->storage->rollback();
            $this->transaction_level=0;
        }
    }
    
    /**
    @brief Shortcut for getType().
    
    @param $n 
        The type name.
    
    @return Type
        The requested type
    
    This allows you to do:
        
        $db->Person;
        
    instead of:
    
        $db->getType('Person');
    */
    function __get($n) 
    {
        try {
            return $this->getType($n);
        } catch( \Exception $e ) {
            $pos=strrpos($n,'_');
            if ($pos!==false) $frag=substr($n,$pos);
            else $frag=$n;
            if (strtoupper($frag[0])!==$frag[0]) {
                throw new \Exception( 'Property '.get_class($this).'::'.$n.' does not exist.' ); 
            } else {
                throw $e;
            }
        }
    }
    
    /**
    @brief Shortcut for creating / getting nodes from types.
    
    @param $n 
        The type name
    @param $args 
        Either nothing or an associative array to create a new node
    or an identifier to get an existing one.
    
    @return Node
        The requested node.
    
    This allows you to do things like:
    
        $john=$db->Person();
        $john=$db->Person(array("firstName"=>"John"));
        $john=$db->Person(157);
    
    */
    function __call($n,$args) 
    {
        try {
            $type=$this->getType($n);
            if (count($args)==0) return $type->newNode();
            else {
                $arg=$args[0];
                if (is_array($arg)) return $type->newNode($arg);
                else return $type->getNode($arg);
            }
        } catch (\Exception $e) {
            $pos=strrpos($n,'_');
            if ($pos!==false) $frag=substr($n,$pos);
            else $frag=$n;
            if (strtoupper($frag[0])!==$frag[0] || count($args)>1) {
                throw new \Exception( 'Method '.get_class($this).'::'.$n.' does not exist.' ); 
            } else {
                throw $e;
            }
        }
        
    }
        
    /**
    @brief Returns a node type by name.
    
    @param $name 
        The type name
    @param $phpns 
        The PHP namespace relatively to which the name is given. This is
    useful only if ypu are extending a node and plan to be inherited from a different
    namespace than yours... so quite advanced stuff.
    
    @return Type
        The requested type
    
    */
    function getType($name,$phpns=null) 
    {
        if (!$name) throw new \Exception('No name.');
        if ($phpns) $name=Syntax::typeName($name,'',$phpns);
        else if ($name[0]=='_') $name=substr($name,1);
        if (!array_key_exists($name,$this->types)) {
            $def=$this->_getTypeDefinition($name);
            $tcl=$def->typeClass();
            $type=new $tcl($this,$def);
            $this->types[$name]=$type;
            return $type;
        } else {
            return $this->types[$name];
        }
    }
    
    /**
    @brief Performs a query.
    
    @param $query 
        The query
    @param $params 
        Optional parameters to fill ut the '?' in the query. It can be 
    a single value if there is only one '?', or an array containing several values
    if there are more, in the same order as they appear in the query.
    
    @return ResultSet
        The query result.
    
    @sa \ref gql
    
    */
    function select($query='',$params=null) 
    {
        if (array_key_exists($query,$this->queries)) $q=$this->queries[$query];
        else {
            $q=new Query($query,$this,null);
            $this->queries[$query]=$q;
        }
        return $q->execute($params);
    }
    
    /*
    Set to private in order to hide untyped nodes. 
    
    - Max Jacob 02 2015
    */
    private function _getDataNode($id) 
    {
        return new DataNode($this,(int)$id,$this->untypedNode);
    }
    
    /**
    @brief Gives back the \em raw MySql connection.
    
    @return mysqli
        The raw mysqli connection.
    
    */
    function getMySqlConnection()
    {
        return $this->storage->get_mysqli();
    }
    
    /**
    @brief Gives back a node by its id.
    
    @param $id 
        The identifier of the node
    
    @return Node
        The requested node.
    
    */
    function getNode($id) 
    {
        $i=(int)$id;
        if ($i<=0) throw new \Exception('Invalid id: '.$id);
        $l=$this->storage->getTriples($i,'graphene_topType',null,'string');
        if ($l->count()>0) {
            $tr=$l[0];
            return $this->getType($tr['ob'])->getNode($i);
        } else {
            return new DataNode($this,$i,$this->untypedNode);
        }
    }
 
    /*
    Set to private in order to hide untyped nodes. 
    
    - Max Jacob 02 2015
    */
    private function newNode($params=null) 
    {
        $id=$this->storage->newId();
        $node=new DataNode($this,$id,$this->untypedNode);
        if (is_array($params)) {
            foreach ($params as $k=>$v) {
                $node->set($k,$v);
            }
        }
        return $node;
    }

    function _storage() {
        return $this->storage;
    }

    
}


class UntypedNode extends Type 
{
    
    function getNode($i) { return $this->db()->_getDataNode($i); }
    
    function addNode($i,$args=null) {}
    
    function removeNode($i) {}
    
    
}


