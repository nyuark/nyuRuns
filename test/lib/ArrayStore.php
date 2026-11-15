<?php

// Test-only store keeping its rows in memory. It follows the Store
// contract the way a document store does: no fixed list of fields, a key
// named _id that it gives itself, values compared with their type, no
// transactions, and the options of the SQL types refused.

namespace nyu\Data\DBStore;

class ArrayStore implements Store {

	protected $tblRowsArr = Array();
	protected $nextKeyNum = 0;
	protected $errMessage = false;

	/**
	 * Checks a table or field name.
	 *
	 * @param String $fldLabelText The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name is not a plain one.
	 */
	public function checkNameChars(String $fldLabelText): String {
		if( !preg_match('/^\w+(\.\w+)?$/D', $fldLabelText) ){
			throw new \RuntimeException('Invalid table or field name: '.$fldLabelText);
		}
		return $fldLabelText;
	}

	/**
	 * Describes a table: no list of fields, and _id as its key.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool An empty list under 'flds' and '_id' under 'pkey'.
	 */
	public function tableInfoArray(String $tbleLabelTxt): Array|Bool {
		$this->checkNameChars($tbleLabelTxt);
		return Array('flds'=>Array(), 'pkey'=>'_id', 'blob'=>Array());
	}

	/**
	 * Tells whether a row meets every condition of a filter.
	 *
	 * @param Array $tableRowList   The row.
	 * @param Array $filterFields Filter array.
	 * @return Bool True when it does.
	 */
	protected function rowMeetsFilter(Array $tableRowList, Array $filterFields): Bool {
		foreach(Filter::readWhereArray($filterFields) as $clause){
			$rowValu = $tableRowList[$clause['field']] ?? null;
			$testVal = $clause['value'];
			$hasValu = ($rowValu!==null);
			switch($clause['opert']){
				case 'every': $matched = true; break;
				case 'never': $matched = false; break;
				case 'equal': $matched = $hasValu && $rowValu===$testVal; break;
				case 'diffr': $matched = $hasValu && $rowValu!==$testVal; break;
				case 'under': $matched = $hasValu && gettype($rowValu)==gettype($testVal) && $rowValu<$testVal; break;
				case 'undeq': $matched = $hasValu && gettype($rowValu)==gettype($testVal) && $rowValu<=$testVal; break;
				case 'above': $matched = $hasValu && gettype($rowValu)==gettype($testVal) && $rowValu>$testVal; break;
				case 'aboeq': $matched = $hasValu && gettype($rowValu)==gettype($testVal) && $rowValu>=$testVal; break;
				case 'among': $matched = $hasValu && in_array($rowValu, $testVal, true); break;
				case 'nmong': $matched = $hasValu && !in_array($rowValu, $testVal, true); break;
				case 'unset': $matched = !$hasValu; break;
				case 'isset': $matched = $hasValu; break;
				default:
					$likeRgx = '/^'.implode('.*', array_map(function( Mixed $oneTkn ): String { return preg_quote(strval($oneTkn), '/'); }, (array)$testVal)).'$/is';
					$matched = is_string($rowValu) && (preg_match($likeRgx, $rowValu)==($clause['opert']=='match' ? 1 : 0));
			}
			if( !$matched ){
				return false;
			}
		}
		return true;
	}

	/**
	 * Refuses a filter that matches every row.
	 *
	 * @param Array $filterFields Filter array.
	 *
	 * @throws \RuntimeException When the filter selects nothing in particular.
	 */
	protected function needWhereArray(Array $filterFields): Void {
		$clauses = array_filter(Filter::readWhereArray($filterFields), function($conditionArr){ return $conditionArr['opert']!='every'; });
		if( empty($clauses) ){
			throw new \RuntimeException('A change or a deletion needs a filter: a whole table is never changed at once');
		}
	}

	/**
	 * Reads the rows of a table matching a filter.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array, empty for every row.
	 * @param Array  $queryOptions Options of the reading: 'field', 'order', 'limit', 'offst'.
	 * @return Array|Bool The rows.
	 *
	 * @throws \RuntimeException When a name is not valid, or an option of the SQL types is asked for.
	 */
	public function findItemsArray(String $tbleLabelTxt, Array $filterFields=Array(), Array $queryOptions=Array()): Array|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		foreach(Array('funcs'=>'Functions', 'joins'=>'Joins', 'group'=>'Groups', 'devel'=>'Fragments written as SQL') as $option=>$phrase){
			if( !empty($queryOptions[$option]) ){
				throw new \RuntimeException($phrase.' are not available on a document store');
			}
		}
		$matches = Array();
		foreach(($this->tblRowsArr[$tbleLabelTxt] ?? Array()) as $tblRow){
			if( $this->rowMeetsFilter($tblRow, $filterFields) ){
				$matches[] = $tblRow;
			}
		}
		$ordrLst = Array();
		foreach((array)($queryOptions['order'] ?? Array()) as $sortBy){
			$ordrLst[] = Array($this->checkNameChars(strval($sortBy[0] ?? '')), !empty($sortBy[1]));
		}
		if( !empty($ordrLst) ){
			usort($matches, function($firstRowData, $otherRowData) use ($ordrLst){
				foreach($ordrLst as $sortBy){
					$cmpValu = ($firstRowData[$sortBy[0]] ?? null) <=> ($otherRowData[$sortBy[0]] ?? null);
					if( $cmpValu!=0 ){
						return $sortBy[1] ? -$cmpValu : $cmpValu;
					}
				}
				return 0;
			});
		}
		if( isset($queryOptions['limit']) ){
			$matches = array_slice($matches, isset($queryOptions['offst']) ? max(0, intval($queryOptions['offst'])) : 0, max(0, intval($queryOptions['limit'])));
		}
		$fldList = (array)($queryOptions['field'] ?? Array());
		if( !empty($fldList) ){
			foreach($matches as $rowKey=>$hitRow){
				$trimRow = Array();
				foreach($fldList as $column){
					$fldName = $this->checkNameChars(is_array($column) ? strval($column['field'] ?? '') : strval($column));
					$fldAlis = is_array($column) ? strval($column['alias'] ?? '') : '';
					if( array_key_exists($fldName, $hitRow) ){
						$trimRow[$fldAlis!='' ? $fldAlis : $fldName] = $hitRow[$fldName];
					}
				}
				$matches[$rowKey] = $trimRow;
			}
		}
		return $matches;
	}

	/**
	 * Adds a row to a table, with a key of its own unless it has one.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $fieldValList Map of field name => value of the new row.
	 * @return String|Bool The key of the new row.
	 */
	public function insertTableRow(String $tbleLabelTxt, Array $fieldValList): String|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		foreach(array_keys($fieldValList) as $fldNme){
			$this->checkNameChars(strval($fldNme));
		}
		if( !isset($fieldValList['_id']) ){
			$fieldValList = Array('_id'=>'key'.(++$this->nextKeyNum)) + $fieldValList;
		}
		$this->tblRowsArr[$tbleLabelTxt][] = $fieldValList;
		return strval($fieldValList['_id']);
	}

	/**
	 * Changes the rows of a table matching a filter.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array of the rows to change.
	 * @param Array  $fieldValList Map of field name => new value.
	 * @return Int|Bool The number of rows the filter matched.
	 */
	public function updateRowCount(String $tbleLabelTxt, Array $filterFields, Array $fieldValList): Int|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		$this->needWhereArray($filterFields);
		$rwsDone = 0;
		foreach(($this->tblRowsArr[$tbleLabelTxt] ?? Array()) as $rowKey=>$tblRow){
			if( $this->rowMeetsFilter($tblRow, $filterFields) ){
				$this->tblRowsArr[$tbleLabelTxt][$rowKey] = array_merge($tblRow, $fieldValList);
				$rwsDone++;
			}
		}
		return $rwsDone;
	}

	/**
	 * Deletes the rows of a table matching a filter.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array of the rows to delete.
	 * @return Int|Bool The number of rows deleted.
	 */
	public function deleteRowCount(String $tbleLabelTxt, Array $filterFields): Int|Bool {
		$this->errMessage = false;
		$this->checkNameChars($tbleLabelTxt);
		$this->needWhereArray($filterFields);
		$rwsDone = 0;
		foreach(($this->tblRowsArr[$tbleLabelTxt] ?? Array()) as $rowKey=>$tblRow){
			if( $this->rowMeetsFilter($tblRow, $filterFields) ){
				unset($this->tblRowsArr[$tbleLabelTxt][$rowKey]);
				$rwsDone++;
			}
		}
		return $rwsDone;
	}

	/**
	 * @return Bool Always false: the store has no transactions.
	 */
	public function canTransaction(): Bool {
		return false;
	}

	/**
	 * @return Bool Always false.
	 */
	public function newTransaction(): Bool {
		return false;
	}

	/**
	 * @return Bool Always false.
	 */
	public function setTransaction(): Bool {
		return false;
	}

	/**
	 * @return Bool Always false.
	 */
	public function delTransaction(): Bool {
		return false;
	}

	/**
	 * @return String|Bool The error of the last call, or false.
	 */
	public function getLastErrText(): String|Bool {
		return $this->errMessage;
	}
}
