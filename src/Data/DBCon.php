<?php

namespace nyu\Data;

use \nyu\Data\DBHs;

// One connection per configured database, opened on first use
class DBCon {
	// Open connections, by database name; the default one is under ''
	private static 	$linkList = Array();
	public 			$dbHandle;

	// Handler class of each database type code
	private const TYPELIST = Array('mysql'=>'MySQL', 'sqlte'=>'SQLite', 'pgsql'=>'PgSQL');

	/**
	 * Establishes the database connection with the handler of the given
	 * type. Private: connections are opened through linkNamed() or getInstnc().
	 *
	 * @param String $serverType Database type code ('mysql'), or the name of a handler class under nyu\Data\DBHs. 'PDO', the former name of the MySQL handler, is still accepted.
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port' and 'char' (character set).
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	private function __construct( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array() ){
		$typeKey = strtolower($serverType);
		if( $typeKey=='pdo' ){
			$typeKey = 'mysql';
		}
		$dbClass = '\\nyu\\Data\\DBHs\\'.(self::TYPELIST[$typeKey] ?? $serverType);
		if( !preg_match('/^\w+$/D', $serverType) || !class_exists($dbClass) || !is_subclass_of($dbClass, DBHs\Handler::class) ){
			throw new \RuntimeException('Unknown database type: '.$serverType);
		}
		$this->dbHandle = new $dbClass($serverHost, $serverUser, $serverPass, $serverBase, $moreParams);
	}
	
	/**
	 * Returns the connection of the default database, opening it on first
	 * call with the given settings. Later calls ignore their arguments and
	 * return the connection already open. Code that relies on the
	 * configuration uses linkNamed() instead.
	 *
	 * @param String $serverType Database type code ('mysql'), or the name of a handler class under nyu\Data\DBHs.
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port' and 'char' (character set).
	 * @return DBCon The connection of the default database.
	 *
	 * @throws \RuntimeException When the type is not a known code nor a handler class.
	 */
	public static function getInstnc( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array() ): DBCon {
		if( !isset(self::$linkList['']) ){
			self::$linkList[''] = new DBCon($serverType, $serverHost, $serverUser, $serverPass, $serverBase, $moreParams);
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
			self::$linkList[$nameKey] = new DBCon($baseSet['type'], $baseSet['host'], $baseSet['user'], $baseSet['pass'], $baseSet['base'], Array('port'=>$baseSet['port'], 'char'=>$baseSet['char']));
		}
		return self::$linkList[$nameKey];
	}
	
	/**
	 * Reads the settings of a configured database. The 'database' config
	 * holds either a single block of settings, or several blocks under
	 * names: the first block is then the default database.
	 *
	 * @param String $dbaseLabel Name of the database, whatever its case; '' for the default one.
	 * @return Array The 'type', 'host', 'port', 'base', 'user', 'pass' and 'char' settings, '' for those left out.
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
		$dfltSet = Array('type'=>'', 'host'=>'', 'port'=>'', 'base'=>'', 'user'=>'', 'pass'=>'', 'char'=>'');
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
	 * @return \nyu\Data\DBHs\Handler The active DB handler (e.g. PDO).
	 */
	public function getConnex(): DBHs\Handler {
		return $this->dbHandle;
	}
}