<?php

namespace nyu\Access\Auth;

/**
 * Verifies credentials against an Apache-style .htpasswd file, so the
 * same file can be shared with an Apache/Nginx Basic-auth config.
 */
class PassList implements Handler{

	protected $htpwFile;
	protected $lastMssg;

	/**
	 * @param Array $confValues Config block with an 'htpasswd' file path (relative to the project root).
	 */
	function __construct( Array $confValues ){
		$htpPath = $confValues['htpasswd'] ?? '';
		$this->htpwFile = ($htpPath!='') ? TOP_DIR.DS.$htpPath : '';
		$this->lastMssg = '';
	}

	/**
	 * Verifies a username/password pair against the .htpasswd file,
	 * supporting bcrypt hashes (via password_verify) and legacy
	 * apr1/MD5/SHA/DES htpasswd hashes (via crypt comparison).
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
		foreach( $htpList as $htpLin ){
			$sepPosn = strpos( $htpLin, ':' );
			if( $sepPosn===false ){
				continue;
			}
			$linUser = substr( $htpLin, 0, $sepPosn );
			$linHash = substr( $htpLin, $sepPosn+1 );
			if( $linUser!==$gvnUser ){
				continue;
			}
			if( in_array(substr($linHash,0,4), Array('$2y$','$2a$','$2b$')) ){
				$matched = password_verify( $gvnPass, $linHash );
			}else{
				$matched = hash_equals( $linHash, crypt($gvnPass, $linHash) );
			}
			if( $matched ){
				return Array('user'=>$gvnUser);
			}
			$this->lastMssg = 'Invalid credentials';
			return false;
		}
		$this->lastMssg = 'Invalid credentials';
		return false;
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
