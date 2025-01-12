<?php

namespace nyu\Tools;

class ServerReg extends Registry{
	// Convert $_SERVER super global into a Registry

	/**
	 * Builds the server registry from $_SERVER, optionally merged with
	 * extra values, then unsets the $_SERVER superglobal so it's no
	 * longer accessed directly elsewhere.
	 *
	 * @param Array $valueArray Extra values to merge over $_SERVER.
	 * @param Bool  $lowCaseKey Whether to store keys in lowercase instead of uppercase.
	 */
	function __construct( Array $valueArray = Array(), Bool $lowCaseKey = true ){
		if(!empty($valueArray)){
			$valueArray = array_merge($_SERVER, $valueArray);
		}else{
			$valueArray = $_SERVER;
		}
		unset($_SERVER);
		if(is_array($valueArray) && !empty($valueArray)){
			parent::__construct( $valueArray, $lowCaseKey );
		}
	}
}