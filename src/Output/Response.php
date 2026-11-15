<?php

namespace nyu\Output;

use nyu\Data\Mail\Template;

/**
 * The reply to a request: its status, its headers and its body in the
 * format the preset asks for, and the static files and HTML pages a
 * declared route answers with.
 */
class Response{

	// Content type of the file extensions a declared route may serve; any other extension is not served
	const TYPE_LIST = Array(
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

	// The formats a reply is written in (the 'outputAs' key of a preset's 'response' block), and the file extension of the writer of each
	const FORM_LIST = Array('jsonData'=>'json', 'yamlData'=>'yaml', 'xmlNodes'=>'xml', 'csvTable'=>'csv', 'htmlView'=>'html', 'rawBytes'=>'');

	protected $dataFormat;
	protected $formatUsed;
	protected $fluxOutput;
	protected $headrArray;
	protected $originList = Array();

	protected $outputText;

	protected $fileToSend;
	protected $mimeOfFile;
	protected $fileLength;
	protected $fileStatus;
	protected $rangeStart;
	protected $rangeUntil;

	protected $pageOutput;
	protected $pageStatus;

	/**
	 * Configures the response from the 'response' preset section.
	 *
	 * @param Array $renderValues Render configuration with 'headList' (response headers), 'outputAs' (the format of the reply, one of FORM_LIST whatever its case, 'jsonData' when not set) and, optionally, 'corsList' (the origins other sites may call from, see setOrigin) keys.
	 */
	public function __construct( Array $renderValues=Array() ){
		$this->headrArray = $renderValues['headList'] ?? Array();
		$frmtText = strval($renderValues['outputAs'] ?? 'jsonData');
		// The code of the format whatever its case, kept as FORM_LIST writes it
		$this->dataFormat = strval( array_values(preg_grep('/^'.preg_quote($frmtText, '/').'$/iD', array_keys(self::FORM_LIST)))[0] ?? $frmtText );
		$this->formatUsed = $this->dataFormat;
		// An origin is a scheme, a host and a port : compared without case, nor a slash at its end
		foreach( (array)($renderValues['corsList'] ?? Array()) as $oneAdr ){
			$origText = is_scalar($oneAdr) ? strtolower( rtrim(trim(strval($oneAdr)), '/') ) : '';
			if( $origText!='' && $origText!='*' ){
				$this->originList[] = $origText;
			}
		}
	}

	/**
	 * Applies the origins the preset allows (the 'corsList' key of its
	 * 'response' block) to the request: an origin of the list is named in
	 * Access-Control-Allow-Origin, any other gets no such header, and
	 * caches are told the reply differs by origin. Without a list, the
	 * headers of the preset are sent as they are written.
	 *
	 * @param ?String $originHeader Origin header of the request, null when it sent none.
	 */
	public function setReplyOrigin( ?String $originHeader ): Void {
		if( empty($this->originList) ){
			return;
		}
		$this->dropReplyHeads( Array('access-control-allow-origin', 'vary') );
		$this->headrArray['Vary'] = 'Origin';
		$askOrign = strtolower( rtrim(trim(strval($originHeader)), '/') );
		if( $askOrign!='' && in_array($askOrign, $this->originList, true) ){
			$this->headrArray['Access-Control-Allow-Origin'] = $askOrign;
		}
	}

	/**
	 * Adds or overrides a single response header, on top of the
	 * preset-configured ones.
	 *
	 * @param String $nameOfHeader Header name.
	 * @param String $headersValue Header value.
	 */
	public function addReplyHeader( String $nameOfHeader, String $headersValue ): Void {
		if(!is_array($this->headrArray)){
			$this->headrArray = Array();
		}
		$this->headrArray[$nameOfHeader] = $headersValue;
	}

	/**
	 * Gives the headers of the response, as they stand: those of the
	 * preset and those added since. For a reply that is not sent over
	 * HTTP (see Shell\ActCaller).
	 *
	 * @return Array The header values, by name.
	 */
	public function readHeadsArray(): Array {
		return is_array($this->headrArray) ? $this->headrArray : Array();
	}

	/**
	 * Sets the format of this reply, in place of the one of the preset:
	 * an action exporting a table, for one.
	 *
	 * @param String $outputFormat One of FORM_LIST, whatever its case.
	 *
	 * @throws \RuntimeException When the format is not one of FORM_LIST.
	 */
	public function setReplyFormat( String $outputFormat ): Void {
		$frmtClef = strval( array_values(preg_grep('/^'.preg_quote($outputFormat, '/').'$/iD', array_keys(self::FORM_LIST)))[0] ?? $outputFormat );
		if( !array_key_exists($frmtClef, self::FORM_LIST) ){
			throw new \RuntimeException('Unknown output format: '.$outputFormat);
		}
		$this->dataFormat = $frmtClef;
		$this->formatUsed = $frmtClef;
	}

	/**
	 * Tells whether the warnings of a run can be sent along with the data:
	 * not in a table, nor in raw output, which have no place for them.
	 *
	 * @return Bool True when the format can carry them.
	 */
	public function canAddWarnings(): Bool {
		return !in_array($this->dataFormat, Array('csvTable', 'rawBytes'), true);
	}

	/**
	 * Writes the data in the format of the reply (see FORM_LIST): JSON,
	 * YAML or XML for any values; a table (CSV) for a list of records; an
	 * HTML page, the records in a table and any other values in nested
	 * definition lists; raw output (rawBytes) for a text, or a Fluxd sent
	 * piece by piece. An error reply is written as JSON when the format is
	 * a table or raw output. Data the format cannot hold raises a warning,
	 * and an error body is prepared instead.
	 *
	 * @param Array|Object|String|Int|Float|Bool $outputToSend The data to write.
	 * @param Bool $errReplyFlag Whether the data is the body of an error.
	 * @return Bool False when the data could not be written.
	 */
	public function castToReplyFmt( Array|Object|String|Int|Float|Bool $outputToSend = Array(), Bool $errReplyFlag=false ): Bool {
		$this->fluxOutput = null;
		$this->formatUsed = ( $errReplyFlag && !$this->canAddWarnings() ) ? 'jsonData' : $this->dataFormat;
		try{
			$this->outputText = $this->renderReplyTxt($outputToSend);
			return true;
		}catch( \Throwable $e ){
			trigger_error('Response could not be encoded as '.$this->formatUsed.': '.$e->getMessage(), E_USER_WARNING);
			$errorRaw = Array('error'=>'Response could not be encoded', 'code'=>500);
			$this->fluxOutput = null;
			if( !$this->canAddWarnings() ){
				$this->formatUsed = 'jsonData';
			}
			try{
				$this->outputText = $this->renderReplyTxt($errorRaw);
			}catch( \Throwable $e ){
				$this->formatUsed = 'jsonData';
				$this->outputText = strval(json_encode($errorRaw));
			}
			return false;
		}
	}

	/**
	 * Writes the data in the format the reply is sent in.
	 *
	 * @param mixed $outputToSend The data.
	 * @return String The body; empty for a Fluxd, kept to be sent piece by piece.
	 *
	 * @throws \RuntimeException When the format cannot hold the data.
	 */
	protected function renderReplyTxt( Mixed $outputToSend ): String {
		if( $this->formatUsed=='jsonData' ){
			// A binary value cannot be written as text: it is given as null
			$jsonText = json_encode(\nyu\Data\Fluxd::dropAllSources($outputToSend));
			if( $jsonText===false ){
				throw new \RuntimeException(json_last_error_msg());
			}
			return $jsonText;
		}
		if( $this->formatUsed=='rawBytes' ){
			if( $outputToSend instanceof \nyu\Data\Fluxd ){
				$this->fluxOutput = $outputToSend;
				return '';
			}
			if( !is_string($outputToSend) ){
				throw new \RuntimeException('raw output is a text or a Fluxd');
			}
			return $outputToSend;
		}
		$extnName = self::FORM_LIST[$this->formatUsed] ?? '';
		$wrtrInst = \nyu\Data\Writers\Index::getExtnsWriter($extnName);
		if( $wrtrInst===null ){
			throw new \RuntimeException('no writer for the format '.$this->formatUsed);
		}
		$dataVals = \nyu\Data\Fluxd::dropAllSources( is_object($outputToSend) ? json_decode(strval(json_encode($outputToSend)), true) : $outputToSend );
		$cellList = $this->getRecordCells($dataVals);
		if( $this->formatUsed=='csvTable' ){
			if( $cellList===null ){
				throw new \RuntimeException('a table is written from a list of records');
			}
			return $wrtrInst->renderDocument( Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(\nyu\Data\Shape::makeSheetArray('data', $cellList))), \nyu\Data\Writers\Index::getOptsForExtn($extnName) );
		}
		if( $this->formatUsed=='htmlView' ){
			if( $cellList!==null ){
				return $wrtrInst->renderDocument( Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(\nyu\Data\Shape::makeSheetArray('data', $cellList))), Array('whole'=>true, 'heads'=>true) );
			}
			return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title></title>\n</head>\n<body>\n".$this->renderValsHtml($dataVals)."</body>\n</html>\n";
		}
		return $wrtrInst->renderDocument( Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>$dataVals), \nyu\Data\Writers\Index::getOptsForExtn($extnName) );
	}

	/**
	 * Gives the cells of a table when the data is a list of records: each
	 * one a set of plain values, by name. The first row names the columns,
	 * in the order they first come; a record without a value has an empty
	 * cell.
	 *
	 * @param mixed $outputToSend The data.
	 * @return ?Array The rows of the table, its column names first; null when the data is not a list of records.
	 */
	protected function getRecordCells( Mixed $outputToSend ): ?Array {
		if( !is_array($outputToSend) || (!empty($outputToSend) && array_keys($outputToSend)!==range(0, count($outputToSend)-1)) ){
			return null;
		}
		$clmnList = Array();
		foreach( $outputToSend as $oneRow ){
			if( !is_array($oneRow) || empty($oneRow) ){
				return null;
			}
			foreach( $oneRow as $oneCol => $oneVal ){
				if( $oneVal!==null && !is_scalar($oneVal) ){
					return null;
				}
				$clmnList[strval($oneCol)] = true;
			}
		}
		$clmnNmes = array_map('strval', array_keys($clmnList));
		$cellList = empty($clmnNmes) ? Array() : Array($clmnNmes);
		foreach( $outputToSend as $oneRow ){
			$rowCells = Array();
			foreach( $clmnNmes as $oneHed ){
				$oneVal = $oneRow[$oneHed] ?? '';
				$rowCells[] = is_bool($oneVal) ? ($oneVal ? 'true' : 'false') : $oneVal;
			}
			$cellList[] = $rowCells;
		}
		return $cellList;
	}

	/**
	 * Writes any values as HTML: a set of values as a definition list,
	 * each name with its value, nested as deep as the values go; a plain
	 * value as a paragraph. Every text is HTML-encoded.
	 *
	 * @param mixed $valueToWrite The values.
	 * @return String The HTML.
	 */
	protected function renderValsHtml( Mixed $valueToWrite ): String {
		if( !is_array($valueToWrite) ){
			$plainTxt = is_bool($valueToWrite) ? ($valueToWrite ? 'true' : 'false') : strval($valueToWrite ?? '');
			return '<p>'.htmlspecialchars($plainTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</p>\n";
		}
		$htmlText = "<dl>\n";
		foreach( $valueToWrite as $oneKey => $oneVal ){
			$htmlText .= '<dt>'.htmlspecialchars(strval($oneKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</dt>\n<dd>".$this->renderValsHtml($oneVal)."</dd>\n";
		}
		return $htmlText."</dl>\n";
	}
	
	/**
	 * Sends each configured response header, a default Content-Type when
	 * the preset sets none, and the HTTP status code when one is given.
	 * Every reply tells the client not to guess another type than the one
	 * announced (X-Content-Type-Options), whatever the preset writes.
	 *
	 * @param ?Int $responseCode HTTP status code to send; omitted for the default 200.
	 */
	public function writeReplyHead( ?Int $responseCode=null ): Void {
		if(!is_array($this->headrArray)){
			$this->headrArray = Array();
		}
		// A registered file is only sent on success : an error keeps its data body
		if( $this->fileToSend!==null && $responseCode!==null && ($responseCode<200 || $responseCode>=300) ){
			$this->fileToSend = null;
		}
		// A registered page is only sent with the status it was registered for
		if( $this->pageOutput!==null && ($responseCode ?? 200)!=$this->pageStatus ){
			$this->pageOutput = null;
		}
		if( $this->fileToSend!==null ){
			$responseCode = $this->fileStatus;
			$this->setFileHeaders();
		}elseif( $this->pageOutput!==null ){
			$this->setOwnMimeType('text/html; charset=utf-8');
		}
		if($responseCode!=null){
			http_response_code($responseCode);
		}
		$hasMimes = in_array('content-type', array_map('strtolower', array_keys($this->headrArray)));
		$sameFile = ($this->fileToSend!==null && $this->fileStatus==304);
		if( $sameFile || ($this->outputText==='' && $this->fileToSend===null && $this->pageOutput===null && $this->fluxOutput===null) ){
			// Emptied response, or a file the client already has : no type is announced, not even PHP's own default
			ini_set('default_mimetype', '');
		}elseif( !$hasMimes ){
			// Default Content-Type, unless the preset sets its own
			$this->headrArray['Content-Type'] = $this->findMimeOfBody();
		}
		// The client is never left to guess another type than the one announced
		$this->dropReplyHeads( Array('x-content-type-options') );
		$this->headrArray['X-Content-Type-Options'] = 'nosniff';
		foreach($this->headrArray as $oneKey => $oneVal){
			header($oneKey.': '.$oneVal);
		}
	}

	/**
	 * Sets the headers describing the registered file: its type, its
	 * length and, for a range request, the bytes being sent.
	 */
	protected function setFileHeaders(): Void {
		if( $this->fileStatus==304 ){
			// The client keeps its copy : nothing describes a body
			$this->dropReplyHeads( Array('content-type') );
			return;
		}
		$this->setOwnMimeType( strval($this->mimeOfFile) );
		$sentSize = ($this->fileLength>0) ? $this->rangeUntil-$this->rangeStart+1 : 0;
		if( $this->fileStatus==416 ){
			$sentSize = 0;
			$this->headrArray['Content-Range'] = 'bytes */'.$this->fileLength;
		}elseif( $this->fileStatus==206 ){
			$this->headrArray['Content-Range'] = 'bytes '.$this->rangeStart.'-'.$this->rangeUntil.'/'.$this->fileLength;
		}
		$this->headrArray['Accept-Ranges'] = 'bytes';
		$this->headrArray['Content-Length'] = strval($sentSize);
	}

	/**
	 * Sets the type of a reply that carries its own (a file, a page): it
	 * replaces any Content-Type set by the preset.
	 *
	 * @param String $mimeTypeText Content-Type of the reply.
	 */
	protected function setOwnMimeType( String $mimeTypeText ): Void {
		$this->dropReplyHeads( Array('content-type') );
		$this->headrArray['Content-Type'] = $mimeTypeText;
	}

	/**
	 * Removes response headers, whatever the case their names were set in.
	 *
	 * @param Array $replyHdrsLst Names of the headers, in lower case.
	 */
	protected function dropReplyHeads( Array $replyHdrsLst ): Void {
		if( !is_array($this->headrArray) ){
			$this->headrArray = Array();
		}
		foreach( array_keys($this->headrArray) as $oneKey ){
			if( in_array(strtolower(strval($oneKey)), $replyHdrsLst, true) ){
				unset($this->headrArray[$oneKey]);
			}
		}
	}

	/**
	 * Gives the content type a file is served with by a declared route,
	 * from its extension.
	 *
	 * @param String $extensionTxt The extension, without its dot, whatever its case.
	 * @return ?String The Content-Type value, null when the extension is not served.
	 */
	public static function getMimeOfExtns( String $extensionTxt ): ?String {
		return self::TYPE_LIST[strtolower($extensionTxt)] ?? null;
	}

	/**
	 * Resolves the Content-Type of the format the body was written in:
	 * the one its writer is sent with, application/octet-stream for raw
	 * output, whose action sets its own type.
	 *
	 * @return String The Content-Type header value.
	 */
	protected function findMimeOfBody(): String {
		if( $this->formatUsed=='jsonData' ){
			return 'application/json; charset=utf-8';
		}
		$countCod = \nyu\Data\Writers\Index::getMimeForExtn( self::FORM_LIST[$this->formatUsed] ?? '' );
		return ($countCod!='') ? $countCod : 'application/octet-stream';
	}

	/**
	 * Empties the response: nothing is sent after the headers, and no
	 * Content-Type is announced. Used for a "no content" reply.
	 */
	public function clearReplyBody(): Void {
		$this->outputText = '';
		$this->fluxOutput = null;
		$this->fileToSend = null;
		$this->pageOutput = null;
	}

	/**
	 * Registers an HTML page to send in place of the data body, as
	 * text/html: the file is read and its %name% placeholders are filled
	 * with the given values. The page is only sent when the reply has the
	 * status it was registered for.
	 *
	 * @param String $sentFilePath Path of the HTML file.
	 * @param Array  $pageValsList Values of the placeholders, by name.
	 * @param Int    $responseCode Status the page is the reply of.
	 * @return Bool False when the file cannot be read; nothing is registered then.
	 */
	public function setPageToReply( String $sentFilePath, Array $pageValsList=Array(), Int $responseCode=200 ): Bool {
		if( !is_file($sentFilePath) || !is_readable($sentFilePath) ){
			return false;
		}
		$this->pageOutput = $this->fillNamedSlots( strval(file_get_contents($sentFilePath)), $pageValsList );
		$this->pageStatus = $responseCode;
		$this->fileToSend = null;
		return true;
	}

	/**
	 * Fills the %name% placeholders of a page. A value is always written
	 * as text, its HTML characters encoded; a placeholder without a value,
	 * or whose value is not a text or a number, is left as written.
	 *
	 * @param String $pageTemplate The page, as read.
	 * @param Array  $pageValsList Values of the placeholders, by name.
	 * @return String The filled page.
	 */
	protected function fillNamedSlots( String $pageTemplate, Array $pageValsList ): String {
		return Template::fillNamedSlots( $pageTemplate, $pageValsList, true );
	}

	/**
	 * Registers a file to send in place of the data body, entirely or for
	 * the byte range the client asked for.
	 *
	 * @param String $sentFilePath Path of the file to send.
	 * @param String $mimeTypeText Content-Type of the file.
	 * @param String $rangeValText Value of the Range request header, empty when none was sent.
	 * @return Bool False when the file cannot be read; nothing is registered then.
	 */
	public function setFileToReply( String $sentFilePath, String $mimeTypeText, String $rangeValText='' ): Bool {
		if( !is_file($sentFilePath) || !is_readable($sentFilePath) ){
			return false;
		}
		$this->pageOutput = null;
		$this->fileToSend = $sentFilePath;
		$this->mimeOfFile = $mimeTypeText;
		$this->fileLength = filesize($sentFilePath);
		$this->fileStatus = $this->parseRangeHead($rangeValText, $this->fileLength);
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
	 * @param Array $headersArray Request headers: 'If-None-Match', 'If-Modified-Since' and 'If-Range', whatever their case.
	 * @param Bool  $isPublicFile True when anyone gets the file: a proxy may keep it too. False keeps it to the client's own cache.
	 */
	public function cacheReplyFile( Array $headersArray, Bool $isPublicFile=false ): Void {
		if( $this->fileToSend===null ){
			return;
		}
		$modStamp = intval(filemtime($this->fileToSend));
		$nttyVals = '"'.dechex($modStamp).'-'.dechex(intval($this->fileLength)).'"';
		$modelDte = gmdate('D, d M Y H:i:s', $modStamp).' GMT';
		$reqtHdrs = array_change_key_case($headersArray, CASE_LOWER);

		$this->dropReplyHeads( Array('cache-control', 'expires', 'pragma') );
		$this->headrArray['Cache-Control'] = ($isPublicFile ? 'public' : 'private').', no-cache';
		$this->headrArray['ETag'] = $nttyVals;
		$this->headrArray['Last-Modified'] = $modelDte;

		$rangeChk = trim(strval($reqtHdrs['if-range'] ?? ''));
		if( $rangeChk!=='' && $rangeChk!==$nttyVals && $rangeChk!==$modelDte ){
			// The range was asked for another version of the file
			$this->fileStatus = $this->parseRangeHead('', intval($this->fileLength));
		}
		$noneHedr = trim(strval($reqtHdrs['if-none-match'] ?? ''));
		$sinceTme = strtotime(strval($reqtHdrs['if-modified-since'] ?? ''));
		if( $noneHedr!=='' ){
			$isCached = $this->isFileEtagHeld($noneHedr, $nttyVals);
		}else{
			$isCached = ($sinceTme!==false && $modStamp<=$sinceTme);
		}
		if( $isCached ){
			$this->fileStatus = 304;
		}
	}

	/**
	 * Tells whether the versions a client holds include the one of the
	 * file. A version marked as weak (W/) is compared without its mark.
	 *
	 * @param String $clntTagsText Value of the If-None-Match header: versions separated by commas, or *.
	 * @param String $fileEtagText Version of the file.
	 * @return Bool True when the client holds that version.
	 */
	protected function isFileEtagHeld( String $clntTagsText, String $fileEtagText ): Bool {
		if( trim($clntTagsText)==='*' ){
			return true;
		}
		foreach( explode(',', $clntTagsText) as $oneVal ){
			$oneVal = trim($oneVal);
			if( substr($oneVal, 0, 2)=='W/' ){
				$oneVal = substr($oneVal, 2);
			}
			if( $oneVal===$fileEtagText ){
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
	 * @param String $rangeValText Value of the Range request header, empty when none was sent.
	 * @param Int    $totalBytNumb Size of the file, in bytes.
	 * @return Int The status to reply with: 200 (whole file), 206 (partial) or 416 (range outside the file).
	 */
	protected function parseRangeHead( String $rangeValText, Int $totalBytNumb ): Int {
		$this->rangeStart = 0;
		$this->rangeUntil = max(0, $totalBytNumb-1);
		$matxList = Array();
		if( !preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $rangeValText, $matxList) ){
			return 200;
		}
		$fromText = $matxList[1];
		$uptoText = $matxList[2];
		if( $fromText=='' && $uptoText=='' ){
			return 200;
		}
		if( $fromText=='' ){
			// Suffix range : the last N bytes
			$lastSize = intval($uptoText);
			if( $lastSize==0 || $totalBytNumb==0 ){
				return 416;
			}
			$this->rangeStart = max(0, $totalBytNumb-$lastSize);
			return 206;
		}
		$startIdx = intval($fromText);
		if( $startIdx>=$totalBytNumb ){
			return 416;
		}
		if( $uptoText!='' ){
			$finalPos = intval($uptoText);
			if( $finalPos<$startIdx ){
				// Reversed range : ignored, the whole file is sent
				return 200;
			}
			$this->rangeUntil = min($finalPos, $totalBytNumb-1);
		}
		$this->rangeStart = $startIdx;
		return 206;
	}

	/**
	 * Streams the selected bytes of the registered file to the client,
	 * piece by piece.
	 */
	protected function sendReplyChunk(): Void {
		if( $this->fileStatus==416 || $this->fileStatus==304 || $this->fileLength==0 ){
			return;
		}
		$fileHndl = fopen($this->fileToSend, 'rb');
		if( $fileHndl===false ){
			return;
		}
		fseek($fileHndl, $this->rangeStart);
		$leftSize = $this->rangeUntil-$this->rangeStart+1;
		while( $leftSize>0 && !feof($fileHndl) ){
			$dataChnk = fread($fileHndl, min(8192, $leftSize));
			if( $dataChnk===false || connection_aborted() ){
				break;
			}
			print($dataChnk);
			flush();
			$leftSize -= strlen($dataChnk);
		}
		fclose($fileHndl);
	}

	/**
	 * Sends the registered file or page when there is one, otherwise
	 * prints the final formatted response string to the client.
	 */
	public function writeReplyBody( ): Void {
		if( $this->fileToSend!==null ){
			$this->sendReplyChunk();
			return;
		}
		if( $this->pageOutput!==null ){
			print($this->pageOutput);
			return;
		}
		if( $this->fluxOutput!==null ){
			$this->sendReplyFluxd();
			return;
		}
		print($this->outputText);
	}

	/**
	 * Sends raw output read from a Fluxd, piece by piece, then closes it.
	 */
	protected function sendReplyFluxd(): Void {
		while( !$this->fluxOutput->isFluxFinished() ){
			$pieceTxt = $this->fluxOutput->getChunkString(8192);
			if( $pieceTxt===false || $pieceTxt==='' || connection_aborted() ){
				break;
			}
			print($pieceTxt);
			flush();
		}
		$this->fluxOutput->shutFluxSource();
		$this->fluxOutput = null;
	}
}