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
	* @param String $rulesIndex Name of the class the rule is for.
	* @param Array  $initParams Constructor parameters of the shared object, if any.
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
	* @param String $objectName Name of the class to create.
	* @param ?Array $shareParms Constructor parameters that make the object a shared one, null for a plain object.
	* @param Array  $constrArgs Arguments passed to the constructor.
	* @return Object The created object.
	*/
    public function makeObjct(String $objectName, ?Array $shareParms=null, Array $constrArgs=Array() ): Object {
		if($shareParms!==null){
			$this->shareRule($objectName, $shareParms);
		}
		$rsltObj = $this->containr->create($objectName, $constrArgs);
		return $rsltObj;
	}
}
