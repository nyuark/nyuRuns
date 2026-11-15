<?php

namespace nyu\Runs\Auth;

use \nyu\App\Actionset;
use \nyu\Access\AccessMail;
use \nyu\Access\Checker;
use \nyu\Access\Limiter;
use \nyu\Access\LoginMark;
use \nyu\Access\Auth\UserBase;
use \nyu\Tools\Logger;

/**
 * Signs users in and out, activates, signs up and resets their accounts. The
 * methods are those of routes.rte: every action takes POST alone, keyset GET;
 * another method is answered 405 by the route, before the action is reached.
 */
class Actions extends Actionset {

	/**
	 * Checks the 'userName' and 'passWord' inputs (and 'sentCode', for a user with
	 * 2FA) against a credential store and, on success, issues a token
	 * for the matched identity, or opens a session whose cookie goes out
	 * with the reply. The 'loginSet' block of the ACCESS config
	 * file names the credential check ('authWith', htpasswd by default),
	 * the carrier issuing the token ('carrWith', jwtToken by
	 * default) and the blocks holding their settings ('authConf',
	 * 'carrConf'). Its route takes POST alone (routes.rte). Reports 400 when 'userName' or 'passWord' is missing
	 * (both are required by input/login.json, so that reply comes before
	 * the action runs), is not a text, or when the password came in the
	 * query string of the address (see passInUrl), 401
	 * on invalid credentials, 429 when the address presented too many
	 * wrong ones (see Access\Limiter) and 500 when no token can be issued.
	 * Credential checks and their outcome are logged.
	 */
	public function login(): Void {
		if($this->denyPwdInQuery( Array('passWord'), 'Login' )){
			return;
		}

		$givenUsr = $this->inputsRegr->readValueByKey('userName');
		$givenPwd = $this->inputsRegr->readValueByKey('passWord');

		// Both are required by input/login.json; what is left to refuse is a value that is not a text
		if(!is_string($givenUsr) || !is_string($givenPwd)){
			$this->statusCode = 400;
			$this->statusText = 'Invalid userName or passWord';
			return;
		}

		$loginCfg = $this->findAccessConf('loginSet');
		$accsJrnl = Logger::retrieveLogger('access');
		$authClss = Checker::authClssString( strval($loginCfg['authWith'] ?? 'htpasswd') );
		$sessClss = Checker::sessnClassText( strval($loginCfg['carrWith'] ?? 'jwtToken') );
		if($authClss===null || $sessClss===null || !class_exists($authClss) || !class_exists($sessClss)){
			$accsJrnl->error( 'Login unavailable: unknown authWith or carrWith in the login block' );
			$this->statusCode = 500;
			$this->statusText = 'Login unavailable';
			return;
		}
		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrList = $this->denyHeldClient('Login', $givenUsr);
		if($lmtrList===null){
			return;
		}
		$lmtrInst = $lmtrList[0];
		$userAddr = $lmtrList[1];
		$fromText = ($userAddr!='') ? ' from '.$userAddr : '';
		$sessInst = new $sessClss( $this->findAccessConf($loginCfg['carrConf'] ?? 'jwtToken') );
		// A project holding only the public key checks the tokens another server issues : nothing is checked here
		if(method_exists($sessInst, 'verifyOnlyFlag') && $sessInst->verifyOnlyFlag()){
			$accsJrnl->warning( 'Login refused for user '.json_encode($givenUsr).$fromText.': this project only checks tokens, issued by another server' );
			$this->statusCode = 501;
			$this->statusText = 'Tokens are issued by another server';
			return;
		}
		$authInst = new $authClss( $this->findAccessConf($loginCfg['authConf'] ?? 'htpasswd') );
		// The 'sentCode' input carries the one-time code of a user with 2FA
		$givenCod = $this->inputsRegr->readValueByKey('sentCode');
		// The login mark of a client already used: the hold of an account does not stop it (see Access\LoginMark)
		$givenMrk = strval( $this->requestObj->retrieveHeader('X-Lgmark') ?? (is_string($this->requestObj->retrieveCookie('lgmark')) ? $this->requestObj->retrieveCookie('lgmark') : '') );
		$idntList = $authInst->checkSecretArr( Array('user'=>$givenUsr, 'pass'=>$givenPwd, 'code'=>is_scalar($givenCod) ? strval($givenCod) : '', 'mark'=>$givenMrk) );

		if($idntList===false){
			// Only a credential presented and refused counts against the address
			if(!method_exists($authInst, 'hasWrongSecret') || $authInst->hasWrongSecret()){
				$lmtrInst->countFailedTry($userAddr);
			}
			$accsJrnl->warning( 'Login refused for user '.json_encode($givenUsr).$fromText.': '.$authInst->finalErrorText() );
			$this->statusCode = 401;
			$this->statusText = $authInst->finalErrorText();
			return;
		}

		// A session the request came with is closed : a new login does not leave the former one open
		if(method_exists($sessInst, 'dropUsrSession')){
			$sessInst->dropUsrSession( $this->requestObj );
		}
		$rawToken = $sessInst->issueTokenText( $idntList );
		if($rawToken==''){
			$accsJrnl->error( 'Login unavailable: '.($sessInst->finalErrorText() ?: 'the carrier issues no token') );
			$this->statusCode = 500;
			$this->statusText = 'Login unavailable';
			return;
		}

		// A credential store that tracks presence is told the user was seen
		if(method_exists($authInst, 'retainAliveFlg')){
			$authInst->retainAliveFlg( strval($idntList['userName'] ?? '') );
		}
		// A login is always traced, production included; the one of a privileged user (any role above the first of ROLELIST) tells it
		$userRole = strval($idntList['userRole'] ?? '');
		if(in_array($userRole, array_slice(Checker::ROLE_LIST, 1), true)){
			$accsJrnl->notice( 'Privileged login granted for user '.json_encode($givenUsr).' ('.$userRole.')'.$fromText );
		}else{
			$accsJrnl->notice( 'Login granted for user '.json_encode($givenUsr).$fromText );
		}
		$this->statusCode = 200;
		// A server-side session travels in its cookie alone : its id is not shown to the scripts of the page
		$this->replyValue = method_exists($sessInst, 'dropUsrSession') ? Array('logged'=>true) : Array('token'=>$rawToken);
		// A new login mark for this client : in a cookie the scripts of the page cannot read, or in the reply of a token
		$markInst = LoginMark::fromLimitBlock();
		// Made by the store, which signs it with the hash of the password it just checked : a check without one gives no mark
		$nouvMark = method_exists($authInst, 'createUserMark') ? strval($authInst->createUserMark( strval($idntList['userName'] ?? $givenUsr) )) : '';
		if($nouvMark!==''){
			if(method_exists($sessInst, 'dropUsrSession')){
				if(!headers_sent()){
					setcookie('lgmark', $nouvMark, Array('expires'=>time() + 86400*$markInst->markLastsCount(), 'path'=>$this->markCookiePath(),'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict'));
				}
			}else{
				$this->replyValue['lgmark'] = $nouvMark;
			}
		}
	}

	/**
	 * Gives the path the cookie of the login mark is set with: the folder
	 * of the route that reached the login, built as the links are (see
	 * Router::buildRouteLink), so that the browser sends the cookie back to
	 * it. For a route of the Run it is the basePath the preset is served
	 * under, then the mount of the Run (/auth, /api/auth, / when mounted at
	 * the root); for a route the site declares, the folder of that route.
	 * Without a router, or a route to build from, the root of the site.
	 *
	 * @return String The path, from the root of the site, without a final slash ('/' for the root).
	 */
	protected function markCookiePath(): String {
		if($this->routingObj===null){
			return '/';
		}
		$routRegr = $this->routingObj->locateRouteReg();
		$routePth = strval( $routRegr->readValueByKey('routPath') ?? '' );
		try{
			$linkText = $this->routingObj->buildRouteLink( $routePth, (array)$routRegr->readValueByKey('pathVars') );
		}catch( \RuntimeException $e ){
			return '/';
		}
		// The folder of the link, its query string left out
		$linkText = strval( strtok($linkText, '?') );
		$fldrText = substr( $linkText, 0, intval(strrpos($linkText, '/')) );
		return ($fldrText==='') ? '/' : $fldrText;
	}

	/**
	 * Publishes the public keys the tokens of the login are checked with,
	 * as a JWK Set (RFC 7517), for other projects to check them without a
	 * copy of a key file: the current key and the previous one, each named
	 * by its 'kid'. Declared at /.well-known/jwks.json, read by anyone.
	 * Always written as JSON, and may be kept five minutes. Reports 404
	 * when there is nothing to publish: tokens signed with a secret
	 * (HS256), or issued by another server.
	 */
	public function keyset(): Void {
		$loginCfg = $this->findAccessConf('loginSet');
		$sessClss = Checker::sessnClassText( strval($loginCfg['carrWith'] ?? 'jwtToken') );
		$sessInst = ($sessClss!==null && class_exists($sessClss)) ? new $sessClss( $this->findAccessConf($loginCfg['carrConf'] ?? 'jwtToken') ) : null;
		$keysDict = ($sessInst!==null && method_exists($sessInst, 'publicSetArray')) ? $sessInst->publicSetArray() : Array('keys'=>Array());
		if( empty($keysDict['keys']) ){
			Logger::retrieveLogger('runner')->info( 'Key set not published: the tokens of the login are not signed with a key of RS256 issued here' );
			$this->statusCode = 404;
			$this->statusText = 'Not Found';
			return;
		}
		$this->setReplyFormat('jsonData');
		if( $this->respondObj!==null ){
			$this->respondObj->addReplyHeader('Cache-Control', 'public, max-age=300');
			$this->respondObj->addReplyHeader('Expires', gmdate('D, d M Y H:i:s', time() + 300).' GMT');
		}
		$this->statusCode = 200;
		$this->replyValue = $keysDict;
	}

	/**
	 * Ends the session the login opened: a server-side session is
	 * destroyed and its cookie expired. A token cannot be taken back, so
	 * with a token carrier this only tells the client to forget it. Its
	 * route takes POST alone (routes.rte).
	 */
	public function logout(): Void {
		$loginCfg = $this->findAccessConf('loginSet');
		$sessClss = Checker::sessnClassText( strval($loginCfg['carrWith'] ?? 'jwtToken') );
		if($sessClss!==null && class_exists($sessClss)){
			$sessInst = new $sessClss( $this->findAccessConf($loginCfg['carrConf'] ?? 'jwtToken') );
			if(method_exists($sessInst, 'dropUsrSession')){
				$sessInst->dropUsrSession( $this->requestObj );
			}
		}
		if($this->accessRegr!==null){
			$accsJrnl = Logger::retrieveLogger('access');
			$userRole = strval($this->accessRegr->readValueByKey('userRole'));
			$clntRegr = $this->requestObj->retrieveClient();
			$userAddr = ($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '';
			$fromText = ($userAddr!='') ? ' from '.$userAddr : '';
			// A logout is always traced, as the login
			if(in_array($userRole, array_slice(Checker::ROLE_LIST, 1), true)){
				$accsJrnl->notice( 'Logout of privileged user '.json_encode($this->accessRegr->readValueByKey('userName')).' ('.$userRole.')'.$fromText );
			}else{
				$accsJrnl->notice( 'Logout of user '.json_encode($this->accessRegr->readValueByKey('userName')).$fromText );
			}
		}
		$this->statusCode = 200;
		$this->replyValue = Array('logged'=>false);
	}

	/**
	 * Activates a pending user of the native userbase: the 'userName' and
	 * 'sentCode' inputs name it and carry its activation code, the 'passWord'
	 * input the password it chooses, kept as sent. The action is meant to
	 * be open ('openList'): a pending user cannot log in. Its route takes
	 * POST alone (routes.rte). Reports 400 when an input is missing, is not
	 * a text, the password breaks a rule or came in the query string of
	 * the address (see passInUrl), 401 on a wrong name or code,
	 * whichever it is, or one that ended, 429 when the address presented
	 * too many wrong ones (see Access\Limiter) and 500 when the login does
	 * not use the native userbase. The reply tells the status the user is
	 * in: 'approved', or 'inReview' for a sign-up a manager has to approve.
	 */
	public function active(): Void {
		if($this->denyPwdInQuery( Array('passWord'), 'Activation' )){
			return;
		}
		$givenUsr = $this->inputsRegr->readValueByKey('userName');
		$givenCod = $this->inputsRegr->readValueByKey('sentCode');
		$givenPwd = $this->inputsRegr->readValueByKey('passWord');
		if(!is_string($givenUsr) || !is_string($givenCod) || !is_string($givenPwd)){
			$this->statusCode = 400;
			$this->statusText = 'Invalid userName, sentCode or passWord';
			return;
		}
		$userBase = UserBase::fromLoginBlock();
		if($userBase===null){
			$this->statusCode = 500;
			$this->statusText = 'User management unavailable';
			return;
		}
		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrList = $this->denyHeldClient('Activation', $givenUsr);
		if($lmtrList===null){
			return;
		}
		$lmtrInst = $lmtrList[0];
		$userAddr = $lmtrList[1];
		$clntRegr = $this->requestObj->retrieveClient();
		// The language the request asks for is given to a user who has none yet : the one who activates is the one the mails go to
		$clntLang = ($clntRegr!==null) ? strval($clntRegr->readValueByKey('language')) : '';
		if(!$userBase->activateUsrFlg($givenUsr, $givenCod, $givenPwd, $clntLang)){
			// Only a wrong name or code counts against the address, not a password breaking a rule
			if($userBase->hasWrongSecret()){
				$lmtrInst->countFailedTry($userAddr);
			}
			$this->statusCode = $userBase->lastStateCount();
			$this->statusText = $userBase->finalErrorText();
			return;
		}
		$userStts = strval( ($userBase->userInfosArray($givenUsr) ?: Array())['userStatus'] ?? 'approved' );
		$this->statusCode = 200;
		$this->replyValue = Array('active'=>$userStts=='approved', 'stat'=>$userStts);
	}

	/**
	 * Signs a user up, when the block of the native userbase allows it
	 * ('signupOn') and names a mail account ('mailAcnt'): the 'userName' input is
	 * the name asked for, the 'userMail' input its address. The address is
	 * sent a code that confirms it and sets the password (auth/active);
	 * a manager then approves the account (users/approve). A name or an
	 * address already taken gets the same reply, and the address is told
	 * by mail. A client may sign up 'maxPerIp' times (16) within 'holdSecs' of
	 * the 'tryLimit' block, counted apart from its wrong credentials; at most
	 * 'maxQueue' sign-ups (256) wait at once, and the managers are told by
	 * mail at each count of 'alertsAt' (128, 200, 256). The action is meant
	 * to be open ('openList'). Reports 202, 404 when sign-ups are not open,
	 * 400 for a name or an address that
	 * is not one, 429 when the address of the client asked too often or
	 * is held, 503 when too many sign-ups
	 * are waiting.
	 */
	public function signup(): Void {
		$userBase = UserBase::fromLoginBlock();
		$mailInst = AccessMail::fromLoginBlock( $this->getRunFldrPath(), PHP_SAPI!=='cli' );
		if($userBase===null || $mailInst===null || !AccessMail::signupsAllowed()){
			$this->statusCode = 404;
			$this->statusText = 'Not Found';
			return;
		}
		$givenUsr = $this->inputsRegr->readValueByKey('userName');
		$givenEml = $this->inputsRegr->readValueByKey('userMail');
		if(!is_string($givenUsr) || !is_string($givenEml)){
			$this->statusCode = 400;
			$this->statusText = 'Invalid userName or userMail';
			return;
		}
		// An address held for wrong credentials is refused; its sign-ups are counted apart, 'maxPerIp' of them within 'holdSecs'
		$limitCfg = $this->findAccessConf('tryLimit');
		if($this->denyHeldClient('Sign-up', $givenUsr)===null){
			return;
		}
		$fieldNme = trim(strval($limitCfg['dataPath'] ?? ''), '/\\ ');
		$regrLmtr = new Limiter( Array('maxTries'=>intval($limitCfg['maxPerIp'] ?? 16), 'dataPath'=>(($fieldNme!='') ? $fieldNme : 'temp/limits').'/signup') + $limitCfg );
		$lmtrList = $this->denyHeldClient('Sign-up', $givenUsr, $regrLmtr);
		if($lmtrList===null){
			return;
		}
		// Every sign-up counts : an address cannot fill the mailboxes of others
		$lmtrList[0]->countFailedTry($lmtrList[1]);
		$regrTops = max( 1, intval($limitCfg['maxQueue'] ?? UserBase::SIGN_MAXI) );
		$clntRegr = $this->requestObj->retrieveClient();
		$clntLang = ($clntRegr!==null) ? strval($clntRegr->readValueByKey('language')) : '';
		$newCodig = $userBase->signsUsrString($givenUsr, $givenEml, $regrTops, $clntLang);
		if($newCodig===false){
			$this->statusCode = $userBase->lastStateCount();
			$this->statusText = $userBase->finalErrorText();
			return;
		}
		if($newCodig===''){
			// An address taken is told to its owner alone, in its language; a name taken, to an address of no account, says nothing of any account
			$ownEntry = $userBase->listUsersNamed( Array('contactEml'=>$givenEml), 1 );
			if(is_array($ownEntry) && !empty($ownEntry)){
				$mailInst->sendAddrExists($givenEml, $givenUsr, strval($ownEntry[0]['userLocale'] ?? ''));
			}else{
				$mailInst->sendNameExists(strtolower(trim($givenEml)), $givenUsr, $clntLang);
			}
		}else{
			$mailInst->sendCodeToUser('register', $givenUsr, strtolower(trim($givenEml)), $newCodig, $clntLang);
			$this->sendMngrAlerts($userBase, $mailInst, $regrTops, is_array($limitCfg['alertsAt'] ?? null) ? $limitCfg['alertsAt'] : Array(128, 200, 256));
		}
		$this->statusCode = 202;
		$this->replyValue = Array('asked'=>true);
	}

	/**
	 * Tells the managers, by mail and on the 'access' log, when a sign-up
	 * brings the number of those waiting to one of the counts of 'alertsAt';
	 * at 'maxQueue', the sign-ups are closed.
	 *
	 * @param UserBase   $userDatabase The userbase.
	 * @param AccessMail $accessMailer The sender of the access mails.
	 * @param Int        $authTopsNumb The most sign-ups waiting at once.
	 * @param Array      $warnAtCounts The counts the managers are told at.
	 */
	protected function sendMngrAlerts( UserBase $userDatabase, AccessMail $accessMailer, Int $authTopsNumb, Array $warnAtCounts ): Void {
		$waitNumb = $userDatabase->waitingSignups();
		if($waitNumb===false || !in_array($waitNumb, array_map('intval', $warnAtCounts), true)){
			return;
		}
		$accsJrnl = Logger::retrieveLogger('access');
		if($waitNumb>=$authTopsNumb){
			$accsJrnl->warning( $waitNumb.' sign-ups are waiting: the sign-ups are closed until some are approved or removed' );
		}else{
			$accsJrnl->notice( $waitNumb.' sign-ups are waiting, '.$authTopsNumb.' at most' );
		}
		$mngrList = $userDatabase->manageMailList();
		if(is_array($mngrList) && !empty($mngrList)){
			$accessMailer->sendQueueAlert($mngrList, $waitNumb, $authTopsNumb);
		}else{
			$accsJrnl->warning( 'No manager with an address is told of the sign-ups waiting' );
		}
	}

	/**
	 * Asks for a new password, when the block of the native userbase names
	 * a mail account: the 'userName' input is the login name or the address
	 * of the user. An active user with an address is sent a code, its
	 * password kept until the code is used (auth/recover). Whoever the
	 * user is, or is not, the reply is the same. Each request counts
	 * against the address of the client (see Access\Limiter). The action
	 * is meant to be open ('openList'). Reports 202, 404 when no mail is
	 * sent, 400 for an input that is
	 * not a text, 429 when the address of the client asked too often.
	 */
	public function forgot(): Void {
		$userBase = UserBase::fromLoginBlock();
		$mailInst = AccessMail::fromLoginBlock( $this->getRunFldrPath(), PHP_SAPI!=='cli' );
		if($userBase===null || $mailInst===null){
			$this->statusCode = 404;
			$this->statusText = 'Not Found';
			return;
		}
		$givenUsr = $this->inputsRegr->readValueByKey('userName');
		if(!is_string($givenUsr)){
			$this->statusCode = 400;
			$this->statusText = 'Invalid userName';
			return;
		}
		$lmtrList = $this->denyHeldClient('Reset', $givenUsr);
		if($lmtrList===null){
			return;
		}
		$lmtrList[0]->countFailedTry($lmtrList[1]);
		$restVals = $userBase->resetPassArray($givenUsr);
		if(is_array($restVals)){
			$clntRegr = $this->requestObj->retrieveClient();
			$clntLang = ($clntRegr!==null) ? strval($clntRegr->readValueByKey('language')) : '';
			$mailInst->sendCodeToUser('password', $restVals['user'], $restVals['mail'], $restVals['code'], $restVals['lang'] ?? $clntLang);
		}
		$this->statusCode = 202;
		$this->replyValue = Array('asked'=>true);
	}

	/**
	 * Sets a new password with the code auth/forgot sent: the 'userName' and
	 * 'sentCode' inputs name the user and carry the code, the 'passWord' input the
	 * new password, kept as sent. The action is meant to be open
	 * ('openList'). Reports 400 when an
	 * input is missing, is not a text, the password breaks a rule or came
	 * in the query string of the address (see passInUrl), 401 on a wrong
	 * name or code, or one that ended, 429 when the address presented too
	 * many wrong ones (see Access\Limiter) and 500 when the login does not
	 * use the native userbase.
	 */
	public function recover(): Void {
		if($this->denyPwdInQuery( Array('passWord'), 'Password reset' )){
			return;
		}
		$givenUsr = $this->inputsRegr->readValueByKey('userName');
		$givenCod = $this->inputsRegr->readValueByKey('sentCode');
		$givenPwd = $this->inputsRegr->readValueByKey('passWord');
		if(!is_string($givenUsr) || !is_string($givenCod) || !is_string($givenPwd)){
			$this->statusCode = 400;
			$this->statusText = 'Invalid userName, sentCode or passWord';
			return;
		}
		$userBase = UserBase::fromLoginBlock();
		if($userBase===null){
			$this->statusCode = 500;
			$this->statusText = 'User management unavailable';
			return;
		}
		$lmtrList = $this->denyHeldClient('Password reset', $givenUsr);
		if($lmtrList===null){
			return;
		}
		if(!$userBase->recoverUserFlg($givenUsr, $givenCod, $givenPwd)){
			if($userBase->hasWrongSecret()){
				$lmtrList[0]->countFailedTry($lmtrList[1]);
			}
			$this->statusCode = $userBase->lastStateCount();
			$this->statusText = $userBase->finalErrorText();
			return;
		}
		$this->statusCode = 200;
		$this->replyValue = Array('changed'=>true);
	}

	/**
	 * Refuses a request from an address that presented too many wrong
	 * credentials, or asked too often: a 429, with the time to wait.
	 *
	 * @param String   $requestLabel What was asked for, for the log.
	 * @param String   $namedUserTxt The user named, for the log.
	 * @param ?Limiter $ceilingCount The limiter to ask; the one of the 'tryLimit' block when null.
	 * @return ?Array The limiter and the address of the client, or null when the request is refused.
	 */
	protected function denyHeldClient( String $requestLabel, String $namedUserTxt, ?Limiter $ceilingCount=null ): ?Array {
		$lmtrInst = $ceilingCount ?? new Limiter( $this->findAccessConf('tryLimit') );
		$clntRegr = $this->requestObj->retrieveClient();
		$userAddr = ($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '';
		$heldTime = $lmtrInst->heldUntilCount($userAddr);
		if($heldTime>0){
			Logger::retrieveLogger('access')->warning( $requestLabel.' refused for user '.json_encode($namedUserTxt).(($userAddr!='') ? ' from '.$userAddr : '').': too many attempts' );
			if($this->respondObj!==null){
				$this->respondObj->addReplyHeader('Retry-After', strval(max(1, $heldTime - time())));
			}
			$this->statusCode = 429;
			$this->statusText = 'Too many attempts';
			return null;
		}
		return Array($lmtrInst, $userAddr);
	}

	/**
	 * Gives the folder of this application, whose mails folder holds the
	 * templates that replace those of conf/mails.
	 *
	 * @return String The folder.
	 */
	protected function getRunFldrPath(): String {
		return __DIR__;
	}

	/**
	 * Changes the password of the logged user, in the native userbase:
	 * the 'oldValue' input carries its current password, the 'passWord' input
	 * the new one, both kept as sent. No name is read: the user is the
	 * one the access check identified. Reports 400 when an input is missing, is not a text, the new
	 * password breaks a rule, or a password came in the query string of
	 * the address (see passInUrl), 401 when nobody is logged or the current
	 * password is wrong (a wrong one counts as a failed attempt, for the
	 * user and for its address), 403 for a request made with a device token
	 * (see needsPassLogin) and 500 when the login does not use the native
	 * userbase.
	 */
	public function setpass(): Void {
		// A device never changes the password of its user: that needs the password login
		$this->needsPassLogin();
		if($this->denyPwdInQuery( Array('oldValue', 'passWord'), 'Password change' )){
			return;
		}
		$userName = ($this->accessRegr!==null && $this->accessRegr->readValueByKey('loggedIn')===true) ? strval($this->accessRegr->readValueByKey('userName')) : '';
		if($userName==''){
			$this->statusCode = 401;
			$this->statusText = 'Not logged in';
			return;
		}
		$oldValue = $this->inputsRegr->readValueByKey('oldValue');
		$givenPwd = $this->inputsRegr->readValueByKey('passWord');
		if(!is_string($oldValue) || !is_string($givenPwd)){
			$this->statusCode = 400;
			$this->statusText = 'Invalid oldValue or passWord';
			return;
		}
		$userBase = UserBase::fromLoginBlock();
		if($userBase===null){
			$this->statusCode = 500;
			$this->statusText = 'User management unavailable';
			return;
		}
		if(!$userBase->editPasswdFlag($userName, $oldValue, $givenPwd)){
			if($userBase->hasWrongSecret()){
				$clntRegr = $this->requestObj->retrieveClient();
				(new Limiter( $this->findAccessConf('tryLimit') ))->countFailedTry( ($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '' );
			}
			$this->statusCode = $userBase->lastStateCount();
			$this->statusText = $userBase->finalErrorText();
			return;
		}
		$this->statusCode = 200;
		$this->replyValue = Array('changed'=>true);
	}

	/**
	 * Sets the language of the mails of the logged user, in the native
	 * userbase: the 'saveLang' input is a language tag (fr, fr-ch), or empty
	 * to have the default one (DEF_LANG). No name is read: the user is the
	 * one the access check identified. Reports 400 for a language that is not a tag, 401 when nobody is logged
	 * and 500 when the login does not use the native userbase.
	 */
	public function usrlang(): Void {
		$userName = ($this->accessRegr!==null && $this->accessRegr->readValueByKey('loggedIn')===true) ? strval($this->accessRegr->readValueByKey('userName')) : '';
		if($userName==''){
			$this->statusCode = 401;
			$this->statusText = 'Not logged in';
			return;
		}
		$userBase = UserBase::fromLoginBlock();
		if($userBase===null){
			$this->statusCode = 500;
			$this->statusText = 'User management unavailable';
			return;
		}
		$givenLng = $this->inputsRegr->readValueByKey('saveLang');
		if(!$userBase->changeLanguage($userName, is_string($givenLng) ? $givenLng : null)){
			$this->statusCode = $userBase->lastStateCount();
			$this->statusText = $userBase->finalErrorText();
			return;
		}
		$this->statusCode = 200;
		$this->replyValue = Array('lang'=>($userBase->userInfosArray($userName) ?: Array())['userLocale'] ?? null);
	}

	/**
	 * Refuses a request that carries a password in the query string of its
	 * address, wherever else it is sent: what travels in an address stays
	 * in the logs of every server on its way. The refusal is a 400, told
	 * on the 'access' channel without the value; nothing is checked, so
	 * nothing is counted against a user or an address.
	 *
	 * @param Array  $fldNamesList Names of the inputs that carry a password.
	 * @param String $requestLabel What was asked for, for the log.
	 * @return Bool True when the request is refused.
	 */
	protected function denyPwdInQuery( Array $fldNamesList, String $requestLabel ): Bool {
		foreach($fldNamesList as $fldNme){
			if($this->requestObj->isSentViaQuery( strval($fldNme) )){
				$clntRegr = $this->requestObj->retrieveClient();
				$userAddr = ($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '';
				Logger::retrieveLogger('access')->warning( $requestLabel.' refused'.(($userAddr!='') ? ' from '.$userAddr : '').': password sent in the query string' );
				$this->statusCode = 400;
				$this->statusText = 'Password sent in the query string';
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a named config block from the ACCESS config file.
	 *
	 * @param String $cfgBlockName Name of the config block to read.
	 * @return Array The block's values, or an empty array if not found.
	 */
	protected function findAccessConf( String $cfgBlockName ): Array {
		$nyuConfg = $GLOBALS['nyuConfig'];
		$accsConf = $nyuConfg->obtainRowValue('ACCESS', strtoupper($cfgBlockName));
		return is_array($accsConf) ? $accsConf : Array();
	}
}
