<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;

class DBRecord {
	protected $tableNme;
	protected $dbConnex;

	protected $primaKey;
	protected $keyField;
	protected $fieldLst;

	protected $recArray;
	protected $belongTo;
	protected $children;

	/**
	 * Builds a record object bound to a table: verifies the table exists,
	 * detects its primary key and field list via EXPLAIN, and stores the
	 * declared child/parent relations for later loading.
	 *
	 * @param String $dbaseTable Name of the table this record maps to.
	 * @param Array  $childArray Child relations to load via loadRelat(), keyed by table name or as [table, fld1, fld2, fld3].
	 * @param Array  $parntArray Parent relations to load via loadRelat(), keyed by table name or as [table, fld1, fld2].
	 * @param DBCon  $handleLink Existing DB connection to reuse instead of the shared singleton.
	 *
	 * @todo Halts execution with a printed error instead of raising an exception when the table doesn't exist.
	 */
	function __construct( String $dbaseTable, Array $childArray=array(), Array $parntArray=Array(), ?DBCon $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($handleLink == null){
			$nyuConf = $GLOBALS['nyuConfig'];
			$nyuBase = $nyuConf->readValue('database');
			$this->dbConnex = DBCon::getInstnc($nyuBase['TYPE'], $nyuBase['HOST'], $nyuBase['USER'], $nyuBase['PASS'], $nyuBase['BASE']);
		}else{
			$this->dbConnex = $handleLink;
		}

		// Check if table exists
		$exstQry = "SELECT 1 FROM `$this->tableNme` LIMIT 1;";
		$this->dbConnex->dbHandle->sendQuery($exstQry);
		$tabExst = $this->dbConnex->dbHandle->readQuery();
		if(!$tabExst){
			// TODO, Raise exception
			print('<h1>Database error</h1>');
			print('Table might  not exist : '.$this->dbConnex->dbHandle->lastError());
			die();
		}

		$xplnQry = "EXPLAIN `$this->tableNme`;";
		$this->dbConnex->dbHandle->sendQuery($xplnQry);
		$this->keyField = '';
		$this->fieldLst = array();

		$fldList = $this->dbConnex->dbHandle->readQuery();
		foreach($fldList as $fldArr){
			if($fldArr['Key'] == 'PRI' && $this->keyField==''){
				$this->keyField = $fldArr['Field'];
			}
			// Detect foreign keys and indexes
			$this->fieldLst[] = $fldArr['Field'];
		}
		if( !empty($childArray) ){
			$this->children = $childArray;
		}
		if( !empty($parntArray) ){
			$this->belongTo = $parntArray;
		}
	}

	/**
	 * Loads a single record by its primary key and loads its declared
	 * relations.
	 *
	 * @param String $primaryKey Value of the primary key to look up.
	 * @return mixed The loaded record's field array, or false if not found.
	 */
	function loadRecrd( String $primaryKey ): Array|Bool {
		$idQuery = "SELECT * FROM `$this->tableNme` WHERE `$this->keyField`='".$this->dbConnex->dbHandle->escapeStr($primaryKey)."';";
		$this->dbConnex->dbHandle->sendQuery($idQuery);
		$this->recArray = array();

		$this->recArray = $this->dbConnex->dbHandle->readQuery();
		if( is_array($this->recArray) && !empty($this->recArray) ){
			$this->primaKey = $primaryKey;
			$this->loadRelat();
			return $this->recArray;
		}else{
			return false;
		}
	}

	/**
	 * Loads the record's declared child and parent relations (as set up
	 * in the constructor), attaching each to the record's field array via
	 * childList()/parentRec().
	 */
	function loadRelat( ): Void {
		if( !empty($this->children) ){
			foreach( $this->children as $chlTab=>$chlFld){
				$chlArg2 = false;
				$chlArg3 = '';
				$chlArg4 = '';
				$chlArg5 = '';
				if(is_string($chlTab) && !is_numeric($chlTab)){
					$chlArg1 = $chlTab;
					if(is_array($chlFld)){
						$chlArg3 = $chlFld[0];
						$chlArg4 = $chlFld[1];
						$chlArg5 = $chlFld[2];
					}else{
						$chlArg3 = $chlFld;
					}
				}else{
					$chlArg1 = $chlFld;
				}
				$this->childList($chlArg1, $chlArg2, $chlArg3, $chlArg4, $chlArg5);
			}
		}
		if( !empty($this->belongTo) ){
			foreach( $this->belongTo as $prnTab=>$prnFld){
				$prnArg2 = false;
				$prnArg3 = '';
				$prnArg4 = '';
				if(is_string($prnTab) && !is_numeric($prnTab)){
					$prnArg1 = $prnTab;
					if(is_array($prnFld)){
						$prnArg3 = $prnFld[0];
						$prnArg4 = $prnFld[1];
					}else{
						$prnArg3 = $prnFld;
					}
				}else{
					$prnArg1 = $prnFld;
				}
				$this->parentRec($prnArg1, $prnArg2, $prnArg3, $prnArg4);
			}
		}
	}

	/**
	 * Checks whether a record with the given primary key exists.
	 *
	 * @param mixed $primaryKey Value of the primary key to look up.
	 * @return Bool True if a matching record exists.
	 */
	function exstRecrd( Mixed $primaryKey ): Bool {
		if( $primaryKey===null || $primaryKey==='' ){
			return false;
		}
		$idQuery = "SELECT * FROM `$this->tableNme` WHERE `$this->keyField`='".$this->dbConnex->dbHandle->escapeStr($primaryKey)."';";
		$this->dbConnex->dbHandle->sendQuery($idQuery);

		$tempRec = $this->dbConnex->dbHandle->readQuery();
		if( is_array($tempRec) && isset($tempRec[$this->keyField]) && $tempRec[$this->keyField]==$primaryKey ){
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Copies values from an array into the record's field array, ignoring
	 * any keys that aren't recognized fields of the table, and tracking
	 * the primary key value when set.
	 *
	 * @param Array $valueArray Map of field name => value to bind onto the record.
	 */
	function bindArray( Array $valueArray ): Void {
		foreach($valueArray as $fldNme=>$fldVal){
			if(in_array($fldNme,$this->fieldLst)){
				if($fldNme==$this->keyField){
					$this->primaKey = $fldVal;
				}
				$this->recArray[$fldNme] = $fldVal;
			}
		}
	}

	/**
	 * Finds the first record matching a map of field => value(s), using
	 * the same filter syntax as DBList::lstSearch (IN/NOT IN for array
	 * values, LIKE/NOT LIKE for wildcard values, '!' suffix to negate),
	 * and loads its declared relations.
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 * @return mixed The matched record's field array, or false if not found.
	 */
	function findRecrd( Array $whereArray ): Array|Bool {
		$whreStr = '1=1';
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
				foreach($valsArr as $oneVal){
					if(!empty($oneVal)){
						if($valList!=''){
							$valList.=',';
						}
						$valList.="'".$this->dbConnex->dbHandle->escapeStr($oneVal)."'";
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

		$idQuery = "SELECT * FROM `$this->tableNme` WHERE $whreStr LIMIT 1;";
		$this->dbConnex->dbHandle->sendQuery($idQuery);
		$this->recArray = array();

		$this->recArray = $this->dbConnex->dbHandle->readQuery();
		if( is_array($this->recArray) ){
			if(isset($this->recArray[$this->keyField])){
				$this->primaKey = $this->recArray[$this->keyField];
			}
			$this->loadRelat();
			return $this->recArray;
		}else{
			return false;
		}
	}

	/**
	 * Persists the record: updates the existing row if one exists for the
	 * current primary key, otherwise inserts a new row. A primary key that
	 * was bound to a new record is inserted as given; without one, the
	 * database assigns it.
	 *
	 * @param Array $exludeFlds Field names to exclude from the save (in addition to the primary key, which is never updated).
	 * @return mixed The primary key on success (existing key for updates, bound key or new insert ID for inserts), or false on failure.
	 */
	function saveRecrd( Array $exludeFlds=Array() ): Mixed {
		$recExst = false;
		$sameRec = $this->exstRecrd($this->primaKey);
		if($sameRec!=false){
			$recExst = true;
		}

		$saveArr = $this->recArray;
		$updtStr = '';
		foreach( $saveArr as $fldNme => $fldVal ){
			if($fldNme==$this->keyField){
				// Can't change Prim Key
				unset($saveArr[$fldNme]);
				continue;
			}elseif(in_array($fldNme,$exludeFlds)){
				// Don't save excluded field
				unset($saveArr[$fldNme]);
				continue;
			}else{
				if($updtStr!=''){
					$updtStr.= ',';
				}
				if($recExst){
					$updtStr.="`$fldNme`=".( $fldVal===null ? 'NULL' : "'".$this->dbConnex->dbHandle->escapeStr($fldVal)."'" );
				}
			}
		}
		if($recExst){
			$svQuery = "UPDATE `$this->tableNme` SET $updtStr WHERE `$this->keyField`='".$this->dbConnex->dbHandle->escapeStr($this->primaKey)."' LIMIT 1;";
		}else{
			// A key bound to the new record is inserted with the other fields
			if( $this->primaKey!==null && $this->primaKey!=='' ){
				$saveArr = Array($this->keyField=>$this->primaKey) + $saveArr;
			}
			$insVals = array_map(function($fieldValue){
				return $fieldValue===null ? 'NULL' : "'".$this->dbConnex->dbHandle->escapeStr($fieldValue)."'";
			}, array_values($saveArr));
			$svQuery = "INSERT INTO `$this->tableNme` (`".implode('`,`',array_keys($saveArr))."`) VALUES (".implode(",",$insVals).");";
		}
		$didSave = $this->dbConnex->dbHandle->sendQuery($svQuery, true);

		if($didSave){
			if($recExst){
				return $this->primaKey;
			}else{
				return ($this->primaKey!==null && $this->primaKey!=='') ? $this->primaKey : $this->dbConnex->dbHandle->lastAdded();
			}
		}else{
			return false;
		}
	}

	/**
	 * Loads this record's parent row from another table and attaches it
	 * under the parent table's name.
	 *
	 * @param String $parntTable Name of the parent table.
	 * @param Bool   $returnVals Whether to return the updated record array.
	 * @param String $foreignKey Field on the parent table referencing this record (currently ignored due to a variable-name bug — see flagged issue).
	 * @param String $currentKey Field on this record referencing the parent (defaults to `id_<singularized table>`).
	 * @return mixed The updated record array if $returnVals is true, otherwise nothing.
	 *
	 * @todo $foreignKey parameter is never used — the body reads/writes a differently-spelled $foreignKey variable instead.
	 */
	function parentRec( String $parntTable, Bool $returnVals=false, String $foreignKey='', String $currentKey='' ): ?Array {
		$prntRec = new DBRecord( $parntTable );
		if( $foreignKey=='' ) {
			$foreignKey = $prntRec->keyField;
		}
		if( $currentKey=='' ) {
			$intName = StrKit::singlrize($this->tableNme);
			$currentKey = $intName.'_id';
		}
		$whreArr = Array( $foreignKey => $this->recArray[$currentKey] );
		$prntArr = $prntRec->findRecrd( $whreArr );
		$prntKey = ucfirst(StrKit::singlrize($parntTable));
		$this->recArray[$prntKey] = $prntArr;
		if($returnVals==true){
			return $this->recArray;
		}
		return null;
	}
	/**
	 * Loads this record's child rows from another table via a foreign key
	 * and attaches them under the child table's name.
	 *
	 * @param String $childTable Name of the child table.
	 * @param Bool   $returnVals Whether to return the updated record array.
	 * @param String $foreignKey Field on the child table referencing this record (currently ignored due to a variable-name bug — see flagged issue).
	 * @param String $currentKey Field on this record referenced by the child (defaults to this record's key field).
	 * @param String $sortFields Sort clause passed through to the child DBList.
	 * @return mixed The updated record array if $returnVals is true, otherwise nothing.
	 */
	function childList( String $childTable, Bool $returnVals=false, String $foreignKey='', String $currentKey='', String $sortFields='' ): ?Array {
		if( $foreignKey=='' ) {
			$extName = StrKit::singlrize($this->tableNme);
			$foreignKey = $extName.'_id';
		}
		if( $currentKey=='' ) {
			$currentKey = $this->keyField;
		}
		$chldKey = ucfirst($childTable);

		$subWhre = "`$foreignKey` = '".$this->dbConnex->dbHandle->escapeStr($this->recArray[$currentKey])."'";
		$subList = new DBList($childTable, null, $subWhre, $sortFields);
		$this->recArray[$chldKey] = $subList->readItems();

		if($returnVals==true){
			return $this->recArray;
		}
		return null;
	}
}
