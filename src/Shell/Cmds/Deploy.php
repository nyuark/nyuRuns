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
	public function describeInLine(): String {
		return 'Installs the project: server check, local files, secrets, users, health check';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
		return Array(
			'complete'=>Array('args'=>'[--projKind=<allInOne|authServ|dataServ|openSite>] [--useStore=<nativeDb|userFile|none>] [--sendWith=<selfSmtp|phpMailr|emlFiles|none>] [--signupOn] [--noPrompt]', 'about'=>'the whole sequence: servr, files, questions, secrets, users, check'),
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
		$chckObj = new HealthChk();
		$chckObj->validateServer();
		$this->printerObj->printShellRows( Array('check', 'state', 'found'), $chckObj->rowsFoundArray() );
		if( $chckObj->validateFailed() ){
			$this->terminateShell('The server cannot run the project: nothing was installed', self::EXIT_FLAW);
		}
		// The settings of the database are made with the native userbase only : a project in the users file needs none
		$this->makeLocalFiles( Array('.env', 'conf/access.json') );
		$projKnd = $this->queryProfilTxt();
		$storNme = $this->querySetupsTxt($projKnd);
		// The access mails are those of the native userbase : asked right after its store, and of no other
		$mailTyp = strval( $this->optionsObj->retrieveOption('sendWith', '') );
		if( $storNme=='nativeDb' ){
			$mailTyp = $this->queryMailerTxt();
		}elseif( $mailTyp!=='' && strcasecmp($mailTyp, 'none')!=0 ){
			$this->terminateShell('The access mails need the native userbase (nativeDb): --sendWith does not apply', self::EXIT_HELP);
		}
		// The secrets that are still empty are made; those in use are left alone
		$confCmd = new Config($this->optionsObj, $this->printerObj, $this->readStream);
		// An open site logs nobody in : it needs no secret
		$secrLst = ($projKnd=='openSite') ? Array() : Array('jwtToken'=>'secret or keys of the tokens', 'keysList'=>'API key', 'markSecr'=>'key of the login marks');
		// The 2FA secrets of the users are stored encrypted : their key is made with the store
		if( $storNme!='none' ){
			$secrLst['tfaCrypt'] = 'key of the 2FA secrets';
		}
		foreach( $secrLst as $kndNme => $kndLbl ){
			try{
				$confCmd->generateSecret($kndNme, true);
			}catch( \RuntimeException $e ){
				$this->printerObj->printShellLine( 'No '.$kndLbl.' made: '.$e->getMessage() );
			}
		}
		Console::loadConfigLogs();
		if( $storNme!='none' ){
			try{
				$this->makeBasicUsers($storNme, '');
			}catch( ShellStop $e ){
				// The health check tells what is missing : the installation goes on to it
				$this->printerObj->printShellFail( $e->getMessage() );
			}
		}
		// An open site given a store : the presets it opened are closed again, before the map is asked
		if( $storNme!='none' ){
			$this->closePublicSet();
		}
		// Asked last : the presets served, and where (conf/doorways.lst)
		$this->queryDoorsList($projKnd);
		// A config cache brought along, or left by a former installation, is not the config of this one
		ConfCache::clearConfCache();
		$this->traceShellDone('Deploy: installation run as '.$projKnd.', users in '.$storNme.( $storNme=='nativeDb' ? ', access mails '.$mailTyp : '' ));
		return $this->showCheckCount();
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
		$gvnAddr = strval($this->optionsObj->shiftFirstWord() ?? '');
		$gvnAddr = ($gvnAddr!=='') ? $gvnAddr : $this->queryAnswerTxt('Send the test mail to');
		if( $gvnAddr==='' ){
			$this->terminateShell('Missing argument: the address the test mail is sent to', self::EXIT_HELP);
		}
		return $this->sendsTestEmail($gvnAddr) ? self::EXIT_DONE : self::EXIT_FAIL;
	}

	/**
	 * Checks the server alone.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	public function invokeTestHost(): Int {
		$chckObj = new HealthChk();
		$chckObj->validateServer();
		$chckObj->checkFldrWrite();
		$this->printerObj->printShellRows( Array('check', 'state', 'found'), $chckObj->rowsFoundArray() );
		return $chckObj->validateFailed() ? self::EXIT_FLAW : self::EXIT_DONE;
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
		$blckNme = strval($this->optionsObj->shiftFirstWord() ?? '');
		$storNme = strval( $this->optionsObj->retrieveOption('useStore', strval($this->accessSettings('loginSet')['authWith'] ?? '')) );
		if( !in_array($storNme, Array('nativeDb', 'userFile'), true) ){
			$this->terminateShell('The login uses neither the native userbase nor the users file: name the store with --useStore=nativeDb or --useStore=userFile', self::EXIT_HELP);
		}
		$this->makeBasicUsers($storNme, $blckNme);
		return self::EXIT_DONE;
	}

	/**
	 * Runs the health check.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	public function invokeCheckAll(): Int {
		return $this->showCheckCount();
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
		$trgtNme = $this->needsShellWord('the store the users go to: nativeDb or userFile');
		if( !in_array($trgtNme, Array('nativeDb', 'userFile'), true) ){
			$this->terminateShell('Not a store the users can be moved to: '.$trgtNme, self::EXIT_HELP);
		}
		$lgnConf = $this->accessSettings('loginSet');
		$srcName = strval($lgnConf['authWith'] ?? '');
		if( $srcName==$trgtNme ){
			$this->terminateShell('The login already uses '.$trgtNme.': nothing to move', self::EXIT_DENY);
		}
		if( !in_array($srcName, Array('nativeDb', 'userFile'), true) ){
			$this->terminateShell('The login uses neither the native userbase nor the users file: there are no users to move', self::EXIT_DENY);
		}
		$srcConf = $this->accessSettings( strval($lgnConf['authConf'] ?? $srcName) );
		$usrList = ($srcName=='nativeDb') ? $this->fromTableArray($srcConf) : $this->fromFilesArray($srcConf);
		if( empty($usrList) ){
			$this->terminateShell('The '.$srcName.' store holds no users: nothing to move', self::EXIT_DENY);
		}
		// The store moved to gets its block, and the native userbase its database, before anything is checked
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$accPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$treeObj = $wrtrObj->importJsonsObj($accPath);
		if( !isset($treeObj->$trgtNme) ){
			$treeObj->$trgtNme = (object)self::STOR_CONF[$trgtNme];
			$wrtrObj->saveJsonConfig($accPath, $treeObj);
		}
		if( $trgtNme=='nativeDb' && !$this->optionsObj->validateOption('noPrompt') ){
			$this->queryDatabases($wrtrObj);
		}
		Console::loadConfigLogs();
		$dstConf = $this->accessSettings($trgtNme);
		$this->checkStoreVoid($trgtNme, $dstConf);
		list($lostLst, $devcIds) = $this->lostItemsArray($srcName, $usrList);
		$usrList = $this->shapeUsersList($usrList, $srcConf, $dstConf);
		$this->printerObj->printShellPair('from', $srcName);
		$this->printerObj->printShellPair('to', $trgtNme);
		$this->printerObj->printShellPair('users', count($usrList));
		if( !empty($lostLst) ){
			$this->printerObj->printShellRows( Array('not kept', 'details'), $lostLst );
		}
		if( !$this->queryAgreedFlg('Move '.count($usrList).' users to '.$trgtNme.' and point the login at it?') ){
			$this->terminateShell('Nothing was moved', self::EXIT_DENY);
		}
		if( $trgtNme=='nativeDb' ){
			$this->saveUsersTable($dstConf, $usrList);
		}else{
			$this->writeUsersFile($dstConf, $usrList);
		}
		// Devices left behind would still pass on users the project no longer manages
		$dvcAuth = new DeviceAuth( $this->accessSettings('dvcToken') );
		foreach( $devcIds as $devcId ){
			$dvcAuth->remUserDevcFlg($devcId);
		}
		// The login is pointed at the store only once every user is in it
		$treeObj = $wrtrObj->importJsonsObj($accPath);
		if( !isset($treeObj->loginSet) || !is_object($treeObj->loginSet) ){
			$treeObj->loginSet = new \stdClass();
		}
		$treeObj->loginSet->authWith = $trgtNme;
		$treeObj->loginSet->authConf = $trgtNme;
		$wrtrObj->saveJsonConfig($accPath, $treeObj);
		Console::loadConfigLogs();
		$this->traceShellDone('Deploy: '.count($usrList).' users moved from '.$srcName.' to '.$trgtNme.( empty($devcIds) ? '' : ', '.count($devcIds).' devices revoked' ).', the login points at '.$trgtNme);
		$this->printerObj->printShellPair('moved', count($usrList));
		$this->printerObj->printShellLine('The login now uses '.$trgtNme.'; the '.$srcName.' store is left as it was.');
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
	protected function fromTableArray( Array $configValues ): Array {
		$tblName = strval($configValues['useTable'] ?? 'users');
		try{
			$dbHndlr = DBCon::linkNamedDbCon( strval($configValues['linkName'] ?? '') )->readConnection();
		}catch( \Throwable $e ){
			$this->terminateShell('The users cannot be read: '.$e->getMessage());
		}
		if( $dbHndlr===null || !preg_match('/^\w+$/D', $tblName) ){
			$this->terminateShell('The users cannot be read: the native userbase needs an SQL database and a table named with letters and digits', self::EXIT_DENY);
		}
		$fldList = array_map( Array($dbHndlr, 'quoteFieldName'), array_merge(UserFile::FLDS_LIST, Array('actvtnCode', 'recovrCode', 'twoFactWay')) );
		if( !$dbHndlr->sendBuiltQuery('SELECT '.implode(', ', $fldList).' FROM '.$dbHndlr->quoteFieldName($tblName).' ORDER BY '.$dbHndlr->quoteFieldName('id_user').';') ){
			$this->terminateShell('The users cannot be read: '.strval($dbHndlr->finalErrorText()));
		}
		$rowList = $dbHndlr->readLastResult(true);
		return is_array($rowList) ? $rowList : Array();
	}

	/**
	 * Reads every user of the users file, hashes and secrets included.
	 *
	 * @param Array $configValues Config block of the users file.
	 * @return Array The users, as the file lists them.
	 *
	 * @throws ShellStop When the file cannot be used.
	 */
	protected function fromFilesArray( Array $configValues ): Array {
		$fileObj = new UserFile( $configValues, new ConfFile(TOP_DIR.DS.'temp'.DS.'archives') );
		$usrList = $fileObj->readUsersArray();
		if( $usrList===false ){
			$this->terminateShell('The users cannot be read: '.$fileObj->finalErrorText());
		}
		return $usrList;
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
			$userNum = count( $this->fromFilesArray($configValues) );
		}else{
			$tblName = strval($configValues['useTable'] ?? 'users');
			try{
				$dbHndlr = DBCon::linkNamedDbCon( strval($configValues['linkName'] ?? '') )->readConnection();
			}catch( \Throwable $e ){
				$this->terminateShell('Nothing was moved: '.$e->getMessage(), self::EXIT_DENY);
			}
			if( $dbHndlr===null || !preg_match('/^\w+$/D', $tblName) ){
				$this->terminateShell('Nothing was moved: the native userbase needs an SQL database and a table named with letters and digits', self::EXIT_DENY);
			}
			$userNum = 0;
			if( $dbHndlr->tableInfoArray($tblName)!==false ){
				$rowList = $dbHndlr->sendBuiltQuery('SELECT COUNT(*) AS '.$dbHndlr->quoteFieldName('usrCount').' FROM '.$dbHndlr->quoteFieldName($tblName).';') ? $dbHndlr->readLastResult(true) : false;
				if( !is_array($rowList) ){
					$this->terminateShell('Nothing was moved: the users table cannot be read: '.strval($dbHndlr->finalErrorText()));
				}
				$userNum = intval($rowList[0]['usrCount'] ?? 0);
			}
		}
		if( $userNum>0 ){
			$this->terminateShell('Nothing was moved: the '.$storeOfUsers.' store already holds users', self::EXIT_DENY);
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
	protected function lostItemsArray( String $pageFileName, Array $usersRecords ): Array {
		if( $pageFileName!='nativeDb' ){
			return Array( Array(), Array() );
		}
		$lostLst = Array();
		$pending = array_column( array_filter($usersRecords, function( Array $usrValuesArr ): Bool {
			return ($usrValuesArr['userStatus'] ?? '')=='awaiting' && strval($usrValuesArr['actvtnCode'] ?? '')!=='';
		}), 'systemName' );
		if( !empty($pending) ){
			$lostLst[] = Array('activation codes', implode(', ', $pending).': pending until made active, or given a password, with accounts');
		}
		$rsetLst = array_column( array_filter($usersRecords, function( Array $usrValuesArr ): Bool {
			return strval($usrValuesArr['recovrCode'] ?? '')!=='';
		}), 'systemName' );
		if( !empty($rsetLst) ){
			$lostLst[] = Array('reset codes', implode(', ', $rsetLst).': the resets asked are forgotten, the passwords kept');
		}
		$rvwList = array_column( array_filter($usersRecords, function( Array $usrValuesArr ): Bool {
			return ($usrValuesArr['userStatus'] ?? '')=='inReview';
		}), 'systemName' );
		if( !empty($rvwList) ){
			$lostLst[] = Array('sign-ups to review', implode(', ', $rvwList).': kept in review, which the users file cannot approve; account freeUser makes them active');
		}
		// A code mailed at each login is written in the store : the users file, only read, cannot keep one
		$mailLst = array_column( array_filter($usersRecords, function( Array $usrValuesArr ): Bool {
			return ($usrValuesArr['twoFactWay'] ?? null)==='emailOtp';
		}), 'systemName' );
		if( !empty($mailLst) ){
			$this->terminateShell('Nothing was moved: codes by mail need the native userbase ('.implode(', ', $mailLst).'); "account setTwoFa <user> --removeIt" takes them away', self::EXIT_DENY);
		}
		$lostLst[] = Array('presence and 2FA steps', 'the users file is only read: last seen, failed attempts and the step of the last 2FA code are not kept');
		$dvcConf = $this->accessSettings('dvcToken');
		$dvcRows = empty($dvcConf) ? Array() : (new DeviceAuth($dvcConf))->lstUserDevices();
		$devcIds = Array();
		$devcNms = Array();
		foreach( is_array($dvcRows) ? $dvcRows : Array() as $dvcRow ){
			if( $dvcRow['revokeDate']===null ){
				$devcIds[] = intval($dvcRow['id_device']);
				$devcNms[] = $dvcRow['systemName'].'/'.$dvcRow['deviceName'];
			}
		}
		if( !empty($devcIds) ){
			if( !$this->optionsObj->validateOption('dropsAll') ){
				$this->terminateShell('Nothing was moved: active devices need the native userbase ('.implode(', ', $devcNms).'); --dropsAll revokes them', self::EXIT_DENY);
			}
			$lostLst[] = Array('devices', implode(', ', $devcNms).': revoked');
		}
		return Array( $lostLst, $devcIds );
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
	protected function shapeUsersList( Array $usersRecords, Array $sourceConfig, Array $targetConfig ): Array {
		$srcSeal = strval($sourceConfig['tfaCrypt'] ?? '');
		$trgtSel = strval($targetConfig['tfaCrypt'] ?? '');
		$lastNum = max( array_merge(Array(0), array_map('intval', array_column($usersRecords, 'id_user'))) );
		$nopsLst = Array();
		$readyLs = Array();
		foreach( $usersRecords as $usrRow ){
			$userRow = Array();
			foreach( UserFile::FLDS_LIST as $fldNme ){
				if( isset($usrRow[$fldNme]) && $usrRow[$fldNme]!=='' ){
					$userRow[$fldNme] = $usrRow[$fldNme];
				}
			}
			if( strval($userRow['passwdHash'] ?? '')==='' ){
				$nopsLst[] = strval($usrRow['systemName'] ?? '');
				continue;
			}
			$userRow['id_user'] = (intval($userRow['id_user'] ?? 0)>0) ? intval($userRow['id_user']) : ++$lastNum;
			$userRow['accessRole'] = $userRow['accessRole'] ?? Checker::ROLE_LIST[0];
			$userRow['userStatus'] = $userRow['userStatus'] ?? 'approved';
			if( isset($userRow['twoFactKey']) && $srcSeal!==$trgtSel ){
				$plainTx = Totp::openSecretText( strval($userRow['twoFactKey']), $srcSeal );
				if( $plainTx===null ){
					$this->terminateShell('Nothing was moved: the 2FA secret of user '.json_encode($userRow['systemName']).' cannot be read with the key of its store', self::EXIT_DENY);
				}
				$userRow['twoFactKey'] = Totp::sealSecretText($plainTx, $trgtSel);
			}
			$readyLs[] = $userRow;
		}
		if( !empty($nopsLst) ){
			$this->terminateShell('Nothing was moved: users without a password hash ('.implode(', ', $nopsLst).'); give them a password with accounts first', self::EXIT_DENY);
		}
		return $readyLs;
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
			$dbHndlr = $this->openUsersTable($configValues);
			$baseTyp = strval(DBCon::baseSetupArray( strval($configValues['linkName'] ?? '') )['dbEngine']);
		}catch( ShellStop $e ){
			throw $e;
		}catch( \Throwable $e ){
			$this->terminateShell('Nothing was moved: '.$e->getMessage());
		}
		$qtdTble = $dbHndlr->quoteFieldName( strval($configValues['useTable'] ?? 'users') );
		$dbHndlr->newTransaction();
		foreach( $usersRecords as $usrRow ){
			$addStmt = 'INSERT INTO '.$qtdTble.' ('.implode(', ', array_map(Array($dbHndlr, 'quoteFieldName'), array_keys($usrRow))).') VALUES ('.implode(', ', array_fill(0, count($usrRow), '?')).');';
			if( !$dbHndlr->sendBuiltQuery($addStmt, true, array_values($usrRow)) ){
				$errText = strval($dbHndlr->finalErrorText());
				$dbHndlr->delTransaction();
				$this->terminateShell('Nothing was moved: user '.json_encode($usrRow['systemName']).' cannot be written: '.$errText);
			}
		}
		// The identifiers were given : PostgreSQL is told to count on from the highest
		if( $baseTyp=='postgres' && !$dbHndlr->sendBuiltQuery('SELECT setval(pg_get_serial_sequence(?, \'id_user\'), (SELECT MAX('.$dbHndlr->quoteFieldName('id_user').') FROM '.$qtdTble.'));', false, Array($qtdTble)) ){
			$errText = strval($dbHndlr->finalErrorText());
			$dbHndlr->delTransaction();
			$this->terminateShell('Nothing was moved: the identifiers cannot be set: '.$errText);
		}
		$dbHndlr->setTransaction();
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
		$fileObj = new UserFile( $configValues, new ConfFile(TOP_DIR.DS.'temp'.DS.'archives') );
		if( !$fileObj->fillEmptyStore($usersRecords) ){
			$this->terminateShell('Nothing was moved: '.$fileObj->finalErrorText());
		}
	}

	/**
	 * Runs the health check and writes its rows.
	 *
	 * @return Int EXITDONE, or EXITFLAW when a check failed.
	 */
	protected function showCheckCount(): Int {
		$chckObj = new HealthChk();
		$rowList = $chckObj->runChecksArray();
		$this->printerObj->printShellRows( Array('check', 'state', 'found'), $rowList );
		$sttsLst = array_count_values( array_column($rowList, 1) );
		$this->printerObj->printShellPair('fail', intval($sttsLst['fail'] ?? 0));
		$this->printerObj->printShellPair('warn', intval($sttsLst['warn'] ?? 0));
		return $chckObj->validateFailed() ? self::EXIT_FLAW : self::EXIT_DONE;
	}

	/**
	 * Creates each local file that is missing from its example. The file
	 * of the secrets is made readable by its owner only.
	 *
	 * @param Array $filesToBuild The local files to make, as DISTLIST names them; all of them when empty.
	 */
	protected function makeLocalFiles( Array $filesToBuild=Array() ): Void {
		$rowList = Array();
		foreach( self::DIST_LIST as $filNme => $target ){
			if( !empty($filesToBuild) && !in_array($filNme, $filesToBuild, true) ){
				continue;
			}
			$filPath = TOP_DIR.DS.str_replace('/', DS, $filNme);
			$dstPath = TOP_DIR.DS.str_replace('/', DS, $target);
			if( is_file($filPath) ){
				$rowList[] = Array($filNme, 'kept');
			}elseif( !is_file($dstPath) ){
				$rowList[] = Array($filNme, 'not created: its example '.$target.' is missing');
			}elseif( (function() use ( $dstPath, $filPath ): Bool {
				// Made readable by its owner only, from its first byte: the file will hold secrets
				$prevMsk = umask(0077);
				try{
					return @copy($dstPath, $filPath);
				}finally{
					umask($prevMsk);
				}
			})() ){
				// The secrets are for the owner alone; the access config is read by the group of the web server too
				$secRgts = Array('.env'=>0600, 'conf/access.json'=>0640);
				@chmod($filPath, $secRgts[$filNme] ?? (0666 & ~umask()));
				$rowList[] = Array($filNme, 'created from '.$target);
				$this->traceShellDone('Deploy: '.$filNme.' created from '.$target);
			}else{
				$rowList[] = Array($filNme, 'not created: its folder cannot be written');
			}
		}
		$this->printerObj->printShellRows( Array('file', 'state'), $rowList );
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
	protected function queryDoorsList( String $profileNamed ): Void {
		// The lines of a map already written are proposed as they are, those for any host only : a line naming a host is kept apart
		try{
			$rowList = \nyu\Runner::readDoorsArray();
		}catch( \RuntimeException $e ){
			$this->terminateShell( 'Nothing was installed further: '.$e->getMessage(), self::EXIT_DENY );
		}
		$hostLst = Array();
		$dfltLst = Array();
		foreach( $rowList as $oneRow ){
			if( $oneRow['hostName']==='' ){
				$dfltLst[] = $oneRow['basePath'].'='.$oneRow['presetId'];
			}else{
				$hostLst[] = $oneRow;
			}
		}
		$gvnText = $this->queryAnswerTxt( 'Presets to serve, and where (basePath=preset, comma-separated; a host is given with setting entryPts --hostName)', empty($dfltLst) ? ( $profileNamed=='openSite' ? '/=statics' : '/=default' ) : implode(', ', $dfltLst) );
		$newList = $hostLst;
		foreach( array_filter(array_map('trim', explode(',', $gvnText))) as $onePar ){
			$pairArr = array_map('trim', explode('=', $onePar, 2));
			$baseTxt = '/'.trim($pairArr[0], '/');
			$prstNme = strval($pairArr[1] ?? '');
			if( !preg_match('#^/[\w.\-/]*$#D', $baseTxt) || !preg_match('/^\w+$/D', $prstNme) ){
				$this->terminateShell( 'Not a line of the presets served: '.$onePar.' (basePath=preset)', self::EXIT_DENY );
			}
			if( strcasecmp($prstNme, \nyu\Setup\Preset::XMPL_NAME)==0 ){
				$this->terminateShell( 'The preset '.\nyu\Setup\Preset::XMPL_NAME.' only explains the keys of a preset, and is never loaded: it serves nothing', self::EXIT_DENY );
			}
			try{
				new \nyu\Setup\Preset($prstNme);
			}catch( \RuntimeException $e ){
				$this->terminateShell( 'Not a preset to serve: '.$e->getMessage(), self::EXIT_DENY );
			}
			$newList[] = Array('hostName'=>'', 'basePath'=>$baseTxt, 'presetId'=>$prstNme);
		}
		if( empty($newList) ){
			$this->terminateShell( 'No preset to serve: give one line at least, /=default', self::EXIT_DENY );
		}
		if( $profileNamed=='openSite' ){
			$this->openPresetsLst($newList);
		}
		(new Config($this->optionsObj, $this->printerObj, $this->readStream))->writeDoorsList($newList);
		$lineLst = Array();
		foreach( $newList as $oneRow ){
			$lineLst[] = $oneRow['hostName'].$oneRow['basePath'].'='.$oneRow['presetId'];
		}
		$this->traceShellDone( 'Deploy: presets served '.json_encode($lineLst, JSON_UNESCAPED_SLASHES) );
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
		$openLst = Array();
		foreach( array_unique(array_column($doorwaysRows, 'presetId')) as $prstId ){
			try{
				$setpObj = (new \nyu\Setup\Preset($prstId))->newSetupLoader();
				$rteConf = $setpObj->getRegistryKey('dispatch');
				$hasActn = (new \nyu\Tools\Router( is_array($rteConf) ? $rteConf : Array(), \nyu\Shell\ActCaller::makeRequestArr('GET', '/')[0] ))->reachesActions();
				$accConf = $setpObj->setAccessArray();
			}catch( \Throwable $e ){
				$this->terminateShell('Nothing was opened: the preset '.$prstId.' cannot be read: '.$e->getMessage(), self::EXIT_DENY);
			}
			if( $hasActn ){
				$this->terminateShell('Nothing was opened: the preset '.$prstId.' can reach an action, which an open site never makes public; serve statics, or a preset that serves no Run, or open the Run on purpose with openList in a preset that checks access', self::EXIT_DENY);
			}
			if( ($accConf['required'] ?? true)!==false || ($accConf['isPublic'] ?? false)!==true ){
				$openLst[] = $prstId;
			}
		}
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		foreach( $openLst as $prstId ){
			$rpsPath = TOP_DIR.DS.'conf'.DS.'presets'.DS.$prstId.'.rps';
			try{
				$treeObj = $wrtrObj->importJsonsObj($rpsPath);
			}catch( \RuntimeException $e ){
				$this->terminateShell('The preset '.$prstId.' cannot be opened: '.$e->getMessage().' (a preset holding comments is opened by hand)', self::EXIT_DENY);
			}
			if( !isset($treeObj->security) || !is_object($treeObj->security) ){
				$treeObj->security = new \stdClass();
			}
			$treeObj->security->required = false;
			$treeObj->security->isPublic = true;
			$wrtrObj->saveJsonConfig($rpsPath, $treeObj);
			$this->printerObj->printShellLine('Preset '.$prstId.' opened: "required": false, "isPublic": true.');
		}
		if( !empty($openLst) ){
			$this->traceShellDone('Deploy: presets opened for an open site ('.implode(', ', $openLst).')');
		}
		return $openLst;
	}

	/**
	 * Closes again the presets an open site had opened, when the project is
	 * given a store of users: when every preset of the map is open, each one
	 * marked "isPublic" is given "required": true, its "isPublic" removed,
	 * and named. A preset closed without a carrier refuses every request
	 * (500) until its owner sets one or opens it on purpose. Without a map,
	 * or with a preset of it that checks access, nothing is changed.
	 *
	 * @throws ShellStop When a preset cannot be written.
	 */
	protected function closePublicSet(): Void {
		try{
			$rowList = \nyu\Runner::readDoorsArray();
		}catch( \RuntimeException $e ){
			// The question of the map tells it
			return;
		}
		$pblcLst = Array();
		foreach( array_unique(array_column($rowList, 'presetId')) as $prstId ){
			try{
				$accConf = (new \nyu\Setup\Preset($prstId))->newSetupLoader()->setAccessArray();
			}catch( \Throwable $e ){
				return;
			}
			// A preset that checks access : the project was not an open site
			if( ($accConf['required'] ?? true)!==false ){
				return;
			}
			if( ($accConf['isPublic'] ?? false)===true ){
				$pblcLst[] = $prstId;
			}
		}
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		foreach( $pblcLst as $prstId ){
			$rpsPath = TOP_DIR.DS.'conf'.DS.'presets'.DS.$prstId.'.rps';
			try{
				$treeObj = $wrtrObj->importJsonsObj($rpsPath);
			}catch( \RuntimeException $e ){
				$this->terminateShell('The preset '.$prstId.' cannot be closed: '.$e->getMessage().'; close it by hand ("required": true)', self::EXIT_DENY);
			}
			$treeObj->security->required = true;
			unset($treeObj->security->isPublic);
			$wrtrObj->saveJsonConfig($rpsPath, $treeObj);
			$this->printerObj->printShellLine('Preset '.$prstId.' closed: the project has users now; open it again on purpose when it is meant to be public.');
		}
		if( !empty($pblcLst) ){
			Console::loadConfigLogs();
			$this->traceShellDone('Deploy: presets of the open site closed, a store of users given ('.implode(', ', $pblcLst).')');
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
	protected function querySetupsTxt( String $profileNamed ): String {
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$iniPath = TOP_DIR.DS.'conf'.DS.'system.ini';
		$accPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$currEnv = strval($wrtrObj->retrieveIniKey($iniPath, 'ENV_NAME') ?? '');
		if( $currEnv!='' ){
			$wantEnv = strtoupper( $this->queryAnswerTxt('Environment (DEVS, TEST, PREV, PROD)', $currEnv) );
			if( !in_array($wantEnv, Array('DEVS', 'TEST', 'PREV', 'PROD'), true) ){
				$this->terminateShell('Not an environment: '.$wantEnv, self::EXIT_DENY);
			}
			if( $wantEnv!=$currEnv ){
				$wrtrObj->changeIniValue($iniPath, 'ENV_NAME', $wantEnv);
			}
		}
		// A resource server accepts the tokens of another server, an open site logs nobody in : no users, nor a store to ask
		if( $profileNamed=='dataServ' || $profileNamed=='openSite' ){
			Console::loadConfigLogs();
			return 'none';
		}
		$lgnAuth = strval($this->accessSettings('loginSet')['authWith'] ?? '');
		$storNme = strval( $this->optionsObj->retrieveOption('useStore', in_array($lgnAuth, Array('nativeDb', 'userFile'), true) ? $lgnAuth : ( $profileNamed=='authServ' ? 'userFile' : 'none' )) );
		$storNme = $this->queryAnswerTxt('Store of the users ('.implode(', ', self::STOR_LIST).')', $storNme);
		// Matched whatever its letter case, kept as the list writes it
		foreach( self::STOR_LIST as $oneCod ){
			$storNme = (strcasecmp($oneCod, $storNme)==0) ? $oneCod : $storNme;
		}
		if( !in_array($storNme, self::STOR_LIST, true) ){
			$this->terminateShell('Not a store of users: '.$storNme, self::EXIT_DENY);
		}
		if( $storNme=='none' && $profileNamed=='authServ' ){
			$this->terminateShell('An authentication server (authServ) logs its users in: it needs a store (nativeDb or userFile)', self::EXIT_DENY);
		}
		// The login is pointed at the store chosen
		if( $storNme!='none' && $storNme!=$lgnAuth && is_file($accPath) ){
			$treeObj = $wrtrObj->importJsonsObj($accPath);
			if( !isset($treeObj->loginSet) || !is_object($treeObj->loginSet) ){
				$treeObj->loginSet = new \stdClass();
			}
			$treeObj->loginSet->authWith = $storNme;
			$treeObj->loginSet->authConf = $storNme;
			if( !isset($treeObj->$storNme) ){
				$treeObj->$storNme = (object)self::STOR_CONF[$storNme];
			}
			$wrtrObj->saveJsonConfig($accPath, $treeObj);
		}
		if( $storNme=='nativeDb' ){
			$this->makeLocalFiles( Array('conf/database.json') );
			if( !$this->optionsObj->validateOption('noPrompt') ){
				$this->queryDatabases($wrtrObj);
			}
		}
		Console::loadConfigLogs();
		return $storNme;
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
	 * presets it serves are opened with the map (see queryDoorsList).
	 *
	 * @return String The profile, one of KIND_LIST.
	 *
	 * @throws ShellStop When the answer is not a profile, or conf/access.json cannot be read.
	 */
	protected function queryProfilTxt(): String {
		$projKnd = strval( $this->optionsObj->retrieveOption('projKind', '') );
		$projKnd = ($projKnd!=='') ? $projKnd : $this->queryAnswerTxt('What this project is ('.implode(', ', self::KIND_LIST).')', self::KIND_LIST[0]);
		// Matched whatever its letter case, kept as the list writes it
		foreach( self::KIND_LIST as $oneCod ){
			$projKnd = (strcasecmp($oneCod, $projKnd)==0) ? $oneCod : $projKnd;
		}
		if( !in_array($projKnd, self::KIND_LIST, true) ){
			$this->terminateShell('Not a profile of a project: '.$projKnd, self::EXIT_DENY);
		}
		if( $projKnd=='allInOne' ){
			return $projKnd;
		}
		if( $projKnd!='authServ' && strval($this->optionsObj->retrieveOption('useStore', ''))!=='' ){
			$this->terminateShell( ($projKnd=='dataServ' ? 'A resource server (dataServ)' : 'An open site (openSite)').' has no users of its own: --useStore does not apply', self::EXIT_HELP );
		}
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$accPath = TOP_DIR.DS.'conf'.DS.'access.json';
		if( !is_file($accPath) ){
			$this->terminateShell('Nothing was installed: conf/access.json is missing, the profile cannot be written', self::EXIT_FAIL);
		}
		$treeObj = $wrtrObj->importJsonsObj($accPath);
		if( !isset($treeObj->loginSet) || !is_object($treeObj->loginSet) ){
			$treeObj->loginSet = new \stdClass();
		}
		// An open site has no users' store : the login checks nothing, and the presets it serves are opened with the map
		if( $projKnd=='openSite' ){
			unset($treeObj->loginSet->authWith, $treeObj->loginSet->authConf);
			$wrtrObj->saveJsonConfig($accPath, $treeObj);
			Console::loadConfigLogs();
			$this->traceShellDone('Deploy: conf/access.json written for the profile openSite (no users\' store)');
			return $projKnd;
		}
		// The tokens are the bond between the projects : the login carries them
		$blckNme = ( strval($treeObj->loginSet->carrWith ?? 'jwtToken')=='jwtToken' ) ? strval($treeObj->loginSet->carrConf ?? 'jwtToken') : 'jwtToken';
		$treeObj->loginSet->carrWith = 'jwtToken';
		$treeObj->loginSet->carrConf = $blckNme;
		if( !isset($treeObj->$blckNme) || !is_object($treeObj->$blckNme) ){
			$treeObj->$blckNme = new \stdClass();
		}
		$blckNod = $treeObj->$blckNme;
		$blckNod->signAlgo = 'RS256';
		if( $projKnd=='authServ' ){
			// Its tokens are signed here : a public key found alone is replaced by a pair of its own
			unset($blckNod->external);
		}else{
			$this->queryIssuerKey($blckNod, $blckNme);
			// Nothing is checked here : the login answers that the tokens are issued by another server
			unset($treeObj->loginSet->authWith, $treeObj->loginSet->authConf);
		}
		$this->queryTokenClms($blckNod);
		$wrtrObj->saveJsonConfig($accPath, $treeObj);
		Console::loadConfigLogs();
		$this->traceShellDone('Deploy: conf/access.json written for the profile '.$projKnd.' (block '.$blckNme.')');
		return $projKnd;
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
	protected function queryTokenClms( \stdClass $tokenConfObj ): Void {
		$isExtrn = ($tokenConfObj->external ?? false)===true;
		$nowIssr = is_string($tokenConfObj->issuerId ?? null) ? trim($tokenConfObj->issuerId) : '';
		$nowIssr = ($nowIssr=='' && !$isExtrn) ? strval(gethostname()) : $nowIssr;
		$tokenConfObj->issuerId = trim( $this->queryAnswerTxt($isExtrn ? 'Issuer of the authentication server\'s tokens (issuerId)' : 'Issuer of the tokens (issuerId)', $nowIssr) );
		$nowAdnc = $tokenConfObj->audience ?? Array();
		$nowAdnc = is_array($nowAdnc) ? implode(', ', array_map('strval', $nowAdnc)) : strval($nowAdnc);
		$adncTxt = $this->queryAnswerTxt($isExtrn ? 'Audience naming this project (audience, comma-separated)' : 'Projects that will accept its tokens (audience, comma-separated)', $nowAdnc);
		$tokenConfObj->audience = array_values( array_filter(array_map('trim', explode(',', $adncTxt)), 'strlen') );
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
	 * @param String    $blckNamedTxt Name of that block.
	 *
	 * @throws ShellStop When the file is missing, is not a public RSA key of JWT::RSAS_BITS bits at least, or cannot be copied.
	 */
	protected function queryIssuerKey( \stdClass $tokenConfObj, String $blckNamedTxt ): Void {
		$publNme = 'conf/keys/'.$blckNamedTxt.'.pub';
		$publPth = TOP_DIR.DS.str_replace('/', DS, $publNme);
		$srcName = trim( $this->queryAnswerTxt('File of the public key of the authentication server', is_file($publPth) ? $publNme : '') );
		// The path is read from where the command runs, or from the project
		$srcPath = is_file($srcName) ? $srcName : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, ltrim($srcName, '/\\'));
		if( $srcName=='' || !is_file($srcPath) ){
			$this->terminateShell('Nothing was installed: no file of the public key'.( $srcName!='' ? ' at '.json_encode($srcName) : ' given' ), self::EXIT_DENY);
		}
		if( !function_exists('openssl_pkey_get_public') ){
			$this->terminateShell('Nothing was installed: RS256 needs the openssl extension', self::EXIT_FAIL);
		}
		$pemText = strval(@file_get_contents($srcPath));
		$keyObjc = (strpos($pemText, 'PRIVATE KEY')===false) ? @openssl_pkey_get_public($pemText) : false;
		$keyInfo = ($keyObjc!==false) ? openssl_pkey_get_details($keyObjc) : false;
		if( !is_array($keyInfo) || ($keyInfo['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($keyInfo['bits'] ?? 0)<\nyu\Access\Carr\JWT::RSAS_BITS ){
			$this->terminateShell('Nothing was installed: '.json_encode($srcName).' is not a public RSA key of '.\nyu\Access\Carr\JWT::RSAS_BITS.' bits at least', self::EXIT_DENY);
		}
		$publTxt = strval($keyInfo['key']);
		$nowText = is_file($publPth) ? strval(@file_get_contents($publPth)) : '';
		if( $nowText!=='' && trim($nowText)!==trim($publTxt) && !$this->queryAgreedFlg('Replace the public key in use ('.$publNme.'): the tokens it checks are refused') ){
			$this->terminateShell('Nothing was installed: the public key in use is kept', self::EXIT_DENY);
		}
		if( trim($nowText)!==trim($publTxt) ){
			if( !is_dir(dirname($publPth)) ){
				@mkdir(dirname($publPth), 0700, true);
			}
			if( @file_put_contents($publPth, $publTxt, LOCK_EX)===false ){
				$this->terminateShell('Nothing was installed: '.$publNme.' cannot be written', self::EXIT_FAIL);
			}
			@chmod($publPth, 0644);
			$this->traceShellDone('Deploy: public key of the authentication server copied into '.$publNme);
		}
		$tokenConfObj->publFile = $publNme;
		$tokenConfObj->external = true;
		unset($tokenConfObj->privFile, $tokenConfObj->hmacSecr);
	}

	/**
	 * Asks the access mails, right after the native userbase is chosen:
	 * whether they are sent, through which type of account, the account
	 * (see queryAccntConf), the front-end link of the mails, whether the
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
	protected function queryMailerTxt(): String {
		$typeLst = array_keys(Mailer::TYPE_LIST);
		if( !class_exists(PHMail::MAIL_CLSS) ){
			$typeLst = array_values( array_diff($typeLst, Array('phpMailr')) );
		}
		$yesList = Array('y', 'yes');
		$typeNme = strval( $this->optionsObj->retrieveOption('sendWith', '') );
		if( $typeNme==='' ){
			if( !in_array(strtolower($this->queryAnswerTxt('Send the access mails (activation, reset, sign-ups)? (yes/no)', 'no')), $yesList, true) ){
				return 'none';
			}
			$typeNme = $this->queryAnswerTxt('Mail account type ('.implode(', ', $typeLst).')', $typeLst[0]);
		}
		if( strcasecmp($typeNme, 'none')==0 ){
			return 'none';
		}
		// Matched whatever its letter case, kept as the list writes it
		foreach( array_keys(Mailer::TYPE_LIST) as $oneCod ){
			$typeNme = (strcasecmp($oneCod, $typeNme)==0) ? $oneCod : $typeNme;
		}
		if( !isset(Mailer::TYPE_LIST[$typeNme]) ){
			$this->terminateShell('Not a type of mail account: '.$typeNme, self::EXIT_DENY);
		}
		if( !in_array($typeNme, $typeLst, true) ){
			$this->terminateShell('A phpMailr account needs the PHPMailer library, which is not installed (composer require phpmailer/phpmailer)', self::EXIT_DENY);
		}
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		$accPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$lgnConf = $this->accessSettings('loginSet');
		$blckNme = ( strval($lgnConf['authWith'] ?? '')=='nativeDb' ) ? strval($lgnConf['authConf'] ?? 'nativeDb') : 'nativeDb';
		$nowAcnt = $this->accessSettings($blckNme)['mailAcnt'] ?? '';
		$acntNme = $this->queryAccntConf( $wrtrObj, $typeNme, is_string($nowAcnt) ? $nowAcnt : '' );
		$treeObj = $wrtrObj->importJsonsObj($accPath);
		if( !isset($treeObj->$blckNme) || !is_object($treeObj->$blckNme) ){
			$treeObj->$blckNme = (object)self::STOR_CONF['nativeDb'];
		}
		$blckNod = $treeObj->$blckNme;
		$lnkTmpl = $this->queryAnswerTxt('Front-end link of the mails (empty for none)', is_string($blckNod->linkTmpl ?? null) ? $blckNod->linkTmpl : '');
		if( $lnkTmpl!=='' && ( !preg_match('#^https?://[^\s]+$#Di', $lnkTmpl) || strpos($lnkTmpl, '%sentCode%')===false ) ){
			$this->terminateShell('Not a front-end link: an http(s) address holding %sentCode% (and %mailKind%, %userName% as needed)', self::EXIT_DENY);
		}
		$blckNod->mailAcnt = $acntNme;
		$blckNod->linkTmpl = $lnkTmpl;
		$blckNod->signupOn = $this->optionsObj->validateOption('signupOn') || in_array(strtolower($this->queryAnswerTxt('Open the sign-ups? (yes/no)', 'no')), $yesList, true);
		$wrtrObj->saveJsonConfig($accPath, $treeObj);
		Console::loadConfigLogs();
		$this->traceShellDone('Deploy: access mails sent through the account '.json_encode($acntNme).' ('.$typeNme.'), sign-ups '.( $blckNod->signupOn ? 'open' : 'closed' ));
		$tstAddr = $this->queryAnswerTxt('Send a test mail to (empty for none)');
		if( $tstAddr!=='' ){
			// A test mail that does not go is told, and the installation goes on
			$this->sendsTestEmail($tstAddr);
		}
		return $typeNme;
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
	protected function queryAccntConf( ConfFile $writerObject, String $sendWithCode, String $accountLabel ): String {
		$mailPth = TOP_DIR.DS.'conf'.DS.'mailer.json';
		$treeObj = is_file($mailPth) ? $writerObject->importJsonsObj($mailPth) : new \stdClass();
		$nowVals = Array();
		foreach( get_object_vars($treeObj) as $oneKey => $oneVal ){
			if( strcasecmp($oneKey, 'sendWith')==0 ){
				$this->terminateShell('No mail account written: conf/mailer.json holds one account without a name; put it under a name first', self::EXIT_DENY);
			}
			if( $accountLabel!=='' && strcasecmp($oneKey, $accountLabel)==0 && is_object($oneVal) ){
				$nowVals = array_change_key_case( get_object_vars($oneVal), CASE_LOWER );
			}
		}
		$nowVals = array_map( 'strval', array_filter($nowVals, 'is_scalar') );
		$sendsIt = ($sendWithCode!='emlFiles');
		$noAsked = $this->optionsObj->validateOption('noPrompt');
		if( $sendsIt && $noAsked && ($nowVals['hostname'] ?? '')==='' ){
			$this->terminateShell('No mail account written: a '.$sendWithCode.' account needs its mail server, which --noPrompt cannot ask', self::EXIT_DENY);
		}
		$acntSet = array_fill_keys(Mailer::KEYS_LIST, '');
		$acntSet['sendWith'] = $sendWithCode;
		if( $sendsIt ){
			$acntSet['hostName'] = $this->queryAnswerTxt('Mail server host', $nowVals['hostname'] ?? '');
			$acntSet['portNumb'] = $this->queryAnswerTxt('Port (465 or 587)', ($nowVals['portnumb'] ?? '')!=='' ? $nowVals['portnumb'] : '587');
			if( $acntSet['hostName']==='' || !preg_match('/^\d{1,5}$/D', $acntSet['portNumb']) ){
				$this->terminateShell('No mail account written: a '.$sendWithCode.' account needs the host of its mail server, and a port number', self::EXIT_DENY);
			}
			$acntSet['userName'] = $this->queryAnswerTxt('Mail user', $nowVals['username'] ?? '');
			$nowPass = $nowVals['password'] ?? '';
			$envName = (strpos($nowPass, 'env:')===0 && strlen($nowPass)>4) ? substr($nowPass, 4) : 'NYU_MAIL_PASS';
			$gvnPass = $noAsked ? '' : $this->querySecretTxt('Mail password (Enter keeps the present one)');
			if( $gvnPass!=='' ){
				$writerObject->changeEnvValue(TOP_DIR.DS.'.env', $envName, $gvnPass);
			}
			// Never the password itself : the account names where it is kept
			$acntSet['passWord'] = ($gvnPass!=='' || $nowPass==='') ? 'env:'.$envName : $nowPass;
			$acntSet['waitSecs'] = ($nowVals['waitsecs'] ?? '')!=='' ? $nowVals['waitsecs'] : '10';
		}
		$acntSet['fromAddr'] = $this->queryAnswerTxt('Sender address', ($nowVals['fromaddr'] ?? '')!=='' ? $nowVals['fromaddr'] : ( $sendsIt ? '' : 'no-reply@example.com' ));
		try{
			Message::checkEmailAddr($acntSet['fromAddr']);
		}catch( MailFailure $e ){
			$this->terminateShell('No mail account written: the sender address is not one ('.$e->getMessage().')', self::EXIT_DENY);
		}
		$acntSet['fromName'] = $this->queryAnswerTxt('Sender name', ($nowVals['fromname'] ?? '')!=='' ? $nowVals['fromname'] : 'nyuRuns');
		$acntSet['copiesTo'] = $nowVals['copiesto'] ?? '';
		$acntNme = $this->queryAnswerTxt('Name of the mail account', ($accountLabel!=='') ? $accountLabel : 'nyuRuns');
		if( !preg_match('/^\w+$/D', $acntNme) ){
			$this->terminateShell('No mail account written: '.json_encode($acntNme).' is not a name of account (letters, digits, underscores)', self::EXIT_DENY);
		}
		// An account of that name, whatever its case, is the one replaced
		$nameKey = $acntNme;
		foreach( array_keys(get_object_vars($treeObj)) as $oneKey ){
			$nameKey = (strcasecmp(strval($oneKey), $acntNme)==0) ? strval($oneKey) : $nameKey;
		}
		$treeObj->$nameKey = (object)$acntSet;
		$writerObject->saveJsonConfig($mailPth, $treeObj);
		$this->printerObj->printShellLine('Mail account '.$nameKey.' ('.$sendWithCode.') written in conf/mailer.json.');
		$this->traceShellDone('Deploy: mail account '.json_encode($nameKey).' ('.$sendWithCode.') written in conf/mailer.json');
		return $nameKey;
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
	protected function sendsTestEmail( String $emailAddress ): Bool {
		// The config as it is now written, and no mailer built from a former one
		Console::loadConfigLogs();
		Mailer::clearMailCache();
		$lgnConf = $this->accessSettings('loginSet');
		$blckNme = ( strval($lgnConf['authWith'] ?? '')=='nativeDb' ) ? strval($lgnConf['authConf'] ?? 'nativeDb') : 'nativeDb';
		$acntNme = $this->accessSettings($blckNme)['mailAcnt'] ?? '';
		if( !is_string($acntNme) || $acntNme==='' ){
			$this->terminateShell('No test mail sent: the native userbase names no mail account ("mailAcnt" of the '.$blckNme.' block)', self::EXIT_DENY);
		}
		$typeNme = '';
		try{
			$typeNme = Mailer::acntSetupArray($acntNme)['sendWith'];
			$mailObj = Mailer::retrieveMailer($acntNme);
			$bodyTxt = 'This is a test mail of nyuRuns, sent by "php nyuRun install testMail" through the mail account '.$acntNme.'.'."\n\n".'Receiving it means the access mails (activation, reset, sign-ups) can be sent.'."\n";
			$failTxt = $mailObj->deliverEmlFlag($emailAddress, 'nyuRuns test mail', $bodyTxt) ? null : $mailObj->finalErrorText();
		}catch( MailFailure $e ){
			$failTxt = $e->getMessage();
		}
		if( $failTxt!==null ){
			$this->printerObj->printShellFail('Test mail not sent to '.$emailAddress.' through the account '.$acntNme.': '.$failTxt);
			return false;
		}
		$this->printerObj->printShellLine( (strcasecmp($typeNme, 'emlFiles')==0) ? 'Test mail to '.$emailAddress.' written as a file by the account '.$acntNme.' (emlFiles): nothing was sent.' : 'Test mail sent to '.$emailAddress.' through the account '.$acntNme.'.' );
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
	protected function queryDatabases( ConfFile $writerObject ): Void {
		$basePth = TOP_DIR.DS.'conf'.DS.'database.json';
		if( !is_file($basePth) ){
			return;
		}
		$treeObj = $writerObject->importJsonsObj($basePth);
		$nodeObj = $treeObj;
		if( !property_exists($treeObj, 'dbEngine') ){
			foreach( get_object_vars($treeObj) as $blkVal ){
				if( is_object($blkVal) ){
					$nodeObj = $blkVal;
					break;
				}
			}
		}
		$baseTyp = strtolower( $this->queryAnswerTxt('Database type (mysql_db, postgres, sqlitedb)', strval($nodeObj->dbEngine ?? 'mysql_db')) );
		if( !in_array($baseTyp, Array('mysql_db', 'postgres', 'sqlitedb'), true) ){
			$this->terminateShell('The native userbase works on mysql_db, postgres or sqlitedb, not on: '.$baseTyp, self::EXIT_DENY);
		}
		$nodeObj->dbEngine = $baseTyp;
		if( $baseTyp=='sqlitedb' ){
			$nodeObj->baseName = $this->queryAnswerTxt('Database file', strval($nodeObj->baseName ?? ''));
		}else{
			$nodeObj->hostName = $this->queryAnswerTxt('Database host', strval($nodeObj->hostName ?? 'localhost'));
			$nodeObj->portNumb = $this->queryAnswerTxt('Database port (empty for the usual one)', strval($nodeObj->portNumb ?? ''));
			$nodeObj->baseName = $this->queryAnswerTxt('Database name', strval($nodeObj->baseName ?? ''));
			$nodeObj->userName = $this->queryAnswerTxt('Database user', strval($nodeObj->userName ?? ''));
			$gvnPass = $this->querySecretTxt('Password of that user (Enter keeps the present one)');
			if( $gvnPass!=='' ){
				$nowPass = strval($nodeObj->passWord ?? '');
				$envName = (strpos($nowPass, 'env:')===0 && strlen($nowPass)>4) ? substr($nowPass, 4) : 'NYU_DB_PASS';
				$writerObject->changeEnvValue(TOP_DIR.DS.'.env', $envName, $gvnPass);
				$nodeObj->passWord = 'env:'.$envName;
			}
		}
		$writerObject->saveJsonConfig($basePth, $treeObj);
		$this->traceShellDone('Deploy: settings of the default database written');
	}

	/**
	 * Creates the basic users, one per role, each with a random password
	 * shown this once. In the native userbase, the users table is created
	 * first when it is missing. A store that already holds users is left
	 * as it is.
	 *
	 * @param String $storeOfUsers 'nativeDb' or 'userFile'.
	 * @param String $blckNamedTxt Block of conf/access.json holding the settings of the store; the one the login names, or the one named as the store, when empty.
	 *
	 * @throws ShellStop When the users cannot be created.
	 */
	protected function makeBasicUsers( String $storeOfUsers, String $blckNamedTxt ): Void {
		// Tables are made: the descriptions the config cache keeps (TBL_CACH) are no longer the whole story
		ConfCache::clearConfCache();
		$lgnConf = $this->accessSettings('loginSet');
		$blckNme = ($blckNamedTxt!='') ? $blckNamedTxt : ( strval($lgnConf['authWith'] ?? '')==$storeOfUsers ? strval($lgnConf['authConf'] ?? $storeOfUsers) : $storeOfUsers );
		$blckCfg = $this->accessSettings($blckNme);
		if( empty($blckCfg) && ($storeOfUsers=='nativeDb' || $blckNamedTxt!='') ){
			$this->terminateShell('No user created: conf/access.json has no "'.$blckNme.'" block', self::EXIT_DENY);
		}
		$passLst = Array();
		if( $storeOfUsers=='userFile' ){
			$fileObj = new UserFile( $blckCfg, new ConfFile(TOP_DIR.DS.'temp'.DS.'archives') );
			$usrList = $fileObj->listUsersNamed();
			if( $usrList===false ){
				$this->terminateShell('No user created: '.$fileObj->finalErrorText(), self::EXIT_FAIL);
			}
			if( !empty($usrList) ){
				$this->printerObj->printShellLine('The users file already holds users: nothing was changed.');
				return;
			}
			foreach( $this->queryUsersList() as $usrNme => $usrRle ){
				$passLst[$usrNme] = bin2hex(random_bytes(10));
				if( !$fileObj->enrolUsrString($usrNme, $usrRle, $passLst[$usrNme]) ){
					$this->terminateShell('User '.$usrNme.' cannot be written: '.$fileObj->finalErrorText(), self::EXIT_FAIL);
				}
			}
		}else{
			try{
				$passLst = $this->tableUserArray($blckCfg);
			}catch( ShellStop $e ){
				throw $e;
			}catch( \Throwable $e ){
				$this->terminateShell('No user created: '.$e->getMessage(), self::EXIT_FAIL);
			}
			if( empty($passLst) ){
				$this->makeDevicesTbl();
				return;
			}
		}
		// The accounts come in the order of USER_LIST: all of them, or its first and last (the simple version)
		$acntLst = array_combine( array_keys($passLst), (count($passLst)==count(self::USER_LIST)) ? array_values(self::USER_LIST) : array_merge(array_slice(array_values(self::USER_LIST), 0, 1), array_slice(array_values(self::USER_LIST), -1)) );
		$this->traceShellDone('Deploy: basic users created in '.$storeOfUsers.' ('.implode(', ', array_keys($acntLst)).')');
		$this->printerObj->printShellLine('Users created. Their passwords are shown this once:');
		foreach( $acntLst as $usrNme => $usrRle ){
			$this->printerObj->printShellOnce('Password of '.$usrNme.' ('.$usrRle.')', $passLst[$usrNme]);
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
	protected function queryUsersList(): Array {
		$acntLst = Array();
		$gvnName = strtolower(trim( $this->queryAnswerTxt('Accounts to create: simple ('.implode(' and ', array_keys(array_slice(self::USER_LIST, 0, 1, true) + array_slice(self::USER_LIST, -1, 1, true))).') or full ('.implode(', ', array_keys(self::USER_LIST)).')', 'simple') ));
		if( !in_array($gvnName, Array('simple', 'full'), true) ){
			$this->terminateShell('No user created: '.json_encode($gvnName).' is neither simple nor full', self::EXIT_DENY);
		}
		foreach( ($gvnName=='full') ? self::USER_LIST : array_slice(self::USER_LIST, 0, 1, true) + array_slice(self::USER_LIST, -1, 1, true) as $usrNme => $usrRle ){
			$gvnName = $this->queryAnswerTxt('Name of the '.$usrRle.' account', $usrNme);
			if( !preg_match(\nyu\Access\Auth\UserBase::NAME_RULE, $gvnName) || in_array(strtolower($gvnName), \nyu\Access\Auth\UserBase::NAME_DENY, true) ){
				$this->terminateShell('No user created: '.json_encode($gvnName).' is not a name a user can have', self::EXIT_DENY);
			}
			if( in_array(strtolower($gvnName), array_map('strtolower', array_keys($acntLst)), true) ){
				$this->terminateShell('No user created: two accounts cannot share the name '.json_encode($gvnName), self::EXIT_DENY);
			}
			$acntLst[$gvnName] = $usrRle;
		}
		return $acntLst;
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
		$dvcConf = $this->accessSettings('dvcToken');
		if( empty($dvcConf) ){
			return;
		}
		$tblName = strval($dvcConf['useTable'] ?? 'devices');
		$usrTble = strval($dvcConf['usersTbl'] ?? 'users');
		try{
			$baseNme = strval($dvcConf['linkName'] ?? '');
			$baseTyp = strval(DBCon::baseSetupArray($baseNme)['dbEngine']);
			$dbHndlr = DBCon::linkNamedDbCon($baseNme)->readConnection();
		}catch( \Throwable $e ){
			$this->terminateShell('No devices table created: '.$e->getMessage(), self::EXIT_FAIL);
		}
		$sqlFile = TOP_DIR.DS.'conf'.DS.'schema'.DS.'devices.'.$baseTyp.'.sql';
		if( !preg_match('/^\w+$/D', $baseTyp) || !preg_match('/^\w+$/D', $tblName) || !preg_match('/^\w+$/D', $usrTble) || !is_file($sqlFile) ){
			$this->terminateShell('No devices table created: there is no devices schema for a database of type "'.$baseTyp.'"', self::EXIT_DENY);
		}
		if( $dbHndlr===null ){
			$this->terminateShell('No devices table created: the devices need an SQL database', self::EXIT_DENY);
		}
		if( $dbHndlr->tableInfoArray($tblName)!==false ){
			return;
		}
		// The schema names its tables 'devices' and 'users' : they are given the configured names
		$makeSql = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$dbHndlr->quoteFieldName($tblName), strval(file_get_contents($sqlFile)), 1);
		$makeSql = preg_replace('/REFERENCES \S+/', 'REFERENCES '.$dbHndlr->quoteFieldName($usrTble), strval($makeSql), 1);
		if( !$dbHndlr->sendBuiltQuery(strval($makeSql), true) ){
			$this->terminateShell('No devices table created: '.strval($dbHndlr->finalErrorText()));
		}
		$this->printerObj->printShellLine('Table '.$tblName.' created.');
		$this->traceShellDone('Deploy: devices table '.$tblName.' created');
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
	protected function tableUserArray( Array $configValues ): Array {
		$tblName = strval($configValues['useTable'] ?? 'users');
		$dbHndlr = $this->openUsersTable($configValues);
		$qtdTble = $dbHndlr->quoteFieldName($tblName);
		$rowList = $dbHndlr->sendBuiltQuery('SELECT COUNT(*) AS '.$dbHndlr->quoteFieldName('usrCount').' FROM '.$qtdTble.';') ? $dbHndlr->readLastResult(true) : false;
		if( !is_array($rowList) ){
			$this->terminateShell('No user created: the table cannot be read: '.strval($dbHndlr->finalErrorText()));
		}
		if( intval($rowList[0]['usrCount'] ?? 0)>0 ){
			$this->printerObj->printShellLine('The table '.$tblName.' already holds users: nothing was changed.');
			return Array();
		}
		// The rules of the userbase are those of its block, except the length : the passwords made here are long enough for any
		$ntvAuth = new Native( Array('minChars'=>1) + $configValues );
		$passLst = Array();
		$addStmt = 'INSERT INTO '.$qtdTble.' ('.$dbHndlr->quoteFieldName('systemName').', '.$dbHndlr->quoteFieldName('passwdHash').', '.$dbHndlr->quoteFieldName('accessRole').', '.$dbHndlr->quoteFieldName('userStatus').") VALUES (?, '!', ?, 'approved');";
		$acntLst = $this->queryUsersList();
		$dbHndlr->newTransaction();
		foreach( $acntLst as $usrNme => $usrRle ){
			$passLst[$usrNme] = bin2hex(random_bytes(10));
			// The row is first written with a hash nothing matches, then given its password
			if( !$dbHndlr->sendBuiltQuery($addStmt, true, Array($usrNme, $usrRle)) || !$ntvAuth->savePasswdFlag($usrNme, $passLst[$usrNme]) ){
				$dbHndlr->delTransaction();
				$this->terminateShell('User '.$usrNme.' cannot be written; nothing was changed');
			}
		}
		$dbHndlr->setTransaction();
		return $passLst;
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
		$baseNme = strval($configValues['linkName'] ?? '');
		$tblName = strval($configValues['useTable'] ?? 'users');
		$baseTyp = strval(DBCon::baseSetupArray($baseNme)['dbEngine']);
		$sqlFile = TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.'.$baseTyp.'.sql';
		if( !preg_match('/^\w+$/D', $baseTyp) || !preg_match('/^\w+$/D', $tblName) || !is_file($sqlFile) ){
			$this->terminateShell('No users table: there is no users schema for a database of type "'.$baseTyp.'"', self::EXIT_DENY);
		}
		$dbHndlr = DBCon::linkNamedDbCon($baseNme)->readConnection();
		if( $dbHndlr===null ){
			$this->terminateShell('No users table: the native userbase needs an SQL database', self::EXIT_DENY);
		}
		if( $dbHndlr->tableInfoArray($tblName)===false ){
			// The schema names its table 'users' : it is given the configured name
			$makeSql = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$dbHndlr->quoteFieldName($tblName), strval(file_get_contents($sqlFile)), 1);
			if( !$dbHndlr->sendBuiltQuery(strval($makeSql), true) ){
				$this->terminateShell('No users table: it cannot be created: '.strval($dbHndlr->finalErrorText()));
			}
			$this->printerObj->printShellLine('Table '.$tblName.' created.');
			$this->traceShellDone('Deploy: users table '.$tblName.' created');
		}
		return $dbHndlr;
	}
}
