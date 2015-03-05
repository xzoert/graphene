<?php

namespace um;

class UserType extends \graphene\Type 
{
    
    private static $expirationTime=3600;  // in seconds: 1 hour. 
    
    
    function setExpirationTime($secs) 
    {
        self::$expirationTime=$secs;
    }
    
    function getExpirationTime() 
    {
        return self::$expirationTime;
    }
    
    function getLoggedUser($token) 
    {
        $user=$this->getBy('token',$token);
        if  ($user && $user->tokenIsValid()) {
            // we set a begin / commit block, so the caller must not bother 
            // about starting a transaction just to get back the logged user
            // and if we already are in a transaction this will do no harm
            $db=$this->db();
            $db->begin();
            try {
                $user->refreshToken(self::$expirationTime);
                $db->commit();
            } catch (\Exception $e) {
                $db->rollback();
                throw $e;
            }
            return $user;
        }
    }
    
    function authenticate($emailOrNickname,$password) 
    {
        return $this->select(
            '(email=? or nickname=?) and password=?',
            array($emailOrNickname,$emailOrNickname,$password)
        )[0];
    }
    
    function getByPrivilege($privilege) 
    {
        return $this->select('groups.ancestors.privileges=?',$privilege);
    }
    
    
}


