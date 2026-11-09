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
	// The values read from the config cache, or those gathered to write it (see ConfCache)
	protected $cachVals;
	// Whether they were read from the cache
	protected $cachRead;
	// Name of the preset, which the cache is kept under
	protected $rpsLabel;

	/**
	* Constructs a setup loader, optionally loading a full setup array.
	*
	* @param Array  $setupArray Full setup array to load, if any.
	* @param ?Array $cachedVals The values the config cache holds for the preset, read in place of the files; null when the files are read.
	* @param String $presetName Name of the preset, which the cache is kept under; '' for no cache.
	*/
    public function __construct(Array $setupArray = Array(), ?Array $cachedVals = null, String $presetName = ''){
		$this->setAllReg( $setupArray );
		$this->fieldSet = Array('setup','auths','route','rnder','input');
		$this->rpsLabel = $presetName;
		$this->cachRead = ($cachedVals!==null);
		// The preset is kept as it is before any overload of the Runner
		$this->cachVals = $cachedVals ?? Array('preset'=>$setupArray);
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
	* Get the configuration files based on the setup key of configuration registry.
	* With the config cache, its values are taken in place of the files; the
	* env: values are filled on each request, from the environment file.
	*
	* @return Registry The values of the config files.
	* @throws \RuntimeException When the preset has no setup block.
	*/
	function getConfig(): Registry {
		$confSet = $this->getRegKey('setup');
		if( $confSet===false ){
			throw new \RuntimeException('The preset has no setup block');
		}
		if( $this->cachRead ){
			$confReg = new Registry( $this->fillEnvrn( $this->cachVals['config'], $this->loadEnvrn($confSet['envfle'] ?? '.env') ) );
			// The descriptions of the tables the cache keeps, when they are kept (TBL_CACH, with CNF_CACH)
			$sysConf = $confReg->readValue('system') ?: Array();
			ConfCache::$tblInfos = ( in_array(strtoupper(strval($sysConf['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) && in_array(strtoupper(strval($sysConf['TBL_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) ) ? (array)($this->cachVals['tables'] ?? Array()) : null;
			ConfCache::$tblAdded = false;
			return $confReg;
		}
		// Read all INI and JSON files in conf
		// folder and store it into an array
		$confArr = Array();
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
			// The kind and the name are told by the file name: only the files kept are opened
			$fleExtn = strtolower(pathinfo($fleNme, PATHINFO_EXTENSION));
			$fleTitl = pathinfo($fleNme, PATHINFO_FILENAME);
			if( !in_array($fleExtn, $allowFT) || ($nameLst!==false && !in_array($fleTitl, $nameLst)) ){
				continue;
			}
			if( $confSet['ftypes']!='all' && $confSet['ftypes']!=$fleExtn ){
				continue;
			}
			$nowPath = $conPath.$fleNme;
			if( is_file($nowPath) ){
				$nyuFile = new Dcmnt($nowPath);
				$confArr[strtoupper($fleTitl)] = $nyuFile->readValue('out');
			}
		}
		// The cache holds the values as written: no secret of the environment file goes into it
		$this->cachVals['config'] = $confArr;
		$confArr = $this->fillEnvrn( $confArr, $this->loadEnvrn($confSet['envfle'] ?? '.env') );
		$confReg = new Registry( $confArr );
		// The descriptions of the tables are kept from this request on, when they are (TBL_CACH, with CNF_CACH)
		$sysConf = $confReg->readValue('system') ?: Array();
		ConfCache::$tblInfos = ( in_array(strtoupper(strval($sysConf['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) && in_array(strtoupper(strval($sysConf['TBL_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) ) ? Array() : null;
		ConfCache::$tblAdded = false;
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
		if( $this->cachRead ){
			$this->routrObj->setRoutes( $this->cachVals['routes'] );
		}
		return $this->routrObj->findRoute();
	}

	/**
	 * Writes the config cache of the preset, when CNF_CACH is TRUE: once
	 * the route of the request is found, when the values were read from the
	 * files (the preset, the config files and the declared routes), and at
	 * the end of the request, when tables were described (TBL_CACH). The
	 * next requests of the preset read them in place of the files. Nothing
	 * is written when a file of the config changed while the request ran:
	 * the next request reads them.
	 *
	 * @return Bool Whether the cache was written.
	 */
	public function keepCache(): Bool {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
		if( !in_array( strtoupper(strval($sysConf['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true ) || ($this->cachRead && !ConfCache::$tblAdded) || $this->rpsLabel=='' || $this->routrObj===null || !isset($this->cachVals['config']) ){
			return false;
		}
		// A config, preset or route file written since the request began (the Runs' routes too): what it read is not cached
		$srcTime = 0;
		foreach( array_merge( (array)glob(TOP_DIR.DS.'conf'.DS.'*.ini'), (array)glob(TOP_DIR.DS.'conf'.DS.'*.json'), (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps'),
			(array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*.rte'), (array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*'.DS.'*.rte'), defined('RUN_DIR') ? (array)glob(RUN_DIR.DS.'*'.DS.'routes.rte') : Array() ) as $srcFle ){
			$srcTime = max($srcTime, intval(@filemtime(strval($srcFle))));
		}
		// File times are in seconds: a change within the second the request began is not told apart
		if( $srcTime>intval($_SERVER['REQUEST_TIME'] ?? time()) ){
			return false;
		}
		$this->cachVals['routes'] = $this->routrObj->getRoutes();
		$this->cachVals['tables'] = ConfCache::$tblInfos ?? Array();
		ConfCache::$tblAdded = false;
		if( !ConfCache::makeCache($this->rpsLabel, $this->cachVals) ){
			return false;
		}
		// Written once: the end of the request writes it again only for tables described meanwhile
		$this->cachRead = true;
		return true;
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
	 * Resolves the level of the errors PHP reports from the SYSTEM config
	 * file (ERR_LEVL): E_* names joined by |, & and ~, & binding before |
	 * as PHP writes them, read name by name and never run as code. A value
	 * left unquoted in system.ini arrives as the number PHP made of it.
	 * E_ALL when not set. What the level leaves out is neither shown nor
	 * logged.
	 *
	 * @return Int The error level.
	 *
	 * @throws \RuntimeException When the value holds what is not an error level.
	 */
	function errConfig(): Int {
		$nyuConf = $GLOBALS['nyuConfig'];
		$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
		$lvlText = trim( strval($sysConf['ERR_LEVL'] ?? '') );
		if( $lvlText=='' ){
			return E_ALL;
		}
		if( preg_match('/^\d+$/D', $lvlText) ){
			return intval($lvlText) & E_ALL;
		}
		$errLevl = 0;
		foreach( explode('|', $lvlText) as $orsPrt ){
			$andLevl = -1;
			foreach( explode('&', $orsPrt) as $andPrt ){
				$nameTxt = trim($andPrt);
				$isNegtd = (strpos($nameTxt, '~')===0);
				$nameTxt = trim( ltrim($nameTxt, '~') );
				if( !preg_match('/^E_[A-Z_]+$/D', $nameTxt) || !defined($nameTxt) || !is_int(constant($nameTxt)) ){
					throw new \RuntimeException('ERR_LEVL holds what is not an error level: '.($nameTxt!='' ? $nameTxt : $lvlText));
				}
				$andLevl = $andLevl & ( $isNegtd ? ~constant($nameTxt) : constant($nameTxt) );
			}
			$errLevl = $errLevl | $andLevl;
		}
		return $errLevl & E_ALL;
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
