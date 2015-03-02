<?php 

/**
Utility class that tries to centralize some parsing and validation of basic stuff.
It is not used everywhere though, so don't think that changing things here will be
sufficient to ensure the same behaviour everywhere.
This is sometimes due to the fact that this class has been added much later, 
but sometimes it is done for optimization purposes (a more complex regexp that 
already detects the inner elements of a property name for example, as it happens
in Def or in Query), and this is done on purpose.

- Max Jacob 02 2015
*/



namespace graphene;


class Syntax {
    
    static $preds=array();
    
    
    public static function isEmpty($datum) {
        return is_null($datum) || $datum==='' || (is_array($datum) && count($datum)==0);
    }


    public static function parsePred($n,$ns='') {
        $key=$n.'|'.$ns;
        if(array_key_exists($key, self::$preds)) {
            return self::$preds[$key];
        }
        $r = array();
        if (mb_ereg('^(\@)?(\_)?(((([a-z][a-z0-9]*\_)*)([a-z][a-zA-Z0-9]*))(\:([a-z][a-z])(\_([A-Z][A-Z]))?)?)$', $n, $r)) {
            if($r[1]) $dir = -1;
            else $dir = 1;
            if (!$r[2]) {
                $pred=$ns?$ns.'_'.$r[3]:$r[3];
            } else {
                $pred=$r[3];
            }
            if( $r[7]=='id' ) throw new \Exception( 'The property name \'id\' is reserved, please use a different one' );
            $e = array('pred' => $pred, 'dir' => $dir);
            self::$preds[$key] = $e;
            return $e;
        } else {
            throw new \Exception('Wrong name: '.$n);
        }
    }
    
    public static function typeName($n,$ns='',$phpns=null) {
        if( $n[0]=='_' ) return substr($n,1);
        else {
            if( $phpns ) $ns=str_replace('\\','_',$phpns);
            else $ns=$this->_data->ns();
            return $ns?$ns.'_'.$n:$n;
        }
    }
    
    public static function relName($n,$ns='') {
        if( $n[0]=='_' ) return substr($n,1);
        if( $ns=='' ) return $n;
        return $ns.'_'.$n;
    }
    
    public static function unpackDatum(&$v) {
        if( is_object($v) ) {
            if( $v instanceof NodeBase ) $v=$v->id();
            else if( $v instanceof \DateTime ) $v=$v->format('Y-m-d H:i:s');
        } 
    }
    
}

