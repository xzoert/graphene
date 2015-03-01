<?php

/**
NOTE: the whole code here is still open to the possibility of having untyped nodes. 
This feature is not yet made available since it hasn't been tested enough and as well 
because the use cases aren't yet very clear to me.

- Max Jacob 02 2015
*/


/** class graphene\Connection

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
    
    function close() {
        try {
            $this->storage->closeConnection();
        } catch( \Exception $e ) {}
        \graphene::_close($this->id);
    }
    
    function isFrozen() {
        return $this->frozen;
    }
    
    function freeze() {
        $this->frozen=true;                        
    }
    
    function unfreeze() {
        $this->frozen=false;
    }

    function begin() {
        if( $this->transaction_level===0 ) $this->storage->start_writing();
        $this->transaction_level++;
    }

    public function commit() {
        if( $this->transaction_level>0 ) {
            $this->transaction_level--;
            if( $this->transaction_level===0 ) $this->storage->commit();
        }
    }
    
    public function rollback() {
        if( $this->transaction_level>0 ) {
            $this->storage->rollback();
            $this->transaction_level=0;
        }
    }
    
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
        
    function getType($name) {
        if( !$name ) throw new \Exception('No name.');
        if( $name[0]=='_' ) $name=substr($name,1);
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
    
    function select($s='',$params=null) {
        if( array_key_exists($s,$this->queries) )$q=$this->queries[$s];
        else {
            $q=new Query($s,$this,null);
            $this->queries[$s]=$q;
        }
        return $q->execute($params);
    }
    
    /**
    Set to private in order to hide untyped nodes. 
    
    - Max Jacob 02 2015
    */
    private function _getDataNode($id) {
        return new DataNode($this,(int)$id,$this->untypedNode);
    }
    
    function getMySqlConnection() {
        return $this->storage->get_mysqli();
    }
    
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
    
    function newNode($params=null) {
        $id=$this->storage->newId();
        $node=new DataNode($this,$id,$this->untypedNode);
        if( is_array($params) ) {
            foreach( $params as $k=>$v ) {
                $node->set($k,$v);
            }
        }
        return $node;
    }

    public function offsetExists( $offset ) {
        return 1;    
    }
    
    public function offsetGet ( $offset ) {
        return $this->type($offset);
    }
    
    public function offsetSet( $offset, $value ) {
        throw new \Exception( 'Can not set types.' );
    }
    
    public function offsetUnset( $offset ) {
        throw new \Exception( 'Can not unset types.' );
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


