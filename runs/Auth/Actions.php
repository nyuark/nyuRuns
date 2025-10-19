<?php

namespace nyu\Runs\Auth;

use \nyu\App\Actionset;
use \nyu\Access\Checker;
use \nyu\Access\Limiter;
use \nyu\Access\Auth\UserBase;
use \nyu\Tools\Logger;

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
	 * the action runs), is not a text, or when the password came in the
	 * query string of the address (see passInUrl), 401
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
		if($this->passInUrl( Array('pass'), 'Login' )){
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
		$logInst = Logger::getLogger('access');
		$authCls = Checker::authClass( strval($lgnConf['authby'] ?? 'htpass') );
		$sessCls = Checker::sessClass( strval($lgnConf['sessby'] ?? 'jtoken') );
		if($authCls===null || $sessCls===null || !class_exists($authCls) || !class_exists($sessCls)){
			$logInst->error( 'Login unavailable: unknown authby or sessby in the login block' );
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
			$logInst->warning( 'Login refused for user '.json_encode($gvnUser).$fromTxt.': too many attempts' );
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
			$logInst->warning( 'Login refused for user '.json_encode($gvnUser).$fromTxt.': '.$authObj->lastError() );
			$this->statCode = 401;
			$this->statMssg = $authObj->lastError();
			return;
		}

		$sessObj = new $sessCls( $this->resolvCnf($lgnConf['sesscf'] ?? 'dftokn') );
		// A session the request came with is closed : a new login does not leave the former one open
		if(method_exists($sessObj, 'dropSessn')){
			$sessObj->dropSessn( $this->httpRqst );
		}
		$rawTokn = $sessObj->issueTokn( $idntArr );
		if($rawTokn==''){
			$logInst->error( 'Login unavailable: '.($sessObj->lastError() ?: 'the session carrier issues no token') );
			$this->statCode = 500;
			$this->statMssg = 'Login unavailable';
			return;
		}

		// A credential store that tracks presence is told the user was seen
		if(method_exists($authObj, 'keepAlive')){
			$authObj->keepAlive( strval($idntArr['user'] ?? '') );
		}
		// The login of a privileged user (any role above the first of ROLELIST) is a notice of its own
		$usrRole = strval($idntArr['role'] ?? '');
		if(in_array($usrRole, array_slice(Checker::ROLELIST, 1), true)){
			$logInst->notice( 'Privileged login granted for user '.json_encode($gvnUser).' ('.$usrRole.')'.$fromTxt );
		}else{
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
		if($this->axessReg!==null){
			$logInst = Logger::getLogger('access');
			$usrRole = strval($this->axessReg->readValue('role'));
			$clntReg = $this->httpRqst->getClient();
			$usrAddr = ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '';
			$fromTxt = ($usrAddr!='') ? ' from '.$usrAddr : '';
			if(in_array($usrRole, array_slice(Checker::ROLELIST, 1), true)){
				$logInst->notice( 'Logout of privileged user '.json_encode($this->axessReg->readValue('user')).' ('.$usrRole.')'.$fromTxt );
			}else{
				$logInst->info( 'Logout of user '.json_encode($this->axessReg->readValue('user')).$fromTxt );
			}
		}
		$this->statCode = 200;
		$this->asOutput = Array('logged'=>false);
	}

	/**
	 * Activates a pending user of the native userbase: the 'user' and
	 * 'code' inputs name it and carry its activation code, the 'pass'
	 * input the password it chooses, kept as sent. The action is meant to
	 * be open ('openrt'): a pending user cannot log in. Reports 405
	 * unless the request is a POST, 400 when an input is missing, is not
	 * a text, the password breaks a rule or came in the query string of
	 * the address (see passInUrl), 401 on a wrong name or code,
	 * whichever it is, 429 when the address presented too many wrong
	 * ones (see Access\Limiter) and 500 when the login does not use the
	 * native userbase.
	 */
	public function activate(): Void {
		if($this->httpRqst===null || !$this->httpRqst->usedMethd('POST')){
			$this->statCode = 405;
			$this->statMssg = 'Method Not Allowed';
			return;
		}
		if($this->passInUrl( Array('pass'), 'Activation' )){
			return;
		}
		$gvnUser = $this->inputReg->readValue('user');
		$gvnCode = $this->inputReg->readValue('code');
		$gvnPass = $this->inputReg->readValue('pass');
		if(!is_string($gvnUser) || !is_string($gvnCode) || !is_string($gvnPass)){
			$this->statCode = 400;
			$this->statMssg = 'Invalid user, code or pass';
			return;
		}
		$usrBase = UserBase::fromLogin();
		if($usrBase===null){
			$this->statCode = 500;
			$this->statMssg = 'User management unavailable';
			return;
		}
		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrObj = new Limiter( $this->resolvCnf('limits') );
		$clntReg = $this->httpRqst->getClient();
		$usrAddr = ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '';
		$heldEnd = $lmtrObj->heldUntil($usrAddr);
		if($heldEnd>0){
			Logger::getLogger('access')->warning( 'Activation refused for user '.json_encode($gvnUser).(($usrAddr!='') ? ' from '.$usrAddr : '').': too many attempts' );
			if($this->httpResp!==null){
				$this->httpResp->addHeader('Retry-After', strval(max(1, $heldEnd - time())));
			}
			$this->statCode = 429;
			$this->statMssg = 'Too many attempts';
			return;
		}
		if(!$usrBase->actvtUser($gvnUser, $gvnCode, $gvnPass)){
			// Only a wrong name or code counts against the address, not a password breaking a rule
			if($usrBase->wrongCred()){
				$lmtrObj->countFail($usrAddr);
			}
			$this->statCode = $usrBase->lastState();
			$this->statMssg = $usrBase->lastError();
			return;
		}
		$this->statCode = 200;
		$this->asOutput = Array('active'=>true);
	}

	/**
	 * Changes the password of the logged user, in the native userbase:
	 * the 'former' input carries its current password, the 'pass' input
	 * the new one, both kept as sent. No name is read: the user is the
	 * one the access check identified. Reports 405 unless the request is
	 * a POST, 400 when an input is missing, is not a text, the new
	 * password breaks a rule, or a password came in the query string of
	 * the address (see passInUrl), 401 when nobody is logged or the current
	 * password is wrong (a wrong one counts as a failed attempt, for the
	 * user and for its address) and 500 when the login does not use the
	 * native userbase.
	 */
	public function password(): Void {
		if($this->httpRqst===null || !$this->httpRqst->usedMethd('POST')){
			$this->statCode = 405;
			$this->statMssg = 'Method Not Allowed';
			return;
		}
		if($this->passInUrl( Array('former', 'pass'), 'Password change' )){
			return;
		}
		$usrName = ($this->axessReg!==null && $this->axessReg->readValue('logged')===true) ? strval($this->axessReg->readValue('user')) : '';
		if($usrName==''){
			$this->statCode = 401;
			$this->statMssg = 'Not logged in';
			return;
		}
		$gvnFrmr = $this->inputReg->readValue('former');
		$gvnPass = $this->inputReg->readValue('pass');
		if(!is_string($gvnFrmr) || !is_string($gvnPass)){
			$this->statCode = 400;
			$this->statMssg = 'Invalid former or pass';
			return;
		}
		$usrBase = UserBase::fromLogin();
		if($usrBase===null){
			$this->statCode = 500;
			$this->statMssg = 'User management unavailable';
			return;
		}
		if(!$usrBase->chgPasswd($usrName, $gvnFrmr, $gvnPass)){
			if($usrBase->wrongCred()){
				$clntReg = $this->httpRqst->getClient();
				(new Limiter( $this->resolvCnf('limits') ))->countFail( ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '' );
			}
			$this->statCode = $usrBase->lastState();
			$this->statMssg = $usrBase->lastError();
			return;
		}
		$this->statCode = 200;
		$this->asOutput = Array('changed'=>true);
	}

	/**
	 * Refuses a request that carries a password in the query string of its
	 * address, wherever else it is sent: what travels in an address stays
	 * in the logs of every server on its way. The refusal is a 400, told
	 * on the 'access' channel without the value; nothing is checked, so
	 * nothing is counted against a user or an address.
	 *
	 * @param Array  $fieldNames Names of the inputs that carry a password.
	 * @param String $eventLabel What was asked for, for the log.
	 * @return Bool True when the request is refused.
	 */
	protected function passInUrl( Array $fieldNames, String $eventLabel ): Bool {
		foreach($fieldNames as $fldNme){
			if($this->httpRqst->fromQuery( strval($fldNme) )){
				$clntReg = $this->httpRqst->getClient();
				$usrAddr = ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '';
				Logger::getLogger('access')->warning( $eventLabel.' refused'.(($usrAddr!='') ? ' from '.$usrAddr : '').': password sent in the query string' );
				$this->statCode = 400;
				$this->statMssg = 'Password sent in the query string';
				return true;
			}
		}
		return false;
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
