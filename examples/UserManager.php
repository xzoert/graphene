<?php

require_once 'init.php';



$db->unfreeze();
$db->begin();

echo PHP_EOL,PHP_EOL,'####################### USER MANAGER EXAMPLE #########################',PHP_EOL,PHP_EOL;


////////// GET INITIAL NODE COUNT /////////

$nodeCount=$db->select()->count();
echo "Initial node count is ",$nodeCount,PHP_EOL;

///////// BASIC STUFF /////////////

$users=$db->User;
$groups=$db->um_Group;

$bobsEmail="bob@example.com";
$bobsPwd="12345";
$bobsNickname="bob";

// create user bob
$bob=$db->User->newNode(array(
	"email"=>$bobsEmail,
	"password"=>$bobsPwd,
	"nickname"=>$bobsNickname
));

// set some personal data
$bob->firstName="Robert";
$bob->lastName="Taylor";
$bob->address=$db->Adress(array("street"=>"Borgo San Frediano","apartment"=>"43","city"=>"Florence","country"=>"Italy"));


// display its email
echo "Email of Bob: ",$bob->email,PHP_EOL;
// display its last name
echo "Last name of Bob: ",$bob->lastName,PHP_EOL;

// get it back by email
$gotback=$users->getBy("email",$bobsEmail);

// display the gotback
echo "Gotback: ",$gotback->email,' (',$gotback->firstName,' ',$gotback->lastName,')',PHP_EOL;


//////////  LOGIN //////////////

// try to authenticate by email
if( $auth=$db->um_User->authenticate($bobsEmail,$bobsPwd) ) {
	echo "Authenticated by email as: ",$auth->firstName,' ',$auth->lastName,PHP_EOL;
} else {
	throw new Exception("Something went wrong... bob is not authenticated.");
}

// try to authenticate by nickname
if( $auth=$users->authenticate($bobsNickname,$bobsPwd) ) {
	echo "Authenticated by nickname as: ",$auth,PHP_EOL;
} else {
	throw new Exception("Something went wrong... bob is not authenticated.");
}

// set expiration time 3 seconds
$users->setExpirationTime(3);

// try to login
$token=$auth->login();
echo "Token: ",$token,PHP_EOL;

// get back the user by its token
$logged=$users->getLoggedUser($token);
if( $logged ) {
	echo "Logged: ",$logged,PHP_EOL;
} else {
	throw new Exception("Something went wrong... bob is not logged in.");
}

// try logout
$bob->logout();

if( $users->getLoggedUser($token) ) echo "Ooops... Bob is still logged in...",PHP_EOL;
else echo "Ok, Bob has logged out.",PHP_EOL;

// re-login
$token=$bob->login();


// check expiration time
echo "Wait two seconds and relog in the user.",PHP_EOL;
sleep(2);
echo "Regot token: ",$bob->login(),PHP_EOL;

echo "Wait two seconds and check if it has been refreshed.",PHP_EOL;
sleep(2);
$logged=$users->getLoggedUser($token);
if( $logged ) {
	echo "Ok, Bob is still logged in.",PHP_EOL;
} else {
	throw new Exception("Something went wrong... bob not logged in anymore.");
}

echo "Wait four seconds and check if the session expired.",PHP_EOL;
sleep(4);
$logged=$users->getLoggedUser($token);
if( !$logged ) {
	echo "Ok, Bob's session has expired.",PHP_EOL;
} else {
	throw new Exception("Something went wrong... bob is still logged in.");
}



//////////// GROUPS AND PRIVILEGES //////////////


// create admin group
$admin=$groups->newNode(array("groupName"=>"admin"));
$admin->privileges->add("manage-users");
$admin->privileges->add("change-settings");

// create superadmin group as a child of admin
$superadmin=$groups->newNode(array("groupName"=>"superadmin"));
$superadmin->privileges->add("change-database-params");
$superadmin->parentGroup=$admin;

// subscribe bob to superadmin
$bob->subscribe("superadmin");

// verify it has 'change-database-params' coming from superadmin
echo "Can Bob change the database params? ",$bob->hasPrivilege("change-database-params")?"yes":"no",PHP_EOL;

// verify it has 'manage-users' coming from admin
echo "Can Bob manage users? ",$bob->hasPrivilege("manage-users")?"yes":"no",PHP_EOL;

// verify it has not the right to beat my cousin
echo "Can Bob beat my cousin? ",$bob->hasPrivilege("beat-my-cousin")?"yes":"no",PHP_EOL;


// create a third group and mess around with parents
$subGroup=$groups->newNode(array("groupName"=>"subgroup","parentGroup"=>$superadmin));

// unset and reset a parent
$superadmin->parentGroup=null;
$superadmin->parentGroup=$admin;

// try to create a loop
try {
	$admin->parentGroup=$subGroup;
	echo "Ooops... no loop detection?",PHP_EOL;
} catch( \Exception $e ) { 
	echo "Ok, loop has been detectd.",PHP_EOL;	
}

// create some more users
$kate=$db->User(array(
	"email"=>"kate@example.com",
	"password"=>"12345",
	"nickname"=>"kate"
));

$alice=$db->User(array(
	"email"=>"alice@example.com",
	"password"=>"12345",
	"nickname"=>"alice"
));

$kate->subscribe("admin");

$alice->subscribe("subgroup");


// check out the recursive search functions...
foreach( $admin->getMembersRecursive() as $u ) {
	echo $u," is member of 'admin'.",PHP_EOL;
}
foreach( $superadmin->getMembersRecursive() as $u ) {
	echo $u," is member of 'superadmin'.",PHP_EOL;
}
foreach( $subGroup->getMembersRecursive() as $u ) {
	echo $u," is member of 'subgroup'.",PHP_EOL;
}
foreach( $groups->getByPrivilege("manage-users") as $g ) {
	echo "members of group '",$g,"' can manage users",PHP_EOL; 
}
foreach( $groups->getByPrivilege("change-database-params") as $g ) {
	echo "members of group '",$g,"' can change the database params",PHP_EOL; 
}
foreach( $users->getByPrivilege("change-database-params") as $u ) {
	echo "User '",$u,"' can change the database params",PHP_EOL; 
}




// delete all
echo "Deleting all...";
$bob->delete();
$kate->delete();
$alice->delete();
$admin->delete();
$superadmin->delete();
$subGroup->delete();
echo "done",PHP_EOL;

$db->commit();

// check the node count is the same as at the beginning
$nodeCount2=$db->select()->count();
if( $nodeCount!=$nodeCount2 ) {
	echo "Ooops... node count is not the same...",PHP_EOL;	
} else {
	echo "Ok, final node count is ",$nodeCount2,PHP_EOL;
}


echo PHP_EOL,PHP_EOL,'##################### USER MANAGER EXAMPLE END #######################',PHP_EOL,PHP_EOL;


