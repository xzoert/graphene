<?php 


/*
NOTE: This class is still in slug_case as it was at the origins.
However all functions called by the higher level (Connection, Type, Node, Def etc.)
have been wrapped in their camelCase version, and this should ensure that adapting this code 
to the camelCase style should not affect them. 

- Max Jacob 02 2015
*/


namespace graphene;

require_once __DIR__.'/Storage.php';

/* class \graphene\DbStorage

This class extends Storage and does in-memory caching of the nodes got from the database.
It does not contain any SQL, this is demanded to its specializations (as MySql is).

- Max Jacob 02 2015
*/


abstract class DbStorage extends Storage {
    
    abstract function init_db();
    
    protected abstract function load_node($id);
    protected abstract function insert_node($id,$props);
    protected abstract function update_node($id,$props);
    protected abstract function remove_node($id);
    protected abstract function lock_node($id);
                                                                           
    protected abstract function load_triple($id,$type);
    protected abstract function insert_triple($triple);
    protected abstract function update_triple($triple);
    protected abstract function remove_triple($id,$type);
         
    protected abstract function get_triple_predicate($id);
    protected abstract function update_triple_predicate($id,$pred);
    protected abstract function remove_triple_predicate($id);
    
    protected abstract function load_pred($name);
    protected abstract function update_pred($name,$type);
    protected abstract function insert_pred($name,$type);
            
    protected abstract function get_triple_list( $types, $sub, $pred, $ob, $sub_ok, $ob_ok );    
    protected abstract function get_next_object_triple_id($triple);
    protected abstract function get_next_subject_triple_id($triple);
    protected abstract function get_last_ob_ok($sub,$pred,$type);
    protected abstract function get_last_sub_ok($ob,$pred,$type);
    
    protected abstract function write_start();
    protected abstract function write_end($commit);
    
    public abstract function closeConnection();

    private $node_cache_list=array();
    private $node_cache_idx=array();
    private $node_cache_size=256;
    private $triple_cache=array();
    private $pred_cache=array();
    protected $write_mode=0;
    private $record_nodes=0;                                    
    private $init_data=null;
    private $accessed_nodes=array();
    protected $read_only=0;
    
    private static $events;
    
    public function __construct($init_params) {
        $this->init_params=$init_params;
    }

    
    public function start_writing() {
        if( $this->read_only ) throw new \Exception('Read only storage');
        $this->node_cache_list=array();
        $this->node_cache_idx=array();
        $this->triple_cache=array();
        $this->pred_cache=array();
        $this->write_mode=1;
        $this->write_start();
    }                                                                            
    
    public function is_writing() {
        return $this->write_mode;
    }                                                                                
    
    public function isWriting() {
        return $this->write_mode;
    }                                                         
    
    
    public function rollback() {
        $this->write_end(0);
        $this->node_cache_list=array();
        $this->node_cache_idx=array();
        $this->triple_cache=array();
        $this->pred_cache=array();
        $this->write_mode=0;
    }
    
    public function commit() {
        try {
            while($count = count($this->node_cache_list)) {
                $node = $this->node_cache_list[$count-1];
                if($node->modified) {
                    $this->save_node($node);
                } else {
                    $this->remove_triples_from_cache($node);             
                    array_pop($this->node_cache_list);
                    unset($this->node_cache_idx[$node->id]);
                }
            }
            $this->write_end(1);
            $this->write_mode=0;
            $this->node_cache_list=array();
            $this->node_cache_idx=array();
            $this->triple_cache=array();
            $this->pred_cache=array();
        } catch( \Exception $e ) {
            $this->rollback();
            throw $e;
        }
    }


    function insert($sub,$pred,$ob,$type,$before_ob_id=null,$before_sub_id=null) {
        if( $pred=='id' ) throw new \Exception('!');
        if( !$this->write_mode ) throw new \Exception( 'Database not in write mode.' );
        $pred_data=$this->get_pred($pred);
        if( !$pred_data['type'] ) {
            $pred_data=$this->set_pred_type($pred,$type);
        } else {
            if( $type!=$pred_data['type'] ) throw new \Exception( 'Wrong type: predicate '.$pred.' is known to be of type '.$pred_data['type'].' and you are trying to save a '.$type );
        }
        $id=$this->new_id($pred);
        $triple=array(
            'id'=>$id,
            'sub'=>$sub,
            'pred'=>$pred,
            'type'=>$type,
            'ob'=>$ob,
            'ob_ok'=>$id
        );
        if( !is_null($before_ob_id) ) {
            // make space
            $tr=$this->get_triple($before_ob_id,$pred);
            if( $tr ) {
                $this->make_space_for_object($tr['sub'],$tr['pred'],$tr['type'],$tr['ob_ok']-1);
                $triple['ob_ok']=$tr['ob_ok']-1;
            } 
        }
        if( $type==='node' ) {
            $triple['sub_ok']=$id;
            if( !is_null($before_sub_id) ) {
                // make space
                $tr=$this->get_triple($before_sub_id,$pred);
                if( $tr ) {
                    $this->make_space_for_subject($tr['ob'],$tr['pred'],$tr['type'],$tr['sub_ok']-1);
                    $triple['sub_ok']=$tr['sub_ok']-1;
                } 
            }
        } 
        
        $node=$this->get_node($sub);
        
        $ob_card=$this->add_triple_to_node($node,$triple);
        $ob_node=null;
        if( $type==='node' ) {
            $ob_node=$this->get_node($ob);
            $this->add_triple_to_node($ob_node,$triple,-1);
        }
        $this->insert_triple($triple);
        if( $node->modified==-1 ) $this->save_node($node);
        if( $ob_node && $ob_node->modified==-1 ) $this->save_node($ob_node);
        return $id;
    }
    
    function remove($id,$pred=null) {
        if( !$this->write_mode ) throw new \Exception( 'Database is not in write mode.' );
        $triple=$this->get_triple($id,$pred);
        if( !$triple ) return;
        $sub=$triple['sub'];
        if( !$sub ) return;
        $node=$this->get_node($sub);
        $this->remove_triple_from_node($node,$triple);
        $ob=$triple['ob'];
        $type=$triple['type'];
        if( $type==='node' ) {
            $node=$this->get_node($ob);
            $this->remove_triple_from_node($node,$triple,-1);
        }
        $pred_data=$this->get_pred($triple['pred']);
        $this->remove_triple($id,$triple['type']);
        $this->remove_triple_predicate($id);
        unset( $this->triple_cache[$id] );
        return 1;
    }
    
    function update( $id, $nsub, $npred, $nob, $ntype ) {
        if( !$this->write_mode ) throw new \Exception( 'Database is not in write mode.' );
        
        $triple=$this->get_triple($id);
        
        if( $triple ) {
            if( is_null($nsub) ) $nsub=$triple['sub'];
            if( is_null($npred) ) $npred=$triple['pred'];
            if( is_null($nob) ) $nob=$triple['ob'];
            if( $nsub==$triple['sub'] && $npred==$triple['pred'] && $nob==$triple['ob'] ) return;
            $ntriple=array(
                'id'=>$triple['id'],
                'sub'=>$nsub,
                'pred'=>$npred,
                'ob'=>$nob,
                'type'=>$ntype,
                'ob_ok'=>$triple['ob_ok']
            );
            if( isset($triple['sub_ok']) ) $ntriple['sub_ok']=$triple['sub_ok'];
            $old_pred=$this->get_pred($triple['pred']);
            $new_pred=$this->get_pred($npred);
            if( !$new_pred['type'] ) {
                $new_pred=$this->set_pred_type($npred,$ntype);
            }
            $node=$this->get_node($triple['sub'],1);
            $this->remove_triple_from_node($node,$triple);
            if( $triple['type']==='node' ) {
                $node=$this->get_node($triple['ob'],1);
                $this->remove_triple_from_node($node,$triple,-1);
            }
            $node=$this->get_node($ntriple['sub'],1);
            $ob_card=$this->add_triple_to_node($node,$ntriple);
            $ob_node=null;
            if( $ntriple['type']==='node' ) {
                $ob_node=$this->get_node($ntriple['ob']);
                $sub_card=$this->add_triple_to_node($ob_node,$ntriple,-1);
            }
            if( $triple['pred']!=$npred ) {
                if( $old_pred['type']!=$new_pred['type'] ) {
                    $this->remove_triple($id,$old_pred['type']);
                    $this->insert_triple($ntriple);
                } else {
                    $this->update_triple($ntriple);
                }
                $this->update_triple_predicate($id,$npred);
            } else {
                $this->update_triple($ntriple);
            }
            if( $node->modified==-1 ) $this->save_node($node);
            if( $ob_node && $ob_node->modified==-1 ) $this->save_node($node);
            return $id;
        }
    }

    function get_triples($sub,$pred,$ob,$type) {
        if( !is_null($pred) ) {
            if( !is_null($sub) ) {
                $key='>'.$pred;
                $node=$this->get_node($sub);
                if( !array_key_exists($key,$node->props) ) return new MemIt($this,null);
                $prop=&$node->props[$key];
                if( $prop['c'] ) {
                    if( !is_null($ob) ) {
                        return new MemIt($this,$prop['l'],$ob);
                    } else {
                        return new MemIt($this,$prop['l']);
                    }
                }
            } else if( $type==='node' && !is_null($ob) ) {
                $key='<'.$pred;
                $node=$this->get_node($ob);
                if( !array_key_exists($key,$node->props) ) return new MemIt($this,null);
                $prop=&$node->props[$key];
                if( $prop['c'] ) {
                    return new MemIt($this,$prop['l']);
                } 
            }
            $pred_data=$this->get_pred($pred);
            if( !$pred_data['type'] ) {
                return new MemIt($this,null);
            } else {
                return $this->get_triple_list(array($pred_data['type']),$sub,$pred,$ob,null,null);
            }
        } else {
            $types=array();
            if( !is_null($sub) ) {
                if( !is_null($type) ) $types=array($type);
                else { 
                    $node=$this->get_node($sub);
                    foreach( $node->props as $pred_name=>$e ) {
                        if( mb_ereg('[>]([^\$]*)',$pred_name,$r) ) {
                            $pn=$r[1];
                            $pred_data=$this->get_pred($pn);
                            if( !in_array($pred_data['type'],$types) ) $types[]=$pred_data['type'];
                        }
                    }
                }
            } else if( $type==='node' && !is_null($ob) ) {
                $types=array('node');
            } else {
                $types=array('node','int','float','datetime','string');
            }
            if( !count($types) ) return new MemIt($this,null);
            return $this->get_triple_list($types,$sub,$pred,$ob,null,null);
        }
    }
    
    function next_object_triple_id($ref,$pred=null) {
        $triple=$this->get_triple($ref,$pred);
        if( $triple ) return $this->get_next_object_triple_id($triple);
    }

    function next_subject_triple_id($ref,$pred=null) {
        $triple=$this->get_triple($ref,$pred);
        if( $triple ) return $this->get_next_subject_triple_id($triple);
    }

    function move_before_object($ref,$id,$pred=null) {
        $triple=$this->get_triple($id,$pred);
        if( $triple ) {
            if( $ref ) {
                $ref=$this->get_triple($ref,$triple['pred']);
                if( $ref ) {
                    $this->make_space_for_object( $triple['sub'],$triple['pred'],$triple['type'],$ref['ob_ok']-1);
                    $this->update_ob_ok($triple,$ref['ob_ok']-1);
                }
            } else {
                $ok=$this->get_last_ob_ok($triple['sub'],$triple['pred'],$triple['type']);
                if( !is_null($ok) ) {
                    $ok=((int)$ok)-1;
                    $this->make_space_for_object( $triple['sub'],$triple['pred'],$triple['type'],$ok);
                    $this->update_ob_ok($triple['id'],$ok);
                }
            }
        }
    }

    function move_before_subject($ref,$id,$pred=null) {
        $triple=$this->get_triple($id,$pred);
        if( $triple ) {
            if( $ref ) {
                $ref=$this->get_triple($ref,$triple['pred']);
                if( $ref ) {
                    $this->make_space_for_subject( $triple['ob'],$triple['pred'],$triple['type'],$ref['sub_ok']-1);
                    $this->update_sub_ok($triple,$ref['sub_ok']-1);
                }
            } else {
                $ok=$this->get_last_sub_ok($triple['ob'],$triple['pred'],$triple['type']);
                if( !is_null($ok) ) {
                    $ok=((int)$ok)-1;
                    $this->make_space_for_subject( $triple['ob'],$triple['pred'],$triple['type'],$ok);
                    $this->update_sub_ok($triple['id'],$ok);
                }
            }
        }
    }

    
    private function update_ob_ok($triple,$ok) {
        if( $triple ) {
            $pred=$this->get_pred($triple['pred']);
            $sub=$this->get_node($triple['sub']);                                       
            $this->remove_triple_from_node($sub,$triple);
            if( $triple['type']=='node' ) {
                $ob=$this->get_node($triple['ob']);
                $this->remove_triple_from_node($ob,$triple,-1);
            }
            $triple['ob_ok']=$ok;
            $this->add_triple_to_node($sub,$triple);
            if( $ob ) $this->add_triple_to_node($ob,$triple,-1);
            $this->update_triple($triple);
        }
    }
    
    private function update_sub_ok($triple,$ok) {
        if( $triple ) {
            $pred=$this->get_pred($triple['pred']);
            $ob=$this->get_node($triple['ob']);
            $sub=$this->get_node($triple['sub']);
            $this->remove_triple_from_node($ob,$triple,-1);
            $this->remove_triple_from_node($sub,$triple);
            $triple['sub_ok']=$ok;
            $this->add_triple_to_node($ob,$triple,-1);
            $this->add_triple_to_node($sub,$triple);
            $this->update_triple($triple);
        }
    }
    
    private function make_space_for_object($sub,$pred,$type,$ob_ok) {
        $list=$this->get_triple_list(array($type),$sub,$pred,null,null,$ob_ok);
        $list->rewind();
        if( $list->valid() ) {
            $prev_ok=((int)$ob_ok)-1;
            $this->make_space_for_object($sub,$pred,$type,$prev_ok);
            $triple=$list->current();
            $this->update_ob_ok($triple,$prev_ok);
            $this->make_space_for_object($sub,$pred,$type,$ob_ok);
        }
    }

    private function make_space_for_subject($ob,$pred,$type,$sub_ok) {
        $list=$this->get_triple_list(array($type),null,$pred,$ob,$sub_ok,null);
        $list->rewind();
        if( $list->valid() ) {
            $prev_ok=((int)$sub_ok)-1;
            $this->make_space_for_subject($ob,$pred,$type,$prev_ok);
            $triple=$list->current();
            $this->update_sub_ok($triple,$prev_ok);
            $this->make_space_for_subject($ob,$pred,$type,$sub_ok);
        }
    }

    
    private function get_pred($name) {
        if( array_key_exists($name,$this->pred_cache) ) return $this->pred_cache[$name];
        $pred=$this->load_pred($name);
        $this->pred_cache[$name]=$pred;
        return $pred;
    }
    
    function pred_type($name) { 
        $pred=$this->get_pred($name);
        return $pred['type'];
    }
    
    /**
    NOTE: This code has to be reviewed, it will not work properly casting from and 
    to node types.
    */
    function set_pred_type($name,$type) {
        $pred=$this->get_pred($name);
        if( !$this->write_mode ) {
            $pred['type']=$type;
            $this->pred_cache[$name]=$pred;
        } else if( !is_null($pred['type']) ) {
            if( $pred['type']!=$type ) {
                $this->update_pred($name,$type);
                $old_type=$pred['type'];
                $pred['type']=$type;
                $this->pred_cache[$name]=$pred;
                $list=$this->get_triple_list(array($old_type),null,$name,null,null,null);
                $list->rewind();
                while( $list->valid() ) {
                    $triple=$list->current();
                    $this->normalize_triple($triple,$old_type);
                    $node=$this->get_node($triple['sub']);
                    $this->remove_triple_from_node($node,$triple);
                    $this->cast_datum($triple['ob'],$old_type,$type);
                    if( $type==='node' ) $triple['sub_ok']=$triple['id'];
                    else unset( $triple['sub_ok'] );
                    $triple['type']=$type;
                    $this->add_triple_to_node($node,$triple);
                    $this->insert_triple( $triple );
                    $this->remove_triple($triple['id'],$old_type);
                    $list->next();
                }
            }
        } else {
            $pred['type']=$type;
            $this->insert_pred($name,$type);
            $this->pred_cache[$name]=$pred;
        }
        return $pred;
    }
    
    function setPredType($name,$type) {
        return $this->set_pred_type($name,$type);
    }

    function get_triple($id,$pred=null) {
        if( is_null($id) ) throw new \Exception( 'Id can\'t be null.' );
        if( array_key_exists( $id, $this->triple_cache ) ) return $this->triple_cache[$id];
        if( is_null($pred ) ) $pred=$this->get_triple_predicate($id);
        if( $pred ) {
            $pred_data=$this->get_pred($pred);
            if( $pred_data['type'] ) {                          
                $triple=$this->load_triple($id,$pred_data['type']);
                if( !$triple ) return;
                $this->normalize_triple($triple,$pred_data['type']);
                return $triple;
            }
        }
    }
    
    function normalize_triple(&$arr,$type) {
        $arr['id']=(int)$arr['id'];
        $arr['sub']=(int)$arr['sub'];
        $arr['type']=$type;
        $arr['ob_ok']=(int)$arr['ob_ok'];
        if( isset($arr['sub_ok']) ) $arr['sub_ok']=(int)$arr['sub_ok'];
        switch($type) {
            case 'node':
            case 'int':
                $arr['ob']=(int)$arr['ob'];
                break;
            case 'float':
                $arr['ob']=(float)$arr['ob'];
                break;
        }
        return $arr;
    }
    
    public function cacheNode($id,$props) {
        if( $this->is_writing() ) return;
        if( array_key_exists($id,$this->node_cache_idx) ) return;
        $node=new CachedNode($id,json_decode($props,true));
        $this->add_node_to_cache($id,$node);
    }

    private function get_node($id) {
        if( $this->record_nodes ) $this->accessed_nodes[$id]=$id;
        if( array_key_exists($id,$this->node_cache_idx) ) return $this->node_cache_list[$this->node_cache_idx[$id]];
        if( !$id ) throw new \Exception( 'Empty id.' );
        if( is_string($id) ) throw new \Exception( 'Id is string!' );
        $arr=$this->load_node($id);
        if( !$arr ) {
            $node=new CachedNode($id,array());
            if( $this->is_writing() ) $node->modified=-1;
        }
        else $node=new CachedNode($id,json_decode($arr['props'],true));
        $this->add_node_to_cache($id,$node);
        return $node;
    }
    
    private function add_triples_to_cache($node) {
        foreach( $node->props as $k=>$v ) {
            $prop=&$node->props[$k];
            if( $k[0]=='<' ) $field='ob';
            else $field='sub';
            $pname=substr($k,1);
            foreach( $prop['l'] as $i=>$tr ) {
                $tr['pred']=$pname;
                $tr['type']=$this->pred_type($pname);
                $tr[$field]=$node->id;
                $id=$tr['id'];
                if( array_key_exists($id,$this->triple_cache) ) {
                    $this->triple_cache[$id]['double']=1;
                } else {
                    $tr['double']=0;
                    $this->triple_cache[$id]=$tr;
                }
                $prop['l'][$i]=$id;
            }
        }
    }
    
    private function remove_triples_from_cache($node) {
        foreach( $node->props as $k=>$v ) {
            $prop=&$node->props[$k];
            foreach( $prop['l'] as $i=>$id ) {
                if( $this->triple_cache[$id]['double'] ) $this->triple_cache[$id]['double']=0;
                else unset($this->triple_cache[$id]);
            }
        }
    }

    function node_predicates($id,$dir=null) {
        $n=$this->get_node($id);
        $res=array();
        foreach( $n->props as $k=>$v ) {
            if( $k[0]=='>' && $dir!==-1 ) $res[substr($k,1)]=$v['n'];
            else if( $dir!==1 ) $res['@'.substr($k,1)]=$v['n'];
        }
        return $res;
    }
    
    function node_pred_cardinality($id,$pred) {
        $n=$this->get_node($id);
        if( $pred[0]!='<' && $pred[0]!='>' ) $pred='>'.$pred;
        if( array_key_exists($pred,$n->props) ) return $n->props[$pred]['n'];
        return 0;
    }
    
    
    private function add_triple_to_node(&$node,&$triple,$dir=1) {
        $key=($dir==1?'>':'<').$triple['pred'];
        if( !array_key_exists($key,$node->props) ) {
            $prop=array('n'=>0,'c'=>1,'l'=>array());
            $node->props[$key]=&$prop;
        } else {
            $prop=&$node->props[$key];
        }
        $t=$triple['type'];
        $prop['n']++;
        $id=$triple['id'];
        if( $prop['c'] && ($prop['n']>8 || (is_string($triple['ob']) && strlen($triple['ob'])>1024)) ) {
            foreach( $prop['l'] as $i=>$tid ) {
                if( $this->triple_cache[$tid]['double'] ) $this->triple_cache[$tid]['double']=0;
                else unset($this->triple_cache[$tid]);
            }
            $prop['l']=array();
            $prop['c']=0;
        } else if( $prop['c'] ) {
            $set=0;
            $last=count($prop['l'])-1;
            $arr=&$prop['l'];
            for( $i=$last; $i>=0; --$i ) {
                $tr=$this->triple_cache[$arr[$i]];
                if( $dir==1 ) {
                    if( $tr['ob_ok']<$triple['ob_ok'] ) $set=1;
                } else {
                    if( $tr['sub_ok']<$triple['sub_ok'] ) $set=1;
                }
                if( $set ) {
                    if( $i==count($arr)-1 ) {
                        $arr[]=$id;
                    } else {
                        $before=array_slice($arr,0,$i+1);
                        $before[]=$id;
                        $arr=array_merge($before,array_slice($arr,$i+1));
                    }
                    break;
                }
            }
            if( !$set ) {
                array_unshift($arr,$id);
            }
            if( array_key_exists($id,$this->triple_cache) ) {
                if( $triple['type']!=='node' ) {
                    $triple['double']=0;
                    $this->triple_cache[$id]=$triple;
                } else {
                    $this->triple_cache[$id]['double']=1;
                }
            }
            else {                                      
                $triple['double']=0;
                $this->triple_cache[$id]=$triple;
            }
        }
        
        if( $node->modified!==-1 ) {
            $node->modified=1;
        }
        
        return $prop['n'];
    }
                                                                   
    public function remove_triple_from_node( &$node, &$triple, $dir=1 ) {
        $key=($dir==1?'>':'<').$triple['pred'];
        if( array_key_exists($key,$node->props) ) {
            $prop=&$node->props[$key];
            $prop['n']--;
            foreach( $prop['l'] as $i=>$tid ) if( $tid==$triple['id'] ) {
                array_splice($prop['l'],$i,1);
                if( $this->triple_cache[$tid]['double'] ) {
                    $this->triple_cache[$tid]['double']=0;
                } else {
                    unset($this->triple_cache[$tid]);
                }
                break;
            }
            if( $prop['n']===0 ) {
                unset( $node->props[$key] );
            }
        }
        
        if( $node->modified!==-1 ) {
            $node->modified=1;
        }
    }    

    private function save_node($node) {
        if( !$node->modified ) return;
        if( count($node->props) ) {
            $arr=array();
            foreach( $node->props as $k=>$v ) {
                $prop=$node->props[$k];
                if( $k[0]=='<' ) $field='ob';
                else $field='sub';
                $pred=substr($k,1);
                foreach( $prop['l'] as $i=>$id ) {
                    $triple=$this->get_triple($id,$pred);
                    $triple['double']=0;
                    $prop['l'][$i]=$triple;
                    $tr=&$prop['l'][$i];
                    unset($tr['double']);
                    unset($tr['pred']);
                    unset($tr[$field]);
                    unset($tr['type']);
                }
                $arr[$k]=$prop;
            }
            if( $node->modified==-1 ) {
                $this->insert_node($node->id,json_encode($arr));
                $node->modified=0;
                $this->insert_indexes($node->id);
            }
            else {
                $this->update_node($node->id,json_encode($arr));
                $node->modified=0;
                $this->update_indexes($node->id);
            }
        } else {
            $this->remove_indexes($node->id);
            $this->remove_node($node->id);
            $node->modified=0;
        }
    }
    
    public function set_prova($v) {
        $this->prova=$v;
    }
    
    private function add_node_to_cache($id,&$node) {
        $c=count($this->node_cache_list);
        if( $c>=$this->node_cache_size ) {
            $idx=rand(0,$this->node_cache_size-1);
            $p=$this->node_cache_list[$idx];
            $p2=null;
            do {
                $this->save_node($p);
                $p2=$this->node_cache_list[$idx];
                if( $p2->id==$p->id ) break;
                $p=$p2;
            } while( true );
            unset( $this->node_cache_idx[$p->id] );
            $this->remove_triples_from_cache($p);
            $this->node_cache_list[$idx]=$node;
            $this->node_cache_idx[$node->id]=$idx;
        } else {
            $this->node_cache_list[$c]=$node;
            $this->node_cache_idx[$node->id]=$c;
        }
        $this->add_triples_to_cache($node);
    }
    

    
    function find_first($s,$params=null,$context=null) {
        if( is_null($context) ) $context=$this->tdz_caller_context();
        if( $s ) $l=$this->get_ql($context)->execute( 'select * where '.$s.' limit 1',$params);
        else $l=$this->get_ql($context)->execute( 'select * limit 1',$params);
        if( count($l) ) return $l[0];
    }
    
    function query($s,$params=null,$context=null) {
        if( is_null($context) ) $context=$this->tdz_caller_context();
        return $this->get_ql($context)->execute( $s,$params );
    }
    

    protected function insert_indexes($node_id) {
        // TRIGGER UPDATE TRIGGER
    }
    protected function update_indexes($node_id) {
        //$backup=$this->backup_storage->node($node_id);
        // TRIGGER UPDATE TRIGGER
    }
    protected function remove_indexes($node_id) {
        // TRIGGER DELETE TRIGGER
    }

}

class CachedNode {
    public $props;
    public $id;
    public $modified;
    
    public function __construct($id,$props) {
        if( is_null($props) ) $props=array(); 
        $this->props=$props;
        $this->id=$id;
        $this->modified=0;
    }
    
}

class MemIt extends ResultSet {
    
    private $storage;
    private $cur;
    private $arr;
    private $ob_filter;
    
    public function __construct($storage,$arr,$ob_filter=null) {
        $this->storage=$storage;
        $this->arr=$arr;
        $this->ob_filter=$ob_filter;
        if( is_null($this->arr) ) $this->arr=array();
    }
    
    function get_storage() {
        return $this->storage;
    }
    
    public function get_triple() {
        return $this->cur;
    }
    
    public function rewind() {
        if( $this->arr ) {
            reset($this->arr);
            $this->find();
        }
    }
    
    public function valid() {
        return $this->arr && !is_null($this->cur);
    }
    
    
    public function next() {
        if( next($this->arr) ) {
            $this->find();
        } else $this->cur=null;
    }
    
    private function find() {
        do {
            $cur=current($this->arr);
            if( $cur ) {
                $this->cur=$this->storage->get_triple($cur);
                if( (is_null($this->ob_filter) || $this->cur['ob']==$this->ob_filter) ) return;
                next($this->arr);
            }
        } while( $cur );
        $this->cur=null;
    }
    
    public function count() {
        if( !$this->ob_filter ) return count($this->arr);
        else {
            $this->rewind();
            $i=0;
            while( $this->valid() ) {
                ++$i;
                $this->next();
            }                                   
            return $i;
        }
    }
    
    public function seek($pos) {
        $this->rewind();
        $i=0;
        while( $this->valid() && $i<$pos) {
            ++$i;
            $this->next();
        }
    }
    
}


