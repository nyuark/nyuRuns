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
		// Check that application exists
		$fullDir = RUN_DIR.DS.$appNameToRun;
		$actnPth = $fullDir.DS.'Actions.php';
		$actNSpc = '\\nyu\\Runs\\'.$appNameToRun.'\\';
		if( is_dir($fullDir) && file_exists($actnPth) ){
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
			$this->keptAction = $this->dfltActionText();
			$isDeflt = true;
		}
		// A name that is not an action is nothing the client can reach; a default naming none is the application's mistake
		$clsName = $this->actionsObj->getName();
		if( !$clsName::hasActionNamed($this->keptAction) ){
			if( $isDeflt ){
				$this->failStatus = 500;
				$this->loadFailed = 'Internal Server Error';
				Logger::retrieveLogger('runner')->error( 'The default action of '.$appNameToRun.' is not an action: '.json_encode($this->keptAction, JSON_INVALID_UTF8_SUBSTITUTE).' ($dfltAction, or no action declared)' );
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
	 * Without that file, the inputs stay as the preset built them. A
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
	 * actionset's own $dfltAction when it sets one, otherwise the first
	 * public method declared in the application's Actions class.
	 *
	 * @return String The default action name, or an empty string when the actionset declares no action.
	 */
	protected function dfltActionText(): String {
		$clsProp = $this->actionsObj->getDefaultProperties();
		if( !empty($clsProp['dfltAction']) && is_string($clsProp['dfltAction']) ){
			return $clsProp['dfltAction'];
		}
		foreach( $this->actionsObj->getMethods(\ReflectionMethod::IS_PUBLIC) as $mthObj ){
			if( $mthObj->getDeclaringClass()->getName()==$this->actionsObj->getName() && !$mthObj->isStatic() && substr($mthObj->getName(), 0, 2)!='__' ){
				return $mthObj->getName();
			}
		}
		return '';
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