<?php

namespace nyu;

use nyu\Setup\Preset;
use nyu\App\Controller;
use nyu\Tools\Registry;
use nyu\Setup\Builder;
use nyu\Access\Checker;

$GLOBALS['nyuConfig'] = null;

class Runner{

	protected 	$nyPreset;	// RPS (Runner PreSet) Object
	protected	$setupCon;	// Loader Container (Configuration)
	protected	$shareCon;	// Shared Container (client and server values)
	protected	$httpRqst;	// Initial HTTP Request
	protected 	$routeReg;	// Route Registry
	protected 	$inputLib;	// Library (Registry) of allowed Inputs
	protected 	$axessCon;	// Access Container (Access\Checker result)
	protected 	$appBlock;	// Application Object
	protected 	$response;	// Output Container

	public 		$errorObj;
	public 		$procStat;
	public 		$logStore;

	/**
	 * Loads the given preset, builds the shared objects (config, request,
	 * response), then sets up routing and access before running the request.
	 *
	 * @param String $initPreset Name of the .rps preset file to load (defaults to 'default').
	 * @param Array  $overLoaded Configuration values to merge over the loaded preset.
	 */
	function __construct( String $initPreset = '', Array $overLoaded = Array() ){
		try{
			// Load the preset object and call setup
			$this->nyPreset = new Preset($initPreset);

			// Prepare setup and overload when required
			$this->setupCon = $this->nyPreset->makeSetup();
			if(!empty($overLoaded)){
				$this->setupCon->overLoads($overLoaded, true);
			}
			// Load Shared Data (conf, request, response)
			$GLOBALS['nyuConfig'] = $this->setupCon->getConfig();
			$this->loadShare();

			// Wire the logger into PHP's error/exception/shutdown handling
			$GLOBALS['nyuLogger'] = $this->logStore;
			$GLOBALS['ErrorResponse'] = Array($this, 'failReply');
			require_once TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php';
			initError();
		}catch( \Throwable $e ){
			// Nothing can format or log an error yet
			$this->bootFails($e);
		}

		// Setup route & access, then process
		$this->loadSetup();
	}

	/**
	 * Answers a failure that happens before the error handler is installed:
	 * the details go to PHP's error log, the client gets a plain JSON 500.
	 *
	 * @param \Throwable $bootExcptn What was thrown during start-up.
	 */
	protected function bootFails( \Throwable $bootExcptn ): Void {
		error_log('nyuRuns start-up failure: '.get_class($bootExcptn).': '.$bootExcptn->getMessage().' in '.$bootExcptn->getFile().' on line '.$bootExcptn->getLine());
		if( !headers_sent() ){
			http_response_code(500);
			header('Content-Type: application/json; charset=utf-8');
		}
		print(json_encode( Array('error'=>'Internal Server Error', 'code'=>500) ));
		exit();
	}

	/**
	 * Instanciates the shared objects (server/client registries, request,
	 * response) through the DI container so they're available throughout
	 * the whole running process.
	 */
	function loadShare( ): Void {
		// Instanciate the shared objects to
		// be available during all the running
		// process through DI Container

		$contObj = new Builder();
		$contObj->makeObjct('nyu\\Tools\\ServerReg', array());
		$contObj->makeObjct('nyu\Tools\ClientReg', array());
		$rndrCnf = $this->setupCon->getRegKey('rnder');
		$this->response = $contObj->makeObjct('nyu\\Output\\Response', array(), Array($rndrCnf ?: Array()));
		$this->httpRqst = $contObj->makeObjct('nyu\\Tools\\Request', array());

		$logConf = $this->setupCon->logConfig();
		$this->logStore = new \nyu\Tools\Logger( $logConf['path'], 'runner', $logConf['mode'], $logConf['size'], $logConf['rota'], $logConf['pref'] );

		$this->shareCon = $contObj;
	}

	/**
	 * Retrieves the configuration files and builds the registries needed to
	 * run the request (inputs, route, access), then triggers the processor.
	 */
	function loadSetup( ): Void {
		// Get required configuration files
		// and build all required objects for
		// running the sequence and the Registrys needed

		// Inputs are defined at an application level
		$this->inputLib = $this->setupCon->getInputs( $this->httpRqst );

		$this->routeReg = $this->setupCon->loadRoute( $this->httpRqst );

		$axsConf = $this->setupCon->setAccess();
		$this->axessCon = $this->chkAccess( $axsConf );

		if( $this->axessCon->getDefVal('statCode', 200) == 200 ){
			$this->processor();
		}else{
			$this->denyAxess( $this->axessCon );
		}
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

	/**
	 * Identifies the route from the path/request, or forces it manually
	 * from a filename and parameters, in order to retrieve the application
	 * to load.
	 *
	 * @todo Not yet implemented — routing currently happens in Setup\Loader::loadRoute().
	 */
	function findRoute( ): Void {
		// Identify route from path / request
		// or manually force from filename & params
		// to retrieve application to load
	}

	/**
	 * Verifies access for the current request against the configured
	 * auth/sess strategy.
	 *
	 * @param Array $accessConf Resolved 'auths' config block for the active preset.
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys.
	 */
	function chkAccess( Array $accessConf ): Registry {
		$chkObjc = new Checker( $accessConf, $this->httpRqst, $this->routeReg );
		return $chkObjc->verifyAxs();
	}

	/**
	 * Sends an access-denial response (401/403/500) directly, without
	 * ever constructing the requested application's Controller/Actionset.
	 *
	 * @param Registry $accessRslt The Access\Checker result carrying the denial status/message.
	 */
	function denyAxess( Registry $accessRslt ): Void {
		$wwwAuth = $accessRslt->readValue('wwwAuth');
		if( $wwwAuth!=null ){
			$this->response->addHeader('WWW-Authenticate', $wwwAuth);
		}
		$this->setStatus( $accessRslt->getDefVal('statCode', 401), strval($accessRslt->readValue('statMssg')) );
		$this->doRespond( $this->procStat );
	}

	/**
	 * Launches an application according to the loaded route, passing it the
	 * input data, and forwards its status and results to the output layout.
	 *
	 * @param String $runAppName Name of the application (run) to load.
	 * @param String $runAppActn Name of the action/method to call on the application.
	 * @param String $runAppOptn Optional extra parameter passed to the action.
	 */
	function appCaller( String $runAppName, String $runAppActn, ?String $runAppOptn=null ): Void {
		// Launch an application according to
		// the loaded route, pass input data,
		// and end up with expected results,
		// then pass them to the output
		$conTrol = new Controller( $runAppName, $runAppActn, $this->inputLib, $runAppOptn, $this->axessCon, $this->httpRqst, $this->response );
		$statArr = $conTrol->getStatus();
		$this->setStatus( $statArr['code'], $statArr['mssg'], $conTrol->getOutput() );
	}

	/**
	 * Retrieves the predefined layout/format and builds the response
	 * template with the given output data.
	 *
	 * @param Array|Object|String|Int|Float|Bool $dataValues The data returned by the application to format into the response.
	 * @return Bool False when the data could not be formatted; an error body was built instead.
	 */
	function getLayout( Array|Object|String|Int|Float|Bool $dataValues ): Bool {
		// Retrieve the predefined layout or
		// format for the response contents
		$isBuilt = $this->response->formatOut( $dataValues );
		$this->response->buildTmpl();
		return $isBuilt;
	}

	/**
	 * Prepares the response body for a status and records the status to
	 * send: the output data for a 2xx status, an error body otherwise.
	 *
	 * @param Int    $statusCode Status reported by the application or the access check.
	 * @param String $statusMssg Message describing a non-2xx status.
	 * @param mixed  $dataValues Output data for a 2xx status.
	 */
	protected function setStatus( Int $statusCode, String $statusMssg='', Mixed $dataValues=null ): Void {
		// "No content" without any output is sent as such : no body, and no warnings
		if( $statusCode==204 && $dataValues===null ){
			$this->response->clearBody();
			$this->procStat = 204;
			return;
		}
		$success = ($statusCode>=200 && $statusCode<300);
		if( $success ){
			// "No content" carrying an output is sent as a plain 200 with it
			if( $statusCode==204 ){
				$statusCode = 200;
			}
			$bodyArr = $dataValues ?? Array();
		}else{
			$bodyArr = Array('error'=>$statusMssg, 'code'=>$statusCode);
		}

		// Warnings and notices collected by the error handler (only when errors may be shown)
		$hndlObj = $GLOBALS['ErrorHandler'] ?? null;
		$warnLst = ($hndlObj!==null) ? $hndlObj->takeStack() : Array();
		if( !empty($warnLst) ){
			if( $success ){
				$bodyArr = Array('data'=>$bodyArr, 'warnings'=>$warnLst);
			}else{
				$bodyArr['warnings'] = $warnLst;
			}
		}

		// Data that cannot be formatted is an error of the application
		if( !$this->getLayout( $bodyArr ) ){
			$statusCode = 500;
		}
		$this->procStat = $statusCode;
	}

	/**
	 * Sends a 500 response carrying the given message, in the configured
	 * output format. Called by the error handler for uncaught exceptions.
	 *
	 * @param String $errMessage Message to expose in the response body.
	 */
	public function failReply( String $errMessage ): Void {
		$this->setStatus( 500, $errMessage );
		$this->doRespond( $this->procStat );
	}

	/**
	 * Builds the response headers and outputs the final response contents
	 * to the requester. A HEAD request is answered by the headers alone.
	 *
	 * @param Int $statusCode HTTP status code to send; omitted for the default 200.
	 */
	function doRespond( ?Int $statusCode=null ): Void {
		// Build the response headers and
		// output the contents to the requester
		$this->response->buildHead( $statusCode );
		// A HEAD request is answered by its headers alone, whatever the web server does with a body
		if( $this->httpRqst!==null && $this->httpRqst->usedMethd('HEAD') ){
			return;
		}
		$this->response->sendOutpt();
	}

	/**
	 * Runs the request-handling sequence: reads the resolved app/action/param
	 * from the route registry, calls the application, and sends the response.
	 */
	function processor( ): Void {
		$appCall = $this->routeReg->readValue('app');
		$actCall = $this->routeReg->readValue('action');
		$actOptn = $this->routeReg->getDefVal('param', null);

		/*	Place input loading at appCaller level	*/
		//$this->inputData($appCall, $actCall);

		$rteStat = $this->routeReg->getDefVal('statCode', 200);
		if( $rteStat!=200 ){
			// The route answers by itself : method refused, or OPTIONS
			$alwList = $this->routeReg->readValue('allowed');
			if( $alwList!=null ){
				$this->response->addHeader('Allow', $alwList);
			}
			$this->setStatus( $rteStat, strval($this->routeReg->readValue('statMssg')) );
		}elseif( $appCall==null ){
			// No route resolved for this request
			$this->setStatus( 404, 'Unknown Application' );
		}else{
			$this->appCaller($appCall, strval($actCall), $actOptn);
		}
		$this->doRespond( $this->procStat );
		exit();
	}
}
