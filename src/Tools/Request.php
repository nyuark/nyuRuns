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

	/**
	 * Builds the request object: derives the method, URI, query string and
	 * headers from the server registry, then loads the request's content
	 * (body vars, cookies, files).
	 *
	 * @param ServerReg $srvRegistr Registry of $_SERVER values.
	 * @param Response  $httpRespns The response object (reserved for cookie writes).
	 *
	 * @todo Should read an encoding parameter and format each value to that encoding.
	 */
	 function __construct( ServerReg $srvRegistr, Response $httpRespns ){

		$this->serveReg = $srvRegistr;

		// Request method
		$this->inMethod = $this->serveReg->readValue('request_method');

		// Query Values
		$rqstURI = $this->serveReg->readValue('request_uri');
		$quoMark = strPos($rqstURI, '?');
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
		$this->allHeads = $this->getHeadrs();

		$this->getContnt( $qParams );
	}

	// Get query variables, cookies and files
	/**
	 * Reads query variables, cookies and files into the request's internal
	 * registries, merging raw parsed body vars with $_GET/$_POST, then
	 * unsets the superglobals so nothing downstream reads them directly.
	 *
	 * @param Array $addrValues Query-string values parsed from the request URI.
	 */
	protected function getContnt( Array $addrValues = Array() ): Void {
		// Read Variables
		$this->rawInput = $this->getRawDat();
		$this->dataVals = Array();
		if(!empty($addrValues)){
			$this->dataVals = array_merge($this->dataVals, $addrValues);
		}
		if(!empty($this->rawInput['vars'])){
			$this->dataVals = array_merge($this->dataVals, $this->rawInput['vars']);
		}
		if(!empty($_GET)){
			$this->dataVals = array_merge($this->dataVals, $_GET);
		}
		if(!empty($_POST)){
			$this->dataVals = array_merge($this->dataVals, $_POST);
		}

		// Get cookies and files
		$this->cookVals = $_COOKIE;
		// TODO: Both file arrays should get the same format / structure
		$this->fileData = array_merge($_FILES, $this->rawInput['file']);

		// TODO : Do we really need this??? ClientCon should get this...
		//$this->agentStr = $this->getHeader('User-Agent');

		// Unset super globals
		unset($_GET);
		unset($_POST);
		unset($_COOKIE);
		unset($_FILES);
		unset($_REQUEST);
	}

	//function servMethod
	/**
	 * Reads and parses the raw request body from php://input: a JSON or
	 * URL-encoded body is decoded into variables, a multipart body is
	 * split by boundary into headers, variables and files. Any other
	 * body is returned untouched.
	 *
	 * @return Array Associative array with 'head', 'vars', 'file' and 'data' keys.
	 */
	protected function getRawDat(): Array {
		// Fetch content and determine boundary
		$rawData = file_get_contents('php://input');

		// JSON body (POST/PUT/PATCH) : decode directly, no boundary framing
		$cntType = $this->getHeader('Content-Type');
		if($cntType!==null && stripos($cntType, 'application/json')!==false){
			$jsnVals = json_decode($rawData, true);
			return array(
				'head'=>Array('Content-Type'=>$cntType),
				'vars'=>is_array($jsnVals) ? $jsnVals : Array(),
				'file'=>Array(),
				'data'=>$rawData
			);
		}

		// URL-encoded form body (PUT/PATCH) : PHP only parses these for POST
		if($cntType!==null && stripos($cntType, 'application/x-www-form-urlencoded')!==false){
			parse_str($rawData, $frmVals);
			return array(
				'head'=>Array('Content-Type'=>$cntType),
				'vars'=>$frmVals,
				'file'=>Array(),
				'data'=>$rawData
			);
		}

		// Only a multipart body is split by boundary
		if($cntType===null || stripos($cntType, 'multipart/')===false){
			return array(
				'head'=>Array(),
				'vars'=>Array(),
				'file'=>Array(),
				'data'=>$rawData
			);
		}

		$rawData = str_replace("\r",'',$rawData);
		$bndrPos = strpos($rawData, "\n");
		if($bndrPos!==false){
			$boundry = substr($rawData, 0, $bndrPos );
		}else{
			// No boundaries found
			$saveFlx = array(
				'head'=>Array(),
				'vars'=>Array(),
				'file'=>Array(),
				'data'=>$rawData
			);
			return $saveFlx;
		}

		// Fetch each part
		$flxPrts = array_slice( explode($boundry, $rawData), 1 );
		$prtHeds = array();
		$savData = array();
		$savFile = array();

		foreach ($flxPrts as $partLn) {
			// If this is the last part, break
			if ($partLn == "--\n") break;

			// Separate content from headers
			$partArr = explode("\n\n",$partLn,2);
			$prtHead = str_replace("\n",';',$partArr[0]);
			$headArr = explode(';',$prtHead);
			$vrNames = array();

			foreach($headArr as $headLn){
				if(trim($headLn)==''){
					continue;
				}
				$hedVals = explode(':',$headLn);
				if(count($hedVals)>1){
					$prtHeds[trim($hedVals[0])] = trim($hedVals[1]);
				}else{
					$hedLine = str_replace('"','',$headLn);
					$namVals = explode('=',$hedLine);
					if(count($namVals)>1){
						if(trim($namVals[1])!=''){
							$vrNames[trim($namVals[0])] = trim($namVals[1]);
						}
					}
				}
			}
			// Get part content and store in named array
			if(isset($vrNames['filename'])){
				$savFile[trim($vrNames['filename'])] = trim($partArr[1]);
			}else{
				if(isset($vrNames['name'])){
					$savData[trim($vrNames['name'])] = trim($partArr[1]);
				}
			}
		}

		$readFlx = array(
			'head'=>$prtHeds,
			'vars'=>$savData,
			'file'=>$savFile,
			'data'=>$rawData
		);

		return $readFlx;
	}

	//function getHeadrs
	/**
	 * Retrieves all request headers, using apache_request_headers() when
	 * available or rebuilding them from the server registry's HTTP_* values
	 * for Nginx/other-SAPI compatibility.
	 *
	 * @return Array Associative array of header name => value.
	 */
	protected function getHeadrs(): Array {
		// NGinX compatibility
		if (!function_exists('apache_request_headers')){
			$headers = Array();
			$xcepted = Array(
				'Dasl'             => 'DASL',
                'Dav'              => 'DAV',
                'Etag'             => 'ETag',
                'Mime-Version'     => 'MIME-Version',
                'Slug'             => 'SLUG',
                'Te'               => 'TE',
                'Www-Authenticate' => 'WWW-Authenticate',
                'Content-Md5'      => 'Content-MD5',
                'Content-Id'       => 'Content-ID',
                'Content-Features' => 'Content-features',
			);
			$serVars = $this->serveReg->readLines('http_');
			foreach ($serVars as $varKey => $varVal){
				$headKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($varKey, 5)))));
				if ( array_key_exists( $headKey, $xcepted ) ) {
                    $headKey = $xcepted[ $headKey ];
                }
				$headers[$headKey] = $varVal;
			}
			return $headers;
		}else{
			return apache_request_headers();
		}
	}

	// PUBLIC FUNCTIONS //

	/**
	 * Checks whether the request's HTTP method matches the given value.
	 *
	 * @param String $checkValue HTTP method to compare against (e.g. 'GET', 'POST').
	 * @return Bool True if the request used that method.
	 */
	public function usedMethd( String $checkValue='' ): Bool {
		if( $this->inMethod==$checkValue ){
			return true;
		}else{
			return false;
		}
	}
	/**
	 * Reads a value from the parsed request URI.
	 *
	 * @param String $uriValsKey Key of the URI value to read.
	 * @return mixed The value, or null if not set.
	 */
	public function getURLVal( String $uriValsKey='' ): Mixed {
		if( isset( $this->queryURI[$uriValsKey] ) ){
			return $this->queryURI[$uriValsKey];
		}else{
			// TODO : Log error if key not in list
			return NULL;
		}
	}
	/**
	 * Reads a single header value received with the request.
	 *
	 * @param String $headersKey Name of the header to read.
	 * @return mixed The header value, or null if not present.
	 */
	public function getHeader( String $headersKey='' ): ?String {
		if( isset( $this->allHeads[$headersKey] ) ){
			return $this->allHeads[$headersKey];
		}else{
			return NULL;
		}
	}

	/**
	 * Reads a single user-submitted value (query/body) received with the
	 * request.
	 *
	 * @param String $usrVarName Name of the value to read.
	 * @return mixed The value, or null if not present.
	 */
	public function getUsrVar( String $usrVarName ): Mixed {
		if( isset( $this->dataVals[$usrVarName] ) ){
			return $this->dataVals[$usrVarName];
		}else{
			return NULL;
		}
	}
	/**
	 * Reads a single cookie value received with the request.
	 *
	 * @param String $cookieName Name of the cookie to read.
	 * @return mixed The cookie value, or null if not present.
	 */
	public function getCookie( String $cookieName ): Mixed {
		if( isset( $this->cookVals[$cookieName] ) ){
			return $this->cookVals[$cookieName];
		}else{
			return NULL;
		}
	}
	/**
	 * Reads a single uploaded file entry received with the request.
	 *
	 * @param String $fileArrKey Key of the file entry to read.
	 * @return mixed The file data, or null if not present.
	 */
	public function getInFile( String $fileArrKey ): Mixed {
		if( isset( $this->fileData[$fileArrKey] ) ){
			return $this->fileData[$fileArrKey];
		}else{
			return NULL;
		}
	}
	/**
	 * Returns every user-submitted value (query, form, or JSON body)
	 * merged into a single array, for use by the input-filtering layer.
	 *
	 * @return Array All parsed request values.
	 */
	public function getAllVar(): Array {
		return $this->dataVals ?? Array();
	}
	/**
	 * Returns server registry values, optionally filtered by key prefix,
	 * for use by the input-filtering layer.
	 *
	 * @param String $keysFilter Prefix to filter keys by; returns all entries when empty.
	 * @return Array Matching server values.
	 */
	public function getSrvVar( String $keysFilter='' ): Array {
		return $this->serveReg->readLines($keysFilter);
	}

}
