<?php

namespace nyu\Data\DBHs;

use nyu\Data\DBHs\Handler;

Class PDO implements Handler {
	
	public 		$dbDriver;
	private		$sqlQuery;
	private		$queryRes;
	private		$errorMsg;
	
	public function __construct(String $serverHost, String $serverUser, String $serverPass, String $serverBase){
		$this->openDBase($serverHost, $serverUser, $serverPass,  $serverBase);
	}
	
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase){
		$this->dbDriver = new \PDO("mysql:host=$serverHost;dbname=$serverBase", $serverUser, $serverPass);
	}
//	public function makeQuery();
	public function sendQuery(String $builtQuery, Bool $query2Exec=false){
		try{
			$this->sqlQuery = $builtQuery;
			if($query2Exec){
				$this->queryRes = $this->dbDriver->exec($this->sqlQuery);
			}else{
				$this->queryRes = $this->dbDriver->query($this->sqlQuery, \PDO::FETCH_ASSOC);
			}
			if($this->queryRes==false){
				$this->errorMsg[] = "PDO Error: ".$this->dbDriver->errorinfo()[2];
				return false;
			}else{
				return true;
			}
		}catch(PDOException  $e ){
			$this->errorMsg[] = "PDO Error: ".$e;
			return false;
		}
	}
	public function readQuery(){
		if(isset($this->queryRes) && $this->queryRes!=false ){
			$rowVals = Array();
			if(is_array($this->queryRes) || is_object($this->queryRes)){
				foreach($this->queryRes as $resRow){
					$rowVals[] = $resRow;
				}
				if(count($rowVals)==0 || $rowVals[0]==null){
					return $rowVals;
				}
				if( count($rowVals)>1 ){
					return $rowVals;
				}else{
					if( count($rowVals[0])>1 ){
						return $rowVals[0];
					}else{
						return current($rowVals[0]);
					}
				}
			}else{
				return $rowVals;
			}
		}else{
			// Throw errors
			if( !isset($this->sqlQuery) ){
				// Empty query
				$this->errorMsg[] = "Empty query cannot return results";
			}else{
				if(count($this->errorMsg)<1){
					// Empty result
					$this->errorMsg[] = "The query returned no results";
					return Array();
				}else{
					return false;
				}
			}
			return false;
		}
	}
	
	public function escapeStr(String $str2Escape){
		return $str2Escape;
	}
	
	public function lastError(){
		return end( $this->errorMsg );
	}
	
	public function lastAdded(){
		return $this->dbDriver->lastInsertId();
	}
	
	public function exitDBase(){
		$this->dbDriver = null;
	}
	
}