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



*/

class Connection {

    
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
    @brief Add other classpaths.
    
    @param $path A string with the directory path.
    
    If you happen to have several graphene bundles placed in different places,
    you can add their classpaths using this function.
    
    If the connection is unfrozen, the def files generated will be in any case
    placed in the first classpath (the one passed to the Graphene::open function).
    
    */
    public function addClasspath($path) {
        if( !array_key_exists($path,$this->classPath) ) {
            if( is_dir($path) ) $this->classPath[$path]=1;
        }
    }
    
    public function _loadClass($name) {
        $frag=str_replace('\\','/',$name);
        foreach( $this->classPath as $dir=>$dummy ) {
            $fn=$dir.'/classes/'.$frag.'.php';
            if( is_file($fn) ) {
                include $fn;
                return true;
            }
        }
    }
    
    public function alterPropertyType($name,$type) {
        try {
            $this->begin();
            $this->storage->setPredType($name,$type);
            $this->commit();
        } catch( \Exception $e ) {
            $this->rollback();
            throw $e;
        }
    }

    public function _findDefFile($name) {
        $frag=str_replace('_','/',$name);
        $first=null;
        foreach( $this->classPath as $dir=>$dummy ) {
            $fn=$dir.'/definitions/'.$frag.'.def';
            if( is_file($fn) ) {
                return $fn;
            } else if( is_null($first) ) {
                $first=$fn;
            }
        }
        return $first;
    }

    
    private $_defs=array();
    
    function _getTypeDefinition($name,$ns='') {
        if( $name[0]!='_' ) {
            if( $ns ) $name=$ns.'_'.$name;
        } else {
            $name=substr($name,1);
        }
        if( array_key_exists($name,$this->_defs) ) return $this->_defs[$name];
        $this->_defs[$name]=null;  // paceholder for antiloop in class inheritance
        $def=new Def($name,$this);
        $this->_defs[$name]=$def;
        return $def;
    }

    
    public static function _open($params,$id) {
        return new Connection($params,$id);
    }
    
    function _isDeleting($id) { return array_key_exists($id,$this->deleting); }
    function _setDeleting($id) { $this->deleting[$id]=1; }
    function _unsetDeleting($id) { unset($this->deleting[$id]); }
    
    function __construct($params,$id) {
        $this->id=$id;
        if( !isset($params['port']) ) $params['port']=null;
        if( !isset($params['prefix']) ) $params['prefix']='';
        if( !isset($params['classpath']) ) throw new \Exception("Please set a classpath (a directory the web server can write to.");
        $this->classPath[$params['classpath']]=1;
        unset($params['classpath']);
        $this->storage=new MySql($params);
        $this->untypedNode=new UntypedNode($this,$this->_getTypeDefinition('Untyped'));
        $this->types['Untyped']=$this->untypedNode;
    }
    
    /**
    @brief Close the connection.
    */
    function close() {
        try {
            $this->storage->closeConnection();
        } catch( \Exception $e ) {}
        \graphene::_close($this->id);
    }
    
    /**
    @brief Tells if the connection is frozen or not.
    
    @return int
    
    @sa freeze unfreeze
    */
    function isFrozen() {
        return $this->frozen;
    }
    
    /**
    @brief Freezes the database.
    */
    function freeze() {
        $this->frozen=true;                        
    }
    
    /**
    @brief Unfreezes the database.
    */
    function unfreeze() {
        $this->frozen=false;
    }

    /**
    @brief Begins a transaction.
    */
    function begin() {
        if( $this->transaction_level===0 ) $this->storage->start_writing();
        $this->transaction_level++;
    }

    /**
    @brief Commits the transaction.
    */
    public function commit() {
        if( $this->transaction_level>0 ) {
            $this->transaction_level--;
            if( $this->transaction_level===0 ) $this->storage->commit();
        }
    }
    
    /**
    @brief Rolls back the transaction.
    */
    public function rollback() {
        if( $this->transaction_level>0 ) {
            $this->storage->rollback();
            $this->transaction_level=0;
        }
    }
    
    /**
    @brief Shortcut for getType().
    
    @param $n The type name.
    
    @return Type
    
    This allows you to do:
        
        $db->Person;
        
    instead of:
    
        $db->getType('Person');
    */
    function __get($n) {
        try {
            return $this->getType($n);
        } catch( \Exception $e ) {
            $pos=strrpos($n,'_');
            if( $pos!==false ) $frag=substr($n,$pos);
            else $frag=$n;
            if( strtoupper($frag[0])!==$frag[0] ) {
                throw new \Exception( 'Property '.get_class($this).'::'.$n.' does not exist.' ); 
            } else {
                throw $e;
            }
        }
    }
    
    /**
    @brief Shortcut for creating / getting nodes from types.
    
    @param $n The type name
    @param $args Either nothing or an associative array to create a new node
    or an identifier to get an existing one.
    
    @return Node
    
    This allows you to do things like:
    
        $john=$db->Person();
        $john=$db->Person(array("firstName"=>"John"));
        $john=$db->Person(157);
    
    */
    function __call($n,$args) {
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
            if( $pos!==false ) $frag=substr($n,$pos);
            else $frag=$n;
            if( strtoupper($frag[0])!==$frag[0] || count($args)>1) {
                throw new \Exception( 'Method '.get_class($this).'::'.$n.' does not exist.' ); 
            } else {
                throw $e;
            }
        }
        
    }
        
    /**
    @brief Returns a node type by name.
    
    @param $name The type name
    @param $phpns The PHP namespace relatively to which the name is given. This is
    useful only if ypu are extending a node and plan to be inherited from a different
    namespace than yours... so quite advanced stuff.
    
    @return Type
    
    */
    function getType($name,$phpns=null) {
        if( !$name ) throw new \Exception('No name.');
        if( $phpns ) $name=Syntax::typeName($name,'',$phpns);
        else if( $name[0]=='_' ) $name=substr($name,1);
        if( !array_key_exists($name,$this->types) ) {
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
    
    @param $s The query
    @param $params Optional parameters to fill ut the '?' in the query. It can be 
    a single value if there is only one '?', or an array containing several values
    if there are more, in the same order as they appear in the query.
    
    @return an \\Iterable and \\Cuntable result set over the matched nodes.    
    
    */
    function select($s='',$params=null) {
        if( array_key_exists($s,$this->queries) )$q=$this->queries[$s];
        else {
            $q=new Query($s,$this,null);
            $this->queries[$s]=$q;
        }
        return $q->execute($params);
    }
    
    /*
    Set to private in order to hide untyped nodes. 
    
    - Max Jacob 02 2015
    */
    private function _getDataNode($id) {
        return new DataNode($this,(int)$id,$this->untypedNode);
    }
    
    /**
    @brief Gives back the rough MySql connection.
    */
    function getMySqlConnection() {
        return $this->storage->get_mysqli();
    }
    
    /**
    @brief Gives back a node by its id.
    */
    function getNode($i) {
        $id=(int)$i;
        if( $id<=0 ) throw new \Exception('Invalid id: '.$i);
        $l=$this->storage->getTriples($id,'graphene_topType',null,'string');
        if( $l->count()>0 ) {
            $tr=$l[0];
            return $this->getType($tr['ob'])->getNode($id);
        } else {
            return new DataNode($this,$id,$this->untypedNode);
        }
    }
 
    /*
    Set to private in order to hide untyped nodes. 
    
    - Max Jacob 02 2015
    */
    private function newNode($params=null) {
        $id=$this->storage->newId();
        $node=new DataNode($this,$id,$this->untypedNode);
        if( is_array($params) ) {
            foreach( $params as $k=>$v ) {
                $node->set($k,$v);
            }
        }
        return $node;
    }

    function _storage() {
        return $this->storage;
    }

    
}


class UntypedNode extends Type {
    
    function getNode($i) {
        return $this->db()->_getDataNode($i);
    }
    
    function addNode($i,$args=null) {
    }
    
    function removeNode($i) {
    }
    
    
}


