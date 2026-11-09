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
	const KEYS_BLCK = 'apkeys';
	const KEYS_HEAD = 'X-App-Key';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Manages the keys of the applications calling for their users: making, the list, the routes, the revocation';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
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
	 * are checked with (--pubky, an RSA key of JWT::RSAS_BITS bits at least,
	 * from the project root) and the highest role of its users (--urole,
	 * member when not told). A name already listed is refused. The key is
	 * shown once; its hash is written in the 'apkeys' block, created when
	 * conf/access.json has none.
	 *
	 * @return Int The exit code.
	 */
	public function callMakes(): Int {
		$appName = $this->needsShellWord('the name of the application');
		if( !preg_match('/^[a-z][a-z0-9]{1,15}$/D', $appName) ){
			$this->terminateShell('Invalid name: a lower-case word of 2 to 16 letters and digits', self::EXIT_HELP);
		}
		$rteList = $this->parseRouteList();
		$maxRole = strval($this->optnObject->retrieveOption('urole', Checker::ROLE_LIST[0]));
		if( !in_array($maxRole, Checker::ROLE_LIST, true) ){
			$this->terminateShell('Invalid role: one of '.implode(', ', Checker::ROLE_LIST), self::EXIT_HELP);
		}
		$publPth = trim( strval($this->optnObject->retrieveOption('pubky', '')) );
		if( $publPth=='' ){
			$this->terminateShell('Missing option: --pubky, the public key of the tokens of the application', self::EXIT_HELP);
		}
		$fullPth = preg_match('#^([A-Za-z]:|[/\\\\])#', $publPth) ? $publPth : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $publPth);
		$keyInst = (is_file($fullPth) && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public(strval(file_get_contents($fullPth))) : false;
		$keyInfo = ($keyInst!==false) ? openssl_pkey_get_details($keyInst) : false;
		if( !is_array($keyInfo) || ($keyInfo['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($keyInfo['bits'] ?? 0)<JWT::RSAS_BITS ){
			$this->terminateShell('No key made: '.json_encode($publPth).' is not the public RSA key of '.JWT::RSAS_BITS.' bits at least of the application', self::EXIT_HELP);
		}
		$treeObj = $this->readAccessConf();
		$blckNme = self::KEYS_BLCK;
		if( !isset($treeObj->$blckNme) || !is_object($treeObj->$blckNme) ){
			// The audiences naming this project are left empty : "config write access apkeys.audnce" sets them
			$treeObj->$blckNme = (object)Array('header'=>self::KEYS_HEAD, 'applst'=>new \stdClass(), 'audnce'=>Array());
		}
		if( !isset($treeObj->$blckNme->applst) || !is_object($treeObj->$blckNme->applst) ){
			$treeObj->$blckNme->applst = new \stdClass();
		}
		if( isset($treeObj->$blckNme->applst->$appName) ){
			$this->terminateShell('No key made: the application '.json_encode($appName).' already has one (appkey revok it first)', self::EXIT_DENY);
		}
		$keyText = rtrim( strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=' );
		$treeObj->$blckNme->applst->$appName = (object)Array(
			'keyhsh'=>hash('sha256', $keyText), 'routes'=>$rteList, 'pubkey'=>$publPth, 'maxrol'=>$maxRole, 'madedt'=>date('Y-m-d H:i:s')
		);
		$this->saveAccessConf($treeObj);
		$this->traceShellDone( 'App key: made for application '.json_encode($appName).' ('.$maxRole.'), routes '.json_encode($rteList, JSON_UNESCAPED_SLASHES) );
		$this->printerObj->printShellPair('key made', $appName);
		$this->printerObj->printShellOnce('Key of '.$appName, $keyText);
		return self::EXIT_DONE;
	}

	/**
	 * Lists the applications of the 'apkeys' block: name, routes, public
	 * key, highest role and the date the key was made. A hash is never shown.
	 *
	 * @return Int The exit code.
	 */
	public function callLists(): Int {
		$treeObj = $this->readAccessConf();
		$blckNme = self::KEYS_BLCK;
		$rowList = Array();
		foreach( get_object_vars($treeObj->$blckNme->applst ?? new \stdClass()) as $appKey => $appSet ){
			$appSet = (array)$appSet;
			$rowList[] = Array(strval($appKey), implode(', ', array_map('strval', (array)($appSet['routes'] ?? Array()))), strval($appSet['pubkey'] ?? ''), strval($appSet['maxrol'] ?? Checker::ROLE_LIST[0]), strval($appSet['madedt'] ?? ''));
		}
		$this->printerObj->printShellRows( Array('application', 'routes', 'public key', 'highest role', 'made'), $rowList );
		return self::EXIT_DONE;
	}

	/**
	 * Replaces the routes of an application with those --routs gives.
	 *
	 * @return Int The exit code.
	 */
	public function callRoute(): Int {
		$appName = $this->needsShellWord('the name of the application');
		$rteList = $this->parseRouteList();
		$treeObj = $this->readAccessConf();
		$blckNme = self::KEYS_BLCK;
		if( !isset($treeObj->$blckNme->applst->$appName) || !is_object($treeObj->$blckNme->applst->$appName) ){
			$this->terminateShell('No route changed: no application named '.json_encode($appName), self::EXIT_DENY);
		}
		$treeObj->$blckNme->applst->$appName->routes = $rteList;
		$this->saveAccessConf($treeObj);
		$this->traceShellDone( 'App key: routes of application '.json_encode($appName).' set to '.json_encode($rteList, JSON_UNESCAPED_SLASHES) );
		$this->printerObj->printShellPair('routes set', $appName);
		return self::EXIT_DONE;
	}

	/**
	 * Removes the key of an application: it is refused from the next request.
	 *
	 * @return Int The exit code.
	 */
	public function callRevok(): Int {
		$appName = $this->needsShellWord('the name of the application');
		$treeObj = $this->readAccessConf();
		$blckNme = self::KEYS_BLCK;
		if( !isset($treeObj->$blckNme->applst->$appName) ){
			$this->terminateShell('Nothing revoked: no application named '.json_encode($appName), self::EXIT_DENY);
		}
		unset($treeObj->$blckNme->applst->$appName);
		$this->saveAccessConf($treeObj);
		$this->traceShellDone( 'App key: key of application '.json_encode($appName).' revoked' );
		$this->printerObj->printShellPair('key revoked', $appName);
		return self::EXIT_DONE;
	}

	/**
	 * Reads conf/access.json as it is written.
	 *
	 * @return Object Its blocks.
	 */
	protected function readAccessConf(): Object {
		try{
			return (new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' ))->importJsonsObj( TOP_DIR.DS.'conf'.DS.'access.json' );
		}catch( \RuntimeException $e ){
			$this->terminateShell('conf/access.json cannot be read: it is missing or is not a JSON object (deploy files makes it)');
		}
	}

	/**
	 * Writes conf/access.json, in the layout it has.
	 *
	 * @param Object $appsAccsTree Its blocks.
	 */
	protected function saveAccessConf( Object $appsAccsTree ): Void {
		try{
			(new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' ))->saveJsonConfig( TOP_DIR.DS.'conf'.DS.'access.json', $appsAccsTree );
		}catch( \RuntimeException $e ){
			$this->terminateShell('conf/access.json cannot be written: '.$e->getMessage());
		}
	}

	/**
	 * Reads the routes --routs gives, separated by commas: each one an
	 * action, as "app/action" or "app/*", or a declared route, as its final
	 * path with a leading slash (a Run's route with its mount: /auth/login).
	 *
	 * @return Array The routes.
	 */
	protected function parseRouteList(): Array {
		$rteList = array_values( array_filter(array_map('trim', explode(',', strval($this->optnObject->retrieveOption('routs', '')))), 'strlen') );
		if( empty($rteList) ){
			$this->terminateShell('Missing option: --routs, the routes the application may reach, separated by commas', self::EXIT_HELP);
		}
		foreach( $rteList as $rteVal ){
			if( !preg_match('#^(/[^\s]+|[A-Za-z0-9]+/(\*|[A-Za-z0-9_]+))$#D', $rteVal) ){
				$this->terminateShell('Invalid route '.json_encode($rteVal).': "app/action", "app/*" or a declared path with a leading slash', self::EXIT_HELP);
			}
		}
		return $rteList;
	}
}
