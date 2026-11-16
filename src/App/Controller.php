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
	protected static $twinsNoted = Array();
	// The folder names of each folder of Runs, by its path: read once for the request (see findRunFldrNme)
	protected static $runFolders = Array();
	protected $inputsRegr;
	protected $accessRegr;
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
	 * same 404 "Not Found" through getStatusOfAct(): the client is not told
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
		$this->inputsRegr = $runInputsReg;
		$this->accessRegr = $accsCheckReg;
		$this->requestObj = $givenRequest;
		$this->respondObj = $httpResponse;
		$this->routingObj = $routerObject;
		// The name goes into a path and a class name : letters and digits only, anything else is no application
		$logStrng = 'Not found: '.json_encode($appNameToRun.'/'.$runAppAction, JSON_INVALID_UTF8_SUBSTITUTE).': ';
		if( !preg_match('/^[A-Za-z0-9]+$/D', $appNameToRun) ){
			$this->loadFailed = 'Not Found';
			Logger::getLoggerOfChn('runner')->info( $logStrng.'not a name of an application'.Logger::getActorSuffix() );
			return;
		}
		// Check that application exists : its folder found whatever the case of its name, as the Router and the Checker find it
		$fieldNme = self::findRunFldrNme($appNameToRun);
		if( $fieldNme!=='' ){
			$appNameToRun = $fieldNme;
			$this->runAppName = $fieldNme;
		}
		$fullPath = RUN_DIR.DS.$appNameToRun;
		$actnPath = $fullPath.DS.'Actions.php';
		$actnNmsp = '\\nyu\\Runs\\'.$appNameToRun.'\\';
		if( $fieldNme!=='' && file_exists($actnPath) ){
			$this->appsFolder = $fullPath;
			// The actionset is asked for by its name: the loader of the Runs reads its file (RunLoader)
			class_exists($actnNmsp.'Actions');
			try{
				// Load the actionset in a reflection class
				$this->actionsObj = new \ReflectionClass($actnNmsp.'Actions');
			}catch(\ReflectionException $e){
				// Actions.php does not declare the application's actionset : a mistake of the application, told to the client as nothing found
				$this->loadFailed = 'Not Found';
				Logger::getLoggerOfChn('runner')->warning( $logStrng.'Actions.php of the application declares no actionset'.Logger::getActorSuffix() );
				return;
			}
		}else{
			// Unknown application : reported through getStatusOfAct()
			$this->loadFailed = 'Not Found';
			Logger::getLoggerOfChn('runner')->info( $logStrng.'unknown application'.Logger::getActorSuffix() );
			return;
		}
		$hasDeflt = false;
		if( $this->keptAction=='' ){
			$this->keptAction = self::findDfltAction( $this->actionsObj );
			$hasDeflt = true;
		}
		// A name that is not an action is nothing the client can reach; a default naming none is the application's mistake
		$classNme = $this->actionsObj->getName();
		if( !$classNme::hasActionNamed($this->keptAction) ){
			if( $hasDeflt ){
				$this->failStatus = 500;
				$this->loadFailed = 'Internal Server Error';
				Logger::getLoggerOfChn('runner')->error( 'The default action of '.$appNameToRun.' is not an action: '.json_encode($this->keptAction, JSON_INVALID_UTF8_SUBSTITUTE).' ($dfltAction names no action, or is not set)' );
				return;
			}
			$this->loadFailed = 'Not Found';
			Logger::getLoggerOfChn('runner')->info( $logStrng.'not an action of the application'.Logger::getActorSuffix() );
			return;
		}
		$this->treatInputDefs();
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
	 * refuses the request: a 400 naming the fields, through getStatusOfAct().
	 *
	 * @throws \RuntimeException When the file is not a valid definition.
	 */
	protected function treatInputDefs(): Void {
		if( !preg_match('/^\w+$/', $this->keptAction) || !$this->actionsObj->hasMethod($this->keptAction) ){
			return;
		}
		// The file is named as the action is declared, whatever the case the request wrote it in
		$actnName = $this->actionsObj->getMethod($this->keptAction)->getName();
		$defnPath = $this->appsFolder.DS.'input'.DS.$actnName.'.json';
		if( !is_file($defnPath) ){
			// No definition, no input : an action takes only what it declares
			$this->inputsRegr = new Registry( Array() );
			return;
		}
		$fieldDfs = json_decode( strval(file_get_contents($defnPath)), true );
		if( !is_array($fieldDfs) || !isset($fieldDfs['fieldSet']) || !(is_array($fieldDfs['fieldSet']) || is_string($fieldDfs['fieldSet'])) ){
			throw new \RuntimeException('Input misconfigured: '.$this->runAppName.'/input/'.$actnName.'.json is not a definition of fields');
		}
		$inputObj = new Input( null, 'ACTON', $fieldDfs['fieldSet'], $actnName );
		$this->inputsRegr = new Registry( $inputObj->parseValuesDct($this->inputsRegr->readLinesArray()) );
		$missList = $inputObj->getMissingFlds();
		if( !empty($missList) ){
			// A required field is missing or empty : the action is not run
			$this->failStatus = 400;
			$this->loadFailed = 'Missing or invalid input: '.implode(', ', $missList);
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
	public static function findDfltAction( \ReflectionClass $actionsClass ): String {
		$classPrp = $actionsClass->getDefaultProperties();
		return (!empty($classPrp['dfltAction']) && is_string($classPrp['dfltAction'])) ? $classPrp['dfltAction'] : '';
	}

	/**
	 * Forgets the folders of Runs read for the request: the next lookup reads
	 * them again. For a command or a test that has just written a Run folder;
	 * a web request keeps the list it read once.
	 */
	public static function clearFldrCache(): Void {
		self::$runFolders = Array();
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
	public static function findRunFldrNme( String $appNameToRun, ?String $runsFldrPath = null ): String {
		$runsPath = $runsFldrPath ?? (defined('RUN_DIR') ? RUN_DIR : '');
		if( $runsPath==='' || !preg_match('/^[A-Za-z0-9]+$/D', $appNameToRun) ){
			return '';
		}
		// The folders of the Runs are read once for the request, every lookup then served from that list
		if( !isset(self::$runFolders[$runsPath]) ){
			self::$runFolders[$runsPath] = Array();
			foreach( (array)glob($runsPath.DS.'*', GLOB_ONLYDIR) as $onePth ){
				self::$runFolders[$runsPath][] = basename(strval($onePth));
			}
		}
		$findList = Array();
		foreach( self::$runFolders[$runsPath] as $onePth ){
			if( strcasecmp($onePth, $appNameToRun)===0 ){
				$findList[] = $onePth;
			}
		}
		// Two folders whose names differ by their case alone: neither, the case of a URL never choosing between them
		if( count($findList)>1 && !isset(self::$twinsNoted[strtolower($appNameToRun)]) ){
			self::$twinsNoted[strtolower($appNameToRun)] = true;
			Logger::getLoggerOfChn('runner')->error( 'Run folders '.json_encode($findList, JSON_UNESCAPED_SLASHES).' differ by their case alone: neither is served'.Logger::getActorSuffix() );
		}
		return (count($findList)===1) ? $findList[0] : '';
	}

	// Instantiate model loader class
	/**
	 * Instantiates the model loader (ModelStack) for the current
	 * application and triggers the actionset run.
	 */
	protected function loadModelStack(): Void {
		$applName = $this->runAppName;
		$this->modelStack = new ModelStack( $applName );
		$this->treatAppAction();
	}
	
	/**
	 * Builds what the run is given for this request, once its inputs are
	 * applied (see actInputs) and its models loaded.
	 *
	 * @return RunContext The action, its option and inputs, the access result, request, response, router, models and application.
	 */
	protected function makeRunContext(): RunContext {
		return new RunContext( $this->keptAction, $this->keptOption, $this->inputsRegr, $this->accessRegr, $this->requestObj, $this->respondObj, $this->routingObj, $this->modelStack, $this->runAppName, strval($this->appsFolder) );
	}

	// Execute application (from actionset)
	/**
	 * Creates the reflected actionset with the context of the request, then
	 * runs it: its openEachAction() hook, then the action (see Actionset::treatAskedActn).
	 * A StopRun thrown by either ends the run with its status.
	 */
	protected function treatAppAction(): Void {
		$this->actionsObj = $this->actionsObj->newInstance( $this->makeRunContext() );
		$this->actionsObj->treatAskedActn();
	}
	
	// Prepare outcome message and/or data
	/**
	 * Returns the status of the executed action, or of what kept it from
	 * running: the application or the action not found (404), a default
	 * action that names none (500), or its required inputs (400).
	 *
	 * @return Array The status as ['code' => Int, 'mssg' => String].
	 */
	public function getStatusOfAct(): Array {
		if( $this->loadFailed!==null ){
			return Array('code'=>$this->failStatus, 'mssg'=>$this->loadFailed);
		}
		return Array(
			'code'=>intval($this->actionsObj->statusCode ?? 200),
			'mssg'=>strval($this->actionsObj->statusText ?? '')
		);
	}

	/**
	 * Returns the executed action's output.
	 *
	 * @return mixed The action's output data, or null when the action was not run.
	 */
	public function getOutputOfAct(): Mixed {
		$this->respValues = ($this->loadFailed===null) ? $this->actionsObj->replyValue : null;
		return $this->respValues;
	}
	
}