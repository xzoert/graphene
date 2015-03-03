<?php

namespace um;

class UserNode extends \graphene\Node {

	
	function subscribe($name) {
		$g=$this->getType('Group',__NAMESPACE__)->getBy('groupName',$name);
		if( !$g ) throw new \Exception( 'Group '.$name.' does not exist.' );
		$this->groups->add($g);
	}

	function unsubscribe($name) {
		$g=$this->getType('Group',__NAMESPACE__)->getBy('groupName',$name);
		if( $g ) $this->groups->remove($g);
	}
	
	function login() {
        $token=null;
        // we set a begin / commit block, so the caller must not bother 
        // about starting a transaction just to get the user logged in
        // and if we already are in a transaction this will do no harm
        $db=$this->db();
        $db->begin();
        try {
            if( $this->tokenIsValid() ) {
                $this->refreshToken($this->type()->getExpirationTime());
                $token=$this->data()->token;
            } else {
                $token=$this->getNewToken($this->type()->getExpirationTime());
            }
            $db->commit();
        } catch( \Exception $e ) {
            $db->rollback();
            throw $e;
        }
        return $token;
	}
	
	function hasPrivilege($privilege) {
		return $this->type(__CLASS__)->select(
			'#x=? and #x.groups.ancestors.privileges=?',
			array($this,$privilege)
		)->count()>0;
	}
	
	function logout() {
        // we set a begin / commit block, so the caller must not bother 
        // about starting a transaction just to get the user logged out
        // and if we already are in a transaction this will do no harm
        $db=$this->db();
        $db->begin();
        try {
            $this->data()->token=null;
            $db->commit();
        } catch( \Exception $e ) {
            $db->rollback();
            throw $e;
        }
	}
	
	function tokenIsValid() {
		$data=$this->data();
		$curToken=$data->token;
		if( !is_string($curToken) ) {
			return false;
		}
		$now=new \DateTime();
		if ($data->tokenExpires<$now) {
			return false;
		}
		return true;
	}
	
	function refreshToken($seconds) {
		$expires=new \DateTime();
		$expires->add(new \DateInterval('PT'.$seconds.'S'));
		$this->data()->tokenExpires=$expires;
	}
	
	
	
	private function rands($n=8) {
		static $chars='ABCDEFGHIJKLMNPQRSTUVWXYZ123456789';
		$len=strlen( $chars );
		$s='';
		while ($n--) {
			$r=rand(0,$len-1);
			$s.=substr($chars,$r,1);
		}
		return( $s );
	}
	
	
	function __toString() {
		$s=$this->nickname;
		if (!$s) $s='User '.$this->id();
		return $s;
	}

	private function getNewToken($seconds) {
	    $attempts=10;
		while ($attempts--) {
			$token=$this->rands(12);
			try {
				$this->data()->token=$token; 
				break; 
			}
			catch( \Exception $e ) {}  // in the very unfortunate case it is not unique...
		}
		$this->refreshToken($seconds);
		return $token;
	}
	
	
	
	private function _emailValidator($v) {
		if (filter_var($v, FILTER_VALIDATE_EMAIL)===false) {
			throw new \Exception( 'Invalid email: '.$v );
		} 
	}
	
	                     
}


