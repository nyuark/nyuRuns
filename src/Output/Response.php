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

	// The formats a reply is written in (the 'format' key of a preset's 'rnder' block), and the file extension of the writer of each
	const FORM_LIST = Array('json'=>'json', 'yaml'=>'yaml', 'xmlx'=>'xml', 'tabl'=>'csv', 'html'=>'html', 'flux'=>'');

	protected $dataFormat;
	protected $formatUsed;
	protected $fluxOutput;
	protected $headrArray;
	protected $originList = Array();

	protected $finlString;

	protected $fileToSend;
	protected $mimeOfFile;
	protected $fileLength;
	protected $fileStatus;
	protected $rangeStart;
	protected $rangeUntil;

	protected $pageOutput;
	protected $pageStatus;

	/**
	 * Configures the response from the 'rnder' preset section.
	 *
	 * @param Array $renderValues Render configuration with 'headrs' (response headers), 'format' (the format of the reply, one of FORMTLST, 'json' when not set) and, optionally, 'origns' (the origins other sites may call from, see setOrigin) keys.
	 */
	public function __construct( Array $renderValues=Array() ){
		$this->headrArray = $renderValues['headrs'] ?? Array();
		$this->dataFormat = strtolower( strval($renderValues['format'] ?? 'json') );
		$this->formatUsed = $this->dataFormat;
		// An origin is a scheme, a host and a port : compared without case, nor a slash at its end
		foreach( (array)($renderValues['origns'] ?? Array()) as $origin ){
			$orgnTxt = is_scalar($origin) ? strtolower( rtrim(trim(strval($origin)), '/') ) : '';
			if( $orgnTxt!='' && $orgnTxt!='*' ){
				$this->originList[] = $orgnTxt;
			}
		}
	}

	/**
	 * Applies the origins the preset allows (the 'origns' key of its
	 * 'rnder' block) to the request: an origin of the list is named in
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
		$askOrgn = strtolower( rtrim(trim(strval($originHeader)), '/') );
		if( $askOrgn!='' && in_array($askOrgn, $this->originList, true) ){
			$this->headrArray['Access-Control-Allow-Origin'] = $askOrgn;
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
	 * @param String $outputFormat One of FORMTLST, whatever its case.
	 *
	 * @throws \RuntimeException When the format is not one of FORMTLST.
	 */
	public function useReplyFormat( String $outputFormat ): Void {
		$frmtKey = strtolower($outputFormat);
		if( !array_key_exists($frmtKey, self::FORM_LIST) ){
			throw new \RuntimeException('Unknown output format: '.$outputFormat);
		}
		$this->dataFormat = $frmtKey;
		$this->formatUsed = $frmtKey;
	}

	/**
	 * Tells whether the warnings of a run can be sent along with the data:
	 * not in a table, nor in raw output, which have no place for them.
	 *
	 * @return Bool True when the format can carry them.
	 */
	public function takesReplyWarn(): Bool {
		return !in_array($this->dataFormat, Array('tabl', 'flux'), true);
	}

	/**
	 * Writes the data in the format of the reply (see FORMTLST): JSON,
	 * YAML or XML for any values; a table (CSV) for a list of records; an
	 * HTML page, the records in a table and any other values in nested
	 * definition lists; raw output (flux) for a text, or a Fluxd sent
	 * piece by piece. An error reply is written as JSON when the format is
	 * a table or raw output. Data the format cannot hold raises a warning,
	 * and an error body is prepared instead.
	 *
	 * @param Array|Object|String|Int|Float|Bool $outputToSend The data to write.
	 * @param Bool $errReplyFlag Whether the data is the body of an error.
	 * @return Bool False when the data could not be written.
	 */
	public function convertOutFlag( Array|Object|String|Int|Float|Bool $outputToSend = Array(), Bool $errReplyFlag=false ): Bool {
		$this->fluxOutput = null;
		$this->formatUsed = ( $errReplyFlag && !$this->takesReplyWarn() ) ? 'json' : $this->dataFormat;
		try{
			$this->finlString = $this->writesReplyOut($outputToSend);
			return true;
		}catch( \Throwable $e ){
			trigger_error('Response could not be encoded as '.$this->formatUsed.': '.$e->getMessage(), E_USER_WARNING);
			$errBody = Array('error'=>'Response could not be encoded', 'code'=>500);
			$this->fluxOutput = null;
			if( !$this->takesReplyWarn() ){
				$this->formatUsed = 'json';
			}
			try{
				$this->finlString = $this->writesReplyOut($errBody);
			}catch( \Throwable $e ){
				$this->formatUsed = 'json';
				$this->finlString = strval(json_encode($errBody));
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
	protected function writesReplyOut( Mixed $outputToSend ): String {
		if( $this->formatUsed=='json' ){
			// A binary value cannot be written as text: it is given as null
			$jsonTxt = json_encode(\nyu\Data\Fluxd::nullifySources($outputToSend));
			if( $jsonTxt===false ){
				throw new \RuntimeException(json_last_error_msg());
			}
			return $jsonTxt;
		}
		if( $this->formatUsed=='flux' ){
			if( $outputToSend instanceof \nyu\Data\Fluxd ){
				$this->fluxOutput = $outputToSend;
				return '';
			}
			if( !is_string($outputToSend) ){
				throw new \RuntimeException('raw output is a text or a Fluxd');
			}
			return $outputToSend;
		}
		$extName = self::FORM_LIST[$this->formatUsed] ?? '';
		$wrtrObj = \nyu\Data\Writers\Index::forExtnsParser($extName);
		if( $wrtrObj===null ){
			throw new \RuntimeException('no writer for the format '.$this->formatUsed);
		}
		$dataVal = \nyu\Data\Fluxd::nullifySources( is_object($outputToSend) ? json_decode(strval(json_encode($outputToSend)), true) : $outputToSend );
		$cellLst = $this->recordsOfArray($dataVal);
		if( $this->formatUsed=='tabl' ){
			if( $cellLst===null ){
				throw new \RuntimeException('a table is written from a list of records');
			}
			return $wrtrObj->renderDocument( Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(\nyu\Data\Shape::makeSheetArray('data', $cellLst))), \nyu\Data\Writers\Index::optionsOfExtns($extName) );
		}
		if( $this->formatUsed=='html' ){
			if( $cellLst!==null ){
				return $wrtrObj->renderDocument( Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(\nyu\Data\Shape::makeSheetArray('data', $cellLst))), Array('whole'=>true, 'heads'=>true) );
			}
			return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title></title>\n</head>\n<body>\n".$this->dlistReplyHtml($dataVal)."</body>\n</html>\n";
		}
		return $wrtrObj->renderDocument( Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>$dataVal), \nyu\Data\Writers\Index::optionsOfExtns($extName) );
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
	protected function recordsOfArray( Mixed $outputToSend ): ?Array {
		if( !is_array($outputToSend) || (!empty($outputToSend) && array_keys($outputToSend)!==range(0, count($outputToSend)-1)) ){
			return null;
		}
		$clmnLst = Array();
		foreach( $outputToSend as $rowVal ){
			if( !is_array($rowVal) || empty($rowVal) ){
				return null;
			}
			foreach( $rowVal as $column => $oneVal ){
				if( $oneVal!==null && !is_scalar($oneVal) ){
					return null;
				}
				$clmnLst[strval($column)] = true;
			}
		}
		$clmnNms = array_map('strval', array_keys($clmnLst));
		$cellLst = empty($clmnNms) ? Array() : Array($clmnNms);
		foreach( $outputToSend as $rowVal ){
			$rowClls = Array();
			foreach( $clmnNms as $header ){
				$oneVal = $rowVal[$header] ?? '';
				$rowClls[] = is_bool($oneVal) ? ($oneVal ? 'true' : 'false') : $oneVal;
			}
			$cellLst[] = $rowClls;
		}
		return $cellLst;
	}

	/**
	 * Writes any values as HTML: a set of values as a definition list,
	 * each name with its value, nested as deep as the values go; a plain
	 * value as a paragraph. Every text is HTML-encoded.
	 *
	 * @param mixed $valueToWrite The values.
	 * @return String The HTML.
	 */
	protected function dlistReplyHtml( Mixed $valueToWrite ): String {
		if( !is_array($valueToWrite) ){
			$plainTx = is_bool($valueToWrite) ? ($valueToWrite ? 'true' : 'false') : strval($valueToWrite ?? '');
			return '<p>'.htmlspecialchars($plainTx, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</p>\n";
		}
		$htmText = "<dl>\n";
		foreach( $valueToWrite as $valKey => $oneVal ){
			$htmText .= '<dt>'.htmlspecialchars(strval($valKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</dt>\n<dd>".$this->dlistReplyHtml($oneVal)."</dd>\n";
		}
		return $htmText."</dl>\n";
	}
	
	/**
	 * Sends each configured response header, a default Content-Type when
	 * the preset sets none, and the HTTP status code when one is given.
	 * Every reply tells the client not to guess another type than the one
	 * announced (X-Content-Type-Options), whatever the preset writes.
	 *
	 * @param ?Int $responseCode HTTP status code to send; omitted for the default 200.
	 */
	public function buildReplyHead( ?Int $responseCode=null ): Void {
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
			$this->fileReplyHeads();
		}elseif( $this->pageOutput!==null ){
			$this->ownedReplyType('text/html; charset=utf-8');
		}
		if($responseCode!=null){
			http_response_code($responseCode);
		}
		$hasType = in_array('content-type', array_map('strtolower', array_keys($this->headrArray)));
		$samFile = ($this->fileToSend!==null && $this->fileStatus==304);
		if( $samFile || ($this->finlString==='' && $this->fileToSend===null && $this->pageOutput===null && $this->fluxOutput===null) ){
			// Emptied response, or a file the client already has : no type is announced, not even PHP's own default
			ini_set('default_mimetype', '');
		}elseif( !$hasType ){
			// Default Content-Type, unless the preset sets its own
			$this->headrArray['Content-Type'] = $this->contnReplyType();
		}
		// The client is never left to guess another type than the one announced
		$this->dropReplyHeads( Array('x-content-type-options') );
		$this->headrArray['X-Content-Type-Options'] = 'nosniff';
		foreach($this->headrArray as $hdrKey => $hdrVal){
			header($hdrKey.': '.$hdrVal);
		}
	}

	/**
	 * Sets the headers describing the registered file: its type, its
	 * length and, for a range request, the bytes being sent.
	 */
	protected function fileReplyHeads(): Void {
		if( $this->fileStatus==304 ){
			// The client keeps its copy : nothing describes a body
			$this->dropReplyHeads( Array('content-type') );
			return;
		}
		$this->ownedReplyType( strval($this->mimeOfFile) );
		$sentLen = ($this->fileLength>0) ? $this->rangeUntil-$this->rangeStart+1 : 0;
		if( $this->fileStatus==416 ){
			$sentLen = 0;
			$this->headrArray['Content-Range'] = 'bytes */'.$this->fileLength;
		}elseif( $this->fileStatus==206 ){
			$this->headrArray['Content-Range'] = 'bytes '.$this->rangeStart.'-'.$this->rangeUntil.'/'.$this->fileLength;
		}
		$this->headrArray['Accept-Ranges'] = 'bytes';
		$this->headrArray['Content-Length'] = strval($sentLen);
	}

	/**
	 * Sets the type of a reply that carries its own (a file, a page): it
	 * replaces any Content-Type set by the preset.
	 *
	 * @param String $mimeTypeText Content-Type of the reply.
	 */
	protected function ownedReplyType( String $mimeTypeText ): Void {
		$this->dropReplyHeads( Array('content-type') );
		$this->headrArray['Content-Type'] = $mimeTypeText;
	}

	/**
	 * Removes response headers, whatever the case their names were set in.
	 *
	 * @param Array $replyHdrList Names of the headers, in lower case.
	 */
	protected function dropReplyHeads( Array $replyHdrList ): Void {
		if( !is_array($this->headrArray) ){
			$this->headrArray = Array();
		}
		foreach( array_keys($this->headrArray) as $hdrKey ){
			if( in_array(strtolower(strval($hdrKey)), $replyHdrList, true) ){
				unset($this->headrArray[$hdrKey]);
			}
		}
	}

	/**
	 * Gives the content type a file is served with by a declared route,
	 * from its extension.
	 *
	 * @param String $filExtension The extension, without its dot, whatever its case.
	 * @return ?String The Content-Type value, null when the extension is not served.
	 */
	public static function typOfExtension( String $filExtension ): ?String {
		return self::TYPE_LIST[strtolower($filExtension)] ?? null;
	}

	/**
	 * Resolves the Content-Type of the format the body was written in:
	 * the one its writer is sent with, application/octet-stream for raw
	 * output, whose action sets its own type.
	 *
	 * @return String The Content-Type header value.
	 */
	protected function contnReplyType(): String {
		if( $this->formatUsed=='json' ){
			return 'application/json; charset=utf-8';
		}
		$cntType = \nyu\Data\Writers\Index::contentTypeFor( self::FORM_LIST[$this->formatUsed] ?? '' );
		return ($cntType!='') ? $cntType : 'application/octet-stream';
	}

	/**
	 * Empties the response: nothing is sent after the headers, and no
	 * Content-Type is announced. Used for a "no content" reply.
	 */
	public function clearReplyBody(): Void {
		$this->finlString = '';
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
	 * @param Array  $pgeValuesArr Values of the placeholders, by name.
	 * @param Int    $responseCode Status the page is the reply of.
	 * @return Bool False when the file cannot be read; nothing is registered then.
	 */
	public function setPageToReply( String $sentFilePath, Array $pgeValuesArr=Array(), Int $responseCode=200 ): Bool {
		if( !is_file($sentFilePath) || !is_readable($sentFilePath) ){
			return false;
		}
		$this->pageOutput = $this->fillNamedSlots( strval(file_get_contents($sentFilePath)), $pgeValuesArr );
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
	 * @param Array  $pgeValuesArr Values of the placeholders, by name.
	 * @return String The filled page.
	 */
	protected function fillNamedSlots( String $pageTemplate, Array $pgeValuesArr ): String {
		return Template::fillNamedSlots( $pageTemplate, $pgeValuesArr, true );
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
		$this->fileStatus = $this->getRangeString($rangeValText, $this->fileLength);
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
		$mdlTime = intval(filemtime($this->fileToSend));
		$etagVal = '"'.dechex($mdlTime).'-'.dechex(intval($this->fileLength)).'"';
		$mdlDate = gmdate('D, d M Y H:i:s', $mdlTime).' GMT';
		$reqHdrs = array_change_key_case($headersArray, CASE_LOWER);

		$this->dropReplyHeads( Array('cache-control', 'expires', 'pragma') );
		$this->headrArray['Cache-Control'] = ($isPublicFile ? 'public' : 'private').', no-cache';
		$this->headrArray['ETag'] = $etagVal;
		$this->headrArray['Last-Modified'] = $mdlDate;

		$rngChck = trim(strval($reqHdrs['if-range'] ?? ''));
		if( $rngChck!=='' && $rngChck!==$etagVal && $rngChck!==$mdlDate ){
			// The range was asked for another version of the file
			$this->fileStatus = $this->getRangeString('', intval($this->fileLength));
		}
		$noneHdr = trim(strval($reqHdrs['if-none-match'] ?? ''));
		$sinceTm = strtotime(strval($reqHdrs['if-modified-since'] ?? ''));
		if( $noneHdr!=='' ){
			$isFresh = $this->compareETagFlg($noneHdr, $etagVal);
		}else{
			$isFresh = ($sinceTm!==false && $mdlTime<=$sinceTm);
		}
		if( $isFresh ){
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
	protected function compareETagFlg( String $clntTagsText, String $fileEtagText ): Bool {
		if( trim($clntTagsText)==='*' ){
			return true;
		}
		foreach( explode(',', $clntTagsText) as $tagVal ){
			$tagVal = trim($tagVal);
			if( substr($tagVal, 0, 2)=='W/' ){
				$tagVal = substr($tagVal, 2);
			}
			if( $tagVal===$fileEtagText ){
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
	 * @param Int    $totlBytesNum Size of the file, in bytes.
	 * @return Int The status to reply with: 200 (whole file), 206 (partial) or 416 (range outside the file).
	 */
	protected function getRangeString( String $rangeValText, Int $totlBytesNum ): Int {
		$this->rangeStart = 0;
		$this->rangeUntil = max(0, $totlBytesNum-1);
		$matched = Array();
		if( !preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $rangeValText, $matched) ){
			return 200;
		}
		$fromTxt = $matched[1];
		$uptoTxt = $matched[2];
		if( $fromTxt=='' && $uptoTxt=='' ){
			return 200;
		}
		if( $fromTxt=='' ){
			// Suffix range : the last N bytes
			$lastLen = intval($uptoTxt);
			if( $lastLen==0 || $totlBytesNum==0 ){
				return 416;
			}
			$this->rangeStart = max(0, $totlBytesNum-$lastLen);
			return 206;
		}
		$startAt = intval($fromTxt);
		if( $startAt>=$totlBytesNum ){
			return 416;
		}
		if( $uptoTxt!='' ){
			$uptPstn = intval($uptoTxt);
			if( $uptPstn<$startAt ){
				// Reversed range : ignored, the whole file is sent
				return 200;
			}
			$this->rangeUntil = min($uptPstn, $totlBytesNum-1);
		}
		$this->rangeStart = $startAt;
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
		$filHndl = fopen($this->fileToSend, 'rb');
		if( $filHndl===false ){
			return;
		}
		fseek($filHndl, $this->rangeStart);
		$lftSize = $this->rangeUntil-$this->rangeStart+1;
		while( $lftSize>0 && !feof($filHndl) ){
			$dataOut = fread($filHndl, min(8192, $lftSize));
			if( $dataOut===false || connection_aborted() ){
				break;
			}
			print($dataOut);
			flush();
			$lftSize -= strlen($dataOut);
		}
		fclose($filHndl);
	}

	/**
	 * Sends the registered file or page when there is one, otherwise
	 * prints the final formatted response string to the client.
	 */
	public function transmitOutput( ): Void {
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
		print($this->finlString);
	}

	/**
	 * Sends raw output read from a Fluxd, piece by piece, then closes it.
	 */
	protected function sendReplyFluxd(): Void {
		while( !$this->fluxOutput->stopOfFluxFlag() ){
			$pieceTx = $this->fluxOutput->getChunkString(8192);
			if( $pieceTx===false || $pieceTx==='' || connection_aborted() ){
				break;
			}
			print($pieceTx);
			flush();
		}
		$this->fluxOutput->closeTheSource();
		$this->fluxOutput = null;
	}
}