<?php

namespace nyu\Setup;

use \nyu\Tools\Input;
use \nyu\Tools\Registry;
use \nyu\Tools\Request;
use \nyu\Tools\Router;
use \nyu\Data\Dcmnt;
use \nyu\Output\Response;

/**
 * A container to load the configuration files, routes and access, and
 * retrieve the config.
 */
class Loader{

	protected $fieldSet;
	protected $conArray;
	protected $routrObj;

	/**
	* Constructs a setup loader, optionally loading a full setup array.
	*
	* @param Array $setupArray Full setup array to load, if any.
	*/
    public function __construct(Array $setupArray = Array()){
		$this->setAllReg( $setupArray );
		$this->fieldSet = Array('setup','auths','route','rnder','input');
	}

	/**
	* Replaces (without key check) the current config registry with the one provided.
	*
	* @param Array $setupArray Setup array that replaces the current one.
	*/
    protected function setAllReg(Array $setupArray = Array()): Void {
		$this->conArray = $setupArray;
	}

	/**
	* Overload some or all of the setup array, usually from the runner.
	*
	* @param Array $addedArray Setup blocks to load, by key.
	* @param Bool  $arrayMerge Whether a block is merged with the one already loaded instead of replacing it.
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
	* @param String $keyOfArray Key of the setup block.
	* @return Mixed The block, or false when the key is unknown or not loaded.
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
	* @param String $keyOfArray Key of the setup block.
	* @param Array  $newArrVals Values of the block.
	*/
    protected function setRegKey(String $keyOfArray, Array $newArrVals = Array()): Void {
		if(in_array($keyOfArray,$this->fieldSet)){
			$this->conArray[$keyOfArray] = $newArrVals;
		}
	}

	/**
	* Get the configuration files based on the setup key of configuration registry
	*
	* @return Registry The values of the config files.
	* @throws \RuntimeException When the preset has no setup block.
	*/
	function getConfig(): Registry {
		// Read all INI and JSON files in conf
		// folder and store it into an array
		$confArr = Array();
		$confSet = $this->getRegKey('setup');
		if( $confSet===false ){
			throw new \RuntimeException('The preset has no setup block');
		}
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
	 * Input object for each configured source (QUERY is the only one in
	 * version 1.0; DFILE, DBREC, XTAPI, SESSN and RFLUX come with 1.1).
	 * Server and client values are not inputs: they are read through the
	 * request (getSrvVar, getClient). The 'fields' of a source are 'all',
	 * or a definition of the fields kept and of their formats (see
	 * Tools\Input); an application can define them for each of its
	 * actions, which its Controller applies.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Registry Registry of parsed input values, empty if no 'input' config is set.
	 */
    function getInputs( Request $requestObj ): Registry {
		$inptSet = $this->getRegKey('input');
		if($inptSet){
			$inptArr = Array();
			foreach($inptSet as $putSet){
				// Only allow for a set of input sources
				// Version 1.0 :	'QUERY'
				// Version 1.1 :	'DFILE', 'DBREC', 'XTAPI', 'SESSN', 'RFLUX'
				$inpType = Array('QUERY');
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
		$this->routrObj = new Router( is_array($rteConf) ? $rteConf : Array(), $requestObj );
		return $this->routrObj->findRoute();
	}

	/**
	 * Gives the router that resolved the route of the request, for the
	 * applications to build links with.
	 *
	 * @return ?Router The router, null as long as no route was resolved.
	 */
	public function getRouter(): ?Router {
		return $this->routrObj;
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
	 * applying sensible defaults for anything not set. The log folder is
	 * always one of the project: LOG_PATH is read from the project root,
	 * with or without a leading slash, and is temp/logs when not set.
	 *
	 * @return Array Log settings: 'path', 'mode', 'size', 'rota', 'pref'.
	 */
	function logConfig(): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
		$fldName = trim( strval($sysConf['LOG_PATH'] ?? ''), '/\\ ' );
		return Array(
			'path' => TOP_DIR.DS.($fldName!='' ? str_replace(Array('/', '\\'), DS, $fldName) : 'temp'.DS.'logs'),
			'mode' => $sysConf['LOG_MODE'] ?? 'FULL',
			'size' => $sysConf['LOG_SIZE'] ?? 0,
			'rota' => $sysConf['LOG_ROTA'] ?? 'NUMBER',
			'pref' => $sysConf['LOG_PREF'] ?? 'Y-m-d H:i',
		);
	}

	/**
	 * Resolves the sound configuration from the SYSTEM config file: where
	 * the program that unpacks sounds is, and how long it may run.
	 *
	 * @return Array Sound settings: 'tool' ('' when the server has none), 'time' in seconds.
	 */
	function sndConfig(): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
		return Array(
			'tool' => strval($sysConf['SND_TOOL'] ?? ''),
			'time' => intval($sysConf['SND_TIME'] ?? 60),
		);
	}

	/**
	 * Resolves the reverse proxy configuration from the SYSTEM config file:
	 * the proxies standing in front of the server, and the headers in which
	 * they give the address of the client and the scheme it used.
	 *
	 * @return Array Proxy settings: 'list' of addresses or CIDR ranges (empty when no proxy is trusted), 'head' name of the header giving the address, 'schm' name of the one giving the scheme.
	 */
	function proxyConf(): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
		$prxList = array_filter( array_map('trim', explode(',', strval($sysConf['PRX_LIST'] ?? ''))), 'strlen' );
		return Array(
			'list' => array_values($prxList),
			'head' => strval($sysConf['PRX_HEAD'] ?? '') ?: 'X-Forwarded-For',
			'schm' => strval($sysConf['PRX_SCHM'] ?? '') ?: 'X-Forwarded-Proto',
		);
	}

	/**
	 * Resolves the limits of a request body from the SYSTEM config file:
	 * its largest size, and the most parts of a multipart body.
	 *
	 * @return Array Body limits: 'size' in KB (REQ_SIZE, 8192 when not set) and 'part' as a number of parts (REQ_PART, 200 when not set); 0 for no limit.
	 */
	function reqConfig(): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
		return Array(
			'size' => max( 0, intval($sysConf['REQ_SIZE'] ?? 8192) ),
			'part' => max( 0, intval($sysConf['REQ_PART'] ?? 200) ),
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
