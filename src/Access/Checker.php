<?php

namespace nyu\Access;

use nyu\Tools\Request;
use nyu\Tools\Registry;
use nyu\Tools\Router;
use nyu\Tools\Logger;

/**
 * Loads the session carrier ('sessby') and credential check ('authby')
 * the 'auths' block names for the current request, verifies access,
 * and reports the result.
 */
class Checker{

	// Roles an identity can carry, from the least to the most privileged.
	// A route or an action asks for one as a minimum (see roleMeets)
	const ROLE_LIST = Array('member', 'system', 'manage');
	// Short names of the framework's strategies, as the config files write them, and their classes.
	// This is the only place that ties the two together. Any other short name is a plug-in (see plugClass)
	const AUTH_LIST = Array('htpass'=>'PassList', 'native'=>'Native', 'regkey'=>'RegKey', 'usrlst'=>'UserList', 'device'=>'Device');
	const SESS_LIST = Array('hbasic'=>'Basic', 'header'=>'Header', 'jtoken'=>'JWT', 'phsess'=>'PHPSess', 'clcert'=>'ClCert');
	// Carriers that hand what they carry to a credential check, and the framework's checks that can take it.
	// A plug-in check is taken as able to
	const PAIR_LIST = Array('hbasic'=>Array('htpass', 'native', 'usrlst'), 'header'=>Array('regkey', 'native', 'usrlst', 'device'));

	protected $accssSetup;
	protected $httpRequst;
	protected $routRgstry;

	/**
	 * Builds the access check of a request from the 'auths' block, the request and its route.
	 *
	 * @param Array     $accessConfig Resolved 'auths' config block for the active preset.
	 * @param Request   $requstObject The shared, already-parsed HTTP request.
	 * @param ?Registry $rtngRegistry The resolved route, used to recognise public routes.
	 */
	function __construct( Array $accessConfig, Request $requstObject, ?Registry $rtngRegistry = null ){
		$this->accssSetup = $accessConfig;
		$this->httpRequst = $requstObject;
		$this->routRgstry = $rtngRegistry;
	}

	/**
	 * Resolves the class of a credential check from its short name: one
	 * of the framework's (see AUTHLIST), otherwise a plug-in of runs/Shared.
	 *
	 * @param String $shrtNamedTxt Short name, as written in the config.
	 * @return ?String The class name with its namespace, or null for an unknown short name.
	 */
	public static function authClssString( String $shrtNamedTxt ): ?String {
		return isset(self::AUTH_LIST[$shrtNamedTxt]) ? '\\nyu\\Access\\Auth\\'.self::AUTH_LIST[$shrtNamedTxt] : self::plugClssString($shrtNamedTxt, 'authby', 'Auth');
	}

	/**
	 * Resolves the class of a session carrier from its short name: one of
	 * the framework's (see SESSLIST), otherwise a plug-in of runs/Shared.
	 *
	 * @param String $shrtNamedTxt Short name, as written in the config.
	 * @return ?String The class name with its namespace, or null for an unknown short name.
	 */
	public static function sessnClassText( String $shrtNamedTxt ): ?String {
		return isset(self::SESS_LIST[$shrtNamedTxt]) ? '\\nyu\\Access\\Sess\\'.self::SESS_LIST[$shrtNamedTxt] : self::plugClssString($shrtNamedTxt, 'sessby', 'Sess');
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
		$heldLvl = array_search($signedInRole, self::ROLE_LIST, true);
		$needLvl = array_search($requiredRole, self::ROLE_LIST, true);
		return $heldLvl!==false && $needLvl!==false && $heldLvl>=$needLvl;
	}

	/**
	 * Resolves the class of a strategy the applications add. Its short
	 * name must be six lower-case letters or digits and be listed in the
	 * 'plugin' block of the ACCESS config; runs/Shared must then hold
	 * <Name>Auth.php or <Name>Sess.php, declaring the class of that name
	 * in nyu\Runs\Shared, which must implement the framework's Handler
	 * interface of its kind. A listed plug-in that cannot be used is logged.
	 *
	 * @param String $shrtNamedTxt Short name, as written in the config.
	 * @param String $accPluginLst Key of the 'plugin' block listing the names allowed: 'authby' or 'sessby'.
	 * @param String $pluginSuffix 'Auth' or 'Sess': suffix of the class, and namespace of its interface.
	 * @return ?String The class name with its namespace, or null when there is no such plug-in.
	 */
	protected static function plugClssString( String $shrtNamedTxt, String $accPluginLst, String $pluginSuffix ): ?String {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$plugCfg = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'PLUGIN') : null;
		$allwLst = is_array($plugCfg) ? ($plugCfg[$accPluginLst] ?? Array()) : Array();
		if( !preg_match('/^[a-z][a-z0-9]{5}$/D', $shrtNamedTxt) || !in_array($shrtNamedTxt, (array)$allwLst, true) || !defined('RUN_DIR') ){
			return null;
		}
		$clsName = ucfirst($shrtNamedTxt).$pluginSuffix;
		$clsFile = RUN_DIR.DS.'Shared'.DS.$clsName.'.php';
		$fullCls = '\\nyu\\Runs\\Shared\\'.$clsName;
		$whyText = '';
		if( !class_exists($fullCls, false) ){
			if( !is_file($clsFile) ){
				$whyText = 'runs/Shared/'.$clsName.'.php is missing';
			}else{
				try{
					require_once $clsFile;
				}catch( \Throwable $e ){
					$whyText = 'runs/Shared/'.$clsName.'.php cannot be loaded: '.$e->getMessage();
				}
			}
		}
		if( $whyText=='' && !class_exists($fullCls, false) ){
			$whyText = 'runs/Shared/'.$clsName.'.php does not declare '.ltrim($fullCls, '\\');
		}
		if( $whyText=='' && !is_subclass_of($fullCls, '\\nyu\\Access\\'.$pluginSuffix.'\\Handler') ){
			$whyText = ltrim($fullCls, '\\').' does not implement nyu\\Access\\'.$pluginSuffix.'\\Handler';
		}
		if( $whyText!='' ){
			Logger::retrieveLogger('access')->error( 'Access plug-in "'.$shrtNamedTxt.'" cannot be used: '.$whyText );
			return null;
		}
		return $fullCls;
	}

	/**
	 * Verifies access for the current request against the configured
	 * strategy. Fails closed on misconfiguration (never falls back to
	 * a different strategy): an unknown carrier or check, or a pair that
	 * cannot work together (see pairsFlaw), is answered 500. When no check applies (access not required,
	 * or a public route), the request goes through as not logged. An
	 * address that presented too many wrong credentials is answered 429
	 * until its hold ends (see Limiter). A request denied an HTML page is
	 * given the sign-in route to go to, when the 'auths' block names one
	 * (see signinUrl). A logged user who does not hold the role its route
	 * asks for (the 'rolert' key, see routeRole) is answered 404, as for a
	 * route that does not exist; the refusal is logged (see logDenial). The call
	 * of an application for one of its users is checked by its own key and
	 * token (the 'appkey' key, see appsCalls).
	 *
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys, 'retryAft' (seconds to wait) with a 429 status, 'denyGoal' (address to send the visitor to) with the 401 of a page, and 'fromApp' (the name of the application) for the call of an application.
	 */
	function validateAccess(): Registry {
		// The roles asked for are read first : a block that cannot be applied refuses every request
		$rleFlow = $this->checkRoleFlaws();
		if( $rleFlow!='' ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: '.$rleFlow );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}
		$needRle = $this->routRoleString();
		if( empty($this->accssSetup) || ($this->accssSetup['needed'] ?? true) === false || $this->openAccessRout() ){
			// A route cannot be open and ask for a role : only its own OPTIONS answer goes through
			if( $needRle!==null && $this->routRgstry->readFileDetail('statCode')!=204 ){
				$this->logAccsAttempt( false, '', 'Access misconfigured: the route is both open and asking for a role' );
				return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			return new Registry( Array('logged'=>false, 'user'=>'', 'role'=>null, 'statCode'=>200, 'statMssg'=>'') );
		}

		$sessNme = strval($this->accssSetup['sessby'] ?? '');
		$authNme = $this->accssSetup['authby'] ?? null;

		$sessCls = self::sessnClassText($sessNme);
		if( $sessCls===null || !class_exists($sessCls) ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: unknown session carrier "'.$sessNme.'"' );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		// The sign-in route is a path of this site : never an address elsewhere
		$signRte = $this->accssSetup['signin'] ?? null;
		if( $signRte!==null && ( !is_string($signRte) || !preg_match('#^/(?!/)#', $signRte) || !Router::validRouteAddr($signRte) ) ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: the sign-in route is not a path of this site' );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		$authObj = null;
		if( $authNme!=null ){
			$authCls = self::authClssString(strval($authNme));
			if( $authCls===null || !class_exists($authCls) ){
				$this->logAccsAttempt( false, '', 'Access misconfigured: unknown credential check "'.strval($authNme).'"' );
				return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			// The credential check reads its own block when 'authcf' names one, the carrier's otherwise
			$authObj = new $authCls( $this->resolveConfArr($this->accssSetup['authcf'] ?? $this->accssSetup['sesscf'] ?? '') );
		}

		// A carrier and a check that cannot work together refuse every request, as a mistake of the config
		$pairFlw = $this->checkPairFlaws();
		if( $pairFlw!='' ){
			$this->logAccsAttempt( false, '', 'Access misconfigured: '.$pairFlw );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrObj = new Limiter( $this->resolveConfArr('limits') );
		$usrAddr = $this->clntAddressTxt();
		$heldEnd = $lmtrObj->heldUntilCount($usrAddr);
		if( $heldEnd>0 ){
			$this->logAccsAttempt( false, '', 'Too many attempts' );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>429, 'statMssg'=>'Too many attempts', 'retryAft'=>max(1, $heldEnd - time())) );
		}

		// An application calling for one of its users is checked by its own key and token, not by the carrier
		$appRslt = $this->verifyAppCalls( $lmtrObj, $usrAddr, $needRle );
		if( $appRslt!==null ){
			return $appRslt;
		}

		$sessObj = new $sessCls( $this->resolveConfArr($this->accssSetup['sesscf'] ?? ''), $authObj );
		$idntArr = $sessObj->verifyRqstList( $this->httpRequst );

		if( $idntArr===false ){
			// Only a credential presented and refused counts against the address: not a missing one, nor a store that cannot be read
			$credErr = ($authObj!==null && $authObj->finalErrorText()!='' && (!method_exists($authObj, 'wrngCredential') || $authObj->wrngCredential()));
			// A carrier that verifies what it carries tells a forged one itself
			$crrrBad = (method_exists($sessObj, 'wrngCredential') && $sessObj->wrngCredential());
			if( $credErr || $crrrBad ){
				$lmtrObj->countFailedTry($usrAddr);
			}
			$chlgMap = Array('hbasic'=>'Basic realm="nyuRuns", charset="UTF-8"', 'jtoken'=>'Bearer');
			$wwwAuth = $chlgMap[$sessNme] ?? null;
			$this->logAccsAttempt( false, '', $sessObj->finalErrorText() );
			$denyArr = Array('logged'=>false, 'user'=>'', 'statCode'=>401, 'statMssg'=>$sessObj->finalErrorText(), 'wwwAuth'=>$wwwAuth);
			// A visitor denied a page is sent to the sign-in route
			$goalUrl = $this->getSigninRoute();
			if( $goalUrl!==null ){
				$denyArr['denyGoal'] = $goalUrl;
			}
			return new Registry( $denyArr );
		}
		// A credential store that tracks presence is told the user was seen
		if( $authObj!==null && method_exists($authObj, 'retainAliveFlg') && ($idntArr['user'] ?? '')!='' ){
			$authObj->retainAliveFlg( strval($idntArr['user']) );
		}
		// An identity without a known role is a plain member
		$usrRole = $idntArr['role'] ?? '';
		if( !in_array($usrRole, self::ROLE_LIST, true) ){
			$usrRole = self::ROLE_LIST[0];
		}
		// A route asking for a role refuses a user who does not hold it, as if it did not exist : nothing is counted against the address
		if( $needRle!==null && !self::rleAccessMeets($usrRole, $needRle) ){
			self::logRoleRefusal( strval($idntArr['user'] ?? ''), $usrRole, $needRle, $usrAddr, $this->rteDestination() );
			return new Registry( Array('logged'=>true, 'user'=>$idntArr['user'] ?? '', 'role'=>$usrRole, 'ident'=>$idntArr, 'statCode'=>404, 'statMssg'=>'Not Found') );
		}
		$this->logAccsAttempt( true, strval($idntArr['user'] ?? ''), '', $usrRole );
		return new Registry( Array('logged'=>true, 'user'=>$idntArr['user'] ?? '', 'role'=>$usrRole, 'ident'=>$idntArr, 'statCode'=>200, 'statMssg'=>'') );
	}

	/**
	 * Checks the call of an application on behalf of one of its users,
	 * when the 'appkey' key of the 'auths' block names the block of the
	 * application keys and the request carries one (see AppKey): the key
	 * and the token of the user, then the routes of the application, then
	 * the role the route asks for. A wrong key or a forged token counts
	 * against the address. A granted write (any method but those of
	 * AppKey::READ_LIST) is a notice on the 'access' channel, a read an info.
	 *
	 * @param Limiter $limitrObject The limit by address, counting the wrong credentials.
	 * @param String  $clientIpAddr Address of the client, empty when unknown.
	 * @param ?String $requiredRole The role the route asks for, null for none.
	 * @return ?Registry The result, as validateAccess() gives it with the application under 'fromApp'; null when the request is not the call of an application.
	 */
	protected function verifyAppCalls( Limiter $limitrObject, String $clientIpAddr, ?String $requiredRole ): ?Registry {
		$blckNme = strval($this->accssSetup['appkey'] ?? '');
		if( $blckNme=='' ){
			return null;
		}
		$keyInst = new AppKey( $this->resolveConfArr($blckNme) );
		$idntArr = $keyInst->verifyAppArray( $this->httpRequst );
		if( $idntArr===null ){
			return null;
		}
		if( $idntArr===false ){
			// A block or a key file that cannot be used is a mistake of the config, as an unknown carrier is
			if( in_array($keyInst->finalErrorText(), Array('Access misconfigured', 'Token keys not configured'), true) ){
				$this->logAccsAttempt( false, '', 'Access misconfigured: the application keys cannot be used ('.$keyInst->finalErrorText().')' );
				return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			if( $keyInst->wrngCredential() ){
				$limitrObject->countFailedTry($clientIpAddr);
			}
			$this->logAccsAttempt( false, '', $keyInst->finalErrorText() );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>401, 'statMssg'=>$keyInst->finalErrorText()) );
		}
		$usrName = strval($idntArr['user'] ?? '');
		$appName = strval($idntArr['fromApp']);
		$fromTxt = ($clientIpAddr!='') ? ' from '.$clientIpAddr : '';
		$denyArr = Array('logged'=>true, 'user'=>$usrName, 'role'=>$idntArr['role'], 'ident'=>$idntArr, 'fromApp'=>$appName, 'statCode'=>403, 'statMssg'=>'Forbidden');
		if( $this->routRgstry===null || !$keyInst->allowsRouteFlg($this->routRgstry) ){
			Logger::retrieveLogger('access')->warning( 'App call refused for app '.json_encode($appName).', user '.json_encode($usrName).$fromTxt.' to '.$this->rteDestination().': not a route of the application' );
			return new Registry( $denyArr );
		}
		if( $requiredRole!==null && !self::rleAccessMeets($idntArr['role'], $requiredRole) ){
			self::logRoleRefusal( $usrName, $idntArr['role'], $requiredRole, $clientIpAddr, $this->rteDestination() );
			return new Registry( $denyArr );
		}
		$mthName = '';
		foreach( array_merge(AppKey::READ_LIST, Array('POST', 'PUT', 'PATCH', 'DELETE')) as $mthVal ){
			if( $this->httpRequst->usedMethodFlag($mthVal) ){
				$mthName = $mthVal;
			}
		}
		$logText = 'App call granted for app '.json_encode($appName).', user '.json_encode($usrName).' ('.$idntArr['role'].')'.$fromTxt.' to '.$this->rteDestination().' ('.($mthName!='' ? $mthName : 'other method').')';
		if( in_array($mthName, AppKey::READ_LIST, true) ){
			Logger::retrieveLogger('access')->info( $logText );
		}else{
			Logger::retrieveLogger('access')->notice( $logText );
		}
		return new Registry( array_merge($denyArr, Array('statCode'=>200, 'statMssg'=>'')) );
	}

	/**
	 * Gives the access of an action called from the command line (see
	 * Shell\ActCaller): no credential is checked, since whoever runs the
	 * command line already holds the files of the project, but the role
	 * the route asks for (the 'rolert' key) applies to the role the call
	 * is given. The call is traced on the 'access' channel.
	 *
	 * @param String $signedInUser Name the call runs under.
	 * @param String $signedInRole Role the call runs with, one of ROLELIST.
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys: 200 when the action may run, 403 when the role is not enough, 500 when the roles of the routes cannot be applied.
	 */
	public function grantShellCall( String $signedInUser, String $signedInRole ): Registry {
		$rleFlow = $this->checkRoleFlaws();
		if( $rleFlow=='' && !in_array($signedInRole, self::ROLE_LIST, true) ){
			$rleFlow = 'unknown role '.json_encode($signedInRole).' for a call from the command line';
		}
		if( $rleFlow!='' ){
			Logger::retrieveLogger('access')->warning( 'Access denied: Access misconfigured: '.$rleFlow );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}
		$idntArr = Array('user'=>$signedInUser, 'role'=>$signedInRole, 'id'=>null);
		$needRle = $this->routRoleString();
		if( $needRle!==null && !self::rleAccessMeets($signedInRole, $needRle) ){
			self::logRoleRefusal( $signedInUser, $signedInRole, $needRle, '', $this->rteDestination() );
			return new Registry( Array('logged'=>true, 'user'=>$signedInUser, 'role'=>$signedInRole, 'ident'=>$idntArr, 'statCode'=>403, 'statMssg'=>'Forbidden') );
		}
		Logger::retrieveLogger('access')->notice( 'Action '.$this->rteDestination().' called from the command line by user '.json_encode($signedInUser).' ('.$signedInRole.')' );
		return new Registry( Array('logged'=>true, 'user'=>$signedInUser, 'role'=>$signedInRole, 'ident'=>$idntArr, 'statCode'=>200, 'statMssg'=>'') );
	}

	/**
	 * Tells whether the resolved route is served without any access check:
	 * an OPTIONS request the route answers by itself, or what the 'openrt'
	 * key of the 'auths' block lists: an action, as "app/action" or "app/*"
	 * (case-insensitive), or a declared route, as its path with a leading
	 * slash ("/login", "/assets/*"). The path is the one the route is
	 * declared with, to the letter: an entry opens that route only, and
	 * its * is not a pattern of its own.
	 *
	 * @return Bool Whether the route is open to everyone.
	 */
	public function openAccessRout(): Bool {
		if( $this->routRgstry===null ){
			return false;
		}
		$appName = strtolower( strval($this->routRgstry->readFileDetail('app')) );
		$actnNme = strtolower( strval($this->routRgstry->readFileDetail('action')) );
		$rtePath = strval($this->routRgstry->readFileDetail('routPath'));
		$publLst = $this->accssSetup['openrt'] ?? Array();
		// Declared routes, by their path : whatever they answer with
		foreach( (array)$publLst as $openRt ){
			$openRt = strval($openRt);
			if( $rtePath!='' && substr($openRt, 0, 1)=='/' && trim($openRt, '/')===$rtePath ){
				return true;
			}
		}
		if( $appName=='' ){
			// Without an application, only the route's own OPTIONS answer is open
			return $this->routRgstry->readFileDetail('statCode')==204;
		}
		foreach( (array)$publLst as $openRt ){
			if( substr(strval($openRt), 0, 1)=='/' ){
				continue;
			}
			$openRt = strtolower( trim(strval($openRt), '/') );
			if( $openRt==$appName.'/'.$actnNme || $openRt==$appName.'/*' ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Tells what is wrong with the carrier and the check of the 'auths'
	 * block as a pair: a carrier that hands a name and a password, or a
	 * key, to a check that takes the other, or to no check at all (see
	 * PAIRLIST).
	 *
	 * @return String What is wrong, empty when the pair can work.
	 */
	public function checkPairFlaws(): String {
		$sessNme = strval($this->accssSetup['sessby'] ?? '');
		$authNme = $this->accssSetup['authby'] ?? null;
		if( !isset(self::PAIR_LIST[$sessNme]) ){
			return '';
		}
		if( $authNme===null || $authNme==='' ){
			return 'the carrier "'.$sessNme.'" needs a credential check (authby)';
		}
		$authNme = strval($authNme);
		if( isset(self::AUTH_LIST[$authNme]) && !in_array($authNme, self::PAIR_LIST[$sessNme], true) ){
			return 'the credential check "'.$authNme.'" cannot check what the carrier "'.$sessNme.'" hands it';
		}
		return '';
	}

	/**
	 * Checks the 'rolert' key of the 'auths' block: a list of routes, each
	 * with a role of ROLELIST, in a block that requires access.
	 *
	 * @return String What is wrong with it, empty when it can be applied.
	 */
	public function checkRoleFlaws(): String {
		$roleMap = $this->accssSetup['rolert'] ?? null;
		if( $roleMap===null ){
			return '';
		}
		if( !is_array($roleMap) ){
			return 'the roles of the routes are not a list of routes';
		}
		if( !empty($roleMap) && ($this->accssSetup['needed'] ?? true)===false ){
			return 'roles are asked for while access is not required';
		}
		foreach( $roleMap as $rteKey=>$rteRle ){
			if( !is_string($rteRle) || !in_array($rteRle, self::ROLE_LIST, true) ){
				return 'unknown role for the route '.json_encode(strval($rteKey));
			}
		}
		return '';
	}

	/**
	 * Gives the role the resolved route asks for, from the 'rolert' key of
	 * the 'auths' block: its routes are written as those of 'openrt' (see
	 * openRoute), each with the least role it is served to. When several
	 * entries name the route, the highest role is asked for. A request
	 * naming no action is taken as any action of its application. A path
	 * names a declared route by its final path: a Run's route with its mount.
	 *
	 * @return ?String The role, or null when the route asks for none.
	 */
	public function routRoleString(): ?String {
		$roleMap = $this->accssSetup['rolert'] ?? Array();
		if( $this->routRgstry===null || !is_array($roleMap) ){
			return null;
		}
		// The application is named as the Controller reads it : without its spaces
		$appName = strtolower( str_replace(' ', '', strval($this->routRgstry->readFileDetail('app'))) );
		$actnNme = strtolower( strval($this->routRgstry->readFileDetail('action')) );
		$rtePath = strval($this->routRgstry->readFileDetail('routPath'));
		$needRle = null;
		foreach( $roleMap as $rteKey=>$rteRle ){
			$rteKey = strval($rteKey);
			if( substr($rteKey, 0, 1)=='/' ){
				$isNamed = ($rtePath!='' && trim($rteKey, '/')===$rtePath);
			}else{
				$rteKey = strtolower( trim($rteKey, '/') );
				$isNamed = ($appName!='' && ( $rteKey==$appName.'/'.$actnNme || $rteKey==$appName.'/*' || ($actnNme=='' && strpos($rteKey, $appName.'/')===0) ));
			}
			if( $isNamed && ($needRle===null || !self::rleAccessMeets($needRle, strval($rteRle))) ){
				$needRle = strval($rteRle);
			}
		}
		return $needRle;
	}

	/**
	 * Gives the address a denied request is sent to: the sign-in route the
	 * 'signin' key of the 'auths' block names, when the request asked for
	 * a declared HTML page. The page asked for goes with it, as 'back',
	 * for the sign-in to return to. Nothing is given for an action, a
	 * file, or the sign-in route itself.
	 *
	 * @return ?String The address, from the root of the site, or null when the denial is answered as it is.
	 */
	protected function getSigninRoute(): ?String {
		$signRte = strval($this->accssSetup['signin'] ?? '');
		if( $signRte=='' || $this->routRgstry===null || $this->routRgstry->readFileDetail('routKind')!='htmlPage' ){
			return null;
		}
		if( trim($signRte, '/')===strval($this->routRgstry->readFileDetail('routPath')) ){
			return null;
		}
		$askedUr = strval($this->httpRequst->obtainUrlValue('reqstURI'));
		return $signRte.( ($askedUr!='' && $askedUr!='/') ? '?back='.rawurlencode($askedUr) : '' );
	}

	/**
	 * Gives the address of the client, as seen through the trusted reverse
	 * proxies.
	 *
	 * @return String The address, empty when the request tells none.
	 */
	protected function clntAddressTxt(): String {
		$clntReg = $this->httpRequst->retrieveClient();
		return ($clntReg!==null) ? strval($clntReg->readFileDetail('userAddr')) : '';
	}

	/**
	 * Tells what the request asked for, for the log: the action, as
	 * "app/action", or the path of the declared route.
	 *
	 * @return String The action or the path.
	 */
	protected function rteDestination(): String {
		if( $this->routRgstry===null ){
			return '/';
		}
		$appName = strval($this->routRgstry->readFileDetail('app'));
		return ($appName!='') ? $appName.'/'.strval($this->routRgstry->readFileDetail('action')) : '/'.strval($this->routRgstry->readFileDetail('routPath'));
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
		$fromTxt = ($clientIpAddr!='') ? ' from '.$clientIpAddr : '';
		Logger::retrieveLogger('access')->warning( 'Access refused for user '.json_encode($signedInUser).' ('.($signedInRole ?? 'no role').')'.$fromTxt.' to '.$demandedRout.': role '.$requiredRole.' needed' );
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
		$accsLog = Logger::retrieveLogger('access');
		$usrAddr = $this->clntAddressTxt();
		$fromTxt = ($usrAddr!='') ? ' from '.$usrAddr : '';
		if( $grantedOrNot && in_array($signedInRole, array_slice(self::ROLE_LIST, 1), true) ){
			$accsLog->notice( 'Privileged access granted for user '.json_encode($signedInUser).' ('.$signedInRole.')'.$fromTxt.' to '.$this->rteDestination() );
		}elseif( $grantedOrNot ){
			$accsLog->info( 'Access granted for user '.json_encode($signedInUser).$fromTxt );
		}else{
			$accsLog->warning( 'Access denied'.$fromTxt.': '.$denialReason );
		}
	}

	/**
	 * Reads a named strategy config block from the ACCESS config file.
	 *
	 * @param String $confBlckName Name of the config block to read.
	 * @return Array The strategy's configuration, or an empty array if not found.
	 */
	protected function resolveConfArr( String $confBlckName ): Array {
		if( $confBlckName=='' ){
			return Array();
		}
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$accsCnf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', strtoupper($confBlckName)) : null;
		return is_array($accsCnf) ? $accsCnf : Array();
	}
}
