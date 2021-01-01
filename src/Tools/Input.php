<?php

/*
	This class should sanitize the input from the Request object
	to provide clean values that are exposed to the apps. Ideally
	all data stored in the exposed object should be encapsulated
	in a Variable object, with a predefined "string" type
	
*/

namespace nyu\Tools;

class Input{
	
	protected $saveName;
	protected $fldArray;
	protected $reqstObj;
	
	// Shoud get the endode parameter and format each value to that encoding
    //function __construct(Request $requestObj, String $fromSource, String $fldListStr, String $RegistryName){
    function __construct(String $fromSource, Array $fldListStr, String $RegistryName){
		$this->saveName = $RegistryName;
		/*
		$this->fldArray = $this->fLisToArr( $fldListStr );
		if( $fromSource=='QUERY' ){
			if($optionsStr=='ALL'){
				$optionsStr = 'REQUEST';
			}
			$allVals = $this->readQuery( $optionsStr );
		}elseif( $fromSource=='SERVR' ){
			$allVals = $this->getServer( );
		}else{
			// Throw error
		}
		$outVals = $this->parseVals( $allVals );
		*/
		$outVals = Array();
		$this->inputReg[$this->saveName] = $outVals;
	}
	
	protected function readQuery( String $queryMethd ){
		$allowed = Array('REQUEST','POST','GET','COOKIE');
		if(in_array($queryMethd, $allowed)){		
			$reqVars = $_REQUEST;
			if( $queryMethd!='REQUEST' ){
				$varName = '_'.$queryMethd;
				$reqVars = ${$varName};
			}
			return $reqVars;
		}else{
			return Array();
		}
	}
	
	protected function parseVals( Array $valueArray ){
		// Phase 1, filter vars in valueArr by coma separated list of keys
		// Phase 2, apply filter by calling the Variable formatting class
		$fldList = $this->fldArray;
		$valList = Array();
		// If all values are wanted, fldList is empty
		if(empty($fldList)){
			return $valueArray;
		}
		foreach( $valueArray as $vKey=>$vVal ){
			if( in_array($vKey, array_keys( $fldList ) ) ){
				$fldOpts = $fldList[$vKey];
				
			}
		}
	}
	
	protected function fLisToArr( String $fldListStr='all' ){
		$fltrArr = Array();
		if( $fldListStr != 'all' ){
			// Strip spaces in initial variable
			$fldListStr = str_replace(' ','',$fldListStr);
			// Split into fields, delimited by semicolon
			$lstLine = explode(';',$fldListStr);
			foreach( $lstLine as $lstItm ){
				// Field STR : valueName:valueFormat(opt1,opt2,opt3)
				// Split fields and types, separated by colons
				if(strPos($lstItm, ':') !== false){
					$itmArr = explode(':',$lstItm);
					$varNme = $itmArr[0];
					$varOpt = $itmArr[1];
					if(strPos($varOpt, ':') !== false){
						$frmArr = explode('(',$varOpt);
						$format = $frmArr[0];
						$frmArr[1] = str_replace(')', '', $frmArr[1]);
						if(strPos($frmArr[1], ',') !== false){
							$frmOpt = explode(',', $frmArr[1]);
						}else{
							$frmOpt = Array($frmArr[1]);
						}
					}else{
						$format = $itmArr[1];
						$frmOpt = Array();
					}
					$fltrArr[$varNme] = Array($format => $frmOpt);
				}else{
					$fltrArr[$lstItm] = Array();
				}
			}
		}
		return $fltrArr;
	}
	
    public function regStack( ){
		// Stack values of the current Registry
		// by merging it with an existing array
		return $this->inputReg[$this->saveName];
	}
}