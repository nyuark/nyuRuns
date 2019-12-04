<?php

namespace nyu\Data;

use \nyu\Data\DBHs;

// Singleton to connect DataBase
class DBCon {
	// Hold the class instance.
	private static 	$instance = null;
	public 			$dbHandle;
	
	// The db connection is established in the private constructor.
	private function __construct( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase ){
		// \nyu\Data\DBHs
		if(!class_exists('\\nyu\\Data\\DBHs\\'.$serverType)){
			$serverType = 'PDO';
		}
		$dbClass = '\\nyu\\Data\\DBHs\\'.$serverType;
		$this->dbHandle = new $dbClass($serverHost, $serverUser, $serverPass, $serverBase);
	}
	
	public static function getInstance( String $serverType, String $serverHost, String $serverUser, String $serverPass, String $serverBase ){
		if(!self::$instance){
			self::$instance = new DBCon($serverType, $serverHost, $serverUser, $serverPass, $serverBase);
		}
	 
		return self::$instance;
	}
	
	public function getConnection(){
		return $this->dbHandle;
	}
}