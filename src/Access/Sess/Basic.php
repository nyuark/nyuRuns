<?php

namespace nyu\Access\Sess;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;

/**
 * Carries credentials via the standard HTTP Basic Authorization
 * header, delegating verification to an Auth\Handler.
 */
class Basic implements Handler{

	protected $authObjc;
	protected $lastMssg;

	/**
	 * @param Array     $confValues Unused for this strategy.
	 * @param AuthHndlr $authHandlr Credential-verification strategy to delegate to.
	 */
	function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
		$this->authObjc = $authHandlr;
		$this->lastMssg = '';
	}

	/**
	 * Parses the Authorization: Basic header and checks the decoded
	 * credentials against the delegated Auth handler.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The verified identity array, or false on failure.
	 */
	function verifyReq( Request $requestObj ): Array|Bool {
		$authHed = $requestObj->getHeader('Authorization');
		if( $authHed===null || stripos($authHed, 'Basic ')!==0 ){
			$this->lastMssg = 'Missing Basic Authorization header';
			return false;
		}
		$decoded = base64_decode( substr($authHed, 6), true );
		if( $decoded===false || strpos($decoded, ':')===false ){
			$this->lastMssg = 'Malformed Basic credentials';
			return false;
		}
		list($gvnUser, $gvnPass) = explode( ':', $decoded, 2 );

		if( $this->authObjc===null ){
			$this->lastMssg = 'No credential handler configured';
			return false;
		}
		$idntArr = $this->authObjc->checkCred( Array('user'=>$gvnUser, 'pass'=>$gvnPass) );
		if( $idntArr===false ){
			$this->lastMssg = $this->authObjc->lastError();
			return false;
		}
		return $idntArr;
	}

	/**
	 * Not applicable — this is not a token-issuing strategy.
	 *
	 * @param Array $identArray Unused.
	 * @return String Always an empty string.
	 */
	function issueTokn( Array $identArray ): String {
		return '';
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
