<?php

namespace nyu\App;

/**
 * The models of an application: every *Model.php file of its mods folder,
 * made the first time it is asked for, then kept and given by name. A model
 * not found there is looked for in the shared ones (runs/Shared).
 */
class ModelStack{

	protected $appIdentty;
	protected $modelFoldr;
	protected $modelsList;
	// The models of the mods folder, by name in lower case, with their name as written
	protected $modelFiles;

	// Initialize application
	/**
	 * Scans the application's mods/ folder and lists every *Model.php file
	 * found there, keyed by lowercased model name. A model is only made when
	 * it is first asked for (getModelObject()).
	 *
	 * @param String $appNameToRun Name of the application (run) whose models to load.
	 */
	public function __construct(String $appNameToRun){
		$this->appIdentty = $appNameToRun;
		$this->modelFoldr = RUN_DIR.DS.$appNameToRun.DS.'mods';
		$this->modelsList = array();
		$this->modelFiles = array();
		if(is_dir($this->modelFoldr)){
			foreach( scandir($this->modelFoldr) as $mdlFil ){
				$nmeSize = strpos($mdlFil,'Model.php');
				if( $nmeSize!==false ){
					$mdlName = substr($mdlFil, 0, $nmeSize);
					$this->modelFiles[strtolower($mdlName)] = $mdlName;
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
	 * @param String $appModelName Name of the model to retrieve.
	 * @return ?Object The model instance, or null when no model has this name.
	 *
	 * @throws \RuntimeException When the model exists but cannot be created.
	 */
	function getModelObject(String $appModelName): ?Object {
		$lowName = strtolower($appModelName);
		// A model of the app is made the first time it is asked for
		if( !array_key_exists($lowName, $this->modelsList) && array_key_exists($lowName, $this->modelFiles) ){
			$this->modelsList[$lowName] = $this->newModelObject($this->modelFiles[$lowName]);
		}
		if( array_key_exists($lowName, $this->modelsList) ){
			return $this->modelsList[$lowName];
		}else{
			$shrdMdl = $this->sharedModelObj( $appModelName );
			if($shrdMdl != false){
				$this->modelsList[$lowName] = $shrdMdl;
				return $shrdMdl;
			}else{
				// Throw Instanciation error for model call
				return null;
			}
		}
	}

	/**
	 * Makes a model of the application from its file in the mods folder.
	 *
	 * @param String $modelClsName Name of the model, as its file writes it (without "Model.php").
	 * @return Object The model instance.
	 *
	 * @throws \RuntimeException When the model cannot be created.
	 */
	protected function newModelObject(String $modelClsName): Object {
		require_once($this->modelFoldr.DS.$modelClsName.'Model.php');
		try{
			$clsName = "\\nyu\\Runs\\".$this->appIdentty."\\mods\\".$modelClsName;
			return new $clsName();
		}catch( \Throwable $e ){
			throw new \RuntimeException('Model "'.$modelClsName.'" of "'.$this->appIdentty.'" could not be loaded: '.$e->getMessage(), 0, $e);
		}
	}

	// Load and return a shared model
	/**
	 * Loads and instantiates a shared model from runs/Shared, for use when
	 * a model isn't found in the current application's own mods/ folder.
	 *
	 * Shared models live in the nyu\Runs\Shared namespace.
	 *
	 * @param String $appModelName Name of the shared model to load.
	 * @return Object|Bool The shared model instance, or false if no matching file exists.
	 *
	 * @throws \RuntimeException When the model file exists but the model cannot be created.
	 */
	function sharedModelObj( String $appModelName ): Object|Bool {
		$shrdDir = RUN_DIR.DS.'Shared'.DS;
		$shrFile = $shrdDir.ucfirst($appModelName).'Model.php';
		if(file_exists( $shrFile )){
			require_once($shrFile);
			try{
				$clsName = "\\nyu\\Runs\\Shared\\".$appModelName;
				$modInst = new $clsName();
				return $modInst;
			}catch( \Throwable $e ){
				throw new \RuntimeException('Shared model "'.$appModelName.'" could not be loaded: '.$e->getMessage(), 0, $e);
			}
		}else{
			return false;
		}
	}
}
