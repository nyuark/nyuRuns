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

// Kept when it is already loaded : the command line reads the map of the presets (readDoorwayLst) with its own config
$GLOBALS['nyuConfig'] = $GLOBALS['nyuConfig'] ?? null;

/**
 * The entry of every request: loads the preset and the config, reads the
 * request and its route, checks the access, calls the application and
 * sends the reply.
 */
class Runner{

	// The map of the presets served, from the project root: which preset answers a request, by its host and the start of its path
	const DOOR_FILE = 'conf/doorways.lst';

	protected 	$routesBase = '';	// The basePath of the line of the map chosen: taken off the path before routing
	protected 	$presetUsed;	// RPS (Runner PreSet) Object
	protected	$confLoader;	// Loader Container (Configuration)
	protected	$objBuilder;	// Shared Container (client and server values)
	protected	$requestObj;	// Initial HTTP Request
	protected 	$routerRegr;	// Route Registry
	protected 	$inputsRegr;	// Library (Registry) of allowed Inputs
	protected 	$accessRegr;	// Access Container (Access\Checker result)
	protected 	$requestRsp;	// Output Container

	public 		$errorHndlr;
	public 		$statusCode;
	public 		$runnerLogs;

	/**
	 * Loads the given preset, builds the shared objects (config, request,
	 * response), then sets up routing and access before running the request.
	 *
	 * @param String $presetToLoad Name of the .rps preset file to load; when empty, the map of the presets served picks it (see findPresetName), 'default' when it picks none.
	 * @param Array  $overrideConf Configuration values to merge over the loaded preset.
	 */
	function __construct( String $presetToLoad = '', Array $overrideConf = Array() ){
		try{
			// No preset named : the map picks it, by the host and the path of the request, before anything else
			if( $presetToLoad=='' ){
				$presetToLoad = $this->findPresetName();
			}
			// The config cache of the preset stands for its files, when there is one (CNF_CACH)
			$cacheVal = ConfCache::readCacheArray($presetToLoad);
			if( $cacheVal!==null ){
				$this->confLoader = new Loader( $cacheVal['preset'], $cacheVal, ($presetToLoad=='') ? 'default' : $presetToLoad );
			}else{
				// Load the preset object and call setup
				$this->presetUsed = new Preset($presetToLoad);
				$this->confLoader = $this->presetUsed->makeConfLoader();
			}

			// Overload when required
			if(!empty($overrideConf)){
				$this->confLoader->setSetupBlocks($overrideConf, true);
			}
			// Load Shared Data (conf, request, response)
			$GLOBALS['nyuConfig'] = $this->confLoader->readConfigRegr();
			$this->makeSharedInst();

			// Wire the logger into PHP's error/exception/shutdown handling, on its own channel
			$GLOBALS['nyuLogger'] = Logger::getLoggerOfChn('error');
			$GLOBALS['ErrorResponse'] = Array($this, 'sendErrorReply');
			require_once TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php';
			// The errors PHP reports are those of ERR_LEVL; one that cannot be read stops the start-up
			$GLOBALS['ErrorLevel'] = $this->confLoader->findErrorLevel();
			installHandler();
		}catch( \Throwable $e ){
			// Nothing can format or log an error yet
			$this->sendEarlyError($e);
		}

		// Setup route & access, then process
		$this->loadSetupFiles();
	}

	/**
	 * Picks the preset of the request from the map of the presets served
	 * (DOOR_FILE): of the lines matching the host and the start of the path,
	 * a line naming a host comes before a line without one, then the
	 * longest basePath. The basePath of the line chosen is kept, to be taken
	 * off the path before routing. No map, an empty one or no line matching:
	 * the default preset. The map is read on each request, never cached.
	 *
	 * @return String The name of the preset.
	 *
	 * @throws \RuntimeException When the map cannot be read.
	 */
	protected function findPresetName(): String {
		$hostText = strtolower( preg_replace('/:\d+$/', '', strval($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')) );
		$pathText = '/'.trim( strval(parse_url(strval($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? ''), '/' );
		$bestNtry = null;
		foreach( self::readDoorwayLst() as $oneRow ){
			$baseText = $oneRow['basePath'];
			// A basePath matches whole parts of the path: /api takes /api and /api/..., never /apix
			if( ($oneRow['hostName']!=='' && $oneRow['hostName']!==$hostText) || ($baseText!=='/' && $pathText!==$baseText && !str_starts_with($pathText, $baseText.'/')) ){
				continue;
			}
			if( $bestNtry===null || [$oneRow['hostName']!=='', strlen($baseText)] > [$bestNtry['hostName']!=='', strlen($bestNtry['basePath'])] ){
				$bestNtry = $oneRow;
			}
		}
		if( $bestNtry===null ){
			return 'default';
		}
		$this->routesBase = ($bestNtry['basePath']==='/') ? '' : $bestNtry['basePath'];
		return $bestNtry['presetId'];
	}

	/**
	 * Reads the map of the presets served, conf/doorways.lst: JSON with a
	 * .lst extension, which the Loader does not read as a config. Each line
	 * names a preset (presetId), the start of the path it answers (basePath)
	 * and, when it is given, the host (hostName, any host when empty).
	 *
	 * @return Array The lines, each with 'hostName' (lower case, '' for any host), 'basePath' (a leading slash, none after it) and 'presetId'; empty when there is no map.
	 *
	 * @throws \RuntimeException When the map is not JSON, or a line of it is not whole: a broken map never serves another preset.
	 */
	public static function readDoorwayLst(): Array {
		$doorPath = TOP_DIR.DS.str_replace('/', DS, self::DOOR_FILE);
		if( !is_file($doorPath) ){
			return Array();
		}
		$doorVals = json_decode( strval(file_get_contents($doorPath)), true );
		if( !is_array($doorVals) || !is_array($doorVals['doorways'] ?? Array()) || !array_is_list($doorVals['doorways'] ?? Array()) ){
			throw new \RuntimeException(self::DOOR_FILE.' cannot be read: { "doorways": [ { "hostName", "basePath", "presetId" } ] } expected');
		}
		$rowsList = Array();
		foreach( $doorVals['doorways'] ?? Array() as $rowIdx => $oneRow ){
			$hostText = is_array($oneRow) ? ($oneRow['hostName'] ?? '') : null;
			if( !is_array($oneRow) || array_diff(array_keys($oneRow), Array('hostName', 'basePath', 'presetId')) || !is_string($hostText) || !preg_match('/^[a-z0-9.\-]*$/Di', $hostText)
				|| !is_string($oneRow['basePath'] ?? null) || !preg_match('#^/[\w.\-/]*$#D', $oneRow['basePath']) || !is_string($oneRow['presetId'] ?? null) || !preg_match('/^\w+$/D', $oneRow['presetId']) ){
				throw new \RuntimeException(self::DOOR_FILE.' cannot be read: line '.($rowIdx + 1).' needs a basePath starting with a slash, a presetId, and a hostName or none');
			}
			$rowsList[] = Array( 'hostName'=>strtolower($hostText), 'basePath'=>'/'.trim($oneRow['basePath'], '/'), 'presetId'=>$oneRow['presetId'] );
		}
		return $rowsList;
	}

	/**
	 * Answers a failure that happens before the error handler is installed:
	 * the details go to PHP's error log, the client gets a plain JSON 500.
	 *
	 * @param \Throwable $bootExcpInst What was thrown during start-up.
	 */
	protected function sendEarlyError( \Throwable $bootExcpInst ): Void {
		error_log('nyuRuns start-up failure: '.get_class($bootExcpInst).': '.$bootExcpInst->getMessage().' in '.$bootExcpInst->getFile().' on line '.$bootExcpInst->getLine());
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
	function makeSharedInst( ): Void {
		// Instanciate the shared objects to
		// be available during all the running
		// process through DI Container

		$ctnrInst = new Builder();
		$ctnrInst->makeObjOfClass('nyu\\Tools\\ServerReg', array());
		// The client address is seen through the reverse proxies the config trusts
		$ctnrInst->makeObjOfClass('nyu\Tools\ClientReg', array(), Array(true, $this->confLoader->findProxyConfg()));
		$rndrConf = $this->confLoader->getRegistryKey('response');
		$this->requestRsp = $ctnrInst->makeObjOfClass('nyu\\Output\\Response', array(), Array($rndrConf ?: Array()));
		// A body larger than the config allows is not read
		$this->requestObj = $ctnrInst->makeObjOfClass('nyu\\Tools\\Request', array(), Array($this->confLoader->findBodyLimits()));
		// Another site is named in the reply only when the preset lists it
		$this->requestRsp->setReplyOrigin( $this->requestObj->getHeaderValue('Origin') );

		// Every log channel is built with this configuration; the Runner writes on its own
		Logger::loadLogsConfig( $this->confLoader->logConfigArray() );
		$this->runnerLogs = Logger::getLoggerOfChn('runner');

		// Plug the program that unpacks sounds, where the server has one
		$soundCfg = $this->confLoader->findSoundConfg();
		if( $soundCfg['tool']!=='' ){
			\nyu\Data\Sound::plugSoundCodec( $ctnrInst->makeObjOfClass('nyu\\Data\\Codec', array(), Array($soundCfg['tool'], $soundCfg['time'])) );
		}

		$this->objBuilder = $ctnrInst;
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
		if( $this->requestObj->isBodyTooLarge() ){
			$clntRegr = $this->requestObj->getClientOfRqt();
			$userAddr = ($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '';
			$this->runnerLogs->warning( 'Request refused'.(($userAddr!='') ? ' from '.$userAddr : '').': body larger than allowed (REQ_SIZE, REQ_PART)' );
			$this->setReplyStatus( 413, 'Payload Too Large' );
			$this->sendFinalReply( $this->statusCode );
			return;
		}

		// The preset tells the sources of the inputs; the definition an application
		// gives for an action (input/<action>.json) is applied by its Controller
		$this->inputsRegr = $this->confLoader->makeInputsRegr( $this->requestObj );

		// The basePath of the line of the map chosen is taken off the path, and put back in front of the links
		$this->routerRegr = $this->confLoader->loadRouteRgstr( $this->requestObj, $this->routesBase );
		// Read from its files, the config is cached for the next requests when CNF_CACH is TRUE
		$this->confLoader->saveConfgCache();

		$accsConf = $this->confLoader->setAccessArray();
		$this->accessRegr = $this->checkRqtAccess( $accsConf );

		// The events traced from here on are signed with the user and the address of the request
		$clntRegr = $this->requestObj->getClientOfRqt();
		Logger::setCurrentUser( strval($this->accessRegr->readValueByKey('userName')), ($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '' );
		$this->logUploadFiles( $this->requestObj->getUploadFiles() );

		if( $this->accessRegr->getValueOrDflt('statCode', 200) == 200 ){
			$this->treatRoutedRqt();
		}else{
			$this->denyReqtAccess( $this->accessRegr );
		}
	}

	/**
	 * Traces the files received with the request, whether access is
	 * granted or not: one entry per file, and a warning for an upload
	 * that failed. A file field left empty is not traced.
	 *
	 * @param Array  $rqtFilesList File entries, as Request::getUploadFiles() gives them.
	 * @param String $fldLabelText Name the entries were sent under, for the nested ones.
	 */
	protected function logUploadFiles( Array $rqtFilesList, String $fldLabelText='' ): Void {
		foreach( $rqtFilesList as $filKey=>$filVal ){
			if( !is_array($filVal) ){
				continue;
			}
			$labelTxt = ($fldLabelText=='') ? strval($filKey) : $fldLabelText.'['.$filKey.']';
			if( !array_key_exists('error', $filVal) || !array_key_exists('name', $filVal) ){
				// Files sent under a bracketed name are nested
				$this->logUploadFiles( $filVal, $labelTxt );
				continue;
			}
			if( is_array($filVal['error']) ){
				// Several files under one name, as PHP describes them : one entry is rebuilt for each
				$subLstng = Array();
				foreach( array_keys($filVal['error']) as $subKey ){
					$subLstng[$subKey] = Array('name'=>$filVal['name'][$subKey] ?? '', 'type'=>$filVal['type'][$subKey] ?? '', 'size'=>$filVal['size'][$subKey] ?? 0, 'error'=>$filVal['error'][$subKey]);
				}
				$this->logUploadFiles( $subLstng, $labelTxt );
				continue;
			}
			$errorCod = intval($filVal['error']);
			if( $errorCod==UPLOAD_ERR_NO_FILE ){
				continue;
			}
			// The names are the client's own : written as JSON texts
			$nameText = json_encode(strval($filVal['name']), JSON_INVALID_UTF8_SUBSTITUTE).' as '.json_encode($labelTxt, JSON_INVALID_UTF8_SUBSTITUTE);
			if( $errorCod==UPLOAD_ERR_OK ){
				$this->runnerLogs->info( 'File received: '.$nameText.' ('.json_encode(strval($filVal['type'] ?? ''), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES).', '.intval($filVal['size'] ?? 0).' bytes)'.Logger::getActorSuffix() );
			}else{
				$this->runnerLogs->warning( 'File upload failed: '.$nameText.', error '.$errorCod.Logger::getActorSuffix() );
			}
		}
	}

	/**
	 * Verifies access for the current request against the configured
	 * auth/sess strategy.
	 *
	 * @param Array $accessConfig Resolved 'security' config block for the active preset.
	 * @return Registry Registry with 'loggedIn', 'userName', 'userRole', 'identity', 'statCode' and 'statMssg' keys.
	 */
	function checkRqtAccess( Array $accessConfig ): Registry {
		$checkObj = new Checker( $accessConfig, $this->requestObj, $this->routerRegr, $this->confLoader->getRouterOfRqt() );
		return $checkObj->checkRqtAccess();
	}

	/**
	 * Sends an access-denial response (401/403/404/429/500), or the redirection
	 * to the sign-in route, directly, without ever constructing the
	 * requested application's Controller/Actionset.
	 *
	 * @param Registry $accsCheckReg The Access\Checker result carrying the denial status/message.
	 */
	function denyReqtAccess( Registry $accsCheckReg ): Void {
		// A visitor denied a page is sent to the sign-in route, in place of the error
		$goalPath = $accsCheckReg->readValueByKey('denyGoal');
		if( $goalPath!=null ){
			$this->requestRsp->addReplyHeader('Location', strval($goalPath));
			$this->setReplyStatus( 302 );
			$this->sendFinalReply( $this->statusCode );
			return;
		}
		$wwwAuthn = $accsCheckReg->readValueByKey('wwwHeadr');
		if( $wwwAuthn!=null ){
			$this->requestRsp->addReplyHeader('WWW-Authenticate', $wwwAuthn);
		}
		// A held address is told how long to wait
		$rtryAftr = $accsCheckReg->readValueByKey('retryAft');
		if( $rtryAftr!=null ){
			$this->requestRsp->addReplyHeader('Retry-After', strval($rtryAftr));
		}
		$this->setReplyStatus( $accsCheckReg->getValueOrDflt('statCode', 401), strval($accsCheckReg->readValueByKey('statMssg')) );
		$this->sendFinalReply( $this->statusCode );
	}

	/**
	 * Launches an application according to the loaded route, passing it the
	 * input data, and forwards its status and results to the output layout.
	 *
	 * @param String $appNameToRun Name of the application (run) to load.
	 * @param String $runAppAction Name of the action/method to call on the application.
	 * @param ?String $runAppOption Optional extra parameter passed to the action.
	 */
	function treatAppAction( String $appNameToRun, String $runAppAction, ?String $runAppOption=null ): Void {
		// Launch an application according to
		// the loaded route, pass input data,
		// and end up with expected results,
		// then pass them to the output
		$ctrlInst = new Controller( $appNameToRun, $runAppAction, $this->inputsRegr, $runAppOption, $this->accessRegr, $this->requestObj, $this->requestRsp, $this->confLoader->getRouterOfRqt() );
		$sttsList = $ctrlInst->getStatusOfAct();
		$this->setReplyStatus( $sttsList['code'], $sttsList['mssg'], $ctrlInst->getOutputOfAct() );
	}

	/**
	 * Writes the body of the response in its format (the 'outputAs' of the
	 * preset, or the one the action chose).
	 *
	 * @param Array|Object|String|Int|Float|Bool $outputToSend The data returned by the application to format into the response.
	 * @param Bool $errReplyFlag Whether the data is the body of an error.
	 * @return Bool False when the data could not be formatted; an error body was built instead.
	 */
	function castToReplyFmt( Array|Object|String|Int|Float|Bool $outputToSend, Bool $errReplyFlag=false ): Bool {
		return $this->requestRsp->castToReplyFmt( $outputToSend, $errReplyFlag );
	}

	/**
	 * Prepares the response body for a status and records the status to
	 * send: the output data for a 2xx status, an error body otherwise.
	 *
	 * @param Int    $responseCode Status reported by the application or the access check.
	 * @param String $statusReason Message describing a non-2xx status.
	 * @param mixed  $outputToSend Output data for a 2xx status.
	 */
	protected function setReplyStatus( Int $responseCode, String $statusReason='', Mixed $outputToSend=null ): Void {
		// "No content" without any output is sent as such : no body, and no warnings
		if( $responseCode==204 && $outputToSend===null ){
			$this->requestRsp->clearReplyBody();
			$this->statusCode = 204;
			return;
		}
		// A redirection is its Location header : no body either
		if( $responseCode>=300 && $responseCode<400 ){
			$this->requestRsp->clearReplyBody();
			$this->statusCode = $responseCode;
			return;
		}
		$isPassed = ($responseCode>=200 && $responseCode<300);
		if( $isPassed ){
			// "No content" carrying an output is sent as a plain 200 with it
			if( $responseCode==204 ){
				$responseCode = 200;
			}
			$bodyList = $outputToSend ?? Array();
		}else{
			$bodyList = Array('error'=>$statusReason, 'code'=>$responseCode);
		}

		// Warnings and notices collected by the error handler (only when errors may be shown)
		$hndlInst = $GLOBALS['ErrorHandler'] ?? null;
		$warnList = ($hndlInst!==null) ? $hndlInst->getStackErrors() : Array();
		// A table or raw output has no place for them : they stay in the log
		if( !empty($warnList) && ($this->requestRsp->canAddWarnings() || !$isPassed) ){
			if( $isPassed ){
				$bodyList = Array('data'=>$bodyList, 'warnings'=>$warnList);
			}else{
				$bodyList['warnings'] = $warnList;
			}
		}

		// Data that cannot be formatted is an error of the application
		if( !$this->castToReplyFmt( $bodyList, !$isPassed ) ){
			$responseCode = 500;
		}
		if( !$isPassed ){
			$this->sendStatusPage( $responseCode, $statusReason );
		}
		$this->statusCode = $responseCode;
	}

	/**
	 * Answers an error with the HTML page the site has for its status
	 * (see Router::findErrPagePth), in place of the error body: when the
	 * request asked for a page or a file, or for nothing a route answers
	 * in a preset that serves pages. A request for an action keeps the
	 * error body of the preset. The page gets the status as %code% and
	 * the message as %error%.
	 *
	 * @param Int    $responseCode Status of the reply.
	 * @param String $statusReason Message describing it.
	 */
	protected function sendStatusPage( Int $responseCode, String $statusReason ): Void {
		$routInst = ($this->confLoader!==null) ? $this->confLoader->getRouterOfRqt() : null;
		if( $routInst===null || $this->routerRegr===null ){
			return;
		}
		$routeCod = $this->routerRegr->readValueByKey('routKind');
		$isNoRout = ( $routeCod==null && $this->routerRegr->readValueByKey('appToRun')==null && strval($this->routerRegr->readValueByKey('routPath'))=='' );
		if( !$isNoRout && !in_array($routeCod, Array('htmlPage', 'toStatic'), true) ){
			return;
		}
		$pagePath = $routInst->findErrPagePth($responseCode);
		if( $pagePath!==null ){
			$this->requestRsp->setPageToReply( $pagePath, Array('code'=>$responseCode, 'error'=>$statusReason), $responseCode );
		}
	}

	/**
	 * Sends a 500 response carrying the given message, in the configured
	 * output format. Called by the error handler for uncaught exceptions.
	 *
	 * @param String $errorMessage Message to expose in the response body.
	 */
	public function sendErrorReply( String $errorMessage ): Void {
		$this->setReplyStatus( 500, $errorMessage );
		$this->sendFinalReply( $this->statusCode );
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
		$this->requestRsp->writeReplyHead( $responseCode );
		// A HEAD request is answered by its headers alone, whatever the web server does with a body
		if( $this->requestObj!==null && $this->requestObj->isSentByMethod('HEAD') ){
			return;
		}
		$this->requestRsp->writeReplyBody();
	}

	/**
	 * Prepares the reply of a declared route that runs no action: a
	 * redirection, a static file (the Range header of the request is
	 * honoured, and a client that already holds the file is told so) or
	 * an HTML page, in which the values captured from the path
	 * fill the placeholders of their name.
	 */
	protected function makeRouteReply(): Void {
		$routeCod = $this->routerRegr->readValueByKey('routKind');
		$routeGol = strval($this->routerRegr->readValueByKey('routTarg'));
		if( $routeCod=='redirect' ){
			$this->requestRsp->addReplyHeader('Location', $routeGol);
			$this->setReplyStatus( intval($this->routerRegr->getValueOrDflt('routStat', 302)) );
			return;
		}
		if( $routeCod=='toStatic' ){
			$wasFound = $this->requestRsp->setFileToReply( $routeGol, strval($this->routerRegr->readValueByKey('routType')), strval($this->requestObj->getHeaderValue('Range')) );
			// The client may keep the file, and a proxy too when it is served to anyone
			$askdHedr = Array();
			foreach( Array('If-None-Match', 'If-Modified-Since', 'If-Range') as $hdrKey ){
				$askdHedr[$hdrKey] = strval($this->requestObj->getHeaderValue($hdrKey));
			}
			$this->requestRsp->cacheReplyFile( $askdHedr, !$this->accessRegr->readValueByKey('loggedIn') );
		}else{
			$wasFound = $this->requestRsp->setPageToReply( $routeGol, (array)$this->routerRegr->getValueOrDflt('pathVars', Array()) );
		}
		if( $wasFound ){
			$this->setReplyStatus( 200 );
		}else{
			$this->setReplyStatus( 404, 'Not Found' );
		}
	}

	/**
	 * Runs the request-handling sequence: reads the resolved appToRun/doAction/argument
	 * from the route registry, calls the application or prepares the reply
	 * the route gives by itself, and sends the response.
	 */
	function treatRoutedRqt( ): Void {
		$applCall = $this->routerRegr->readValueByKey('appToRun');
		$actTitle = $this->routerRegr->readValueByKey('doAction');
		$actOptns = $this->routerRegr->getValueOrDflt('argument', null);

		$routeStt = $this->routerRegr->getValueOrDflt('statCode', 200);
		if( $routeStt!=200 ){
			// The route answers by itself : method refused, or OPTIONS
			$allowLst = $this->routerRegr->readValueByKey('allowHdr');
			if( $allowLst!=null ){
				$this->requestRsp->addReplyHeader('Allow', $allowLst);
			}
			$this->setReplyStatus( $routeStt, strval($this->routerRegr->readValueByKey('statMssg')) );
		}elseif( in_array($this->routerRegr->readValueByKey('routKind'), Array('redirect', 'toStatic', 'htmlPage'), true) ){
			$this->makeRouteReply();
		}elseif( $applCall==null ){
			// No route resolved for this request
			$this->setReplyStatus( 404, 'Not Found' );
		}else{
			$this->treatAppAction($applCall, strval($actTitle), $actOptns);
		}
		// The tables described by the action are added to the config cache (TBL_CACH)
		$this->confLoader->saveConfgCache();
		$this->sendFinalReply( $this->statusCode );
		exit();
	}
}
