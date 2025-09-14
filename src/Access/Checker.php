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
	const ROLELIST = Array('member', 'system', 'manage');
	// Short names of the framework's strategies, as the config files write them, and their classes.
	// This is the only place that ties the two together. Any other short name is a plug-in (see plugClass)
	const AUTHLIST = Array('htpass'=>'PassList', 'native'=>'Native', 'regkey'=>'RegKey', 'usrlst'=>'UserList');
	const SESSLIST = Array('hbasic'=>'Basic', 'header'=>'Header', 'jtoken'=>'JWT', 'phsess'=>'PHPSess', 'clcert'=>'ClCert');

	protected $axsSetup;
	protected $httpRqst;
	protected $routeReg;

	/**
	 * @param Array     $accessConf Resolved 'auths' config block for the active preset.
	 * @param Request   $requestObj The shared, already-parsed HTTP request.
	 * @param ?Registry $routingReg The resolved route, used to recognise public routes.
	 */
	function __construct( Array $accessConf, Request $requestObj, ?Registry $routingReg = null ){
		$this->axsSetup = $accessConf;
		$this->httpRqst = $requestObj;
		$this->routeReg = $routingReg;
	}

	/**
	 * Resolves the class of a credential check from its short name: one
	 * of the framework's (see AUTHLIST), otherwise a plug-in of runs/Shared.
	 *
	 * @param String $shortNamed Short name, as written in the config.
	 * @return ?String The class name with its namespace, or null for an unknown short name.
	 */
	public static function authClass( String $shortNamed ): ?String {
		return isset(self::AUTHLIST[$shortNamed]) ? '\\nyu\\Access\\Auth\\'.self::AUTHLIST[$shortNamed] : self::plugClass($shortNamed, 'authby', 'Auth');
	}

	/**
	 * Resolves the class of a session carrier from its short name: one of
	 * the framework's (see SESSLIST), otherwise a plug-in of runs/Shared.
	 *
	 * @param String $shortNamed Short name, as written in the config.
	 * @return ?String The class name with its namespace, or null for an unknown short name.
	 */
	public static function sessClass( String $shortNamed ): ?String {
		return isset(self::SESSLIST[$shortNamed]) ? '\\nyu\\Access\\Sess\\'.self::SESSLIST[$shortNamed] : self::plugClass($shortNamed, 'sessby', 'Sess');
	}

	/**
	 * Tells whether a role is enough where another one is asked for: the
	 * roles are ordered (see ROLELIST), and each one includes those before it.
	 *
	 * @param ?String $loggedRole Role held, null for none.
	 * @param String  $neededRole Role asked for.
	 * @return Bool True when the role held is the one asked for or a higher one; false for an unknown role.
	 */
	public static function roleMeets( ?String $loggedRole, String $neededRole ): Bool {
		$heldLvl = array_search($loggedRole, self::ROLELIST, true);
		$needLvl = array_search($neededRole, self::ROLELIST, true);
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
	 * @param String $shortNamed Short name, as written in the config.
	 * @param String $pluginList Key of the 'plugin' block listing the names allowed: 'authby' or 'sessby'.
	 * @param String $kindSuffix 'Auth' or 'Sess': suffix of the class, and namespace of its interface.
	 * @return ?String The class name with its namespace, or null when there is no such plug-in.
	 */
	protected static function plugClass( String $shortNamed, String $pluginList, String $kindSuffix ): ?String {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$plgConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'PLUGIN') : null;
		$alwList = is_array($plgConf) ? ($plgConf[$pluginList] ?? Array()) : Array();
		if( !preg_match('/^[a-z][a-z0-9]{5}$/D', $shortNamed) || !in_array($shortNamed, (array)$alwList, true) || !defined('RUN_DIR') ){
			return null;
		}
		$clsName = ucfirst($shortNamed).$kindSuffix;
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
		if( $whyText=='' && !is_subclass_of($fullCls, '\\nyu\\Access\\'.$kindSuffix.'\\Handler') ){
			$whyText = ltrim($fullCls, '\\').' does not implement nyu\\Access\\'.$kindSuffix.'\\Handler';
		}
		if( $whyText!='' ){
			Logger::getLogger('access')->error( 'Access plug-in "'.$shortNamed.'" cannot be used: '.$whyText );
			return null;
		}
		return $fullCls;
	}

	/**
	 * Verifies access for the current request against the configured
	 * strategy. Fails closed on misconfiguration (never falls back to
	 * a different strategy). When no check applies (access not required,
	 * or a public route), the request goes through as not logged. An
	 * address that presented too many wrong credentials is answered 429
	 * until its hold ends (see Limiter). A request denied an HTML page is
	 * given the sign-in route to go to, when the 'auths' block names one
	 * (see signinUrl). A logged user who does not hold the role its route
	 * asks for (the 'rolert' key, see routeRole) is answered 403.
	 *
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys, 'retryAft' (seconds to wait) with a 429 status, and 'denyGoal' (address to send the visitor to) with the 401 of a page.
	 */
	function verifyAxs(): Registry {
		// The roles asked for are read first : a block that cannot be applied refuses every request
		$roleFlw = $this->rolesFlaw();
		if( $roleFlw!='' ){
			$this->logAttmpt( false, '', 'Access misconfigured: '.$roleFlw );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}
		$needRle = $this->routeRole();
		if( empty($this->axsSetup) || ($this->axsSetup['needed'] ?? true) === false || $this->openRoute() ){
			// A route cannot be open and ask for a role : only its own OPTIONS answer goes through
			if( $needRle!==null && $this->routeReg->readValue('statCode')!=204 ){
				$this->logAttmpt( false, '', 'Access misconfigured: the route is both open and asking for a role' );
				return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			return new Registry( Array('logged'=>false, 'user'=>'', 'role'=>null, 'statCode'=>200, 'statMssg'=>'') );
		}

		$sessNme = strval($this->axsSetup['sessby'] ?? '');
		$authNme = $this->axsSetup['authby'] ?? null;

		$sessCls = self::sessClass($sessNme);
		if( $sessCls===null || !class_exists($sessCls) ){
			$this->logAttmpt( false, '', 'Access misconfigured: unknown session carrier "'.$sessNme.'"' );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		// The sign-in route is a path of this site : never an address elsewhere
		$signRte = $this->axsSetup['signin'] ?? null;
		if( $signRte!==null && ( !is_string($signRte) || !preg_match('#^/(?!/)#', $signRte) || !Router::validAddr($signRte) ) ){
			$this->logAttmpt( false, '', 'Access misconfigured: the sign-in route is not a path of this site' );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}

		$authObj = null;
		if( $authNme!=null ){
			$authCls = self::authClass(strval($authNme));
			if( $authCls===null || !class_exists($authCls) ){
				$this->logAttmpt( false, '', 'Access misconfigured: unknown credential check "'.strval($authNme).'"' );
				return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
			}
			// The credential check reads its own block when 'authcf' names one, the carrier's otherwise
			$authObj = new $authCls( $this->resolvCnf($this->axsSetup['authcf'] ?? $this->axsSetup['sesscf'] ?? '') );
		}

		// An address that presented too many wrong credentials is refused before anything is checked
		$lmtrObj = new Limiter( $this->resolvCnf('limits') );
		$usrAddr = $this->clientAdr();
		$heldEnd = $lmtrObj->heldUntil($usrAddr);
		if( $heldEnd>0 ){
			$this->logAttmpt( false, '', 'Too many attempts' );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>429, 'statMssg'=>'Too many attempts', 'retryAft'=>max(1, $heldEnd - time())) );
		}

		$sessObj = new $sessCls( $this->resolvCnf($this->axsSetup['sesscf'] ?? ''), $authObj );
		$idntArr = $sessObj->verifyReq( $this->httpRqst );

		if( $idntArr===false ){
			// Only a credential presented and refused counts against the address: not a missing one, nor a store that cannot be read
			if( $authObj!==null && $authObj->lastError()!='' && (!method_exists($authObj, 'wrongCred') || $authObj->wrongCred()) ){
				$lmtrObj->countFail($usrAddr);
			}
			$chlgMap = Array('hbasic'=>'Basic', 'jtoken'=>'Bearer');
			$wwwAuth = $chlgMap[$sessNme] ?? null;
			$this->logAttmpt( false, '', $sessObj->lastError() );
			$denyArr = Array('logged'=>false, 'user'=>'', 'statCode'=>401, 'statMssg'=>$sessObj->lastError(), 'wwwAuth'=>$wwwAuth);
			// A visitor denied a page is sent to the sign-in route
			$goalUrl = $this->signinUrl();
			if( $goalUrl!==null ){
				$denyArr['denyGoal'] = $goalUrl;
			}
			return new Registry( $denyArr );
		}
		// A credential store that tracks presence is told the user was seen
		if( $authObj!==null && method_exists($authObj, 'keepAlive') && ($idntArr['user'] ?? '')!='' ){
			$authObj->keepAlive( strval($idntArr['user']) );
		}
		// An identity without a known role is a plain member
		$usrRole = $idntArr['role'] ?? '';
		if( !in_array($usrRole, self::ROLELIST, true) ){
			$usrRole = self::ROLELIST[0];
		}
		// A route asking for a role refuses a user who does not hold it : nothing is counted against the address
		if( $needRle!==null && !self::roleMeets($usrRole, $needRle) ){
			self::logDenial( strval($idntArr['user'] ?? ''), $usrRole, $needRle, $usrAddr, $this->routeGoal() );
			return new Registry( Array('logged'=>true, 'user'=>$idntArr['user'] ?? '', 'role'=>$usrRole, 'ident'=>$idntArr, 'statCode'=>403, 'statMssg'=>'Forbidden') );
		}
		$this->logAttmpt( true, strval($idntArr['user'] ?? ''), '', $usrRole );
		return new Registry( Array('logged'=>true, 'user'=>$idntArr['user'] ?? '', 'role'=>$usrRole, 'ident'=>$idntArr, 'statCode'=>200, 'statMssg'=>'') );
	}

	/**
	 * Gives the access of an action called from the command line (see
	 * Shell\ActCaller): no credential is checked, since whoever runs the
	 * command line already holds the files of the project, but the role
	 * the route asks for (the 'rolert' key) applies to the role the call
	 * is given. The call is traced on the 'access' channel.
	 *
	 * @param String $loggedUser Name the call runs under.
	 * @param String $loggedRole Role the call runs with, one of ROLELIST.
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys: 200 when the action may run, 403 when the role is not enough, 500 when the roles of the routes cannot be applied.
	 */
	public function shellPass( String $loggedUser, String $loggedRole ): Registry {
		$roleFlw = $this->rolesFlaw();
		if( $roleFlw=='' && !in_array($loggedRole, self::ROLELIST, true) ){
			$roleFlw = 'unknown role '.json_encode($loggedRole).' for a call from the command line';
		}
		if( $roleFlw!='' ){
			Logger::getLogger('access')->warning( 'Access denied: Access misconfigured: '.$roleFlw );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>500, 'statMssg'=>'Access misconfigured') );
		}
		$idntArr = Array('user'=>$loggedUser, 'role'=>$loggedRole, 'id'=>null);
		$needRle = $this->routeRole();
		if( $needRle!==null && !self::roleMeets($loggedRole, $needRle) ){
			self::logDenial( $loggedUser, $loggedRole, $needRle, '', $this->routeGoal() );
			return new Registry( Array('logged'=>true, 'user'=>$loggedUser, 'role'=>$loggedRole, 'ident'=>$idntArr, 'statCode'=>403, 'statMssg'=>'Forbidden') );
		}
		Logger::getLogger('access')->notice( 'Action '.$this->routeGoal().' called from the command line by user '.json_encode($loggedUser).' ('.$loggedRole.')' );
		return new Registry( Array('logged'=>true, 'user'=>$loggedUser, 'role'=>$loggedRole, 'ident'=>$idntArr, 'statCode'=>200, 'statMssg'=>'') );
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
	public function openRoute(): Bool {
		if( $this->routeReg===null ){
			return false;
		}
		$appName = strtolower( strval($this->routeReg->readValue('app')) );
		$actName = strtolower( strval($this->routeReg->readValue('action')) );
		$rtePath = strval($this->routeReg->readValue('rtePath'));
		$pubList = $this->axsSetup['openrt'] ?? Array();
		// Declared routes, by their path : whatever they answer with
		foreach( (array)$pubList as $pubRte ){
			$pubRte = strval($pubRte);
			if( $rtePath!='' && substr($pubRte, 0, 1)=='/' && trim($pubRte, '/')===$rtePath ){
				return true;
			}
		}
		if( $appName=='' ){
			// Without an application, only the route's own OPTIONS answer is open
			return $this->routeReg->readValue('statCode')==204;
		}
		foreach( (array)$pubList as $pubRte ){
			if( substr(strval($pubRte), 0, 1)=='/' ){
				continue;
			}
			$pubRte = strtolower( trim(strval($pubRte), '/') );
			if( $pubRte==$appName.'/'.$actName || $pubRte==$appName.'/*' ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks the 'rolert' key of the 'auths' block: a list of routes, each
	 * with a role of ROLELIST, in a block that requires access.
	 *
	 * @return String What is wrong with it, empty when it can be applied.
	 */
	public function rolesFlaw(): String {
		$roleMap = $this->axsSetup['rolert'] ?? null;
		if( $roleMap===null ){
			return '';
		}
		if( !is_array($roleMap) ){
			return 'the roles of the routes are not a list of routes';
		}
		if( !empty($roleMap) && ($this->axsSetup['needed'] ?? true)===false ){
			return 'roles are asked for while access is not required';
		}
		foreach( $roleMap as $rteKey=>$rteRle ){
			if( !is_string($rteRle) || !in_array($rteRle, self::ROLELIST, true) ){
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
	 * naming no action is taken as any action of its application.
	 *
	 * @return ?String The role, or null when the route asks for none.
	 */
	public function routeRole(): ?String {
		$roleMap = $this->axsSetup['rolert'] ?? Array();
		if( $this->routeReg===null || !is_array($roleMap) ){
			return null;
		}
		// The application is named as the Controller reads it : without its spaces
		$appName = strtolower( str_replace(' ', '', strval($this->routeReg->readValue('app'))) );
		$actName = strtolower( strval($this->routeReg->readValue('action')) );
		$rtePath = strval($this->routeReg->readValue('rtePath'));
		$needRle = null;
		foreach( $roleMap as $rteKey=>$rteRle ){
			$rteKey = strval($rteKey);
			if( substr($rteKey, 0, 1)=='/' ){
				$isNamed = ($rtePath!='' && trim($rteKey, '/')===$rtePath);
			}else{
				$rteKey = strtolower( trim($rteKey, '/') );
				$isNamed = ($appName!='' && ( $rteKey==$appName.'/'.$actName || $rteKey==$appName.'/*' || ($actName=='' && strpos($rteKey, $appName.'/')===0) ));
			}
			if( $isNamed && ($needRle===null || !self::roleMeets($needRle, strval($rteRle))) ){
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
	protected function signinUrl(): ?String {
		$signRte = strval($this->axsSetup['signin'] ?? '');
		if( $signRte=='' || $this->routeReg===null || $this->routeReg->readValue('rteKind')!='htpage' ){
			return null;
		}
		if( trim($signRte, '/')===strval($this->routeReg->readValue('rtePath')) ){
			return null;
		}
		$askedUr = strval($this->httpRqst->getURLVal('reqstURI'));
		return $signRte.( ($askedUr!='' && $askedUr!='/') ? '?back='.rawurlencode($askedUr) : '' );
	}

	/**
	 * Gives the address of the client, as seen through the trusted reverse
	 * proxies.
	 *
	 * @return String The address, empty when the request tells none.
	 */
	protected function clientAdr(): String {
		$clntReg = $this->httpRqst->getClient();
		return ($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '';
	}

	/**
	 * Tells what the request asked for, for the log: the action, as
	 * "app/action", or the path of the declared route.
	 *
	 * @return String The action or the path.
	 */
	protected function routeGoal(): String {
		if( $this->routeReg===null ){
			return '/';
		}
		$appName = strval($this->routeReg->readValue('app'));
		return ($appName!='') ? $appName.'/'.strval($this->routeReg->readValue('action')) : '/'.strval($this->routeReg->readValue('rtePath'));
	}

	/**
	 * Logs, as a warning on the 'access' channel, a user refused for its
	 * role: who, with which role, from where, what was asked for and the
	 * role it needed.
	 *
	 * @param String  $loggedUser The identified username, empty for nobody.
	 * @param ?String $loggedRole Its role, null for none.
	 * @param String  $neededRole The role asked for.
	 * @param String  $clientAddr Address of the client, empty when unknown.
	 * @param String  $askedRoute What was asked for: an action or a path.
	 */
	public static function logDenial( String $loggedUser, ?String $loggedRole, String $neededRole, String $clientAddr, String $askedRoute ): Void {
		$fromTxt = ($clientAddr!='') ? ' from '.$clientAddr : '';
		Logger::getLogger('access')->warning( 'Access refused for user '.json_encode($loggedUser).' ('.($loggedRole ?? 'no role').')'.$fromTxt.' to '.$askedRoute.': role '.$neededRole.' needed' );
	}

	/**
	 * Logs an access grant/denial on the 'access' channel.
	 *
	 * The address of the client is told with it, when the request has one.
	 * The access of a privileged user (any role above the first of
	 * ROLELIST) is a notice of its own, telling the role and what was asked for.
	 *
	 * @param Bool   $wasGranted Whether access was granted.
	 * @param String $loggedUser The identified username, if any.
	 * @param String $denyReason The denial reason, if access was not granted.
	 * @param String $loggedRole The role of the identified user, if any.
	 */
	protected function logAttmpt( Bool $wasGranted, String $loggedUser, String $denyReason, String $loggedRole='' ): Void {
		$logInst = Logger::getLogger('access');
		$usrAddr = $this->clientAdr();
		$fromTxt = ($usrAddr!='') ? ' from '.$usrAddr : '';
		if( $wasGranted && in_array($loggedRole, array_slice(self::ROLELIST, 1), true) ){
			$logInst->notice( 'Privileged access granted for user '.json_encode($loggedUser).' ('.$loggedRole.')'.$fromTxt.' to '.$this->routeGoal() );
		}elseif( $wasGranted ){
			$logInst->info( 'Access granted for user "'.$loggedUser.'"'.$fromTxt );
		}else{
			$logInst->warning( 'Access denied'.$fromTxt.': '.$denyReason );
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
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$axsCnfg = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', strtoupper($confRowKey)) : null;
		return is_array($axsCnfg) ? $axsCnfg : Array();
	}
}
