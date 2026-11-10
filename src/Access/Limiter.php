<?php

namespace nyu\Access;

/**
 * Limits the wrong credentials one client address may present: after as
 * many as allowed, the address is refused for the hold time, whatever it
 * presents. The stores read from a file (userFile, htpasswd) count each account
 * the same way (see heldByUsr and countUser), in counters of their own. The counters are small files, one per address, so the limit
 * works with every credential check, with or without a database. Each
 * counter holds its address, for the held ones to be listed and released
 * (see listHolds and freeHolds).
 */
class Limiter{

	// One counted failure out of this many also removes the counters no longer in use
	const SWEP_ONCE = 50;

	protected $triesLimit;
	protected $holdPeriod;
	protected $countsFldr;

	/**
	 * Builds the limiter of wrong credentials from its config block.
	 *
	 * @param Array $configValues Config block, optionally with the wrong credentials allowed from one address ('maxTries',
	 *                          defaults to 10; 0 for no limit), the time in seconds they add up within and then hold the
	 *                          address for ('holdSecs', defaults to 600) and the 'dataPath' keeping the counters, relative to
	 *                          the project root (defaults to temp/limits).
	 */
	function __construct( Array $configValues ){
		$this->triesLimit = max( 0, intval($configValues['maxTries'] ?? 10) );
		$this->holdPeriod = max( 1, intval($configValues['holdSecs'] ?? 600) );
		$fldName = trim( strval($configValues['dataPath'] ?? ''), '/\\ ' );
		$this->countsFldr = TOP_DIR.DS.($fldName!='' ? $fldName : 'temp'.DS.'limits');
	}

	/**
	 * Tells whether an address is held back after too many wrong
	 * credentials: as many as allowed, the last one within the hold time.
	 * A counter whose hold time has passed, or that is not one, is removed
	 * once read, as dropStaleCount() does: an address that stopped failing is
	 * no longer read on each of its requests.
	 *
	 * @param String $clientIpAddr Address of the client.
	 * @param ?Int   $unixMomentNb Unix time to check at; now when not given.
	 * @return Int The Unix time the hold ends at, or 0 when the address is not held.
	 */
	public function heldUntilCount( String $clientIpAddr, ?Int $unixMomentNb = null ): Int {
		return $this->readsHeldCount( $this->pathLimitOfKey($this->keyLimitOfAddr($clientIpAddr)), $unixMomentNb ?? time() );
	}

	/**
	 * Tells whether an account is held back after too many wrong
	 * credentials, as heldUntilCount() does for an address. Its counter is named
	 * by a hash of the user name, which it does not hold.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param ?Int   $unixMomentNb Unix time to check at; now when not given.
	 * @return Int The Unix time the hold ends at, or 0 when the account is not held.
	 */
	public function heldByUserNumb( String $namedUserTxt, ?Int $unixMomentNb = null ): Int {
		return $this->readsHeldCount( $this->pathOfUserText($namedUserTxt), $unixMomentNb ?? time() );
	}

	/**
	 * Counts one more wrong credential for an account, as countFailedTry() does
	 * for an address.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param ?Int   $unixMomentNb Unix time of the failure; now when not given.
	 */
	public function countLimitUser( String $namedUserTxt, ?Int $unixMomentNb = null ): Void {
		$this->writeLimitFail( $this->pathOfUserText($namedUserTxt), '', $unixMomentNb ?? time() );
	}

	/**
	 * Forgets the wrong credentials of an account, after a right one.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 */
	public function clearLimitUser( String $namedUserTxt ): Void {
		$cntPath = $this->pathOfUserText($namedUserTxt);
		if( $cntPath!='' && is_file($cntPath) ){
			@unlink($cntPath);
		}
	}

	/**
	 * Reads a counter: the end of its hold, when it holds as many failures
	 * as allowed within the hold time. A counter whose hold time has passed,
	 * or that is not one, is removed once read.
	 *
	 * @param String $countersPath Path of the counter; empty for none.
	 * @param Int    $unixMomentNb Unix time to check at.
	 * @return Int The Unix time the hold ends at, or 0 when nothing is held.
	 */
	protected function readsHeldCount( String $countersPath, Int $unixMomentNb ): Int {
		$cntPath = $countersPath;
		if( $this->triesLimit<1 || $cntPath=='' || !is_file($cntPath) ){
			return 0;
		}
		$nowTime = $unixMomentNb;
		$cntFile = @fopen($cntPath, 'rb');
		if( $cntFile===false ){
			return 0;
		}
		flock($cntFile, LOCK_SH);
		$tallies = $this->readLimitCount( strval(stream_get_contents($cntFile)) );
		flock($cntFile, LOCK_UN);
		fclose($cntFile);
		if( $tallies===null || $tallies['stamp']+$this->holdPeriod<=$nowTime ){
			// A failure counted meanwhile would have started again at 1
			@unlink($cntPath);
			return 0;
		}
		if( $tallies['fails']<$this->triesLimit ){
			return 0;
		}
		return $tallies['stamp']+$this->holdPeriod;
	}

	/**
	 * Counts one more wrong credential for an address. The count restarts
	 * at 1 when the previous one is older than the hold time. A counter
	 * that cannot be written limits nothing: the mistake is logged, and
	 * the request is answered as it would be without a limit.
	 *
	 * @param String $clientIpAddr Address of the client.
	 * @param ?Int   $unixMomentNb Unix time of the failure; now when not given.
	 */
	public function countFailedTry( String $clientIpAddr, ?Int $unixMomentNb = null ): Void {
		$this->writeLimitFail( $this->pathLimitOfKey($this->keyLimitOfAddr($clientIpAddr)), $this->maskClientAddr($clientIpAddr), $unixMomentNb ?? time() );
	}

	/**
	 * Counts one more failure in a counter, which restarts at 1 when the
	 * previous one is older than the hold time. A counter that cannot be
	 * written limits nothing: the mistake is logged.
	 *
	 * @param String $countersPath Path of the counter; empty for none.
	 * @param String $limitAdrText What the counter tells beside its count: the address counted, or nothing.
	 * @param Int    $unixMomentNb Unix time of the failure.
	 */
	protected function writeLimitFail( String $countersPath, String $limitAdrText, Int $unixMomentNb ): Void {
		$cntPath = $countersPath;
		if( $this->triesLimit<1 || $cntPath=='' ){
			return;
		}
		$nowTime = $unixMomentNb;
		$cntFile = $this->openLimitsFldr() ? @fopen($cntPath, 'c+b') : false;
		if( $cntFile===false || !flock($cntFile, LOCK_EX) ){
			\nyu\Tools\Logger::retrieveLogger('access')->error( 'Limit not applied: the counters cannot be written in '.$this->countsFldr );
			if( $cntFile!==false ){
				fclose($cntFile);
			}
			return;
		}
		$tallies = $this->readLimitCount( strval(stream_get_contents($cntFile)) );
		$failNum = ($tallies===null || $tallies['stamp']+$this->holdPeriod<=$nowTime) ? 1 : $tallies['fails']+1;
		ftruncate($cntFile, 0);
		rewind($cntFile);
		fwrite($cntFile, $failNum.' '.$nowTime.( $limitAdrText!='' ? ' '.$limitAdrText : '' ));
		fflush($cntFile);
		flock($cntFile, LOCK_UN);
		fclose($cntFile);
		// Now and then, the counters of the addresses that stopped trying are removed
		if( random_int(1, self::SWEP_ONCE)==1 ){
			$this->dropStaleCount($nowTime);
		}
	}

	/**
	 * Removes the counters no longer in use, of the addresses and of the
	 * accounts: those whose last failure is older than the hold time.
	 *
	 * @param ?Int $unixMomentNb Unix time to check at; now when not given.
	 * @return Int The number of counters removed.
	 */
	public function dropStaleCount( ?Int $unixMomentNb = null ): Int {
		$nowTime = $unixMomentNb ?? time();
		$dropNum = 0;
		foreach( array_merge((array)glob($this->countsFldr.DS.'*.cnt'), (array)glob($this->countsFldr.DS.'*.usr')) as $cntFil ){
			$tallies = $this->readLimitCount( strval(@file_get_contents($cntFil)) );
			if( ($tallies===null || $tallies['stamp']+$this->holdPeriod<=$nowTime) && @unlink($cntFil) ){
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
	 * @param String $clientIpAddr Address of the client.
	 * @return String The key, 64 hexadecimal characters; empty when the address is not one.
	 */
	protected function keyLimitOfAddr( String $clientIpAddr ): String {
		$addrTxt = $this->maskClientAddr($clientIpAddr);
		return ($addrTxt=='') ? '' : hash('sha256', $addrTxt);
	}

	/**
	 * Gives the address a client is counted as: its own for IPv4, the
	 * first address of its /64 network for IPv6.
	 *
	 * @param String $clientIpAddr Address of the client.
	 * @return String The address counted; empty when the address is not one.
	 */
	protected function maskClientAddr( String $clientIpAddr ): String {
		if( filter_var($clientIpAddr, FILTER_VALIDATE_IP)===false ){
			return '';
		}
		$addrRaw = inet_pton($clientIpAddr);
		if( strlen($addrRaw)==16 ){
			$addrRaw = substr($addrRaw, 0, 8).str_repeat("\x00", 8);
		}
		return strval(inet_ntop($addrRaw));
	}

	/**
	 * Lists the addresses that have a counter: those held, and those
	 * still within the time their failures add up.
	 *
	 * @param ?Int $unixMomentNb Unix time to check at; now when not given.
	 * @return Array One entry per counter in use, with the address under 'addrs' (empty when the counter does not tell it), the failures under 'fails', the time of the last one under 'stamp' and the end of the hold under 'until' (0 when the address is not held).
	 */
	public function listLimitHolds( ?Int $unixMomentNb = null ): Array {
		$nowTime = $unixMomentNb ?? time();
		$heldLst = Array();
		foreach( (array)glob($this->countsFldr.DS.'*.cnt') as $cntFil ){
			$tallies = $this->readLimitCount( strval(@file_get_contents(strval($cntFil))) );
			if( $tallies===null || $tallies['stamp']+$this->holdPeriod<=$nowTime ){
				continue;
			}
			$tallies['until'] = ($this->triesLimit>0 && $tallies['fails']>=$this->triesLimit) ? $tallies['stamp']+$this->holdPeriod : 0;
			$heldLst[] = $tallies;
		}
		return $heldLst;
	}

	/**
	 * Releases an address, or all of them, the accounts included: their
	 * counters are removed, so they are no longer held and their failures
	 * are forgotten.
	 *
	 * @param ?String $clientIpAddr Address to release; null for every address.
	 * @return Int The number of counters removed.
	 */
	public function freeHoldsCount( ?String $clientIpAddr = null ): Int {
		if( $clientIpAddr!==null ){
			$cntPath = $this->pathLimitOfKey( $this->keyLimitOfAddr($clientIpAddr) );
			return ( $cntPath!='' && is_file($cntPath) && @unlink($cntPath) ) ? 1 : 0;
		}
		$dropNum = 0;
		foreach( array_merge((array)glob($this->countsFldr.DS.'*.cnt'), (array)glob($this->countsFldr.DS.'*.usr')) as $cntFil ){
			if( @unlink(strval($cntFil)) ){
				$dropNum++;
			}
		}
		return $dropNum;
	}

	/**
	 * Gives the file a key is counted in.
	 *
	 * @param String $limitAddrKey The key, as keyLimitOfAddr() gives it.
	 * @return String The path of the file; empty for an empty key.
	 */
	protected function pathLimitOfKey( String $limitAddrKey ): String {
		return ($limitAddrKey=='') ? '' : $this->countsFldr.DS.$limitAddrKey.'.cnt';
	}

	/**
	 * Gives the file an account is counted in: a hash of its name, which
	 * the file does not hold, with its own extension (.usr), apart from the
	 * counters of the addresses.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String The path of the file; empty for an empty name.
	 */
	protected function pathOfUserText( String $namedUserTxt ): String {
		return ($namedUserTxt=='') ? '' : $this->countsFldr.DS.hash('sha256', 'user:'.$namedUserTxt).'.usr';
	}

	/**
	 * Reads what a counter holds: the failures counted, and the time of
	 * the last one.
	 *
	 * @param String $rawCountText Content of the counter.
	 * @return ?Array The count under 'fails', the Unix time under 'stamp' and the address under 'addrs' (empty in a counter written before addresses were kept); null when the content is not a counter.
	 */
	protected function readLimitCount( String $rawCountText ): ?Array {
		$cntPart = Array();
		if( !preg_match('/^(\d{1,9}) (\d{1,12})(?: ([0-9A-Fa-f:.]{2,45}))?$/D', trim($rawCountText), $cntPart) ){
			return null;
		}
		return Array('fails'=>intval($cntPart[1]), 'stamp'=>intval($cntPart[2]), 'addrs'=>strval($cntPart[3] ?? ''));
	}

	/**
	 * Makes sure the folder of the counters is there and can be written to.
	 *
	 * @return Bool True when counters can be written.
	 */
	protected function openLimitsFldr(): Bool {
		if( !is_dir($this->countsFldr) ){
			@mkdir($this->countsFldr, 0770, true);
		}
		return is_dir($this->countsFldr) && is_writable($this->countsFldr);
	}
}
