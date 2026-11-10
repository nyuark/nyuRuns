<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;
use \nyu\Tools\Logger;

/**
 * One record of a table: loaded by its key or found by its fields,
 * created, saved and removed, with its parent and child rows attached when
 * asked.
 */
class DBRec {
	protected $tableLabel;
	protected $tableDbCon;
	// Name of the configured database the table lives in: '' for the default one. A model sets its own.
	protected $dbLinkName = '';

	protected $primaryKey;
	protected $keyColName;
	protected $fieldsList;

	protected $recordData;
	protected $parentRels;
	protected $childLinks;
	// Names under which linked rows were attached to the record: never saved
	protected $linkFields = Array();

	/**
	 * Builds a record object bound to a table: verifies the table exists by describing it,
	 * reads its primary key and field list from the store, and stores the
	 * declared child/parent relations for later loading.
	 *
	 * @param String $databaseTble Name of the table this record maps to.
	 * @param Array  $childrenList Child relations to load via importRelation(), keyed by table name or as [table, fld1, fld2, fld3].
	 * @param Array  $parentTables Parent relations to load via importRelation(), keyed by table name or as [table, fld1, fld2].
	 * @param ?DBCon $dbConnection Connection to use instead of the one of the database named by $baseName.
	 *
	 * @throws \RuntimeException When the table cannot be read.
	 */
	function __construct( String $databaseTble, Array $childrenList=array(), Array $parentTables=Array(), ?DBCon $dbConnection=null ){
		$this->tableLabel = $databaseTble;
		if($dbConnection == null){
			$this->tableDbCon = DBCon::linkNamedDbCon($this->dbLinkName);
		}else{
			$this->tableDbCon = $dbConnection;
		}

		// A table name that is not a plain name is refused
		$dbStore = $this->tableDbCon->getStoreObject();
		$dbStore->checkNameChars($this->tableLabel);

		// The description of the table tells it exists: a missing table cannot be described
		$tblInfo = $dbStore->tableInfoArray($this->tableLabel);
		if( $tblInfo===false ){
			throw new \RuntimeException('Table "'.$this->tableLabel.'" is not available: '.$dbStore->finalErrorText());
		}
		$this->keyColName = $tblInfo['pkey'];
		$this->fieldsList = $tblInfo['flds'];
		if( !empty($childrenList) ){
			$this->childLinks = $childrenList;
		}
		if( !empty($parentTables) ){
			$this->parentRels = $parentTables;
		}
	}

	/**
	 * Loads a single record by its primary key and loads its declared
	 * relations.
	 *
	 * @param String $primaryValue Value of the primary key to look up.
	 * @return Array|Bool The loaded record's field array, or false if not found.
	 */
	function loadRecordList( String $primaryValue ): Array|Bool {
		$matches = $this->findRowWithKey($primaryValue);
		$this->recordData = $matches[0] ?? Array();
		if( is_array($this->recordData) && !empty($this->recordData) ){
			// The key is kept as the database gives it, not as it was asked for
			$this->primaryKey = $this->recordData[$this->keyColName] ?? $primaryValue;
			$this->importRelation();
			return $this->recordData;
		}else{
			return false;
		}
	}

	/**
	 * Loads the record's declared child and parent relations (as set up
	 * in the constructor), attaching each to the record's field array via
	 * attachChildren()/parentRecdList().
	 */
	function importRelation( ): Void {
		if( !empty($this->childLinks) ){
			foreach( $this->childLinks as $chlTab=>$chlFld){
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
				$this->attachChildren($chlArg1, $chlArg2, $chlArg3, $chlArg4, $chlArg5);
			}
		}
		if( !empty($this->parentRels) ){
			foreach( $this->parentRels as $prnTab=>$prnFld){
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
				$this->parentRecdList($prnArg1, $prnArg2, $prnArg3, $prnArg4);
			}
		}
	}

	/**
	 * Checks whether a record with the given primary key exists.
	 *
	 * @param mixed $primaryValue Value of the primary key to look up.
	 * @return Bool True if a matching record exists.
	 */
	function existsRecdFlag( Mixed $primaryValue ): Bool {
		if( $primaryValue===null || $primaryValue==='' ){
			return false;
		}
		$matches = $this->findRowWithKey($primaryValue);
		$tempRec = $matches[0] ?? null;
		if( is_array($tempRec) && isset($tempRec[$this->keyColName]) && $tempRec[$this->keyColName]==$primaryValue ){
			return true;
		}else{
			return false;
		}
	}

	/**
	 * Reads the row holding a key value, compared exactly: a key is never
	 * read as a wildcard.
	 *
	 * @param mixed $primaryValue Value of the primary key.
	 * @return Array|Bool The rows found, at most one, or false when the table has no key or cannot be read.
	 */
	protected function findRowWithKey( Mixed $primaryValue ): Array|Bool {
		if( $this->keyColName=='' ){
			return false;
		}
		return $this->tableDbCon->getStoreObject()->findItemsArray($this->tableLabel, Array($this->keyColName.'='=>$primaryValue), Array('limit'=>1));
	}

	/**
	 * Copies values from an array into the record's field array, ignoring
	 * any keys that aren't recognized fields of the table, and tracking
	 * the primary key value when set. On a database that sets no list of
	 * fields, any plain field name is taken.
	 *
	 * @param Array $fieldValList Map of field name => value to bind onto the record.
	 */
	function bindFieldArray( Array $fieldValList ): Void {
		foreach($fieldValList as $fldNme=>$fldVal){
			if( $this->isFieldOfTable(strval($fldNme)) ){
				if($fldNme==$this->keyColName){
					$this->primaryKey = $fldVal;
				}
				$this->recordData[$fldNme] = $fldVal;
			}
		}
	}

	/**
	 * Tells whether a name is a field of the table: one of its list of
	 * fields, or any plain name when the database sets no such list.
	 * What was attached by a relation is never one.
	 *
	 * @param String $fldLabelText The name.
	 * @return Bool True when the name is a field of the table.
	 */
	protected function isFieldOfTable( String $fldLabelText ): Bool {
		if( in_array($fldLabelText, $this->linkFields, true) ){
			return false;
		}
		return empty($this->fieldsList) ? (bool)preg_match('/^\w+$/D', $fldLabelText) : in_array($fldLabelText, $this->fieldsList);
	}

	/**
	 * Empties the record: the next bindFieldArray() and insertOrUpdate() create a
	 * new row instead of changing the one that was loaded.
	 */
	function initiateRecord(): Void {
		$this->primaryKey = null;
		$this->recordData = Array();
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
	 * @param Array $childrenList Child tables whose linked rows go too: 'table', or 'table'=>'foreign key' (default `<singularized table>_id`).
	 * @return Bool True when the record was deleted, false when no record is loaded or the deletion failed.
	 *
	 * @throws \RuntimeException When a table or field name is not valid, or children are named on a database without transactions.
	 */
	function removeRecdFlag( Array $childrenList=Array() ): Bool {
		if( $this->primaryKey===null || $this->primaryKey==='' || $this->keyColName=='' ){
			return false;
		}
		$dbStore = $this->tableDbCon->getStoreObject();
		// Every name is checked before anything is deleted
		$remList = Array();
		foreach($childrenList as $chlTab=>$chlFld){
			if( is_int($chlTab) ){
				$chlTab = $chlFld;
				$chlFld = StrKit::singularizeTxt($this->tableLabel).'_id';
			}
			$remList[] = Array($dbStore->checkNameChars(strval($chlTab)), $dbStore->checkNameChars(strval($chlFld)));
		}
		// The record's own row comes last
		$remList[] = Array($this->tableLabel, $this->keyColName);

		// One deletion is safe by itself; several must go together, or not at all
		$useTrns = $dbStore->canTransaction();
		if( !$useTrns && count($remList)>1 ){
			throw new \RuntimeException('Deleting a record with its children needs transactions, which this database does not have');
		}
		// A removal is traced with who asked for it, and so is one the database refused
		$accsLog = Logger::retrieveLogger('runner');
		// The key may come from the client : written as a JSON text
		$recText = $this->tableLabel.' #'.json_encode(strval($this->primaryKey), JSON_INVALID_UTF8_SUBSTITUTE);
		if( $useTrns && !$dbStore->newTransaction() ){
			$accsLog->warning( 'Record removal failed: '.$recText.Logger::getActorSuffix() );
			return false;
		}
		$rowsRem = 0;
		foreach($remList as $remTbl){
			$rowsRem = $dbStore->deleteRowCount($remTbl[0], Array($remTbl[1].'='=>$this->primaryKey));
			if( $rowsRem===false ){
				if( $useTrns ) $dbStore->delTransaction();
				$accsLog->warning( 'Record removal failed: '.$recText.Logger::getActorSuffix() );
				return false;
			}
		}
		// No row deleted for the record itself means it was not there
		if( $rowsRem<1 ){
			if( $useTrns ) $dbStore->delTransaction();
			return false;
		}
		if( $useTrns && !$dbStore->setTransaction() ){
			$accsLog->warning( 'Record removal failed: '.$recText.Logger::getActorSuffix() );
			return false;
		}
		if( count($remList)>1 ){
			$recText .= ', with its rows in '.implode(', ', array_column(array_slice($remList, 0, -1), 0));
		}
		$accsLog->notice( 'Record removed: '.$recText.Logger::getActorSuffix() );
		$this->initiateRecord();
		return true;
	}

	/**
	 * Finds the first record matching a map of field => value(s) and loads
	 * its declared relations. The filter syntax is the one of
	 * DBStore\Filter::readWhereArray(): a field name may end with '!' (negation), '<=',
	 * '>=', '<' or '>'; an array value gives IN/NOT IN, and a name ending
	 * with '~' or '!~' LIKE/NOT LIKE, '*' being any text in its value. Any
	 * other value is compared exactly. 'field?'=>false gives IS NULL and
	 * 'field?'=>true IS NOT NULL; a null value matches nothing.
	 *
	 * @param Array $filterFields Map of field name => value (or array of values) to filter by.
	 * @return Array|Bool The matched record's field array, or false if not found.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	function findRecordList( Array $filterFields ): Array|Bool {
		$matches = $this->tableDbCon->getStoreObject()->findItemsArray($this->tableLabel, $filterFields, Array('limit'=>1));
		$this->recordData = is_array($matches) ? ($matches[0] ?? Array()) : false;
		if( is_array($this->recordData) ){
			if(isset($this->recordData[$this->keyColName])){
				$this->primaryKey = $this->recordData[$this->keyColName];
			}
			// Relations are only loaded for a record that was found
			if( !empty($this->recordData) ){
				$this->importRelation();
			}
			return $this->recordData;
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
	 * @param Array $skipFieldArr Field names to exclude from the save (in addition to the primary key, which is never updated).
	 * @return mixed The primary key on success, or false on failure: for an update, the key as the database gave it; for an insert, the key the application bound, as bound, or the one the database numbered, as a number.
	 */
	function insertOrUpdate( Array $skipFieldArr=Array() ): Mixed {
		$existed = false;
		$sameRec = $this->existsRecdFlag($this->primaryKey);
		if($sameRec!=false){
			$existed = true;
		}

		$saveArr = Array();
		foreach( $this->recordData as $fldNme => $fldVal ){
			// The key is never changed, and only the table's own fields are saved
			if( $fldNme==$this->keyColName || in_array($fldNme, $skipFieldArr) || !$this->isFieldOfTable(strval($fldNme)) ){
				continue;
			}
			$saveArr[$fldNme] = $fldVal;
		}
		if($existed){
			if( empty($saveArr) ){
				return $this->primaryKey;
			}
			$rwsDone = $this->tableDbCon->getStoreObject()->updateRowCount($this->tableLabel, Array($this->keyColName.'='=>$this->primaryKey), $saveArr);
			return ($rwsDone===false) ? false : $this->primaryKey;
		}
		// A key bound to the new record is inserted with the other fields
		$withKey = ($this->primaryKey!==null && $this->primaryKey!=='');
		if( $withKey ){
			$saveArr = Array($this->keyColName=>$this->primaryKey) + $saveArr;
		}
		$madeKey = $this->tableDbCon->getStoreObject()->insertTableRow($this->tableLabel, $saveArr);
		if( $madeKey===false ){
			return false;
		}
		if( $withKey ){
			return $this->primaryKey;
		}
		// A key the database numbered is an integer column, which a reading gives as a number: the insert tells it as a text
		return ( is_string($madeKey) && ctype_digit($madeKey) ) ? intval($madeKey) : $madeKey;
	}

	/**
	 * Loads this record's parent row from another table and attaches it
	 * under the parent table's name.
	 *
	 * @param String $parentTblTxt Name of the parent table.
	 * @param Bool   $returnValues Whether to return the updated record array.
	 * @param String $foreignField Field on the parent table this record points to (defaults to the key field of the parent).
	 * @param String $ownLinkField Field on this record referencing the parent (defaults to `id_<singularized table>`).
	 * @return ?Array The updated record array if $returnValues is true, otherwise null.
	 */
	function parentRecdList( String $parentTblTxt, Bool $returnValues=false, String $foreignField='', String $ownLinkField='' ): ?Array {
		$linkRec = new DBRec( $parentTblTxt, Array(), Array(), $this->tableDbCon );
		if( $foreignField=='' ) {
			$foreignField = $linkRec->keyColName;
		}
		if( $ownLinkField=='' ) {
			$intName = StrKit::singularizeTxt($this->tableLabel);
			$ownLinkField = $intName.'_id';
		}
		$whrArry = Array( $foreignField.'=' => $this->recordData[$ownLinkField] ?? null );
		$linkRow = $linkRec->findRecordList( $whrArry );
		$prntKey = ucfirst(StrKit::singularizeTxt($parentTblTxt));
		$this->recordData[$prntKey] = $linkRow;
		$this->linkFields[] = $prntKey;
		if($returnValues==true){
			return $this->recordData;
		}
		return null;
	}
	/**
	 * Loads this record's child rows from another table via a foreign key
	 * and attaches them under the child table's name.
	 *
	 * @param String $childTbleTxt Name of the child table.
	 * @param Bool   $returnValues Whether to return the updated record array.
	 * @param String $foreignField Field on the child table referencing this record (defaults to `<singularized table>_id`).
	 * @param String $ownLinkField Field on this record referenced by the child (defaults to this record's key field).
	 * @param String $childrenSort Sort of the children, as field names with their direction ("date ASC, time DESC") or
	 *                           prefixed with '^' for a descending one ("^date, time"), through DBLst::setOrderFields().
	 * @return ?Array The updated record array if $returnValues is true, otherwise null.
	 *
	 * @throws \RuntimeException When the sort names a field that is not valid, or has another form.
	 */
	function attachChildren( String $childTbleTxt, Bool $returnValues=false, String $foreignField='', String $ownLinkField='', String $childrenSort='' ): ?Array {
		if( $foreignField=='' ) {
			$extName = StrKit::singularizeTxt($this->tableLabel);
			$foreignField = $extName.'_id';
		}
		if( $ownLinkField=='' ) {
			$ownLinkField = $this->keyColName;
		}
		$chldKey = ucfirst($childTbleTxt);
		$this->linkFields[] = $chldKey;

		// A record without a key value has no children
		if( !isset($this->recordData[$ownLinkField]) ){
			$this->recordData[$chldKey] = Array();
		}else{
			$subList = new DBLst($childTbleTxt, null, null, null, null, $this->tableDbCon);
			// The sort goes through setOrderFields(), never into the query as written : "date ASC, time DESC" or "^date, time"
			if( trim($childrenSort)!='' ){
				$sortLst = Array();
				foreach( explode(',', $childrenSort) as $srtFld ){
					$orderBy = preg_split('/\s+/', trim($srtFld));
					$dscSort = (count($orderBy)==2 && strtoupper($orderBy[1])=='DESC');
					if( count($orderBy)>2 || (count($orderBy)==2 && !in_array(strtoupper($orderBy[1]), Array('ASC', 'DESC'), true)) ){
						throw new \RuntimeException('Invalid sort of the children: '.trim($srtFld));
					}
					$sortLst[] = ($dscSort ? '^' : '').$orderBy[0];
				}
				$subList->setOrderFields($sortLst);
			}
			$subList->replaceFilters(Array($foreignField.'='=>$this->recordData[$ownLinkField]));
			$this->recordData[$chldKey] = $subList->readRowsOfList();
		}

		if($returnValues==true){
			return $this->recordData;
		}
		return null;
	}
}
