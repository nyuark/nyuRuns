<?php

namespace nyu\Data\DBStore;

use nyu\Data\DBHndlr\Handler;
use nyu\Data\Fluxd;
use nyu\Setup\ConfCache;

/**
 * The SQL types as a store: the calls of the Store contract are written
 * as SQL and sent through the handler of the database (MySQL, SQLite,
 * PostgreSQL), which gives its own quoting and wildcard operator.
 */
class SqlStore implements Store {

	protected $dbHandle;
	// What the tables already described tell of their fields, by table name
	protected $infoList = Array();
	// Name of the database in the config, which the config cache keeps its tables under ('' for the default one)
	protected $baseName = '';
	// The tables whose description came from the config cache
	protected $fromCach = Array();

	/**
	 * Builds the store on the handler of a database.
	 *
	 * @param Handler $handlerObj Handler of the database the store works on.
	 * @param String  $dbaseLabel  Name of the database in the config ('' for the default one), for the descriptions of its tables the config cache keeps (TBL_CACH).
	 */
	public function __construct(Handler $handlerObj, String $dbaseLabel = ''){
		$this->dbHandle = $handlerObj;
		$this->baseName = $dbaseLabel;
	}

	/**
	 * Checks a table or field name: letters, digits and underscores, with
	 * an optional table prefix (`table.field`).
	 *
	 * @param String $fieldLabel The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function checkName(String $fieldLabel): String {
		$this->dbHandle->quoteName($fieldLabel);
		return $fieldLabel;
	}

	/**
	 * Describes a table: its field names and its key field, asking the
	 * database once for each table. With TBL_CACH, a description the
	 * config cache keeps stands for the question, and one asked is kept.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool The field names under 'flds', the key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool {
		if( !isset($this->infoList[$tableLabel]) ){
			$tblInfo = ConfCache::tableInfo($this->baseName, $tableLabel);
			if( $tblInfo!==null ){
				$this->fromCach[] = $tableLabel;
			}else{
				$tblInfo = $this->dbHandle->tableInfo($tableLabel);
				// A table that cannot be described is asked again next time
				if( !is_array($tblInfo) ){
					return false;
				}
				$tblInfo = Array('flds'=>(array)($tblInfo['flds'] ?? Array()), 'pkey'=>strval($tblInfo['pkey'] ?? ''), 'blob'=>(array)($tblInfo['blob'] ?? Array()));
				ConfCache::keepTable($this->baseName, $tableLabel, $tblInfo);
			}
			$this->infoList[$tableLabel] = Array('flds'=>(array)($tblInfo['flds'] ?? Array()), 'pkey'=>strval($tblInfo['pkey'] ?? ''), 'blob'=>(array)($tblInfo['blob'] ?? Array()));
		}
		return $this->infoList[$tableLabel];
	}

	/**
	 * Reads the rows of a table matching a filter, as one SELECT. Every
	 * option of the contract is honoured. A 'devel' fragment takes the
	 * place of the matching option, except 'where', which is added to the
	 * filter. An offset is only applied together with a limit. A binary
	 * field is read when it is named, and given as a flux.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array, empty for every row.
	 * @param Array  $findOption Options of the reading: 'field', 'order', 'limit', 'offst', 'bytes', 'funcs', 'joins', 'group', 'devel'.
	 * @return Array|Bool The rows, each an array of its fields, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, a join does not have three parts, or a binary field is larger than accepted.
	 */
	public function findItems(String $tableLabel, Array $whereArray=Array(), Array $findOption=Array()): Array|Bool {
		$dbHandl = $this->dbHandle;
		$devText = (array)($findOption['devel'] ?? Array());

		$joinLst = array_values((array)($findOption['joins'] ?? Array()));
		if( !empty($joinLst) && count($joinLst)!=3 ){
			throw new \RuntimeException('A join needs a table and two field names');
		}
		$tblList = empty($joinLst) ? Array($tableLabel) : Array($tableLabel, strval($joinLst[0]));

		// Selection: the fields, then the functions
		$slctLst = Array();
		// The binary fields read, by the name they are read under
		$blobOut = Array();
		foreach( (array)($findOption['field'] ?? Array()) as $fldSel ){
			$fldName = is_array($fldSel) ? strval($fldSel['field'] ?? '') : strval($fldSel);
			$fldAlas = is_array($fldSel) ? strval($fldSel['alias'] ?? '') : '';
			$slctLst[] = $dbHandl->quoteName($fldName).($fldAlas!='' ? ' AS '.$dbHandl->quoteName($fldAlas) : '');
			$fldPart = explode('.', $fldName);
			$bareNme = end($fldPart);
			foreach( (count($fldPart)>1) ? Array($fldPart[0]) : $tblList as $tblNme ){
				if( in_array($bareNme, $this->tableFlds($tblNme)['blob']) ){
					$blobOut[($fldAlas!='') ? $fldAlas : $bareNme] = true;
				}
			}
		}
		// No field named: all of them, but the binary ones
		if( empty($slctLst) && empty($findOption['funcs']) && strval($devText['field'] ?? '')=='' ){
			$hasBlob = false;
			$wideLst = Array();
			foreach($tblList as $tblNme){
				$tblInfo = $this->tableFlds($tblNme);
				$hasBlob = $hasBlob || !empty($tblInfo['blob']);
				foreach(array_diff($tblInfo['flds'], $tblInfo['blob']) as $fldNme){
					$wideLst[] = $dbHandl->quoteName((count($tblList)>1) ? $tblNme.'.'.$fldNme : $fldNme);
				}
			}
			if( $hasBlob && !empty($wideLst) ){
				$slctLst = $wideLst;
			}
		}
		foreach( (array)($findOption['funcs'] ?? Array()) as $fncSel ){
			$fncName = strtolower( strval($fncSel['funct'] ?? '') );
			$fncFild = strval($fncSel['field'] ?? '*');
			$fncAlas = strval($fncSel['alias'] ?? '');
			// Checked here too : a store may be called without DBLst
			if( !in_array($fncName, Store::FUNCLIST, true) || ($fncFild=='*' && $fncName!='count') ){
				throw new \RuntimeException('Invalid function name: '.$fncName);
			}
			$slctLst[] = $fncName.'('.($fncFild=='*' ? '*' : $dbHandl->quoteName($fncFild)).')'.($fncAlas!='' ? ' AS '.$dbHandl->quoteName($fncAlas) : '');
		}
		$slctTxt = strval($devText['field'] ?? '');
		if( $slctTxt=='' ){
			$slctTxt = empty($slctLst) ? '*' : implode(',', $slctLst);
		}
		$findQry = 'SELECT '.$slctTxt.' FROM '.$dbHandl->quoteName($tableLabel);

		// Join
		if( !empty($joinLst) ){
			$findQry .= ' LEFT JOIN '.$dbHandl->quoteName(strval($joinLst[0])).' ON '.$dbHandl->quoteName(strval($joinLst[1])).' = '.$dbHandl->quoteName(strval($joinLst[2]));
		}

		// Filter
		$whrPart = $dbHandl->makeWhere($whereArray);
		$whrText = strval($devText['where'] ?? '');
		if( $whrText!='' && $whrPart['text']!='' ){
			$whrText = '('.$whrText.') AND ('.$whrPart['text'].')';
		}elseif( $whrText=='' ){
			$whrText = $whrPart['text'];
		}
		if( $whrText!='' ){
			$findQry .= ' WHERE '.$whrText;
		}

		// Group
		$grpList = Array();
		foreach( (array)($findOption['group'] ?? Array()) as $grpFld ){
			$grpList[] = $dbHandl->quoteName(strval($grpFld));
		}
		if( !empty($grpList) ){
			$findQry .= ' GROUP BY '.implode(',', $grpList);
		}

		// Order
		$ordText = strval($devText['order'] ?? '');
		if( $ordText=='' ){
			$ordList = Array();
			foreach( (array)($findOption['order'] ?? Array()) as $ordPar ){
				$ordPair = array_values((array)$ordPar);
				$ordList[] = $dbHandl->quoteName(strval($ordPair[0] ?? '')).(empty($ordPair[1]) ? ' ASC' : ' DESC');
			}
			$ordText = implode(',', $ordList);
		}
		if( $ordText!='' ){
			$findQry .= ' ORDER BY '.$ordText;
		}

		// Limit
		$limText = strval($devText['limit'] ?? '');
		if( $limText=='' && isset($findOption['limit']) ){
			$limText = strval(max(0, intval($findOption['limit'])));
			if( isset($findOption['offst']) ){
				$limText .= ' OFFSET '.max(0, intval($findOption['offst']));
			}
		}
		if( $limText!='' ){
			$findQry .= ' LIMIT '.$limText;
		}

		if( !$dbHandl->sendQuery($findQry, false, $whrPart['vals']) ){
			// A table changed since the config cache described it: the next request describes it again
			if( !empty(array_intersect($tblList, $this->fromCach)) ){
				ConfCache::dropCache();
			}
			return false;
		}
		$fndRows = $dbHandl->readQuery(true);
		if( !is_array($fndRows) ){
			return false;
		}
		// What is binary is given as a flux: what the database gives as a stream, and the binary fields named
		$byteMax = max(0, intval($findOption['bytes'] ?? self::BLOBSIZE));
		foreach($fndRows as $rowIdx=>$rowArr){
			foreach($rowArr as $fldNme=>$fldVal){
				if( is_resource($fldVal) ){
					$fldVal = strval(stream_get_contents($fldVal, $byteMax+1));
				}elseif( !isset($blobOut[$fldNme]) || !is_string($fldVal) ){
					continue;
				}
				if( strlen($fldVal)>$byteMax ){
					throw new \RuntimeException('Binary field larger than the '.$byteMax.' bytes allowed: '.$fldNme);
				}
				$fndRows[$rowIdx][$fldNme] = Fluxd::openBytes($fldVal);
			}
		}
		return $fndRows;
	}

	/**
	 * Tells the fields of a table and which of them are binary, from its
	 * description (tableInfo()).
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array The field names under 'flds', the key field under 'pkey' and the binary ones under 'blob'; all empty when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	protected function tableFlds(String $tableLabel): Array {
		$tblInfo = $this->tableInfo($tableLabel);
		return is_array($tblInfo) ? $tblInfo : Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
	}

	/**
	 * Adds a row to a table.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $valueArray Map of field name => value of the new row.
	 * @return String|Bool The key the database gave to the row, '' when it gave none, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid.
	 */
	public function insertRow(String $tableLabel, Array $valueArray): String|Bool {
		$dbHandl = $this->dbHandle;
		$fldsQtd = Array();
		foreach( array_keys($valueArray) as $fldNme ){
			$fldsQtd[] = $dbHandl->quoteName(strval($fldNme));
		}
		$addQury = 'INSERT INTO '.$dbHandl->quoteName($tableLabel).' ('.implode(',', $fldsQtd).') VALUES ('.implode(',', array_fill(0, count($valueArray), '?')).');';
		if( !$dbHandl->sendQuery($addQury, true, array_values($valueArray)) ){
			if( in_array($tableLabel, $this->fromCach, true) ){
				ConfCache::dropCache();
			}
			return false;
		}
		$newKey = $dbHandl->lastAdded();
		return ($newKey===false) ? '' : strval($newKey);
	}

	/**
	 * Changes the rows of a table matching a filter. A filter that would
	 * match every row is refused.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array of the rows to change.
	 * @param Array  $valueArray Map of field name => new value.
	 * @return Int|Bool The number of rows the database reports as changed, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	public function updateRow(String $tableLabel, Array $whereArray, Array $valueArray): Int|Bool {
		$dbHandl = $this->dbHandle;
		$whrPart = $this->needWhere($whereArray);
		$tblQutd = $dbHandl->quoteName($tableLabel);
		if( empty($valueArray) ){
			return 0;
		}
		$setList = Array();
		foreach( array_keys($valueArray) as $fldNme ){
			$setList[] = $dbHandl->quoteName(strval($fldNme)).'=?';
		}
		$updQury = 'UPDATE '.$tblQutd.' SET '.implode(',', $setList).' WHERE '.$whrPart['text'].';';
		if( !$dbHandl->sendQuery($updQury, true, array_merge(array_values($valueArray), $whrPart['vals'])) ){
			if( in_array($tableLabel, $this->fromCach, true) ){
				ConfCache::dropCache();
			}
			return false;
		}
		return $dbHandl->lastCount();
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
		$dbHandl = $this->dbHandle;
		$whrPart = $this->needWhere($whereArray);
		$remQury = 'DELETE FROM '.$dbHandl->quoteName($tableLabel).' WHERE '.$whrPart['text'].';';
		if( !$dbHandl->sendQuery($remQury, true, $whrPart['vals']) ){
			if( in_array($tableLabel, $this->fromCach, true) ){
				ConfCache::dropCache();
			}
			return false;
		}
		return $dbHandl->lastCount();
	}

	/**
	 * Builds the WHERE clause of a change or a deletion, and refuses a
	 * filter that selects nothing in particular: an empty one, or one made
	 * only of conditions that are always true.
	 *
	 * @param Array $whereArray Filter array.
	 * @return Array The clause under 'text' and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	protected function needWhere(Array $whereArray): Array {
		$whrPart = $this->dbHandle->makeWhere($whereArray);
		if( $whrPart['text']=='' || preg_match('/^1=1( AND 1=1)*$/D', $whrPart['text']) ){
			throw new \RuntimeException('A change or a deletion needs a filter: a whole table is never changed at once');
		}
		return $whrPart;
	}

	/**
	 * Tells whether the store has transactions: the SQL types do.
	 *
	 * @return Bool Always true.
	 */
	public function canTrnsct(): Bool {
		return true;
	}
	
	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure.
	 */
	public function opnTrnsct(): Bool {
		return $this->dbHandle->opnTrnsct();
	}

	/**
	 * Makes the changes of the transaction permanent. On an inner level,
	 * nothing is written yet: the outermost transaction decides.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function setTrnsct(): Bool {
		return $this->dbHandle->setTrnsct();
	}

	/**
	 * Cancels the changes of the whole transaction, outer levels included.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function remTrnsct(): Bool {
		return $this->dbHandle->remTrnsct();
	}

	/**
	 * Returns the error of the last call. Each call clears the error of
	 * the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function lastError(): String|Bool {
		return $this->dbHandle->lastError();
	}

}
