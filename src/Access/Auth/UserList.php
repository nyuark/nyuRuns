<?php

namespace nyu\Access\Auth;

use nyu\Access\Totp;
use nyu\Tools\Logger;

/**
 * Verifies credentials against a flat file of users, so an application
 * can run without any database: the native userbase (see Native), read
 * from a JSON file written as { "users": [ { field: value }, ... ] }.
 * A user has a 'systemName' and a 'passwdHash' (as given by password_hash()),
 * and optionally an 'accessRole' (internal when left out), a 'userStatus'
 * (active when left out), a 'mailboxAdr' address (one account each), the
 * 'bearerHash' SHA-256 hash of a personal API token, a 'twoFactKey' 2FA
 * secret and an 'id_user'.
 * The file is only read: what needs a write (presence, new tokens,
 * secrets or passwords) is answered false. The wrong passwords and codes
 * of each account are counted in the folder of the limits, as those of
 * each address are (see Access\Limiter): after 'maxTries' of them within
 * 'holdSecs', the account is held as the native userbase holds it.
 */
class UserList implements Handler{

	protected $usrLstPath;
	protected $sealingKey;
	protected $usrLimiter;
	protected $passedUser = null;
	protected $errMessage;
	protected $badTryFlag = false;

	/**
	 * Builds the check against the users file from its config block.
	 *
	 * @param Array $configValues Config block with the 'listFile' path of the users file, relative to the project root, and
	 *                          optionally the key its encrypted 2FA secrets are read with ('tfaCrypt'), the wrong
	 *                          credentials allowed for one account ('maxTries', defaults to 5; 0 for no limit) and the
	 *                          time in seconds they add up within and then hold it for ('holdSecs', defaults to 600).
	 */
	function __construct( Array $configValues ){
		$fileNme = is_scalar($configValues['listFile'] ?? '') ? trim( strval($configValues['listFile'] ?? ''), '/\\ ' ) : '';
		$this->usrLstPath = ($fileNme!='') ? TOP_DIR.DS.$fileNme : '';
		$this->sealingKey = is_scalar($configValues['tfaCrypt'] ?? '') ? strval($configValues['tfaCrypt'] ?? '') : '';
		// The wrong credentials of each account are counted beside those of each address, in the folder of the limits
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'TRYLIMIT') : null;
		$this->usrLimiter = new \nyu\Access\Limiter( Array(
			'maxTries'=>$configValues['maxTries'] ?? 5, 'holdSecs'=>$configValues['holdSecs'] ?? 600, 'dataPath'=>is_array($lmtConf) ? strval($lmtConf['dataPath'] ?? '') : ''
		) );		$this->errMessage = '';
	}

	/**
	 * Verifies a username/password pair against the users file. The
	 * password must match the stored hash and the user must be 'approved'
	 * (one that is not is refused as a wrong password is, and the reason
	 * logged); a user with a 2FA secret must also give the current code of its
	 * authenticator. A 'key' entry is checked as a personal API token
	 * instead.
	 *
	 * @param Array $loginDataArr Must contain 'user' and 'pass' entries (and 'code' for a 2FA user), or a 'key' entry.
	 * @return Array|Bool An identity array ('userName', 'userRole', 'idOfUser') on success, or false on failure.
	 */
	function checkSecretArr( Array $loginDataArr ): Array|Bool {
		$this->badTryFlag = false;
		$this->passedUser = null;
		if( isset($loginDataArr['key']) ){
			return $this->checkTokenList( strval($loginDataArr['key']) );
		}
		$gvnUser = strval($loginDataArr['user'] ?? '');
		$gvnPass = strval($loginDataArr['pass'] ?? '');
		// A name of the file written as the counter of a login mark is the name of nobody
		$userVls = ($gvnUser!='' && strncmp($gvnUser, 'mark:', 5)!==0) ? $this->fetchUserArray($gvnUser) : null;
		if( $userVls===false ){
			$this->errMessage = 'Credential store unavailable';
			return false;
		}
		$isHashd = is_array($userVls) && password_get_info(strval($userVls['passwdHash']))['algo']!==null;
		if( !$isHashd ){
			// Unknown user, or an account without a password : the hash of the first user of the file is checked, at the cost the file's own hashes have, so the reply time tells neither apart from a wrong password
			password_verify( $gvnPass, $this->fakeHashString() );
			$matched = false;
		}else{
			$matched = password_verify( $gvnPass, $userVls['passwdHash'] );
		}
		// A client already used by the user : its mark, while it has not failed too often itself; its failures are counted on it
		$cntName = is_array($userVls) ? strval($userVls['systemName']) : '';
		if( is_array($userVls) && is_string($loginDataArr['mark'] ?? null) && $loginDataArr['mark']!=='' ){
			// Signed with the hash the password has now : a password set after the mark was made ends it
			$markKey = \nyu\Access\LoginMark::fromLimitBlock()->readClientMark( $loginDataArr['mark'], $cntName, $isHashd ? strval($userVls['passwdHash']) : '' );
			if( $markKey!='' && $this->usrLimiter->heldByUserNumb($markKey)>0 ){
				Logger::retrieveLogger('access')->warning( 'Login mark refused for user '.json_encode($cntName).': too many attempts with it' );
			}elseif( $markKey!='' ){
				$cntName = $markKey;
			}
		}
		// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
		if( is_array($userVls) && $this->usrLimiter->heldByUserNumb($cntName)>0 ){
			Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($userVls['systemName']).' is held after too many attempts' );
			$this->errMessage = 'Invalid credentials';
			$this->badTryFlag = true;
			return false;
		}
		if( $userVls===null || !$matched ){
			if( $userVls!==null ){
				$this->usrLimiter->countLimitUser( $cntName );
			}
			$this->errMessage = 'Invalid credentials';
			$this->badTryFlag = true;
			return false;
		}
		if( $userVls['userStatus']!=='approved' ){
			// The reply is the one of a wrong password : the status of a user is told in the log only
			Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($userVls['systemName']).' is not active ('.$userVls['userStatus'].')' );
			$this->errMessage = 'Invalid credentials';
			$this->badTryFlag = true;
			return false;
		}
		// A user with a 2FA secret must also give the current code of its authenticator
		if( $userVls['twoFactKey']!='' ){
			// A secret stored encrypted is read with the key of the config
			$sealKey = Totp::openSecretText($userVls['twoFactKey'], $this->sealingKey);
			if( $sealKey===null ){
				Logger::retrieveLogger('access')->error( '2FA secret of user '.json_encode($userVls['systemName']).' cannot be read: tfaCrypt is missing or was changed' );
				$this->errMessage = 'Credential store unavailable';
				return false;
			}
			$gvnCode = strval($loginDataArr['code'] ?? '');
			if( $gvnCode=='' ){
				// Not a failed attempt : the password was right, the client now asks for the code
				$this->errMessage = 'Verification code required';
				return false;
			}
			if( !Totp::verifyTotpCode($sealKey, $gvnCode) ){
				$this->usrLimiter->countLimitUser( $cntName );
				$this->errMessage = 'Invalid verification code';
				$this->badTryFlag = true;
				return false;
			}
		}
		$this->usrLimiter->clearLimitUser( $cntName );
		$this->passedUser = Array('systemName'=>strval($userVls['systemName']), 'passwdHash'=>strval($userVls['passwdHash']));
		return $this->createIdentity($userVls);
	}

	/**
	 * Makes the login mark of the user the last check accepted, signed
	 * with the hash of its password (see Access\LoginMark).
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String The mark, or '' when the last check did not accept that user, or no mark is made.
	 */
	public function createUserMark( String $namedUserTxt ): String {
		if( $this->passedUser===null || $this->passedUser['systemName']!==$namedUserTxt ){
			return '';
		}
		return \nyu\Access\LoginMark::fromLimitBlock()->createUserMark( $namedUserTxt, $this->passedUser['passwdHash'] );
	}

	/**
	 * Verifies a personal API token: its SHA-256 hash must be the
	 * bearerHash of an active user.
	 *
	 * @param String $toknValueTxt The token, as sent by the client.
	 * @return Array|Bool The identity array ('userName', 'userRole', 'idOfUser'), or false on failure.
	 */
	protected function checkTokenList( String $toknValueTxt ): Array|Bool {
		if( $toknValueTxt=='' ){
			$this->errMessage = 'No API key provided';
			return false;
		}
		$userVls = $this->fetchUserArray( hash('sha256', $toknValueTxt), 'bearerHash' );
		if( $userVls===false ){
			$this->errMessage = 'Credential store unavailable';
			return false;
		}
		if( $userVls===null || $userVls['userStatus']!=='approved' ){
			$this->errMessage = 'Invalid API key';
			$this->badTryFlag = true;
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
	 * @return Array|Bool The identity array ('userName', 'userRole', 'idOfUser'), or false when the user is unknown, not active, or the file cannot be read.
	 */
	public function usrIdentityArr( String $namedUserTxt, ?Int $issuedUnixNb=null ): Array|Bool {
		$this->badTryFlag = false;
		$userVls = ($namedUserTxt!='') ? $this->fetchUserArray($namedUserTxt) : null;
		if( $userVls===false ){
			$this->errMessage = 'Credential store unavailable';
			return false;
		}
		if( $userVls===null || $userVls['userStatus']!=='approved' ){
			$this->errMessage = 'Account not active';
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
	 * SHA-256 hash is written in the file by hand, as 'bearerHash'.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool Always false.
	 */
	public function makeToknString( String $namedUserTxt ): String|Bool {
		return false;
	}

	/**
	 * A 2FA secret cannot be made: the file is only read. The secret is
	 * written in the file by hand, as 'twoFactKey'.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool Always false.
	 */
	public function buildTwoFactor( String $namedUserTxt ): String|Bool {
		return false;
	}

	/**
	 * A password cannot be changed: the file is only read. Its hash is
	 * written in the file by hand, as 'passwdHash'.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The new password.
	 * @return Bool Always false.
	 */
	public function savePasswdFlag( String $namedUserTxt, String $usrPasswdTxt ): Bool {
		return false;
	}

	/**
	 * Gives the hash an unknown name is checked against: the one of the
	 * first user of the file whose password is hashed, so that it costs
	 * what the file's own hashes cost; Native::FAKEHASH when there is none.
	 *
	 * @return String The hash.
	 */
	protected function fakeHashString(): String {
		$usrList = $this->loadUsersArray();
		foreach( is_array($usrList) ? $usrList : Array() as $usrRow ){
			if( password_get_info(strval($usrRow['passwdHash']))['algo']!==null ){
				return strval($usrRow['passwdHash']);
			}
		}
		return Native::getDummyBcrypt();
	}

	/**
	 * Gives the identity of a user of the file.
	 *
	 * @param Array $usrValuesArr The user's values, as loadUsersArray() gives them.
	 * @return Array The identity array ('userName', 'userRole', 'idOfUser').
	 */
	protected function createIdentity( Array $usrValuesArr ): Array {
		return Array('userName'=>$usrValuesArr['systemName'], 'userRole'=>$usrValuesArr['accessRole'], 'idOfUser'=>$usrValuesArr['id_user']);
	}

	/**
	 * Reads a user's values by login name, or by the hash of its API token.
	 *
	 * @param String $usrLookupTxt Value to look up.
	 * @param String $fldNamedText Field it is looked up in: 'systemName' or 'bearerHash'.
	 * @return Array|Bool|Null The user's values, null when there is none, or false when the file cannot be read.
	 */
	protected function fetchUserArray( String $usrLookupTxt, String $fldNamedText = 'systemName' ): Array|Bool|Null {
		$usrList = in_array($fldNamedText, Array('systemName', 'bearerHash'), true) ? $this->loadUsersArray() : false;
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
	 * Reads the users file. Each user is given every field: 'accessRole' is
	 * internal and 'userStatus' approved when left out, 'mailboxAdr', 'userLocale',
	 * 'bearerHash' and 'twoFactKey' empty, 'id_user' null; the address and the
	 * language are read in lower case. The file is refused as a whole, and the refusal logged, when it
	 * is not a JSON text holding a 'users' list, when a user lacks its
	 * 'systemName' or 'passwdHash', when a field is not a text, or when two
	 * users share a 'systemName', a 'mailboxAdr' or a 'bearerHash'.
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
			if( !is_array($usrRow) || !is_string($usrRow['systemName'] ?? null) || $usrRow['systemName']=='' || !is_string($usrRow['passwdHash'] ?? null) || $usrRow['passwdHash']=='' ){
				$whyText = 'a user has no systemName or no passwdHash';
				break;
			}
			$userVls = Array('systemName'=>$usrRow['systemName'], 'passwdHash'=>$usrRow['passwdHash'], 'id_user'=>$usrRow['id_user'] ?? null);
			foreach( Array('accessRole'=>'internal', 'userStatus'=>'approved', 'mailboxAdr'=>'', 'userLocale'=>'', 'bearerHash'=>'', 'twoFactKey'=>'') as $fldNme=>$fldDef ){
				$userVls[$fldNme] = $usrRow[$fldNme] ?? $fldDef;
				if( !is_string($userVls[$fldNme]) ){
					$whyText = 'the '.$fldNme.' of user '.json_encode($usrRow['systemName']).' is not a text';
					break 2;
				}
			}
			if( $userVls['id_user']!==null && !is_int($userVls['id_user']) && !is_string($userVls['id_user']) ){
				$whyText = 'the id_user of user '.json_encode($usrRow['systemName']).' is neither a number nor a text';
				break;
			}
			$userVls['bearerHash'] = strtolower($userVls['bearerHash']);
			$userVls['mailboxAdr'] = strtolower($userVls['mailboxAdr']);
			$userVls['userLocale'] = strtolower($userVls['userLocale']);
			if( in_array($userVls['systemName'], $nameLst, true) ){
				$whyText = 'user '.json_encode($userVls['systemName']).' is listed twice';
				break;
			}
			if( $userVls['bearerHash']!='' && in_array($userVls['bearerHash'], $tknList, true) ){
				$whyText = 'the bearerHash of user '.json_encode($userVls['systemName']).' is the one of another user';
				break;
			}
			if( $userVls['mailboxAdr']!='' && in_array($userVls['mailboxAdr'], $mailLst, true) ){
				$whyText = 'the mailboxAdr of user '.json_encode($userVls['systemName']).' is the one of another user';
				break;
			}
			$nameLst[] = $userVls['systemName'];
			$tknList[] = $userVls['bearerHash'];
			$mailLst[] = $userVls['mailboxAdr'];
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
	public function hasWrongSecret(): Bool {
		return $this->badTryFlag;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	function finalErrorText(): String {
		return $this->errMessage;
	}
}
