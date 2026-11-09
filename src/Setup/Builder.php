<?php

namespace nyu\Setup;

use \Dice\Dice; // DICon
/*
use \nyu\Tools\ClientReg;
use \nyu\Tools\Request;
use \nyu\Output\Response;
*/

/**
 * A dependency injection container to instantiate utility classes
 * (config, request, response, etc.).
 */
class Builder{

	protected $diceObject;

	/**
	* Instanciates the dependency injection container
	*
	*/
    public function __construct( ){
		$diceCon = new Dice();
		$this->diceObject = $diceCon;
	}

	/**
	* Defines rules for a shared rule object
	*
	* @param String $rulesIndxTxt Name of the class the rule is for.
	* @param Array  $initParamtrs Constructor parameters of the shared object, if any.
	*/
    protected function shareSetupRule(String $rulesIndxTxt, Array $initParamtrs = Array()): Void {
		$shrtVls['shared'] = true;
		if(!empty($initParamtrs)){
			$shrtVls['constructParams'] = $initParamtrs;
		}
		$this->diceObject = $this->diceObject->addRule( $rulesIndxTxt, $shrtVls );
	}

	/**
	* Creates and return an object
	*
	* @param String $setupObjName Name of the class to create.
	* @param ?Array $sharedParams Constructor parameters that make the object a shared one, null for a plain object.
	* @param Array  $cnstrArgmnts Arguments passed to the constructor.
	* @return Object The created object.
	*/
    public function generateObject(String $setupObjName, ?Array $sharedParams=null, Array $cnstrArgmnts=Array() ): Object {
		if($sharedParams!==null){
			$this->shareSetupRule($setupObjName, $sharedParams);
		}
		$rsltObj = $this->diceObject->create($setupObjName, $cnstrArgmnts);
		return $rsltObj;
	}
}
