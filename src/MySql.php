<?php

namespace graphene;

require_once 'DbStorage.php';

class MySql extends DbStorage {

    private $mysqli;
    private $nodetn;
    private $predtn;
    private $prefix;
    private $countertn;
    private $start_time;
    private $uid;
    private $log=0;
    private $ql;
    
    public function __construct($params) {
        
        if( !class_exists('mysqli') ) $this->error('Mysqli driver is not installed on the server.');
        
        $this->connect($params);
        $this->prefix=$params['prefix'];
        if( $this->prefix && substr($this->prefix,-1)!='_' ) $this->prefix.='_';
        $this->nodetn=$this->table_name('graphene_node');
        $this->countertn=$this->table_name('graphene_id');
        $this->predtn=$this->table_name('graphene_pred');
        $this->node_cache_list=array();
        $this->node_cache_idx=array();
        $this->triple_cache=array();
        $this->pred_cache=array();
        $this->uid=$this->uid();
        $this->write_mode=0;
        $rs=$this->sql_query('show tables like '.$this->quote($this->prefix.'graphene_all_triples'));
        $n=mysqli_num_rows($rs);
        mysqli_free_result($rs);
        if( !$n ) $this->init_db();
        parent::__construct($params);
    }
    
    public function log_queries($v) {
        $this->log=$v;
    }
    
    private function uid($n=8) {
        static $chars='abcdefghijklmnpqrstuvwxyz';
        $len=strlen( $chars );                             
        $i=$n;
        $id='';
        while( $i-- ) {
            $r=rand(0,$len-1);
            $id.=substr($chars,$r,1);
        }
        return( $id );
    }
    
    private function now() {
        $rs=$this->sql_query('select now() as now');
        $row=mysqli_fetch_assoc($rs);
        mysqli_free_result($rs);
        return $row['now'];                                                  
    }
    
    
    private function connect($params) {
        $this->mysqli=new \mysqli($params['host'],$params['user'],$params['pwd'],$params['db'],$params['port']);
        if ($this->mysqli->connect_errno) {
            throw new \Exception( 'Failed to connect to MySQL: (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error );
        }
        $this->sql_query('set names utf8');
        //$this->sql_query( 'start transaction' );
        $this->start_time=$this->now();
    }
    
    public function closeConnection() {
        if( !$this->mysqli ) return;
        //i do not rollback: it does it anyway and doing it here appears to be time consuming
        mysqli_close($this->mysqli); 
    }
    
    public function write_start() { 
        $this->sql_query( 'start transaction' );
    }
    
    public function write_end($commit) {
        if( $commit ) $this->sql_query('commit');
        else $this->sql_query('rollback');
        //$this->sql_query( 'start transaction' );
    }

    public function get_ql($db,$type) {
        //return new mysql_ql($db,$type);
    }
    
    public function getQl($db,$type) {
        return new mysql_ql($db,$type);
    }
    
    
    public function init_db() {
        $this->sql_query('drop table if exists '.$this->nodetn);
        $this->sql_query('create table '.$this->nodetn.' ( id bigint not null primary key, props longtext ) character set utf8 engine=innodb' );
        $this->sql_query('drop table if exists '.$this->countertn);
        $this->sql_query('create table '.$this->countertn.' ( id bigint not null primary key auto_increment, pred varchar(255) ) character set utf8 engine=innodb' );
        $this->sql_query('drop table if exists '.$this->predtn);
        $this->sql_query('create table '.$this->predtn.' ( 
            name varchar(255) not null primary key,
            type varchar(255)
        ) character set utf8 engine=innodb');
        $this->create_datatype('node','bigint');
        $this->create_datatype('string','longtext',64);
        $this->create_datatype('int','bigint');
        $this->create_datatype('float','double');
        $this->create_datatype('datetime','datetime');
        /*
        $db_indexers = $this('@discovery')->find('@db_indexer');
        foreach ($db_indexers as $db_indexer) {
            $this($db_indexer['class'])->init_index($this);
        }
        */
    }

    public function get_mysqli() {
        return $this->mysqli;
    }

    public function create_datatype($name,$mysqltype,$index_size=null) {
        if( $this->write_mode ) throw new Exception( 'Datatypes should not be created in write mode. ' );
        if( $index_size ) $idx='pred,ob('.((int)$index_size).')';
        else if( !$index_size ) $idx='pred,ob';

        $tn=$this->datatype_table($name);
        $this->sql_query( 'drop table if exists '.$tn );
        $sql='create table '.$tn.' ('.
            'id bigint not null primary key, '.
            'sub bigint not null, '.
            'pred varchar(255) not null, '.
            'ob '.$mysqltype.' not null, '.
            'ob_ok bigint not null';
        if( $name==='node' ) $sql.=', sub_ok bigint not null';
        $sql.=') character set utf8 engine=innodb';
        $this->sql_query( $sql );
        $this->sql_query( 'alter table '.$tn.' add index sub_idx ( sub, pred, ob_ok )' );
        if( $name!=='node' ) $this->sql_query( 'alter table '.$tn.' add index ob_idx ( '.$idx.' )' );
        else $this->sql_query( 'alter table '.$tn.' add unique index ob_idx ( ob,pred,sub_ok )' );
        $atv='';
        $anv='';
        $this->sql_query( 'drop view if exists '.$this->table_name('graphene_all_triples') );
        $this->sql_query( 'drop view if exists '.$this->table_name('graphene_all_nodes') );
        $rs=$this->sql_query( 'show tables like '.$this->quote($this->prefix.'graphene\\_triple\\_%') );
        while( $row=mysqli_fetch_row($rs) ) {
            if( $atv ) $atv.=' union all ';
            if( $anv ) $anv.=' union ';
            $atv.='select id, sub,pred,ob, '.$this->quote(substr($row[0],16+($this->prefix?strlen($this->prefix):0))).' as type';
            if( $row[0]===$this->prefix.'graphene_triple_node' ) $atv.=', sub_ok';
            else $atv.=', null as sub_ok';
            $atv.=', ob_ok from `'.$row[0].'`';
            $anv.='select sub as node from `'.$row[0].'`';
            if( $row[0]==$this->prefix.'graphene_triple_node' ) {
                $anv.=' union select ob as node from `'.$row[0].'`';
            }
        }
        mysqli_free_result($rs);
        $this->sql_query( 'create view '.$this->table_name('graphene_all_triples').' as '.$atv );
        $this->sql_query( 'create view '.$this->table_name('graphene_all_nodes').' as '.$anv );
        
        //$this->sql_query( 'start transaction' );
    }

    public function sql_query($sql) {
        if( $this->log ) echo "query: $sql\n";
        //$this('@log')->log('debug-sql',$sql);
        $r=$this->mysqli->query($sql);
        if( !$r ) throw new \Exception( "MySQL reported an error executing following query:\n".$sql."\nErrno: ".$this->mysqli->errno."\nError: ".$this->mysqli->error );
        return $r;
    }

    public function quote($s) {
        if( is_null($s) || (is_string($s) && $s=='') ) return 'NULL';
        return '\''.mb_ereg_replace('\'','\\\'',mb_ereg_replace('\\\\','\\\\',$s)).'\'';
    }

    public function quote_name($s) {
        return '`'.mb_ereg_replace('`','\\`',mb_ereg_replace('\\\\','\\\\',$s)).'`';
    }

    public function table_name($n) {
        return $this->quote_name($this->prefix.$n);
    }
    
    public function datatype_table($type) {
        return $this->table_name('graphene_triple_'.$type);
    }                                                                               

    public function datatypeTable($type) {
        return $this->datatype_table($type);
    }
    
    public function nodeTable() {
        return $this->table_name('graphene_node');
    }
    
    protected function lock_node($id) {
        $rs=$this->sql_query( 'select id from '.$this->nodetn.' where id='.$this->quote($id).' lock in share mode' );
        mysqli_free_result($rs);
    }

    protected function load_node($id) {
        if( $this->is_writing() ) $locksql=' lock in share mode';
        else $locksql='';
        $rs=$this->sql_query( 'select props from '.$this->nodetn.' where id='.$this->quote($id).$locksql );
        $arr=mysqli_fetch_assoc($rs);
        mysqli_free_result($rs);
        return $arr;                                                                                              
    }
    
    protected function insert_node($id,$props) {
        $this->sql_query( 'insert into '.$this->nodetn.' (id, props) values ('.$this->quote($id).','.$this->quote($props).')' );        
    }
    
    protected function remove_node($id) {
        $this->sql_query( 'delete from '.$this->nodetn.' where id='.$this->quote($id) );
    }
    
    protected function update_node($id,$props) {
        $this->sql_query( 'update '.$this->nodetn.' set props='.$this->quote($props).' where id='.$this->quote($id) );
    }
    
    protected function load_triple($id,$type) {
        //if( $this->is_writing() ) $locksql=' for update';
        $locksql='';
        $rs=$this->sql_query( 'select * from '.$this->datatype_table($type).' where id='.$this->quote($id).$locksql );
        $arr=mysqli_fetch_assoc($rs);
        mysqli_free_result($rs);
        if( $arr ) {
            $this->normalize_triple($arr,$type);
            return $arr;
        }
    }
    
    protected function insert_triple($triple) {
        $type=$triple['type'];
        if( $type==='node' ) {
            $this->sql_query( 'insert into '.$this->datatype_table($type).' (id, sub, pred, ob, sub_ok, ob_ok ) values ( '.
                $this->quote($triple['id']).','.
                $this->quote($triple['sub']).','.
                $this->quote($triple['pred']).','.
                $this->quote($triple['ob']).','.
                $this->quote($triple['sub_ok']).','.
                $this->quote($triple['ob_ok']).
            ')' );
        } else {
            $this->sql_query( 'insert into '.$this->datatype_table($type).' (id, sub, pred, ob, ob_ok ) values ( '.
                $this->quote($triple['id']).','.
                $this->quote($triple['sub']).','.
                $this->quote($triple['pred']).','.
                $this->quote($triple['ob']).','.
                $this->quote($triple['ob_ok']).
            ')' );
        }
        
    }
    
    protected function update_triple($triple) {
        $type=$triple['type'];
        if( $type==='node' ) {
            $this->sql_query( 'update '.$this->datatype_table($type).
                ' set sub='.$this->quote($triple['sub']).
                ',pred='.$this->quote($triple['pred']).
                ',ob='.$this->quote($triple['ob']).
                ',sub_ok='.$this->quote($triple['sub_ok']).
                ',ob_ok='.$this->quote($triple['ob_ok']).
                ' where id='.$this->quote($triple['id']) 
            );
        } else {
            $this->sql_query( 'update '.$this->datatype_table($type).
                ' set sub='.$this->quote($triple['sub']).
                ',pred='.$this->quote($triple['pred']).
                ',ob='.$this->quote($triple['ob']).
                ',ob_ok='.$this->quote($triple['ob_ok']).
                ' where id='.$this->quote($triple['id']) 
            );
        }
    }
    
    protected function remove_triple($id,$type) {
        $this->sql_query( 'delete from '.$this->datatype_table($type).' where id='.$this->quote($id) );
    }
    
    protected function get_triple_predicate($id) {
        $rs=$this->sql_query( 'select pred from '.$this->countertn.' where id='.$this->quote($id) );
        $row=mysqli_fetch_row($rs);
        mysqli_free_result($rs);
        return $row[0];        
    }
    
    protected function update_triple_predicate($id,$pred) {
        $this->sql_query( 'update '.$this->countertn.' set pred='.$this->quote($pred).' where id='.$this->quote($id) );
    }

    protected function remove_triple_predicate($id) {
        $this->sql_query( 'delete from '.$this->countertn.' where id='.$this->quote($id) );
    }
    
    protected function new_id($pred=null) {
        $tn=$this->countertn;
        if( !mysqli_multi_query( $this->mysqli, 'insert into '.$tn.' (pred) values ('.$this->quote($pred).'); select last_insert_id()' ) ) {
            throw new Exception( 'Could not create id.' );
        }
        $r=mysqli_store_result($this->mysqli);
        if( $r ) mysqli_free_result($r);
        mysqli_next_result($this->mysqli);
        $r=mysqli_store_result($this->mysqli);
        $row=mysqli_fetch_row($r);
        mysqli_free_result($r);
        return (int)$row[0];
    }

    protected function load_pred($name) {
        //if( $this->write_mode ) $locksql=' lock in share mode';
        $locksql='';
        $r=$this->sql_query('select * from '.$this->predtn.' where name='.$this->quote($name).$locksql);
        $pred=mysqli_fetch_assoc($r);
        mysqli_free_result($r);
        return $pred;
    }
    
    protected function update_pred($name,$type) {
        $this->sql_query( 'update '.$this->predtn.' set type='.$this->quote($type).' where name='.$this->quote($name) );
    }
    
    protected function insert_pred($name,$type) {
        $this->sql_query('insert into '.$this->predtn.' (name,type) values ('.$this->quote($name).','.$this->quote($type).')');
    }
    
    protected function get_triple_id_by_ob_ok($sub,$pred,$type,$ok) {
        $rs=$this->sql_query( 'select id from '.$this->datatype_table($type).' where sub='.$this->quote($sub).' and pred='.$this->quote($pred).' and ob_ok='.$ob_ok );
        if( mysqli_num_rows($rs) ) {
            $row=mysqli_fetch_row($rs);
            mysqli_free_result($rs);
            $id=$row[0];
        }
        return $id;
    }
    
    protected function get_triple_by_id_sub_ok($ob,$pred,$type,$ok) {
        $rs=$this->sql_query( 'select id from '.$this->datatype_table($type).' where ob='.$this->quote($ob).' and pred='.$this->quote($pred).' and ob_ok='.$ob_ok );
        if( mysqli_num_rows($rs) ) {
            $row=mysqli_fetch_row($rs);
            mysqli_free_result($rs);
            $id=$row[0];
        }
        return $id;
    }
    
    function get_triple_list( $types, $sub, $pred, $ob, $sub_ok, $ob_ok ) {
        //if( $this->is_writing() ) $locksql=' for update';
        $locksql='';
        $sql='';
        foreach( $types as $type ) {
            if( $sql ) $sql.=' union all ';
            if( $type=='node' ) {
                $sql.='select id, sub, pred, ob, '.$this->quote($type).' as type, sub_ok, ob_ok from '.$this->datatype_table($type);
            } else {
                $sql.='select id, sub, pred, ob, '.$this->quote($type).' as type, null as sub_ok, ob_ok from '.$this->datatype_table($type);
            }
            $sql.=' where true ';
            if( $sub ) $sql.=' and sub='.$this->quote($sub);
            if( $pred ) $sql.=' and pred='.$this->quote($pred);
            if( $ob ) $sql.=' and ob='.$this->quote($ob);
            if( $sub_ok ) $sql.=' and sub_ok='.$this->quote($sub_ok);
            if( $ob_ok ) $sql.=' and ob_ok='.$this->quote($ob_ok);
        }
        if( $sub ) {
            if( is_null($pred) ) $sql.=' order by pred, ob_ok';
            else $sql.=' order by ob_ok';
        }
        else if( $ob && $type=='node' ) {
            if( is_null($pred) ) $sql.=' order by pred, sub_ok';
            else $sql.=' order by sub_ok';
        }
        $sql.=$locksql;
        return new MySqlIterator( $this, $this->sql_query($sql) );
    }

    function get_next_object_triple_id($triple) {
        $rs=$this->sql_query('select id from '.$this->datatype_table($triple['type']).
            ' where sub='.$this->quote($triple['sub']).
            ' and pred='.$this->quote($triple['pred']).
            ' and ob_ok>'.$this->quote($triple['ob_ok']).' order by ob_ok asc limit 1' 
        );
        if( $row=mysqli_fetch_assoc($rs) ) {
            $id=(int)$row['id'];
        }                         
        mysqli_free_result($rs);
        return $id;
    }                          
    
    function get_next_subject_triple_id($triple) {
        $rs=$this->sql_query('select id from '.$this->datatype_table($triple['type']).
            ' where ob='.$this->quote($triple['ob']).
            ' and pred='.$this->quote($triple['pred']).
            ' and sub_ok>'.$this->quote($triple['sub_ok']).' order by sub_ok asc limit 1' 
        );
        if( $row=mysqli_fetch_assoc($rs) ) {
            $id=(int)$row['id'];
        }                         
        mysqli_free_result($rs);
        return $id;
    }

    function get_last_ob_ok($sub,$pred,$type) {
        $rs=$this->sql_query( 'select ob_ok from '.$this->datatype_table($type).
            ' where sub='.$this->quote($sub).
            ' and pred='.$this->quote($pred).
            ' order by ob_ok desc limit 1' 
        );
        $row=mysqli_fetch_row($rs);
        mysqli_free_result($rs);
        if( $row ) return (int)$row[0];
    }

    function get_last_sub_ok($ob,$pred,$type) {
        $rs=$this->sql_query( 'select sub_ok from '.$this->datatype_table($type).
            ' where ob='.$this->quote($ob).
            ' and pred='.$this->quote($pred).
            ' order by sub_ok desc limit 1' 
        );
        $row=mysqli_fetch_row($rs);
        mysqli_free_result($rs);
        if( $row ) return (int)$row[0];
    }

    
}

class MySqlIterator extends ResultSet {
    
    private $rs;
    private $storage;
    private $row;
    private $mysqli;
    
    public function __construct($storage,$rs) {
        $this->storage=$storage;
        $this->rs=$rs;
    }

    function get_storage() {
        return $this->storage;
    }
    /*
    function get_subject() {
        return $this->storage->node((int)$this->row['sub']);
    }
    
    function get_object() {
        return $this->storage->pack_datum($this->row['ob'],$this->row['type']);
    }
    
    public function get_predicate() {
        return $this->row['pred'];
    }
    */
    
    public function get_triple() {
        return $this->storage->normalize_triple($this->row, $this->row['type']);
    }
    
    public function rewind() {
        if( $this->rs ) {
            mysqli_data_seek($this->rs,0);
            $this->row=mysqli_fetch_assoc($this->rs);
        }
    }
    
    public function valid() {
        return $this->rs && !is_null($this->row);
    }
    
    
    public function next() {
        $this->row=mysqli_fetch_assoc($this->rs);
    }
    
    public function count() {
        return mysqli_num_rows($this->rs);
    }
    
    public function seek($pos) {
        mysqli_data_seek($this->rs,$pos);
        $this->row=mysqli_fetch_assoc($this->rs);
    }
    
    public function __destruct() {
        mysqli_free_result($this->rs);
    }
    
}


                                                                 


