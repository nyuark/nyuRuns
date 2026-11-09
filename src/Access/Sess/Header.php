<?php

namespace nyu\Access\Sess;

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
	protected $lastMessag;

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
		$this->lastMessag = '';
	}

	/**
	 * Reads the configured header off the request, takes the key after
	 * the scheme when one is configured, and checks it against the
	 * delegated Auth handler.
	 *
	 * @param Request $requstObject The shared, already-parsed HTTP request.
	 * @return Array|Bool The verified identity array, or false on failure.
	 */
	function verifyRqstList( Request $requstObject ): Array|Bool {
		$headVal = $requstObject->retrieveHeader( $this->headerName );
		if( $headVal===null || $headVal=='' ){
			$this->lastMessag = 'Missing '.$this->headerName.' header';
			return false;
		}
		// With a scheme, the header reads "<scheme> <key>" : only the key is checked
		if( $this->schemaName!='' ){
			if( stripos($headVal, $this->schemaName.' ')!==0 ){
				$this->lastMessag = 'Missing '.$this->schemaName.' '.$this->headerName.' header';
				return false;
			}
			$headVal = trim(substr($headVal, strlen($this->schemaName)));
		}
		if( $this->authObject===null ){
			$this->lastMessag = 'No credential handler configured';
			return false;
		}
		$idntArr = $this->authObject->chckCredential( Array('key'=>$headVal) );
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
