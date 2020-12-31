<?php

namespace nyu\Runs\Shows\mods;

use \nyu\Data\DBList;

class Listing extends DBList {
	
	public function __construct(){
		parent::__construct( 'shows' );
	}
	
}