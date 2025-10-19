<?php

namespace nyu\Access\Sess;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;
use Firebase\JWT\JWT as JwtCodec;
use Firebase\JWT\Key;

/**
 * Carries an identity via a signed JWT bearer token, verified by its
 * signature. When an Auth\Handler that knows its users is given, the
 * token's user is also confirmed against it on every request.
 */
class JWT implements Handler{

	protected $authObjc;
	protected $jwtSecrt;
	protected $jwtAlgor;
	protected $jwtExpir;
	protected $lastMssg;
	protected $wrongCrd = false;

	/**
	 * @param Array     $confValues Config block with 'secret', 'algrtm' (default 'HS256') and 'lifetm' (seconds, default 3600) keys.
	 * @param AuthHndlr $authHandlr Optional credential store confirming the token's user (see verifyReq).
	 */
	function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
		$this->authObjc = $authHandlr;
		$this->jwtSecrt = $confValues['secret'] ?? '';
		$this->jwtAlgor = $confValues['algrtm'] ?? 'HS256';
		$this->jwtExpir = $confValues['lifetm'] ?? 3600;
		$this->lastMssg = '';
	}

	/**
	 * Extracts and verifies a Bearer token from the Authorization header.
	 * A token that is not valid is told as 'Invalid token', one that ended
	 * as 'Expired token': why the library refused it goes to the log only.
	 * A signed token without an expiry or a user is not valid either.
	 * With a credential store offering userIdent(), the user must still be
	 * allowed in, and its current identity (role) replaces the token's.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The decoded identity array, or false on failure.
	 */
	function verifyReq( Request $requestObj ): Array|Bool {
		$this->wrongCrd = false;
		$authHed = $requestObj->getHeader('Authorization');
		if( $authHed===null || stripos($authHed, 'Bearer ')!==0 ){
			$this->lastMssg = 'Missing Bearer Authorization header';
			return false;
		}
		if( !$this->hasSecret() ){
			$this->lastMssg = 'JWT secret not configured';
			return false;
		}
		$rawTokn = substr( $authHed, 7 );
		try{
			$payload = JwtCodec::decode( $rawTokn, new Key($this->jwtSecrt, $this->jwtAlgor) );
			$idntArr = (array)$payload;
		}catch( \Firebase\JWT\ExpiredException $e ){
			$this->lastMssg = 'Expired token';
			return false;
		}catch( \Throwable $e ){
			// What the library found is for the log : the client is only told the token is not valid
			\nyu\Tools\Logger::getLogger('access')->debug( 'Token refused: '.get_class($e).': '.$e->getMessage() );
			$this->lastMssg = 'Invalid token';
			$this->wrongCrd = true;
			return false;
		}
		// A token names its user and ends : one that does neither was not issued here
		if( !is_numeric($idntArr['exp'] ?? null) || !is_scalar($idntArr['user'] ?? null) || strval($idntArr['user'])==='' ){
			\nyu\Tools\Logger::getLogger('access')->debug( 'Token refused: signed, but without an expiry or a user' );
			$this->lastMssg = 'Invalid token';
			return false;
		}
		// A credential store that knows its users confirms the token's user is still allowed in
		if( $this->authObjc!==null && method_exists($this->authObjc, 'userIdent') ){
			$nowIdnt = $this->authObjc->userIdent( strval($idntArr['user'] ?? '') );
			if( $nowIdnt===false ){
				$this->lastMssg = $this->authObjc->lastError();
				return false;
			}
			$idntArr = array_merge($idntArr, $nowIdnt);
		}
		return $idntArr;
	}

	/**
	 * Issues a signed JWT for the given identity, expiring after the
	 * configured TTL.
	 *
	 * @param Array $identArray The identity to encode into the token.
	 * @return String The issued JWT, or an empty string when the secret is not usable.
	 */
	function issueTokn( Array $identArray ): String {
		if( !$this->hasSecret() ){
			$this->lastMssg = 'JWT secret not configured';
			return '';
		}
		$payload = $identArray;
		$payload['iat'] = time();
		$payload['exp'] = time() + $this->jwtExpir;
		return JwtCodec::encode( $payload, $this->jwtSecrt, $this->jwtAlgor );
	}

	/**
	 * Tells whether the configured secret can sign a token: set, and
	 * at least 32 bytes long (the minimum for HS256).
	 *
	 * @return Bool Whether the secret is usable.
	 */
	protected function hasSecret(): Bool {
		return is_string($this->jwtSecrt) && strlen($this->jwtSecrt)>=32;
	}

	/**
	 * Tells whether the last refusal was a token that was not issued here
	 * (wrong signature, not a token), as opposed to a missing one, one that
	 * ended, or a user no longer allowed in: only a forged token counts
	 * against the address that presented it (see Access\Limiter).
	 *
	 * @return Bool True when a token was presented and found forged.
	 */
	public function wrongCred(): Bool {
		return $this->wrongCrd;
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
