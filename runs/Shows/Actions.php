<?php

namespace nyu\Runs\Shows;

use \nyu\App\Actionset;

class Actions extends Actionset {
	
	public function index(){
		$showLst = $this->modStack->readModel('listing');
		// Filter results
		// Data in here should be strTyped Variables object from inputReg
		// $reqVals = $this->inputReg->getRegVal('QUERY');
		// $this->inputReg['varname']['index']->get();
		$filters = $this->inputReg->getDefVal('filters', array());
		if(isset($filters['search'])){
			$itemFil = Array('title'=>$filters['search']);
			unset($filters['search']);
		}else{
			$itemFil = Array();
		}
		$showLst->lstSearch($itemFil);
		// Sort results
		$sorting = $this->inputReg->getDefVal('sorting', array('id_show'));
		$showLst->listOrder($sorting);
		// Limit results
		$indexes = $this->inputReg->getDefVal('indexes', array());
		$showLst->listLimit($indexes);
		
		$showLst = $showLst->readItems();
		$showOut = Array();
		$initRec = $this->modStack->readModel('single');
		foreach($showLst as $shwRec){
			$showRec = clone($initRec);
			$showArr = $showRec->loadItem($shwRec['id_show']); 
			if(!empty($itemFil)){
				if($showRec->hasCateg($itemFil)==false){
					continue;
				}
			}
			$showOut[] = $showArr;
			$showRec = null;
		}
		if($showOut!=false){
			if(!empty($showOut)){
				$this->statCode = 200;
				$this->asOutput = $showOut;
			}else{
				$this->statCode = 204;
				$this->statMssg = 'Show Listing returned no results';
				$this->asOutput = $showOut;
			}
		}else{
			$this->statCode = 500;
			$this->statMssg = 'An error occured while processing Listing query';
		}
	}
	
	
	public function view( $currShowId ){
		$showRec = $this->modStack->readModel('single');
		// Filter results
		$showIdn = $currShowId;
		$showArr = $showRec->loadItem($showIdn);
		//print_r($showArr);
		//die();
		
		if($showArr!=false){
			if(!empty($showArr)){
				$this->statCode = 200;
				$this->asOutput = $showArr;
			}else{
				$this->statCode = 204;
				$this->statMssg = 'Show Listing returned no results';
				$this->asOutput = $showArr;
			}
		}else{
			$this->statCode = 500;
			$this->statMssg = 'An error occured while processing Listing query';
		}
	}
}