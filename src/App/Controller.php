<?php

namespace nyu\App;

use nyu\App\ModelStack;
//use nyu\App\Renderer;
use nyu\Tools\Register;

class Controller{
	
	protected $appFoldr;
	protected $inputReg;
	protected $savedAct;
	protected $appObjct;
	protected $modStack;
	protected $respVals;
	
	// Initialize application
	public function __construct( String $runAppName, String $runAppActn, Register $inRegister ){
		// Save action and inputs
		$this->savedAct = $runAppActn;
		$this->inputReg = $inRegister;
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
		$modPath = $this->appFoldr.DS.'mods';
		$this->modStack = new ModelStack( $modPath );
		$this->appRunner();
	}
	
	// Execute application (from actionset)
	protected function appRunner(){
		$this->appObjct = $this->appObjct->newInstanceArgs( Array($this->savedAct, $this->inputReg, $this->modStack) );
	}
	
	// Prepare outcome message and/or data
	protected function getOutput(){
		if($this->appObjct->statCode==200){
			return $this->appObjct->asOutput;
		}else{
			return 'Error '.$this->appObjct->statCode.' : '.$this->appObjct->statMssg;
		}
	}
	
}