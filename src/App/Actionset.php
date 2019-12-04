<?php

namespace nyu\App;

use nyu\Tools\Register;

class Actionset {
	
	public 		$asOutput;
	public		$statCode;
	public		$statMssg;
	
	protected 	$inputReg;
	protected 	$modStack;
	
	function __construct( String $actionName, Register $inRegister = null, ModelStack $modelStack = null){
		$this->inputReg = $inRegister;
		$this->modStack = $modelStack;
		$this->runAction( $actionName );
	}
	
	function runAction( String $actionName){
		if( method_exists($this,"$actionName") ){
			call_user_func( array($this, $actionName) );
		}else{
			$this->defAction();
		}
	}
	
	function defAction(){
		// Raise error
		$this->statCode = '500';
		$this->statCode = 'Unknown Action';
		$this->resValue = null;
		return false;
	}
}