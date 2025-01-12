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
	protected $fldsList;
	protected $reqstObj;
	protected $inputReg;

	/**
	 * Builds a named input registry entry by pulling values from the
	 * given source off the shared Request and filtering them down to
	 * the configured field list.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @param String  $fromSource 'QUERY' (request vars); any other source yields no value.
	 * @param String  $fldListStr Semicolon-separated field-list definition, or 'all'/'none'.
	 * @param String  $regstrName Name under which the parsed values are stored.
	 *
	 * @todo Should read an encoding parameter and format each value to that encoding.
	 */
    function __construct( Request $requestObj, String $fromSource, String $fldListStr, String $regstrName ){
		$this->reqstObj = $requestObj;
		$this->saveName = $regstrName;
		$this->fldsList = $fldListStr;
		$this->fldArray = $this->fLisToArr( $fldListStr );
		if( $fromSource=='QUERY' ){
			$allVals = $this->reqstObj->getAllVar();
		}else{
			$allVals = Array();
		}
		$this->inputReg[$this->saveName] = $this->parseVals( $allVals );
	}
	
	/**
	 * Filters the given values down to the configured field list, then
	 * (eventually) applies per-field formatting via the Variable class.
	 *
	 * @param Array $valueArray Raw values to filter/format.
	 * @return Array The filtered values; the full array unchanged when the field list is 'all'; empty when it's 'none'.
	 *
	 * @todo Phase 2 (calling the Variable formatting class per field) is not yet implemented.
	 */
	protected function parseVals( Array $valueArray ): Array {
		// Phase 1, filter vars in valueArr by coma separated list of keys
		// Phase 2, apply filter by calling the Variable formatting class
		if( $this->fldsList=='all' ){
			return $valueArray;
		}
		if( $this->fldsList=='none' ){
			return Array();
		}
		$fldList = $this->fldArray;
		$valList = Array();
		foreach( $valueArray as $valKey=>$valVal ){
			if( in_array($valKey, array_keys( $fldList ) ) ){
				$fldOpts = $fldList[$valKey];
				$valList[$valKey] = $valVal;
			}
		}
		return $valList;
	}
	
	/**
	 * Parses a field-list definition string into a structured array of
	 * field names mapped to their format and format options.
	 *
	 * Expected syntax per field: `valueName:valueFormat(opt1,opt2,opt3)`,
	 * with fields separated by semicolons.
	 *
	 * @param String $fldListStr Semicolon-separated field list, 'all' for no filtering, or 'none' to exclude everything.
	 * @return Array Map of field name => [format => options].
	 */
	protected function fLisToArr( String $fldListStr='all' ): Array {
		$fltrArr = Array();
		if( $fldListStr != 'all' && $fldListStr != 'none' ){
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
	
    /**
	 * Returns the parsed values currently stored under this input's
	 * registry name, for merging into the wider input registry.
	 *
	 * @return Array The stored values for this input's registry name.
	 */
    public function regiStack( ): Array {
		// Stack values of the current Registry
		// by merging it with an existing array
		return $this->inputReg[$this->saveName];
	}
}