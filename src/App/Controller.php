<?php

namespace nyu\App;

use nyu\App\ModelStack;
//use nyu\App\Renderer;
use nyu\Tools\Registry;

class Controller{
	
	protected $appIdent;
	protected $appFoldr;
	protected $inputReg;
	protected $savedAct;
	protected $savedOpt;
	protected $appObjct;
	protected $modStack;
	protected $respVals;
	protected $loadFail;
	
	// Initialize application
	/**
	 * Loads the application's Actions.php file and prepares it (via
	 * reflection) for execution, then loads its model stack.
	 *
	 * @param String   $runAppName Name of the application (run) to load.
	 * @param String   $runAppActn Name of the action/method to call on the application.
	 * @param Registry $inRegistry Registry of input values to pass to the application.
	 * @param String   $runAppOptn Optional extra parameter passed to the action.
	 *
	 * @todo Missing-application/actionset errors are silently swallowed instead of raised.
	 */
	public function __construct( String $runAppName, String $runAppActn, Registry $inRegistry, ?String $runAppOptn = null ){
		// Change case of AppName
		$runAppName = str_replace(' ','',UCFirst($runAppName));
		// Save action and inputs
		$this->appIdent = $runAppName;
		$this->savedAct = $runAppActn;
		$this->savedOpt = $runAppOptn;
		$this->inputReg = $inRegistry;
		// Check that application exists
		$fullDir = RUN_DIR.DS.$runAppName;
		$actPath = $fullDir.DS.'Actions.php';
		$actNSpc = '\\nyu\\Runs\\'.$runAppName.'\\';
		if( is_dir($fullDir) && file_exists($actPath) ){
			$this->appFoldr = $fullDir;
			require( $actPath );
			try{
				// Load the actionset in a reflection class
				$this->appObjct = new \ReflectionClass($actNSpc.'Actions');
			}catch(Exception $e){
				// Throw app loading error
			}
		}else{
			// Unknown application : reported through getStatus()
			$this->loadFail = 'Unknown Application';
			return;
		}
		if( $this->savedAct=='' ){
			$this->savedAct = $this->defltActn();
		}
		$this->modLoader();
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
	 * option, inputs and model stack — which runs the action itself.
	 */
	protected function appRunner(): Void {
		$this->appObjct = $this->appObjct->newInstanceArgs( Array($this->savedAct, $this->savedOpt, $this->inputReg, $this->modStack) );
	}
	
	// Prepare outcome message and/or data
	/**
	 * Returns the status of the executed action, or of the application
	 * load when it failed.
	 *
	 * @return Array The status as ['code' => Int, 'mssg' => String].
	 */
	public function getStatus(): Array {
		if( $this->loadFail!==null ){
			return Array('code'=>404, 'mssg'=>$this->loadFail);
		}
		return Array(
			'code'=>intval($this->appObjct->statCode ?? 200),
			'mssg'=>strval($this->appObjct->statMssg ?? '')
		);
	}

	/**
	 * Returns the executed action's output.
	 *
	 * @return mixed The action's output data, or null when the application could not be loaded.
	 */
	public function getOutput(): Mixed {
		$this->respVals = ($this->loadFail===null) ? $this->appObjct->asOutput : null;
		return $this->respVals;
	}
	
}