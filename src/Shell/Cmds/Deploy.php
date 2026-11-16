<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Checker;
use nyu\Access\Totp;
use nyu\Access\Auth\Device as DeviceAuth;
use nyu\Access\Auth\Native;
use nyu\Data\DBCon;
use nyu\Data\DBHndlr\Handler as DbHandlr;
use nyu\Data\Mail\Drivers\PHMail;
use nyu\Data\Mail\MailFailure;
use nyu\Data\Mail\Mailer;
use nyu\Data\Mail\Message;
use nyu\Setup\ConfCache;
use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Shell\Console;
use nyu\Shell\HealthChk;
use nyu\Shell\ShellStop;
use nyu\Shell\UserFile;

/**
 * Installs the project on a server, and checks it:
 *
 *   php nyuRun install complete             the whole sequence, asking what it needs with a default to take on Enter
 *   php nyuRun install complete --noPrompt  the same without a question, for a deployment script
 *   php nyuRun install complete --projKind=dataServ  the profile of the project answered beforehand
 *   php nyuRun install complete --projKind=dataServ --publFile=<file> --issuerId=<name> --audience=<a,b> --noPrompt
 *                                      a resource server without a question: the public key of the authentication
 *                                      server, its issuer and the audiences naming this project (the last two for authServ too)
 *   php nyuRun install testHost             checks the server: PHP, extensions, folders
 *   php nyuRun install makeConf             creates the local files from their examples
 *   php nyuRun install userBase             creates the users table and the basic users, or the users file;
 *                                      the devices table too when conf/access.json has a device block
 *   php nyuRun install checkAll         the health check
 *   php nyuRun install moveUser native  moves the users to the other store (native or userFile), the login with them
 *   php nyuRun install testMail <address>   sends a test mail through the account of the access mails
 *
 * Nothing that exists is replaced: a local file, a secret, a table or a
 * file that already holds users are left as they are.
 */
class Deploy extends Command{

	// The local files, and the example each is created from
	const DIST_LIST = Array('.env'=>'.env.example', 'conf/access.json'=>'conf/access.json.dist', 'conf/database.json'=>'conf/database.json.dist', 'conf/mailer.json'=>'conf/mailer.json.dist');
	// The basic users, and the role of each
	// The basic accounts, one per role: the name proposed for each, asked when they are created
	const USER_LIST = Array('userDemo'=>'standard', 'powrDemo'=>'advanced', 'deptDemo'=>'delegate', 'bossDemo'=>'managers');
	// The stores the users can be created in, and 'none' for a project without users of its own
	const STOR_LIST = Array('nativeDb', 'userFile', 'none');
	// The profiles of a project: users, login and data in one, an authentication server, a resource server, a site with nothing to log in to
	const KIND_LIST = Array('allInOne', 'authServ', 'dataServ', 'openSite');
	// The block a store of users is given in conf/access.json when it has none
	const STOR_CONF = Array(
		'nativeDb'=>Array('linkName'=>'', 'useTable'=>'users', 'tfaCrypt'=>'env:NYU_TFA_KEY', 'mailAcnt'=>'', 'signupOn'=>false, 'codeMins'=>15, 'linkTmpl'=>''),
		'userFile'=>Array('listFile'=>'conf/users.lst', 'tfaCrypt'=>'env:NYU_TFA_KEY')
	);

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function renderSynopsis(): String {
		return 'Installs the project: server check, local files, secrets, users, health check';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function listSubCmndDct(): Array {
		return Array(
			'complete'=>Array('args'=>'[--projKind=<allInOne|authServ|dataServ|openSite>] [--useStore=<nativeDb|userFile|none>] [--sendWith=<selfSmtp|phpMailr|emlFiles|none>] [--signupOn] [--publFile=<file>] [--issuerId=<name>] [--audience=<a,b>] [--noPrompt]', 'about'=>'the whole sequence: servr, files, questions, secrets, users, check'),
			'testHost'=>Array('args'=>'', 'about'=>'checks the server: PHP version, extensions, writable folders'),
			'makeConf'=>Array('args'=>'', 'about'=>'creates .env, conf/access.json, conf/database.json and conf/mailer.json from their examples; none is replaced'),
			'userBase'=>Array('args'=>'[block] [--useStore=<nativeDb|userFile>]', 'about'=>'creates the users table and the basic users (nativeDb), or the users file (userFile); the devices table with a device block'),
			'checkAll'=>Array('args'=>'', 'about'=>'health check: presets, access, secrets, databases, users, unsafe settings'),
			'moveUser'=>Array('args'=>'<nativeDb|userFile> [--dropsAll] [--yesToAll]', 'about'=>'moves the users to the other store and points the login at it; the store left is kept as it is'),
			'testMail'=>Array('args'=>'[address]', 'about'=>'sends a test mail through the account nativeDb.mailAcnt names; nothing is changed')
		);
	}

	/**
	 * Runs the whole installation. Each question proposes a default,
	 * taken on Enter; with --noPrompt the defaults are taken without a word.
	 *
	 * @return Int EXITDONE when the health check finds no failure, EXITFLAW otherwise.
	 */
	public function invokeComplete(): Int {
		$checkObj = new HealthChk();
		$checkObj->checkPhpServer();
		$this->printerObj->writeShellRows( Array('check', 'state', 'found'), $checkObj->getCheckedRows() );
		if( $checkObj->hasCheckFailed() ){
			$this->stopWithReason('The server cannot run the project: nothing was installed', self::EXIT_FLAW);
		}
		// The settings of the database are made with the native userbase only : a project in the users file needs none
		$this->makeLocalFiles( Array('.env', 'conf/access.json') );
		$projCode = $this->promptProjKind();
		$storeNme = $this->promptSetupTxt($projCode);
		// The access mails are those of the native userbase : asked right after its store, and of no other
		$mailCode = strval( $this->optionsObj->getOptionValue('sendWith', '') );
		if( $storeNme=='nativeDb' ){
			$mailCode = $this->promptAccsMail();
		}elseif( $mailCode!=='' && strcasecmp($mailCode, 'none')!=0 ){
			$this->stopWithReason('The access mails need the native userbase (nativeDb): --sendWith does not apply', self::EXIT_HELP);
		}
		// The secrets that are still empty are made; those in use are left alone
		$confCmmn = new Config($this->optionsObj, $this->printerObj, $this->readStream);
		// An open site logs nobody in : it needs no secret
		$secrList = ($projCode=='openSite') ? Array() : Array('jwtToken'=>'secret or keys of the tokens', 'keysList'=>'API key', 'markSecr'=>'key of the login marks');
		// The 2FA secrets of the users are stored encrypted : their key is made with the store
		if( $storeNme!='none' ){
			$secrList['tfaCrypt'] = 'key of the 2FA secrets';
		}
		foreach( $secrList as $oneNme => $oneLbl ){
			try{
				$confCmmn->makeSecretText($oneNme, true);
			}catch( \RuntimeException $e ){
				$this->printerObj->writeShellLine( 'No '.$oneLbl.' made: '.$e->getMessage() );
			}
		}
		Console::loadConfigLogs();
		if( $storeNme!='none' ){
			try{
				$this->makeBasicUsers($storeNme, '');
			}catch( ShellStop $e ){
				// The health check tells what is missing : the installation goes on to it
				$this->printerObj->writeShellFail( $e->getMessage() );
			}
		}
		// An open site given a store : the presets it opened are closed again, before the map is asked
		if( $storeNme!='none' ){
			$this->shutPubPresets();
		}
		// Asked last : the presets served, and where (conf/doorways.lst)
		$this->promptDoorways($projCode);
		// A config cache brought along, or left by a former installation, is not the config of this one
		ConfCache::clearConfCache();
		$this->logShellChange('Deploy: installation run as '.$projCode.', users in '.$storeNme.( $storeNme=='nativeDb' ? ', access mails '.$mailCode : '' ));
		return $this->writeCheckRows();
	}

	/**
	 * Sends a short test mail through the account the native userbase
	 * names for its access mails ('mailAcnt'), to the address given, asked
	 * when it is missing, and tells whether it went or why not. Nothing is
	 * changed; the mail is logged on 'runner' as any other.
	 *
	 * @return Int EXITDONE when the mail went, EXITFAIL otherwise.
	 */
	public function invokeTestMail(): Int {
		$givenAdr = strval($this->optionsObj->shiftFirstWord() ?? '');
		$givenAdr = ($givenAdr!=='') ? $givenAdr : $this->promptValueTxt('Send the test mail to');
		if( $givenAdr==='' ){
			$this->stopWithReason('Missing argument: the address the test mail is sent to', self::EXIT_HELP);
		}
		return $this->sendSampleMail($givenAdr) ? self::EXIT_DONE : self::EXIT_FAIL;
	}

	/**
	 * Checks the server alone.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	public function invokeTestHost(): Int {
		$checkObj = new HealthChk();
		$checkObj->checkPhpServer();
		$checkObj->checkFldrWrite();
		$this->printerObj->writeShellRows( Array('check', 'state', 'found'), $checkObj->getCheckedRows() );
		return $checkObj->hasCheckFailed() ? self::EXIT_FLAW : self::EXIT_DONE;
	}

	/**
	 * Creates the local files from their examples.
	 *
	 * @return Int The exit code.
	 */
	public function invokeMakeConf(): Int {
		$this->makeLocalFiles();
		return self::EXIT_DONE;
	}

	/**
	 * Creates the users: in the store --useStore names, or the one the login
	 * uses.
	 *
	 * @return Int The exit code.
	 */
	public function invokeUserBase(): Int {
		$blockNme = strval($this->optionsObj->shiftFirstWord() ?? '');
		$storeNme = strval( $this->optionsObj->getOptionValue('useStore', strval($this->findAccessDict('loginSet')['authWith'] ?? '')) );
		if( !in_array($storeNme, Array('nativeDb', 'userFile'), true) ){
			$this->stopWithReason('The login uses neither the native userbase nor the users file: name the store with --useStore=nativeDb or --useStore=userFile', self::EXIT_HELP);
		}
		$this->makeBasicUsers($storeNme, $blockNme);
		return self::EXIT_DONE;
	}

	/**
	 * Runs the health check.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	public function invokeCheckAll(): Int {
		return $this->writeCheckRows();
	}

	/**
	 * Moves the users to the other store, the native userbase or the users
	 * file, and points the login at it. Passwords, roles, statuses,
	 * personal tokens and 2FA secrets go along, and each user keeps its
	 * identifier; the store left is not changed, so going back is moving
	 * the users again, or pointing the login back at it. The store moved to
	 * must hold no users. What does not go along is told first, and the
	 * move asked to be confirmed (--yesToAll is the yes given beforehand).
	 * Active devices need the native userbase: they stop a move to the
	 * users file, unless --dropsAll lets them go, revoked.
	 *
	 * @return Int The exit code.
	 */
	public function invokeMoveUser(): Int {
		$trgtName = $this->needsShellWord('the store the users go to: nativeDb or userFile');
		if( !in_array($trgtName, Array('nativeDb', 'userFile'), true) ){
			$this->stopWithReason('Not a store the users can be moved to: '.$trgtName, self::EXIT_HELP);
		}
		$loginCfg = $this->findAccessDict('loginSet');
		$srceName = strval($loginCfg['authWith'] ?? '');
		if( $srceName==$trgtName ){
			$this->stopWithReason('The login already uses '.$trgtName.': nothing to move', self::EXIT_DENY);
		}
		if( !in_array($srceName, Array('nativeDb', 'userFile'), true) ){
			$this->stopWithReason('The login uses neither the native userbase nor the users file: there are no users to move', self::EXIT_DENY);
		}
		$srceConf = $this->findAccessDict( strval($loginCfg['authConf'] ?? $srceName) );
		$userList = ($srceName=='nativeDb') ? $this->readUsersOfTbl($srceConf) : $this->readUsersOfFle($srceConf);
		if( empty($userList) ){
			$this->stopWithReason('The '.$srceName.' store holds no users: nothing to move', self::EXIT_DENY);
		}
		// The store moved to gets its block, and the native userbase its database, before anything is checked
		$wrtrInst = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$accsPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$treeInst = $wrtrInst->readRawJsonCfg($accsPath);
		if( !isset($treeInst->$trgtName) ){
			$treeInst->$trgtName = (object)self::STOR_CONF[$trgtName];
			$wrtrInst->saveJsonConfig($accsPath, $treeInst);
		}
		if( $trgtName=='nativeDb' && !$this->optionsObj->isOptionPassed('noPrompt') ){
			$this->promptDatabase($wrtrInst);
		}
		Console::loadConfigLogs();
		$distConf = $this->findAccessDict($trgtName);
		$this->checkStoreVoid($trgtName, $distConf);
		list($lostList, $dvceList) = $this->listLostFields($srceName, $userList);
		$userList = $this->castMovedUsers($userList, $srceConf, $distConf);
		$this->printerObj->writeShellPair('from', $srceName);
		$this->printerObj->writeShellPair('to', $trgtName);
		$this->printerObj->writeShellPair('users', count($userList));
		if( !empty($lostList) ){
			$this->printerObj->writeShellRows( Array('not kept', 'details'), $lostList );
		}
		if( !$this->promptApproval('Move '.count($userList).' users to '.$trgtName.' and point the login at it?') ){
			$this->stopWithReason('Nothing was moved', self::EXIT_DENY);
		}
		if( $trgtName=='nativeDb' ){
			$this->saveUsersTable($distConf, $userList);
		}else{
			$this->writeUsersFile($distConf, $userList);
		}
		// Devices left behind would still pass on users the project no longer manages
		$dvceAuth = new DeviceAuth( $this->findAccessDict('dvcToken') );
		foreach( $dvceList as $oneDvc ){
			$dvceAuth->dropUserDevice($oneDvc);
		}
		// The login is pointed at the store only once every user is in it
		$treeInst = $wrtrInst->readRawJsonCfg($accsPath);
		if( !isset($treeInst->loginSet) || !is_object($treeInst->loginSet) ){
			$treeInst->loginSet = new \stdClass();
		}
		$treeInst->loginSet->authWith = $trgtName;
		$treeInst->loginSet->authConf = $trgtName;
		$wrtrInst->saveJsonConfig($accsPath, $treeInst);
		Console::loadConfigLogs();
		$this->logShellChange('Deploy: '.count($userList).' users moved from '.$srceName.' to '.$trgtName.( empty($dvceList) ? '' : ', '.count($dvceList).' devices revoked' ).', the login points at '.$trgtName);
		$this->printerObj->writeShellPair('moved', count($userList));
		$this->printerObj->writeShellLine('The login now uses '.$trgtName.'; the '.$srceName.' store is left as it was.');
		return self::EXIT_DONE;
	}

	/**
	 * Reads every user of the native userbase, hashes and secrets
	 * included, with its activation code.
	 *
	 * @param Array $configValues Config block of the native userbase.
	 * @return Array The users, by identifier.
	 *
	 * @throws ShellStop When the users cannot be read.
	 */
	protected function readUsersOfTbl( Array $configValues ): Array {
		$tableNme = strval($configValues['useTable'] ?? 'users');
		try{
			$dtbsHndr = DBCon::linkNamedDbCon( strval($configValues['linkName'] ?? '') )->readConnection();
		}catch( \Throwable $e ){
			$this->stopWithReason('The users cannot be read: '.$e->getMessage());
		}
		if( $dtbsHndr===null || !preg_match('/^\w+$/D', $tableNme) ){
			$this->stopWithReason('The users cannot be read: the native userbase needs an SQL database and a table named with letters and digits', self::EXIT_DENY);
		}
		$fieldLst = array_map( Array($dtbsHndr, 'quoteFieldName'), array_merge(UserFile::FLDS_LIST, Array('actvtnCode', 'recovrCode', 'twoFactWay')) );
		if( !$dtbsHndr->sendBuiltQuery('SELECT '.implode(', ', $fieldLst).' FROM '.$dtbsHndr->quoteFieldName($tableNme).' ORDER BY '.$dtbsHndr->quoteFieldName('id_user').';') ){
			$this->stopWithReason('The users cannot be read: '.strval($dtbsHndr->getLastErrText()));
		}
		$rowsList = $dtbsHndr->readLastResult(true);
		return is_array($rowsList) ? $rowsList : Array();
	}

	/**
	 * Reads every user of the users file, hashes and secrets included.
	 *
	 * @param Array $configValues Config block of the users file.
	 * @return Array The users, as the file lists them.
	 *
	 * @throws ShellStop When the file cannot be used.
	 */
	protected function readUsersOfFle( Array $configValues ): Array {
		$fileInst = new UserFile( $configValues, new ConfFile(TOP_DIR.DS.'temp'.DS.'archives') );
		$userList = $fileInst->readUsersArray();
		if( $userList===false ){
			$this->stopWithReason('The users cannot be read: '.$fileInst->getLastErrText());
		}
		return $userList;
	}

	/**
	 * Checks that the store the users go to holds none: a table that
	 * holds users, or a file that does, is never merged into.
	 *
	 * @param String $storeOfUsers 'nativeDb' or 'userFile'.
	 * @param Array  $configValues Config block of that store.
	 *
	 * @throws ShellStop When the store holds users or cannot be read.
	 */
	protected function checkStoreVoid( String $storeOfUsers, Array $configValues ): Void {
		if( $storeOfUsers=='userFile' ){
			$userNumb = count( $this->readUsersOfFle($configValues) );
		}else{
			$tableNme = strval($configValues['useTable'] ?? 'users');
			try{
				$dtbsHndr = DBCon::linkNamedDbCon( strval($configValues['linkName'] ?? '') )->readConnection();
			}catch( \Throwable $e ){
				$this->stopWithReason('Nothing was moved: '.$e->getMessage(), self::EXIT_DENY);
			}
			if( $dtbsHndr===null || !preg_match('/^\w+$/D', $tableNme) ){
				$this->stopWithReason('Nothing was moved: the native userbase needs an SQL database and a table named with letters and digits', self::EXIT_DENY);
			}
			$userNumb = 0;
			if( $dtbsHndr->getInfoOfTable($tableNme)!==false ){
				$rowsList = $dtbsHndr->sendBuiltQuery('SELECT COUNT(*) AS '.$dtbsHndr->quoteFieldName('usrCount').' FROM '.$dtbsHndr->quoteFieldName($tableNme).';') ? $dtbsHndr->readLastResult(true) : false;
				if( !is_array($rowsList) ){
					$this->stopWithReason('Nothing was moved: the users table cannot be read: '.strval($dtbsHndr->getLastErrText()));
				}
				$userNumb = intval($rowsList[0]['usrCount'] ?? 0);
			}
		}
		if( $userNumb>0 ){
			$this->stopWithReason('Nothing was moved: the '.$storeOfUsers.' store already holds users', self::EXIT_DENY);
		}
	}

	/**
	 * Lists what does not go along when the users leave the native
	 * userbase for the users file, which is only read: activation and reset
	 * codes, the review of sign-ups, presence and the step of the last 2FA
	 * code. Users with codes by mail stop the move; active devices stop it
	 * too, unless --dropsAll lets them go.
	 *
	 * @param String $pageFileName The store the users leave.
	 * @param Array  $usersRecords The users, as that store holds them.
	 * @return Array The rows of what is not kept, and the identifiers of the devices to revoke.
	 *
	 * @throws ShellStop When users with codes by mail are found, or active devices without --dropsAll.
	 */
	protected function listLostFields( String $pageFileName, Array $usersRecords ): Array {
		if( $pageFileName!='nativeDb' ){
			return Array( Array(), Array() );
		}
		$lostList = Array();
		$pendList = array_column( array_filter($usersRecords, function( Array $userValsList ): Bool {
			return ($userValsList['userStatus'] ?? '')=='awaiting' && strval($userValsList['actvtnCode'] ?? '')!=='';
		}), 'systemName' );
		if( !empty($pendList) ){
			$lostList[] = Array('activation codes', implode(', ', $pendList).': pending until made active, or given a password, with accounts');
		}
		$rsetList = array_column( array_filter($usersRecords, function( Array $userValsList ): Bool {
			return strval($userValsList['recovrCode'] ?? '')!=='';
		}), 'systemName' );
		if( !empty($rsetList) ){
			$lostList[] = Array('reset codes', implode(', ', $rsetList).': the resets asked are forgotten, the passwords kept');
		}
		$rivuList = array_column( array_filter($usersRecords, function( Array $userValsList ): Bool {
			return ($userValsList['userStatus'] ?? '')=='inReview';
		}), 'systemName' );
		if( !empty($rivuList) ){
			$lostList[] = Array('sign-ups to review', implode(', ', $rivuList).': kept in review, which the users file cannot approve; account freeUser makes them active');
		}
		// A code mailed at each login is written in the store : the users file, only read, cannot keep one
		$mailList = array_column( array_filter($usersRecords, function( Array $userValsList ): Bool {
			return ($userValsList['twoFactWay'] ?? null)==='emailOtp';
		}), 'systemName' );
		if( !empty($mailList) ){
			$this->stopWithReason('Nothing was moved: codes by mail need the native userbase ('.implode(', ', $mailList).'); "account setTwoFa <user> --removeIt" takes them away', self::EXIT_DENY);
		}
		$lostList[] = Array('presence and 2FA steps', 'the users file is only read: last seen, failed attempts and the step of the last 2FA code are not kept');
		$dvceConf = $this->findAccessDict('dvcToken');
		$dvceNtry = empty($dvceConf) ? Array() : (new DeviceAuth($dvceConf))->listUsrDevices();
		$dvceList = Array();
		$dvceNmes = Array();
		foreach( is_array($dvceNtry) ? $dvceNtry : Array() as $oneRow ){
			if( $oneRow['revokeDate']===null ){
				$dvceList[] = intval($oneRow['id_device']);
				$dvceNmes[] = $oneRow['systemName'].'/'.$oneRow['deviceName'];
			}
		}
		if( !empty($dvceList) ){
			if( !$this->optionsObj->isOptionPassed('dropsAll') ){
				$this->stopWithReason('Nothing was moved: active devices need the native userbase ('.implode(', ', $dvceNmes).'); --dropsAll revokes them', self::EXIT_DENY);
			}
			$lostList[] = Array('devices', implode(', ', $dvceNmes).': revoked');
		}
		return Array( $lostList, $dvceList );
	}

	/**
	 * Gives the users as the store they go to takes them: the fields both
	 * stores have, the role and status a users file leaves out, an
	 * identifier for a user without one, and the 2FA secrets encrypted
	 * with the key of that store when it is not the same.
	 *
	 * @param Array $usersRecords The users, as the store they leave holds them.
	 * @param Array $sourceConfig Config block of the store they leave.
	 * @param Array $targetConfig Config block of the store they go to.
	 * @return Array The users to write.
	 *
	 * @throws ShellStop When a user has no password hash, or a 2FA secret that cannot be read.
	 */
	protected function castMovedUsers( Array $usersRecords, Array $sourceConfig, Array $targetConfig ): Array {
		$srceSeal = strval($sourceConfig['tfaCrypt'] ?? '');
		$trgtSeal = strval($targetConfig['tfaCrypt'] ?? '');
		$lastNumb = max( array_merge(Array(0), array_map('intval', array_column($usersRecords, 'id_user'))) );
		$nopsList = Array();
		$readyLst = Array();
		foreach( $usersRecords as $oneRow ){
			$userNtry = Array();
			foreach( UserFile::FLDS_LIST as $oneNme ){
				if( isset($oneRow[$oneNme]) && $oneRow[$oneNme]!=='' ){
					$userNtry[$oneNme] = $oneRow[$oneNme];
				}
			}
			if( strval($userNtry['passwdHash'] ?? '')==='' ){
				$nopsList[] = strval($oneRow['systemName'] ?? '');
				continue;
			}
			$userNtry['id_user'] = (intval($userNtry['id_user'] ?? 0)>0) ? intval($userNtry['id_user']) : ++$lastNumb;
			$userNtry['accessRole'] = $userNtry['accessRole'] ?? Checker::ROLE_LIST[0];
			$userNtry['userStatus'] = $userNtry['userStatus'] ?? 'approved';
			if( isset($userNtry['twoFactKey']) && $srceSeal!==$trgtSeal ){
				$plainTxt = Totp::openSecretText( strval($userNtry['twoFactKey']), $srceSeal );
				if( $plainTxt===null ){
					$this->stopWithReason('Nothing was moved: the 2FA secret of user '.json_encode($userNtry['systemName']).' cannot be read with the key of its store', self::EXIT_DENY);
				}
				$userNtry['twoFactKey'] = Totp::sealSecretText($plainTxt, $trgtSeal);
			}
			$readyLst[] = $userNtry;
		}
		if( !empty($nopsList) ){
			$this->stopWithReason('Nothing was moved: users without a password hash ('.implode(', ', $nopsList).'); give them a password with accounts first', self::EXIT_DENY);
		}
		return $readyLst;
	}

	/**
	 * Writes the users into the native userbase, its table created first
	 * when it is missing, all of them or none.
	 *
	 * @param Array $configValues Config block of the native userbase.
	 * @param Array $usersRecords The users to write.
	 *
	 * @throws ShellStop When the table cannot be created or written.
	 */
	protected function saveUsersTable( Array $configValues, Array $usersRecords ): Void {
		try{
			$dtbsHndr = $this->openUsersTable($configValues);
			$baseCode = strval(DBCon::readDbaseConfg( strval($configValues['linkName'] ?? '') )['dbEngine']);
		}catch( ShellStop $e ){
			throw $e;
		}catch( \Throwable $e ){
			$this->stopWithReason('Nothing was moved: '.$e->getMessage());
		}
		$qotdTble = $dtbsHndr->quoteFieldName( strval($configValues['useTable'] ?? 'users') );
		$dtbsHndr->addTransaction();
		foreach( $usersRecords as $oneRow ){
			$addSttmt = 'INSERT INTO '.$qotdTble.' ('.implode(', ', array_map(Array($dtbsHndr, 'quoteFieldName'), array_keys($oneRow))).') VALUES ('.implode(', ', array_fill(0, count($oneRow), '?')).');';
			if( !$dtbsHndr->sendBuiltQuery($addSttmt, true, array_values($oneRow)) ){
				$errorTxt = strval($dtbsHndr->getLastErrText());
				$dtbsHndr->dropWholeTrnsc();
				$this->stopWithReason('Nothing was moved: user '.json_encode($oneRow['systemName']).' cannot be written: '.$errorTxt);
			}
		}
		// The identifiers were given : PostgreSQL is told to count on from the highest
		if( $baseCode=='postgres' && !$dtbsHndr->sendBuiltQuery('SELECT setval(pg_get_serial_sequence(?, \'id_user\'), (SELECT MAX('.$dtbsHndr->quoteFieldName('id_user').') FROM '.$qotdTble.'));', false, Array($qotdTble)) ){
			$errorTxt = strval($dtbsHndr->getLastErrText());
			$dtbsHndr->dropWholeTrnsc();
			$this->stopWithReason('Nothing was moved: the identifiers cannot be set: '.$errorTxt);
		}
		$dtbsHndr->setTransaction();
	}

	/**
	 * Writes the users into the users file, which must hold none.
	 *
	 * @param Array $configValues Config block of the users file.
	 * @param Array $usersRecords The users to write.
	 *
	 * @throws ShellStop When the file cannot be written.
	 */
	protected function writeUsersFile( Array $configValues, Array $usersRecords ): Void {
		$fileInst = new UserFile( $configValues, new ConfFile(TOP_DIR.DS.'temp'.DS.'archives') );
		if( !$fileInst->fillEmptyStore($usersRecords) ){
			$this->stopWithReason('Nothing was moved: '.$fileInst->getLastErrText());
		}
	}

	/**
	 * Runs the health check and writes its rows.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	protected function writeCheckRows(): Int {
		$checkObj = new HealthChk();
		$rowsList = $checkObj->treatAllChecks();
		$this->printerObj->writeShellRows( Array('check', 'state', 'found'), $rowsList );
		$sttsList = array_count_values( array_column($rowsList, 1) );
		$this->printerObj->writeShellPair('fail', intval($sttsList['fail'] ?? 0));
		$this->printerObj->writeShellPair('warn', intval($sttsList['warn'] ?? 0));
		return $checkObj->hasCheckFailed() ? self::EXIT_FLAW : self::EXIT_DONE;
	}

	/**
	 * Creates each local file that is missing from its example. The file
	 * of the secrets is made readable by its owner only.
	 *
	 * @param Array $filesToBuild The local files to make, as DISTLIST names them; all of them when empty.
	 */
	protected function makeLocalFiles( Array $filesToBuild=Array() ): Void {
		$rowsList = Array();
		foreach( self::DIST_LIST as $oneNme => $oneTrg ){
			if( !empty($filesToBuild) && !in_array($oneNme, $filesToBuild, true) ){
				continue;
			}
			$filePath = TOP_DIR.DS.str_replace('/', DS, $oneNme);
			$distPath = TOP_DIR.DS.str_replace('/', DS, $oneTrg);
			if( is_file($filePath) ){
				$rowsList[] = Array($oneNme, 'kept');
			}elseif( !is_file($distPath) ){
				$rowsList[] = Array($oneNme, 'not created: its example '.$oneTrg.' is missing');
			}elseif( (function() use ( $distPath, $filePath ): Bool {
				// Made readable by its owner only, from its first byte: the file will hold secrets
				$prevMask = umask(0077);
				try{
					return @copy($distPath, $filePath);
				}finally{
					umask($prevMask);
				}
			})() ){
				// The secrets are for the owner alone; the access config is read by the group of the web server too
				$rghtDict = Array('.env'=>0600, 'conf/access.json'=>0640);
				@chmod($filePath, $rghtDict[$oneNme] ?? (0666 & ~umask()));
				$rowsList[] = Array($oneNme, 'created from '.$oneTrg);
				$this->logShellChange('Deploy: '.$oneNme.' created from '.$oneTrg);
			}else{
				$rowsList[] = Array($oneNme, 'not created: its folder cannot be written');
			}
		}
		$this->printerObj->writeShellRows( Array('file', 'state'), $rowsList );
		Console::loadConfigLogs();
	}

	/**
	 * Asks the presets to serve, and where: lines of basePath=preset for any
	 * host, '/=default' proposed, or the lines of the map already written.
	 * The lines naming a host are kept as they are (setting entryPts
	 * --hostName changes them). The map, conf/doorways.lst, is written whole.
	 * An open site is proposed '/=statics', and its presets are opened (see
	 * openPresetsLst) before the map is written.
	 *
	 * @param String $profileNamed The profile of the project, one of KIND_LIST.
	 *
	 * @throws ShellStop When a line is not one, names a preset that does not load or the example, no line is given, or a preset of an open site can reach an action.
	 */
	protected function promptDoorways( String $profileNamed ): Void {
		// The lines of a map already written are proposed as they are, those for any host only : a line naming a host is kept apart
		try{
			$rowsList = \nyu\Runner::readDoorwayLst();
		}catch( \RuntimeException $e ){
			$this->stopWithReason( 'Nothing was installed further: '.$e->getMessage(), self::EXIT_DENY );
		}
		$hostList = Array();
		$dfltList = Array();
		foreach( $rowsList as $oneRow ){
			if( $oneRow['hostName']==='' ){
				$dfltList[] = $oneRow['basePath'].'='.$oneRow['presetId'];
			}else{
				$hostList[] = $oneRow;
			}
		}
		$givenTxt = $this->promptValueTxt( 'Presets to serve, and where (basePath=preset, comma-separated; a host is given with setting entryPts --hostName)', empty($dfltList) ? ( $profileNamed=='openSite' ? '/=statics' : '/=default' ) : implode(', ', $dfltList) );
		$newLstng = $hostList;
		foreach( array_filter(array_map('trim', explode(',', $givenTxt))) as $onePar ){
			$pairList = array_map('trim', explode('=', $onePar, 2));
			$baseText = '/'.trim($pairList[0], '/');
			$psetName = strval($pairList[1] ?? '');
			if( !preg_match('#^/[\w.\-/]*$#D', $baseText) || !preg_match('/^\w+$/D', $psetName) ){
				$this->stopWithReason( 'Not a line of the presets served: '.$onePar.' (basePath=preset)', self::EXIT_DENY );
			}
			if( strcasecmp($psetName, \nyu\Setup\Preset::XMPL_NAME)==0 ){
				$this->stopWithReason( 'The preset '.\nyu\Setup\Preset::XMPL_NAME.' only explains the keys of a preset, and is never loaded: it serves nothing', self::EXIT_DENY );
			}
			try{
				new \nyu\Setup\Preset($psetName);
			}catch( \RuntimeException $e ){
				$this->stopWithReason( 'Not a preset to serve: '.$e->getMessage(), self::EXIT_DENY );
			}
			$newLstng[] = Array('hostName'=>'', 'basePath'=>$baseText, 'presetId'=>$psetName);
		}
		if( empty($newLstng) ){
			$this->stopWithReason( 'No preset to serve: give one line at least, /=default', self::EXIT_DENY );
		}
		if( $profileNamed=='openSite' ){
			$this->openPresetsLst($newLstng);
		}
		(new Config($this->optionsObj, $this->printerObj, $this->readStream))->writeDoorLines($newLstng);
		$lineList = Array();
		foreach( $newLstng as $oneRow ){
			$lineList[] = $oneRow['hostName'].$oneRow['basePath'].'='.$oneRow['presetId'];
		}
		$this->logShellChange( 'Deploy: presets served '.json_encode($lineList, JSON_UNESCAPED_SLASHES) );
	}

	/**
	 * Opens the presets of the map of an open site: each one is given
	 * "required": false and "isPublic": true, unless it already has them. A
	 * preset that can reach an action is never opened: a Run added later
	 * would become public without anyone deciding it. Every preset is
	 * checked before any is written.
	 *
	 * @param Array $doorwaysRows The lines of the map, each with its 'presetId'.
	 * @return Array The names of the presets opened.
	 *
	 * @throws ShellStop When a preset can reach an action, or cannot be read or written.
	 */
	protected function openPresetsLst( Array $doorwaysRows ): Array {
		$openList = Array();
		foreach( array_unique(array_column($doorwaysRows, 'presetId')) as $oneRps ){
			try{
				$setupObj = (new \nyu\Setup\Preset($oneRps))->makeConfLoader();
				$routeCfg = $setupObj->getRegistryKey('dispatch');
				$canReach = (new \nyu\Tools\Router( is_array($routeCfg) ? $routeCfg : Array(), \nyu\Shell\ActCaller::makeRequestLst('GET', '/')[0] ))->canReachAction();
				$accsConf = $setupObj->setAccessArray();
			}catch( \Throwable $e ){
				$this->stopWithReason('Nothing was opened: the preset '.$oneRps.' cannot be read: '.$e->getMessage(), self::EXIT_DENY);
			}
			if( $canReach ){
				$this->stopWithReason('Nothing was opened: the preset '.$oneRps.' can reach an action, which an open site never makes public; serve statics, or a preset that serves no Run, or open the Run on purpose with openList in a preset that checks access', self::EXIT_DENY);
			}
			if( ($accsConf['required'] ?? true)!==false || ($accsConf['isPublic'] ?? false)!==true ){
				$openList[] = $oneRps;
			}
		}
		$wrtrInst = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		foreach( $openList as $oneRps ){
			$psetPath = TOP_DIR.DS.'conf'.DS.'presets'.DS.$oneRps.'.rps';
			try{
				$treeInst = $wrtrInst->readRawJsonCfg($psetPath);
			}catch( \RuntimeException $e ){
				$this->stopWithReason('The preset '.$oneRps.' cannot be opened: '.$e->getMessage().' (a preset holding comments is opened by hand)', self::EXIT_DENY);
			}
			if( !isset($treeInst->security) || !is_object($treeInst->security) ){
				$treeInst->security = new \stdClass();
			}
			$treeInst->security->required = false;
			$treeInst->security->isPublic = true;
			$wrtrInst->saveJsonConfig($psetPath, $treeInst);
			$this->printerObj->writeShellLine('Preset '.$oneRps.' opened: "required": false, "isPublic": true.');
		}
		if( !empty($openList) ){
			$this->logShellChange('Deploy: presets opened for an open site ('.implode(', ', $openList).')');
		}
		return $openList;
	}

	/**
	 * Closes again the presets an open site had opened, when the project is
	 * given a store of users: when every preset of the map is open, each one
	 * marked "isPublic" is given "required": true, its "isPublic" removed,
	 * and named. A preset that names no carrier takes the one of the login
	 * block (carrWith, carrConf), which the store works with; when the login
	 * names none, it is left open, and told. Without a map, or with a preset
	 * of it that checks access, nothing is changed.
	 *
	 * @throws ShellStop When a preset cannot be written.
	 */
	protected function shutPubPresets(): Void {
		try{
			$rowsList = \nyu\Runner::readDoorwayLst();
		}catch( \RuntimeException $e ){
			// The question of the map tells it
			return;
		}
		$publList = Array();
		foreach( array_unique(array_column($rowsList, 'presetId')) as $oneRps ){
			try{
				$accsConf = (new \nyu\Setup\Preset($oneRps))->makeConfLoader()->setAccessArray();
			}catch( \Throwable $e ){
				return;
			}
			// A preset that checks access : the project was not an open site
			if( ($accsConf['required'] ?? true)!==false ){
				return;
			}
			if( ($accsConf['isPublic'] ?? false)===true ){
				$publList[] = $oneRps;
			}
		}
		$wrtrInst = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		// A preset closed without a carrier would refuse every request (500): it takes the one of the login, which the store works with
		if( !empty($publList) ){
			Console::loadConfigLogs();
		}
		$carrName = trim( strval($this->findAccessDict('loginSet')['carrWith'] ?? '') );
		$carrConf = trim( strval($this->findAccessDict('loginSet')['carrConf'] ?? '') );
		$shutList = Array();
		foreach( $publList as $oneRps ){
			$psetPath = TOP_DIR.DS.'conf'.DS.'presets'.DS.$oneRps.'.rps';
			try{
				$treeInst = $wrtrInst->readRawJsonCfg($psetPath);
			}catch( \RuntimeException $e ){
				$this->stopWithReason('The preset '.$oneRps.' cannot be closed: '.$e->getMessage().'; close it by hand ("required": true)', self::EXIT_DENY);
			}
			$hasCarry = trim(strval($treeInst->security->carrWith ?? ''))!='';
			if( !$hasCarry && $carrName=='' ){
				$this->printerObj->writeShellLine('Preset '.$oneRps.' left open: the login names no carrier to check access with; close it by hand ("required": true, and a carrier).');
				continue;
			}
			if( !$hasCarry ){
				$treeInst->security->carrWith = $carrName;
				$treeInst->security->carrConf = $carrConf;
			}
			$treeInst->security->required = true;
			unset($treeInst->security->isPublic);
			$wrtrInst->saveJsonConfig($psetPath, $treeInst);
			$shutList[] = $oneRps;
			$this->printerObj->writeShellLine('Preset '.$oneRps.' closed'.($hasCarry ? '' : ', with the carrier of the login ('.$carrName.')').': the project has users now; open it again on purpose when it is meant to be public.');
		}
		$publList = $shutList;
		if( !empty($publList) ){
			Console::loadConfigLogs();
			$this->logShellChange('Deploy: presets of the open site closed, a store of users given ('.implode(', ', $publList).')');
		}
	}

	/**
	 * Asks what the installation needs, each question with its default:
	 * the environment, the store of the users and, for the native
	 * userbase, where its database is: conf/database.json is then made
	 * from its example, and only then. What is answered is written to the
	 * config files. A resource server is not asked the store: it has no
	 * users of its own; an authentication server needs one.
	 *
	 * @param String $profileNamed The profile of the project, one of KIND_LIST.
	 * @return String The store of the users: 'nativeDb', 'userFile' or 'none'.
	 *
	 * @throws ShellStop When an answer is not one of those proposed, or the store does not fit the profile.
	 */
	protected function promptSetupTxt( String $profileNamed ): String {
		$wrtrInst = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$iniTrail = TOP_DIR.DS.'conf'.DS.'system.ini';
		$accsPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$currEnvr = strval($wrtrInst->readIniFileVal($iniTrail, 'ENV_NAME') ?? '');
		if( $currEnvr!='' ){
			$wantEnvr = strtoupper( $this->promptValueTxt('Environment (DEVS, TEST, PREV, PROD)', $currEnvr) );
			if( !in_array($wantEnvr, Array('DEVS', 'TEST', 'PREV', 'PROD'), true) ){
				$this->stopWithReason('Not an environment: '.$wantEnvr, self::EXIT_DENY);
			}
			if( $wantEnvr!=$currEnvr ){
				$wrtrInst->saveIniFileVal($iniTrail, 'ENV_NAME', $wantEnvr);
			}
		}
		// A resource server accepts the tokens of another server, an open site logs nobody in : no users, nor a store to ask
		if( $profileNamed=='dataServ' || $profileNamed=='openSite' ){
			Console::loadConfigLogs();
			return 'none';
		}
		$lognAuth = strval($this->findAccessDict('loginSet')['authWith'] ?? '');
		$storeNme = strval( $this->optionsObj->getOptionValue('useStore', in_array($lognAuth, Array('nativeDb', 'userFile'), true) ? $lognAuth : ( $profileNamed=='authServ' ? 'userFile' : 'none' )) );
		$storeNme = $this->promptValueTxt('Store of the users ('.implode(', ', self::STOR_LIST).')', $storeNme);
		// Matched whatever its letter case, kept as the list writes it
		foreach( self::STOR_LIST as $oneCod ){
			$storeNme = (strcasecmp($oneCod, $storeNme)==0) ? $oneCod : $storeNme;
		}
		if( !in_array($storeNme, self::STOR_LIST, true) ){
			$this->stopWithReason('Not a store of users: '.$storeNme, self::EXIT_DENY);
		}
		if( $storeNme=='none' && $profileNamed=='authServ' ){
			$this->stopWithReason('An authentication server (authServ) logs its users in: it needs a store (nativeDb or userFile)', self::EXIT_DENY);
		}
		// The login is pointed at the store chosen
		if( $storeNme!='none' && $storeNme!=$lognAuth && is_file($accsPath) ){
			$treeInst = $wrtrInst->readRawJsonCfg($accsPath);
			if( !isset($treeInst->loginSet) || !is_object($treeInst->loginSet) ){
				$treeInst->loginSet = new \stdClass();
			}
			$treeInst->loginSet->authWith = $storeNme;
			$treeInst->loginSet->authConf = $storeNme;
			if( !isset($treeInst->$storeNme) ){
				$treeInst->$storeNme = (object)self::STOR_CONF[$storeNme];
			}
			$wrtrInst->saveJsonConfig($accsPath, $treeInst);
		}
		if( $storeNme=='nativeDb' ){
			$this->makeLocalFiles( Array('conf/database.json') );
			if( !$this->optionsObj->isOptionPassed('noPrompt') ){
				$this->promptDatabase($wrtrInst);
			}
		}
		Console::loadConfigLogs();
		return $storeNme;
	}

	/**
	 * Asks what the project is, first: users, login and data in one
	 * ('allInOne'), an authentication server issuing tokens for other
	 * projects ('authServ'), or a resource server accepting them ('dataServ').
	 * --projKind answers it beforehand, --noPrompt takes 'allInOne'. No key
	 * keeps the profile: the blocks of conf/access.json it needs are written,
	 * and they are what tells it afterwards. 'allInOne' writes nothing;
	 * 'authServ' signs the tokens with RS256, its pair made with the other
	 * secrets, and asks the claims they carry; 'dataServ' asks the public key
	 * of the authentication server and the claims it checks, and points the
	 * login at no credential check: it answers 501. 'openSite', a site with
	 * nothing to log in to, takes the users' store out of the login; the
	 * presets it serves are opened with the map (see promptDoorways).
	 *
	 * @return String The profile, one of KIND_LIST.
	 *
	 * @throws ShellStop When the answer is not a profile, or conf/access.json cannot be read.
	 */
	protected function promptProjKind(): String {
		$projCode = strval( $this->optionsObj->getOptionValue('projKind', '') );
		$projCode = ($projCode!=='') ? $projCode : $this->promptValueTxt('What this project is ('.implode(', ', self::KIND_LIST).')', self::KIND_LIST[0]);
		// Matched whatever its letter case, kept as the list writes it
		foreach( self::KIND_LIST as $oneCod ){
			$projCode = (strcasecmp($oneCod, $projCode)==0) ? $oneCod : $projCode;
		}
		if( !in_array($projCode, self::KIND_LIST, true) ){
			$this->stopWithReason('Not a profile of a project: '.$projCode, self::EXIT_DENY);
		}
		if( $projCode=='allInOne' ){
			return $projCode;
		}
		if( $projCode!='authServ' && strval($this->optionsObj->getOptionValue('useStore', ''))!=='' ){
			$this->stopWithReason( ($projCode=='dataServ' ? 'A resource server (dataServ)' : 'An open site (openSite)').' has no users of its own: --useStore does not apply', self::EXIT_HELP );
		}
		$wrtrInst = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$accsPath = TOP_DIR.DS.'conf'.DS.'access.json';
		if( !is_file($accsPath) ){
			$this->stopWithReason('Nothing was installed: conf/access.json is missing, the profile cannot be written', self::EXIT_FAIL);
		}
		$treeInst = $wrtrInst->readRawJsonCfg($accsPath);
		if( !isset($treeInst->loginSet) || !is_object($treeInst->loginSet) ){
			$treeInst->loginSet = new \stdClass();
		}
		// An open site has no users' store : the login checks nothing, and the presets it serves are opened with the map
		if( $projCode=='openSite' ){
			unset($treeInst->loginSet->authWith, $treeInst->loginSet->authConf);
			$wrtrInst->saveJsonConfig($accsPath, $treeInst);
			Console::loadConfigLogs();
			$this->logShellChange('Deploy: conf/access.json written for the profile openSite (no users\' store)');
			return $projCode;
		}
		// The tokens are the bond between the projects : the login carries them
		$blockNme = ( strval($treeInst->loginSet->carrWith ?? 'jwtToken')=='jwtToken' ) ? strval($treeInst->loginSet->carrConf ?? 'jwtToken') : 'jwtToken';
		$treeInst->loginSet->carrWith = 'jwtToken';
		$treeInst->loginSet->carrConf = $blockNme;
		if( !isset($treeInst->$blockNme) || !is_object($treeInst->$blockNme) ){
			$treeInst->$blockNme = new \stdClass();
		}
		$blockNde = $treeInst->$blockNme;
		$blockNde->signAlgo = 'RS256';
		if( $projCode=='authServ' ){
			// Its tokens are signed here : a public key found alone is replaced by a pair of its own
			unset($blockNde->external);
		}else{
			$this->promptIssurKey($blockNde, $blockNme);
			// Nothing is checked here : the login answers that the tokens are issued by another server
			unset($treeInst->loginSet->authWith, $treeInst->loginSet->authConf);
		}
		$this->promptBindings($blockNde);
		$wrtrInst->saveJsonConfig($accsPath, $treeInst);
		Console::loadConfigLogs();
		$this->logShellChange('Deploy: conf/access.json written for the profile '.$projCode.' (block '.$blockNme.')');
		return $projCode;
	}

	/**
	 * Asks the claims binding the tokens to the projects: the issuer
	 * ('issuerId') and the audiences ('audience', comma-separated). An
	 * authentication server names itself, the host name proposed, and the
	 * projects accepting its tokens; a resource server ('external') names
	 * the authentication server and this project. Each is proposed as the
	 * block has it.
	 *
	 * @param \stdClass $tokenConfObj The block of the tokens, given the answers.
	 */
	protected function promptBindings( \stdClass $tokenConfObj ): Void {
		$isExtern = ($tokenConfObj->external ?? false)===true;
		$nowIssur = is_string($tokenConfObj->issuerId ?? null) ? trim($tokenConfObj->issuerId) : '';
		$nowIssur = ($nowIssur=='' && !$isExtern) ? strval(gethostname()) : $nowIssur;
		// --issuerId and --audience answer the two questions beforehand
		$nowIssur = trim( strval($this->optionsObj->getOptionValue('issuerId', $nowIssur)) );
		$tokenConfObj->issuerId = trim( $this->promptValueTxt($isExtern ? 'Issuer of the authentication server\'s tokens (issuerId)' : 'Issuer of the tokens (issuerId)', $nowIssur) );
		$nowAudnc = $tokenConfObj->audience ?? Array();
		$nowAudnc = is_array($nowAudnc) ? implode(', ', array_map('strval', $nowAudnc)) : strval($nowAudnc);
		$nowAudnc = strval( $this->optionsObj->getOptionValue('audience', $nowAudnc) );
		$audnText = $this->promptValueTxt($isExtern ? 'Audience naming this project (audience, comma-separated)' : 'Projects that will accept its tokens (audience, comma-separated)', $nowAudnc);
		$tokenConfObj->audience = array_values( array_filter(array_map('trim', explode(',', $audnText)), 'strlen') );
	}

	/**
	 * Asks the file of the public key of the authentication server, and
	 * copies it into conf/keys under the name of the block: the tokens are
	 * checked with it, none is issued. The block is given that key alone
	 * ('external': the key comes from another server, kept as it is by the
	 * installation). The key in use is proposed; another one replaces it
	 * once confirmed.
	 *
	 * @param \stdClass $tokenConfObj The block of the tokens, given the key.
	 * @param String    $blockNmdText Name of that block.
	 *
	 * @throws ShellStop When the file is missing, is not a public RSA key of JWT::RSAS_BITS bits at least, or cannot be copied.
	 */
	protected function promptIssurKey( \stdClass $tokenConfObj, String $blockNmdText ): Void {
		$publName = 'conf/keys/'.$blockNmdText.'.pub';
		$publPath = TOP_DIR.DS.str_replace('/', DS, $publName);
		// --publFile answers the question beforehand: without it, --noPrompt has no file to take on a new project
		$srceName = trim( $this->promptValueTxt('File of the public key of the authentication server', strval($this->optionsObj->getOptionValue('publFile', is_file($publPath) ? $publName : ''))) );
		// The path is read from where the command runs, or from the project
		$srcePath = is_file($srceName) ? $srceName : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, ltrim($srceName, '/\\'));
		if( $srceName=='' || !is_file($srcePath) ){
			$this->stopWithReason('Nothing was installed: no file of the public key'.( $srceName!='' ? ' at '.json_encode($srceName) : ' given (--publFile names it)' ), self::EXIT_DENY);
		}
		if( !function_exists('openssl_pkey_get_public') ){
			$this->stopWithReason('Nothing was installed: RS256 needs the openssl extension', self::EXIT_FAIL);
		}
		$pemStrng = strval(@file_get_contents($srcePath));
		$keyObjct = (strpos($pemStrng, 'PRIVATE KEY')===false) ? @openssl_pkey_get_public($pemStrng) : false;
		$keyArray = ($keyObjct!==false) ? openssl_pkey_get_details($keyObjct) : false;
		if( !is_array($keyArray) || ($keyArray['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($keyArray['bits'] ?? 0)<\nyu\Access\Carr\JWT::RSAS_BITS ){
			$this->stopWithReason('Nothing was installed: '.json_encode($srceName).' is not a public RSA key of '.\nyu\Access\Carr\JWT::RSAS_BITS.' bits at least', self::EXIT_DENY);
		}
		$publText = strval($keyArray['key']);
		$nowStrng = is_file($publPath) ? strval(@file_get_contents($publPath)) : '';
		if( $nowStrng!=='' && trim($nowStrng)!==trim($publText) && !$this->promptApproval('Replace the public key in use ('.$publName.'): the tokens it checks are refused') ){
			$this->stopWithReason('Nothing was installed: the public key in use is kept', self::EXIT_DENY);
		}
		if( trim($nowStrng)!==trim($publText) ){
			if( !is_dir(dirname($publPath)) ){
				@mkdir(dirname($publPath), 0700, true);
			}
			if( @file_put_contents($publPath, $publText, LOCK_EX)===false ){
				$this->stopWithReason('Nothing was installed: '.$publName.' cannot be written', self::EXIT_FAIL);
			}
			@chmod($publPath, 0644);
			$this->logShellChange('Deploy: public key of the authentication server copied into '.$publName);
		}
		$tokenConfObj->publFile = $publName;
		$tokenConfObj->external = true;
		unset($tokenConfObj->privFile, $tokenConfObj->hmacSecr);
	}

	/**
	 * Asks the access mails, right after the native userbase is chosen:
	 * whether they are sent, through which type of account, the account
	 * (see promptAccntCfg), the front-end link of the mails, whether the
	 * sign-ups are open, and an address a test mail is sent to. --sendWith
	 * answers the first two beforehand ('none' for no mail), --signupOn the
	 * sign-ups; --noPrompt alone takes 'none'. Without mails nothing is
	 * written: 'mailAcnt' stays as it is, and the codes are given in the
	 * replies. The 'phpMailr' type is proposed only when PHPMailer is
	 * installed.
	 *
	 * @return String The type of the account the mails go through, 'none' when they are not sent.
	 *
	 * @throws ShellStop When the type is not one proposed, the account cannot be written, or the link is not one.
	 */
	protected function promptAccsMail(): String {
		$typeList = array_keys(Mailer::TYPE_LIST);
		if( !class_exists(PHMail::MAIL_CLSS) ){
			$typeList = array_values( array_diff($typeList, Array('phpMailr')) );
		}
		$yessList = Array('y', 'yes');
		$typeName = strval( $this->optionsObj->getOptionValue('sendWith', '') );
		if( $typeName==='' ){
			if( !in_array(strtolower($this->promptValueTxt('Send the access mails (activation, reset, sign-ups)? (yes/no)', 'no')), $yessList, true) ){
				return 'none';
			}
			$typeName = $this->promptValueTxt('Mail account type ('.implode(', ', $typeList).')', $typeList[0]);
		}
		if( strcasecmp($typeName, 'none')==0 ){
			return 'none';
		}
		// Matched whatever its letter case, kept as the list writes it
		foreach( array_keys(Mailer::TYPE_LIST) as $oneCod ){
			$typeName = (strcasecmp($oneCod, $typeName)==0) ? $oneCod : $typeName;
		}
		if( !isset(Mailer::TYPE_LIST[$typeName]) ){
			$this->stopWithReason('Not a type of mail account: '.$typeName, self::EXIT_DENY);
		}
		if( !in_array($typeName, $typeList, true) ){
			$this->stopWithReason('A phpMailr account needs the PHPMailer library, which is not installed (composer require phpmailer/phpmailer)', self::EXIT_DENY);
		}
		$wrtrInst = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$accsPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$loginCfg = $this->findAccessDict('loginSet');
		$blockNme = ( strval($loginCfg['authWith'] ?? '')=='nativeDb' ) ? strval($loginCfg['authConf'] ?? 'nativeDb') : 'nativeDb';
		$nowAccnt = $this->findAccessDict($blockNme)['mailAcnt'] ?? '';
		$acctName = $this->promptAccntCfg( $wrtrInst, $typeName, is_string($nowAccnt) ? $nowAccnt : '' );
		$treeInst = $wrtrInst->readRawJsonCfg($accsPath);
		if( !isset($treeInst->$blockNme) || !is_object($treeInst->$blockNme) ){
			$treeInst->$blockNme = (object)self::STOR_CONF['nativeDb'];
		}
		$blockNde = $treeInst->$blockNme;
		$linkTmpl = $this->promptValueTxt('Front-end link of the mails (empty for none)', is_string($blockNde->linkTmpl ?? null) ? $blockNde->linkTmpl : '');
		if( $linkTmpl!=='' && ( !preg_match('#^https?://[^\s]+$#Di', $linkTmpl) || strpos($linkTmpl, '%sentCode%')===false ) ){
			$this->stopWithReason('Not a front-end link: an http(s) address holding %sentCode% (and %mailKind%, %userName% as needed)', self::EXIT_DENY);
		}
		$blockNde->mailAcnt = $acctName;
		$blockNde->linkTmpl = $linkTmpl;
		$blockNde->signupOn = $this->optionsObj->isOptionPassed('signupOn') || in_array(strtolower($this->promptValueTxt('Open the sign-ups? (yes/no)', 'no')), $yessList, true);
		$wrtrInst->saveJsonConfig($accsPath, $treeInst);
		Console::loadConfigLogs();
		$this->logShellChange('Deploy: access mails sent through the account '.json_encode($acctName).' ('.$typeName.'), sign-ups '.( $blockNde->signupOn ? 'open' : 'closed' ));
		$testAddr = $this->promptValueTxt('Send a test mail to (empty for none)');
		if( $testAddr!=='' ){
			// A test mail that does not go is told, and the installation goes on
			$this->sendSampleMail($testAddr);
		}
		return $typeName;
	}

	/**
	 * Asks the mail account of the access mails and writes it in
	 * conf/mailer.json, under the name asked ('nyuRuns' proposed): the
	 * server, its port, the user and the password for an account that
	 * sends ('selfSmtp', 'phpMailr'), then the sender for every type. Each
	 * value is proposed as the account in use has it. The password is typed
	 * hidden, kept in the file of the secrets, and only named in the
	 * account; Enter alone keeps the present one. A file made here holds
	 * that account alone; in a file that exists, the other accounts are
	 * left as they are. An 'emlFiles' account keeps its mails in temp/mails.
	 *
	 * @param ConfFile $writerObject The writer of config files.
	 * @param String   $sendWithCode Type of the account: 'selfSmtp', 'phpMailr' or 'emlFiles'.
	 * @param String   $accountLabel Name of the account the access mails go through now, '' when none.
	 * @return String The name of the account written.
	 *
	 * @throws ShellStop When the file holds one account without a name, or the server, the port, the sender or the name is not one.
	 */
	protected function promptAccntCfg( ConfFile $writerObject, String $sendWithCode, String $accountLabel ): String {
		$mailPath = TOP_DIR.DS.'conf'.DS.'mailer.json';
		$treeInst = is_file($mailPath) ? $writerObject->readRawJsonCfg($mailPath) : new \stdClass();
		$nowValrs = Array();
		foreach( get_object_vars($treeInst) as $oneKey => $oneVal ){
			if( strcasecmp($oneKey, 'sendWith')==0 ){
				$this->stopWithReason('No mail account written: conf/mailer.json holds one account without a name; put it under a name first', self::EXIT_DENY);
			}
			if( $accountLabel!=='' && strcasecmp($oneKey, $accountLabel)==0 && is_object($oneVal) ){
				$nowValrs = array_change_key_case( get_object_vars($oneVal), CASE_LOWER );
			}
		}
		$nowValrs = array_map( 'strval', array_filter($nowValrs, 'is_scalar') );
		$canEmail = ($sendWithCode!='emlFiles');
		$isSilent = $this->optionsObj->isOptionPassed('noPrompt');
		if( $canEmail && $isSilent && ($nowValrs['hostname'] ?? '')==='' ){
			$this->stopWithReason('No mail account written: a '.$sendWithCode.' account needs its mail server, which --noPrompt cannot ask', self::EXIT_DENY);
		}
		$acctDict = array_fill_keys(Mailer::KEYS_LIST, '');
		$acctDict['sendWith'] = $sendWithCode;
		if( $canEmail ){
			$acctDict['hostName'] = $this->promptValueTxt('Mail server host', $nowValrs['hostname'] ?? '');
			$acctDict['portNumb'] = $this->promptValueTxt('Port (465 or 587)', ($nowValrs['portnumb'] ?? '')!=='' ? $nowValrs['portnumb'] : '587');
			if( $acctDict['hostName']==='' || !preg_match('/^\d{1,5}$/D', $acctDict['portNumb']) ){
				$this->stopWithReason('No mail account written: a '.$sendWithCode.' account needs the host of its mail server, and a port number', self::EXIT_DENY);
			}
			$acctDict['userName'] = $this->promptValueTxt('Mail user', $nowValrs['username'] ?? '');
			$nowPsswd = $nowValrs['password'] ?? '';
			$envrName = (strpos($nowPsswd, 'env:')===0 && strlen($nowPsswd)>4) ? substr($nowPsswd, 4) : 'NYU_MAIL_PASS';
			$givenPwd = $isSilent ? '' : $this->promptSecrText('Mail password (Enter keeps the present one)');
			if( $givenPwd!=='' ){
				$writerObject->saveEnvFileVal(TOP_DIR.DS.'.env', $envrName, $givenPwd);
			}
			// Never the password itself : the account names where it is kept
			$acctDict['passWord'] = ($givenPwd!=='' || $nowPsswd==='') ? 'env:'.$envrName : $nowPsswd;
			$acctDict['waitSecs'] = ($nowValrs['waitsecs'] ?? '')!=='' ? $nowValrs['waitsecs'] : '10';
		}
		$acctDict['fromAddr'] = $this->promptValueTxt('Sender address', ($nowValrs['fromaddr'] ?? '')!=='' ? $nowValrs['fromaddr'] : ( $canEmail ? '' : 'no-reply@example.com' ));
		try{
			Message::checkEmailAddr($acctDict['fromAddr']);
		}catch( MailFailure $e ){
			$this->stopWithReason('No mail account written: the sender address is not one ('.$e->getMessage().')', self::EXIT_DENY);
		}
		$acctDict['fromName'] = $this->promptValueTxt('Sender name', ($nowValrs['fromname'] ?? '')!=='' ? $nowValrs['fromname'] : 'nyuRuns');
		$acctDict['copiesTo'] = $nowValrs['copiesto'] ?? '';
		$acctName = $this->promptValueTxt('Name of the mail account', ($accountLabel!=='') ? $accountLabel : 'nyuRuns');
		if( !preg_match('/^\w+$/D', $acctName) ){
			$this->stopWithReason('No mail account written: '.json_encode($acctName).' is not a name of account (letters, digits, underscores)', self::EXIT_DENY);
		}
		// An account of that name, whatever its case, is the one replaced
		$nameClef = $acctName;
		foreach( array_keys(get_object_vars($treeInst)) as $oneKey ){
			$nameClef = (strcasecmp(strval($oneKey), $acctName)==0) ? strval($oneKey) : $nameClef;
		}
		$treeInst->$nameClef = (object)$acctDict;
		$writerObject->saveJsonConfig($mailPath, $treeInst);
		$this->printerObj->writeShellLine('Mail account '.$nameClef.' ('.$sendWithCode.') written in conf/mailer.json.');
		$this->logShellChange('Deploy: mail account '.json_encode($nameClef).' ('.$sendWithCode.') written in conf/mailer.json');
		return $nameClef;
	}

	/**
	 * Sends a short test mail through the account the native userbase
	 * names for its access mails, and tells whether it went or why not. An
	 * 'emlFiles' account writes it as a file, which is told. Nothing is
	 * changed; the Mailer logs it on 'runner'.
	 *
	 * @param String $emailAddress Address the test mail is sent to.
	 * @return Bool Whether the mail went.
	 *
	 * @throws ShellStop When the native userbase names no mail account.
	 */
	protected function sendSampleMail( String $emailAddress ): Bool {
		// The config as it is now written, and no mailer built from a former one
		Console::loadConfigLogs();
		Mailer::clearMailCache();
		$loginCfg = $this->findAccessDict('loginSet');
		$blockNme = ( strval($loginCfg['authWith'] ?? '')=='nativeDb' ) ? strval($loginCfg['authConf'] ?? 'nativeDb') : 'nativeDb';
		$acctName = $this->findAccessDict($blockNme)['mailAcnt'] ?? '';
		if( !is_string($acctName) || $acctName==='' ){
			$this->stopWithReason('No test mail sent: the native userbase names no mail account ("mailAcnt" of the '.$blockNme.' block)', self::EXIT_DENY);
		}
		$typeName = '';
		try{
			$typeName = Mailer::readAccountCfg($acctName)['sendWith'];
			$mailInst = Mailer::getMailerOfAcc($acctName);
			$bodyText = 'This is a test mail of nyuRuns, sent by "php nyuRun install testMail" through the mail account '.$acctName.'.'."\n\n".'Receiving it means the access mails (activation, reset, sign-ups) can be sent.'."\n";
			$failText = $mailInst->sendMailViaAcc($emailAddress, 'nyuRuns test mail', $bodyText) ? null : $mailInst->getLastErrText();
		}catch( MailFailure $e ){
			$failText = $e->getMessage();
		}
		if( $failText!==null ){
			$this->printerObj->writeShellFail('Test mail not sent to '.$emailAddress.' through the account '.$acctName.': '.$failText);
			return false;
		}
		$this->printerObj->writeShellLine( (strcasecmp($typeName, 'emlFiles')==0) ? 'Test mail to '.$emailAddress.' written as a file by the account '.$acctName.' (emlFiles): nothing was sent.' : 'Test mail sent to '.$emailAddress.' through the account '.$acctName.'.' );
		return true;
	}

	/**
	 * Asks where the default database is, each value proposed as the
	 * config has it, and writes what changed. The password is typed
	 * hidden, kept in the file of the secrets, and only named in the
	 * config; Enter alone keeps the present one.
	 *
	 * @param ConfFile $writerObject The writer of config files.
	 *
	 * @throws ShellStop When the type is not one of a database.
	 */
	protected function promptDatabase( ConfFile $writerObject ): Void {
		$basePath = TOP_DIR.DS.'conf'.DS.'database.json';
		if( !is_file($basePath) ){
			return;
		}
		$treeInst = $writerObject->readRawJsonCfg($basePath);
		$nodeInst = $treeInst;
		if( !property_exists($treeInst, 'dbEngine') ){
			foreach( get_object_vars($treeInst) as $oneVal ){
				if( is_object($oneVal) ){
					$nodeInst = $oneVal;
					break;
				}
			}
		}
		$baseCode = strtolower( $this->promptValueTxt('Database type (mysql_db, postgres, sqlitedb)', strval($nodeInst->dbEngine ?? 'mysql_db')) );
		if( !in_array($baseCode, Array('mysql_db', 'postgres', 'sqlitedb'), true) ){
			$this->stopWithReason('The native userbase works on mysql_db, postgres or sqlitedb, not on: '.$baseCode, self::EXIT_DENY);
		}
		$nodeInst->dbEngine = $baseCode;
		if( $baseCode=='sqlitedb' ){
			$nodeInst->baseName = $this->promptValueTxt('Database file', strval($nodeInst->baseName ?? ''));
		}else{
			$nodeInst->hostName = $this->promptValueTxt('Database host', strval($nodeInst->hostName ?? 'localhost'));
			$nodeInst->portNumb = $this->promptValueTxt('Database port (empty for the usual one)', strval($nodeInst->portNumb ?? ''));
			$nodeInst->baseName = $this->promptValueTxt('Database name', strval($nodeInst->baseName ?? ''));
			$nodeInst->userName = $this->promptValueTxt('Database user', strval($nodeInst->userName ?? ''));
			$givenPwd = $this->promptSecrText('Password of that user (Enter keeps the present one)');
			if( $givenPwd!=='' ){
				$nowPsswd = strval($nodeInst->passWord ?? '');
				$envrName = (strpos($nowPsswd, 'env:')===0 && strlen($nowPsswd)>4) ? substr($nowPsswd, 4) : 'NYU_DB_PASS';
				$writerObject->saveEnvFileVal(TOP_DIR.DS.'.env', $envrName, $givenPwd);
				$nodeInst->passWord = 'env:'.$envrName;
			}
		}
		$writerObject->saveJsonConfig($basePath, $treeInst);
		$this->logShellChange('Deploy: settings of the default database written');
	}

	/**
	 * Creates the basic users, one per role, each with a random password
	 * shown this once. In the native userbase, the users table is created
	 * first when it is missing. A store that already holds users is left
	 * as it is.
	 *
	 * @param String $storeOfUsers 'nativeDb' or 'userFile'.
	 * @param String $blockNmdText Block of conf/access.json holding the settings of the store; the one the login names, or the one named as the store, when empty.
	 *
	 * @throws ShellStop When the users cannot be created.
	 */
	protected function makeBasicUsers( String $storeOfUsers, String $blockNmdText ): Void {
		// Tables are made: the descriptions the config cache keeps (TBL_CACH) are no longer the whole story
		ConfCache::clearConfCache();
		$loginCfg = $this->findAccessDict('loginSet');
		$blockNme = ($blockNmdText!='') ? $blockNmdText : ( strval($loginCfg['authWith'] ?? '')==$storeOfUsers ? strval($loginCfg['authConf'] ?? $storeOfUsers) : $storeOfUsers );
		$blockCfg = $this->findAccessDict($blockNme);
		if( empty($blockCfg) && ($storeOfUsers=='nativeDb' || $blockNmdText!='') ){
			$this->stopWithReason('No user created: conf/access.json has no "'.$blockNme.'" block', self::EXIT_DENY);
		}
		$passList = Array();
		if( $storeOfUsers=='userFile' ){
			$fileInst = new UserFile( $blockCfg, new ConfFile(TOP_DIR.DS.'temp'.DS.'archives') );
			$userList = $fileInst->listUsersNamed();
			if( $userList===false ){
				$this->stopWithReason('No user created: '.$fileInst->getLastErrText(), self::EXIT_FAIL);
			}
			if( !empty($userList) ){
				$this->printerObj->writeShellLine('The users file already holds users: nothing was changed.');
				return;
			}
			foreach( $this->promptAccounts() as $oneNme => $oneRle ){
				$passList[$oneNme] = bin2hex(random_bytes(10));
				if( !$fileInst->enrolUsrString($oneNme, $oneRle, $passList[$oneNme]) ){
					$this->stopWithReason('User '.$oneNme.' cannot be written: '.$fileInst->getLastErrText(), self::EXIT_FAIL);
				}
			}
		}else{
			try{
				$passList = $this->makeUsersTable($blockCfg);
			}catch( ShellStop $e ){
				throw $e;
			}catch( \Throwable $e ){
				$this->stopWithReason('No user created: '.$e->getMessage(), self::EXIT_FAIL);
			}
			if( empty($passList) ){
				$this->makeDevicesTbl();
				return;
			}
		}
		// The accounts come in the order of USER_LIST: all of them, or its first and last (the simple version)
		$acctList = array_combine( array_keys($passList), (count($passList)==count(self::USER_LIST)) ? array_values(self::USER_LIST) : array_merge(array_slice(array_values(self::USER_LIST), 0, 1), array_slice(array_values(self::USER_LIST), -1)) );
		$this->logShellChange('Deploy: basic users created in '.$storeOfUsers.' ('.implode(', ', array_keys($acctList)).')');
		$this->printerObj->writeShellLine('Users created. Their passwords are shown this once:');
		foreach( $acctList as $oneNme => $oneRle ){
			$this->printerObj->writeShellOnce('Password of '.$oneNme.' ('.$oneRle.')', $passList[$oneNme]);
		}
		// The devices come after the passwords : a table that cannot be made does not hide them
		if( $storeOfUsers=='nativeDb' ){
			$this->makeDevicesTbl();
		}
	}

	/**
	 * Asks which basic accounts to create, the simple version (the first
	 * and the last of USER_LIST: the lowest role and the managers) or the
	 * full one (one per role), then the name of each, the one of USER_LIST
	 * proposed: Enter alone takes it. --noPrompt takes the simple version
	 * with the names proposed.
	 *
	 * @return Array The role of each account, by its name, in the order of USER_LIST.
	 *
	 * @throws ShellStop When the version is neither simple nor full, a name is not one a user can have, or two accounts are given one name.
	 */
	protected function promptAccounts(): Array {
		$acctList = Array();
		$givenNme = strtolower(trim( $this->promptValueTxt('Accounts to create: simple ('.implode(' and ', array_keys(array_slice(self::USER_LIST, 0, 1, true) + array_slice(self::USER_LIST, -1, 1, true))).') or full ('.implode(', ', array_keys(self::USER_LIST)).')', 'simple') ));
		if( !in_array($givenNme, Array('simple', 'full'), true) ){
			$this->stopWithReason('No user created: '.json_encode($givenNme).' is neither simple nor full', self::EXIT_DENY);
		}
		foreach( ($givenNme=='full') ? self::USER_LIST : array_slice(self::USER_LIST, 0, 1, true) + array_slice(self::USER_LIST, -1, 1, true) as $oneNme => $oneRle ){
			$givenNme = $this->promptValueTxt('Name of the '.$oneRle.' account', $oneNme);
			if( !preg_match(\nyu\Access\Auth\UserBase::NAME_RULE, $givenNme) || in_array(strtolower($givenNme), \nyu\Access\Auth\UserBase::NAME_DENY, true) ){
				$this->stopWithReason('No user created: '.json_encode($givenNme).' is not a name a user can have', self::EXIT_DENY);
			}
			if( in_array(strtolower($givenNme), array_map('strtolower', array_keys($acctList)), true) ){
				$this->stopWithReason('No user created: two accounts cannot share the name '.json_encode($givenNme), self::EXIT_DENY);
			}
			$acctList[$givenNme] = $oneRle;
		}
		return $acctList;
	}

	/**
	 * Creates the devices table when conf/access.json holds a device block
	 * and the table is missing, from the devices.<type>.sql file of
	 * conf/schema, linked to the users table the block names. A project
	 * without a device block gets no devices table.
	 *
	 * @throws ShellStop When the table cannot be created.
	 */
	protected function makeDevicesTbl(): Void {
		$dvceConf = $this->findAccessDict('dvcToken');
		if( empty($dvceConf) ){
			return;
		}
		$tableNme = strval($dvceConf['useTable'] ?? 'devices');
		$userTble = strval($dvceConf['usersTbl'] ?? 'users');
		try{
			$baseName = strval($dvceConf['linkName'] ?? '');
			$baseCode = strval(DBCon::readDbaseConfg($baseName)['dbEngine']);
			$dtbsHndr = DBCon::linkNamedDbCon($baseName)->readConnection();
		}catch( \Throwable $e ){
			$this->stopWithReason('No devices table created: '.$e->getMessage(), self::EXIT_FAIL);
		}
		$sqlFichr = TOP_DIR.DS.'conf'.DS.'schema'.DS.'devices.'.$baseCode.'.sql';
		if( !preg_match('/^\w+$/D', $baseCode) || !preg_match('/^\w+$/D', $tableNme) || !preg_match('/^\w+$/D', $userTble) || !is_file($sqlFichr) ){
			$this->stopWithReason('No devices table created: there is no devices schema for a database of type "'.$baseCode.'"', self::EXIT_DENY);
		}
		if( $dtbsHndr===null ){
			$this->stopWithReason('No devices table created: the devices need an SQL database', self::EXIT_DENY);
		}
		if( $dtbsHndr->getInfoOfTable($tableNme)!==false ){
			return;
		}
		// The schema names its tables 'devices' and 'users' : they are given the configured names
		$makeStmt = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$dtbsHndr->quoteFieldName($tableNme), strval(file_get_contents($sqlFichr)), 1);
		$makeStmt = preg_replace('/REFERENCES \S+/', 'REFERENCES '.$dtbsHndr->quoteFieldName($userTble), strval($makeStmt), 1);
		if( !$dtbsHndr->sendBuiltQuery(strval($makeStmt), true) ){
			$this->stopWithReason('No devices table created: '.strval($dtbsHndr->getLastErrText()));
		}
		$this->printerObj->writeShellLine('Table '.$tableNme.' created.');
		$this->logShellChange('Deploy: devices table '.$tableNme.' created');
	}

	/**
	 * Creates the users table of the native userbase when it is missing,
	 * from the users.<type>.sql file of conf/schema, and gives it the
	 * basic users.
	 *
	 * @param Array $configValues Config block of the native userbase.
	 * @return Array The passwords made, by user; empty when the table already holds users and was left as it is.
	 *
	 * @throws ShellStop When the table cannot be created, read or written.
	 * @throws \RuntimeException When the database is not configured or does not answer.
	 */
	protected function makeUsersTable( Array $configValues ): Array {
		$tableNme = strval($configValues['useTable'] ?? 'users');
		$dtbsHndr = $this->openUsersTable($configValues);
		$qotdTble = $dtbsHndr->quoteFieldName($tableNme);
		$rowsList = $dtbsHndr->sendBuiltQuery('SELECT COUNT(*) AS '.$dtbsHndr->quoteFieldName('usrCount').' FROM '.$qotdTble.';') ? $dtbsHndr->readLastResult(true) : false;
		if( !is_array($rowsList) ){
			$this->stopWithReason('No user created: the table cannot be read: '.strval($dtbsHndr->getLastErrText()));
		}
		if( intval($rowsList[0]['usrCount'] ?? 0)>0 ){
			$this->printerObj->writeShellLine('The table '.$tableNme.' already holds users: nothing was changed.');
			return Array();
		}
		// The rules of the userbase are those of its block, except the length : the passwords made here are long enough for any
		$natvAuth = new Native( Array('minChars'=>1) + $configValues );
		$passList = Array();
		$addSttmt = 'INSERT INTO '.$qotdTble.' ('.$dtbsHndr->quoteFieldName('systemName').', '.$dtbsHndr->quoteFieldName('passwdHash').', '.$dtbsHndr->quoteFieldName('accessRole').', '.$dtbsHndr->quoteFieldName('userStatus').") VALUES (?, '!', ?, 'approved');";
		$acctList = $this->promptAccounts();
		$dtbsHndr->addTransaction();
		foreach( $acctList as $oneNme => $oneRle ){
			$passList[$oneNme] = bin2hex(random_bytes(10));
			// The row is first written with a hash nothing matches, then given its password
			if( !$dtbsHndr->sendBuiltQuery($addSttmt, true, Array($oneNme, $oneRle)) || !$natvAuth->savePassOfUser($oneNme, $passList[$oneNme]) ){
				$dtbsHndr->dropWholeTrnsc();
				$this->stopWithReason('User '.$oneNme.' cannot be written; nothing was changed');
			}
		}
		$dtbsHndr->setTransaction();
		return $passList;
	}

	/**
	 * Gives the database of the native userbase, its users table created
	 * from the users.<type>.sql file of conf/schema when it is missing.
	 *
	 * @param Array $configValues Config block of the native userbase.
	 * @return DbHandlr The handler of that database.
	 *
	 * @throws ShellStop When there is no schema for the database, or the table cannot be created.
	 * @throws \RuntimeException When the database is not configured or does not answer.
	 */
	protected function openUsersTable( Array $configValues ): DbHandlr {
		$baseName = strval($configValues['linkName'] ?? '');
		$tableNme = strval($configValues['useTable'] ?? 'users');
		$baseCode = strval(DBCon::readDbaseConfg($baseName)['dbEngine']);
		$sqlFichr = TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.'.$baseCode.'.sql';
		if( !preg_match('/^\w+$/D', $baseCode) || !preg_match('/^\w+$/D', $tableNme) || !is_file($sqlFichr) ){
			$this->stopWithReason('No users table: there is no users schema for a database of type "'.$baseCode.'"', self::EXIT_DENY);
		}
		$dtbsHndr = DBCon::linkNamedDbCon($baseName)->readConnection();
		if( $dtbsHndr===null ){
			$this->stopWithReason('No users table: the native userbase needs an SQL database', self::EXIT_DENY);
		}
		if( $dtbsHndr->getInfoOfTable($tableNme)===false ){
			// The schema names its table 'users' : it is given the configured name
			$makeStmt = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$dtbsHndr->quoteFieldName($tableNme), strval(file_get_contents($sqlFichr)), 1);
			if( !$dtbsHndr->sendBuiltQuery(strval($makeStmt), true) ){
				$this->stopWithReason('No users table: it cannot be created: '.strval($dtbsHndr->getLastErrText()));
			}
			$this->printerObj->writeShellLine('Table '.$tableNme.' created.');
			$this->logShellChange('Deploy: users table '.$tableNme.' created');
		}
		return $dtbsHndr;
	}
}
