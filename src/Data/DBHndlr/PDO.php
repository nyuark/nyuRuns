<?php

namespace nyu\Data\DBHndlr;

use nyu\Data\DBHndlr\Handler;

/**
 * What every PDO-based handler shares. Each database type extends it with
 * its own connection (openDBase) and table description (tableInfo).
 */
abstract Class PDO implements Handler {

	public 		$baseDriver;
	protected	$quotesChar = '`';
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
	 * @param Array  $settingsList Optional settings: 'portNumb', 'encoding' (character set) and 'waitSecs' (seconds given to the server to answer).
	 */
	public function __construct(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array()){
		$this->openConnection($databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $settingsList);
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
			$errorSrc = $this->baseDriver;
			if( !empty($valuesToBind) ){
				$stmtInst = $this->baseDriver->prepare($this->builtQuery);
				if( $stmtInst!==false && $this->execBoundQuery($stmtInst, array_values($valuesToBind)) ){
					$stmtInst->setFetchMode(\PDO::FETCH_ASSOC);
					$this->lastResult = $isWriteQuery ? $stmtInst->rowCount() : $stmtInst;
				}else{
					$this->lastResult = false;
					if( $stmtInst!==false ) $errorSrc = $stmtInst;
				}
			}elseif($isWriteQuery){
				$this->lastResult = $this->baseDriver->exec($this->builtQuery);
			}else{
				$this->lastResult = $this->baseDriver->query($this->builtQuery, \PDO::FETCH_ASSOC);
			}
			if($this->lastResult===false){
				$this->errMessage[] = "PDO Error: ".$errorSrc->errorInfo()[2];
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
	 * @param \PDOStatement $statementObj The prepared query.
	 * @param Array         $valuesToBind Its values, in order.
	 * @return Bool True when the query ran.
	 */
	protected function execBoundQuery(\PDOStatement $statementObj, Array $valuesToBind): Bool {
		$hasBlobs = false;
		foreach($valuesToBind as $bndVal){
			$hasBlobs = $hasBlobs || ($bndVal instanceof \nyu\Data\Fluxd);
		}
		if( !$hasBlobs ){
			return $statementObj->execute($valuesToBind);
		}
		foreach($valuesToBind as $bndIdx=>$bndVal){
			if( $bndVal instanceof \nyu\Data\Fluxd ){
				$bndVal->rewindToOrigin();
				$statementObj->bindValue($bndIdx+1, $bndVal->getWholeString(), \PDO::PARAM_LOB);
			}else{
				$statementObj->bindValue($bndIdx+1, $bndVal);
			}
		}
		return $statementObj->execute();
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
			$rowArray = Array();
			if(is_array($this->lastResult) || is_object($this->lastResult)){
				foreach($this->lastResult as $resRow){
					$rowArray[] = $resRow;
				}
				if($alwaysAsList){
					return $rowArray;
				}
				if(count($rowArray)==0 || $rowArray[0]==null){
					return $rowArray;
				}
				if( count($rowArray)>1 ){
					return $rowArray;
				}else{
					if( count($rowArray[0])>1 ){
						return $rowArray[0];
					}else{
						return current($rowArray[0]);
					}
				}
			}else{
				return $rowArray;
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
	 * Reads the connection options every PDO-based handler shares: 'waitSecs',
	 * the seconds given to the database to answer.
	 *
	 * @param Array $settingsList Optional settings of the connection.
	 * @return Array The PDO options, empty when none is set.
	 *
	 * @throws \RuntimeException When the waiting time is not a number.
	 */
	protected function pdoOptionArray(Array $settingsList): Array {
		$waitSecs = strval($settingsList['waitSecs'] ?? '');
		if( $waitSecs=='' ){
			return Array();
		}
		if( !ctype_digit($waitSecs) ){
			throw new \RuntimeException('Invalid database waiting time: '.$waitSecs);
		}
		return Array(\PDO::ATTR_TIMEOUT=>intval($waitSecs));
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
		return $this->quotesChar.str_replace('.', $this->quotesChar.'.'.$this->quotesChar, $fldLabelText).$this->quotesChar;
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
		$signList = Array('equal'=>'=', 'diffr'=>'!=', 'under'=>'<', 'undeq'=>'<=', 'above'=>'>', 'aboeq'=>'>=');
		$whereTxt = Array();
		$fltrList = Array();
		foreach(\nyu\Data\DBStore\Filter::readWhereArray($filterFields) as $clause){
			$oprtTokn = $clause['opert'];
			$safeFild = ($clause['field']!='') ? $this->quoteFieldName($clause['field']) : '';
			if( $oprtTokn=='never' || $oprtTokn=='every' ){
				$whereTxt[] = ($oprtTokn=='every') ? '1=1' : '1=0';
			}elseif( $oprtTokn=='unset' || $oprtTokn=='isset' ){
				$whereTxt[] = $safeFild.($oprtTokn=='isset' ? ' IS NOT NULL' : ' IS NULL');
			}elseif( $oprtTokn=='among' || $oprtTokn=='nmong' ){
				$whereTxt[] = $safeFild.($oprtTokn=='nmong' ? ' NOT IN (' : ' IN (').implode(',', array_fill(0, count($clause['value']), '?')).')';
				$fltrList = array_merge($fltrList, $clause['value']);
			}elseif( $oprtTokn=='match' || $oprtTokn=='nmtch' ){
				$whereTxt[] = $safeFild.' '.($oprtTokn=='nmtch' ? 'NOT ' : '').$this->getLikeKeyword()." ? ESCAPE '!'";
				$fltrList[] = $this->writeLikeValue( (array)$clause['value'] );
			}else{
				$whereTxt[] = $safeFild.' '.$signList[$oprtTokn].' ?';
				$fltrList[] = $clause['value'];
			}
		}
		return Array('text'=>implode(' AND ', $whereTxt), 'vals'=>$fltrList);
	}
	
	/**
	 * Writes a match as the pattern of a wildcard search: its literal
	 * pieces joined by the wildcard of the database, the characters the
	 * database would read as wildcards (%, _) escaped with '!', the escape
	 * character the search is written with.
	 *
	 * @param Array $literalParts The literal pieces, as Filter::readWhereArray() gives them.
	 * @return String The pattern.
	 */
	protected function writeLikeValue( Array $literalParts ): String {
		$partList = Array();
		foreach( $literalParts as $oneTkn ){
			$partList[] = strtr( strval($oneTkn), Array('!'=>'!!', '%'=>'!%', '_'=>'!_') );
		}
		return implode('%', $partList);
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
	public function newTransaction(): Bool {
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
	public function delTransaction(): Bool {
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