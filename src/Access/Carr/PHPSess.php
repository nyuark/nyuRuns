<?php

namespace nyu\Access\Carr;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;

/**
 * Carries an identity in a server-side PHP session, found through its
 * cookie. Meant for browser clients: the login opens the session, the
 * browser sends the cookie back by itself, the logout destroys it. When
 * an Auth\Handler that knows its users is given, the session's user is
 * also confirmed against it on every request.
 */
class PHPSess implements Handler{

	// SameSite policies of the cookie, by their config short name
	const SITE_LIST = Array('strict'=>'Strict', 'lax'=>'Lax');

	protected $authObject;
	protected $cookieName;
	protected $unusedSecs;
	protected $entireLife;
	protected $saveFolder;
	protected $sitePolicy;
	protected $isHttpOnly;
	protected $errMessage;

	/**
	 * Builds the PHP session carrier from its config block.
	 *
	 * @param Array     $configValues Config block with the 'cookName' name (default 'nyuSessn'), the 'lifeSecs' in
	 * 		seconds a session may stay unused (default 3600), the 'fullSecs' in seconds it may
	 * 		live in all, however much it is used (default 43200; 0 for no limit), the 'sameSite'
	 * 		policy ('strict' by default, or 'lax'), 'secureOn' (true by default: the cookie only
	 * 		travels over HTTPS) and the 'dataPath' its sessions are kept in, from the project root
	 * 		(PHP's own when left empty).
	 * @param ?AuthHndlr $authStrategy Optional credential store confirming the session's user (see verifyReq).
	 */
	function __construct( Array $configValues, ?AuthHndlr $authStrategy = null ){
		$ckieName = strval($configValues['cookName'] ?? 'nyuSessn');
		$this->authObject = $authStrategy;
		$this->cookieName = preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $ckieName) ? $ckieName : 'nyuSessn';
		$this->unusedSecs = max( 1, intval($configValues['lifeSecs'] ?? 3600) );
		$this->entireLife = max( 0, intval($configValues['fullSecs'] ?? 43200) );
		$fieldNme = is_scalar($configValues['dataPath'] ?? '') ? trim( strval($configValues['dataPath'] ?? ''), '/\\ ' ) : '';
		$this->saveFolder = ($fieldNme!='') ? TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $fieldNme) : '';
		$this->sitePolicy = self::SITE_LIST[$configValues['sameSite'] ?? 'strict'] ?? 'Strict';
		$this->isHttpOnly = (($configValues['secureOn'] ?? true) !== false);
		$this->errMessage = '';
	}

	/**
	 * Opens the session named by the request's cookie and returns the
	 * identity it holds. A session left unused for longer than its
	 * lifetime is destroyed, and so is one opened longer ago than a
	 * session may live in all ('fullSecs'). With a credential store offering usrIdentityArr(),
	 * the user must still be allowed in, and its current identity (role)
	 * replaces the session's.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return Array|Bool The identity array, or false on failure.
	 */
	function verifyRqstList( Request $givenRequest ): Array|Bool {
		$sessIdnt = $this->cookUserSidTxt($givenRequest);
		if( $sessIdnt=='' ){
			$this->errMessage = 'No active session';
			return false;
		}
		if( !$this->startSessnFlag($sessIdnt) ){
			$this->errMessage = 'Session unavailable';
			return false;
		}
		$idntList = $_SESSION['nyuIdent'] ?? null;
		// Left unused for too long, or open for longer than a session may live, however much it was used
		$hasEnded = intval($_SESSION['nyuStamp'] ?? 0)<time() - $this->unusedSecs
			|| ($this->entireLife>0 && intval($_SESSION['nyuBirth'] ?? 0)<time() - $this->entireLife);
		if( !is_array($idntList) || $hasEnded ){
			$this->errMessage = is_array($idntList) ? 'Session expired' : 'No active session';
			$this->closeUserSessn();
			return false;
		}
		// A credential store that knows its users confirms the session's user is still allowed in
		if( $this->authObject!==null && method_exists($this->authObject, 'usrIdentityArr') ){
			// The start of the session : a password set since ends it
			$nowIdent = $this->authObject->usrIdentityArr( strval($idntList['userName'] ?? ''), intval($_SESSION['nyuBirth'] ?? 0) );
			if( $nowIdent===false ){
				$this->errMessage = $this->authObject->finalErrorText();
				$this->closeUserSessn();
				return false;
			}
			$idntList = array_merge($idntList, $nowIdent);
		}
		$_SESSION['nyuStamp'] = time();
		session_write_close();
		return $idntList;
	}

	/**
	 * Opens a new session for the given identity, under a new session
	 * id. The cookie carrying it is sent with the response.
	 *
	 * @param Array $identityList The identity to keep in the session.
	 * @return String The session id, or an empty string when no session can be opened.
	 */
	function issueTokenText( Array $identityList ): String {
		if( !$this->startSessnFlag() ){
			$this->errMessage = 'Session unavailable';
			return '';
		}
		// Never keep an id the client came with
		session_regenerate_id(true);
		$_SESSION = Array('nyuIdent'=>$identityList, 'nyuStamp'=>time(), 'nyuBirth'=>time());
		$sessIdnt = strval(session_id());
		session_write_close();
		return $sessIdnt;
	}

	/**
	 * Destroys the session named by the request's cookie, and has the
	 * client forget the cookie.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 */
	public function dropUsrSession( Request $givenRequest ): Void {
		$sessIdnt = $this->cookUserSidTxt($givenRequest);
		if( $sessIdnt!='' && $this->startSessnFlag($sessIdnt) ){
			$this->closeUserSessn();
		}
	}

	/**
	 * Reads the session id carried by the request's cookie.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return String The session id, empty when there is none or it is not shaped like one.
	 */
	protected function cookUserSidTxt( Request $givenRequest ): String {
		$sessIdnt = $givenRequest->retrieveCookie($this->cookieName);
		return ( is_string($sessIdnt) && preg_match('/^[A-Za-z0-9,-]{20,128}$/', $sessIdnt) ) ? $sessIdnt : '';
	}

	/**
	 * Starts the PHP session with this carrier's cookie settings.
	 *
	 * @param String $sessIdentity Id of the session to open; a new one when empty.
	 * @return Bool Whether the session is started.
	 */
	protected function startSessnFlag( String $sessIdentity = '' ): Bool {
		if( session_status()===PHP_SESSION_ACTIVE ){
			session_write_close();
		}
		// An id PHP did not issue is never adopted, and the id only travels in the cookie
		ini_set('session.use_strict_mode', '1');
		ini_set('session.use_only_cookies', '1');
		ini_set('session.use_trans_sid', '0');
		ini_set('session.gc_maxlifetime', strval(max($this->unusedSecs, intval(ini_get('session.gc_maxlifetime')))));
		session_cache_limiter('');
		// A folder of its own keeps the sessions away from the other PHP applications of the server
		if( $this->saveFolder!='' ){
			if( !is_dir($this->saveFolder) ){
				@mkdir($this->saveFolder, 0700, true);
			}
			if( !is_dir($this->saveFolder) || !is_writable($this->saveFolder) ){
				return false;
			}
			session_save_path($this->saveFolder);
			// Where the system cleans PHP's own folder by itself, nothing would clean this one
			ini_set('session.gc_probability', '1');
			ini_set('session.gc_divisor', '100');
		}
		session_name($this->cookieName);
		session_set_cookie_params( $this->cookieSetArray(0) );
		if( $sessIdentity!='' ){
			session_id($sessIdentity);
		}
		return session_start();
	}

	/**
	 * Destroys the started session and has the client forget its cookie.
	 */
	protected function closeUserSessn(): Void {
		$_SESSION = Array();
		session_destroy();
		// Drops the cookie PHP may have queued for a new id, and no other cookie of the reply, then expires the client's
		$keptList = Array();
		foreach( headers_list() as $hdrLin ){
			if( stripos($hdrLin, 'Set-Cookie:')===0 && stripos(ltrim(substr($hdrLin, 11)), $this->cookieName.'=')!==0 ){
				$keptList[] = $hdrLin;
			}
		}
		header_remove('Set-Cookie');
		foreach( $keptList as $hdrLin ){
			header($hdrLin, false);
		}
		setcookie( $this->cookieName, '', $this->cookieSetArray(1) );
	}

	/**
	 * Builds the settings of the session cookie.
	 *
	 * @param Int $expiryTmeNum Expiry time of the cookie; 0 for the browser session.
	 * @return Array The settings, as session_set_cookie_params() and setcookie() take them.
	 */
	protected function cookieSetArray( Int $expiryTmeNum ): Array {
		$optsDict = Array('path'=>'/', 'secure'=>$this->isHttpOnly, 'httponly'=>true, 'samesite'=>$this->sitePolicy);
		$optsDict[ $expiryTmeNum==0 ? 'lifetime' : 'expires' ] = $expiryTmeNum;
		return $optsDict;
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
