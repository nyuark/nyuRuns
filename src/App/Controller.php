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
	
	// Initialize application
	public function __construct( String $runAppName, String $runAppActn, Registry $inRegistry, String $runAppOptn = null ){
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
			// Throw app folder/actionset error
		}
		$this->modLoader();
	}
	
	// Instantiate model loader class
	protected function modLoader(){
		$appName = $this->appIdent;
		$this->modStack = new ModelStack( $appName );
		$this->appRunner();
	}
	
	// Execute application (from actionset)
	protected function appRunner(){
		$this->appObjct = $this->appObjct->newInstanceArgs( Array($this->savedAct, $this->savedOpt, $this->inputReg, $this->modStack) );
	}
	
	// Prepare outcome message and/or data
	public function getOutput(){
		if($this->appObjct->statCode==200){
			$this->respVals = $this->appObjct->asOutput;
		}else{
			$this->respVals = array('err', 'Error '.$this->appObjct->statCode.' : '.$this->appObjct->statMssg);
		}
		return $this->respVals;
	}
	
}