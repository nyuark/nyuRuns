<?php

namespace nyu\App;

class ModelStack{
	
	protected $modFoldr;
	protected $modeList;
	
	// Initialize application
	public function __construct(String $modlFolder){
		$this->modFoldr = $modlFolder;
		if(is_dir($this->modFoldr)){
			$modList = scandir( $this->modFoldr );
			foreach($modList as $modFle){
				$nameLen = strpos($modFle,'Model.php');
				if( $nameLen!==false ){
					$modPath = $this->modFoldr.DS.$modFle;
					$modName = substr( $modFle, 0, $modLen);
					require_once($modPath);
					try{							
						$modInst = new $modName.'()';
						$this->modeList[strtolower($modName)] = $modInst;
					}catch( Exception $e ){
						// Throw Instanciation error
					}
				}
			}
		}else{
			// Throw app folder error
		}
	}
	
	// Return a preloaded model (or check if shared)
	function readModel(String $modelName){
		if( array_key_exists( strtolower($modelName), $this->modeList) ){
			return $this->modeList[strtolower($modelName)];
		}else{
			$sharMod = $this->sharedMod( $modelName );
			if($sharMod != false){
				return $sharMod;
			}
		}
	}
	
	// Load and return a shared model 
	function sharedMod( String $modelName ){
		$sharDir = RUN_DIR.DS.'Shared'.DS;
		$shrFile = $sharDir.$modelName.'Model.php';
		if(file_exists( $shrFile )){
			$modInst = new $modelName.'()';
			require_once($shrFile);
			try{							
				$modInst = new $modName.'()';
				return $modInst;
			}catch( Exception $e ){
				// Throw Instanciation error for shared model
			}
		}else{
			return false;
		}
	}
}