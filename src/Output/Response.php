<?php

namespace nyu\Output;

class Response{

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
		if( $this->filePath!==null ){
			$statusCode = $this->fileCode;
			$this->fileHeads();
		}
		if($statusCode!=null){
			http_response_code($statusCode);
		}
		$hasType = in_array('content-type', array_map('strtolower', array_keys($this->headrArr)));
		if( $this->finalStr==='' && $this->filePath===null ){
			// Emptied response : no type is announced, not even PHP's own default
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
		// The file's own type replaces any Content-Type set by the preset
		foreach( array_keys($this->headrArr) as $hedKey ){
			if( strtolower($hedKey)=='content-type' ){
				unset($this->headrArr[$hedKey]);
			}
		}
		$sentLen = ($this->fileSize>0) ? $this->byteUpto-$this->byteFrom+1 : 0;
		if( $this->fileCode==416 ){
			$sentLen = 0;
			$this->headrArr['Content-Range'] = 'bytes */'.$this->fileSize;
		}elseif( $this->fileCode==206 ){
			$this->headrArr['Content-Range'] = 'bytes '.$this->byteFrom.'-'.$this->byteUpto.'/'.$this->fileSize;
		}
		$this->headrArr['Content-Type'] = $this->fileType;
		$this->headrArr['Accept-Ranges'] = 'bytes';
		$this->headrArr['Content-Length'] = strval($sentLen);
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
		$this->filePath = $sourcePath;
		$this->fileType = $mimeString;
		$this->fileSize = filesize($sourcePath);
		$this->fileCode = $this->readRange($rangeValue, $this->fileSize);
		return true;
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
		if( $this->fileCode==416 || $this->fileSize==0 ){
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
	 * Sends the registered file when there is one, otherwise prints the
	 * final formatted response string to the client.
	 */
	public function sendOutpt( ): Void {
		if( $this->filePath!==null ){
			$this->sendChunk();
			return;
		}
		print($this->finalStr);
	}
}