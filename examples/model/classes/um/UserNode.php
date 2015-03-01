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
		if( $this->tokenIsValid() ) {
			$this->refreshToken($this->type()->getExpirationTime());
			return $this->data()->token;
		} else {
			return $this->getNewToken($this->type()->getExpirationTime());
		}
	}
	
	function hasPrivilege($privilege) {
		return $this->type(__CLASS__)->select(
			'#x=? and #x.groups.ancestors.privileges=?',
			array($this,$privilege)
		)->count()>0;
	}
	
	function logout() {
		$this->data()->token=null;
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
		while (1) {
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
		if (!filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
		  return;
		} else {
			throw new \Exception( 'Invalid email: '.$v );
		}
	}
	
	
}


