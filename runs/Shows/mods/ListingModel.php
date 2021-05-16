<?php

namespace nyu\Runs\Shows\mods;

use \nyu\Data\DBList;

class Listing extends DBList {
	
	/**
	 * Binds the listing model to the 'shows' table.
	 */
	public function __construct(){
		parent::__construct( 'shows' );
	}
	
}