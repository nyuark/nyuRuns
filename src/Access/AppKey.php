<?php

namespace nyu\Access;

use nyu\Tools\Request;
use nyu\Tools\Registry;
use nyu\Access\Carr\JWT;

/**
 * Checks a call an application makes on behalf of one of its users: the
 * key of the application, sent in its own header, and the user's token,
 * signed by that application and checked with its public key. The keys
 * are read from a block of the ACCESS config (the one the 'appsConf' key of
 * the 'security' block names), stored as their SHA-256 hash, each with the
 * routes it may reach, the public key of its tokens and the highest role
 * its users are given.
 */
class AppKey{

	// The methods that read: any other one is a write, logged as a notice (see Checker::verifyAppCalls)
	const READ_LIST = Array('GET', 'HEAD', 'OPTIONS');

	protected $headerName;
	protected $appsByName;
	protected $audValList;
	protected $callingApp;
	protected $errMessage;
	protected $isWrongTry = false;

	/**
	 * Builds the check from its config block.
	 *
	 * @param Array $configValues Config block with the 'headName' the key is sent in (default 'X-App-Key') and the 'appsList'
	 *                          of the applications, by name, each with its 'keyDigst' (SHA-256 of its key, hexadecimal),
	 *                          its 'routeSet' (written as those of 'openList'), its 'publFile' (path of the PEM file of the
	 *                          public key of its tokens, from the project root), its 'roleTops' (default 'standard') and
	 *                          the 'issuerId' its tokens name (default: the name of the application); and the 'audience'
	 *                          of the block, the audiences naming this project (a text or a list): while it is
	 *                          empty, neither the issuer nor the audience of the tokens is checked.
	 */
	function __construct( Array $configValues ){
		$this->headerName = strval($configValues['headName'] ?? 'X-App-Key');
		$this->appsByName = is_array($configValues['appsList'] ?? null) ? $configValues['appsList'] : Array();
		$this->audValList = $configValues['audience'] ?? Array();
		$this->callingApp = null;
		$this->errMessage = '';
	}

	/**
	 * Verifies the call of an application: its key, then the token of the
	 * user it calls for. The user is taken from the token as it was issued,
	 * not looked up in a store of this project; its role is never above
	 * the 'roleTops' of the application.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return Array|Bool|null The identity of the user, with the application under 'appsName'; false when the key or
	 *                         the token is refused; null when the request carries no application key.
	 */
	public function verifyAppArray( Request $givenRequest ): Array|Bool|null {
		$this->isWrongTry = false;
		$this->callingApp = null;
		$sentClef = strval($givenRequest->retrieveHeader($this->headerName) ?? '');
		if( $sentClef=='' ){
			return null;
		}
		$givenHsh = hash('sha256', $sentClef);
		$applName = null;
		foreach( $this->appsByName as $appKey => $appSet ){
			$keyDigst = is_array($appSet) ? strtolower(strval($appSet['keyDigst'] ?? '')) : '';
			// An entry without a hash is the key of nobody
			if( preg_match('/^[0-9a-f]{64}$/D', $keyDigst) && hash_equals($keyDigst, $givenHsh) ){
				$applName = strval($appKey);
				break;
			}
		}
		if( $applName===null ){
			$this->errMessage = 'Invalid app key';
			$this->isWrongTry = true;
			return false;
		}
		$applDict = $this->appsByName[$applName];
		$roleTops = strval($applDict['roleTops'] ?? Checker::ROLE_LIST[0]);
		if( !in_array($roleTops, Checker::ROLE_LIST, true) ){
			$this->errMessage = 'Access misconfigured';
			return false;
		}
		// The token is checked with the key of the application alone : it issues none here
		$jwtConfg = Array('signAlgo'=>'RS256', 'publFile'=>$applDict['publFile'] ?? '');
		// Once the block names this project's audiences, the token names them and its application as issuer
		if( !empty($this->audValList) ){
			$jwtConfg['audience'] = $this->audValList;
			$jwtConfg['issuerId'] = (is_string($applDict['issuerId'] ?? null) && trim($applDict['issuerId'])!='') ? $applDict['issuerId'] : $applName;
		}
		$toknInst = new JWT( $jwtConfg );
		$idntList = $toknInst->verifyRqstList( $givenRequest );
		if( $idntList===false ){
			$this->errMessage = $toknInst->finalErrorText();
			$this->isWrongTry = $toknInst->hasWrongSecret();
			return false;
		}
		$userRole = strval($idntList['userRole'] ?? '');
		if( !in_array($userRole, Checker::ROLE_LIST, true) ){
			$userRole = Checker::ROLE_LIST[0];
		}
		$idntList['userRole'] = Checker::rleAccessMeets($roleTops, $userRole) ? $userRole : $roleTops;
		$idntList['appsName'] = $applName;
		$this->callingApp = $applName;
		return $idntList;
	}

	/**
	 * Tells whether the application verified last may reach the route: its
	 * 'routeSet' are written as those of 'openList', an action as "app/action"
	 * or "app/*" (case-insensitive), a declared route as its path with a
	 * leading slash, to the letter. A request naming no action is reached
	 * through "app/*" only.
	 *
	 * @param Registry $resolvedRegr The resolved route.
	 * @return Bool True when the route is one of the application's.
	 */
	public function allowsRouteFlg( Registry $resolvedRegr ): Bool {
		if( $this->callingApp===null ){
			return false;
		}
		$applName = strtolower( str_replace(' ', '', strval($resolvedRegr->readValueByKey('appToRun'))) );
		$actnName = strtolower( strval($resolvedRegr->readValueByKey('doAction')) );
		$routePth = strval($resolvedRegr->readValueByKey('routPath'));
		foreach( (array)($this->appsByName[$this->callingApp]['routeSet'] ?? Array()) as $rteVal ){
			$rteVal = strval($rteVal);
			if( substr($rteVal, 0, 1)=='/' ){
				if( $routePth!='' && trim($rteVal, '/')===$routePth ){
					return true;
				}
				continue;
			}
			$rteVal = strtolower( trim($rteVal, '/') );
			if( $applName!='' && ( $rteVal==$applName.'/*' || ($actnName!='' && $rteVal==$applName.'/'.$actnName) ) ){
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
	public function hasWrongSecret(): Bool {
		return $this->isWrongTry;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	public function finalErrorText(): String {
		return $this->errMessage;
	}
}
