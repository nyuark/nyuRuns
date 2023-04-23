<?php

namespace nyu\Data;

// A source read piece by piece: a file of the server, the body of the
// request, or bytes already in memory such as a blob read from a table.
// The source is never held whole, unless readWhole() is asked for it.
class Fluxd {

	protected $strmHndl;
	protected $srcePath;
	protected $tmpFiles = Array();

	/**
	 * Private: a source is opened through openLocal(), openInput() or
	 * openBytes().
	 *
	 * @param mixed   $streamHndl The open stream.
	 * @param ?String $sourcePath Path of the local file behind it, null when there is none.
	 */
	private function __construct( Mixed $streamHndl, ?String $sourcePath=null ){
		$this->strmHndl = $streamHndl;
		$this->srcePath = $sourcePath;
	}

	/**
	 * Releases the source when the object is no longer used.
	 */
	public function __destruct(){
		$this->closeFlux();
	}

	/**
	 * Opens a file of the server. When a base folder is given, the file
	 * must be inside it: this is how a path that comes from a request is
	 * kept from reaching the rest of the disk.
	 *
	 * @param String $sourcePath Path of the file.
	 * @param String $baseFolder Folder the file must be in, '' for no restriction.
	 * @return Fluxd The opened source.
	 *
	 * @throws \RuntimeException When the file cannot be read, or is outside the base folder.
	 */
	public static function openLocal( String $sourcePath, String $baseFolder='' ): Fluxd {
		$realPth = realpath($sourcePath);
		if( $realPth===false || !is_file($realPth) || !is_readable($realPth) ){
			throw new \RuntimeException('File not found: '.basename($sourcePath));
		}
		if( $baseFolder!='' ){
			$realDir = realpath($baseFolder);
			if( $realDir===false || strpos($realPth, rtrim($realDir, '/\\').DIRECTORY_SEPARATOR)!==0 ){
				throw new \RuntimeException('File outside the allowed folder: '.basename($sourcePath));
			}
		}
		$fileHdl = fopen($realPth, 'rb');
		if( $fileHdl===false ){
			throw new \RuntimeException('File not found: '.basename($sourcePath));
		}
		return new Fluxd($fileHdl, $realPth);
	}

	/**
	 * Opens the body of the request. It is copied to a temporary store
	 * first, so that it can be measured and read more than once.
	 *
	 * @return Fluxd The opened source.
	 *
	 * @throws \RuntimeException When the body cannot be read.
	 */
	public static function openInput(): Fluxd {
		$bodyHdl = fopen('php://input', 'rb');
		$copyHdl = tmpfile();
		if( $bodyHdl===false || $copyHdl===false ){
			throw new \RuntimeException('The request body cannot be read');
		}
		stream_copy_to_stream($bodyHdl, $copyHdl);
		fclose($bodyHdl);
		rewind($copyHdl);
		return new Fluxd($copyHdl);
	}

	/**
	 * Wraps bytes already in memory, such as a blob read from a table.
	 *
	 * @param String $rawContent The bytes.
	 * @return Fluxd The opened source.
	 *
	 * @throws \RuntimeException When the temporary store cannot be made.
	 */
	public static function openBytes( String $rawContent ): Fluxd {
		$copyHdl = tmpfile();
		if( $copyHdl===false ){
			throw new \RuntimeException('The temporary store cannot be made');
		}
		fwrite($copyHdl, $rawContent);
		rewind($copyHdl);
		return new Fluxd($copyHdl);
	}

	/**
	 * Gives the open stream, or refuses a source that was closed.
	 *
	 * @return mixed The open stream.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	protected function checkOpen(): Mixed {
		if( !is_resource($this->strmHndl) ){
			throw new \RuntimeException('The source is closed');
		}
		return $this->strmHndl;
	}

	/**
	 * Reads the next piece of the source.
	 *
	 * @param Int $chunkBytes Greatest number of bytes to read.
	 * @return String|Bool The piece, or false at the end of the source.
	 *
	 * @throws \RuntimeException When the size is not positive, or the source is closed.
	 */
	public function readChunk( Int $chunkBytes=8192 ): String|Bool {
		$strmHdl = $this->checkOpen();
		if( $chunkBytes<1 ){
			throw new \RuntimeException('A piece holds at least one byte');
		}
		$chnkTxt = fread($strmHdl, $chunkBytes);
		return ($chnkTxt===false || $chnkTxt==='') ? false : $chnkTxt;
	}

	/**
	 * Reads the next line of the source, without its line ending.
	 *
	 * @return String|Bool The line, or false at the end of the source.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function readALine(): String|Bool {
		$lineTxt = fgets($this->checkOpen());
		return ($lineTxt===false) ? false : rtrim($lineTxt, "\r\n");
	}

	/**
	 * Reads the bytes between two positions, both included, the first
	 * byte being at position 0. The reading goes on after the range.
	 *
	 * @param Int $rangeStart Position of the first byte.
	 * @param Int $rangeUntil Position of the last byte.
	 * @return String The bytes, fewer than asked when the source ends before.
	 *
	 * @throws \RuntimeException When the range is not valid, or the source is closed.
	 */
	public function readRange( Int $rangeStart, Int $rangeUntil ): String {
		$strmHdl = $this->checkOpen();
		if( $rangeStart<0 || $rangeUntil<$rangeStart ){
			throw new \RuntimeException('Invalid byte range: '.$rangeStart.'-'.$rangeUntil);
		}
		fseek($strmHdl, $rangeStart);
		$leftLen = $rangeUntil-$rangeStart+1;
		$rngeTxt = '';
		while( $leftLen>0 && !feof($strmHdl) ){
			$chnkTxt = fread($strmHdl, min(8192, $leftLen));
			if( $chnkTxt===false || $chnkTxt==='' ){
				break;
			}
			$rngeTxt .= $chnkTxt;
			$leftLen -= strlen($chnkTxt);
		}
		return $rngeTxt;
	}

	/**
	 * Reads the whole source, from its start. With a limit, a source
	 * larger than it is refused instead of being loaded.
	 *
	 * @param ?Int $limitBytes Greatest size accepted, null for no limit.
	 * @return String The content.
	 *
	 * @throws \RuntimeException When the source is larger than the limit, or is closed.
	 */
	public function readWhole( ?Int $limitBytes=null ): String {
		$strmHdl = $this->checkOpen();
		rewind($strmHdl);
		if( $limitBytes===null ){
			return strval(stream_get_contents($strmHdl));
		}
		// One byte more than the limit is enough to know the source is too large
		$fullTxt = strval(stream_get_contents($strmHdl, max(0, $limitBytes)+1));
		if( strlen($fullTxt)>$limitBytes ){
			throw new \RuntimeException('Content larger than the '.$limitBytes.' bytes allowed');
		}
		return $fullTxt;
	}

	/**
	 * Tells whether the end of the source is reached.
	 *
	 * @return Bool True when nothing is left to read.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function endOfFlux(): Bool {
		$strmHdl = $this->checkOpen();
		$fullLen = $this->totalSize();
		return ($fullLen!==null) ? ftell($strmHdl)>=$fullLen : feof($strmHdl);
	}

	/**
	 * Gives the size of the source.
	 *
	 * @return ?Int The size in bytes, null when it cannot be known.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function totalSize(): ?Int {
		$strmInf = fstat($this->checkOpen());
		return isset($strmInf['size']) ? intval($strmInf['size']) : null;
	}

	/**
	 * Goes back to the start of the source.
	 *
	 * @throws \RuntimeException When the source is closed.
	 */
	public function goToStart(): Void {
		rewind($this->checkOpen());
	}

	/**
	 * Gives the source as a local file, for what can only read a file on
	 * disk. A source that already is a local file gives its own path.
	 * Otherwise its content is written to the path given, or to a
	 * temporary file that is deleted when the source is closed. The
	 * reading position is left where it was.
	 *
	 * @param ?String $targetPath Path of the file to write, null for the source's own file or a temporary one.
	 * @return String The path of the local file.
	 *
	 * @throws \RuntimeException When the file cannot be written, or the source is closed.
	 */
	public function saveLocal( ?String $targetPath=null ): String {
		$strmHdl = $this->checkOpen();
		if( $targetPath===null && $this->srcePath!==null ){
			return $this->srcePath;
		}
		$savePth = $targetPath;
		if( $savePth===null ){
			$savePth = tempnam(sys_get_temp_dir(), 'nyu');
			if( $savePth===false ){
				throw new \RuntimeException('The temporary file cannot be made');
			}
			$this->tmpFiles[] = $savePth;
		}
		$saveHdl = @fopen($savePth, 'wb');
		if( $saveHdl===false ){
			throw new \RuntimeException('File cannot be written: '.basename($savePth));
		}
		$nowPosn = ftell($strmHdl);
		rewind($strmHdl);
		stream_copy_to_stream($strmHdl, $saveHdl);
		fclose($saveHdl);
		fseek($strmHdl, $nowPosn===false ? 0 : $nowPosn);
		return $savePth;
	}

	/**
	 * Releases the source and deletes the temporary files it made.
	 * Closing a source twice does nothing.
	 */
	public function closeFlux(): Void {
		if( is_resource($this->strmHndl) ){
			fclose($this->strmHndl);
		}
		$this->strmHndl = null;
		foreach( $this->tmpFiles as $tmpFle ){
			if( is_file($tmpFle) ){
				@unlink($tmpFle);
			}
		}
		$this->tmpFiles = Array();
	}

}
