<?php

namespace nyu\Access;

use nyu\Tools\Request;
use nyu\Tools\Registry;
use nyu\Tools\Logger;

/**
 * Loads the session carrier ('sessby') and credential check ('authby')
 * the 'auths' block names for the current request, verifies access,
 * and reports the result.
 */
class Checker{

	// Roles an identity can carry, from the least to the most privileged.
	// Reserved for the coming access rules : nothing is enforced on them yet
	const ROLELIST = Array('membr', 'systm', 'admin');
	// Short names of the framework's strategies, as the config files write them, and their classes.
	// This is the only place that ties the two together. Any other short name is a plug-in (see plugClass)
	const AUTHLIST = Array('htpass'=>'PassList', 'native'=>'Native', 'regkey'=>'RegKey');
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
	 * until its hold ends (see Limiter).
	 *
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys, and 'retryAft' (seconds to wait) with a 429 status.
	 */
	function verifyAxs(): Registry {
		if( empty($this->axsSetup) || ($this->axsSetup['needed'] ?? true) === false || $this->openRoute() ){
			return new Registry( Array('logged'=>false, 'user'=>'', 'role'=>null, 'statCode'=>200, 'statMssg'=>'') );
		}

		$sessNme = strval($this->axsSetup['sessby'] ?? '');
		$authNme = $this->axsSetup['authby'] ?? null;

		$sessCls = self::sessClass($sessNme);
		if( $sessCls===null || !class_exists($sessCls) ){
			$this->logAttmpt( false, '', 'Access misconfigured: unknown session carrier "'.$sessNme.'"' );
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
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>401, 'statMssg'=>$sessObj->lastError(), 'wwwAuth'=>$wwwAuth) );
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
		$this->logAttmpt( true, strval($idntArr['user'] ?? ''), '', $usrRole );
		return new Registry( Array('logged'=>true, 'user'=>$idntArr['user'] ?? '', 'role'=>$usrRole, 'ident'=>$idntArr, 'statCode'=>200, 'statMssg'=>'') );
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
	protected function openRoute(): Bool {
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
			$appName = ($this->routeReg!==null) ? strval($this->routeReg->readValue('app')) : '';
			$goalTxt = ($appName!='') ? $appName.'/'.strval($this->routeReg->readValue('action')) : '/'.(($this->routeReg!==null) ? strval($this->routeReg->readValue('rtePath')) : '');
			$logInst->notice( 'Privileged access granted for user '.json_encode($loggedUser).' ('.$loggedRole.')'.$fromTxt.' to '.$goalTxt );
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
