<?php

namespace nyu\Runs\Shows;

use \nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltAction = 'index';
	
	/**
	 * Lists the shows, each loaded with its relations, and sets the
	 * list as output. Reads the 'filters' ('search' on the title,
	 * 'categ' on the item type), 'sorting' and 'indexes' inputs.
	 */
	public function index(): Void {
		$showLst = $this->modelStack->getModelObject('listing');
		// Filter results
		// Data in here should be strTyped Variables object from inputReg
		// $reqVals = $this->inputReg->getRegVal('QUERY');
		// $this->inputReg['varname']['index']->get();
		$filters = $this->inptRgstry->obtainDefValue('filters', array());
		$byCateg = '';
		if(isset($filters['categ']) && is_string($filters['categ'])){
			$byCateg = $filters['categ'];
			unset($filters['categ']);
		}
		if(isset($filters['search'])){
			// A search is a match : the * of what is searched for is any text
			$itmFile = Array('title~'=>$filters['search']);
			unset($filters['search']);
		}else{
			$itmFile = Array();
		}
		$showLst->replaceFilters($itmFile);
		// Sort results
		$sorting = $this->inptRgstry->obtainDefValue('sorting', array('id_show'));
		$showLst->setOrderFields($sorting);
		// Limit results
		$indexes = $this->inptRgstry->obtainDefValue('indexes', array());
		$showLst->setLimitOffset($indexes);
		
		$showLst = $showLst->readRowsOfList();
		if($showLst===false){
			$this->statusCode = 500;
			$this->statusMssg = 'An error occurred while processing Listing query';
			return;
		}
		$showOut = Array();
		$initRec = $this->modelStack->getModelObject('single');
		foreach($showLst as $shwRec){
			$showRec = clone($initRec);
			$showArr = $showRec->loadRecordList($shwRec['id_show']); 
			if($byCateg!=''){
				if($showRec->verifyCategory($byCateg)==false){
					continue;
				}
			}
			$showOut[] = $showArr;
			$showRec = null;
		}
		if(!empty($showOut)){
			$this->statusCode = 200;
			$this->replyValue = $showOut;
		}else{
			$this->statusCode = 204;
			$this->statusMssg = 'Show Listing returned no results';
			$this->replyValue = $showOut;
		}
	}
	
	
	/**
	 * Loads a single show with its relations and sets it as output.
	 *
	 * @param String $currentShwId Primary key of the show to load.
	 */
	public function view( String $currentShwId ): Void {
		$showRec = $this->modelStack->getModelObject('single');
		// Filter results
		$shwIdnt = $currentShwId;
		$showArr = $showRec->loadRecordList($shwIdnt);
		//print_r($showArr);
		//die();
		
		if($showArr!==false && !empty($showArr)){
			$this->statusCode = 200;
			$this->replyValue = $showArr;
		}else{
			$this->statusCode = 404;
			$this->statusMssg = 'Show not found';
		}
	}
}