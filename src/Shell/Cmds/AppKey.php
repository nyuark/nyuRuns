<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Access\Checker;
use nyu\Access\Carr\JWT;

/**
 * Manages the keys of the applications calling this project for their
 * users (see Access\AppKey):
 *
 *   php nyuRun appsKeys makeKeys portal --routeSet=contacts/*,/members/save --publFile=conf/keys/portal.pub
 *   php nyuRun appsKeys fullList                        lists the applications and their routes
 *   php nyuRun appsKeys setRoute portal --routeSet=…  replaces the routes of an application
 *   php nyuRun appsKeys revokeIt portal                 removes the key of an application
 *
 * The keys are those of the 'appsKeys' block of conf/access.json: only the
 * hash of a key is stored, the key is shown once. A preset reads them
 * when its 'security' block names that block under 'appsConf'. Every change
 * is traced on the 'shell' log channel.
 */
class AppKey extends Command{

	// The block of conf/access.json holding the keys, and the header it is created with
	const KEYS_BLCK = 'appsKeys';
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
			'makeKeys'=>Array('args'=>'<name> --routeSet=<routes> --publFile=<file> [--roleTops=standard]', 'about'=>'makes the key of an application, with its routes, the public key of its tokens and the highest role of its users: shown once'),
			'fullList'=>Array('args'=>'', 'about'=>'lists the applications, their routes, public key, highest role and date'),
			'setRoute'=>Array('args'=>'<name> --routeSet=<routes>', 'about'=>'replaces the routes of an application'),
			'revokeIt'=>Array('args'=>'<name>', 'about'=>'removes the key of an application: it is refused from the next request')
		);
	}

	/**
	 * Makes the key of an application: its routes (--routeSet, written as
	 * those of 'openList', separated by commas), the public key its tokens
	 * are checked with (--publFile, an RSA key of JWT::RSAS_BITS bits at least,
	 * from the project root) and the highest role of its users (--roleTops,
	 * standard when not told). A name already listed is refused. The key is
	 * shown once; its hash is written in the 'appsKeys' block, created when
	 * conf/access.json has none.
	 *
	 * @return Int The exit code.
	 */
	public function invokeMakeKeys(): Int {
		$appName = $this->needsShellWord('the name of the application');
		if( !preg_match('/^[a-z][a-z0-9]{1,15}$/D', $appName) ){
			$this->terminateShell('Invalid name: a lower-case word of 2 to 16 letters and digits', self::EXIT_HELP);
		}
		$rteList = $this->parseRouteList();
		$maxRole = strval($this->optionsObj->retrieveOption('roleTops', Checker::ROLE_LIST[0]));
		if( !in_array($maxRole, Checker::ROLE_LIST, true) ){
			$this->terminateShell('Invalid role: one of '.implode(', ', Checker::ROLE_LIST), self::EXIT_HELP);
		}
		$publPth = trim( strval($this->optionsObj->retrieveOption('publFile', '')) );
		if( $publPth=='' ){
			$this->terminateShell('Missing option: --publFile, the public key of the tokens of the application', self::EXIT_HELP);
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
			// The audiences naming this project are left empty : "settings setValue accsConf appsKeys.audience" sets them
			$treeObj->$blckNme = (object)Array('headName'=>self::KEYS_HEAD, 'appsList'=>new \stdClass(), 'audience'=>Array());
		}
		if( !isset($treeObj->$blckNme->appsList) || !is_object($treeObj->$blckNme->appsList) ){
			$treeObj->$blckNme->appsList = new \stdClass();
		}
		if( isset($treeObj->$blckNme->appsList->$appName) ){
			$this->terminateShell('No key made: the application '.json_encode($appName).' already has one (appsKeys revokeIt it first)', self::EXIT_DENY);
		}
		$keyText = rtrim( strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=' );
		$treeObj->$blckNme->appsList->$appName = (object)Array(
			'keyDigst'=>hash('sha256', $keyText), 'routeSet'=>$rteList, 'publFile'=>$publPth, 'roleTops'=>$maxRole, 'madeDate'=>date('Y-m-d H:i:s')
		);
		$this->saveAccessConf($treeObj);
		$this->traceShellDone( 'App key: made for application '.json_encode($appName).' ('.$maxRole.'), routes '.json_encode($rteList, JSON_UNESCAPED_SLASHES) );
		$this->printerObj->printShellPair('key made', $appName);
		$this->printerObj->printShellOnce('Key of '.$appName, $keyText);
		return self::EXIT_DONE;
	}

	/**
	 * Lists the applications of the 'appsKeys' block: name, routes, public
	 * key, highest role and the date the key was made. A hash is never shown.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$treeObj = $this->readAccessConf();
		$blckNme = self::KEYS_BLCK;
		$rowList = Array();
		foreach( get_object_vars($treeObj->$blckNme->appsList ?? new \stdClass()) as $appKey => $appSet ){
			$appSet = (array)$appSet;
			$rowList[] = Array(strval($appKey), implode(', ', array_map('strval', (array)($appSet['routeSet'] ?? Array()))), strval($appSet['publFile'] ?? ''), strval($appSet['roleTops'] ?? Checker::ROLE_LIST[0]), strval($appSet['madeDate'] ?? ''));
		}
		$this->printerObj->printShellRows( Array('application', 'routes', 'public key', 'highest role', 'made'), $rowList );
		return self::EXIT_DONE;
	}

	/**
	 * Replaces the routes of an application with those --routeSet gives.
	 *
	 * @return Int The exit code.
	 */
	public function invokeSetRoute(): Int {
		$appName = $this->needsShellWord('the name of the application');
		$rteList = $this->parseRouteList();
		$treeObj = $this->readAccessConf();
		$blckNme = self::KEYS_BLCK;
		if( !isset($treeObj->$blckNme->appsList->$appName) || !is_object($treeObj->$blckNme->appsList->$appName) ){
			$this->terminateShell('No route changed: no application named '.json_encode($appName), self::EXIT_DENY);
		}
		$treeObj->$blckNme->appsList->$appName->routeSet = $rteList;
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
	public function invokeRevokeIt(): Int {
		$appName = $this->needsShellWord('the name of the application');
		$treeObj = $this->readAccessConf();
		$blckNme = self::KEYS_BLCK;
		if( !isset($treeObj->$blckNme->appsList->$appName) ){
			$this->terminateShell('Nothing revoked: no application named '.json_encode($appName), self::EXIT_DENY);
		}
		unset($treeObj->$blckNme->appsList->$appName);
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
			return (new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' ))->importJsonsObj( TOP_DIR.DS.'conf'.DS.'access.json' );
		}catch( \RuntimeException $e ){
			$this->terminateShell('conf/access.json cannot be read: it is missing or is not a JSON object (installs makeConf makes it)');
		}
	}

	/**
	 * Writes conf/access.json, in the layout it has.
	 *
	 * @param Object $appsAccsTree Its blocks.
	 */
	protected function saveAccessConf( Object $appsAccsTree ): Void {
		try{
			(new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' ))->saveJsonConfig( TOP_DIR.DS.'conf'.DS.'access.json', $appsAccsTree );
		}catch( \RuntimeException $e ){
			$this->terminateShell('conf/access.json cannot be written: '.$e->getMessage());
		}
	}

	/**
	 * Reads the routes --routeSet gives, separated by commas: each one an
	 * action, as "app/action" or "app/*", or a declared route, as its final
	 * path with a leading slash (a Run's route with its mount: /auth/login).
	 *
	 * @return Array The routes.
	 */
	protected function parseRouteList(): Array {
		$rteList = array_values( array_filter(array_map('trim', explode(',', strval($this->optionsObj->retrieveOption('routeSet', '')))), 'strlen') );
		if( empty($rteList) ){
			$this->terminateShell('Missing option: --routeSet, the routes the application may reach, separated by commas', self::EXIT_HELP);
		}
		foreach( $rteList as $rteVal ){
			if( !preg_match('#^(/[^\s]+|[A-Za-z0-9]+/(\*|[A-Za-z0-9_]+))$#D', $rteVal) ){
				$this->terminateShell('Invalid route '.json_encode($rteVal).': "app/action", "app/*" or a declared path with a leading slash', self::EXIT_HELP);
			}
		}
		return $rteList;
	}
}
