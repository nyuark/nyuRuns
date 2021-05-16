<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;

class DBList {
	protected $tableNme;	
	protected $dbConnex;
	
	protected $whrClaus;
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
	 * @param String $dbaseTable Name of the table to query.
	 * @param String $selctScope Fields to select (defaults to '*').
	 * @param String $whereClaus Pre-built WHERE clause fragment, if any.
	 * @param String $orderClaus Pre-built ORDER BY clause fragment, if any.
	 * @param String $limitValue Pre-built LIMIT clause fragment, if any.
	 * @param DBCon  $handleLink Existing DB connection to reuse instead of the shared singleton.
	 */
	function __construct( String $dbaseTable, ?String $selctScope=null, ?String $whereClaus=null, ?String $orderClaus=null, ?String $limitValue=null, ?DBCon $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($selctScope == null) $selctScope='*';
		$this->selScope = $selctScope;
		$this->whrClaus = $whereClaus;
		$this->whrOrder = $orderClaus;
		$this->whrLimit = $limitValue;
		if($handleLink == null){
			$nyuConf = $GLOBALS['nyuConfig'];
			$nyuBase = $nyuConf->readValue('database');
			$this->dbConnex = DBCon::getInstnc($nyuBase['TYPE'],$nyuBase['HOST'],$nyuBase['USER'],$nyuBase['PASS'],$nyuBase['BASE']);
		}else{
			$this->dbConnex = $handleLink;
		}
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
		
		$selTabl = $this->tableNme;
		
		$listSel = 'SELECT '.$scpeVal;
		$listSel .=' FROM `'.$selTabl.'`';
		
		if( $lstJoin!='' )	$listSel.= ' LEFT JOIN '.$lstJoin;
		if( $whrVals!='' )	$listSel.= ' WHERE '.$whrVals;
		if( $grpVals!='' )	$listSel.= ' GROUP by '.$grpVals;
		if( $ordrVal!='' )	$listSel.= ' ORDER by '.$ordrVal;
		if( $limVals!='' )	$listSel.= ' LIMIT '.$limVals;
		
		$this->dbConnex->dbHandle->sendQuery($listSel);
	//	echo $listSel.'<br />';
	}
	
	/**
	 * Builds the WHERE clause from a map of field => value(s), supporting
	 * IN/NOT IN for array values, LIKE/NOT LIKE when a value contains a
	 * wildcard ('*' or '%'), and a trailing '!' on the field name to negate
	 * the comparison.
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 */
	public function lstSearch( Array $whereArray ): Void {
		$this->whrClaus = '';
		$whreStr = '';
		foreach($whereArray as $fldNme=>$fldVal){
			if($whreStr != ''){
				$whreStr.=' AND ';
			}
			
			if(is_array($fldVal)){
				$valsArr = $fldVal;
				$operatr = 'IN';
				if(substr($fldNme,-1)=='!'){
					$operatr = 'NOT IN';
					$fldNme = substr($fldNme,0,-1);
				}
				$valList = '';
				foreach($valsArr as $lstVal){
					if(!empty($lstVal)){
						if($valList!=''){
							$valList.=',';
						}
						$valList.="'".$this->dbConnex->dbHandle->escapeStr($lstVal)."'";
					}
				}
				$whreStr .= '`'.$fldNme.'` '.$operatr.' ('.$valList.')';
			}else{
				$operatr = '=';
				$reverse = false;
				if(substr($fldNme,-1)=='!'){
					$operatr = '!=';
					$reverse=true;
					$fldNme = substr($fldNme,0,-1);
				}
				if( ( substr($fldNme,-2)=='<=' || substr($fldNme,-2)=='>=' ) ){
					$operatr = substr($fldNme,-2);
					$fldNme = substr($fldNme,0,-2);
				}
				
				if( ( strpos($fldVal, '*')!==false || strpos($fldVal, '%')!==false ) ){
					$fldVal = str_replace( '*','%', $fldVal) ;
					if ($reverse==false){
						$operatr = 'LIKE';
					}else{
						$operatr = 'NOT LIKE';
					}
				}
				$whreStr .= '`'.$fldNme.'` '.$operatr." '".$this->dbConnex->dbHandle->escapeStr($fldVal)."'";
			}
		}
		$this->whrClaus = $whreStr;
	}
	
	/**
	 * Sets the field selection scope for the SELECT, from a comma-separated
	 * field list. Backtick-quotes plain field names but leaves expressions
	 * (containing '(' or ' as ') unquoted.
	 *
	 * @param String $fieldScope Comma-separated list of fields, or null for '*'.
	 */
	public function fieldScop( ?String $fieldScope = null ): Void {
		if( $fieldScope == null){
			$this->selScope = '*';
		}else{
			$scopStr = '';
			$selFlds = Array();
			if( is_string($fieldScope) ){
				$fieldScope = str_replace('`','',$fieldScope);
				$selFlds = explode(',',$fieldScope);
			}
			foreach($selFlds as $selFld){
				$selFld = trim($selFld);
				if($scopStr != ''){
					$scopStr.=',';
				}
				if(strpos($selFld,'(')===false && strpos($selFld,' as ')===false){
					$scopStr .= '`'.$this->dbConnex->dbHandle->escapeStr($selFld).'`';
				}else{
					$scopStr .= $this->dbConnex->dbHandle->escapeStr($selFld);
				}
			}
			$this->selScope = $scopStr;
		}
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
				$grpVals .= '`'.$this->dbConnex->dbHandle->escapeStr($fldNme).'`';
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
				$ordrVal .= '`'.$this->dbConnex->dbHandle->escapeStr($fldNme).'`'.$ordrDir;
			}
		}
		$this->whrOrder = $ordrVal;
	}
	
	/**
	 * Builds the LIMIT clause from a count, an "offset,count" comma pair,
	 * or a numeric value; defaults to 100 rows when no valid value is given.
	 *
	 * @param mixed $limIndexes Limit as a number, or a "count[,offset]" string/array; null clears the limit.
	 */
	public function listLimit( Mixed $limIndexes=null ): Void {
		$this->whrLimit = '';
		// TODO : Get Table Limit from core config
		if($limIndexes!=null){
			$dfltLim = 100;
			$limIndx = Array(
				$dfltLim
			);
			if(is_string($limIndexes)){
				str_replace(' ','',$limIndexes);
				if(strpos($limIndexes,',')!==false){
					$limIndx = explode(',', $limIndexes);
				}
			}
			if(!is_array($limIndexes)){
				if(is_numeric($limIndexes)){
					$limIndx = Array(
						intval($limIndexes)
					);
				}
			}
			
			$this->whrLimit.= $limIndx[0];
			if(isset($limIndx[1])) $this->whrLimit.=' OFFSET '.$limIndx[1];
		}
	}
	
	/**
	 * Builds a single LEFT JOIN clause from a 3-element array of
	 * [joinTable, field1, field2].
	 *
	 * @param Array $joinClause [joinTable, field1, field2] describing the join.
	 *
	 * @todo Only a single LEFT JOIN is currently supported; multiple tables/operators and per-field table references are not.
	 */
	public function tableJoin( Array $joinClause=array() ): Void {
		// Currently supports single left joins
		// Array ( 'joinTable', 'fld1', 'fld2') )
		// TODO : Could admit many tables and different operators
		// TODO : Also could set table reference for each field
		$this->whrJoint = '';
		if( is_array($joinClause) && !empty($joinClause) ) {
			$this->whrJoint .= '`'.$joinClause[0].'` ON '.$joinClause[1].' = '.$joinClause[2];
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
			$subWhre = "`$foreingKey` = '".$this->dbConnex->dbHandle->escapeStr($rowArr[$currentKey])."'";
			$subList = new DBList($childTable, null, $subWhre);
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