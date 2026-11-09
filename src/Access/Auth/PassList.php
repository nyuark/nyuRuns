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

	protected $htpsswdFil;
	protected $weakHashes;
	protected $usrLimiter;
	protected $lastMessag;
	protected $invalidCrd = false;

	/**
	 * Builds the check against the password file from its config block.
	 *
	 * @param Array $configValues Config block with a 'passFile' file path (relative to the project root),
	 *                          and optionally 'weakHash' => true to accept weak {SHA}, DES and MD5 crypt hashes (refused by default),
	 *                          the wrong passwords allowed for one account ('maxTries', defaults to 5; 0 for no limit) and the
	 *                          time in seconds they add up within and then hold it for ('holdSecs', defaults to 600).
	 */
	function __construct( Array $configValues ){
		$pwdFile = $configValues['passFile'] ?? '';
		$this->htpsswdFil = ($pwdFile!='') ? TOP_DIR.DS.$pwdFile : '';
		$this->weakHashes = (($configValues['weakHash'] ?? false) === true);
		// The wrong credentials of each account are counted beside those of each address, in the folder of the limits
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'TRYLIMIT') : null;
		$this->usrLimiter = new \nyu\Access\Limiter( Array(
			'maxTries'=>$configValues['maxTries'] ?? 5, 'holdSecs'=>$configValues['holdSecs'] ?? 600, 'dataPath'=>is_array($lmtConf) ? strval($lmtConf['dataPath'] ?? '') : ''
		) );		$this->lastMessag = '';
	}

	/**
	 * Verifies a username/password pair against the .htpasswd file,
	 * supporting bcrypt, Apache apr1 (MD5) and SHA-256 or SHA-512 crypt
	 * htpasswd hashes, plus {SHA}, legacy crypt (DES) and MD5 crypt
	 * hashes in unsecure mode only.
	 *
	 * @param Array $crdntlsArray Must contain 'user' and 'pass' entries.
	 * @return Array|Bool An identity array on success, or false on failure.
	 */
	function chckCredential( Array $crdntlsArray ): Array|Bool {
		$gvnUser = $crdntlsArray['user'] ?? '';
		$gvnPass = $crdntlsArray['pass'] ?? '';
		$this->invalidCrd = false;
		if( $this->htpsswdFil=='' || !file_exists($this->htpsswdFil) ){
			$this->lastMessag = 'Credential store unavailable';
			return false;
		}
		$pwdRows = file( $this->htpsswdFil, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$usrHash = null;
		foreach( $pwdRows as $pwdRow ){
			$colonAt = strpos( $pwdRow, ':' );
			// An empty name is the one of no user, whatever a line may start with
			if( $gvnUser!=='' && $colonAt!==false && substr($pwdRow, 0, $colonAt)===$gvnUser ){
				$usrHash = substr( $pwdRow, $colonAt+1 );
				break;
			}
		}
		if( $usrHash===null ){
			// Unknown user : the same check is run, at the cost of new hashes, so the reply time does not tell which names exist
			password_verify( $gvnPass, Native::getDummyBcrypt() );
		}else{
			$matched = $this->verifyHtpasswd($gvnPass, $usrHash);
			// A client already used by the user : its mark, while it has not failed too often itself; its failures are counted on it
			$cntName = strval($gvnUser);
			if( is_string($crdntlsArray['mark'] ?? null) && $crdntlsArray['mark']!=='' ){
				$markKey = \nyu\Access\LoginMark::fromLimitBlock()->readClientMark( $crdntlsArray['mark'], $cntName );
				if( $markKey!='' && $this->usrLimiter->heldByUserNumb($markKey)>0 ){
					\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Login mark refused for user '.json_encode($gvnUser).': too many attempts with it' );
				}elseif( $markKey!='' ){
					$cntName = $markKey;
				}
			}
			// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
			if( $this->usrLimiter->heldByUserNumb($cntName)>0 ){
				\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Credential refused: user '.json_encode($gvnUser).' is held after too many attempts' );
			}elseif( $matched ){
				$this->usrLimiter->clearLimitUser( $cntName );
				return Array('user'=>$gvnUser);
			}else{
				$this->usrLimiter->countLimitUser( $cntName );
			}
		}
		$this->lastMessag = 'Invalid credentials';
		$this->invalidCrd = true;
		return false;
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
			$saltVal = explode('$', $htpasswdHash)[2] ?? '';
			return hash_equals( $htpasswdHash, $this->computeApr1Md5($usrPasswdTxt, $saltVal) );
		}
		$shaHash = (substr($htpasswdHash,0,5)=='{SHA}');
		// SHA-256 and SHA-512 crypt are the only other formats strong enough to be accepted as they are
		$strngFm = in_array(substr($htpasswdHash,0,3), Array('$5$', '$6$'), true);
		if( !$strngFm && !$this->weakHashes ){
			\nyu\Tools\Logger::retrieveLogger('access')->warning( 'Credential refused: {SHA}, DES and MD5 htpasswd hashes need the "weakHash" option' );
			return false;
		}
		if( $shaHash ){
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
		$pwdSize = strlen($usrPasswdTxt);
		$mixText = $usrPasswdTxt.'$apr1$'.$hashSaltText;
		$bnryHsh = md5($usrPasswdTxt.$hashSaltText.$usrPasswdTxt, true);
		for( $idxInc=$pwdSize; $idxInc>0; $idxInc-=16 ){
			$mixText .= substr($bnryHsh, 0, min(16, $idxInc));
		}
		for( $idxInc=$pwdSize; $idxInc>0; $idxInc>>=1 ){
			$mixText .= ($idxInc & 1) ? chr(0) : $usrPasswdTxt[0];
		}
		$bnryHsh = md5($mixText, true);
		for( $idxInc=0; $idxInc<1000; $idxInc++ ){
			$rndText = ($idxInc & 1) ? $usrPasswdTxt : $bnryHsh;
			if( $idxInc % 3 ){
				$rndText .= $hashSaltText;
			}
			if( $idxInc % 7 ){
				$rndText .= $usrPasswdTxt;
			}
			$rndText .= ($idxInc & 1) ? $bnryHsh : $usrPasswdTxt;
			$bnryHsh = md5($rndText, true);
		}
		$ordered = '';
		for( $idxInc=0; $idxInc<5; $idxInc++ ){
			$thirdIx = ($idxInc==4) ? 5 : $idxInc+12;
			$ordered = $bnryHsh[$idxInc].$bnryHsh[$idxInc+6].$bnryHsh[$thirdIx].$ordered;
		}
		$ordered = chr(0).chr(0).$bnryHsh[11].$ordered;
		$encoded = strtr(
			strrev(substr(base64_encode($ordered), 2)),
			'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
			'./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
		);
		return '$apr1$'.$hashSaltText.'$'.$encoded;
	}

	/**
	 * Tells whether the last refusal was a wrong credential, as opposed to
	 * a file that cannot be read: only a wrong credential counts against
	 * the address that presented it (see Access\Limiter).
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
