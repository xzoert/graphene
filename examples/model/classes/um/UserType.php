<?php

namespace um;

class UserType extends \graphene\Type {
	
	private static $expirationTime=3600;  // in seconds: 1 hour. 
	
	
	function setExpirationTime($secs) {
		self::$expirationTime=$secs;
	}
	
	function getExpirationTime() {
		return self::$expirationTime;
	}
	
	function getLoggedUser($token) {
		$user=$this->getBy('token',$token);
		if( $user && $user->tokenIsValid() ) {
			$user->refreshToken(self::$expirationTime);
			return $user;
		}
	}
	
	function authenticate($emailOrNickname,$password) {
		return $this->select(
			'(email=? or nickname=?) and password=?',
			array($emailOrNickname,$emailOrNickname,$password)
		)[0];
	}
	
	function getByPrivilege($privilege) {
		return $this->select('groups.ancestors.privileges=?',$privilege);
	}
	
	
}


