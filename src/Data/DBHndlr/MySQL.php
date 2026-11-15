<?php

namespace nyu\Data\DBHndlr;

/**
 * MySQL (and MariaDB) handler, type code 'mysql_db'
 */
Class MySQL extends PDO {

	/**
	 * Creates the underlying PDO connection for MySQL.
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
		$this->baseDriver = new \PDO($this->buildDsnString($databaseHost, $hostBaseText, $settingsList), $serverUsrTxt, $hostPassText, $this->pdoOptionArray($settingsList));
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
	protected function buildDsnString(String $databaseHost, String $hostBaseText, Array $settingsList=Array()): String {
		$dsnStrng = "mysql:host=$databaseHost;dbname=$hostBaseText";
		$partNumb = strval($settingsList['portNumb'] ?? '');
		if( $partNumb!='' ){
			if( !ctype_digit($partNumb) ){
				throw new \RuntimeException('Invalid database port: '.$partNumb);
			}
			$dsnStrng .= ';port='.$partNumb;
		}
		$encoding = strval($settingsList['encoding'] ?? '');
		if( $encoding!='' ){
			// A character set name (utf8, utf8mb4), not a collation such as utf8_general_ci
			if( !preg_match('/^[A-Za-z0-9]+$/D', $encoding) ){
				throw new \RuntimeException('Invalid database character set: '.$encoding);
			}
			$dsnStrng .= ';charset='.$encoding;
		}
		return $dsnStrng;
	}

	/**
	 * Describes a table, through EXPLAIN: its field names and its primary key.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfoArray(String $tbleLabelTxt): Array|Bool {
		if( !$this->sendBuiltQuery('EXPLAIN '.$this->quoteFieldName($tbleLabelTxt).';') ){
			return false;
		}
		$fieldRow = $this->readLastResult(true);
		if( !is_array($fieldRow) || empty($fieldRow) ){
			return false;
		}
		$tableDct = Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
		foreach($fieldRow as $fldRow){
			if( $fldRow['Key']=='PRI' && $tableDct['pkey']=='' ){
				$tableDct['pkey'] = $fldRow['Field'];
			}
			$tableDct['flds'][] = $fldRow['Field'];
			if( preg_match('/^((tiny|medium|long)?blob|(var)?binary)/i', strval($fldRow['Type'])) ){
				$tableDct['blob'][] = $fldRow['Field'];
			}
		}
		return $tableDct;
	}

	/**
	 * Gives the type of each column of a table, through EXPLAIN.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The types by column name, in the order of the table, or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function columnTypeList(String $tbleLabelTxt): Array|Bool {
		if( !$this->sendBuiltQuery('EXPLAIN '.$this->quoteFieldName($tbleLabelTxt).';') ){
			return false;
		}
		$fieldRow = $this->readLastResult(true);
		return ( is_array($fieldRow) && !empty($fieldRow) ) ? array_column($fieldRow, 'Type', 'Field') : false;
	}

	/**
	 * Lists the tables of the database, views left out.
	 *
	 * @return Array|Bool The names of the tables, in name order, or false when they cannot be read.
	 */
	public function tableNameArray(): Array|Bool {
		if( !$this->sendBuiltQuery("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE';") ){
			return false;
		}
		$tableRow = $this->readLastResult(true);
		if( !is_array($tableRow) ){
			return false;
		}
		// The first column is named after the database: it is read by its place
		$tableLst = Array();
		foreach( $tableRow as $oneRow ){
			$tableLst[] = strval( array_values((array)$oneRow)[0] ?? '' );
		}
		sort($tableLst);
		return $tableLst;
	}

}
