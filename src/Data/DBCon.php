<?php

namespace nyu\Data;

use \nyu\Data\DBHs;

// Singleton to connect DataBase
class DBCon {
	// Hold the class instance.
	private static 	$instance = null;
	public 			$dbHandle;
	
	/**
	 * Establishes the database connection using the given driver
	 * (falls back to PDO if the requested handler class doesn't exist).
	 * Private to enforce the singleton pattern via getInstnc().
	 *
	 * @param String $serverType Name of the DB handler class (under nyu\Data\DBHs), e.g. 'PDO'.
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 */
	private function __construct( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase ){
		// \nyu\Data\DBHs
		if(!class_exists('\\nyu\\Data\\DBHs\\'.$serverType)){
			$serverType = 'PDO';
		}
		$dbClass = '\\nyu\\Data\\DBHs\\'.$serverType;
		$this->dbHandle = new $dbClass($serverHost, $serverUser, $serverPass, $serverBase);
	}
	
	/**
	 * Returns the shared DBCon instance, creating it on first call with
	 * the given connection parameters. Subsequent calls ignore their
	 * arguments and return the already-created instance.
	 *
	 * @param String $serverType Name of the DB handler class (under nyu\Data\DBHs), e.g. 'PDO'.
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @return DBCon The shared connection instance.
	 */
	public static function getInstnc( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase ): DBCon {
		if(!self::$instance){
			self::$instance = new DBCon($serverType, $serverHost, $serverUser, $serverPass, $serverBase);
		}
	 
		return self::$instance;
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