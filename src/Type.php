<?php

/**
NOTE: the whole code here is still open to the possibility of any node to be added 
to any type and thus have multiple types, or at least it should... 
this feature is not yet made available since it hasn't been tested enough and as well 
because the use cases aren't yet very clear to me.
If we decide to drop this feature, the _remove and _get code should be reviewed for optimization. 

- Max Jacob 02 2015
*/

namespace graphene;



require_once 'Node.php';
require_once 'Syntax.php';

/**
@brief A node type.

\section overview Overview
Types have, in Graphene, a role similar to that of tables in a relational database. 

As it happens with table rows in relational databases, nodes of the same type 
are supposed to share all the same data structure. But while in a relational 
database each table has its own fields, in Graphene the same property can be
shared among several node types. Furthermore in Graphene it is possible to have
type inheritance, feature that is provided as well by some ORM for relational
databases.

The most common way to obtain a type is from a Connection object, either by
calling:

    $myType=$db->getType('MyType');
    
or using the shorcut notation:

    $myType=$db->MyType;


Basic things you can do with a Type are the following.

Creating a node:

    $node=$myType->newNode();

or, with data initialization:

    $node=$myType->newNode(array("param1"=>"value1","param2"=>"value2",...));

Getting back a node by its identifier:

    $node=$myType->getNode($id);

Getting a node by a given value on a given field:

    $myUser=$myUserType->getBy("email","john@example.com");
    
And querying:

    $usersWoFrstNm=$myUserType->select("not firstName");
    
@sa \ref gql

At the time being each node belongs to one type (and implicitly to all its 
ancestor types, if it has been extended), but nothing prevents the database structure 
from having nodes that belong to several types, and if clear use cases for such
a feature should arise, maybe the API will support it one day.

\section type-names Type names

The names of node types in Graphene MUST begin with an uppercase letter, as
in 'Person', 'Bookshop' and so forth. 

Furthermore Graphene supports namespaces, and every type can live in a given 
namespace. While in PHP the namespace separator is '\\', as in "graphene\Type"
for example, in the Graphene syntax namespaces are separated by '_', as
in "graphene_Type". The namespaces MUST be in lower case. 

By default the properties of a gven type will be assigned to its namespace, so
if for example you have a type called "um_User" having a property "email", the
real (full) name of that property will be "um_email". 

This is crucial, since it allows to build bundles that use a set of types and 
properties which do not conflict with others by the fact that they live in a 
different namespace.

Usually type names will be interpreded (as in PHP) as relative to the namespace you
are in, and (as in PHP) if you want to reach a type outside your namespace, you
have to specify you are providing an absolute name by prepending a '_' (as in PHP you
have to prepend '\\'). For example if you are in type a_SomeType and want to reach 
type b_SomeType, you'll have to call it _b_SomeType, else it will be interpreted 
as a_b_SomeType.

\section data-structure Data structures

The data structure of a node type is defined in a corresponding 
.def file, located in the \em definitions directory in the Connection's 
classpath(s). Within this directory, every namespace is a subdirectory 
(recursively). So for example the definition file of a type whose full name is
'ab_cd_MyType' will be:

    {classpath}/definitions/ab/cd/MyType.def

For a full description of the definition files syntax, see \ref def-files.

\section inheritance Custom type classes

By default the class used by Graphene is the Type class itself. But you can provide
for each of your types a custom class extending graphene\\Type.

The class must be located in the \em classes directory within the Connection's 
classpath(s). The name of the class must be the name of the type followed by
'Type', and the file name must be called the same way.

If for example I want to customize the ab_cd_Person type, I have to create the file:

    {classpath}/classes/ab/cd/PersonType.php
    
And within this file there must be the corresponding PHP class:

    <?php
    
    namespace ab\cd;
    
    class PersonType extends \graphene\Type {
        
    
    }

Notice that the PHP namespaces must follow the Graphene namespaces, which makes
sense since you don't want your PHP classes to have name conflicts either.

From now on Graphene will use your class instead of the Type class, and any public
method you implement will be available to the caller.

@sa \ref node-inheritance


*/

class Type 
{

    private $_db;
    private $_def;
    private $_queries;
    
    /**
    @brief Gives back the type's namespace (in graphene notation).
    
    Gives back the type's namespace (in graphene notation).
    
    @retval string
        The namespace in Graphene notation.
        
    @sa \ref type-names
    */
    public function ns() { return $this->_def->ns(); }
    
    /**
    @brief Gives back to type's name.
    
    Gives back to type's name.
    
    @retval string
        The name in Graphene notation.
    
    @sa \ref type-names
    */
    public function name() { return $this->_def->name(); }
    
    /**
    @brief Gives back to type's database Connection.
    
    Gives back to type's database Connection.
    
    @retval Connection
        The connection this type is bound to.
        
    */
    public function db() { return $this->_db; }

    /**
    @brief Gives back a type by name relatively to this type's namespace.
    
    Gives back a type by name relatively to this type's namespace.
    
    @param $n
        The type name.
        
    @param $phpns
        The actual PHP namespace you are calling from.
        
    This is a handy function when you write your own type class (see \ref
    inheritance). It gives you back a type relatively to your namespace. 
    
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
    
    @sa \ref inheritance
    @sa \ref type-names
    
    */
    public function getType($n,$phpns=null) 
    {
        return $this->_db->getType(Syntax::typeName($n,$this->ns(),$phpns));
    }
    
    public function _findDef($n,$create=true) 
    {
        $def=$this->_def->findDef($n);
        if (!$def && !$this->_def->isFrozen() && $create) $def=$this->_def->emptyDef($n);
        return $def;
    }

    
    public function _getRequired() 
    {
        return $this->_def->getRequired();
    }
    
    
    final function __construct(Connection $db,$def) 
    {
        $this->_db=$db;
        $this->_def=$def;
        $this->_queries=array();
        $this->_init();
    }
    
    /**
    @brief Initialization hook.
    
    Initialization hook.
    
    This method will be invoked when the class is instantiated and replaces in 
    some sense the constructor. You can overload it if you have to do some 
    initialization in your custom type class.
    
    Notice that a node type will be instantiated only once for each connection,
    and only if needed.

    @sa \ref inheritance
    */
    protected function _init() {}
    

    /**
    @brief Creates a new node of this type.
    
    Creates a new node of this type.
    
    @param $args
        An array containing the ihe initialization arguments
        
    @retval Node
        The new node.
        
    If no argument is passed, an empty node is created. Else you can pass
    an associative array having as keys the property names and as values
    the values to set. If a property is required, it has to be passed at creation
    or an error is thrown (unless the Connection is not frozen, in which case
    it will simply relax the constraint, see \ref freeze-unfreeze).
    
    In unfrozen mode, if you \em always pass a given property among the initialization
    arguments, Graphene will set the \em required flag.
    
    
        
    */
    public function newNode($args=null) 
    {
        return $this->_create($args);
    }
    
    protected function _create($args=null) 
    {
        if (is_null($args)) $args=array();
        if (!is_array($args)) throw new \Exception( 'Arguments must be an array.' );
        $id=$this->_db->_storage()->newId();
        $c=$this->_def->nodeClass();
        $node=new DataNode($this->_db,$id,$this,false,null);
        $r=new $c($node,$this);
        $node->_graphene_topType=$this->_def->name();
        $tdef=$this->_def;
        $prop=$node->_graphene_type;
        $anc=array();
        // append all ancestor types and at the same time make a list 
        // of the ancestors starting from the bottom
        while( $tdef ) {
            if ($prop->add($tdef->name())) array_unshift($anc,$tdef);
            $tdef=$tdef->supertype();
        }
        // set the properties as candidates for required if they do not exist
        if (!$this->_def->isFrozen()) {
            $this->_checkRequiredCandidates($args,$node);
        }
        // call the int functions 
        $lastInit=null;
        foreach ($anc as $tdef) {
            if ($lastInit!=$tdef->nodeClass()) {
                $rc=new \ReflectionClass($tdef->nodeClass());
                $r->_callInit($rc);
                $lastInit=$tdef->nodeClass();
            }
        }
        // write the arguments
        $r->update($args);
        // check all required fields are set
        foreach ($this->_def->getRequired() as $n=>$pn) {
            $preds=$node->properties();
            if (!array_key_exists($n,$preds)) {
                if (!$this->_def->isFrozen()) {
                    $def=$this->_def->findDef($pn);
                    if ($def->required) {
                        $def->required=0;
                        $this->_def->save();
                    }
                } else {
                    throw new \Exception( 'Property '.$pn.' is required for type '.$this->name().'.' );
                }
            }
        }
        return $r;
    }
    
    /**
    @brief Gives back the node having a given value on a given property.
    
    Gives back the node having a given value on a given property.
    
    @param $field
        The name of the property.
        
    @param $value
        The value of the property.
        
    @retval Node
        The first node of this type having that value on that property, or 
        \em null if not found.
        
    This one is useful if you have unique properties, such as the email of a 
    person or the tag name of a tag and so forth. It will return the first matching
    node of this type. 
    
    Furthermore, if the connection, the type and the property are all unfrozen, 
    it will check if if it is really unique and in this case set the \em unique 
    flag to the property definition.
    
    
    */
    public function getBy($field,$value) 
    {
        if (!$this->_def->isFrozen()) {
            $n=$field;
            $pos=strpos($n,':');
            if ($pos!==false) $n=substr($n,0,$pos);
            $def=$this->_findDef($n);
            if (!$def->frozen && !$def->unique) {
                $storage=$this->_db->_storage();
                $v=$value;
                Syntax::unpackDatum($v);
                $arr=Syntax::parsePred($field,$this->ns());
                if ($arr['dir']==1) {
                    $l=$storage->getTriples(null,$arr['pred'],$v,$def->type);
                    $subob='sub';
                } else {
                    $l=$storage->getTriples($v,$arr['pred'],null,$def->type);
                    $subob='ob';
                }
                $c=$l->count();
                if ($c<2) {
                    $def->unique=1;
                    $this->_def->save();
                }
                $l->rewind();
                if ($l->valid()) {
                    $tr=$l->current();
                    return $this->getNode($tr[$subob]);
                } else {
                    return null;  
                }
            }
        }
        return $this->select($field.'=? limit 1',$value)[0];
    }
    
    
    /**
    @brief Tells if the type is frozen.
    
    Tells if the type is frozen.
    
    @retval int
        1 if frozen, 0 if not
        
    A type is frozen if the Connection is frozen or if the definition file contains
    the '\\frozen' directive.
    
    @sa \ref def-files
    
    @sa \ref freeze-unfreeze
    
    */
    public function isFrozen() 
    {
        return $this->_def->isFrozen();
    }
    
    public function _saveDefinition() 
    {
        $this->_def->save();
    }
    
    public function containsNode($i) 
    {
        if ($i instanceof \graphene\NodeBase) $i=$i->id();
        $id=(int)$i;
        return $this->_db->_storage()->getTriples($id,'graphene_type',$this->name(),'string')->count()>0;
    }
    
    
    /**
    @brief Gives back a node by id.
    
    Gives back a node by id.
    
    @param $id
        The node identifier.
        
    @retval Node
        The requested node.
        
    If the node is not found or is not of this type, an error is thrown.
    
    */
    public function getNode($id) 
    {
        $node=$this->_get($id);
        return $node;
    }
    protected function _get($i) 
    {
        if ($i instanceof \graphene\NodeBase) $i=$i->id();
        $id=(int)$i;
        if ($id<=0) throw new \Exception( 'Invalid id: '.$i );
        $storage=$this->_db->_storage();
        if ($storage->getTriples($id,'graphene_type',$this->name(),'string')->count()==0) {
            throw new \Exception( 'Node '.$id.' is not of type '.$this->name() );
        }
        // find the top most class on this lineage the node is a direct instance of
        $topMost=$this->_def;
        $type=$this;
        foreach ($storage->getTriples($id,'graphene_topType',null,'string') as $tr) {
            $def=$this->_db->_getTypeDefinition($tr['ob']);
            if ($def->isAncestor($topMost->name())) {
                $topMost=$def;
                $type=null;
            }
        }
        if (!$type) {
            $type=$this->_db->getType($topMost->name());
        } 
        $node=new DataNode($this->_db,$id,$type,false,null);
        $c=$topMost->nodeClass();
        return new $c($node,$type);
    }
    
    /*
    Set to private in order to hide this feature.
    
    - Max Jacob 03 2015
    */
    private function addNode($i,$args=null) 
    {
        return $this->_add($i,$args);
    }
    
    private function _checkRequiredCandidates($args,$node) 
    {
        foreach ($args as $k=>$v) {
            if (Syntax::isEmpty($v)) continue;
            $pos=strpos($k,':');
            if ($pos!==false) $k=substr($k,0,$pos);
            $def=$this->_findDef($k,false);
            if (!$def) {
                $rs=$this->select('not '.$k.' and #x!='.$node->id().' limit 1');
                if (!$rs->count()) {
                    $def=$this->_findDef($k);
                    $def->required=1;
                    $this->_def->save();
                }
            }
        }
    }
    
    protected function _add($i,$args) 
    {
        if (is_null($args)) $args=array();
        if (!is_array($args)) throw new \Exception( 'Arguments must be an array.' );
        if ($i instanceof \graphene\NodeBase) $i=$i->id();
        $id=(int)$i;
        if ($id<=0) throw new \Exception( 'Invalid id: '.$i );
        $c=$this->_def->nodeClass();
        $node=new DataNode($this->_db,$id,$this,false,null);
        $r=new $c($node,$this);
        if (!$node->_graphene_topType->add($this->_def->name())) return; // is already a top type
        $tdef=$this->_def;
        $prop=$node->_graphene_type;
        $anc=array();
        // append all ancestor types and at the same time make a list 
        // of the ancestors starting from the bottom
        while( $tdef ) {
            if ($prop->add($tdef->name())) array_unshift($anc,$tdef);
            $tdef=$tdef->supertype();
        }
        // set the properties as candidates for required if they do not exist
        if (!$this->_def->isFrozen()) {
            $this->_checkRequiredCandidates($args,$node);
        }
        // call the init functions 
        $lastInit=null;
        foreach ($anc as $tdef) {
            if ($lastInit!=$tdef->nodeClass()) {
                $rc=new \ReflectionClass($tdef->nodeClass());
                $r->_callInit($rc);
                $lastInit=$tdef->nodeClass();
            }
        }
        // write the arguments
        $r->update($args);
        // check all required fields are set
        foreach ($this->_def->getRequired() as $n=>$pn) {
            $preds=$node->properties();
            if (!in_array($n,$preds)) {
                if (!$this->_def->isFrozen()) {
                    $def=$this->_def->findDef($pn);
                    if ($def->required) {
                        $def->required=0;
                        $this->_def->save();
                    }
                } else {
                    throw new \Exception( 'Property '.$pn.' is required for type '.$this->name().'.' );
                }
            }
        }
        return $r;
        
    }
    
    public function removeNode($i) 
    {
        $this->_remove($i);
    }
    
                                          
    protected function _remove($i) 
    {
        if ($i instanceof \graphene\NodeBase) {
            if (get_class($i)==$this->_def->nodeClass()) $node=$i;
            else $node=$this->getNode($i->id());
        } else {
            $node=$this->getNode($i);
        }
        if ($this->_db->_isDeleting($node->id())) {
            $topcall=false;
        } else {
            $topcall=true;
            $this->_db->_setDeleting($node->id());
        }
        try {
            $storage=$this->_db->_storage();        
            $topClasses=array();
            $found=null;
            // get all top types except myself and at the same time check i am a top type
            foreach ($storage->getTriples($node->id(),'graphene_topType',null,'string') as $tr) {
                $type=$tr['ob'];
                if ($type==$this->name()) {
                    $found=$tr['id'];
                    continue;
                }
                $topClasses[]=$this->_db->getType($type);
            }
            // if i'm not a top type, throw error
            if (!$found) throw new \Exception( 'Node '.$node->id().' is not a direct instance of '.$this->name().' and thus can not be removed from it.' );
            
            // look at which types should be removed, i.e. me and all my ancestors,
            // but taking care of not removing any other top type or one of its ancestors
            $tdef=$this->_def;
            $lastCleanup=null;
            while( $tdef ) {
                $otherwiseImplied=false;
                $type=$this->_db->getType($tdef->name());
                foreach ($topClasses as $ttype) {
                    if ($ttype->name()==$tdef->name() || $ttype->_def->isAncestor($tdef->name())) {
                        $otherwiseImplied=true;
                        break;
                    }
                }
                if (!$otherwiseImplied) {
                    // call cleanup
                    $nodeClass=$tdef->nodeClass();
                    if ($nodeClass!=$lastCleanup) {
                        $rc=new \ReflectionClass($nodeClass);
                        $node->_callCleanup($rc);
                        $lastCleanup=$nodeClass;
                    }
                    // remove associated properties if not shared by other top types
                    $propsToRemove=$tdef->getPropDefs();
                    $dataNode=new DataNode($this->_db,$node->id(),$type);
                    foreach ($propsToRemove as $def) {
                        $shared=false;
                        foreach ($topClasses as $ttype) {
                            if ($ttype->_findDef($def->absname,false)) {
                                $shared=true;
                                break;
                            }
                        }
                        if (!$shared) {
                            if ($def->deleteCascade && $def->type=='node') {
                                $prop=new Prop($node,$def->predName,$def->dir);
                                foreach ($prop as $d) {
                                    $d->delete();
                                }
                            }             
                            $dataNode->set($def->properName,null);
                        }
                    }
                    // remove the type from the type list
                    foreach ($storage->getTriples($node->id(),'graphene_type',$tdef->name(),'string') as $tr) {
                        $storage->remove($tr['id'],'graphene_type');
                    }
                }
                $tdef=$tdef->supertype();
            }        
            // remove me as top type
            $storage->remove($found,'graphene_topType');
        } catch( \Exception $e ) {
            if ($topcall) $this->_db->_unsetDeleting($node->id());
            throw $e;
        }
        if ($topcall) $this->_db->_unsetDeleting($node->id());
    }
    
    public function __toString() 
    {
        return 'Type '.$this->name();
    }

    
    /**
    @brief Performs a query on the nodes of this type.
    
    Performs a query on the nodes of this type.
    
    @param $query
        The query.
    @param $params
        Optional query params (values for placeholders).
        
    @sa \ref gql
    
    */
    function select($query=null,$params=null) 
    {
        if (array_key_exists($query,$this->_queries)) $q=$this->_queries[$query];
        else {
            $q=new Query($query,$this->_db,$this);
            $this->_queries[$query]=$q;
            $q->addConstraint('_graphene_type#type=\''.$this->name().'\'');
        }
        return $q->execute($params);
    }
    
    
}





