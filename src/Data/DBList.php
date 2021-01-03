<?php

namespace nyu\Data;

use \nyu\Data\DBCon;
use \nyu\Tools\StrKit;

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
			$nyuConf = $GLOBALS['nyuConfig'];
			$nyuBase = $nyuConf->readValue('database');
			$this->dbConnex = DBCon::getInstance($nyuBase['TYPE'],$nyuBase['HOST'],$nyuBase['USER'],$nyuBase['PASS'],$nyuBase['BASE']);
		}else{
			$this->dbConnex = $handleLink;
		}
		if( $whereClaus!=null ){
			$this->buildSlct();
		}
	}
	
	public function readItems($queryBuilt=false){
		if( $queryBuilt==false ) $this->buildSlct();
		
		$this->listRows = $this->dbConnex->dbHandle->readQuery();
		
		return $this->listRows;
	}
	
	public function flatItems( $isolateFld, $queryBuilt=false){
		$results = $this->readItems($queryBuilt);
		$flatLst = Array();
		if(isset($results) && !empty($results)){
			if(isset($results[$isolateFld])){
				$flatLst[] = $results[$isolateFld];
			}else{				
				foreach($results as $result){
					if(isset($result[$isolateFld])){
						$flatLst[] = $result[$isolateFld];
					}
				}
			}
		}
		return $flatLst;
	}
	
	protected function buildSlct($selctScope=null, $whereClaus=null, $orderClaus=null, $limitValue=null, $joinClause=null, $groupClaus=null){
		
		if($selctScope != null)	$this->selScope = $selctScope;
		
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
				foreach($valsArr as $lstVal){
					if(!empty($lstVal)){
						if($valList!=''){
							$valList.=',';
						}
						$valList.="'".$this->dbConnex->dbHandle->escapeStr($lstVal)."'";
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
	
	public function fieldScop( String $fieldScope = null ){
		if( $fieldScope == null){
			$this->selScope = '*';
		}else{
			$scopStr = '';
			$selFlds = Array();
			if( is_string($fieldScope) ){
				$fieldScope = str_replace('`','',$fieldScope);
				$selFlds = explode(',',$fieldScope);
			}
			foreach($selFlds as $selFld){
				$selFld = trim($selFld);
				if($scopStr != ''){
					$scopStr.=',';
				}
				if(strpos($selFld,'(')===false && strpos($selFld,' as ')===false){
					$scopStr .= '`'.$this->dbConnex->dbHandle->escapeStr($selFld).'`';
				}else{
					$scopStr .= $this->dbConnex->dbHandle->escapeStr($selFld);
				}
			}
			$this->selScope = $scopStr;
		}
	}
	
	public function grpFields( $groupByFld ){
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
	
	public function childList( String $childTable, String $singlField='', String $foreingKey='', String $currentKey='' ){
		if( $foreingKey=='' ) {
			$extName = StrKit::singlrize($this->tableNme);
			$foreingKey = $extName.'_id';
		}
		if( $currentKey=='' ) {
			$intName = StrKit::singlrize($this->tableNme);
			$currentKey = 'id_'.$intName;
		}
		$lstRows = $this->listRows;
		if(!$lstRows){
			$this->readItems();
			$lstRows = $this->listRows;
		}
		foreach($lstRows as $rowKey=>$rowArr){
			$subWhre = "`$foreingKey` = '$rowArr[$currentKey]'";
			$subList = new DBList($childTable, null, $subWhre);
			if($singlField!=''){
				$lstRows[$rowKey][$childTable] = $subList->flatItems($singlField);
			}else{
				$lstRows[$rowKey][$childTable] = $subList->readItems();
			}
		}
		return $lstRows;
	}
	
	/* TOO POWERFUL TO USE FOR NOW 
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