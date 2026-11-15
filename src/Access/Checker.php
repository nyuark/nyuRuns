<?php

namespace nyu\Access;

use nyu\Tools\Request;
use nyu\Tools\Registry;
use nyu\Tools\Router;
use nyu\Tools\Logger;

/**
 * Loads the carrier ('carrWith') and credential check ('authWith')
 * the 'security' block names for the current request, verifies access,
 * and reports the result.
 */
class Checker{

	// Roles an identity can carry, from the least to the most privileged.
	// A route or an action asks for one as a minimum (see roleMeets)
	const ROLE_LIST = Array('standard', 'advanced', 'delegate', 'managers');
	// Short names of the framework's strategies, as the config files write them, and their classes.
	// This is the only place that ties the two together. Any other short name is a plug-in (see plugClass)
	const AUTH_LIST = Array('htpasswd'=>'PassList', 'nativeDb'=>'Native', 'regdKeys'=>'RegKey', 'userFile'=>'UserList', 'dvcToken'=>'Device');
	const SESS_LIST = Array('httpBasc'=>'Basic', 'apiHeadr'=>'Header', 'jwtToken'=>'JWT', 'phpSessn'=>'PHPSess', 'clntCert'=>'ClCert');
	// Carriers that hand what they carry to a credential check, and the framework's checks that can take it.
	// A plug-in check is taken as able to
	const PAIR_LIST = Array('httpBasc'=>Array('htpasswd', 'nativeDb', 'userFile'), 'apiHeadr'=>Array('regdKeys', 'nativeDb', 'userFile', 'dvcToken'));

	protected $accessConf;
	protected $requestObj;
	protected $routerRegr;
	protected $routingObj;
	protected $routesList = null;

	/**
	 * Builds the access check of a request from the 'security' block, the request and its route.
	 *
	 * @param Array     $accessConfig Resolved 'security' config block for the active preset.
	 * @param Request   $givenRequest The shared, already-parsed HTTP request.
	 * @param ?Registry $resolvedRegr The resolved route, used to recognise public routes.
	 * @param ?Router   $routerObject The router of the preset, for the declared routes a '/path' of 'minRoles' names: without one, such a key cannot be applied (see checkRoleFlaws).
	 */
	function __construct( Array $accessConfig, Request $givenRequest, ?Registry $resolvedRegr = null, ?Router $routerObject = null ){
		$this->accessConf = $accessConfig;
		$this->requestObj = $givenRequest;
		$this->routerRegr = $resolvedRegr;
		$this->routingObj = $routerObject;
	}

	/**
	 * Resolves the class of a credential check from its short name: one
	 * of the framework's (see AUTHLIST), otherwise a plug-in of runs/Shared.
	 *
	 * @param String $shrtNamedTxt Short name, as written in the config.
	 * @return ?String The class name with its namespace, or null for an unknown short name.
	 */
	public static function authClssString( String $shrtNamedTxt ): ?String {
		return isset(self::AUTH_LIST[$shrtNamedTxt]) ? '\\nyu\\Access\\Auth\\'.self::AUTH_LIST[$shrtNamedTxt] : self::plugClssString($shrtNamedTxt, 'authWith', 'Auth');
	}

	/**
	 * Resolves the class of a carrier from its short name: one of
	 * the framework's (see SESSLIST), otherwise a plug-in of runs/Shared.
	 *
	 * @param String $shrtNamedTxt Short name, as written in the config.
	 * @return ?String The class name with its namespace, or null for an unknown short name.
	 */
	public static function sessnClassText( String $shrtNamedTxt ): ?String {
		return isset(self::SESS_LIST[$shrtNamedTxt]) ? '\\nyu\\Access\\Carr\\'.self::SESS_LIST[$shrtNamedTxt] : self::plugClssString($shrtNamedTxt, 'carrWith', 'Carr');
	}

	/**
	 * Tells whether a role is enough where another one is asked for: the
	 * roles are ordered (see ROLELIST), and each one includes those before it.
	 *
	 * @param ?String $signedInRole Role held, null for none.
	 * @param String  $requiredRole Role asked for.
	 * @return Bool True when the role held is the one asked for or a higher one; false for an unknown role.
	 */
	public static function rleAccessMeets( ?String $signedInRole, String $requiredRole ): Bool {
		$heldLevl = array_search($signedInRole, self::ROLE_LIST, true);
		$needLevl = array_search($requiredRole, self::ROLE_LIST, true);
		return $heldLevl!==false && $needLevl!==false && $heldLevl>=$needLevl;
	}

	/**
	 * Resolves the class of a strategy the applications add. Its short
	 * name must be six lower-case letters or digits and be listed in the
	 * 'plugLibs' block of the ACCESS config; runs/Shared must then hold
	 * <Name>Auth.php or <Name>Carr.php, declaring the class of that name
	 * in nyu\Runs\Shared, which must implement the framework's Handler
	 * interface of its kind. A listed plug-in that cannot be used is logged.
	 *
	 * @param String $shrtNamedTxt Short name, as written in the config.
	 * @param String $accPluginLst Key of the 'plugLibs' block listing the names allowed: 'authWith' or 'carrWith'.
	 * @param String $pluginSuffix 'Auth' or 'Carr': suffix of the class, and namespace of its interface.
	 * @return ?String The class name with its namespace, or null when there is no such plug-in.
	 */
	protected static function plugClssString( String $shrtNamedTxt, String $accPluginLst, String $pluginSuffix ): ?String {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$plugConf = ($nyuConfg!==null) ? $nyuConfg->obtainRowValue('ACCESS', 'PLUGLIBS') : null;
		$allowLst = is_array($plugConf) ? ($plugConf[$accPluginLst] ?? Array()) : Array();
		if( !preg_match('/^[a-z][a-z0-9]{5}$/D', $shrtNamedTxt) || !in_array($shrtNamedTxt, (array)$allowLst, true) || !defined('RUN_DIR') ){
			return null;
		}
		$classNme = ucfirst($shrtNamedTxt).$pluginSuffix;
		$classFle = RUN_DIR.DS.'Shared'.DS.$classNme.'.php';
		$fullClss = '\\nyu\\Runs\\Shared\\'.$classNme;
		$whyStrng = '';
		if( !class_exists($fullClss, false) ){
			if( !is_file($classFle) ){
				$whyStrng = 'runs/Shared/'.$classNme.'.php is missing';
			}else{
				try{
					require_once $classFle;
				}catch( \Throwable $e ){
					$whyStrng = 'runs/Shared/'.$classNme.'.php cannot be loaded: '.$e->getMessage();
				}
			}
		}
		if( $whyStrng=='' && !class_exists($fullClss, false) ){
			$whyStrng = 'runs/Shared/'.$classNme.'.php does not declare '.ltrim($fullClss, '\\');
		}
		if( $whyStrng=='' && !is_subclass_of($fullClss, '\\nyu\\Access\\'.$pluginSuffix.'\\Handler') ){
			$whyStrng = ltrim($fullClss, '\\').' does not implement nyu\\Access\\'.$pluginSuffix.'\\Handler';
		}
		if( $whyStrng!='' ){
			Logger::retrieveLogger('access')->error( 'Access plug-in "'.$shrtNamedTxt.'" cannot be used: '.$whyStrng );
			return null;
		}
		return $fullClss;
	}

	/**
	 * Verifies access for the current request against the configured
	 * strategy. Fails closed on misconfiguration (never falls back to
	 * a different strategy): an unknown carrier or check, or a pair that
	 * cannot work together (see pairsFlaw), is answered 500. When no check applies (access not required,
	 * or a public route), the request goes through as not logged. An
	 * address that presented too many wrong credentials is answered 429
	 * until its hold ends (see Limiter). A request denied an HTML page is
	 * given the sign-in route to go to, when the 'security' block names one
	 * (see signinUrl). A logged user who does not hold the role its route
	 * asks for (the 'minRoles' key, see routeRole) is answered 404, as for a
	 * route that does not exist; the refusal is logged (see logDenial). The call
	 * of an application for one of its users is checked by its own key and
	 * token (the 'appsConf' key, see appsCalls).
	 *
	 * @return Registry Registry with 'loggedIn', 'userName', 'userRole', 'identity', 'statCode' and 'statMssg' keys, 'retryAft' (seconds to wait) with a 429 status, 'denyGoal' (address to send the visitor to) with the 401 of a page, and 'appsName' (the name of the application) for the call of an application.
	 */
	function validateAccess(): Registry {
		// The roles asked for are read first : a block that cannot be applied refuses every request
		$roleFlow = $this->checkRoleFlaws();
		if( $roleFlow!='' ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: '.$roleFlow );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}
		$needRole = $this->routRoleString();
		// Without an access config (conf/access.json missing or empty), only a preset open by intent ("isPublic") is served : anything else fails closed
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$accesAll = ($nyuConfg!==null) ? $nyuConfg->readValueByKey('access') : null;
		$hasConfg = is_array($accesAll) && !empty($accesAll);
		if( !$hasConfg && ($this->accessConf['isPublic'] ?? false)!==true ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: no access config (conf/access.json), and the preset is not open by intent ("isPublic")' );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}
		if( empty($this->accessConf) || ($this->accessConf['required'] ?? true) === false || $this->openAccessRout() ){
			// A route cannot be open and ask for a role : only its own OPTIONS answer goes through
			if( $needRole!==null && $this->routerRegr->readValueByKey('statCode')!=204 ){
				$this->logAccsAttempt( false, '', 'Access misconfigured: the route is both open and asking for a role' );
				return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'userRole'=>null, 'statCode'=>200, 'statMssg'=>'') );
		}

		// A check without an access config has nothing to check with, whatever the preset says of itself : it fails closed
		if( !$hasConfg ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: no access config (conf/access.json), and the preset checks access' );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		$sessName = strval($this->accessConf['carrWith'] ?? '');
		$authName = $this->accessConf['authWith'] ?? null;

		$sessClss = self::sessnClassText($sessName);
		if( $sessClss===null || !class_exists($sessClss) ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: unknown carrier "'.$sessName.'"' );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		// The sign-in route is a path of this site : never an address elsewhere
		$signRout = $this->accessConf['signPath'] ?? null;
		if( $signRout!==null && ( !is_string($signRout) || !preg_match('#^/(?!/)#', $signRout) || !Router::validRouteAddr($signRout) ) ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: the sign-in route is not a path of this site' );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		$authInst = null;
		if( $authName!=null ){
			$authClss = self::authClssString(strval($authName));
			if( $authClss===null || !class_exists($authClss) ){
				$this->logAccsAttempt( false, '', 'Access misconfigured: unknown credential check "'.strval($authName).'"' );
				return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			// The credential check reads its own block when 'authConf' names one, the carrier's otherwise
			$authInst = new $authClss( $this->findAccessConf($this->accessConf['authConf'] ?? $this->accessConf['carrConf'] ?? '') );
		}

		// A carrier and a check that cannot work together refuse every request, as a mistake of the config
		$pairFlow = $this->checkPairFlaws();
		if( $pairFlow!='' ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: '.$pairFlow );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrInst = new Limiter( $this->findAccessConf('tryLimit') );
		$userAddr = $this->clntAddressTxt();
		$heldTime = $lmtrInst->heldUntilCount($userAddr);
		if( $heldTime>0 ){
			$this->logAccsAttempt( false, '', 'Too many attempts' );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>429, 'statMssg'=>'Too many attempts', 'retryAft'=>max(1, $heldTime - time())) );
		}

		// An application calling for one of its users is checked by its own key and token, not by the carrier
		$applRslt = $this->verifyAppCalls( $lmtrInst, $userAddr, $needRole );
		if( $applRslt!==null ){
			return $applRslt;
		}

		$sessInst = new $sessClss( $this->findAccessConf($this->accessConf['carrConf'] ?? ''), $authInst );
		$idntList = $sessInst->verifyRqstList( $this->requestObj );

		if( $idntList===false ){
			// Only a credential presented and refused counts against the address: not a missing one, nor a store that cannot be read
			$crdnErro = ($authInst!==null && $authInst->finalErrorText()!='' && (!method_exists($authInst, 'hasWrongSecret') || $authInst->hasWrongSecret()));
			// A carrier that verifies what it carries tells a forged one itself
			$hasWrong = (method_exists($sessInst, 'hasWrongSecret') && $sessInst->hasWrongSecret());
			if( $crdnErro || $hasWrong ){
				$lmtrInst->countFailedTry($userAddr);
			}
			$chlgDict = Array('httpBasc'=>'Basic realm="nyuRuns", charset="UTF-8"', 'jwtToken'=>'Bearer');
			$wwwAuthn = $chlgDict[$sessName] ?? null;
			$this->logAccsAttempt( false, '', $sessInst->finalErrorText() );
			$denyList = Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>401, 'statMssg'=>$sessInst->finalErrorText(), 'wwwHeadr'=>$wwwAuthn);
			// A visitor denied a page is sent to the sign-in route
			$goalPath = $this->getSigninRoute();
			if( $goalPath!==null ){
				$denyList['denyGoal'] = $goalPath;
			}
			return new Registry( $denyList );
		}
		// A credential store that tracks presence is told the user was seen
		if( $authInst!==null && method_exists($authInst, 'retainAliveFlg') && ($idntList['userName'] ?? '')!='' ){
			$authInst->retainAliveFlg( strval($idntList['userName']) );
		}
		// An identity without a known role is given the lowest one, standard
		$userRole = $idntList['userRole'] ?? '';
		if( !in_array($userRole, self::ROLE_LIST, true) ){
			$userRole = self::ROLE_LIST[0];
		}
		// A route asking for a role refuses a user who does not hold it, as if it did not exist : nothing is counted against the address
		if( $needRole!==null && !self::rleAccessMeets($userRole, $needRole) ){
			self::logRoleRefusal( strval($idntList['userName'] ?? ''), $userRole, $needRole, $userAddr, $this->rteDestination() );
			return new Registry( Array('loggedIn'=>true, 'userName'=>$idntList['userName'] ?? '', 'userRole'=>$userRole, 'identity'=>$idntList, 'statCode'=>404, 'statMssg'=>'Not Found') );
		}
		$this->logAccsAttempt( true, strval($idntList['userName'] ?? ''), '', $userRole );
		return new Registry( Array('loggedIn'=>true, 'userName'=>$idntList['userName'] ?? '', 'userRole'=>$userRole, 'identity'=>$idntList, 'statCode'=>200, 'statMssg'=>'') );
	}

	/**
	 * Checks the call of an application on behalf of one of its users,
	 * when the 'appsConf' key of the 'security' block names the block of the
	 * application keys and the request carries one (see AppKey): the key
	 * and the token of the user, then the routes of the application, then
	 * the role the route asks for. A wrong key or a forged token counts
	 * against the address. A granted write (any method but those of
	 * AppKey::READ_LIST) is a notice on the 'access' channel, a read an info.
	 *
	 * @param Limiter $givenLimiter The limit by address, counting the wrong credentials.
	 * @param String  $clientIpAddr Address of the client, empty when unknown.
	 * @param ?String $requiredRole The role the route asks for, null for none.
	 * @return ?Registry The result, as validateAccess() gives it with the application under 'appsName'; null when the request is not the call of an application.
	 */
	protected function verifyAppCalls( Limiter $givenLimiter, String $clientIpAddr, ?String $requiredRole ): ?Registry {
		$blockNme = strval($this->accessConf['appsConf'] ?? '');
		if( $blockNme=='' ){
			return null;
		}
		$keyInstn = new AppKey( $this->findAccessConf($blockNme) );
		$idntList = $keyInstn->verifyAppArray( $this->requestObj );
		if( $idntList===null ){
			return null;
		}
		if( $idntList===false ){
			// A block or a key file that cannot be used is a mistake of the config, as an unknown carrier is
			if( in_array($keyInstn->finalErrorText(), Array('Access misconfigured', 'Token keys not configured'), true) ){
				$this->logAccsAttempt( false, '', 'Access misconfigured: the application keys cannot be used ('.$keyInstn->finalErrorText().')' );
				return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			if( $keyInstn->hasWrongSecret() ){
				$givenLimiter->countFailedTry($clientIpAddr);
			}
			$this->logAccsAttempt( false, '', $keyInstn->finalErrorText() );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>401, 'statMssg'=>$keyInstn->finalErrorText()) );
		}
		$userName = strval($idntList['userName'] ?? '');
		$applName = strval($idntList['appsName']);
		$fromText = ($clientIpAddr!='') ? ' from '.$clientIpAddr : '';
		$denyList = Array('loggedIn'=>true, 'userName'=>$userName, 'userRole'=>$idntList['userRole'], 'identity'=>$idntList, 'appsName'=>$applName, 'statCode'=>404, 'statMssg'=>'Not Found');
		if( $this->routerRegr===null || !$keyInstn->allowsRouteFlg($this->routerRegr) ){
			Logger::retrieveLogger('access')->warning( 'App call refused for app '.json_encode($applName).', user '.json_encode($userName).$fromText.' to '.$this->rteDestination().': not a route of the application' );
			return new Registry( $denyList );
		}
		if( $requiredRole!==null && !self::rleAccessMeets($idntList['userRole'], $requiredRole) ){
			self::logRoleRefusal( $userName, $idntList['userRole'], $requiredRole, $clientIpAddr, $this->rteDestination() );
			return new Registry( $denyList );
		}
		$mthdName = '';
		foreach( array_merge(AppKey::READ_LIST, Array('POST', 'PUT', 'PATCH', 'DELETE')) as $mthVal ){
			if( $this->requestObj->usedMethodFlag($mthVal) ){
				$mthdName = $mthVal;
			}
		}
		$logStrng = 'App call granted for app '.json_encode($applName).', user '.json_encode($userName).' ('.$idntList['userRole'].')'.$fromText.' to '.$this->rteDestination().' ('.($mthdName!='' ? $mthdName : 'other method').')';
		if( in_array($mthdName, AppKey::READ_LIST, true) ){
			Logger::retrieveLogger('access')->info( $logStrng );
		}else{
			Logger::retrieveLogger('access')->notice( $logStrng );
		}
		return new Registry( array_merge($denyList, Array('statCode'=>200, 'statMssg'=>'')) );
	}

	/**
	 * Gives the access of an action called from the command line (see
	 * Shell\ActCaller): no credential is checked, since whoever runs the
	 * command line already holds the files of the project, but the role
	 * the route asks for (the 'minRoles' key) applies to the role the call
	 * is given. The call is traced on the 'access' channel.
	 *
	 * @param String $signedInUser Name the call runs under.
	 * @param String $signedInRole Role the call runs with, one of ROLELIST.
	 * @return Registry Registry with 'loggedIn', 'userName', 'userRole', 'identity', 'statCode' and 'statMssg' keys: 200 when the action may run, 404 when the role is not enough (as for a route that does not exist), 500 when the roles of the routes cannot be applied.
	 */
	public function grantShellCall( String $signedInUser, String $signedInRole ): Registry {
		$roleFlow = $this->checkRoleFlaws();
		if( $roleFlow=='' && !in_array($signedInRole, self::ROLE_LIST, true) ){
			$roleFlow = 'unknown role '.json_encode($signedInRole).' for a call from the command line';
		}
		if( $roleFlow!='' ){
			Logger::retrieveLogger('access')->warning( 'Access denied: Access misconfigured: '.$roleFlow );
			return new Registry( Array('loggedIn'=>false, 'userName'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}
		$idntList = Array('userName'=>$signedInUser, 'userRole'=>$signedInRole, 'idOfUser'=>null);
		$needRole = $this->routRoleString();
		if( $needRole!==null && !self::rleAccessMeets($signedInRole, $needRole) ){
			self::logRoleRefusal( $signedInUser, $signedInRole, $needRole, '', $this->rteDestination() );
			return new Registry( Array('loggedIn'=>true, 'userName'=>$signedInUser, 'userRole'=>$signedInRole, 'identity'=>$idntList, 'statCode'=>404, 'statMssg'=>'Not Found') );
		}
		Logger::retrieveLogger('access')->notice( 'Action '.$this->rteDestination().' called from the command line by user '.json_encode($signedInUser).' ('.$signedInRole.')' );
		return new Registry( Array('loggedIn'=>true, 'userName'=>$signedInUser, 'userRole'=>$signedInRole, 'identity'=>$idntList, 'statCode'=>200, 'statMssg'=>'') );
	}

	/**
	 * Tells whether the resolved route is served without any access check:
	 * an OPTIONS request the route answers by itself, or what the 'openList'
	 * key of the 'security' block lists: an action, as "app/action" or "app/*"
	 * (case-insensitive), or a declared route, as its path with a leading
	 * slash ("/login", "/assets/*"). The path is the one the route is
	 * declared with, to the letter: an entry opens that route only, and
	 * its * is not a pattern of its own.
	 *
	 * @return Bool Whether the route is open to everyone.
	 */
	public function openAccessRout(): Bool {
		if( $this->routerRegr===null ){
			return false;
		}
		$applName = strtolower( strval($this->routerRegr->readValueByKey('appToRun')) );
		$actnName = strtolower( strval($this->routerRegr->readValueByKey('doAction')) );
		$routePth = strval($this->routerRegr->readValueByKey('routPath'));
		$publList = $this->accessConf['openList'] ?? Array();
		// Declared routes, by their path : whatever they answer with
		foreach( (array)$publList as $openRt ){
			$openRt = strval($openRt);
			if( $routePth!='' && substr($openRt, 0, 1)=='/' && trim($openRt, '/')===$routePth ){
				return true;
			}
		}
		if( $applName=='' ){
			// Without an application, only the route's own OPTIONS answer is open
			return $this->routerRegr->readValueByKey('statCode')==204;
		}
		foreach( (array)$publList as $openRt ){
			if( substr(strval($openRt), 0, 1)=='/' ){
				continue;
			}
			$openRt = strtolower( trim(strval($openRt), '/') );
			if( $openRt==$applName.'/'.$actnName || $openRt==$applName.'/*' ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Tells what is wrong with the carrier and the check of the 'security'
	 * block as a pair: a carrier that hands a name and a password, or a
	 * key, to a check that takes the other, or to no check at all (see
	 * PAIRLIST).
	 *
	 * @return String What is wrong, empty when the pair can work.
	 */
	public function checkPairFlaws(): String {
		$sessName = strval($this->accessConf['carrWith'] ?? '');
		$authName = $this->accessConf['authWith'] ?? null;
		if( !isset(self::PAIR_LIST[$sessName]) ){
			return '';
		}
		if( $authName===null || $authName==='' ){
			return 'the carrier "'.$sessName.'" needs a credential check (authWith)';
		}
		$authName = strval($authName);
		if( isset(self::AUTH_LIST[$authName]) && !in_array($authName, self::PAIR_LIST[$sessName], true) ){
			return 'the credential check "'.$authName.'" cannot check what the carrier "'.$sessName.'" hands it';
		}
		return '';
	}

	/**
	 * Checks the 'minRoles' key of the 'security' block: a list of routes, each
	 * with a role of ROLELIST, in a block that requires access. A '/path'
	 * must name a declared route, exactly as the router matches it: one
	 * naming none, or a check given no router to read the routes from,
	 * would ask for nothing, and is refused.
	 *
	 * @return String What is wrong with it, empty when it can be applied.
	 */
	public function checkRoleFlaws(): String {
		$roleDict = $this->accessConf['minRoles'] ?? null;
		if( $roleDict===null ){
			return '';
		}
		if( !is_array($roleDict) ){
			return 'the roles of the routes are not a list of routes';
		}
		if( !empty($roleDict) && ($this->accessConf['required'] ?? true)===false ){
			return 'roles are asked for while access is not required';
		}
		foreach( $roleDict as $rteKey=>$rteRle ){
			if( !is_string($rteRle) || !in_array($rteRle, self::ROLE_LIST, true) ){
				return 'unknown role for the route '.json_encode(strval($rteKey));
			}
		}
		foreach( array_keys($roleDict) as $rteKey ){
			$rteKey = strval($rteKey);
			if( substr($rteKey, 0, 1)!='/' ){
				continue;
			}
			if( $this->routingObj===null ){
				return 'the route '.json_encode($rteKey, JSON_UNESCAPED_SLASHES).' of minRoles cannot be checked: no router is given';
			}
			$this->routesList ??= $this->routingObj->allRoutesArray();
			if( !in_array(trim($rteKey, '/'), array_column($this->routesList, 'routPath'), true) ){
				return 'the route '.json_encode($rteKey, JSON_UNESCAPED_SLASHES).' of minRoles is declared nowhere';
			}
		}
		return '';
	}

	/**
	 * Tells whether a declared route answers with the action asked for:
	 * the same application and the same action, whatever their case, the
	 * default action of the application standing for the one a side does
	 * not name.
	 *
	 * @param String $appNameToRun Application asked for, as the route of the request names it.
	 * @param String $runAppAction Action asked for, in lower case; '' for none.
	 * @param Array  $routEntryLst An entry of Router::allRoutesArray().
	 * @return Bool True when the route answers with that action.
	 */
	protected function matchActionFlg( String $appNameToRun, String $runAppAction, Array $routEntryLst ): Bool {
		$applName = strtolower( str_replace(' ', '', $appNameToRun) );
		if( ($routEntryLst['routKind'] ?? '')!=='toAction' || $applName=='' || strtolower(str_replace(' ', '', strval($routEntryLst['appToRun'] ?? '')))!==$applName ){
			return false;
		}
		$routeAct = strtolower( strval($routEntryLst['doAction'] ?? '') );
		if( $routeAct===$runAppAction ){
			return true;
		}
		// One side names no action : the default action of the application stands for it
		$dfltActn = strtolower( $this->dfltActnString($appNameToRun) );
		return $dfltActn!=='' && (($routeAct==='') ? $dfltActn : $routeAct)===(($runAppAction==='') ? $dfltActn : $runAppAction);
	}

	/**
	 * Gives the default action of an application, as the Controller resolves
	 * it (see Controller::findDfltAction): its Actions.php is loaded, from
	 * the folder the Controller would load it from.
	 *
	 * @param String $appNameToRun Name of the application, as the route of the request names it.
	 * @return String The default action, or '' for an application that is not one.
	 */
	protected function dfltActnString( String $appNameToRun ): String {
		$applName = \nyu\App\Controller::findRunFldrNme($appNameToRun);
		if( $applName==='' ){
			return '';
		}
		$classNme = '\\nyu\\Runs\\'.$applName.'\\Actions';
		$actnPath = RUN_DIR.DS.$applName.DS.'Actions.php';
		if( !class_exists($classNme, false) && is_file($actnPath) ){
			require_once $actnPath;
		}
		return class_exists($classNme, false) ? \nyu\App\Controller::findDfltAction( new \ReflectionClass($classNme) ) : '';
	}

	/**
	 * Gives the role the resolved route asks for, from the 'minRoles' key of
	 * the 'security' block: its routes are written as those of 'openList' (see
	 * openRoute), each with the least role it is served to. When several
	 * entries name the route, the highest role is asked for. A request
	 * naming no action is taken as any action of its application. A path
	 * names a declared route by its final path: a Run's route with its mount.
	 * The role of a path declared to answer with an action (with any of its
	 * methods) follows that action, whatever path reaches it: its own path
	 * (autopath), a route of its Run, another route.
	 *
	 * @return ?String The role, or null when the route asks for none.
	 */
	public function routRoleString(): ?String {
		$roleDict = $this->accessConf['minRoles'] ?? Array();
		if( $this->routerRegr===null || !is_array($roleDict) ){
			return null;
		}
		// The application is named as the Controller reads it : without its spaces
		$applRead = strval($this->routerRegr->readValueByKey('appToRun'));
		$applName = strtolower( str_replace(' ', '', $applRead) );
		$actnName = strtolower( strval($this->routerRegr->readValueByKey('doAction')) );
		$routePth = strval($this->routerRegr->readValueByKey('routPath'));
		$needRole = null;
		foreach( $roleDict as $rteKey=>$rteRle ){
			$rteKey = strval($rteKey);
			if( substr($rteKey, 0, 1)=='/' ){
				$isNameOk = ($routePth!='' && trim($rteKey, '/')===$routePth);
				// The action a declared route answers with carries its role, on every path that reaches it
				if( !$isNameOk && $applName!='' && $this->routingObj!==null ){
					$this->routesList ??= $this->routingObj->allRoutesArray();
					foreach( $this->routesList as $rteRow ){
						if( $rteRow['routPath']===trim($rteKey, '/') && $this->matchActionFlg($applRead, $actnName, $rteRow) ){
							$isNameOk = true;
							break;
						}
					}
				}
			}else{
				$rteKey = strtolower( trim($rteKey, '/') );
				$isNameOk = ($applName!='' && ( $rteKey==$applName.'/'.$actnName || $rteKey==$applName.'/*' || ($actnName=='' && strpos($rteKey, $applName.'/')===0) ));
			}
			if( $isNameOk && ($needRole===null || !self::rleAccessMeets($needRole, strval($rteRle))) ){
				$needRole = strval($rteRle);
			}
		}
		return $needRole;
	}

	/**
	 * Gives the address a denied request is sent to: the sign-in route the
	 * 'signPath' key of the 'security' block names, when the request asked for
	 * a declared HTML page. The page asked for goes with it, as 'back',
	 * for the sign-in to return to. Nothing is given for an action, a
	 * file, or the sign-in route itself.
	 *
	 * @return ?String The address, from the root of the site, or null when the denial is answered as it is.
	 */
	protected function getSigninRoute(): ?String {
		$signRout = strval($this->accessConf['signPath'] ?? '');
		if( $signRout=='' || $this->routerRegr===null || $this->routerRegr->readValueByKey('routKind')!='htmlPage' ){
			return null;
		}
		if( trim($signRout, '/')===strval($this->routerRegr->readValueByKey('routPath')) ){
			return null;
		}
		$askdPath = strval($this->requestObj->obtainUrlValue('fullPath'));
		// The sign-in route is one of the preset's : under the basePath it is served under, as the page asked for is
		$baseText = rtrim(strval($this->routerRegr->readValueByKey('routBase') ?? ''), '/');
		return $baseText.$signRout.( ($askdPath!='' && $askdPath!='/') ? '?back='.rawurlencode($askdPath) : '' );
	}

	/**
	 * Gives the address of the client, as seen through the trusted reverse
	 * proxies.
	 *
	 * @return String The address, empty when the request tells none.
	 */
	protected function clntAddressTxt(): String {
		$clntRegr = $this->requestObj->retrieveClient();
		return ($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '';
	}

	/**
	 * Tells what the request asked for, for the log: the action, as
	 * "app/action", or the path of the declared route.
	 *
	 * @return String The action or the path.
	 */
	protected function rteDestination(): String {
		if( $this->routerRegr===null ){
			return '/';
		}
		$applName = strval($this->routerRegr->readValueByKey('appToRun'));
		return ($applName!='') ? $applName.'/'.strval($this->routerRegr->readValueByKey('doAction')) : '/'.strval($this->routerRegr->readValueByKey('routPath'));
	}

	/**
	 * Logs, as a warning on the 'access' channel, a user refused for its
	 * role: who, with which role, from where, what was asked for and the
	 * role it needed.
	 *
	 * @param String  $signedInUser The identified username, empty for nobody.
	 * @param ?String $signedInRole Its role, null for none.
	 * @param String  $requiredRole The role asked for.
	 * @param String  $clientIpAddr Address of the client, empty when unknown.
	 * @param String  $demandedRout What was asked for: an action or a path.
	 */
	public static function logRoleRefusal( String $signedInUser, ?String $signedInRole, String $requiredRole, String $clientIpAddr, String $demandedRout ): Void {
		$fromText = ($clientIpAddr!='') ? ' from '.$clientIpAddr : '';
		Logger::retrieveLogger('access')->warning( 'Access refused for user '.json_encode($signedInUser).' ('.($signedInRole ?? 'no role').')'.$fromText.' to '.$demandedRout.': role '.$requiredRole.' needed' );
	}

	/**
	 * Logs an access grant/denial on the 'access' channel.
	 *
	 * The address of the client is told with it, when the request has one.
	 * The access of a privileged user (any role above the first of
	 * ROLELIST) is a notice of its own, telling the role and what was asked for.
	 *
	 * @param Bool   $grantedOrNot Whether access was granted.
	 * @param String $signedInUser The identified username, if any.
	 * @param String $denialReason The denial reason, if access was not granted.
	 * @param String $signedInRole The role of the identified user, if any.
	 */
	protected function logAccsAttempt( Bool $grantedOrNot, String $signedInUser, String $denialReason, String $signedInRole='' ): Void {
		$accsJrnl = Logger::retrieveLogger('access');
		$userAddr = $this->clntAddressTxt();
		$fromText = ($userAddr!='') ? ' from '.$userAddr : '';
		if( $grantedOrNot && in_array($signedInRole, array_slice(self::ROLE_LIST, 1), true) ){
			$accsJrnl->notice( 'Privileged access granted for user '.json_encode($signedInUser).' ('.$signedInRole.')'.$fromText.' to '.$this->rteDestination() );
		}elseif( $grantedOrNot ){
			$accsJrnl->info( 'Access granted for user '.json_encode($signedInUser).$fromText );
		}else{
			$accsJrnl->warning( 'Access denied'.$fromText.': '.$denialReason );
		}
	}

	/**
	 * Reads a named strategy config block from the ACCESS config file.
	 *
	 * @param String $cfgBlockName Name of the config block to read.
	 * @return Array The strategy's configuration, or an empty array if not found.
	 */
	protected function findAccessConf( String $cfgBlockName ): Array {
		if( $cfgBlockName=='' ){
			return Array();
		}
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$accsConf = ($nyuConfg!==null) ? $nyuConfg->obtainRowValue('ACCESS', strtoupper($cfgBlockName)) : null;
		return is_array($accsConf) ? $accsConf : Array();
	}
}
