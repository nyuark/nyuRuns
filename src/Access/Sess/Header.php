<?php

namespace nyu\Access\Sess;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;

/**
 * Carries an API key via a configurable HTTP header (default
 * X-Api-Key), delegating verification to an Auth\Handler.
 */
class Header implements Handler{

	protected $headName;
	protected $authObjc;
	protected $lastMssg;

	/**
	 * @param Array     $confValues Config block, optionally with a 'header' name (defaults to 'X-Api-Key').
	 * @param AuthHndlr $authHandlr Credential-verification strategy to delegate to.
	 */
	function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
		$this->headName = $confValues['header'] ?? 'X-Api-Key';
		$this->authObjc = $authHandlr;
		$this->lastMssg = '';
	}

	/**
	 * Reads the configured header off the request and checks it
	 * against the delegated Auth handler.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The verified identity array, or false on failure.
	 */
	function verifyReq( Request $requestObj ): Array|Bool {
		$headVal = $requestObj->getHeader( $this->headName );
		if( $headVal===null || $headVal=='' ){
			$this->lastMssg = 'Missing '.$this->headName.' header';
			return false;
		}
		if( $this->authObjc===null ){
			$this->lastMssg = 'No credential handler configured';
			return false;
		}
		$idntArr = $this->authObjc->checkCred( Array('key'=>$headVal) );
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
