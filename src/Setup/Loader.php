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

	protected $blockNames;
	protected $confBlocks;
	protected $routingObj;
	// The values read from the config cache, or those gathered to write it (see ConfCache)
	protected $cachValues;
	// Whether they were read from the cache
	protected $isCacheHit;
	// Name of the preset, which the cache is kept under
	protected $presetName;

	/**
	* Constructs a setup loader, optionally loading a full setup array.
	*
	* @param Array  $fullSetupLst Full setup array to load, if any.
	* @param ?Array $cachedValues The values the config cache holds for the preset, read in place of the files; null when the files are read.
	* @param String $nameOfPreset Name of the preset, which the cache is kept under; '' for no cache.
	*/
    public function __construct(Array $fullSetupLst = Array(), ?Array $cachedValues = null, String $nameOfPreset = ''){
		$this->setAllRegistry( $fullSetupLst );
		$this->blockNames = Array('settings','security','dispatch','response','inputSet');
		$this->presetName = $nameOfPreset;
		$this->isCacheHit = ($cachedValues!==null);
		// The preset is kept as it is before any overload of the Runner
		$this->cachValues = $cachedValues ?? Array('preset'=>$fullSetupLst);
	}

	/**
	* Replaces (without key check) the current config registry with the one provided.
	*
	* @param Array $fullSetupLst Setup array that replaces the current one.
	*/
    protected function setAllRegistry(Array $fullSetupLst = Array()): Void {
		$this->confBlocks = $fullSetupLst;
	}

	/**
	* Overload some or all of the setup array, usually from the runner.
	*
	* @param Array $addedEntries Setup blocks to load, by key.
	* @param Bool  $mergeEntries Whether a block is merged with the one already loaded instead of replacing it.
	*/
	public function overSetupLoads(Array $addedEntries, Bool $mergeEntries = false): Void {
		foreach($addedEntries as $arrKey=>$arrVal){
			if( $mergeEntries ){
				$prevList = $this->getRegistryKey( $arrKey );
				if( $prevList!==false ){
					$arrVal = array_merge($prevList, $arrVal);
				}
			}
			$this->setRegistryKey($arrKey, $arrVal);
		}
	}

	/**
	* Gets one of the config registry values using one of the conf key array.
	*
	* @param String $setupBlcClef Key of the setup block.
	* @return Mixed The block, or false when the key is unknown or not loaded.
	*/
    public function getRegistryKey(String $setupBlcClef): Mixed {
		if(array_key_exists($setupBlcClef, $this->confBlocks) && in_array($setupBlcClef, $this->blockNames) ){
			return $this->confBlocks[$setupBlcClef];
		}else{
			return false;
		}
	}

	/**
	* Sets one of the config registry values using the conf key array
	*
	* @param String $setupBlcClef Key of the setup block.
	* @param Array  $newArrayVals Values of the block.
	*/
    protected function setRegistryKey(String $setupBlcClef, Array $newArrayVals = Array()): Void {
		if(in_array($setupBlcClef,$this->blockNames)){
			$this->confBlocks[$setupBlcClef] = $newArrayVals;
		}
	}

	/**
	* Get the configuration files based on the setup key of configuration registry.
	* With the config cache, its values are taken in place of the files; the
	* env: values are filled on each request, from the environment file.
	*
	* @return Registry The values of the config files.
	* @throws \RuntimeException When the preset has no settings block.
	*/
	function retrieveConfig(): Registry {
		$confDict = $this->getRegistryKey('settings');
		if( $confDict===false ){
			throw new \RuntimeException('The preset has no settings block');
		}
		if( $this->isCacheHit ){
			$confRegr = new Registry( $this->replaceEnvVals( $this->cachValues['config'], $this->lodEnvironment($confDict['envrFile'] ?? '.env') ) );
			// The descriptions of the tables the cache keeps, when they are kept (TBL_CACH, with CNF_CACH)
			$iniConfg = $confRegr->readValueByKey('system') ?: Array();
			ConfCache::$tableInfos = ( in_array(strtoupper(strval($iniConfg['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) && in_array(strtoupper(strval($iniConfg['TBL_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) ) ? (array)($this->cachValues['tables'] ?? Array()) : null;
			ConfCache::$isTblAdded = false;
			return $confRegr;
		}
		// Read all INI and JSON files in conf
		// folder and store it into an array
		$confList = Array();
		// Folder of the config files, relative to the project root : conf unless the preset names another
		$conxPath = TOP_DIR.DS.($confDict['confPath'] ?? 'conf').DS;
		$conxScan = scandir($conxPath);
		// Allowed file types
		$allowTyp = Array('ini','json');
		// File names to load : 'any', or a comma-separated list of names without extension
		$confFilt = $confDict['confList'] ?? 'any';
		$nameList = false;
		if($confFilt!='any'){
			$nameList = array_map('trim', explode(',', $confFilt));
		}
		foreach($conxScan as $filNme){
			// The kind and the name are told by the file name: only the files kept are opened
			$fileExtn = strtolower(pathinfo($filNme, PATHINFO_EXTENSION));
			$fileTitl = pathinfo($filNme, PATHINFO_FILENAME);
			if( !in_array($fileExtn, $allowTyp) || ($nameList!==false && !in_array($fileTitl, $nameList)) ){
				continue;
			}
			if( $confDict['confType']!='all' && $confDict['confType']!=$fileExtn ){
				continue;
			}
			$nowTrail = $conxPath.$filNme;
			if( is_file($nowTrail) ){
				$nyuFichr = new Dcmnt($nowTrail);
				$confList[strtoupper($fileTitl)] = $nyuFichr->readFileDetail('out');
			}
		}
		// The cache holds the values as written: no secret of the environment file goes into it
		$this->cachValues['config'] = $confList;
		$confList = $this->replaceEnvVals( $confList, $this->lodEnvironment($confDict['envrFile'] ?? '.env') );
		$confRegr = new Registry( $confList );
		// The descriptions of the tables are kept from this request on, when they are (TBL_CACH, with CNF_CACH)
		$iniConfg = $confRegr->readValueByKey('system') ?: Array();
		ConfCache::$tableInfos = ( in_array(strtoupper(strval($iniConfg['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) && in_array(strtoupper(strval($iniConfg['TBL_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) ) ? Array() : null;
		ConfCache::$isTblAdded = false;
		return $confRegr;
	}

	/**
	 * Reads the environment file of the project: NAME=value lines, with
	 * # comments, kept out of the repository to hold the secrets.
	 *
	 * @param String $relativePath Path of the file, relative to the project root.
	 * @return Array The values by name, empty when the file does not exist.
	 */
	protected function lodEnvironment( String $relativePath ): Array {
		$envrVals = Array();
		$envrPath = TOP_DIR.DS.$relativePath;
		if( !is_file($envrPath) ){
			return $envrVals;
		}
		foreach( file($envrPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $envRow ){
			$envRow = trim($envRow);
			$splitIdx = strpos($envRow, '=');
			if( $envRow=='' || $envRow[0]=='#' || $splitIdx===false ){
				continue;
			}
			$envrVals[trim(substr($envRow, 0, $splitIdx))] = trim(trim(substr($envRow, $splitIdx+1)), '"\'');
		}
		return $envrVals;
	}

	/**
	 * Replaces every config value written env:NAME by the value of NAME,
	 * taken from the environment file first, then from the environment
	 * of the process. An unknown name gives an empty value.
	 *
	 * @param Array $configValues Config values, at any depth.
	 * @param Array $envValuesDct Values of the environment file, by name.
	 * @return Array The config values, with their env: references replaced.
	 */
	protected function replaceEnvVals( Array $configValues, Array $envValuesDct ): Array {
		foreach( $configValues as $cnfKey => $cnfVal ){
			if( is_array($cnfVal) ){
				$configValues[$cnfKey] = $this->replaceEnvVals($cnfVal, $envValuesDct);
			}elseif( is_string($cnfVal) && strpos($cnfVal, 'env:')===0 ){
				$envrName = substr($cnfVal, 4);
				$matxRows = getenv($envrName);
				$configValues[$cnfKey] = $envValuesDct[$envrName] ?? ($matxRows!==false ? $matxRows : '');
			}
		}
		return $configValues;
	}

    /**
	 * Builds the registry of allowed input values by instantiating an
	 * Input object for each configured source (QUERY is the only one in
	 * version 1.0; DFILE, DBREC, XTAPI, SESSN and RFLUX come with 1.1).
	 * Server and client values are not inputs: they are read through the
	 * request (getSrvVar, getClient). The 'fieldSet' of a source are 'all',
	 * or a definition of the fields kept and of their formats (see
	 * Tools\Input); an application can define them for each of its
	 * actions, which its Controller applies.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return Registry Registry of parsed input values, empty if no 'inputSet' config is set.
	 */
    function retrieveInputs( Request $givenRequest ): Registry {
		$inputDct = $this->getRegistryKey('inputSet');
		if($inputDct){
			$inputLst = Array();
			foreach($inputDct as $putSet){
				// Only allow for a set of input sources
				// Version 1.0 :	'QUERY'
				// Version 1.1 :	'DFILE', 'DBREC', 'XTAPI', 'SESSN', 'RFLUX'
				$inputCod = Array('QUERY');
				$inputSrc = $putSet['readFrom'];

				if(in_array($inputSrc, $inputCod)){
					$inputObj = new Input( $givenRequest, $inputSrc, $putSet['fieldSet'], $putSet['sourceId']);
					$inputLst = array_merge($inputLst, $inputObj->readRgstValues());
				}
			}
			$inputReg = new Registry( $inputLst );
			return $inputReg;
		}else{
			$inputReg = new Registry( Array() );
			return $inputReg;
		}
	}

    /**
	 * Resolves the application/action/param to run for the current request,
	 * through the Router and the 'dispatch' configuration of the active preset.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @param String  $basePathText The basePath of the line of the map of presets chosen ('' for the root): taken off the path, kept as 'routBase'.
	 * @return Registry Registry holding the resolved 'appToRun' and 'doAction' values, and the 'routBase' of the request.
	 */
    function importRouteReg( Request $givenRequest, String $basePathText='' ): Registry {
		$routeCfg = $this->getRegistryKey('dispatch');
		$this->routingObj = new Router( is_array($routeCfg) ? $routeCfg : Array(), $givenRequest, $basePathText );
		if( $this->isCacheHit ){
			$this->routingObj->assignRouteSet( $this->cachValues['routes'] );
		}
		$routRegr = $this->routingObj->locateRouteReg();
		$routRegr->saveValueByKey('routBase', $basePathText);
		return $routRegr;
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
	public function retainCacheFlg(): Bool {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		if( !in_array( strtoupper(strval($iniConfg['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true ) || ($this->isCacheHit && !ConfCache::$isTblAdded) || $this->presetName=='' || $this->routingObj===null || !isset($this->cachValues['config']) ){
			return false;
		}
		// A config, preset or route file written since the request began (the Runs' routes too): what it read is not cached
		$srceUnix = 0;
		foreach( array_merge( (array)glob(TOP_DIR.DS.'conf'.DS.'*.ini'), (array)glob(TOP_DIR.DS.'conf'.DS.'*.json'), (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps'),
			(array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*.rte'), (array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*'.DS.'*.rte'), defined('RUN_DIR') ? (array)glob(RUN_DIR.DS.'*'.DS.'routes.rte') : Array() ) as $srcFil ){
			$srceUnix = max($srceUnix, intval(@filemtime(strval($srcFil))));
		}
		// File times are in seconds: a change within the second the request began is not told apart
		if( $srceUnix>intval($_SERVER['REQUEST_TIME'] ?? time()) ){
			return false;
		}
		$this->cachValues['routes'] = $this->routingObj->retrieveRoutes();
		$this->cachValues['tables'] = ConfCache::$tableInfos ?? Array();
		ConfCache::$isTblAdded = false;
		if( !ConfCache::buildCacheFlag($this->presetName, $this->cachValues) ){
			return false;
		}
		// Written once: the end of the request writes it again only for tables described meanwhile
		$this->isCacheHit = true;
		return true;
	}

	/**
	 * Gives the router that resolved the route of the request, for the
	 * applications to build links with.
	 *
	 * @return ?Router The router, null as long as no route was resolved.
	 */
	public function retrieveRouter(): ?Router {
		return $this->routingObj;
	}

    /**
	 * Retrieves the resolved 'security' configuration block for the active
	 * preset, for the Access\Checker to act on.
	 *
	 * @return Array The 'security' config block, or ['required' => false] if none is set.
	 */
    function setAccessArray(): Array {
		$accsConf = $this->getRegistryKey('security');
		return is_array($accsConf) ? $accsConf : Array('required'=>false);
	}

	/**
	 * Resolves the logging configuration from the SYSTEM config file,
	 * applying sensible defaults for anything not set. The log folder is
	 * always one of the project: LOG_PATH is read from the project root,
	 * with or without a leading slash, and is temp/logs when not set.
	 *
	 * @return Array Log settings: 'path', 'mode', 'size', 'rota', 'pref'.
	 */
	function logConfigArray(): Array {
		$nyuConfg = $GLOBALS['nyuConfig'];
		$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		$fieldNme = trim( strval($iniConfg['LOG_PATH'] ?? ''), '/\\ ' );
		return Array(
			'path' => TOP_DIR.DS.($fieldNme!='' ? str_replace(Array('/', '\\'), DS, $fieldNme) : 'temp'.DS.'logs'),
			'mode' => $iniConfg['LOG_MODE'] ?? 'FULL',
			'size' => $iniConfg['LOG_SIZE'] ?? 0,
			'rota' => $iniConfg['LOG_ROTA'] ?? 'INDX',
			'pref' => $iniConfg['LOG_PRFX'] ?? 'Y-m-d H:i',
		);
	}

	/**
	 * Resolves the sound configuration from the SYSTEM config file: where
	 * the program that unpacks sounds is, and how long it may run.
	 *
	 * @return Array Sound settings: 'tool' ('' when the server has none), 'time' in seconds.
	 */
	function soundConfigArr(): Array {
		$nyuConfg = $GLOBALS['nyuConfig'];
		$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		return Array(
			'tool' => strval($iniConfg['SND_TOOL'] ?? ''),
			'time' => intval($iniConfg['SND_SECS'] ?? 60),
		);
	}

	/**
	 * Resolves the reverse proxy configuration from the SYSTEM config file:
	 * the proxies standing in front of the server, and the headers in which
	 * they give the address of the client and the scheme it used.
	 *
	 * @return Array Proxy settings: 'list' of addresses or CIDR ranges (empty when no proxy is trusted), 'head' name of the header giving the address, 'schm' name of the one giving the scheme.
	 */
	function proxySetupConf(): Array {
		$nyuConfg = $GLOBALS['nyuConfig'];
		$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		$prxyList = array_filter( array_map('trim', explode(',', strval($iniConfg['PRX_LIST'] ?? ''))), 'strlen' );
		return Array(
			'list' => array_values($prxyList),
			'head' => strval($iniConfg['PRX_HEAD'] ?? '') ?: 'X-Forwarded-For',
			'schm' => strval($iniConfg['PRX_SCHM'] ?? '') ?: 'X-Forwarded-Proto',
		);
	}

	/**
	 * Resolves the limits of a request body from the SYSTEM config file:
	 * its largest size, and the most parts of a multipart body.
	 *
	 * @return Array Body limits: 'size' in KB (REQ_SIZE, 8192 when not set) and 'part' as a number of parts (REQ_PART, 200 when not set); 0 for no limit.
	 */
	function rqstConfigList(): Array {
		$nyuConfg = $GLOBALS['nyuConfig'];
		$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		return Array(
			'size' => max( 0, intval($iniConfg['REQ_SIZE'] ?? 8192) ),
			'part' => max( 0, intval($iniConfg['REQ_PART'] ?? 200) ),
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
	function errorConfigNum(): Int {
		$nyuConfg = $GLOBALS['nyuConfig'];
		$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		$levelTxt = trim( strval($iniConfg['ERR_LEVL'] ?? '') );
		if( $levelTxt=='' ){
			return E_ALL;
		}
		if( preg_match('/^\d+$/D', $levelTxt) ){
			return intval($levelTxt) & E_ALL;
		}
		$errorLvl = 0;
		foreach( explode('|', $levelTxt) as $orTerm ){
			$bitsMask = -1;
			foreach( explode('&', $orTerm) as $factor ){
				$nameText = trim($factor);
				$isNegatd = (strpos($nameText, '~')===0);
				$nameText = trim( ltrim($nameText, '~') );
				if( !preg_match('/^E_[A-Z_]+$/D', $nameText) || !defined($nameText) || !is_int(constant($nameText)) ){
					throw new \RuntimeException('ERR_LEVL holds what is not an error level: '.($nameText!='' ? $nameText : $levelTxt));
				}
				$bitsMask = $bitsMask & ( $isNegatd ? ~constant($nameText) : constant($nameText) );
			}
			$errorLvl = $errorLvl | $bitsMask;
		}
		return $errorLvl & E_ALL;
	}

    /**
	 * Defines the output format and response template by building a
	 * Response object from the 'response' configuration.
	 *
	 * @return Response The configured response object.
	 */
    function saveOutputResp(): Response {
		// Define the output format
		// and response template
		$outConfg = $this->getRegistryKey('response');

		$respInst = new Response($outConfg);
		return $respInst;
	}
}
