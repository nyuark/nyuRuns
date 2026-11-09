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
	protected 		$storObject;

	// Handler class of each SQL database type code
	private const TYPE_LIST = Array('mysql'=>'MySQL', 'sqlte'=>'SQLite', 'pgsql'=>'PgSQL');
	// Store class of each database type that is not SQL: those have no handler
	private const STOR_LIST = Array('mongo'=>'MongoStore');

	/**
	 * Private: connections are opened through linkNamedDbCon(), obtainInstance() or
	 * fromStoreDbCon().
	 */
	private function __construct(){
	}
	
	/**
	 * Opens the connection of a database of the given type: an SQL type
	 * through its handler, any other through its own store.
	 *
	 * @param String $hostTypeText Database type code ('mysql'), or the name of a handler class under nyu\Data\DBHndlr. 'PDO', the former name of the MySQL handler, is still accepted.
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $moreParamtrs Optional settings: 'port', 'char' (character set), 'auth' (database holding the account) and 'wait' (seconds given to the server to answer).
	 * @param String $databasLabel Name of the database in the config, '' for the default one: the config cache keeps the descriptions of its tables under it.
	 *
	 * @return DBCon The new connection.
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	private static function buildLinkDbCon( String $hostTypeText, String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $moreParamtrs=Array(), String $databasLabel='' ): DBCon {
		$newLink = new DBCon();
		$typeKey = strtolower($hostTypeText);
		if( $typeKey=='pdo' ){
			$typeKey = 'mysql';
		}
		if( isset(self::STOR_LIST[$typeKey]) ){
			$storCls = '\\nyu\\Data\\DBStore\\'.self::STOR_LIST[$typeKey];
			$newLink->storObject = new $storCls($databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $moreParamtrs);
			return $newLink;
		}
		$dbClass = '\\nyu\\Data\\DBHndlr\\'.(self::TYPE_LIST[$typeKey] ?? $hostTypeText);
		if( !preg_match('/^\w+$/D', $hostTypeText) || !class_exists($dbClass) || !is_subclass_of($dbClass, DBHndlr\Handler::class) ){
			throw new \RuntimeException('Unknown database type: '.$hostTypeText);
		}
		$newLink->baseHandle = new $dbClass($databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $moreParamtrs);
		$newLink->storObject = new DBStore\SqlStore($newLink->baseHandle, $databasLabel);
		return $newLink;
	}
	
	/**
	 * Makes a connection around a store given ready-made, outside of the
	 * configured databases. It is not kept: the caller hands it to the
	 * lists and records that use it.
	 *
	 * @param DBStore\Store $dataStoreObj The store.
	 * @return DBCon A connection working through that store.
	 */
	public static function fromStoreDbCon( DBStore\Store $dataStoreObj ): DBCon {
		$newLink = new DBCon();
		$newLink->storObject = $dataStoreObj;
		return $newLink;
	}

	/**
	 * Returns the store of the database: what a Record and a List work
	 * through, whatever the kind of database.
	 *
	 * @return DBStore\Store The store of this connection.
	 */
	public function getStoreObject(): DBStore\Store {
		return $this->storObject;
	}
	
	/**
	 * Returns the connection of the default database, opening it on first
	 * call with the given settings. Later calls ignore their arguments and
	 * return the connection already open. Code that relies on the
	 * configuration uses linkNamedDbCon() instead.
	 *
	 * @param String $hostTypeText Database type code ('mysql'), or the name of a handler class under nyu\Data\DBHndlr.
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $moreParamtrs Optional settings: 'port', 'char' (character set), 'auth' (database holding the account) and 'wait' (seconds given to the server to answer).
	 * @return DBCon The connection of the default database.
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	public static function obtainInstance( String $hostTypeText, String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $moreParamtrs=Array() ): DBCon {
		if( !isset(self::$knownLinks['']) ){
			self::$knownLinks[''] = self::buildLinkDbCon($hostTypeText, $databaseHost, $serverUsrTxt, $hostPassText, $hostBaseText, $moreParamtrs);
		}
		return self::$knownLinks[''];
	}
	
	/**
	 * Returns the connection of a configured database, opening it the
	 * first time it is asked for. Each database has a single connection.
	 *
	 * @param String $databasLabel Name of the database, whatever its case; '' for the default one.
	 * @return DBCon The connection of that database.
	 *
	 * @throws \RuntimeException When no database is configured, none has that name, or its type is unknown.
	 */
	public static function linkNamedDbCon( String $databasLabel='' ): DBCon {
		if( $databasLabel=='' && isset(self::$knownLinks['']) ){
			return self::$knownLinks[''];
		}
		$baseSet = self::readSetupArray($databasLabel);
		$nameKey = $baseSet['name'];
		if( !isset(self::$knownLinks[$nameKey]) ){
			self::$knownLinks[$nameKey] = self::buildLinkDbCon($baseSet['type'], $baseSet['host'], $baseSet['user'], $baseSet['pass'], $baseSet['base'], Array('port'=>$baseSet['port'], 'char'=>$baseSet['char'], 'auth'=>$baseSet['auth'], 'wait'=>$baseSet['wait']), $nameKey);
		}
		return self::$knownLinks[$nameKey];
	}
	
	/**
	 * Reads the settings of a configured database. The 'database' config
	 * holds either a single block of settings, or several blocks under
	 * names: the first block is then the default database.
	 *
	 * @param String $databasLabel Name of the database, whatever its case; '' for the default one.
	 * @return Array The 'type', 'host', 'port', 'base', 'user', 'pass', 'char', 'auth' and 'wait' settings, '' for those left out.
	 *
	 * @throws \RuntimeException When no database is configured, or none has that name.
	 */
	public static function baseSetupArray( String $databasLabel='' ): Array {
		$baseSet = self::readSetupArray($databasLabel);
		unset($baseSet['name']);
		return $baseSet;
	}
	
	/**
	 * Finds the settings of a configured database, and the name its
	 * connection is kept under.
	 *
	 * @param String $databasLabel Name of the database, whatever its case; '' for the default one.
	 * @return Array The settings of baseSetupArray(), plus 'name': the name in lower case, '' for the default database.
	 *
	 * @throws \RuntimeException When no database is configured, or none has that name.
	 */
	private static function readSetupArray( String $databasLabel ): Array {
		$dfltSet = Array('type'=>'', 'host'=>'', 'port'=>'', 'base'=>'', 'user'=>'', 'pass'=>'', 'char'=>'', 'auth'=>'', 'wait'=>'');
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$allBase = $nyuConf ? $nyuConf->readFileDetail('database') : null;
		if( !is_array($allBase) || empty($allBase) ){
			throw new \RuntimeException('No database is configured');
		}
		$allBase = array_change_key_case($allBase, CASE_LOWER);
		if( array_key_exists('type', $allBase) ){
			// A single block of settings: it has no name, and is the default
			if( $databasLabel!='' ){
				throw new \RuntimeException('Unknown database: '.$databasLabel);
			}
			$baseSet = $allBase;
			$nameKey = '';
		}else{
			$frstKey = strval(array_key_first($allBase));
			$nameKey = ($databasLabel=='') ? $frstKey : strtolower($databasLabel);
			if( !isset($allBase[$nameKey]) || !is_array($allBase[$nameKey]) ){
				throw new \RuntimeException('Unknown database: '.$databasLabel);
			}
			$baseSet = array_change_key_case($allBase[$nameKey], CASE_LOWER);
			// The first block is the default, whether asked by its name or not
			if( $nameKey===$frstKey ){
				$nameKey = '';
			}
		}
		$baseSet = array_merge( $dfltSet, array_map('strval', array_filter(array_intersect_key($baseSet, $dfltSet), 'is_scalar')) );
		$baseSet['name'] = $nameKey;
		return $baseSet;
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