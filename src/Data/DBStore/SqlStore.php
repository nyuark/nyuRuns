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

	protected $baseHandle;
	// What the tables already described tell of their fields, by table name
	protected $detailList = Array();
	// Name of the database in the config, which the config cache keeps its tables under ('' for the default one)
	protected $dbLinkName = '';
	// The tables whose description came from the config cache
	protected $cachTblArr = Array();

	/**
	 * Builds the store on the handler of a database.
	 *
	 * @param Handler $dbHandlerObj Handler of the database the store works on.
	 * @param String  $linkNameText  Name of the database in the config ('' for the default one), for the descriptions of its tables the config cache keeps (TBL_CACH).
	 */
	public function __construct(Handler $dbHandlerObj, String $linkNameText = ''){
		$this->baseHandle = $dbHandlerObj;
		$this->dbLinkName = $linkNameText;
	}

	/**
	 * Checks a table or field name: letters, digits and underscores, with
	 * an optional table prefix (`table.field`).
	 *
	 * @param String $fldLabelText The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function checkNameChars(String $fldLabelText): String {
		$this->baseHandle->quoteFieldName($fldLabelText);
		return $fldLabelText;
	}

	/**
	 * Describes a table: its field names and its key field, asking the
	 * database once for each table. With TBL_CACH, a description the
	 * settings clrCache keeps stands for the question, and one asked is kept.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfoArray(String $tbleLabelTxt): Array|Bool {
		if( !isset($this->detailList[$tbleLabelTxt]) ){
			$tblInfo = ConfCache::tableInfoArray($this->dbLinkName, $tbleLabelTxt);
			if( $tblInfo!==null ){
				$this->cachTblArr[] = $tbleLabelTxt;
			}else{
				$tblInfo = $this->baseHandle->tableInfoArray($tbleLabelTxt);
				// A table that cannot be described is asked again next time
				if( !is_array($tblInfo) ){
					return false;
				}
				$tblInfo = Array('flds'=>(array)($tblInfo['flds'] ?? Array()), 'pkey'=>strval($tblInfo['pkey'] ?? ''), 'blob'=>(array)($tblInfo['blob'] ?? Array()));
				ConfCache::keepCacheTable($this->dbLinkName, $tbleLabelTxt, $tblInfo);
			}
			$this->detailList[$tbleLabelTxt] = Array('flds'=>(array)($tblInfo['flds'] ?? Array()), 'pkey'=>strval($tblInfo['pkey'] ?? ''), 'blob'=>(array)($tblInfo['blob'] ?? Array()));
		}
		return $this->detailList[$tbleLabelTxt];
	}

	/**
	 * Reads the rows of a table matching a filter, as one SELECT. Every
	 * option of the contract is honoured. A 'devel' fragment takes the
	 * place of the matching option, except 'where', which is added to the
	 * filter. An offset is only applied together with a limit. A binary
	 * field is read when it is named, and given as a flux.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array, empty for every row.
	 * @param Array  $queryOptions Options of the reading: 'field', 'order', 'limit', 'offst', 'bytes', 'funcs', 'joins', 'group', 'devel'.
	 * @return Array|Bool The rows, each an array of its fields, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, a join does not have three parts, or a binary field is larger than accepted.
	 */
	public function findItemsArray(String $tbleLabelTxt, Array $filterFields=Array(), Array $queryOptions=Array()): Array|Bool {
		$dbHndlr = $this->baseHandle;
		$devText = (array)($queryOptions['devel'] ?? Array());

		$joinLst = array_values((array)($queryOptions['joins'] ?? Array()));
		if( !empty($joinLst) && count($joinLst)!=3 ){
			throw new \RuntimeException('A join needs a table and two field names');
		}
		$tblList = empty($joinLst) ? Array($tbleLabelTxt) : Array($tbleLabelTxt, strval($joinLst[0]));

		// Selection: the fields, then the functions
		$slctLst = Array();
		// The binary fields read, by the name they are read under
		$blobOut = Array();
		foreach( (array)($queryOptions['field'] ?? Array()) as $column ){
			$fldName = is_array($column) ? strval($column['field'] ?? '') : strval($column);
			$fldAlis = is_array($column) ? strval($column['alias'] ?? '') : '';
			$slctLst[] = $dbHndlr->quoteFieldName($fldName).($fldAlis!='' ? ' AS '.$dbHndlr->quoteFieldName($fldAlis) : '');
			$fldPart = explode('.', $fldName);
			$bareNme = end($fldPart);
			foreach( (count($fldPart)>1) ? Array($fldPart[0]) : $tblList as $tblNme ){
				if( in_array($bareNme, $this->tableFieldsArr($tblNme)['blob']) ){
					$blobOut[($fldAlis!='') ? $fldAlis : $bareNme] = true;
				}
			}
		}
		// No field named: all of them, but the binary ones
		if( empty($slctLst) && empty($queryOptions['funcs']) && strval($devText['field'] ?? '')=='' ){
			$hasBlob = false;
			$wideLst = Array();
			foreach($tblList as $tblNme){
				$tblInfo = $this->tableFieldsArr($tblNme);
				$hasBlob = $hasBlob || !empty($tblInfo['blob']);
				foreach(array_diff($tblInfo['flds'], $tblInfo['blob']) as $fldNme){
					$wideLst[] = $dbHndlr->quoteFieldName((count($tblList)>1) ? $tblNme.'.'.$fldNme : $fldNme);
				}
			}
			if( $hasBlob && !empty($wideLst) ){
				$slctLst = $wideLst;
			}
		}
		foreach( (array)($queryOptions['funcs'] ?? Array()) as $fncSel ){
			$funcNme = strtolower( strval($fncSel['funct'] ?? '') );
			$funcFld = strval($fncSel['field'] ?? '*');
			$asLabel = strval($fncSel['alias'] ?? '');
			// Checked here too : a store may be called without DBLst
			if( !in_array($funcNme, Store::FUNC_LIST, true) || ($funcFld=='*' && $funcNme!='count') ){
				throw new \RuntimeException('Invalid function name: '.$funcNme);
			}
			$slctLst[] = $funcNme.'('.($funcFld=='*' ? '*' : $dbHndlr->quoteFieldName($funcFld)).')'.($asLabel!='' ? ' AS '.$dbHndlr->quoteFieldName($asLabel) : '');
		}
		$slctTxt = strval($devText['field'] ?? '');
		if( $slctTxt=='' ){
			$slctTxt = empty($slctLst) ? '*' : implode(',', $slctLst);
		}
		$findQry = 'SELECT '.$slctTxt.' FROM '.$dbHndlr->quoteFieldName($tbleLabelTxt);

		// Join
		if( !empty($joinLst) ){
			$findQry .= ' LEFT JOIN '.$dbHndlr->quoteFieldName(strval($joinLst[0])).' ON '.$dbHndlr->quoteFieldName(strval($joinLst[1])).' = '.$dbHndlr->quoteFieldName(strval($joinLst[2]));
		}

		// Filter
		$whrPart = $dbHndlr->makeWhereArray($filterFields);
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
		foreach( (array)($queryOptions['group'] ?? Array()) as $grpFld ){
			$grpList[] = $dbHndlr->quoteFieldName(strval($grpFld));
		}
		if( !empty($grpList) ){
			$findQry .= ' GROUP BY '.implode(',', $grpList);
		}

		// Order
		$ordrTxt = strval($devText['order'] ?? '');
		if( $ordrTxt=='' ){
			$ordrLst = Array();
			foreach( (array)($queryOptions['order'] ?? Array()) as $sortBy ){
				$sortDef = array_values((array)$sortBy);
				$ordrLst[] = $dbHndlr->quoteFieldName(strval($sortDef[0] ?? '')).(empty($sortDef[1]) ? ' ASC' : ' DESC');
			}
			$ordrTxt = implode(',', $ordrLst);
		}
		if( $ordrTxt!='' ){
			$findQry .= ' ORDER BY '.$ordrTxt;
		}

		// Limit
		$lmtText = strval($devText['limit'] ?? '');
		if( $lmtText=='' && isset($queryOptions['limit']) ){
			$lmtText = strval(max(0, intval($queryOptions['limit'])));
			if( isset($queryOptions['offst']) ){
				$lmtText .= ' OFFSET '.max(0, intval($queryOptions['offst']));
			}
		}
		if( $lmtText!='' ){
			$findQry .= ' LIMIT '.$lmtText;
		}

		if( !$dbHndlr->sendBuiltQuery($findQry, false, $whrPart['vals']) ){
			// A table changed since the config cache described it: the next request describes it again
			if( !empty(array_intersect($tblList, $this->cachTblArr)) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		$matches = $dbHndlr->readLastResult(true);
		if( !is_array($matches) ){
			return false;
		}
		// What is binary is given as a flux: what the database gives as a stream, and the binary fields named
		$byteMax = max(0, intval($queryOptions['bytes'] ?? self::BLOB_SIZE));
		foreach($matches as $rowIdx=>$rowArr){
			foreach($rowArr as $fldNme=>$fldVal){
				if( is_resource($fldVal) ){
					$fldVal = strval(stream_get_contents($fldVal, $byteMax+1));
				}elseif( !isset($blobOut[$fldNme]) || !is_string($fldVal) ){
					continue;
				}
				if( strlen($fldVal)>$byteMax ){
					throw new \RuntimeException('Binary field larger than the '.$byteMax.' bytes allowed: '.$fldNme);
				}
				$matches[$rowIdx][$fldNme] = Fluxd::openBytesFluxd($fldVal);
			}
		}
		return $matches;
	}

	/**
	 * Tells the fields of a table and which of them are binary, from its
	 * description (tableInfoArray()).
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array The field names under 'flds', the key field under 'pkey' and the binary ones under 'blob'; all empty when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	protected function tableFieldsArr(String $tbleLabelTxt): Array {
		$tblInfo = $this->tableInfoArray($tbleLabelTxt);
		return is_array($tblInfo) ? $tblInfo : Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
	}

	/**
	 * Adds a row to a table.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $fieldValList Map of field name => value of the new row.
	 * @return String|Bool The key the database gave to the row, '' when it gave none, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid.
	 */
	public function insertTableRow(String $tbleLabelTxt, Array $fieldValList): String|Bool {
		$dbHndlr = $this->baseHandle;
		$fldsQtd = Array();
		foreach( array_keys($fieldValList) as $fldNme ){
			$fldsQtd[] = $dbHndlr->quoteFieldName(strval($fldNme));
		}
		$addStmt = 'INSERT INTO '.$dbHndlr->quoteFieldName($tbleLabelTxt).' ('.implode(',', $fldsQtd).') VALUES ('.implode(',', array_fill(0, count($fieldValList), '?')).');';
		if( !$dbHndlr->sendBuiltQuery($addStmt, true, array_values($fieldValList)) ){
			if( in_array($tbleLabelTxt, $this->cachTblArr, true) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		$madeKey = $dbHndlr->finalAddedText();
		return ($madeKey===false) ? '' : strval($madeKey);
	}

	/**
	 * Changes the rows of a table matching a filter. A filter that would
	 * match every row is refused.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array of the rows to change.
	 * @param Array  $fieldValList Map of field name => new value.
	 * @return Int|Bool The number of rows the database reports as changed, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	public function updateRowCount(String $tbleLabelTxt, Array $filterFields, Array $fieldValList): Int|Bool {
		$dbHndlr = $this->baseHandle;
		$whrPart = $this->needWhereArray($filterFields);
		$tbleQtd = $dbHndlr->quoteFieldName($tbleLabelTxt);
		if( empty($fieldValList) ){
			return 0;
		}
		$setList = Array();
		foreach( array_keys($fieldValList) as $fldNme ){
			$setList[] = $dbHndlr->quoteFieldName(strval($fldNme)).'=?';
		}
		$updtQry = 'UPDATE '.$tbleQtd.' SET '.implode(',', $setList).' WHERE '.$whrPart['text'].';';
		if( !$dbHndlr->sendBuiltQuery($updtQry, true, array_merge(array_values($fieldValList), $whrPart['vals'])) ){
			if( in_array($tbleLabelTxt, $this->cachTblArr, true) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		return $dbHndlr->countLastWrite();
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
		$dbHndlr = $this->baseHandle;
		$whrPart = $this->needWhereArray($filterFields);
		$delStmt = 'DELETE FROM '.$dbHndlr->quoteFieldName($tbleLabelTxt).' WHERE '.$whrPart['text'].';';
		if( !$dbHndlr->sendBuiltQuery($delStmt, true, $whrPart['vals']) ){
			if( in_array($tbleLabelTxt, $this->cachTblArr, true) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		return $dbHndlr->countLastWrite();
	}

	/**
	 * Builds the WHERE clause of a change or a deletion, and refuses a
	 * filter that selects nothing in particular: an empty one, or one made
	 * only of conditions that are always true.
	 *
	 * @param Array $filterFields Filter array.
	 * @return Array The clause under 'text' and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter matches every row.
	 */
	protected function needWhereArray(Array $filterFields): Array {
		$whrPart = $this->baseHandle->makeWhereArray($filterFields);
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
	public function canTransaction(): Bool {
		return true;
	}
	
	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure.
	 */
	public function newTransaction(): Bool {
		return $this->baseHandle->newTransaction();
	}

	/**
	 * Makes the changes of the transaction permanent. On an inner level,
	 * nothing is written yet: the outermost transaction decides.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function setTransaction(): Bool {
		return $this->baseHandle->setTransaction();
	}

	/**
	 * Cancels the changes of the whole transaction, outer levels included.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function delTransaction(): Bool {
		return $this->baseHandle->delTransaction();
	}

	/**
	 * Returns the error of the last call. Each call clears the error of
	 * the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function finalErrorText(): String|Bool {
		return $this->baseHandle->finalErrorText();
	}

}
