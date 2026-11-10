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

}
