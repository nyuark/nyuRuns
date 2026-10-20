<?php

namespace nyu\App;

/**
 * The models of an application: every *Model.php file of its mods folder,
 * made the first time it is asked for, then kept and given by name. A model
 * not found there is looked for in the shared ones (runs/Shared).
 */
class ModelStack{

	protected $appIdent;
	protected $modFoldr;
	protected $modeList;
	// The models of the mods folder, by name in lower case, with their name as written
	protected $modFiles;

	// Initialize application
	/**
	 * Scans the application's mods/ folder and lists every *Model.php file
	 * found there, keyed by lowercased model name. A model is only made when
	 * it is first asked for (readModel()).
	 *
	 * @param String $runAppName Name of the application (run) whose models to load.
	 */
	public function __construct(String $runAppName){
		$this->appIdent = $runAppName;
		$this->modFoldr = RUN_DIR.DS.$runAppName.DS.'mods';
		$this->modeList = array();
		$this->modFiles = array();
		if(is_dir($this->modFoldr)){
			foreach( scandir($this->modFoldr) as $modFle ){
				$nameLen = strpos($modFle,'Model.php');
				if( $nameLen!==false ){
					$modName = substr($modFle, 0, $nameLen);
					$this->modFiles[strtolower($modName)] = $modName;
				}
			}
		}else{
			// Throw app folder error
		}
	}

	// Return a model of the app (made once) or a shared one
	/**
	 * Returns a model by name: one of the application, made the first time
	 * it is asked for, or else a shared model loaded from runs/Shared.
	 *
	 * @param String $appModName Name of the model to retrieve.
	 * @return ?Object The model instance, or null when no model has this name.
	 *
	 * @throws \RuntimeException When the model exists but cannot be created.
	 */
	function readModel(String $appModName): ?Object {
		$lowName = strtolower($appModName);
		// A model of the app is made the first time it is asked for
		if( !array_key_exists($lowName, $this->modeList) && array_key_exists($lowName, $this->modFiles) ){
			$this->modeList[$lowName] = $this->makeModel($this->modFiles[$lowName]);
		}
		if( array_key_exists($lowName, $this->modeList) ){
			return $this->modeList[$lowName];
		}else{
			$sharMod = $this->sharedMod( $appModName );
			if($sharMod != false){
				$this->modeList[$lowName] = $sharMod;
				return $sharMod;
			}else{
				// Throw Instanciation error for model call
				return null;
			}
		}
	}

	/**
	 * Makes a model of the application from its file in the mods folder.
	 *
	 * @param String $modelLabel Name of the model, as its file writes it (without "Model.php").
	 * @return Object The model instance.
	 *
	 * @throws \RuntimeException When the model cannot be created.
	 */
	protected function makeModel(String $modelLabel): Object {
		require_once($this->modFoldr.DS.$modelLabel.'Model.php');
		try{
			$instStr = "\\nyu\\Runs\\".$this->appIdent."\\mods\\".$modelLabel;
			return new $instStr();
		}catch( \Throwable $e ){
			throw new \RuntimeException('Model "'.$modelLabel.'" of "'.$this->appIdent.'" could not be loaded: '.$e->getMessage(), 0, $e);
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
