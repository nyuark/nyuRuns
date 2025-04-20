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

	A name ending with '!' is a required field ("user!", "mail!:EmlAddr",
	"filters!": { "search!": "TextStr" }): the ones that are missing or
	empty, once formatted, are listed by getMissed().
*/

namespace nyu\Tools;

class Input{

	protected $saveName;
	protected $fldArray;
	protected $reqstObj;
	protected $inputReg;
	protected $missList = Array();

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
	 * The required fields found missing or empty are kept for getMissed().
	 *
	 * @param Array $valueArray Raw values to filter and format.
	 * @return Array The values kept; all of them, unchanged, when the definition is 'all'.
	 */
	public function parseVals( Array $valueArray ): Array {
		$this->missList = Array();
		if( $this->fldArray===null ){
			return $valueArray;
		}
		$keptVls = $this->mapValues( $valueArray, $this->fldArray, true );
		$this->missList = $this->findEmpty( $keptVls, $valueArray, $this->fldArray, '', true );
		return $keptVls;
	}

	/**
	 * Returns the required fields the last parseVals() found missing or
	 * empty: not sent, sent empty, or emptied by their format.
	 *
	 * @return Array The names as the definition writes them, in its order, a field held by another written 'filters.search'.
	 */
	public function getMissed(): Array {
		return $this->missList;
	}

	/**
	 * Lists the required fields of one level that are missing or empty. A
	 * required field holding fields is listed alone when nothing is kept
	 * under it; otherwise its own fields are checked.
	 *
	 * @param Array  $keptValues Values of the level, once filtered and formatted.
	 * @param Array  $sentValues Values of the level, as sent.
	 * @param Array  $fieldNodes Fields of the level, as fieldsMap() gives them.
	 * @param String $prefixText Names of the fields holding this level, each followed by a dot.
	 * @param Bool   $ignoreCase Whether the names are matched whatever their case.
	 * @return Array The names of the fields, as the definition writes them.
	 */
	protected function findEmpty( Array $keptValues, Array $sentValues, Array $fieldNodes, String $prefixText='', Bool $ignoreCase=false ): Array {
		if( $ignoreCase ){
			$keptValues = array_change_key_case($keptValues, CASE_LOWER);
			$sentValues = array_change_key_case($sentValues, CASE_LOWER);
		}
		$missLst = Array();
		foreach( $fieldNodes as $fldKey => $fldNde ){
			$nameKey = $ignoreCase ? strtolower(strval($fldKey)) : $fldKey;
			$keptVal = $keptValues[$nameKey] ?? null;
			$sentVal = $sentValues[$nameKey] ?? null;
			// A value sent empty is empty, whatever its format makes of it (Integer gives 0)
			if( $fldNde['reqd'] && ($this->isEmptied($sentVal) || $this->isEmptied($keptVal)) ){
				$missLst[] = $prefixText.$fldKey;
				continue;
			}
			if( $fldNde['kids']!==null ){
				$missLst = array_merge( $missLst, $this->findEmpty(is_array($keptVal) ? $keptVal : Array(), is_array($sentVal) ? $sentVal : Array(), $fldNde['kids'], $prefixText.$fldKey.'.') );
			}
		}
		return $missLst;
	}

	/**
	 * Tells whether a value is empty: null, an empty text, or an array
	 * holding nothing else. 0, '0', false and a text of spaces are values.
	 *
	 * @param mixed $givenValue The value to check.
	 * @return Bool True when the value is empty.
	 */
	protected function isEmptied( Mixed $givenValue ): Bool {
		if( is_array($givenValue) ){
			foreach( $givenValue as $itmVal ){
				if( !$this->isEmptied($itmVal) ){
					return false;
				}
			}
			return true;
		}
		return $givenValue===null || $givenValue==='';
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
	 * sent), the option of the format ('optn'), when the field holds
	 * fields of its own, those fields ('kids'), and whether the field is
	 * required ('reqd': its name ends with '!', which is not kept in it).
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
			// In a plain list of names, the name is the value
			$isPlain = is_int($fldKey) && !is_array($fldDef);
			$fldName = strval($isPlain ? $fldDef : $fldKey);
			// A name ending with '!' is a required field
			$isReqrd = (substr($fldName, -1)=='!');
			if( $isReqrd ){
				$fldName = rtrim(substr($fldName, 0, -1));
			}
			if( is_array($fldDef) ){
				$nodeMap[$fldName] = Array('frmt'=>'', 'optn'=>null, 'kids'=>$this->fieldsMap($fldDef), 'reqd'=>$isReqrd);
				continue;
			}
			if( $isPlain ){
				// A plain list of names : each is kept as sent
				$nodeMap[$fldName] = Array('frmt'=>'', 'optn'=>null, 'kids'=>null, 'reqd'=>$isReqrd);
				continue;
			}
			$fldSpec = trim(strval($fldDef));
			$frmPrts = Array();
			if( $fldSpec!='' && !preg_match('/^(\w+)(?:\((.*)\))?$/s', $fldSpec, $frmPrts) ){
				throw new \RuntimeException('Input misconfigured: "'.$fldSpec.'" is not a format, for the field "'.$fldName.'"');
			}
			$frmName = $frmPrts[1] ?? '';
			if( $frmName!='' && !method_exists(Variable::class, 'to'.$frmName) ){
				throw new \RuntimeException('Input misconfigured: unknown format "'.$frmName.'" for the field "'.$fldName.'"');
			}
			$nodeMap[$fldName] = Array('frmt'=>$frmName, 'optn'=>(($frmPrts[2] ?? '')!=='' ? $frmPrts[2] : null), 'kids'=>null, 'reqd'=>$isReqrd);
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
