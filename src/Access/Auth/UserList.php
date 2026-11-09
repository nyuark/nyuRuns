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

	protected $usrLstPath;
	protected $sealingKey;
	protected $usrLimiter;
	protected $lastMessag;
	protected $invalidCrd = false;

	/**
	 * Builds the check against the users file from its config block.
	 *
	 * @param Array $configValues Config block with the 'usrfle' path of the users file, relative to the project root, and
	 *                          optionally the key its encrypted 2FA secrets are read with ('tfakey'), the wrong
	 *                          credentials allowed for one account ('maxtry', defaults to 5; 0 for no limit) and the
	 *                          time in seconds they add up within and then hold it for ('holdtm', defaults to 600).
	 */
	function __construct( Array $configValues ){
		$fileNme = is_scalar($configValues['usrfle'] ?? '') ? trim( strval($configValues['usrfle'] ?? ''), '/\\ ' ) : '';
		$this->usrLstPath = ($fileNme!='') ? TOP_DIR.DS.$fileNme : '';
		$this->sealingKey = is_scalar($configValues['tfakey'] ?? '') ? strval($configValues['tfakey'] ?? '') : '';
		// The wrong credentials of each account are counted beside those of each address, in the folder of the limits
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'LIMITS') : null;
		$this->usrLimiter = new \nyu\Access\Limiter( Array(
			'maxtry'=>$configValues['maxtry'] ?? 5, 'holdtm'=>$configValues['holdtm'] ?? 600, 'folder'=>is_array($lmtConf) ? strval($lmtConf['folder'] ?? '') : ''
		) );		$this->lastMessag = '';
	}

	/**
	 * Verifies a username/password pair against the users file. The
	 * password must match the stored hash and the user must be 'active'
	 * (one that is not is refused as a wrong password is, and the reason
	 * logged); a user with a 2FA secret must also give the current code of its
	 * authenticator. A 'key' entry is checked as a personal API token
	 * instead.
	 *
	 * @param Array $crdntlsArray Must contain 'user' and 'pass' entries (and 'code' for a 2FA user), or a 'key' entry.
	 * @return Array|Bool An identity array ('user', 'role', 'id') on success, or false on failure.
	 */
	function chckCredential( Array $crdntlsArray ): Array|Bool {
		$this->invalidCrd = false;
		if( isset($crdntlsArray['key']) ){
			return $this->checkTokenList( strval($crdntlsArray['key']) );
		}
		$gvnUser = strval($crdntlsArray['user'] ?? '');
		$gvnPass = strval($crdntlsArray['pass'] ?? '');
		// A name of the file written as the counter of a login mark is the name of nobody
		$userVls = ($gvnUser!='' && strncmp($gvnUser, 'mark:', 5)!==0) ? $this->fetchUserArray($gvnUser) : null;
		if( $userVls===false ){
			$this->lastMessag = 'Credential store unavailable';
			return false;
		}
		if( $userVls===null || password_get_info(strval($userVls['passHash']))['algo']===null ){
			// Unknown user, or an account without a password : the same check is run, so the reply time tells neither apart from a wrong password
			password_verify( $gvnPass, Native::getDummyBcrypt() );
			$matched = false;
		}else{
			$matched = password_verify( $gvnPass, $userVls['passHash'] );
		}
		// A client already used by the user : its mark, while it has not failed too often itself; its failures are counted on it
		$cntName = is_array($userVls) ? strval($userVls['userName']) : '';
		if( is_array($userVls) && is_string($crdntlsArray['mark'] ?? null) && $crdntlsArray['mark']!=='' ){
			$markKey = \nyu\Access\LoginMark::fromLimitBlock()->readClientMark( $crdntlsArray['mark'], $cntName );
			if( $markKey!='' && $this->usrLimiter->heldByUserNumb($markKey)>0 ){
				Logger::retrieveLogger('access')->warning( 'Login mark refused for user '.json_encode($cntName).': too many attempts with it' );
			}elseif( $markKey!='' ){
				$cntName = $markKey;
			}
		}
		// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
		if( is_array($userVls) && $this->usrLimiter->heldByUserNumb($cntName)>0 ){
			Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($userVls['userName']).' is held after too many attempts' );
			$this->lastMessag = 'Invalid credentials';
			$this->invalidCrd = true;
			return false;
		}
		if( $userVls===null || !$matched ){
			if( $userVls!==null ){
				$this->usrLimiter->countLimitUser( $cntName );
			}
			$this->lastMessag = 'Invalid credentials';
			$this->invalidCrd = true;
			return false;
		}
		if( $userVls['userStat']!=='active' ){
			// The reply is the one of a wrong password : the status of a user is told in the log only
			Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($userVls['userName']).' is not active ('.$userVls['userStat'].')' );
			$this->lastMessag = 'Invalid credentials';
			$this->invalidCrd = true;
			return false;
		}
		// A user with a 2FA secret must also give the current code of its authenticator
		if( $userVls['twoFaKey']!='' ){
			// A secret stored encrypted is read with the key of the config
			$sealKey = Totp::openSecretText($userVls['twoFaKey'], $this->sealingKey);
			if( $sealKey===null ){
				Logger::retrieveLogger('access')->error( '2FA secret of user '.json_encode($userVls['userName']).' cannot be read: tfakey is missing or was changed' );
				$this->lastMessag = 'Credential store unavailable';
				return false;
			}
			$gvnCode = strval($crdntlsArray['code'] ?? '');
			if( $gvnCode=='' ){
				// Not a failed attempt : the password was right, the client now asks for the code
				$this->lastMessag = 'Verification code required';
				return false;
			}
			if( !Totp::verifyTotpCode($sealKey, $gvnCode) ){
				$this->usrLimiter->countLimitUser( $cntName );
				$this->lastMessag = 'Invalid verification code';
				$this->invalidCrd = true;
				return false;
			}
		}
		$this->usrLimiter->clearLimitUser( $cntName );
		return $this->createIdentity($userVls);
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
		if( $userVls===null || $userVls['userStat']!=='active' ){
			$this->lastMessag = 'Invalid API key';
			$this->invalidCrd = true;
			return false;
		}
		return $this->createIdentity($userVls);
	}

	/**
	 * Reads the current identity of a user authenticated by other means
	 * (a token), so that a lock or a role change written in the file
	 * applies at once. The file is only read: no password is set through
	 * it, so a session or a token is never ended by a password change here
	 * (the time it was issued at is taken, and not compared).
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param ?Int   $issuedUnixNb Unix time the session or the token was issued at; not compared.
	 * @return Array|Bool The identity array ('user', 'role', 'id'), or false when the user is unknown, not active, or the file cannot be read.
	 */
	public function usrIdentityArr( String $namedUserTxt, ?Int $issuedUnixNb=null ): Array|Bool {
		$this->invalidCrd = false;
		$userVls = ($namedUserTxt!='') ? $this->fetchUserArray($namedUserTxt) : null;
		if( $userVls===false ){
			$this->lastMessag = 'Credential store unavailable';
			return false;
		}
		if( $userVls===null || $userVls['userStat']!=='active' ){
			$this->lastMessag = 'Account not active';
			return false;
		}
		return $this->createIdentity($userVls);
	}

	/**
	 * Presence is not tracked: the file is only read.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Always false: nothing is stamped.
	 */
	public function retainAliveFlg( String $namedUserTxt ): Bool {
		return false;
	}

	/**
	 * Presence is not tracked: who is online is not known.
	 *
	 * @return Array|Bool Always false, as a store that cannot be read: not an empty list, which would tell nobody is online.
	 */
	public function onlineNowArray(): Array|Bool {
		return false;
	}

	/**
	 * A personal API token cannot be made: the file is only read. Its
	 * SHA-256 hash is written in the file by hand, as 'tokenKey'.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool Always false.
	 */
	public function makeToknString( String $namedUserTxt ): String|Bool {
		return false;
	}

	/**
	 * A 2FA secret cannot be made: the file is only read. The secret is
	 * written in the file by hand, as 'twoFaKey'.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool Always false.
	 */
	public function buildTwoFactor( String $namedUserTxt ): String|Bool {
		return false;
	}

	/**
	 * A password cannot be changed: the file is only read. Its hash is
	 * written in the file by hand, as 'passHash'.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The new password.
	 * @return Bool Always false.
	 */
	public function savePasswdFlag( String $namedUserTxt, String $usrPasswdTxt ): Bool {
		return false;
	}

	/**
	 * Gives the identity of a user of the file.
	 *
	 * @param Array $usrValuesArr The user's values, as loadUsersArray() gives them.
	 * @return Array The identity array ('user', 'role', 'id').
	 */
	protected function createIdentity( Array $usrValuesArr ): Array {
		return Array('user'=>$usrValuesArr['userName'], 'role'=>$usrValuesArr['userRole'], 'id'=>$usrValuesArr['id_user']);
	}

	/**
	 * Reads a user's values by login name, or by the hash of its API token.
	 *
	 * @param String $usrLookupTxt Value to look up.
	 * @param String $fldNamedText Field it is looked up in: 'userName' or 'tokenKey'.
	 * @return Array|Bool|Null The user's values, null when there is none, or false when the file cannot be read.
	 */
	protected function fetchUserArray( String $usrLookupTxt, String $fldNamedText = 'userName' ): Array|Bool|Null {
		$usrList = in_array($fldNamedText, Array('userName', 'tokenKey'), true) ? $this->loadUsersArray() : false;
		if( $usrList===false ){
			return false;
		}
		$userVls = null;
		// Every user is compared, so the reply time does not tell where the match is
		foreach( $usrList as $usrRow ){
			if( $usrRow[$fldNamedText]!='' && hash_equals($usrRow[$fldNamedText], $usrLookupTxt) ){
				$userVls = $usrRow;
			}
		}
		return $userVls;
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
	protected function loadUsersArray(): Array|Bool {
		if( $this->usrLstPath=='' || !is_file($this->usrLstPath) ){
			return false;
		}
		$fileVls = json_decode( strval(@file_get_contents($this->usrLstPath)), true );
		$rowList = is_array($fileVls) ? ($fileVls['users'] ?? null) : null;
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
			$userVls = Array('userName'=>$usrRow['userName'], 'passHash'=>$usrRow['passHash'], 'id_user'=>$usrRow['id_user'] ?? null);
			foreach( Array('userRole'=>'member', 'userStat'=>'active', 'userMail'=>'', 'userLang'=>'', 'tokenKey'=>'', 'twoFaKey'=>'') as $fldNme=>$fldDef ){
				$userVls[$fldNme] = $usrRow[$fldNme] ?? $fldDef;
				if( !is_string($userVls[$fldNme]) ){
					$whyText = 'the '.$fldNme.' of user '.json_encode($usrRow['userName']).' is not a text';
					break 2;
				}
			}
			if( $userVls['id_user']!==null && !is_int($userVls['id_user']) && !is_string($userVls['id_user']) ){
				$whyText = 'the id_user of user '.json_encode($usrRow['userName']).' is neither a number nor a text';
				break;
			}
			$userVls['tokenKey'] = strtolower($userVls['tokenKey']);
			$userVls['userMail'] = strtolower($userVls['userMail']);
			$userVls['userLang'] = strtolower($userVls['userLang']);
			if( in_array($userVls['userName'], $nameLst, true) ){
				$whyText = 'user '.json_encode($userVls['userName']).' is listed twice';
				break;
			}
			if( $userVls['tokenKey']!='' && in_array($userVls['tokenKey'], $tknList, true) ){
				$whyText = 'the tokenKey of user '.json_encode($userVls['userName']).' is the one of another user';
				break;
			}
			if( $userVls['userMail']!='' && in_array($userVls['userMail'], $mailLst, true) ){
				$whyText = 'the userMail of user '.json_encode($userVls['userName']).' is the one of another user';
				break;
			}
			$nameLst[] = $userVls['userName'];
			$tknList[] = $userVls['tokenKey'];
			$mailLst[] = $userVls['userMail'];
			$usrList[] = $userVls;
		}
		if( $whyText!='' ){
			Logger::retrieveLogger('access')->error( 'Users file '.json_encode(basename($this->usrLstPath)).' cannot be used: '.$whyText );
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
