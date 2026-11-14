<?php

namespace nyu\Data;

/**
 * A source read piece by piece: a file of the server, the body of the
 * request, or bytes already in memory such as a blob read from a table.
 * The source is never held whole, unless getWholeString() is asked for it.
 */
class Fluxd {

	protected $fileHandle;
	protected $sourcePath;
	protected $tmpFileArr = Array();

	/**
	 * Private: a source is opened through openLocalFluxd(), openInputFluxd() or
	 * openBytesFluxd().
	 *
	 * @param mixed   $streamHandle The open stream.
	 * @param ?String $sentFilePath Path of the local file behind it, null when there is none.
	 */
	private function __construct( Mixed $streamHandle, ?String $sentFilePath=null ){
		$this->fileHandle = $streamHandle;
		$this->sourcePath = $sentFilePath;
	}

	/**
	 * Releases the source when the object is no longer used.
	 */
	public function __destruct(){
		$this->closeTheSource();
	}

	/**
	 * Opens a file of the server. When a base folder is given, the file
	 * must be inside it: this is how a path that comes from a request is
	 * kept from reaching the rest of the disk.
	 *
	 * @param String $sentFilePath Path of the file.
	 * @param String $baseFldrText Folder the file must be in, '' for no restriction.
	 * @return Fluxd The opened source.
	 *
	 * @throws \RuntimeException When the file cannot be read, or is outside the base folder.
	 */
	public static function openLocalFluxd( String $sentFilePath, String $baseFldrText='' ): Fluxd {
		$rltnPath = realpath($sentFilePath);
		if( $rltnPath===false || !is_file($rltnPath) || !is_readable($rltnPath) ){
			throw new \RuntimeException('File not found: '.basename($sentFilePath));
		}
		if( $baseFldrText!='' ){
			$realPath = realpath($baseFldrText);
			if( $realPath===false || strpos($rltnPath, rtrim($realPath, '/\\').DIRECTORY_SEPARATOR)!==0 ){
				throw new \RuntimeException('File outside the allowed folder: '.basename($sentFilePath));
			}
		}
		$fileHndl = fopen($rltnPath, 'rb');
		if( $fileHndl===false ){
			throw new \RuntimeException('File not found: '.basename($sentFilePath));
		}
		return new Fluxd($fileHndl, $rltnPath);
	}

	/**
	 * Opens the body of the request. It is copied to a temporary store
	 * first, so that it can be measured and read more than once.
	 *
	 * @return Fluxd The opened source.
	 *
	 * @throws \RuntimeException When the body cannot be read.
	 */
	public static function openInputFluxd(): Fluxd {
		$bodyHndl = fopen('php://input', 'rb');
		$copyHndl = tmpfile();
		if( $bodyHndl===false || $copyHndl===false ){
			throw new \RuntimeException('The request body cannot be read');
		}
		stream_copy_to_stream($bodyHndl, $copyHndl);
		fclose($bodyHndl);
		rewind($copyHndl);
		return new Fluxd($copyHndl);
	}

	/**
	 * Wraps bytes already in memory, such as a blob read from a table.
	 *
	 * @param String $nakedContent The bytes.
	 * @return Fluxd The opened source.
	 *
	 * @throws \RuntimeException When the temporary store cannot be made.
	 */
	public static function openBytesFluxd( String $nakedContent ): Fluxd {
		$copyHndl = tmpfile();
		if( $copyHndl===false ){
			throw new \RuntimeException('The temporary store cannot be made');
		}
		fwrite($copyHndl, $nakedContent);
		rewind($copyHndl);
		return new Fluxd($copyHndl);
	}

	/**
	 * Gives the open stream, or refuses a source that was closed.
	 *
	 * @return mixed The open stream.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	protected function readOpenStream(): Mixed {
		if( !is_resource($this->fileHandle) ){
			throw new \RuntimeException('The source is closed');
		}
		return $this->fileHandle;
	}

	/**
	 * Reads the next piece of the source.
	 *
	 * @param Int $chunkBytesNb Greatest number of bytes to read.
	 * @return String|Bool The piece, or false at the end of the source.
	 *
	 * @throws \RuntimeException When the size is not positive, or the source is closed.
	 */
	public function getChunkString( Int $chunkBytesNb=8192 ): String|Bool {
		$inptHndl = $this->readOpenStream();
		if( $chunkBytesNb<1 ){
			throw new \RuntimeException('A piece holds at least one byte');
		}
		$chunkTxt = fread($inptHndl, $chunkBytesNb);
		return ($chunkTxt===false || $chunkTxt==='') ? false : $chunkTxt;
	}

	/**
	 * Reads the next line of the source, without its line ending.
	 *
	 * @return String|Bool The line, or false at the end of the source.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function getALineString(): String|Bool {
		$lineText = fgets($this->readOpenStream());
		return ($lineText===false) ? false : rtrim($lineText, "\r\n");
	}

	/**
	 * Reads the bytes between two positions, both included, the first
	 * byte being at position 0. The reading goes on after the range.
	 *
	 * @param Int $rangeStartNb Position of the first byte.
	 * @param Int $rangeUntilNb Position of the last byte.
	 * @return String The bytes, fewer than asked when the source ends before.
	 *
	 * @throws \RuntimeException When the range is not valid, or the source is closed.
	 */
	public function getRangeString( Int $rangeStartNb, Int $rangeUntilNb ): String {
		$inptHndl = $this->readOpenStream();
		if( $rangeStartNb<0 || $rangeUntilNb<$rangeStartNb ){
			throw new \RuntimeException('Invalid byte range: '.$rangeStartNb.'-'.$rangeUntilNb);
		}
		fseek($inptHndl, $rangeStartNb);
		$leftSize = $rangeUntilNb-$rangeStartNb+1;
		$rangeTxt = '';
		while( $leftSize>0 && !feof($inptHndl) ){
			$chunkTxt = fread($inptHndl, min(8192, $leftSize));
			if( $chunkTxt===false || $chunkTxt==='' ){
				break;
			}
			$rangeTxt .= $chunkTxt;
			$leftSize -= strlen($chunkTxt);
		}
		return $rangeTxt;
	}

	/**
	 * Reads the whole source, from its start. With a limit, a source
	 * larger than it is refused instead of being loaded.
	 *
	 * @param ?Int $ceilingBytes Greatest size accepted, null for no limit.
	 * @return String The content.
	 *
	 * @throws \RuntimeException When the source is larger than the limit, or is closed.
	 */
	public function getWholeString( ?Int $ceilingBytes=null ): String {
		$inptHndl = $this->readOpenStream();
		rewind($inptHndl);
		if( $ceilingBytes===null ){
			return strval(stream_get_contents($inptHndl));
		}
		// One byte more than the limit is enough to know the source is too large
		$fullText = strval(stream_get_contents($inptHndl, max(0, $ceilingBytes)+1));
		if( strlen($fullText)>$ceilingBytes ){
			throw new \RuntimeException('Content larger than the '.$ceilingBytes.' bytes allowed');
		}
		return $fullText;
	}

	/**
	 * Tells whether the end of the source is reached.
	 *
	 * @return Bool True when nothing is left to read.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function stopOfFluxFlag(): Bool {
		$inptHndl = $this->readOpenStream();
		$srceSize = $this->totalSizeCount();
		return ($srceSize!==null) ? ftell($inptHndl)>=$srceSize : feof($inptHndl);
	}

	/**
	 * Gives the size of the source.
	 *
	 * @return ?Int The size in bytes, null when it cannot be known.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function totalSizeCount(): ?Int {
		$strmInfo = fstat($this->readOpenStream());
		return isset($strmInfo['size']) ? intval($strmInfo['size']) : null;
	}

	/**
	 * Goes back to the start of the source.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function rewindToOrigin(): Void {
		rewind($this->readOpenStream());
	}

	/**
	 * Gives the source as a local file, for what can only read a file on
	 * disk. A source that already is a local file gives its own path.
	 * Otherwise its content is written to the path given, or to a
	 * temporary file that is deleted when the source is closed. The
	 * reading position is left where it was.
	 *
	 * @param ?String $saveFilePath Path of the file to write, null for the source's own file or a temporary one.
	 * @return String The path of the local file.
	 *
	 * @throws \RuntimeException When the file cannot be written, or the source is closed.
	 */
	public function setLocalString( ?String $saveFilePath=null ): String {
		$inptHndl = $this->readOpenStream();
		if( $saveFilePath===null && $this->sourcePath!==null ){
			return $this->sourcePath;
		}
		$savePath = $saveFilePath;
		if( $savePath===null ){
			$savePath = tempnam(sys_get_temp_dir(), 'nyu');
			if( $savePath===false ){
				throw new \RuntimeException('The temporary file cannot be made');
			}
			$this->tmpFileArr[] = $savePath;
		}
		$saveHndl = @fopen($savePath, 'wb');
		if( $saveHndl===false ){
			throw new \RuntimeException('File cannot be written: '.basename($savePath));
		}
		$crsorPos = ftell($inptHndl);
		rewind($inptHndl);
		stream_copy_to_stream($inptHndl, $saveHndl);
		fclose($saveHndl);
		fseek($inptHndl, $crsorPos===false ? 0 : $crsorPos);
		return $savePath;
	}

	/**
	 * Takes the sources out of a value that is to be written as text: a
	 * source cannot be, and is given as null, wherever it stands.
	 *
	 * @param mixed $valueAsGiven The value: a source, an array holding some at any depth, or anything else.
	 * @return mixed The value, with null in place of every source.
	 */
	public static function nullifySources( Mixed $valueAsGiven ): Mixed {
		if( $valueAsGiven instanceof Fluxd ){
			return null;
		}
		if( is_array($valueAsGiven) ){
			foreach($valueAsGiven as $valKey=>$valItm){
				$valueAsGiven[$valKey] = self::nullifySources($valItm);
			}
		}
		return $valueAsGiven;
	}

	/**
	 * Releases the source and deletes the temporary files it made.
	 * Closing a source twice does nothing.
	 */
	public function closeTheSource(): Void {
		if( is_resource($this->fileHandle) ){
			fclose($this->fileHandle);
		}
		$this->fileHandle = null;
		foreach( $this->tmpFileArr as $oneTmp ){
			if( is_file($oneTmp) ){
				@unlink($oneTmp);
			}
		}
		$this->tmpFileArr = Array();
	}

}
