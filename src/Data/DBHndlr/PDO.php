<?php

namespace nyu\Data\DBHndlr;

use nyu\Data\DBHndlr\Handler;

/**
 * What every PDO-based handler shares. Each database type extends it with
 * its own connection (openDBase) and table description (tableInfo).
 */
abstract Class PDO implements Handler {

	public 		$baseDriver;
	protected	$qutCharctr = '`';
	protected	$likeSyntax = 'LIKE';
	private		$builtQuery;
	private		$lastResult;
	private		$errMessage = Array();
	private		$transLevel = 0;
	
	/**
	 * Opens the PDO database connection.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $moreParamtrs Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
	 */
	public function __construct(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $moreParamtrs=Array()){
		$this->openConnection($databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $moreParamtrs);
	}
	
	/**
	 * Sends a built SQL query to the database, either as a query (default,
	 * for SELECTs) or as an exec (for INSERT/UPDATE/DELETE).
	 *
	 * @param String $createdQuery The SQL query to execute.
	 * @param Bool   $isWriteQuery Whether to run the query via PDO::exec() instead of PDO::query().
	 * @param Array  $valuesToBind Values bound, in order, to the ? placeholders of the query.
	 * @return Bool True on success, false on failure (error recorded in finalErrorText()).
	 */
	public function sendBuiltQuery(String $createdQuery, Bool $isWriteQuery=false, Array $valuesToBind=Array()): Bool {
		try{
			// The error and the result of the previous query do not outlive it
			$this->errMessage = Array();
			$this->lastResult = false;
			$this->builtQuery = $createdQuery;
			$errrSrc = $this->baseDriver;
			if( !empty($valuesToBind) ){
				$stmtObj = $this->baseDriver->prepare($this->builtQuery);
				if( $stmtObj!==false && $this->execBoundQuery($stmtObj, array_values($valuesToBind)) ){
					$stmtObj->setFetchMode(\PDO::FETCH_ASSOC);
					$this->lastResult = $isWriteQuery ? $stmtObj->rowCount() : $stmtObj;
				}else{
					$this->lastResult = false;
					if( $stmtObj!==false ) $errrSrc = $stmtObj;
				}
			}elseif($isWriteQuery){
				$this->lastResult = $this->baseDriver->exec($this->builtQuery);
			}else{
				$this->lastResult = $this->baseDriver->query($this->builtQuery, \PDO::FETCH_ASSOC);
			}
			if($this->lastResult===false){
				$this->errMessage[] = "PDO Error: ".$errrSrc->errorInfo()[2];
				return false;
			}else{
				return true;
			}
		}catch(\PDOException  $e ){
			$this->errMessage[] = "PDO Error: ".$e->getMessage();
			return false;
		}
	}
	/**
	 * Runs a prepared query with its values. A value given as a flux
	 * (nyu\Data\Fluxd) is bound as binary, which is what a binary field of
	 * every database type asks for; without one, the values are bound the
	 * usual way.
	 *
	 * @param \PDOStatement $preparedStmt The prepared query.
	 * @param Array         $valuesToBind Its values, in order.
	 * @return Bool True when the query ran.
	 */
	protected function execBoundQuery(\PDOStatement $preparedStmt, Array $valuesToBind): Bool {
		$hasBlob = false;
		foreach($valuesToBind as $bndVal){
			$hasBlob = $hasBlob || ($bndVal instanceof \nyu\Data\Fluxd);
		}
		if( !$hasBlob ){
			return $preparedStmt->execute($valuesToBind);
		}
		foreach($valuesToBind as $bndIdx=>$bndVal){
			if( $bndVal instanceof \nyu\Data\Fluxd ){
				$bndVal->rewindToOrigin();
				$preparedStmt->bindValue($bndIdx+1, $bndVal->getWholeString(), \PDO::PARAM_LOB);
			}else{
				$preparedStmt->bindValue($bndIdx+1, $bndVal);
			}
		}
		return $preparedStmt->execute();
	}

	/**
	 * Reads the results of the last sent query, collapsing a single-row
	 * single-column result down to a scalar and a single-row multi-column
	 * result down to that one row, unless a list of rows is asked for.
	 *
	 * @param Bool $alwaysAsList Whether to always return a list of rows, even for a single row.
	 * @return mixed Array of rows/row/scalar value, or false on error.
	 */
	public function readLastResult( Bool $alwaysAsList=false ): Mixed {
		if(isset($this->lastResult) && $this->lastResult!=false ){
			$rowData = Array();
			if(is_array($this->lastResult) || is_object($this->lastResult)){
				foreach($this->lastResult as $resRow){
					$rowData[] = $resRow;
				}
				if($alwaysAsList){
					return $rowData;
				}
				if(count($rowData)==0 || $rowData[0]==null){
					return $rowData;
				}
				if( count($rowData)>1 ){
					return $rowData;
				}else{
					if( count($rowData[0])>1 ){
						return $rowData[0];
					}else{
						return current($rowData[0]);
					}
				}
			}else{
				return $rowData;
			}
		}else{
			// Throw errors
			if( !isset($this->builtQuery) ){
				// Empty query
				$this->errMessage[] = "Empty query cannot return results";
			}else{
				// Nothing to read is not an error; a failed query is
				return (count($this->errMessage)<1) ? Array() : false;
			}
			return false;
		}
	}

	/**
	 * Reads the connection options every PDO-based handler shares: 'wait',
	 * the seconds given to the database to answer.
	 *
	 * @param Array $moreParamtrs Optional settings of the connection.
	 * @return Array The PDO options, empty when none is set.
	 *
	 * @throws \RuntimeException When the waiting time is not a number.
	 */
	protected function pdoOptionArray(Array $moreParamtrs): Array {
		$waitSec = strval($moreParamtrs['wait'] ?? '');
		if( $waitSec=='' ){
			return Array();
		}
		if( !ctype_digit($waitSec) ){
			throw new \RuntimeException('Invalid database waiting time: '.$waitSec);
		}
		return Array(\PDO::ATTR_TIMEOUT=>intval($waitSec));
	}
	
	/**
	 * Quotes a table or field name for inclusion in a query. Only letters,
	 * digits and underscores are accepted, with an optional table prefix
	 * (`table.field`). The quote character is the one of the database type.
	 *
	 * @param String $fldLabelText The table or field name.
	 * @return String The quoted name.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function quoteFieldName(String $fldLabelText): String {
		if( !preg_match('/^\w+(\.\w+)?$/D', $fldLabelText) ){
			throw new \RuntimeException('Invalid table or field name: '.$fldLabelText);
		}
		return $this->qutCharctr.str_replace('.', $this->qutCharctr.'.'.$this->qutCharctr, $fldLabelText).$this->qutCharctr;
	}

	/**
	 * Builds the WHERE part of a query from a filter array, read by
	 * DBStore\Filter::readWhereArray(). Names are quoted, and matches written,
	 * the way this database type expects them.
	 *
	 * @param Array $filterFields Map of field name => value (or array of values) to filter by.
	 * @return Array The clause under 'text', with ? placeholders, and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public function makeWhereArray( Array $filterFields ): Array {
		$signLst = Array('equal'=>'=', 'diffr'=>'!=', 'under'=>'<', 'undeq'=>'<=', 'above'=>'>', 'aboeq'=>'>=');
		$whrText = Array();
		$filters = Array();
		foreach(\nyu\Data\DBStore\Filter::readWhereArray($filterFields) as $clause){
			$opToken = $clause['opert'];
			$safeFld = ($clause['field']!='') ? $this->quoteFieldName($clause['field']) : '';
			if( $opToken=='never' || $opToken=='every' ){
				$whrText[] = ($opToken=='every') ? '1=1' : '1=0';
			}elseif( $opToken=='unset' || $opToken=='isset' ){
				$whrText[] = $safeFld.($opToken=='isset' ? ' IS NOT NULL' : ' IS NULL');
			}elseif( $opToken=='among' || $opToken=='nmong' ){
				$whrText[] = $safeFld.($opToken=='nmong' ? ' NOT IN (' : ' IN (').implode(',', array_fill(0, count($clause['value']), '?')).')';
				$filters = array_merge($filters, $clause['value']);
			}elseif( $opToken=='match' || $opToken=='nmtch' ){
				$whrText[] = $safeFld.' '.($opToken=='nmtch' ? 'NOT ' : '').$this->getLikeKeyword()." ? ESCAPE '!'";
				$filters[] = $this->writeLikeValue( strval($clause['value']) );
			}else{
				$whrText[] = $safeFld.' '.$signLst[$opToken].' ?';
				$filters[] = $clause['value'];
			}
		}
		return Array('text'=>implode(' AND ', $whrText), 'vals'=>$filters);
	}
	
	/**
	 * Writes the text of a match as the pattern of a wildcard search: its
	 * * becomes the wildcard of the database, and the characters the
	 * database would read as wildcards (%, _) are escaped with '!', the
	 * escape character the search is written with.
	 *
	 * @param String $wildcardText The text, in which * is any text.
	 * @return String The pattern.
	 */
	protected function writeLikeValue( String $wildcardText ): String {
		return str_replace( '*', '%', strtr($wildcardText, Array('!'=>'!!', '%'=>'!%', '_'=>'!_')) );
	}

	/**
	 * Returns the operator of a wildcard search that ignores the case.
	 *
	 * @return String The operator: LIKE, or what the database type uses in its place.
	 */
	public function getLikeKeyword(): String {
		return $this->likeSyntax;
	}
	
	/**
	 * Returns the error of the last query or transaction call. Each of
	 * those calls clears the error of the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function finalErrorText(): String|Bool {
		return end( $this->errMessage );
	}
	
	/**
	 * Returns the auto-increment ID of the last inserted row.
	 *
	 * @return String|Bool The last insert ID.
	 */
	public function finalAddedText(): String|Bool {
		return $this->baseDriver->lastInsertId();
	}

	/**
	 * Returns the number of rows changed by the last write sent with
	 * sendBuiltQuery() in exec mode.
	 *
	 * @return Int The number of affected rows, 0 when the last query was not a write.
	 */
	public function countLastWrite(): Int {
		return is_int($this->lastResult) ? $this->lastResult : 0;
	}

	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure.
	 */
	public function opnTransaction(): Bool {
		$this->errMessage = Array();
		if( $this->transLevel>0 ){
			$this->transLevel++;
			return true;
		}
		try{
			if( !$this->baseDriver->beginTransaction() ){
				return false;
			}
		}catch(\PDOException $e ){
			$this->errMessage[] = "PDO Error: ".$e->getMessage();
			return false;
		}
		$this->transLevel = 1;
		return true;
	}

	/**
	 * Makes the changes of the transaction permanent. On an inner level,
	 * nothing is written yet: the outermost transaction decides.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open (never opened, or already removed).
	 */
	public function setTransaction(): Bool {
		$this->errMessage = Array();
		if( $this->transLevel==0 ){
			return false;
		}
		if( --$this->transLevel>0 ){
			// An outer transaction is still open: it decides
			return true;
		}
		try{
			return $this->baseDriver->commit();
		}catch(\PDOException $e ){
			$this->errMessage[] = "PDO Error: ".$e->getMessage();
			return false;
		}
	}

	/**
	 * Cancels the changes of the whole transaction, outer levels included.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function remTransaction(): Bool {
		$this->errMessage = Array();
		if( $this->transLevel==0 ){
			return false;
		}
		$this->transLevel = 0;
		try{
			return $this->baseDriver->rollBack();
		}catch(\PDOException $e ){
			$this->errMessage[] = "PDO Error: ".$e->getMessage();
			return false;
		}
	}
	
	/**
	 * Closes the PDO database connection.
	 */
	public function exitConnection(): Void {
		$this->baseDriver = null;
	}
	
}