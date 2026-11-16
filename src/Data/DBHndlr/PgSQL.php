<?php

namespace nyu\Data\DBHndlr;

/**
 * PostgreSQL handler, type code 'postgres'
 */
Class PgSQL extends PDO {

	// Names are quoted with double quotes, and wildcard searches ignore the case as they do on the other types
	protected	$quotesChar = '"';
	protected	$likeSyntax = 'ILIKE';

	/**
	 * Creates the underlying PDO connection for PostgreSQL.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'encoding' (character set) and 'waitSecs' (seconds given to the server to answer).
	 *
	 * @throws \RuntimeException When the port, the character set or the waiting time is not valid.
	 */
	public function openConnection(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array()): Void {
		$this->baseDriver = new \PDO($this->makeDsnForHost($databaseHost, $hostBaseText, $settingsList), $serverUsrTxt, $hostPassText, $this->readPdoOptsDct($settingsList));
	}

	/**
	 * Builds the connection string. The port and the character set are
	 * only written when they are given: without them, the server decides.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'encoding' (character set) and 'waitSecs' (seconds given to the server to answer).
	 * @return String The PDO connection string.
	 *
	 * @throws \RuntimeException When the port, the character set or the waiting time is not valid.
	 */
	protected function makeDsnForHost(String $databaseHost, String $hostBaseText, Array $settingsList=Array()): String {
		$dsnStrng = "pgsql:host=$databaseHost;dbname=$hostBaseText";
		$partNumb = strval($settingsList['portNumb'] ?? '');
		if( $partNumb!='' ){
			if( !ctype_digit($partNumb) ){
				throw new \RuntimeException('Invalid database port: '.$partNumb);
			}
			$dsnStrng .= ';port='.$partNumb;
		}
		$encoding = strval($settingsList['encoding'] ?? '');
		if( $encoding!='' ){
			// A character set name (UTF8, LATIN1), not a collation
			if( !preg_match('/^[A-Za-z0-9]+$/D', $encoding) ){
				throw new \RuntimeException('Invalid database character set: '.$encoding);
			}
			$dsnStrng .= ";options='--client_encoding=".$encoding."'";
		}
		return $dsnStrng;
	}

	/**
	 * Describes a table, through the PostgreSQL catalogue: its field names
	 * and its primary key.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getInfoOfTable(String $tbleLabelTxt): Array|Bool {
		$infoQuer = 'SELECT a.attname AS name, COALESCE(i.indisprimary, false) AS pk, format_type(a.atttypid, NULL) AS type FROM pg_attribute a'
			.' LEFT JOIN pg_index i ON i.indrelid = a.attrelid AND i.indisprimary AND a.attnum = ANY(i.indkey)'
			.' WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum;';
		// The quoted name keeps the case of the table as it is written
		if( !$this->sendBuiltQuery($infoQuer, false, Array($this->quoteFieldName($tbleLabelTxt))) ){
			return false;
		}
		$fieldRow = $this->readLastResult(true);
		if( !is_array($fieldRow) || empty($fieldRow) ){
			return false;
		}
		$tableDct = Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
		foreach($fieldRow as $oneRow){
			if( $oneRow['pk'] && $tableDct['pkey']=='' ){
				$tableDct['pkey'] = $oneRow['name'];
			}
			$tableDct['flds'][] = $oneRow['name'];
			if( strval($oneRow['type'])=='bytea' ){
				$tableDct['blob'][] = $oneRow['name'];
			}
		}
		return $tableDct;
	}

	/**
	 * Gives the type of each column of a table, through the PostgreSQL catalogue.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The types by column name, in the order of the table, or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getColTypeList(String $tbleLabelTxt): Array|Bool {
		$typeQuer = 'SELECT a.attname AS name, format_type(a.atttypid, NULL) AS type FROM pg_attribute a'
			.' WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum;';
		if( !$this->sendBuiltQuery($typeQuer, false, Array($this->quoteFieldName($tbleLabelTxt))) ){
			return false;
		}
		$fieldRow = $this->readLastResult(true);
		return ( is_array($fieldRow) && !empty($fieldRow) ) ? array_column($fieldRow, 'type', 'name') : false;
	}

	/**
	 * Lists the columns a record cannot be written without, through the
	 * PostgreSQL catalogue: no NULL, no default value (a serial has one),
	 * not an identity.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The names of those columns, in the order of the table, or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getColNeedList(String $tbleLabelTxt): Array|Bool {
		$needQuer = 'SELECT a.attname AS name FROM pg_attribute a'
			.' WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped AND a.attnotnull AND NOT a.atthasdef AND a.attidentity = \'\' ORDER BY a.attnum;';
		if( !$this->sendBuiltQuery($needQuer, false, Array($this->quoteFieldName($tbleLabelTxt))) ){
			return false;
		}
		$fieldRow = $this->readLastResult(true);
		return is_array($fieldRow) ? array_column($fieldRow, 'name') : false;
	}

	/**
	 * Lists the tables of the current schema, views left out.
	 *
	 * @return Array|Bool The names of the tables, in name order, or false when they cannot be read.
	 */
	public function listTableNames(): Array|Bool {
		if( !$this->sendBuiltQuery("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name;") ){
			return false;
		}
		$tableRow = $this->readLastResult(true);
		return is_array($tableRow) ? array_map('strval', array_column($tableRow, 'table_name')) : false;
	}

	/**
	 * Returns the key given by the database to the last inserted row.
	 *
	 * @return String|Bool The last insert ID, or false when the insert did not draw a key from a sequence.
	 */
	public function getLastAddedId(): String|Bool {
		try{
			return $this->baseDriver->lastInsertId();
		}catch(\PDOException $e ){
			return false;
		}
	}

}
