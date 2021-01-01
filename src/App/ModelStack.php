<?php

namespace nyu\App;

class ModelStack{
	
	protected $appIdent;
	protected $modFoldr;
	protected $modeList;
	
	// Initialize application
	public function __construct(String $runAppName){
		$this->modFoldr = RUN_DIR.DS.$runAppName.DS.'mods';
		$this->modeList = array();
		if(is_dir($this->modFoldr)){
			$modList = scandir( $this->modFoldr );
			foreach($modList as $modFle){
				$nameLen = strpos($modFle,'Model.php');
				if( $nameLen!==false ){
					$modPath = $this->modFoldr.DS.$modFle;
					$modName = substr( $modFle, 0, $nameLen);
					require_once($modPath);
					try{
						$nmeSpce = "\\nyu\\Runs\\$runAppName\\mods\\";
						$instStr = $nmeSpce.$modName;
						$modInst = new $instStr();
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
	function readModel(String $appModName){
		if( array_key_exists( strtolower($appModName), $this->modeList) ){
			return $this->modeList[strtolower($appModName)];
		}else{
			$sharMod = $this->sharedMod( $appModName );
			if($sharMod != false){
				return $sharMod;
			}else{
				// Throw Instanciation error for model call
			}
		}
	}
	
	// Load and return a shared model 
	function sharedMod( String $appModName ){
		$sharDir = RUN_DIR.DS.'Shared'.DS;
		$shrFile = $sharDir.$appModName.'Model.php';
		if(file_exists( $shrFile )){
			$modInst = new $appModName();
			require_once($shrFile);
			try{							
				$modInst = new $appModName();
				return $modInst;
			}catch( Exception $e ){
				// Throw Instanciation error for shared model
				return false;
			}
		}else{
			return false;
		}
	}
}