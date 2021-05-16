<?php

// Dictionary of values accessible through setters and getters
// Values in array can be unidimensional or multidimensional
// Keys and Subkeys must be Strings, uppercased by default

namespace nyu\Tools;

class Registry{

	protected $fldUCase;
	protected $valueReg;

	// Builds the values
    /**
	 * Builds the registry from the given array, normalizing every key to
	 * uppercase (default) or lowercase.
	 *
	 * @param Array $valueArray Initial key/value pairs to store in the registry.
	 * @param Bool  $lowCaseKey Whether to store keys in lowercase instead of uppercase.
	 */
    function __construct( Array $valueArray, Bool $lowCaseKey = false ){
		if($lowCaseKey){
			$this->fldUCase = false;
		}else{
			$this->fldUCase = true;
		}
		foreach( $valueArray as $arrKey => $arrVal ){
			if($this->fldUCase){
				$arrKey = strtoupper($arrKey);
			}else{
				$arrKey = strtolower($arrKey);
			}
			$this->saveValue( $arrKey, $arrVal );
		}
	}

	/**
	 * Stores a value in the registry under the given key, normalizing the
	 * key's case to match the registry's configuration.
	 *
	 * @param String $regLineKey Key under which to store the value.
	 * @param mixed  $regLineVal Value to store.
	 */
	public function saveValue( String $regLineKey, Mixed $regLineVal ): Void {
		if($this->fldUCase){
			$regLineKey = strtoupper($regLineKey);
		}else{
			$regLineKey = strtolower($regLineKey);
		}
		$this->valueReg[$regLineKey] = $regLineVal;
	}

	/**
	 * Reads a single top-level value from the registry.
	 *
	 * @param String $registrKey Key to look up.
	 * @return mixed The stored value, or null if the key doesn't exist.
	 */
	public function readValue( String $registrKey ): Mixed {
		if($this->fldUCase){
			$registrKey = strtoupper($registrKey);
		}else{
			$registrKey = strtolower($registrKey);
		}
		$l1Value = null;
		if(isset($this->valueReg[$registrKey])){
			$l1Value = $this->valueReg[$registrKey];
		}
		return $l1Value;
	}

	/**
	 * Reads a nested value from a two-dimensional registry entry, trying
	 * the given key case first and falling back to uppercase.
	 *
	 * @param String $regRowName Key of the top-level (row) entry.
	 * @param String $regLineKey Key within that row's array.
	 * @return mixed The stored value, or null if the row or key doesn't exist.
	 */
	public function getRowVal( String $regRowName, String $regLineKey ): Mixed {
		$l2Value = null;
		if($this->fldUCase){
			$regRowName = strtoupper($regRowName);
			$regLineKey = strtoupper($regLineKey);
		}else{
			$regRowName = strtolower($regRowName);
			$regLineKey = strtolower($regLineKey);
		}
		$l1Value = $this->readValue($regRowName);
		if($l1Value!=null){
			if(isset($l1Value[$regLineKey])){
				$l2Value = $l1Value[$regLineKey];
			}else{
				$regLineKey = strtoupper($regLineKey);
				if(isset($l1Value[$regLineKey])){
					$l2Value = $l1Value[$regLineKey];
				}
			}
		}
		return $l2Value;
	}

	/**
	 * Writes a value into a two-dimensional registry entry, creating the
	 * row if it doesn't already exist.
	 *
	 * @param String $regRowName Key of the top-level (row) entry.
	 * @param String $regLineKey Key within that row's array to write.
	 * @param mixed  $regLineVal Value to store.
	 * @return mixed The value that was stored.
	 */
	public function setRowVal( String $regRowName, String $regLineKey, Mixed $regLineVal ): Mixed {
		if($this->fldUCase){
			$regRowName = strtoupper($regRowName);
			$regLineKey = strtoupper($regLineKey);
		}else{
			$regRowName = strtolower($regRowName);
			$regLineKey = strtolower($regLineKey);
		}
		$l1Value = $this->readValue($regRowName);
		if(!is_array($l1Value)){
			$l1Value = Array();
		}
		$l1Value[$regLineKey] = $regLineVal;
		$this->saveValue($regRowName, $l1Value);
		return $regLineVal;
	}

	/**
	 * Reads a top-level registry value, falling back to a default when
	 * the value is missing or null.
	 *
	 * @param String $regLineKey Key to look up.
	 * @param mixed  $defaultVal Value to return when the key is unset or null.
	 * @return mixed The stored value, or $defaultVal.
	 */
	public function getDefVal( String $regLineKey, Mixed $defaultVal=null ): Mixed {
		$nuValue = $this->readValue( $regLineKey );
		if($nuValue==null){
			return $defaultVal;
		}else{
			return $nuValue;
		}
	}

	/**
	 * Returns all registry entries, optionally filtered to only those keys
	 * starting with the given prefix.
	 *
	 * @param String $keysFilter Prefix to filter keys by; returns all entries when empty.
	 * @return Array Matching key/value pairs.
	 */
	public function readLines( String $keysFilter='' ): Array {
		$lineArr = Array();
		if($keysFilter!=''){
			$fltrLen = strlen($keysFilter);
			foreach($this->valueReg as $arrKey => $arrVal){
				$checKey = substr($arrKey, 0, $fltrLen);
				if($checKey==$keysFilter){
					$lineArr[$arrKey] = $arrVal;
				}
			}
		}else{
			foreach($this->valueReg as $arrKey => $arrVal){
				$lineArr[$arrKey] = $arrVal;
			}
		}
		return $lineArr;
	}

}
