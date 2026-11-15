<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;

/**
 * A list of rows of a table, read through the store of its database: the
 * filter, fields, order, limit, grouping and joins are given as plain
 * values.
 */
class DBLst {
	protected $tableLabel;
	protected $tableDbCon;
	// Name of the configured database the table lives in: '' for the default one. A model sets its own.
	protected $dbLinkName = '';
	
	// What the list reads, as plain values: the store turns them into a query
	protected $whereArray = Array();
	protected $selectList = Array();
	protected $aggregates = Array();
	protected $orderTerms = Array();
	protected $limitCount = null;
	protected $offsetNumb = null;
	protected $tableJoins = Array();
	protected $groupNames = Array();
	// Fragments written as SQL by the developer, kept as given: SQL types only
	protected $sqlClauses = Array();
	
	protected $loadedRows;
	
	/**
	 * Builds a list of the rows of a table, on the connection given or on
	 * the one of the database the list names.
	 *
	 * The selection, where, order and limit fragments are written into the
	 * query as given: they must never hold request input, and only the SQL
	 * types take them. Use setReadColumns(), setListFilters(), setOrderFields() and
	 * setLimitOffset() for anything else. An order written as plain field names
	 * ("date ASC, time DESC") and a limit written as a number are read as
	 * such, and work on every kind of database.
	 *
	 * @param String $databaseTble Name of the table to query.
	 * @param ?String $selectFields Fields to select (defaults to '*').
	 * @param ?String $filterClause Pre-built WHERE clause fragment, if any.
	 * @param ?String $orderByTerms Pre-built ORDER BY clause fragment, if any.
	 * @param ?String $ceilingValue Pre-built LIMIT clause fragment, if any.
	 * @param ?DBCon $dbConnection Connection to use instead of the one of the database named by $baseName.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	function __construct( String $databaseTble, ?String $selectFields=null, ?String $filterClause=null, ?String $orderByTerms=null, ?String $ceilingValue=null, ?DBCon $dbConnection=null ){
		$this->tableLabel = $databaseTble;
		if($dbConnection == null){
			$this->tableDbCon = DBCon::linkNamedDbCon($this->dbLinkName);
		}else{
			$this->tableDbCon = $dbConnection;
		}
		// A table name that is not a plain name is refused
		$this->tableDbCon->getStoreObject()->checkNameChars($this->tableLabel);
		
		if( $selectFields!==null && trim($selectFields)!='' && trim($selectFields)!='*' ){
			$this->sqlClauses['field'] = $selectFields;
		}
		if( $filterClause!==null && trim($filterClause)!='' ){
			$this->sqlClauses['where'] = $filterClause;
		}
		if( $orderByTerms!==null && trim($orderByTerms)!='' ){
			// Plain field names with their direction are kept as a sort every database can do
			if( preg_match('/^\s*\w+(\.\w+)?(\s+(ASC|DESC))?(\s*,\s*\w+(\.\w+)?(\s+(ASC|DESC))?)*\s*$/iD', $orderByTerms) ){
				foreach(explode(',', $orderByTerms) as $oneFld){
					$ordrPrts = preg_split('/\s+/', trim($oneFld));
					$this->orderTerms[] = Array($ordrPrts[0], strtoupper($ordrPrts[1] ?? '')=='DESC');
				}
			}else{
				// Kept as given, as the other fragments are, and told : setOrderFields() is the way that takes no SQL
				\nyu\Tools\Logger::getLoggerOfChn('runner')->notice( 'List of '.json_encode($databaseTble).': order written as SQL, kept as given' );
				$this->sqlClauses['order'] = $orderByTerms;
			}
		}
		if( $ceilingValue!==null && trim($ceilingValue)!='' ){
			if( ctype_digit(trim($ceilingValue)) ){
				$this->limitCount = intval(trim($ceilingValue));
			}else{
				$this->sqlClauses['limit'] = $ceilingValue;
			}
		}
	}
	
	/**
	 * Reads the rows of the list from the database.
	 *
	 * @param Bool $keepReadRows Whether to return the rows already read, when there are some, instead of reading again.
	 * @return mixed The rows, or false when they could not be read.
	 */
	public function readRowsOfList( Bool $keepReadRows=false ): Mixed {
		if( $keepReadRows && is_array($this->loadedRows) ){
			return $this->loadedRows;
		}
		$findOpts = Array(
			'field'=>$this->selectList, 'funcs'=>$this->aggregates, 'order'=>$this->orderTerms,
			'limit'=>$this->limitCount, 'offst'=>$this->offsetNumb,
			'joins'=>$this->tableJoins, 'group'=>$this->groupNames, 'devel'=>$this->sqlClauses
		);
		// Only what is set is asked for: a store refuses the options it cannot honour
		$findOpts = array_filter($findOpts, function($optionsValue){ return $optionsValue!==null && $optionsValue!==Array(); });
		$this->loadedRows = $this->tableDbCon->getStoreObject()->findItemsArray($this->tableLabel, $this->whereArray, $findOpts);
		
		return $this->loadedRows;
	}
	
	/**
	 * Runs the query and returns a flat array of a single field's values
	 * across all result rows.
	 *
	 * @param String $isolateField Name of the field to isolate from each row.
	 * @param Bool   $keepReadRows Whether to use the rows already read, when there are some.
	 * @return Array Flat array of the isolated field's values.
	 */
	public function listColumnVals( String $isolateField, Bool $keepReadRows=false ): Array {
		$rsltList = $this->readRowsOfList($keepReadRows);
		$flatList = Array();
		if(isset($rsltList) && !empty($rsltList)){
			if(isset($rsltList[$isolateField])){
				$flatList[] = $rsltList[$isolateField];
			}else{				
				foreach($rsltList as $oneRow){
					if(isset($oneRow[$isolateField])){
						$flatList[] = $oneRow[$isolateField];
					}
				}
			}
		}
		return $flatList;
	}
	
	/**
	 * Sets the filter of the list from a map of field => value(s), in
	 * place of any filter set before. The filter syntax is the one of
	 * DBStore\Filter::readWhereArray(): a field name may end with '!' (negation), '<=',
	 * '>=', '<', '>' or '=' (exact value); an array value gives IN/NOT IN,
	 * and a name ending with '~' or '!~' LIKE/NOT LIKE, '*' being any text
	 * in its value. Any other value is compared exactly. 'field?'=>false gives
	 * IS NULL and 'field?'=>true IS NOT NULL; a null value matches nothing.
	 *
	 * @param Array $filterFields Map of field name => value (or array of values) to filter by.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public function setListFilters( Array $filterFields ): Void {
		// Read once here, so that a faulty filter is refused where it is written
		DBStore\Filter::readWhereArray($filterFields);
		$this->whereArray = $filterFields;
		unset($this->sqlClauses['where']);
	}
	
	/**
	 * Sets the fields the list reads, from a comma-separated list. Each
	 * entry is a field (`title`, `shows.title`) or an aggregate of
	 * DBStore\Store::FUNC_LIST on one field (`max(date)`), or on '*' for a
	 * count (`count(*)`), with an optional ` as alias`. The fields come
	 * first in each row, then the functions.
	 *
	 * @param ?String $fldScopeText Comma-separated list of fields, or null for '*'.
	 *
	 * @throws \RuntimeException When an entry has any other form, or names another function.
	 */
	public function setReadColumns( ?String $fldScopeText = null ): Void {
		$this->selectList = Array();
		$this->aggregates = Array();
		unset($this->sqlClauses['field']);
		if( $fldScopeText===null || trim($fldScopeText)=='' || trim($fldScopeText)=='*' ){
			return;
		}
		$dtbsStre = $this->tableDbCon->getStoreObject();
		$fldsList = Array();
		$funcsDct = Array();
		foreach(explode(',', str_replace('`', '', $fldScopeText)) as $column){
			// name, table.name, function(name) or function(*), each with an optional alias
			if( !preg_match('/^(?:(\w+)\((\*|\w+(?:\.\w+)?)\)|(\w+(?:\.\w+)?))(?:\s+as\s+(\w+))?$/iD', trim($column), $fieldPar) ){
				throw new \RuntimeException('Invalid field selection: '.trim($column));
			}
			$fieldAls = $fieldPar[4] ?? '';
			if( $fieldPar[1]!='' ){
				// An aggregate of DBStore\Store::FUNC_LIST only, and '*' for a count alone
				$funcsNme = strtolower($fieldPar[1]);
				if( !in_array($funcsNme, DBStore\Store::FUNC_LIST, true) || ($fieldPar[2]=='*' && $funcsNme!='count') ){
					throw new \RuntimeException('Invalid field selection: '.trim($column));
				}
				$funcsDct[] = Array('funct'=>$funcsNme, 'field'=>($fieldPar[2]=='*') ? '*' : $dtbsStre->checkNameChars($fieldPar[2]), 'alias'=>$fieldAls);
			}elseif( $fieldAls!='' ){
				$fldsList[] = Array('field'=>$dtbsStre->checkNameChars($fieldPar[3]), 'alias'=>$fieldAls);
			}else{
				$fldsList[] = $dtbsStre->checkNameChars($fieldPar[3]);
			}
		}
		$this->selectList = $fldsList;
		$this->aggregates = $funcsDct;
	}
	
	/**
	 * Sets the fields the rows are grouped by.
	 *
	 * @param String|Array $groupByField Field name or array of field names to group by.
	 *
	 * @throws \RuntimeException When a field name is not valid.
	 */
	public function setGroupFields( String|Array $groupByField ): Void {
		$groupLst = Array();
		foreach((array)$groupByField as $fldNme){
			$groupLst[] = $this->tableDbCon->getStoreObject()->checkNameChars(strval($fldNme));
		}
		$this->groupNames = $groupLst;
	}
	
	/**
	 * Sets the fields the rows are sorted by, in place of any order set
	 * before. A field prefixed with '^' is sorted descending.
	 *
	 * @param String|Array $sortByFields Field name or array of field names to order by.
	 *
	 * @throws \RuntimeException When a field name is not valid.
	 */
	public function setOrderFields( String|Array $sortByFields ): Void {
		$orderVal = Array();
		foreach((array)$sortByFields as $fldNme){
			$fldNme = strval($fldNme);
			$descSort = (substr($fldNme,0,1)=='^');
			if( $descSort ){
				$fldNme = substr($fldNme, 1);
			}
			$orderVal[] = Array($this->tableDbCon->getStoreObject()->checkNameChars($fldNme), $descSort);
		}
		$this->orderTerms = $orderVal;
		unset($this->sqlClauses['order']);
	}
	
	/**
	 * Sets how many rows are read, from a count, or from a count and an
	 * offset given as a "count,offset" string or a [count, offset] array.
	 * Both are read as whole numbers: a count that is not a number gives
	 * the default number of rows (DEF_ROWS, 100 when not set), an offset that is not a number is left out.
	 *
	 * @param mixed $limitIndexes Limit as a number, or a "count[,offset]" string/array; null clears the limit.
	 */
	public function setLimitOffset( Mixed $limitIndexes=null ): Void {
		$this->limitCount = null;
		$this->offsetNumb = null;
		unset($this->sqlClauses['limit']);
		if($limitIndexes!=null){
			$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
			$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
			$dfltLimt = intval($iniConfg['DEF_ROWS'] ?? 100) ?: 100;
			$limitIdx = is_array($limitIndexes) ? array_values($limitIndexes) : explode(',', strval($limitIndexes));
			$this->limitCount = $this->castToWholeNum($limitIdx[0] ?? null) ?? $dfltLimt;
			$this->offsetNumb = $this->castToWholeNum($limitIdx[1] ?? null);
		}
	}
	
	/**
	 * Reads a limit or offset value as a whole number, never negative.
	 *
	 * @param mixed $valueAsGiven The value, as a number or a numeric string.
	 * @return ?Int The whole number, or null when the value is not a number.
	 */
	protected function castToWholeNum( Mixed $valueAsGiven ): ?Int {
		if( !is_scalar($valueAsGiven) || !is_numeric(trim(strval($valueAsGiven))) ){
			return null;
		}
		return max(0, intval(trim(strval($valueAsGiven))));
	}
	
	/**
	 * Joins another table to the list, as a single LEFT JOIN, from a
	 * 3-element array of [joinTable, field1, field2]. A field may carry
	 * its table (`events.show_id`). An empty array clears the join. Only
	 * the SQL types can join.
	 *
	 * @param Array $joinSettings [joinTable, field1, field2] describing the join.
	 *
	 * @throws \RuntimeException When the join does not have three parts, or a name is not valid.
	 *
	 * @todo Version 1.1 : several tables and other operators; a single LEFT JOIN is supported in 1.0.
	 */
	public function joinOtherTable( Array $joinSettings=array() ): Void {
		// Currently supports single left joins
		// Array ( 'joinTable', 'fld1', 'fld2') )
		$this->tableJoins = Array();
		if( !empty($joinSettings) ) {
			if( count($joinSettings)!=3 ){
				throw new \RuntimeException('A join needs a table and two field names');
			}
			foreach(array_values($joinSettings) as $jonNme){
				$this->tableJoins[] = $this->tableDbCon->getStoreObject()->checkNameChars(strval($jonNme));
			}
		}
	}
	
	/**
	 * Attaches a related child list (fetched from another table via a
	 * foreign key) to each row already loaded by this list.
	 *
	 * @param String $childTbleTxt Name of the related table to fetch children from.
	 * @param String $singleFldTxt When given, isolates just this field from each child row instead of the full row.
	 * @param String $childLinkFld Field on the child table referencing this list's rows (defaults to `<singularized table>_id`).
	 * @param String $ownLinkField Field on this list's rows referenced by the child (defaults to `id_<singularized table>`).
	 * @return Array The current rows, each augmented with its child data under the child table's name.
	 */
	public function loadChildRecrd( String $childTbleTxt, String $singleFldTxt='', String $childLinkFld='', String $ownLinkField='' ): Array {
		if( $childLinkFld=='' ) {
			$extnName = StrKit::singularizeTxt($this->tableLabel);
			$childLinkFld = $extnName.'_id';
		}
		if( $ownLinkField=='' ) {
			$itrnName = StrKit::singularizeTxt($this->tableLabel);
			$ownLinkField = 'id_'.$itrnName;
		}
		$listNtry = $this->loadedRows;
		if(!$listNtry){
			$this->readRowsOfList();
			$listNtry = $this->loadedRows;
		}
		foreach($listNtry as $rowKey=>$rowArr){
			// A row without a key value has no children
			if( !isset($rowArr[$ownLinkField]) ){
				$listNtry[$rowKey][$childTbleTxt] = Array();
				continue;
			}
			$subLstng = new DBLst($childTbleTxt, null, null, null, null, $this->tableDbCon);
			// The key is compared exactly, never read as a wildcard
			$subLstng->setListFilters(Array($childLinkFld.'='=>$rowArr[$ownLinkField]));
			if($singleFldTxt!=''){
				$listNtry[$rowKey][$childTbleTxt] = $subLstng->listColumnVals($singleFldTxt);
			}else{
				$listNtry[$rowKey][$childTbleTxt] = $subLstng->readRowsOfList();
			}
		}
		return $listNtry;
	}
}