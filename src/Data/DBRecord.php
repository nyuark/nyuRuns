<?php

namespace nyu\Data;

use \nyu\Data\DBCon;

class DBRecord {
	protected $tableNme;	
	protected $dbConnex;
	
	protected $primaKey;
	protected $keyField;	
	protected $fieldLst;
	
	protected $recArray;
	protected $hasMulti;
	protected $hasChild;
	
	function __construct( String $dbaseTable, $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($handleLink == null){
			$nyuConf = $GLOBALS['nyuConfVars'];
			$nyuBase = $nyuConf->getRegVal('database');
			$this->dbConnex = DBCon::getInstance($nyuBase['TYPE'],$nyuBase['HOST'],$nyuBase['USER'],$nyuBase['PASS'],$nyuBase['BASE']);
		}else{
			$this->dbConnex = $handleLink;
		}
		$exQuery = "EXPLAIN `$this->tableNme`;";
		$this->dbConnex->dbHandle->sendQuery($exQuery);
		$this->keyField = '';
		$this->fieldLst = array();
		
		$fldList = $this->dbConnex->dbHandle->readQuery();
		foreach($fldList as $fldArr){
			if($fldArr['Key'] == 'PRI' && $this->keyField==''){
				$this->keyField = $fldArr['Field'];
			}
			// Detect foreign keys and indexes
			$this->fieldLst[] = $fldArr['Field'];
		}
		
	}
	
	function loadItem( $primaryKey ){
		$idQuery = "SELECT * FROM `$this->tableNme` WHERE `$this->keyField`='$primaryKey';";
		$this->dbConnex->dbHandle->sendQuery($idQuery);
		$this->recArray = array();
		
		$this->recArray = $this->dbConnex->dbHandle->readQuery();
		if( is_array($this->recArray) ){
			$this->primaKey = $primaryKey;
			return $this->recArray;
		}else{
			return false;
		}
	}
	
	function exstItem( $primaryKey ){
		$idQuery = "SELECT * FROM `$this->tableNme` WHERE `$this->keyField`='$primaryKey';";
		$this->dbConnex->dbHandle->sendQuery($idQuery);
		
		$tempRec = $this->dbConnex->dbHandle->readQuery();
		if( is_array($tempRec) && $tempRec[$this->keyField]==$primaryKey ){
			return true;
		}else{
			return false;
		}
	}
	
	function bindVals( $valueArray ){
		foreach($valueArray as $fldNme=>$fldVal){
			if(in_array($fldNme,$this->fieldLst)){
				if($fldNme==$this->keyField){
					$this->primaKey = $fldVal;
				}
				$this->recArray[$fldNme] = $fldVal;
			}
		}
	}
	
	function findItem( $whereArray ){
		$whereStr = '1=1';
		foreach($whereArray as $fldNme=>$fldVal){
			if($whereStr != ''){
				$whereStr.=' AND ';
			}
			
			if(is_array($fldVal)){
				$valsArr = $fldVal;
				$operatr = 'IN';
				if(substr($fldNme,-1)=='!'){
					$operatr = 'NOT IN';
					$fldNme = substr($fldNme,0,-1);
				}
				$valList = '';
				foreach($valsArr as $valsVal){
					if(!empty($valsVal)){
						if($valList!=''){
							$valList.=',';
						}
						$valList.="'".$this->dbConnex->dbHandle->escapeStr($valsVal)."'";
					}
				}
				$whereStr .= '`'.$fldNme.'` '.$operatr.' ('.$valList.')';
			}else{
				$operatr = '=';
				$reverse = false;
				if(substr($fldNme,-1)=='!'){
					$operatr = '!=';
					$reverse=true;
					$fldNme = substr($fldNme,0,-1);
				}
				if( ( substr($fldNme,-2)=='<=' || substr($fldNme,-2)=='>=' ) ){
					$operatr = substr($fldNme,-2);
					$fldNme = substr($fldNme,0,-2);
				}
				
				if( ( strpos($fldVal, '*')!==false || strpos($fldVal, '%')!==false ) ){
					$fldVal = str_replace( '*','%', $fldVal) ;
					if ($reverse==false){
						$operatr = 'LIKE';
					}else{
						$operatr = 'NOT LIKE';
					}
				}
				$whereStr .= '`'.$fldNme.'` '.$operatr." '".$this->dbConnex->dbHandle->escapeStr($fldVal)."'";
			}
		}
		
		$idQuery = "SELECT * FROM `$this->tableNme` WHERE $whereStr LIMIT 1;";
		$this->dbConnex->dbHandle->sendQuery($idQuery);
		$this->recArray = array();
		
		$this->recArray = $this->dbConnex->dbHandle->readQuery();
		if( is_array($this->recArray) ){
			if(isset($this->recArray[$this->keyField])){
				$this->primaKey = $this->recArray[$this->keyField];
			}
			return $this->recArray;
		}else{
			return false;
		}
	}
	
	function saveItem( Array $exludeFlds=Array() ){
		$recExst = false;
		$sameRec = $this->exstItem($this->primaKey);
		if($sameRec!=false){
			$recExst = true;				
		}
		
		$saveArr = $this->recArray;
		$updtStr = '';
		foreach( $saveArr as $fldNme => $fldVal ){
			if($fldNme==$this->keyField){
				// Can't change Prim Key
				unset($saveArr[$fldNme]);
				continue;
			}elseif(in_array($fldNme,$exludeFlds)){
				// Don't save excluded field 
				unset($saveArr[$fldNme]);
				continue;
			}else{
				if($updtStr!=''){
					$updtStr.= ',';
				}
				if($recExst){
					$updtStr.="`$fldNme`='$fldVal'";
				}
			}
		}
		if($recExst){
			$svQuery = "UPDATE `$this->tableNme` SET $updtStr WHERE `$this->keyField`='$this->primaKey' LIMIT 1;";
		}else{
			$svQuery = "INSERT INTO `$this->tableNme` (`".implode('`,`',array_keys($saveArr))."`) VALUES ('".implode("','",array_values($saveArr))."');";
		}
		$didSave = $this->dbConnex->dbHandle->sendQuery($svQuery, true);
		
		if($didSave){
			if($recExst){
				return $this->primaKey;
			}else{
				return $this->dbConnex->dbHandle->lastAdded();
			}
		}else{
			return false;
		}
	}

	function getChild(){
		
	}
}