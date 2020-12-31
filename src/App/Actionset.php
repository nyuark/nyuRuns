<?php

namespace nyu\App;

use nyu\Tools\Registry;

class Actionset {
	
	public 		$asOutput;
	public		$statCode;
	public		$statMssg;
	
	protected 	$inputReg;
	protected 	$modStack;
	
	function __construct( String $actionName, String $actnOption=null, Registry $inRegistry = null, ModelStack $modelStack = null){
		$this->inputReg = $inRegistry;
		$this->modStack = $modelStack;
		$this->runAction( $actionName, $actnOption );
	}
	
	function runAction( String $actionName, String $actnOption=null ){
		if( method_exists($this,"$actionName") ){
			if($actnOption!=null){
				call_user_func( array($this, $actionName), $actnOption );
			}else{
				call_user_func( array($this, $actionName) );
			}
		}else{
			$this->defAction();
		}
	}
	
	function defAction(){
		// Raise error
		$this->statCode = '500';
		$this->statMssg = 'Unknown Action';
		$this->resValue = null;
		return false;
	}

	function checkList( Array $resultsArr ){
		if($resultsArr!==false){
			if(!empty($resultsArr)){
				$this->statCode = 200;
				$this->asOutput = $resultsArr;
			}else{
				$this->statCode = 204;
				$this->statMssg = 'List returned no results';
				$this->asOutput = $resultsArr;
			}
		}else{
			$this->statCode = 500;
			$this->statMssg = 'An error occured while processing query for this list';
		}
	}
}