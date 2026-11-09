<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Checker;
use nyu\Access\Totp;
use nyu\Access\Auth\Device as DeviceAuth;
use nyu\Access\Auth\Native;
use nyu\Data\DBCon;
use nyu\Data\DBHndlr\Handler as DbHandlr;
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
 *   php nyuRun deploy start            the whole sequence, asking what it needs with a default to take on Enter
 *   php nyuRun deploy start --noask    the same without a question, for a deployment script
 *   php nyuRun deploy servr            checks the server: PHP, extensions, folders
 *   php nyuRun deploy files            creates the local files from their examples
 *   php nyuRun deploy users            creates the users table and the basic users, or the users file;
 *                                      the devices table too when conf/access.json has a device block
 *   php nyuRun deploy check            the health check
 *   php nyuRun deploy store native     moves the users to the other store (native or usrlst), the login with them
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
	// The block a store of users is given in conf/access.json when it has none
	const STORCONF = Array(
		'native'=>Array('basnme'=>'', 'tblnme'=>'users', 'tfakey'=>'env:NYU_TFA_KEY', 'mailer'=>'', 'signup'=>false, 'codexp'=>15, 'lnkurl'=>''),
		'usrlst'=>Array('usrfle'=>'conf/users.lst', 'tfakey'=>'env:NYU_TFA_KEY')
	);

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Installs the project: server check, local files, secrets, users, health check';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		return Array(
			'start'=>Array('args'=>'[--store=<native|usrlst|none>] [--noask]', 'about'=>'the whole sequence: servr, files, questions, secrets, users, check'),
			'servr'=>Array('args'=>'', 'about'=>'checks the server: PHP version, extensions, writable folders'),
			'files'=>Array('args'=>'', 'about'=>'creates .env, conf/access.json and conf/database.json from their examples; none is replaced'),
			'users'=>Array('args'=>'[block] [--store=<native|usrlst>]', 'about'=>'creates the users table and the basic users (native), or the users file (usrlst); the devices table with a device block'),
			'check'=>Array('args'=>'', 'about'=>'health check: presets, access, secrets, databases, users, unsafe settings'),
			'store'=>Array('args'=>'<native|usrlst> [--drops] [--agree]', 'about'=>'moves the users to the other store and points the login at it; the store left is kept as it is')
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
		// The settings of the database are made with the native userbase only : a project in the users file needs none
		$this->makeFiles( Array('.env', 'conf/access.json') );
		$storNme = $this->askSetups();
		// The secrets that are still empty are made; those in use are left alone
		$cnfgCmd = new Config($this->optnObjc, $this->prntObjc, $this->inptStrm);
		$scrtLst = Array('jtoken'=>'secret or keys of the tokens', 'apikey'=>'API key', 'mrkkey'=>'key of the login marks');
		// The 2FA secrets of the users are stored encrypted : their key is made with the store
		if( $storNme!='none' ){
			$scrtLst['tfakey'] = 'key of the 2FA secrets';
		}
		foreach( $scrtLst as $kndNme => $kndLbl ){
			try{
				$cnfgCmd->makeSecrt($kndNme, true);
			}catch( \RuntimeException $e ){
				$this->prntObjc->printLine( 'No '.$kndLbl.' made: '.$e->getMessage() );
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
		// A config cache brought along, or left by a former installation, is not the config of this one
		ConfCache::dropCache();
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
	 * Moves the users to the other store, the native userbase or the users
	 * file, and points the login at it. Passwords, roles, statuses,
	 * personal tokens and 2FA secrets go along, and each user keeps its
	 * identifier; the store left is not changed, so going back is moving
	 * the users again, or pointing the login back at it. The store moved to
	 * must hold no users. What does not go along is told first, and the
	 * move asked to be confirmed (--agree is the yes given beforehand).
	 * Active devices need the native userbase: they stop a move to the
	 * users file, unless --drops lets them go, revoked.
	 *
	 * @return Int The exit code.
	 */
	public function callStore(): Int {
		$tgtName = $this->needsWord('the store the users go to: native or usrlst');
		if( !in_array($tgtName, Array('native', 'usrlst'), true) ){
			$this->stopShell('Not a store the users can be moved to: '.$tgtName, self::EXITUSGE);
		}
		$lgnConf = $this->accessCnf('login');
		$srcName = strval($lgnConf['authby'] ?? '');
		if( $srcName==$tgtName ){
			$this->stopShell('The login already uses '.$tgtName.': nothing to move', self::EXITDENY);
		}
		if( !in_array($srcName, Array('native', 'usrlst'), true) ){
			$this->stopShell('The login uses neither the native userbase nor the users file: there are no users to move', self::EXITDENY);
		}
		$srcConf = $this->accessCnf( strval($lgnConf['authcf'] ?? $srcName) );
		$usrList = ($srcName=='native') ? $this->fromTable($srcConf) : $this->fromFiles($srcConf);
		if( empty($usrList) ){
			$this->stopShell('The '.$srcName.' store holds no users: nothing to move', self::EXITDENY);
		}
		// The store moved to gets its block, and the native userbase its database, before anything is checked
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' );
		$axsPath = TOP_DIR.DS.'conf'.DS.'access.json';
		$treeObj = $wrtrObj->loadJsons($axsPath);
		if( !isset($treeObj->$tgtName) ){
			$treeObj->$tgtName = (object)self::STORCONF[$tgtName];
			$wrtrObj->saveJsons($axsPath, $treeObj);
		}
		if( $tgtName=='native' && !$this->optnObjc->hasOption('noask') ){
			$this->askDbases($wrtrObj);
		}
		Console::loadConfg();
		$tgtConf = $this->accessCnf($tgtName);
		$this->emptyStor($tgtName, $tgtConf);
		list($lostLst, $dvcIdns) = $this->lostItems($srcName, $usrList);
		$usrList = $this->prepUsers($usrList, $srcConf, $tgtConf);
		$this->prntObjc->printPair('from', $srcName);
		$this->prntObjc->printPair('to', $tgtName);
		$this->prntObjc->printPair('users', count($usrList));
		if( !empty($lostLst) ){
			$this->prntObjc->printRows( Array('not kept', 'details'), $lostLst );
		}
		if( !$this->askAgreed('Move '.count($usrList).' users to '.$tgtName.' and point the login at it?') ){
			$this->stopShell('Nothing was moved', self::EXITDENY);
		}
		if( $tgtName=='native' ){
			$this->intoTable($tgtConf, $usrList);
		}else{
			$this->intoFiles($tgtConf, $usrList);
		}
		// Devices left behind would still pass on users the project no longer manages
		$dvcAuth = new DeviceAuth( $this->accessCnf('device') );
		foreach( $dvcIdns as $dvcIdn ){
			$dvcAuth->remDevice($dvcIdn);
		}
		// The login is pointed at the store only once every user is in it
		$treeObj = $wrtrObj->loadJsons($axsPath);
		if( !isset($treeObj->login) || !is_object($treeObj->login) ){
			$treeObj->login = new \stdClass();
		}
		$treeObj->login->authby = $tgtName;
		$treeObj->login->authcf = $tgtName;
		$wrtrObj->saveJsons($axsPath, $treeObj);
		Console::loadConfg();
		$this->traceDone('Deploy: '.count($usrList).' users moved from '.$srcName.' to '.$tgtName.( empty($dvcIdns) ? '' : ', '.count($dvcIdns).' devices revoked' ).', the login points at '.$tgtName);
		$this->prntObjc->printPair('moved', count($usrList));
		$this->prntObjc->printLine('The login now uses '.$tgtName.'; the '.$srcName.' store is left as it was.');
		return self::EXITDONE;
	}

	/**
	 * Reads every user of the native userbase, hashes and secrets
	 * included, with its activation code.
	 *
	 * @param Array $confValues Config block of the native userbase.
	 * @return Array The users, by identifier.
	 *
	 * @throws ShellStop When the users cannot be read.
	 */
	protected function fromTable( Array $confValues ): Array {
		$tblName = strval($confValues['tblnme'] ?? 'users');
		try{
			$dbHandl = DBCon::linkNamed( strval($confValues['basnme'] ?? '') )->getConnex();
		}catch( \Throwable $e ){
			$this->stopShell('The users cannot be read: '.$e->getMessage());
		}
		if( $dbHandl===null || !preg_match('/^\w+$/D', $tblName) ){
			$this->stopShell('The users cannot be read: the native userbase needs an SQL database and a table named with letters and digits', self::EXITDENY);
		}
		$fldList = array_map( Array($dbHandl, 'quoteName'), array_merge(UserFile::FLDSLIST, Array('actvCode', 'resetCod', 'twoFaWay')) );
		if( !$dbHandl->sendQuery('SELECT '.implode(', ', $fldList).' FROM '.$dbHandl->quoteName($tblName).' ORDER BY '.$dbHandl->quoteName('id_user').';') ){
			$this->stopShell('The users cannot be read: '.strval($dbHandl->lastError()));
		}
		$rowList = $dbHandl->readQuery(true);
		return is_array($rowList) ? $rowList : Array();
	}

	/**
	 * Reads every user of the users file, hashes and secrets included.
	 *
	 * @param Array $confValues Config block of the users file.
	 * @return Array The users, as the file lists them.
	 *
	 * @throws ShellStop When the file cannot be used.
	 */
	protected function fromFiles( Array $confValues ): Array {
		$fileObj = new UserFile( $confValues, new ConfFile(TOP_DIR.DS.'temp'.DS.'backup') );
		$usrList = $fileObj->readUsers();
		if( $usrList===false ){
			$this->stopShell('The users cannot be read: '.$fileObj->lastError());
		}
		return $usrList;
	}

	/**
	 * Checks that the store the users go to holds none: a table that
	 * holds users, or a file that does, is never merged into.
	 *
	 * @param String $storeNamed 'native' or 'usrlst'.
	 * @param Array  $confValues Config block of that store.
	 *
	 * @throws ShellStop When the store holds users or cannot be read.
	 */
	protected function emptyStor( String $storeNamed, Array $confValues ): Void {
		if( $storeNamed=='usrlst' ){
			$usrNmbr = count( $this->fromFiles($confValues) );
		}else{
			$tblName = strval($confValues['tblnme'] ?? 'users');
			try{
				$dbHandl = DBCon::linkNamed( strval($confValues['basnme'] ?? '') )->getConnex();
			}catch( \Throwable $e ){
				$this->stopShell('Nothing was moved: '.$e->getMessage(), self::EXITDENY);
			}
			if( $dbHandl===null || !preg_match('/^\w+$/D', $tblName) ){
				$this->stopShell('Nothing was moved: the native userbase needs an SQL database and a table named with letters and digits', self::EXITDENY);
			}
			$usrNmbr = 0;
			if( $dbHandl->tableInfo($tblName)!==false ){
				$rowList = $dbHandl->sendQuery('SELECT COUNT(*) AS '.$dbHandl->quoteName('usrCount').' FROM '.$dbHandl->quoteName($tblName).';') ? $dbHandl->readQuery(true) : false;
				if( !is_array($rowList) ){
					$this->stopShell('Nothing was moved: the users table cannot be read: '.strval($dbHandl->lastError()));
				}
				$usrNmbr = intval($rowList[0]['usrCount'] ?? 0);
			}
		}
		if( $usrNmbr>0 ){
			$this->stopShell('Nothing was moved: the '.$storeNamed.' store already holds users', self::EXITDENY);
		}
	}

	/**
	 * Lists what does not go along when the users leave the native
	 * userbase for the users file, which is only read: activation and reset
	 * codes, the review of sign-ups, presence and the step of the last 2FA
	 * code. Users with codes by mail stop the move; active devices stop it
	 * too, unless --drops lets them go.
	 *
	 * @param String $sourceName The store the users leave.
	 * @param Array  $usersArray The users, as that store holds them.
	 * @return Array The rows of what is not kept, and the identifiers of the devices to revoke.
	 *
	 * @throws ShellStop When users with codes by mail are found, or active devices without --drops.
	 */
	protected function lostItems( String $sourceName, Array $usersArray ): Array {
		if( $sourceName!='native' ){
			return Array( Array(), Array() );
		}
		$lostLst = Array();
		$pendLst = array_column( array_filter($usersArray, function( Array $userValues ): Bool {
			return ($userValues['userStat'] ?? '')=='pendng' && strval($userValues['actvCode'] ?? '')!=='';
		}), 'userName' );
		if( !empty($pendLst) ){
			$lostLst[] = Array('activation codes', implode(', ', $pendLst).': pending until made active, or given a password, with manage');
		}
		$rsetLst = array_column( array_filter($usersArray, function( Array $userValues ): Bool {
			return strval($userValues['resetCod'] ?? '')!=='';
		}), 'userName' );
		if( !empty($rsetLst) ){
			$lostLst[] = Array('reset codes', implode(', ', $rsetLst).': the resets asked are forgotten, the passwords kept');
		}
		$rvwList = array_column( array_filter($usersArray, function( Array $userValues ): Bool {
			return ($userValues['userStat'] ?? '')=='review';
		}), 'userName' );
		if( !empty($rvwList) ){
			$lostLst[] = Array('sign-ups to review', implode(', ', $rvwList).': kept in review, which the users file cannot approve; manage unlck makes them active');
		}
		// A code mailed at each login is written in the store : the users file, only read, cannot keep one
		$mailLst = array_column( array_filter($usersArray, function( Array $userValues ): Bool {
			return ($userValues['twoFaWay'] ?? null)==='mailed';
		}), 'userName' );
		if( !empty($mailLst) ){
			$this->stopShell('Nothing was moved: codes by mail need the native userbase ('.implode(', ', $mailLst).'); "manage twofa <user> --clear" takes them away', self::EXITDENY);
		}
		$lostLst[] = Array('presence and 2FA steps', 'the users file is only read: last seen, failed attempts and the step of the last 2FA code are not kept');
		$dvcConf = $this->accessCnf('device');
		$dvcRows = empty($dvcConf) ? Array() : (new DeviceAuth($dvcConf))->listDevcs();
		$dvcIdns = Array();
		$dvcNmes = Array();
		foreach( is_array($dvcRows) ? $dvcRows : Array() as $dvcRow ){
			if( $dvcRow['revkDate']===null ){
				$dvcIdns[] = intval($dvcRow['id_device']);
				$dvcNmes[] = $dvcRow['userName'].'/'.$dvcRow['devcName'];
			}
		}
		if( !empty($dvcIdns) ){
			if( !$this->optnObjc->hasOption('drops') ){
				$this->stopShell('Nothing was moved: active devices need the native userbase ('.implode(', ', $dvcNmes).'); --drops revokes them', self::EXITDENY);
			}
			$lostLst[] = Array('devices', implode(', ', $dvcNmes).': revoked');
		}
		return Array( $lostLst, $dvcIdns );
	}

	/**
	 * Gives the users as the store they go to takes them: the fields both
	 * stores have, the role and status a users file leaves out, an
	 * identifier for a user without one, and the 2FA secrets encrypted
	 * with the key of that store when it is not the same.
	 *
	 * @param Array $usersArray The users, as the store they leave holds them.
	 * @param Array $sourceConf Config block of the store they leave.
	 * @param Array $targetConf Config block of the store they go to.
	 * @return Array The users to write.
	 *
	 * @throws ShellStop When a user has no password hash, or a 2FA secret that cannot be read.
	 */
	protected function prepUsers( Array $usersArray, Array $sourceConf, Array $targetConf ): Array {
		$srcSeal = strval($sourceConf['tfakey'] ?? '');
		$tgtSeal = strval($targetConf['tfakey'] ?? '');
		$lastIdn = max( array_merge(Array(0), array_map('intval', array_column($usersArray, 'id_user'))) );
		$nopsLst = Array();
		$readyLs = Array();
		foreach( $usersArray as $usrRow ){
			$newRow = Array();
			foreach( UserFile::FLDSLIST as $fldNme ){
				if( isset($usrRow[$fldNme]) && $usrRow[$fldNme]!=='' ){
					$newRow[$fldNme] = $usrRow[$fldNme];
				}
			}
			if( strval($newRow['passHash'] ?? '')==='' ){
				$nopsLst[] = strval($usrRow['userName'] ?? '');
				continue;
			}
			$newRow['id_user'] = (intval($newRow['id_user'] ?? 0)>0) ? intval($newRow['id_user']) : ++$lastIdn;
			$newRow['userRole'] = $newRow['userRole'] ?? Checker::ROLELIST[0];
			$newRow['userStat'] = $newRow['userStat'] ?? 'active';
			if( isset($newRow['twoFaKey']) && $srcSeal!==$tgtSeal ){
				$plainTx = Totp::openSecrt( strval($newRow['twoFaKey']), $srcSeal );
				if( $plainTx===null ){
					$this->stopShell('Nothing was moved: the 2FA secret of user '.json_encode($newRow['userName']).' cannot be read with the key of its store', self::EXITDENY);
				}
				$newRow['twoFaKey'] = Totp::sealSecrt($plainTx, $tgtSeal);
			}
			$readyLs[] = $newRow;
		}
		if( !empty($nopsLst) ){
			$this->stopShell('Nothing was moved: users without a password hash ('.implode(', ', $nopsLst).'); give them a password with manage first', self::EXITDENY);
		}
		return $readyLs;
	}

	/**
	 * Writes the users into the native userbase, its table created first
	 * when it is missing, all of them or none.
	 *
	 * @param Array $confValues Config block of the native userbase.
	 * @param Array $usersArray The users to write.
	 *
	 * @throws ShellStop When the table cannot be created or written.
	 */
	protected function intoTable( Array $confValues, Array $usersArray ): Void {
		try{
			$dbHandl = $this->needTable($confValues);
			$baseTyp = strval(DBCon::baseSetup( strval($confValues['basnme'] ?? '') )['type']);
		}catch( ShellStop $e ){
			throw $e;
		}catch( \Throwable $e ){
			$this->stopShell('Nothing was moved: '.$e->getMessage());
		}
		$qtdTble = $dbHandl->quoteName( strval($confValues['tblnme'] ?? 'users') );
		$dbHandl->opnTrnsct();
		foreach( $usersArray as $usrRow ){
			$addQury = 'INSERT INTO '.$qtdTble.' ('.implode(', ', array_map(Array($dbHandl, 'quoteName'), array_keys($usrRow))).') VALUES ('.implode(', ', array_fill(0, count($usrRow), '?')).');';
			if( !$dbHandl->sendQuery($addQury, true, array_values($usrRow)) ){
				$errText = strval($dbHandl->lastError());
				$dbHandl->remTrnsct();
				$this->stopShell('Nothing was moved: user '.json_encode($usrRow['userName']).' cannot be written: '.$errText);
			}
		}
		// The identifiers were given : PostgreSQL is told to count on from the highest
		if( $baseTyp=='pgsql' && !$dbHandl->sendQuery('SELECT setval(pg_get_serial_sequence(?, \'id_user\'), (SELECT MAX('.$dbHandl->quoteName('id_user').') FROM '.$qtdTble.'));', false, Array($qtdTble)) ){
			$errText = strval($dbHandl->lastError());
			$dbHandl->remTrnsct();
			$this->stopShell('Nothing was moved: the identifiers cannot be set: '.$errText);
		}
		$dbHandl->setTrnsct();
	}

	/**
	 * Writes the users into the users file, which must hold none.
	 *
	 * @param Array $confValues Config block of the users file.
	 * @param Array $usersArray The users to write.
	 *
	 * @throws ShellStop When the file cannot be written.
	 */
	protected function intoFiles( Array $confValues, Array $usersArray ): Void {
		$fileObj = new UserFile( $confValues, new ConfFile(TOP_DIR.DS.'temp'.DS.'backup') );
		if( !$fileObj->fillUsers($usersArray) ){
			$this->stopShell('Nothing was moved: '.$fileObj->lastError());
		}
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
	 *
	 * @param Array $localFiles The local files to make, as DISTLIST names them; all of them when empty.
	 */
	protected function makeFiles( Array $localFiles=Array() ): Void {
		$rowList = Array();
		foreach( self::DISTLIST as $fleNme => $dstNme ){
			if( !empty($localFiles) && !in_array($fleNme, $localFiles, true) ){
				continue;
			}
			$filePth = TOP_DIR.DS.str_replace('/', DS, $fleNme);
			$distPth = TOP_DIR.DS.str_replace('/', DS, $dstNme);
			if( is_file($filePth) ){
				$rowList[] = Array($fleNme, 'kept');
			}elseif( !is_file($distPth) ){
				$rowList[] = Array($fleNme, 'not created: its example '.$dstNme.' is missing');
			}elseif( (function() use ( $distPth, $filePth ): Bool {
				// Made readable by its owner only, from its first byte: the file will hold secrets
				$prevMsk = umask(0077);
				try{
					return @copy($distPth, $filePth);
				}finally{
					umask($prevMsk);
				}
			})() ){
				// The secrets are for the owner alone; the access config is read by the group of the web server too
				$secRgts = Array('.env'=>0600, 'conf/access.json'=>0640);
				@chmod($filePth, $secRgts[$fleNme] ?? (0666 & ~umask()));
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
	 * userbase, where its database is: conf/database.json is then made
	 * from its example, and only then. What is answered is written to the
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
				$treeObj->$storNme = (object)self::STORCONF[$storNme];
			}
			$wrtrObj->saveJsons($axsPath, $treeObj);
		}
		if( $storNme=='native' ){
			$this->makeFiles( Array('conf/database.json') );
			if( !$this->optnObjc->hasOption('noask') ){
				$this->askDbases($wrtrObj);
			}
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
		// Tables are made: the descriptions the config cache keeps (TBL_CACH) are no longer the whole story
		ConfCache::dropCache();
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
				$this->tableDevc();
				return;
			}
		}
		$this->traceDone('Deploy: basic users created in '.$storeNamed.' ('.implode(', ', array_keys(self::USERLIST)).')');
		$this->prntObjc->printLine('Users created. Their passwords are shown this once:');
		foreach( self::USERLIST as $usrNme => $usrRle ){
			$this->prntObjc->printOnce('Password of '.$usrNme.' ('.$usrRle.')', $passLst[$usrNme]);
		}
		// The devices come after the passwords : a table that cannot be made does not hide them
		if( $storeNamed=='native' ){
			$this->tableDevc();
		}
	}

	/**
	 * Creates the devices table when conf/access.json holds a device block
	 * and the table is missing, from the devices.<type>.sql file of
	 * conf/schema, linked to the users table the block names. A project
	 * without a device block gets no devices table.
	 *
	 * @throws ShellStop When the table cannot be created.
	 */
	protected function tableDevc(): Void {
		$dvcConf = $this->accessCnf('device');
		if( empty($dvcConf) ){
			return;
		}
		$tblName = strval($dvcConf['tblnme'] ?? 'devices');
		$usrTble = strval($dvcConf['usrtbl'] ?? 'users');
		try{
			$baseNme = strval($dvcConf['basnme'] ?? '');
			$baseTyp = strval(DBCon::baseSetup($baseNme)['type']);
			$dbHandl = DBCon::linkNamed($baseNme)->getConnex();
		}catch( \Throwable $e ){
			$this->stopShell('No devices table created: '.$e->getMessage(), self::EXITFAIL);
		}
		$sqlFile = TOP_DIR.DS.'conf'.DS.'schema'.DS.'devices.'.$baseTyp.'.sql';
		if( !preg_match('/^\w+$/D', $baseTyp) || !preg_match('/^\w+$/D', $tblName) || !preg_match('/^\w+$/D', $usrTble) || !is_file($sqlFile) ){
			$this->stopShell('No devices table created: there is no devices schema for a database of type "'.$baseTyp.'"', self::EXITDENY);
		}
		if( $dbHandl===null ){
			$this->stopShell('No devices table created: the devices need an SQL database', self::EXITDENY);
		}
		if( $dbHandl->tableInfo($tblName)!==false ){
			return;
		}
		// The schema names its tables 'devices' and 'users' : they are given the configured names
		$makeSql = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$dbHandl->quoteName($tblName), strval(file_get_contents($sqlFile)), 1);
		$makeSql = preg_replace('/REFERENCES \S+/', 'REFERENCES '.$dbHandl->quoteName($usrTble), strval($makeSql), 1);
		if( !$dbHandl->sendQuery(strval($makeSql), true) ){
			$this->stopShell('No devices table created: '.strval($dbHandl->lastError()));
		}
		$this->prntObjc->printLine('Table '.$tblName.' created.');
		$this->traceDone('Deploy: devices table '.$tblName.' created');
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
		$tblName = strval($confValues['tblnme'] ?? 'users');
		$dbHandl = $this->needTable($confValues);
		$qtdTble = $dbHandl->quoteName($tblName);
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

	/**
	 * Gives the database of the native userbase, its users table created
	 * from the users.<type>.sql file of conf/schema when it is missing.
	 *
	 * @param Array $confValues Config block of the native userbase.
	 * @return DbHandlr The handler of that database.
	 *
	 * @throws ShellStop When there is no schema for the database, or the table cannot be created.
	 * @throws \RuntimeException When the database is not configured or does not answer.
	 */
	protected function needTable( Array $confValues ): DbHandlr {
		$baseNme = strval($confValues['basnme'] ?? '');
		$tblName = strval($confValues['tblnme'] ?? 'users');
		$baseTyp = strval(DBCon::baseSetup($baseNme)['type']);
		$sqlFile = TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.'.$baseTyp.'.sql';
		if( !preg_match('/^\w+$/D', $baseTyp) || !preg_match('/^\w+$/D', $tblName) || !is_file($sqlFile) ){
			$this->stopShell('No users table: there is no users schema for a database of type "'.$baseTyp.'"', self::EXITDENY);
		}
		$dbHandl = DBCon::linkNamed($baseNme)->getConnex();
		if( $dbHandl===null ){
			$this->stopShell('No users table: the native userbase needs an SQL database', self::EXITDENY);
		}
		if( $dbHandl->tableInfo($tblName)===false ){
			// The schema names its table 'users' : it is given the configured name
			$makeSql = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$dbHandl->quoteName($tblName), strval(file_get_contents($sqlFile)), 1);
			if( !$dbHandl->sendQuery(strval($makeSql), true) ){
				$this->stopShell('No users table: it cannot be created: '.strval($dbHandl->lastError()));
			}
			$this->prntObjc->printLine('Table '.$tblName.' created.');
			$this->traceDone('Deploy: users table '.$tblName.' created');
		}
		return $dbHandl;
	}
}
