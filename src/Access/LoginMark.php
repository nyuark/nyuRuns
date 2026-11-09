<?php

namespace nyu\Access;

/**
 * The mark a client gets once it has logged in as a user, and sends back
 * at its next logins ('lgmark': the X-Lgmark header, or the lgmark
 * cookie). It names the user, the time it was made and an identifier of
 * its own, signed with the key of the limits block ('mrkkey'); it holds no
 * secret of the user. A client presenting a valid mark for the user it
 * logs in as is not stopped by the hold of the account: the stores count
 * its failures on the mark instead (see Limiter::countUser), so that
 * guesses from elsewhere cannot keep the owner out. A mark ends after
 * 'mrkday' days, with the password set after it was made (native
 * userbase), or with its key.
 */
class LoginMark{

	// Days a mark lasts, unless 'mrkday' says otherwise
	const MARKDAYS = 90;
	// Shortest key the marks are signed with, in characters : without one, no mark is made or read
	const KEYSSIZE = 32;

	protected $mrkSecrt;
	protected $markDays;

	/**
	 * Builds the marks from the limits block of the access config.
	 *
	 * @param Array $limitsConf The block, with the key the marks are signed with ('mrkkey') and their lifetime in days ('mrkday').
	 */
	function __construct( Array $limitsConf ){
		$this->mrkSecrt = is_string($limitsConf['mrkkey'] ?? null) ? $limitsConf['mrkkey'] : '';
		$this->markDays = max( 1, intval($limitsConf['mrkday'] ?? self::MARKDAYS) );
	}

	/**
	 * Builds the marks from the limits block of the loaded config.
	 *
	 * @return LoginMark The marks.
	 */
	public static function fromLimit(): LoginMark {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'LIMITS') : null;
		return new self( is_array($lmtConf) ? $lmtConf : Array() );
	}

	/**
	 * Makes the mark of a user that has just logged in.
	 *
	 * @param String $userString Login name of the user.
	 * @return String The mark, or '' when no key of KEYSSIZE characters at least is set.
	 */
	public function makesMark( String $userString ): String {
		if( strlen($this->mrkSecrt)<self::KEYSSIZE || $userString==='' ){
			return '';
		}
		$markTxt = $this->base64Url($userString).'.'.time().'.'.bin2hex(random_bytes(8));
		return $markTxt.'.'.$this->base64Url( hash_hmac('sha256', $markTxt, $this->mrkSecrt, true) );
	}

	/**
	 * Reads a mark a client sent: signed with the key, made for the user it
	 * logs in as, within its lifetime, and after the password was last set.
	 *
	 * @param String $markString The mark, as sent.
	 * @param String $userString Login name of the user logging in.
	 * @param ?Int   $passedUnix Unix time the password was last set; null when the store keeps none.
	 * @return String The key its failures are counted under ('mark:' and its identifier), or '' when it is not a valid mark for that user.
	 */
	public function readsMark( String $markString, String $userString, ?Int $passedUnix=null ): String {
		$markPrt = explode('.', $markString);
		if( strlen($this->mrkSecrt)<self::KEYSSIZE || count($markPrt)!==4 || !preg_match('/^\d{1,12}$/D', $markPrt[1]) || !preg_match('/^[0-9a-f]{16}$/D', $markPrt[2]) ){
			return '';
		}
		$signTxt = $this->base64Url( hash_hmac('sha256', $markPrt[0].'.'.$markPrt[1].'.'.$markPrt[2], $this->mrkSecrt, true) );
		if( !hash_equals($signTxt, $markPrt[3]) || $markPrt[0]!==$this->base64Url($userString) ){
			return '';
		}
		$madeUnx = intval($markPrt[1]);
		if( $madeUnx>time() + 60 || $madeUnx + 86400*$this->markDays<time() || ($passedUnix!==null && $madeUnx<$passedUnix) ){
			return '';
		}
		return 'mark:'.$markPrt[2];
	}

	/**
	 * Gives the days a mark lasts.
	 *
	 * @return Int The days.
	 */
	public function markLasts(): Int {
		return $this->markDays;
	}

	/**
	 * Encodes bytes as base64url, without padding.
	 *
	 * @param String $binaryText The bytes.
	 * @return String The encoded text.
	 */
	protected function base64Url( String $binaryText ): String {
		return rtrim( strtr(base64_encode($binaryText), '+/', '-_'), '=' );
	}
}
