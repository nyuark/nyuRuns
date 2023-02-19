<?php

namespace nyu\Data\DBHndlr;

use nyu\Data\DBHndlr\Handler;

// What every PDO-based handler shares. Each database type extends it with
// its own connection (openDBase) and table description (tableInfo).
abstract Class PDO implements Handler {

	public 		$dbDriver;
	protected	$quoteChr = '`';
	protected	$likeWord = 'LIKE';
	private		$sqlQuery;
	private		$queryRes;
	private		$errorMsg = Array();
	private		$transLvl = 0;
	
	/**
	 * Opens the PDO database connection.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
	 */
	public function __construct(String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array()){
		$this->openDBase($serverHost, $serverUser, $serverPass, $serverBase, $moreParams);
	}
	
	/**
	 * Sends a built SQL query to the database, either as a query (default,
	 * for SELECTs) or as an exec (for INSERT/UPDATE/DELETE).
	 *
	 * @param String $builtQuery The SQL query to execute.
	 * @param Bool   $query2Exec Whether to run the query via PDO::exec() instead of PDO::query().
	 * @param Array  $boundArray Values bound, in order, to the ? placeholders of the query.
	 * @return Bool True on success, false on failure (error recorded in lastError()).
	 */
	public function sendQuery(String $builtQuery, Bool $query2Exec=false, Array $boundArray=Array()): Bool {
		try{
			// The error and the result of the previous query do not outlive it
			$this->errorMsg = Array();
			$this->queryRes = false;
			$this->sqlQuery = $builtQuery;
			$errSrce = $this->dbDriver;
			if( !empty($boundArray) ){
				$prepStm = $this->dbDriver->prepare($this->sqlQuery);
				if( $prepStm!==false && $prepStm->execute(array_values($boundArray)) ){
					$prepStm->setFetchMode(\PDO::FETCH_ASSOC);
					$this->queryRes = $query2Exec ? $prepStm->rowCount() : $prepStm;
				}else{
					$this->queryRes = false;
					if( $prepStm!==false ) $errSrce = $prepStm;
				}
			}elseif($query2Exec){
				$this->queryRes = $this->dbDriver->exec($this->sqlQuery);
			}else{
				$this->queryRes = $this->dbDriver->query($this->sqlQuery, \PDO::FETCH_ASSOC);
			}
			if($this->queryRes===false){
				$this->errorMsg[] = "PDO Error: ".$errSrce->errorInfo()[2];
				return false;
			}else{
				return true;
			}
		}catch(\PDOException  $e ){
			$this->errorMsg[] = "PDO Error: ".$e->getMessage();
			return false;
		}
	}
	/**
	 * Reads the results of the last sent query, collapsing a single-row
	 * single-column result down to a scalar and a single-row multi-column
	 * result down to that one row, unless a list of rows is asked for.
	 *
	 * @param Bool $keepAsList Whether to always return a list of rows, even for a single row.
	 * @return mixed Array of rows/row/scalar value, or false on error.
	 */
	public function readQuery( Bool $keepAsList=false ): Mixed {
		if(isset($this->queryRes) && $this->queryRes!=false ){
			$rowVals = Array();
			if(is_array($this->queryRes) || is_object($this->queryRes)){
				foreach($this->queryRes as $resRow){
					$rowVals[] = $resRow;
				}
				if($keepAsList){
					return $rowVals;
				}
				if(count($rowVals)==0 || $rowVals[0]==null){
					return $rowVals;
				}
				if( count($rowVals)>1 ){
					return $rowVals;
				}else{
					if( count($rowVals[0])>1 ){
						return $rowVals[0];
					}else{
						return current($rowVals[0]);
					}
				}
			}else{
				return $rowVals;
			}
		}else{
			// Throw errors
			if( !isset($this->sqlQuery) ){
				// Empty query
				$this->errorMsg[] = "Empty query cannot return results";
			}else{
				// Nothing to read is not an error; a failed query is
				return (count($this->errorMsg)<1) ? Array() : false;
			}
			return false;
		}
	}

	/**
	 * Reads the connection options every PDO-based handler shares: 'wait',
	 * the seconds given to the database to answer.
	 *
	 * @param Array $moreParams Optional settings of the connection.
	 * @return Array The PDO options, empty when none is set.
	 *
	 * @throws \RuntimeException When the waiting time is not a number.
	 */
	protected function pdoOption(Array $moreParams): Array {
		$waitSec = strval($moreParams['wait'] ?? '');
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
	 * @param String $fieldLabel The table or field name.
	 * @return String The quoted name.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function quoteName(String $fieldLabel): String {
		if( !preg_match('/^\w+(\.\w+)?$/D', $fieldLabel) ){
			throw new \RuntimeException('Invalid table or field name: '.$fieldLabel);
		}
		return $this->quoteChr.str_replace('.', $this->quoteChr.'.'.$this->quoteChr, $fieldLabel).$this->quoteChr;
	}

	/**
	 * Builds the WHERE part of a query from a filter array, read by
	 * DBStore\Filter::readWhere(). Names are quoted, and matches written,
	 * the way this database type expects them.
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 * @return Array The clause under 'text', with ? placeholders, and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public function makeWhere( Array $whereArray ): Array {
		$signLst = Array('equal'=>'=', 'diffr'=>'!=', 'under'=>'<', 'undeq'=>'<=', 'above'=>'>', 'aboeq'=>'>=');
		$whrText = Array();
		$whrVals = Array();
		foreach(\nyu\Data\DBStore\Filter::readWhere($whereArray) as $cndArr){
			$oprCode = $cndArr['opert'];
			$fldQutd = ($cndArr['field']!='') ? $this->quoteName($cndArr['field']) : '';
			if( $oprCode=='never' || $oprCode=='every' ){
				$whrText[] = ($oprCode=='every') ? '1=1' : '1=0';
			}elseif( $oprCode=='unset' || $oprCode=='isset' ){
				$whrText[] = $fldQutd.($oprCode=='isset' ? ' IS NOT NULL' : ' IS NULL');
			}elseif( $oprCode=='among' || $oprCode=='nmong' ){
				$whrText[] = $fldQutd.($oprCode=='nmong' ? ' NOT IN (' : ' IN (').implode(',', array_fill(0, count($cndArr['value']), '?')).')';
				$whrVals = array_merge($whrVals, $cndArr['value']);
			}elseif( $oprCode=='match' || $oprCode=='nmtch' ){
				$whrText[] = $fldQutd.' '.($oprCode=='nmtch' ? 'NOT ' : '').$this->likeOprtr().' ?';
				$whrVals[] = $cndArr['value'];
			}else{
				$whrText[] = $fldQutd.' '.$signLst[$oprCode].' ?';
				$whrVals[] = $cndArr['value'];
			}
		}
		return Array('text'=>implode(' AND ', $whrText), 'vals'=>$whrVals);
	}
	
	/**
	 * Returns the operator of a wildcard search that ignores the case.
	 *
	 * @return String The operator: LIKE, or what the database type uses in its place.
	 */
	public function likeOprtr(): String {
		return $this->likeWord;
	}
	
	/**
	 * Returns the error of the last query or transaction call. Each of
	 * those calls clears the error of the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function lastError(): String|Bool {
		return end( $this->errorMsg );
	}
	
	/**
	 * Returns the auto-increment ID of the last inserted row.
	 *
	 * @return String The last insert ID.
	 */
	public function lastAdded(): String|Bool {
		return $this->dbDriver->lastInsertId();
	}

	/**
	 * Returns the number of rows changed by the last write sent with
	 * sendQuery() in exec mode.
	 *
	 * @return Int The number of affected rows, 0 when the last query was not a write.
	 */
	public function lastCount(): Int {
		return is_int($this->queryRes) ? $this->queryRes : 0;
	}

	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure.
	 */
	public function opnTrnsct(): Bool {
		$this->errorMsg = Array();
		if( $this->transLvl>0 ){
			$this->transLvl++;
			return true;
		}
		try{
			if( !$this->dbDriver->beginTransaction() ){
				return false;
			}
		}catch(\PDOException $e ){
			$this->errorMsg[] = "PDO Error: ".$e->getMessage();
			return false;
		}
		$this->transLvl = 1;
		return true;
	}

	/**
	 * Makes the changes of the transaction permanent. On an inner level,
	 * nothing is written yet: the outermost transaction decides.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open (never opened, or already removed).
	 */
	public function setTrnsct(): Bool {
		$this->errorMsg = Array();
		if( $this->transLvl==0 ){
			return false;
		}
		if( --$this->transLvl>0 ){
			// An outer transaction is still open: it decides
			return true;
		}
		try{
			return $this->dbDriver->commit();
		}catch(\PDOException $e ){
			$this->errorMsg[] = "PDO Error: ".$e->getMessage();
			return false;
		}
	}

	/**
	 * Cancels the changes of the whole transaction, outer levels included.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function remTrnsct(): Bool {
		$this->errorMsg = Array();
		if( $this->transLvl==0 ){
			return false;
		}
		$this->transLvl = 0;
		try{
			return $this->dbDriver->rollBack();
		}catch(\PDOException $e ){
			$this->errorMsg[] = "PDO Error: ".$e->getMessage();
			return false;
		}
	}
	
	/**
	 * Closes the PDO database connection.
	 */
	public function exitDBase(): Void {
		$this->dbDriver = null;
	}
	
}