<?php

/**
NOTE: the whole code here is still open to the possibility of any node to be added 
to any type and thus have multiple types, or at least it should... 
this feature is not yet made available since it hasn't been tested enough and as well 
because the use cases aren't yet very clear to me.
If we decide to drop this feature, the _remove and _get code should be reviewed for optimization. 

- Max Jacob 02 2015
*/

namespace graphene;



require_once 'Node.php';
require_once 'Syntax.php';

class Type {

	private $_db;
	private $_def;
	private $_queries;
	
	public function ns() { return $this->_def->ns(); }
	
	public function name() { return $this->_def->name(); }
	
	public function db() { return $this->_db; }

	public function getType($n) {
		return $this->_db->getType(Syntax::typeName($n,$this->ns()));
	}
	
	public function _findDef($n,$create=true) {
		$def=$this->_def->findDef($n);
		if( !$def && !$this->_def->isFrozen() && $create ) $def=$this->_def->emptyDef($n);
		return $def;
	}

	
	public function _getRequired() {
		return $this->_def->getRequired();
	}
	
	
	final function __construct(Connection $db,$def) {
		$this->_db=$db;
		$this->_def=$def;
		$this->_queries=array();
		$this->_init();
	}
	
	protected function _init() {}
	

	public function newNode($args=null) {
		return $this->_create($args);
	}
	
	protected function _create($args=null) {
		if( is_null($args) ) $args=array();
		if( !is_array($args) ) throw new \Exception( 'Arguments must be an array.' );
		$id=$this->_db->_storage()->newId();
		$c=$this->_def->nodeClass();
		$node=new DataNode($this->_db,$id,$this,false,null);
		$r=new $c($node,$this);
		$node->_graphene_topType=$this->_def->name();
		$tdef=$this->_def;
		$prop=$node->_graphene_type;
		$anc=array();
		// append all ancestor types and at the same time make a list 
		// of the ancestors starting from the bottom
		while( $tdef ) {
			if( $prop->add($tdef->name()) ) array_unshift($anc,$tdef);
			$tdef=$tdef->supertype();
		}
		// set the properties as candidates for required if they do not exist
		if( !$this->_def->isFrozen() ) {
			$this->_checkRequiredCandidates($args,$node);
		}
		// call the int functions 
		$lastInit=null;
		foreach( $anc as $tdef ) {
			if( $lastInit!=$tdef->nodeClass() ) {
				$rc=new \ReflectionClass($tdef->nodeClass());
				$r->_callInit($rc);
				$lastInit=$tdef->nodeClass();
			}
		}
		// write the arguments
		$r->update($args);
		// check all required fields are set
		foreach( $this->_def->getRequired() as $n=>$pn ) {
			$preds=$node->properties();
			if( !array_key_exists($n,$preds) ) {
				if( !$this->_def->isFrozen() ) {
					$def=$this->_def->findDef($pn);
					if( $def->required ) {
						$def->required=0;
						$this->_def->save();
					}
				} else {
					throw new \Exception( 'Property '.$pn.' is required for type '.$this->name().'.' );
				}
			}
		}
		return $r;
	}
	
	public function getBy($field,$value) {
		if( !$this->_def->isFrozen() ) {
			$n=$field;
			$pos=strpos($n,':');
			if( $pos!==false ) $n=substr($n,0,$pos);
			$def=$this->_findDef($n);
			if( !$def->frozen && !$def->unique ) {
				$storage=$this->_db->_storage();
				$v=$value;
				Syntax::unpackDatum($v);
				$arr=Syntax::parsePred($field,$this->ns());
				if( $arr['dir']==1 ) {
					$l=$storage->getTriples(null,$arr['pred'],$v,$def->type);
					$subob='sub';
				} else {
					$l=$storage->getTriples($v,$arr['pred'],null,$def->type);
					$subob='ob';
				}
				$c=$l->count();
				if( $c<2 ) {
					$def->unique=1;
					$this->_def->save();
				}
				$l->rewind();
				if( $l->valid() ) {
					$tr=$l->current();
					return $this->getNode($tr[$subob]);
				} else {
					return null;  
				}
			}
		}
		return $this->select($field.'=? limit 1',$value)[0];
	}
	
	
	public function isFrozen() {
		return $this->_def->isFrozen();
	}
	
	public function _saveDefinition() {
		$this->_def->save();
	}
	
	public function containsNode($i) {
		if( $i instanceof \graphene\NodeBase ) $i=$i->id();
		$id=(int)$i;
		return $this->_db->_storage()->getTriples($id,'graphene_type',$this->name(),'string')->count()>0;
	}
	
	
	public function getNode($i) {
		$node=$this->_get($i);
		return $node;
	}
	protected function _get($i) {
		if( $i instanceof \graphene\NodeBase ) $i=$i->id();
		$id=(int)$i;
		if( $id<=0 ) throw new \Exception( 'Invalid id: '.$i );
		$storage=$this->_db->_storage();
		if( $storage->getTriples($id,'graphene_type',$this->name(),'string')->count()==0 ) {
			throw new \Exception( 'Node '.$id.' is not of type '.$this->name() );
		}
		// find the top most class on this lineage the node is a direct instance of
		$topMost=$this->_def;
		$type=$this;
		foreach( $storage->getTriples($id,'graphene_topType',null,'string') as $tr ) {
			$def=$this->_db->_getTypeDefinition($tr['ob']);
			if( $def->isAncestor($topMost->name()) ) {
				$topMost=$def;
				$type=null;
			}
		}
		if( !$type ) {
			$type=$this->_db->getType($topMost->name());
		} 
		$node=new DataNode($this->_db,$id,$type,false,null);
		$c=$topMost->nodeClass();
		return new $c($node,$type);
	}
	
	/**
	Set to private in order to hide this feature.
	*/
	private function addNode($i,$args=null) {
		return $this->_add($i,$args);
	}
	
	private function _checkRequiredCandidates($args,$node) {
		foreach( $args as $k=>$v ) {
			if( Syntax::isEmpty($v) ) continue;
			$pos=strpos($k,':');
			if( $pos!==false ) $k=substr($k,0,$pos);
			$def=$this->_findDef($k,false);
			if( !$def ) {
				$rs=$this->select('not '.$k.' and #x!='.$node->id().' limit 1');
				if( !$rs->count() ) {
					$def=$this->_findDef($k);
					$def->required=1;
					$this->_def->save();
				}
			}
		}
	}
	
	protected function _add($i,$args) {
		if( is_null($args) ) $args=array();
		if( !is_array($args) ) throw new \Exception( 'Arguments must be an array.' );
		if( $i instanceof \graphene\NodeBase ) $i=$i->id();
		$id=(int)$i;
		if( $id<=0 ) throw new \Exception( 'Invalid id: '.$i );
		$c=$this->_def->nodeClass();
		$node=new DataNode($this->_db,$id,$this,false,null);
		$r=new $c($node,$this);
		if( !$node->_graphene_topType->add($this->_def->name()) ) return; // is already a top type
		$tdef=$this->_def;
		$prop=$node->_graphene_type;
		$anc=array();
		// append all ancestor types and at the same time make a list 
		// of the ancestors starting from the bottom
		while( $tdef ) {
			if( $prop->add($tdef->name()) ) array_unshift($anc,$tdef);
			$tdef=$tdef->supertype();
		}
		// set the properties as candidates for required if they do not exist
		if( !$this->_def->isFrozen() ) {
			$this->_checkRequiredCandidates($args,$node);
		}
		// call the init functions 
		$lastInit=null;
		foreach( $anc as $tdef ) {
			if( $lastInit!=$tdef->nodeClass() ) {
				$rc=new \ReflectionClass($tdef->nodeClass());
				$r->_callInit($rc);
				$lastInit=$tdef->nodeClass();
			}
		}
		// write the arguments
		$r->update($args);
		// check all required fields are set
		foreach( $this->_def->getRequired() as $n=>$pn ) {
			$preds=$node->properties();
			if( !in_array($n,$preds) ) {
				if( !$this->_def->isFrozen() ) {
					$def=$this->_def->findDef($pn);
					if( $def->required ) {
						$def->required=0;
						$this->_def->save();
					}
				} else {
					throw new \Exception( 'Property '.$pn.' is required for type '.$this->name().'.' );
				}
			}
		}
		return $r;
		
		
		/*
		$c=$this->_def->nodeClass();
		$node=new DataNode($this->_db,$id,$this,false,null);
		if( is_null($args) ) $args=array();
		if( !is_array($args) ) throw new \Exception( 'Arguments must be an array.' );
		if( !$this->_db->_storage()->getTriples($id,'graphene_topType',$this->name(),'string')->count() ) {
			$this->_db->_storage()->insert($id,'graphene_topType',$this->name(),'string');
			$tdef=$this->_def;
			$prop=$node->_graphene_type;
			while( $tdef ) {
				$prop->add($tdef->name());
				$tdef=$tdef->supertype();
			}
			return new $c($node,$this,true,$args);
		} else {
			return new $c($node,$this);
		}
		*/
	}
	
	public function removeNode($i) {
		$this->_remove($i);
	}
	
	
	protected function _remove($i) {
		if( $i instanceof \graphene\NodeBase ) {
			if( get_class($i)==$this->_def->nodeClass() ) $node=$i;
			else $node=$this->getNode($i->id());
		} else {
			$node=$this->getNode($i);
		}
		if( $this->_db->_isDeleting($node->id()) ) {
			$topcall=false;
		} else {
			$topcall=true;
			$this->_db->_setDeleting($node->id());
		}
		try {
			$storage=$this->_db->_storage();		
			$topClasses=array();
			$found=null;
			// get all top types except myself and at the same time check i am a top type
			foreach( $storage->getTriples($node->id(),'graphene_topType',null,'string') as $tr ) {
				$type=$tr['ob'];
				if( $type==$this->name() ) {
					$found=$tr['id'];
					continue;
				}
				$topClasses[]=$this->_db->getType($type);
			}
			// if i'm not a top type, throw error
			if( !$found ) throw new \Exception( 'Node '.$node->id().' is not a direct instance of '.$this->name().' and thus can not be removed from it.' );
			
			// look at which types should be removed, i.e. me and all my ancestors,
			// but taking care of not removing any other top type or one of its ancestors
			$tdef=$this->_def;
			$lastCleanup=null;
			while( $tdef ) {
				$otherwiseImplied=false;
				$type=$this->_db->getType($tdef->name());
				foreach( $topClasses as $ttype ) {
					if( $ttype->name()==$tdef->name() || $ttype->_def->isAncestor($tdef->name()) ) {
						$otherwiseImplied=true;
						break;
					}
				}
				if( !$otherwiseImplied ) {
					// call cleanup
					$nodeClass=$tdef->nodeClass();
					if( $nodeClass!=$lastCleanup ) {
						$rc=new \ReflectionClass($nodeClass);
						$node->_callCleanup($rc);
						$lastCleanup=$nodeClass;
					}
					// remove associated properties if not shared by other top types
					$propsToRemove=$tdef->getPropDefs();
					$dataNode=new DataNode($this->_db,$node->id(),$type);
					foreach( $propsToRemove as $def ) {
						$shared=false;
						foreach( $topClasses as $ttype ) {
							if( $ttype->_findDef($def->absname,false) ) {
								$shared=true;
								break;
							}
						}
						if( !$shared ) {
							if( $def->deleteCascade && $def->type=='node' ) {
								$prop=new Prop($node,$def->predName,$def->dir);
								foreach( $prop as $d ) {
									$d->delete();
								}
							}             
							$dataNode->set($def->properName,null);
						}
					}
					// remove the type from the type list
					foreach( $storage->getTriples($node->id(),'graphene_type',$tdef->name(),'string') as $tr ) {
						$storage->remove($tr['id'],'graphene_type');
					}
				}
				$tdef=$tdef->supertype();
			}		
			// remove me as top type
			$storage->remove($found,'graphene_topType');
		} catch( \Exception $e ) {
			if( $topcall ) $this->_db->_unsetDeleting($node->id());
			throw $e;
		}
		if( $topcall ) $this->_db->_unsetDeleting($node->id());
	}
	
	public function __toString() {
		return 'Type '.$this->name();
	}

	
	function select($filter=null,$filterParams=null) {
		if( array_key_exists($filter,$this->_queries) )$q=$this->_queries[$filter];
		else {
			$q=new Query($filter,$this->_db,$this);
			$this->_queries[$filter]=$q;
			$q->addConstraint('_graphene_type#type=\''.$this->name().'\'');
		}
		return $q->execute($filterParams);
	}
	
	
}





