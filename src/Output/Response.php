<?php

namespace nyu\Output;

class Response{

	// Content type of the file extensions a declared route may serve; any other extension is not served
	const TYPELIST = Array(
		'html'=>'text/html; charset=utf-8',
		'htm'=>'text/html; charset=utf-8',
		'css'=>'text/css; charset=utf-8',
		'js'=>'text/javascript; charset=utf-8',
		'json'=>'application/json; charset=utf-8',
		'txt'=>'text/plain; charset=utf-8',
		'csv'=>'text/csv; charset=utf-8',
		'xml'=>'application/xml; charset=utf-8',
		'svg'=>'image/svg+xml',
		'png'=>'image/png',
		'jpg'=>'image/jpeg',
		'jpeg'=>'image/jpeg',
		'gif'=>'image/gif',
		'webp'=>'image/webp',
		'ico'=>'image/x-icon',
		'woff2'=>'font/woff2',
		'pdf'=>'application/pdf'
	);

	protected $dataFrmt;
	protected $tmpltNme;
	protected $headrArr;

	protected $finalStr;
	protected $dataOuts;

	protected $filePath;
	protected $fileType;
	protected $fileSize;
	protected $fileCode;
	protected $byteFrom;
	protected $byteUpto;

	protected $pageText;
	protected $pageCode;

	/**
	 * Configures the response from the 'rnder' preset section.
	 *
	 * @param Array $renderVals Render configuration with 'headrs' (response headers), 'tmplte' (template name), and 'return' (output format, e.g. 'json') keys.
	 */
	public function __construct( Array $renderVals=Array() ){
		$this->headrArr = $renderVals['headrs'] ?? Array();
		$this->tmpltNme = $renderVals['tmplte'] ?? 'plain';
		$this->dataFrmt = $renderVals['return'] ?? 'json';
	}

	/**
	 * Adds or overrides a single response header, on top of the
	 * preset-configured ones.
	 *
	 * @param String $headersKey Header name.
	 * @param String $headersVal Header value.
	 */
	public function addHeader( String $headersKey, String $headersVal ): Void {
		if(!is_array($this->headrArr)){
			$this->headrArr = Array();
		}
		$this->headrArr[$headersKey] = $headersVal;
	}

	/**
	 * Gives the headers of the response, as they stand: those of the
	 * preset and those added since. For a reply that is not sent over
	 * HTTP (see Shell\ActCaller).
	 *
	 * @return Array The header values, by name.
	 */
	public function readHeads(): Array {
		return is_array($this->headrArr) ? $this->headrArr : Array();
	}

	/**
	 * Encodes the given data as the configured output format (JSON, or
	 * PHP-serialized as a fallback). Data that cannot be encoded raises a
	 * warning and an error body is prepared instead.
	 *
	 * @param Array|Object|String|Int|Float|Bool $dataValues The data to encode.
	 * @return Bool False when the data could not be encoded.
	 */
	public function formatOut( Array|Object|String|Int|Float|Bool $dataValues = Array() ): Bool {
		if(strtoupper($this->dataFrmt)=='JSON'){
			// A binary value cannot be written as text: it is given as null
			$jsonStr = json_encode(\nyu\Data\Fluxd::dropFluxd($dataValues));
			if( $jsonStr===false ){
				trigger_error('Response could not be encoded as JSON: '.json_last_error_msg(), E_USER_WARNING);
				$this->dataOuts = json_encode( Array('error'=>'Response could not be encoded', 'code'=>500) );
				return false;
			}
			$this->dataOuts = $jsonStr;
		}else{
			$this->dataOuts = serialize($dataValues);
		}
		return true;
	}

	/**
	 * Loads the configured output template file and substitutes the
	 * formatted data into its `%data%` placeholder. An unknown template
	 * raises a warning and the 'plain' one is used instead.
	 */
	public function buildTmpl( ): Void {
		$tplFile = realpath(dirname(__FILE__)).DS.'Format'.DS.$this->tmpltNme.'.php';
		if(!file_exists($tplFile)){
			// Unknown template : the data is sent as is, and the mistake is reported
			trigger_error('Unknown output template "'.$this->tmpltNme.'", using "plain"', E_USER_WARNING);
			$this->tmpltNme = 'plain';
			$tplFile = realpath(dirname(__FILE__)).DS.'Format'.DS.'plain.php';
		}
		require($tplFile);
		$parsStr = str_replace('%data%',$this->dataOuts,$tplData);
		$this->finalStr = $parsStr;
	}
	
	/**
	 * Sends each configured response header, a default Content-Type when
	 * the preset sets none, and the HTTP status code when one is given.
	 *
	 * @param Int $statusCode HTTP status code to send; omitted for the default 200.
	 */
	public function buildHead( ?Int $statusCode=null ): Void {
		if(!is_array($this->headrArr)){
			$this->headrArr = Array();
		}
		// A registered file is only sent on success : an error keeps its data body
		if( $this->filePath!==null && $statusCode!==null && ($statusCode<200 || $statusCode>=300) ){
			$this->filePath = null;
		}
		// A registered page is only sent with the status it was registered for
		if( $this->pageText!==null && ($statusCode ?? 200)!=$this->pageCode ){
			$this->pageText = null;
		}
		if( $this->filePath!==null ){
			$statusCode = $this->fileCode;
			$this->fileHeads();
		}elseif( $this->pageText!==null ){
			$this->ownedType('text/html; charset=utf-8');
		}
		if($statusCode!=null){
			http_response_code($statusCode);
		}
		$hasType = in_array('content-type', array_map('strtolower', array_keys($this->headrArr)));
		$sameFle = ($this->filePath!==null && $this->fileCode==304);
		if( $sameFle || ($this->finalStr==='' && $this->filePath===null && $this->pageText===null) ){
			// Emptied response, or a file the client already has : no type is announced, not even PHP's own default
			ini_set('default_mimetype', '');
		}elseif( !$hasType ){
			// Default Content-Type, unless the preset sets its own
			$this->headrArr['Content-Type'] = $this->contnType();
		}
		foreach($this->headrArr as $hedKey => $hedVal){
			header($hedKey.': '.$hedVal);
		}
	}

	/**
	 * Sets the headers describing the registered file: its type, its
	 * length and, for a range request, the bytes being sent.
	 */
	protected function fileHeads(): Void {
		if( $this->fileCode==304 ){
			// The client keeps its copy : nothing describes a body
			$this->dropHeads( Array('content-type') );
			return;
		}
		$this->ownedType( strval($this->fileType) );
		$sentLen = ($this->fileSize>0) ? $this->byteUpto-$this->byteFrom+1 : 0;
		if( $this->fileCode==416 ){
			$sentLen = 0;
			$this->headrArr['Content-Range'] = 'bytes */'.$this->fileSize;
		}elseif( $this->fileCode==206 ){
			$this->headrArr['Content-Range'] = 'bytes '.$this->byteFrom.'-'.$this->byteUpto.'/'.$this->fileSize;
		}
		$this->headrArr['Accept-Ranges'] = 'bytes';
		$this->headrArr['Content-Length'] = strval($sentLen);
	}

	/**
	 * Sets the type of a reply that carries its own (a file, a page): it
	 * replaces any Content-Type set by the preset, and the client is told
	 * not to guess another one.
	 *
	 * @param String $mimeString Content-Type of the reply.
	 */
	protected function ownedType( String $mimeString ): Void {
		$this->dropHeads( Array('content-type') );
		$this->headrArr['Content-Type'] = $mimeString;
		$this->headrArr['X-Content-Type-Options'] = 'nosniff';
	}

	/**
	 * Removes response headers, whatever the case their names were set in.
	 *
	 * @param Array $headerList Names of the headers, in lower case.
	 */
	protected function dropHeads( Array $headerList ): Void {
		if( !is_array($this->headrArr) ){
			$this->headrArr = Array();
		}
		foreach( array_keys($this->headrArr) as $hedKey ){
			if( in_array(strtolower(strval($hedKey)), $headerList, true) ){
				unset($this->headrArr[$hedKey]);
			}
		}
	}

	/**
	 * Gives the content type a file is served with by a declared route,
	 * from its extension.
	 *
	 * @param String $fileExtnsn The extension, without its dot, whatever its case.
	 * @return ?String The Content-Type value, null when the extension is not served.
	 */
	public static function typeOfExt( String $fileExtnsn ): ?String {
		return self::TYPELIST[strtolower($fileExtnsn)] ?? null;
	}

	/**
	 * Resolves the Content-Type matching the configured template and
	 * output format.
	 *
	 * @return String The Content-Type header value.
	 */
	protected function contnType(): String {
		$tmplTyp = Array(
			'excel'=>'application/vnd.ms-excel',
			'mswrd'=>'application/msword',
			'optab'=>'application/vnd.oasis.opendocument.spreadsheet'
		);
		if( isset($tmplTyp[$this->tmpltNme]) ){
			return $tmplTyp[$this->tmpltNme];
		}
		if(strtoupper($this->dataFrmt)=='JSON'){
			return 'application/json; charset=utf-8';
		}
		return 'text/plain; charset=utf-8';
	}

	/**
	 * Empties the response: nothing is sent after the headers, and no
	 * Content-Type is announced. Used for a "no content" reply.
	 */
	public function clearBody(): Void {
		$this->finalStr = '';
		$this->filePath = null;
		$this->pageText = null;
	}

	/**
	 * Registers an HTML page to send in place of the data body, as
	 * text/html: the file is read and its %name% placeholders are filled
	 * with the given values. The page is only sent when the reply has the
	 * status it was registered for.
	 *
	 * @param String $sourcePath Path of the HTML file.
	 * @param Array  $pageValues Values of the placeholders, by name.
	 * @param Int    $statusCode Status the page is the reply of.
	 * @return Bool False when the file cannot be read; nothing is registered then.
	 */
	public function pageReply( String $sourcePath, Array $pageValues=Array(), Int $statusCode=200 ): Bool {
		if( !is_file($sourcePath) || !is_readable($sourcePath) ){
			return false;
		}
		$this->pageText = $this->fillPlace( strval(file_get_contents($sourcePath)), $pageValues );
		$this->pageCode = $statusCode;
		$this->filePath = null;
		return true;
	}

	/**
	 * Fills the %name% placeholders of a page. A value is always written
	 * as text, its HTML characters encoded; a placeholder without a value,
	 * or whose value is not a text or a number, is left as written.
	 *
	 * @param String $pageString The page, as read.
	 * @param Array  $pageValues Values of the placeholders, by name.
	 * @return String The filled page.
	 */
	protected function fillPlace( String $pageString, Array $pageValues ): String {
		return strval( preg_replace_callback('/%(\w+)%/', function( Array $matchedArr ) use ( $pageValues ): String {
			$fillVal = $pageValues[$matchedArr[1]] ?? null;
			if( !is_scalar($fillVal) ){
				return $matchedArr[0];
			}
			return htmlspecialchars( strval($fillVal), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}, $pageString) );
	}

	/**
	 * Registers a file to send in place of the data body, entirely or for
	 * the byte range the client asked for.
	 *
	 * @param String $sourcePath Path of the file to send.
	 * @param String $mimeString Content-Type of the file.
	 * @param String $rangeValue Value of the Range request header, empty when none was sent.
	 * @return Bool False when the file cannot be read; nothing is registered then.
	 */
	public function fileReply( String $sourcePath, String $mimeString, String $rangeValue='' ): Bool {
		if( !is_file($sourcePath) || !is_readable($sourcePath) ){
			return false;
		}
		$this->pageText = null;
		$this->filePath = $sourcePath;
		$this->fileType = $mimeString;
		$this->fileSize = filesize($sourcePath);
		$this->fileCode = $this->readRange($rangeValue, $this->fileSize);
		return true;
	}

	/**
	 * Lets the client keep the registered file and ask whether it changed
	 * before using it again: the file is given a version (ETag, from its
	 * date and size) and its date (Last-Modified), and the cache headers
	 * of the preset, which would forbid keeping it, are replaced. A client
	 * that shows it holds that version (If-None-Match, otherwise
	 * If-Modified-Since) is answered 304, without the file. A range asked
	 * for another version (If-Range) is ignored: the whole file is sent.
	 *
	 * @param Array $headersArr Request headers: 'If-None-Match', 'If-Modified-Since' and 'If-Range', whatever their case.
	 * @param Bool  $sharedFile True when anyone gets the file: a proxy may keep it too. False keeps it to the client's own cache.
	 */
	public function cacheFile( Array $headersArr, Bool $sharedFile=false ): Void {
		if( $this->filePath===null ){
			return;
		}
		$modTime = intval(filemtime($this->filePath));
		$etagStr = '"'.dechex($modTime).'-'.dechex(intval($this->fileSize)).'"';
		$modDate = gmdate('D, d M Y H:i:s', $modTime).' GMT';
		$askVals = array_change_key_case($headersArr, CASE_LOWER);

		$this->dropHeads( Array('cache-control', 'expires', 'pragma') );
		$this->headrArr['Cache-Control'] = ($sharedFile ? 'public' : 'private').', no-cache';
		$this->headrArr['ETag'] = $etagStr;
		$this->headrArr['Last-Modified'] = $modDate;

		$rngeChk = trim(strval($askVals['if-range'] ?? ''));
		if( $rngeChk!=='' && $rngeChk!==$etagStr && $rngeChk!==$modDate ){
			// The range was asked for another version of the file
			$this->fileCode = $this->readRange('', intval($this->fileSize));
		}
		$noneStr = trim(strval($askVals['if-none-match'] ?? ''));
		$sinceTm = strtotime(strval($askVals['if-modified-since'] ?? ''));
		if( $noneStr!=='' ){
			$isFresh = $this->matchETag($noneStr, $etagStr);
		}else{
			$isFresh = ($sinceTm!==false && $modTime<=$sinceTm);
		}
		if( $isFresh ){
			$this->fileCode = 304;
		}
	}

	/**
	 * Tells whether the versions a client holds include the one of the
	 * file. A version marked as weak (W/) is compared without its mark.
	 *
	 * @param String $clientTags Value of the If-None-Match header: versions separated by commas, or *.
	 * @param String $storedETag Version of the file.
	 * @return Bool True when the client holds that version.
	 */
	protected function matchETag( String $clientTags, String $storedETag ): Bool {
		if( trim($clientTags)==='*' ){
			return true;
		}
		foreach( explode(',', $clientTags) as $tagVal ){
			$tagVal = trim($tagVal);
			if( substr($tagVal, 0, 2)=='W/' ){
				$tagVal = substr($tagVal, 2);
			}
			if( $tagVal===$storedETag ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a Range header against the size of the file and stores the
	 * first and last byte to send. Only a single byte range is served: an
	 * empty, malformed or multiple range selects the whole file.
	 *
	 * @param String $rangeValue Value of the Range request header, empty when none was sent.
	 * @param Int    $totalBytes Size of the file, in bytes.
	 * @return Int The status to reply with: 200 (whole file), 206 (partial) or 416 (range outside the file).
	 */
	protected function readRange( String $rangeValue, Int $totalBytes ): Int {
		$this->byteFrom = 0;
		$this->byteUpto = max(0, $totalBytes-1);
		$matched = Array();
		if( !preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $rangeValue, $matched) ){
			return 200;
		}
		$fromStr = $matched[1];
		$uptoStr = $matched[2];
		if( $fromStr=='' && $uptoStr=='' ){
			return 200;
		}
		if( $fromStr=='' ){
			// Suffix range : the last N bytes
			$lastLen = intval($uptoStr);
			if( $lastLen==0 || $totalBytes==0 ){
				return 416;
			}
			$this->byteFrom = max(0, $totalBytes-$lastLen);
			return 206;
		}
		$fromPos = intval($fromStr);
		if( $fromPos>=$totalBytes ){
			return 416;
		}
		if( $uptoStr!='' ){
			$uptoPos = intval($uptoStr);
			if( $uptoPos<$fromPos ){
				// Reversed range : ignored, the whole file is sent
				return 200;
			}
			$this->byteUpto = min($uptoPos, $totalBytes-1);
		}
		$this->byteFrom = $fromPos;
		return 206;
	}

	/**
	 * Streams the selected bytes of the registered file to the client,
	 * piece by piece.
	 */
	protected function sendChunk(): Void {
		if( $this->fileCode==416 || $this->fileCode==304 || $this->fileSize==0 ){
			return;
		}
		$fileHdl = fopen($this->filePath, 'rb');
		if( $fileHdl===false ){
			return;
		}
		fseek($fileHdl, $this->byteFrom);
		$leftLen = $this->byteUpto-$this->byteFrom+1;
		while( $leftLen>0 && !feof($fileHdl) ){
			$buffer = fread($fileHdl, min(8192, $leftLen));
			if( $buffer===false || connection_aborted() ){
				break;
			}
			print($buffer);
			flush();
			$leftLen -= strlen($buffer);
		}
		fclose($fileHdl);
	}

	/**
	 * Sends the registered file or page when there is one, otherwise
	 * prints the final formatted response string to the client.
	 */
	public function sendOutpt( ): Void {
		if( $this->filePath!==null ){
			$this->sendChunk();
			return;
		}
		if( $this->pageText!==null ){
			print($this->pageText);
			return;
		}
		print($this->finalStr);
	}
}