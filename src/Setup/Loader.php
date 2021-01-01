<?php

// A container to load configuration files, routes 
// acces, and retrieve conf

namespace nyu\Setup;

use \nyu\Tools\Input;
use \nyu\Tools\Registry;
use \nyu\Tools\Router;
use \nyu\Data\DFile;
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
		$this->fieldSet = Array('setup','input','route','axess','rnder');
		// TODO : Raise exception if empty
	}
	
	/**
	* Replaces (without key check) the current config registry with the one provided.
	* 
	* @Param Array	$setupArray
	*/
    protected function setAllReg(Array $setupArray = Array()){
		$this->conArray = $setupArray;
	}
	
	/**
	* Overload some or all of the setup array, usually from the runner.
	* 
	* @Param String	$keyOfArray
	* @Param Array	$newArrVals
	*/
	public function overLoads(Array $addedArray, Bool $arrayMerge = false){
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
    public function getRegKey(String $keyOfArray){
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
    protected function setRegKey(String $keyOfArray, Array $newArrVals = Array()){
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
	function getConfig(){
		// Read all INI and JSON files in conf
		// folder and store it into an array
		// TODO : Add XML
		$confArr = Array();
		// TODO : Raise error if getRegKey returns false
		$confSet = $this->getRegKey('setup');
		$conPath = TOP_DIR.DS.'conf'.DS;
		$conScan = scandir($conPath);
		// Allowed file types
		$allowFT = Array('ini','json');
		$conFltr = $confSet['fnames'];
		$nameLst = false;
		if($conFltr!='any' && strpos($conFltr,',')!==false){
			 $nameLst = explode('',$conFltr);
		}
		foreach($conScan as $fleNme){
			$nowPath = $conPath.$fleNme;
			if(!is_dir($nowPath) && is_file($nowPath)){
				$nyuFile = new DFile($nowPath);
				// Skip file if title is not in filter list
				if($nameLst!=false && !in_array($nyuFile->get('tit'),$nameLst)){
					continue;
				}
				if(in_array($nyuFile->get('ext'),$allowFT)){
					$conName = strtoupper($nyuFile->get('tit'));
					if( $confSet['ftypes']=='all' || $confSet['ftypes']==$nyuFile->get('ext') ){					
						$conVars = $nyuFile->get('out');
						$confArr[$conName] = $conVars;
					}
				}
			}
		}
		$confReg = new Registry( $confArr );
		return $confReg;
	}
	
    function getInputs(){
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
					$inptObj = new Input( $inpSrce, $putSet['fields'], $putSet['naming']);
					$inptArr = array_merge($inptArr, $inptObj->regStack());
				}
			}
			$inptReg = new Registry( $inptArr );
			return $inptReg;
		}
	}
	
    function loadRoute(){
		$rteConf = $this->getRegKey('route');
		$rteVals = Array(
			'app'=>null,
			'action'=>null
		);
		
		$urlPath = substr($_SERVER['REQUEST_URI'],1);
		$urlVars = $_SERVER['QUERY_STRING'];
		if($urlVars!=''){
			$urlPath = str_replace ($urlVars, '', $urlPath);
			$urlPath = substr( $urlPath, 0, -1 );
		}
		$urlPart = explode( '/', $urlPath );
		
		// Automatic path detection (from url path)
		if( $rteConf['detect'] == 'autopath' ){
			$mapPath = str_replace( '$', '', $rteConf['mapper'] );
			$mapPart = explode( '/', $mapPath );
			
			foreach( $mapPart as $mapIdx => $mapKey ){
				$currVal = null;
				if( isset( $urlPart[$mapIdx] ) ){
					$currVal = $urlPart[$mapIdx];
				}
				$rteVals[$mapKey] = $currVal;
			}
		}
				
		if(empty(join($rteVals)) || strPos($urlPath,'.php')!==false){
			$urlPath = str_replace('.php','',$urlPath);
			// Get route from entry point file
			$manRout = $GLOBALS['manualRoute'];
			if( $rteConf['detect'] == 'manual' && !empty($manRoute) ){
				$manVals = $manRout[$urlPath]['command'];
				$rteVals = Array(
					'app'=>		$appVals['app'],
					'action'=>	$appVals['act']
				);
			}else{
				if($urlPath==''){
					$urlPath = 'index';
				}
				// Get route from files library
				$rteConf['detect'] = 'routelib';
				$rtePath = TOP_DIR.DS.'conf'.DS.'routes.lib';
				$rteFile = new DFile($rtePath);
				$rteFile->fileParse( 'json', false );
				$rteData = $rteFile->get('out');
				if(isset($rteData[$urlPath])){
					$appVals = $rteData[$urlPath]['command'];
					$rteVals = Array(
						'app'=>		$appVals['app'],
						'action'=>	$appVals['act']
					);
				}else{
					// No route set
				}
			}
		}
		
		$routReg = new Registry( $rteVals );
		return $routReg;
	}
	
    function setAccess(){
		// Either retrieve access values and auth status
		// or redirect to proper auth page / handler
		$usrData = Array( 
			'logged' => false,
			'user' => '',
			'ipaddr' => '',
			'token' => ''
		);
		
		$userReg = new Registry( $usrData );
		return $userReg;
	}
	
	
    function setOutput(){
		// Define the output format
		// and response template
		$outConf = $this->getRegKey('rnder');
		
		$respObj = new Response($outConf);
		return $respObj;
	}	
}