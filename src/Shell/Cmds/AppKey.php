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
 *   php nyuRun appkeys makeKeys portal --routeSet=contacts/*,/members/save --publFile=conf/keys/portal.pub
 *   php nyuRun appkeys fullList                        lists the applications and their routes
 *   php nyuRun appkeys setRoute portal --routeSet=…  replaces the routes of an application
 *   php nyuRun appkeys revokeIt portal                 removes the key of an application
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
	public function renderSynopsis(): String {
		return 'Manages the keys of the applications calling for their users: making, the list, the routes, the revocation';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function listSubCmndDct(): Array {
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
		$applName = $this->needsShellWord('the name of the application');
		if( !preg_match('/^[a-z][a-z0-9]{1,15}$/D', $applName) ){
			$this->stopWithReason('Invalid name: a lower-case word of 2 to 16 letters and digits', self::EXIT_HELP);
		}
		$routeLst = $this->parseRouteList();
		$roleTops = strval($this->optionsObj->getOptionValue('roleTops', Checker::ROLE_LIST[0]));
		if( !in_array($roleTops, Checker::ROLE_LIST, true) ){
			$this->stopWithReason('Invalid role: one of '.implode(', ', Checker::ROLE_LIST), self::EXIT_HELP);
		}
		$publPath = trim( strval($this->optionsObj->getOptionValue('publFile', '')) );
		if( $publPath=='' ){
			$this->stopWithReason('Missing option: --publFile, the public key of the tokens of the application', self::EXIT_HELP);
		}
		$fullPath = preg_match('#^([A-Za-z]:|[/\\\\])#', $publPath) ? $publPath : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $publPath);
		$keyInstn = (is_file($fullPath) && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public(strval(file_get_contents($fullPath))) : false;
		$keyArray = ($keyInstn!==false) ? openssl_pkey_get_details($keyInstn) : false;
		if( !is_array($keyArray) || ($keyArray['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($keyArray['bits'] ?? 0)<JWT::RSAS_BITS ){
			$this->stopWithReason('No key made: '.json_encode($publPath).' is not the public RSA key of '.JWT::RSAS_BITS.' bits at least of the application', self::EXIT_HELP);
		}
		$treeInst = $this->readAccessConf();
		$blockNme = self::KEYS_BLCK;
		if( !isset($treeInst->$blockNme) || !is_object($treeInst->$blockNme) ){
			// The audiences naming this project are left empty : "setting setValue accsConf appsKeys.audience" sets them
			$treeInst->$blockNme = (object)Array('headName'=>self::KEYS_HEAD, 'appsList'=>new \stdClass(), 'audience'=>Array());
		}
		if( !isset($treeInst->$blockNme->appsList) || !is_object($treeInst->$blockNme->appsList) ){
			$treeInst->$blockNme->appsList = new \stdClass();
		}
		if( isset($treeInst->$blockNme->appsList->$applName) ){
			$this->stopWithReason('No key made: the application '.json_encode($applName).' already has one (appkeys revokeIt it first)', self::EXIT_DENY);
		}
		$keyStrng = rtrim( strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=' );
		$treeInst->$blockNme->appsList->$applName = (object)Array(
			'keyDigst'=>hash('sha256', $keyStrng), 'routeSet'=>$routeLst, 'publFile'=>$publPath, 'roleTops'=>$roleTops, 'madeDate'=>date('Y-m-d H:i:s')
		);
		$this->saveAccessConf($treeInst);
		$this->logShellChange( 'App key: made for application '.json_encode($applName).' ('.$roleTops.'), routes '.json_encode($routeLst, JSON_UNESCAPED_SLASHES) );
		$this->printerObj->writeShellPair('key made', $applName);
		$this->printerObj->writeShellOnce('Key of '.$applName, $keyStrng);
		return self::EXIT_DONE;
	}

	/**
	 * Lists the applications of the 'appsKeys' block: name, routes, public
	 * key, highest role and the date the key was made. A hash is never shown.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$treeInst = $this->readAccessConf();
		$blockNme = self::KEYS_BLCK;
		$rowsList = Array();
		foreach( get_object_vars($treeInst->$blockNme->appsList ?? new \stdClass()) as $oneKey => $oneSet ){
			$oneSet = (array)$oneSet;
			$rowsList[] = Array(strval($oneKey), implode(', ', array_map('strval', (array)($oneSet['routeSet'] ?? Array()))), strval($oneSet['publFile'] ?? ''), strval($oneSet['roleTops'] ?? Checker::ROLE_LIST[0]), strval($oneSet['madeDate'] ?? ''));
		}
		$this->printerObj->writeShellRows( Array('application', 'routes', 'public key', 'highest role', 'made'), $rowsList );
		return self::EXIT_DONE;
	}

	/**
	 * Replaces the routes of an application with those --routeSet gives.
	 *
	 * @return Int The exit code.
	 */
	public function invokeSetRoute(): Int {
		$applName = $this->needsShellWord('the name of the application');
		$routeLst = $this->parseRouteList();
		$treeInst = $this->readAccessConf();
		$blockNme = self::KEYS_BLCK;
		if( !isset($treeInst->$blockNme->appsList->$applName) || !is_object($treeInst->$blockNme->appsList->$applName) ){
			$this->stopWithReason('No route changed: no application named '.json_encode($applName), self::EXIT_DENY);
		}
		$treeInst->$blockNme->appsList->$applName->routeSet = $routeLst;
		$this->saveAccessConf($treeInst);
		$this->logShellChange( 'App key: routes of application '.json_encode($applName).' set to '.json_encode($routeLst, JSON_UNESCAPED_SLASHES) );
		$this->printerObj->writeShellPair('routes set', $applName);
		return self::EXIT_DONE;
	}

	/**
	 * Removes the key of an application: it is refused from the next request.
	 *
	 * @return Int The exit code.
	 */
	public function invokeRevokeIt(): Int {
		$applName = $this->needsShellWord('the name of the application');
		$treeInst = $this->readAccessConf();
		$blockNme = self::KEYS_BLCK;
		if( !isset($treeInst->$blockNme->appsList->$applName) ){
			$this->stopWithReason('Nothing revoked: no application named '.json_encode($applName), self::EXIT_DENY);
		}
		unset($treeInst->$blockNme->appsList->$applName);
		$this->saveAccessConf($treeInst);
		$this->logShellChange( 'App key: key of application '.json_encode($applName).' revoked' );
		$this->printerObj->writeShellPair('key revoked', $applName);
		return self::EXIT_DONE;
	}

	/**
	 * Reads conf/access.json as it is written.
	 *
	 * @return Object Its blocks.
	 */
	protected function readAccessConf(): Object {
		try{
			return (new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' ))->readRawJsonCfg( TOP_DIR.DS.'conf'.DS.'access.json' );
		}catch( \RuntimeException $e ){
			$this->stopWithReason('conf/access.json cannot be read: it is missing or is not a JSON object (install makeConf makes it)');
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
			$this->stopWithReason('conf/access.json cannot be written: '.$e->getMessage());
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
		$routeLst = array_values( array_filter(array_map('trim', explode(',', strval($this->optionsObj->getOptionValue('routeSet', '')))), 'strlen') );
		if( empty($routeLst) ){
			$this->stopWithReason('Missing option: --routeSet, the routes the application may reach, separated by commas', self::EXIT_HELP);
		}
		foreach( $routeLst as $oneVal ){
			if( !preg_match('#^(/[^\s]+|[A-Za-z0-9]+/(\*|[A-Za-z0-9_]+))$#D', $oneVal) ){
				$this->stopWithReason('Invalid route '.json_encode($oneVal).': "app/action", "app/*" or a declared path with a leading slash', self::EXIT_HELP);
			}
		}
		return $routeLst;
	}
}
