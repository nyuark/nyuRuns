<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;

class DBRecord {
	protected $tableNme;	
	protected $dbConnex;
	
	protected $primaKey;
	protected $keyField;	
	protected $fieldLst;
	
	protected $recArray;
	protected $belongTo;
	protected $children;
	
	function __construct( String $dbaseTable, Array $childArray=array(), Array $parntArray=Array(), $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($handleLink == null){
			$nyuConf = $GLOBALS['nyuConfVars'];
			$nyuBase = $nyuConf->readValue('database');
			$this->dbConnex = DBCon::getInstance($nyuBase['TYPE'], $nyuBase['HOST'], $nyuBase['USER'], $nyuBase['PASS'], $nyuBase['BASE']);
		}else{
			$this->dbConnex = $handleLink;
		}

		// Check if table exists
		$exstQry = "SELECT 1 FROM `$this->tableNme` LIMIT 1;";
		$this->dbConnex->dbHandle->sendQuery($exstQry);
		$tabExst = $this->dbConnex->dbHandle->readQuery();
		if(!$tabExst){
			// TODO, Raise exception
			print('<h1>Database error</h1>');
			print('Table might  not exist : '.$this->dbConnex->dbHandle->lastError());
			die();
		}

		$xplnQry = "EXPLAIN `$this->tableNme`;";
		$this->dbConnex->dbHandle->sendQuery($xplnQry);
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
		if( !empty($childArray) ){
			$this->children = $childArray;
		}
		if( !empty($parntArray) ){
			$this->belongTo = $parntArray;
		}
	}
	
	function loadItem( String $primaryKey ){
		$idQuery = "SELECT * FROM `$this->tableNme` WHERE `$this->keyField`='$primaryKey';";
		$this->dbConnex->dbHandle->sendQuery($idQuery);
		$this->recArray = array();
		
		$this->recArray = $this->dbConnex->dbHandle->readQuery();
		if( is_array($this->recArray) ){
			$this->primaKey = $primaryKey;
			$this->loadRels();
			return $this->recArray;
		}else{
			return false;
		}
	}
	
	function loadRels( ){
		if( !empty($this->children) ){
			foreach( $this->children as $chlTab=>$chlFld){
				$chlArg2 = false;
				$chlArg3 = '';
				$chlArg4 = '';
				$chlArg5 = '';
				if(is_string($chlTab) && !is_numeric($chlTab)){
					$chlArg1 = $chlTab;
					if(is_array($chlFld)){
						$chlArg3 = $chlFld[0];
						$chlArg4 = $chlFld[1];
						$chlArg5 = $chlFld[2];
					}else{
						$chlArg3 = $chlFld;
					}
				}else{
					$chlArg1 = $chlFld;
				}
				$this->childLst($chlArg1, $chlArg2, $chlArg3, $chlArg4, $chlArg5);
			}
		}
		if( !empty($this->belongTo) ){
			foreach( $this->belongTo as $prnTab=>$prnFld){
				$prnArg2 = false;
				$prnArg3 = '';
				$prnArg4 = '';
				if(is_string($prnTab) && !is_numeric($prnTab)){
					$prnArg1 = $prnTab;
					if(is_array($chlFld)){
						$prnArg3 = $prnFld[0];
						$prnArg4 = $prnFld[1];
					}else{
						$prnArg3 = $prnFld;
					}
				}else{
					$prnArg1 = $prnFld;
				}
				$this->parntRec($prnArg1, $prnArg2, $prnArg3, $prnArg4);
			}
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
			$this->loadRels();
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

	function parntRec( String $parntTable, Bool $returnVals=false, String $foreignKey='', String $currentKey='' ){
		$prntRec = new DBRecord( $parntTable );
		if( $foreingKey=='' ) {
			$foreingKey = $prntRec->keyField;
		}
		if( $currentKey=='' ) {
			$intName = StrKit::singlrize($this->tableNme);
			$currentKey = 'id_'.$intName;
		}
		$whreArr = Array( $foreignKey => $this->recArray[$currentKey] );
		$prntArr = $prntRec->findItem( $whreArr );
		$prntKey = ucfirst(StrKit::singularize($parntTable));
		$this->recArray[$prntKey] = $prntArr;
		if($returnVals==true){
			return $this->recArray;
		}
	}
	function childLst( String $childTable, Bool $returnVals=false, String $foreignKey='', String $currentKey='', String $sortFields='' ){
		if( $foreingKey=='' ) {
			$extName = StrKit::singlrize($this->tableNme);
			$foreingKey = $extName.'_id';
		}
		if( $currentKey=='' ) {
			$currentKey = $this->keyField;
		}
		$chldKey = ucfirst($childTable);
		
		$subWhre = "`$foreingKey` = '".$this->recArray[$currentKey]."'";
		$subList = new DBList($childTable, null, $subWhre, $sortFields);
		$this->recArray[$chldKey] = $subList->readItems();
		
		if($returnVals==true){
			return $this->recArray;
		}
	}
}