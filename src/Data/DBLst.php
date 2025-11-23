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
	protected $tableNme;
	protected $dbConnex;
	// Name of the configured database the table lives in: '' for the default one. A model sets its own.
	protected $baseName = '';
	
	// What the list reads, as plain values: the store turns them into a query
	protected $whrArray = Array();
	protected $slctFlds = Array();
	protected $slctFncs = Array();
	protected $ordrList = Array();
	protected $limitNum = null;
	protected $offstNum = null;
	protected $joinList = Array();
	protected $grpNames = Array();
	// Fragments written as SQL by the developer, kept as given: SQL types only
	protected $devlText = Array();
	
	protected $listRows;
	
	/**
	 * Builds a list of the rows of a table, on the connection given or on
	 * the one of the database the list names.
	 *
	 * The selection, where, order and limit fragments are written into the
	 * query as given: they must never hold request input, and only the SQL
	 * types take them. Use fieldScop(), lstSearch(), listOrder() and
	 * listLimit() for anything else. An order written as plain field names
	 * ("date ASC, time DESC") and a limit written as a number are read as
	 * such, and work on every kind of database.
	 *
	 * @param String $dbaseTable Name of the table to query.
	 * @param ?String $selctScope Fields to select (defaults to '*').
	 * @param ?String $whereClaus Pre-built WHERE clause fragment, if any.
	 * @param ?String $orderClaus Pre-built ORDER BY clause fragment, if any.
	 * @param ?String $limitValue Pre-built LIMIT clause fragment, if any.
	 * @param ?DBCon $handleLink Connection to use instead of the one of the database named by $baseName.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	function __construct( String $dbaseTable, ?String $selctScope=null, ?String $whereClaus=null, ?String $orderClaus=null, ?String $limitValue=null, ?DBCon $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($handleLink == null){
			$this->dbConnex = DBCon::linkNamed($this->baseName);
		}else{
			$this->dbConnex = $handleLink;
		}
		// A table name that is not a plain name is refused
		$this->dbConnex->dataStore()->checkName($this->tableNme);
		
		if( $selctScope!==null && trim($selctScope)!='' && trim($selctScope)!='*' ){
			$this->devlText['field'] = $selctScope;
		}
		if( $whereClaus!==null && trim($whereClaus)!='' ){
			$this->devlText['where'] = $whereClaus;
		}
		if( $orderClaus!==null && trim($orderClaus)!='' ){
			// Plain field names with their direction are kept as a sort every database can do
			if( preg_match('/^\s*\w+(\.\w+)?(\s+(ASC|DESC))?(\s*,\s*\w+(\.\w+)?(\s+(ASC|DESC))?)*\s*$/iD', $orderClaus) ){
				foreach(explode(',', $orderClaus) as $ordFld){
					$ordPart = preg_split('/\s+/', trim($ordFld));
					$this->ordrList[] = Array($ordPart[0], strtoupper($ordPart[1] ?? '')=='DESC');
				}
			}else{
				$this->devlText['order'] = $orderClaus;
			}
		}
		if( $limitValue!==null && trim($limitValue)!='' ){
			if( ctype_digit(trim($limitValue)) ){
				$this->limitNum = intval(trim($limitValue));
			}else{
				$this->devlText['limit'] = $limitValue;
			}
		}
	}
	
	/**
	 * Reads the rows of the list from the database.
	 *
	 * @param Bool $queryBuilt Whether to return the rows already read, when there are some, instead of reading again.
	 * @return mixed The rows, or false when they could not be read.
	 */
	public function readItems( Bool $queryBuilt=false ): Mixed {
		if( $queryBuilt && is_array($this->listRows) ){
			return $this->listRows;
		}
		$findOpt = Array(
			'field'=>$this->slctFlds, 'funcs'=>$this->slctFncs, 'order'=>$this->ordrList,
			'limit'=>$this->limitNum, 'offst'=>$this->offstNum,
			'joins'=>$this->joinList, 'group'=>$this->grpNames, 'devel'=>$this->devlText
		);
		// Only what is set is asked for: a store refuses the options it cannot honour
		$findOpt = array_filter($findOpt, function($optionItem){ return $optionItem!==null && $optionItem!==Array(); });
		$this->listRows = $this->dbConnex->dataStore()->findItems($this->tableNme, $this->whrArray, $findOpt);
		
		return $this->listRows;
	}
	
	/**
	 * Runs the query and returns a flat array of a single field's values
	 * across all result rows.
	 *
	 * @param String $isolateFld Name of the field to isolate from each row.
	 * @param Bool   $queryBuilt Whether to use the rows already read, when there are some.
	 * @return Array Flat array of the isolated field's values.
	 */
	public function flatItems( String $isolateFld, Bool $queryBuilt=false ): Array {
		$results = $this->readItems($queryBuilt);
		$flatLst = Array();
		if(isset($results) && !empty($results)){
			if(isset($results[$isolateFld])){
				$flatLst[] = $results[$isolateFld];
			}else{				
				foreach($results as $result){
					if(isset($result[$isolateFld])){
						$flatLst[] = $result[$isolateFld];
					}
				}
			}
		}
		return $flatLst;
	}
	
	/**
	 * Sets the filter of the list from a map of field => value(s), in
	 * place of any filter set before. The filter syntax is the one of
	 * DBStore\Filter::readWhere(): a field name may end with '!' (negation), '<=',
	 * '>=', '<', '>' or '=' (exact value); an array value gives IN/NOT IN,
	 * and a name ending with '~' or '!~' LIKE/NOT LIKE, '*' being any text
	 * in its value. Any other value is compared exactly. 'field?'=>false gives
	 * IS NULL and 'field?'=>true IS NOT NULL; a null value matches nothing.
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public function lstSearch( Array $whereArray ): Void {
		// Read once here, so that a faulty filter is refused where it is written
		DBStore\Filter::readWhere($whereArray);
		$this->whrArray = $whereArray;
		unset($this->devlText['where']);
	}
	
	/**
	 * Sets the fields the list reads, from a comma-separated list. Each
	 * entry is a field (`title`, `shows.title`) or a function on one field
	 * or on '*' (`count(*)`, `max(date)`), with an optional ` as alias`.
	 * The fields come first in each row, then the functions.
	 *
	 * @param ?String $fieldScope Comma-separated list of fields, or null for '*'.
	 *
	 * @throws \RuntimeException When an entry has any other form.
	 */
	public function fieldScop( ?String $fieldScope = null ): Void {
		$this->slctFlds = Array();
		$this->slctFncs = Array();
		unset($this->devlText['field']);
		if( $fieldScope===null || trim($fieldScope)=='' || trim($fieldScope)=='*' ){
			return;
		}
		$dbStore = $this->dbConnex->dataStore();
		$fldsLst = Array();
		$fncsLst = Array();
		foreach(explode(',', str_replace('`', '', $fieldScope)) as $selFld){
			// name, table.name, function(name) or function(*), each with an optional alias
			if( !preg_match('/^(?:(\w+)\((\*|\w+(?:\.\w+)?)\)|(\w+(?:\.\w+)?))(?:\s+as\s+(\w+))?$/iD', trim($selFld), $fldPart) ){
				throw new \RuntimeException('Invalid field selection: '.trim($selFld));
			}
			$fldAlas = $fldPart[4] ?? '';
			if( $fldPart[1]!='' ){
				$fncsLst[] = Array('funct'=>$fldPart[1], 'field'=>$fldPart[2], 'alias'=>$fldAlas);
			}elseif( $fldAlas!='' ){
				$fldsLst[] = Array('field'=>$dbStore->checkName($fldPart[3]), 'alias'=>$fldAlas);
			}else{
				$fldsLst[] = $dbStore->checkName($fldPart[3]);
			}
		}
		$this->slctFlds = $fldsLst;
		$this->slctFncs = $fncsLst;
	}
	
	/**
	 * Sets the fields the rows are grouped by.
	 *
	 * @param String|Array $groupByFld Field name or array of field names to group by.
	 *
	 * @throws \RuntimeException When a field name is not valid.
	 */
	public function grpFields( String|Array $groupByFld ): Void {
		$grpVals = Array();
		foreach((array)$groupByFld as $fldNme){
			$grpVals[] = $this->dbConnex->dataStore()->checkName(strval($fldNme));
		}
		$this->grpNames = $grpVals;
	}
	
	/**
	 * Sets the fields the rows are sorted by, in place of any order set
	 * before. A field prefixed with '^' is sorted descending.
	 *
	 * @param String|Array $orderItems Field name or array of field names to order by.
	 *
	 * @throws \RuntimeException When a field name is not valid.
	 */
	public function listOrder( String|Array $orderItems ): Void {
		$ordrVal = Array();
		foreach((array)$orderItems as $fldNme){
			$fldNme = strval($fldNme);
			$dscSort = (substr($fldNme,0,1)=='^');
			if( $dscSort ){
				$fldNme = substr($fldNme, 1);
			}
			$ordrVal[] = Array($this->dbConnex->dataStore()->checkName($fldNme), $dscSort);
		}
		$this->ordrList = $ordrVal;
		unset($this->devlText['order']);
	}
	
	/**
	 * Sets how many rows are read, from a count, or from a count and an
	 * offset given as a "count,offset" string or a [count, offset] array.
	 * Both are read as whole numbers: a count that is not a number gives
	 * the default number of rows (LST_ROWS, 100 when not set), an offset that is not a number is left out.
	 *
	 * @param mixed $limIndexes Limit as a number, or a "count[,offset]" string/array; null clears the limit.
	 */
	public function listLimit( Mixed $limIndexes=null ): Void {
		$this->limitNum = null;
		$this->offstNum = null;
		unset($this->devlText['limit']);
		if($limIndexes!=null){
			$nyuConf = $GLOBALS['nyuConfig'] ?? null;
			$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
			$dfltLim = intval($sysConf['LST_ROWS'] ?? 100) ?: 100;
			$limIndx = is_array($limIndexes) ? array_values($limIndexes) : explode(',', strval($limIndexes));
			$this->limitNum = $this->wholeNmbr($limIndx[0] ?? null) ?? $dfltLim;
			$this->offstNum = $this->wholeNmbr($limIndx[1] ?? null);
		}
	}
	
	/**
	 * Reads a limit or offset value as a whole number, never negative.
	 *
	 * @param mixed $givenValue The value, as a number or a numeric string.
	 * @return ?Int The whole number, or null when the value is not a number.
	 */
	protected function wholeNmbr( Mixed $givenValue ): ?Int {
		if( !is_scalar($givenValue) || !is_numeric(trim(strval($givenValue))) ){
			return null;
		}
		return max(0, intval(trim(strval($givenValue))));
	}
	
	/**
	 * Joins another table to the list, as a single LEFT JOIN, from a
	 * 3-element array of [joinTable, field1, field2]. A field may carry
	 * its table (`events.show_id`). An empty array clears the join. Only
	 * the SQL types can join.
	 *
	 * @param Array $joinClause [joinTable, field1, field2] describing the join.
	 *
	 * @throws \RuntimeException When the join does not have three parts, or a name is not valid.
	 *
	 * @todo Version 1.1 : several tables and other operators; a single LEFT JOIN is supported in 1.0.
	 */
	public function tableJoin( Array $joinClause=array() ): Void {
		// Currently supports single left joins
		// Array ( 'joinTable', 'fld1', 'fld2') )
		$this->joinList = Array();
		if( !empty($joinClause) ) {
			if( count($joinClause)!=3 ){
				throw new \RuntimeException('A join needs a table and two field names');
			}
			foreach(array_values($joinClause) as $jonNme){
				$this->joinList[] = $this->dbConnex->dataStore()->checkName(strval($jonNme));
			}
		}
	}
	
	/**
	 * Attaches a related child list (fetched from another table via a
	 * foreign key) to each row already loaded by this list.
	 *
	 * @param String $childTable Name of the related table to fetch children from.
	 * @param String $singlField When given, isolates just this field from each child row instead of the full row.
	 * @param String $foreingKey Field on the child table referencing this list's rows (defaults to `<singularized table>_id`).
	 * @param String $currentKey Field on this list's rows referenced by the child (defaults to `id_<singularized table>`).
	 * @return Array The current rows, each augmented with its child data under the child table's name.
	 */
	public function childList( String $childTable, String $singlField='', String $foreingKey='', String $currentKey='' ): Array {
		if( $foreingKey=='' ) {
			$extName = StrKit::singlrize($this->tableNme);
			$foreingKey = $extName.'_id';
		}
		if( $currentKey=='' ) {
			$intName = StrKit::singlrize($this->tableNme);
			$currentKey = 'id_'.$intName;
		}
		$lstRows = $this->listRows;
		if(!$lstRows){
			$this->readItems();
			$lstRows = $this->listRows;
		}
		foreach($lstRows as $rowKey=>$rowArr){
			// A row without a key value has no children
			if( !isset($rowArr[$currentKey]) ){
				$lstRows[$rowKey][$childTable] = Array();
				continue;
			}
			$subList = new DBLst($childTable, null, null, null, null, $this->dbConnex);
			// The key is compared exactly, never read as a wildcard
			$subList->lstSearch(Array($foreingKey.'='=>$rowArr[$currentKey]));
			if($singlField!=''){
				$lstRows[$rowKey][$childTable] = $subList->flatItems($singlField);
			}else{
				$lstRows[$rowKey][$childTable] = $subList->readItems();
			}
		}
		return $lstRows;
	}
}