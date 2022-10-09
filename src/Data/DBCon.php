<?php

namespace nyu\Data;

use \nyu\Data\DBHndlr;

// One connection per configured database, opened on first use
class DBCon {
	// Open connections, by database name; the default one is under ''
	private static 	$linkList = Array();
	public 			$dbHandle;
	protected 		$storeObj;

	// Handler class of each SQL database type code
	private const TYPELIST = Array('mysql'=>'MySQL', 'sqlte'=>'SQLite', 'pgsql'=>'PgSQL');
	// Store class of each database type that is not SQL: those have no handler
	private const STORLIST = Array('mongo'=>'MongoStore');

	/**
	 * Private: connections are opened through linkNamed(), getInstnc() or
	 * fromStore().
	 */
	private function __construct(){
	}
	
	/**
	 * Opens the connection of a database of the given type: an SQL type
	 * through its handler, any other through its own store.
	 *
	 * @param String $serverType Database type code ('mysql'), or the name of a handler class under nyu\Data\DBHndlr. 'PDO', the former name of the MySQL handler, is still accepted.
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'char' (character set), 'auth' (database holding the account) and 'wait' (seconds given to the server to answer).
	 *
	 * @return DBCon The new connection.
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	private static function buildLink( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array() ): DBCon {
		$newLink = new DBCon();
		$typeKey = strtolower($serverType);
		if( $typeKey=='pdo' ){
			$typeKey = 'mysql';
		}
		if( isset(self::STORLIST[$typeKey]) ){
			$storCls = '\\nyu\\Data\\DBStore\\'.self::STORLIST[$typeKey];
			$newLink->storeObj = new $storCls($serverHost, $serverUser, $serverPass, $serverBase, $moreParams);
			return $newLink;
		}
		$dbClass = '\\nyu\\Data\\DBHndlr\\'.(self::TYPELIST[$typeKey] ?? $serverType);
		if( !preg_match('/^\w+$/D', $serverType) || !class_exists($dbClass) || !is_subclass_of($dbClass, DBHndlr\Handler::class) ){
			throw new \RuntimeException('Unknown database type: '.$serverType);
		}
		$newLink->dbHandle = new $dbClass($serverHost, $serverUser, $serverPass, $serverBase, $moreParams);
		$newLink->storeObj = new DBStore\SqlStore($newLink->dbHandle);
		return $newLink;
	}
	
	/**
	 * Makes a connection around a store given ready-made, outside of the
	 * configured databases. It is not kept: the caller hands it to the
	 * lists and records that use it.
	 *
	 * @param DBStore\Store $storeObjct The store.
	 * @return DBCon A connection working through that store.
	 */
	public static function fromStore( DBStore\Store $storeObjct ): DBCon {
		$newLink = new DBCon();
		$newLink->storeObj = $storeObjct;
		return $newLink;
	}

	/**
	 * Returns the store of the database: what a Record and a List work
	 * through, whatever the kind of database.
	 *
	 * @return DBStore\Store The store of this connection.
	 */
	public function dataStore(): DBStore\Store {
		return $this->storeObj;
	}
	
	/**
	 * Returns the connection of the default database, opening it on first
	 * call with the given settings. Later calls ignore their arguments and
	 * return the connection already open. Code that relies on the
	 * configuration uses linkNamed() instead.
	 *
	 * @param String $serverType Database type code ('mysql'), or the name of a handler class under nyu\Data\DBHndlr.
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'char' (character set), 'auth' (database holding the account) and 'wait' (seconds given to the server to answer).
	 * @return DBCon The connection of the default database.
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	public static function getInstnc( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array() ): DBCon {
		if( !isset(self::$linkList['']) ){
			self::$linkList[''] = self::buildLink($serverType, $serverHost, $serverUser, $serverPass, $serverBase, $moreParams);
		}
		return self::$linkList[''];
	}
	
	/**
	 * Returns the connection of a configured database, opening it the
	 * first time it is asked for. Each database has a single connection.
	 *
	 * @param String $dbaseLabel Name of the database, whatever its case; '' for the default one.
	 * @return DBCon The connection of that database.
	 *
	 * @throws \RuntimeException When no database is configured, none has that name, or its type is unknown.
	 */
	public static function linkNamed( String $dbaseLabel='' ): DBCon {
		if( $dbaseLabel=='' && isset(self::$linkList['']) ){
			return self::$linkList[''];
		}
		$baseSet = self::readSetup($dbaseLabel);
		$nameKey = $baseSet['name'];
		if( !isset(self::$linkList[$nameKey]) ){
			self::$linkList[$nameKey] = self::buildLink($baseSet['type'], $baseSet['host'], $baseSet['user'], $baseSet['pass'], $baseSet['base'], Array('port'=>$baseSet['port'], 'char'=>$baseSet['char'], 'auth'=>$baseSet['auth'], 'wait'=>$baseSet['wait']));
		}
		return self::$linkList[$nameKey];
	}
	
	/**
	 * Reads the settings of a configured database. The 'database' config
	 * holds either a single block of settings, or several blocks under
	 * names: the first block is then the default database.
	 *
	 * @param String $dbaseLabel Name of the database, whatever its case; '' for the default one.
	 * @return Array The 'type', 'host', 'port', 'base', 'user', 'pass', 'char', 'auth' and 'wait' settings, '' for those left out.
	 *
	 * @throws \RuntimeException When no database is configured, or none has that name.
	 */
	public static function baseSetup( String $dbaseLabel='' ): Array {
		$baseSet = self::readSetup($dbaseLabel);
		unset($baseSet['name']);
		return $baseSet;
	}
	
	/**
	 * Finds the settings of a configured database, and the name its
	 * connection is kept under.
	 *
	 * @param String $dbaseLabel Name of the database, whatever its case; '' for the default one.
	 * @return Array The settings of baseSetup(), plus 'name': the name in lower case, '' for the default database.
	 *
	 * @throws \RuntimeException When no database is configured, or none has that name.
	 */
	private static function readSetup( String $dbaseLabel ): Array {
		$dfltSet = Array('type'=>'', 'host'=>'', 'port'=>'', 'base'=>'', 'user'=>'', 'pass'=>'', 'char'=>'', 'auth'=>'', 'wait'=>'');
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$allBase = $nyuConf ? $nyuConf->readValue('database') : null;
		if( !is_array($allBase) || empty($allBase) ){
			throw new \RuntimeException('No database is configured');
		}
		$allBase = array_change_key_case($allBase, CASE_LOWER);
		if( array_key_exists('type', $allBase) ){
			// A single block of settings: it has no name, and is the default
			if( $dbaseLabel!='' ){
				throw new \RuntimeException('Unknown database: '.$dbaseLabel);
			}
			$baseSet = $allBase;
			$nameKey = '';
		}else{
			$frstKey = strval(array_key_first($allBase));
			$nameKey = ($dbaseLabel=='') ? $frstKey : strtolower($dbaseLabel);
			if( !isset($allBase[$nameKey]) || !is_array($allBase[$nameKey]) ){
				throw new \RuntimeException('Unknown database: '.$dbaseLabel);
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
	public function getConnex(): ?DBHndlr\Handler {
		return $this->dbHandle;
	}
}