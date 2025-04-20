<?php

namespace nyu\Access\Auth;

use nyu\Access\Totp;
use nyu\Data\DBCon;
use nyu\Data\DBHndlr\Handler as DbHandlr;

/**
 * Verifies credentials against the native userbase: a database table
 * of users holding a bcrypt password hash, a role and a status. Also
 * tracks presence: the time each user was last seen, from which who is
 * online is worked out.
 */
class Native implements Handler{

	// Statuses a user can have; only an active user may log in
	const STATLIST = Array('pendng', 'active', 'locked');
	// Seconds during which a user already seen is not stamped again
	const STAMPGAP = 60;

	protected $userBase;
	protected $usrTable;
	protected $idleSecs;
	protected $maxTries;
	protected $holdSecs;
	protected $lastMssg;
	protected $wrongCrd = false;

	/**
	 * @param Array $confValues Config block, optionally with the 'basnme' configured database holding the users
	 *                          (defaults to the default database), their 'tblnme' table (defaults to 'users'),
	 *                          the 'idletm' time in seconds after which a user is no longer online (defaults to 300),
	 *                          the failed tries allowed ('maxtry', defaults to 5) and the time in seconds they hold the
	 *                          account for ('holdtm', defaults to 600).
	 */
	function __construct( Array $confValues ){
		$this->userBase = is_scalar($confValues['basnme'] ?? '') ? strval($confValues['basnme'] ?? '') : '';
		$tblName = strval($confValues['tblnme'] ?? 'users');
		$this->usrTable = preg_match('/^\w+$/D', $tblName) ? $tblName : '';
		$this->idleSecs = max( 1, intval($confValues['idletm'] ?? 300) );
		$this->maxTries = max( 1, intval($confValues['maxtry'] ?? 5) );
		$this->holdSecs = max( 1, intval($confValues['holdtm'] ?? 600) );
		$this->lastMssg = '';
	}

	/**
	 * Verifies a username/password pair against the users table. The
	 * password must match the stored bcrypt hash and the user must be
	 * 'active'; a user with a 2FA secret must also give the current code
	 * of its authenticator. After too many wrong passwords or codes, the
	 * user is refused for the hold time, whatever it gives: the reply is
	 * the one of a wrong password, and the hold is told in the log only. A 'key' entry
	 * is checked as a personal API token instead.
	 *
	 * @param Array $credsArray Must contain 'user' and 'pass' entries (and 'code' for a 2FA user), or a 'key' entry.
	 * @return Array|Bool An identity array ('user', 'role', 'id') on success, or false on failure.
	 */
	function checkCred( Array $credsArray ): Array|Bool {
		$this->wrongCrd = false;
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
		if( $usrVals===null ){
			// Unknown user : a hash is still computed, at the cost PHP gives new hashes, so the reply time does not tell which names exist
			password_hash( $gvnPass, PASSWORD_BCRYPT );
			$matched = false;
		}else{
			$matched = password_verify( $gvnPass, strval($usrVals['passHash'] ?? '') );
		}
		// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
		if( is_array($usrVals) && $this->isHeldNow($usrVals) ){
			$logInst = $GLOBALS['nyuLogger'] ?? null;
			if( $logInst!==null ){
				$logInst->warning( 'Credential refused: user '.json_encode($usrVals['userName']).' is held after too many attempts' );
			}
			$this->lastMssg = 'Invalid credentials';
			$this->wrongCrd = true;
			return false;
		}
		if( $usrVals===null || !$matched ){
			if( $usrVals!==null ){
				$this->countFail( strval($usrVals['userName']) );
			}
			$this->lastMssg = 'Invalid credentials';
			$this->wrongCrd = true;
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
				$this->wrongCrd = true;
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
			$qtdName = $this->quoteFlds($dbHandl);
			$nowTime = time();
			// failNmbr is set first : it must read the previous failDate
			$updQury = "UPDATE {$qtdName['usrTable']} SET {$qtdName['failNmbr']}=CASE WHEN {$qtdName['failDate']} IS NULL OR {$qtdName['failDate']}<='".date('Y-m-d H:i:s', $nowTime - $this->holdSecs)."' THEN 1 ELSE {$qtdName['failNmbr']}+1 END,"
				." {$qtdName['failDate']}='".date('Y-m-d H:i:s', $nowTime)."' WHERE {$qtdName['userName']}=?;";
			$dbHandl->sendQuery($updQury, true, Array($userString));
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
			$qtdName = $this->quoteFlds($dbHandl);
			$dbHandl->sendQuery( "UPDATE {$qtdName['usrTable']} SET {$qtdName['failNmbr']}=0, {$qtdName['failDate']}=NULL WHERE {$qtdName['userName']}=?;", true, Array($userString) );
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
			$qtdName = $this->quoteFlds($dbHandl);
			$newScrt = Totp::makeSecrt();
			$updQury = "UPDATE {$qtdName['usrTable']} SET {$qtdName['twoFaKey']}='".$newScrt."'"
				." WHERE {$qtdName['userName']}=? AND {$qtdName['userStat']}='active';";
			return ( $dbHandl->sendQuery($updQury, true, Array($userString)) && $dbHandl->lastCount()>0 ) ? $newScrt : false;
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
			$this->wrongCrd = true;
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
			$qtdName = $this->quoteFlds($dbHandl);
			$newTokn = bin2hex(random_bytes(32));
			$updQury = "UPDATE {$qtdName['usrTable']} SET {$qtdName['tokenKey']}='".hash('sha256', $newTokn)."'"
				." WHERE {$qtdName['userName']}=? AND {$qtdName['userStat']}='active';";
			return ( $dbHandl->sendQuery($updQury, true, Array($userString)) && $dbHandl->lastCount()>0 ) ? $newTokn : false;
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
		$this->wrongCrd = false;
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
			$qtdName = $this->quoteFlds($dbHandl);
			$nowTime = time();
			$updQury = "UPDATE {$qtdName['usrTable']} SET {$qtdName['lastSeen']}='".date('Y-m-d H:i:s', $nowTime)."'"
				." WHERE {$qtdName['userName']}=? AND {$qtdName['userStat']}='active'"
				." AND ({$qtdName['lastSeen']} IS NULL OR {$qtdName['lastSeen']}<'".date('Y-m-d H:i:s', $nowTime - self::STAMPGAP)."');";
			return $dbHandl->sendQuery($updQury, true, Array($userString)) && $dbHandl->lastCount()>0;
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
			$qtdName = $this->quoteFlds($dbHandl);
			$lstQury = "SELECT {$qtdName['userName']} FROM {$qtdName['usrTable']} WHERE {$qtdName['userStat']}='active'"
				." AND {$qtdName['lastSeen']}>='".date('Y-m-d H:i:s', time() - $this->idleSecs)."' ORDER BY {$qtdName['userName']};";
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
			$qtdName = $this->quoteFlds($dbHandl);
			$usrQury = "SELECT * FROM {$qtdName['usrTable']} WHERE {$qtdName[$fieldNamed]}=? LIMIT 1;";
			if( !$dbHandl->sendQuery($usrQury, false, Array($lookupText)) ){
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
	 * Quotes the users table and the fields named in the queries, the way
	 * the database in use expects them.
	 *
	 * @param DbHandlr $handlerObj The database handler.
	 * @return Array The quoted names, keyed by plain name; the table is under 'usrTable'.
	 */
	protected function quoteFlds( DbHandlr $handlerObj ): Array {
		$qtdName = Array('usrTable'=>$handlerObj->quoteName($this->usrTable));
		foreach( Array('userName', 'userStat', 'tokenKey', 'twoFaKey', 'failNmbr', 'failDate', 'lastSeen') as $fldNme ){
			$qtdName[$fldNme] = $handlerObj->quoteName($fldNme);
		}
		return $qtdName;
	}

	/**
	 * Returns the handler of the database holding the users: the one
	 * named by 'basnme', or the default database.
	 *
	 * @return DbHandlr The database handler.
	 *
	 * @throws \RuntimeException When that database is not configured.
	 */
	protected function linkDBase(): DbHandlr {
		return DBCon::linkNamed($this->userBase)->getConnex();
	}

	/**
	 * Tells whether the last refusal was a wrong credential, as opposed to
	 * a store that cannot be read, an account not active or a step still
	 * to take: only a wrong credential counts against the address that
	 * presented it (see Access\Limiter).
	 *
	 * @return Bool True when a credential was presented and refused.
	 */
	public function wrongCred(): Bool {
		return $this->wrongCrd;
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
