<?php

namespace nyu\Data\DBHndlr;

/**
 * SQLite handler, type code 'sqlte'. The database is a single file.
 */
Class SQLite extends PDO {

	/**
	 * Opens the SQLite file given as the database name. The foreign keys
	 * are turned on, as the other databases have them: SQLite leaves them
	 * off for each new connection.
	 *
	 * @param String $serverHost Unused: SQLite has no server.
	 * @param String $serverUser Unused.
	 * @param String $serverPass Unused.
	 * @param String $serverBase Path of the SQLite file, absolute or from the project root.
	 * @param Array  $moreParams Optional settings: 'wait', the seconds given to a file locked by another connection.
	 */
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array()): Void {
		$this->dbDriver = new \PDO('sqlite:'.$this->locateFle($serverBase), null, null, $this->pdoOption($moreParams));
		$this->dbDriver->exec('PRAGMA foreign_keys = ON;');
	}

	/**
	 * Resolves the path of the database file. A relative path starts from
	 * the project root, so that the file never lands in the exposed folder.
	 *
	 * @param String $serverBase Path of the SQLite file, or ':memory:'.
	 * @return String The path to open.
	 */
	protected function locateFle(String $serverBase): String {
		$isAbslt = (bool)preg_match('#^([A-Za-z]:|[/\\\\])#', $serverBase);
		if( $serverBase=='' || $serverBase==':memory:' || $isAbslt || !defined('TOP_DIR') ){
			return $serverBase;
		}
		return TOP_DIR.DIRECTORY_SEPARATOR.$serverBase;
	}

	/**
	 * Describes a table, through SQLite's table_info: its field names and
	 * its primary key.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool {
		$this->quoteName($tableLabel);
		if( !$this->sendQuery('SELECT name, pk, type FROM pragma_table_info(?) ORDER BY cid;', false, Array($tableLabel)) ){
			return false;
		}
		$fldRows = $this->readQuery(true);
		if( !is_array($fldRows) || empty($fldRows) ){
			return false;
		}
		$tblInfo = Array('flds'=>Array(), 'pkey'=>'', 'blob'=>Array());
		foreach($fldRows as $fldRow){
			if( $fldRow['pk']==1 ){
				$tblInfo['pkey'] = $fldRow['name'];
			}
			$tblInfo['flds'][] = $fldRow['name'];
			if( stripos(strval($fldRow['type']), 'blob')!==false ){
				$tblInfo['blob'][] = $fldRow['name'];
			}
		}
		return $tblInfo;
	}

}
