<?php

namespace nyu\Data\DBHndlr;

/**
 * PostgreSQL handler, type code 'postgres'
 */
Class PgSQL extends PDO {

	// Names are quoted with double quotes, and wildcard searches ignore the case as they do on the other types
	protected	$qutCharctr = '"';
	protected	$likeSyntax = 'ILIKE';

	/**
	 * Creates the underlying PDO connection for PostgreSQL.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $moreParamtrs Optional settings: 'portNumb', 'encoding' (character set) and 'waitSecs' (seconds given to the server to answer).
	 *
	 * @throws \RuntimeException When the port, the character set or the waiting time is not valid.
	 */
	public function openConnection(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $moreParamtrs=Array()): Void {
		$this->baseDriver = new \PDO($this->buildDsnString($databaseHost, $hostBaseText, $moreParamtrs), $serverUsrTxt, $hostPassText, $this->pdoOptionArray($moreParamtrs));
	}

	/**
	 * Builds the connection string. The port and the character set are
	 * only written when they are given: without them, the server decides.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $hostBaseText Database name.
	 * @param Array  $moreParamtrs Optional settings: 'portNumb', 'encoding' (character set) and 'waitSecs' (seconds given to the server to answer).
	 * @return String The PDO connection string.
	 *
	 * @throws \RuntimeException When the port, the character set or the waiting time is not valid.
	 */
	protected function buildDsnString(String $databaseHost, String $hostBaseText, Array $moreParamtrs=Array()): String {
		$dsnText = "pgsql:host=$databaseHost;dbname=$hostBaseText";
		$partNum = strval($moreParamtrs['portNumb'] ?? '');
		if( $partNum!='' ){
			if( !ctype_digit($partNum) ){
				throw new \RuntimeException('Invalid database port: '.$partNum);
			}
			$dsnText .= ';port='.$partNum;
		}
		$charSet = strval($moreParamtrs['encoding'] ?? '');
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
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfoArray(String $tbleLabelTxt): Array|Bool {
		$infoQry = 'SELECT a.attname AS name, COALESCE(i.indisprimary, false) AS pk, format_type(a.atttypid, NULL) AS type FROM pg_attribute a'
			.' LEFT JOIN pg_index i ON i.indrelid = a.attrelid AND i.indisprimary AND a.attnum = ANY(i.indkey)'
			.' WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum;';
		// The quoted name keeps the case of the table as it is written
		if( !$this->sendBuiltQuery($infoQry, false, Array($this->quoteFieldName($tbleLabelTxt))) ){
			return false;
		}
		$fldRows = $this->readLastResult(true);
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
	public function finalAddedText(): String|Bool {
		try{
			return $this->baseDriver->lastInsertId();
		}catch(\PDOException $e ){
			return false;
		}
	}

}
