<?php

>

use nyu\Tools\Preset;
use nyu\App\Controller;
use nyu\Tools\Registry;
use nyu\Setup\Builder;

$GLOBALS['nyuConfig'] = null;

class Runner{

	protected 	$nyPreset;	// RPS (Runner PreSet) Object
	protected	$setupCon;	// Loader Container
//	protected	$shareCon;	// Shared Container ??? WHAT FOR? Smokalaka!
	protected	$httpRqst;	// Initial HTTP Request
//	protected	$confVars;	// Config Registry
	protected 	$routeReg;	// Route Registry
	protected 	$inputLib;	// Library (Registry) of allowed Inputs
	protected 	$axessCon;	// Access Container *TODO*
	protected 	$appBlock;	// Application Object
	protected 	$response;	// Output Container
	
	public 		$errorObj;
	public 		$procStat;
	public 		$logStore;

	function __construct( String $initPreset = '', Array $overLoaded = Array() ){
		// Load the preset object and call setup
		$this->nyPreset = new Preset($initPreset);
		
		// Prepare setup and overload when required
		$this->setupCon = $this->nyPreset->generate();
		if(!empty($overLoaded)){
			$this->setupCon->overLoads($overLoaded, true);
		}
		// Load Shared Data (conf, request, response)
		$GLOBALS['nyuConfig'] = $this->setupCon->getConfig();
		$this->loadShare();
		
		// Setup route & access, then process
		$this->loadSetup();
		// TODO : Logging, Status update
	}

	function loadShare( ){ 
		// Instanciate the shared objects to 
		// be available during all the running
		// process through DI Container
		
		$contObj = new Builder();
		$contObj->makeObjct('nyu\\Tools\\ServerReg', array());
	//	$contObj->makeObjct('nyu\\Tools\\ClientReg', array());
		$this->httpRqst = $contObj->makeObjct('nyu\\Tools\\Request', array());
		$this->response = $contObj->makeObjct('nyu\\Output\\Response', array());
		$this->shareCon = $contObj;
	}

	function loadSetup( ){
		// Get required configuration files
		// and build all required objects for 
		// running the sequence and the Registrys needed
		
		// Inputs are defined at an application level
		//$this->inputLib = $this->setupCon->getInputs();
		
		$this->routeReg = $this->setupCon->loadRoute();
		$this->axessCon = $this->setupCon->setAccess();
	
		$this->processor();
	}
	/*	Place input loading at appCaller level	*/
	/*
	function inputData( String $runAppName, String $runAppActn ){
		// Parse input from selected sources
		// to return an array or object of data
		// That will be exposed to the apps
		
		// Gets specific app action setup and overload setup
		
		
		// If unexisting, load all untyped inputs (from request)
		
		// Then, call the setup input parser to get a lib of vars
		$this->inputLib = $this->setupCon->getInputs();
	}
	*/
	
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
	
	function appCaller( String $runAppName, String $runAppActn, String $runAppOptn=null ){
		// Launch an application according to
		// the loaded route, pass input data,
		// and end up with expected results,
		// then pass them to the output
		$conTrol = new Controller( $runAppName, $runAppActn, $this->inputLib, $runAppOptn );
		$outPuts = $conTrol->getOutput();
		if(!isset($outPuts[0]) || $outPuts[0]!='err'){
			$this->getLayout( $outPuts );
		}else{
			// TODO: Raise exception/error
			print('<h1>App error</h1>');
			print_r($outPuts);
			die();
		}
	}
	
	function getLayout( $dataVals ){
		// Retrieve the predefined layout or
		// format for the response contents
		$this->response->formatOut( $dataVals );
		$this->response->buildTmpl();
		
	}
	
	function doRespond( ){
		// Build the response headers and
		// output the contents to the requester
		$this->response->buildHead();
		$this->response->sendOutpt();
	}
		
	function processor( ){
		// call each of the method needed
	//	$this->findRoute();
	//	$this->chkAccess();
		// print_R($this);
		// die();
		$appCall = $this->routeReg->readValue('app');
		$actCall = $this->routeReg->readValue('action');
		$actOptn = $this->routeReg->getDefVal('param', null);
		$resPond = $this->setupCon->getRegKey('rnder');
		
		/*	Place input loading at appCaller level	*/
		//$this->inputData($appCall, $actCall);
		
		$this->appCaller($appCall, $actCall, $actOptn);
		$this->doRespond();
	}
}