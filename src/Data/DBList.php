<?php

namespace nyu\Data;

use \nyu\Data\DBCon;

class DBList {
	protected $tableNme;	
	protected $dbConnex;
	
	protected $whrClaus;
	protected $selScope;
	protected $whrOrder;
	protected $whrLimit;
	protected $whrJoint;
	protected $whrGroup;
	
	protected $listRows;
	
	function __construct( String $dbaseTable, $selctScope=null, $whereClaus=null, $orderClaus=null, $limitValue=null, $handleLink=null ){
		$this->tableNme = $dbaseTable;
		if($selctScope == null) $selctScope='*';
		$this->selScope = $selctScope;
		$this->whrClaus = $whereClaus;
		$this->whrOrder = $orderClaus;
		$this->whrLimit = $limitValue;
		if($handleLink == null){
			$nyuConf = $GLOBALS['nyuConfVars'];
			$nyuBase = $nyuConf->getRegVal('database');
			$this->dbConnex = DBCon::getInstance($nyuBase['TYPE'],$nyuBase['HOST'],$nyuBase['USER'],$nyuBase['PASS'],$nyuBase['BASE']);
		}else{
			$this->dbConnex = $handleLink;
		}
		if( $whereClaus!=null ){
			$this->buildSlct();
		}
	}
	
	public function getRows($queryBuilt=true){
		if( $queryBuilt==false ) $this->buildSlct();
		
		$this->listRows = $this->dbConnex->dbHandle->readQuery();
		
		return $this->listRows;
	}
	
	protected function buildSlct($selctScope=null, $whereClaus=null, $orderClaus=null, $limitValue=null, $joinClause=null, $groupClaus=null){
		
		if($selctScope != null)	$this->selScope = $scope;
		
		$scpeVal = $this->selScope;
		
		$lstJoin = '';
		if(!is_null($joinClause))$this->tableJoin($joinClause);
		if($this->whrJoint!='') $lstJoin = $this->whrJoint;
		
		$whrVals = '';
		if(is_array($whereClaus))	$this->lstSearch($whereClaus);
		if($this->whrClaus!='')		$whrVals = $this->whrClaus;
		
		$grpVals = '';
		if(!is_null($groupClaus)) 	$this->grpFields($groupClaus);
		if($this->whrGroup!='') 	$grpVals = $this->whrGroup;
		
		$ordrVal = '';
		if(!is_null($orderClaus)) 	$this->listOrder($orderClaus);
		if($this->whrOrder!='') 	$ordrVal = $this->whrOrder;
		
		$limVals = '';
		if(!is_null($limitValue))	$this->listLimit($limitValue);
		if($this->whrLimit!='') 	$limVals = $this->whrLimit;
		
		$selTabl = $this->tableNme;
		
		$listSel = 'SELECT '.$scpeVal;
		$listSel .=' FROM `'.$selTabl.'`';
		
		if( $lstJoin!='' )	$listSel.= ' LEFT JOIN '.$lstJoin;
		if( $whrVals!='' )	$listSel.= ' WHERE '.$whrVals;
		if( $grpVals!='' )	$listSel.= ' GROUP by '.$grpVals;
		if( $ordrVal!='' )	$listSel.= ' ORDER by '.$ordrVal;
		if( $limVals!='' )	$listSel.= ' LIMIT '.$limVals;
		
		$this->dbConnex->dbHandle->sendQuery($listSel);
	//	echo $listSel.'<br />';
	}
	
	public function lstSearch( Array $whereArray ){
		$this->whrClaus = '';
		$whereStr = '';
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
		$this->whrClaus = $whereStr;
	}
	
	public function grpFields($groupByFld){
		$grpVals = '';
		if( is_string($groupByFld) ) $groupByFld = array($this->whrGroup);
		if( is_array($groupByFld) ) foreach($groupByFld as $fldNme){
			if($grpVals != ''){
				$grpVals.=',';
			}
			$grpVals .= '`'.$this->dbConnex->dbHandle->escapeStr($fldNme).'`';
		}
		$this->whrGroup = $grpVals;
	}
	
	public function listOrder($orderItems){
		$ordrVal = '';
		if( is_string($orderItems) ) {
			$orderItems = array($orderItems);
		}
		if( is_array($this->whrOrder) ) {
			foreach($this->whrOrder as $fldNme){
				if($ordrVal != ''){
					$ordrVal.=',';
				}
				$ordrDir=' ASC';
				if(substr($fldNme,0,1)=='^'){
					$ordrDir=' DESC';
					$fldNme = subStr($fldNme, 1);
				}
				$ordrVal .= '`'.$this->dbConnex->dbHandle->escapeStr($fldNme).'`'.$ordrDir;
			}
		}
		$this->whrOrder = $ordrVal;
	}
	
	public function listLimit($limIndexes=null){
		$this->whrLimit = '';
		// TODO : Get Table Limit from core config
		if($limIndexes!=null){
			$dfltLim = 100;
			$limIndx = Array(
				$dfltLim
			);
			if(is_string($limIndexes)){
				str_replace(' ','',$limIndexes);
				if(strpos($limIndexes,',')!==false){
					$limIndx = explode(',', $limIndexes);
				}
			}
			if(!is_array($limIndexes)){
				if(is_numeric($limIndexes)){
					$limIndx = Array(
						intval($limIndexes)
					);
				}
			}
			
			$this->whrLimit.= $limIndx[0];
			if(isset($limIndx[1])) $this->whrLimit.=' OFFSET '.$limIndx[1];
		}
	}
	
	public function tableJoin( Array $joinClause=array() ){
		// Currently supports single left joins
		// Array ( 'joinTable', 'fld1', 'fld2') )
		// TODO : Could admit many tables and different operators
		// TODO : Also could set table reference for each field
		$this->jointCls = '';
		if( is_array($joinClause) ) {
			$this->jointCls .= '`'.$joinClause[0].'` ON '.$joinClause[1].' = '.$joinClause[2];
		}
	}
	
	/* TOO DANGEROUS TO USE FOR NOW 
		// TODO: Use Regular function
	function customSel( $scope='*', $where=false, $order=false, $limit=false, $joint=false, $group=false, $havin=false ){
		$query = 'SELECT '.$scope.' FROM `'.$this->lstTable.'`';
		if( $joint!='' )$query.= $joint;
		if( $where!='' )$query.= ' WHERE '.$where;
		if( $group!='' )$query.= ' GROUP by '.$group;
		if( $havin!='' )$query.= ' HAVING '.$havin;
		if( $order!='' )$query.= ' ORDER by '.$order;
		if( $limit!='' )$query.= ' LIMIT '.$limit;
		
		$result = $this->dbConnex->query($query);
		
		if($result){
			$this->rows = Array();
			while ($row = mysqli_fetch_assoc($result)) {
				$this->rows[] = $row;
			}
		} else {
			//$this->errLog( SB_ERR_DB_LISTFAIL.$this->dbConnex->errGet() );
		}
	}
	*/
}
/*
function mysqli_result($res, $row, $field=0) { 
    $res->data_seek($row); 
    $datarow = $res->fetch_array(); 
    return $datarow[$field]; 
}
*/