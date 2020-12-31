<?php

namespace nyu\Runs\Profile;

use \nyu\App\Actionset;

class Actions extends Actionset {
	
	/* Return a list of profiles sorted by ranks and creation date */
	public function index( ){
		$itemLst = $this->modStack->readModel('listing');
		// Filter results
		$filters = $this->inputReg->getDefVal('filters', array());
		if(isset($filters['items'])){
			$itemFlt = $filters['items'];
			unset($filters['items']);
		}else{
			$itemFlt = Array();
		}
		$itemLst->lstSearch($filters);
		// Sort results
		$sorting = $this->inputReg->getDefVal('sorting', array('id_item'));
		$itemLst->listOrder($sorting);
		// Limit results
		$indexes = $this->inputReg->getDefVal('indexes', array());
		$itemLst->listLimit($indexes);
		
		$itemLst = $itemLst->readItems();
		$itemOut = Array();
		$initRec = $this->modStack->readModel('single');
		foreach($itemLst as $shwRec){
			$itemRec = clone($initRec);
			$itemArr = $itemRec->loadItem($shwRec['id_item']); 
			if(!empty($itemFlt)){
				if($itemRec->hasCateg($itemFlt)==false){
					continue;
				}
			}
			$itemOut[] = $itemArr;
			$itemRec = null;
		}
		if($itemOut!=false){
			if(!empty($itemOut)){
				$this->statCode = 200;
				$this->asOutput = $itemOut;
			}else{
				$this->statCode = 204;
				$this->statMssg = 'item Listing returned no results';
				$this->asOutput = $itemOut;
			}
		}else{
			$this->statCode = 500;
			$this->statMssg = 'An error occured while processing Listing query';
		}
	}

	/* Return a list of active regions filtered by country */
	public function regions( String $countryCde = '' ){
		$regList = $this->modStack->readModel('locationList');
		// TODO: SHOULD NOT BE HARDCODED
		if($countryCde==''){
			$countryCde = 'ES';
		}
		// Set field scope
		$regList->fieldScop('DISTINCT(region), country_code');
		// Filter results
		$filters = array(
			'country_code'=>$countryCde,
			'is_active'=>"1"
		);
		$regList->lstSearch($filters);
		$regions = $regList->readItems();
		parent::checkList( $regions );
	}

	/* Return a list of province lists by region */
	public function provinces( $regionList = Array() ){
		$provLst = $this->modStack->readModel('locationList');
		if(is_string($regionList)){
			$regionList = explode(',',$regionList);
		}
		foreach($regionList as $r=>$region){
			$regionList[$r] = urldecode($region);
		}
		// Set field scope
		$provLst->fieldScop('DISTINCT(province), region');
		// Filter results
		$filters = array(
			'region'=>$regionList,
			'is_active'=>"1"
		);
		$provLst->lstSearch($filters);
		$prvnces = $provLst->readItems();
		parent::checkList( $prvnces );
	}

	/* Return a list of province lists by region */
	public function locations( $provnceLst = Array() ){
		$locList = $this->modStack->readModel('locationList');
		// TODO: SHOULD NOT BE HARDCODED
		if(is_string($provnceLst)){
			$provnceLst = explode(',',$provnceLst);
		}
		foreach($provnceLst as $p=>$provnc){
			$provnceLst[$p] = urldecode($provnc);
		}
		// Set field scope
		$locList->fieldScop('id_location as id, city, province');
		// Filter results
		$filters = array(
			'province'=>$provnceLst,
			'is_active'=>"1"
		);
		// Limit results
		//$locList->listLimit('25');

		$locList->lstSearch($filters);
		$locatio = $locList->readItems();
		parent::checkList( $locatio );
	}
	
	/* Return a list of streets by partial name search */
	public function streets( $partialNme ){
		$strtLst = $this->modStack->readModel('streetList');
		// Filter results
		$filters = array('full_name'=>"*$partialNme*");
		$strtLst->lstSearch($filters);
		// Sort results
		$sorting = array('full_name');
		$strtLst->listOrder($sorting);
		// Limit results
		$strtLst->listLimit('25');
		
		$streets = $strtLst->readItems();
		parent::checkList( $streets );
	}
}