<?php

// A container to load configuration files, routes
// acces, and retrieve conf

namespace nyu\Setup;

use \nyu\Tools\Input;
use \nyu\Tools\Registry;
use \nyu\Tools\Request;
use \nyu\Tools\Router;
use \nyu\Data\Dcmnt;
use \nyu\Output\Response;

class Loader{

	protected $fieldSet;
	protected $conArray;

	/**
	* Constructs a setup loader, optionally loading a full setup array.
	*
	* @Param Array	$setupArray
	*/
    public function __construct(Array $setupArray = Array()){
		$this->setAllReg( $setupArray );
		$this->fieldSet = Array('setup','auths','route','rnder','input');
		// TODO : Raise exception if empty
	}

	/**
	* Replaces (without key check) the current config registry with the one provided.
	*
	* @Param Array	$setupArray
	*/
    protected function setAllReg(Array $setupArray = Array()): Void {
		$this->conArray = $setupArray;
	}

	/**
	* Overload some or all of the setup array, usually from the runner.
	*
	* @Param String	$keyOfArray
	* @Param Array	$newArrVals
	*/
	public function overLoads(Array $addedArray, Bool $arrayMerge = false): Void {
		foreach($addedArray as $arrKey=>$arrVal){
			if( $arrayMerge ){
				$prevArr = $this->getRegKey( $arrKey );
				if( $prevArr!==false ){
					$arrVal = array_merge($prevArr, $arrVal);
				}
			}
			$this->setRegKey($arrKey, $arrVal);
		}
	}

	/**
	* Gets one of the config registry values using one of the conf key array.
	*
	* @Param String	$keyOfArray
	*/
    public function getRegKey(String $keyOfArray): Mixed {
		if(array_key_exists($keyOfArray, $this->conArray) && in_array($keyOfArray, $this->fieldSet) ){
			return $this->conArray[$keyOfArray];
		}else{
			return false;
		}
	}

	/**
	* Sets one of the config registry values using the conf key array
	*
	* @Param String	$keyOfArray
	* @Param Array	$newArrVals
	*/
    protected function setRegKey(String $keyOfArray, Array $newArrVals = Array()): Void {
		if(in_array($keyOfArray,$this->fieldSet)){
			$this->conArray[$keyOfArray] = $newArrVals;
		}
	}

	/**
	* Get the configuration files based on the setup key of configuration registry
	*
	* @Param String	$keyOfArray
	* @Param Array	$newArrVals
	*/
	function getConfig(): Registry {
		// Read all INI and JSON files in conf
		// folder and store it into an array
		// TODO : Add XML
		$confArr = Array();
		// TODO : Raise error if getRegKey returns false
		$confSet = $this->getRegKey('setup');
		// Folder of the config files, relative to the project root : conf unless the preset names another
		$conPath = TOP_DIR.DS.($confSet['folder'] ?? 'conf').DS;
		$conScan = scandir($conPath);
		// Allowed file types
		$allowFT = Array('ini','json');
		// File names to load : 'any', or a comma-separated list of names without extension
		$conFltr = $confSet['fnames'] ?? 'any';
		$nameLst = false;
		if($conFltr!='any'){
			$nameLst = array_map('trim', explode(',', $conFltr));
		}
		foreach($conScan as $fleNme){
			$nowPath = $conPath.$fleNme;
			if(!is_dir($nowPath) && is_file($nowPath)){
				$nyuFile = new Dcmnt($nowPath);
				// Skip file if title is not in filter list
				if($nameLst!==false && !in_array($nyuFile->readValue('tit'),$nameLst)){
					continue;
				}
				if(in_array($nyuFile->readValue('ext'),$allowFT)){
					$conName = strtoupper($nyuFile->readValue('tit'));
					if( $confSet['ftypes']=='all' || $confSet['ftypes']==$nyuFile->readValue('ext') ){
						$conVars = $nyuFile->readValue('out');
						$confArr[$conName] = $conVars;
					}
				}
			}
		}
		$confArr = $this->fillEnvrn( $confArr, $this->loadEnvrn($confSet['envfle'] ?? '.env') );
		$confReg = new Registry( $confArr );
		return $confReg;
	}

	/**
	 * Reads the environment file of the project: NAME=value lines, with
	 * # comments, kept out of the repository to hold the secrets.
	 *
	 * @param String $envFilePth Path of the file, relative to the project root.
	 * @return Array The values by name, empty when the file does not exist.
	 */
	protected function loadEnvrn( String $envFilePth ): Array {
		$envVals = Array();
		$envPath = TOP_DIR.DS.$envFilePth;
		if( !is_file($envPath) ){
			return $envVals;
		}
		foreach( file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $envLin ){
			$envLin = trim($envLin);
			$sepPos = strpos($envLin, '=');
			if( $envLin=='' || $envLin[0]=='#' || $sepPos===false ){
				continue;
			}
			$envVals[trim(substr($envLin, 0, $sepPos))] = trim(trim(substr($envLin, $sepPos+1)), '"\'');
		}
		return $envVals;
	}

	/**
	 * Replaces every config value written env:NAME by the value of NAME,
	 * taken from the environment file first, then from the environment
	 * of the process. An unknown name gives an empty value.
	 *
	 * @param Array $confValues Config values, at any depth.
	 * @param Array $envrValues Values of the environment file, by name.
	 * @return Array The config values, with their env: references replaced.
	 */
	protected function fillEnvrn( Array $confValues, Array $envrValues ): Array {
		foreach( $confValues as $cnfKey => $cnfVal ){
			if( is_array($cnfVal) ){
				$confValues[$cnfKey] = $this->fillEnvrn($cnfVal, $envrValues);
			}elseif( is_string($cnfVal) && strpos($cnfVal, 'env:')===0 ){
				$envName = substr($cnfVal, 4);
				$envFind = getenv($envName);
				$confValues[$cnfKey] = $envrValues[$envName] ?? ($envFind!==false ? $envFind : '');
			}
		}
		return $confValues;
	}

    /**
	 * Builds the registry of allowed input values by instantiating an
	 * Input object for each configured source (currently QUERY and SERVR
	 * are supported; DFILE, DBREC, XTAPI, SESSN and RFLUX are still TODO).
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Registry Registry of parsed input values, empty if no 'input' config is set.
	 */
    function getInputs( Request $requestObj ): Registry {
		// TODO : Refactorise with Input Registry

		$inptSet = $this->getRegKey('input');
		if($inptSet){
			$inptArr = Array();
			foreach($inptSet as $putSet){
				// Only allow for a set of input sources
				// WORKING :	'QUERY', 'SERVR'
				// TODO : 		'DFILE', 'DBREC', 'XTAPI', 'SESSN', 'RFLUX');
				$inpType = Array('QUERY','SERVR');
				$inpSrce = $putSet['source'];

				if(in_array($inpSrce, $inpType)){
					$inptObj = new Input( $requestObj, $inpSrce, $putSet['fields'], $putSet['naming']);
					$inptArr = array_merge($inptArr, $inptObj->regiStack());
				}
			}
			$inptReg = new Registry( $inptArr );
			return $inptReg;
		}else{
			$inptReg = new Registry( Array() );
			return $inptReg;
		}
	}

    /**
	 * Resolves the application/action/param to run for the current request,
	 * through the Router and the 'route' configuration of the active preset.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Registry Registry holding the resolved 'app' and 'action' values.
	 */
    function loadRoute( Request $requestObj ): Registry {
		$rteConf = $this->getRegKey('route');
		$routObj = new Router( is_array($rteConf) ? $rteConf : Array(), $requestObj );
		return $routObj->findRoute();
	}

    /**
	 * Retrieves the resolved 'auths' configuration block for the active
	 * preset, for the Access\Checker to act on.
	 *
	 * @return Array The 'auths' config block, or ['needed' => false] if none is set.
	 */
    function setAccess(): Array {
		$axsConf = $this->getRegKey('auths');
		return is_array($axsConf) ? $axsConf : Array('needed'=>false);
	}

	/**
	 * Resolves the logging configuration from the SYSTEM config file,
	 * applying sensible defaults for anything not set.
	 *
	 * @return Array Log settings: 'path', 'mode', 'size', 'rota', 'pref'.
	 */
	function logConfig(): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
		return Array(
			'path' => $sysConf['LOG_PATH'] ?? TOP_DIR.DS.'logs',
			'mode' => $sysConf['LOG_MODE'] ?? 'FULL',
			'size' => $sysConf['LOG_SIZE'] ?? 0,
			'rota' => $sysConf['LOG_ROTA'] ?? 'NUMBER',
			'pref' => $sysConf['LOG_PREF'] ?? 'Y-m-d H:i',
		);
	}


    /**
	 * Defines the output format and response template by building a
	 * Response object from the 'rnder' configuration.
	 *
	 * @return Response The configured response object.
	 */
    function setOutput(): Response {
		// Define the output format
		// and response template
		$outConf = $this->getRegKey('rnder');

		$respObj = new Response($outConf);
		return $respObj;
	}
}
