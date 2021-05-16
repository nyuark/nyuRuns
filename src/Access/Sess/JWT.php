<?php

namespace nyu\Access\Sess;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;
use Firebase\JWT\JWT as JwtCodec;
use Firebase\JWT\Key;
use \Exception;

/**
 * Carries an identity via a signed JWT bearer token. Self-verifying
 * via the configured secret — ignores the Auth\Handler, if given.
 */
class JWT implements Handler{

	protected $jwtSecrt;
	protected $jwtAlgor;
	protected $jwtExpir;
	protected $lastMssg;

	/**
	 * @param Array     $confValues Config block with 'secret', 'alg' (default 'HS256') and 'ttl' (default 3600) keys.
	 * @param AuthHndlr $authHandlr Unused — JWT self-verifies via signature.
	 */
	function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
		$this->jwtSecrt = $confValues['secret'] ?? '';
		$this->jwtAlgor = $confValues['alg'] ?? 'HS256';
		$this->jwtExpir = $confValues['ttl'] ?? 3600;
		$this->lastMssg = '';
	}

	/**
	 * Extracts and verifies a Bearer token from the Authorization header.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The decoded identity array, or false on failure.
	 */
	function verifyReq( Request $requestObj ): Array|Bool {
		$authHed = $requestObj->getHeader('Authorization');
		if( $authHed===null || stripos($authHed, 'Bearer ')!==0 ){
			$this->lastMssg = 'Missing Bearer Authorization header';
			return false;
		}
		if( $this->jwtSecrt=='' ){
			$this->lastMssg = 'JWT secret not configured';
			return false;
		}
		$rawTokn = substr( $authHed, 7 );
		try{
			$payload = JwtCodec::decode( $rawTokn, new Key($this->jwtSecrt, $this->jwtAlgor) );
			return (array)$payload;
		}catch( Exception $e ){
			$this->lastMssg = $e->getMessage();
			return false;
		}
	}

	/**
	 * Issues a signed JWT for the given identity, expiring after the
	 * configured TTL.
	 *
	 * @param Array $identArray The identity to encode into the token.
	 * @return String The issued JWT.
	 */
	function issueTokn( Array $identArray ): String {
		$payload = $identArray;
		$payload['iat'] = time();
		$payload['exp'] = time() + $this->jwtExpir;
		return JwtCodec::encode( $payload, $this->jwtSecrt, $this->jwtAlgor );
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
