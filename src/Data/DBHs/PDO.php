<?php

namespace nyu\Data\DBHs;

use nyu\Data\DBHs\Handler;

Class PDO implements Handler {
	
	public 		$dbDriver;
	private		$sqlQuery;
	private		$queryRes;
	private		$errorMsg;
	
	/**
	 * Opens the PDO database connection.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 */
	public function __construct(String $serverHost, String $serverUser, String $serverPass, String $serverBase){
		$this->openDBase($serverHost, $serverUser, $serverPass,  $serverBase);
	}
	
	/**
	 * Creates the underlying PDO connection for MySQL.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 */
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase): Void {
		$this->dbDriver = new \PDO("mysql:host=$serverHost;dbname=$serverBase", $serverUser, $serverPass);
	}
//	public function makeQuery();
	/**
	 * Sends a built SQL query to the database, either as a query (default,
	 * for SELECTs) or as an exec (for INSERT/UPDATE/DELETE).
	 *
	 * @param String $builtQuery The SQL query to execute.
	 * @param Bool   $query2Exec Whether to run the query via PDO::exec() instead of PDO::query().
	 * @return Bool True on success, false on failure (error recorded in lastError()).
	 */
	public function sendQuery(String $builtQuery, Bool $query2Exec=false): Bool {
		try{
			$this->sqlQuery = $builtQuery;
			if($query2Exec){
				$this->queryRes = $this->dbDriver->exec($this->sqlQuery);
			}else{
				$this->queryRes = $this->dbDriver->query($this->sqlQuery, \PDO::FETCH_ASSOC);
			}
			if($this->queryRes==false){
				$this->errorMsg[] = "PDO Error: ".$this->dbDriver->errorinfo()[2];
				return false;
			}else{
				return true;
			}
		}catch(PDOException  $e ){
			$this->errorMsg[] = "PDO Error: ".$e;
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
				if(count($this->errorMsg)<1){
					// Empty result
					$this->errorMsg[] = "The query returned no results";
					return Array();
				}else{
					return false;
				}
			}
			return false;
		}
	}
	
	/**
	 * Escapes a string for safe inclusion in a SQL query, quoted with the
	 * caller's own surrounding single quotes.
	 *
	 * @param String $str2Escape The raw string to escape.
	 * @return String The escaped string, without surrounding quotes.
	 */
	public function escapeStr(String $str2Escape): String {
		$quoted = $this->dbDriver->quote($str2Escape);
		return substr($quoted, 1, -1);
	}
	
	/**
	 * Returns the most recent PDO error message recorded.
	 *
	 * @return mixed The last error message, or false if none recorded.
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
	 * Closes the PDO database connection.
	 */
	public function exitDBase(): Void {
		$this->dbDriver = null;
	}
	
}