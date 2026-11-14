<?php

namespace nyu\Tools;

use nyu\Output\Response;


/**
 * The HTTP request as the client sent it: method, path, headers, values,
 * cookies and uploaded files. It also gives the server values and what is
 * known of the client.
 */
class Request{

	protected $serverRgst;
	protected $clientRgst;

	protected $rqstInMthd;
	protected $uriDetails;
	protected $givenQuery;
	protected $everyHeads;
	protected $nakedInput;

	protected $dataValues; // Registry of values
	protected $addrValues = Array(); // Names of the values sent in the query string, in lower case
	protected $cookValues; // Registry of readable cookie values (written through response)
	protected $uploadData; // Registry of files
	protected $tmpFileArr = Array(); // Temporary files written for uploads received in the raw body
	protected $maxSizeVal = 0; // Largest body read, in KB; 0 for no limit
	protected $pieceLimit = 0; // Most parts read from a multipart body; 0 for no limit
	protected $overLength = false; // Whether the body went over one of those limits

	/**
	 * Builds the request object: derives the method, URI, query string and
	 * headers from the server registry, then loads the request's content
	 * (body vars, cookies, files).
	 *
	 * @param ServerReg $serverValReg Registry of $_SERVER values.
	 * @param Response  $httpResponse The response object (reserved for cookie writes).
	 * @param ClientReg $clntRegistry Registry of client values (address, agent, system, device, language).
	 * @param Array     $limitsConfig The limits of a body: its largest 'size' in KB and the most 'part's of a multipart one, 0 or left out for no limit (see Setup\Loader::rqstConfigList).
	 *
	 * @todo Should read an encoding parameter and format each value to that encoding.
	 */
	 function __construct( ServerReg $serverValReg, Response $httpResponse, ClientReg $clntRegistry, Array $limitsConfig=Array() ){

		$this->serverRgst = $serverValReg;
		$this->clientRgst = $clntRegistry;
		$this->maxSizeVal = max( 0, intval($limitsConfig['size'] ?? 0) );
		$this->pieceLimit = max( 0, intval($limitsConfig['part'] ?? 0) );

		// Request method
		$this->rqstInMthd = $this->serverRgst->readValueByKey('request_method');

		// Query Values
		$reqstUri = $this->serverRgst->readValueByKey('request_uri');
		$quoteMrk = strPos($reqstUri, '?');
		$urlArgmn = Array();

		if( $quoteMrk!==false ){
			$pageAddr = subStr($reqstUri, 0, $quoteMrk);
			$queryTxt = subStr($reqstUri, $quoteMrk+1);
			parse_str($queryTxt, $urlArgmn);
		}else{
			$pageAddr = $reqstUri;
		}
		$urlArray = Array(
						'htScheme'=>$this->clientRgst->readValueByKey('userSchm'),
						'servName'=>$this->serverRgst->readValueByKey('server_name'),
						'fullPath'=>$reqstUri,
						'scriptAt'=>$this->serverRgst->readValueByKey('script_name'),
						'httpVers'=>$this->serverRgst->readValueByKey('server_protocol')
					);
		$urlArray['wholeUrl'] = 	$urlArray['htScheme'].'://'.$urlArray['servName'].$pageAddr;
		$urlArray['rqstLine'] = 	$this->rqstInMthd.' '.$urlArray['htScheme'].'://'.$urlArray['servName']
								.$urlArray['fullPath'].' '.$urlArray['scriptAt'].' '.$urlArray['httpVers'];
		$this->uriDetails = $urlArray;
		$this->givenQuery = $this->serverRgst->readValueByKey('query_string');

		// Get received headers
		$this->everyHeads = $this->fetchHeadsList();

		$this->getRqstContent( $urlArgmn );
	}

	// Get query variables, cookies and files
	/**
	 * Reads query variables, cookies and files into the request's internal
	 * registries, merging raw parsed body vars with $_GET/$_POST, then
	 * unsets the superglobals so nothing downstream reads them directly.
	 *
	 * @param Array $uriQueryList Query-string values parsed from the request URI.
	 */
	protected function getRqstContent( Array $uriQueryList = Array() ): Void {
		// Read Variables
		$this->nakedInput = $this->getRawDataList();
		$this->dataValues = Array();
		// What came in the address is remembered by name (see fromQuery)
		$this->addrValues = array_map( 'strtolower', array_map('strval', array_merge(array_keys($uriQueryList), array_keys($_GET ?? Array()))) );
		if(!empty($uriQueryList)){
			$this->dataValues = array_merge($this->dataValues, $uriQueryList);
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
		$rawArray = $this->readBodyCapped();

		// JSON body (POST/PUT/PATCH) : decode directly, no boundary framing
		$countCod = $this->retrieveHeader('Content-Type');
		if($countCod!==null && stripos($countCod, 'application/json')!==false){
			$jsonVals = json_decode($rawArray, true);
			return array(
				'head'=>Array('Content-Type'=>$countCod),
				'vars'=>is_array($jsonVals) ? $jsonVals : Array(),
				'file'=>Array(),
				'data'=>$rawArray
			);
		}

		// URL-encoded form body (PUT/PATCH) : PHP only parses these for POST
		if($countCod!==null && stripos($countCod, 'application/x-www-form-urlencoded')!==false){
			parse_str($rawArray, $formVals);
			return array(
				'head'=>Array('Content-Type'=>$countCod),
				'vars'=>$formVals,
				'file'=>Array(),
				'data'=>$rawArray
			);
		}

		// Only a multipart body is split by boundary
		if($countCod===null || stripos($countCod, 'multipart/')===false){
			return array(
				'head'=>Array(),
				'vars'=>Array(),
				'file'=>Array(),
				'data'=>$rawArray
			);
		}

		return $this->readMultiArray( $rawArray, $countCod );
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
		$maxBytes = $this->maxSizeVal * 1024;
		// What the request announces is checked first : nothing is then read
		$clmdSize = max( intval($this->retrieveHeader('Content-Length')), intval($this->serverRgst->readValueByKey('content_length')) );
		if( $clmdSize>$maxBytes ){
			$this->overLength = true;
			return '';
		}
		// Read piece by piece : PHP 8.2 reserves the whole length asked of file_get_contents() before reading
		$rawArray = '';
		$inptHndl = fopen('php://input', 'rb');
		if( $inptHndl!==false ){
			while( !feof($inptHndl) && strlen($rawArray)<=$maxBytes ){
				$pieceTxt = fread($inptHndl, min(8192, $maxBytes+1-strlen($rawArray)));
				if( $pieceTxt===false || $pieceTxt==='' ){
					break;
				}
				$rawArray .= $pieceTxt;
			}
			fclose($inptHndl);
		}
		if( strlen($rawArray)>$maxBytes ){
			$this->overLength = true;
			return '';
		}
		return $rawArray;
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
		$readFlux = array(
			'head'=>Array(),
			'vars'=>Array(),
			'file'=>Array(),
			'data'=>$nakedContent
		);
		if( !preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $sentMimeType, $bindVals) ){
			return $readFlux;
		}
		$boundary = '--'.($bindVals[1]!='' ? $bindVals[1] : $bindVals[2]);

		// The first piece is what precedes the first boundary
		$fluxPrts = array_slice( explode($boundary, $nakedContent), 1 );
		// The last piece closes the body : a body of more parts than allowed is not read
		if( $this->pieceLimit>0 && count($fluxPrts)-1>$this->pieceLimit ){
			$this->overLength = true;
			return $readFlux;
		}
		foreach( $fluxPrts as $partLn ){
			// The closing boundary is followed by "--"
			if( substr($partLn, 0, 2)=='--' ){
				break;
			}
			// A part is : line break, headers, empty line, content, line break
			$partLn = preg_replace('/^\r?\n/', '', $partLn);
			if( !preg_match('/\r?\n\r?\n/', $partLn, $seprVals, PREG_OFFSET_CAPTURE) ){
				continue;
			}
			$partHead = substr($partLn, 0, $seprVals[0][1]);
			$partBody = substr($partLn, $seprVals[0][1] + strlen($seprVals[0][0]));
			$partBody = preg_replace('/\r?\n\z/', '', $partBody);

			$fieldNme = null;
			$fileName = null;
			$partCode = '';
			foreach( preg_split('/\r?\n/', $partHead) as $hdrLin ){
				$colonIdx = strpos($hdrLin, ':');
				if( $colonIdx===false ){
					continue;
				}
				$hedrName = trim(substr($hdrLin, 0, $colonIdx));
				$hedrText = trim(substr($hdrLin, $colonIdx+1));
				$readFlux['head'][$hedrName] = trim(strtok($hedrText, ';'));
				if( strcasecmp($hedrName, 'Content-Disposition')==0 ){
					if( preg_match('/\bname=(?:"([^"]*)"|([^;\s]+))/i', $hedrText, $nameVals) ){
						$fieldNme = $nameVals[2] ?? $nameVals[1];
					}
					if( preg_match('/\bfilename=(?:"([^"]*)"|([^;\s]+))/i', $hedrText, $fnmeVals) ){
						$fileName = $fnmeVals[2] ?? $fnmeVals[1];
					}
				}elseif( strcasecmp($hedrName, 'Content-Type')==0 ){
					$partCode = $hedrText;
				}
			}
			if( $fieldNme===null || $fieldNme==='' ){
				continue;
			}

			if( $fileName!==null ){
				$fileVals = $this->saveRqstUpFile( $fileName, $partCode, $partBody );
				$brcktPos = strpos($fieldNme, '[');
				if( $brcktPos>0 ){
					// field[], field[key] or field[a][b] : one array per property, as PHP fills $_FILES
					$fieldBas = substr($fieldNme, 0, $brcktPos);
					foreach( $fileVals as $prpKey => $prpVal ){
						$this->nestRequestVal( $readFlux['file'][$fieldBas][$prpKey], substr($fieldNme, $brcktPos), $prpVal );
					}
				}else{
					$readFlux['file'][$fieldNme] = $fileVals;
				}
			}elseif( strpos($fieldNme, '[')!==false ){
				// A variable named like filters[search] or tags[] is nested, as PHP does for forms
				parse_str($fieldNme.'='.urlencode($partBody), $nestVals);
				$readFlux['vars'] = array_merge_recursive($readFlux['vars'], $nestVals);
			}else{
				$readFlux['vars'][$fieldNme] = $partBody;
			}
		}
		return $readFlux;
	}

	/**
	 * Stores a value under the keys of a bracket path such as [cover],
	 * [a][b] or [] (next free index), creating the levels on the way.
	 *
	 * @param ?Array $goalNodeList Array to store into, created when null.
	 * @param String $bracketsText The bracket part of a field name.
	 * @param mixed  $storedAtLeaf Value to store at the end of the path.
	 */
	protected function nestRequestVal( ?Array &$goalNodeList, String $bracketsText, Mixed $storedAtLeaf ): Void {
		preg_match_all('/\[([^\]]*)\]/', $bracketsText, $keysList);
		$nodeRefr = &$goalNodeList;
		foreach( $keysList[1] as $keyNme ){
			if( $keyNme==='' ){
				$nodeRefr[] = null;
				$nodeRefr = &$nodeRefr[array_key_last($nodeRefr)];
			}else{
				$nodeRefr = &$nodeRefr[$keyNme];
			}
		}
		$nodeRefr = $storedAtLeaf;
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
		$baseName = preg_replace('#^.*[/\\\\]#', '', $reqUploadNme);
		$fileVals = Array('name'=>$baseName, 'full_path'=>$reqUploadNme, 'type'=>$declaredMime, 'tmp_name'=>'', 'error'=>UPLOAD_ERR_NO_FILE, 'size'=>0);
		if( $reqUploadNme==='' ){
			return $fileVals;
		}
		$tempFile = tempnam(sys_get_temp_dir(), 'nyu');
		$fileSize = ($tempFile!==false) ? file_put_contents($tempFile, $fileContents) : false;
		if( $fileSize===false ){
			$fileVals['error'] = UPLOAD_ERR_CANT_WRITE;
			return $fileVals;
		}
		if( empty($this->tmpFileArr) ){
			register_shutdown_function( Array($this, 'dropTmpUploads') );
		}
		$this->tmpFileArr[] = $tempFile;
		$fileVals['tmp_name'] = $tempFile;
		$fileVals['error'] = UPLOAD_ERR_OK;
		$fileVals['size'] = $fileSize;
		return $fileVals;
	}

	/**
	 * Deletes the temporary upload files still present at the end of the
	 * request. A file the application moved elsewhere is left alone.
	 */
	public function dropTmpUploads(): Void {
		foreach( $this->tmpFileArr as $oneTmp ){
			if( file_exists($oneTmp) ){
				@unlink($oneTmp);
			}
		}
		$this->tmpFileArr = Array();
	}

	//function getHeadrs
	/**
	 * Retrieves all request headers, using apache_request_headers() when
	 * available or rebuilding them from the server registry's HTTP_* values
	 * for Nginx/other-SAPI compatibility.
	 *
	 * @return Array Associative array of header name => value.
	 */
	protected function fetchHeadsList(): Array {
		// NGinX compatibility
		if (!function_exists('apache_request_headers')){
			$hedrList = Array();
			$xcptList = Array(
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
			$srvrVars = $this->serverRgst->readLinesArray('http_');
			foreach ($srvrVars as $srvKey => $srvVal){
				$headClef = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($srvKey, 5)))));
				if ( array_key_exists( $headClef, $xcptList ) ) {
                    $headClef = $xcptList[ $headClef ];
                }
				$hedrList[$headClef] = $srvVal;
			}
			return $hedrList;
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
		if( isset( $this->uriDetails[$uriValuesKey] ) ){
			return $this->uriDetails[$uriValuesKey];
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
	 * @param String $variableName Name of the value to read.
	 * @return mixed The value, or null if not present.
	 */
	public function getUsrVariable( String $variableName ): Mixed {
		if( isset( $this->dataValues[$variableName] ) ){
			return $this->dataValues[$variableName];
		}else{
			return NULL;
		}
	}
	/**
	 * Tells whether a value was sent in the query string of the address,
	 * whatever else the body holds under the same name. What travels in
	 * an address is kept by the logs of every server on its way.
	 *
	 * @param String $variableName Name of the value, whatever its case.
	 * @return Bool True when the query string holds that name.
	 */
	public function isSentViaQuery( String $variableName ): Bool {
		return in_array( strtolower($variableName), $this->addrValues, true );
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
		return $this->serverRgst->readLinesArray($keysCriteria);
	}
	/**
	 * Returns the client registry: 'userAddr' (the client, seen through
	 * the trusted reverse proxies), 'peerAddr' (the connection itself),
	 * 'userHost', 'userSchm' (the scheme the client used, seen the same way),
	 * 'usrAgent', 'clSystem', 'clDevice' (computer, handheld, tabletPc, llmAgent,
	 * crawlers, scripted or shadowed), 'language' and 'humanSys'. All but the
	 * addresses and the scheme come from what the client sent: hints, never proofs.
	 *
	 * @return ?ClientReg The client registry, or null when the request was built without one.
	 */
	public function retrieveClient(): ?ClientReg {
		return $this->clientRgst;
	}

}
