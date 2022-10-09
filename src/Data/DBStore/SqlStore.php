<?php

namespace nyu\Data\DBStore;

use nyu\Data\DBFilter;
use nyu\Data\DBHndlr\Handler;

// The SQL types as a store: the calls of the Store contract are written
// as SQL and sent through the handler of the database (MySQL, SQLite,
// PostgreSQL), which gives its own quoting and wildcard operator.
class SqlStore implements Store {

	protected $dbHandle;

	/**
	 * @param Handler $handlerObj Handler of the database the store works on.
	 */
	public function __construct(Handler $handlerObj){
		$this->dbHandle = $handlerObj;
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
	 * Describes a table: its field names and its key field.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool The field names under 'flds' and the key field under 'pkey' ('' when the table has none), or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool {
		return $this->dbHandle->tableInfo($tableLabel);
	}

	/**
	 * Reads the rows of a table matching a filter, as one SELECT. Every
	 * option of the contract is honoured. A 'devel' fragment takes the
	 * place of the matching option, except 'where', which is added to the
	 * filter. An offset is only applied together with a limit.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array, empty for every row.
	 * @param Array  $findOption Options of the reading: 'field', 'order', 'limit', 'offst', 'funcs', 'joins', 'group', 'devel'.
	 * @return Array|Bool The rows, each an array of its fields, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or a join does not have three parts.
	 */
	public function findItems(String $tableLabel, Array $whereArray=Array(), Array $findOption=Array()): Array|Bool {
		$dbHandl = $this->dbHandle;
		$devText = (array)($findOption['devel'] ?? Array());

		// Selection: the fields, then the functions
		$slctLst = Array();
		foreach( (array)($findOption['field'] ?? Array()) as $fldSel ){
			$fldName = is_array($fldSel) ? strval($fldSel['field'] ?? '') : strval($fldSel);
			$fldAlas = is_array($fldSel) ? strval($fldSel['alias'] ?? '') : '';
			$slctLst[] = $dbHandl->quoteName($fldName).($fldAlas!='' ? ' AS '.$dbHandl->quoteName($fldAlas) : '');
		}
		foreach( (array)($findOption['funcs'] ?? Array()) as $fncSel ){
			$fncName = strval($fncSel['funct'] ?? '');
			$fncFild = strval($fncSel['field'] ?? '*');
			$fncAlas = strval($fncSel['alias'] ?? '');
			if( !preg_match('/^\w+$/D', $fncName) ){
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
		$joinLst = array_values((array)($findOption['joins'] ?? Array()));
		if( !empty($joinLst) ){
			if( count($joinLst)!=3 ){
				throw new \RuntimeException('A join needs a table and two field names');
			}
			$findQry .= ' LEFT JOIN '.$dbHandl->quoteName(strval($joinLst[0])).' ON '.$dbHandl->quoteName(strval($joinLst[1])).' = '.$dbHandl->quoteName(strval($joinLst[2]));
		}

		// Filter
		$whrPart = DBFilter::makeWhere($whereArray, $dbHandl);
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
			return false;
		}
		$fndRows = $dbHandl->readQuery(true);
		return is_array($fndRows) ? $fndRows : false;
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
		$whrPart = DBFilter::makeWhere($whereArray, $this->dbHandle);
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
