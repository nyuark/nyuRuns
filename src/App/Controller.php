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
	
	protected $appIdent;
	protected $appFoldr;
	protected $inputReg;
	protected $axessReg;
	protected $httpRqst;
	protected $httpResp;
	protected $routrObj;
	protected $savedAct;
	protected $savedOpt;
	protected $appObjct;
	protected $modStack;
	protected $respVals;
	protected $loadFail;
	// Status reported with $loadFail
	protected $failCode = 404;

	// Initialize application
	/**
	 * Loads the application's Actions.php file and prepares it (via
	 * reflection) for execution, then loads its model stack. A missing
	 * application, one whose name is not made of letters and digits only,
	 * one whose Actions.php does not declare its actionset, a name that is
	 * not one of its actions, and an action found from the path while a
	 * declared route answers with it (see Router::shutsActn) are all the
	 * same 404 "Not Found" through getStatus(): the client is not told
	 * which. The reason is logged on the 'runner' channel. A default action
	 * that names no action is a 500, logged as an error. A required input
	 * that is missing or empty is a 400, the action not being run.
	 *
	 * @param String   $runAppName Name of the application (run) to load.
	 * @param String   $runAppActn Name of the action/method to call on the application.
	 * @param Registry $inRegistry Registry of input values to pass to the application.
	 * @param ?String  $runAppOptn Optional extra parameter passed to the action.
	 * @param ?Registry $axRegistry Access check result (logged user, role and identity) to pass to the application.
	 * @param ?Request $requestObj The shared HTTP request, to pass to the application.
	 * @param ?Response $httpRespns The shared response, to pass to the application.
	 * @param ?Router  $routingObj The router of the request, to pass to the application.
	 */
	public function __construct( String $runAppName, String $runAppActn, Registry $inRegistry, ?String $runAppOptn = null, ?Registry $axRegistry = null, ?Request $requestObj = null, ?Response $httpRespns = null, ?Router $routingObj = null ){
		// Change case of AppName
		$runAppName = UCFirst($runAppName);
		// Save action and inputs
		$this->appIdent = $runAppName;
		$this->savedAct = $runAppActn;
		$this->savedOpt = $runAppOptn;
		$this->inputReg = $inRegistry;
		$this->axessReg = $axRegistry;
		$this->httpRqst = $requestObj;
		$this->httpResp = $httpRespns;
		$this->routrObj = $routingObj;
		// The name goes into a path and a class name : letters and digits only, anything else is no application
		$logText = 'Not found: '.json_encode($runAppName.'/'.$runAppActn, JSON_INVALID_UTF8_SUBSTITUTE).': ';
		if( !preg_match('/^[A-Za-z0-9]+$/D', $runAppName) ){
			$this->loadFail = 'Not Found';
			Logger::getLogger('runner')->info( $logText.'not a name of an application'.Logger::actorText() );
			return;
		}
		// Check that application exists
		$fullDir = RUN_DIR.DS.$runAppName;
		$actPath = $fullDir.DS.'Actions.php';
		$actNSpc = '\\nyu\\Runs\\'.$runAppName.'\\';
		if( is_dir($fullDir) && file_exists($actPath) ){
			$this->appFoldr = $fullDir;
			require_once( $actPath );
			try{
				// Load the actionset in a reflection class
				$this->appObjct = new \ReflectionClass($actNSpc.'Actions');
			}catch(\ReflectionException $e){
				// Actions.php does not declare the application's actionset : a mistake of the application, told to the client as nothing found
				$this->loadFail = 'Not Found';
				Logger::getLogger('runner')->warning( $logText.'Actions.php of the application declares no actionset'.Logger::actorText() );
				return;
			}
		}else{
			// Unknown application : reported through getStatus()
			$this->loadFail = 'Not Found';
			Logger::getLogger('runner')->info( $logText.'unknown application'.Logger::actorText() );
			return;
		}
		$isDeflt = false;
		if( $this->savedAct=='' ){
			$this->savedAct = $this->defltActn();
			$isDeflt = true;
		}
		// An action a declared route answers with is only reached through that route
		if( $this->routrObj!==null ){
			$actName = ( preg_match('/^\w+$/', $this->savedAct) && $this->appObjct->hasMethod($this->savedAct) ) ? $this->appObjct->getMethod($this->savedAct)->getName() : $this->savedAct;
			if( $this->routrObj->shutsActn($this->appIdent, $actName, strcasecmp($actName, $this->defltActn())==0) ){
				$this->loadFail = 'Not Found';
				Logger::getLogger('runner')->info( $logText.'the action is only reached through its declared route'.Logger::actorText() );
				return;
			}
		}
		// A name that is not an action is nothing the client can reach; a default naming none is the application's mistake
		$clsName = $this->appObjct->getName();
		if( !$clsName::ownAction($this->savedAct) ){
			if( $isDeflt ){
				$this->failCode = 500;
				$this->loadFail = 'Internal Server Error';
				Logger::getLogger('runner')->error( 'The default action of '.$runAppName.' is not an action: '.json_encode($this->savedAct, JSON_INVALID_UTF8_SUBSTITUTE).' ($dfltActn, or no action declared)' );
				return;
			}
			$this->loadFail = 'Not Found';
			Logger::getLogger('runner')->info( $logText.'not an action of the application'.Logger::actorText() );
			return;
		}
		$this->actInputs();
		if( $this->loadFail!==null ){
			return;
		}
		$this->modLoader();
	}

	/**
	 * Applies the definition of the action's inputs, when the application
	 * has one in input/<action>.json: the fields it lists are kept, each
	 * given its format, and the others are left out (see Tools\Input).
	 * Without that file, the inputs stay as the preset built them. A
	 * field the definition requires ('name!') that is missing or empty
	 * refuses the request: a 400 naming the fields, through getStatus().
	 *
	 * @throws \RuntimeException When the file is not a valid definition.
	 */
	protected function actInputs(): Void {
		if( !preg_match('/^\w+$/', $this->savedAct) || !$this->appObjct->hasMethod($this->savedAct) ){
			return;
		}
		// The file is named as the action is declared, whatever the case the request wrote it in
		$actName = $this->appObjct->getMethod($this->savedAct)->getName();
		$defPath = $this->appFoldr.DS.'input'.DS.$actName.'.json';
		if( !is_file($defPath) ){
			return;
		}
		$defVals = json_decode( strval(file_get_contents($defPath)), true );
		if( !is_array($defVals) || !isset($defVals['fields']) || !(is_array($defVals['fields']) || is_string($defVals['fields'])) ){
			throw new \RuntimeException('Input misconfigured: '.$this->appIdent.'/input/'.$actName.'.json is not a definition of fields');
		}
		$inptObj = new Input( null, 'ACTON', $defVals['fields'], $actName );
		$this->inputReg = new Registry( $inptObj->parseVals($this->inputReg->readLines()) );
		$missLst = $inptObj->getMissed();
		if( !empty($missLst) ){
			// A required field is missing or empty : the action is not run
			$this->failCode = 400;
			$this->loadFail = 'Missing or invalid input: '.implode(', ', $missLst);
		}
	}

	/**
	 * Resolves the action to run when the request names none: the
	 * actionset's own $dfltActn when it sets one, otherwise the first
	 * public method declared in the application's Actions class.
	 *
	 * @return String The default action name, or an empty string when the actionset declares no action.
	 */
	protected function defltActn(): String {
		$clsProp = $this->appObjct->getDefaultProperties();
		if( !empty($clsProp['dfltActn']) && is_string($clsProp['dfltActn']) ){
			return $clsProp['dfltActn'];
		}
		foreach( $this->appObjct->getMethods(\ReflectionMethod::IS_PUBLIC) as $mthObj ){
			if( $mthObj->getDeclaringClass()->getName()==$this->appObjct->getName() && !$mthObj->isStatic() && substr($mthObj->getName(), 0, 2)!='__' ){
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
	protected function modLoader(): Void {
		$appName = $this->appIdent;
		$this->modStack = new ModelStack( $appName );
		$this->appRunner();
	}
	
	// Execute application (from actionset)
	/**
	 * Instantiates the reflected actionset class with the current action,
	 * option, inputs, model stack, access result, request, response and router — which runs the action itself.
	 */
	protected function appRunner(): Void {
		$this->appObjct = $this->appObjct->newInstanceArgs( Array($this->savedAct, $this->savedOpt, $this->inputReg, $this->modStack, $this->axessReg, $this->httpRqst, $this->httpResp, $this->routrObj) );
	}
	
	// Prepare outcome message and/or data
	/**
	 * Returns the status of the executed action, or of what kept it from
	 * running: the application or the action not found (404), a default
	 * action that names none (500), or its required inputs (400).
	 *
	 * @return Array The status as ['code' => Int, 'mssg' => String].
	 */
	public function getStatus(): Array {
		if( $this->loadFail!==null ){
			return Array('code'=>$this->failCode, 'mssg'=>$this->loadFail);
		}
		return Array(
			'code'=>intval($this->appObjct->statCode ?? 200),
			'mssg'=>strval($this->appObjct->statMssg ?? '')
		);
	}

	/**
	 * Returns the executed action's output.
	 *
	 * @return mixed The action's output data, or null when the action was not run.
	 */
	public function getOutput(): Mixed {
		$this->respVals = ($this->loadFail===null) ? $this->appObjct->asOutput : null;
		return $this->respVals;
	}
	
}