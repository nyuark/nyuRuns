<?php

namespace nyu\Tools;

/**
 * Registry of the server values: $_SERVER read once, then unset so that
 * nothing reads it directly.
 */
class ServerReg extends Registry{
	// Convert $_SERVER super global into a Registry

	/**
	 * Builds the server registry from $_SERVER, optionally merged with
	 * extra values, then unsets the $_SERVER superglobal so it's no
	 * longer accessed directly elsewhere.
	 *
	 * @param Array $fieldValList Extra values to merge over $_SERVER.
	 * @param Bool  $lowerCaseKey Whether to store keys in lowercase instead of uppercase.
	 */
	function __construct( Array $fieldValList = Array(), Bool $lowerCaseKey = true ){
		if(!empty($fieldValList)){
			$fieldValList = array_merge($_SERVER, $fieldValList);
		}else{
			$fieldValList = $_SERVER;
		}
		unset($_SERVER);
		if(is_array($fieldValList) && !empty($fieldValList)){
			parent::__construct( $fieldValList, $lowerCaseKey );
		}
	}
}