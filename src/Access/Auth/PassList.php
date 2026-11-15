<?php

namespace nyu\Access\Auth;

/**
 * Verifies credentials against an Apache-style .htpasswd file, so the
 * same file can be shared with an Apache/Nginx Basic-auth config. The
 * wrong passwords of each account are counted in the folder of the limits
 * (see Access\Limiter): after 'maxTries' of them within 'holdSecs', the account
 * is refused, whatever it gives, until the hold is over.
 */
class PassList implements Handler{

	protected $hashesFile;
	protected $canUseWeak;
	protected $usrLimiter;
	protected $passedUser = null;
	protected $errMessage;
	protected $isWrongTry = false;

	/**
	 * Builds the check against the password file from its config block.
	 *
	 * @param Array $configValues Config block with a 'passFile' file path (relative to the project root),
	 *                          and optionally 'weakHash' => true to accept weak {SHA}, DES and MD5 crypt hashes (refused by default),
	 *                          the wrong passwords allowed for one account ('maxTries', defaults to 5; 0 for no limit) and the
	 *                          time in seconds they add up within and then hold it for ('holdSecs', defaults to 600).
	 */
	function __construct( Array $configValues ){
		$passFile = $configValues['passFile'] ?? '';
		$this->hashesFile = ($passFile!='') ? TOP_DIR.DS.$passFile : '';
		$this->canUseWeak = (($configValues['weakHash'] ?? false) === true);
		// The wrong credentials of each account are counted beside those of each address, in the folder of the limits
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$limitCfg = ($nyuConfg!==null) ? $nyuConfg->obtainRowValue('ACCESS', 'TRYLIMIT') : null;
		$this->usrLimiter = new \nyu\Access\Limiter( Array(
			'maxTries'=>$configValues['maxTries'] ?? 5, 'holdSecs'=>$configValues['holdSecs'] ?? 600, 'dataPath'=>is_array($limitCfg) ? strval($limitCfg['dataPath'] ?? '') : ''
		) );		$this->errMessage = '';
	}

	/**
	 * Verifies a username/password pair against the .htpasswd file,
	 * supporting bcrypt, Apache apr1 (MD5) and SHA-256 or SHA-512 crypt
	 * htpasswd hashes, plus {SHA}, legacy crypt (DES) and MD5 crypt
	 * hashes in unsecure mode only.
	 *
	 * @param Array $loginDataLst Must contain 'user' and 'pass' entries.
	 * @return Array|Bool An identity array on success, or false on failure.
	 */
	function checkSecretArr( Array $loginDataLst ): Array|Bool {
		$givenUsr = $loginDataLst['user'] ?? '';
		$givenPwd = $loginDataLst['pass'] ?? '';
		$this->isWrongTry = false;
		$this->passedUser = null;
		if( $this->hashesFile=='' || !file_exists($this->hashesFile) ){
			$this->errMessage = 'Credential store unavailable';
			return false;
		}
		$passNtry = file( $this->hashesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$userHash = null;
		$fakeHash = null;
		foreach( $passNtry as $pwdRow ){
			$colonIdx = strpos( $pwdRow, ':' );
			// The first entry is what an unknown name is checked against : a comment is none
			if( $fakeHash===null && $colonIdx!==false && $colonIdx>0 && $pwdRow[0]!=='#' && substr($pwdRow, $colonIdx+1)!=='' ){
				$fakeHash = substr( $pwdRow, $colonIdx+1 );
			}
			// An empty name is the one of no user, whatever a line may start with
			if( $givenUsr!=='' && $colonIdx!==false && substr($pwdRow, 0, $colonIdx)===$givenUsr ){
				$userHash = substr( $pwdRow, $colonIdx+1 );
				break;
			}
		}
		if( $userHash===null ){
			// Unknown user : the hash of the first entry is checked as a real one is, at the cost the file's own hashes have, so the reply time does not tell which names exist
			($fakeHash!==null) ? $this->verifyHtpasswd($givenPwd, $fakeHash) : password_verify( $givenPwd, Native::getDummyBcrypt() );
		}else{
			$matxList = $this->verifyHtpasswd($givenPwd, $userHash);
			// A client already used by the user : its mark, while it has not failed too often itself; its failures are counted on it
			$countNme = strval($givenUsr);
			if( is_string($loginDataLst['mark'] ?? null) && $loginDataLst['mark']!=='' ){
				// Signed with the hash the password has now : a password set after the mark was made ends it
				$markClef = \nyu\Access\LoginMark::fromLimitBlock()->readClientMark( $loginDataLst['mark'], $countNme, $userHash );
				if( $markClef!='' && $this->usrLimiter->heldByUserNumb($markClef)>0 ){
					\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Login mark refused for user '.json_encode($givenUsr).': too many attempts with it' );
				}elseif( $markClef!='' ){
					$countNme = $markClef;
				}
			}
			// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
			if( $this->usrLimiter->heldByUserNumb($countNme)>0 ){
				\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($givenUsr).' is held after too many attempts' );
			}elseif( $matxList ){
				$this->usrLimiter->clearLimitUser( $countNme );
				$this->passedUser = Array('systemName'=>strval($givenUsr), 'passwdHash'=>$userHash);
				return Array('userName'=>$givenUsr);
			}else{
				$this->usrLimiter->countLimitUser( $countNme );
			}
		}
		$this->errMessage = 'Invalid credentials';
		$this->isWrongTry = true;
		return false;
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
	 * Compares a password with a stored htpasswd hash, whatever its format.
	 * Bcrypt, apr1 and SHA-256 or SHA-512 crypt ('$5$', '$6$') are accepted
	 * as they are. {SHA} (unsalted SHA-1), DES crypt (any hash not starting
	 * with '$') and MD5 crypt ('$1$'), like any other format, are too weak
	 * to protect a password: they are refused, and the refusal logged,
	 * unless the unsecure mode is on.
	 *
	 * @param String $usrPasswdTxt The password to check.
	 * @param String $htpasswdHash The hash read from the .htpasswd file.
	 * @return Bool Whether the password matches the hash.
	 */
	protected function verifyHtpasswd( String $usrPasswdTxt, String $htpasswdHash ): Bool {
		if( in_array(substr($htpasswdHash,0,4), Array('$2y$','$2a$','$2b$')) ){
			return password_verify( $usrPasswdTxt, $htpasswdHash );
		}
		if( substr($htpasswdHash,0,6)=='$apr1$' ){
			$saltVals = explode('$', $htpasswdHash)[2] ?? '';
			return hash_equals( $htpasswdHash, $this->computeApr1Md5($usrPasswdTxt, $saltVals) );
		}
		$shaDigst = (substr($htpasswdHash,0,5)=='{SHA}');
		// SHA-256 and SHA-512 crypt are the only other formats strong enough to be accepted as they are
		$strnFrmt = in_array(substr($htpasswdHash,0,3), Array('$5$', '$6$'), true);
		if( !$strnFrmt && !$this->canUseWeak ){
			\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Credential refused: {SHA}, DES and MD5 htpasswd hashes need the "weakHash" option' );
			return false;
		}
		if( $shaDigst ){
			return hash_equals( $htpasswdHash, '{SHA}'.base64_encode(sha1($usrPasswdTxt, true)) );
		}
		return hash_equals( $htpasswdHash, crypt($usrPasswdTxt, $htpasswdHash) );
	}

	/**
	 * Computes Apache's apr1 (iterated MD5) hash, which PHP's crypt()
	 * does not provide.
	 *
	 * @param String $usrPasswdTxt The password to hash.
	 * @param String $hashSaltText The salt, as found between the second and third '$' of the stored hash.
	 * @return String The full '$apr1$salt$hash' string.
	 */
	protected function computeApr1Md5( String $usrPasswdTxt, String $hashSaltText ): String {
		$passSize = strlen($usrPasswdTxt);
		$mixedTxt = $usrPasswdTxt.'$apr1$'.$hashSaltText;
		$bnryHash = md5($usrPasswdTxt.$hashSaltText.$usrPasswdTxt, true);
		for( $idxInc=$passSize; $idxInc>0; $idxInc-=16 ){
			$mixedTxt .= substr($bnryHash, 0, min(16, $idxInc));
		}
		for( $idxInc=$passSize; $idxInc>0; $idxInc>>=1 ){
			$mixedTxt .= ($idxInc & 1) ? chr(0) : $usrPasswdTxt[0];
		}
		$bnryHash = md5($mixedTxt, true);
		for( $idxInc=0; $idxInc<1000; $idxInc++ ){
			$roundTxt = ($idxInc & 1) ? $usrPasswdTxt : $bnryHash;
			if( $idxInc % 3 ){
				$roundTxt .= $hashSaltText;
			}
			if( $idxInc % 7 ){
				$roundTxt .= $usrPasswdTxt;
			}
			$roundTxt .= ($idxInc & 1) ? $bnryHash : $usrPasswdTxt;
			$bnryHash = md5($roundTxt, true);
		}
		$ordrdTxt = '';
		for( $idxInc=0; $idxInc<5; $idxInc++ ){
			$thrdIndx = ($idxInc==4) ? 5 : $idxInc+12;
			$ordrdTxt = $bnryHash[$idxInc].$bnryHash[$idxInc+6].$bnryHash[$thrdIndx].$ordrdTxt;
		}
		$ordrdTxt = chr(0).chr(0).$bnryHash[11].$ordrdTxt;
		$encdHash = strtr(
			strrev(substr(base64_encode($ordrdTxt), 2)),
			'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
			'./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
		);
		return '$apr1$'.$hashSaltText.'$'.$encdHash;
	}

	/**
	 * Tells whether the last refusal was a wrong credential, as opposed to
	 * a file that cannot be read: only a wrong credential counts against
	 * the address that presented it (see Access\Limiter).
	 *
	 * @return Bool True when a credential was presented and refused.
	 */
	public function hasWrongSecret(): Bool {
		return $this->isWrongTry;
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
