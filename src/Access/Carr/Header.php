<?php

namespace nyu\Access\Carr;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;

/**
 * Carries an API key via a configurable HTTP header (default
 * X-Api-Key), delegating verification to an Auth\Handler. The key can
 * follow a scheme, as in "Authorization: Bearer <key>".
 */
class Header implements Handler{

	protected $headerName;
	protected $schemaName;
	protected $authObject;
	protected $errMessage;

	/**
	 * Builds the header carrier from its config block, with the credential check it hands over to.
	 *
	 * @param Array     $configValues Config block, optionally with a 'headName' name (defaults to 'X-Api-Key') and the
	 *                              'authSchm' the key must follow in it, such as 'Bearer' (none by default).
	 * @param ?AuthHndlr $authStrategy Credential-verification strategy to delegate to.
	 */
	function __construct( Array $configValues, ?AuthHndlr $authStrategy = null ){
		$this->headerName = $configValues['headName'] ?? 'X-Api-Key';
		$this->schemaName = trim(strval($configValues['authSchm'] ?? ''));
		$this->authObject = $authStrategy;
		$this->errMessage = '';
	}

	/**
	 * Reads the configured header off the request, takes the key after
	 * the scheme when one is configured, and checks it against the
	 * delegated Auth handler.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return Array|Bool The verified identity array, or false on failure.
	 */
	function verifyRqstList( Request $givenRequest ): Array|Bool {
		$headVal = $givenRequest->retrieveHeader( $this->headerName );
		if( $headVal===null || $headVal=='' ){
			$this->errMessage = 'Missing '.$this->headerName.' header';
			return false;
		}
		// With a scheme, the header reads "<scheme> <key>" : only the key is checked
		if( $this->schemaName!='' ){
			if( stripos($headVal, $this->schemaName.' ')!==0 ){
				$this->errMessage = 'Missing '.$this->schemaName.' '.$this->headerName.' header';
				return false;
			}
			$headVal = trim(substr($headVal, strlen($this->schemaName)));
		}
		if( $this->authObject===null ){
			$this->errMessage = 'No credential handler configured';
			return false;
		}
		$idntArr = $this->authObject->checkSecretArr( Array('key'=>$headVal) );
		if( $idntArr===false ){
			$this->errMessage = $this->authObject->finalErrorText();
			return false;
		}
		return $idntArr;
	}

	/**
	 * Not applicable — this is not a token-issuing strategy.
	 *
	 * @param Array $identityList Unused.
	 * @return String Always an empty string.
	 */
	function issueTokenText( Array $identityList ): String {
		return '';
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
