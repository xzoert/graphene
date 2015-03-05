<?php


/*
NOTE: the node delete function here is still open to the possibility of any node to be added 
to any type and thus have multiple types, or at least it should... 
this feature is not yet made available since it hasn't been tested enough and as well 
because the use cases aren't yet very clear to me.
If we decide to drop this feature, the code can be reviewed for optimization. 
*/

namespace graphene;

require_once 'Syntax.php';
require_once 'Prop.php';

/**
@brief Base class for graphene nodes.

Base class for graphene nodes.

@sa Node

*/
abstract class NodeBase {
    
    private $_type;                           
    
    function __construct($type) {
        $this->_type=$type;
    }
    
    /**
    @brief Gives back the node's type.
    
    @param $phpclass 
        The PHP class of the caller.
        
    @retval Type
        The requested type
        
    The optional $phpclass parameter is useful for calling this function internally
    from a custom node class which wants to be inheritable. 
    
    If MyType2 extends MyType1, when a function of MyType1 is invoked through an 
    instance of MyType2, and within the function there is a call like:
    
        $this->type()
    
    it will give you back the MyType2 type, since that's what the node is an 
    instance of. But this is not always what you want. If you want your original
    type, you can write:
    
        $this->type(__CLASS__)
    
    Which will, in the example, give you always back MyType1.

    @sa \ref node-inheritance
    @sa \ref type-names
    
    */
    public function type($phpclass=null) {
        if( $phpclass ) {
            return $this->db()->getType('_'.substr(str_replace('\\','_',$phpclass),0,-4));
        }
        return $this->_type;
    }
    
    /**
    @brief Gives back the node's namespace in graphene notation.
    
    Gives back the node's namespace in graphene notation.
    
    @retval string
        The namespace.
        
    @sa \ref type-names
    
    */
    public function ns() {
        if( $this->_type ) return $this->_type->ns();
    }
    
    function __toString() {
        return 'Node '.$this->id();
    }
    
    /**
    @brief Gives back the node's id.
    
    Gives back the node's identifier.
    
    @retval int
        The id.
    
    */
    abstract function id();

    /**
    @brief Gives back the node's database Connection.
    
    Gives back the node's database Connection.
    
    @retval Connection
    
    */
    abstract function db();    

    /**
    @brief Deletes the node.
    
    Deletes the node from the database, along with all objects signed as 
    \em delete \em cascade in the .def file.
    
    @sa \ref def-files
    
    */
    abstract function delete();    

    
    abstract protected function _mask($mask);
    
    private function validateLang($lang) {
        if( !mb_ereg('^[a-z][a-z](\_[A-Z][A-Z])?',$lang) ) throw new \Exception( 'Invalid language code: "'.$lang.'".' );
    }

    /**
    @brief Gives back the translation in a given language.
    
    Gives back the best translation of a given property for a given language. 
    Langage codes can be either two letters long (ex: "en") or five letters 
    long (ex: "en_UK"). 
    
    @param $n
        The property name.
    
    @param $lang
        A two or five letter long language code.
        
    @retval
        mixed The best value found.
        
    It will search for a match of the whole code, if not found for the first two
    letters (if you provided a 5 letter code) and if nothing is found it returns the
    default value (without any language code), if any.
    
    If you want to have back the translation for a given language without searching 
    a match, you can simply call get() by adding a ":" followed by the 
    language code to the property name:
    
        $myBook->get("title:en_UK");
        
    This will give you exactly the value of the currently stored translation for
    "en_UK", or null if none is set.
    
    
    NOTE: Translations work only on single valued properties!
        
    */
    public function getTr($n,$lang) {
        $this->validateLang($lang);
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        if( $def->isList ) {
            if( $this->_type->isFrozen() ) throw new \Exception( 'Translations are only supported for single valued properties.' );
            $def->isList=false;
            $this->_type->_saveDefinition();
        }
        $prop=new Prop($this,$def->predName.':'.$lang,$def->dir,$this->_mask($def->mask),$def);
        $r=$prop[0];
        if( is_null($r) ) {
            if( strlen($lang)==5 ) {
                $dprop=new Prop($this,$def->predName.':'.substr($lang,0,2),$def->dir,$this->_mask($def->mask),$def);
                $r=$dprop[0];
                if( !is_null($r) ) return $r;
            }
            $dprop=new Prop($this,$def->predName,$def->dir,$this->_mask($def->mask),$def);
            return $dprop[0];
        }
        return $r;
    }
    
    /**
    @brief Sets the translation in a given language.
    
    Sets the translation of a given property for a given language. 

    @param $n
        The property name.
        
    @param $lang
        The language code
        
    @param $v
        The value to set

    Langage codes can be either two letters long (ex: "en") or five letters 
    long (ex: "en_UK"). 
    
    If the code is five letters it will set as well the value of the corresponding
    two letter code if not already set, as well as the default value (without any 
    language code), if not already set.
    
    NOTE: Translations work only on single valued properties!

    */
    public function setTr($n,$lang,$v) {
        $this->validateLang($lang);
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        if( $def->isList ) {
            if( $def->frozen ) throw new \Exception( 'Translations are only supported for single valued properties.' );
            else {
                $def->isList=false;
                $this->_type->_saveDefinition();
            }
        }
        $mask=$this->_mask($def->mask);
        $prop=new Prop($this,$def->predName.':'.$lang,$def->dir,$mask,$def);
        if( Syntax::isEmpty($v) ) {
            $prop->delete();
        } else if( is_array($v) ) {
            throw new \Exception('Property '.$n.' is single valued, can not set array.');
        } else {
            $prop[0]=$v;
        } 
        $dprop=new Prop($this,$def->predName,$def->dir,$mask,$def);
        if( $dprop->count()==0 ) $dprop[0]=$v;
        if( strlen($lang)==5 ) {
            $dprop=new Prop($this,$def->predName.':'.substr($lang,0,2),$def->dir,$mask,$def);
            if( $dprop->count()==0 ) $dprop[0]=$v;
        }
    }
    
    /**
    @brief Gets a property.
    
    Returns a property or the value of a property.

    @param $n
        The property name.
        
    @retval mixed
        The value for single valued properties, or a Prop for lists and sets.
    
    What this function returns depends actually on what kind of property it is.
    For properties that are lists or sets, a Prop object is returned. For single 
    valued properties it will return directly the value. 
    
    Whether a property is single valued or not depends on what is written in the
    .def file. By default properties are sets, so when a property has never been
    mentioned this function will return a Prop object. But as you set the first 
    value as a single value and Graphene is not frozen, the property will be 
    marked as single valued. This might sound a little bit odd, but in general
    it is quite transparent and does what you expect. And if it does not, a little 
    fix in the .def file is quickly done...
    
    @sa \ref def-files
    
    You can add a ":" followed by a language code to the property name to get
    a stored translation, or null if not set. For exaple:
    
        $myBook->get("title:en_UK");
    
    @sa getTr()
    */
    public function get($n) {
        $pos=strpos($n,':');
        if( $pos!==false ) {
            $s=$n;
            $n=substr($s,0,$pos);
            $lang=substr($s,$pos+1);
            $this->validateLang($lang);
        } else {
            $lang=null;
        }
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        $pred=$def->predName;
        $dir=$def->dir;
        $mask=$def->mask;
        if( $lang ) {
            if( $def && $def->isList ) {
                if( $def->frozen ) throw new \Exception( 'Translations are only supported for single valued properties.' );
                $def->isList=false;
                $this->_type->_saveDefinition();
            }
            $pred=$pred.':'.$lang;
        } 
        $prop=new Prop($this,$pred,$dir,$this->_mask($mask),$def);
        if( !$def || $def->isList ) return $prop;
        else return $prop[0];
    }

    /** @brief Gives you back a property.
    
    Gives you back a property.
    
    @param $n
        The property name.
        
    @retval Prop
        The requested property.
        
    Differently from get(), this function always return a Prop object, even for
    single valued properties. It can be useful to manage properties you 
    ignore the cardinality of, providing a uniform API to any kind of property.
    
    */
    public function prop($n) {
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        $pred=$def->predName;
        $dir=$def->dir;
        $mask=$def->mask;
        return new Prop($this,$pred,$dir,$this->_mask($mask),$def);
    }

    
    /**
    @brief Sets a property.
    
    Sets a property.
    
    @param $n
        The property name.
    
    @param $v
        The value(s) to be set. 
        
    You can either set the value of a single valued property, or reset all values
    of a list or set by passing an array.
    
    When you pass a single value and Graphene is unfrozen, it will mark the 
    property as single valued. 
    
    Notice also that, when unfrozen, Graphene assigns the property a type when you
    set it the first time: if you set a string it will become string if you set an
    int it will become int and so forth. If you do not agree with the type Graphene
    has assigned the property to, you can fix it in the .def file.
    
    @sa \ref def-files
    
    You can add a ":" followed by a language code to the property name to set
    a translation. For exaple:
    
        $myBook->set("title:en_UK","Finnegans wake");
        
    This is exactly the same as calling:
    
        $myBook->setTr("title","en_UK","Finnegans wake");
    
    
    
    */
    public function set($n,$v) {
        $pos=strpos($n,':');
        if( $pos!==false ) return $this->setTr(substr($n,0,$pos),substr($n,$pos+1),$v);
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            throw new \Exception( 'No such property: '.$n );
        }
        $mask=$this->_mask($def->mask);
        $prop=new Prop($this,$def->predName,$def->dir,$mask,$def);
        if( Syntax::isEmpty($v) ) {
            $prop->delete();
        } else if( is_array($v) ) {
            if( !$def->isList ) throw new \Exception('Property '.$n.' is single valued, can not set array.');
            $prop->reset($v);
        } else {
            if( $def->isList && !$def->frozen ) {
                $def->isList=false;
                $this->_type->_saveDefinition();
            }
            $prop[0]=$v;
        } 
    }
    
    /**
    @brief Shortcut for set().
    
    Shortcut for get().
    
    @param $n
        The property name.
    
    @param $v
        The value to be set.
        
    This is what allows you to do:

        $john->firstName="John";
    
    instead of:
        
        $john->set("firstName","John");
    
    */
    public function __set($n,$v) { return $this->set($n,$v); }
    /**
    @brief Shortcut for get().
    
    Shortcut for get().
    
    @param $n
        The property name.
        
    This is what allows you to call:
    
        echo $john->firstName;
  
    instead of:
    
        echo $john->get("firstName");
        
    */
    public function __get($n) { return $this->get($n); }

    /*
    This one is quite meaningless without untyped nodes.
    Probably it should be changed to tell you if the node type
    has a definition for a given property (always true if the type is 
    not frozen).
    
    - Max Jacob 02 2015
    */
    function hasProp($n) {
        $pos=strpos($n,':');
        if( $pos!==false ) {
            $s=$n;
            $n=substr($s,0,$pos);
            $lang=substr($s,$pos+1);
            $this->validateLang($lang);
        } else {
            $lang=null;
        }
        $def=$this->_type->_findDef($n);
        if( !$def ) {
            return;
        }
        $pred=$def->predName;
        if( $lang ) {
            $pred=$pred.':'.$lang;
        } 
        $dir=$def->dir;
        $prop=new Prop($this,$pred,$dir,\graphene::ACCESS_FULL,$def);
        return $prop->count()>0;
    }

    /**
    @brief Lets you set a collection of fields in a single call.
    
    Lets you set a collection of fields in a single call.
    
    @param $args
        An associative array having as keys the propery names and as values the
        values to (re)set.
    
    */
    public final function update(array $args) {
        foreach( $args as $k=>$v ) {
            $this->$k=$v;
        }
    }

    
}

/**
@brief A node.

Nodes are the foundamental data objects in Graphene. In general a node is a 
collection of properties each one having a name, a type and a list of values.

@sa Prop

A node has usually a Type, which determines its data structure. The data structure
definition is written in the definition files, which are auto-generated when 
Graphene is in unfrozen mode and that you can review later on.

@sa \ref def-files
@sa \ref freeze-unfreeze

You can create a node of a given type like this:

    $myNode=$db->MyType();

or, if you don't like magic methods:

    $myNode=$db->getType('MyType')->newNode();
    
@sa Connection::__call()
@sa Type::newNode()
    

\section node-inheritance Custom node classes

By default the class used for nodes by Graphene is the Node class itself. But you 
can provide for each of your types a custom class extending graphene\\Node.

The class must be located in the \em classes directory within the Connection's 
classpath(s). The name of the class must be the name of the node type followed by
'Node', and the file name must be called the same way.

If for example I want to customize the nodes of ab_cd_Person type, I have to 
create the file:

    {classpath}/classes/ab/cd/PersonNode.php
    
And within this file there must be the corresponding PHP class:

    <?php
    
    namespace ab\cd;
    
    class PersonNode extends \graphene\Node {
        
    
    }

Notice that the PHP namespaces must follow the Graphene namespaces, which makes
sense since you don't want your PHP classes to have name conflicts either.

From now on Graphene will use your class instead of the Node class, and any public
method you implement will be available to the caller.

There are two special methods you can overload when you create a custom node class:
_initNode() and _cleanupNode(). The former will be called when the node is created,
the second when the node is deleted:

    <?php
    
    namespace ab\cd;
    
    class PersonNode extends \graphene\Node {
        
        protected function _initNode() {
            echo "Person node ",$this->id()," created",PHP_EOL;
        }
        
        protected function _cleanupNode() {
            echo "Person node ",$this->id()," deleted",PHP_EOL;
        }
    
    }


Furthermore you can place \em validators and \em triggers on your class to validte
data fields and perform certain actions when property values are inserted, update
or deleted, as will be explained in the next sections.

@sa \ref inheritance

\subsection validators Validators

If you implement a function called "_" followed by a property name, followed by
"Validator", this function will be called each time a value is about to be written
to that property. For example:

    protected function _emailValidator($v) 
    {
        if (filter_var($v, FILTER_VALIDATE_EMAIL)===false) {
            throw new \Exception('Invalid email: '.$v);
        } 
    }

This function will be called each time an email is set, and if the address is not
valid it throws an exception. 

You can even modify the value, if you add an "&" before the $v:


    protected function _slugValidator(&$v) 
    {
        $v=trim(strtolower(mb_ereg_replace("[^a-zA-Z0-9]",'_',$v)),"_");
    }

But this can only be done on datatype properties (not on node properties).

The value you receive in the validator is already normalized depending on the
property type: strings will always be strings, integers will be integers and so
for floating point numbers, but DateTimes are already transformed into strings, 
and nodes are passed as idenentifiers.

\subsection triggers Triggers

Another set of special functions you can implement are \em triggers. A trigger
is a function whose name begins with "_on" followed by the property name with
first letter in upper case and by one of the following:

- "Interted"
- "Updated"
- "Deleted"

For example:

    protected function _onEmailInserted($v) {
        # insert into mailing list 
    }
    
    protected function _onEmailUpdated($v,$old) {
        # update entry in the mailing list
    }
    
    protected function _onEmailDeleted($v) {
        # remove from the mailing list (nobody does this ever...)
    }

As for validators, the data you receive are normalized: strings, integers and 
floating point numbers are what they should be, DateTimes are converted into strings
and nodes are passed as identifiers.

\subsection datanodes Data nodes

In the definition files you can restrict the access to some properties and set them,
for example, to be read only. This is great for encapsulation, but how will you 
ever set them? If you do:

    $this->readOnlyProp="something";
    
One of two things can happen: if the property is not frozen, Graphene will relax the 
access restriction, if it is frozen an error will be thrown, which is what you 
want if someone else tryes to write to the property... That's why datanodes are there.
Within a node class you can get your \em twin data node by calling:

    $data=$this->data();
    
The data node you get has the same identifier and type than your node, but without 
any access restriction on its properties, and of course without all the fancy 
functions, triggers and validators you have added to your class.
So if you have to write to a read only property, you do:

    $this->data()->readOnlyProp="something";
    
And this will not raise any error, nor will Graphene try to change the access 
restrictions on the property.

@sa \ref def-files
@sa DataNode

*/
class Node extends NodeBase {
    
    private $_data;
    
    public final function __construct($node,$type) {
        $this->_data=$node;
        parent::__construct($type);
        $this->_init();
    }
    
    /**
    @brief Initialization hook.
    
    Initialization hook.
    
    This method will be invoked when the class is instantiated and replaces in 
    some sense the constructor. You can overload it if you have to do some 
    initialization in your custom node class.
    
    Notice that a node can be instantiated and thrown away several times during 
    the execution, so don't do any time consuming operation here. If you want to 
    cache stuff, I'd suggest to do it on a custom type class, which is instantiated
    only once per Connection. 
    
    @sa \ref inheritance
    
    */
    protected function _init() {}
    
    public final function id() {
        return $this->_data->id();
    }
    
    protected final function data() {
        return $this->_data;                                                   
    }
        

    /** 
    @brief Node intitialization hook
    
    Initialization hook to be overloaded by custom node classes. 
    This method will be invoked at node creation (not at PHP object creation, 
    that's the _init() function!), and before the initialization arguments (if any) 
    are written down.
    
    When you extend a class different than graphene\Node, it is possible that
    that class does something already in the _initNode() method, and if you overload 
    it on your turn, you'll probably want to call the parent method as well:

        protected function _initNode() {
            parent::_initNode();
            # do my own initialization
        }
        
    @sa \ref node-inheritance
    
    */
    protected function _initNode() {}
    
    public function properties() {
        return $this->_data->properties();
    }
    
    /**
    @brief Gives back a type by name relatively to the node's namespace.
    
    Gives back a type by name relatively to the node's namespace.
    
    @param $n
        The type name
    
    @param $phpns
        The (optional) PHP namespace of the caller.
        
    The $phpns argument is useful to ensure that you get the right namespace
    in the case your class has been extended by another one in a different 
    namespace. Suppose for example your class is 'a_SomeType' and someone
    created a class 'b_SomeOtherType' extending yours. If the caller invokes
    one of your methods through an instance of b_SomeOtherType, $this will be
    boud to that type, and if you call:
    
        $this->getType('YetAnother');
        
    you will get back b_YetAnother instead of a_YetAnother, which is probably
    what you were looking for. So if you want your class to be extended from
    others outside your namespace, the safe way to go is calling:
    
        $this->getType('YetAnother',__NAMESPACE__);
        
    which will always give you back a_YetAnother. .
    
    @sa \ref node-inheritance
    @sa \ref type-names
    
    
    */
    public final function getType($n,$phpns=null) {
        return $this->db()->getType(Syntax::typeName($n,$this->_data->ns(),$phpns));
    }
    
    /*
    public final function getAs($n) {
        return $this->db()->getType($n)->getNode($this->id());
    }
    */
    
    /**
    @brief Node deletion hook
    
    Deletion hook to be overloaded by custom node classes. 
    This method will be invoked when the node is about to be deleted and
    allows you to perform some cleanup operation.
    
    When you extend a class different than graphene\\Node, it is possible that
    that class does something already in the _cleanupNode() method, and if you overload 
    it on your turn, you'll probably want to call the parent method as well:
    
        protected function _cleanupNode() {
            parent::_cleanupNode();
            # do my own cleanup
        }
    
    @sa \ref node-inheritance
    
    */
    protected function _cleanupNode() {}
    
    public final function delete() {
        $db=$this->db();
        if( $db->_isDeleting($this->id()) ) return;
        $db->_setDeleting($this->id());
        try {
            foreach( $this->_data->_graphene_topType as $type ) {
                $node=$db->getType($type)->removeNode($this->_data->id());
            }
            $this->_data->delete();
        } catch( \Exception $e ) {
            $db->_unsetDeleting($this->id());
            throw $e;
        }
        $db->_unsetDeleting($this->id());
    }
    
    public final function _callCleanup($rc) {
        $m=$rc->getMethod('_cleanupNode');
        if( $m->getDeclaringClass()->getName()==$rc->getName() ) {
            $m->setAccessible(true);
            $m->invoke($this);
        }
    }

    public final function _callInit($rc) {
        $m=$rc->getMethod('_initNode');
        if( $m->getDeclaringClass()->getName()==$rc->getName() ) {
            $m->setAccessible(true);
            $m->invoke($this);
        }
    }
    
    public final function db() {
        return $this->_data->db();
    }    
    
    protected function _mask($mask) { return $mask; }

}

/**
@brief A data node.

A data node. 

Data nodes are pretty much the same than usual nodes, except that they do not check any
access restriction on their properties. When you create a custom node class, you get your
\em twin data node by calling:

    $this->data();
    
Which allows you to bypass the access restrictions you have imposed to the public.

@sa \ref datanodes

*/
final class DataNode extends NodeBase {
    
    private $id;
    private $db;
                                             
    final function __construct( Connection $db, $id, $type ) {
        if( !$type ) throw new \Exception('No type');
        $this->db=$db;
        $this->id=$id;
        parent::__construct( $type );
    }
    
    
    protected function _mask($mask) { return \graphene::ACCESS_FULL; }
    
    public function id() { return $this->id; }
    
    public function db() { return $this->db; }
    
    function properties($dir=null) {
        return $this->db->_storage()->node_predicates($this->id,$dir);
    }
    
    function delete() {
        $preds=$this->db->_storage()->node_predicates($this->id,null);
        foreach( $preds as $pred=>$c ) {
            if( $pred[0]=='@' ) {
                $dir=-1;
                $pred=substr($pred,1);
            } else {
                $dir=1;
            }
            $prop=new Prop($this,$pred,$dir);
            $prop->delete();
        }
    }
    
    
    function hasType($n) {
        return $this->_data->prop('_graphene_type')->hasValue($n);
    }

    
}



