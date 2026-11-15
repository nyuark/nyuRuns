<?php

namespace nyu\Access\Carr;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;

/**
 * Carries an identity in the TLS client certificate. The web server
 * checks the certificate against the authorities it trusts during the
 * handshake, and reports the outcome in server variables: this carrier
 * only reads them. When an Auth\Handler that knows its users is given,
 * the certificate's user is also confirmed against it.
 */
class ClCert implements Handler{

	protected $authObject;
	protected $checkedVar;
	protected $usrVarName;
	protected $issrVarNme;
	protected $issuerName;
	protected $errMessage;

	/**
	 * Builds the client certificate carrier from its config block.
	 *
	 * @param Array     $configValues Config block naming the server variables of the verification result
	 * 		('checkVar', SSL_CLIENT_VERIFY by default), of the user name ('ownerVar', SSL_CLIENT_S_DN_CN
	 * 		by default) and of the name of the issuer ('issueVar', SSL_CLIENT_I_DN_CN by default), and
	 * 		optionally the common name of the only 'issuerId' accepted.
	 * @param ?AuthHndlr $authStrategy Optional credential store confirming the certificate's user.
	 */
	function __construct( Array $configValues, ?AuthHndlr $authStrategy = null ){
		$this->authObject = $authStrategy;
		$this->checkedVar = strtolower( strval($configValues['checkVar'] ?? 'SSL_CLIENT_VERIFY') );
		$this->usrVarName = strtolower( strval($configValues['ownerVar'] ?? 'SSL_CLIENT_S_DN_CN') );
		$this->issrVarNme = strtolower( strval($configValues['issueVar'] ?? 'SSL_CLIENT_I_DN_CN') );
		$this->issuerName = strval($configValues['issuerId'] ?? '');
		$this->errMessage = '';
	}

	/**
	 * Reads what the web server reported about the client certificate:
	 * it must be verified, come from the accepted issuer when one is
	 * set, and name a user.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return Array|Bool The identity array, or false on failure.
	 */
	function checkReqstDict( Request $givenRequest ): Array|Bool {
		// A variable named HTTP_... is a request header : anyone could send it
		if( strpos($this->checkedVar, 'http_')===0 || strpos($this->usrVarName, 'http_')===0 || strpos($this->issrVarNme, 'http_')===0 ){
			$this->errMessage = 'Certificate variables misconfigured';
			return false;
		}
		$srvrVals = $givenRequest->getServerValue();
		if( ($srvrVals[$this->checkedVar] ?? '')!=='SUCCESS' ){
			$this->errMessage = 'No verified client certificate';
			return false;
		}
		if( $this->issuerName!='' && ($srvrVals[$this->issrVarNme] ?? '')!==$this->issuerName ){
			$this->errMessage = 'Certificate issuer not accepted';
			return false;
		}
		$certUser = trim( strval($srvrVals[$this->usrVarName] ?? '') );
		if( $certUser=='' ){
			$this->errMessage = 'Certificate names no user';
			return false;
		}
		$idntList = Array('userName'=>$certUser);
		// A credential store that knows its users confirms the certificate's user is allowed in
		if( $this->authObject!==null && method_exists($this->authObject, 'readIdntOfUser') ){
			$nowIdent = $this->authObject->readIdntOfUser($certUser);
			if( $nowIdent===false ){
				$this->errMessage = $this->authObject->getLastErrText();
				return false;
			}
			$idntList = array_merge($idntList, $nowIdent);
		}
		return $idntList;
	}

	/**
	 * Not applicable — a certificate is issued by its authority.
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
	function getLastErrText(): String {
		return $this->errMessage;
	}
}
