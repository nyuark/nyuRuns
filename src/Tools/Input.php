<?php

/*
	This class cleans the input from the Request object to provide
	the values that are exposed to the apps: the fields a definition
	lists are kept, each given the format the definition names for it
	(see Variable), and the others are left out.

	A definition is 'all' (every value, as sent), 'none', a text of
	name:Format(option) items separated by semicolons, or an array
	shaped like the values:
		{ "filters": { "search": "OutText" }, "since": "ShrDate", "pass": "" }
	A field without a format is kept as sent.

	A name ending with '!' is a required field ("user!", "mail!:EmlAddr",
	"filters!": { "search!": "OutText" }): the ones that are missing or
	empty, once formatted, are listed by retrieveMissed().
*/

namespace nyu\Tools;

/**
 * The inputs of an action: the fields its definition lists are kept, each
 * given its format, and the others are left out. Tells which required
 * fields are missing.
 */
class Input{

	protected $storedName;
	protected $fieldArray;
	protected $rqstObject;
	protected $inputsList;
	protected $missingSet = Array();

	/**
	 * Builds a named input registry entry by pulling values from the
	 * given source off the shared Request, keeping the fields the
	 * definition lists and giving each its format.
	 *
	 * @param ?Request     $givenRequest The shared, already-parsed HTTP request; null when the values are given to parseValuesArr().
	 * @param String       $sourceOfData 'QUERY' (request vars); any other source yields no value.
	 * @param String|Array $fieldsConfig The definition of the fields: 'all', 'none', a text of name:Format(option) items, or an array shaped like the values.
	 * @param String       $registryName Name under which the parsed values are stored.
	 *
	 * @throws \RuntimeException When the definition names a format that does not exist.
	 *
	 * @todo Should read an encoding parameter and format each value to that encoding.
	 */
    function __construct( ?Request $givenRequest, String $sourceOfData, String|Array $fieldsConfig, String $registryName ){
		$this->rqstObject = $givenRequest;
		$this->storedName = $registryName;
		$this->fieldArray = $this->fieldsInputMap( $fieldsConfig );
		$allData = ($sourceOfData=='QUERY' && $givenRequest!==null) ? $givenRequest->getAllVariable() : Array();
		$this->inputsList[$this->storedName] = $this->parseValuesArr( $allData );
	}

	/**
	 * Keeps the values the definition lists, each given its format, and
	 * leaves the others out. The names of the first level are matched
	 * whatever their case, as the registry the values end in reads them.
	 * The required fields found missing or empty are kept for retrieveMissed().
	 *
	 * @param Array $fieldValList Raw values to filter and format.
	 * @return Array The values kept; all of them, unchanged, when the definition is 'all'.
	 */
	public function parseValuesArr( Array $fieldValList ): Array {
		$this->missingSet = Array();
		if( $this->fieldArray===null ){
			return $fieldValList;
		}
		$keptVls = $this->mapValuesArray( $fieldValList, $this->fieldArray, true );
		$this->missingSet = $this->findEmptyArray( $keptVls, $fieldValList, $this->fieldArray, '', true );
		return $keptVls;
	}

	/**
	 * Returns the required fields the last parseValuesArr() found missing or
	 * empty: not sent, sent empty, or emptied by their format.
	 *
	 * @return Array The names as the definition writes them, in its order, a field held by another written 'filters.search'.
	 */
	public function retrieveMissed(): Array {
		return $this->missingSet;
	}

	/**
	 * Lists the required fields of one level that are missing or empty. A
	 * required field holding fields is listed alone when nothing is kept
	 * under it; otherwise its own fields are checked.
	 *
	 * @param Array  $filteredVals Values of the level, once filtered and formatted.
	 * @param Array  $valuesAsSent Values of the level, as sent.
	 * @param Array  $fldNodesList Fields of the level, as fieldsInputMap() gives them.
	 * @param String $prefixString Names of the fields holding this level, each followed by a dot.
	 * @param Bool   $matchAnyCase Whether the names are matched whatever their case.
	 * @return Array The names of the fields, as the definition writes them.
	 */
	protected function findEmptyArray( Array $filteredVals, Array $valuesAsSent, Array $fldNodesList, String $prefixString='', Bool $matchAnyCase=false ): Array {
		if( $matchAnyCase ){
			$filteredVals = array_change_key_case($filteredVals, CASE_LOWER);
			$valuesAsSent = array_change_key_case($valuesAsSent, CASE_LOWER);
		}
		$missLst = Array();
		foreach( $fldNodesList as $fldKey => $fldNod ){
			$nameKey = $matchAnyCase ? strtolower(strval($fldKey)) : $fldKey;
			$keptVal = $filteredVals[$nameKey] ?? null;
			$sentVal = $valuesAsSent[$nameKey] ?? null;
			// A value sent empty is empty, whatever its format makes of it (Integer gives 0)
			if( $fldNod['reqd'] && ($this->hasEmptiedFlag($sentVal) || $this->hasEmptiedFlag($keptVal)) ){
				$missLst[] = $prefixString.$fldKey;
				continue;
			}
			if( $fldNod['kids']!==null ){
				$missLst = array_merge( $missLst, $this->findEmptyArray(is_array($keptVal) ? $keptVal : Array(), is_array($sentVal) ? $sentVal : Array(), $fldNod['kids'], $prefixString.$fldKey.'.') );
			}
		}
		return $missLst;
	}

	/**
	 * Tells whether a value is empty: null, an empty text, or an array
	 * holding nothing else. 0, '0', false and a text of spaces are values.
	 *
	 * @param mixed $valueAsGiven The value to check.
	 * @return Bool True when the value is empty.
	 */
	protected function hasEmptiedFlag( Mixed $valueAsGiven ): Bool {
		if( is_array($valueAsGiven) ){
			foreach( $valueAsGiven as $itmVal ){
				if( !$this->hasEmptiedFlag($itmVal) ){
					return false;
				}
			}
			return true;
		}
		return $valueAsGiven===null || $valueAsGiven==='';
	}

	/**
	 * Keeps the values listed at one level of the definition. A field
	 * defined with fields of its own is read level by level; a value that
	 * should hold fields and does not is emptied.
	 *
	 * @param Array $fieldValList Values of the level.
	 * @param Array $fldNodesList Fields of the level, as fieldsInputMap() gives them.
	 * @param Bool  $matchAnyCase Whether the names are matched whatever their case.
	 * @return Array The values kept, under the names they came with.
	 */
	protected function mapValuesArray( Array $fieldValList, Array $fldNodesList, Bool $matchAnyCase=false ): Array {
		if( $matchAnyCase ){
			$fldNodesList = array_change_key_case($fldNodesList, CASE_LOWER);
		}
		$valList = Array();
		foreach( $fieldValList as $valKey => $valVal ){
			$nodeKey = $matchAnyCase ? strtolower(strval($valKey)) : strval($valKey);
			if( !isset($fldNodesList[$nodeKey]) ){
				continue;
			}
			$fldNode = $fldNodesList[$nodeKey];
			if( $fldNode['kids']!==null ){
				$valList[$valKey] = is_array($valVal) ? $this->mapValuesArray($valVal, $fldNode['kids']) : Array();
			}elseif( $fldNode['frmt']=='' ){
				$valList[$valKey] = $valVal;
			}else{
				$valList[$valKey] = $this->formatInputVal($valVal, $fldNode['frmt'], $fldNode['optn']);
			}
		}
		return $valList;
	}

	/**
	 * Gives a value the format named, through the Variable class. An
	 * array is formatted value by value. A value the format cannot read
	 * is emptied.
	 *
	 * @param mixed   $valueAsGiven The value as sent.
	 * @param String  $nameOfFormat Name of the format (see Variable).
	 * @param ?String $formatOption Option of the format, when it takes one.
	 * @return mixed The formatted value.
	 */
	protected function formatInputVal( Mixed $valueAsGiven, String $nameOfFormat, ?String $formatOption=null ): Mixed {
		if( is_array($valueAsGiven) ){
			foreach( $valueAsGiven as $itmKey => $itmVal ){
				$valueAsGiven[$itmKey] = $this->formatInputVal($itmVal, $nameOfFormat, $formatOption);
			}
			return $valueAsGiven;
		}
		try{
			$vrblObj = new Variable($valueAsGiven);
			return $vrblObj->castWrappedVal($nameOfFormat, $formatOption ?? false);
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
	 * @param String|Array $fieldsConfig 'all', 'none', a text of name:Format(option) items separated by semicolons, or an array of name => format, name => fields, or plain names.
	 * @return ?Array The fields by name; an empty array for 'none'; null for 'all'.
	 *
	 * @throws \RuntimeException When a format is not written as Name or Name(option), or does not exist.
	 */
	protected function fieldsInputMap( String|Array $fieldsConfig ): ?Array {
		if( is_string($fieldsConfig) ){
			$defText = trim($fieldsConfig);
			if( $defText=='all' ){
				return null;
			}
			// name:Format(option) items; the option may hold a colon, not a semicolon
			$fieldsConfig = Array();
			foreach( ($defText=='none' ? Array() : explode(';', $defText)) as $lstItm ){
				$itmPrts = explode(':', $lstItm, 2);
				if( trim($itmPrts[0])!='' ){
					$fieldsConfig[trim($itmPrts[0])] = trim($itmPrts[1] ?? '');
				}
			}
		}
		$nodeMap = Array();
		foreach( $fieldsConfig as $fldKey => $fldDef ){
			// In a plain list of names, the name is the value
			$isPlain = is_int($fldKey) && !is_array($fldDef);
			$fldName = strval($isPlain ? $fldDef : $fldKey);
			// A name ending with '!' is a required field
			$isReqrd = (substr($fldName, -1)=='!');
			if( $isReqrd ){
				$fldName = rtrim(substr($fldName, 0, -1));
			}
			if( is_array($fldDef) ){
				$nodeMap[$fldName] = Array('frmt'=>'', 'optn'=>null, 'kids'=>$this->fieldsInputMap($fldDef), 'reqd'=>$isReqrd);
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
			if( $frmName!='' && !method_exists(Variable::class, 'formats'.$frmName) ){
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
    public function readRgstValues( ): Array {
		// Stack values of the current Registry
		// by merging it with an existing array
		return $this->inputsList[$this->storedName];
	}
}
