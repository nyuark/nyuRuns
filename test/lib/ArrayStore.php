<?php

// Test-only store keeping its rows in memory. It follows the Store
// contract the way a document store does: no fixed list of fields, a key
// named _id that it gives itself, values compared with their type, no
// transactions, and the options of the SQL types refused.

namespace nyu\Data\DBStore;

class ArrayStore implements Store {

	protected $tblRows = Array();
	protected $nextKey = 0;
	protected $errorMsg = false;

	/**
	 * Checks a table or field name.
	 *
	 * @param String $fieldLabel The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name is not a plain one.
	 */
	public function checkName(String $fieldLabel): String {
		if( !preg_match('/^\w+(\.\w+)?$/D', $fieldLabel) ){
			throw new \RuntimeException('Invalid table or field name: '.$fieldLabel);
		}
		return $fieldLabel;
	}

	/**
	 * Describes a table: no list of fields, and _id as its key.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool An empty list under 'flds' and '_id' under 'pkey'.
	 */
	public function tableInfo(String $tableLabel): Array|Bool {
		$this->checkName($tableLabel);
		return Array('flds'=>Array(), 'pkey'=>'_id', 'blob'=>Array());
	}

	/**
	 * Tells whether a row meets every condition of a filter.
	 *
	 * @param Array $tableRow   The row.
	 * @param Array $whereArray Filter array.
	 * @return Bool True when it does.
	 */
	protected function meetsAll(Array $tableRow, Array $whereArray): Bool {
		foreach(Filter::readWhere($whereArray) as $cndArr){
			$rowVal = $tableRow[$cndArr['field']] ?? null;
			$cndVal = $cndArr['value'];
			$hasVal = ($rowVal!==null);
			switch($cndArr['opert']){
				case 'every': $cndMet = true; break;
				case 'never': $cndMet = false; break;
				case 'equal': $cndMet = $hasVal && $rowVal===$cndVal; break;
				case 'diffr': $cndMet = $hasVal && $rowVal!==$cndVal; break;
				case 'under': $cndMet = $hasVal && gettype($rowVal)==gettype($cndVal) && $rowVal<$cndVal; break;
				case 'undeq': $cndMet = $hasVal && gettype($rowVal)==gettype($cndVal) && $rowVal<=$cndVal; break;
				case 'above': $cndMet = $hasVal && gettype($rowVal)==gettype($cndVal) && $rowVal>$cndVal; break;
				case 'aboeq': $cndMet = $hasVal && gettype($rowVal)==gettype($cndVal) && $rowVal>=$cndVal; break;
				case 'among': $cndMet = $hasVal && in_array($rowVal, $cndVal, true); break;
				case 'nmong': $cndMet = $hasVal && !in_array($rowVal, $cndVal, true); break;
				case 'unset': $cndMet = !$hasVal; break;
				case 'isset': $cndMet = $hasVal; break;
				default:
					$likeRgx = '/^'.str_replace('\\*', '.*', preg_quote(strval($cndVal), '/')).'$/is';
					$cndMet = is_string($rowVal) && (preg_match($likeRgx, $rowVal)==($cndArr['opert']=='match' ? 1 : 0));
			}
			if( !$cndMet ){
				return false;
			}
		}
		return true;
	}

	/**
	 * Refuses a filter that matches every row.
	 *
	 * @param Array $whereArray Filter array.
	 *
	 * @throws \RuntimeException When the filter selects nothing in particular.
	 */
	protected function needWhere(Array $whereArray): Void {
		$cndList = array_filter(Filter::readWhere($whereArray), function($cndtnArray){ return $cndtnArray['opert']!='every'; });
		if( empty($cndList) ){
			throw new \RuntimeException('A change or a deletion needs a filter: a whole table is never changed at once');
		}
	}

	/**
	 * Reads the rows of a table matching a filter.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array, empty for every row.
	 * @param Array  $findOption Options of the reading: 'field', 'order', 'limit', 'offst'.
	 * @return Array|Bool The rows.
	 *
	 * @throws \RuntimeException When a name is not valid, or an option of the SQL types is asked for.
	 */
	public function findItems(String $tableLabel, Array $whereArray=Array(), Array $findOption=Array()): Array|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		foreach(Array('funcs'=>'Functions', 'joins'=>'Joins', 'group'=>'Groups', 'devel'=>'Fragments written as SQL') as $optKey=>$optNme){
			if( !empty($findOption[$optKey]) ){
				throw new \RuntimeException($optNme.' are not available on a document store');
			}
		}
		$fndRows = Array();
		foreach(($this->tblRows[$tableLabel] ?? Array()) as $tblRow){
			if( $this->meetsAll($tblRow, $whereArray) ){
				$fndRows[] = $tblRow;
			}
		}
		$ordList = Array();
		foreach((array)($findOption['order'] ?? Array()) as $ordPar){
			$ordList[] = Array($this->checkName(strval($ordPar[0] ?? '')), !empty($ordPar[1]));
		}
		if( !empty($ordList) ){
			usort($fndRows, function($firstRows, $secndRows) use ($ordList){
				foreach($ordList as $ordPar){
					$cmpVal = ($firstRows[$ordPar[0]] ?? null) <=> ($secndRows[$ordPar[0]] ?? null);
					if( $cmpVal!=0 ){
						return $ordPar[1] ? -$cmpVal : $cmpVal;
					}
				}
				return 0;
			});
		}
		if( isset($findOption['limit']) ){
			$fndRows = array_slice($fndRows, isset($findOption['offst']) ? max(0, intval($findOption['offst'])) : 0, max(0, intval($findOption['limit'])));
		}
		$fldList = (array)($findOption['field'] ?? Array());
		if( !empty($fldList) ){
			foreach($fndRows as $rowKey=>$fndRow){
				$cutRow = Array();
				foreach($fldList as $fldSel){
					$fldName = $this->checkName(is_array($fldSel) ? strval($fldSel['field'] ?? '') : strval($fldSel));
					$fldAlas = is_array($fldSel) ? strval($fldSel['alias'] ?? '') : '';
					if( array_key_exists($fldName, $fndRow) ){
						$cutRow[$fldAlas!='' ? $fldAlas : $fldName] = $fndRow[$fldName];
					}
				}
				$fndRows[$rowKey] = $cutRow;
			}
		}
		return $fndRows;
	}

	/**
	 * Adds a row to a table, with a key of its own unless it has one.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $valueArray Map of field name => value of the new row.
	 * @return String|Bool The key of the new row.
	 */
	public function insertRow(String $tableLabel, Array $valueArray): String|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		foreach(array_keys($valueArray) as $fldNme){
			$this->checkName(strval($fldNme));
		}
		if( !isset($valueArray['_id']) ){
			$valueArray = Array('_id'=>'key'.(++$this->nextKey)) + $valueArray;
		}
		$this->tblRows[$tableLabel][] = $valueArray;
		return strval($valueArray['_id']);
	}

	/**
	 * Changes the rows of a table matching a filter.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array of the rows to change.
	 * @param Array  $valueArray Map of field name => new value.
	 * @return Int|Bool The number of rows the filter matched.
	 */
	public function updateRow(String $tableLabel, Array $whereArray, Array $valueArray): Int|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		$this->needWhere($whereArray);
		$rowsDne = 0;
		foreach(($this->tblRows[$tableLabel] ?? Array()) as $rowKey=>$tblRow){
			if( $this->meetsAll($tblRow, $whereArray) ){
				$this->tblRows[$tableLabel][$rowKey] = array_merge($tblRow, $valueArray);
				$rowsDne++;
			}
		}
		return $rowsDne;
	}

	/**
	 * Deletes the rows of a table matching a filter.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array of the rows to delete.
	 * @return Int|Bool The number of rows deleted.
	 */
	public function deleteRow(String $tableLabel, Array $whereArray): Int|Bool {
		$this->errorMsg = false;
		$this->checkName($tableLabel);
		$this->needWhere($whereArray);
		$rowsDne = 0;
		foreach(($this->tblRows[$tableLabel] ?? Array()) as $rowKey=>$tblRow){
			if( $this->meetsAll($tblRow, $whereArray) ){
				unset($this->tblRows[$tableLabel][$rowKey]);
				$rowsDne++;
			}
		}
		return $rowsDne;
	}

	/**
	 * @return Bool Always false: the store has no transactions.
	 */
	public function canTrnsct(): Bool {
		return false;
	}

	/**
	 * @return Bool Always false.
	 */
	public function opnTrnsct(): Bool {
		return false;
	}

	/**
	 * @return Bool Always false.
	 */
	public function setTrnsct(): Bool {
		return false;
	}

	/**
	 * @return Bool Always false.
	 */
	public function remTrnsct(): Bool {
		return false;
	}

	/**
	 * @return String|Bool The error of the last call, or false.
	 */
	public function lastError(): String|Bool {
		return $this->errorMsg;
	}
}
