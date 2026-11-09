<?php

namespace nyu\Access\Sess;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;

/**
 * Carries credentials via the standard HTTP Basic Authorization
 * header, delegating verification to an Auth\Handler.
 */
class Basic implements Handler{

	protected $authObject;
	protected $lastMessag;

	/**
	 * Builds the HTTP Basic carrier, with the credential check it hands over to.
	 *
	 * @param Array     $configValues Unused for this strategy.
	 * @param ?AuthHndlr $authStrategy Credential-verification strategy to delegate to.
	 */
	function __construct( Array $configValues, ?AuthHndlr $authStrategy = null ){
		$this->authObject = $authStrategy;
		$this->lastMessag = '';
	}

	/**
	 * Parses the Authorization: Basic header and checks the decoded
	 * credentials against the delegated Auth handler.
	 *
	 * @param Request $requstObject The shared, already-parsed HTTP request.
	 * @return Array|Bool The verified identity array, or false on failure.
	 */
	function verifyRqstList( Request $requstObject ): Array|Bool {
		$authHdr = $requstObject->retrieveHeader('Authorization');
		if( $authHdr===null || stripos($authHdr, 'Basic ')!==0 ){
			$this->lastMessag = 'Missing Basic Authorization header';
			return false;
		}
		$decoded = base64_decode( substr($authHdr, 6), true );
		if( $decoded===false || strpos($decoded, ':')===false ){
			$this->lastMessag = 'Malformed Basic credentials';
			return false;
		}
		list($gvnUser, $gvnPass) = explode( ':', $decoded, 2 );

		if( $this->authObject===null ){
			$this->lastMessag = 'No credential handler configured';
			return false;
		}
		$idntArr = $this->authObject->chckCredential( Array('user'=>$gvnUser, 'pass'=>$gvnPass) );
		if( $idntArr===false ){
			$this->lastMessag = $this->authObject->finalErrorText();
			return false;
		}
		return $idntArr;
	}

	/**
	 * Not applicable — this is not a token-issuing strategy.
	 *
	 * @param Array $identtyArray Unused.
	 * @return String Always an empty string.
	 */
	function issueTokenText( Array $identtyArray ): String {
		return '';
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
