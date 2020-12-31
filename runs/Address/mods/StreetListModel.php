<?php

namespace nyu\Runs\Address\mods;

use \nyu\Data\DBList;

class StreetList extends DBList {
	
	public function __construct(){
		parent::__construct( 'streets' );
	}
	
}