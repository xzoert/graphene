<?php


/**
NOTE: the node delete function here is still open to the possibility of any node to be added 
to any type and thus have multiple types, or at least it should... 
this feature is not yet made available since it hasn't been tested enough and as well 
because the use cases aren't yet very clear to me.
If we decide to drop this feature, the code can be reviewed for optimization. 
*/

namespace graphene;

require_once 'Syntax.php';
require_once 'Prop.php';

/**
@brief Base class for graphene nodes.
*/
abstract class NodeBase {
    
    private $_type;                           
    
    function __construct($type) {
        $this->_type=$type;
    }
    
    /**
    @brief Gives back the node's type.
    */
    public function type($phpclass=null) {
        if( $phpclass ) {
            return $this->db()->getType('_'.substr(str_replace('\\','_',$phpclass),0,-4));
        }
        return $this->_type;
    }
    
    /**
    @brief Gives back the node's namespace (in graphene notation).
    */
    public function ns() {
        if( $this->_type ) return $this->_type->ns();
    }
    
    function __toString() {
        return 'Node '.$this->id();
    }
    
    /**
    @brief Gives back the node's id.
    */
    abstract function id();

    /**
    @brief Gives back the node's database Connection.
    */
    abstract function db();    

    /**
    @brief Deletes the node.
    */
    abstract function delete();    

    
    abstract protected function _mask($mask);
    
    private function validateLang($lang) {
        if( !mb_ereg('^[a-z][a-z](\_[A-Z][A-Z])?',$lang) ) throw new \Exception( 'Invalid language code: "'.$lang.'".' );
    }

    /**
    @brief Gives back the translation in a given language.
    */
    public function getTr($n,$lang) {
        $this->validateLang($lang);
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        if( $def->isList ) {
            if( $this->_type->isFrozen() ) throw new \Exception( 'Translations are only supported for single valued properties.' );
            $def->isList=false;
            $this->_type->_saveDefinition();
        }
        $prop=new Prop($this,$def->predName.':'.$lang,$def->dir,$this->_mask($def->mask),$def);
        $r=$prop[0];
        if( is_null($r) ) {
            if( strlen($lang)==5 ) {
                $dprop=new Prop($this,$def->predName.':'.substr($lang,0,2),$def->dir,$this->_mask($def->mask),$def);
                $r=$dprop[0];
                if( !is_null($r) ) return $r;
            }
            $dprop=new Prop($this,$def->predName,$def->dir,$this->_mask($def->mask),$def);
            return $dprop[0];
        }
        return $r;
    }
    
    /**
    @brief Sets the translation in a given language.
    */
    public function setTr($n,$lang,$v) {
        $this->validateLang($lang);
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        if( $def->isList ) {
            if( $def->frozen ) throw new \Exception( 'Translations are only supported for single valued properties.' );
            else {
                $def->isList=false;
                $this->_type->_saveDefinition();
            }
        }
        $mask=$this->_mask($def->mask);
        $prop=new Prop($this,$def->predName.':'.$lang,$def->dir,$mask,$def);
        if( Syntax::isEmpty($v) ) {
            $prop->delete();
        } else if( is_array($v) ) {
            throw new \Exception('Property '.$n.' is single valued, can not set array.');
        } else {
            $prop[0]=$v;
        } 
        $dprop=new Prop($this,$def->predName,$def->dir,$mask,$def);
        if( $dprop->count()==0 ) $dprop[0]=$v;
        if( strlen($lang)==5 ) {
            $dprop=new Prop($this,$def->predName.':'.substr($lang,0,2),$def->dir,$mask,$def);
            if( $dprop->count()==0 ) $dprop[0]=$v;
        }
    }
    
    /**
    @brief Gets a property.
    */
    public function get($n) {
        $pos=strpos($n,':');
        if( $pos!==false ) {
            $s=$n;
            $n=substr($s,0,$pos);
            $lang=substr($s,$pos+1);
            $this->validateLang($lang);
        } else {
            $lang=null;
        }
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        $pred=$def->predName;
        $dir=$def->dir;
        $mask=$def->mask;
        if( $lang ) {
            if( $def && $def->isList ) {
                if( $def->frozen ) throw new \Exception( 'Translations are only supported for single valued properties.' );
                $def->isList=false;
                $this->_type->_saveDefinition();
            }
            $pred=$pred.':'.$lang;
        } 
        $prop=new Prop($this,$pred,$dir,$this->_mask($mask),$def);
        if( !$def || $def->isList ) return $prop;
        else return $prop[0];
    }
    
    /**
    @brief Sets a property.
    */
    public function set($n,$v) {
        $pos=strpos($n,':');
        if( $pos!==false ) return $this->setTr(substr($n,0,$pos),substr($n,$pos+1),$v);
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        $mask=$this->_mask($def->mask);
        $prop=new Prop($this,$def->predName,$def->dir,$mask,$def);
        if( Syntax::isEmpty($v) ) {
            $prop->delete();
        } else if( is_array($v) ) {
            if( !$def->isList ) throw new \Exception('Property '.$n.' is single valued, can not set array.');
            $prop->reset($v);
        } else {
            if( $def->isList && !$def->frozen ) {
                $def->isList=false;
                $this->_type->_saveDefinition();
            }
            $prop[0]=$v;
        } 
    }
    
    /**
    @brief Shortcut for set().
    */
    public function __set($n,$v) { return $this->set($n,$v); }
    /**
    @brief Shortcut for get().
    */
    public function __get($n) { return $this->get($n); }

    /*
    This one is quite meaningless without untyped nodes.
    Probably it should be changed to tell you if the node type
    has a definition for a given property (always true if the type is 
    not frozen).
    
    - Max Jacob 02 2015
    */
    function hasProp($n) {
        $pos=strpos($n,':');
        if( $pos!==false ) {
            $s=$n;
            $n=substr($s,0,$pos);
            $lang=substr($s,$pos+1);
            $this->validateLang($lang);
        } else {
            $lang=null;
        }
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            return;
        }
        $pred=$def->predName;
        if( $lang ) {
            $pred=$pred.':'.$lang;
        } 
        $dir=$def->dir;
        $prop=new Prop($this,$pred,$dir,\graphene::ACCESS_FULL,$def);
        return $prop->count()>0;
    }

    /**
    @brief Lets you set a collection of fields in a single call.
    */
    public final function update(array $args) {
        foreach( $args as $k=>$v ) {
            $this->$k=$v;
        }
    }

    
}

/**
@brief A node.
*/
class Node extends NodeBase {
    
    private $_data;
    
    
    public final function __construct($node,$type) {
        $this->_data=$node;
        parent::__construct($type);
    }
    
    public final function id() {
        return $this->_data->id();
    }
    
    protected final function data() {
        return $this->_data;                                                   
    }
    
    

    /**
    @brief Function to be overloaded by extensions that will be invoked on node creation..
    */
    private function _initNode() {}
    
    public function properties() {
        return $this->_data->properties();
    }
    
    /**
    @brief Gives back a type by name relatively to the node's namespace.
    */
    public final function getType($n,$phpns=null) {
        return $this->db()->getType(Syntax::typeName($n,$this->_data->ns(),$phpns));
            /*
        if( $n[0]=='_' ) return $db->getType($n);
        else {
            if( $phpns ) $ns=str_replace('\\','_',$phpns);
            else $ns=$this->_data->ns();
            return $db->getType($ns?$ns.'_'.$n:$n);
        }
        */
    }
    
    /*
    public final function getAs($n) {
        return $this->db()->getType($n)->getNode($this->id());
    }
    */
    
    /**
    @brief Function to be overloaded by extensions that will be invoked on node deletion..
    */
    private function _cleanupNode() {}
    
    public final function delete() {
        $db=$this->db();
        if( $db->_isDeleting($this->id()) ) return;
        $db->_setDeleting($this->id());
        try {
            foreach( $this->_data->_graphene_topType as $type ) {
                $node=$db->getType($type)->removeNode($this->_data->id());
            }
            $this->_data->delete();
        } catch( \Exception $e ) {
            $db->_unsetDeleting($this->id());
            throw $e;
        }
        $db->_unsetDeleting($this->id());
    }
    
    public final function _callCleanup($rc) {
        $m=$rc->getMethod('_cleanupNode');
        if( $m->getDeclaringClass()->getName()==$rc->getName() ) {
            $m->setAccessible(true);
            $m->invoke($this);
        }
    }

    public final function _callInit($rc) {
        $m=$rc->getMethod('_initNode');
        if( $m->getDeclaringClass()->getName()==$rc->getName() ) {
            $m->setAccessible(true);
            $m->invoke($this);
        }
    }
    
    public final function db() {
        return $this->_data->db();
    }    
    
    protected function _mask($mask) { return $mask; }

}

/**
@brief A data node.
*/
final class DataNode extends NodeBase {
    
    private $id;
    private $db;
                                             
    final function __construct( Connection $db, $id, $type ) {
        if( !$type ) throw new \Exception('No type');
        $this->db=$db;
        $this->id=$id;
        parent::__construct( $type );
    }
    
    
    protected function _mask($mask) { return \graphene::ACCESS_FULL; }
    
    public function id() { return $this->id; }
    
    public function db() { return $this->db; }
    
    function properties($dir=null) {
        return $this->db->_storage()->node_predicates($this->id,$dir);
    }
    
    function delete() {
        $preds=$this->db->_storage()->node_predicates($this->id,null);
        foreach( $preds as $pred=>$c ) {
            if( $pred[0]=='@' ) {
                $dir=-1;
                $pred=substr($pred,1);
            } else {
                $dir=1;
            }
            $prop=new Prop($this,$pred,$dir);
            $prop->delete();
        }
    }
    
    
    function hasType($n) {
        return $this->_data->prop('_graphene_type')->hasValue($n);
    }

    
}



