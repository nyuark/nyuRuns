<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;

class DBList {
	protected $tableNme;
	protected $tblQuotd;
	protected $dbConnex;
	// Name of the configured database the table lives in: '' for the default one. A model sets its own.
	protected $baseName = '';
	
	protected $whrClaus;
	protected $whrValus = Array();
	protected $selScope;
	protected $whrOrder;
	protected $whrLimit;
	protected $whrJoint;
	protected $whrGroup;
	
	protected $listRows;
	
	/**
	 * Builds a query object for listing rows from a table, using either a
	 * provided DB connection handle or the shared connection from global
	 * config, and pre-builds the SELECT when a where clause is given.
	 *
	 * The selection, where, order and limit fragments are written into the
	 * query as given: they must never hold request input. Use fieldScop(),
	 * lstSearch(), listOrder() and listLimit() for anything that does.
	 *
	 * @param String $dbaseTable Name of the table to query.
	 * @param String $selctScope Fields to select (defaults to '*').
	 * @param String $whereClaus Pre-built WHERE clause fragment, if any.
	 * @param String $orderClaus Pre-built ORDER BY clause fragment, if any.
	 * @param String $limitValue Pre-built LIMIT clause fragment, if any.
	 * @param DBCon  $handleLink Connection to use instead of the one of the database named by $baseName.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	function __construct( String $dbaseTable, ?String $selctScope=null, ?String $whereClaus=null, ?String $orderClaus=null, ?String $limitValue=null, ?DBCon $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($selctScope == null) $selctScope='*';
		$this->selScope = $selctScope;
		$this->whrClaus = $whereClaus;
		$this->whrOrder = $orderClaus;
		$this->whrLimit = $limitValue;
		if($handleLink == null){
			$this->dbConnex = DBCon::linkNamed($this->baseName);
		}else{
			$this->dbConnex = $handleLink;
		}
		// Quoting also refuses a table name that is not a plain name
		$this->tblQuotd = $this->dbConnex->dbHandle->quoteName($this->tableNme);
		if( $whereClaus!=null ){
			$this->buildSlct();
		}
	}
	
	/**
	 * Runs the built SELECT (rebuilding it first unless told it's already
	 * built) and returns the resulting rows.
	 *
	 * @param Bool $queryBuilt Whether the SELECT was already built/sent, skipping a rebuild.
	 * @return mixed The query results.
	 */
	public function readItems( Bool $queryBuilt=false ): Mixed {
		if( $queryBuilt==false ) $this->buildSlct();
		
		$this->listRows = $this->dbConnex->dbHandle->readQuery( true );
		
		return $this->listRows;
	}
	
	/**
	 * Runs the query and returns a flat array of a single field's values
	 * across all result rows.
	 *
	 * @param String $isolateFld Name of the field to isolate from each row.
	 * @param Bool   $queryBuilt Whether the SELECT was already built/sent, skipping a rebuild.
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
	 * Assembles and sends the SELECT statement from the current (or
	 * newly given) scope, join, where, group, order and limit clauses.
	 *
	 * @param String       $selctScope Fields to select; overrides the current scope when given.
	 * @param Array        $whereClaus Array of field/value pairs to build a WHERE clause from.
	 * @param String|Array $orderClaus Order-by field(s); overrides the current order when given.
	 * @param mixed        $limitValue Limit/offset value(s); overrides the current limit when given.
	 * @param Array        $joinClause Join definition array; overrides the current join when given.
	 * @param String|Array $groupClaus Group-by field(s); overrides the current group when given.
	 */
	protected function buildSlct( ?String $selctScope=null, ?Array $whereClaus=null, String|Array|Null $orderClaus=null, Mixed $limitValue=null, ?Array $joinClause=null, String|Array|Null $groupClaus=null ): Void {
		
		if($selctScope != null)	$this->selScope = $selctScope;
		
		$scpeVal = $this->selScope;
		
		$lstJoin = '';
		if(!is_null($joinClause))$this->tableJoin($joinClause);
		if($this->whrJoint!='') $lstJoin = $this->whrJoint;
		
		$whrVals = '';
		if(is_array($whereClaus))	$this->lstSearch($whereClaus);
		if($this->whrClaus!='')		$whrVals = $this->whrClaus;
		
		$grpVals = '';
		if(!is_null($groupClaus)) 	$this->grpFields($groupClaus);
		if($this->whrGroup!='') 	$grpVals = $this->whrGroup;
		
		$ordrVal = '';
		if(!is_null($orderClaus)) 	$this->listOrder($orderClaus);
		if($this->whrOrder!='') 	$ordrVal = $this->whrOrder;
		
		$limVals = '';
		if(!is_null($limitValue))	$this->listLimit($limitValue);
		if($this->whrLimit!='') 	$limVals = $this->whrLimit;
		
		$listSel = 'SELECT '.$scpeVal;
		$listSel .=' FROM '.$this->tblQuotd;
		
		if( $lstJoin!='' )	$listSel.= ' LEFT JOIN '.$lstJoin;
		if( $whrVals!='' )	$listSel.= ' WHERE '.$whrVals;
		if( $grpVals!='' )	$listSel.= ' GROUP by '.$grpVals;
		if( $ordrVal!='' )	$listSel.= ' ORDER by '.$ordrVal;
		if( $limVals!='' )	$listSel.= ' LIMIT '.$limVals;
		
		$this->dbConnex->dbHandle->sendQuery($listSel, false, $this->whrValus);
	//	echo $listSel.'<br />';
	}
	
	/**
	 * Builds the WHERE clause from a map of field => value(s). The filter
	 * syntax is the one of DBFilter::makeWhere(): a field name may end with
	 * '!' (negation), '<=', '>=', '<' or '>'; an array value gives IN/NOT IN,
	 * and a wildcard ('*' or '%') LIKE/NOT LIKE. 'field?'=>false gives
	 * IS NULL and 'field?'=>true IS NOT NULL; a null value matches nothing.
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public function lstSearch( Array $whereArray ): Void {
		$whrPart = DBFilter::makeWhere($whereArray, $this->dbConnex->dbHandle);
		$this->whrClaus = $whrPart['text'];
		$this->whrValus = $whrPart['vals'];
	}
	
	/**
	 * Sets the field selection scope for the SELECT, from a comma-separated
	 * field list. Each entry is a field (`title`, `shows.title`) or a
	 * function on one field or on '*' (`count(*)`, `max(date)`), with an
	 * optional ` as alias`.
	 *
	 * @param String $fieldScope Comma-separated list of fields, or null for '*'.
	 *
	 * @throws \RuntimeException When an entry has any other form.
	 */
	public function fieldScop( ?String $fieldScope = null ): Void {
		if( $fieldScope===null || trim($fieldScope)=='' || trim($fieldScope)=='*' ){
			$this->selScope = '*';
			return;
		}
		$dbHandl = $this->dbConnex->dbHandle;
		$scopLst = Array();
		foreach(explode(',', str_replace('`', '', $fieldScope)) as $selFld){
			// name, table.name, function(name) or function(*), each with an optional alias
			if( !preg_match('/^(?:(\w+)\((\*|\w+(?:\.\w+)?)\)|(\w+(?:\.\w+)?))(?:\s+as\s+(\w+))?$/iD', trim($selFld), $fldPart) ){
				throw new \RuntimeException('Invalid field selection: '.trim($selFld));
			}
			if( $fldPart[1]!='' ){
				$scopStr = $fldPart[1].'('.($fldPart[2]=='*' ? '*' : $dbHandl->quoteName($fldPart[2])).')';
			}else{
				$scopStr = $dbHandl->quoteName($fldPart[3]);
			}
			if( ($fldPart[4] ?? '')!='' ){
				$scopStr .= ' AS '.$dbHandl->quoteName($fldPart[4]);
			}
			$scopLst[] = $scopStr;
		}
		$this->selScope = implode(',', $scopLst);
	}
	
	/**
	 * Builds the GROUP BY clause from one or more field names.
	 *
	 * @param String|Array $groupByFld Field name or array of field names to group by.
	 */
	public function grpFields( String|Array $groupByFld ): Void {
		$grpVals = '';
		if( is_string($groupByFld) ) $groupByFld = array($groupByFld);
		if( is_array($groupByFld) && !empty($groupByFld) ){
			foreach($groupByFld as $fldNme){
				if($grpVals != ''){
					$grpVals.=',';
				}
				$grpVals .= $this->dbConnex->dbHandle->quoteName($fldNme);
			}
		}
		$this->whrGroup = $grpVals;
	}
	
	/**
	 * Builds the ORDER BY clause from one or more field names, prefixing a
	 * field with '^' to sort it descending.
	 *
	 * @param String|Array $orderItems Field name or array of field names to order by.
	 */
	public function listOrder( String|Array $orderItems ): Void {
		$ordrVal = '';
		if( is_string($orderItems) ) {
			$orderItems = array($orderItems);
		}
		if( is_array($orderItems) && !empty($orderItems) ) {
			foreach($orderItems as $fldNme){
				if($ordrVal != ''){
					$ordrVal.=',';
				}
				$ordrDir=' ASC';
				if(substr($fldNme,0,1)=='^'){
					$ordrDir=' DESC';
					$fldNme = subStr($fldNme, 1);
				}
				$ordrVal .= $this->dbConnex->dbHandle->quoteName($fldNme).$ordrDir;
			}
		}
		$this->whrOrder = $ordrVal;
	}
	
	/**
	 * Builds the LIMIT clause from a count, or from a count and an offset
	 * given as a "count,offset" string or a [count, offset] array. Both are
	 * read as whole numbers: a count that is not a number gives the default
	 * of 100 rows, an offset that is not a number is left out.
	 *
	 * @param mixed $limIndexes Limit as a number, or a "count[,offset]" string/array; null clears the limit.
	 */
	public function listLimit( Mixed $limIndexes=null ): Void {
		$this->whrLimit = '';
		// TODO : Get Table Limit from core config
		if($limIndexes!=null){
			$dfltLim = 100;
			$limIndx = is_array($limIndexes) ? array_values($limIndexes) : explode(',', strval($limIndexes));
			$rowsMax = $this->wholeNmbr($limIndx[0] ?? null);
			$rowsOff = $this->wholeNmbr($limIndx[1] ?? null);
			$this->whrLimit = strval($rowsMax ?? $dfltLim);
			if( $rowsOff!==null ){
				$this->whrLimit .= ' OFFSET '.$rowsOff;
			}
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
	 * Builds a single LEFT JOIN clause from a 3-element array of
	 * [joinTable, field1, field2]. A field may carry its table
	 * (`events.show_id`). An empty array clears the join.
	 *
	 * @param Array $joinClause [joinTable, field1, field2] describing the join.
	 *
	 * @throws \RuntimeException When the join does not have three parts, or a name is not valid.
	 *
	 * @todo Only a single LEFT JOIN is currently supported; multiple tables/operators are not.
	 */
	public function tableJoin( Array $joinClause=array() ): Void {
		// Currently supports single left joins
		// Array ( 'joinTable', 'fld1', 'fld2') )
		// TODO : Could admit many tables and different operators
		// TODO : Also could set table reference for each field
		$this->whrJoint = '';
		if( is_array($joinClause) && !empty($joinClause) ) {
			if( count($joinClause)!=3 ){
				throw new \RuntimeException('A join needs a table and two field names');
			}
			$joinLst = array_map(Array($this->dbConnex->dbHandle, 'quoteName'), array_values($joinClause));
			$this->whrJoint = $joinLst[0].' ON '.$joinLst[1].' = '.$joinLst[2];
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
			$subList = new DBList($childTable, null, null, null, null, $this->dbConnex);
			$subList->lstSearch(Array($foreingKey=>$rowArr[$currentKey]));
			if($singlField!=''){
				$lstRows[$rowKey][$childTable] = $subList->flatItems($singlField);
			}else{
				$lstRows[$rowKey][$childTable] = $subList->readItems();
			}
		}
		return $lstRows;
	}
	
	/* TOO POWERFUL TO USE FOR NOW 
		// TODO: Use Regular function
	function customSel( $scope='*', $where=false, $order=false, $limit=false, $joint=false, $group=false, $havin=false ){
		$query = 'SELECT '.$scope.' FROM `'.$this->lstTable.'`';
		if( $joint!='' )$query.= $joint;
		if( $where!='' )$query.= ' WHERE '.$where;
		if( $group!='' )$query.= ' GROUP by '.$group;
		if( $havin!='' )$query.= ' HAVING '.$havin;
		if( $order!='' )$query.= ' ORDER by '.$order;
		if( $limit!='' )$query.= ' LIMIT '.$limit;
		
		$result = $this->dbConnex->query($query);
		
		if($result){
			$this->rows = Array();
			while ($row = mysqli_fetch_assoc($result)) {
				$this->rows[] = $row;
			}
		} else {
			//$this->errLog( SB_ERR_DB_LISTFAIL.$this->dbConnex->errGet() );
		}
	}
	*/
}
/*
function mysqli_result($res, $row, $field=0) { 
    $res->data_seek($row); 
    $datarow = $res->fetch_array(); 
    return $datarow[$field]; 
}
*/