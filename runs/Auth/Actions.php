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
		if($this->refuseUrlPassw( Array('passWord'), 'Login' )){
			return;
		}

		$gvnUser = $this->inputsRgst->readValueByKey('userName');
		$gvnPass = $this->inputsRgst->readValueByKey('passWord');

		// Both are required by input/login.json; what is left to refuse is a value that is not a text
		if(!is_string($gvnUser) || !is_string($gvnPass)){
			$this->statusCode = 400;
			$this->statusMssg = 'Invalid userName or passWord';
			return;
		}

		$lgnConf = $this->resolveConfArr('loginSet');
		$accsLog = Logger::retrieveLogger('access');
		$authCls = Checker::authClssString( strval($lgnConf['authWith'] ?? 'htpasswd') );
		$sessCls = Checker::sessnClassText( strval($lgnConf['carrWith'] ?? 'jwtToken') );
		if($authCls===null || $sessCls===null || !class_exists($authCls) || !class_exists($sessCls)){
			$accsLog->error( 'Login unavailable: unknown authWith or carrWith in the login block' );
			$this->statusCode = 500;
			$this->statusMssg = 'Login unavailable';
			return;
		}
		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrArr = $this->refuseHeldAddr('Login', $gvnUser);
		if($lmtrArr===null){
			return;
		}
		$lmtrObj = $lmtrArr[0];
		$usrAddr = $lmtrArr[1];
		$fromTxt = ($usrAddr!='') ? ' from '.$usrAddr : '';
		$sessObj = new $sessCls( $this->resolveConfArr($lgnConf['carrConf'] ?? 'jwtToken') );
		// A project holding only the public key checks the tokens another server issues : nothing is checked here
		if(method_exists($sessObj, 'verifyOnlyFlag') && $sessObj->verifyOnlyFlag()){
			$accsLog->warning( 'Login refused for user '.json_encode($gvnUser).$fromTxt.': this project only checks tokens, issued by another server' );
			$this->statusCode = 501;
			$this->statusMssg = 'Tokens are issued by another server';
			return;
		}
		$authObj = new $authCls( $this->resolveConfArr($lgnConf['authConf'] ?? 'htpasswd') );
		// The 'sentCode' input carries the one-time code of a user with 2FA
		$gvnCode = $this->inputsRgst->readValueByKey('sentCode');
		// The login mark of a client already used: the hold of an account does not stop it (see Access\LoginMark)
		$gvnMark = strval( $this->requestObj->retrieveHeader('X-Lgmark') ?? (is_string($this->requestObj->retrieveCookie('lgmark')) ? $this->requestObj->retrieveCookie('lgmark') : '') );
		$idntArr = $authObj->checkSecretArr( Array('user'=>$gvnUser, 'pass'=>$gvnPass, 'code'=>is_scalar($gvnCode) ? strval($gvnCode) : '', 'mark'=>$gvnMark) );

		if($idntArr===false){
			// Only a credential presented and refused counts against the address
			if(!method_exists($authObj, 'hasWrongSecret') || $authObj->hasWrongSecret()){
				$lmtrObj->countFailedTry($usrAddr);
			}
			$accsLog->warning( 'Login refused for user '.json_encode($gvnUser).$fromTxt.': '.$authObj->finalErrorText() );
			$this->statusCode = 401;
			$this->statusMssg = $authObj->finalErrorText();
			return;
		}

		// A session the request came with is closed : a new login does not leave the former one open
		if(method_exists($sessObj, 'dropUsrSession')){
			$sessObj->dropUsrSession( $this->requestObj );
		}
		$rawTokn = $sessObj->issueTokenText( $idntArr );
		if($rawTokn==''){
			$accsLog->error( 'Login unavailable: '.($sessObj->finalErrorText() ?: 'the carrier issues no token') );
			$this->statusCode = 500;
			$this->statusMssg = 'Login unavailable';
			return;
		}

		// A credential store that tracks presence is told the user was seen
		if(method_exists($authObj, 'retainAliveFlg')){
			$authObj->retainAliveFlg( strval($idntArr['userName'] ?? '') );
		}
		// A login is always traced, production included; the one of a privileged user (any role above the first of ROLELIST) tells it
		$usrRole = strval($idntArr['userRole'] ?? '');
		if(in_array($usrRole, array_slice(Checker::ROLE_LIST, 1), true)){
			$accsLog->notice( 'Privileged login granted for user '.json_encode($gvnUser).' ('.$usrRole.')'.$fromTxt );
		}else{
			$accsLog->notice( 'Login granted for user '.json_encode($gvnUser).$fromTxt );
		}
		$this->statusCode = 200;
		// A server-side session travels in its cookie alone : its id is not shown to the scripts of the page
		$this->replyValue = method_exists($sessObj, 'dropUsrSession') ? Array('logged'=>true) : Array('token'=>$rawTokn);
		// A new login mark for this client : in a cookie the scripts of the page cannot read, or in the reply of a token
		$markObj = LoginMark::fromLimitBlock();
		// Made by the store, which signs it with the hash of the password it just checked : a check without one gives no mark
		$newMark = method_exists($authObj, 'createUserMark') ? strval($authObj->createUserMark( strval($idntArr['userName'] ?? $gvnUser) )) : '';
		if($newMark!==''){
			if(method_exists($sessObj, 'dropUsrSession')){
				if(!headers_sent()){
					setcookie('lgmark', $newMark, Array('expires'=>time() + 86400*$markObj->markLastsCount(), 'path'=>$this->markCookiePath(),'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict'));
				}
			}else{
				$this->replyValue['lgmark'] = $newMark;
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
		$routReg = $this->routingObj->locateRouteReg();
		$rtePath = strval( $routReg->readValueByKey('routPath') ?? '' );
		try{
			$linkTxt = $this->routingObj->buildRouteLink( $rtePath, (array)$routReg->readValueByKey('pathVars') );
		}catch( \RuntimeException $e ){
			return '/';
		}
		// The folder of the link, its query string left out
		$linkTxt = strval( strtok($linkTxt, '?') );
		$fldrTxt = substr( $linkTxt, 0, intval(strrpos($linkTxt, '/')) );
		return ($fldrTxt==='') ? '/' : $fldrTxt;
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
		$lgnConf = $this->resolveConfArr('loginSet');
		$sessCls = Checker::sessnClassText( strval($lgnConf['carrWith'] ?? 'jwtToken') );
		$sessObj = ($sessCls!==null && class_exists($sessCls)) ? new $sessCls( $this->resolveConfArr($lgnConf['carrConf'] ?? 'jwtToken') ) : null;
		$keysSet = ($sessObj!==null && method_exists($sessObj, 'publicSetArray')) ? $sessObj->publicSetArray() : Array('keys'=>Array());
		if( empty($keysSet['keys']) ){
			Logger::retrieveLogger('runner')->info( 'Key set not published: the tokens of the login are not signed with a key of RS256 issued here' );
			$this->statusCode = 404;
			$this->statusMssg = 'Not Found';
			return;
		}
		$this->useReplyFormat('jsonData');
		if( $this->respondObj!==null ){
			$this->respondObj->addReplyHeader('Cache-Control', 'public, max-age=300');
			$this->respondObj->addReplyHeader('Expires', gmdate('D, d M Y H:i:s', time() + 300).' GMT');
		}
		$this->statusCode = 200;
		$this->replyValue = $keysSet;
	}

	/**
	 * Ends the session the login opened: a server-side session is
	 * destroyed and its cookie expired. A token cannot be taken back, so
	 * with a token carrier this only tells the client to forget it. Its
	 * route takes POST alone (routes.rte).
	 */
	public function logout(): Void {
		$lgnConf = $this->resolveConfArr('loginSet');
		$sessCls = Checker::sessnClassText( strval($lgnConf['carrWith'] ?? 'jwtToken') );
		if($sessCls!==null && class_exists($sessCls)){
			$sessObj = new $sessCls( $this->resolveConfArr($lgnConf['carrConf'] ?? 'jwtToken') );
			if(method_exists($sessObj, 'dropUsrSession')){
				$sessObj->dropUsrSession( $this->requestObj );
			}
		}
		if($this->accessRgst!==null){
			$accsLog = Logger::retrieveLogger('access');
			$usrRole = strval($this->accessRgst->readValueByKey('userRole'));
			$clntReg = $this->requestObj->retrieveClient();
			$usrAddr = ($clntReg!==null) ? strval($clntReg->readValueByKey('userAddr')) : '';
			$fromTxt = ($usrAddr!='') ? ' from '.$usrAddr : '';
			// A logout is always traced, as the login
			if(in_array($usrRole, array_slice(Checker::ROLE_LIST, 1), true)){
				$accsLog->notice( 'Logout of privileged user '.json_encode($this->accessRgst->readValueByKey('userName')).' ('.$usrRole.')'.$fromTxt );
			}else{
				$accsLog->notice( 'Logout of user '.json_encode($this->accessRgst->readValueByKey('userName')).$fromTxt );
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
		if($this->refuseUrlPassw( Array('passWord'), 'Activation' )){
			return;
		}
		$gvnUser = $this->inputsRgst->readValueByKey('userName');
		$gvnCode = $this->inputsRgst->readValueByKey('sentCode');
		$gvnPass = $this->inputsRgst->readValueByKey('passWord');
		if(!is_string($gvnUser) || !is_string($gvnCode) || !is_string($gvnPass)){
			$this->statusCode = 400;
			$this->statusMssg = 'Invalid userName, sentCode or passWord';
			return;
		}
		$usrBase = UserBase::fromLoginBlock();
		if($usrBase===null){
			$this->statusCode = 500;
			$this->statusMssg = 'User management unavailable';
			return;
		}
		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrArr = $this->refuseHeldAddr('Activation', $gvnUser);
		if($lmtrArr===null){
			return;
		}
		$lmtrObj = $lmtrArr[0];
		$usrAddr = $lmtrArr[1];
		$clntReg = $this->requestObj->retrieveClient();
		// The language the request asks for is given to a user who has none yet : the one who activates is the one the mails go to
		$clntLng = ($clntReg!==null) ? strval($clntReg->readValueByKey('language')) : '';
		if(!$usrBase->activateUsrFlg($gvnUser, $gvnCode, $gvnPass, $clntLng)){
			// Only a wrong name or code counts against the address, not a password breaking a rule
			if($usrBase->hasWrongSecret()){
				$lmtrObj->countFailedTry($usrAddr);
			}
			$this->statusCode = $usrBase->lastStateCount();
			$this->statusMssg = $usrBase->finalErrorText();
			return;
		}
		$usrStts = strval( ($usrBase->userInfosArray($gvnUser) ?: Array())['userStatus'] ?? 'approved' );
		$this->statusCode = 200;
		$this->replyValue = Array('active'=>$usrStts=='approved', 'stat'=>$usrStts);
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
		$usrBase = UserBase::fromLoginBlock();
		$mailObj = AccessMail::fromLoginBlock( $this->mailsDirString(), PHP_SAPI!=='cli' );
		if($usrBase===null || $mailObj===null || !AccessMail::signupsAllowed()){
			$this->statusCode = 404;
			$this->statusMssg = 'Not Found';
			return;
		}
		$gvnUser = $this->inputsRgst->readValueByKey('userName');
		$gvnMail = $this->inputsRgst->readValueByKey('userMail');
		if(!is_string($gvnUser) || !is_string($gvnMail)){
			$this->statusCode = 400;
			$this->statusMssg = 'Invalid userName or userMail';
			return;
		}
		// An address held for wrong credentials is refused; its sign-ups are counted apart, 'maxPerIp' of them within 'holdSecs'
		$lmtConf = $this->resolveConfArr('tryLimit');
		if($this->refuseHeldAddr('Sign-up', $gvnUser)===null){
			return;
		}
		$fldName = trim(strval($lmtConf['dataPath'] ?? ''), '/\\ ');
		$regLmtr = new Limiter( Array('maxTries'=>intval($lmtConf['maxPerIp'] ?? 16), 'dataPath'=>(($fldName!='') ? $fldName : 'temp/limits').'/signup') + $lmtConf );
		$lmtrArr = $this->refuseHeldAddr('Sign-up', $gvnUser, $regLmtr);
		if($lmtrArr===null){
			return;
		}
		// Every sign-up counts : an address cannot fill the mailboxes of others
		$lmtrArr[0]->countFailedTry($lmtrArr[1]);
		$regTops = max( 1, intval($lmtConf['maxQueue'] ?? UserBase::SIGN_MAXI) );
		$clntReg = $this->requestObj->retrieveClient();
		$clntLng = ($clntReg!==null) ? strval($clntReg->readValueByKey('language')) : '';
		$newCode = $usrBase->signsUsrString($gvnUser, $gvnMail, $regTops, $clntLng);
		if($newCode===false){
			$this->statusCode = $usrBase->lastStateCount();
			$this->statusMssg = $usrBase->finalErrorText();
			return;
		}
		if($newCode===''){
			// An address taken is told to its owner alone, in its language; a name taken, to an address of no account, says nothing of any account
			$ownRows = $usrBase->listUsersNamed( Array('contactEml'=>$gvnMail), 1 );
			if(is_array($ownRows) && !empty($ownRows)){
				$mailObj->sendAddrExists($gvnMail, $gvnUser, strval($ownRows[0]['userLocale'] ?? ''));
			}else{
				$mailObj->sendNameExists(strtolower(trim($gvnMail)), $gvnUser, $clntLng);
			}
		}else{
			$mailObj->sendCodeToUser('register', $gvnUser, strtolower(trim($gvnMail)), $newCode, $clntLng);
			$this->notifyManagers($usrBase, $mailObj, $regTops, is_array($lmtConf['alertsAt'] ?? null) ? $lmtConf['alertsAt'] : Array(128, 200, 256));
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
	protected function notifyManagers( UserBase $userDatabase, AccessMail $accessMailer, Int $authTopsNumb, Array $warnAtCounts ): Void {
		$waitNum = $userDatabase->waitingSignups();
		if($waitNum===false || !in_array($waitNum, array_map('intval', $warnAtCounts), true)){
			return;
		}
		$accsLog = Logger::retrieveLogger('access');
		if($waitNum>=$authTopsNumb){
			$accsLog->warning( $waitNum.' sign-ups are waiting: the sign-ups are closed until some are approved or removed' );
		}else{
			$accsLog->notice( $waitNum.' sign-ups are waiting, '.$authTopsNumb.' at most' );
		}
		$mngrLst = $userDatabase->manageMailList();
		if(is_array($mngrLst) && !empty($mngrLst)){
			$accessMailer->sendQueueAlert($mngrLst, $waitNum, $authTopsNumb);
		}else{
			$accsLog->warning( 'No manager with an address is told of the sign-ups waiting' );
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
		$usrBase = UserBase::fromLoginBlock();
		$mailObj = AccessMail::fromLoginBlock( $this->mailsDirString(), PHP_SAPI!=='cli' );
		if($usrBase===null || $mailObj===null){
			$this->statusCode = 404;
			$this->statusMssg = 'Not Found';
			return;
		}
		$gvnUser = $this->inputsRgst->readValueByKey('userName');
		if(!is_string($gvnUser)){
			$this->statusCode = 400;
			$this->statusMssg = 'Invalid userName';
			return;
		}
		$lmtrArr = $this->refuseHeldAddr('Reset', $gvnUser);
		if($lmtrArr===null){
			return;
		}
		$lmtrArr[0]->countFailedTry($lmtrArr[1]);
		$rstVals = $usrBase->resetPassArray($gvnUser);
		if(is_array($rstVals)){
			$clntReg = $this->requestObj->retrieveClient();
			$clntLng = ($clntReg!==null) ? strval($clntReg->readValueByKey('language')) : '';
			$mailObj->sendCodeToUser('password', $rstVals['user'], $rstVals['mail'], $rstVals['code'], $rstVals['lang'] ?? $clntLng);
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
		if($this->refuseUrlPassw( Array('passWord'), 'Password reset' )){
			return;
		}
		$gvnUser = $this->inputsRgst->readValueByKey('userName');
		$gvnCode = $this->inputsRgst->readValueByKey('sentCode');
		$gvnPass = $this->inputsRgst->readValueByKey('passWord');
		if(!is_string($gvnUser) || !is_string($gvnCode) || !is_string($gvnPass)){
			$this->statusCode = 400;
			$this->statusMssg = 'Invalid userName, sentCode or passWord';
			return;
		}
		$usrBase = UserBase::fromLoginBlock();
		if($usrBase===null){
			$this->statusCode = 500;
			$this->statusMssg = 'User management unavailable';
			return;
		}
		$lmtrArr = $this->refuseHeldAddr('Password reset', $gvnUser);
		if($lmtrArr===null){
			return;
		}
		if(!$usrBase->recoverUserFlg($gvnUser, $gvnCode, $gvnPass)){
			if($usrBase->hasWrongSecret()){
				$lmtrArr[0]->countFailedTry($lmtrArr[1]);
			}
			$this->statusCode = $usrBase->lastStateCount();
			$this->statusMssg = $usrBase->finalErrorText();
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
	protected function refuseHeldAddr( String $requestLabel, String $namedUserTxt, ?Limiter $ceilingCount=null ): ?Array {
		$lmtrObj = $ceilingCount ?? new Limiter( $this->resolveConfArr('tryLimit') );
		$clntReg = $this->requestObj->retrieveClient();
		$usrAddr = ($clntReg!==null) ? strval($clntReg->readValueByKey('userAddr')) : '';
		$heldEnd = $lmtrObj->heldUntilCount($usrAddr);
		if($heldEnd>0){
			Logger::retrieveLogger('access')->warning( $requestLabel.' refused for user '.json_encode($namedUserTxt).(($usrAddr!='') ? ' from '.$usrAddr : '').': too many attempts' );
			if($this->respondObj!==null){
				$this->respondObj->addReplyHeader('Retry-After', strval(max(1, $heldEnd - time())));
			}
			$this->statusCode = 429;
			$this->statusMssg = 'Too many attempts';
			return null;
		}
		return Array($lmtrObj, $usrAddr);
	}

	/**
	 * Gives the folder of this application, whose mails folder holds the
	 * templates that replace those of conf/mails.
	 *
	 * @return String The folder.
	 */
	protected function mailsDirString(): String {
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
	 * (see needsPasswdLgn) and 500 when the login does not use the native
	 * userbase.
	 */
	public function setpass(): Void {
		// A device never changes the password of its user: that needs the password login
		$this->needsPasswdLgn();
		if($this->refuseUrlPassw( Array('oldValue', 'passWord'), 'Password change' )){
			return;
		}
		$usrName = ($this->accessRgst!==null && $this->accessRgst->readValueByKey('loggedIn')===true) ? strval($this->accessRgst->readValueByKey('userName')) : '';
		if($usrName==''){
			$this->statusCode = 401;
			$this->statusMssg = 'Not logged in';
			return;
		}
		$gvnFrmr = $this->inputsRgst->readValueByKey('oldValue');
		$gvnPass = $this->inputsRgst->readValueByKey('passWord');
		if(!is_string($gvnFrmr) || !is_string($gvnPass)){
			$this->statusCode = 400;
			$this->statusMssg = 'Invalid oldValue or passWord';
			return;
		}
		$usrBase = UserBase::fromLoginBlock();
		if($usrBase===null){
			$this->statusCode = 500;
			$this->statusMssg = 'User management unavailable';
			return;
		}
		if(!$usrBase->editPasswdFlag($usrName, $gvnFrmr, $gvnPass)){
			if($usrBase->hasWrongSecret()){
				$clntReg = $this->requestObj->retrieveClient();
				(new Limiter( $this->resolveConfArr('tryLimit') ))->countFailedTry( ($clntReg!==null) ? strval($clntReg->readValueByKey('userAddr')) : '' );
			}
			$this->statusCode = $usrBase->lastStateCount();
			$this->statusMssg = $usrBase->finalErrorText();
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
		$usrName = ($this->accessRgst!==null && $this->accessRgst->readValueByKey('loggedIn')===true) ? strval($this->accessRgst->readValueByKey('userName')) : '';
		if($usrName==''){
			$this->statusCode = 401;
			$this->statusMssg = 'Not logged in';
			return;
		}
		$usrBase = UserBase::fromLoginBlock();
		if($usrBase===null){
			$this->statusCode = 500;
			$this->statusMssg = 'User management unavailable';
			return;
		}
		$gvnLang = $this->inputsRgst->readValueByKey('saveLang');
		if(!$usrBase->changeLanguage($usrName, is_string($gvnLang) ? $gvnLang : null)){
			$this->statusCode = $usrBase->lastStateCount();
			$this->statusMssg = $usrBase->finalErrorText();
			return;
		}
		$this->statusCode = 200;
		$this->replyValue = Array('lang'=>($usrBase->userInfosArray($usrName) ?: Array())['userLocale'] ?? null);
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
	protected function refuseUrlPassw( Array $fldNamesList, String $requestLabel ): Bool {
		foreach($fldNamesList as $fldNme){
			if($this->requestObj->isSentViaQuery( strval($fldNme) )){
				$clntReg = $this->requestObj->retrieveClient();
				$usrAddr = ($clntReg!==null) ? strval($clntReg->readValueByKey('userAddr')) : '';
				Logger::retrieveLogger('access')->warning( $requestLabel.' refused'.(($usrAddr!='') ? ' from '.$usrAddr : '').': password sent in the query string' );
				$this->statusCode = 400;
				$this->statusMssg = 'Password sent in the query string';
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a named config block from the ACCESS config file.
	 *
	 * @param String $confBlckName Name of the config block to read.
	 * @return Array The block's values, or an empty array if not found.
	 */
	protected function resolveConfArr( String $confBlckName ): Array {
		$nyuConf = $GLOBALS['nyuConfig'];
		$accsCnf = $nyuConf->obtainRowValue('ACCESS', strtoupper($confBlckName));
		return is_array($accsCnf) ? $accsCnf : Array();
	}
}
