<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;
use \nyu\Tools\Logger;

class DBRec {
	protected $tableNme;
	protected $dbConnex;
	// Name of the configured database the table lives in: '' for the default one. A model sets its own.
	protected $baseName = '';

	protected $primaKey;
	protected $keyField;
	protected $fieldLst;

	protected $recArray;
	protected $belongTo;
	protected $children;
	// Names under which linked rows were attached to the record: never saved
	protected $linkKeys = Array();

	/**
	 * Builds a record object bound to a table: verifies the table exists,
	 * reads its primary key and field list from the store, and stores the
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

		// A table name that is not a plain name is refused
		$dbStore = $this->dbConnex->dataStore();
		$dbStore->checkName($this->tableNme);

		// Check if table exists
		if( $dbStore->findItems($this->tableNme, Array(), Array('limit'=>1))===false ){
			throw new \RuntimeException('Table "'.$this->tableNme.'" is not available: '.$dbStore->lastError());
		}

		// The store knows how its database describes a table
		$tblInfo = $dbStore->tableInfo($this->tableNme);
		if( $tblInfo===false ){
			throw new \RuntimeException('Table "'.$this->tableNme.'" cannot be described: '.$dbStore->lastError());
		}
		$this->keyField = $tblInfo['pkey'];
		$this->fieldLst = $tblInfo['flds'];
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
		$fndRows = $this->rowsOfKey($primaryKey);
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
		$fndRows = $this->rowsOfKey($primaryKey);
		$tempRec = $fndRows[0] ?? null;
		if( is_array($tempRec) && isset($tempRec[$this->keyField]) && $tempRec[$this->keyField]==$primaryKey ){
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Reads the row holding a key value, compared exactly: a key is never
	 * read as a wildcard.
	 *
	 * @param mixed $primaryKey Value of the primary key.
	 * @return Array|Bool The rows found, at most one, or false when the table has no key or cannot be read.
	 */
	protected function rowsOfKey( Mixed $primaryKey ): Array|Bool {
		if( $this->keyField=='' ){
			return false;
		}
		return $this->dbConnex->dataStore()->findItems($this->tableNme, Array($this->keyField.'='=>$primaryKey), Array('limit'=>1));
	}

	/**
	 * Copies values from an array into the record's field array, ignoring
	 * any keys that aren't recognized fields of the table, and tracking
	 * the primary key value when set. On a database that sets no list of
	 * fields, any plain field name is taken.
	 *
	 * @param Array $valueArray Map of field name => value to bind onto the record.
	 */
	function bindArray( Array $valueArray ): Void {
		foreach($valueArray as $fldNme=>$fldVal){
			if( $this->ownsField(strval($fldNme)) ){
				if($fldNme==$this->keyField){
					$this->primaKey = $fldVal;
				}
				$this->recArray[$fldNme] = $fldVal;
			}
		}
	}

	/**
	 * Tells whether a name is a field of the table: one of its list of
	 * fields, or any plain name when the database sets no such list.
	 * What was attached by a relation is never one.
	 *
	 * @param String $fieldLabel The name.
	 * @return Bool True when the name is a field of the table.
	 */
	protected function ownsField( String $fieldLabel ): Bool {
		if( in_array($fieldLabel, $this->linkKeys, true) ){
			return false;
		}
		return empty($this->fieldLst) ? (bool)preg_match('/^\w+$/D', $fieldLabel) : in_array($fieldLabel, $this->fieldLst);
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
	 * the record is emptied afterwards: a second call deletes nothing. On
	 * a database without transactions, a record is only deleted alone.
	 * The removal is traced on the 'runner' log channel, signed with the
	 * user and the address of the request; one the database refused too.
	 *
	 * @param Array $childArray Child tables whose linked rows go too: 'table', or 'table'=>'foreign key' (default `<singularized table>_id`).
	 * @return Bool True when the record was deleted, false when no record is loaded or the deletion failed.
	 *
	 * @throws \RuntimeException When a table or field name is not valid, or children are named on a database without transactions.
	 */
	function remvRecrd( Array $childArray=Array() ): Bool {
		if( $this->primaKey===null || $this->primaKey==='' || $this->keyField=='' ){
			return false;
		}
		$dbStore = $this->dbConnex->dataStore();
		// Every name is checked before anything is deleted
		$remList = Array();
		foreach($childArray as $chlTab=>$chlFld){
			if( is_int($chlTab) ){
				$chlTab = $chlFld;
				$chlFld = StrKit::singlrize($this->tableNme).'_id';
			}
			$remList[] = Array($dbStore->checkName(strval($chlTab)), $dbStore->checkName(strval($chlFld)));
		}
		// The record's own row comes last
		$remList[] = Array($this->tableNme, $this->keyField);

		// One deletion is safe by itself; several must go together, or not at all
		$useTrns = $dbStore->canTrnsct();
		if( !$useTrns && count($remList)>1 ){
			throw new \RuntimeException('Deleting a record with its children needs transactions, which this database does not have');
		}
		// A removal is traced with who asked for it, and so is one the database refused
		$logInst = Logger::getLogger('runner');
		$recText = $this->tableNme.' #'.$this->primaKey;
		if( $useTrns && !$dbStore->opnTrnsct() ){
			$logInst->warning( 'Record removal failed: '.$recText.Logger::actorText() );
			return false;
		}
		$rowsRem = 0;
		foreach($remList as $remTbl){
			$rowsRem = $dbStore->deleteRow($remTbl[0], Array($remTbl[1].'='=>$this->primaKey));
			if( $rowsRem===false ){
				if( $useTrns ) $dbStore->remTrnsct();
				$logInst->warning( 'Record removal failed: '.$recText.Logger::actorText() );
				return false;
			}
		}
		// No row deleted for the record itself means it was not there
		if( $rowsRem<1 ){
			if( $useTrns ) $dbStore->remTrnsct();
			return false;
		}
		if( $useTrns && !$dbStore->setTrnsct() ){
			$logInst->warning( 'Record removal failed: '.$recText.Logger::actorText() );
			return false;
		}
		if( count($remList)>1 ){
			$recText .= ', with its rows in '.implode(', ', array_column(array_slice($remList, 0, -1), 0));
		}
		$logInst->notice( 'Record removed: '.$recText.Logger::actorText() );
		$this->initRecrd();
		return true;
	}

	/**
	 * Finds the first record matching a map of field => value(s) and loads
	 * its declared relations. The filter syntax is the one of
	 * DBStore\Filter::readWhere(): a field name may end with '!' (negation), '<=',
	 * '>=', '<' or '>'; an array value gives IN/NOT IN, and a name ending
	 * with '~' or '!~' LIKE/NOT LIKE, '*' being any text in its value. Any
	 * other value is compared exactly. 'field?'=>false gives IS NULL and
	 * 'field?'=>true IS NOT NULL; a null value matches nothing.
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 * @return mixed The matched record's field array, or false if not found.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	function findRecrd( Array $whereArray ): Array|Bool {
		$fndRows = $this->dbConnex->dataStore()->findItems($this->tableNme, $whereArray, Array('limit'=>1));
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
			if( $fldNme==$this->keyField || in_array($fldNme, $exludeFlds) || !$this->ownsField(strval($fldNme)) ){
				continue;
			}
			$saveArr[$fldNme] = $fldVal;
		}
		if($recExst){
			if( empty($saveArr) ){
				return $this->primaKey;
			}
			$rowsDne = $this->dbConnex->dataStore()->updateRow($this->tableNme, Array($this->keyField.'='=>$this->primaKey), $saveArr);
			return ($rowsDne===false) ? false : $this->primaKey;
		}
		// A key bound to the new record is inserted with the other fields
		$keyGivn = ($this->primaKey!==null && $this->primaKey!=='');
		if( $keyGivn ){
			$saveArr = Array($this->keyField=>$this->primaKey) + $saveArr;
		}
		$newKey = $this->dbConnex->dataStore()->insertRow($this->tableNme, $saveArr);
		if( $newKey===false ){
			return false;
		}
		return $keyGivn ? $this->primaKey : $newKey;
	}

	/**
	 * Loads this record's parent row from another table and attaches it
	 * under the parent table's name.
	 *
	 * @param String $parntTable Name of the parent table.
	 * @param Bool   $returnVals Whether to return the updated record array.
	 * @param String $foreignKey Field on the parent table this record points to (defaults to the key field of the parent).
	 * @param String $currentKey Field on this record referencing the parent (defaults to `id_<singularized table>`).
	 * @return mixed The updated record array if $returnVals is true, otherwise nothing.
	 */
	function parentRec( String $parntTable, Bool $returnVals=false, String $foreignKey='', String $currentKey='' ): ?Array {
		$prntRec = new DBRec( $parntTable, Array(), Array(), $this->dbConnex );
		if( $foreignKey=='' ) {
			$foreignKey = $prntRec->keyField;
		}
		if( $currentKey=='' ) {
			$intName = StrKit::singlrize($this->tableNme);
			$currentKey = $intName.'_id';
		}
		$whreArr = Array( $foreignKey.'=' => $this->recArray[$currentKey] ?? null );
		$prntArr = $prntRec->findRecrd( $whreArr );
		$prntKey = ucfirst(StrKit::singlrize($parntTable));
		$this->recArray[$prntKey] = $prntArr;
		$this->linkKeys[] = $prntKey;
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
	 * @param String $foreignKey Field on the child table referencing this record (defaults to `<singularized table>_id`).
	 * @param String $currentKey Field on this record referenced by the child (defaults to this record's key field).
	 * @param String $sortFields Sort clause passed through to the child DBLst.
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
		$this->linkKeys[] = $chldKey;

		// A record without a key value has no children
		if( !isset($this->recArray[$currentKey]) ){
			$this->recArray[$chldKey] = Array();
		}else{
			$subList = new DBLst($childTable, null, null, $sortFields, null, $this->dbConnex);
			$subList->lstSearch(Array($foreignKey.'='=>$this->recArray[$currentKey]));
			$this->recArray[$chldKey] = $subList->readItems();
		}

		if($returnVals==true){
			return $this->recArray;
		}
		return null;
	}
}
