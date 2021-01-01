<?php

namespace nyu\Tools;

use nyu\Output\Response;


class Request{
	
	protected $serveReg;
	protected $clienReg;
	
	protected $inMethod;
	protected $queryURI;
	protected $queryStr;
	protected $allHeads;
	protected $rawInput;
	
	protected $dataVals; // Registry of values
	protected $cookVals; // Registry of readable cookie values (written through response)
	protected $fileData; // Registry of files
	//protected $agentStr; // Registry of values (Moved to Client)
	
	// Shoud get the encode parameter and format each value to that encoding
	 function __construct( ServerReg $srvRegistr, Response $httpRespns ){
		
		$this->serveReg = $srvRegistr;
		
		// Request method
		$this->inMethod = $this->serveReg->readValue('request_method');
		
		// Query Values
		$rqstURI = $this->serveReg->readValue('request_uri');
		$quoMark = strRPos($rqstURI, '?');
		$qParams = Array();

		if( $quoMark!==false ){
			$urlPage = subStr($rqstURI, 0, $quoMark);
			$qParStr = subStr($rqstURI, $quoMark+1);
			parse_str($qParStr, $qParams);
		}else{
			$urlPage = $rqstURI;
		}
		$uriVals = Array(
						'htScheme'=>$this->serveReg->readValue('request_scheme'),
						'servName'=>$this->serveReg->readValue('server_name'),
						'reqstURI'=>$rqstURI,
						'document'=>$this->serveReg->readValue('script_name'),
						'protocol'=>$this->serveReg->readValue('server_protocol')
					);
		$uriVals['full_url'] = 	$uriVals['htScheme'].'://'.$uriVals['servName'].$urlPage;
		$uriVals['full_uri'] = 	$this->inMethod.' '.$uriVals['htScheme'].'://'.$uriVals['servName']
								.$uriVals['reqstURI'].' '.$uriVals['document'].' '.$uriVals['protocol'];
		$this->queryURI = $uriVals;
		$this->queryStr = $this->serveReg->readValue('query_string');
		
		// Get received headers
		$this->allHeads = $this->getHeaders();
		
		$this->getContent( $qParams );
	}
	
	// Get query variables, cookies and files
	protected function getContent( Array $addrValues = Array() ){
		// Read Variables
		$this->rawInput = $this->getRawData();
		if(!empty($_GET)){
			$readVal = $this->rawInput['vars'];
			$this->dataVals = array_merge($readVal, $addrValues);
		}
		if(!empty($_POST)){
			$readVal = $this->rawInput['vars'];
			$this->dataVals = array_merge($readVal, $_POST);
		}
		
		// Get cookies and files
		$this->cookVals = $_COOKIES;
		// TODO: Both file arrays should get the same format / structure
		$this->fileData = array_merge($_FILES, $this->rawInput['file']);
		
		// TODO : Do we really need this??? ClientCon should get this...
		//$this->agentStr = $this->readHeader('User-Agent');
		
		// Unset super globals
		unset($_GET);
		unset($_POST);
		unset($_COOKIES);
		unset($_FILES);
		unset($_REQUEST);
	}
	
	//function servMethod
	protected function getRawData(){
		// Fetch content and determine boundary
		$raw_data = file_get_contents('php://input');
		$raw_data = str_replace("\r",'',$raw_data);
		$boundPos = strpos($raw_data, "\n");
		if($boundPos!==false){
			$boundary = substr($raw_data, 0, $boundPos );
		}else{
			// No boundaries found		
			$saveFlux = array(
				'head'=>Array(),
				'vars'=>Array(),
				'file'=>Array(),
				'data'=>$raw_data
			);
			return $saveFlux;
		}
		
		// Fetch each part
		$flxParts = array_slice( explode($boundary, $raw_data), 1 );
		$prtHeads = array();
		$saveData = array();
		$saveFile = array();

		foreach ($flxParts as $partLn) {
			
			$saveFile[] = $partLn;
			// If this is the last part, break
			if ($partLn == "--\n") break;

			// Separate content from headers
			$partsArr = explode("\n\n",$partLn,2);
			$partHead = str_replace("\n",';',$partsArr[0]);
			$headrArr = explode(';',$partHead);
			$varNames = array();

			foreach($headrArr as $headLn){
				if(trim($headLn)==''){
					continue;
				}
				$headVals = explode(':',$headLn);
				if(count($headVals)>1){
					$headers[trim($headVals[0])] = trim($headVals[1]);
				}else{
					$headLine = str_replace('"','',$headLn);
					$nameVals = explode('=',$headLine);
					if(count($nameVals)>1){
						if(trim($nameVals[1])!=''){
							$varNames[trim($nameVals[0])] = trim($nameVals[1]);
						}
					}
				}
			}
			// Get part content and store in named array
			if(isset($varNames['filename'])){
				$fileData[trim($varNames['filename'])] = trim($partsArr[1]);
			}else{
				$saveData[trim($varNames['name'])] = trim($partsArr[1]);
			}
		}
		$saveFlux = array(
			'head'=>$headers,
			'vars'=>$saveData,
			'file'=>$fileData,
			'data'=>$raw_data
		);
		
		return $saveFlux;
	}
	
	//function getHeaders
	protected function getHeaders(){
		// NGinX compatibility
		if (!function_exists('apache_request_headers')){
			$headers = [];
			$serVars = $serveReg->readLines('http_');
			foreach ($serVars as $varKey => $varVal){
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($varKey, 5)))))] = $varVal;
			}
			return $headers;
		}else{
			return apache_request_headers();
		}
	}
	
	// PUBLIC FUNCTIONS //
	
	public function usedMethod( String $checkValue='' ){
		if( $this->inMethod==$checkValue ){
			return true;
		}else{
			return false;
		}
	}
	public function readURLVal( String $uriValsKey='' ){
		if( isset( $this->queryURI[$uriValsKey] ) ){
			return $this->queryURI[$uriValsKey];
		}else{
			// TODO : Log error if key not in list
			return NULL;
		}
	}
	public function readHeader( String $headersKey='' ){
		if( isset( $this->allHeads[$headersKey] ) ){
			return $this->allHeads[$headersKey];
		}else{
			return NULL;
		}
	}
	
	public function readUsrVar( $usrVarName ){
		if( isset( $this->dataVals[$usrVarName] ) ){
			return $this->dataVals[$usrVarName];
		}else{
			return NULL;
		}
	}
	public function readCookie( $cookieName ){
		if( isset( $this->cookVals[$cookieName] ) ){
			return $this->cookVals[$cookieName];
		}else{
			return NULL;
		}
	}
	public function readInFile( $fileArrKey ){
		if( isset( $this->fileData[$fileArrKey] ) ){
			return $this->fileData[$fileArrKey];
		}else{
			return NULL;
		}
	}

}