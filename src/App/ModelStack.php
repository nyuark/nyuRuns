<?php

namespace nyu\App;

/**
 * The models of an application: every *Model.php file of its mods folder,
 * loaded once and given by name. A model not found there is looked for in
 * the shared ones (runs/Shared).
 */
class ModelStack{
	
	protected $appIdent;
	protected $modFoldr;
	protected $modeList;
	
	// Initialize application
	/**
	 * Scans the application's mods/ folder and instantiates every
	 * *Model.php file found there, keyed by lowercased model name.
	 *
	 * @param String $runAppName Name of the application (run) whose models to load.
	 */
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
					}catch( \Throwable $e ){
						throw new \RuntimeException('Model "'.$modName.'" of "'.$runAppName.'" could not be loaded: '.$e->getMessage(), 0, $e);
					}
				}
			}
		}else{
			// Throw app folder error
		}
	}
	
	// Return a preloaded model (or check if shared)
	/**
	 * Returns a preloaded model by name, falling back to loading it as a
	 * shared model (from runs/Shared) if it isn't part of this app's stack.
	 *
	 * @param String $appModName Name of the model to retrieve.
	 * @return ?Object The model instance, or the shared-model lookup result.
	 */
	function readModel(String $appModName): ?Object {
		if( array_key_exists( strtolower($appModName), $this->modeList) ){
			return $this->modeList[strtolower($appModName)];
		}else{
			$sharMod = $this->sharedMod( $appModName );
			if($sharMod != false){
				$this->modeList[strtolower($appModName)] = $sharMod;
				return $sharMod;
			}else{
				// Throw Instanciation error for model call
				return null;
			}
		}
	}
	
	// Load and return a shared model
	/**
	 * Loads and instantiates a shared model from runs/Shared, for use when
	 * a model isn't found in the current application's own mods/ folder.
	 *
	 * Shared models live in the nyu\Runs\Shared namespace.
	 *
	 * @param String $appModName Name of the shared model to load.
	 * @return Object|Bool The shared model instance, or false if no matching file exists.
	 *
	 * @throws \RuntimeException When the model file exists but the model cannot be created.
	 */
	function sharedMod( String $appModName ): Object|Bool {
		$sharDir = RUN_DIR.DS.'Shared'.DS;
		$shrFile = $sharDir.ucfirst($appModName).'Model.php';
		if(file_exists( $shrFile )){
			require_once($shrFile);
			try{
				$instStr = "\\nyu\\Runs\\Shared\\".$appModName;
				$modInst = new $instStr();
				return $modInst;
			}catch( \Throwable $e ){
				throw new \RuntimeException('Shared model "'.$appModName.'" could not be loaded: '.$e->getMessage(), 0, $e);
			}
		}else{
			return false;
		}
	}
}