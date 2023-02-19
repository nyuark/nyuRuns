<?php

namespace nyu\Runs\Shows\mods;

use \nyu\Data\DBLst;

class Listing extends DBLst {
	
	/**
	 * Binds the listing model to the 'shows' table.
	 */
	public function __construct(){
		parent::__construct( 'shows' );
	}
	
}