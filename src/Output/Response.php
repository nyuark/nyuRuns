<?php

namespace nyu\Output;

/**
 * The reply to a request: its status, its headers and its body in the
 * format the preset asks for, and the static files and HTML pages a
 * declared route answers with.
 */
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

	// The formats a reply is written in (the 'format' key of a preset's 'rnder' block), and the file extension of the writer of each
	const FORMTLST = Array('json'=>'json', 'yaml'=>'yaml', 'xmlx'=>'xml', 'tabl'=>'csv', 'html'=>'html', 'flux'=>'');

	protected $dataFrmt;
	protected $setFormt;
	protected $fluxData;
	protected $headrArr;
	protected $orgnList = Array();

	protected $finalStr;

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
	 * @param Array $renderVals Render configuration with 'headrs' (response headers), 'format' (the format of the reply, one of FORMTLST, 'json' when not set) and, optionally, 'origns' (the origins other sites may call from, see setOrigin) keys.
	 */
	public function __construct( Array $renderVals=Array() ){
		$this->headrArr = $renderVals['headrs'] ?? Array();
		$this->dataFrmt = strtolower( strval($renderVals['format'] ?? 'json') );
		$this->setFormt = $this->dataFrmt;
		// An origin is a scheme, a host and a port : compared without case, nor a slash at its end
		foreach( (array)($renderVals['origns'] ?? Array()) as $orgVal ){
			$orgText = is_scalar($orgVal) ? strtolower( rtrim(trim(strval($orgVal)), '/') ) : '';
			if( $orgText!='' && $orgText!='*' ){
				$this->orgnList[] = $orgText;
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
	 * @param ?String $originHead Origin header of the request, null when it sent none.
	 */
	public function setOrigin( ?String $originHead ): Void {
		if( empty($this->orgnList) ){
			return;
		}
		$this->dropHeads( Array('access-control-allow-origin', 'vary') );
		$this->headrArr['Vary'] = 'Origin';
		$askOrgn = strtolower( rtrim(trim(strval($originHead)), '/') );
		if( $askOrgn!='' && in_array($askOrgn, $this->orgnList, true) ){
			$this->headrArr['Access-Control-Allow-Origin'] = $askOrgn;
		}
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
	 * Sets the format of this reply, in place of the one of the preset:
	 * an action exporting a table, for one.
	 *
	 * @param String $formatCode One of FORMTLST, whatever its case.
	 *
	 * @throws \RuntimeException When the format is not one of FORMTLST.
	 */
	public function useFormat( String $formatCode ): Void {
		$frmtKey = strtolower($formatCode);
		if( !array_key_exists($frmtKey, self::FORMTLST) ){
			throw new \RuntimeException('Unknown output format: '.$formatCode);
		}
		$this->dataFrmt = $frmtKey;
		$this->setFormt = $frmtKey;
	}

	/**
	 * Tells whether the warnings of a run can be sent along with the data:
	 * not in a table, nor in raw output, which have no place for them.
	 *
	 * @return Bool True when the format can carry them.
	 */
	public function takesWarn(): Bool {
		return !in_array($this->dataFrmt, Array('tabl', 'flux'), true);
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
	 * @param Array|Object|String|Int|Float|Bool $dataValues The data to write.
	 * @param Bool $errorReply Whether the data is the body of an error.
	 * @return Bool False when the data could not be written.
	 */
	public function formatOut( Array|Object|String|Int|Float|Bool $dataValues = Array(), Bool $errorReply=false ): Bool {
		$this->fluxData = null;
		$this->setFormt = ( $errorReply && !$this->takesWarn() ) ? 'json' : $this->dataFrmt;
		try{
			$this->finalStr = $this->writesOut($dataValues);
			return true;
		}catch( \Throwable $e ){
			trigger_error('Response could not be encoded as '.$this->setFormt.': '.$e->getMessage(), E_USER_WARNING);
			$errBody = Array('error'=>'Response could not be encoded', 'code'=>500);
			$this->fluxData = null;
			if( !$this->takesWarn() ){
				$this->setFormt = 'json';
			}
			try{
				$this->finalStr = $this->writesOut($errBody);
			}catch( \Throwable $e ){
				$this->setFormt = 'json';
				$this->finalStr = strval(json_encode($errBody));
			}
			return false;
		}
	}

	/**
	 * Writes the data in the format the reply is sent in.
	 *
	 * @param mixed $dataValues The data.
	 * @return String The body; empty for a Fluxd, kept to be sent piece by piece.
	 *
	 * @throws \RuntimeException When the format cannot hold the data.
	 */
	protected function writesOut( Mixed $dataValues ): String {
		if( $this->setFormt=='json' ){
			// A binary value cannot be written as text: it is given as null
			$jsonStr = json_encode(\nyu\Data\Fluxd::dropFluxd($dataValues));
			if( $jsonStr===false ){
				throw new \RuntimeException(json_last_error_msg());
			}
			return $jsonStr;
		}
		if( $this->setFormt=='flux' ){
			if( $dataValues instanceof \nyu\Data\Fluxd ){
				$this->fluxData = $dataValues;
				return '';
			}
			if( !is_string($dataValues) ){
				throw new \RuntimeException('raw output is a text or a Fluxd');
			}
			return $dataValues;
		}
		$extName = self::FORMTLST[$this->setFormt] ?? '';
		$wrtrObj = \nyu\Data\Writers\Index::forExtnsn($extName);
		if( $wrtrObj===null ){
			throw new \RuntimeException('no writer for the format '.$this->setFormt);
		}
		$dataVal = \nyu\Data\Fluxd::dropFluxd( is_object($dataValues) ? json_decode(strval(json_encode($dataValues)), true) : $dataValues );
		$cellLst = $this->recordsOf($dataVal);
		if( $this->setFormt=='tabl' ){
			if( $cellLst===null ){
				throw new \RuntimeException('a table is written from a list of records');
			}
			return $wrtrObj->writeData( Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(\nyu\Data\Shape::makeSheet('data', $cellLst))), \nyu\Data\Writers\Index::extnOptns($extName) );
		}
		if( $this->setFormt=='html' ){
			if( $cellLst!==null ){
				return $wrtrObj->writeData( Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(\nyu\Data\Shape::makeSheet('data', $cellLst))), Array('whole'=>true, 'heads'=>true) );
			}
			return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title></title>\n</head>\n<body>\n".$this->dlistHtml($dataVal)."</body>\n</html>\n";
		}
		return $wrtrObj->writeData( Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>$dataVal), \nyu\Data\Writers\Index::extnOptns($extName) );
	}

	/**
	 * Gives the cells of a table when the data is a list of records: each
	 * one a set of plain values, by name. The first row names the columns,
	 * in the order they first come; a record without a value has an empty
	 * cell.
	 *
	 * @param mixed $dataValues The data.
	 * @return ?Array The rows of the table, its column names first; null when the data is not a list of records.
	 */
	protected function recordsOf( Mixed $dataValues ): ?Array {
		if( !is_array($dataValues) || (!empty($dataValues) && array_keys($dataValues)!==range(0, count($dataValues)-1)) ){
			return null;
		}
		$clmnLst = Array();
		foreach( $dataValues as $rowVal ){
			if( !is_array($rowVal) || empty($rowVal) ){
				return null;
			}
			foreach( $rowVal as $colKey => $celVal ){
				if( $celVal!==null && !is_scalar($celVal) ){
					return null;
				}
				$clmnLst[strval($colKey)] = true;
			}
		}
		$colNmes = array_map('strval', array_keys($clmnLst));
		$cellLst = empty($colNmes) ? Array() : Array($colNmes);
		foreach( $dataValues as $rowVal ){
			$rowCels = Array();
			foreach( $colNmes as $colNme ){
				$celVal = $rowVal[$colNme] ?? '';
				$rowCels[] = is_bool($celVal) ? ($celVal ? 'true' : 'false') : $celVal;
			}
			$cellLst[] = $rowCels;
		}
		return $cellLst;
	}

	/**
	 * Writes any values as HTML: a set of values as a definition list,
	 * each name with its value, nested as deep as the values go; a plain
	 * value as a paragraph. Every text is HTML-encoded.
	 *
	 * @param mixed $valueToPut The values.
	 * @return String The HTML.
	 */
	protected function dlistHtml( Mixed $valueToPut ): String {
		if( !is_array($valueToPut) ){
			$plainTx = is_bool($valueToPut) ? ($valueToPut ? 'true' : 'false') : strval($valueToPut ?? '');
			return '<p>'.htmlspecialchars($plainTx, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</p>\n";
		}
		$htmText = "<dl>\n";
		foreach( $valueToPut as $valKey => $oneVal ){
			$htmText .= '<dt>'.htmlspecialchars(strval($valKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</dt>\n<dd>".$this->dlistHtml($oneVal)."</dd>\n";
		}
		return $htmText."</dl>\n";
	}
	
	/**
	 * Sends each configured response header, a default Content-Type when
	 * the preset sets none, and the HTTP status code when one is given.
	 * Every reply tells the client not to guess another type than the one
	 * announced (X-Content-Type-Options), whatever the preset writes.
	 *
	 * @param ?Int $statusCode HTTP status code to send; omitted for the default 200.
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
		if( $sameFle || ($this->finalStr==='' && $this->filePath===null && $this->pageText===null && $this->fluxData===null) ){
			// Emptied response, or a file the client already has : no type is announced, not even PHP's own default
			ini_set('default_mimetype', '');
		}elseif( !$hasType ){
			// Default Content-Type, unless the preset sets its own
			$this->headrArr['Content-Type'] = $this->contnType();
		}
		// The client is never left to guess another type than the one announced
		$this->dropHeads( Array('x-content-type-options') );
		$this->headrArr['X-Content-Type-Options'] = 'nosniff';
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
	 * replaces any Content-Type set by the preset.
	 *
	 * @param String $mimeString Content-Type of the reply.
	 */
	protected function ownedType( String $mimeString ): Void {
		$this->dropHeads( Array('content-type') );
		$this->headrArr['Content-Type'] = $mimeString;
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
	 * Resolves the Content-Type of the format the body was written in:
	 * the one its writer is sent with, application/octet-stream for raw
	 * output, whose action sets its own type.
	 *
	 * @return String The Content-Type header value.
	 */
	protected function contnType(): String {
		if( $this->setFormt=='json' ){
			return 'application/json; charset=utf-8';
		}
		$cntType = \nyu\Data\Writers\Index::cnTypeFor( self::FORMTLST[$this->setFormt] ?? '' );
		return ($cntType!='') ? $cntType : 'application/octet-stream';
	}

	/**
	 * Empties the response: nothing is sent after the headers, and no
	 * Content-Type is announced. Used for a "no content" reply.
	 */
	public function clearBody(): Void {
		$this->finalStr = '';
		$this->fluxData = null;
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
		if( $this->fluxData!==null ){
			$this->sendFluxd();
			return;
		}
		print($this->finalStr);
	}

	/**
	 * Sends raw output read from a Fluxd, piece by piece, then closes it.
	 */
	protected function sendFluxd(): Void {
		while( !$this->fluxData->endOfFlux() ){
			$pieceTx = $this->fluxData->readChunk(8192);
			if( $pieceTx===false || $pieceTx==='' || connection_aborted() ){
				break;
			}
			print($pieceTx);
			flush();
		}
		$this->fluxData->closeFlux();
		$this->fluxData = null;
	}
}