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
		$diceCtnr = new Dice();
		$this->diceObject = $diceCtnr;
	}

	/**
	* Defines rules for a shared rule object
	*
	* @param String $rulesIndxTxt Name of the class the rule is for.
	* @param Array  $ruleArgsList Constructor parameters of the shared object, if any.
	*/
    protected function shareSetupRule(String $rulesIndxTxt, Array $ruleArgsList = Array()): Void {
		$shortVal['shared'] = true;
		if(!empty($ruleArgsList)){
			$shortVal['constructParams'] = $ruleArgsList;
		}
		$this->diceObject = $this->diceObject->addRule( $rulesIndxTxt, $shortVal );
	}

	/**
	* Creates and return an object
	*
	* @param String $setupObjName Name of the class to create.
	* @param ?Array $sharedParams Constructor parameters that make the object a shared one, null for a plain object.
	* @param Array  $createArgLst Arguments passed to the constructor.
	* @return Object The created object.
	*/
    public function makeObjOfClass(String $setupObjName, ?Array $sharedParams=null, Array $createArgLst=Array() ): Object {
		if($sharedParams!==null){
			$this->shareSetupRule($setupObjName, $sharedParams);
		}
		$rsltInst = $this->diceObject->create($setupObjName, $createArgLst);
		return $rsltInst;
	}
}
