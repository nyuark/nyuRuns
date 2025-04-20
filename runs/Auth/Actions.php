<?php

namespace nyu\Runs\Auth;

use \nyu\App\Actionset;
use \nyu\Access\Checker;
use \nyu\Access\Limiter;

class Actions extends Actionset {

	/**
	 * Checks the 'user' and 'pass' inputs (and 'code', for a user with
	 * 2FA) against a credential store and, on success, issues a token
	 * for the matched identity, or opens a session whose cookie goes out
	 * with the reply. The 'login' block of the ACCESS config
	 * file names the credential check ('authby', htpass by default),
	 * the session carrier issuing the token ('sessby', jtoken by
	 * default) and the blocks holding their settings ('authcf',
	 * 'sesscf'). Reports 405
	 * unless the request is a POST, 400 when 'user' or 'pass' is missing
	 * (both are required by input/login.json, so that reply comes before
	 * the action runs) or is not a text, 401
	 * on invalid credentials, 429 when the address presented too many
	 * wrong ones (see Access\Limiter) and 500 when no token can be issued.
	 * Credential checks and their outcome are logged.
	 */
	public function login(): Void {
		if($this->httpRqst===null || !$this->httpRqst->usedMethd('POST')){
			$this->statCode = 405;
			$this->statMssg = 'Method Not Allowed';
			return;
		}

		$gvnUser = $this->inputReg->readValue('user');
		$gvnPass = $this->inputReg->readValue('pass');

		// Both are required by input/login.json; what is left to refuse is a value that is not a text
		if(!is_string($gvnUser) || !is_string($gvnPass)){
			$this->statCode = 400;
			$this->statMssg = 'Invalid user or pass';
			return;
		}

		$lgnConf = $this->resolvCnf('login');
		$logInst = $GLOBALS['nyuLogger'] ?? null;
		$authCls = Checker::authClass( strval($lgnConf['authby'] ?? 'htpass') );
		$sessCls = Checker::sessClass( strval($lgnConf['sessby'] ?? 'jtoken') );
		if($authCls===null || $sessCls===null || !class_exists($authCls) || !class_exists($sessCls)){
			if($logInst!==null){
				$logInst->error( 'Login unavailable: unknown authby or sessby in the login block' );
			}
			$this->statCode = 500;
			$this->statMssg = 'Login unavailable';
			return;
		}
		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrObj = new Limiter( $this->resolvCnf('limits') );
		$clntReg = $this->httpRqst->getClient();
		$usrAddr = ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '';
		$fromTxt = ($usrAddr!='') ? ' from '.$usrAddr : '';
		$heldEnd = $lmtrObj->heldUntil($usrAddr);
		if($heldEnd>0){
			if($logInst!==null){
				$logInst->warning( 'Login refused for user '.json_encode($gvnUser).$fromTxt.': too many attempts' );
			}
			if($this->httpResp!==null){
				$this->httpResp->addHeader('Retry-After', strval(max(1, $heldEnd - time())));
			}
			$this->statCode = 429;
			$this->statMssg = 'Too many attempts';
			return;
		}
		$authObj = new $authCls( $this->resolvCnf($lgnConf['authcf'] ?? 'dfpass') );
		// The 'code' input carries the one-time code of a user with 2FA
		$gvnCode = $this->inputReg->readValue('code');
		$idntArr = $authObj->checkCred( Array('user'=>$gvnUser, 'pass'=>$gvnPass, 'code'=>is_scalar($gvnCode) ? strval($gvnCode) : '') );

		if($idntArr===false){
			// Only a credential presented and refused counts against the address
			if(!method_exists($authObj, 'wrongCred') || $authObj->wrongCred()){
				$lmtrObj->countFail($usrAddr);
			}
			if($logInst!==null){
				$logInst->warning( 'Login refused for user '.json_encode($gvnUser).$fromTxt.': '.$authObj->lastError() );
			}
			$this->statCode = 401;
			$this->statMssg = $authObj->lastError();
			return;
		}

		$sessObj = new $sessCls( $this->resolvCnf($lgnConf['sesscf'] ?? 'dftokn') );
		$rawTokn = $sessObj->issueTokn( $idntArr );
		if($rawTokn==''){
			if($logInst!==null){
				$logInst->error( 'Login unavailable: '.($sessObj->lastError() ?: 'the session carrier issues no token') );
			}
			$this->statCode = 500;
			$this->statMssg = 'Login unavailable';
			return;
		}

		// A credential store that tracks presence is told the user was seen
		if(method_exists($authObj, 'keepAlive')){
			$authObj->keepAlive( strval($idntArr['user'] ?? '') );
		}
		if($logInst!==null){
			$logInst->info( 'Login granted for user '.json_encode($gvnUser).$fromTxt );
		}
		$this->statCode = 200;
		// A server-side session travels in its cookie alone : its id is not shown to the scripts of the page
		$this->asOutput = method_exists($sessObj, 'dropSessn') ? Array('logged'=>true) : Array('token'=>$rawTokn);
	}

	/**
	 * Ends the session the login opened: a server-side session is
	 * destroyed and its cookie expired. A token cannot be taken back, so
	 * with a token carrier this only tells the client to forget it.
	 * Reports 405 unless the request is a POST.
	 */
	public function logout(): Void {
		if($this->httpRqst===null || !$this->httpRqst->usedMethd('POST')){
			$this->statCode = 405;
			$this->statMssg = 'Method Not Allowed';
			return;
		}
		$lgnConf = $this->resolvCnf('login');
		$sessCls = Checker::sessClass( strval($lgnConf['sessby'] ?? 'jtoken') );
		if($sessCls!==null && class_exists($sessCls)){
			$sessObj = new $sessCls( $this->resolvCnf($lgnConf['sesscf'] ?? 'dftokn') );
			if(method_exists($sessObj, 'dropSessn')){
				$sessObj->dropSessn( $this->httpRqst );
			}
		}
		$logInst = $GLOBALS['nyuLogger'] ?? null;
		if($logInst!==null && $this->axessReg!==null){
			$logInst->info( 'Logout of user '.json_encode($this->axessReg->readValue('user')) );
		}
		$this->statCode = 200;
		$this->asOutput = Array('logged'=>false);
	}

	/**
	 * Reads a named config block from the ACCESS config file.
	 *
	 * @param String $confRowKey Name of the config block to read.
	 * @return Array The block's values, or an empty array if not found.
	 */
	protected function resolvCnf( String $confRowKey ): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$axsCnfg = $nyuConf->getRowVal('ACCESS', strtoupper($confRowKey));
		return is_array($axsCnfg) ? $axsCnfg : Array();
	}
}
