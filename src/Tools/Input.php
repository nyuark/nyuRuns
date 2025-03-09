<?php

/*
	This class cleans the input from the Request object to provide
	the values that are exposed to the apps: the fields a definition
	lists are kept, each given the format the definition names for it
	(see Variable), and the others are left out.

	A definition is 'all' (every value, as sent), 'none', a text of
	name:Format(option) items separated by semicolons, or an array
	shaped like the values:
		{ "filters": { "search": "TextStr" }, "since": "ShrDate", "pass": "" }
	A field without a format is kept as sent.
*/

namespace nyu\Tools;

class Input{

	protected $saveName;
	protected $fldArray;
	protected $reqstObj;
	protected $inputReg;

	/**
	 * Builds a named input registry entry by pulling values from the
	 * given source off the shared Request, keeping the fields the
	 * definition lists and giving each its format.
	 *
	 * @param ?Request     $requestObj The shared, already-parsed HTTP request; null when the values are given to parseVals().
	 * @param String       $fromSource 'QUERY' (request vars); any other source yields no value.
	 * @param String|Array $fieldsDefn The definition of the fields: 'all', 'none', a text of name:Format(option) items, or an array shaped like the values.
	 * @param String       $regstrName Name under which the parsed values are stored.
	 *
	 * @throws \RuntimeException When the definition names a format that does not exist.
	 *
	 * @todo Should read an encoding parameter and format each value to that encoding.
	 */
    function __construct( ?Request $requestObj, String $fromSource, String|Array $fieldsDefn, String $regstrName ){
		$this->reqstObj = $requestObj;
		$this->saveName = $regstrName;
		$this->fldArray = $this->fieldsMap( $fieldsDefn );
		$allVals = ($fromSource=='QUERY' && $requestObj!==null) ? $requestObj->getAllVar() : Array();
		$this->inputReg[$this->saveName] = $this->parseVals( $allVals );
	}

	/**
	 * Keeps the values the definition lists, each given its format, and
	 * leaves the others out. The names of the first level are matched
	 * whatever their case, as the registry the values end in reads them.
	 *
	 * @param Array $valueArray Raw values to filter and format.
	 * @return Array The values kept; all of them, unchanged, when the definition is 'all'.
	 */
	public function parseVals( Array $valueArray ): Array {
		if( $this->fldArray===null ){
			return $valueArray;
		}
		return $this->mapValues( $valueArray, $this->fldArray, true );
	}

	/**
	 * Keeps the values listed at one level of the definition. A field
	 * defined with fields of its own is read level by level; a value that
	 * should hold fields and does not is emptied.
	 *
	 * @param Array $valueArray Values of the level.
	 * @param Array $fieldNodes Fields of the level, as fieldsMap() gives them.
	 * @param Bool  $ignoreCase Whether the names are matched whatever their case.
	 * @return Array The values kept, under the names they came with.
	 */
	protected function mapValues( Array $valueArray, Array $fieldNodes, Bool $ignoreCase=false ): Array {
		if( $ignoreCase ){
			$fieldNodes = array_change_key_case($fieldNodes, CASE_LOWER);
		}
		$valList = Array();
		foreach( $valueArray as $valKey => $valVal ){
			$nodeKey = $ignoreCase ? strtolower(strval($valKey)) : strval($valKey);
			if( !isset($fieldNodes[$nodeKey]) ){
				continue;
			}
			$fldNode = $fieldNodes[$nodeKey];
			if( $fldNode['kids']!==null ){
				$valList[$valKey] = is_array($valVal) ? $this->mapValues($valVal, $fldNode['kids']) : Array();
			}elseif( $fldNode['frmt']=='' ){
				$valList[$valKey] = $valVal;
			}else{
				$valList[$valKey] = $this->formatVal($valVal, $fldNode['frmt'], $fldNode['optn']);
			}
		}
		return $valList;
	}

	/**
	 * Gives a value the format named, through the Variable class. An
	 * array is formatted value by value. A value the format cannot read
	 * is emptied.
	 *
	 * @param mixed   $givenValue The value as sent.
	 * @param String  $formatName Name of the format (see Variable).
	 * @param ?String $formatOptn Option of the format, when it takes one.
	 * @return mixed The formatted value.
	 */
	protected function formatVal( Mixed $givenValue, String $formatName, ?String $formatOptn=null ): Mixed {
		if( is_array($givenValue) ){
			foreach( $givenValue as $itmKey => $itmVal ){
				$givenValue[$itmKey] = $this->formatVal($itmVal, $formatName, $formatOptn);
			}
			return $givenValue;
		}
		try{
			$varObjc = new Variable($givenValue);
			return $varObjc->readValue($formatName, $formatOptn ?? false);
		}catch( \TypeError | \ValueError $e ){
			return '';
		}
	}

	/**
	 * Reads a definition of fields into the fields of each level: for
	 * each name, its format ('frmt', empty when the value is kept as
	 * sent), the option of the format ('optn') and, when the field holds
	 * fields of its own, those fields ('kids').
	 *
	 * @param String|Array $fieldsDefn 'all', 'none', a text of name:Format(option) items separated by semicolons, or an array of name => format, name => fields, or plain names.
	 * @return ?Array The fields by name; an empty array for 'none'; null for 'all'.
	 *
	 * @throws \RuntimeException When a format is not written as Name or Name(option), or does not exist.
	 */
	protected function fieldsMap( String|Array $fieldsDefn ): ?Array {
		if( is_string($fieldsDefn) ){
			$defText = trim($fieldsDefn);
			if( $defText=='all' ){
				return null;
			}
			// name:Format(option) items; the option may hold a colon, not a semicolon
			$fieldsDefn = Array();
			foreach( ($defText=='none' ? Array() : explode(';', $defText)) as $lstItm ){
				$itmPrts = explode(':', $lstItm, 2);
				if( trim($itmPrts[0])!='' ){
					$fieldsDefn[trim($itmPrts[0])] = trim($itmPrts[1] ?? '');
				}
			}
		}
		$nodeMap = Array();
		foreach( $fieldsDefn as $fldKey => $fldDef ){
			if( is_array($fldDef) ){
				$nodeMap[strval($fldKey)] = Array('frmt'=>'', 'optn'=>null, 'kids'=>$this->fieldsMap($fldDef));
				continue;
			}
			if( is_int($fldKey) ){
				// A plain list of names : each is kept as sent
				$nodeMap[strval($fldDef)] = Array('frmt'=>'', 'optn'=>null, 'kids'=>null);
				continue;
			}
			$fldSpec = trim(strval($fldDef));
			$frmPrts = Array();
			if( $fldSpec!='' && !preg_match('/^(\w+)(?:\((.*)\))?$/s', $fldSpec, $frmPrts) ){
				throw new \RuntimeException('Input misconfigured: "'.$fldSpec.'" is not a format, for the field "'.$fldKey.'"');
			}
			$frmName = $frmPrts[1] ?? '';
			if( $frmName!='' && !method_exists(Variable::class, 'to'.$frmName) ){
				throw new \RuntimeException('Input misconfigured: unknown format "'.$frmName.'" for the field "'.$fldKey.'"');
			}
			$nodeMap[strval($fldKey)] = Array('frmt'=>$frmName, 'optn'=>(($frmPrts[2] ?? '')!=='' ? $frmPrts[2] : null), 'kids'=>null);
		}
		return $nodeMap;
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
