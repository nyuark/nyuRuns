<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;

class DBRecord {
	protected $tableNme;
	protected $dbConnex;
	// Name of the configured database the table lives in: '' for the default one. A model sets its own.
	protected $baseName = '';

	protected $primaKey;
	protected $keyField;
	protected $fieldLst;
	protected $tblQuotd;
	protected $keyQuotd;

	protected $recArray;
	protected $belongTo;
	protected $children;

	/**
	 * Builds a record object bound to a table: verifies the table exists,
	 * reads its primary key and field list from the handler, and stores the
	 * declared child/parent relations for later loading.
	 *
	 * @param String $dbaseTable Name of the table this record maps to.
	 * @param Array  $childArray Child relations to load via loadRelat(), keyed by table name or as [table, fld1, fld2, fld3].
	 * @param Array  $parntArray Parent relations to load via loadRelat(), keyed by table name or as [table, fld1, fld2].
	 * @param DBCon  $handleLink Connection to use instead of the one of the database named by $baseName.
	 *
	 * @throws \RuntimeException When the table cannot be read.
	 */
	function __construct( String $dbaseTable, Array $childArray=array(), Array $parntArray=Array(), ?DBCon $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($handleLink == null){
			$this->dbConnex = DBCon::linkNamed($this->baseName);
		}else{
			$this->dbConnex = $handleLink;
		}

		// Quoting also refuses a table name that is not a plain name
		$this->tblQuotd = $this->dbConnex->dbHandle->quoteName($this->tableNme);

		// Check if table exists
		$exstQry = "SELECT 1 FROM $this->tblQuotd LIMIT 1;";
		if( !$this->dbConnex->dbHandle->sendQuery($exstQry) ){
			throw new \RuntimeException('Table "'.$this->tableNme.'" is not available: '.$this->dbConnex->dbHandle->lastError());
		}

		// The handler knows how its database describes a table
		$tblInfo = $this->dbConnex->dbHandle->tableInfo($this->tableNme);
		if( $tblInfo===false ){
			throw new \RuntimeException('Table "'.$this->tableNme.'" cannot be described: '.$this->dbConnex->dbHandle->lastError());
		}
		$this->keyField = $tblInfo['pkey'];
		$this->fieldLst = $tblInfo['flds'];
		$this->keyQuotd = ($this->keyField!='') ? $this->dbConnex->dbHandle->quoteName($this->keyField) : '';
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
		$idQuery = "SELECT * FROM $this->tblQuotd WHERE $this->keyQuotd=?;";
		$this->dbConnex->dbHandle->sendQuery($idQuery, false, Array($primaryKey));
		$this->recArray = array();

		$fndRows = $this->dbConnex->dbHandle->readQuery(true);
		$this->recArray = $fndRows[0] ?? Array();
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
		$idQuery = "SELECT * FROM $this->tblQuotd WHERE $this->keyQuotd=?;";
		$this->dbConnex->dbHandle->sendQuery($idQuery, false, Array($primaryKey));

		$fndRows = $this->dbConnex->dbHandle->readQuery(true);
		$tempRec = $fndRows[0] ?? null;
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
	 * Empties the record: the next bindArray() and saveRecrd() create a
	 * new row instead of changing the one that was loaded.
	 */
	function initRecrd(): Void {
		$this->primaKey = null;
		$this->recArray = Array();
	}

	/**
	 * Deletes the loaded record and, with it, its rows in the child tables
	 * named in the call. Nothing else is deleted: parents and unnamed
	 * children are left alone. The whole deletion is one transaction, and
	 * the record is emptied afterwards: a second call deletes nothing.
	 *
	 * @param Array $childArray Child tables whose linked rows go too: 'table', or 'table'=>'foreign key' (default `<singularized table>_id`).
	 * @return Bool True when the record was deleted, false when no record is loaded or the deletion failed.
	 *
	 * @throws \RuntimeException When a table or field name is not valid.
	 */
	function remvRecrd( Array $childArray=Array() ): Bool {
		if( $this->primaKey===null || $this->primaKey==='' || $this->keyQuotd=='' ){
			return false;
		}
		$dbHandl = $this->dbConnex->dbHandle;
		// Every name is checked before anything is deleted
		$remList = Array();
		foreach($childArray as $chlTab=>$chlFld){
			if( is_int($chlTab) ){
				$chlTab = $chlFld;
				$chlFld = StrKit::singlrize($this->tableNme).'_id';
			}
			$remList[] = 'DELETE FROM '.$dbHandl->quoteName($chlTab).' WHERE '.$dbHandl->quoteName($chlFld).'=?;';
		}
		$remList[] = "DELETE FROM $this->tblQuotd WHERE $this->keyQuotd=?;";

		if( !$dbHandl->opnTrnsct() ){
			return false;
		}
		foreach($remList as $remQry){
			if( !$dbHandl->sendQuery($remQry, true, Array($this->primaKey)) ){
				$dbHandl->remTrnsct();
				return false;
			}
		}
		// The record's own row comes last: no row deleted means it was not there
		if( $dbHandl->lastCount()<1 ){
			$dbHandl->remTrnsct();
			return false;
		}
		if( !$dbHandl->setTrnsct() ){
			return false;
		}
		$this->initRecrd();
		return true;
	}

	/**
	 * Finds the first record matching a map of field => value(s) and loads
	 * its declared relations. The filter syntax is the one of
	 * DBFilter::makeWhere(): a field name may end with '!' (negation), '<=',
	 * '>=', '<' or '>'; an array value gives IN/NOT IN, and a wildcard
	 * ('*' or '%') LIKE/NOT LIKE. 'field?'=>false gives IS NULL and
	 * 'field?'=>true IS NOT NULL; a null value matches nothing.
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 * @return mixed The matched record's field array, or false if not found.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	function findRecrd( Array $whereArray ): Array|Bool {
		$whrPart = DBFilter::makeWhere($whereArray, $this->dbConnex->dbHandle);
		$idQuery = "SELECT * FROM $this->tblQuotd".($whrPart['text']!='' ? ' WHERE '.$whrPart['text'] : '')." LIMIT 1;";
		$this->dbConnex->dbHandle->sendQuery($idQuery, false, $whrPart['vals']);
		$this->recArray = array();

		$fndRows = $this->dbConnex->dbHandle->readQuery(true);
		$this->recArray = is_array($fndRows) ? ($fndRows[0] ?? Array()) : false;
		if( is_array($this->recArray) ){
			if(isset($this->recArray[$this->keyField])){
				$this->primaKey = $this->recArray[$this->keyField];
			}
			// Relations are only loaded for a record that was found
			if( !empty($this->recArray) ){
				$this->loadRelat();
			}
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

		$saveArr = Array();
		foreach( $this->recArray as $fldNme => $fldVal ){
			// The key is never changed, and only the table's own fields are saved
			if( $fldNme==$this->keyField || in_array($fldNme, $exludeFlds) || !in_array($fldNme, $this->fieldLst) ){
				continue;
			}
			$saveArr[$fldNme] = $fldVal;
		}
		if($recExst){
			if( empty($saveArr) ){
				return $this->primaKey;
			}
			$fldsQtd = array_map(Array($this->dbConnex->dbHandle, 'quoteName'), array_keys($saveArr));
			$svQuery = "UPDATE $this->tblQuotd SET ".implode('=?,', $fldsQtd)."=? WHERE $this->keyQuotd=?;";
			$saveVal = array_merge(array_values($saveArr), Array($this->primaKey));
		}else{
			// A key bound to the new record is inserted with the other fields
			if( $this->primaKey!==null && $this->primaKey!=='' ){
				$saveArr = Array($this->keyField=>$this->primaKey) + $saveArr;
			}
			$fldsQtd = array_map(Array($this->dbConnex->dbHandle, 'quoteName'), array_keys($saveArr));
			$svQuery = "INSERT INTO $this->tblQuotd (".implode(',', $fldsQtd).") VALUES (".implode(',', array_fill(0, count($saveArr), '?')).");";
			$saveVal = array_values($saveArr);
		}
		$didSave = $this->dbConnex->dbHandle->sendQuery($svQuery, true, $saveVal);

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
		$prntRec = new DBRecord( $parntTable, Array(), Array(), $this->dbConnex );
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

		// A record without a key value has no children
		if( !isset($this->recArray[$currentKey]) ){
			$this->recArray[$chldKey] = Array();
		}else{
			$subList = new DBList($childTable, null, null, $sortFields, null, $this->dbConnex);
			$subList->lstSearch(Array($foreignKey=>$this->recArray[$currentKey]));
			$this->recArray[$chldKey] = $subList->readItems();
		}

		if($returnVals==true){
			return $this->recArray;
		}
		return null;
	}
}
