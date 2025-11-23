<?php

namespace nyu\Data\DBStore;

/**
 * MongoDB as a store, type code 'mongo', through the mongodb extension of
 * PHP. A table is a collection, a row a document, and the key of every
 * table is the _id MongoDB gives to each document.
 *
 * What differs from the SQL types:
 *  - a collection has no fixed list of fields;
 *  - values are compared with their type: the text '2' is not the number 2;
 *  - functions, joins, groups and SQL fragments are refused;
 *  - there are no transactions: the server would need a replica set.
 */
class MongoStore implements Store {

	protected $mngrLink;
	protected $baseName;
	protected $errorMsg = false;

	// Greatest size of a binary value written: a document holds 16 MB at most, the rest of it included
	const BLOBSMAX = 15728640;

	/**
	 * Opens the connection to a MongoDB database.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user, '' when the server asks for none.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'auth' (database holding the account) and 'wait' (seconds given to the server to answer).
	 *
	 * @throws \RuntimeException When the mongodb extension is not loaded, or a setting is not valid.
	 */
	public function __construct(String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array()){
		if( !class_exists('\\MongoDB\\Driver\\Manager') ){
			throw new \RuntimeException('The mongodb extension of PHP is not loaded');
		}
		$mngrCls = '\\MongoDB\\Driver\\Manager';
		$this->baseName = $serverBase;
		$this->mngrLink = new $mngrCls( self::dsnString($serverHost, $serverUser, $serverPass, $serverBase, $moreParams) );
	}

	/**
	 * Builds the connection string of a MongoDB database.
	 *
	 * @param String $serverHost Database host, 'localhost' when empty.
	 * @param String $serverUser Database user, '' when the server asks for none.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'auth' (database holding the account) and 'wait' (seconds given to the server to answer).
	 * @return String The connection string.
	 *
	 * @throws \RuntimeException When the database name, the host, the port, the account database or the waiting time is not valid.
	 */
	public static function dsnString(String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array()): String {
		if( !preg_match('/^\w+$/D', $serverBase) ){
			throw new \RuntimeException('Invalid database name: '.$serverBase);
		}
		if( $serverHost=='' ){
			$serverHost = 'localhost';
		}
		if( !preg_match('/^[\w.\-]+$/D', $serverHost) ){
			throw new \RuntimeException('Invalid database host: '.$serverHost);
		}
		$prtNmbr = strval($moreParams['port'] ?? '');
		if( $prtNmbr!='' && !ctype_digit($prtNmbr) ){
			throw new \RuntimeException('Invalid database port: '.$prtNmbr);
		}
		$lnkText = 'mongodb://';
		if( $serverUser!='' ){
			$lnkText .= rawurlencode($serverUser).':'.rawurlencode($serverPass).'@';
		}
		$lnkText .= $serverHost.($prtNmbr!='' ? ':'.$prtNmbr : '').'/'.$serverBase;
		$optnLst = Array();
		// Database holding the account: the one of the connection when left out
		$authNme = strval($moreParams['auth'] ?? '');
		if( $authNme!='' ){
			if( !preg_match('/^\w+$/D', $authNme) ){
				throw new \RuntimeException('Invalid account database name: '.$authNme);
			}
			$optnLst[] = 'authSource='.$authNme;
		}
		// Seconds given to the server to answer, the default of the driver when left out
		$waitSec = strval($moreParams['wait'] ?? '');
		if( $waitSec!='' ){
			if( !ctype_digit($waitSec) ){
				throw new \RuntimeException('Invalid database waiting time: '.$waitSec);
			}
			$optnLst[] = 'serverSelectionTimeoutMS='.(intval($waitSec)*1000);
		}
		return $lnkText.(empty($optnLst) ? '' : '?'.implode('&', $optnLst));
	}

	/**
	 * Checks a table or field name: letters, digits and underscores. A
	 * dot leads into a field of a nested document (`address.city`).
	 *
	 * @param String $fieldLabel The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function checkName(String $fieldLabel): String {
		if( !preg_match('/^\w+(\.\w+)?$/D', $fieldLabel) ){
			throw new \RuntimeException('Invalid table or field name: '.$fieldLabel);
		}
		return $fieldLabel;
	}

	/**
	 * Describes a table. A collection sets no list of fields, and its key
	 * is always _id. A collection that does not exist yet is described the
	 * same way: MongoDB creates it with its first document.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool An empty list under 'flds', '_id' under 'pkey' and an empty list under 'blob': what is binary is told value by value.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool {
		$this->checkName($tableLabel);
		return Array('flds'=>Array(), 'pkey'=>'_id', 'blob'=>Array());
	}

	/**
	 * Writes a filter array as a MongoDB query. The conditions give the
	 * same rows as on the SQL types: a comparison leaves out the rows
	 * where the field is not set, and a match ignores the case.
	 *
	 * @param Array $whereArray Filter array, empty for every row.
	 * @return Array The query, as MongoDB reads it; an empty array for every row.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public static function makeQuery(Array $whereArray): Array {
		$signLst = Array('under'=>'$lt', 'undeq'=>'$lte', 'above'=>'$gt', 'aboeq'=>'$gte');
		$andList = Array();
		foreach(Filter::readWhere($whereArray) as $cndArr){
			$oprCode = $cndArr['opert'];
			$fldName = $cndArr['field'];
			$cndVals = $cndArr['value'];
			if( $oprCode=='every' ){
				continue;
			}elseif( $oprCode=='never' ){
				// No document meets none of nothing
				$andList[] = Array('$nor'=>Array(new \stdClass()));
			}elseif( $oprCode=='equal' ){
				$andList[] = Array($fldName=>Array('$eq'=>$cndVals));
			}elseif( $oprCode=='diffr' ){
				$andList[] = Array($fldName=>Array('$nin'=>Array($cndVals, null)));
			}elseif( $oprCode=='match' ){
				$andList[] = Array($fldName=>Array('$regex'=>self::likeRegex($cndVals), '$options'=>'is'));
			}elseif( $oprCode=='nmtch' ){
				$andList[] = Array($fldName=>Array('$not'=>Array('$regex'=>self::likeRegex($cndVals), '$options'=>'is'), '$ne'=>null));
			}elseif( $oprCode=='among' ){
				$andList[] = Array($fldName=>Array('$in'=>$cndVals));
			}elseif( $oprCode=='nmong' ){
				$andList[] = Array($fldName=>Array('$nin'=>array_merge($cndVals, Array(null))));
			}elseif( $oprCode=='unset' ){
				$andList[] = Array($fldName=>Array('$eq'=>null));
			}elseif( $oprCode=='isset' ){
				$andList[] = Array($fldName=>Array('$ne'=>null));
			}else{
				$andList[] = Array($fldName=>Array($signLst[$oprCode]=>$cndVals));
			}
		}
		return empty($andList) ? Array() : Array('$and'=>$andList);
	}

	/**
	 * Writes the text of a match as a regular expression: * stands for any
	 * text, everything else for itself.
	 *
	 * @param String $likePattrn The text, as readWhere() gives it.
	 * @return String The regular expression, covering the whole value.
	 */
	protected static function likeRegex(String $likePattrn): String {
		return '^'.str_replace('\\*', '.*', preg_quote($likePattrn)).'$';
	}

	/**
	 * Gives a key written as text the type MongoDB uses for the keys it
	 * generates, wherever it stands in a value: alone, or in a list.
	 *
	 * @param mixed $givenValue A value compared with, or stored in, _id.
	 * @return mixed The value, its 24-character hexadecimal texts turned into ObjectId.
	 */
	protected function keyValues(Mixed $givenValue): Mixed {
		if( is_array($givenValue) ){
			foreach($givenValue as $valKey=>$valItm){
				$givenValue[$valKey] = $this->keyValues($valItm);
			}
			return $givenValue;
		}
		if( is_string($givenValue) && preg_match('/^[0-9a-fA-F]{24}$/D', $givenValue) ){
			$keyClss = '\\MongoDB\\BSON\\ObjectId';
			return new $keyClss($givenValue);
		}
		return $givenValue;
	}

	/**
	 * Gives the values to be written their MongoDB type where they need
	 * one: a flux (nyu\Data\Fluxd) is written as binary.
	 *
	 * @param Array $valueArray Map of field name => value.
	 * @return Array The values, each flux turned into a binary value.
	 *
	 * @throws \RuntimeException When a flux is larger than a document may hold.
	 */
	public static function blobsSent(Array $valueArray): Array {
		foreach($valueArray as $fldNme=>$fldVal){
			if( $fldVal instanceof \nyu\Data\Fluxd ){
				$fldVal->goToStart();
				$binClss = '\\MongoDB\\BSON\\Binary';
				$valueArray[$fldNme] = new $binClss($fldVal->readWhole(self::BLOBSMAX), 0);
			}
		}
		return $valueArray;
	}

	/**
	 * Gives the binary values of a row read as fluxes, or leaves them out
	 * when they were not asked for by name.
	 *
	 * @param Array $documntRow The row, as MongoDB gives it.
	 * @param Array $fieldNames The fields asked for by name; none when every field was asked for.
	 * @param Int   $bytesLimit Greatest size accepted for a binary value.
	 * @return Array The row.
	 *
	 * @throws \RuntimeException When a binary value asked for is larger than accepted.
	 */
	public static function blobsRead(Array $documntRow, Array $fieldNames, Int $bytesLimit): Array {
		foreach($documntRow as $fldNme=>$fldVal){
			if( !is_object($fldVal) || !is_a($fldVal, 'MongoDB\\BSON\\Binary') ){
				continue;
			}
			if( !in_array($fldNme, $fieldNames) ){
				unset($documntRow[$fldNme]);
				continue;
			}
			if( strlen($fldVal->getData())>$bytesLimit ){
				throw new \RuntimeException('Binary field larger than the '.$bytesLimit.' bytes allowed: '.$fldNme);
			}
			$documntRow[$fldNme] = \nyu\Data\Fluxd::openBytes($fldVal->getData());
		}
		return $documntRow;
	}

	/**
	 * Builds the query of a filter, ready to be sent: the keys in it are
	 * given their MongoDB type.
	 *
	 * @param Array $whereArray Filter array.
	 * @param Bool  $needFilter Whether a filter matching every row is refused.
	 * @return Array The query.
	 *
	 * @throws \RuntimeException When a name is not valid, or a needed filter matches every row.
	 */
	protected function sentQuery(Array $whereArray, Bool $needFilter=false): Array {
		$fndQury = self::makeQuery($whereArray);
		if( $needFilter && empty($fndQury) ){
			throw new \RuntimeException('A change or a deletion needs a filter: a whole table is never changed at once');
		}
		foreach(($fndQury['$and'] ?? Array()) as $cndKey=>$cndArr){
			if( isset($cndArr['_id']) ){
				$fndQury['$and'][$cndKey]['_id'] = $this->keyValues($cndArr['_id']);
			}
		}
		return $fndQury;
	}

	/**
	 * Reads the rows of a table matching a filter. An offset is only
	 * applied together with a limit, as on the SQL types.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array, empty for every row.
	 * @param Array  $findOption Options of the reading: 'field', 'order', 'limit', 'offst', 'bytes'.
	 * @return Array|Bool The rows, each an array of its fields with its _id as text and its binary values asked for by name as fluxes, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, an option of the SQL types is asked for, or a binary value is larger than accepted.
	 */
	public function findItems(String $tableLabel, Array $whereArray=Array(), Array $findOption=Array()): Array|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		foreach(Array('funcs'=>'Functions', 'joins'=>'Joins', 'group'=>'Groups', 'devel'=>'Fragments written as SQL') as $optKey=>$optNme){
			if( !empty($findOption[$optKey]) ){
				throw new \RuntimeException($optNme.' are not available on a document store');
			}
		}
		$qryOpts = Array();
		// Fields: those asked for, under the name asked for
		$aliasOf = Array();
		foreach( (array)($findOption['field'] ?? Array()) as $fldSel ){
			$fldName = $this->checkName(is_array($fldSel) ? strval($fldSel['field'] ?? '') : strval($fldSel));
			$fldAlas = is_array($fldSel) ? strval($fldSel['alias'] ?? '') : '';
			$qryOpts['projection'][$fldName] = 1;
			if( $fldAlas!='' ){
				$aliasOf[$fldName] = $this->checkName($fldAlas);
			}
		}
		if( isset($qryOpts['projection']) && !isset($qryOpts['projection']['_id']) ){
			$qryOpts['projection']['_id'] = 0;
		}
		foreach( (array)($findOption['order'] ?? Array()) as $ordPar ){
			$ordPair = array_values((array)$ordPar);
			$qryOpts['sort'][$this->checkName(strval($ordPair[0] ?? ''))] = empty($ordPair[1]) ? 1 : -1;
		}
		if( isset($findOption['limit']) ){
			$qryOpts['limit'] = max(0, intval($findOption['limit']));
			if( isset($findOption['offst']) ){
				$qryOpts['skip'] = max(0, intval($findOption['offst']));
			}
		}
		$fndQury = $this->sentQuery($whereArray);
		$namedLs = array_keys(array_filter($qryOpts['projection'] ?? Array()));
		$byteMax = max(0, intval($findOption['bytes'] ?? self::BLOBSIZE));
		$fndRows = Array();
		$rowList = Array();
		try{
			$qryClss = '\\MongoDB\\Driver\\Query';
			$rowCurs = $this->mngrLink->executeQuery($this->baseName.'.'.$tableLabel, new $qryClss($fndQury, $qryOpts));
			$rowCurs->setTypeMap(Array('root'=>'array', 'document'=>'array', 'array'=>'array'));
			foreach($rowCurs as $rowDoc){
				$rowList[] = $rowDoc;
			}
		}catch( \Exception $e ){
			$this->errorMsg = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
		foreach($rowList as $rowDoc){
			// What is binary is given as a flux when it was asked for by name, and left out otherwise
			$rowDoc = self::blobsRead($rowDoc, $namedLs, $byteMax);
			// The key is given as text, like the keys of the SQL types
			if( isset($rowDoc['_id']) && is_object($rowDoc['_id']) ){
				$rowDoc['_id'] = strval($rowDoc['_id']);
			}
			foreach($aliasOf as $fldNme=>$newNme){
				if( array_key_exists($fldNme, $rowDoc) ){
					$rowDoc[$newNme] = $rowDoc[$fldNme];
					unset($rowDoc[$fldNme]);
				}
			}
			$fndRows[] = $rowDoc;
		}
		return $fndRows;
	}

	/**
	 * Adds a row to a table. Without an _id of its own, MongoDB gives it one.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $valueArray Map of field name => value of the new row.
	 * @return String|Bool The _id of the new row, as text, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid.
	 */
	public function insertRow(String $tableLabel, Array $valueArray): String|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		foreach(array_keys($valueArray) as $fldNme){
			$this->checkName(strval($fldNme));
		}
		if( isset($valueArray['_id']) ){
			$valueArray['_id'] = $this->keyValues($valueArray['_id']);
		}
		$valueArray = self::blobsSent($valueArray);
		try{
			$blkClss = '\\MongoDB\\Driver\\BulkWrite';
			$blkWrit = new $blkClss();
			$newKey = $blkWrit->insert($valueArray);
			$this->mngrLink->executeBulkWrite($this->baseName.'.'.$tableLabel, $blkWrit);
			return strval($newKey ?? $valueArray['_id'] ?? '');
		}catch( \Exception $e ){
			$this->errorMsg = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
	}

	/**
	 * Changes the rows of a table matching a filter. A filter that would
	 * match every row is refused.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array of the rows to change.
	 * @param Array  $valueArray Map of field name => new value.
	 * @return Int|Bool The number of rows the filter matched, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	public function updateRow(String $tableLabel, Array $whereArray, Array $valueArray): Int|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		$fndQury = $this->sentQuery($whereArray, true);
		foreach(array_keys($valueArray) as $fldNme){
			$this->checkName(strval($fldNme));
		}
		if( empty($valueArray) ){
			return 0;
		}
		$valueArray = self::blobsSent($valueArray);
		try{
			$blkClss = '\\MongoDB\\Driver\\BulkWrite';
			$blkWrit = new $blkClss();
			$blkWrit->update($fndQury, Array('$set'=>$valueArray), Array('multi'=>true));
			$wrtDone = $this->mngrLink->executeBulkWrite($this->baseName.'.'.$tableLabel, $blkWrit);
			return intval($wrtDone->getMatchedCount());
		}catch( \Exception $e ){
			$this->errorMsg = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
	}

	/**
	 * Deletes the rows of a table matching a filter. A filter that would
	 * match every row is refused.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array of the rows to delete.
	 * @return Int|Bool The number of rows deleted, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	public function deleteRow(String $tableLabel, Array $whereArray): Int|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		$fndQury = $this->sentQuery($whereArray, true);
		try{
			$blkClss = '\\MongoDB\\Driver\\BulkWrite';
			$blkWrit = new $blkClss();
			$blkWrit->delete($fndQury, Array('limit'=>0));
			$wrtDone = $this->mngrLink->executeBulkWrite($this->baseName.'.'.$tableLabel, $blkWrit);
			return intval($wrtDone->getDeletedCount());
		}catch( \Exception $e ){
			$this->errorMsg = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
	}

	/**
	 * Tells whether the store has transactions: this one has none.
	 *
	 * @return Bool Always false.
	 */
	public function canTrnsct(): Bool {
		return false;
	}

	/**
	 * Starts a transaction: refused, the store has none.
	 *
	 * @return Bool Always false.
	 */
	public function opnTrnsct(): Bool {
		$this->errorMsg = 'This database has no transactions';
		return false;
	}

	/**
	 * Makes the changes of a transaction permanent: there is never one open.
	 *
	 * @return Bool Always false.
	 */
	public function setTrnsct(): Bool {
		$this->errorMsg = 'This database has no transactions';
		return false;
	}

	/**
	 * Cancels the changes of a transaction: there is never one open.
	 *
	 * @return Bool Always false.
	 */
	public function remTrnsct(): Bool {
		$this->errorMsg = 'This database has no transactions';
		return false;
	}

	/**
	 * Returns the error of the last call. Each call clears the error of
	 * the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function lastError(): String|Bool {
		return $this->errorMsg;
	}

}
