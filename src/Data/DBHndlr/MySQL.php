<?php

namespace nyu\Data\DBHndlr;

// MySQL (and MariaDB) handler, type code 'mysql'
Class MySQL extends PDO {

	/**
	 * Creates the underlying PDO connection for MySQL.
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
		$dsnText = "mysql:host=$serverHost;dbname=$serverBase";
		$prtNmbr = strval($moreParams['port'] ?? '');
		if( $prtNmbr!='' ){
			if( !ctype_digit($prtNmbr) ){
				throw new \RuntimeException('Invalid database port: '.$prtNmbr);
			}
			$dsnText .= ';port='.$prtNmbr;
		}
		$charSet = strval($moreParams['char'] ?? '');
		if( $charSet!='' ){
			// A character set name (utf8, utf8mb4), not a collation such as utf8_general_ci
			if( !preg_match('/^[A-Za-z0-9]+$/D', $charSet) ){
				throw new \RuntimeException('Invalid database character set: '.$charSet);
			}
			$dsnText .= ';charset='.$charSet;
		}
		return $dsnText;
	}

	/**
	 * Describes a table, through EXPLAIN: its field names and its primary key.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool {
		if( !$this->sendQuery('EXPLAIN '.$this->quoteName($tableLabel).';') ){
			return false;
		}
		$fldRows = $this->readQuery(true);
		if( !is_array($fldRows) || empty($fldRows) ){
			return false;
		}
		$tblInfo = Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
		foreach($fldRows as $fldRow){
			if( $fldRow['Key']=='PRI' && $tblInfo['pkey']=='' ){
				$tblInfo['pkey'] = $fldRow['Field'];
			}
			$tblInfo['flds'][] = $fldRow['Field'];
			if( preg_match('/^((tiny|medium|long)?blob|(var)?binary)/i', strval($fldRow['Type'])) ){
				$tblInfo['blob'][] = $fldRow['Field'];
			}
		}
		return $tblInfo;
	}

}
