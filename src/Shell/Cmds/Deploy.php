<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Auth\Native;
use nyu\Data\DBCon;
use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Shell\Console;
use nyu\Shell\HealthChk;
use nyu\Shell\ShellStop;
use nyu\Shell\UserFile;

/**
 * Installs the project on a server, and checks it:
 *
 *   php nyuRun deploy start            the whole sequence, asking what it needs with a default to take on Enter
 *   php nyuRun deploy start --noask    the same without a question, for a deployment script
 *   php nyuRun deploy servr            checks the server: PHP, extensions, folders
 *   php nyuRun deploy files            creates the local files from their examples
 *   php nyuRun deploy users            creates the users table and the basic users, or the users file
 *   php nyuRun deploy check            the health check
 *
 * Nothing that exists is replaced: a local file, a secret, a table or a
 * file that already holds users are left as they are.
 */
class Deploy extends Command{

	// The local files, and the example each is created from
	const DISTLIST = Array('.env'=>'.env.example', 'conf/access.json'=>'conf/access.json.dist', 'conf/database.json'=>'conf/database.json.dist');
	// The basic users, and the role of each
	const USERLIST = Array('member'=>'member', 'system'=>'system', 'managr'=>'manage');
	// The stores the users can be created in, and 'none' for a project without users of its own
	const STORLIST = Array('native', 'usrlst', 'none');

	/**
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Installs the project: server check, local files, secrets, users, health check';
	}

	/**
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		return Array(
			'start'=>Array('args'=>'[--store=<native|usrlst|none>] [--noask]', 'about'=>'the whole sequence: servr, files, questions, secrets, users, check'),
			'servr'=>Array('args'=>'', 'about'=>'checks the server: PHP version, extensions, writable folders'),
			'files'=>Array('args'=>'', 'about'=>'creates .env, conf/access.json and conf/database.json from their examples; none is replaced'),
			'users'=>Array('args'=>'[block] [--store=<native|usrlst>]', 'about'=>'creates the users table and the basic users (native), or the users file (usrlst)'),
			'check'=>Array('args'=>'', 'about'=>'health check: presets, access, secrets, databases, users, unsafe settings')
		);
	}

	/**
	 * Runs the whole installation. Each question proposes a default,
	 * taken on Enter; with --noask the defaults are taken without a word.
	 *
	 * @return Int EXITDONE when the health check finds no failure, EXITFLAW otherwise.
	 */
	public function callStart(): Int {
		$chkObjc = new HealthChk();
		$chkObjc->chkServer();
		$this->prntObjc->printRows( Array('check', 'state', 'found'), $chkObjc->rowsFound() );
		if( $chkObjc->hasFailed() ){
			$this->stopShell('The server cannot run the project: nothing was installed', self::EXITFLAW);
		}
		$this->makeFiles();
		$storNme = $this->askSetups();
		// The secrets that are still empty are made; those in use are left alone
		$cnfgCmd = new Config($this->optnObjc, $this->prntObjc, $this->inptStrm);
		foreach( Array('jtoken', 'apikey') as $kndNme ){
			try{
				$cnfgCmd->makeSecrt($kndNme, true);
			}catch( \RuntimeException $e ){
				$this->prntObjc->printLine( 'No '.($kndNme=='jtoken' ? 'secret of the tokens' : 'API key').' made: '.$e->getMessage() );
			}
		}
		Console::loadConfg();
		if( $storNme!='none' ){
			try{
				$this->makeUsers($storNme, '');
			}catch( ShellStop $e ){
				// The health check tells what is missing : the installation goes on to it
				$this->prntObjc->printFail( $e->getMessage() );
			}
		}
		$this->traceDone('Deploy: installation run, users in '.$storNme);
		return $this->showCheck();
	}

	/**
	 * Checks the server alone.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	public function callServr(): Int {
		$chkObjc = new HealthChk();
		$chkObjc->chkServer();
		$chkObjc->chkFoldrs();
		$this->prntObjc->printRows( Array('check', 'state', 'found'), $chkObjc->rowsFound() );
		return $chkObjc->hasFailed() ? self::EXITFLAW : self::EXITDONE;
	}

	/**
	 * Creates the local files from their examples.
	 *
	 * @return Int The exit code.
	 */
	public function callFiles(): Int {
		$this->makeFiles();
		return self::EXITDONE;
	}

	/**
	 * Creates the users: in the store --store names, or the one the login
	 * uses.
	 *
	 * @return Int The exit code.
	 */
	public function callUsers(): Int {
		$blkName = strval($this->optnObjc->shiftWord() ?? '');
		$storNme = strval( $this->optnObjc->getOption('store', strval($this->accessCnf('login')['authby'] ?? '')) );
		if( !in_array($storNme, Array('native', 'usrlst'), true) ){
			$this->stopShell('The login uses neither the native userbase nor the users file: name the store with --store=native or --store=usrlst', self::EXITUSGE);
		}
		$this->makeUsers($storNme, $blkName);
		return self::EXITDONE;
	}

	/**
	 * Runs the health check.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	public function callCheck(): Int {
		return $this->showCheck();
	}

	/**
	 * Runs the health check and writes its rows.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	protected function showCheck(): Int {
		$chkObjc = new HealthChk();
		$rowList = $chkObjc->runChecks();
		$this->prntObjc->printRows( Array('check', 'state', 'found'), $rowList );
		$statLst = array_count_values( array_column($rowList, 1) );
		$this->prntObjc->printPair('fail', intval($statLst['fail'] ?? 0));
		$this->prntObjc->printPair('warn', intval($statLst['warn'] ?? 0));
		return $chkObjc->hasFailed() ? self::EXITFLAW : self::EXITDONE;
	}

	/**
	 * Creates each local file that is missing from its example. The file
	 * of the secrets is made readable by its owner only.
	 */
	protected function makeFiles(): Void {
		$rowList = Array();
		foreach( self::DISTLIST as $fleNme => $dstNme ){
			$filePth = TOP_DIR.DS.str_replace('/', DS, $fleNme);
			$distPth = TOP_DIR.DS.str_replace('/', DS, $dstNme);
			if( is_file($filePth) ){
				$rowList[] = Array($fleNme, 'kept');
			}elseif( !is_file($distPth) ){
				$rowList[] = Array($fleNme, 'not created: its example '.$dstNme.' is missing');
			}elseif( @copy($distPth, $filePth) ){
				if( $fleNme=='.env' ){
					@chmod($filePth, 0600);
				}
				$rowList[] = Array($fleNme, 'created from '.$dstNme);
				$this->traceDone('Deploy: '.$fleNme.' created from '.$dstNme);
			}else{
				$rowList[] = Array($fleNme, 'not created: its folder cannot be written');
			}
		}
		$this->prntObjc->printRows( Array('file', 'state'), $rowList );
		Console::loadConfg();
	}

	/**
	 * Asks what the installation needs, each question with its default:
	 * the environment, the store of the users and, for the native
	 * userbase, where its database is. What is answered is written to the
	 * config files.
	 *
	 * @return String The store of the users: 'native', 'usrlst' or 'none'.
	 *
	 * @throws ShellStop When an answer is not one of those proposed.
	 */
	protected function askSetups(): String {
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' );
		$iniPath = TOP_DIR.DS.'conf'.DS.'system.ini';
		$axsPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$nowEnvr = strval($wrtrObj->getIniKey($iniPath, 'ENV_NAME') ?? '');
		if( $nowEnvr!='' ){
			$gvnEnvr = strtoupper( $this->askAnswer('Environment (DEV, TEST, PREV, PROD)', $nowEnvr) );
			if( !in_array($gvnEnvr, Array('DEV', 'TEST', 'PREV', 'PROD'), true) ){
				$this->stopShell('Not an environment: '.$gvnEnvr, self::EXITDENY);
			}
			if( $gvnEnvr!=$nowEnvr ){
				$wrtrObj->setIniKey($iniPath, 'ENV_NAME', $gvnEnvr);
			}
		}
		$lgnAuth = strval($this->accessCnf('login')['authby'] ?? '');
		$storNme = strval( $this->optnObjc->getOption('store', in_array($lgnAuth, Array('native', 'usrlst'), true) ? $lgnAuth : 'none') );
		$storNme = strtolower( $this->askAnswer('Store of the users ('.implode(', ', self::STORLIST).')', $storNme) );
		if( !in_array($storNme, self::STORLIST, true) ){
			$this->stopShell('Not a store of users: '.$storNme, self::EXITDENY);
		}
		// The login is pointed at the store chosen
		if( $storNme!='none' && $storNme!=$lgnAuth && is_file($axsPath) ){
			$treeObj = $wrtrObj->loadJsons($axsPath);
			if( !isset($treeObj->login) || !is_object($treeObj->login) ){
				$treeObj->login = new \stdClass();
			}
			$treeObj->login->authby = $storNme;
			$treeObj->login->authcf = $storNme;
			if( !isset($treeObj->$storNme) ){
				$treeObj->$storNme = ($storNme=='native') ? (object)Array('basnme'=>'', 'tblnme'=>'users') : (object)Array('usrfle'=>'conf/users.lst');
			}
			$wrtrObj->saveJsons($axsPath, $treeObj);
		}
		if( $storNme=='native' && !$this->optnObjc->hasOption('noask') ){
			$this->askDbases($wrtrObj);
		}
		Console::loadConfg();
		return $storNme;
	}

	/**
	 * Asks where the default database is, each value proposed as the
	 * config has it, and writes what changed. The password is typed
	 * hidden, kept in the file of the secrets, and only named in the
	 * config; Enter alone keeps the present one.
	 *
	 * @param ConfFile $writerObjc The writer of config files.
	 *
	 * @throws ShellStop When the type is not one of a database.
	 */
	protected function askDbases( ConfFile $writerObjc ): Void {
		$basPath = TOP_DIR.DS.'conf'.DS.'database.json';
		if( !is_file($basPath) ){
			return;
		}
		$treeObj = $writerObjc->loadJsons($basPath);
		$nodeObj = $treeObj;
		if( !property_exists($treeObj, 'type') ){
			foreach( get_object_vars($treeObj) as $blkVal ){
				if( is_object($blkVal) ){
					$nodeObj = $blkVal;
					break;
				}
			}
		}
		$baseTyp = strtolower( $this->askAnswer('Database type (mysql, pgsql, sqlte)', strval($nodeObj->type ?? 'mysql')) );
		if( !in_array($baseTyp, Array('mysql', 'pgsql', 'sqlte'), true) ){
			$this->stopShell('The native userbase works on mysql, pgsql or sqlte, not on: '.$baseTyp, self::EXITDENY);
		}
		$nodeObj->type = $baseTyp;
		if( $baseTyp=='sqlte' ){
			$nodeObj->base = $this->askAnswer('Database file', strval($nodeObj->base ?? ''));
		}else{
			$nodeObj->host = $this->askAnswer('Database host', strval($nodeObj->host ?? 'localhost'));
			$nodeObj->port = $this->askAnswer('Database port (empty for the usual one)', strval($nodeObj->port ?? ''));
			$nodeObj->base = $this->askAnswer('Database name', strval($nodeObj->base ?? ''));
			$nodeObj->user = $this->askAnswer('Database user', strval($nodeObj->user ?? ''));
			$gvnPass = $this->askSecret('Password of that user (Enter keeps the present one)');
			if( $gvnPass!=='' ){
				$nowPass = strval($nodeObj->pass ?? '');
				$envName = (strpos($nowPass, 'env:')===0 && strlen($nowPass)>4) ? substr($nowPass, 4) : 'NYU_DB_PASS';
				$writerObjc->setEnvKey(TOP_DIR.DS.'.env', $envName, $gvnPass);
				$nodeObj->pass = 'env:'.$envName;
			}
		}
		$writerObjc->saveJsons($basPath, $treeObj);
		$this->traceDone('Deploy: settings of the default database written');
	}

	/**
	 * Creates the basic users, one per role, each with a random password
	 * shown this once. In the native userbase, the users table is created
	 * first when it is missing. A store that already holds users is left
	 * as it is.
	 *
	 * @param String $storeNamed 'native' or 'usrlst'.
	 * @param String $blockNamed Block of conf/access.json holding the settings of the store; the one the login names, or the one named as the store, when empty.
	 *
	 * @throws ShellStop When the users cannot be created.
	 */
	protected function makeUsers( String $storeNamed, String $blockNamed ): Void {
		$lgnConf = $this->accessCnf('login');
		$blkName = ($blockNamed!='') ? $blockNamed : ( strval($lgnConf['authby'] ?? '')==$storeNamed ? strval($lgnConf['authcf'] ?? $storeNamed) : $storeNamed );
		$blkConf = $this->accessCnf($blkName);
		if( empty($blkConf) && ($storeNamed=='native' || $blockNamed!='') ){
			$this->stopShell('No user created: conf/access.json has no "'.$blkName.'" block', self::EXITDENY);
		}
		$passLst = Array();
		if( $storeNamed=='usrlst' ){
			$fileObj = new UserFile( $blkConf, new ConfFile(TOP_DIR.DS.'temp'.DS.'backup') );
			$usrList = $fileObj->listUsers();
			if( $usrList===false ){
				$this->stopShell('No user created: '.$fileObj->lastError(), self::EXITFAIL);
			}
			if( !empty($usrList) ){
				$this->prntObjc->printLine('The users file already holds users: nothing was changed.');
				return;
			}
			foreach( self::USERLIST as $usrNme => $usrRle ){
				$passLst[$usrNme] = bin2hex(random_bytes(10));
				if( !$fileObj->enrolUser($usrNme, $usrRle, $passLst[$usrNme]) ){
					$this->stopShell('User '.$usrNme.' cannot be written: '.$fileObj->lastError(), self::EXITFAIL);
				}
			}
		}else{
			try{
				$passLst = $this->tableUser($blkConf);
			}catch( ShellStop $e ){
				throw $e;
			}catch( \Throwable $e ){
				$this->stopShell('No user created: '.$e->getMessage(), self::EXITFAIL);
			}
			if( empty($passLst) ){
				return;
			}
		}
		$this->traceDone('Deploy: basic users created in '.$storeNamed.' ('.implode(', ', array_keys(self::USERLIST)).')');
		$this->prntObjc->printLine('Users created. Their passwords are shown this once:');
		foreach( self::USERLIST as $usrNme => $usrRle ){
			$this->prntObjc->printOnce('Password of '.$usrNme.' ('.$usrRle.')', $passLst[$usrNme]);
		}
	}

	/**
	 * Creates the users table of the native userbase when it is missing,
	 * from the users.<type>.sql file of conf/schema, and gives it the
	 * basic users.
	 *
	 * @param Array $confValues Config block of the native userbase.
	 * @return Array The passwords made, by user; empty when the table already holds users and was left as it is.
	 *
	 * @throws ShellStop When the table cannot be created, read or written.
	 * @throws \RuntimeException When the database is not configured or does not answer.
	 */
	protected function tableUser( Array $confValues ): Array {
		$baseNme = strval($confValues['basnme'] ?? '');
		$tblName = strval($confValues['tblnme'] ?? 'users');
		$baseTyp = strval(DBCon::baseSetup($baseNme)['type']);
		$sqlFile = TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.'.$baseTyp.'.sql';
		if( !preg_match('/^\w+$/D', $baseTyp) || !preg_match('/^\w+$/D', $tblName) || !is_file($sqlFile) ){
			$this->stopShell('No user created: there is no users schema for a database of type "'.$baseTyp.'"', self::EXITDENY);
		}
		$dbHandl = DBCon::linkNamed($baseNme)->getConnex();
		if( $dbHandl===null ){
			$this->stopShell('No user created: the native userbase needs an SQL database', self::EXITDENY);
		}
		$qtdTble = $dbHandl->quoteName($tblName);
		if( $dbHandl->tableInfo($tblName)===false ){
			// The schema names its table 'users' : it is given the configured name
			$makeSql = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$qtdTble, strval(file_get_contents($sqlFile)), 1);
			if( !$dbHandl->sendQuery(strval($makeSql), true) ){
				$this->stopShell('No user created: the table cannot be created: '.strval($dbHandl->lastError()));
			}
			$this->prntObjc->printLine('Table '.$tblName.' created.');
			$this->traceDone('Deploy: users table '.$tblName.' created');
		}
		$rowList = $dbHandl->sendQuery('SELECT COUNT(*) AS '.$dbHandl->quoteName('usrCount').' FROM '.$qtdTble.';') ? $dbHandl->readQuery(true) : false;
		if( !is_array($rowList) ){
			$this->stopShell('No user created: the table cannot be read: '.strval($dbHandl->lastError()));
		}
		if( intval($rowList[0]['usrCount'] ?? 0)>0 ){
			$this->prntObjc->printLine('The table '.$tblName.' already holds users: nothing was changed.');
			return Array();
		}
		// The rules of the userbase are those of its block, except the length : the passwords made here are long enough for any
		$natAuth = new Native( Array('minlen'=>1) + $confValues );
		$passLst = Array();
		$addQury = 'INSERT INTO '.$qtdTble.' ('.$dbHandl->quoteName('userName').', '.$dbHandl->quoteName('passHash').', '.$dbHandl->quoteName('userRole').', '.$dbHandl->quoteName('userStat').") VALUES (?, '!', ?, 'active');";
		$dbHandl->opnTrnsct();
		foreach( self::USERLIST as $usrNme => $usrRle ){
			$passLst[$usrNme] = bin2hex(random_bytes(10));
			// The row is first written with a hash nothing matches, then given its password
			if( !$dbHandl->sendQuery($addQury, true, Array($usrNme, $usrRle)) || !$natAuth->setPasswd($usrNme, $passLst[$usrNme]) ){
				$dbHandl->remTrnsct();
				$this->stopShell('User '.$usrNme.' cannot be written; nothing was changed');
			}
		}
		$dbHandl->setTrnsct();
		return $passLst;
	}
}
