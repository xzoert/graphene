<?php

/**
NOTE: This class is still in slug_case as it was at the origins.
However all functions called by the higher level (Connection, Type, Node, Def etc.)
have been wrapped in their camelCase version, and this should ensure that adapting this code 
to the camelCase style should not affect them. 

- Max Jacob 02 2015
*/

/**
NOTE: The whole structure Storage -> DbStorage -> MySql has been created
having in mind the possibility to create drivers for different backends than MySql
as well as for in-memory storages, which would extend Storage but not DbStorage.

- Max Jacob 02 2015
*/

namespace graphene;

require_once 'Node.php';

abstract class Storage {

    
    /**
    *    Returns an instance of storage_iterator.
    *    @param $sub If not null, the subject of the targeted triples;
    *    @param $pred If not null the predicate name of the targeted triples
    *    @param $ob If not null, the value of the object of the targeted triples
    *    @param $type The type of the object (taken into account only if the object is set, in which case it is mandatory)
    */
    public abstract function get_triples($sub,$pred,$ob,$type);
    public function getTriples($sub,$pred,$ob,$type) {
        return $this->get_triples($sub,$pred,$ob,$type);
    }

    public abstract function pred_type($pred);
    
    public function predType($pred) {
        return $this->pred_type($pred);
    }
    
    public abstract function insert($sub,$pred,$ob,$type,$before_ob_id=null,$before_sub_id=null);

    public abstract function update( $id, $nsub, $npred, $nob, $ntype );
    
    public abstract function remove($id,$pred=null);
    
    
    public abstract function next_object_triple_id($ref,$pred=null);
    public function nextObjectTripleId($ref,$pred=null) {
        return $this->next_object_triple_id($ref,$pred);
    }

    public abstract function next_subject_triple_id($ref,$pred=null);
    public function nextSubjectTripleId($ref,$pred=null) {
        return $this->next_subject_triple_id($ref,$pred);
    }
    
    public abstract function move_before_object($ref,$id,$pred=null);
    public function moveBeforeObject($ref,$id,$pred=null) {
        return $this->move_before_object($ref,$pred);
    }
    
    public abstract function move_before_subject($ref,$id,$pred=null);
    public function moveBeforeSubject($ref,$id,$pred=null) {
        return $this->move_before_subject($ref,$pred);
    }
    
    protected abstract function new_id();
    
    function newId() {
        return $this->new_id();
    }
    
    protected function node_accessed($id) {
        
    }
    
    protected function node_modified($id) {
        
    }
    
    public function node_predicates($id,$forward_only=0) {
        
    }
    
    public function parse_name($n) {
        $key=$n;                                            
        if(array_key_exists($key, self::$preds)) {
            return self::$preds[$key];
        }
        $r = array();
        if(mb_ereg('^(\<)?\>?([^\>\<\.\s\"\'\+\-\/\*\=\&\|\^0-9][^\>\<\.\s\"\'\+\-\/\*\=\&\|\^]*)$', $n, $r)) {
            if($r[1]) {
                $dir = -1;
            }
            else {
                $dir = 1;
            }
            $pred = $r[2];
            $e = array('pred' => $pred, 'dir' => $dir);
            self::$preds[$key] = $e;
            return $e;
        } else {
            throw new Exception('wrong name: '.$n);
        }
    }
    
    function parse_path($n) {
        if(array_key_exists($n,self::$pred_expanded)) {
            return self::$pred_expanded[$n];
        }
        $s=$n;
        $arr=array();
        
        while( mb_ereg('([\.\<\>]+)?([^\>\<\.\s\"\'\+\-\/\*\=\&\|\^0-9][^\>\<\.\s\"\'\+\-\/\*\=\&\|\^]*)(.*)?',$s,$r) ) {
            switch( $r[1] ) {
                case '':
                case '.':
                case '>':
                case '.>':
                    $dir=1;
                    break;
                case '<':
                case '.<':
                    $dir=-1;
                    break;
                default:
                    throw new Exception('Wrong path: %s.',$n);
            }
            $arr[]=array('dir'=>$dir,'pred'=>$r[2]);
            $s=$r[3];
        }
        return $arr;
    }

    
    function node($id) {
        if( is_null($id) ) {
            return new Node($this,$this->new_id());
        } else {
            $id=(int)$id;
            if( $id<1 ) throw new \Exception( 'wrong-id' );
            return new Node($this,$id);
        }
    }
    
    public function pack_datum($datum,$type,$db,$ntype) {
        switch( $type ) {
            case 'node': {
                if( $ntype ) return $ntype->getNode($datum); 
                return $db->getNode($datum);
            }
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
    
    
    public function cast_datum(&$datum,$type,$target_type) {
        switch( $target_type ) {
            case 'int':
            case 'node':
                switch( $type ) {
                    case 'int':
                    case 'node':
                        break;
                    case 'float':
                        $datum=(int)$datum;
                        break;
                    case 'string':
                        //if( is_numeric($datum) ) {
                            $ndatum=(int)$datum;
                            if( $target_type=='node' && $ndatum<1 ) throw new \Exception( 'Wrong id: '.$datum.'.' );
                            $datum=$ndatum;
                            break;
                        //}
                    case 'datetime':
                        throw new \Exception( 'Can not cast "'.$type.'" to "'.$target_type.'".' );
                }
                break;
            case 'float':
                switch( $type ) {
                    case 'int':
                    case 'node':
                        $datum=(float)$datum;
                        break;
                    case 'string':
                        //if( is_numeric($datum) ) {
                            $datum=(float)$datum;
                            break;
                        //}
                    case 'datetime':
                        throw new \Exception( 'Can not cast "'.$type.'" to "'.$target_type.'".' );
                }
                break;
            case 'datetime':
                switch( $type ) {
                    case 'string':
                        try {
                            if( $test=new \DateTime($datum) ) {
                                $datum=$test->format('Y-m-d h:i:s');
                                break;
                            }
                        } catch( Exception $e ) {}
                        throw new \Exception( 'Can not cast "'.$type.'" to "'.$target_type.'".' );
                }
                break;
            case 'string':
                switch( $type ) {
                    case 'int':
                    case 'float':
                        $datum=''.$datum;
                        break;
                    case 'node':
                        $datum='Node '.$datum;
                    case 'datetime':
                        $d=new \DateTime($datum);
                        $datum=$d->format('Y-m-d h:i:s');
                }
                break;
        }
    }
    
    public function castDatum(&$datum,$type,$targetType) {
        return $this->cast_datum($datum,$type,$targetType);
    }
    
    public function is_assoc(&$arr) {
        return is_array($arr) && range(0,count($arr)-1)!==array_keys($arr);    
    }
    
    
}

abstract class StorageIterator implements \ArrayAccess, \SeekableIterator, \Countable {

    /*

    function rewind() {
        
    }
    function valid(){
        
    }
    function next(){
        
    }
    */
    
    /*
    *    Must return an array in the form:
    *        id: the triple identifier
    *        sub: the id of the subject
    *        pred: the predicate name
    *        ob: the value of the object
    */
    
    abstract function get_triple();
    function getTriple() {
        return $this->get_triple();
    }
    
    public function current() { return $this->get_triple(); }
    
    public function key() { $t=$this->get_triple(); return $t['id']; }
    
    function offsetExists($offs) {
        return $this->count()-1>$offs;
    }
    
    function offsetGet($offs) {
        $this->seek($offs);
        if( $this->valid() ) return $this->current();
    }
    
    function offsetSet($offs,$value) {
        throw new \Exception('Set not implemented.');
    }

    function offsetUnset($offs) {
        throw new \Exception('Unset not implemented.');
    }
    
    
    // generic implementations relying only on the Iterator functions
    // should be overloaded if possible
    function seek($pos) {
        $this->rewind();
        while( $this->valid() && $pos ) {
            $pos--;
            $this->next();
        }
    }
    
    // generic implementations relying only on the Iterator functions
    // should be overloaded if possible
    function count() {
        $count=0;
        $this->rewind();
        while( $this->valid() ) {
            $count++;
            $this->next();
        }
        return $count;
    }
    
}


