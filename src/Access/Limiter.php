<?php

namespace nyu\Access;

/**
 * Limits the wrong credentials one client address may present: after as
 * many as allowed, the address is refused for the hold time, whatever it
 * presents. The counters are small files, one per address, so the limit
 * works with every credential check, with or without a database.
 */
class Limiter{

	// One counted failure out of this many also removes the counters no longer in use
	const SWEEPONE = 50;

	protected $maxTries;
	protected $holdSecs;
	protected $cntFoldr;

	/**
	 * @param Array $confValues Config block, optionally with the wrong credentials allowed from one address ('maxtry',
	 *                          defaults to 10; 0 for no limit), the time in seconds they add up within and then hold the
	 *                          address for ('holdtm', defaults to 600) and the 'folder' keeping the counters, relative to
	 *                          the project root (defaults to temp/limits).
	 */
	function __construct( Array $confValues ){
		$this->maxTries = max( 0, intval($confValues['maxtry'] ?? 10) );
		$this->holdSecs = max( 1, intval($confValues['holdtm'] ?? 600) );
		$fldName = trim( strval($confValues['folder'] ?? ''), '/\\ ' );
		$this->cntFoldr = TOP_DIR.DS.($fldName!='' ? $fldName : 'temp'.DS.'limits');
	}

	/**
	 * Tells whether an address is held back after too many wrong
	 * credentials: as many as allowed, the last one within the hold time.
	 *
	 * @param String $clientAddr Address of the client.
	 * @param ?Int   $unixMoment Unix time to check at; now when not given.
	 * @return Int The Unix time the hold ends at, or 0 when the address is not held.
	 */
	public function heldUntil( String $clientAddr, ?Int $unixMoment = null ): Int {
		$cntPath = $this->pathOfKey( $this->keyOfAddr($clientAddr) );
		if( $this->maxTries<1 || $cntPath=='' || !is_file($cntPath) ){
			return 0;
		}
		$nowTime = $unixMoment ?? time();
		$cntFile = @fopen($cntPath, 'rb');
		if( $cntFile===false ){
			return 0;
		}
		flock($cntFile, LOCK_SH);
		$cntVals = $this->readCount( strval(stream_get_contents($cntFile)) );
		flock($cntFile, LOCK_UN);
		fclose($cntFile);
		if( $cntVals===null || $cntVals['fails']<$this->maxTries || $cntVals['stamp']+$this->holdSecs<=$nowTime ){
			return 0;
		}
		return $cntVals['stamp']+$this->holdSecs;
	}

	/**
	 * Counts one more wrong credential for an address. The count restarts
	 * at 1 when the previous one is older than the hold time. A counter
	 * that cannot be written limits nothing: the mistake is logged, and
	 * the request is answered as it would be without a limit.
	 *
	 * @param String $clientAddr Address of the client.
	 * @param ?Int   $unixMoment Unix time of the failure; now when not given.
	 */
	public function countFail( String $clientAddr, ?Int $unixMoment = null ): Void {
		$cntPath = $this->pathOfKey( $this->keyOfAddr($clientAddr) );
		if( $this->maxTries<1 || $cntPath=='' ){
			return;
		}
		$nowTime = $unixMoment ?? time();
		$cntFile = $this->openFoldr() ? @fopen($cntPath, 'c+b') : false;
		if( $cntFile===false || !flock($cntFile, LOCK_EX) ){
			\nyu\Tools\Logger::getLogger('access')->error( 'Address limit not applied: the counters cannot be written in '.$this->cntFoldr );
			if( $cntFile!==false ){
				fclose($cntFile);
			}
			return;
		}
		$cntVals = $this->readCount( strval(stream_get_contents($cntFile)) );
		$failNum = ($cntVals===null || $cntVals['stamp']+$this->holdSecs<=$nowTime) ? 1 : $cntVals['fails']+1;
		ftruncate($cntFile, 0);
		rewind($cntFile);
		fwrite($cntFile, $failNum.' '.$nowTime);
		fflush($cntFile);
		flock($cntFile, LOCK_UN);
		fclose($cntFile);
		// Now and then, the counters of the addresses that stopped trying are removed
		if( random_int(1, self::SWEEPONE)==1 ){
			$this->dropStale($nowTime);
		}
	}

	/**
	 * Removes the counters no longer in use: those whose last failure is
	 * older than the hold time.
	 *
	 * @param ?Int $unixMoment Unix time to check at; now when not given.
	 * @return Int The number of counters removed.
	 */
	public function dropStale( ?Int $unixMoment = null ): Int {
		$nowTime = $unixMoment ?? time();
		$dropNum = 0;
		foreach( (array)glob($this->cntFoldr.DS.'*.cnt') as $cntFle ){
			$cntVals = $this->readCount( strval(@file_get_contents($cntFle)) );
			if( ($cntVals===null || $cntVals['stamp']+$this->holdSecs<=$nowTime) && @unlink($cntFle) ){
				$dropNum++;
			}
		}
		return $dropNum;
	}

	/**
	 * Gives the key an address is counted under: the address itself for
	 * IPv4, its /64 network for IPv6, where one client commonly holds the
	 * whole network. The key is a hash: it names a file, and keeps the
	 * addresses out of the folder.
	 *
	 * @param String $clientAddr Address of the client.
	 * @return String The key, 64 hexadecimal characters; empty when the address is not one.
	 */
	protected function keyOfAddr( String $clientAddr ): String {
		if( filter_var($clientAddr, FILTER_VALIDATE_IP)===false ){
			return '';
		}
		$addrBin = inet_pton($clientAddr);
		if( strlen($addrBin)==16 ){
			$addrBin = substr($addrBin, 0, 8).str_repeat("\x00", 8);
		}
		return hash('sha256', strval(inet_ntop($addrBin)));
	}

	/**
	 * Gives the file a key is counted in.
	 *
	 * @param String $addressKey The key, as keyOfAddr() gives it.
	 * @return String The path of the file; empty for an empty key.
	 */
	protected function pathOfKey( String $addressKey ): String {
		return ($addressKey=='') ? '' : $this->cntFoldr.DS.$addressKey.'.cnt';
	}

	/**
	 * Reads what a counter holds: the failures counted, and the time of
	 * the last one.
	 *
	 * @param String $counterTxt Content of the counter.
	 * @return ?Array The count under 'fails' and the Unix time under 'stamp'; null when the content is not a counter.
	 */
	protected function readCount( String $counterTxt ): ?Array {
		$cntPart = Array();
		if( !preg_match('/^(\d{1,9}) (\d{1,12})$/D', trim($counterTxt), $cntPart) ){
			return null;
		}
		return Array('fails'=>intval($cntPart[1]), 'stamp'=>intval($cntPart[2]));
	}

	/**
	 * Makes sure the folder of the counters is there and can be written to.
	 *
	 * @return Bool True when counters can be written.
	 */
	protected function openFoldr(): Bool {
		if( !is_dir($this->cntFoldr) ){
			@mkdir($this->cntFoldr, 0770, true);
		}
		return is_dir($this->cntFoldr) && is_writable($this->cntFoldr);
	}
}
