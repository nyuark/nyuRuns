<?php

namespace nyu\Access\Auth;

use nyu\Access\Totp;
use nyu\Tools\Logger;

/**
 * Verifies credentials against a flat file of users, so an application
 * can run without any database: the native userbase (see Native), read
 * from a JSON file written as { "users": [ { field: value }, ... ] }.
 * A user has a 'userName' and a 'passHash' (as given by password_hash()),
 * and optionally a 'userRole' (member when left out), a 'userStat'
 * (active when left out), a 'userMail' address (one account each), the
 * 'tokenKey' SHA-256 hash of a personal API token, a 'twoFaKey' 2FA
 * secret and an 'id_user'.
 * The file is only read: what needs a write (presence, new tokens,
 * secrets or passwords) is answered false. The wrong passwords and codes
 * of each account are counted in the folder of the limits, as those of
 * each address are (see Access\Limiter): after 'maxtry' of them within
 * 'holdtm', the account is held as the native userbase holds it.
 */
class UserList implements Handler{

	protected $usrsFile;
	protected $sealPass;
	protected $userLmtr;
	protected $lastMssg;
	protected $wrongCrd = false;

	/**
	 * Builds the check against the users file from its config block.
	 *
	 * @param Array $confValues Config block with the 'usrfle' path of the users file, relative to the project root, and
	 *                          optionally the key its encrypted 2FA secrets are read with ('tfakey'), the wrong
	 *                          credentials allowed for one account ('maxtry', defaults to 5; 0 for no limit) and the
	 *                          time in seconds they add up within and then hold it for ('holdtm', defaults to 600).
	 */
	function __construct( Array $confValues ){
		$fleName = is_scalar($confValues['usrfle'] ?? '') ? trim( strval($confValues['usrfle'] ?? ''), '/\\ ' ) : '';
		$this->usrsFile = ($fleName!='') ? TOP_DIR.DS.$fleName : '';
		$this->sealPass = is_scalar($confValues['tfakey'] ?? '') ? strval($confValues['tfakey'] ?? '') : '';
		// The wrong credentials of each account are counted beside those of each address, in the folder of the limits
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'LIMITS') : null;
		$this->userLmtr = new \nyu\Access\Limiter( Array(
			'maxtry'=>$confValues['maxtry'] ?? 5, 'holdtm'=>$confValues['holdtm'] ?? 600, 'folder'=>is_array($lmtConf) ? strval($lmtConf['folder'] ?? '') : ''
		) );		$this->lastMssg = '';
	}

	/**
	 * Verifies a username/password pair against the users file. The
	 * password must match the stored hash and the user must be 'active'
	 * (one that is not is refused as a wrong password is, and the reason
	 * logged); a user with a 2FA secret must also give the current code of its
	 * authenticator. A 'key' entry is checked as a personal API token
	 * instead.
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
		// A name of the file written as the counter of a login mark is the name of nobody
		$usrVals = ($gvnUser!='' && strncmp($gvnUser, 'mark:', 5)!==0) ? $this->fetchUser($gvnUser) : null;
		if( $usrVals===false ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		if( $usrVals===null || password_get_info(strval($usrVals['passHash']))['algo']===null ){
			// Unknown user, or an account without a password : the same check is run, so the reply time tells neither apart from a wrong password
			password_verify( $gvnPass, Native::dummyHash() );
			$matched = false;
		}else{
			$matched = password_verify( $gvnPass, $usrVals['passHash'] );
		}
		// A client already used by the user : its mark, while it has not failed too often itself; its failures are counted on it
		$cntName = is_array($usrVals) ? strval($usrVals['userName']) : '';
		if( is_array($usrVals) && is_string($credsArray['mark'] ?? null) && $credsArray['mark']!=='' ){
			$markKey = \nyu\Access\LoginMark::fromLimit()->readsMark( $credsArray['mark'], $cntName );
			if( $markKey!='' && $this->userLmtr->heldByUsr($markKey)>0 ){
				Logger::getLogger('access')->warning( 'Login mark refused for user '.json_encode($cntName).': too many attempts with it' );
			}elseif( $markKey!='' ){
				$cntName = $markKey;
			}
		}
		// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
		if( is_array($usrVals) && $this->userLmtr->heldByUsr($cntName)>0 ){
			Logger::getLogger('access')->warning( 'Credential refused: user '.json_encode($usrVals['userName']).' is held after too many attempts' );
			$this->lastMssg = 'Invalid credentials';
			$this->wrongCrd = true;
			return false;
		}
		if( $usrVals===null || !$matched ){
			if( $usrVals!==null ){
				$this->userLmtr->countUser( $cntName );
			}
			$this->lastMssg = 'Invalid credentials';
			$this->wrongCrd = true;
			return false;
		}
		if( $usrVals['userStat']!=='active' ){
			// The reply is the one of a wrong password : the status of a user is told in the log only
			Logger::getLogger('access')->warning( 'Credential refused: user '.json_encode($usrVals['userName']).' is not active ('.$usrVals['userStat'].')' );
			$this->lastMssg = 'Invalid credentials';
			$this->wrongCrd = true;
			return false;
		}
		// A user with a 2FA secret must also give the current code of its authenticator
		if( $usrVals['twoFaKey']!='' ){
			// A secret stored encrypted is read with the key of the config
			$twoFaKy = Totp::openSecrt($usrVals['twoFaKey'], $this->sealPass);
			if( $twoFaKy===null ){
				Logger::getLogger('access')->error( '2FA secret of user '.json_encode($usrVals['userName']).' cannot be read: tfakey is missing or was changed' );
				$this->lastMssg = 'Credential store unavailable';
				return false;
			}
			$gvnCode = strval($credsArray['code'] ?? '');
			if( $gvnCode=='' ){
				// Not a failed attempt : the password was right, the client now asks for the code
				$this->lastMssg = 'Verification code required';
				return false;
			}
			if( !Totp::checkCode($twoFaKy, $gvnCode) ){
				$this->userLmtr->countUser( $cntName );
				$this->lastMssg = 'Invalid verification code';
				$this->wrongCrd = true;
				return false;
			}
		}
		$this->userLmtr->clearUser( $cntName );
		return $this->makeIdent($usrVals);
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
		if( $usrVals===null || $usrVals['userStat']!=='active' ){
			$this->lastMssg = 'Invalid API key';
			$this->wrongCrd = true;
			return false;
		}
		return $this->makeIdent($usrVals);
	}

	/**
	 * Reads the current identity of a user authenticated by other means
	 * (a token), so that a lock or a role change written in the file
	 * applies at once. The file is only read: no password is set through
	 * it, so a session or a token is never ended by a password change here
	 * (the time it was issued at is taken, and not compared).
	 *
	 * @param String $userString Login name of the user.
	 * @param ?Int   $issuedUnix Unix time the session or the token was issued at; not compared.
	 * @return Array|Bool The identity array ('user', 'role', 'id'), or false when the user is unknown, not active, or the file cannot be read.
	 */
	public function userIdent( String $userString, ?Int $issuedUnix=null ): Array|Bool {
		$this->wrongCrd = false;
		$usrVals = ($userString!='') ? $this->fetchUser($userString) : null;
		if( $usrVals===false ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		if( $usrVals===null || $usrVals['userStat']!=='active' ){
			$this->lastMssg = 'Account not active';
			return false;
		}
		return $this->makeIdent($usrVals);
	}

	/**
	 * Presence is not tracked: the file is only read.
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Always false: nothing is stamped.
	 */
	public function keepAlive( String $userString ): Bool {
		return false;
	}

	/**
	 * Presence is not tracked: who is online is not known.
	 *
	 * @return Array|Bool Always false, as a store that cannot be read: not an empty list, which would tell nobody is online.
	 */
	public function onlineNow(): Array|Bool {
		return false;
	}

	/**
	 * A personal API token cannot be made: the file is only read. Its
	 * SHA-256 hash is written in the file by hand, as 'tokenKey'.
	 *
	 * @param String $userString Login name of the user.
	 * @return String|Bool Always false.
	 */
	public function makeToken( String $userString ): String|Bool {
		return false;
	}

	/**
	 * A 2FA secret cannot be made: the file is only read. The secret is
	 * written in the file by hand, as 'twoFaKey'.
	 *
	 * @param String $userString Login name of the user.
	 * @return String|Bool Always false.
	 */
	public function makeTwoFa( String $userString ): String|Bool {
		return false;
	}

	/**
	 * A password cannot be changed: the file is only read. Its hash is
	 * written in the file by hand, as 'passHash'.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $passwdText The new password.
	 * @return Bool Always false.
	 */
	public function setPasswd( String $userString, String $passwdText ): Bool {
		return false;
	}

	/**
	 * Gives the identity of a user of the file.
	 *
	 * @param Array $userValues The user's values, as loadUsers() gives them.
	 * @return Array The identity array ('user', 'role', 'id').
	 */
	protected function makeIdent( Array $userValues ): Array {
		return Array('user'=>$userValues['userName'], 'role'=>$userValues['userRole'], 'id'=>$userValues['id_user']);
	}

	/**
	 * Reads a user's values by login name, or by the hash of its API token.
	 *
	 * @param String $lookupText Value to look up.
	 * @param String $fieldNamed Field it is looked up in: 'userName' or 'tokenKey'.
	 * @return Array|Bool|Null The user's values, null when there is none, or false when the file cannot be read.
	 */
	protected function fetchUser( String $lookupText, String $fieldNamed = 'userName' ): Array|Bool|Null {
		$usrList = in_array($fieldNamed, Array('userName', 'tokenKey'), true) ? $this->loadUsers() : false;
		if( $usrList===false ){
			return false;
		}
		$usrVals = null;
		// Every user is compared, so the reply time does not tell where the match is
		foreach( $usrList as $usrRow ){
			if( $usrRow[$fieldNamed]!='' && hash_equals($usrRow[$fieldNamed], $lookupText) ){
				$usrVals = $usrRow;
			}
		}
		return $usrVals;
	}

	/**
	 * Reads the users file. Each user is given every field: 'userRole' is
	 * member and 'userStat' active when left out, 'userMail', 'userLang',
	 * 'tokenKey' and 'twoFaKey' empty, 'id_user' null; the address and the
	 * language are read in lower case. The file is refused as a whole, and the refusal logged, when it
	 * is not a JSON text holding a 'users' list, when a user lacks its
	 * 'userName' or 'passHash', when a field is not a text, or when two
	 * users share a 'userName', a 'userMail' or a 'tokenKey'.
	 *
	 * @return Array|Bool The users, or false when the file cannot be used.
	 */
	protected function loadUsers(): Array|Bool {
		if( $this->usrsFile=='' || !is_file($this->usrsFile) ){
			return false;
		}
		$fleVals = json_decode( strval(@file_get_contents($this->usrsFile)), true );
		$rowList = is_array($fleVals) ? ($fleVals['users'] ?? null) : null;
		$whyText = is_array($rowList) ? '' : 'it is not a JSON text holding a "users" list';
		$usrList = Array();
		$nameLst = Array();
		$tknList = Array();
		$mailLst = Array();
		foreach( (array)$rowList as $usrRow ){
			if( !is_array($usrRow) || !is_string($usrRow['userName'] ?? null) || $usrRow['userName']=='' || !is_string($usrRow['passHash'] ?? null) || $usrRow['passHash']=='' ){
				$whyText = 'a user has no userName or no passHash';
				break;
			}
			$usrVals = Array('userName'=>$usrRow['userName'], 'passHash'=>$usrRow['passHash'], 'id_user'=>$usrRow['id_user'] ?? null);
			foreach( Array('userRole'=>'member', 'userStat'=>'active', 'userMail'=>'', 'userLang'=>'', 'tokenKey'=>'', 'twoFaKey'=>'') as $fldNme=>$fldDef ){
				$usrVals[$fldNme] = $usrRow[$fldNme] ?? $fldDef;
				if( !is_string($usrVals[$fldNme]) ){
					$whyText = 'the '.$fldNme.' of user '.json_encode($usrRow['userName']).' is not a text';
					break 2;
				}
			}
			if( $usrVals['id_user']!==null && !is_int($usrVals['id_user']) && !is_string($usrVals['id_user']) ){
				$whyText = 'the id_user of user '.json_encode($usrRow['userName']).' is neither a number nor a text';
				break;
			}
			$usrVals['tokenKey'] = strtolower($usrVals['tokenKey']);
			$usrVals['userMail'] = strtolower($usrVals['userMail']);
			$usrVals['userLang'] = strtolower($usrVals['userLang']);
			if( in_array($usrVals['userName'], $nameLst, true) ){
				$whyText = 'user '.json_encode($usrVals['userName']).' is listed twice';
				break;
			}
			if( $usrVals['tokenKey']!='' && in_array($usrVals['tokenKey'], $tknList, true) ){
				$whyText = 'the tokenKey of user '.json_encode($usrVals['userName']).' is the one of another user';
				break;
			}
			if( $usrVals['userMail']!='' && in_array($usrVals['userMail'], $mailLst, true) ){
				$whyText = 'the userMail of user '.json_encode($usrVals['userName']).' is the one of another user';
				break;
			}
			$nameLst[] = $usrVals['userName'];
			$tknList[] = $usrVals['tokenKey'];
			$mailLst[] = $usrVals['userMail'];
			$usrList[] = $usrVals;
		}
		if( $whyText!='' ){
			Logger::getLogger('access')->error( 'Users file '.json_encode(basename($this->usrsFile)).' cannot be used: '.$whyText );
			return false;
		}
		return $usrList;
	}

	/**
	 * Tells whether the last refusal was a wrong credential, as opposed to
	 * a file that cannot be read, an account not active or a step still
	 * to take: only a wrong credential counts against the address that
	 * presented it (see Access\Limiter).
	 *
	 * @return Bool True when a credential was presented and refused.
	 */
	public function wrongCred(): Bool {
		return $this->wrongCrd;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
