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

	// Statuses a user can have; only an active user may log in. 'review' is a self-registration awaiting a manager
	const STAT_LIST = Array('pendng', 'review', 'active', 'locked');
	// Seconds during which a user already seen is not stamped again
	const STMP_SECS = 60;
	// Longest password, in bytes : bcrypt reads nothing beyond
	const PASS_SIZE = 72;
	// The characters of a code : no 0, 1, I, L or O, which are taken for one another
	const CODE_CHRS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	// Minutes a code sent by mail lasts, unless 'codeMins' says otherwise
	const MAIL_MINS = 15;
	// Seconds before a login asking again for a mailed code is sent a new one
	const MAIL_SECS = 60;

	// A bcrypt hash of nothing, made once at the cost of new hashes : what an unknown name or an account without a password is checked against
	protected static $fakeBcrypt = null;

	protected $usersStore;
	protected $usersTable;
	protected $idlePeriod;
	protected $triesLimit;
	protected $holdPeriod;
	protected $minPasswrd;
	protected $sealingKey;
	protected $mailExpiry;
	protected $mrkLimiter;
	protected $lastMessag;
	protected $invalidCrd = false;

	/**
	 * Builds the check against the native userbase from its config block.
	 *
	 * @param Array $configValues Config block, optionally with the 'linkName' configured database holding the users
	 *                          (defaults to the default database), their 'useTable' table (defaults to 'users'),
	 *                          the 'idleSecs' time in seconds after which a user is no longer online (defaults to 300),
	 *                          the failed tries allowed ('maxTries', defaults to 5), the time in seconds they hold the
	 *                          account for ('holdSecs', defaults to 600), the shortest password accepted, in
	 *                          characters ('minChars', defaults to 10) and the key the 2FA secrets are stored encrypted
	 *                          with ('cryptKey', 32 characters at least; without one they are stored readable), and the
	 *                          minutes a code sent by mail lasts ('codeMins', defaults to MAILMINS).
	 */
	function __construct( Array $configValues ){
		$this->usersStore = is_scalar($configValues['linkName'] ?? '') ? strval($configValues['linkName'] ?? '') : '';
		$tblName = strval($configValues['useTable'] ?? 'users');
		$this->usersTable = preg_match('/^\w+$/D', $tblName) ? $tblName : '';
		$this->idlePeriod = max( 1, intval($configValues['idleSecs'] ?? 300) );
		$this->triesLimit = max( 1, intval($configValues['maxTries'] ?? 5) );
		$this->holdPeriod = max( 1, intval($configValues['holdSecs'] ?? 600) );
		$this->minPasswrd = min( self::PASS_SIZE, max(1, intval($configValues['minChars'] ?? 10)) );
		$this->sealingKey = is_scalar($configValues['cryptKey'] ?? '') ? strval($configValues['cryptKey'] ?? '') : '';
		$this->mailExpiry = max( 1, intval($configValues['codeMins'] ?? self::MAIL_MINS) );
		// The failures of a client presenting a login mark are counted on the mark, in the folder of the limits
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'TRYLIMIT') : null;
		$this->mrkLimiter = new \nyu\Access\Limiter( Array('maxTries'=>$this->triesLimit, 'holdSecs'=>$this->holdPeriod, 'dataPath'=>is_array($lmtConf) ? strval($lmtConf['dataPath'] ?? '') : '') );
		$this->lastMessag = '';
	}

	/**
	 * Verifies a username/password pair against the users table. The
	 * password must match the stored bcrypt hash and the user must be
	 * 'active' (one that is not is refused as a wrong password is, and
	 * the reason logged); a user with a 2FA secret must also give the current code
	 * of its authenticator, and a user with mailed codes ('twoFaWay') the code
	 * sent to its address: asked without one, a code is mailed (see mailsCode)
	 * when the credentials hold a 'code' entry, even empty, as the login's do.
	 * After too many wrong passwords or codes, the
	 * user is refused for the hold time, whatever it gives: the reply is
	 * the one of a wrong password, and the hold is told in the log only. A
	 * client presenting a valid login mark of the user ('mark', made before
	 * the password was last set is not one) is not stopped by that hold:
	 * its failures are counted on the mark, which is refused in its turn
	 * after as many (see Access\LoginMark). A 'key' entry is checked as a
	 * personal API token instead.
	 *
	 * @param Array $crdntlsArray Must contain 'user' and 'pass' entries (and 'code' for a 2FA user, 'mark' for a client already used), or a 'key' entry.
	 * @return Array|Bool An identity array ('user', 'role', 'id') on success, or false on failure.
	 */
	function chckCredential( Array $crdntlsArray ): Array|Bool {
		$this->invalidCrd = false;
		if( isset($crdntlsArray['key']) ){
			return $this->checkTokenList( strval($crdntlsArray['key']) );
		}
		$gvnUser = strval($crdntlsArray['user'] ?? '');
		$gvnPass = strval($crdntlsArray['pass'] ?? '');
		$userVls = ($gvnUser!='') ? $this->fetchUserArray($gvnUser) : null;
		if( $userVls===false ){
			$this->lastMessag = 'Credential store unavailable';
			return false;
		}
		$rowHash = is_array($userVls) ? strval($userVls['passHash'] ?? '') : '';
		if( $userVls===null || password_get_info($rowHash)['algo']===null ){
			// Unknown user, or an account without a password yet : the same check is run, so the reply time tells neither apart from a wrong password
			password_verify( $gvnPass, self::getDummyBcrypt() );
			$matched = false;
		}else{
			$matched = password_verify( $gvnPass, $rowHash );
		}
		// A client already used by the user : its mark, while it has not failed too often itself
		$markKey = '';
		if( is_array($userVls) && is_string($crdntlsArray['mark'] ?? null) && $crdntlsArray['mark']!=='' ){
			$pssUnix = (strval($userVls['passDate'] ?? '')!='') ? intval(strtotime(strval($userVls['passDate']))) : null;
			$markKey = \nyu\Access\LoginMark::fromLimitBlock()->readClientMark( $crdntlsArray['mark'], strval($userVls['userName']), $pssUnix );
			if( $markKey!='' && $this->mrkLimiter->heldByUserNumb($markKey)>0 ){
				\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Login mark refused for user '.json_encode($userVls['userName']).': too many attempts with it' );
				$markKey = '';
			}
		}
		// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
		if( is_array($userVls) && $markKey=='' && $this->hasHeldNowFlag($userVls) ){
			\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($userVls['userName']).' is held after too many attempts' );
			$this->lastMessag = 'Invalid credentials';
			$this->invalidCrd = true;
			return false;
		}
		if( $userVls===null || !$matched ){
			if( $userVls!==null ){
				($markKey!='') ? $this->mrkLimiter->countLimitUser($markKey) : $this->countFailedTry( strval($userVls['userName']) );
			}
			$this->lastMessag = 'Invalid credentials';
			$this->invalidCrd = true;
			return false;
		}
		if( ($userVls['userStat'] ?? '')!=='active' ){
			// The reply is the one of a wrong password : the status of a user is told in the log only
			\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($userVls['userName']).' is not active ('.strval($userVls['userStat'] ?? '').')' );
			$this->lastMessag = 'Invalid credentials';
			$this->invalidCrd = true;
			return false;
		}
		// A user with mailed codes gives the code sent to its address : asked without one, a code is mailed
		$sealKey = strval($userVls['twoFaKey'] ?? '');
		if( ($userVls['twoFaWay'] ?? null)==='mailed' ){
			$gvnCode = strval($crdntlsArray['code'] ?? '');
			if( $gvnCode=='' ){
				// Only a caller that can carry a code (the login) has one mailed : a Basic header cannot
				if( array_key_exists('code', $crdntlsArray) && !$this->sendCodeByMail($userVls) ){
					$this->lastMessag = 'Credential store unavailable';
					return false;
				}
				// Not a failed attempt : the password was right, the client now asks for the code
				$this->lastMessag = 'Verification code required';
				return false;
			}
			$codeHit = $this->compareMailFlg( $userVls, $gvnCode );
			if( $codeHit===null ){
				$this->lastMessag = 'Credential store unavailable';
				return false;
			}
			if( !$codeHit ){
				\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Verification code refused: wrong or ended for user '.json_encode($userVls['userName']) );
				($markKey!='') ? $this->mrkLimiter->countLimitUser($markKey) : $this->countFailedTry( strval($userVls['userName']) );
				$this->lastMessag = 'Invalid verification code';
				$this->invalidCrd = true;
				return false;
			}
		}elseif( $sealKey!='' ){
			// A user with a 2FA secret must also give the current code of its authenticator
			// A secret stored encrypted is read with the key of the config
			$sealKey = Totp::openSecretText($sealKey, $this->sealingKey);
			if( $sealKey===null ){
				\nyu\Tools\Logger::retrieveLogger('access')->error( '2FA secret of user '.json_encode($userVls['userName']).' cannot be read: cryptKey is missing or was changed' );
				$this->lastMessag = 'Credential store unavailable';
				return false;
			}
			$gvnCode = strval($crdntlsArray['code'] ?? '');
			if( $gvnCode=='' ){
				// Not a failed attempt : the password was right, the client now asks for the code
				$this->lastMessag = 'Verification code required';
				return false;
			}
			$stepNum = Totp::matchStepCount($sealKey, $gvnCode);
			// A code is accepted once : its step, and those before it, are closed
			$claimed = ($stepNum!==null) ? $this->claimTwoFaStep( strval($userVls['userName']), $stepNum ) : false;
			if( $claimed===null ){
				$this->lastMessag = 'Credential store unavailable';
				return false;
			}
			if( !$claimed ){
				if( $stepNum!==null ){
					\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Verification code refused: already used by user '.json_encode($userVls['userName']) );
				}
				($markKey!='') ? $this->mrkLimiter->countLimitUser($markKey) : $this->countFailedTry( strval($userVls['userName']) );
				$this->lastMessag = 'Invalid verification code';
				$this->invalidCrd = true;
				return false;
			}
		}
		if( intval($userVls['failNmbr'] ?? 0)>0 ){
			$this->clearFailedTry( strval($userVls['userName']) );
		}
		if( $markKey!='' ){
			$this->mrkLimiter->clearLimitUser($markKey);
		}
		// A hash made at a lower cost than new ones is made again, now that the password is known
		if( password_needs_rehash($rowHash, PASSWORD_BCRYPT) ){
			$this->rehashPassword( strval($userVls['userName']), $gvnPass );
		}
		return Array('user'=>$userVls['userName'], 'role'=>strval($userVls['userRole'] ?? ''), 'id'=>$userVls['id_user'] ?? null);
	}

	/**
	 * Gives a bcrypt hash of nothing, made once per process at the cost PHP
	 * gives new hashes: the stores check an unknown name, or an account
	 * without a password, against it, so the reply time tells neither apart
	 * from a wrong password.
	 *
	 * @return String The hash.
	 */
	public static function getDummyBcrypt(): String {
		if( self::$fakeBcrypt===null ){
			self::$fakeBcrypt = password_hash( bin2hex(random_bytes(16)), PASSWORD_BCRYPT );
		}
		return self::$fakeBcrypt;
	}

	/**
	 * Writes the hash of a password again, at the cost PHP gives new hashes,
	 * after a login gave it right. Nothing else changes: it is the same
	 * password, so the sessions and tokens issued before stay valid.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The password, as given at the login.
	 */
	protected function rehashPassword( String $namedUserTxt, String $usrPasswdTxt ): Void {
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$dbHndlr->sendBuiltQuery( "UPDATE {$qtdName['usrTable']} SET {$qtdName['passHash']}=? WHERE {$qtdName['userName']}=?;", true, Array(password_hash($usrPasswdTxt, PASSWORD_BCRYPT), $namedUserTxt) );
		}catch( \Throwable $e ){
			// A hash that cannot be written again leaves the former one, which still works
		}
	}

	/**
	 * Tells whether a user is held back after too many failed attempts:
	 * as many failures as allowed, the last one within the hold time.
	 *
	 * @param Array $usrValuesArr The user's row.
	 * @return Bool Whether attempts are refused for now.
	 */
	protected function hasHeldNowFlag( Array $usrValuesArr ): Bool {
		$falDate = strval($usrValuesArr['failDate'] ?? '');
		return intval($usrValuesArr['failNmbr'] ?? 0)>=$this->triesLimit
			&& $falDate!='' && $falDate>date('Y-m-d H:i:s', time() - $this->holdPeriod);
	}

	/**
	 * Counts one more failed attempt for a user. The count restarts at 1
	 * when the previous failure is older than the hold time.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 */
	protected function countFailedTry( String $namedUserTxt ): Void {
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$nowTime = time();
			// failNmbr is set first : it must read the previous failDate
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['failNmbr']}=CASE WHEN {$qtdName['failDate']} IS NULL OR {$qtdName['failDate']}<='".date('Y-m-d H:i:s', $nowTime - $this->holdPeriod)."' THEN 1 ELSE {$qtdName['failNmbr']}+1 END,"
				." {$qtdName['failDate']}='".date('Y-m-d H:i:s', $nowTime)."' WHERE {$qtdName['userName']}=?;";
			$dbHndlr->sendBuiltQuery($updtQry, true, Array($namedUserTxt));
		}catch( \Throwable $e ){
			// A count that cannot be written does not change the answer given to the client
		}
	}

	/**
	 * Forgets the failed attempts of a user, after a successful check.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 */
	protected function clearFailedTry( String $namedUserTxt ): Void {
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$dbHndlr->sendBuiltQuery( "UPDATE {$qtdName['usrTable']} SET {$qtdName['failNmbr']}=0, {$qtdName['failDate']}=NULL WHERE {$qtdName['userName']}=?;", true, Array($namedUserTxt) );
		}catch( \Throwable $e ){
			// A count that cannot be written does not change the answer given to the client
		}
	}

	/**
	 * Records the step of an accepted 2FA code, so that neither this code
	 * nor an earlier one is accepted again. One write decides: of two
	 * requests giving the same code at once, one is refused.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param Int    $userStepNumb The step of the code (see Totp::matchStepCount).
	 * @return ?Bool True when the step is now the last one accepted, false when it or a later one already was, null when the table cannot be written.
	 */
	protected function claimTwoFaStep( String $namedUserTxt, Int $userStepNumb ): ?Bool {
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['twoFaStp']}=".$userStepNumb
				." WHERE {$qtdName['userName']}=? AND ({$qtdName['twoFaStp']} IS NULL OR {$qtdName['twoFaStp']}<".$userStepNumb.");";
			if( !$dbHndlr->sendBuiltQuery($updtQry, true, Array($namedUserTxt)) ){
				\nyu\Tools\Logger::retrieveLogger('access')->error( 'The step of a 2FA code cannot be recorded: the users table cannot be written, or has no twoFaStp column (see conf/schema)' );
				return null;
			}
			return $dbHndlr->countLastWrite()>0;
		}catch( \Throwable $e ){
			return null;
		}
	}

	/**
	 * Makes a code for the login of a user with mailed codes, stores its
	 * hash with its end ('codeMins' minutes on) and mails it after the reply,
	 * in the language of the user, through the account the block of the
	 * login names (see Access\AccessMail). A code mailed less than MAILWAIT
	 * seconds ago is kept, and nothing is sent: asking again does not flood
	 * the address.
	 *
	 * @param Array $usrValuesArr The user's row.
	 * @return Bool Whether a code is on its way; false when no mailer or no address sends it, or the table cannot be written.
	 */
	protected function sendCodeByMail( Array $usrValuesArr ): Bool {
		$usrName = strval($usrValuesArr['userName'] ?? '');
		$usrMail = strval($usrValuesArr['userMail'] ?? '');
		// After the reply of a request; at once on the command line, which has none
		$mailObj = \nyu\Access\AccessMail::fromLoginBlock( '', PHP_SAPI!=='cli' );
		if( $mailObj===null || $usrMail=='' ){
			\nyu\Tools\Logger::retrieveLogger('access')->error( 'Verification code of user '.json_encode($usrName).' not sent: no mailer or no address' );
			return false;
		}
		$endDate = strval($usrValuesArr['mailDate'] ?? '');
		if( strval($usrValuesArr['mailCode'] ?? '')!='' && $endDate!='' && strtotime($endDate) - 60*$this->mailExpiry > time() - self::MAIL_SECS ){
			return true;
		}
		$newCode = $this->makeAccessCode();
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['mailCode']}=?, {$qtdName['mailDate']}=? WHERE {$qtdName['userName']}=?;";
			if( !$dbHndlr->sendBuiltQuery($updtQry, true, Array($this->codeHashesList($newCode)[0], date('Y-m-d H:i:s', time() + 60*$this->mailExpiry), $usrName)) || $dbHndlr->countLastWrite()<1 ){
				\nyu\Tools\Logger::retrieveLogger('access')->error( 'A mailed code cannot be recorded: the users table cannot be written, or has no mailCode column (see conf/schema)' );
				return false;
			}
		}catch( \Throwable $e ){
			return false;
		}
		$mailObj->sendCodeToUser( 'lgcode', $usrName, $usrMail, $newCode, strval($usrValuesArr['userLang'] ?? '') ?: null );
		\nyu\Tools\Logger::retrieveLogger('access')->info( 'Verification code mailed to user '.json_encode($usrName) );
		return true;
	}

	/**
	 * Checks the code mailed for the login of a user, and clears it: it
	 * is accepted once, before its end. One write decides: of two requests
	 * giving the same code at once, one is refused.
	 *
	 * @param Array  $usrValuesArr The user's row.
	 * @param String $codeAsString The code given, whatever its case and dashes.
	 * @return ?Bool True when the code is the one mailed, false when it is not or has ended, null when the table cannot be written.
	 */
	protected function compareMailFlg( Array $usrValuesArr, String $codeAsString ): ?Bool {
		$rowHash = strval($usrValuesArr['mailCode'] ?? '');
		$endDate = strval($usrValuesArr['mailDate'] ?? '');
		if( $rowHash=='' || $endDate=='' || $endDate<date('Y-m-d H:i:s') ){
			return false;
		}
		$gvnHash = $this->codeHashesList($codeAsString)[0];
		if( !hash_equals($rowHash, $gvnHash) ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['mailCode']}=NULL, {$qtdName['mailDate']}=NULL WHERE {$qtdName['userName']}=? AND {$qtdName['mailCode']}=?;";
			if( !$dbHndlr->sendBuiltQuery($updtQry, true, Array(strval($usrValuesArr['userName'] ?? ''), $rowHash)) ){
				return null;
			}
			return $dbHndlr->countLastWrite()>0;
		}catch( \Throwable $e ){
			return null;
		}
	}

	/**
	 * Makes a new code: 10 characters of CODECHRS, as XXXX-XXXX-XX.
	 *
	 * @return String The code.
	 */
	protected function makeAccessCode(): String {
		$charTxt = '';
		$lastIdx = strlen(self::CODE_CHRS) - 1;
		for( $posInc=0; $posInc<10; $posInc++ ){
			$charTxt .= self::CODE_CHRS[ random_int(0, $lastIdx) ];
		}
		return substr($charTxt, 0, 4).'-'.substr($charTxt, 4, 4).'-'.substr($charTxt, 8);
	}

	/**
	 * Gives the hashes a code given is looked up by: the one of the code
	 * as it is stored (in upper case, without dashes or spaces), and the
	 * one of the code as it is given, for the codes of the earlier versions.
	 *
	 * @param String $codeAsString The code given.
	 * @return Array The two SHA-256 hashes, the stored form first.
	 */
	protected function codeHashesList( String $codeAsString ): Array {
		$cmpCode = strtoupper( preg_replace('/[^A-Za-z0-9]/', '', $codeAsString) );
		return Array( hash('sha256', $cmpCode), hash('sha256', $codeAsString) );
	}

	/**
	 * Gives an active user a new 2FA secret, replacing any previous one.
	 * From then on its password must come with the code of an
	 * authenticator set up with that secret.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool The new base32 secret, or false when the user is unknown, not active, or the table cannot be written.
	 */
	public function buildTwoFactor( String $namedUserTxt ): String|Bool {
		if( $this->usersTable=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$newSecr = Totp::generateSecret();
			$storTxt = Totp::sealSecretText($newSecr, $this->sealingKey);
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['twoFaKey']}=?, {$qtdName['twoFaStp']}=NULL, {$qtdName['twoFaWay']}=NULL, {$qtdName['mailCode']}=NULL, {$qtdName['mailDate']}=NULL"
				." WHERE {$qtdName['userName']}=? AND {$qtdName['userStat']}='active';";
			if( !$dbHndlr->sendBuiltQuery($updtQry, true, Array($storTxt, $namedUserTxt)) || $dbHndlr->countLastWrite()<1 ){
				return false;
			}
			if( $storTxt===$newSecr ){
				\nyu\Tools\Logger::retrieveLogger('access')->warning( '2FA secret of user '.json_encode($namedUserTxt).' stored readable: no cryptKey of '.Totp::SEAL_SIZE.' characters is set' );
			}
			return $newSecr;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Verifies a personal API token: its SHA-256 hash must be the
	 * tokenKey of an active user.
	 *
	 * @param String $toknValueTxt The token, as sent by the client.
	 * @return Array|Bool The identity array ('user', 'role', 'id'), or false on failure.
	 */
	protected function checkTokenList( String $toknValueTxt ): Array|Bool {
		if( $toknValueTxt=='' ){
			$this->lastMessag = 'No API key provided';
			return false;
		}
		$userVls = $this->fetchUserArray( hash('sha256', $toknValueTxt), 'tokenKey' );
		if( $userVls===false ){
			$this->lastMessag = 'Credential store unavailable';
			return false;
		}
		if( $userVls===null || ($userVls['userStat'] ?? '')!=='active' ){
			$this->lastMessag = 'Invalid API key';
			$this->invalidCrd = true;
			return false;
		}
		return Array('user'=>$userVls['userName'], 'role'=>strval($userVls['userRole'] ?? ''), 'id'=>$userVls['id_user'] ?? null);
	}

	/**
	 * Gives an active user a new personal API token, replacing any
	 * previous one. Only its hash is stored: the token is returned once.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool The new token, or false when the user is unknown, not active, or the table cannot be written.
	 */
	public function makeToknString( String $namedUserTxt ): String|Bool {
		if( $this->usersTable=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$newTokn = bin2hex(random_bytes(32));
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['tokenKey']}='".hash('sha256', $newTokn)."'"
				." WHERE {$qtdName['userName']}=? AND {$qtdName['userStat']}='active';";
			return ( $dbHndlr->sendBuiltQuery($updtQry, true, Array($namedUserTxt)) && $dbHndlr->countLastWrite()>0 ) ? $newTokn : false;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Gives a user a new password, replacing any previous one, and forgets
	 * its failed attempts. Only the bcrypt hash is stored, with the time it
	 * was set ('passDate'): the sessions and tokens issued before it are
	 * refused (see userIdent), and the personal token is taken away. The
	 * status of the user is left as it is. The password must follow the
	 * rules (see checkPass); finalErrorText() tells the one it breaks.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The new password, kept as given.
	 * @return Bool Whether the password was written; false when it breaks a rule, the user is unknown, or the table cannot be written.
	 */
	public function savePasswdFlag( String $namedUserTxt, String $usrPasswdTxt ): Bool {
		if( $this->usersTable=='' ){
			return false;
		}
		$ruleMsg = $this->testPassString($namedUserTxt, $usrPasswdTxt);
		if( $ruleMsg!='' ){
			$this->lastMessag = $ruleMsg;
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['passHash']}=?, {$qtdName['passDate']}=?, {$qtdName['tokenKey']}=NULL, {$qtdName['failNmbr']}=0, {$qtdName['failDate']}=NULL WHERE {$qtdName['userName']}=?;";
			if( !$dbHndlr->sendBuiltQuery($updtQry, true, Array(password_hash($usrPasswdTxt, PASSWORD_BCRYPT), date('Y-m-d H:i:s'), $namedUserTxt)) ){
				\nyu\Tools\Logger::retrieveLogger('access')->error( 'A password cannot be written: the users table cannot be written, or has no passDate column (see conf/schema)' );
				return false;
			}
			return $dbHndlr->countLastWrite()>0;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Tells which rule a password breaks: it holds at least the 'minChars'
	 * characters of the config block and at most PASSSIZE bytes, no control
	 * character, and is not the name of its user, whatever the case.
	 * Nothing is asked about the kind of characters it is made of.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The password, as given.
	 * @return String The rule that is broken, empty when the password is accepted.
	 */
	protected function testPassString( String $namedUserTxt, String $usrPasswdTxt ): String {
		// Characters are counted when the password is UTF-8, bytes otherwise
		$charNum = preg_match_all('/./su', $usrPasswdTxt);
		if( ($charNum ?: strlen($usrPasswdTxt))<$this->minPasswrd ){
			return 'Password too short: '.$this->minPasswrd.' characters at least';
		}
		if( strlen($usrPasswdTxt)>self::PASS_SIZE ){
			return 'Password too long: '.self::PASS_SIZE.' bytes at most';
		}
		if( preg_match('/[\x00-\x1F\x7F]/', $usrPasswdTxt) ){
			return 'Password holding a control character';
		}
		if( strcasecmp($usrPasswdTxt, $namedUserTxt)==0 ){
			return 'Password identical to the user name';
		}
		return '';
	}

	/**
	 * Reads the current identity of a user authenticated by other means
	 * (a token, a session), so that a lock or a role change applies at
	 * once. A session or a token issued before the password was last set
	 * ('passDate') is refused: a reset or a change ends them all, the one
	 * that made it included.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param ?Int   $issuedUnixNb Unix time the session or the token was issued at; null when there is none to compare.
	 * @return Array|Bool The identity array ('user', 'role', 'id'), or false when the user is unknown, not active, its password was set since, or the table cannot be read.
	 */
	public function usrIdentityArr( String $namedUserTxt, ?Int $issuedUnixNb=null ): Array|Bool {
		$this->invalidCrd = false;
		$userVls = ($namedUserTxt!='') ? $this->fetchUserArray($namedUserTxt) : null;
		if( $userVls===false ){
			$this->lastMessag = 'Credential store unavailable';
			return false;
		}
		if( $userVls===null || ($userVls['userStat'] ?? '')!=='active' ){
			$this->lastMessag = 'Account not active';
			return false;
		}
		// A password set since the session or the token was issued ends it
		$pssDate = strval($userVls['passDate'] ?? '');
		if( $issuedUnixNb!==null && $pssDate!='' && strtotime($pssDate)>$issuedUnixNb ){
			\nyu\Tools\Logger::retrieveLogger('access')->info( 'Session refused for user '.json_encode($namedUserTxt).': issued before its password was set' );
			$this->lastMessag = 'Password changed: sign in again';
			return false;
		}
		return Array('user'=>$userVls['userName'], 'role'=>strval($userVls['userRole'] ?? ''), 'id'=>$userVls['id_user'] ?? null);
	}

	/**
	 * Stamps the time an active user was last seen. Meant to be called
	 * on every authenticated request: the row is only written when its
	 * stamp is older than STAMPGAP seconds.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the stamp was written.
	 */
	public function retainAliveFlg( String $namedUserTxt ): Bool {
		if( $this->usersTable=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$nowTime = time();
			$updtQry = "UPDATE {$qtdName['usrTable']} SET {$qtdName['lastSeen']}='".date('Y-m-d H:i:s', $nowTime)."'"
				." WHERE {$qtdName['userName']}=? AND {$qtdName['userStat']}='active'"
				." AND ({$qtdName['lastSeen']} IS NULL OR {$qtdName['lastSeen']}<'".date('Y-m-d H:i:s', $nowTime - self::STMP_SECS)."');";
			return $dbHndlr->sendBuiltQuery($updtQry, true, Array($namedUserTxt)) && $dbHndlr->countLastWrite()>0;
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
	public function onlineNowArray(): Array|Bool {
		if( $this->usersTable=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$listQry = "SELECT {$qtdName['userName']} FROM {$qtdName['usrTable']} WHERE {$qtdName['userStat']}='active'"
				." AND {$qtdName['lastSeen']}>='".date('Y-m-d H:i:s', time() - $this->idlePeriod)."' ORDER BY {$qtdName['userName']};";
			if( !$dbHndlr->sendBuiltQuery($listQry) ){
				return false;
			}
			$usrRows = $dbHndlr->readLastResult(true);
		}catch( \Throwable $e ){
			return false;
		}
		return is_array($usrRows) ? array_column($usrRows, 'userName') : false;
	}

	/**
	 * Reads a user's row by login name, or by the hash of its API token.
	 *
	 * @param String $usrLookupTxt Value to look up.
	 * @param String $fldNamedText Field it is looked up in: 'userName' or 'tokenKey'.
	 * @return Array|Bool|Null The user's row, null when there is none, or false when the table cannot be read.
	 */
	protected function fetchUserArray( String $usrLookupTxt, String $fldNamedText = 'userName' ): Array|Bool|Null {
		if( $this->usersTable=='' || !in_array($fldNamedText, Array('userName', 'tokenKey'), true) ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$qtdName = $this->quoteFieldsArr($dbHndlr);
			$userQry = "SELECT * FROM {$qtdName['usrTable']} WHERE {$qtdName[$fldNamedText]}=? LIMIT 1;";
			if( !$dbHndlr->sendBuiltQuery($userQry, false, Array($usrLookupTxt)) ){
				return false;
			}
			$usrRows = $dbHndlr->readLastResult(true);
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
	 * @param DbHandlr $dbHandlerObj The database handler.
	 * @return Array The quoted names, keyed by plain name; the table is under 'usrTable'.
	 */
	protected function quoteFieldsArr( DbHandlr $dbHandlerObj ): Array {
		$qtdName = Array('usrTable'=>$dbHandlerObj->quoteFieldName($this->usersTable));
		foreach( Array('id_user', 'userName', 'passHash', 'passDate', 'userRole', 'userStat', 'userMail', 'userLang', 'actvCode', 'resetCod', 'codeDate', 'signDate', 'tokenKey', 'twoFaKey', 'twoFaStp', 'twoFaWay', 'mailCode', 'mailDate', 'failNmbr', 'failDate', 'madeDate', 'lastSeen') as $fldNme ){
			$qtdName[$fldNme] = $dbHandlerObj->quoteFieldName($fldNme);
		}
		return $qtdName;
	}

	/**
	 * Returns the handler of the database holding the users: the one
	 * named by 'linkName', or the default database.
	 *
	 * @return DbHandlr The database handler.
	 *
	 * @throws \RuntimeException When that database is not configured.
	 */
	protected function attachDatabase(): DbHandlr {
		return DBCon::linkNamedDbCon($this->usersStore)->readConnection();
	}

	/**
	 * Tells whether the last refusal was a wrong credential, as opposed to
	 * a store that cannot be read, an account not active or a step still
	 * to take: only a wrong credential counts against the address that
	 * presented it (see Access\Limiter).
	 *
	 * @return Bool True when a credential was presented and refused.
	 */
	public function wrngCredential(): Bool {
		return $this->invalidCrd;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	function finalErrorText(): String {
		return $this->lastMessag;
	}
}
