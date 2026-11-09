<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Access\Checker;
use nyu\Access\Sess\JWT;

/**
 * Manages the keys of the applications calling this project for their
 * users (see Access\AppKey):
 *
 *   php nyuRun appkey makes portal --routs=contacts/*,/members/save --pubky=conf/keys/portal.pub
 *   php nyuRun appkey lists                    lists the applications and their routes
 *   php nyuRun appkey route portal --routs=…   replaces the routes of an application
 *   php nyuRun appkey revok portal             removes the key of an application
 *
 * The keys are those of the 'apkeys' block of conf/access.json: only the
 * hash of a key is stored, the key is shown once. A preset reads them
 * when its 'auths' block names that block under 'appkey'. Every change
 * is traced on the 'shell' log channel.
 */
class AppKey extends Command{

	// The block of conf/access.json holding the keys, and the header it is created with
	const KEYSBLCK = 'apkeys';
	const KEYSHEAD = 'X-App-Key';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Manages the keys of the applications calling for their users: making, the list, the routes, the revocation';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		return Array(
			'makes'=>Array('args'=>'<name> --routs=<routes> --pubky=<file> [--urole=member]', 'about'=>'makes the key of an application, with its routes, the public key of its tokens and the highest role of its users: shown once'),
			'lists'=>Array('args'=>'', 'about'=>'lists the applications, their routes, public key, highest role and date'),
			'route'=>Array('args'=>'<name> --routs=<routes>', 'about'=>'replaces the routes of an application'),
			'revok'=>Array('args'=>'<name>', 'about'=>'removes the key of an application: it is refused from the next request')
		);
	}

	/**
	 * Makes the key of an application: its routes (--routs, written as
	 * those of 'openrt', separated by commas), the public key its tokens
	 * are checked with (--pubky, an RSA key of JWT::RSAMINIM bits at least,
	 * from the project root) and the highest role of its users (--urole,
	 * member when not told). A name already listed is refused. The key is
	 * shown once; its hash is written in the 'apkeys' block, created when
	 * conf/access.json has none.
	 *
	 * @return Int The exit code.
	 */
	public function callMakes(): Int {
		$appName = $this->needsWord('the name of the application');
		if( !preg_match('/^[a-z][a-z0-9]{1,15}$/D', $appName) ){
			$this->stopShell('Invalid name: a lower-case word of 2 to 16 letters and digits', self::EXITUSGE);
		}
		$rteList = $this->routeList();
		$maxRole = strval($this->optnObjc->getOption('urole', Checker::ROLELIST[0]));
		if( !in_array($maxRole, Checker::ROLELIST, true) ){
			$this->stopShell('Invalid role: one of '.implode(', ', Checker::ROLELIST), self::EXITUSGE);
		}
		$pubPath = trim( strval($this->optnObjc->getOption('pubky', '')) );
		if( $pubPath=='' ){
			$this->stopShell('Missing option: --pubky, the public key of the tokens of the application', self::EXITUSGE);
		}
		$fullPth = preg_match('#^([A-Za-z]:|[/\\\\])#', $pubPath) ? $pubPath : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $pubPath);
		$keyObjc = (is_file($fullPth) && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public(strval(file_get_contents($fullPth))) : false;
		$keyInfo = ($keyObjc!==false) ? openssl_pkey_get_details($keyObjc) : false;
		if( !is_array($keyInfo) || ($keyInfo['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($keyInfo['bits'] ?? 0)<JWT::RSAMINIM ){
			$this->stopShell('No key made: '.json_encode($pubPath).' is not the public RSA key of '.JWT::RSAMINIM.' bits at least of the application', self::EXITUSGE);
		}
		$treeObj = $this->loadsKeys();
		$blkName = self::KEYSBLCK;
		if( !isset($treeObj->$blkName) || !is_object($treeObj->$blkName) ){
			// The audiences naming this project are left empty : "config write access apkeys.audnce" sets them
			$treeObj->$blkName = (object)Array('header'=>self::KEYSHEAD, 'applst'=>new \stdClass(), 'audnce'=>Array());
		}
		if( !isset($treeObj->$blkName->applst) || !is_object($treeObj->$blkName->applst) ){
			$treeObj->$blkName->applst = new \stdClass();
		}
		if( isset($treeObj->$blkName->applst->$appName) ){
			$this->stopShell('No key made: the application '.json_encode($appName).' already has one (appkey revok it first)', self::EXITDENY);
		}
		$keyText = rtrim( strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=' );
		$treeObj->$blkName->applst->$appName = (object)Array(
			'keyhsh'=>hash('sha256', $keyText), 'routes'=>$rteList, 'pubkey'=>$pubPath, 'maxrol'=>$maxRole, 'madedt'=>date('Y-m-d H:i:s')
		);
		$this->writeKeys($treeObj);
		$this->traceDone( 'App key: made for application '.json_encode($appName).' ('.$maxRole.'), routes '.json_encode($rteList, JSON_UNESCAPED_SLASHES) );
		$this->prntObjc->printPair('key made', $appName);
		$this->prntObjc->printOnce('Key of '.$appName, $keyText);
		return self::EXITDONE;
	}

	/**
	 * Lists the applications of the 'apkeys' block: name, routes, public
	 * key, highest role and the date the key was made. A hash is never shown.
	 *
	 * @return Int The exit code.
	 */
	public function callLists(): Int {
		$treeObj = $this->loadsKeys();
		$blkName = self::KEYSBLCK;
		$rowList = Array();
		foreach( get_object_vars($treeObj->$blkName->applst ?? new \stdClass()) as $appKey => $appSet ){
			$appSet = (array)$appSet;
			$rowList[] = Array(strval($appKey), implode(', ', array_map('strval', (array)($appSet['routes'] ?? Array()))), strval($appSet['pubkey'] ?? ''), strval($appSet['maxrol'] ?? Checker::ROLELIST[0]), strval($appSet['madedt'] ?? ''));
		}
		$this->prntObjc->printRows( Array('application', 'routes', 'public key', 'highest role', 'made'), $rowList );
		return self::EXITDONE;
	}

	/**
	 * Replaces the routes of an application with those --routs gives.
	 *
	 * @return Int The exit code.
	 */
	public function callRoute(): Int {
		$appName = $this->needsWord('the name of the application');
		$rteList = $this->routeList();
		$treeObj = $this->loadsKeys();
		$blkName = self::KEYSBLCK;
		if( !isset($treeObj->$blkName->applst->$appName) || !is_object($treeObj->$blkName->applst->$appName) ){
			$this->stopShell('No route changed: no application named '.json_encode($appName), self::EXITDENY);
		}
		$treeObj->$blkName->applst->$appName->routes = $rteList;
		$this->writeKeys($treeObj);
		$this->traceDone( 'App key: routes of application '.json_encode($appName).' set to '.json_encode($rteList, JSON_UNESCAPED_SLASHES) );
		$this->prntObjc->printPair('routes set', $appName);
		return self::EXITDONE;
	}

	/**
	 * Removes the key of an application: it is refused from the next request.
	 *
	 * @return Int The exit code.
	 */
	public function callRevok(): Int {
		$appName = $this->needsWord('the name of the application');
		$treeObj = $this->loadsKeys();
		$blkName = self::KEYSBLCK;
		if( !isset($treeObj->$blkName->applst->$appName) ){
			$this->stopShell('Nothing revoked: no application named '.json_encode($appName), self::EXITDENY);
		}
		unset($treeObj->$blkName->applst->$appName);
		$this->writeKeys($treeObj);
		$this->traceDone( 'App key: key of application '.json_encode($appName).' revoked' );
		$this->prntObjc->printPair('key revoked', $appName);
		return self::EXITDONE;
	}

	/**
	 * Reads conf/access.json as it is written.
	 *
	 * @return Object Its blocks.
	 */
	protected function loadsKeys(): Object {
		try{
			return (new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' ))->loadJsons( TOP_DIR.DS.'conf'.DS.'access.json' );
		}catch( \RuntimeException $e ){
			$this->stopShell('conf/access.json cannot be read: it is missing or is not a JSON object (deploy files makes it)');
		}
	}

	/**
	 * Writes conf/access.json, in the layout it has.
	 *
	 * @param Object $accessTree Its blocks.
	 */
	protected function writeKeys( Object $accessTree ): Void {
		try{
			(new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' ))->saveJsons( TOP_DIR.DS.'conf'.DS.'access.json', $accessTree );
		}catch( \RuntimeException $e ){
			$this->stopShell('conf/access.json cannot be written: '.$e->getMessage());
		}
	}

	/**
	 * Reads the routes --routs gives, separated by commas: each one an
	 * action, as "app/action" or "app/*", or a declared route, as its final
	 * path with a leading slash (a Run's route with its mount: /auth/login).
	 *
	 * @return Array The routes.
	 */
	protected function routeList(): Array {
		$rteList = array_values( array_filter(array_map('trim', explode(',', strval($this->optnObjc->getOption('routs', '')))), 'strlen') );
		if( empty($rteList) ){
			$this->stopShell('Missing option: --routs, the routes the application may reach, separated by commas', self::EXITUSGE);
		}
		foreach( $rteList as $rteVal ){
			if( !preg_match('#^(/[^\s]+|[A-Za-z0-9]+/(\*|[A-Za-z0-9_]+))$#D', $rteVal) ){
				$this->stopShell('Invalid route '.json_encode($rteVal).': "app/action", "app/*" or a declared path with a leading slash', self::EXITUSGE);
			}
		}
		return $rteList;
	}
}
