<?php

namespace nyu\Runs\Address\mods;

use \nyu\Data\DBList;

class LocationList extends DBList {
	
	public function __construct( ){
		parent::__construct( 'locations' );
	}
	
}