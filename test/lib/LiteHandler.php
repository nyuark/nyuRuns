<?php

// Test-only database handler: the framework's own DBHs\PDO pointed at a
// SQLite file, translating the two MySQL-only statements DBRecord sends.

namespace nyu\Data\DBHs;

class Lite extends PDO {

	/**
	 * Opens the SQLite file given as the database name.
	 *
	 * @param String $serverHost Unused.
	 * @param String $serverUser Unused.
	 * @param String $serverPass Unused.
	 * @param String $serverBase Path of the SQLite file.
	 */
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase): Void {
		$this->dbDriver = new \PDO('sqlite:'.$serverBase);
	}

	/**
	 * Sends a query, after rewriting `EXPLAIN table` and `UPDATE ... LIMIT 1`
	 * into their SQLite equivalents.
	 *
	 * @param String $builtQuery The SQL query to execute.
	 * @param Bool   $query2Exec Whether to run the query via PDO::exec().
	 * @return Bool True on success, false on failure.
	 */
	public function sendQuery(String $builtQuery, Bool $query2Exec=false): Bool {
		if( preg_match('/^EXPLAIN `(\w+)`;$/', $builtQuery, $tblName) ){
			$builtQuery = "SELECT name AS Field, CASE WHEN pk>0 THEN 'PRI' ELSE '' END AS `Key` FROM pragma_table_info('".$tblName[1]."')";
		}
		if( strpos($builtQuery, 'UPDATE')===0 ){
			$builtQuery = str_replace(' LIMIT 1;', ';', $builtQuery);
		}
		return parent::sendQuery($builtQuery, $query2Exec);
	}
}
