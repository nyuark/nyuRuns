<?php

// A dependency injection container to instanciate 
// utility classes (config, request, response, etc.)

namespace nyu\Setup;

use \Dice\Dice; // DICon
/*
use \nyu\Tools\ServerReg;
use \nyu\Tools\ClientReg;
use \nyu\Tools\Request;
use \nyu\Output\Response;
*/

class Builder{
	
	protected $containr;
	
	/**
	* Instanciates the dependency injection container
	* 
	*/
    public function __construct( ){
		$diceCont = new Dice();
		$this->containr = $diceCont;
	}
	
	/**
	* Defines rules for a shared object
	* 
	* @Param String	$setupArray
	* @Param Array	$initParams
	*/
    protected function shareRule(String $rulesIndex, Array $initParams = Array()){
		$shreVals['shared'] = true;
		if(!empty($initParams)){			
			$shreVals['constructParams'] = $initParams;
		}
		$shareArr[$rulesIndex] = $shreVals;
		$this->containr->addRule( $shareArr );
	}
	
	/**
	* Creates and return an object
	* 
	* @Param String	$objectName
	*/
    public function makeObjct(String $objectName, $shareParams=null ){
		if($shareParams!=null){
			$this->shareRule($objectName, $shareParams);
		}
		$rsltObj = $this->containr->create($objectName);
		return $rsltObj;
	}
}