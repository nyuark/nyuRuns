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
	protected $tmpFiles = Array(); // Temporary files written for uploads received in the raw body
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
		// Files of the raw body are described like the $_FILES entries (see saveUpFle)
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
		// Fetch content
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

		return $this->readMulti( $rawData, $cntType );
	}

	/**
	 * Splits a multipart body into variables and files, cutting on the
	 * boundary announced by the Content-Type header so that binary
	 * content is left byte for byte as it was sent.
	 *
	 * @param String $rawContent Raw request body.
	 * @param String $contntType Content-Type header of the request.
	 * @return Array Associative array with 'head', 'vars', 'file' and 'data' keys.
	 */
	protected function readMulti( String $rawContent, String $contntType ): Array {
		$readFlx = array(
			'head'=>Array(),
			'vars'=>Array(),
			'file'=>Array(),
			'data'=>$rawContent
		);
		if( !preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contntType, $bndVals) ){
			return $readFlx;
		}
		$boundry = '--'.($bndVals[1]!='' ? $bndVals[1] : $bndVals[2]);

		// The first piece is what precedes the first boundary
		$flxPrts = array_slice( explode($boundry, $rawContent), 1 );
		foreach( $flxPrts as $partLn ){
			// The closing boundary is followed by "--"
			if( substr($partLn, 0, 2)=='--' ){
				break;
			}
			// A part is : line break, headers, empty line, content, line break
			$partLn = preg_replace('/^\r?\n/', '', $partLn);
			if( !preg_match('/\r?\n\r?\n/', $partLn, $sepVals, PREG_OFFSET_CAPTURE) ){
				continue;
			}
			$prtHead = substr($partLn, 0, $sepVals[0][1]);
			$prtBody = substr($partLn, $sepVals[0][1] + strlen($sepVals[0][0]));
			$prtBody = preg_replace('/\r?\n\z/', '', $prtBody);

			$fldName = null;
			$fleName = null;
			$prtType = '';
			foreach( preg_split('/\r?\n/', $prtHead) as $hedLin ){
				$colnPos = strpos($hedLin, ':');
				if( $colnPos===false ){
					continue;
				}
				$hedName = trim(substr($hedLin, 0, $colnPos));
				$hedText = trim(substr($hedLin, $colnPos+1));
				$readFlx['head'][$hedName] = trim(strtok($hedText, ';'));
				if( strcasecmp($hedName, 'Content-Disposition')==0 ){
					if( preg_match('/\bname=(?:"([^"]*)"|([^;\s]+))/i', $hedText, $nmeVals) ){
						$fldName = $nmeVals[2] ?? $nmeVals[1];
					}
					if( preg_match('/\bfilename=(?:"([^"]*)"|([^;\s]+))/i', $hedText, $fnmVals) ){
						$fleName = $fnmVals[2] ?? $fnmVals[1];
					}
				}elseif( strcasecmp($hedName, 'Content-Type')==0 ){
					$prtType = $hedText;
				}
			}
			if( $fldName===null || $fldName==='' ){
				continue;
			}

			if( $fleName!==null ){
				$fleVals = $this->saveUpFle( $fleName, $prtType, $prtBody );
				$brckPos = strpos($fldName, '[');
				if( $brckPos>0 ){
					// field[], field[key] or field[a][b] : one array per property, as PHP fills $_FILES
					$fldBase = substr($fldName, 0, $brckPos);
					foreach( $fleVals as $prpKey => $prpVal ){
						$this->nestValue( $readFlx['file'][$fldBase][$prpKey], substr($fldName, $brckPos), $prpVal );
					}
				}else{
					$readFlx['file'][$fldName] = $fleVals;
				}
			}elseif( strpos($fldName, '[')!==false ){
				// A variable named like filters[search] or tags[] is nested, as PHP does for forms
				parse_str($fldName.'='.urlencode($prtBody), $nstVals);
				$readFlx['vars'] = array_merge_recursive($readFlx['vars'], $nstVals);
			}else{
				$readFlx['vars'][$fldName] = $prtBody;
			}
		}
		return $readFlx;
	}

	/**
	 * Stores a value under the keys of a bracket path such as [cover],
	 * [a][b] or [] (next free index), creating the levels on the way.
	 *
	 * @param ?Array $targetNode Array to store into, created when null.
	 * @param String $bracketStr The bracket part of a field name.
	 * @param mixed  $leafValues Value to store at the end of the path.
	 */
	protected function nestValue( ?Array &$targetNode, String $bracketStr, Mixed $leafValues ): Void {
		preg_match_all('/\[([^\]]*)\]/', $bracketStr, $keyVals);
		$nodeRef = &$targetNode;
		foreach( $keyVals[1] as $keyNme ){
			if( $keyNme==='' ){
				$nodeRef[] = null;
				$nodeRef = &$nodeRef[array_key_last($nodeRef)];
			}else{
				$nodeRef = &$nodeRef[$keyNme];
			}
		}
		$nodeRef = $leafValues;
	}

	/**
	 * Stores an uploaded file's content in a temporary file and describes
	 * it the way PHP describes a POST upload in $_FILES: 'name' is the file
	 * name without any folder, 'full_path' the name as the client gave it.
	 *
	 * @param String $uploadName File name given by the client.
	 * @param String $uploadType Content type given by the client.
	 * @param String $fileContnt Content of the file.
	 * @return Array The file entry : 'name', 'full_path', 'type', 'tmp_name', 'error' and 'size'.
	 */
	protected function saveUpFle( String $uploadName, String $uploadType, String $fileContnt ): Array {
		$baseNme = preg_replace('#^.*[/\\\\]#', '', $uploadName);
		$fleVals = Array('name'=>$baseNme, 'full_path'=>$uploadName, 'type'=>$uploadType, 'tmp_name'=>'', 'error'=>UPLOAD_ERR_NO_FILE, 'size'=>0);
		if( $uploadName==='' ){
			return $fleVals;
		}
		$tmpPath = tempnam(sys_get_temp_dir(), 'nyu');
		$fleSize = ($tmpPath!==false) ? file_put_contents($tmpPath, $fileContnt) : false;
		if( $fleSize===false ){
			$fleVals['error'] = UPLOAD_ERR_CANT_WRITE;
			return $fleVals;
		}
		if( empty($this->tmpFiles) ){
			register_shutdown_function( Array($this, 'dropTemps') );
		}
		$this->tmpFiles[] = $tmpPath;
		$fleVals['tmp_name'] = $tmpPath;
		$fleVals['error'] = UPLOAD_ERR_OK;
		$fleVals['size'] = $fleSize;
		return $fleVals;
	}

	/**
	 * Deletes the temporary upload files still present at the end of the
	 * request. A file the application moved elsewhere is left alone.
	 */
	public function dropTemps(): Void {
		foreach( $this->tmpFiles as $tmpFle ){
			if( file_exists($tmpFle) ){
				@unlink($tmpFle);
			}
		}
		$this->tmpFiles = Array();
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
		// Header names are case-insensitive
		foreach( $this->allHeads as $hedKey => $hedVal ){
			if( strcasecmp($hedKey, $headersKey)==0 ){
				return $hedVal;
			}
		}
		return NULL;
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
