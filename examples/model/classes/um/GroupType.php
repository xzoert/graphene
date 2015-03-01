<?php

namespace um;

class GroupType extends \graphene\Type {
	
	function getByPrivilege($privilege) {
		return $this->select('#x.ancestors.privileges=?',$privilege);
	}

}
