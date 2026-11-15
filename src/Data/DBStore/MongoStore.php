<?php

namespace nyu\Data\DBStore;

/**
 * MongoDB as a store, type code 'mongo_db', through the mongodb extension of
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

	protected $managerObj;
	protected $hostDbName;
	protected $errMessage = false;

	// Greatest size of a binary value written: a document holds 16 MB at most, the rest of it included
	const BLOB_MAXI = 15728640;

	/**
	 * Opens the connection to a MongoDB database.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user, '' when the server asks for none.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'authBase' (database holding the account) and 'waitSecs' (seconds given to the server to answer).
	 *
	 * @throws \RuntimeException When the mongodb extension is not loaded, or a setting is not valid.
	 */
	public function __construct(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array()){
		if( !class_exists('\\MongoDB\\Driver\\Manager') ){
			throw new \RuntimeException('The mongodb extension of PHP is not loaded');
		}
		$mngrClss = '\\MongoDB\\Driver\\Manager';
		$this->hostDbName = $hostBaseText;
		$this->managerObj = new $mngrClss( self::makeDsnForHost($databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $settingsList) );
	}

	/**
	 * Builds the connection string of a MongoDB database.
	 *
	 * @param String $databaseHost Database host, 'localhost' when empty.
	 * @param String $serverUsrTxt Database user, '' when the server asks for none.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'authBase' (database holding the account) and 'waitSecs' (seconds given to the server to answer).
	 * @return String The connection string.
	 *
	 * @throws \RuntimeException When the database name, the host, the port, the account database or the waiting time is not valid.
	 */
	public static function makeDsnForHost(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array()): String {
		if( !preg_match('/^\w+$/D', $hostBaseText) ){
			throw new \RuntimeException('Invalid database name: '.$hostBaseText);
		}
		if( $databaseHost=='' ){
			$databaseHost = 'localhost';
		}
		if( !preg_match('/^[\w.\-]+$/D', $databaseHost) ){
			throw new \RuntimeException('Invalid database host: '.$databaseHost);
		}
		$partNumb = strval($settingsList['portNumb'] ?? '');
		if( $partNumb!='' && !ctype_digit($partNumb) ){
			throw new \RuntimeException('Invalid database port: '.$partNumb);
		}
		$linkText = 'mongodb://';
		if( $serverUsrTxt!='' ){
			$linkText .= rawurlencode($serverUsrTxt).':'.rawurlencode($hostPassText).'@';
		}
		$linkText .= $databaseHost.($partNumb!='' ? ':'.$partNumb : '').'/'.$hostBaseText;
		$optsList = Array();
		// Database holding the account: the one of the connection when left out
		$authName = strval($settingsList['authBase'] ?? '');
		if( $authName!='' ){
			if( !preg_match('/^\w+$/D', $authName) ){
				throw new \RuntimeException('Invalid account database name: '.$authName);
			}
			$optsList[] = 'authSource='.$authName;
		}
		// Seconds given to the server to answer, the default of the driver when left out
		$waitSecs = strval($settingsList['waitSecs'] ?? '');
		if( $waitSecs!='' ){
			if( !ctype_digit($waitSecs) ){
				throw new \RuntimeException('Invalid database waiting time: '.$waitSecs);
			}
			$optsList[] = 'serverSelectionTimeoutMS='.(intval($waitSecs)*1000);
		}
		return $linkText.(empty($optsList) ? '' : '?'.implode('&', $optsList));
	}

	/**
	 * Checks a table or field name: letters, digits and underscores. A
	 * dot leads into a field of a nested document (`address.city`).
	 *
	 * @param String $fldLabelText The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function checkNameChars(String $fldLabelText): String {
		if( !preg_match('/^\w+(\.\w+)?$/D', $fldLabelText) ){
			throw new \RuntimeException('Invalid table or field name: '.$fldLabelText);
		}
		return $fldLabelText;
	}

	/**
	 * Describes a table. A collection sets no list of fields, and its key
	 * is always _id. A collection that does not exist yet is described the
	 * same way: MongoDB creates it with its first document.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool An empty list under 'flds', '_id' under 'pkey' and an empty list under 'blob': what is binary is told value by value.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getInfoOfTable(String $tbleLabelTxt): Array|Bool {
		$this->checkNameChars($tbleLabelTxt);
		return Array('flds'=>Array(), 'pkey'=>'_id', 'blob'=>Array());
	}

	/**
	 * Writes a filter array as a MongoDB query. The conditions give the
	 * same rows as on the SQL types: a comparison leaves out the rows
	 * where the field is not set, and a match ignores the case.
	 *
	 * @param Array $filterFields Filter array, empty for every row.
	 * @return Array The query, as MongoDB reads it; an empty array for every row.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public static function makeQueryArray(Array $filterFields): Array {
		$signList = Array('under'=>'$lt', 'undeq'=>'$lte', 'above'=>'$gt', 'aboeq'=>'$gte');
		$andsList = Array();
		foreach(Filter::readWhereArray($filterFields) as $clause){
			$oprtTokn = $clause['opert'];
			$fieldNme = $clause['field'];
			$matxVals = $clause['value'];
			if( $oprtTokn=='every' ){
				continue;
			}elseif( $oprtTokn=='never' ){
				// No document meets none of nothing
				$andsList[] = Array('$nor'=>Array(new \stdClass()));
			}elseif( $oprtTokn=='equal' ){
				$andsList[] = Array($fieldNme=>Array('$eq'=>$matxVals));
			}elseif( $oprtTokn=='diffr' ){
				$andsList[] = Array($fieldNme=>Array('$nin'=>Array($matxVals, null)));
			}elseif( $oprtTokn=='match' ){
				$andsList[] = Array($fieldNme=>Array('$regex'=>self::castMatchToRgx($matxVals), '$options'=>'is'));
			}elseif( $oprtTokn=='nmtch' ){
				$andsList[] = Array($fieldNme=>Array('$not'=>Array('$regex'=>self::castMatchToRgx($matxVals), '$options'=>'is'), '$ne'=>null));
			}elseif( $oprtTokn=='among' ){
				$andsList[] = Array($fieldNme=>Array('$in'=>$matxVals));
			}elseif( $oprtTokn=='nmong' ){
				$andsList[] = Array($fieldNme=>Array('$nin'=>array_merge($matxVals, Array(null))));
			}elseif( $oprtTokn=='unset' ){
				$andsList[] = Array($fieldNme=>Array('$eq'=>null));
			}elseif( $oprtTokn=='isset' ){
				$andsList[] = Array($fieldNme=>Array('$ne'=>null));
			}else{
				$andsList[] = Array($fieldNme=>Array($signList[$oprtTokn]=>$matxVals));
			}
		}
		return empty($andsList) ? Array() : Array('$and'=>$andsList);
	}

	/**
	 * Writes a match as a regular expression: its literal pieces, each for
	 * itself, any text between two of them.
	 *
	 * @param Array $literalParts The literal pieces, as Filter::readWhereArray() gives them.
	 * @return String The regular expression, covering the whole value.
	 */
	protected static function castMatchToRgx( Array $literalParts ): String {
		$partList = Array();
		foreach( $literalParts as $oneTkn ){
			$partList[] = preg_quote( strval($oneTkn) );
		}
		return '^'.implode('.*', $partList).'$';
	}

	/**
	 * Gives a key written as text the type MongoDB uses for the keys it
	 * generates, wherever it stands in a value: alone, or in a list.
	 *
	 * @param mixed $valueAsGiven A value compared with, or stored in, _id.
	 * @return mixed The value, its 24-character hexadecimal texts turned into ObjectId.
	 */
	protected function castObjectKeys(Mixed $valueAsGiven): Mixed {
		if( is_array($valueAsGiven) ){
			foreach($valueAsGiven as $valKey=>$valItm){
				$valueAsGiven[$valKey] = $this->castObjectKeys($valItm);
			}
			return $valueAsGiven;
		}
		if( is_string($valueAsGiven) && preg_match('/^[0-9a-fA-F]{24}$/D', $valueAsGiven) ){
			$keyClass = '\\MongoDB\\BSON\\ObjectId';
			return new $keyClass($valueAsGiven);
		}
		return $valueAsGiven;
	}

	/**
	 * Gives the values to be written their MongoDB type where they need
	 * one: a flux (nyu\Data\Fluxd) is written as binary.
	 *
	 * @param Array $fieldValList Map of field name => value.
	 * @return Array The values, each flux turned into a binary value.
	 *
	 * @throws \RuntimeException When a flux is larger than a document may hold.
	 */
	public static function castBlobsForDb(Array $fieldValList): Array {
		foreach($fieldValList as $fldNme=>$fldVal){
			if( $fldVal instanceof \nyu\Data\Fluxd ){
				$fldVal->setFluxToStart();
				$bnryClss = '\\MongoDB\\BSON\\Binary';
				$fieldValList[$fldNme] = new $bnryClss($fldVal->getWholeString(self::BLOB_MAXI), 0);
			}
		}
		return $fieldValList;
	}

	/**
	 * Gives the binary values of a row read as fluxes, or leaves them out
	 * when they were not asked for by name.
	 *
	 * @param Array $documentData The row, as MongoDB gives it.
	 * @param Array $fldNamesList The fields asked for by name; none when every field was asked for.
	 * @param Int   $bytesCeiling Greatest size accepted for a binary value.
	 * @return Array The row.
	 *
	 * @throws \RuntimeException When a binary value asked for is larger than accepted.
	 */
	public static function getBlobsAsFlux(Array $documentData, Array $fldNamesList, Int $bytesCeiling): Array {
		foreach($documentData as $fldNme=>$fldVal){
			if( !is_object($fldVal) || !is_a($fldVal, 'MongoDB\\BSON\\Binary') ){
				continue;
			}
			if( !in_array($fldNme, $fldNamesList) ){
				unset($documentData[$fldNme]);
				continue;
			}
			if( strlen($fldVal->getData())>$bytesCeiling ){
				throw new \RuntimeException('Binary field larger than the '.$bytesCeiling.' bytes allowed: '.$fldNme);
			}
			$documentData[$fldNme] = \nyu\Data\Fluxd::openBytesFluxd($fldVal->getData());
		}
		return $documentData;
	}

	/**
	 * Builds the query of a filter, ready to be sent: the keys in it are
	 * given their MongoDB type.
	 *
	 * @param Array $filterFields Filter array.
	 * @param Bool  $needCriteria Whether a filter matching every row is refused.
	 * @return Array The query.
	 *
	 * @throws \RuntimeException When a name is not valid, or a needed filter matches every row.
	 */
	protected function makeMongoQuery(Array $filterFields, Bool $needCriteria=false): Array {
		$findDocu = self::makeQueryArray($filterFields);
		if( $needCriteria && empty($findDocu) ){
			throw new \RuntimeException('A change or a deletion needs a filter: a whole table is never changed at once');
		}
		foreach(($findDocu['$and'] ?? Array()) as $docKey=>$clause){
			if( isset($clause['_id']) ){
				$findDocu['$and'][$docKey]['_id'] = $this->castObjectKeys($clause['_id']);
			}
		}
		return $findDocu;
	}

	/**
	 * Reads the rows of a table matching a filter. An offset is only
	 * applied together with a limit, as on the SQL types.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array, empty for every row.
	 * @param Array  $queryOptions Options of the reading: 'field', 'order', 'limit', 'offst', 'bytes'.
	 * @return Array|Bool The rows, each an array of its fields with its _id as text and its binary values asked for by name as fluxes, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, an option of the SQL types is asked for, or a binary value is larger than accepted.
	 */
	public function findItemsArray(String $tbleLabelTxt, Array $filterFields=Array(), Array $queryOptions=Array()): Array|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		foreach(Array('funcs'=>'Functions', 'joins'=>'Joins', 'group'=>'Groups', 'devel'=>'Fragments written as SQL') as $option=>$phrase){
			if( !empty($queryOptions[$option]) ){
				throw new \RuntimeException($phrase.' are not available on a document store');
			}
		}
		$optsDict = Array();
		// Fields: those asked for, under the name asked for
		$aliasTbl = Array();
		foreach( (array)($queryOptions['field'] ?? Array()) as $column ){
			$fieldNme = $this->checkNameChars(is_array($column) ? strval($column['field'] ?? '') : strval($column));
			$fieldAls = is_array($column) ? strval($column['alias'] ?? '') : '';
			$optsDict['projection'][$fieldNme] = 1;
			if( $fieldAls!='' ){
				$aliasTbl[$fieldNme] = $this->checkNameChars($fieldAls);
			}
		}
		if( isset($optsDict['projection']) && !isset($optsDict['projection']['_id']) ){
			$optsDict['projection']['_id'] = 0;
		}
		foreach( (array)($queryOptions['order'] ?? Array()) as $sortBy ){
			$sortDefn = array_values((array)$sortBy);
			$optsDict['sort'][$this->checkNameChars(strval($sortDefn[0] ?? ''))] = empty($sortDefn[1]) ? 1 : -1;
		}
		if( isset($queryOptions['limit']) ){
			$optsDict['limit'] = max(0, intval($queryOptions['limit']));
			if( isset($queryOptions['offst']) ){
				$optsDict['skip'] = max(0, intval($queryOptions['offst']));
			}
		}
		$findDocu = $this->makeMongoQuery($filterFields);
		$namedLst = array_keys(array_filter($optsDict['projection'] ?? Array()));
		$maxBytes = max(0, intval($queryOptions['bytes'] ?? self::BLOB_SIZE));
		$matxRows = Array();
		$rowsList = Array();
		try{
			$queryCls = '\\MongoDB\\Driver\\Query';
			$rowCrsor = $this->managerObj->executeQuery($this->hostDbName.'.'.$tbleLabelTxt, new $queryCls($findDocu, $optsDict));
			$rowCrsor->setTypeMap(Array('root'=>'array', 'document'=>'array', 'array'=>'array'));
			foreach($rowCrsor as $oneDoc){
				$rowsList[] = $oneDoc;
			}
		}catch( \Exception $e ){
			$this->errMessage = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
		foreach($rowsList as $oneDoc){
			// What is binary is given as a flux when it was asked for by name, and left out otherwise
			$oneDoc = self::getBlobsAsFlux($oneDoc, $namedLst, $maxBytes);
			// The key is given as text, like the keys of the SQL types
			if( isset($oneDoc['_id']) && is_object($oneDoc['_id']) ){
				$oneDoc['_id'] = strval($oneDoc['_id']);
			}
			foreach($aliasTbl as $fldNme=>$newNme){
				if( array_key_exists($fldNme, $oneDoc) ){
					$oneDoc[$newNme] = $oneDoc[$fldNme];
					unset($oneDoc[$fldNme]);
				}
			}
			$matxRows[] = $oneDoc;
		}
		return $matxRows;
	}

	/**
	 * Adds a row to a table. Without an _id of its own, MongoDB gives it one.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $fieldValList Map of field name => value of the new row.
	 * @return String|Bool The _id of the new row, as text, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid.
	 */
	public function insertTableRow(String $tbleLabelTxt, Array $fieldValList): String|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		foreach(array_keys($fieldValList) as $fldNme){
			$this->checkNameChars(strval($fldNme));
		}
		if( isset($fieldValList['_id']) ){
			$fieldValList['_id'] = $this->castObjectKeys($fieldValList['_id']);
		}
		$fieldValList = self::castBlobsForDb($fieldValList);
		try{
			$blockCls = '\\MongoDB\\Driver\\BulkWrite';
			$bulkOprt = new $blockCls();
			$madeClef = $bulkOprt->insert($fieldValList);
			$this->managerObj->executeBulkWrite($this->hostDbName.'.'.$tbleLabelTxt, $bulkOprt);
			return strval($madeClef ?? $fieldValList['_id'] ?? '');
		}catch( \Exception $e ){
			$this->errMessage = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
	}

	/**
	 * Changes the rows of a table matching a filter. A filter that would
	 * match every row is refused.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array of the rows to change.
	 * @param Array  $fieldValList Map of field name => new value.
	 * @return Int|Bool The number of rows the filter matched, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	public function updateRowCount(String $tbleLabelTxt, Array $filterFields, Array $fieldValList): Int|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		$findDocu = $this->makeMongoQuery($filterFields, true);
		foreach(array_keys($fieldValList) as $fldNme){
			$this->checkNameChars(strval($fldNme));
		}
		if( empty($fieldValList) ){
			return 0;
		}
		$fieldValList = self::castBlobsForDb($fieldValList);
		try{
			$blockCls = '\\MongoDB\\Driver\\BulkWrite';
			$bulkOprt = new $blockCls();
			$bulkOprt->update($findDocu, Array('$set'=>$fieldValList), Array('multi'=>true));
			$writeDun = $this->managerObj->executeBulkWrite($this->hostDbName.'.'.$tbleLabelTxt, $bulkOprt);
			return intval($writeDun->getMatchedCount());
		}catch( \Exception $e ){
			$this->errMessage = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
	}

	/**
	 * Deletes the rows of a table matching a filter. A filter that would
	 * match every row is refused.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array of the rows to delete.
	 * @return Int|Bool The number of rows deleted, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	public function deleteRowCount(String $tbleLabelTxt, Array $filterFields): Int|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		$findDocu = $this->makeMongoQuery($filterFields, true);
		try{
			$blockCls = '\\MongoDB\\Driver\\BulkWrite';
			$bulkOprt = new $blockCls();
			$bulkOprt->delete($findDocu, Array('limit'=>0));
			$writeDun = $this->managerObj->executeBulkWrite($this->hostDbName.'.'.$tbleLabelTxt, $bulkOprt);
			return intval($writeDun->getDeletedCount());
		}catch( \Exception $e ){
			$this->errMessage = 'MongoDB Error: '.$e->getMessage();
			return false;
		}
	}

	/**
	 * Tells whether the store has transactions: this one has none.
	 *
	 * @return Bool Always false.
	 */
	public function canTransaction(): Bool {
		return false;
	}

	/**
	 * Starts a transaction: refused, the store has none.
	 *
	 * @return Bool Always false.
	 */
	public function addTransaction(): Bool {
		$this->errMessage = 'This database has no transactions';
		return false;
	}

	/**
	 * Makes the changes of a transaction permanent: there is never one open.
	 *
	 * @return Bool Always false.
	 */
	public function setTransaction(): Bool {
		$this->errMessage = 'This database has no transactions';
		return false;
	}

	/**
	 * Cancels the changes of a transaction: there is never one open.
	 *
	 * @return Bool Always false.
	 */
	public function dropWholeTrnsc(): Bool {
		$this->errMessage = 'This database has no transactions';
		return false;
	}

	/**
	 * Returns the error of the last call. Each call clears the error of
	 * the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function getLastErrText(): String|Bool {
		return $this->errMessage;
	}

}
