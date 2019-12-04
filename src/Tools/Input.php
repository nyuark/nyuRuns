<?php

namespace nyu\Tools;

class Input{
	
	protected $saveName;
	protected $fldArray;
	protected $inputReg;
	
	// Shoud get the endode parameter and format each value to that encoding
    function __construct(String $fromSource, String $optionsStr, String $fldListStr, String $registerName){
		$this->saveName = $registerName;
		
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
	
	protected function getServer( ){
		return $_SERVER;
	}
	
	protected function readFData( String $filePath ){
		
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
		// Stack values of the current register
		// by merging it with an existing array
		return $this->inputReg;
	}
}