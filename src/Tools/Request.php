<?php

namespace nyu\Tools;

use nyu\Output\Response;


/**
 * The HTTP request as the client sent it: method, path, headers, values,
 * cookies and uploaded files. It also gives the server values and what is
 * known of the client.
 */
class Request{

	protected $servRgstry;
	protected $clientRgst;

	protected $rqstInMthd;
	protected $requestUri;
	protected $queryStrng;
	protected $everyHeads;
	protected $nakedInput;

	protected $dataValues; // Registry of values
	protected $addrValues = Array(); // Names of the values sent in the query string, in lower case
	protected $cookValues; // Registry of readable cookie values (written through response)
	protected $uploadData; // Registry of files
	protected $temprryFls = Array(); // Temporary files written for uploads received in the raw body
	protected $maxSizeVal = 0; // Largest body read, in KB; 0 for no limit
	protected $pieceLimit = 0; // Most parts read from a multipart body; 0 for no limit
	protected $overLength = false; // Whether the body went over one of those limits

	/**
	 * Builds the request object: derives the method, URI, query string and
	 * headers from the server registry, then loads the request's content
	 * (body vars, cookies, files).
	 *
	 * @param ServerReg $srvrRegistry Registry of $_SERVER values.
	 * @param Response  $httpResponse The response object (reserved for cookie writes).
	 * @param ClientReg $clntRegistry Registry of client values (address, agent, system, device, language).
	 * @param Array     $limitsConfig The limits of a body: its largest 'size' in KB and the most 'part's of a multipart one, 0 or left out for no limit (see Setup\Loader::rqstConfigList).
	 *
	 * @todo Should read an encoding parameter and format each value to that encoding.
	 */
	 function __construct( ServerReg $srvrRegistry, Response $httpResponse, ClientReg $clntRegistry, Array $limitsConfig=Array() ){

		$this->servRgstry = $srvrRegistry;
		$this->clientRgst = $clntRegistry;
		$this->maxSizeVal = max( 0, intval($limitsConfig['size'] ?? 0) );
		$this->pieceLimit = max( 0, intval($limitsConfig['part'] ?? 0) );

		// Request method
		$this->rqstInMthd = $this->servRgstry->readFileDetail('request_method');

		// Query Values
		$rqstURI = $this->servRgstry->readFileDetail('request_uri');
		$quoMark = strPos($rqstURI, '?');
		$urlArgs = Array();

		if( $quoMark!==false ){
			$urlPage = subStr($rqstURI, 0, $quoMark);
			$qParStr = subStr($rqstURI, $quoMark+1);
			parse_str($qParStr, $urlArgs);
		}else{
			$urlPage = $rqstURI;
		}
		$urlInfo = Array(
						'htScheme'=>$this->clientRgst->readFileDetail('userSchm'),
						'servName'=>$this->servRgstry->readFileDetail('server_name'),
						'reqstURI'=>$rqstURI,
						'document'=>$this->servRgstry->readFileDetail('script_name'),
						'protocol'=>$this->servRgstry->readFileDetail('server_protocol')
					);
		$urlInfo['full_url'] = 	$urlInfo['htScheme'].'://'.$urlInfo['servName'].$urlPage;
		$urlInfo['full_uri'] = 	$this->rqstInMthd.' '.$urlInfo['htScheme'].'://'.$urlInfo['servName']
								.$urlInfo['reqstURI'].' '.$urlInfo['document'].' '.$urlInfo['protocol'];
		$this->requestUri = $urlInfo;
		$this->queryStrng = $this->servRgstry->readFileDetail('query_string');

		// Get received headers
		$this->everyHeads = $this->retrieveHeadrs();

		$this->getRqstContent( $urlArgs );
	}

	// Get query variables, cookies and files
	/**
	 * Reads query variables, cookies and files into the request's internal
	 * registries, merging raw parsed body vars with $_GET/$_POST, then
	 * unsets the superglobals so nothing downstream reads them directly.
	 *
	 * @param Array $queryStrVals Query-string values parsed from the request URI.
	 */
	protected function getRqstContent( Array $queryStrVals = Array() ): Void {
		// Read Variables
		$this->nakedInput = $this->getRawDataList();
		$this->dataValues = Array();
		// What came in the address is remembered by name (see fromQuery)
		$this->addrValues = array_map( 'strtolower', array_map('strval', array_merge(array_keys($queryStrVals), array_keys($_GET ?? Array()))) );
		if(!empty($queryStrVals)){
			$this->dataValues = array_merge($this->dataValues, $queryStrVals);
		}
		if(!empty($this->nakedInput['vars'])){
			$this->dataValues = array_merge($this->dataValues, $this->nakedInput['vars']);
		}
		if(!empty($_GET)){
			$this->dataValues = array_merge($this->dataValues, $_GET);
		}
		if(!empty($_POST)){
			$this->dataValues = array_merge($this->dataValues, $_POST);
		}

		// Get cookies and files
		$this->cookValues = $_COOKIE;
		// Files of the raw body are described like the $_FILES entries (see saveUpFle)
		$this->uploadData = array_merge($_FILES, $this->nakedInput['file']);

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
	protected function getRawDataList(): Array {
		// Fetch content
		$rawData = $this->readBodyCapped();

		// JSON body (POST/PUT/PATCH) : decode directly, no boundary framing
		$cntType = $this->retrieveHeader('Content-Type');
		if($cntType!==null && stripos($cntType, 'application/json')!==false){
			$jsonVls = json_decode($rawData, true);
			return array(
				'head'=>Array('Content-Type'=>$cntType),
				'vars'=>is_array($jsonVls) ? $jsonVls : Array(),
				'file'=>Array(),
				'data'=>$rawData
			);
		}

		// URL-encoded form body (PUT/PATCH) : PHP only parses these for POST
		if($cntType!==null && stripos($cntType, 'application/x-www-form-urlencoded')!==false){
			parse_str($rawData, $formVal);
			return array(
				'head'=>Array('Content-Type'=>$cntType),
				'vars'=>$formVal,
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

		return $this->readMultiArray( $rawData, $cntType );
	}

	/**
	 * Reads the raw body, up to the largest size allowed and no further:
	 * a body announced or found larger is not kept, and the request is
	 * marked as over its limit (see overLimit).
	 *
	 * @return String The body, empty when it is larger than allowed.
	 */
	protected function readBodyCapped(): String {
		if( $this->maxSizeVal<1 ){
			return strval(file_get_contents('php://input'));
		}
		$maxByts = $this->maxSizeVal * 1024;
		// What the request announces is checked first : nothing is then read
		$claimed = max( intval($this->retrieveHeader('Content-Length')), intval($this->servRgstry->readFileDetail('content_length')) );
		if( $claimed>$maxByts ){
			$this->overLength = true;
			return '';
		}
		// Read piece by piece : PHP 8.2 reserves the whole length asked of file_get_contents() before reading
		$rawData = '';
		$pointer = fopen('php://input', 'rb');
		if( $pointer!==false ){
			while( !feof($pointer) && strlen($rawData)<=$maxByts ){
				$pieceTx = fread($pointer, min(8192, $maxByts+1-strlen($rawData)));
				if( $pieceTx===false || $pieceTx==='' ){
					break;
				}
				$rawData .= $pieceTx;
			}
			fclose($pointer);
		}
		if( strlen($rawData)>$maxByts ){
			$this->overLength = true;
			return '';
		}
		return $rawData;
	}

	/**
	 * Tells whether the body of the request went over a limit: its size,
	 * or the parts of a multipart body. Nothing was read from such a body.
	 *
	 * @return Bool True when the request is to be refused as too large.
	 */
	public function overCeilingFlg(): Bool {
		return $this->overLength;
	}

	/**
	 * Splits a multipart body into variables and files, cutting on the
	 * boundary announced by the Content-Type header so that binary
	 * content is left byte for byte as it was sent.
	 *
	 * @param String $nakedContent Raw request body.
	 * @param String $sentMimeType Content-Type header of the request.
	 * @return Array Associative array with 'head', 'vars', 'file' and 'data' keys.
	 */
	protected function readMultiArray( String $nakedContent, String $sentMimeType ): Array {
		$redFlux = array(
			'head'=>Array(),
			'vars'=>Array(),
			'file'=>Array(),
			'data'=>$nakedContent
		);
		if( !preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $sentMimeType, $bndVals) ){
			return $redFlux;
		}
		$boundry = '--'.($bndVals[1]!='' ? $bndVals[1] : $bndVals[2]);

		// The first piece is what precedes the first boundary
		$flxPrts = array_slice( explode($boundry, $nakedContent), 1 );
		// The last piece closes the body : a body of more parts than allowed is not read
		if( $this->pieceLimit>0 && count($flxPrts)-1>$this->pieceLimit ){
			$this->overLength = true;
			return $redFlux;
		}
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
			$partHed = substr($partLn, 0, $sepVals[0][1]);
			$partBdy = substr($partLn, $sepVals[0][1] + strlen($sepVals[0][0]));
			$partBdy = preg_replace('/\r?\n\z/', '', $partBdy);

			$fldName = null;
			$fileNme = null;
			$partTyp = '';
			foreach( preg_split('/\r?\n/', $partHed) as $hdrLin ){
				$colonAt = strpos($hdrLin, ':');
				if( $colonAt===false ){
					continue;
				}
				$hdrName = trim(substr($hdrLin, 0, $colonAt));
				$hdrText = trim(substr($hdrLin, $colonAt+1));
				$redFlux['head'][$hdrName] = trim(strtok($hdrText, ';'));
				if( strcasecmp($hdrName, 'Content-Disposition')==0 ){
					if( preg_match('/\bname=(?:"([^"]*)"|([^;\s]+))/i', $hdrText, $nameVls) ){
						$fldName = $nameVls[2] ?? $nameVls[1];
					}
					if( preg_match('/\bfilename=(?:"([^"]*)"|([^;\s]+))/i', $hdrText, $fnmVals) ){
						$fileNme = $fnmVals[2] ?? $fnmVals[1];
					}
				}elseif( strcasecmp($hdrName, 'Content-Type')==0 ){
					$partTyp = $hdrText;
				}
			}
			if( $fldName===null || $fldName==='' ){
				continue;
			}

			if( $fileNme!==null ){
				$fileVls = $this->saveRqstUpFile( $fileNme, $partTyp, $partBdy );
				$brckPos = strpos($fldName, '[');
				if( $brckPos>0 ){
					// field[], field[key] or field[a][b] : one array per property, as PHP fills $_FILES
					$fldBase = substr($fldName, 0, $brckPos);
					foreach( $fileVls as $prpKey => $prpVal ){
						$this->nestRequestVal( $redFlux['file'][$fldBase][$prpKey], substr($fldName, $brckPos), $prpVal );
					}
				}else{
					$redFlux['file'][$fldName] = $fileVls;
				}
			}elseif( strpos($fldName, '[')!==false ){
				// A variable named like filters[search] or tags[] is nested, as PHP does for forms
				parse_str($fldName.'='.urlencode($partBdy), $nestVls);
				$redFlux['vars'] = array_merge_recursive($redFlux['vars'], $nestVls);
			}else{
				$redFlux['vars'][$fldName] = $partBdy;
			}
		}
		return $redFlux;
	}

	/**
	 * Stores a value under the keys of a bracket path such as [cover],
	 * [a][b] or [] (next free index), creating the levels on the way.
	 *
	 * @param ?Array $goalNodeList Array to store into, created when null.
	 * @param String $bracktString The bracket part of a field name.
	 * @param mixed  $storedAtLeaf Value to store at the end of the path.
	 */
	protected function nestRequestVal( ?Array &$goalNodeList, String $bracktString, Mixed $storedAtLeaf ): Void {
		preg_match_all('/\[([^\]]*)\]/', $bracktString, $keyList);
		$nodeRef = &$goalNodeList;
		foreach( $keyList[1] as $keyNme ){
			if( $keyNme==='' ){
				$nodeRef[] = null;
				$nodeRef = &$nodeRef[array_key_last($nodeRef)];
			}else{
				$nodeRef = &$nodeRef[$keyNme];
			}
		}
		$nodeRef = $storedAtLeaf;
	}

	/**
	 * Stores an uploaded file's content in a temporary file and describes
	 * it the way PHP describes a POST upload in $_FILES: 'name' is the file
	 * name without any folder, 'full_path' the name as the client gave it.
	 *
	 * @param String $reqUploadNme File name given by the client.
	 * @param String $declaredMime Content type given by the client.
	 * @param String $fileContents Content of the file.
	 * @return Array The file entry : 'name', 'full_path', 'type', 'tmp_name', 'error' and 'size'.
	 */
	protected function saveRqstUpFile( String $reqUploadNme, String $declaredMime, String $fileContents ): Array {
		$baseNme = preg_replace('#^.*[/\\\\]#', '', $reqUploadNme);
		$fileVls = Array('name'=>$baseNme, 'full_path'=>$reqUploadNme, 'type'=>$declaredMime, 'tmp_name'=>'', 'error'=>UPLOAD_ERR_NO_FILE, 'size'=>0);
		if( $reqUploadNme==='' ){
			return $fileVls;
		}
		$tmpFile = tempnam(sys_get_temp_dir(), 'nyu');
		$filSize = ($tmpFile!==false) ? file_put_contents($tmpFile, $fileContents) : false;
		if( $filSize===false ){
			$fileVls['error'] = UPLOAD_ERR_CANT_WRITE;
			return $fileVls;
		}
		if( empty($this->temprryFls) ){
			register_shutdown_function( Array($this, 'dropTmpUploads') );
		}
		$this->temprryFls[] = $tmpFile;
		$fileVls['tmp_name'] = $tmpFile;
		$fileVls['error'] = UPLOAD_ERR_OK;
		$fileVls['size'] = $filSize;
		return $fileVls;
	}

	/**
	 * Deletes the temporary upload files still present at the end of the
	 * request. A file the application moved elsewhere is left alone.
	 */
	public function dropTmpUploads(): Void {
		foreach( $this->temprryFls as $oneTmp ){
			if( file_exists($oneTmp) ){
				@unlink($oneTmp);
			}
		}
		$this->temprryFls = Array();
	}

	//function getHeadrs
	/**
	 * Retrieves all request headers, using apache_request_headers() when
	 * available or rebuilding them from the server registry's HTTP_* values
	 * for Nginx/other-SAPI compatibility.
	 *
	 * @return Array Associative array of header name => value.
	 */
	protected function retrieveHeadrs(): Array {
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
			$srvVars = $this->servRgstry->readLinesArray('http_');
			foreach ($srvVars as $srvKey => $srvVal){
				$headKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($srvKey, 5)))));
				if ( array_key_exists( $headKey, $xcepted ) ) {
                    $headKey = $xcepted[ $headKey ];
                }
				$headers[$headKey] = $srvVal;
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
	 * @param String $checkValText HTTP method to compare against (e.g. 'GET', 'POST').
	 * @return Bool True if the request used that method.
	 */
	public function usedMethodFlag( String $checkValText='' ): Bool {
		if( $this->rqstInMthd==$checkValText ){
			return true;
		}else{
			return false;
		}
	}
	/**
	 * Reads a value from the parsed request URI.
	 *
	 * @param String $uriValuesKey Key of the URI value to read.
	 * @return mixed The value, or null if not set.
	 */
	public function obtainUrlValue( String $uriValuesKey='' ): Mixed {
		if( isset( $this->requestUri[$uriValuesKey] ) ){
			return $this->requestUri[$uriValuesKey];
		}else{
			return NULL;
		}
	}
	/**
	 * Reads a single header value received with the request.
	 *
	 * @param String $nameOfHeader Name of the header to read.
	 * @return ?String The header value, or null if not present.
	 */
	public function retrieveHeader( String $nameOfHeader='' ): ?String {
		// Header names are case-insensitive
		foreach( $this->everyHeads as $hdrKey => $hdrVal ){
			if( strcasecmp($hdrKey, $nameOfHeader)==0 ){
				return $hdrVal;
			}
		}
		return NULL;
	}

	/**
	 * Reads a single user-submitted value (query/body) received with the
	 * request.
	 *
	 * @param String $userVrblName Name of the value to read.
	 * @return mixed The value, or null if not present.
	 */
	public function getUsrVariable( String $userVrblName ): Mixed {
		if( isset( $this->dataValues[$userVrblName] ) ){
			return $this->dataValues[$userVrblName];
		}else{
			return NULL;
		}
	}
	/**
	 * Tells whether a value was sent in the query string of the address,
	 * whatever else the body holds under the same name. What travels in
	 * an address is kept by the logs of every server on its way.
	 *
	 * @param String $userVrblName Name of the value, whatever its case.
	 * @return Bool True when the query string holds that name.
	 */
	public function isSentViaQuery( String $userVrblName ): Bool {
		return in_array( strtolower($userVrblName), $this->addrValues, true );
	}
	/**
	 * Reads a single cookie value received with the request.
	 *
	 * @param String $rqstCookName Name of the cookie to read.
	 * @return mixed The cookie value, or null if not present.
	 */
	public function retrieveCookie( String $rqstCookName ): Mixed {
		if( isset( $this->cookValues[$rqstCookName] ) ){
			return $this->cookValues[$rqstCookName];
		}else{
			return NULL;
		}
	}
	/**
	 * Reads a single uploaded file entry received with the request.
	 *
	 * @param String $fileArrayKey Key of the file entry to read.
	 * @return mixed The file data, or null if not present.
	 */
	public function retrieveInFile( String $fileArrayKey ): Mixed {
		if( isset( $this->uploadData[$fileArrayKey] ) ){
			return $this->uploadData[$fileArrayKey];
		}else{
			return NULL;
		}
	}
	/**
	 * Returns every uploaded file entry received with the request, by
	 * the name it was sent under.
	 *
	 * @return Array The file entries, described like the $_FILES ones.
	 */
	public function getUploadFiles(): Array {
		return $this->uploadData ?? Array();
	}
	/**
	 * Returns every user-submitted value (query, form, or JSON body)
	 * merged into a single array, for use by the input-filtering layer.
	 *
	 * @return Array All parsed request values.
	 */
	public function getAllVariable(): Array {
		return $this->dataValues ?? Array();
	}
	/**
	 * Returns server registry values, optionally filtered by key prefix,
	 * for use by the input-filtering layer.
	 *
	 * @param String $keysCriteria Prefix to filter keys by; returns all entries when empty.
	 * @return Array Matching server values.
	 */
	public function getServerValue( String $keysCriteria='' ): Array {
		return $this->servRgstry->readLinesArray($keysCriteria);
	}
	/**
	 * Returns the client registry: 'userAddr' (the client, seen through
	 * the trusted reverse proxies), 'peerAddr' (the connection itself),
	 * 'userHost', 'userSchm' (the scheme the client used, seen the same way),
	 * 'usrAgent', 'clSystem', 'clDevice' (dsktop, mobile, tablet, aiagnt,
	 * crawlr, script or shadow), 'language' and 'humanSys'. All but the
	 * addresses and the scheme come from what the client sent: hints, never proofs.
	 *
	 * @return ?ClientReg The client registry, or null when the request was built without one.
	 */
	public function retrieveClient(): ?ClientReg {
		return $this->clientRgst;
	}

}
