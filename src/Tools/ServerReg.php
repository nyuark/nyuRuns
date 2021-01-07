<?php

namespace nyu\Tools;

class ServerReg extends Registry{
	// Convert $_SERVER super global into a Registry

	function __construct( Array $valueArray = Array(), Bool $lowCaseKey = true ){
		if(!empty($valueArray)){
			$valueArray = array_merge($valueArray, $_SERVER);
		}else{
			$valueArray = $_SERVER;
		}
		// TODO: Cleanup some values and/or refrain use of global
		unset($_SERVER);
		if(is_array($valueArray) && !empty($valueArray)){
			parent::__construct( $valueArray, $lowCaseKey );
		}
	}
}