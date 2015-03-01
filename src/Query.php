<?php

namespace graphene;

require_once 'Connection.php';
require_once 'Type.php';

class Query {

	private $pos;
	private $s;
	private $mkr;
	private $info;
	private $db;
	private $type;
	private $len;
	private $where;
	private $tcount;
	private $tables;
	private $mainTable;
	private $parsed;
	private $steps;
	
	function __construct($s,$db,$type) {
		
		$this->pos=0;
		$this->s=$s;
		$this->toparse=$s;
		$this->mkr=0;
		$this->db=$db;
		$this->where='';
		$this->sql='';
		$this->type=$type;
		$this->len=strlen($s);
		$this->tcount=0;
		$this->tables=array();
		$this->mainTable=null;
		$this->orderLimit='';
		$this->args=array();
		$this->constraints=array();
		$this->parsed=0;
		$this->steps=array();
		$rootStep=new QueryStep();
		$rootStep->hash='#x';
		$rootStep->key='#x';
		$rootStep->nodeType=$type;
		$this->rootTable=new \stdClass();
		$this->rootTable->valueName='n.id';
		$this->rootTable->optional=false;
		$rootStep->table=$this->rootTable;
		$this->steps[$rootStep->key]=$rootStep;
		
		
	}
	
	function addConstraint($s,$args=null) {
		$this->constraints[]=$s;
		if(!is_null($args)) $this->args=array_merge($this->args,$args);
	}
	
	
	function reset($info) {
		$this->pos=$info['mkr'];
		$this->sql=substr($this->sql,0,$info['wmkr']);
		$this->toparse=substr($this->s,$this->pos);
	}
	
	function current() {
		return array( 'mkr'=>$this->pos, 'wmkr'=>strlen($this->sql) );
	}
	
	
	function ereg($expr,$ignoreCase=false,$debug=0) {
		if( $ignoreCase ) $found=mb_eregi('^'.$expr,$this->toparse,$r);
		else $found=mb_ereg('^'.$expr,$this->toparse,$r);
		if( $found ) {
			if( $debug ) {
				echo 'Matched: '.$expr.PHP_EOL;
				echo 'Against: '.substr($this->s,$this->pos).PHP_EOL;
				print_r($r);
			}
			$this->pos+=strlen($r[0]);
			$this->toparse=substr($this->s,$this->pos);
			return $r;
		} else if( $debug ) {
			echo 'Not found: '.$expr.' at: '.$this->toparse.PHP_EOL;
		}
	}
	
	function parse() {
		$this->sql='';
		$s=$this->s;
		$cwhere='';
		foreach( $this->constraints as $c ) {
			$this->s=$c;
			$this->toparse=$c;
			$this->pos=0;
			if( $this->filter() ) {
				$cwhere.=' and ( '.$this->sql.' ) ';
				$this->sql='';
			}
			if( trim($this->toparse) ) {
				$this->syntaxError('Syntax error');    
			}
		}
		$this->s=$s;
		$this->toparse=$s;
		$this->pos=0;
		$this->sql='';
		if( !mb_eregi('(^\s*order\s+by)|(^\s*limit\s\d)',$this->s) ) {
			if( $this->filter() ) {
				$this->where='( '.$this->sql.' )';
			}
		}
		if( !$this->where ) $this->where=' 1 ';
		$this->sql='';
		$this->orderBy();
		$this->limit();
		if( trim($this->toparse) ) {
			$this->syntaxError('Syntax error: unparsed text: '.$this->toparse);
		}
		$this->orderLimit=$this->sql;
		$storage=$this->db->_storage();
		$select='select n.id, n.props from '.$storage->nodeTable().' n ';
		foreach( $this->tables as $key=>$table ) {
			if( $table->optional ) $select.=' left join ';
			else $select.=' join ';
			$select.=$storage->datatypeTable($table->predType).' '.$table->name.' on '.$table->name.'.pred=\''.$table->predName.'\''.$table->filter;
			/*
			$select.=$storage->datatypeTable($table->predType).' '.$table->name.' on '.$table->name.'.pred=\''.$table->predName.'\' and '.$table->linkName.'=';
			if( $table->refName ) {
				$select.=$table->refName;
			} else {
				$select.='n.id';
			}
			*/
		}
		
		$this->sql=$select.' where '.$this->where.$cwhere.' group by n.id '.$this->orderLimit;
		$this->parsed=1;
	}
	
	
	function execute($args=null) {
		if( !$this->parsed ) $this->parse();
		//echo 'SQL: '.$this->sql.PHP_EOL;
		$conn=$this->db->getMySqlConnection();
		$stmt = $conn->prepare($this->sql);
		if( !$stmt ) throw new \Exception(mysqli_error($conn));
		if( !is_null($args) ) {
			if( !is_array($args) ) $args=array($args);
			$args=array_merge($args,$this->args);
		}
		else {
			$args=$this->args;
		}
		$params=array('');
		$nargs=array();
		for ($i=0; $i<count($args); ++$i ) {
			$arg=$args[$i];
			if( is_object($arg) ) {
				if( $arg instanceof NodeBase ) $arg=$arg->id();
				else if( $arg instanceof \DateTime ) $arg=$arg->format('Y-m-d H:i:s');
				else throw new \Exception('Invalid object in arguments.');
			} 
			$nargs[$i]=$arg;
			$params[$i+1]=&$nargs[$i];
			if( is_string($arg) ) $params[0].='s';
			else if( is_integer($arg) ) $params[0].='i';
			else if( is_double($arg) ) $params[0].='d';
			else throw new \Exception( 'Invalid argument.' );
		}
		if( count($args) ) {
			if(!call_user_func_array(array($stmt, 'bind_param'), $params)) {
				throw new \Exception( 'Error in binding arguments to query.' );
			}
		}
		$stmt->execute();
		$stmt->store_result();
		return new QueryIterator($this->db,$this->type,$stmt);
	}
	
	function orderBy() {
		if( $r=$this->ereg('\s*order\s+by\s',1) ) {
			$this->sql.=$r[0];
			if( !$this->expression(false,false) ) $this->syntaxError('Expression expected');
			if( $r=$this->ereg('(^\s*asc)|(^\s*desc)',1) ) $this->sql.=$r[0];
			while( $r=$this->ereg('\s*\,') ) {
				$this->orderLimit.=$r[0];
				if( !$this->expression(false,false) ) $this->syntaxError('Expression expected');
				if( $r=$this->ereg('(^\s*asc)|(^\s*desc),1') ) $this->sql.=$r[0];
			}
		}
	}

	function limit() {
		if( $r=$this->ereg('\s*limit\s+\d+(\s*\,\d+)?(\s+offset\s+\d+)?',1) ) {
			$this->sql.=$r[0];
		}
	}

	
	function filter() {
		$flds=$this->expression();
		if( is_array($flds) ) {
			foreach( $flds as $k=>$v ) {
				if( !$v ) continue;
				$this->unsetOptional($v);
			}
		}
		return $flds;
		
	}
	
	function unsetOptional($step) {
		if( !$step->table->optional ) return;
		$step->table->optional=false;
		while( $step->prev ) {
			$step=$step->prev;
			$step->table->optional=false;
		}
	}
	
	private function expression($root=true,$where=true) {
		//echo 'starting '.$root.' expression at: '.substr($this->s,$this->pos,40).'<br>';
		$cur=$this->current();
		$not=false;
		if( $root && $r=$this->ereg('\s*not\s',1) ) {
			$this->sql.=$r[0];
			$not=true;
		}
		if( $flds=$this->expressionInParetheses($root) ) {
			
		} else {
			if( $flds=$this->func() ) {
				$info=null;	
			} else {
				$flds=array(''=>0);
				if( !$info=$this->expressionValue() ) {
					$this->reset($cur);
					return false;
				}
			}
			if( $this->binaryOperator() ) {
				if( is_object($info) ) $flds[$info->key]=$info;
				$nflds=$this->expression(false);
				if( !$nflds ) $this->syntaxError('Expression expected');
				if( !is_array($flds) ) $flds=$nflds;
				else if( is_array($nflds) ) $flds=array_merge($flds,$nflds);
			} else {
				if( is_object($info) ) {
					if( $root ) {
						$flds[$info->key]=$info;
						$this->sql.=' is not null';
					} else if( $where ) {
						$flds[$info->key]=$info;
					}
				}
			}
		}
		//echo 'consumed until : '.substr($this->s,$this->pos,40).'<br>';
		if( $root ) {
			//echo 'looking for operator<br>';
			if( $op=$this->logicalOperator() ) {
				$nflds=$this->expression(true);
				if( !$nflds ) $this->syntaxError('Expression expected');
				if( $not ) return array(''=>0);
				if( !is_array($flds) ) return $nflds;
				if( is_array($nflds) ) {
					if( $op=='and' ) {
						return array_merge($flds,$nflds);
					} else {
						return array_intersect_key($flds,$nflds);
					}
				} 
			}
		}
		if( $not ) return array(''=>0);
		//echo 'returning fields<br>';
		//print_r($flds);
		return $flds;
	}
	

	private function logicalOperator() {
		if( $r=$this->ereg('(^\s*(and)\s)|(^\s*(or)\s)',1) ) {
			$this->sql.=$r[0];
			if( $r[2] ) return 'and';
			return 'or';
		}
	}

	
	private function binaryOperator() {
		if( $r=$this->ereg('(^\s*[\=\!\<\>\*\+\/\-]+)|(^\s*(not\s+)?like\s)|(^\s*(not\s+)?rlike)|(^\s*(not\s+)?regexp\s)',1) ) {
			$this->sql.=$r[0];
			return 1;
		}
	}
	
	private function expressionInParetheses($root) {
		$cur=$this->current();
		if( $r=$this->ereg('s*\(') ) {
			$this->sql.=$r[0];
			$flds=$this->expression($root);
			if( !$flds ) $this->syntaxError( 'Expression expected' );
			if( $r=$this->ereg('\s*\)') ) {
				$this->sql.=$r[0];
				return $flds;
			} else {
				$this->reset($cur);
				$this->syntaxError('Unmatched parentheses');
			}
		}
	}
	
	private function func() {
		$cur=$this->current();
		if( $r=$this->ereg('\s*((^length)|(^rand)|(^substr))\s*\(',1) ) {
			$this->sql.=$r[0];
			if( $flds=$this->expression(false) ) {
				while( $r=$this->ereg('\s*\,') ) {
					$this->sql.=$r[0];
					$nflds=$this->expression(false);
					if (!$nflds) $this->syntaxError('Expression expected');
					$flds=array_merge($flds,$nflds);
				}
			} else {
				$flds=true;
			}
			if( $r=$this->ereg('\s*\)') ) {
				$this->sql.=$r[0];
				return $flds;
			} else {
				$this->reset($cur);
				$this->syntaxError('Unmatched parentheses');
			}
		}
	}
	
	private function expressionValue() {
		if( $this->variable() ) return true;
		if( $this->constantExpression() ) return true;
		if( $info=$this->typedNode() ) {
			if( is_object($info) ) {
				$this->sql.=' '.$info->table->valueName;
				return $info;
			}
			return true;
		}
		if( $info=$this->path() ) {
			if( is_object($info) ) {
				$this->sql.=' '.$info->table->valueName;
				return $info;
			}
			return true;
		}
	}
	
	private function variable() {
		if( $r=$this->ereg('\s*\?') ) {
			$this->sql.=$r[0];
			return true;
		}
	}
	
	private function constantExpression() {
		return $this->number() || $this->string() || $this->trueFalse();
	}
	
	private function nullVal() {
		if( $r=$this->ereg('\s*null',1) ) {
			$this->sql.=$r[0];
			return 1;
		}
	}
	
	private function trueFalse() {
		if( $r=$this->ereg('(^\s*true)|(^\s*false)',1) ) {
			$this->sql.=$r[0];
			return 1;
		}
	}
	
	private function number($fromNot=false) {
		if( $r=$this->ereg('\s*(\d*\.)?\d+') ) {
			$this->sql.=$r[0];
			return 1;
		}
	}
	
	private function string($fromNot=false) {
		if( $r1=$this->ereg("\s*\'") ) {
			if( $r2=$this->ereg("((\\\')|([^\']))*\'") ) {
				$this->sql.=$r1[0].$r2[0];
				return true;
			} else {
				$this->syntaxError( 'Untermnated string' );
			}
		}
		if( $r1=$this->ereg('\s*\"') ) {
			if( $r2=$this->ereg('((\\\")|([^\"]))*\"') ) {
				$this->sql.=$r1[0].$r2[0];
				return true;
			} else {
				$this->syntaxError( 'Untermnated string' );
			}
		}
	}
	
	function end() {
		return $this->pos>=$this->len;
	}
	
	function syntaxError($msg) {
		if( $this->len-$this->pos<10 ) $pos=$this->len-10;
		else $pos=$this->pos;
		if( $pos<0 ) $pos=0;
		throw new \Exception($msg.' near "...'.substr($this->s,$pos,20).'".');
	}
	
	private function processStep($step) {
		if( array_key_exists($step->key,$this->tables) ) {
			$table=$this->tables[$step->key];
			$step->table=$table;
		} else {
			$table=new \stdClass();
			$this->tcount++;
			$table->name='t'.$this->tcount;
			$table->predName=$step->predName.($step->lang?':'.$step->lang:'');
			$table->predType=$step->type;
			$table->isRoot=is_null($step->prev);
			if( !$table->isRoot ) {
				$table->refName=$step->prev->table->valueName;
			} else {
				$table->refName=null;
			}
			$table->linkName=$table->name.'.'.($step->dir==1?'sub':'ob');
			$table->valueName=$table->name.'.'.($step->dir==1?'ob':'sub');
			$table->optional=true;
			$step->table=$table;
			$table->key=$step->key;
			if( $table->refName ) $table->filter=' and '.$table->linkName.'='.$table->refName;
			else $table->filter='';
			$this->tables[$step->key]=$table;
		}
	}
	
	private function typedNode() {
		if( $r=$this->ereg('\s*(\_?)(([a-z][a-z0-9]*\_)*[A-Z][a-zA-Z0-9]*)(#[a-z]+)') ) {
			if( $r[1] ) $typeName=$r[2];
			else {
				if( $this->type ) {
					$ns=$this->type->ns();
					if( $ns ) $typeName=$ns.'_'.$r[2];
					else $typeName=$r[2];
				} else {
					$typeName=$r[2];
				}
			}
			$hash=$r[4];
			$info=new QueryStep();
			$info->predName='graphene_type';
			$info->fullName='grahene_type';
			$info->type='string';
			if( array_key_exists($hash,$this->steps) ) {
				$step=$this->steps[$hash];
				$info->prev=$step;
				$info->dir=1;
				$info->key=$step->key.'.graphene_type';
				$step->nodeType=$this->db->getType($typeName);
				$this->processStep($info);
				$info->table->filter.=' and '.$info->table->valueName.'=\''.$typeName.'\'';
			} else {
				$info->hash=$hash;
				$info->key=$hash.'.graphene_type';
				$info->nodeType=$this->db->getType($typeName);
				$info->dir=-1;
				$this->steps[$info->hash]=$info;
				$this->processStep($info);
				$info->table->filter=' and '.$info->table->linkName.'=\''.$typeName.'\'';
			}
			return $info;
		}
	}
	
	private function path() {
		$hash=null;
		$step=null;
		$found=false;
		if( $r=$this->ereg('(^\s*(\#[a-zA-Z_][a-zA-Z_0-9]*))') ) {
			$hash=$r[2];
			//if( $hash!='#x' ) {
				if( !array_key_exists($hash,$this->steps) ) $this->syntaxError( 'Unbound variable '.$hash );
				$step=$this->steps[$hash];
				if( !$r=$this->ereg('\.') ) return $step;
				$found=true;
			//}
		} else {
			$step=$this->steps['#x'];
			//if( !$step=$this->prop($this->type) ) return;
			//$step->key=$step->fullName.$step->hash;
			//$this->processStep($step);
		}
		do {
			$nstep=$this->prop($step?$step->nodeType:$this->type);
			if( !$nstep ) {
				if( $found ) $this->syntaxError('Property name expected after \'.\'');
				return false;
			} 
			$fund=true;
			if( $step ) {
				$nstep->prev=$step;
				$nstep->key=$step->key.'.'.$nstep->fullName.$nstep->hash;
			} else {
				$nstep->key=$nstep->fullName.$nstep->hash;
			}
			$this->processStep($nstep);
			$step=$nstep;
		} while( $this->ereg('\.') );
		return $step;
		/*
		if( $step ) return $step;
		$this->sql.=' n.id';
		return true;
		*/
	}

	
	function prop($type) {
		if( $r=$this->ereg('\s*(\@)?(_)?([a-z][A-Za-z0-9_]*)(\:(([a-z]{2})(_[A-Z]{2})?))?(\#[a-zA-Z_][a-zA-Z_0-9]*)?') ) {
			$info=new QueryStep();
			$info->dir=($r[1]==''?1:-1);
			$abs=$r[2]!='';
			$info->predName=$r[3];
			$info->hash=$r[8];
			if( count($r)>5 ) $info->lang=$r[5];
			else $info->lang=null;
			$info->nodeType=null;
			$info->type=null;
			$def=null;
			if( $type ) {
				$def=$type->_findDef($r[1].$r[2].$r[3],false);
				if( $def ) {
					$info->predName=$def->predName;
					$info->dir=$def->dir;
					if( $def->nodeType ) $info->nodeType=$this->db->getType($def->nodeType);
				}
			}
			$info->type=$this->db->_storage()->predType($info->predName);
			if( !$info->type ) {
				if( $def && $def->nodeType ) $info->type='node';
				else $info->type='string';
			}
			$info->fullName=($info->dir==1?'':'@').$info->predName.($info->lang?':'.$info->lang:'');
			if( $info->hash ) {
				if( array_key_exists($info->hash,$this->steps) ) $this->syntaxError( 'Hash '.$info->hash.' already bound' );
				$this->steps[$info->hash]=$info;
			}
			return $info;
		}
		
	}
	
}


class QueryIterator  implements \ArrayAccess, \SeekableIterator, \Countable {
	
	private $stmt;
	private $db;
	private $id;
	private $props;
	private $pos;
	private $type;
	
	public function __construct($db,$type,$stmt) {
		$this->db=$db;
		$this->stmt=$stmt;
		$this->type=$type;
		$stmt->bind_result($this->id,$this->props);
	}

	public function rewind() {
		if( $this->stmt ) {
			$this->stmt->data_seek(0);
			if( !$this->stmt->fetch() ) $this->id=null;
			$this->pos=0;
		}
	}
	
	public function valid() {
		return $this->stmt && !is_null($this->id);
	}

	public function current() { 
		 $this->db->_storage()->cacheNode($this->id,$this->props);
		 if( $this->type ) return $this->type->getNode($this->id);
		 else return $this->db->getNode($this->id);
	}
	
	public function key() { return $this->pos; }
	
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
	
	
	public function next() {
		if( !$this->stmt->fetch() ) $this->id=null;
		$this->pos++;
	}
	
	public function count() {
		return $this->stmt->num_rows();
	}
	
	public function seek($pos) {
		$this->stmt->data_seek($pos);
		if( !$this->stmt->fetch() ) $this->id=null;
		$this->pos=$pos;
	}
	
	public function __destruct() {
		if($this->stmt) {
			$this->stmt->free_result();
			$this->stmt->close();
		}
	}
	
}


class QueryStep {
	public $predName=null;
	public $hash=null;
	public $lang=null;
	public $nodeType=null;
	public $type=null;
	public $fullName=null;
	public $key=null;
	public $prev=null;
	public $table=null;
}



