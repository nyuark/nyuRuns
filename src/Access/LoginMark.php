<?php

namespace nyu\Access;

/**
 * The mark a client gets once it has logged in as a user, and sends back
 * at its next logins ('lgmark': the X-Lgmark header, or the lgmark
 * cookie). It names the user, the time it was made and an identifier of
 * its own, signed with the key of the limits block ('markSecr') together
 * with the hash of the user's password; it holds no secret of the user,
 * nor any part of that hash. A client presenting a valid mark for the user
 * it logs in as is not stopped by the hold of the account: the stores count
 * its failures on the mark instead (see Limiter::countLimitUser), so that
 * guesses from elsewhere cannot keep the owner out. A mark ends after
 * 'markDays' days, with any change of the password's hash (a new password,
 * a reset, a hash made again at another cost), or with its key.
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
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$limitCfg = ($nyuConfg!==null) ? $nyuConfg->obtainRowValue('ACCESS', 'TRYLIMIT') : null;
		return new self( is_array($limitCfg) ? $limitCfg : Array() );
	}

	/**
	 * Makes the mark of a user that has just logged in.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $userPassHash Hash of the user's password, as the store keeps it: the mark is signed with it, and ends when it changes.
	 * @return String The mark, or '' when no key of KEYSSIZE characters at least is set, or no hash is given.
	 */
	public function createUserMark( String $namedUserTxt, String $userPassHash ): String {
		if( strlen($this->markSecret)<self::KEYS_SIZE || $namedUserTxt==='' || $userPassHash==='' ){
			return '';
		}
		$markText = $this->base64UrlNoPad($namedUserTxt).'.'.time().'.'.bin2hex(random_bytes(8));
		return $markText.'.'.$this->base64UrlNoPad( hash_hmac('sha256', $markText.'.'.$userPassHash, $this->markSecret, true) );
	}

	/**
	 * Reads a mark a client sent: signed with the key and the hash the
	 * password has now, made for the user it logs in as, within its
	 * lifetime. A password set after the mark was made changes the hash,
	 * so the mark is no longer one.
	 *
	 * @param String $sentMarkText The mark, as sent.
	 * @param String $namedUserTxt Login name of the user logging in.
	 * @param String $userPassHash Hash of the user's password, as the store keeps it now.
	 * @return String The key its failures are counted under ('mark:' and its identifier), or '' when it is not a valid mark for that user.
	 */
	public function readClientMark( String $sentMarkText, String $namedUserTxt, String $userPassHash ): String {
		$markPart = explode('.', $sentMarkText);
		if( strlen($this->markSecret)<self::KEYS_SIZE || $userPassHash==='' || count($markPart)!==4 || !preg_match('/^\d{1,12}$/D', $markPart[1]) || !preg_match('/^[0-9a-f]{16}$/D', $markPart[2]) ){
			return '';
		}
		$signText = $this->base64UrlNoPad( hash_hmac('sha256', $markPart[0].'.'.$markPart[1].'.'.$markPart[2].'.'.$userPassHash, $this->markSecret, true) );
		if( !hash_equals($signText, $markPart[3]) || $markPart[0]!==$this->base64UrlNoPad($namedUserTxt) ){
			return '';
		}
		$madeUnix = intval($markPart[1]);
		if( $madeUnix>time() + 60 || $madeUnix + 86400*$this->lifeInDays<time() ){
			return '';
		}
		return 'mark:'.$markPart[2];
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
