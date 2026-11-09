<?php

namespace nyu\Access\Sess;

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
	protected $usrVrblNme;
	protected $issVrblNme;
	protected $issuerName;
	protected $lastMessag;

	/**
	 * Builds the client certificate carrier from its config block.
	 *
	 * @param Array     $configValues Config block naming the server variables of the verification result
	 * 		('chkvar', SSL_CLIENT_VERIFY by default), of the user name ('usrvar', SSL_CLIENT_S_DN_CN
	 * 		by default) and of the name of the issuer ('issvar', SSL_CLIENT_I_DN_CN by default), and
	 * 		optionally the common name of the only 'issuer' accepted.
	 * @param ?AuthHndlr $authStrategy Optional credential store confirming the certificate's user.
	 */
	function __construct( Array $configValues, ?AuthHndlr $authStrategy = null ){
		$this->authObject = $authStrategy;
		$this->checkedVar = strtolower( strval($configValues['chkvar'] ?? 'SSL_CLIENT_VERIFY') );
		$this->usrVrblNme = strtolower( strval($configValues['usrvar'] ?? 'SSL_CLIENT_S_DN_CN') );
		$this->issVrblNme = strtolower( strval($configValues['issvar'] ?? 'SSL_CLIENT_I_DN_CN') );
		$this->issuerName = strval($configValues['issuer'] ?? '');
		$this->lastMessag = '';
	}

	/**
	 * Reads what the web server reported about the client certificate:
	 * it must be verified, come from the accepted issuer when one is
	 * set, and name a user.
	 *
	 * @param Request $requstObject The shared, already-parsed HTTP request.
	 * @return Array|Bool The identity array, or false on failure.
	 */
	function verifyRqstList( Request $requstObject ): Array|Bool {
		// A variable named HTTP_... is a request header : anyone could send it
		if( strpos($this->checkedVar, 'http_')===0 || strpos($this->usrVrblNme, 'http_')===0 || strpos($this->issVrblNme, 'http_')===0 ){
			$this->lastMessag = 'Certificate variables misconfigured';
			return false;
		}
		$srvrVls = $requstObject->getServerValue();
		if( ($srvrVls[$this->checkedVar] ?? '')!=='SUCCESS' ){
			$this->lastMessag = 'No verified client certificate';
			return false;
		}
		if( $this->issuerName!='' && ($srvrVls[$this->issVrblNme] ?? '')!==$this->issuerName ){
			$this->lastMessag = 'Certificate issuer not accepted';
			return false;
		}
		$certUsr = trim( strval($srvrVls[$this->usrVrblNme] ?? '') );
		if( $certUsr=='' ){
			$this->lastMessag = 'Certificate names no user';
			return false;
		}
		$idntArr = Array('user'=>$certUsr);
		// A credential store that knows its users confirms the certificate's user is allowed in
		if( $this->authObject!==null && method_exists($this->authObject, 'usrIdentityArr') ){
			$nowIdnt = $this->authObject->usrIdentityArr($certUsr);
			if( $nowIdnt===false ){
				$this->lastMessag = $this->authObject->finalErrorText();
				return false;
			}
			$idntArr = array_merge($idntArr, $nowIdnt);
		}
		return $idntArr;
	}

	/**
	 * Not applicable — a certificate is issued by its authority.
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
