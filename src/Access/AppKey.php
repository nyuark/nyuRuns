<?php

namespace nyu\Access;

use nyu\Tools\Request;
use nyu\Tools\Registry;
use nyu\Access\Sess\JWT;

/**
 * Checks a call an application makes on behalf of one of its users: the
 * key of the application, sent in its own header, and the user's token,
 * signed by that application and checked with its public key. The keys
 * are read from a block of the ACCESS config (the one the 'appkey' key of
 * the 'auths' block names), stored as their SHA-256 hash, each with the
 * routes it may reach, the public key of its tokens and the highest role
 * its users are given.
 */
class AppKey{

	// The methods that read: any other one is a write, logged as a notice (see Checker::appsCalls)
	const READLIST = Array('GET', 'HEAD', 'OPTIONS');

	protected $headName;
	protected $appsList;
	protected $callAppl;
	protected $lastMssg;
	protected $wrongCrd = false;

	/**
	 * Builds the check from its config block.
	 *
	 * @param Array $confValues Config block with the 'header' the key is sent in (default 'X-App-Key') and the 'applst'
	 *                          of the applications, by name, each with its 'keyhsh' (SHA-256 of its key, hexadecimal),
	 *                          its 'routes' (written as those of 'openrt'), its 'pubkey' (path of the PEM file of the
	 *                          public key of its tokens, from the project root) and its 'maxrol' (default 'member').
	 */
	function __construct( Array $confValues ){
		$this->headName = strval($confValues['header'] ?? 'X-App-Key');
		$this->appsList = is_array($confValues['applst'] ?? null) ? $confValues['applst'] : Array();
		$this->callAppl = null;
		$this->lastMssg = '';
	}

	/**
	 * Verifies the call of an application: its key, then the token of the
	 * user it calls for. The user is taken from the token as it was issued,
	 * not looked up in a store of this project; its role is never above
	 * the 'maxrol' of the application.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool|null The identity of the user, with the application under 'fromApp'; false when the key or
	 *                         the token is refused; null when the request carries no application key.
	 */
	public function verifyApp( Request $requestObj ): Array|Bool|null {
		$this->wrongCrd = false;
		$this->callAppl = null;
		$givnKey = strval($requestObj->getHeader($this->headName) ?? '');
		if( $givnKey=='' ){
			return null;
		}
		$gvnHash = hash('sha256', $givnKey);
		$appName = null;
		foreach( $this->appsList as $appKey => $appSet ){
			$keyHash = is_array($appSet) ? strtolower(strval($appSet['keyhsh'] ?? '')) : '';
			// An entry without a hash is the key of nobody
			if( preg_match('/^[0-9a-f]{64}$/D', $keyHash) && hash_equals($keyHash, $gvnHash) ){
				$appName = strval($appKey);
				break;
			}
		}
		if( $appName===null ){
			$this->lastMssg = 'Invalid app key';
			$this->wrongCrd = true;
			return false;
		}
		$appVals = $this->appsList[$appName];
		$maxRole = strval($appVals['maxrol'] ?? Checker::ROLELIST[0]);
		if( !in_array($maxRole, Checker::ROLELIST, true) ){
			$this->lastMssg = 'Access misconfigured';
			return false;
		}
		// The token is checked with the key of the application alone : it issues none here
		$jwtObjc = new JWT( Array('algrtm'=>'RS256', 'pubkey'=>$appVals['pubkey'] ?? '') );
		$idntArr = $jwtObjc->verifyReq( $requestObj );
		if( $idntArr===false ){
			$this->lastMssg = $jwtObjc->lastError();
			$this->wrongCrd = $jwtObjc->wrongCred();
			return false;
		}
		$usrRole = strval($idntArr['role'] ?? '');
		if( !in_array($usrRole, Checker::ROLELIST, true) ){
			$usrRole = Checker::ROLELIST[0];
		}
		$idntArr['role'] = Checker::roleMeets($maxRole, $usrRole) ? $usrRole : $maxRole;
		$idntArr['fromApp'] = $appName;
		$this->callAppl = $appName;
		return $idntArr;
	}

	/**
	 * Tells whether the application verified last may reach the route: its
	 * 'routes' are written as those of 'openrt', an action as "app/action"
	 * or "app/*" (case-insensitive), a declared route as its path with a
	 * leading slash, to the letter. A request naming no action is reached
	 * through "app/*" only.
	 *
	 * @param Registry $routingReg The resolved route.
	 * @return Bool True when the route is one of the application's.
	 */
	public function allowsRte( Registry $routingReg ): Bool {
		if( $this->callAppl===null ){
			return false;
		}
		$appName = strtolower( str_replace(' ', '', strval($routingReg->readValue('app'))) );
		$actName = strtolower( strval($routingReg->readValue('action')) );
		$rtePath = strval($routingReg->readValue('rtePath'));
		foreach( (array)($this->appsList[$this->callAppl]['routes'] ?? Array()) as $rteVal ){
			$rteVal = strval($rteVal);
			if( substr($rteVal, 0, 1)=='/' ){
				if( $rtePath!='' && trim($rteVal, '/')===$rtePath ){
					return true;
				}
				continue;
			}
			$rteVal = strtolower( trim($rteVal, '/') );
			if( $appName!='' && ( $rteVal==$appName.'/*' || ($actName!='' && $rteVal==$appName.'/'.$actName) ) ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Tells whether the last refusal was a wrong key or a forged token, as
	 * opposed to a token that ended or a config that cannot be used: only
	 * those count against the address (see Access\Limiter).
	 *
	 * @return Bool True when a credential was presented and refused.
	 */
	public function wrongCred(): Bool {
		return $this->wrongCrd;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	public function lastError(): String {
		return $this->lastMssg;
	}
}
