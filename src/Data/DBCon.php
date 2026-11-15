<?php

namespace nyu\Data;

use \nyu\Data\DBHndlr;

/**
 * One connection per configured database, opened on first use
 */
class DBCon {
	// Open connections, by database name; the default one is under ''
	private static 	$knownLinks = Array();
	public 			$baseHandle;
	protected 		$dbStoreObj;

	// Handler class of each SQL database type code
	private const TYPE_LIST = Array('mysql_db'=>'MySQL', 'sqlitedb'=>'SQLite', 'postgres'=>'PgSQL');
	// Store class of each database type that is not SQL: those have no handler
	private const STOR_LIST = Array('mongo_db'=>'MongoStore');

	/**
	 * Private: connections are opened through linkNamedDbCon(), loadDefaultCon() or
	 * makeByStoreObj().
	 */
	private function __construct(){
	}
	
	/**
	 * Opens the connection of a database of the given type: an SQL type
	 * through its handler, any other through its own store.
	 *
	 * @param String $hostTypeText Database type code ('mysql_db'), or the name of a handler class under nyu\Data\DBHndlr. 'PDO', the former name of the MySQL handler, is still accepted.
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'encoding' (character set), 'authBase' (database holding the account) and 'waitSecs' (seconds given to the server to answer).
	 * @param String $linkNameText Name of the database in the config, '' for the default one: the config cache keeps the descriptions of its tables under it.
	 *
	 * @return DBCon The new connection.
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	private static function openTypedDbCon( String $hostTypeText, String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array(), String $linkNameText='' ): DBCon {
		$newNlace = new DBCon();
		$typeClef = strtolower($hostTypeText);
		if( $typeClef=='pdo' ){
			$typeClef = 'mysql_db';
		}
		if( isset(self::STOR_LIST[$typeClef]) ){
			$storeCls = '\\nyu\\Data\\DBStore\\'.self::STOR_LIST[$typeClef];
			$newNlace->dbStoreObj = new $storeCls($databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $settingsList);
			return $newNlace;
		}
		$dtbsClss = '\\nyu\\Data\\DBHndlr\\'.(self::TYPE_LIST[$typeClef] ?? $hostTypeText);
		if( !preg_match('/^\w+$/D', $hostTypeText) || !class_exists($dtbsClss) || !is_subclass_of($dtbsClss, DBHndlr\Handler::class) ){
			throw new \RuntimeException('Unknown database type: '.$hostTypeText);
		}
		$newNlace->baseHandle = new $dtbsClss($databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $settingsList);
		$newNlace->dbStoreObj = new DBStore\SqlStore($newNlace->baseHandle, $linkNameText);
		return $newNlace;
	}
	
	/**
	 * Makes a connection around a store given ready-made, outside of the
	 * configured databases. It is not kept: the caller hands it to the
	 * lists and records that use it.
	 *
	 * @param DBStore\Store $dataStoreObj The store.
	 * @return DBCon A connection working through that store.
	 */
	public static function makeByStoreObj( DBStore\Store $dataStoreObj ): DBCon {
		$newNlace = new DBCon();
		$newNlace->dbStoreObj = $dataStoreObj;
		return $newNlace;
	}

	/**
	 * Returns the store of the database: what a Record and a List work
	 * through, whatever the kind of database.
	 *
	 * @return DBStore\Store The store of this connection.
	 */
	public function getStoreObject(): DBStore\Store {
		return $this->dbStoreObj;
	}
	
	/**
	 * Returns the connection of the default database, opening it on first
	 * call with the given settings. Later calls ignore their arguments and
	 * return the connection already open. Code that relies on the
	 * configuration uses linkNamedDbCon() instead.
	 *
	 * @param String $hostTypeText Database type code ('mysql_db'), or the name of a handler class under nyu\Data\DBHndlr.
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'encoding' (character set), 'authBase' (database holding the account) and 'waitSecs' (seconds given to the server to answer).
	 * @return DBCon The connection of the default database.
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	public static function loadDefaultCon( String $hostTypeText, String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array() ): DBCon {
		if( !isset(self::$knownLinks['']) ){
			self::$knownLinks[''] = self::openTypedDbCon($hostTypeText, $databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $settingsList);
		}
		return self::$knownLinks[''];
	}
	
	/**
	 * Returns the connection of a configured database, opening it the
	 * first time it is asked for. Each database has a single connection.
	 *
	 * @param String $linkNameText Name of the database, whatever its case; '' for the default one.
	 * @return DBCon The connection of that database.
	 *
	 * @throws \RuntimeException When no database is configured, none has that name, or its type is unknown.
	 */
	public static function linkNamedDbCon( String $linkNameText='' ): DBCon {
		if( $linkNameText=='' && isset(self::$knownLinks['']) ){
			return self::$knownLinks[''];
		}
		$baseDict = self::readSetupArray($linkNameText);
		$nameClef = $baseDict['name'];
		if( !isset(self::$knownLinks[$nameClef]) ){
			self::$knownLinks[$nameClef] = self::openTypedDbCon($baseDict['dbEngine'], $baseDict['hostName'], $baseDict['userName'], $baseDict['passWord'], $baseDict['baseName'], Array('portNumb'=>$baseDict['portNumb'], 'encoding'=>$baseDict['encoding'], 'authBase'=>$baseDict['authBase'], 'waitSecs'=>$baseDict['waitSecs']), $nameClef);
		}
		return self::$knownLinks[$nameClef];
	}
	
	/**
	 * Reads the settings of a configured database. The 'database' config
	 * holds either a single block of settings, or several blocks under
	 * names: the first block is then the default database.
	 *
	 * @param String $linkNameText Name of the database, whatever its case; '' for the default one.
	 * @return Array The 'dbEngine', 'hostName', 'portNumb', 'baseName', 'userName', 'passWord', 'encoding', 'authBase' and 'waitSecs' settings, whatever their case in the file, '' for those left out.
	 *
	 * @throws \RuntimeException When no database is configured, or none has that name.
	 */
	public static function readDbaseConfg( String $linkNameText='' ): Array {
		$baseDict = self::readSetupArray($linkNameText);
		unset($baseDict['name']);
		return $baseDict;
	}
	
	/**
	 * Finds the settings of a configured database, and the name its
	 * connection is kept under.
	 *
	 * @param String $linkNameText Name of the database, whatever its case; '' for the default one.
	 * @return Array The settings of readDbaseConfg(), plus 'name': the name in lower case, '' for the default database.
	 *
	 * @throws \RuntimeException When no database is configured, or none has that name.
	 */
	private static function readSetupArray( String $linkNameText ): Array {
		$dfltDict = Array('dbEngine'=>'', 'hostName'=>'', 'portNumb'=>'', 'baseName'=>'', 'userName'=>'', 'passWord'=>'', 'encoding'=>'', 'authBase'=>'', 'waitSecs'=>'');
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$dtbsName = $nyuConfg ? $nyuConfg->readValueByKey('database') : null;
		if( !is_array($dtbsName) || empty($dtbsName) ){
			throw new \RuntimeException('No database is configured');
		}
		$dtbsName = array_change_key_case($dtbsName, CASE_LOWER);
		if( array_key_exists('dbengine', $dtbsName) ){
			// A single block of settings: it has no name, and is the default
			if( $linkNameText!='' ){
				throw new \RuntimeException('Unknown database: '.$linkNameText);
			}
			$baseDict = $dtbsName;
			$nameClef = '';
		}else{
			$firstKey = strval(array_key_first($dtbsName));
			$nameClef = ($linkNameText=='') ? $firstKey : strtolower($linkNameText);
			if( !isset($dtbsName[$nameClef]) || !is_array($dtbsName[$nameClef]) ){
				throw new \RuntimeException('Unknown database: '.$linkNameText);
			}
			$baseDict = array_change_key_case($dtbsName[$nameClef], CASE_LOWER);
			// The first block is the default, whether asked by its name or not
			if( $nameClef===$firstKey ){
				$nameClef = '';
			}
		}
		// The keys are read in lower case, and given as dfltDict writes them
		foreach( array_keys($dfltDict) as $oneNme ){
			$dfltDict[$oneNme] = is_scalar($baseDict[strtolower($oneNme)] ?? null) ? strval($baseDict[strtolower($oneNme)]) : '';
		}
		$baseDict = $dfltDict;
		$baseDict['name'] = $nameClef;
		return $baseDict;
	}

	/**
	 * Returns the underlying database handler instance.
	 *
	 * @return ?\nyu\Data\DBHndlr\Handler The SQL handler of the connection, null when the database is not an SQL one.
	 */
	public function readConnection(): ?DBHndlr\Handler {
		return $this->baseHandle;
	}
}