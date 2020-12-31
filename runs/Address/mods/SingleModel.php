<?php

namespace nyu\Runs\Address\mods;

use \nyu\Data\DBRecord;
use \nyu\Tools\Variable;

class Single extends DBRecord {
	
	public function __construct( ){
		parent::__construct( 'addresses');
	}
}