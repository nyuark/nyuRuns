<?php

namespace nyu\Access\Sess;

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
	const SITELIST = Array('strict'=>'Strict', 'relaxd'=>'Lax');

	protected $authObjc;
	protected $cookName;
	protected $sessLife;
	protected $sameSite;
	protected $onlyHttp;
	protected $lastMssg;

	/**
	 * @param Array     $confValues Config block with the 'cookie' name (default 'nyuSessn'), the 'lifetm' in
	 * 		seconds a session may stay unused (default 3600), the 'samest' policy ('strict'
	 * 		by default, or 'relaxd') and 'secure' (true by default: the cookie only travels over HTTPS).
	 * @param AuthHndlr $authHandlr Optional credential store confirming the session's user (see verifyReq).
	 */
	function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
		$cookNme = strval($confValues['cookie'] ?? 'nyuSessn');
		$this->authObjc = $authHandlr;
		$this->cookName = preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $cookNme) ? $cookNme : 'nyuSessn';
		$this->sessLife = max( 1, intval($confValues['lifetm'] ?? 3600) );
		$this->sameSite = self::SITELIST[$confValues['samest'] ?? 'strict'] ?? 'Strict';
		$this->onlyHttp = (($confValues['secure'] ?? true) !== false);
		$this->lastMssg = '';
	}

	/**
	 * Opens the session named by the request's cookie and returns the
	 * identity it holds. A session left unused for longer than its
	 * lifetime is destroyed. With a credential store offering userIdent(),
	 * the user must still be allowed in, and its current identity (role)
	 * replaces the session's.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The identity array, or false on failure.
	 */
	function verifyReq( Request $requestObj ): Array|Bool {
		$sessIdn = $this->cookieSid($requestObj);
		if( $sessIdn=='' ){
			$this->lastMssg = 'No active session';
			return false;
		}
		if( !$this->startSess($sessIdn) ){
			$this->lastMssg = 'Session unavailable';
			return false;
		}
		$idntArr = $_SESSION['nyuIdent'] ?? null;
		if( !is_array($idntArr) || intval($_SESSION['nyuStamp'] ?? 0)<time() - $this->sessLife ){
			$this->lastMssg = is_array($idntArr) ? 'Session expired' : 'No active session';
			$this->closeSess();
			return false;
		}
		// A credential store that knows its users confirms the session's user is still allowed in
		if( $this->authObjc!==null && method_exists($this->authObjc, 'userIdent') ){
			$nowIdnt = $this->authObjc->userIdent( strval($idntArr['user'] ?? '') );
			if( $nowIdnt===false ){
				$this->lastMssg = $this->authObjc->lastError();
				$this->closeSess();
				return false;
			}
			$idntArr = array_merge($idntArr, $nowIdnt);
		}
		$_SESSION['nyuStamp'] = time();
		session_write_close();
		return $idntArr;
	}

	/**
	 * Opens a new session for the given identity, under a new session
	 * id. The cookie carrying it is sent with the response.
	 *
	 * @param Array $identArray The identity to keep in the session.
	 * @return String The session id, or an empty string when no session can be opened.
	 */
	function issueTokn( Array $identArray ): String {
		if( !$this->startSess() ){
			$this->lastMssg = 'Session unavailable';
			return '';
		}
		// Never keep an id the client came with
		session_regenerate_id(true);
		$_SESSION = Array('nyuIdent'=>$identArray, 'nyuStamp'=>time());
		$sessIdn = strval(session_id());
		session_write_close();
		return $sessIdn;
	}

	/**
	 * Destroys the session named by the request's cookie, and has the
	 * client forget the cookie.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 */
	public function dropSessn( Request $requestObj ): Void {
		$sessIdn = $this->cookieSid($requestObj);
		if( $sessIdn!='' && $this->startSess($sessIdn) ){
			$this->closeSess();
		}
	}

	/**
	 * Reads the session id carried by the request's cookie.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return String The session id, empty when there is none or it is not shaped like one.
	 */
	protected function cookieSid( Request $requestObj ): String {
		$sessIdn = $requestObj->getCookie($this->cookName);
		return ( is_string($sessIdn) && preg_match('/^[A-Za-z0-9,-]{20,128}$/', $sessIdn) ) ? $sessIdn : '';
	}

	/**
	 * Starts the PHP session with this carrier's cookie settings.
	 *
	 * @param String $sessnIdent Id of the session to open; a new one when empty.
	 * @return Bool Whether the session is started.
	 */
	protected function startSess( String $sessnIdent = '' ): Bool {
		if( session_status()===PHP_SESSION_ACTIVE ){
			session_write_close();
		}
		// An id PHP did not issue is never adopted, and the id only travels in the cookie
		ini_set('session.use_strict_mode', '1');
		ini_set('session.use_only_cookies', '1');
		ini_set('session.use_trans_sid', '0');
		ini_set('session.gc_maxlifetime', strval(max($this->sessLife, intval(ini_get('session.gc_maxlifetime')))));
		session_cache_limiter('');
		session_name($this->cookName);
		session_set_cookie_params( $this->cookieSet(0) );
		if( $sessnIdent!='' ){
			session_id($sessnIdent);
		}
		return session_start();
	}

	/**
	 * Destroys the started session and has the client forget its cookie.
	 */
	protected function closeSess(): Void {
		$_SESSION = Array();
		session_destroy();
		// Drops the cookie PHP may have queued for a new id, then expires the client's
		header_remove('Set-Cookie');
		setcookie( $this->cookName, '', $this->cookieSet(1) );
	}

	/**
	 * Builds the settings of the session cookie.
	 *
	 * @param Int $expiryTime Expiry time of the cookie; 0 for the browser session.
	 * @return Array The settings, as session_set_cookie_params() and setcookie() take them.
	 */
	protected function cookieSet( Int $expiryTime ): Array {
		$setVals = Array('path'=>'/', 'secure'=>$this->onlyHttp, 'httponly'=>true, 'samesite'=>$this->sameSite);
		$setVals[ $expiryTime==0 ? 'lifetime' : 'expires' ] = $expiryTime;
		return $setVals;
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
