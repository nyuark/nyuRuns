<?php

namespace nyu\App;

use nyu\App\ModelStack;
//use nyu\App\Renderer;
use nyu\Tools\Input;
use nyu\Tools\Registry;
use nyu\Tools\Request;
use nyu\Output\Response;

class Controller{
	
	protected $appIdent;
	protected $appFoldr;
	protected $inputReg;
	protected $axessReg;
	protected $httpRqst;
	protected $httpResp;
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
	 * application, or one whose Actions.php does not declare its actionset,
	 * is reported as a 404 through getStatus(); a required input that is
	 * missing or empty as a 400, the action not being run.
	 *
	 * @param String   $runAppName Name of the application (run) to load.
	 * @param String   $runAppActn Name of the action/method to call on the application.
	 * @param Registry $inRegistry Registry of input values to pass to the application.
	 * @param String   $runAppOptn Optional extra parameter passed to the action.
	 * @param ?Registry $axRegistry Access check result (logged user, role and identity) to pass to the application.
	 * @param ?Request $requestObj The shared HTTP request, to pass to the application.
	 * @param ?Response $httpRespns The shared response, to pass to the application.
	 */
	public function __construct( String $runAppName, String $runAppActn, Registry $inRegistry, ?String $runAppOptn = null, ?Registry $axRegistry = null, ?Request $requestObj = null, ?Response $httpRespns = null ){
		// Change case of AppName
		$runAppName = str_replace(' ','',UCFirst($runAppName));
		// Save action and inputs
		$this->appIdent = $runAppName;
		$this->savedAct = $runAppActn;
		$this->savedOpt = $runAppOptn;
		$this->inputReg = $inRegistry;
		$this->axessReg = $axRegistry;
		$this->httpRqst = $requestObj;
		$this->httpResp = $httpRespns;
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
				// Actions.php does not declare the application's actionset : reported through getStatus()
				$this->loadFail = 'Unknown Application';
				return;
			}
		}else{
			// Unknown application : reported through getStatus()
			$this->loadFail = 'Unknown Application';
			return;
		}
		if( $this->savedAct=='' ){
			$this->savedAct = $this->defltActn();
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
	 * option, inputs, model stack, access result, request and response — which runs the action itself.
	 */
	protected function appRunner(): Void {
		$this->appObjct = $this->appObjct->newInstanceArgs( Array($this->savedAct, $this->savedOpt, $this->inputReg, $this->modStack, $this->axessReg, $this->httpRqst, $this->httpResp) );
	}
	
	// Prepare outcome message and/or data
	/**
	 * Returns the status of the executed action, or of what kept it from
	 * running: the application load (404) or its required inputs (400).
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