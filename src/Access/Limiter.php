<?php

namespace nyu\Access;

/**
 * Limits the wrong credentials one client address may present: after as
 * many as allowed, the address is refused for the hold time, whatever it
 * presents. The stores read from a file (usrlst, htpass) count each account
 * the same way (see heldByUsr and countUser), in counters of their own. The counters are small files, one per address, so the limit
 * works with every credential check, with or without a database. Each
 * counter holds its address, for the held ones to be listed and released
 * (see listHolds and freeHolds).
 */
class Limiter{

	// One counted failure out of this many also removes the counters no longer in use
	const SWEEPONE = 50;

	protected $maxTries;
	protected $holdSecs;
	protected $cntFoldr;

	/**
	 * Builds the limiter of wrong credentials from its config block.
	 *
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
	 * A counter whose hold time has passed, or that is not one, is removed
	 * once read, as dropStale() does: an address that stopped failing is
	 * no longer read on each of its requests.
	 *
	 * @param String $clientAddr Address of the client.
	 * @param ?Int   $unixMoment Unix time to check at; now when not given.
	 * @return Int The Unix time the hold ends at, or 0 when the address is not held.
	 */
	public function heldUntil( String $clientAddr, ?Int $unixMoment = null ): Int {
		return $this->readsHeld( $this->pathOfKey($this->keyOfAddr($clientAddr)), $unixMoment ?? time() );
	}

	/**
	 * Tells whether an account is held back after too many wrong
	 * credentials, as heldUntil() does for an address. Its counter is named
	 * by a hash of the user name, which it does not hold.
	 *
	 * @param String $userString Login name of the user.
	 * @param ?Int   $unixMoment Unix time to check at; now when not given.
	 * @return Int The Unix time the hold ends at, or 0 when the account is not held.
	 */
	public function heldByUsr( String $userString, ?Int $unixMoment = null ): Int {
		return $this->readsHeld( $this->pathOfUsr($userString), $unixMoment ?? time() );
	}

	/**
	 * Counts one more wrong credential for an account, as countFail() does
	 * for an address.
	 *
	 * @param String $userString Login name of the user.
	 * @param ?Int   $unixMoment Unix time of the failure; now when not given.
	 */
	public function countUser( String $userString, ?Int $unixMoment = null ): Void {
		$this->writeFail( $this->pathOfUsr($userString), '', $unixMoment ?? time() );
	}

	/**
	 * Forgets the wrong credentials of an account, after a right one.
	 *
	 * @param String $userString Login name of the user.
	 */
	public function clearUser( String $userString ): Void {
		$cntPath = $this->pathOfUsr($userString);
		if( $cntPath!='' && is_file($cntPath) ){
			@unlink($cntPath);
		}
	}

	/**
	 * Reads a counter: the end of its hold, when it holds as many failures
	 * as allowed within the hold time. A counter whose hold time has passed,
	 * or that is not one, is removed once read.
	 *
	 * @param String $counterPth Path of the counter; empty for none.
	 * @param Int    $unixMoment Unix time to check at.
	 * @return Int The Unix time the hold ends at, or 0 when nothing is held.
	 */
	protected function readsHeld( String $counterPth, Int $unixMoment ): Int {
		$cntPath = $counterPth;
		if( $this->maxTries<1 || $cntPath=='' || !is_file($cntPath) ){
			return 0;
		}
		$nowTime = $unixMoment;
		$cntFile = @fopen($cntPath, 'rb');
		if( $cntFile===false ){
			return 0;
		}
		flock($cntFile, LOCK_SH);
		$cntVals = $this->readCount( strval(stream_get_contents($cntFile)) );
		flock($cntFile, LOCK_UN);
		fclose($cntFile);
		if( $cntVals===null || $cntVals['stamp']+$this->holdSecs<=$nowTime ){
			// A failure counted meanwhile would have started again at 1
			@unlink($cntPath);
			return 0;
		}
		if( $cntVals['fails']<$this->maxTries ){
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
		$this->writeFail( $this->pathOfKey($this->keyOfAddr($clientAddr)), $this->maskdAddr($clientAddr), $unixMoment ?? time() );
	}

	/**
	 * Counts one more failure in a counter, which restarts at 1 when the
	 * previous one is older than the hold time. A counter that cannot be
	 * written limits nothing: the mistake is logged.
	 *
	 * @param String $counterPth Path of the counter; empty for none.
	 * @param String $addressTxt What the counter tells beside its count: the address counted, or nothing.
	 * @param Int    $unixMoment Unix time of the failure.
	 */
	protected function writeFail( String $counterPth, String $addressTxt, Int $unixMoment ): Void {
		$cntPath = $counterPth;
		if( $this->maxTries<1 || $cntPath=='' ){
			return;
		}
		$nowTime = $unixMoment;
		$cntFile = $this->openFoldr() ? @fopen($cntPath, 'c+b') : false;
		if( $cntFile===false || !flock($cntFile, LOCK_EX) ){
			\nyu\Tools\Logger::getLogger('access')->error( 'Limit not applied: the counters cannot be written in '.$this->cntFoldr );
			if( $cntFile!==false ){
				fclose($cntFile);
			}
			return;
		}
		$cntVals = $this->readCount( strval(stream_get_contents($cntFile)) );
		$failNum = ($cntVals===null || $cntVals['stamp']+$this->holdSecs<=$nowTime) ? 1 : $cntVals['fails']+1;
		ftruncate($cntFile, 0);
		rewind($cntFile);
		fwrite($cntFile, $failNum.' '.$nowTime.( $addressTxt!='' ? ' '.$addressTxt : '' ));
		fflush($cntFile);
		flock($cntFile, LOCK_UN);
		fclose($cntFile);
		// Now and then, the counters of the addresses that stopped trying are removed
		if( random_int(1, self::SWEEPONE)==1 ){
			$this->dropStale($nowTime);
		}
	}

	/**
	 * Removes the counters no longer in use, of the addresses and of the
	 * accounts: those whose last failure is older than the hold time.
	 *
	 * @param ?Int $unixMoment Unix time to check at; now when not given.
	 * @return Int The number of counters removed.
	 */
	public function dropStale( ?Int $unixMoment = null ): Int {
		$nowTime = $unixMoment ?? time();
		$dropNum = 0;
		foreach( array_merge((array)glob($this->cntFoldr.DS.'*.cnt'), (array)glob($this->cntFoldr.DS.'*.usr')) as $cntFle ){
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
	 * whole network (see maskdAddr). The key is a hash: it names a file.
	 *
	 * @param String $clientAddr Address of the client.
	 * @return String The key, 64 hexadecimal characters; empty when the address is not one.
	 */
	protected function keyOfAddr( String $clientAddr ): String {
		$addrTxt = $this->maskdAddr($clientAddr);
		return ($addrTxt=='') ? '' : hash('sha256', $addrTxt);
	}

	/**
	 * Gives the address a client is counted as: its own for IPv4, the
	 * first address of its /64 network for IPv6.
	 *
	 * @param String $clientAddr Address of the client.
	 * @return String The address counted; empty when the address is not one.
	 */
	protected function maskdAddr( String $clientAddr ): String {
		if( filter_var($clientAddr, FILTER_VALIDATE_IP)===false ){
			return '';
		}
		$addrBin = inet_pton($clientAddr);
		if( strlen($addrBin)==16 ){
			$addrBin = substr($addrBin, 0, 8).str_repeat("\x00", 8);
		}
		return strval(inet_ntop($addrBin));
	}

	/**
	 * Lists the addresses that have a counter: those held, and those
	 * still within the time their failures add up.
	 *
	 * @param ?Int $unixMoment Unix time to check at; now when not given.
	 * @return Array One entry per counter in use, with the address under 'addrs' (empty when the counter does not tell it), the failures under 'fails', the time of the last one under 'stamp' and the end of the hold under 'until' (0 when the address is not held).
	 */
	public function listHolds( ?Int $unixMoment = null ): Array {
		$nowTime = $unixMoment ?? time();
		$heldLst = Array();
		foreach( (array)glob($this->cntFoldr.DS.'*.cnt') as $cntFle ){
			$cntVals = $this->readCount( strval(@file_get_contents(strval($cntFle))) );
			if( $cntVals===null || $cntVals['stamp']+$this->holdSecs<=$nowTime ){
				continue;
			}
			$cntVals['until'] = ($this->maxTries>0 && $cntVals['fails']>=$this->maxTries) ? $cntVals['stamp']+$this->holdSecs : 0;
			$heldLst[] = $cntVals;
		}
		return $heldLst;
	}

	/**
	 * Releases an address, or all of them, the accounts included: their
	 * counters are removed, so they are no longer held and their failures
	 * are forgotten.
	 *
	 * @param ?String $clientAddr Address to release; null for every address.
	 * @return Int The number of counters removed.
	 */
	public function freeHolds( ?String $clientAddr = null ): Int {
		if( $clientAddr!==null ){
			$cntPath = $this->pathOfKey( $this->keyOfAddr($clientAddr) );
			return ( $cntPath!='' && is_file($cntPath) && @unlink($cntPath) ) ? 1 : 0;
		}
		$dropNum = 0;
		foreach( array_merge((array)glob($this->cntFoldr.DS.'*.cnt'), (array)glob($this->cntFoldr.DS.'*.usr')) as $cntFle ){
			if( @unlink(strval($cntFle)) ){
				$dropNum++;
			}
		}
		return $dropNum;
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
	 * Gives the file an account is counted in: a hash of its name, which
	 * the file does not hold, with its own extension (.usr), apart from the
	 * counters of the addresses.
	 *
	 * @param String $userString Login name of the user.
	 * @return String The path of the file; empty for an empty name.
	 */
	protected function pathOfUsr( String $userString ): String {
		return ($userString=='') ? '' : $this->cntFoldr.DS.hash('sha256', 'user:'.$userString).'.usr';
	}

	/**
	 * Reads what a counter holds: the failures counted, and the time of
	 * the last one.
	 *
	 * @param String $counterTxt Content of the counter.
	 * @return ?Array The count under 'fails', the Unix time under 'stamp' and the address under 'addrs' (empty in a counter written before addresses were kept); null when the content is not a counter.
	 */
	protected function readCount( String $counterTxt ): ?Array {
		$cntPart = Array();
		if( !preg_match('/^(\d{1,9}) (\d{1,12})(?: ([0-9A-Fa-f:.]{2,45}))?$/D', trim($counterTxt), $cntPart) ){
			return null;
		}
		return Array('fails'=>intval($cntPart[1]), 'stamp'=>intval($cntPart[2]), 'addrs'=>strval($cntPart[3] ?? ''));
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
