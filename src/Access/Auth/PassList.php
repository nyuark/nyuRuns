<?php

namespace nyu\Access\Auth;

/**
 * Verifies credentials against an Apache-style .htpasswd file, so the
 * same file can be shared with an Apache/Nginx Basic-auth config.
 */
class PassList implements Handler{

	// Bcrypt hash of a discarded random string, checked when the user is unknown
	const FAKEHASH = '$2y$10$vRez8inUYw3A1ZAIcgzLduI5/8q6rGAJtWDgKI6oWkhBpt58FBnw.';

	protected $htpwFile;
	protected $weakHash;
	protected $lastMssg;

	/**
	 * @param Array $confValues Config block with an 'htpasswd' file path (relative to the project root),
	 *                          and optionally 'unsecure' => true to accept weak {SHA} and DES hashes (refused by default).
	 */
	function __construct( Array $confValues ){
		$htpPath = $confValues['htpasswd'] ?? '';
		$this->htpwFile = ($htpPath!='') ? TOP_DIR.DS.$htpPath : '';
		$this->weakHash = (($confValues['unsecure'] ?? false) === true);
		$this->lastMssg = '';
	}

	/**
	 * Verifies a username/password pair against the .htpasswd file,
	 * supporting bcrypt and Apache apr1 (MD5) htpasswd hashes, plus
	 * {SHA} and legacy crypt (DES) hashes in unsecure mode only.
	 *
	 * @param Array $credsArray Must contain 'user' and 'pass' entries.
	 * @return Array|Bool An identity array on success, or false on failure.
	 */
	function checkCred( Array $credsArray ): Array|Bool {
		$gvnUser = $credsArray['user'] ?? '';
		$gvnPass = $credsArray['pass'] ?? '';
		if( $gvnUser=='' || $this->htpwFile=='' || !file_exists($this->htpwFile) ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		$htpList = file( $this->htpwFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$usrHash = null;
		foreach( $htpList as $htpLin ){
			$sepPosn = strpos( $htpLin, ':' );
			if( $sepPosn!==false && substr($htpLin, 0, $sepPosn)===$gvnUser ){
				$usrHash = substr( $htpLin, $sepPosn+1 );
				break;
			}
		}
		if( $usrHash===null ){
			// Unknown user : a hash is still computed, so the reply time does not tell which names exist
			password_verify( $gvnPass, self::FAKEHASH );
		}elseif( $this->hashMatch($gvnPass, $usrHash) ){
			return Array('user'=>$gvnUser);
		}
		$this->lastMssg = 'Invalid credentials';
		return false;
	}

	/**
	 * Compares a password with a stored htpasswd hash, whatever its format.
	 * {SHA} (unsalted SHA-1) and DES crypt (any hash not starting with '$')
	 * are too weak to protect a password: they are refused, and the refusal
	 * logged, unless the unsecure mode is on.
	 *
	 * @param String $passwdText The password to check.
	 * @param String $storedHash The hash read from the .htpasswd file.
	 * @return Bool Whether the password matches the hash.
	 */
	protected function hashMatch( String $passwdText, String $storedHash ): Bool {
		if( in_array(substr($storedHash,0,4), Array('$2y$','$2a$','$2b$')) ){
			return password_verify( $passwdText, $storedHash );
		}
		if( substr($storedHash,0,6)=='$apr1$' ){
			$saltVal = explode('$', $storedHash)[2] ?? '';
			return hash_equals( $storedHash, $this->apr1Crypt($passwdText, $saltVal) );
		}
		$shaHash = (substr($storedHash,0,5)=='{SHA}');
		if( ($shaHash || substr($storedHash,0,1)!='$') && !$this->weakHash ){
			$logInst = $GLOBALS['nyuLogger'] ?? null;
			if( $logInst!==null ){
				$logInst->warning( 'Credential refused: {SHA} and DES htpasswd hashes need the "unsecure" option' );
			}
			return false;
		}
		if( $shaHash ){
			return hash_equals( $storedHash, '{SHA}'.base64_encode(sha1($passwdText, true)) );
		}
		return hash_equals( $storedHash, crypt($passwdText, $storedHash) );
	}

	/**
	 * Computes Apache's apr1 (iterated MD5) hash, which PHP's crypt()
	 * does not provide.
	 *
	 * @param String $passwdText The password to hash.
	 * @param String $saltString The salt, as found between the second and third '$' of the stored hash.
	 * @return String The full '$apr1$salt$hash' string.
	 */
	protected function apr1Crypt( String $passwdText, String $saltString ): String {
		$passLen = strlen($passwdText);
		$mixText = $passwdText.'$apr1$'.$saltString;
		$binHash = md5($passwdText.$saltString.$passwdText, true);
		for( $idx=$passLen; $idx>0; $idx-=16 ){
			$mixText .= substr($binHash, 0, min(16, $idx));
		}
		for( $idx=$passLen; $idx>0; $idx>>=1 ){
			$mixText .= ($idx & 1) ? chr(0) : $passwdText[0];
		}
		$binHash = md5($mixText, true);
		for( $idx=0; $idx<1000; $idx++ ){
			$rndText = ($idx & 1) ? $passwdText : $binHash;
			if( $idx % 3 ){
				$rndText .= $saltString;
			}
			if( $idx % 7 ){
				$rndText .= $passwdText;
			}
			$rndText .= ($idx & 1) ? $binHash : $passwdText;
			$binHash = md5($rndText, true);
		}
		$ordered = '';
		for( $idx=0; $idx<5; $idx++ ){
			$thrdIx = ($idx==4) ? 5 : $idx+12;
			$ordered = $binHash[$idx].$binHash[$idx+6].$binHash[$thrdIx].$ordered;
		}
		$ordered = chr(0).chr(0).$binHash[11].$ordered;
		$encoded = strtr(
			strrev(substr(base64_encode($ordered), 2)),
			'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/',
			'./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
		);
		return '$apr1$'.$saltString.'$'.$encoded;
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
