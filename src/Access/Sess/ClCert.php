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

	protected $authObjc;
	protected $chkVarNm;
	protected $usrVarNm;
	protected $issuerCn;
	protected $lastMssg;

	/**
	 * @param Array     $confValues Config block naming the server variables of the verification result
	 * 		('chkvar', SSL_CLIENT_VERIFY by default) and of the user name ('usrvar', SSL_CLIENT_S_DN_CN
	 * 		by default), and optionally the common name of the only 'issuer' accepted.
	 * @param AuthHndlr $authHandlr Optional credential store confirming the certificate's user.
	 */
	function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
		$this->authObjc = $authHandlr;
		$this->chkVarNm = strtolower( strval($confValues['chkvar'] ?? 'SSL_CLIENT_VERIFY') );
		$this->usrVarNm = strtolower( strval($confValues['usrvar'] ?? 'SSL_CLIENT_S_DN_CN') );
		$this->issuerCn = strval($confValues['issuer'] ?? '');
		$this->lastMssg = '';
	}

	/**
	 * Reads what the web server reported about the client certificate:
	 * it must be verified, come from the accepted issuer when one is
	 * set, and name a user.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The identity array, or false on failure.
	 */
	function verifyReq( Request $requestObj ): Array|Bool {
		// A variable named HTTP_... is a request header : anyone could send it
		if( strpos($this->chkVarNm, 'http_')===0 || strpos($this->usrVarNm, 'http_')===0 ){
			$this->lastMssg = 'Certificate variables misconfigured';
			return false;
		}
		$srvVals = $requestObj->getSrvVar();
		if( ($srvVals[$this->chkVarNm] ?? '')!=='SUCCESS' ){
			$this->lastMssg = 'No verified client certificate';
			return false;
		}
		if( $this->issuerCn!='' && ($srvVals['ssl_client_i_dn_cn'] ?? '')!==$this->issuerCn ){
			$this->lastMssg = 'Certificate issuer not accepted';
			return false;
		}
		$crtUser = trim( strval($srvVals[$this->usrVarNm] ?? '') );
		if( $crtUser=='' ){
			$this->lastMssg = 'Certificate names no user';
			return false;
		}
		$idntArr = Array('user'=>$crtUser);
		// A credential store that knows its users confirms the certificate's user is allowed in
		if( $this->authObjc!==null && method_exists($this->authObjc, 'userIdent') ){
			$nowIdnt = $this->authObjc->userIdent($crtUser);
			if( $nowIdnt===false ){
				$this->lastMssg = $this->authObjc->lastError();
				return false;
			}
			$idntArr = array_merge($idntArr, $nowIdnt);
		}
		return $idntArr;
	}

	/**
	 * Not applicable — a certificate is issued by its authority.
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
