<?php

namespace nyu\Access\Auth;

use nyu\Access\Totp;
use nyu\Data\DBCon;
use nyu\Data\DBHs\Handler as DbHandlr;

/**
 * Verifies credentials against the native userbase: a database table
 * of users holding a bcrypt password hash, a role and a status. Also
 * tracks presence: the time each user was last seen, from which who is
 * online is worked out.
 */
class Native implements Handler{

	// Bcrypt hash of a discarded random string, checked when the user is unknown
	const FAKEHASH = '$2y$10$vRez8inUYw3A1ZAIcgzLduI5/8q6rGAJtWDgKI6oWkhBpt58FBnw.';
	// Statuses a user can have; only an active user may log in
	const STATLIST = Array('pendng', 'active', 'locked');
	// Seconds during which a user already seen is not stamped again
	const STAMPGAP = 60;

	protected $usrTable;
	protected $idleSecs;
	protected $maxTries;
	protected $holdSecs;
	protected $lastMssg;

	/**
	 * @param Array $confValues Config block, optionally with the 'tblnme' table holding the users (defaults to 'users'),
	 *                          the 'idletm' time in seconds after which a user is no longer online (defaults to 300),
	 *                          the failed tries allowed ('maxtry', defaults to 3) and the time in seconds they hold the
	 *                          account for ('holdtm', defaults to 600).
	 */
	function __construct( Array $confValues ){
		$tblName = strval($confValues['tblnme'] ?? 'users');
		$this->usrTable = preg_match('/^\w+$/', $tblName) ? $tblName : '';
		$this->idleSecs = max( 1, intval($confValues['idletm'] ?? 300) );
		$this->maxTries = max( 1, intval($confValues['maxtry'] ?? 3) );
		$this->holdSecs = max( 1, intval($confValues['holdtm'] ?? 600) );
		$this->lastMssg = '';
	}

	/**
	 * Verifies a username/password pair against the users table. The
	 * password must match the stored bcrypt hash and the user must be
	 * 'active'; a user with a 2FA secret must also give the current code
	 * of its authenticator. After too many wrong passwords or codes, the
	 * user is refused for the hold time, whatever it gives. A 'key' entry
	 * is checked as a personal API token instead.
	 *
	 * @param Array $credsArray Must contain 'user' and 'pass' entries (and 'code' for a 2FA user), or a 'key' entry.
	 * @return Array|Bool An identity array ('user', 'role', 'id') on success, or false on failure.
	 */
	function checkCred( Array $credsArray ): Array|Bool {
		if( isset($credsArray['key']) ){
			return $this->checkTokn( strval($credsArray['key']) );
		}
		$gvnUser = strval($credsArray['user'] ?? '');
		$gvnPass = strval($credsArray['pass'] ?? '');
		$usrVals = ($gvnUser!='') ? $this->fetchUser($gvnUser) : null;
		if( $usrVals===false ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		// Too many failed attempts : nothing is checked or counted until the hold is over
		if( is_array($usrVals) && $this->isHeldNow($usrVals) ){
			$this->lastMssg = 'Too many attempts';
			return false;
		}
		// Unknown user : a hash is still checked, so the reply time does not tell which names exist
		$matched = password_verify( $gvnPass, strval($usrVals['passHash'] ?? self::FAKEHASH) );
		if( $usrVals===null || !$matched ){
			if( $usrVals!==null ){
				$this->countFail( strval($usrVals['userName']) );
			}
			$this->lastMssg = 'Invalid credentials';
			return false;
		}
		if( ($usrVals['userStat'] ?? '')!=='active' ){
			$this->lastMssg = 'Account not active';
			return false;
		}
		// A user with a 2FA secret must also give the current code of its authenticator
		$twoFaKy = strval($usrVals['twoFaKey'] ?? '');
		if( $twoFaKy!='' ){
			$gvnCode = strval($credsArray['code'] ?? '');
			if( $gvnCode=='' ){
				// Not a failed attempt : the password was right, the client now asks for the code
				$this->lastMssg = 'Verification code required';
				return false;
			}
			if( !Totp::checkCode($twoFaKy, $gvnCode) ){
				$this->countFail( strval($usrVals['userName']) );
				$this->lastMssg = 'Invalid verification code';
				return false;
			}
		}
		if( intval($usrVals['failNmbr'] ?? 0)>0 ){
			$this->clearFail( strval($usrVals['userName']) );
		}
		return Array('user'=>$usrVals['userName'], 'role'=>strval($usrVals['userRole'] ?? ''), 'id'=>$usrVals['id_user'] ?? null);
	}

	/**
	 * Tells whether a user is held back after too many failed attempts:
	 * as many failures as allowed, the last one within the hold time.
	 *
	 * @param Array $userValues The user's row.
	 * @return Bool Whether attempts are refused for now.
	 */
	protected function isHeldNow( Array $userValues ): Bool {
		$failDte = strval($userValues['failDate'] ?? '');
		return intval($userValues['failNmbr'] ?? 0)>=$this->maxTries
			&& $failDte!='' && $failDte>date('Y-m-d H:i:s', time() - $this->holdSecs);
	}

	/**
	 * Counts one more failed attempt for a user. The count restarts at 1
	 * when the previous failure is older than the hold time.
	 *
	 * @param String $userString Login name of the user.
	 */
	protected function countFail( String $userString ): Void {
		try{
			$dbHandl = $this->linkDBase();
			$nowTime = time();
			// failNmbr is set first : it must read the previous failDate
			$updQury = "UPDATE `$this->usrTable` SET `failNmbr`=CASE WHEN `failDate` IS NULL OR `failDate`<='".date('Y-m-d H:i:s', $nowTime - $this->holdSecs)."' THEN 1 ELSE `failNmbr`+1 END,"
				." `failDate`='".date('Y-m-d H:i:s', $nowTime)."' WHERE `userName`='".$dbHandl->escapeStr($userString)."';";
			$dbHandl->sendQuery($updQury, true);
		}catch( \Throwable $e ){
			// A count that cannot be written does not change the answer given to the client
		}
	}

	/**
	 * Forgets the failed attempts of a user, after a successful check.
	 *
	 * @param String $userString Login name of the user.
	 */
	protected function clearFail( String $userString ): Void {
		try{
			$dbHandl = $this->linkDBase();
			$dbHandl->sendQuery( "UPDATE `$this->usrTable` SET `failNmbr`=0, `failDate`=NULL WHERE `userName`='".$dbHandl->escapeStr($userString)."';", true );
		}catch( \Throwable $e ){
			// A count that cannot be written does not change the answer given to the client
		}
	}

	/**
	 * Gives an active user a new 2FA secret, replacing any previous one.
	 * From then on its password must come with the code of an
	 * authenticator set up with that secret.
	 *
	 * @param String $userString Login name of the user.
	 * @return String|Bool The new base32 secret, or false when the user is unknown, not active, or the table cannot be written.
	 */
	public function makeTwoFa( String $userString ): String|Bool {
		if( $this->usrTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$newScrt = Totp::makeSecrt();
			$updQury = "UPDATE `$this->usrTable` SET `twoFaKey`='".$newScrt."'"
				." WHERE `userName`='".$dbHandl->escapeStr($userString)."' AND `userStat`='active';";
			return $dbHandl->sendQuery($updQury, true) ? $newScrt : false;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Verifies a personal API token: its SHA-256 hash must be the
	 * tokenKey of an active user.
	 *
	 * @param String $tokenValue The token, as sent by the client.
	 * @return Array|Bool The identity array ('user', 'role', 'id'), or false on failure.
	 */
	protected function checkTokn( String $tokenValue ): Array|Bool {
		if( $tokenValue=='' ){
			$this->lastMssg = 'No API key provided';
			return false;
		}
		$usrVals = $this->fetchUser( hash('sha256', $tokenValue), 'tokenKey' );
		if( $usrVals===false ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		if( $usrVals===null || ($usrVals['userStat'] ?? '')!=='active' ){
			$this->lastMssg = 'Invalid API key';
			return false;
		}
		return Array('user'=>$usrVals['userName'], 'role'=>strval($usrVals['userRole'] ?? ''), 'id'=>$usrVals['id_user'] ?? null);
	}

	/**
	 * Gives an active user a new personal API token, replacing any
	 * previous one. Only its hash is stored: the token is returned once.
	 *
	 * @param String $userString Login name of the user.
	 * @return String|Bool The new token, or false when the user is unknown, not active, or the table cannot be written.
	 */
	public function makeToken( String $userString ): String|Bool {
		if( $this->usrTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$newTokn = bin2hex(random_bytes(32));
			$updQury = "UPDATE `$this->usrTable` SET `tokenKey`='".hash('sha256', $newTokn)."'"
				." WHERE `userName`='".$dbHandl->escapeStr($userString)."' AND `userStat`='active';";
			return $dbHandl->sendQuery($updQury, true) ? $newTokn : false;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Reads the current identity of a user authenticated by other means
	 * (a token), so that a lock or a role change applies at once.
	 *
	 * @param String $userString Login name of the user.
	 * @return Array|Bool The identity array ('user', 'role', 'id'), or false when the user is unknown, not active, or the table cannot be read.
	 */
	public function userIdent( String $userString ): Array|Bool {
		$usrVals = ($userString!='') ? $this->fetchUser($userString) : null;
		if( $usrVals===false ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		if( $usrVals===null || ($usrVals['userStat'] ?? '')!=='active' ){
			$this->lastMssg = 'Account not active';
			return false;
		}
		return Array('user'=>$usrVals['userName'], 'role'=>strval($usrVals['userRole'] ?? ''), 'id'=>$usrVals['id_user'] ?? null);
	}

	/**
	 * Stamps the time an active user was last seen. Meant to be called
	 * on every authenticated request: the row is only written when its
	 * stamp is older than STAMPGAP seconds.
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the stamp was written.
	 */
	public function keepAlive( String $userString ): Bool {
		if( $this->usrTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$nowTime = time();
			$updQury = "UPDATE `$this->usrTable` SET `lastSeen`='".date('Y-m-d H:i:s', $nowTime)."'"
				." WHERE `userName`='".$dbHandl->escapeStr($userString)."' AND `userStat`='active'"
				." AND (`lastSeen` IS NULL OR `lastSeen`<'".date('Y-m-d H:i:s', $nowTime - self::STAMPGAP)."');";
			return $dbHandl->sendQuery($updQury, true);
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Lists the users who are online: the active users seen within the
	 * configured idle time.
	 *
	 * @return Array|Bool The login names, in alphabetical order, or false when the table cannot be read.
	 */
	public function onlineNow(): Array|Bool {
		if( $this->usrTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$lstQury = "SELECT `userName` FROM `$this->usrTable` WHERE `userStat`='active'"
				." AND `lastSeen`>='".date('Y-m-d H:i:s', time() - $this->idleSecs)."' ORDER BY `userName`;";
			if( !$dbHandl->sendQuery($lstQury) ){
				return false;
			}
			$usrRows = $dbHandl->readQuery(true);
		}catch( \Throwable $e ){
			return false;
		}
		return is_array($usrRows) ? array_column($usrRows, 'userName') : false;
	}

	/**
	 * Reads a user's row by login name, or by the hash of its API token.
	 *
	 * @param String $lookupText Value to look up.
	 * @param String $fieldNamed Field it is looked up in: 'userName' or 'tokenKey'.
	 * @return Array|Bool|Null The user's row, null when there is none, or false when the table cannot be read.
	 */
	protected function fetchUser( String $lookupText, String $fieldNamed = 'userName' ): Array|Bool|Null {
		if( $this->usrTable=='' || !in_array($fieldNamed, Array('userName', 'tokenKey'), true) ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$usrQury = "SELECT * FROM `$this->usrTable` WHERE `$fieldNamed`='".$dbHandl->escapeStr($lookupText)."' LIMIT 1;";
			if( !$dbHandl->sendQuery($usrQury) ){
				return false;
			}
			$usrRows = $dbHandl->readQuery(true);
		}catch( \Throwable $e ){
			return false;
		}
		if( !is_array($usrRows) ){
			return false;
		}
		return empty($usrRows) ? null : $usrRows[0];
	}

	/**
	 * Returns the handler of the shared database connection.
	 *
	 * @return DbHandlr The active database handler.
	 */
	protected function linkDBase(): DbHandlr {
		$nyuBase = $GLOBALS['nyuConfig']->readValue('database');
		return DBCon::getInstnc($nyuBase['TYPE'], $nyuBase['HOST'], $nyuBase['USER'], $nyuBase['PASS'], $nyuBase['BASE'])->getConnex();
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
