<?php

namespace nyu\App;

use nyu\App\ModelStack;
//use nyu\App\Renderer;
use nyu\Tools\Input;
use nyu\Tools\Registry;
use nyu\Tools\Request;
use nyu\Tools\Router;
use nyu\Tools\Logger;
use nyu\Output\Response;

/**
 * The controller of an application: loads its models, applies the
 * definition of the inputs of the action asked for, runs the action and
 * gives its status and its output.
 */
class Controller{
	
	protected $runAppName;
	protected $appsFolder;
	// The names whose folders differ by their case alone, already logged as errors : once each
	protected static $loggdTwins = Array();
	// The folder names of each folder of Runs, by its path: read once for the request (see runFoldrString)
	protected static $runFolders = Array();
	protected $inputsRgst;
	protected $accessRgst;
	protected $requestObj;
	protected $respondObj;
	protected $routingObj;
	protected $keptAction;
	protected $keptOption;
	protected $actionsObj;
	protected $modelStack;
	protected $respValues;
	protected $loadFailed;
	// Status reported with $loadFail
	protected $failStatus = 404;

	// Initialize application
	/**
	 * Loads the application's Actions.php file and prepares it (via
	 * reflection) for execution, then loads its model stack. A missing
	 * application, one whose name is not made of letters and digits only,
	 * one whose Actions.php does not declare its actionset and a name that
	 * is not one of its actions are all the
	 * same 404 "Not Found" through retrieveStatus(): the client is not told
	 * which. The reason is logged on the 'runner' channel. A default action
	 * that names no action is a 500, logged as an error. A required input
	 * that is missing or empty is a 400, the action not being run.
	 *
	 * @param String   $appNameToRun Name of the application (run) to load.
	 * @param String   $runAppAction Name of the action/method to call on the application.
	 * @param Registry $runInputsReg Registry of input values to pass to the application.
	 * @param ?String  $runAppOption Optional extra parameter passed to the action.
	 * @param ?Registry $accsCheckReg Access check result (logged user, role and identity) to pass to the application.
	 * @param ?Request $givenRequest The shared HTTP request, to pass to the application.
	 * @param ?Response $httpResponse The shared response, to pass to the application.
	 * @param ?Router  $routerObject The router of the request, to pass to the application.
	 */
	public function __construct( String $appNameToRun, String $runAppAction, Registry $runInputsReg, ?String $runAppOption = null, ?Registry $accsCheckReg = null, ?Request $givenRequest = null, ?Response $httpResponse = null, ?Router $routerObject = null ){
		// Change case of AppName
		$appNameToRun = UCFirst($appNameToRun);
		// Save action and inputs
		$this->runAppName = $appNameToRun;
		$this->keptAction = $runAppAction;
		$this->keptOption = $runAppOption;
		$this->inputsRgst = $runInputsReg;
		$this->accessRgst = $accsCheckReg;
		$this->requestObj = $givenRequest;
		$this->respondObj = $httpResponse;
		$this->routingObj = $routerObject;
		// The name goes into a path and a class name : letters and digits only, anything else is no application
		$logText = 'Not found: '.json_encode($appNameToRun.'/'.$runAppAction, JSON_INVALID_UTF8_SUBSTITUTE).': ';
		if( !preg_match('/^[A-Za-z0-9]+$/D', $appNameToRun) ){
			$this->loadFailed = 'Not Found';
			Logger::retrieveLogger('runner')->info( $logText.'not a name of an application'.Logger::getActorSuffix() );
			return;
		}
		// Check that application exists : its folder found whatever the case of its name, as the Router and the Checker find it
		$fldName = self::runFoldrString($appNameToRun);
		if( $fldName!=='' ){
			$appNameToRun = $fldName;
			$this->runAppName = $fldName;
		}
		$fullDir = RUN_DIR.DS.$appNameToRun;
		$actnPth = $fullDir.DS.'Actions.php';
		$actNSpc = '\\nyu\\Runs\\'.$appNameToRun.'\\';
		if( $fldName!=='' && file_exists($actnPth) ){
			$this->appsFolder = $fullDir;
			require_once( $actnPth );
			try{
				// Load the actionset in a reflection class
				$this->actionsObj = new \ReflectionClass($actNSpc.'Actions');
			}catch(\ReflectionException $e){
				// Actions.php does not declare the application's actionset : a mistake of the application, told to the client as nothing found
				$this->loadFailed = 'Not Found';
				Logger::retrieveLogger('runner')->warning( $logText.'Actions.php of the application declares no actionset'.Logger::getActorSuffix() );
				return;
			}
		}else{
			// Unknown application : reported through retrieveStatus()
			$this->loadFailed = 'Not Found';
			Logger::retrieveLogger('runner')->info( $logText.'unknown application'.Logger::getActorSuffix() );
			return;
		}
		$isDeflt = false;
		if( $this->keptAction=='' ){
			$this->keptAction = self::dfltActionText( $this->actionsObj );
			$isDeflt = true;
		}
		// A name that is not an action is nothing the client can reach; a default naming none is the application's mistake
		$clsName = $this->actionsObj->getName();
		if( !$clsName::hasActionNamed($this->keptAction) ){
			if( $isDeflt ){
				$this->failStatus = 500;
				$this->loadFailed = 'Internal Server Error';
				Logger::retrieveLogger('runner')->error( 'The default action of '.$appNameToRun.' is not an action: '.json_encode($this->keptAction, JSON_INVALID_UTF8_SUBSTITUTE).' ($dfltAction names no action, or is not set)' );
				return;
			}
			$this->loadFailed = 'Not Found';
			Logger::retrieveLogger('runner')->info( $logText.'not an action of the application'.Logger::getActorSuffix() );
			return;
		}
		$this->applyInputDefs();
		if( $this->loadFailed!==null ){
			return;
		}
		$this->loadModelStack();
	}

	/**
	 * Applies the definition of the action's inputs, when the application
	 * has one in input/<action>.json: the fields it lists are kept, each
	 * given its format, and the others are left out (see Tools\Input).
	 * Without that file, the action gets no input: { "fieldSet": "all" } in
	 * it is the way to keep every input the preset built. A
	 * field the definition requires ('name!') that is missing or empty
	 * refuses the request: a 400 naming the fields, through retrieveStatus().
	 *
	 * @throws \RuntimeException When the file is not a valid definition.
	 */
	protected function applyInputDefs(): Void {
		if( !preg_match('/^\w+$/', $this->keptAction) || !$this->actionsObj->hasMethod($this->keptAction) ){
			return;
		}
		// The file is named as the action is declared, whatever the case the request wrote it in
		$actnNme = $this->actionsObj->getMethod($this->keptAction)->getName();
		$defPath = $this->appsFolder.DS.'input'.DS.$actnNme.'.json';
		if( !is_file($defPath) ){
			// No definition, no input : an action takes only what it declares
			$this->inputsRgst = new Registry( Array() );
			return;
		}
		$fldDefs = json_decode( strval(file_get_contents($defPath)), true );
		if( !is_array($fldDefs) || !isset($fldDefs['fieldSet']) || !(is_array($fldDefs['fieldSet']) || is_string($fldDefs['fieldSet'])) ){
			throw new \RuntimeException('Input misconfigured: '.$this->runAppName.'/input/'.$actnNme.'.json is not a definition of fields');
		}
		$inptObj = new Input( null, 'ACTON', $fldDefs['fieldSet'], $actnNme );
		$this->inputsRgst = new Registry( $inptObj->parseValuesArr($this->inputsRgst->readLinesArray()) );
		$missLst = $inptObj->retrieveMissed();
		if( !empty($missLst) ){
			// A required field is missing or empty : the action is not run
			$this->failStatus = 400;
			$this->loadFailed = 'Missing or invalid input: '.implode(', ', $missLst);
		}
	}

	/**
	 * Resolves the action to run when the request names none: the
	 * actionset's own $dfltAction, and nothing else. A Run that sets none
	 * has no default action: a request naming none is answered 500 (see
	 * the constructor), and `install checkAll` tells it. A Run declaring its
	 * routes says what its path alone answers with its "" route. The access
	 * check reads it too, for the role of a route to follow its action.
	 *
	 * @param \ReflectionClass $actionsClass The application's Actions class.
	 * @return String The default action name, or an empty string when the actionset sets none.
	 */
	public static function dfltActionText( \ReflectionClass $actionsClass ): String {
		$clsProp = $actionsClass->getDefaultProperties();
		return (!empty($clsProp['dfltAction']) && is_string($clsProp['dfltAction'])) ? $clsProp['dfltAction'] : '';
	}

	/**
	 * Finds the folder of a Run from its name as a path or a route writes it,
	 * whatever its case: the Router, the Controller and the Checker all go
	 * through it, so that they designate the same Run.
	 *
	 * @param String  $appNameToRun Name of the application, as written.
	 * @param ?String $runsFldrPath Folder holding the Runs; RUN_DIR when left out.
	 * @return String The name of its folder, as the disk writes it; '' for a name that is not letters and digits only, for no folder, or for two folders differing by their case alone.
	 */
	public static function runFoldrString( String $appNameToRun, ?String $runsFldrPath = null ): String {
		$runsDir = $runsFldrPath ?? (defined('RUN_DIR') ? RUN_DIR : '');
		if( $runsDir==='' || !preg_match('/^[A-Za-z0-9]+$/D', $appNameToRun) ){
			return '';
		}
		// The folders of the Runs are read once for the request, every lookup then served from that list
		if( !isset(self::$runFolders[$runsDir]) ){
			self::$runFolders[$runsDir] = Array();
			foreach( (array)glob($runsDir.DS.'*', GLOB_ONLYDIR) as $oneDir ){
				self::$runFolders[$runsDir][] = basename(strval($oneDir));
			}
		}
		$fndList = Array();
		foreach( self::$runFolders[$runsDir] as $oneDir ){
			if( strcasecmp($oneDir, $appNameToRun)===0 ){
				$fndList[] = $oneDir;
			}
		}
		// Two folders whose names differ by their case alone: neither, the case of a URL never choosing between them
		if( count($fndList)>1 && !isset(self::$loggdTwins[strtolower($appNameToRun)]) ){
			self::$loggdTwins[strtolower($appNameToRun)] = true;
			Logger::retrieveLogger('runner')->error( 'Run folders '.json_encode($fndList, JSON_UNESCAPED_SLASHES).' differ by their case alone: neither is served'.Logger::getActorSuffix() );
		}
		return (count($fndList)===1) ? $fndList[0] : '';
	}

	// Instantiate model loader class
	/**
	 * Instantiates the model loader (ModelStack) for the current
	 * application and triggers the actionset run.
	 */
	protected function loadModelStack(): Void {
		$appName = $this->runAppName;
		$this->modelStack = new ModelStack( $appName );
		$this->runApplication();
	}
	
	/**
	 * Builds what the run is given for this request, once its inputs are
	 * applied (see actInputs) and its models loaded.
	 *
	 * @return RunContext The action, its option and inputs, the access result, request, response, router, models and application.
	 */
	protected function makeRunContext(): RunContext {
		return new RunContext( $this->keptAction, $this->keptOption, $this->inputsRgst, $this->accessRgst, $this->requestObj, $this->respondObj, $this->routingObj, $this->modelStack, $this->runAppName, strval($this->appsFolder) );
	}

	// Execute application (from actionset)
	/**
	 * Creates the reflected actionset with the context of the request, then
	 * runs it: its setupForAction() hook, then the action (see Actionset::runAskedAction).
	 * A StopRun thrown by either ends the run with its status.
	 */
	protected function runApplication(): Void {
		$this->actionsObj = $this->actionsObj->newInstance( $this->makeRunContext() );
		$this->actionsObj->runAskedAction();
	}
	
	// Prepare outcome message and/or data
	/**
	 * Returns the status of the executed action, or of what kept it from
	 * running: the application or the action not found (404), a default
	 * action that names none (500), or its required inputs (400).
	 *
	 * @return Array The status as ['code' => Int, 'mssg' => String].
	 */
	public function retrieveStatus(): Array {
		if( $this->loadFailed!==null ){
			return Array('code'=>$this->failStatus, 'mssg'=>$this->loadFailed);
		}
		return Array(
			'code'=>intval($this->actionsObj->statusCode ?? 200),
			'mssg'=>strval($this->actionsObj->statusMssg ?? '')
		);
	}

	/**
	 * Returns the executed action's output.
	 *
	 * @return mixed The action's output data, or null when the action was not run.
	 */
	public function retrieveOutput(): Mixed {
		$this->respValues = ($this->loadFailed===null) ? $this->actionsObj->replyValue : null;
		return $this->respValues;
	}
	
}