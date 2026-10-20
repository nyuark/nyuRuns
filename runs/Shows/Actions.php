<?php

namespace nyu\Runs\Shows;

use \nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltActn = 'index';
	
	/**
	 * Lists the shows, each loaded with its relations, and sets the
	 * list as output. Reads the 'filters' ('search' on the title,
	 * 'categ' on the item type), 'sorting' and 'indexes' inputs.
	 */
	public function index(): Void {
		$showLst = $this->modStack->readModel('listing');
		// Filter results
		// Data in here should be strTyped Variables object from inputReg
		// $reqVals = $this->inputReg->getRegVal('QUERY');
		// $this->inputReg['varname']['index']->get();
		$filters = $this->inputReg->getDefVal('filters', array());
		$catgFil = '';
		if(isset($filters['categ']) && is_string($filters['categ'])){
			$catgFil = $filters['categ'];
			unset($filters['categ']);
		}
		if(isset($filters['search'])){
			// A search is a match : the * of what is searched for is any text
			$itemFil = Array('title~'=>$filters['search']);
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
		if($showLst===false){
			$this->statCode = 500;
			$this->statMssg = 'An error occurred while processing Listing query';
			return;
		}
		$showOut = Array();
		$initRec = $this->modStack->readModel('single');
		foreach($showLst as $shwRec){
			$showRec = clone($initRec);
			$showArr = $showRec->loadRecrd($shwRec['id_show']); 
			if($catgFil!=''){
				if($showRec->hasCategy($catgFil)==false){
					continue;
				}
			}
			$showOut[] = $showArr;
			$showRec = null;
		}
		if(!empty($showOut)){
			$this->statCode = 200;
			$this->asOutput = $showOut;
		}else{
			$this->statCode = 204;
			$this->statMssg = 'Show Listing returned no results';
			$this->asOutput = $showOut;
		}
	}
	
	
	/**
	 * Loads a single show with its relations and sets it as output.
	 *
	 * @param String $currShowId Primary key of the show to load.
	 */
	public function view( String $currShowId ): Void {
		$showRec = $this->modStack->readModel('single');
		// Filter results
		$showIdn = $currShowId;
		$showArr = $showRec->loadRecrd($showIdn);
		//print_r($showArr);
		//die();
		
		if($showArr!==false && !empty($showArr)){
			$this->statCode = 200;
			$this->asOutput = $showArr;
		}else{
			$this->statCode = 404;
			$this->statMssg = 'Show not found';
		}
	}
}