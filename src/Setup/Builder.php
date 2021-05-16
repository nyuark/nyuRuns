<?php

// A dependency injection container to instanciate
// utility classes (config, request, response, etc.)

namespace nyu\Setup;

use \Dice\Dice; // DICon
/*
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
		$diceCon = new Dice();
		$this->containr = $diceCon;
	}

	/**
	* Defines rules for a shared rule object
	*
	* @Param String	$setupArray
	* @Param Array	$initParams
	*/
    protected function shareRule(String $rulesIndex, Array $initParams = Array()): Void {
		$shrVals['shared'] = true;
		if(!empty($initParams)){
			$shrVals['constructParams'] = $initParams;
		}
		$this->containr = $this->containr->addRule( $rulesIndex, $shrVals );
	}

	/**
	* Creates and return an object
	*
	* @Param String	$objectName
	*/
    public function makeObjct(String $objectName, ?Array $shareParms=null, Array $constrArgs=Array() ): Object {
		if($shareParms!==null){
			$this->shareRule($objectName, $shareParms);
		}
		$rsltObj = $this->containr->create($objectName, $constrArgs);
		return $rsltObj;
	}
}
