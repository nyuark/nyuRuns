<?php

namespace nyu\Data\DBHndlr;

/**
 * MySQL (and MariaDB) handler, type code 'mysql'
 */
Class MySQL extends PDO {

	/**
	 * Creates the underlying PDO connection for MySQL.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $moreParamtrs Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
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
	 * @param Array  $moreParamtrs Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
	 * @return String The PDO connection string.
	 *
	 * @throws \RuntimeException When the port, the character set or the waiting time is not valid.
	 */
	protected function buildDsnString(String $databaseHost, String $hostBaseText, Array $moreParamtrs=Array()): String {
		$dsnText = "mysql:host=$databaseHost;dbname=$hostBaseText";
		$partNum = strval($moreParamtrs['port'] ?? '');
		if( $partNum!='' ){
			if( !ctype_digit($partNum) ){
				throw new \RuntimeException('Invalid database port: '.$partNum);
			}
			$dsnText .= ';port='.$partNum;
		}
		$charSet = strval($moreParamtrs['char'] ?? '');
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
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfoArray(String $tbleLabelTxt): Array|Bool {
		if( !$this->sendBuiltQuery('EXPLAIN '.$this->quoteFieldName($tbleLabelTxt).';') ){
			return false;
		}
		$fldRows = $this->readLastResult(true);
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
