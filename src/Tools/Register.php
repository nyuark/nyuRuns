<?php

// Dictionary of values accessible through setter and getter
// Value array can be unidimensional or multidimensional

namespace nyu\Tools;

class Register{
	
	protected $valueReg;
	
	// Builds the values
    function __construct( Array $valueArray ){
		foreach( $valueArray as $vKey => $kVal ){
			$this->setRegVal( $vKey, $kVal );				
		}
	}
	
	public function setRegVal( String $regLineKey, $regLineVal ){
		$this->valueReg[$regLineKey] = $regLineVal;
	}
	public function getRowVal( String $regRowName, String $regLineKey ){
		$l2Value = null;
		$l1Value = $this->getRegVal($regRowName);
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
	public function getRegVal( String $regLineKey ){
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
	
}