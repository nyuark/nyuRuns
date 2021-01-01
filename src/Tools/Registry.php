<?php

// Dictionary of values accessible through setter and getter
// Value array can be unidimensional or multidimensional

namespace nyu\Tools;

class Registry{
	
	protected $valueReg;
	
	// Builds the values
    function __construct( Array $valueArray, Bool $lowCaseKey = false ){
		foreach( $valueArray as $arrKey => $arrVal ){
			if($lowCaseKey){
				$arrKey = strtolower($arrKey);
			}
			$this->saveValue( $arrKey, $arrVal );				
		}
	}
	
	public function saveValue( String $regLineKey, $regLineVal ){
		$this->valueReg[$regLineKey] = $regLineVal;
	}
	
	public function getRowVal( String $regRowName, String $regLineKey ){
		$l2Value = null;
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
	
	public function readValue( String $regLineKey ){
		$l1Value = null;
		if(isset($this->valueReg[$regLineKey])){
			$l1Value = $this->valueReg[$regLineKey];
		}else{
			$regLineKey = strtoupper($regLineKey);
			if(isset($this->valueReg[$regLineKey])){
				$l1Value = $this->valueReg[$regLineKey];
			}
		}
		return $l1Value;
	}
	
	public function getDefVal( String $regLineKey, $defaultVal=null ){
		$nuValue = $this->readValue( $regLineKey );
		if($nuValue==null){
			return $defaultVal;
		}else{
			return $nuValue;
		}
	}
	
	public function readLines( String $keysFilter='' ){
		$lineArr = Array();
		if($keysFilter!=''){
			$fltrLen = strlen($keysFilter);
			foreach($this->valueReg as $arrKey => $arrVal){
				$chckKey = substr($arrKey,0 ,$fltrLen);
				if($chckKey==$keysFilter){
					$lineArr[$arrKey] = $arrVal;
				}
			}
		}else{
			$lineArr = $this->valueReg;
		}
		return $lineArr;
	}
	
}