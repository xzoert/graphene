<?php

namespace graphene;



class Def {

	private $_nodeClass=null;
	private $_defs=array();
	private $_initRequired=array();
	private $_supertype=null;
	private $_ns;
	private $_name;
	private $_typeClass;
	private $_ancestors=array();
	private $_db;
	private $_fileName;
	private $_frozen;
	private $_supertypeComments=array();
	private $_bottomComments=array();
	private $_defList=array();
	
	
	function __construct($name,$db) {
		
		$this->_db=$db;
		
		$pos=strrpos($name,'_');
		if( $pos!==false ) {
			if( !ctype_upper($name[$pos+1]) ) throw new \Exception('Type names must begin with upper case letter.');
			$this->_ns=substr($name,0,$pos);
		} else {
			if( !ctype_upper($name[0]) ) throw new \Exception('Type names must begin with upper case letter.');
			$this->_ns='';
		}
		$this->_name=$name;

		$this->_fileName=$db->_findDefFile($name);
		if( !is_file($this->_fileName) ) {
			if( $db->isFrozen() && $name!='Untyped' ) throw new \Exception( 'No definition file found for type '.$name.'.' );
			$this->_frozen=false;
			$this->_supertypeComments[]="##### ".$this->_name." #####\n";
		} else {
			$this->_frozen=$db->isFrozen();
			try {
				$this->loadFile($this->_fileName);
			} catch( \Exception $e ) {
				throw new \Exception('Error reading '.$this->_fileName.': '.$e->getMessage());
			}
		}
		
		$gt=$this->emptyDef('_graphene_type');
		$gt->type='string';
		$gt->frozen=1;
		$gt->required=0;
		$gt->repetitions=0;
		$gt->isList=1;
		$gt->unique=0;
		$gtt=$this->emptyDef('_graphene_topType');
		$gtt->type='string';
		$gtt->required=0;
		$gtt->repetitions=0;
		$gtt->isList=1;
		$gtt->unique=0;
		$gtt->frozen=1;
		
		// get the type class
		$tdef=$this;
		$this->_typeClass='\\graphene\\Type';
		while( $tdef ) {
			$phpcl='\\'.str_replace('_','\\',$tdef->_name).'Type';
			if( !class_exists($phpcl) ) $this->_db->_loadClass($phpcl);
			if( class_exists($phpcl) ) {
				$this->_typeClass=$phpcl;
				break;
			} 
			$tdef=$tdef->_supertype;
		}
		
		// get the node class
		$tdef=$this;
		$this->_nodeClass='\\graphene\\Node';
		while( $tdef ) {
			$phpcl='\\'.str_replace('_','\\',$tdef->_name).'Node';
			if( !class_exists($phpcl) ) $this->_db->_loadClass($phpcl);
			if( class_exists($phpcl) ) {
				if( !is_subclass_of($phpcl,'\graphene\Node') ) {
					throw new \Exception( 'Class '.$phpcl.' should extend \\graphene\\Node.' );
				}
				$this->_nodeClass=$phpcl;
				break;
			} 
			$tdef=$tdef->_supertype;
		}
		
		// bind validators and triggers
		if( $this->_nodeClass!='\\graphene\\Node' ) {
			foreach( $this->_defList as $def ) {
				$this->bindMethods($def);
			}
		}
		
		// check inheritance is reflected in php and at the same time get my ancestors and required fields
		$tdef=$this->_supertype;
		if( $tdef ) {
			if( $this->_nodeClass!=$tdef->_nodeClass && !is_subclass_of($this->_nodeClass,$tdef->_nodeClass) ) {
				throw new \Exception( 'Class '.$this->_nodeClass.' does not reflect its type definition (should extend '.$tdef->_nodeClass.').' );
			}
			if( $this->_typeClass!=$tdef->_typeClass && !is_subclass_of($this->_typeClass,$tdef->_typeClass) ) {
				throw new \Exception( 'Class '.$this->_typeClass.' does not reflect its type definition (should extend '.$tdef->_typeClass.').' );
			}
			$this->_ancestors[$tdef->_name]=$tdef;
			foreach( $tdef->_initRequired as $k=>$v ) {
				if( !array_key_exists($k,$this->_initRequired) ) $this->_initRequired[$k]=$v;
			}
			foreach( $tdef->_ancestors as $k=>$v ) {
				$this->_ancestors[$k]=$v;
			}
		}
	}
	
	private function bindMethods($def) {
		if( !$this->_nodeClass ) return;
		if( $def->relName!=$def->properName && $def->relName[0]!='@' ) {
			$names=array($def->properName,$def->relName);
		} else {
			$names=array($def->properName);
		}
		
		$rc=new \ReflectionClass( $this->_nodeClass );
		foreach( $names as $pn ) {
			$vname='_'.$pn.'Validator';
			if( $rc->hasMethod($vname) ) {
				$m=$rc->getMethod($vname);
				$m->setAccessible(true);
				$def->validator=$m;
			}
			$on='_on'.ucfirst($pn);
			$trname=$on.'Inserted';
			if( $rc->hasMethod($trname) ) {
				$m=$rc->getMethod($trname);
				$m->setAccessible(true);
				$def->insertTrigger=$m;
			}
			$trname=$on.'Updated';
			if( $rc->hasMethod($trname) ) {
				$m=$rc->getMethod($trname);
				$m->setAccessible(true);
				$def->updateTrigger=$m;
			}
			$trname=$on.'Deleted';
			if( $rc->hasMethod($trname) ) {
				$m=$rc->getMethod($trname);
				$m->setAccessible(true);
				$def->deleteTrigger=$m;
			}
		}
	}
	
	private function writeComments($handle,$comments) {
		foreach ($comments as $line) fwrite($handle,$line."\n");
	}

	private function relTypeName($n) {
		$nslen=strlen($this->_ns);
		if( !$this->_ns ) return $n;
		else if( !strncmp($n,$this->_ns,$nslen) ) return substr($n,$nslen+1);
		else return '_'.$n;
	}
	
	function save() {
		$exists=is_file($this->_fileName);
		if (!$exists) {
			$info=pathinfo($this->_fileName);
			if( !is_dir($info['dirname']) ) mkdir($info['dirname'],0775,true);
		}
		$handle = fopen($this->_fileName, 'w');
		if ($handle) {
			$nslen=strlen($this->_ns);
			$this->writeComments($handle,$this->_supertypeComments);
			if( $this->_supertype ) {
				fwrite($handle, '\\supertype '.$this->relTypeName($this->_supertype->name())."\n");
			}
			foreach( $this->_defList as $def ) {
				if( $def->nodeType ) {
					$line=$this->relTypeName($def->nodeType);
				}
				else if( $def->type ) $line=$def->type;
				else continue;
				if (!strncmp($def->predName,'graphene_',9)) continue;
				if( $def->isList ) {
					if( $def->repetitions ) $line.='[]';
					else $line.='{}';
				} 
				if( $def->dir==1 ) $back='';
				else $back='@';
				$nslen=strlen($this->_ns);
				if( !$this->_ns ) $relName=$back.$def->predName;
				else if( !strncmp($this->_ns.'_',$def->predName,$nslen+1) ) $relName=$back.substr($def->predName,$nslen+1);
				else $relName=$back.'_'.$def->predName;
				$line.=' '.$relName;
				if( $relName!=$def->properName ) $line.=' as '.$def->properName;
				if( ($def->mask!=\graphene::ACCESS_FULL) ) {
					$mask='';
					if( $def->mask & \graphene::ACCESS_READ ) $mask.='r';
					if( !(($def->mask&\graphene::ACCESS_WRITE)^\graphene::ACCESS_WRITE) ) $mask.='w';
					else {
						if( $def->mask & \graphene::ACCESS_INSERT ) $mask.='i';
						if( $def->mask & \graphene::ACCESS_UPDATE ) $mask.='u';
						if( $def->mask & \graphene::ACCESS_DELETE ) $mask.='d';
					}
					if( $mask ) $line.=' '.$mask;
					else $line.=' n';
				}
				if( $def->required ) $line.=' required';
				if( $def->unique ) $line.=' unique';
				if( $def->deleteCascade ) $line.=' delete cascade';
				if( $def->frozen ) $line.=' !';
				$this->writeComments($handle,$def->comments);
				fwrite($handle,$line."\n");
			}
			if( $this->_bottomComments ) $this->writeComments($handle,$this->_bottomComments);
			else fwrite($handle,"\n\n");
			fclose($handle);
		} else {
			throw new \Exception( 'Could not open '.$fn.' for writing.' );
		}
		
	}
	
	function isFrozen() {
		return $this->_frozen;
	}
	
	function emptyDef($name) {
		$arr=Syntax::parsePred($name,$this->_ns);
		$def=new \stdClass();
		$def->type=null;
		$def->nodeType=null;
		$def->isList=true;
		$def->repetitions=false;
		$def->unique=false;
		$def->deleteCascade=false;
		$def->frozen=false;
		$def->required=false;
		$def->comments=array('','# AUTO-GENERATED');

		$back=$arr['dir']==1?'':'@';
		$predName=$arr['pred'];
		$nslen=strlen($this->_ns);
		$absname=$back.'_'.$predName;
		if( $nslen==0 ) $relName=$back.$predName;
		else if( !strncmp($this->_ns.'_',$predName,$nslen+1) ) $relName=$back.substr($predName,$nslen+1);
		else $relName=$absname;
		if( array_key_exists($absname,$this->_defs) ) throw new \Exception('Double definition of '.$absname.'.');
		if( array_key_exists($relName,$this->_defs) ) throw new \Exception('Double definition of '.$relName.'.');

		$def->relName=$relName;
		$def->properName=$relName;
		$def->predName=$arr['pred'];
		$def->dir=$arr['dir'];
		$def->absname=$absname;
		$def->mask=\graphene::ACCESS_NONE;
		$def->sourceType=$this->_name;
		$def->validator=null;
		$def->insertTrigger=null;
		$def->deleteTrigger=null;
		$def->updateTrigger=null;
		$def->relName=$relName;
		$this->_defs[$relName]=$def;
		$this->_defs[$absname]=$def;
		$this->_defList[]=$def;
		$this->bindMethods($def);
		return $def;
	}
	
	private function loadFile($fn) {
		$comments=array();
		$handle = fopen($fn, 'r');
		if ($handle) {
			while (($line = fgets($handle))!==false) {
				$line=trim($line);
				if( !$line || $line[0]=='#' ) {
					$comments[]=$line;
					continue;
				}
				if( $line[0]=='\\' ) {
					$arr=mb_split('[\s|,]+',$line);
					switch( $arr[0] ) {                                
						case '\\supertype':
							$st=$this->_db->_getTypeDefinition($arr[1],$this->_ns);
							$this->_supertype=$st;
							$this->_supertypeComments=$comments;
							$comments=array();
							break;
						case '\\frozen':
							$this->_frozen=true;
					}
				} else {
					$this->defProp($line,$comments);
					$comments=array();
				}
			}
			$this->_bottomComments=$comments;
			fclose($handle);                
		} else {
			throw new \Exception( 'Could not open file.' );
		}
		
	}


	protected function defProp($s,$comments) {
		if(mb_ereg('^\s*((\_)?((([a-z]+\_)*)([A-Z][a-zA-Z0-9]*))|((string)|(float)|(int)|(datetime)|(node)))(\s*(\[\])|(\{\}))?\s+(\@)?(\_)?(([a-z][a-z0-9]*\_)*)([a-z][a-zA-Z0-9]*)(\s+as\s+([a-z][a-zA-Z\_]*))?(\s+[riundwf]+)?((\s+required)|(\s+delete\s+cascade)|(\s+unique)|(\s+\!))*\s*$', $s, $r)) {
						
			$tabs=$r[2];
			$tns=$r[4];
			$tname=$r[6];
			$type=$r[7];      
			$list=$r[14];
			$set=$r[15];
			$back=$r[16];
			$abs=$r[17];
			$ns=$r[18];
			$name=$r[20];
			$alias=$r[22];
			$flags=$r[23];
			$required=$r[25];
			$delcas=$r[26];
			$unique=$r[27];
			$frozen=$r[28];
			
			if( $name=='id' ) throw new \Exception( 'The property name \'id\' is reserved, please use a different one' );			
			
			$predName=$abs?$ns.$name:($this->_ns?$this->_ns.'_'.$ns.$name:$ns.$name);
			$nslen=strlen($this->_ns);
			$absname=$back.'_'.$predName;
			if( $nslen==0 ) $relName=$back.$predName;
			else if( !strncmp($this->_ns.'_',$predName,$nslen+1) ) $relName=$back.substr($predName,$nslen+1);
			else $relName=$absname;
			if( !$type ) {
				$nodeType=$tabs?$tns.$tname:($this->_ns?$this->_ns.'_'.$tns.$tname:$tns.$tname);
				$type='node';
			} else {
				if( $type!='node' && $back ) throw new \Exception( 'Backward property '.$predName.' can not be of type '.$type.'.' );
				$nodeType=null;
			}
			$def=new \stdClass();                                               
			//$def->getter=null;
			//$def->setter=null;
			$def->type=$type;
			$def->nodeType=$nodeType;
			if( $list ) {
				$def->isList=true;
				$def->repetitions=true;
			} else if ( $set ) {
				$def->isList=true;
				$def->repetitions=false;
			} else {
				$def->isList=false;
				$def->repetitions=false;
			}
			$def->unique=($unique?true:false); 
			$def->absname=$absname;
			$def->predName=$predName;
			$def->relName=$relName;
			$def->dir=$back?-1:1;
			if( !$flags ) $flags='f';
			$def->mask=0;
			foreach( str_split($flags) as $c ) {
				switch( $c ) {
					case 'r':	
						$def->mask|=\graphene::ACCESS_READ;
						break;
					case 'i':
						$def->mask|=\graphene::ACCESS_INSERT;
						break;
					case 'u':
						$def->mask|=\graphene::ACCESS_UPDATE;
						break;
					case 'd':
						$def->mask|=\graphene::ACCESS_DELETE;
						break;
					case 'w':	
						$def->mask|=\graphene::ACCESS_WRITE;
						break;
					case 'f':	
						$def->mask|=\graphene::ACCESS_FULL;
						break;
				}
			}
			if( $delcas ) $def->deleteCascade=true;
			else $def->deleteCascade=false;
			$def->sourceType=$this->_name;
			$def->validator=null;
			$def->insertTrigger=null;
			$def->deleteTrigger=null;
			$def->updateTrigger=null;
			$def->comments=$comments;
			if( $this->_frozen || $frozen ) $def->frozen=true;
			else $def->frozen=false;
			if( $alias ) {
				$absalias=$this->_ns.'_'.$alias;
				if( array_key_exists($alias,$this->_defs) ) throw new \Exception('Double definition of '.$alias.'.');
				if( array_key_exists($absalias,$this->_defs) ) throw new \Exception('Double definition of '.$absalias.'.');
				$properName=$alias;
				$this->_defs[$alias]=$def;
				$this->_defs[$absalias]=$def;
			} else {
				$properName=$relName;
			}
			$def->properName=$properName;
			if( $required ) {
				$this->_initRequired[$def->predName]=$properName;
				$def->required=true;
			} else {
				$def->required=false;
			}
			if( array_key_exists($relName,$this->_defs) ) throw new \Exception('Double definition of '.$relName.'.');
			if( array_key_exists($absname,$this->_defs) ) throw new \Exception('Double definition of '.$absname.'.');
			$this->_defs[$relName]=$def;
			$this->_defs[$absname]=$def;
			$this->_defList[]=$def;
		} else {
			throw new \Exception( 'Invalid property definition: "'.$s.'" for type '.$this->_name.'.' );
		}
	}

	private function normalizeAbsName($name) {
		if( !$this->_ns ) {
			if( $name[0]=='@' ) {
				if( $name[2]=='_' ) $name='@'.substr($name,2);
			} else {
				if( $name[0]=='_' ) $name=substr($name,1);
			}
		}
		return $name;
	}
	
	public function findDef($n) {
		if( array_key_exists($n,$this->_defs) ) {
			$def=$this->_defs[$n];
			return $def;
		}
		if( $this->_supertype ) {
			return $this->_supertype->findDef($n);
		}
	}


	
	public function getRequired() {
		return $this->_initRequired;
	}
	
	public function ns() { return $this->_ns; }
	public function name() { return $this->_name; }
	public function supertype() { return $this->_supertype; }
	public function typeClass() { return $this->_typeClass; }
	public function nodeClass() { return $this->_nodeClass; }
	public function isAncestor($name) { return array_key_exists($name,$this->_ancestors); }
	public function getAncestors() { return $this->_implied; }
	public function getPropDefs() { return $this->_defList; }
	
}



