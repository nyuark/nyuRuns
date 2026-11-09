<?php

namespace nyu\Access\Auth;

/**
 * Verifies credentials against an Apache-style .htpasswd file, so the
 * same file can be shared with an Apache/Nginx Basic-auth config. The
 * wrong passwords of each account are counted in the folder of the limits
 * (see Access\Limiter): after 'maxtry' of them within 'holdtm', the account
 * is refused, whatever it gives, until the hold is over.
 */
class PassList implements Handler{

	// Bcrypt hash of a discarded random string, checked when the user is unknown
	const FAKEHASH = '$2y$10$vRez8inUYw3A1ZAIcgzLduI5/8q6rGAJtWDgKI6oWkhBpt58FBnw.';

	protected $htpwFile;
	protected $weakHash;
	protected $userLmtr;
	protected $lastMssg;
	protected $wrongCrd = false;

	/**
	 * Builds the check against the password file from its config block.
	 *
	 * @param Array $confValues Config block with an 'htpasswd' file path (relative to the project root),
	 *                          and optionally 'unsecure' => true to accept weak {SHA}, DES and MD5 crypt hashes (refused by default),
	 *                          the wrong passwords allowed for one account ('maxtry', defaults to 5; 0 for no limit) and the
	 *                          time in seconds they add up within and then hold it for ('holdtm', defaults to 600).
	 */
	function __construct( Array $confValues ){
		$htpPath = $confValues['htpasswd'] ?? '';
		$this->htpwFile = ($htpPath!='') ? TOP_DIR.DS.$htpPath : '';
		$this->weakHash = (($confValues['unsecure'] ?? false) === true);
		// The wrong credentials of each account are counted beside those of each address, in the folder of the limits
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'LIMITS') : null;
		$this->userLmtr = new \nyu\Access\Limiter( Array(
			'maxtry'=>$confValues['maxtry'] ?? 5, 'holdtm'=>$confValues['holdtm'] ?? 600, 'folder'=>is_array($lmtConf) ? strval($lmtConf['folder'] ?? '') : ''
		) );		$this->lastMssg = '';
	}

	/**
	 * Verifies a username/password pair against the .htpasswd file,
	 * supporting bcrypt, Apache apr1 (MD5) and SHA-256 or SHA-512 crypt
	 * htpasswd hashes, plus {SHA}, legacy crypt (DES) and MD5 crypt
	 * hashes in unsecure mode only.
	 *
	 * @param Array $credsArray Must contain 'user' and 'pass' entries.
	 * @return Array|Bool An identity array on success, or false on failure.
	 */
	function checkCred( Array $credsArray ): Array|Bool {
		$gvnUser = $credsArray['user'] ?? '';
		$gvnPass = $credsArray['pass'] ?? '';
		$this->wrongCrd = false;
		if( $this->htpwFile=='' || !file_exists($this->htpwFile) ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		$htpList = file( $this->htpwFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$usrHash = null;
		foreach( $htpList as $htpLin ){
			$sepPosn = strpos( $htpLin, ':' );
			// An empty name is the one of no user, whatever a line may start with
			if( $gvnUser!=='' && $sepPosn!==false && substr($htpLin, 0, $sepPosn)===$gvnUser ){
				$usrHash = substr( $htpLin, $sepPosn+1 );
				break;
			}
		}
		if( $usrHash===null ){
			// Unknown user : a hash is still computed, so the reply time does not tell which names exist
			password_verify( $gvnPass, self::FAKEHASH );
		}else{
			$matched = $this->hashMatch($gvnPass, $usrHash);
			// Too many failed attempts : nothing is accepted or counted until the hold is over, and the reply does not tell it
			if( $this->userLmtr->heldByUsr(strval($gvnUser))>0 ){
				\nyu\Tools\Logger::getLogger('access')->warning( 'Credential refused: user '.json_encode($gvnUser).' is held after too many attempts' );
			}elseif( $matched ){
				$this->userLmtr->clearUser( strval($gvnUser) );
				return Array('user'=>$gvnUser);
			}else{
				$this->userLmtr->countUser( strval($gvnUser) );
			}
		}
		$this->lastMssg = 'Invalid credentials';
		$this->wrongCrd = true;
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
		// SHA-256 and SHA-512 crypt are the only other formats strong enough to be accepted as they are
		$strngFm = in_array(substr($storedHash,0,3), Array('$5$', '$6$'), true);
		if( !$strngFm && !$this->weakHash ){
			\nyu\Tools\Logger::getLogger('access')->warning( 'Credential refused: {SHA}, DES and MD5 htpasswd hashes need the "unsecure" option' );
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
	 * Tells whether the last refusal was a wrong credential, as opposed to
	 * a file that cannot be read: only a wrong credential counts against
	 * the address that presented it (see Access\Limiter).
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
