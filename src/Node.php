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

abstract class NodeBase {
    
    private $_type;                           
    
    function __construct($type) {
        $this->_type=$type;
    }
    
    public function type($phpclass=null) {
        if( $phpclass ) {
            return $this->db()->getType('_'.substr(str_replace('\\','_',$phpclass),0,-4));
        }
        return $this->_type;
    }
    
    public function ns() {
        if( $this->_type ) return $this->_type->ns();
    }
    
    function __toString() {
        return 'Node '.$this->id();
    }
    
    abstract function id();
    abstract function db();    

    abstract protected function _mask($mask);
    
    private function validateLang($lang) {
        if( !mb_ereg('^[a-z][a-z](\_[A-Z][A-Z])?',$lang) ) throw new \Exception( 'Invalid language code: "'.$lang.'".' );
    }

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
    
    public function __set($n,$v) { return $this->set($n,$v); }
    public function __get($n) { return $this->get($n); }

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

    public final function update(array $args) {
        foreach( $args as $k=>$v ) {
            $this->$k=$v;
        }
    }

    
}

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
    
    

    private function _initNode() {}
    
    public function properties() {
        return $this->_data->properties();
    }
    
    
    public final function getType($n,$phpns=null) {
        $db=$this->db();
        if( $n[0]=='_' ) return $db->getType($n);
        else {
            if( $phpns ) $ns=str_replace('\\','_',$phpns);
            else $ns=$this->_data->ns();
            return $db->getType($ns?$ns.'_'.$n:$n);
        }
    }
    
    
    public final function getAs($n) {
        return $this->db()->getType($n)->getNode($this->id());
    }
    
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



class Prop implements \ArrayAccess, \Iterator, \Countable  {
    
    private $node;
    private $pred;
    private $dir;
    private $list;
    private $def;
    private $storage;
    private $type;
    private $mask=0;              
    private $wasType=null;
    
    public function __construct( $node, $pred, $dir, $mask=\graphene::ACCESS_FULL, $def=null ) {
        $this->pred=$pred;
        $this->dir=$dir;
        $this->storage=$node->db()->_storage();
        $this->node=$node;
        $this->nodeId=$node->id();
        $this->list=null;
        $this->mask=$mask;
        $this->def=$def;
        if( $dir==1 ) {
            $this->type=$this->storage->predType($pred);
            if( $def ) {
                if( !$this->type ) {
                    $this->type=$def->type;
                } else {
                    if( !$def->type && !$def->frozen ) {
                        $def->type=$this->type;
                        $node->db()->getType($def->sourceType)->_saveDefinition();
                    } else if( $def->type!=$this->type ) {
                        if( !$node->db()->isFrozen() ) {
                            try {
                                $this->node->db()->alterPropertyType($this->pred,$def->type);
                            } catch( \Exception $e ) {
                                throw new \Exception( 'Impossible to convert '.$this->pred.' to '.$def->type.': '.$e->getMessage()."\nPlease reset the definition to ".$this->type.' and try to fix the data...' );
                            }
                            $this->type=$def->type;
                        } else {
                            throw new \Exception( 'Definition of '.$this->pred.' conflicts with type in database.' );
                        }
                    }
                }
            }
        } else {
            $this->type='node';
        }
    }
    

    
    private function unpackDatum( &$datum ) {
        if( is_null($datum) ) {
            throw new \Exception( 'Datum is NULL.' );
        }
        $this->wasType=null;
        if( $datum instanceof NodeBase ) {
            $this->wasType=$datum->type();
            $datum=$datum->id();
            $type='node';
        } else if( is_int($datum) ) {
            $type='int';
        } else if( is_float($datum) ) {
            $type='float';
        } else if( is_string($datum) ) {
            $type='string';
        } else if( $datum instanceof \DateTime ) {
            $datum=$datum->format('Y-m-d H:i:s');
            $type='datetime';
        } else if( is_bool($datum) ) {
            $datum=$datum?1:0;
            $type='int';
        } else throw new \Exception( 'Unknown datatype.' );
        if( !is_null($this->type) ) {
            if( $this->type!=$type ) {
                $this->storage->castDatum($datum,$type,$this->type);
            }
        } else {
            $this->type=$type;
            if( $this->def && !$this->def->frozen ) {
                $this->def->type=$type;
                $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
            }
        }
        return $this->type;
    }
    
    public function packDatum($datum,$type) {
        switch( $type ) {
            case 'node':
                return $this->node->db()->getNode($datum);
            case 'datetime': 
                if( is_int($datum) ) {
                    $dt=new \DateTime();
                    $dt->setTimestamp((int)$datum);
                    return $dt;
                }
                return new \DateTime($datum);
            case 'int': return (int)$datum;
            case 'float': return (float)$datum;
            default: return $datum;
        }
    }
    
    private function valueToString($v) {
        if( is_null($v) ) return '';
        switch( $this->type ) {
            case 'node':
                return 'Node '.$v->id();
            case 'int':
            case 'float':
                return ''.$v;
            case 'string':
                return $v;
            case 'datetime':
                return $v->format('Y-m-d H:i:s');
            default:
                return $v;
        }
    }
    
    public function __toString() {
        
        if( $this->def && $this->def->isList ) return $this->join(',');
        else return $this->valueToString($this->getAt(0));
        
    }
    
    public function val() {
        return $this->getAt(0);
    }

    private function inErr($def=null,$nodeId=null) {
        if( is_null($def) ) $def=$this->def;
        if( is_null($nodeId) ) $nodeId=$this->nodeId;
        if( $def ) return $def->properName.' ('.$def->sourceType.'#'.$nodeId.')';
        else return ($this->dir==1?'':'@').$this->predName.' (node #'.$nodeId.')';
    }

    private function validateNodeDelete($l,$update=false) {
        $tr=$l->current();
        if( $this->dir==1 ) $id=$tr['ob'];
        else $id=$tr['sub'];
        $db=$this->node->db();
        if( $this->dir==1 ) $iname='@_'.$this->pred;
        else $iname='_'.$this->pred;
        foreach( $this->storage->getTriples($id,'graphene_topType',null,'string') as $tr ) {
            $type=$db->getType($tr['ob']);
            $idef=$type->_findDef($iname,false);
            if( !$idef ) continue;
            $inode=$type->getNode($id);
            if( $this->node instanceof DataNode && $this->def && $idef->sourceType==$this->def->sourceType ) $mask=\graphene::ACCESS_FULL;
            else $mask=$idef->mask;
            $iprop=new Prop($inode,$iname,-$this->dir,$mask,$idef);
            if( !($iprop->mask&\graphene::ACCESS_DELETE) && !$this->node->db()->_isDeleting($inode->id()) ) {
                if( !$idef->frozen ) {
                    $idef->mask|=\graphene::ACCESS_FULL;
                    $this->node->db()->getType($idef->sourceType)->_saveDefinition();
                } else {
                    throw new \Exception( 'Can not read property '.$this->inErr() );
                }
            }
            if( $idef->required && $iprop->count()==1 ) {
                $iprop->requiredViolation();
            }
            if( !$update && $idef->deleteTrigger ) $idef->deleteTrigger->invoke($inode,$this->nodeId);
            break;
        }
    }
    
    private function validateNodeUpdate($l,$id) {
        $this->validateNodeDelete($l,true);
        $tr=$l->current();
        if( $this->dir==1 ) $old=$tr['ob'];
        else $old=$tr['sub'];
        $this->validateNodeInsert($id,$old);
    }

    
    private function validateNodeInsert($id,$update=null) {
        if( $this->def ) {
            if( $this->def->nodeType ) {
                if( !$this->wasType || $this->wasType->name()!=$this->def->nodeType ) {
                    $l=$this->storage->getTriples($id,'graphene_type',$this->def->nodeType,'string');
                    $l->rewind();
                    if( !$l->valid() ) {
                        throw new \Exception( 'Node #'.$id.' is not of type '.$this->def->nodeType.' as expected for property '.$this->inErr().'.' ); 
                    }
                }
            } else if( !$this->def->frozen && $this->wasType ) {
                if( $this->wasType ) {
                    $this->def->nodeType=$this->wasType->name();
                    $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
                } else {
                    $l=$this->storage->getTriples($id,'graphene_topType',null,'string');
                    $l->rewind();
                    if( $l->valid() ) {
                        $tr=$l->current();
                        $this->def->nodeType=$tr['ob'];
                        $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
                    }
                }
            }
        }
        $db=$this->node->db();
        if( $this->dir==1 ) $iname='@_'.$this->pred;
        else $iname='_'.$this->pred;
        if( $this->wasType ) {
            $idef=$this->wasType->_findDef($iname);
            $type=$this->wasType;
        }
        if( !$idef ) {
            foreach( $this->storage->getTriples($id,'graphene_topType',null,'string') as $tr ) {
                $type=$db->getType($tr['ob']);
                $idef=$type->_findDef($iname,false);
                if( !$idef ) continue;
                break;
            }
        }
        if( $idef ) {
            $inode=$type->getNode($id);
            if( $idef->nodeType ) {
                if( !$this->wasType || $this->wasType->name()!=$idef->nodeType ) {
                    $l=$this->storage->getTriples($this->nodeId,'graphene_type',$idef->nodeType,'string');
                    $l->rewind();
                    if( !$l->valid() ) throw new \Exception( 'Node #'.$this->nodeId.' is not of type '.$idef->nodeType.' as expected for property '.$this->inErr($idef,$id).'.' ); 
                }
            } else {
                if( !$idef->frozen ) {
                    $myType=$this->node->type();
                    if( $myType ) {
                        $idef->nodeType=$this->node->type()->name();
                        $type->_saveDefinition();
                    } else {
                        $l=$this->storage->getTriples($this->nodeId,'graphene_topType',null,'string');
                        $l->rewind();
                        if( $l->valid() ) {
                            $tr=$l->current();
                            $idef->nodeType=$tr['ob'];
                            $type->_saveDefinition();
                        }
                    }
                }
            }
            if( $this->node instanceof DataNode && $this->def && $idef->sourceType==$this->def->sourceType ) $mask=\graphene::ACCESS_FULL;
            else $mask=$idef->mask;
            $iprop=new Prop($inode,$idef->predName,-$this->dir,$mask,$idef);
            if( $idef->validator ) {
                $idef->validator->invoke($inode,$this->nodeId);
            }
            $iprop->validateInsert($this->nodeId);
            if( !$idef->isList && $iprop->count() ) {
                throw new \Exception( 'Property '.$iprop->inErr().' is single valued.' );
            }
            if( is_null($update) ) {
                if( $idef->insertTrigger ) $idef->insertTrigger->invoke($inode,$this->nodeId);
            } else {
                if( $idef->updateTrigger ) $idef->updateTrigger->invoke($inode,$this->nodeId,$update);
            }
        }
        
    }
    
    
    private function _read($l) {
        if( !($this->mask&\graphene::ACCESS_READ) ) {
            if( $this->def && !$this->def->frozen ) {
                $this->def->mask|=\graphene::ACCESS_READ;
                $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
            } else {
                throw new \Exception( 'Can not read property '.$this->inErr() );
            }
        }
        $tr=$l->current();
        if( $this->dir==1 ) return $this->packDatum($tr['ob'],$tr['type']);
        else return $this->node->db()->getNode($tr['sub']);
    }
    
    private function validateUpdate($l,&$v,$resetting) {
        if( $this->def ) {
            if( $this->def->unique ) {
                if( $this->dir==1 ) $tl=$this->storage->getTriples(null,$this->pred,$v,$this->type);
                else $tl=$this->storage->getTriples($v,$this->pred,null,$this->type);
                $tl->rewind();
                if( $tl->valid() ) {
                    $violation=false;
                    if( $tl->key()==$l->key() ) return true;
                    if( $resetting ) {
                        $ok=$this->dir==1?'sub_ok':'ob_ok';
                        $tltr=$tl->current();
                        $ltr=$l->current();
                        if( $tltr[$ok]<$ltr[$ok] ) {
                            $violation=true;
                        }
                    } else {
                        $violation=true;
                    }
                    if( $violation ) {
                        if( $this->def && !$this->def->frozen ) {
                            $this->def->unique=0;
                            $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
                        } else {
                            throw new \Exception( 'Value "'.$v.'" is not unique on property '.$this->inErr().'.' );
                        }
                    }
                }
            } else if( !$this->def->repetitions ) {
                if( $this->dir==1 ) $tl=$this->storage->getTriples($this->nodeId,$this->pred,$v,$this->type);
                else $tl=$this->storage->getTriples($v,$this->pred,$this->nodeId,$this->type);
                $tl->rewind();
                if( $tl->valid() ) {
                    if( $tl->key()==$l->key() ) return true;
                    if( $resetting ) {
                        $ok=$this->dir==1?'sub_ok':'ob_ok';
                        $tltr=$tl->current();
                        $ltr=$l->current();
                        if( $tltr[$ok]<$ltr[$ok] ) {
                            throw new \Exception( 'Value "'.$v.'" is repeated on property '.$this->inErr().'.' );
                        }
                    } else {
                        throw new \Exception( 'Value "'.$v.'" is repeated on property '.$this->inErr().'.' );
                    }
                }
            }
        }
        if( !($this->mask&\graphene::ACCESS_UPDATE) ) {
            if( $this->def && !$this->def->frozen ) {
                $this->def->mask|=\graphene::ACCESS_FULL;
                $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
            } else {
                throw new \Exception( 'Can not update property '.$this->inErr().'.' );
            }
        }
    }

    private function validateInsert($v) {
        if( !($this->mask&\graphene::ACCESS_INSERT) ) {
            if( $this->def && !$this->def->frozen ) {
                $this->def->mask|=\graphene::ACCESS_FULL;
                $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
            } else {
                throw new \Exception( 'Can not insert on property '.$this->inErr().'.' );
            }
        }
        if( $this->def ) {
            if( $this->def->unique ) {
                if( $this->dir==1 ) $l=$this->storage->getTriples(null,$this->pred,$v,$this->type);
                else $l=$this->storage->getTriples($v,$this->pred,null,$this->type);
                $l->rewind();
                if( $l->valid() ) {
                    if( $this->def && !$this->def->frozen ) {
                        $this->def->unique=0;
                        $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
                    } else {
                        throw new \Exception( 'Value "'.$v.'" is not unique on property '.$this->inErr().'.' );
                    }
                }
            } else if( !$this->def->repetitions ) {
                if( $this->dir==1 ) $l=$this->storage->getTriples($this->nodeId,$this->pred,$v,$this->type);
                else $l=$this->storage->getTriples($v,$this->pred,$this->nodeId,$this->type);
                $l->rewind();
                if( $l->valid() ) {
                    if( $this->def->frozen ) {
                        throw new \Exception( 'Value "'.$v.'" is repeated on property '.$this->inErr().'.' );
                    } else {
                        $this->def->repetitions=1;
                        $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
                    }
                }
            }
        }
    }
    
    private function _update($l,$v,$resetting=false) {
        if( Syntax::isEmpty($v) ) throw new \Exception( 'Can not insert empty value into '.$this->inErr().'.' );
        if( $this->def && $this->def->validator && $this->node instanceof Node ) {
            if( $this->type!='node' ) $this->def->validator->invokeArgs($this->node,array(&$v));
            else $this->def->validator->invoke($this->node,$v);
            if( Syntax::isEmpty($v) ) return $this->_delete($l);
        }
        if( $this->validateUpdate($l,$v,$resetting) ) return;
        if( $this->type=='node' ) $this->validateNodeUpdate($l,$v);
        $tr=$l->current();
        if( $this->dir==1 ) {
            $stid=$this->storage->update($l->key(),null,null,$v,$this->type);
        } else {                    
            $stid=$this->storage->update($l->key(),$v,null,null,'node');
        }
        if( $this->def && $this->def->updateTrigger && $this->node instanceof Node ) {
            $this->def->updateTrigger->invoke($this->node,$v,$this->dir==1?$tr['ob']:$tr['sub']);
        }
        return $stid;
    }
                                              
    private function _insert($v,$beforeId=null) {
        if( Syntax::isEmpty($v) ) throw new \Exception( 'Can not insert empty value in '.$this->inErr().'.' );
        if( $this->def && $this->def->validator && $this->node instanceof Node ) {
            if( $this->type!='node' ) $this->def->validator->invokeArgs($this->node,array(&$v));
            else $this->def->validator->invoke($this->node,$v);
            if( Syntax::isEmpty($v) ) return;
        }
        $this->validateInsert($v);
        if( $this->type=='node' ) $this->validateNodeInsert($v);
        if( $this->dir==1 ) {
            $stid=$this->storage->insert($this->nodeId,$this->pred,$v,$this->type,$beforeId,null);
        } else {
            $stid=$this->storage->insert($v,$this->pred,$this->nodeId,'node',null,$beforeId);
        }
        if( $this->def && $this->def->insertTrigger && $this->node instanceof Node ) $this->def->insertTrigger->invoke($this->node,$v);
        return $stid;
    }
    
    
    private function _delete($l,$requiredChecked=false) {
        if( !($this->mask&\graphene::ACCESS_DELETE) && !$this->node->db()->_isDeleting($this->nodeId) ) {
            if( $this->def && !$this->def->frozen ) {
                $this->def->mask|=\graphene::ACCESS_FULL;
                $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
            } else {
                throw new \Exception( 'Can not delete from '.$this->inErr().'.' );
            }
        }
        if( !$requiredChecked && $this->def && $this->def->required && $this->getList()->count()==1 ) {
            $this->requiredViolation();
        }
        if( $this->type=='node' ) $this->validateNodeDelete($l);
        if( $this->dir==1 ) {
            $stid=$this->storage->remove($l->key(),$this->pred);
        } else {
            $stid=$this->storage->remove($l->key(),$this->pred);
        }
        if( $this->def && $this->def->deleteTrigger && $this->node instanceof Node ) {
            $tr=$l->current();
            if( $this->dir==1 ) $v=$tr['ob'];
            else $v=$tr['sub'];
            $this->def->deleteTrigger->invoke($this->node,$v);
        }
        return $stid;
    }
    
        
    private function getList() {                                                 
        if( is_null($this->list) ) {
            if( !$this->storage || !($this->storage instanceof Storage)) throw new Exception('No storage.');
            if( $this->dir==1 ) $this->list=$this->storage->getTriples($this->nodeId,$this->pred,null,null);
            else $this->list=$this->storage->getTriples(null,$this->pred,$this->nodeId,'node');
        }                       
        return $this->list;
    }
    
    function getAt($idx=0) {
        $l=$this->getList();
        $l->seek($idx);
        if( $l->valid() ) {
            return $this->_read($l);
        }
    }

    
    public function join($sep=',') {
        $s='';
        $sp='';
        foreach( $this as $v ) {
            $s.=$sp.$this->valueToString($v);
            $sp=$sep;
        }
        return $s;
    }

    public function toArray() {
        $res=array();
        foreach( $this as $v ) $res[]=$v;
        return $res;
    }
    
    function setAt($v,$idx=0) {
        $count=$this->count();
        if( $idx==$count ) {
            return $this->append($v);
        } else if( $idx<$count ) {
            $this->getList()->seek($idx);
            if( $this->list->valid() ) {
                $this->unpackDatum($v);
                if( Syntax::isEmpty($v) ) {                    
                    if( $this->def && $this->def->required && $idx==0 && $this->list->count()==1 ) $this->requiredViolation();
                    return $this->_delete($this->list,true);
                }
                return $this->_update($this->list,$v);
            }
        } else throw new \Exception( 'Offset '.$idx.' is out of range.' );
    }
    
    private function normalizeNode(&$v) {
        if( $v instanceof NodeBase ) {
            $this->wasType=$v->type();
            $v=$v->id();
        }
        else if( is_numeric($v) ) {
            $this->wasType=null;
            $v=(int)$v;
        }
        else throw new \Exception( 'Node expected.' );
    }
    
    
    function append($v) {
        $this->list=null;
        if( !is_array($v) ) {
            $v=array($v);
            $count=1;
        } else {
            $count=count($v);
        }
        if( $count ) {
            for( $i=0; $i<$count; ++$i ) {
                $this->unpackDatum($v[$i]);
                $stid=$this->_insert($v[$i]);
            }
        }
        return $stid;
    }
    
    function reset($v) {
        if( !is_array($v) ) {
            $v=array($v);
        } 
        $count=count($v);
        $l=$this->getList();
        $l->rewind();
        for( $i=0; $i<$count; ++$i ) {
            $this->unpackDatum($v[$i]);
            if( $l->valid() ) {
                $stid=$this->_update($l,$v[$i],true);
                $l->next();
            } else {
                $stid=$this->_insert($v[$i]);
            }
        }
        if( $this->def && $this->def->required && $count==0 ) $this->requiredViolation();
        while( $l->valid() ) {
            $this->_delete($l,true);
            $l->next();
        }
        $this->list=null;
        return $stid;
    }
    

    function add($v) {
        if( $this->dir==1 ) {
            $this->unpackDatum($v);
            $l=$this->storage->getTriples($this->nodeId,$this->pred,$v,$this->type);
        } else {
            $this->normalizeNode($v);
            $l=$this->storage->getTriples($v,$this->pred,$this->nodeId,'node');
        }
        $l->rewind();
        if( !$l->valid() ) {
            $this->list=null;
            return $this->_insert($v,null);
        }
    }
    
    function remove($v) {
        if( $this->dir==1 ) {
            $this->unpackDatum($v);
            $l=$this->storage->getTriples($this->nodeId,$this->pred,$v,$this->type);
        } else {
            $this->normalizeNode($v);
            $l=$this->storage->getTriples($v,$this->pred,$this->nodeId,'node');
        }
        $l->rewind();
        if( $l->valid() ) {
            $this->list=null;
            return $this->_delete($l);
        }
    }
    
    private function requiredViolation() {
        if( !$this->def->frozen ) {
            $this->def->required=0;
            $this->node->db()->getType($this->def->sourceType)->_saveDefinition();
        } else {
            throw new \Exception( 'Property '.$this->inErr().' is required, can not delete.' );        
        }
    }
    
    function delete() {
        if( $this->def && $this->def->required && !$this->node->db()->_isDeleting($this->nodeId) ) $this->requiredViolation();
        if( $this->dir==1 ) {
            $l=$this->storage->getTriples($this->nodeId,$this->pred,null,null);
        } else {
            $l=$this->storage->getTriples(null,$this->pred,$this->nodeId,'node');
        }
        $l->rewind();
        $stid=null;
        while( $l->valid() ) {
            $stid=$l->key();
            $this->_delete($l,true);
            $l->next();
        }
        return $stid;
    }
    
    
    function contains($v) {
        return $this->tripleIdOf($v)!==false;
    }
    
    /**
    * @brief PHP Iterator's valid() function.
    *
    * Tells, after having called rewind() or next(), if the iterator is still pointing to an entry.
    *
    * @return true or false
    */
    function valid() { 
        return $this->getList()->valid(); 
    }

    /**
    * @brief PHP Iterator's current() function.
    *
    * Returns the value at the current position when iterating. 
    *
    * @return the value at the current position.
    *
    */
    function current() {
        return $this->_read($this->getList());
    }
    
    /**
    * @brief PHP Iterator's key() function.
    * 
    * Returns the current triple id.
    *
    * @return the current triple id
    */
    function key() { return $this->getList()->key(); }

    /**
    * @brief PHP Iterator's rewind() function.
    * 
    * Rewinds the iterator to the first entry in the list.
    *
    */
    function rewind() { 
        return $this->getList()->rewind(); 
    }
    
    /**
    * @brief PHP Iterator's next() function.
    * 
    * Moves the iterator forward to the next entry.
    *
    */
    function next() { 
        return $this->getList()->next(); 
    }

    /**
    * @brief PHP Contable's cont() function.
    * 
    * Tells how many entries the property has.
    *
    */
    function count() { return $this->getList()->count(); }
    
    function offsetExists($offs) {
        return $this->getList()->offsetExists($offs); 
    }

    function offsetGet($offs) {
        return $this->getAt($offs);
    }
    
    
    private function tripleIdAt($offs) {
        $this->getList()->seek($offs);
        if( $this->list->valid() ) return $this->list->key();
    }

    private function tripleIdOf($v) {
        if( $this->dir==1 ) {
            $this->unpackDatum($v);
            $l=$this->storage->getTriples($this->nodeId,$this->pred,$v,$this->type);
            $l->rewind();
            if( $l->valid() ) return $l->key();
        } else {
            $this->normalizeNode($v);
            $l=$this->storage->getTriples($v,$this->pred,$this->nodeId,'node');
            $l->rewind();
            if( $l->valid() ) return $l->key();
        }
        return false;
    }
    
    function offsetSet($offs,$val) {
        if( is_null($offs) ) {
            return $this->append($val);
        } else {
            return $this->setAt($val,$offs);
        }
    }
    
    function offsetUnset($offs) {
        $this->setAt(null,$offs);
    }
    
    private function insertBeforeTriple($tr,$v) {
        $this->list=null;
        $this->unpackDatum($v);
        $this->_insert($v,$tr);
    }

    function prepend($v,$idx=0) {
        $tid=$this->tripleIdAt($idx);
        if( $tid ) return $this->insertBeforeTriple($tid,$v);
        else return $this->append($v);
    }
    
    private function insertAfterTriple($tr,$v) {
        if( $this->dir==1 ) {
            $id=$this->storage->nextObjectTripleId($tr,$this->pred);
        } else {
            $id=$this->storage->nextSubjectTripleId($tr,$this->pred);            
        }
        if( $id ) return $this->insertBeforeTriple($id,$v);
        else $this->append($v);
    }

    private function moveValueBefore($val,$ref) {
        $tr=$this->tripleIdOf($val);
        if( $tr ) {
            $ref=$this->tripleIdOf($ref);
            if( $ref ) {
                $this->list=null;
                if( $this->dir==1 ) return $this->storage->moveBeforeObject($ref,$tr,$this->pred);
                else return $this->storage->moveBeforeSubject($ref,$tr,$this->pred);
            }
        }
    }

    private function moveTripleBefore($tr,$ref) {
        $this->list=null;
        $this->normalizeNode($tr);
        $this->normalizeNode($ref);
        if( $this->dir==1 ) return $this->storage->moveBeforeObject($ref,$tr,$this->pred);
        else return $this->storage->moveBeforeSubject($ref,$tr,$this->pred);
    }
    
    private function moveValueAfter($val,$ref) {
        $tr=$this->tripleIdOf($val);
        if( $tr ) {
            $ref=$this->tripleIdOf($ref);
            if( $ref ) {
                $this->list=null;
                if( $this->dir==1 ) {
                    $ref=$this->storage->nextObjectTripleId($ref,$this->pred);
                    return $this->storage->moveBeforeObject($ref,$tr,$this->pred);
                } else {
                    $ref=$this->storage->nextSubjectTripleId($ref,$this->pred);
                    return $this->storage->moveBeforeSubject($ref,$tr,$this->pred);
                }
            }
        }
    }
    
    private function moveTripleAfter($tr,$ref) {
        $this->list=null;
        $this->normalizeNode($tr);
        $this->normalizeNode($ref);
        if( $this->dir==1 ) {
            $ref=$this->storage->nextObjectTripleId($ref,$this->pred);
            return $this->storage->moveBeforeObject($ref,$tr,$this->pred);
        } else {
            $ref=$this->storage->nextSubjectTripleId($ref,$this->pred);
            return $this->storage->moveBeforeSubject($ref,$tr,$this->pred);
        }
    }
    
    private function moveTripleTo($tr,$pos) {
        $ref=$this->tripleIdAt($pos);
        if( $ref ) $this->moveTripleBefore($tr,$ref);
    }
    
    public function select($filter=null,$params=null) {
        if( $this->type!='node' ) throw new \Exception( 'Can select only on node properties and '.$this->inErr().' is not.' );
        $iname=($this->dir==1?'@_':'_').$this->pred;
        
        $db=$this->node->db();
        if( $this->def && $this->def->nodeType ) {
            $ntype=$db->getType($this->def->nodeType);
        } else {
            $ntype=null;
        }
        $q=new Query($filter,$db,$ntype);
        $q->addConstraint($iname.'#source='.$this->nodeId);
        return $q->execute($params);
    }

}



