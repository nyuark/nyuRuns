<?php

namespace nyu\Access;

use nyu\Tools\Request;
use nyu\Tools\Registry;

/**
 * Loads the configured auth/sess strategy pair for the current
 * request, verifies access, and reports the result.
 */
class Checker{

	protected $axsSetup;
	protected $httpRqst;

	/**
	 * @param Array   $accessConf Resolved 'auths' config block for the active preset.
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 */
	function __construct( Array $accessConf, Request $requestObj ){
		$this->axsSetup = $accessConf;
		$this->httpRqst = $requestObj;
	}

	/**
	 * Verifies access for the current request against the configured
	 * strategy. Fails closed on misconfiguration (never falls back to
	 * a different strategy).
	 *
	 * @return Registry Registry with 'logged', 'user', 'ident', 'statCode' and 'statMssg' keys.
	 */
	function verifyAxs(): Registry {
		if( empty($this->axsSetup) || ($this->axsSetup['required'] ?? true) === false ){
			return new Registry( Array('logged'=>true, 'user'=>'', 'statCode'=>200, 'statMssg'=>'') );
		}

		$sessNme = $this->axsSetup['sess'] ?? '';
		$authNme = $this->axsSetup['auth'] ?? null;

		$sessCls = '\\nyu\\Access\\Sess\\'.$sessNme;
		if( $sessNme=='' || !class_exists($sessCls) ){
			$this->logAttmpt( false, '', 'Access misconfigured: unknown sess strategy "'.$sessNme.'"' );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		$authObj = null;
		if( $authNme!=null ){
			$authCls = '\\nyu\\Access\\Auth\\'.$authNme;
			if( !class_exists($authCls) ){
				$this->logAttmpt( false, '', 'Access misconfigured: unknown auth strategy "'.$authNme.'"' );
				return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			$authObj = new $authCls( $this->resolvCnf($this->axsSetup['confKey'] ?? '') );
		}

		$sessObj = new $sessCls( $this->resolvCnf($this->axsSetup['confKey'] ?? ''), $authObj );
		$idntArr = $sessObj->verifyReq( $this->httpRqst );

		if( $idntArr===false ){
			$wwwAuth = ($sessNme=='Basic') ? 'Basic' : null;
			$this->logAttmpt( false, '', $sessObj->lastError() );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>401, 'statMssg'=>$sessObj->lastError(), 'wwwAuth'=>$wwwAuth) );
		}
		$this->logAttmpt( true, $idntArr['user'] ?? '', '' );
		return new Registry( Array('logged'=>true, 'user'=>$idntArr['user'] ?? '', 'ident'=>$idntArr, 'statCode'=>200, 'statMssg'=>'') );
	}

	/**
	 * Logs an access grant/denial through the shared logger, if one is set up.
	 *
	 * @param Bool   $wasGranted Whether access was granted.
	 * @param String $loggedUser The identified username, if any.
	 * @param String $denyReason The denial reason, if access was not granted.
	 */
	protected function logAttmpt( Bool $wasGranted, String $loggedUser, String $denyReason ): Void {
		$logInst = $GLOBALS['nyuLogger'] ?? null;
		if( $logInst===null ){
			return;
		}
		if( $wasGranted ){
			$logInst->info( 'Access granted for user "'.$loggedUser.'"' );
		}else{
			$logInst->warning( 'Access denied: '.$denyReason );
		}
	}

	/**
	 * Reads a named strategy config block from the ACCESS config file.
	 *
	 * @param String $confRowKey Name of the config block to read.
	 * @return Array The strategy's configuration, or an empty array if not found.
	 */
	protected function resolvCnf( String $confRowKey ): Array {
		if( $confRowKey=='' ){
			return Array();
		}
		$nyuConf = $GLOBALS['nyuConfig'];
		$axsCnfg = $nyuConf->getRowVal('ACCESS', strtoupper($confRowKey));
		return is_array($axsCnfg) ? $axsCnfg : Array();
	}
}
