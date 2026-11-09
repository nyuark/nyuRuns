<?php

namespace nyu;

use nyu\Setup\Preset;
use nyu\Setup\Loader;
use nyu\Setup\ConfCache;
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

	protected 	$presetUsed;	// RPS (Runner PreSet) Object
	protected	$confLoader;	// Loader Container (Configuration)
	protected	$sharedObjs;	// Shared Container (client and server values)
	protected	$httpRequst;	// Initial HTTP Request
	protected 	$routRgstry;	// Route Registry
	protected 	$inputLbrry;	// Library (Registry) of allowed Inputs
	protected 	$rqstAccCon;	// Access Container (Access\Checker result)
	protected 	$reqAppBlck;	// Application Object
	protected 	$requestRsp;	// Output Container

	public 		$errrObject;
	public 		$procssStts;
	public 		$runnerLogs;

	/**
	 * Loads the given preset, builds the shared objects (config, request,
	 * response), then sets up routing and access before running the request.
	 *
	 * @param String $presetToLoad Name of the .rps preset file to load (defaults to 'defaults').
	 * @param Array  $overrideConf Configuration values to merge over the loaded preset.
	 */
	function __construct( String $presetToLoad = '', Array $overrideConf = Array() ){
		try{
			// The config cache of the preset stands for its files, when there is one (CNF_CACH)
			$cachVls = ConfCache::readCacheArray($presetToLoad);
			if( $cachVls!==null ){
				$this->confLoader = new Loader( $cachVls['preset'], $cachVls, ($presetToLoad=='') ? 'defaults' : $presetToLoad );
			}else{
				// Load the preset object and call setup
				$this->presetUsed = new Preset($presetToLoad);
				$this->confLoader = $this->presetUsed->newSetupLoader();
			}

			// Overload when required
			if(!empty($overrideConf)){
				$this->confLoader->overSetupLoads($overrideConf, true);
			}
			// Load Shared Data (conf, request, response)
			$GLOBALS['nyuConfig'] = $this->confLoader->retrieveConfig();
			$this->loadSharedObjs();

			// Wire the logger into PHP's error/exception/shutdown handling, on its own channel
			$GLOBALS['nyuLogger'] = Logger::retrieveLogger('error');
			$GLOBALS['ErrorResponse'] = Array($this, 'replyWithError');
			require_once TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php';
			// The errors PHP reports are those of ERR_LEVL; one that cannot be read stops the start-up
			$GLOBALS['ErrorLevel'] = $this->confLoader->errorConfigNum();
			installHandler();
		}catch( \Throwable $e ){
			// Nothing can format or log an error yet
			$this->answerBootFail($e);
		}

		// Setup route & access, then process
		$this->loadSetupFiles();
	}

	/**
	 * Answers a failure that happens before the error handler is installed:
	 * the details go to PHP's error log, the client gets a plain JSON 500.
	 *
	 * @param \Throwable $botException What was thrown during start-up.
	 */
	protected function answerBootFail( \Throwable $botException ): Void {
		error_log('nyuRuns start-up failure: '.get_class($botException).': '.$botException->getMessage().' in '.$botException->getFile().' on line '.$botException->getLine());
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
	function loadSharedObjs( ): Void {
		// Instanciate the shared objects to
		// be available during all the running
		// process through DI Container

		$contObj = new Builder();
		$contObj->generateObject('nyu\\Tools\\ServerReg', array());
		// The client address is seen through the reverse proxies the config trusts
		$contObj->generateObject('nyu\Tools\ClientReg', array(), Array(true, $this->confLoader->proxySetupConf()));
		$rndrCnf = $this->confLoader->getRegistryKey('response');
		$this->requestRsp = $contObj->generateObject('nyu\\Output\\Response', array(), Array($rndrCnf ?: Array()));
		// A body larger than the config allows is not read
		$this->httpRequst = $contObj->generateObject('nyu\\Tools\\Request', array(), Array($this->confLoader->rqstConfigList()));
		// Another site is named in the reply only when the preset lists it
		$this->requestRsp->setReplyOrigin( $this->httpRequst->retrieveHeader('Origin') );

		// Every log channel is built with this configuration; the Runner writes on its own
		Logger::plugLogsConfig( $this->confLoader->logConfigArray() );
		$this->runnerLogs = Logger::retrieveLogger('runner');

		// Plug the program that unpacks sounds, where the server has one
		$sndConf = $this->confLoader->sndConfigArray();
		if( $sndConf['tool']!=='' ){
			\nyu\Data\Sound::plugSoundCodec( $contObj->generateObject('nyu\\Data\\Codec', array(), Array($sndConf['tool'], $sndConf['time'])) );
		}

		$this->sharedObjs = $contObj;
	}

	/**
	 * Retrieves the configuration files and builds the registries needed to
	 * run the request (inputs, route, access), then triggers the processor.
	 */
	function loadSetupFiles( ): Void {
		// Get required configuration files
		// and build all required objects for
		// running the sequence and the Registrys needed

		// A request larger than allowed is refused before anything is done with it
		if( $this->httpRequst->overCeilingFlg() ){
			$clntReg = $this->httpRequst->retrieveClient();
			$usrAddr = ($clntReg!==null) ? strval($clntReg->readFileDetail('userAddr')) : '';
			$this->runnerLogs->warning( 'Request refused'.(($usrAddr!='') ? ' from '.$usrAddr : '').': body larger than allowed (REQ_SIZE, REQ_PART)' );
			$this->saveStatusFlag( 413, 'Payload Too Large' );
			$this->sendFinalReply( $this->procssStts );
			return;
		}

		// The preset tells the sources of the inputs; the definition an application
		// gives for an action (input/<action>.json) is applied by its Controller
		$this->inputLbrry = $this->confLoader->retrieveInputs( $this->httpRequst );

		$this->routRgstry = $this->confLoader->importRouteReg( $this->httpRequst );
		// Read from its files, the config is cached for the next requests when CNF_CACH is TRUE
		$this->confLoader->retainCacheFlg();

		$accConf = $this->confLoader->setAccessArray();
		$this->rqstAccCon = $this->validateAccess( $accConf );

		// The events traced from here on are signed with the user and the address of the request
		$clntReg = $this->httpRequst->retrieveClient();
		Logger::setCurrentUser( strval($this->rqstAccCon->readFileDetail('user')), ($clntReg!==null) ? strval($clntReg->readFileDetail('userAddr')) : '' );
		$this->logUploadFiles( $this->httpRequst->getUploadFiles() );

		if( $this->rqstAccCon->obtainDefValue('statCode', 200) == 200 ){
			$this->processRequest();
		}else{
			$this->denyRqstAccess( $this->rqstAccCon );
		}
	}

	/**
	 * Traces the files received with the request, whether access is
	 * granted or not: one entry per file, and a warning for an upload
	 * that failed. A file field left empty is not traced.
	 *
	 * @param Array  $rqstFilesArr File entries, as Request::getUploadFiles() gives them.
	 * @param String $fldLabelText Name the entries were sent under, for the nested ones.
	 */
	protected function logUploadFiles( Array $rqstFilesArr, String $fldLabelText='' ): Void {
		foreach( $rqstFilesArr as $filKey=>$filVal ){
			if( !is_array($filVal) ){
				continue;
			}
			$lblText = ($fldLabelText=='') ? strval($filKey) : $fldLabelText.'['.$filKey.']';
			if( !array_key_exists('error', $filVal) || !array_key_exists('name', $filVal) ){
				// Files sent under a bracketed name are nested
				$this->logUploadFiles( $filVal, $lblText );
				continue;
			}
			if( is_array($filVal['error']) ){
				// Several files under one name, as PHP describes them : one entry is rebuilt for each
				$subList = Array();
				foreach( array_keys($filVal['error']) as $subKey ){
					$subList[$subKey] = Array('name'=>$filVal['name'][$subKey] ?? '', 'type'=>$filVal['type'][$subKey] ?? '', 'size'=>$filVal['size'][$subKey] ?? 0, 'error'=>$filVal['error'][$subKey]);
				}
				$this->logUploadFiles( $subList, $lblText );
				continue;
			}
			$errCode = intval($filVal['error']);
			if( $errCode==UPLOAD_ERR_NO_FILE ){
				continue;
			}
			// The names are the client's own : written as JSON texts
			$nameTxt = json_encode(strval($filVal['name']), JSON_INVALID_UTF8_SUBSTITUTE).' as '.json_encode($lblText, JSON_INVALID_UTF8_SUBSTITUTE);
			if( $errCode==UPLOAD_ERR_OK ){
				$this->runnerLogs->info( 'File received: '.$nameTxt.' ('.json_encode(strval($filVal['type'] ?? ''), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES).', '.intval($filVal['size'] ?? 0).' bytes)'.Logger::getActorSuffix() );
			}else{
				$this->runnerLogs->warning( 'File upload failed: '.$nameTxt.', error '.$errCode.Logger::getActorSuffix() );
			}
		}
	}

	/**
	 * Verifies access for the current request against the configured
	 * auth/sess strategy.
	 *
	 * @param Array $accessConfig Resolved 'security' config block for the active preset.
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys.
	 */
	function validateAccess( Array $accessConfig ): Registry {
		$chckObj = new Checker( $accessConfig, $this->httpRequst, $this->routRgstry );
		return $chckObj->validateAccess();
	}

	/**
	 * Sends an access-denial response (401/403/404/429/500), or the redirection
	 * to the sign-in route, directly, without ever constructing the
	 * requested application's Controller/Actionset.
	 *
	 * @param Registry $accessResult The Access\Checker result carrying the denial status/message.
	 */
	function denyRqstAccess( Registry $accessResult ): Void {
		// A visitor denied a page is sent to the sign-in route, in place of the error
		$goalUrl = $accessResult->readFileDetail('denyGoal');
		if( $goalUrl!=null ){
			$this->requestRsp->addReplyHeader('Location', strval($goalUrl));
			$this->saveStatusFlag( 302 );
			$this->sendFinalReply( $this->procssStts );
			return;
		}
		$wwwAuth = $accessResult->readFileDetail('wwwAuth');
		if( $wwwAuth!=null ){
			$this->requestRsp->addReplyHeader('WWW-Authenticate', $wwwAuth);
		}
		// A held address is told how long to wait
		$rtrAftr = $accessResult->readFileDetail('retryAft');
		if( $rtrAftr!=null ){
			$this->requestRsp->addReplyHeader('Retry-After', strval($rtrAftr));
		}
		$this->saveStatusFlag( $accessResult->obtainDefValue('statCode', 401), strval($accessResult->readFileDetail('statMssg')) );
		$this->sendFinalReply( $this->procssStts );
	}

	/**
	 * Launches an application according to the loaded route, passing it the
	 * input data, and forwards its status and results to the output layout.
	 *
	 * @param String $appNameToRun Name of the application (run) to load.
	 * @param String $runAppAction Name of the action/method to call on the application.
	 * @param ?String $runAppOption Optional extra parameter passed to the action.
	 */
	function runApplication( String $appNameToRun, String $runAppAction, ?String $runAppOption=null ): Void {
		// Launch an application according to
		// the loaded route, pass input data,
		// and end up with expected results,
		// then pass them to the output
		$conTrol = new Controller( $appNameToRun, $runAppAction, $this->inputLbrry, $runAppOption, $this->rqstAccCon, $this->httpRequst, $this->requestRsp, $this->confLoader->retrieveRouter() );
		$sttsArr = $conTrol->retrieveStatus();
		$this->saveStatusFlag( $sttsArr['code'], $sttsArr['mssg'], $conTrol->retrieveOutput() );
	}

	/**
	 * Writes the body of the response in its format (the 'outputAs' of the
	 * preset, or the one the action chose).
	 *
	 * @param Array|Object|String|Int|Float|Bool $outputToSend The data returned by the application to format into the response.
	 * @param Bool $errReplyFlag Whether the data is the body of an error.
	 * @return Bool False when the data could not be formatted; an error body was built instead.
	 */
	function retrieveLayout( Array|Object|String|Int|Float|Bool $outputToSend, Bool $errReplyFlag=false ): Bool {
		return $this->requestRsp->convertOutFlag( $outputToSend, $errReplyFlag );
	}

	/**
	 * Prepares the response body for a status and records the status to
	 * send: the output data for a 2xx status, an error body otherwise.
	 *
	 * @param Int    $responseCode Status reported by the application or the access check.
	 * @param String $statusMessag Message describing a non-2xx status.
	 * @param mixed  $outputToSend Output data for a 2xx status.
	 */
	protected function saveStatusFlag( Int $responseCode, String $statusMessag='', Mixed $outputToSend=null ): Void {
		// "No content" without any output is sent as such : no body, and no warnings
		if( $responseCode==204 && $outputToSend===null ){
			$this->requestRsp->clearReplyBody();
			$this->procssStts = 204;
			return;
		}
		// A redirection is its Location header : no body either
		if( $responseCode>=300 && $responseCode<400 ){
			$this->requestRsp->clearReplyBody();
			$this->procssStts = $responseCode;
			return;
		}
		$success = ($responseCode>=200 && $responseCode<300);
		if( $success ){
			// "No content" carrying an output is sent as a plain 200 with it
			if( $responseCode==204 ){
				$responseCode = 200;
			}
			$bodyArr = $outputToSend ?? Array();
		}else{
			$bodyArr = Array('error'=>$statusMessag, 'code'=>$responseCode);
		}

		// Warnings and notices collected by the error handler (only when errors may be shown)
		$hndlObj = $GLOBALS['ErrorHandler'] ?? null;
		$warnLst = ($hndlObj!==null) ? $hndlObj->takeStackArray() : Array();
		// A table or raw output has no place for them : they stay in the log
		if( !empty($warnLst) && ($this->requestRsp->takesReplyWarn() || !$success) ){
			if( $success ){
				$bodyArr = Array('data'=>$bodyArr, 'warnings'=>$warnLst);
			}else{
				$bodyArr['warnings'] = $warnLst;
			}
		}

		// Data that cannot be formatted is an error of the application
		if( !$this->retrieveLayout( $bodyArr, !$success ) ){
			$responseCode = 500;
		}
		if( !$success ){
			$this->htmlRequestErr( $responseCode, $statusMessag );
		}
		$this->procssStts = $responseCode;
	}

	/**
	 * Answers an error with the HTML page the site has for its status
	 * (see Router::errorPgeString), in place of the error body: when the
	 * request asked for a page or a file, or for nothing a route answers
	 * in a preset that serves pages. A request for an action keeps the
	 * error body of the preset. The page gets the status as %code% and
	 * the message as %error%.
	 *
	 * @param Int    $responseCode Status of the reply.
	 * @param String $statusMessag Message describing it.
	 */
	protected function htmlRequestErr( Int $responseCode, String $statusMessag ): Void {
		$rotrObj = ($this->confLoader!==null) ? $this->confLoader->retrieveRouter() : null;
		if( $rotrObj===null || $this->routRgstry===null ){
			return;
		}
		$rteKind = $this->routRgstry->readFileDetail('routKind');
		$noRoute = ( $rteKind==null && $this->routRgstry->readFileDetail('app')==null && strval($this->routRgstry->readFileDetail('routPath'))=='' );
		if( !$noRoute && !in_array($rteKind, Array('htmlPage', 'toStatic'), true) ){
			return;
		}
		$pgePath = $rotrObj->errorPgeString($responseCode);
		if( $pgePath!==null ){
			$this->requestRsp->setPageToReply( $pgePath, Array('code'=>$responseCode, 'error'=>$statusMessag), $responseCode );
		}
	}

	/**
	 * Sends a 500 response carrying the given message, in the configured
	 * output format. Called by the error handler for uncaught exceptions.
	 *
	 * @param String $errorMessage Message to expose in the response body.
	 */
	public function replyWithError( String $errorMessage ): Void {
		$this->saveStatusFlag( 500, $errorMessage );
		$this->sendFinalReply( $this->procssStts );
	}

	/**
	 * Builds the response headers and outputs the final response contents
	 * to the requester. A HEAD request is answered by the headers alone.
	 *
	 * @param ?Int $responseCode HTTP status code to send; omitted for the default 200.
	 */
	function sendFinalReply( ?Int $responseCode=null ): Void {
		// Build the response headers and
		// output the contents to the requester
		$this->requestRsp->buildReplyHead( $responseCode );
		// A HEAD request is answered by its headers alone, whatever the web server does with a body
		if( $this->httpRequst!==null && $this->httpRequst->usedMethodFlag('HEAD') ){
			return;
		}
		$this->requestRsp->transmitOutput();
	}

	/**
	 * Prepares the reply of a declared route that runs no action: a
	 * redirection, a static file (the Range header of the request is
	 * honoured, and a client that already holds the file is told so) or
	 * an HTML page, in which the values captured from the path
	 * fill the placeholders of their name.
	 */
	protected function routeReqAnswer(): Void {
		$rteKind = $this->routRgstry->readFileDetail('routKind');
		$rteGoal = strval($this->routRgstry->readFileDetail('routGoal'));
		if( $rteKind=='redirect' ){
			$this->requestRsp->addReplyHeader('Location', $rteGoal);
			$this->saveStatusFlag( intval($this->routRgstry->obtainDefValue('routCode', 302)) );
			return;
		}
		if( $rteKind=='toStatic' ){
			$isFound = $this->requestRsp->setFileToReply( $rteGoal, strval($this->routRgstry->readFileDetail('routType')), strval($this->httpRequst->retrieveHeader('Range')) );
			// The client may keep the file, and a proxy too when it is served to anyone
			$askHead = Array();
			foreach( Array('If-None-Match', 'If-Modified-Since', 'If-Range') as $hdrKey ){
				$askHead[$hdrKey] = strval($this->httpRequst->retrieveHeader($hdrKey));
			}
			$this->requestRsp->cacheReplyFile( $askHead, !$this->rqstAccCon->readFileDetail('logged') );
		}else{
			$isFound = $this->requestRsp->setPageToReply( $rteGoal, (array)$this->routRgstry->obtainDefValue('pathVars', Array()) );
		}
		if( $isFound ){
			$this->saveStatusFlag( 200 );
		}else{
			$this->saveStatusFlag( 404, 'Not Found' );
		}
	}

	/**
	 * Runs the request-handling sequence: reads the resolved app/action/param
	 * from the route registry, calls the application or prepares the reply
	 * the route gives by itself, and sends the response.
	 */
	function processRequest( ): Void {
		$appCall = $this->routRgstry->readFileDetail('app');
		$actName = $this->routRgstry->readFileDetail('action');
		$actOptn = $this->routRgstry->obtainDefValue('param', null);

		$rteStts = $this->routRgstry->obtainDefValue('statCode', 200);
		if( $rteStts!=200 ){
			// The route answers by itself : method refused, or OPTIONS
			$allwLst = $this->routRgstry->readFileDetail('allowed');
			if( $allwLst!=null ){
				$this->requestRsp->addReplyHeader('Allow', $allwLst);
			}
			$this->saveStatusFlag( $rteStts, strval($this->routRgstry->readFileDetail('statMssg')) );
		}elseif( in_array($this->routRgstry->readFileDetail('routKind'), Array('redirect', 'toStatic', 'htmlPage'), true) ){
			$this->routeReqAnswer();
		}elseif( $appCall==null ){
			// No route resolved for this request
			$this->saveStatusFlag( 404, 'Not Found' );
		}else{
			$this->runApplication($appCall, strval($actName), $actOptn);
		}
		// The tables described by the action are added to the config cache (TBL_CACH)
		$this->confLoader->retainCacheFlg();
		$this->sendFinalReply( $this->procssStts );
		exit();
	}
}
