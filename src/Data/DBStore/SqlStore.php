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
	protected $cachTblLst = Array();

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
	 * setting clrCache keeps stands for the question, and one asked is kept.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getInfoOfTable(String $tbleLabelTxt): Array|Bool {
		if( !isset($this->detailList[$tbleLabelTxt]) ){
			$tableDct = ConfCache::getInfoOfTable($this->dbLinkName, $tbleLabelTxt);
			if( $tableDct!==null ){
				$this->cachTblLst[] = $tbleLabelTxt;
			}else{
				$tableDct = $this->baseHandle->getInfoOfTable($tbleLabelTxt);
				// A table that cannot be described is asked again next time
				if( !is_array($tableDct) ){
					return false;
				}
				$tableDct = Array('flds'=>(array)($tableDct['flds'] ?? Array()), 'pkey'=>strval($tableDct['pkey'] ?? ''), 'blob'=>(array)($tableDct['blob'] ?? Array()));
				ConfCache::addCachedTable($this->dbLinkName, $tbleLabelTxt, $tableDct);
			}
			$this->detailList[$tbleLabelTxt] = Array('flds'=>(array)($tableDct['flds'] ?? Array()), 'pkey'=>strval($tableDct['pkey'] ?? ''), 'blob'=>(array)($tableDct['blob'] ?? Array()));
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
		$dtbsHndr = $this->baseHandle;
		$dvlpText = (array)($queryOptions['devel'] ?? Array());

		$joinList = array_values((array)($queryOptions['joins'] ?? Array()));
		if( !empty($joinList) && count($joinList)!=3 ){
			throw new \RuntimeException('A join needs a table and two field names');
		}
		$tableLst = empty($joinList) ? Array($tbleLabelTxt) : Array($tbleLabelTxt, strval($joinList[0]));

		// Selection: the fields, then the functions
		$slctList = Array();
		// The binary fields read, by the name they are read under
		$blobList = Array();
		foreach( (array)($queryOptions['field'] ?? Array()) as $oneCol ){
			$fieldNme = is_array($oneCol) ? strval($oneCol['field'] ?? '') : strval($oneCol);
			$fieldAls = is_array($oneCol) ? strval($oneCol['alias'] ?? '') : '';
			$slctList[] = $dtbsHndr->quoteFieldName($fieldNme).($fieldAls!='' ? ' AS '.$dtbsHndr->quoteFieldName($fieldAls) : '');
			$fieldPar = explode('.', $fieldNme);
			$bareName = end($fieldPar);
			foreach( (count($fieldPar)>1) ? Array($fieldPar[0]) : $tableLst as $oneTbl ){
				if( in_array($bareName, $this->getFieldsOfTbl($oneTbl)['blob']) ){
					$blobList[($fieldAls!='') ? $fieldAls : $bareName] = true;
				}
			}
		}
		// No field named: all of them, but the binary ones
		if( empty($slctList) && empty($queryOptions['funcs']) && strval($dvlpText['field'] ?? '')=='' ){
			$hasBlobs = false;
			$wideList = Array();
			foreach($tableLst as $oneTbl){
				$tableDct = $this->getFieldsOfTbl($oneTbl);
				$hasBlobs = $hasBlobs || !empty($tableDct['blob']);
				foreach(array_diff($tableDct['flds'], $tableDct['blob']) as $oneNme){
					$wideList[] = $dtbsHndr->quoteFieldName((count($tableLst)>1) ? $oneTbl.'.'.$oneNme : $oneNme);
				}
			}
			if( $hasBlobs && !empty($wideList) ){
				$slctList = $wideList;
			}
		}
		foreach( (array)($queryOptions['funcs'] ?? Array()) as $oneFnc ){
			$funcsNme = strtolower( strval($oneFnc['funct'] ?? '') );
			$funcsFld = strval($oneFnc['field'] ?? '*');
			$aliaName = strval($oneFnc['alias'] ?? '');
			// Checked here too : a store may be called without DBLst
			if( !in_array($funcsNme, Store::FUNC_LIST, true) || ($funcsFld=='*' && $funcsNme!='count') ){
				throw new \RuntimeException('Invalid function name: '.$funcsNme);
			}
			$slctList[] = $funcsNme.'('.($funcsFld=='*' ? '*' : $dtbsHndr->quoteFieldName($funcsFld)).')'.($aliaName!='' ? ' AS '.$dtbsHndr->quoteFieldName($aliaName) : '');
		}
		$slctText = strval($dvlpText['field'] ?? '');
		if( $slctText=='' ){
			$slctText = empty($slctList) ? '*' : implode(',', $slctList);
		}
		$findQuer = 'SELECT '.$slctText.' FROM '.$dtbsHndr->quoteFieldName($tbleLabelTxt);

		// Join
		if( !empty($joinList) ){
			$findQuer .= ' LEFT JOIN '.$dtbsHndr->quoteFieldName(strval($joinList[0])).' ON '.$dtbsHndr->quoteFieldName(strval($joinList[1])).' = '.$dtbsHndr->quoteFieldName(strval($joinList[2]));
		}

		// Filter
		$wherePar = $dtbsHndr->makeWhereArray($filterFields);
		$whereTxt = strval($dvlpText['where'] ?? '');
		if( $whereTxt!='' && $wherePar['text']!='' ){
			$whereTxt = '('.$whereTxt.') AND ('.$wherePar['text'].')';
		}elseif( $whereTxt=='' ){
			$whereTxt = $wherePar['text'];
		}
		if( $whereTxt!='' ){
			$findQuer .= ' WHERE '.$whereTxt;
		}

		// Group
		$groupLst = Array();
		foreach( (array)($queryOptions['group'] ?? Array()) as $oneFld ){
			$groupLst[] = $dtbsHndr->quoteFieldName(strval($oneFld));
		}
		if( !empty($groupLst) ){
			$findQuer .= ' GROUP BY '.implode(',', $groupLst);
		}

		// Order
		$orderTxt = strval($dvlpText['order'] ?? '');
		if( $orderTxt=='' ){
			$orderLst = Array();
			foreach( (array)($queryOptions['order'] ?? Array()) as $oneSrt ){
				$sortDefn = array_values((array)$oneSrt);
				$orderLst[] = $dtbsHndr->quoteFieldName(strval($sortDefn[0] ?? '')).(empty($sortDefn[1]) ? ' ASC' : ' DESC');
			}
			$orderTxt = implode(',', $orderLst);
		}
		if( $orderTxt!='' ){
			$findQuer .= ' ORDER BY '.$orderTxt;
		}

		// Limit
		$limitTxt = strval($dvlpText['limit'] ?? '');
		if( $limitTxt=='' && isset($queryOptions['limit']) ){
			$limitTxt = strval(max(0, intval($queryOptions['limit'])));
			if( isset($queryOptions['offst']) ){
				$limitTxt .= ' OFFSET '.max(0, intval($queryOptions['offst']));
			}
		}
		if( $limitTxt!='' ){
			$findQuer .= ' LIMIT '.$limitTxt;
		}

		if( !$dtbsHndr->sendBuiltQuery($findQuer, false, $wherePar['vals']) ){
			// A table changed since the config cache described it: the next request describes it again
			if( !empty(array_intersect($tableLst, $this->cachTblLst)) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		$matxRows = $dtbsHndr->readLastResult(true);
		if( !is_array($matxRows) ){
			return false;
		}
		// What is binary is given as a flux: what the database gives as a stream, and the binary fields named
		$maxBytes = max(0, intval($queryOptions['bytes'] ?? self::BLOB_SIZE));
		foreach($matxRows as $nthRow=>$oneRow){
			foreach($oneRow as $oneNme=>$oneVal){
				if( is_resource($oneVal) ){
					$oneVal = strval(stream_get_contents($oneVal, $maxBytes+1));
				}elseif( !isset($blobList[$oneNme]) || !is_string($oneVal) ){
					continue;
				}
				if( strlen($oneVal)>$maxBytes ){
					throw new \RuntimeException('Binary field larger than the '.$maxBytes.' bytes allowed: '.$oneNme);
				}
				$matxRows[$nthRow][$oneNme] = Fluxd::openBytesFluxd($oneVal);
			}
		}
		return $matxRows;
	}

	/**
	 * Tells the fields of a table and which of them are binary, from its
	 * description (getInfoOfTable()).
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array The field names under 'flds', the key field under 'pkey' and the binary ones under 'blob'; all empty when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	protected function getFieldsOfTbl(String $tbleLabelTxt): Array {
		$tableDct = $this->getInfoOfTable($tbleLabelTxt);
		return is_array($tableDct) ? $tableDct : Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
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
		$dtbsHndr = $this->baseHandle;
		$fldsQotd = Array();
		foreach( array_keys($fieldValList) as $oneNme ){
			$fldsQotd[] = $dtbsHndr->quoteFieldName(strval($oneNme));
		}
		$addSttmt = 'INSERT INTO '.$dtbsHndr->quoteFieldName($tbleLabelTxt).' ('.implode(',', $fldsQotd).') VALUES ('.implode(',', array_fill(0, count($fieldValList), '?')).');';
		if( !$dtbsHndr->sendBuiltQuery($addSttmt, true, array_values($fieldValList)) ){
			if( in_array($tbleLabelTxt, $this->cachTblLst, true) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		$madeClef = $dtbsHndr->getLastAddedId();
		return ($madeClef===false) ? '' : strval($madeClef);
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
		$dtbsHndr = $this->baseHandle;
		$wherePar = $this->makeWhereClaus($filterFields);
		$tableQtd = $dtbsHndr->quoteFieldName($tbleLabelTxt);
		if( empty($fieldValList) ){
			return 0;
		}
		$setsList = Array();
		foreach( array_keys($fieldValList) as $oneNme ){
			$setsList[] = $dtbsHndr->quoteFieldName(strval($oneNme)).'=?';
		}
		$updtQuer = 'UPDATE '.$tableQtd.' SET '.implode(',', $setsList).' WHERE '.$wherePar['text'].';';
		if( !$dtbsHndr->sendBuiltQuery($updtQuer, true, array_merge(array_values($fieldValList), $wherePar['vals'])) ){
			if( in_array($tbleLabelTxt, $this->cachTblLst, true) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		return $dtbsHndr->countLastWrite();
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
		$dtbsHndr = $this->baseHandle;
		$wherePar = $this->makeWhereClaus($filterFields);
		$remvStmt = 'DELETE FROM '.$dtbsHndr->quoteFieldName($tbleLabelTxt).' WHERE '.$wherePar['text'].';';
		if( !$dtbsHndr->sendBuiltQuery($remvStmt, true, $wherePar['vals']) ){
			if( in_array($tbleLabelTxt, $this->cachTblLst, true) ){
				ConfCache::clearConfCache();
			}
			return false;
		}
		return $dtbsHndr->countLastWrite();
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
	protected function makeWhereClaus(Array $filterFields): Array {
		$wherePar = $this->baseHandle->makeWhereArray($filterFields);
		if( $wherePar['text']=='' || preg_match('/^1=1( AND 1=1)*$/D', $wherePar['text']) ){
			throw new \RuntimeException('A change or a deletion needs a filter: a whole table is never changed at once');
		}
		return $wherePar;
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
	public function addTransaction(): Bool {
		return $this->baseHandle->addTransaction();
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
	public function dropWholeTrnsc(): Bool {
		return $this->baseHandle->dropWholeTrnsc();
	}

	/**
	 * Returns the error of the last call. Each call clears the error of
	 * the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function getLastErrText(): String|Bool {
		return $this->baseHandle->getLastErrText();
	}

}
