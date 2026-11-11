<?php

namespace nyu\Data\DBHndlr;

/**
 * SQLite handler, type code 'sqlitedb'. The database is a single file.
 */
Class SQLite extends PDO {

	/**
	 * Opens the SQLite file given as the database name. The foreign keys
	 * are turned on, as the other databases have them: SQLite leaves them
	 * off for each new connection.
	 *
	 * @param String $databaseHost Unused: SQLite has no server.
	 * @param String $serverUsrTxt Unused.
	 * @param String $hostPassText Unused.
	 * @param String $hostBaseText Path of the SQLite file, absolute or from the project root.
	 * @param Array  $settingsList Optional settings: 'waitSecs', the seconds given to a file locked by another connection.
	 */
	public function openConnection(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array()): Void {
		$this->baseDriver = new \PDO('sqlite:'.$this->locateBaseFile($hostBaseText), null, null, $this->pdoOptionArray($settingsList));
		$this->baseDriver->exec('PRAGMA foreign_keys = ON;');
	}

	/**
	 * Resolves the path of the database file. A relative path starts from
	 * the project root, so that the file never lands in the exposed folder.
	 *
	 * @param String $hostBaseText Path of the SQLite file, or ':memory:'.
	 * @return String The path to open.
	 */
	protected function locateBaseFile(String $hostBaseText): String {
		$isAbslt = (bool)preg_match('#^([A-Za-z]:|[/\\\\])#', $hostBaseText);
		if( $hostBaseText=='' || $hostBaseText==':memory:' || $isAbslt || !defined('TOP_DIR') ){
			return $hostBaseText;
		}
		return TOP_DIR.DIRECTORY_SEPARATOR.$hostBaseText;
	}

	/**
	 * Describes a table, through SQLite's table_info: its field names and
	 * its primary key.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfoArray(String $tbleLabelTxt): Array|Bool {
		$this->quoteFieldName($tbleLabelTxt);
		if( !$this->sendBuiltQuery('SELECT name, pk, type FROM pragma_table_info(?) ORDER BY cid;', false, Array($tbleLabelTxt)) ){
			return false;
		}
		$fldRows = $this->readLastResult(true);
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

	/**
	 * Gives the type of each column of a table, through SQLite's table_info.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The types by column name, in the order of the table, or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function columnTypeList(String $tbleLabelTxt): Array|Bool {
		$this->quoteFieldName($tbleLabelTxt);
		if( !$this->sendBuiltQuery('SELECT name, type FROM pragma_table_info(?) ORDER BY cid;', false, Array($tbleLabelTxt)) ){
			return false;
		}
		$fldRows = $this->readLastResult(true);
		return ( is_array($fldRows) && !empty($fldRows) ) ? array_column($fldRows, 'type', 'name') : false;
	}

	/**
	 * Lists the tables of the database, through sqlite_master: SQLite's own
	 * tables (sqlite_*) left out.
	 *
	 * @return Array|Bool The names of the tables, in name order, or false when they cannot be read.
	 */
	public function tableNameArray(): Array|Bool {
		if( !$this->sendBuiltQuery("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name;") ){
			return false;
		}
		$tblRows = $this->readLastResult(true);
		return is_array($tblRows) ? array_map('strval', array_column($tblRows, 'name')) : false;
	}

}
