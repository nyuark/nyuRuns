<?php

namespace nyu\Tools;

/**
 * Dictionary of values accessible through setters and getters. Values can
 * be unidimensional or multidimensional; keys and subkeys must be strings,
 * uppercased by default.
 */
class Registry{

	protected $isUpperKey;
	protected $valuesList = Array();

	// Builds the values
    /**
	 * Builds the registry from the given array, normalizing every key to
	 * uppercase (default) or lowercase.
	 *
	 * @param Array $fieldValList Initial key/value pairs to store in the registry.
	 * @param Bool  $lowerCaseKey Whether to store keys in lowercase instead of uppercase.
	 */
    function __construct( Array $fieldValList, Bool $lowerCaseKey = false ){
		if($lowerCaseKey){
			$this->isUpperKey = false;
		}else{
			$this->isUpperKey = true;
		}
		foreach( $fieldValList as $oneKey => $oneVal ){
			if($this->isUpperKey){
				$oneKey = strtoupper($oneKey);
			}else{
				$oneKey = strtolower($oneKey);
			}
			$this->saveValueByKey( $oneKey, $oneVal );
		}
	}

	/**
	 * Stores a value in the registry under the given key, normalizing the
	 * key's case to match the registry's configuration.
	 *
	 * @param String $entryKeyText Key under which to store the value.
	 * @param mixed  $regLineValue Value to store.
	 */
	public function saveValueByKey( String $entryKeyText, Mixed $regLineValue ): Void {
		if($this->isUpperKey){
			$entryKeyText = strtoupper($entryKeyText);
		}else{
			$entryKeyText = strtolower($entryKeyText);
		}
		$this->valuesList[$entryKeyText] = $regLineValue;
	}

	/**
	 * Reads a single top-level value from the registry.
	 *
	 * @param String $valueRegClef Key to look up.
	 * @return mixed The stored value, or null if the key doesn't exist.
	 */
	public function readValueByKey( String $valueRegClef ): Mixed {
		if($this->isUpperKey){
			$valueRegClef = strtoupper($valueRegClef);
		}else{
			$valueRegClef = strtolower($valueRegClef);
		}
		$lvl1Vals = null;
		if(isset($this->valuesList[$valueRegClef])){
			$lvl1Vals = $this->valuesList[$valueRegClef];
		}
		return $lvl1Vals;
	}

	/**
	 * Reads a nested value from a two-dimensional registry entry, trying
	 * the given key case first and falling back to uppercase.
	 *
	 * @param String $rowEntryName Key of the top-level (row) entry.
	 * @param String $entryKeyText Key within that row's array.
	 * @return mixed The stored value, or null if the row or key doesn't exist.
	 */
	public function getNestedValue( String $rowEntryName, String $entryKeyText ): Mixed {
		$lvl2Vals = null;
		if($this->isUpperKey){
			$rowEntryName = strtoupper($rowEntryName);
			$entryKeyText = strtoupper($entryKeyText);
		}else{
			$rowEntryName = strtolower($rowEntryName);
			$entryKeyText = strtolower($entryKeyText);
		}
		$lvl1Vals = $this->readValueByKey($rowEntryName);
		if($lvl1Vals!=null){
			if(isset($lvl1Vals[$entryKeyText])){
				$lvl2Vals = $lvl1Vals[$entryKeyText];
			}else{
				$entryKeyText = strtoupper($entryKeyText);
				if(isset($lvl1Vals[$entryKeyText])){
					$lvl2Vals = $lvl1Vals[$entryKeyText];
				}
			}
		}
		return $lvl2Vals;
	}

	/**
	 * Writes a value into a two-dimensional registry entry, creating the
	 * row if it doesn't already exist.
	 *
	 * @param String $rowEntryName Key of the top-level (row) entry.
	 * @param String $entryKeyText Key within that row's array to write.
	 * @param mixed  $regLineValue Value to store.
	 * @return mixed The value that was stored.
	 */
	public function setNestedValue( String $rowEntryName, String $entryKeyText, Mixed $regLineValue ): Mixed {
		if($this->isUpperKey){
			$rowEntryName = strtoupper($rowEntryName);
			$entryKeyText = strtoupper($entryKeyText);
		}else{
			$rowEntryName = strtolower($rowEntryName);
			$entryKeyText = strtolower($entryKeyText);
		}
		$lvl1Vals = $this->readValueByKey($rowEntryName);
		if(!is_array($lvl1Vals)){
			$lvl1Vals = Array();
		}
		$lvl1Vals[$entryKeyText] = $regLineValue;
		$this->saveValueByKey($rowEntryName, $lvl1Vals);
		return $regLineValue;
	}

	/**
	 * Reads a top-level registry value, falling back to a default when
	 * the value is missing, null or an empty string. 0, false and an
	 * empty array are values, and are returned as they are.
	 *
	 * @param String $entryKeyText Key to look up.
	 * @param mixed  $defaultValue Value to return when the key is unset, null or blank.
	 * @return mixed The stored value, or $defaultValue.
	 */
	public function getValueOrDflt( String $entryKeyText, Mixed $defaultValue=null ): Mixed {
		$newValue = $this->readValueByKey( $entryKeyText );
		if($newValue===null || $newValue===''){
			return $defaultValue;
		}else{
			return $newValue;
		}
	}

	/**
	 * Returns all registry entries, optionally filtered to only those keys
	 * starting with the given prefix.
	 *
	 * @param String $keysCriteria Prefix to filter keys by; returns all entries when empty.
	 * @return Array Matching key/value pairs.
	 */
	public function readLinesArray( String $keysCriteria='' ): Array {
		$lineList = Array();
		if($keysCriteria!=''){
			$filtSize = strlen($keysCriteria);
			foreach($this->valuesList as $oneKey => $oneVal){
				$checkKey = substr($oneKey, 0, $filtSize);
				if($checkKey==$keysCriteria){
					$lineList[$oneKey] = $oneVal;
				}
			}
		}else{
			foreach($this->valuesList as $oneKey => $oneVal){
				$lineList[$oneKey] = $oneVal;
			}
		}
		return $lineList;
	}

}
