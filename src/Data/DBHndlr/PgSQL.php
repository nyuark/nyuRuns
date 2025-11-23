<?php

namespace nyu\Data\DBHndlr;

/**
 * PostgreSQL handler, type code 'pgsql'
 */
Class PgSQL extends PDO {

	// Names are quoted with double quotes, and wildcard searches ignore the case as they do on the other types
	protected	$quoteChr = '"';
	protected	$likeWord = 'ILIKE';

	/**
	 * Creates the underlying PDO connection for PostgreSQL.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
	 *
	 * @throws \RuntimeException When the port, the character set or the waiting time is not valid.
	 */
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array()): Void {
		$this->dbDriver = new \PDO($this->dsnString($serverHost, $serverBase, $moreParams), $serverUser, $serverPass, $this->pdoOption($moreParams));
	}

	/**
	 * Builds the connection string. The port and the character set are
	 * only written when they are given: without them, the server decides.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
	 * @return String The PDO connection string.
	 *
	 * @throws \RuntimeException When the port, the character set or the waiting time is not valid.
	 */
	protected function dsnString(String $serverHost, String $serverBase, Array $moreParams=Array()): String {
		$dsnText = "pgsql:host=$serverHost;dbname=$serverBase";
		$prtNmbr = strval($moreParams['port'] ?? '');
		if( $prtNmbr!='' ){
			if( !ctype_digit($prtNmbr) ){
				throw new \RuntimeException('Invalid database port: '.$prtNmbr);
			}
			$dsnText .= ';port='.$prtNmbr;
		}
		$charSet = strval($moreParams['char'] ?? '');
		if( $charSet!='' ){
			// A character set name (UTF8, LATIN1), not a collation
			if( !preg_match('/^[A-Za-z0-9]+$/D', $charSet) ){
				throw new \RuntimeException('Invalid database character set: '.$charSet);
			}
			$dsnText .= ";options='--client_encoding=".$charSet."'";
		}
		return $dsnText;
	}

	/**
	 * Describes a table, through the PostgreSQL catalogue: its field names
	 * and its primary key.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool {
		$infQury = 'SELECT a.attname AS name, COALESCE(i.indisprimary, false) AS pk, format_type(a.atttypid, NULL) AS type FROM pg_attribute a'
			.' LEFT JOIN pg_index i ON i.indrelid = a.attrelid AND i.indisprimary AND a.attnum = ANY(i.indkey)'
			.' WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum;';
		// The quoted name keeps the case of the table as it is written
		if( !$this->sendQuery($infQury, false, Array($this->quoteName($tableLabel))) ){
			return false;
		}
		$fldRows = $this->readQuery(true);
		if( !is_array($fldRows) || empty($fldRows) ){
			return false;
		}
		$tblInfo = Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
		foreach($fldRows as $fldRow){
			if( $fldRow['pk'] && $tblInfo['pkey']=='' ){
				$tblInfo['pkey'] = $fldRow['name'];
			}
			$tblInfo['flds'][] = $fldRow['name'];
			if( strval($fldRow['type'])=='bytea' ){
				$tblInfo['blob'][] = $fldRow['name'];
			}
		}
		return $tblInfo;
	}

	/**
	 * Returns the key given by the database to the last inserted row.
	 *
	 * @return String|Bool The last insert ID, or false when the insert did not draw a key from a sequence.
	 */
	public function lastAdded(): String|Bool {
		try{
			return $this->dbDriver->lastInsertId();
		}catch(\PDOException $e ){
			return false;
		}
	}

}
