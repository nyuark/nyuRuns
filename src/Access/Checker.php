<?php

namespace nyu\Access;

use nyu\Tools\Request;
use nyu\Tools\Registry;

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
			$logInst = $GLOBALS['nyuLogger'] ?? null;
			if( $logInst!==null ){
				$logInst->error( 'Access plug-in "'.$shortNamed.'" cannot be used: '.$whyText );
			}
			return null;
		}
		return $fullCls;
	}

	/**
	 * Verifies access for the current request against the configured
	 * strategy. Fails closed on misconfiguration (never falls back to
	 * a different strategy). When no check applies (access not required,
	 * or a public route), the request goes through as not logged.
	 *
	 * @return Registry Registry with 'logged', 'user', 'role', 'ident', 'statCode' and 'statMssg' keys.
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

		$sessObj = new $sessCls( $this->resolvCnf($this->axsSetup['sesscf'] ?? ''), $authObj );
		$idntArr = $sessObj->verifyReq( $this->httpRqst );

		if( $idntArr===false ){
			$chlgMap = Array('hbasic'=>'Basic', 'jtoken'=>'Bearer');
			$wwwAuth = $chlgMap[$sessNme] ?? null;
			$this->logAttmpt( false, '', $sessObj->lastError() );
			return new Registry( Array('logged'=>false, 'user'=>'', 'statCode'=>401, 'statMssg'=>$sessObj->lastError(), 'wwwAuth'=>$wwwAuth) );
		}
		// A credential store that tracks presence is told the user was seen
		if( $authObj!==null && method_exists($authObj, 'keepAlive') && ($idntArr['user'] ?? '')!='' ){
			$authObj->keepAlive( strval($idntArr['user']) );
		}
		$this->logAttmpt( true, $idntArr['user'] ?? '', '' );
		// An identity without a known role is a plain member
		$usrRole = $idntArr['role'] ?? '';
		if( !in_array($usrRole, self::ROLELIST, true) ){
			$usrRole = self::ROLELIST[0];
		}
		return new Registry( Array('logged'=>true, 'user'=>$idntArr['user'] ?? '', 'role'=>$usrRole, 'ident'=>$idntArr, 'statCode'=>200, 'statMssg'=>'') );
	}

	/**
	 * Tells whether the resolved route is served without any access check:
	 * an OPTIONS request the route answers by itself, or an action listed
	 * in the 'openrt' key of the 'auths' block, as "app/action" or "app/*"
	 * (case-insensitive).
	 *
	 * @return Bool Whether the route is open to everyone.
	 */
	protected function openRoute(): Bool {
		if( $this->routeReg===null ){
			return false;
		}
		$appName = strtolower( strval($this->routeReg->readValue('app')) );
		$actName = strtolower( strval($this->routeReg->readValue('action')) );
		if( $appName=='' ){
			// Without an application, only the route's own OPTIONS answer is open
			return $this->routeReg->readValue('statCode')==204;
		}
		$pubList = $this->axsSetup['openrt'] ?? Array();
		foreach( (array)$pubList as $pubRte ){
			$pubRte = strtolower( trim(strval($pubRte), '/') );
			if( $pubRte==$appName.'/'.$actName || $pubRte==$appName.'/*' ){
				return true;
			}
		}
		return false;
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
