<?php

namespace nyu;

use nyu\Setup\Preset;
use nyu\App\Controller;
use nyu\Tools\Registry;
use nyu\Tools\Logger;
use nyu\Setup\Builder;
use nyu\Access\Checker;

$GLOBALS['nyuConfig'] = null;

/**
 * The entry of every request: loads the preset and the config, reads the
 * request and its route, checks the access, calls the application and
 * sends the reply.
 */
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
	 * @param String $initPreset Name of the .rps preset file to load (defaults to 'dfault').
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

			// Wire the logger into PHP's error/exception/shutdown handling, on its own channel
			$GLOBALS['nyuLogger'] = Logger::getLogger('error');
			$GLOBALS['ErrorResponse'] = Array($this, 'failReply');
			require_once TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php';
			// The errors PHP reports are those of ERR_LEVL; one that cannot be read stops the start-up
			$GLOBALS['ErrorLevel'] = $this->setupCon->errConfig();
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
			header('X-Content-Type-Options: nosniff');
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
		// The client address is seen through the reverse proxies the config trusts
		$contObj->makeObjct('nyu\Tools\ClientReg', array(), Array(true, $this->setupCon->proxyConf()));
		$rndrCnf = $this->setupCon->getRegKey('rnder');
		$this->response = $contObj->makeObjct('nyu\\Output\\Response', array(), Array($rndrCnf ?: Array()));
		// A body larger than the config allows is not read
		$this->httpRqst = $contObj->makeObjct('nyu\\Tools\\Request', array(), Array($this->setupCon->reqConfig()));
		// Another site is named in the reply only when the preset lists it
		$this->response->setOrigin( $this->httpRqst->getHeader('Origin') );

		// Every log channel is built with this configuration; the Runner writes on its own
		Logger::plugSetup( $this->setupCon->logConfig() );
		$this->logStore = Logger::getLogger('runner');

		// Plug the program that unpacks sounds, where the server has one
		$sndConf = $this->setupCon->sndConfig();
		if( $sndConf['tool']!=='' ){
			\nyu\Data\Sound::plugCodec( $contObj->makeObjct('nyu\\Data\\Codec', array(), Array($sndConf['tool'], $sndConf['time'])) );
		}

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

		// A request larger than allowed is refused before anything is done with it
		if( $this->httpRqst->overLimit() ){
			$clntReg = $this->httpRqst->getClient();
			$usrAddr = ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '';
			$this->logStore->warning( 'Request refused'.(($usrAddr!='') ? ' from '.$usrAddr : '').': body larger than allowed (REQ_SIZE, REQ_PART)' );
			$this->setStatus( 413, 'Payload Too Large' );
			$this->doRespond( $this->procStat );
			return;
		}

		// The preset tells the sources of the inputs; the definition an application
		// gives for an action (input/<action>.json) is applied by its Controller
		$this->inputLib = $this->setupCon->getInputs( $this->httpRqst );

		$this->routeReg = $this->setupCon->loadRoute( $this->httpRqst );

		$axsConf = $this->setupCon->setAccess();
		$this->axessCon = $this->chkAccess( $axsConf );

		// The events traced from here on are signed with the user and the address of the request
		$clntReg = $this->httpRqst->getClient();
		Logger::plugActor( strval($this->axessCon->readValue('user')), ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '' );
		$this->logUpload( $this->httpRqst->listFiles() );

		if( $this->axessCon->getDefVal('statCode', 200) == 200 ){
			$this->processor();
		}else{
			$this->denyAxess( $this->axessCon );
		}
	}

	/**
	 * Traces the files received with the request, whether access is
	 * granted or not: one entry per file, and a warning for an upload
	 * that failed. A file field left empty is not traced.
	 *
	 * @param Array  $filesArray File entries, as Request::listFiles() gives them.
	 * @param String $fieldLabel Name the entries were sent under, for the nested ones.
	 */
	protected function logUpload( Array $filesArray, String $fieldLabel='' ): Void {
		foreach( $filesArray as $fleKey=>$fleVal ){
			if( !is_array($fleVal) ){
				continue;
			}
			$lblText = ($fieldLabel=='') ? strval($fleKey) : $fieldLabel.'['.$fleKey.']';
			if( !array_key_exists('error', $fleVal) || !array_key_exists('name', $fleVal) ){
				// Files sent under a bracketed name are nested
				$this->logUpload( $fleVal, $lblText );
				continue;
			}
			if( is_array($fleVal['error']) ){
				// Several files under one name, as PHP describes them : one entry is rebuilt for each
				$subList = Array();
				foreach( array_keys($fleVal['error']) as $subKey ){
					$subList[$subKey] = Array('name'=>$fleVal['name'][$subKey] ?? '', 'type'=>$fleVal['type'][$subKey] ?? '', 'size'=>$fleVal['size'][$subKey] ?? 0, 'error'=>$fleVal['error'][$subKey]);
				}
				$this->logUpload( $subList, $lblText );
				continue;
			}
			$errCode = intval($fleVal['error']);
			if( $errCode==UPLOAD_ERR_NO_FILE ){
				continue;
			}
			// The names are the client's own : written as JSON texts
			$nameTxt = json_encode(strval($fleVal['name']), JSON_INVALID_UTF8_SUBSTITUTE).' as '.json_encode($lblText, JSON_INVALID_UTF8_SUBSTITUTE);
			if( $errCode==UPLOAD_ERR_OK ){
				$this->logStore->info( 'File received: '.$nameTxt.' ('.strval($fleVal['type'] ?? '').', '.intval($fleVal['size'] ?? 0).' bytes)'.Logger::actorText() );
			}else{
				$this->logStore->warning( 'File upload failed: '.$nameTxt.', error '.$errCode.Logger::actorText() );
			}
		}
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
	 * Sends an access-denial response (401/403/429/500), or the redirection
	 * to the sign-in route, directly, without ever constructing the
	 * requested application's Controller/Actionset.
	 *
	 * @param Registry $accessRslt The Access\Checker result carrying the denial status/message.
	 */
	function denyAxess( Registry $accessRslt ): Void {
		// A visitor denied a page is sent to the sign-in route, in place of the error
		$goalUrl = $accessRslt->readValue('denyGoal');
		if( $goalUrl!=null ){
			$this->response->addHeader('Location', strval($goalUrl));
			$this->setStatus( 302 );
			$this->doRespond( $this->procStat );
			return;
		}
		$wwwAuth = $accessRslt->readValue('wwwAuth');
		if( $wwwAuth!=null ){
			$this->response->addHeader('WWW-Authenticate', $wwwAuth);
		}
		// A held address is told how long to wait
		$retrAft = $accessRslt->readValue('retryAft');
		if( $retrAft!=null ){
			$this->response->addHeader('Retry-After', strval($retrAft));
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
	 * @param ?String $runAppOptn Optional extra parameter passed to the action.
	 */
	function appCaller( String $runAppName, String $runAppActn, ?String $runAppOptn=null ): Void {
		// Launch an application according to
		// the loaded route, pass input data,
		// and end up with expected results,
		// then pass them to the output
		$conTrol = new Controller( $runAppName, $runAppActn, $this->inputLib, $runAppOptn, $this->axessCon, $this->httpRqst, $this->response, $this->setupCon->getRouter() );
		$statArr = $conTrol->getStatus();
		$this->setStatus( $statArr['code'], $statArr['mssg'], $conTrol->getOutput() );
	}

	/**
	 * Writes the body of the response in its format (the 'format' of the
	 * preset, or the one the action chose).
	 *
	 * @param Array|Object|String|Int|Float|Bool $dataValues The data returned by the application to format into the response.
	 * @param Bool $errorReply Whether the data is the body of an error.
	 * @return Bool False when the data could not be formatted; an error body was built instead.
	 */
	function getLayout( Array|Object|String|Int|Float|Bool $dataValues, Bool $errorReply=false ): Bool {
		return $this->response->formatOut( $dataValues, $errorReply );
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
		// A redirection is its Location header : no body either
		if( $statusCode>=300 && $statusCode<400 ){
			$this->response->clearBody();
			$this->procStat = $statusCode;
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
		// A table or raw output has no place for them : they stay in the log
		if( !empty($warnLst) && ($this->response->takesWarn() || !$success) ){
			if( $success ){
				$bodyArr = Array('data'=>$bodyArr, 'warnings'=>$warnLst);
			}else{
				$bodyArr['warnings'] = $warnLst;
			}
		}

		// Data that cannot be formatted is an error of the application
		if( !$this->getLayout( $bodyArr, !$success ) ){
			$statusCode = 500;
		}
		if( !$success ){
			$this->htmlError( $statusCode, $statusMssg );
		}
		$this->procStat = $statusCode;
	}

	/**
	 * Answers an error with the HTML page the site has for its status
	 * (see Router::errorPage), in place of the error body: when the
	 * request asked for a page or a file, or for nothing a route answers
	 * in a preset that serves pages. A request for an action keeps the
	 * error body of the preset. The page gets the status as %code% and
	 * the message as %error%.
	 *
	 * @param Int    $statusCode Status of the reply.
	 * @param String $statusMssg Message describing it.
	 */
	protected function htmlError( Int $statusCode, String $statusMssg ): Void {
		$rtrObjc = ($this->setupCon!==null) ? $this->setupCon->getRouter() : null;
		if( $rtrObjc===null || $this->routeReg===null ){
			return;
		}
		$rteKind = $this->routeReg->readValue('rteKind');
		$noRoute = ( $rteKind==null && $this->routeReg->readValue('app')==null && strval($this->routeReg->readValue('rtePath'))=='' );
		if( !$noRoute && !in_array($rteKind, Array('htpage', 'static'), true) ){
			return;
		}
		$pagePth = $rtrObjc->errorPage($statusCode);
		if( $pagePth!==null ){
			$this->response->pageReply( $pagePth, Array('code'=>$statusCode, 'error'=>$statusMssg), $statusCode );
		}
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
	 * @param ?Int $statusCode HTTP status code to send; omitted for the default 200.
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
	 * Prepares the reply of a declared route that runs no action: a
	 * redirection, a static file (the Range header of the request is
	 * honoured, and a client that already holds the file is told so) or
	 * an HTML page, in which the values captured from the path
	 * fill the placeholders of their name.
	 */
	protected function rteAnswer(): Void {
		$rteKind = $this->routeReg->readValue('rteKind');
		$rteGoal = strval($this->routeReg->readValue('rteGoal'));
		if( $rteKind=='redrct' ){
			$this->response->addHeader('Location', $rteGoal);
			$this->setStatus( intval($this->routeReg->getDefVal('rteCode', 302)) );
			return;
		}
		if( $rteKind=='static' ){
			$isFound = $this->response->fileReply( $rteGoal, strval($this->routeReg->readValue('rteType')), strval($this->httpRqst->getHeader('Range')) );
			// The client may keep the file, and a proxy too when it is served to anyone
			$askHead = Array();
			foreach( Array('If-None-Match', 'If-Modified-Since', 'If-Range') as $hedKey ){
				$askHead[$hedKey] = strval($this->httpRqst->getHeader($hedKey));
			}
			$this->response->cacheFile( $askHead, !$this->axessCon->readValue('logged') );
		}else{
			$isFound = $this->response->pageReply( $rteGoal, (array)$this->routeReg->getDefVal('pathVars', Array()) );
		}
		if( $isFound ){
			$this->setStatus( 200 );
		}else{
			$this->setStatus( 404, 'File not found' );
		}
	}

	/**
	 * Runs the request-handling sequence: reads the resolved app/action/param
	 * from the route registry, calls the application or prepares the reply
	 * the route gives by itself, and sends the response.
	 */
	function processor( ): Void {
		$appCall = $this->routeReg->readValue('app');
		$actCall = $this->routeReg->readValue('action');
		$actOptn = $this->routeReg->getDefVal('param', null);

		$rteStat = $this->routeReg->getDefVal('statCode', 200);
		if( $rteStat!=200 ){
			// The route answers by itself : method refused, or OPTIONS
			$alwList = $this->routeReg->readValue('allowed');
			if( $alwList!=null ){
				$this->response->addHeader('Allow', $alwList);
			}
			$this->setStatus( $rteStat, strval($this->routeReg->readValue('statMssg')) );
		}elseif( in_array($this->routeReg->readValue('rteKind'), Array('redrct', 'static', 'htpage'), true) ){
			$this->rteAnswer();
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
