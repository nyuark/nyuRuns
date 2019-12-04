<?php

namespace nyu;

use \nyu\Tools\Preset;
use \nyu\App\Controller;
use nyu\Tools\Register;

$GLOBALS['nyuConfVars'] = null;

class Runner{

	protected 	$ezPreset;	// Preset (RPS) Object
	protected	$setupCon;	// Loader Container
//	protected	$confVars;	// Config Register
	protected 	$inputLib;	// Library (Register) of allowed Inputs
	protected 	$routeReg;	// Route Register
	protected 	$axessCon;	// Access Container *TODO*
	protected 	$appBlock;	// Application Object
	protected 	$template;	// Output Container
	
	public 		$errorObj;
	public 		$procStat;
	public 		$logStore;

	function __construct( String $initPreset = '', Array $overLoaded = Array() ){
		// Load the preset object and call setup
		$this->ezPreset = new Preset($initPreset);
		
		// Prepare setup and overload when required
		$this->setupCon = $this->ezPreset->generate();
		if(!empty($overLoaded)){
			$this->setupCon->overLoads($overLoaded, true);
		}
		$GLOBALS['nyuConfVars'] = $this->setupCon->getConfig();
		$this->loadSetup();
		// TODO : Logging, Status update
	}

	function loadSetup( ){
		// Get required configuration files
		// and build all required objects for 
		// running the sequence, creating the
		// containers and registers needed
		
		$this->inputLib = $this->setupCon->getInputs();
		$this->routeReg = $this->setupCon->loadRoute();
		$this->axessCon = $this->setupCon->setAccess();
	//	$this->template = $this->setupCon->setOutput();
	
		$this->processor();
	}
	/*
	function inputData( ){
		// Parse input from selected sources
		// to return an array or object of data
		// That will be exposed to the apps
	}
	
	function findRoute( ){
		// Identify route from path / request
		// or manually force from filename & params
		// to retrieve application to load
	}
	
	function chkAccess( ){
		// Verify access according to method,
		// send to authentication if needed
		// or deny access when no match
	}
	*/
	function appCaller( String $runAppName, String $runAppActn, Register $inRegister ){
		// Launch an application according to
		// the loaded route, pass input data,
		// and end up with expected results,
		// then pass them to the output
		$conTrol = new Controller( $runAppName, $runAppActn, $inRegister );
	}
	
	function getLayout( ){
		// Retrieve the predefined layout or
		// format for the response contents
	}
	
	function doRespond( ){
		// Build the response headers and
		// output the contents to the requester
	}
		
	function processor( ){
		// call each of the method needed
	//	$this->inputData();
	//	$this->findRoute();
	//	$this->chkAccess();
		$appCall = $this->routeReg->getRegVal('app');
		$actCall = $this->routeReg->getRegVal('action');
		$this->appCaller($appCall, $actCall, $this->inputLib);
	}
}