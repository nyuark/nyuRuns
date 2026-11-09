<?php

namespace nyu\Access;

/**
 * The mark a client gets once it has logged in as a user, and sends back
 * at its next logins ('lgmark': the X-Lgmark header, or the lgmark
 * cookie). It names the user, the time it was made and an identifier of
 * its own, signed with the key of the limits block ('markSecr'); it holds no
 * secret of the user. A client presenting a valid mark for the user it
 * logs in as is not stopped by the hold of the account: the stores count
 * its failures on the mark instead (see Limiter::countLimitUser), so that
 * guesses from elsewhere cannot keep the owner out. A mark ends after
 * 'markDays' days, with the password set after it was made (native
 * userbase), or with its key.
 */
class LoginMark{

	// Days a mark lasts, unless 'markDays' says otherwise
	const MARK_DAYS = 90;
	// Shortest key the marks are signed with, in characters : without one, no mark is made or read
	const KEYS_SIZE = 32;

	protected $markSecret;
	protected $lifeInDays;

	/**
	 * Builds the marks from the limits block of the access config.
	 *
	 * @param Array $limitsConfig The block, with the key the marks are signed with ('markSecr') and their lifetime in days ('markDays').
	 */
	function __construct( Array $limitsConfig ){
		$this->markSecret = is_string($limitsConfig['markSecr'] ?? null) ? $limitsConfig['markSecr'] : '';
		$this->lifeInDays = max( 1, intval($limitsConfig['markDays'] ?? self::MARK_DAYS) );
	}

	/**
	 * Builds the marks from the limits block of the loaded config.
	 *
	 * @return LoginMark The marks.
	 */
	public static function fromLimitBlock(): LoginMark {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'TRYLIMIT') : null;
		return new self( is_array($lmtConf) ? $lmtConf : Array() );
	}

	/**
	 * Makes the mark of a user that has just logged in.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String The mark, or '' when no key of KEYSSIZE characters at least is set.
	 */
	public function createUserMark( String $namedUserTxt ): String {
		if( strlen($this->markSecret)<self::KEYS_SIZE || $namedUserTxt==='' ){
			return '';
		}
		$markTxt = $this->base64UrlNoPad($namedUserTxt).'.'.time().'.'.bin2hex(random_bytes(8));
		return $markTxt.'.'.$this->base64UrlNoPad( hash_hmac('sha256', $markTxt, $this->markSecret, true) );
	}

	/**
	 * Reads a mark a client sent: signed with the key, made for the user it
	 * logs in as, within its lifetime, and after the password was last set.
	 *
	 * @param String $sentMarkText The mark, as sent.
	 * @param String $namedUserTxt Login name of the user logging in.
	 * @param ?Int   $suppliedUnix Unix time the password was last set; null when the store keeps none.
	 * @return String The key its failures are counted under ('mark:' and its identifier), or '' when it is not a valid mark for that user.
	 */
	public function readClientMark( String $sentMarkText, String $namedUserTxt, ?Int $suppliedUnix=null ): String {
		$mrkPart = explode('.', $sentMarkText);
		if( strlen($this->markSecret)<self::KEYS_SIZE || count($mrkPart)!==4 || !preg_match('/^\d{1,12}$/D', $mrkPart[1]) || !preg_match('/^[0-9a-f]{16}$/D', $mrkPart[2]) ){
			return '';
		}
		$signTxt = $this->base64UrlNoPad( hash_hmac('sha256', $mrkPart[0].'.'.$mrkPart[1].'.'.$mrkPart[2], $this->markSecret, true) );
		if( !hash_equals($signTxt, $mrkPart[3]) || $mrkPart[0]!==$this->base64UrlNoPad($namedUserTxt) ){
			return '';
		}
		$madeUnx = intval($mrkPart[1]);
		if( $madeUnx>time() + 60 || $madeUnx + 86400*$this->lifeInDays<time() || ($suppliedUnix!==null && $madeUnx<$suppliedUnix) ){
			return '';
		}
		return 'mark:'.$mrkPart[2];
	}

	/**
	 * Gives the days a mark lasts.
	 *
	 * @return Int The days.
	 */
	public function markLastsCount(): Int {
		return $this->lifeInDays;
	}

	/**
	 * Encodes bytes as base64url, without padding.
	 *
	 * @param String $usrBinaryTxt The bytes.
	 * @return String The encoded text.
	 */
	protected function base64UrlNoPad( String $usrBinaryTxt ): String {
		return rtrim( strtr(base64_encode($usrBinaryTxt), '+/', '-_'), '=' );
	}
}
