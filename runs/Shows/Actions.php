<?php

namespace nyu\Runs\Shows;

use \nyu\App\Actionset;

class Actions extends Actionset {
	
	public function index(){
		$showLst = $this->modStack->readModel('list');
		// Filter results
		$filters = $this->inputReg->getRegVal('filters');
		$showLst->lstSearch($filters);
		// Sort results
		$sorting = $this->inputReg->getRegVal('sorting');
		$showLst->listOrder($sorting);
		// Limit results
		$indexes = $this->inputReg->getRegVal('indexes');
		$showLst->listLimit($indexes);
		
		$showOut = $showLst->listItems();
		
		$this->asOutput = $showOut;
	}
}