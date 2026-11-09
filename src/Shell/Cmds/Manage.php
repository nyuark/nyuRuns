<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Checker;
use nyu\Access\Auth\UserBase;
use nyu\Data\QrCode;
use nyu\Shell\ActCaller;
use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Shell\Console;
use nyu\Shell\UserFile;

/**
 * Manages the users, and calls the actions of the runs:
 *
 *   php nyuRun manage lists --urole=manage     the users, filtered or not
 *   php nyuRun manage enrol ann system         creates a user
 *   php nyuRun manage locks ann                locks it (unlck unlocks)
 *   php nyuRun manage passw ann                gives it a new password, made and shown once (--typed to type it)
 *   php nyuRun manage twofa ann --clear        takes its 2FA secret away, when its authenticator is lost
 *   php nyuRun manage twofa ann --email        has it give a code sent to its address at each login
 *   php nyuRun manage prove                    the sign-ups awaiting review (prove ann approves one)
 *   php nyuRun manage calls shows/overview filters[search]=jazz
 *
 * The users are those of the store the login uses: the native userbase
 * (native) or the users file (usrlst); --store names the other, --block
 * the block of conf/access.json holding its settings. Every change is
 * traced on the 'access' log channel, as those made over HTTP.
 */
class Manage extends Command{

	// Methods a call may be said to use
	const MTHD_LIST = Array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

	// The store the users are managed in: 'native' or 'usrlst'
	protected $usersStore = '';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Manages the users (native userbase or users file), and calls an action of a run';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
		return Array(
			'lists'=>Array('args'=>'[--ustat=<status>] [--urole=<role>] [--count=<n>] [--start=<n>]', 'about'=>'lists the users'),
			'views'=>Array('args'=>'<user>', 'about'=>'shows a user'),
			'enrol'=>Array('args'=>'<user> [role] [--typed] [--noexp] [--ulang=<language>]', 'about'=>'creates a user: pending with an activation code lasting 300 minutes (--noexp: no end) (native), active with its password (usrlst); --ulang gives the language of its mails'),
			'actvt'=>Array('args'=>'<user> <code> [--typed]', 'about'=>'activates a pending user with its code, and gives it a password (native)'),
			'locks'=>Array('args'=>'<user>', 'about'=>'locks a user'),
			'unlck'=>Array('args'=>'<user>', 'about'=>'unlocks a user'),
			'roles'=>Array('args'=>'<user> <role>', 'about'=>'gives a user another role'),
			'remov'=>Array('args'=>'<user>', 'about'=>'removes a user'),
			'reset'=>Array('args'=>'<user> [--noexp]', 'about'=>'for a lost password: a new activation code lasting 300 minutes (--noexp: no end) (native), a new password (usrlst)'),
			'prove'=>Array('args'=>'[user]', 'about'=>'approves a user who signed up and confirmed its address; without a user, lists those awaiting review (native)'),
			'email'=>Array('args'=>'<user> [address]', 'about'=>'gives a user a mail address, or takes it away when none is given'),
			'langs'=>Array('args'=>'<user> [language]', 'about'=>'gives a user the language of its mails (fr, fr-ch), or takes it away when none is given: the default one (LNG_DFLT) then'),
			'passw'=>Array('args'=>'<user> [--typed]', 'about'=>'gives a user a new password'),
			'token'=>Array('args'=>'<user> [--clear]', 'about'=>'gives a user a new personal API token, or takes it away'),
			'twofa'=>Array('args'=>'<user> [--clear] [--plain] [--email]', 'about'=>'gives a user a new 2FA secret, shown with its QR code (--plain: without), codes by mail (--email), or takes it away'),
			'calls'=>Array('args'=>'<app/action[/param]> [name=value ...] [--input=<file|->] [--mthod=<method>] [--prset=<preset>] [--urole=<role>] [--saved=<file>]', 'about'=>'calls an action of a run, as the user cli:<system user>')
		);
	}

	/**
	 * Lists the users, by status and role when asked, a part of the list at a time.
	 *
	 * @return Int The exit code.
	 */
	public function callLists(): Int {
		$storObj = $this->openUsersStore();
		$usrList = $storObj->listUsersNamed(
			Array('userStat'=>$this->optnObject->retrieveOption('ustat', ''), 'userRole'=>$this->optnObject->retrieveOption('urole', '')),
			intval($this->optnObject->retrieveOption('count', '0')),
			intval($this->optnObject->retrieveOption('start', '0'))
		);
		if( $usrList===false ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellRows( empty($usrList) ? Array('userName') : array_keys($usrList[0]), array_map('array_values', $usrList) );
		if( empty($usrList) ){
			$this->printerObj->printShellLine('No user.');
		}
		return self::EXIT_DONE;
	}

	/**
	 * Shows what the store holds of the user named on the command line.
	 *
	 * @return Int The exit code.
	 */
	public function callViews(): Int {
		$storObj = $this->openUsersStore();
		$userVls = $storObj->userInfosArray( $this->needsShellWord('the user') );
		if( $userVls===false ){
			$this->stopStoreError($storObj);
		}
		foreach( $userVls as $fldKey => $fldVal ){
			$this->printerObj->printShellPair(strval($fldKey), $fldVal);
		}
		return self::EXIT_DONE;
	}

	/**
	 * Creates a user. In the native userbase it is pending, and its
	 * activation code is shown once for it to choose its password with.
	 * In the users file, which knows no activation, it is active at once,
	 * with a password made and shown once, or typed.
	 *
	 * @return Int The exit code.
	 */
	public function callEnrol(): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		$roleNme = strval($this->optnObject->shiftFirstWord() ?? '');
		$langTag = $this->optnObject->retrieveOption('ulang');
		$langTag = ($langTag!==null) ? strval($langTag) : null;
		if( $this->usersStore=='native' ){
			$newCode = $storObj->enrolUsrString($usrName, $roleNme, null, $this->codesLastCount(), $langTag);
			if( $newCode===false ){
				$this->stopStoreError($storObj);
			}
			$this->printerObj->printShellPair('created', $usrName);
			$this->printerObj->printShellOnce('Activation code of '.$usrName, strval($newCode));
			return self::EXIT_DONE;
		}
		$passArr = $this->givenPassArray($usrName);
		if( !$storObj->enrolUsrString($usrName, $roleNme, $passArr['pass'], $langTag) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair('created', $usrName);
		if( $passArr['made'] ){
			$this->printerObj->printShellOnce('Password of '.$usrName, $passArr['pass']);
		}
		return self::EXIT_DONE;
	}

	/**
	 * Activates a pending user of the native userbase with its code, and
	 * gives it a password.
	 *
	 * @return Int The exit code.
	 */
	public function callActvt(): Int {
		$storObj = $this->openUsersStore();
		if( $this->usersStore!='native' ){
			$this->terminateShell('The users file knows no activation: a user is created active', self::EXIT_DENY);
		}
		$usrName = $this->needsShellWord('the user');
		$gvnCode = $this->needsShellWord('the activation code');
		$passArr = $this->givenPassArray($usrName);
		if( !$storObj->activateUsrFlg($usrName, $gvnCode, $passArr['pass']) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair('activated', $usrName);
		if( $passArr['made'] ){
			$this->printerObj->printShellOnce('Password of '.$usrName, $passArr['pass']);
		}
		return self::EXIT_DONE;
	}

	/**
	 * Locks the user named on the command line.
	 *
	 * @return Int The exit code.
	 */
	public function callLocks(): Int {
		return $this->statusSetCount('locked');
	}

	/**
	 * Unlocks the user named on the command line: it is active again.
	 *
	 * @return Int The exit code.
	 */
	public function callUnlck(): Int {
		return $this->statusSetCount('active');
	}

	/**
	 * Gives the user named on the command line a status.
	 *
	 * @param String $statusString 'locked' or 'active'.
	 * @return Int The exit code.
	 */
	protected function statusSetCount( String $statusString ): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		if( !$storObj->saveStatusFlag($usrName, $statusString) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair( ($statusString=='locked') ? 'locked' : 'unlocked', $usrName );
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user another role.
	 *
	 * @return Int The exit code.
	 */
	public function callRoles(): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		$roleNme = $this->needsShellWord('the role ('.implode(', ', Checker::ROLE_LIST).')');
		if( !$storObj->changeRoleFlag($usrName, $roleNme) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair($usrName, $roleNme);
		return self::EXIT_DONE;
	}

	/**
	 * Removes a user, once confirmed.
	 *
	 * @return Int The exit code.
	 */
	public function callRemov(): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		if( !$this->queryAgreedFlg('Remove the user '.$usrName) ){
			$this->terminateShell('Nothing was removed: not confirmed', self::EXIT_DENY);
		}
		if( !$storObj->removeUserFlag($usrName) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair('removed', $usrName);
		return self::EXIT_DONE;
	}

	/**
	 * For a user whose password is lost. In the native userbase it is put
	 * back to pending, with a new activation code shown once; in the
	 * users file it is given a new password.
	 *
	 * @return Int The exit code.
	 */
	public function callReset(): Int {
		$storObj = $this->openUsersStore();
		if( $this->usersStore!='native' ){
			return $this->passwdSetCount( $storObj, $this->needsShellWord('the user') );
		}
		$usrName = $this->needsShellWord('the user');
		$newCode = $storObj->resetUsrString($usrName, $this->codesLastCount());
		if( $newCode===false ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair('reset', $usrName);
		$this->printerObj->printShellOnce('Activation code of '.$usrName, strval($newCode));
		return self::EXIT_DONE;
	}

	/**
	 * Approves a user of the native userbase who signed up itself and
	 * confirmed its address. Without a user, lists those awaiting review.
	 *
	 * @return Int The exit code.
	 */
	public function callProve(): Int {
		$storObj = $this->openUsersStore();
		if( $this->usersStore!='native' ){
			$this->terminateShell('The users file knows no sign-up: a user is created active', self::EXIT_DENY);
		}
		$usrName = strval($this->optnObject->shiftFirstWord() ?? '');
		if( $usrName=='' ){
			$usrList = $storObj->listUsersNamed( Array('userStat'=>'review') );
			if( $usrList===false ){
				$this->stopStoreError($storObj);
			}
			$this->printerObj->printShellRows( empty($usrList) ? Array('userName') : array_keys($usrList[0]), array_map('array_values', $usrList) );
			if( empty($usrList) ){
				$this->printerObj->printShellLine('No user awaits review.');
			}
			return self::EXIT_DONE;
		}
		if( !$storObj->approveNewUser($usrName) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair('approved', $usrName);
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user a mail address, or takes it away when none is given.
	 *
	 * @return Int The exit code.
	 */
	public function callEmail(): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		$mailTxt = strval($this->optnObject->shiftFirstWord() ?? '');
		if( !$storObj->chngeEmailFlag($usrName, $mailTxt) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair( ($mailTxt=='') ? 'address removed' : 'address set', $usrName );
		return self::EXIT_DONE;
	}

	/**
	 * Shows the language of the mails of a user, or gives it one, or
	 * takes it away with an empty word ('').
	 *
	 * @return Int The exit code.
	 */
	public function callLangs(): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		$langTag = $this->optnObject->shiftFirstWord();
		if( $langTag===null ){
			$userVls = $storObj->userInfosArray($usrName);
			if( $userVls===false ){
				$this->stopStoreError($storObj);
			}
			$this->printerObj->printShellPair( $usrName, (strval($userVls['userLang'] ?? '')!=='') ? strval($userVls['userLang']) : '(default)' );
			return self::EXIT_DONE;
		}
		if( !$storObj->changeLanguage($usrName, strval($langTag)) ){
			$this->stopStoreError($storObj);
		}
		$this->printerObj->printShellPair( (strval($langTag)=='') ? 'language removed' : 'language set', $usrName );
		return self::EXIT_DONE;
	}

	/**
	 * Gives the minutes an activation code shown here lasts: those of a
	 * code handed over, or none with --noexp.
	 *
	 * @return Int The minutes, 0 for no end.
	 */
	protected function codesLastCount(): Int {
		return $this->optnObject->validateOption('noexp') ? 0 : UserBase::HAND_MINS;
	}

	/**
	 * Gives a user a new password: made and shown once, or typed.
	 *
	 * @return Int The exit code.
	 */
	public function callPassw(): Int {
		return $this->passwdSetCount( $this->openUsersStore(), $this->needsShellWord('the user') );
	}

	/**
	 * Gives a user a new password.
	 *
	 * @param Object $dataStoreObj The store of the users.
	 * @param String $namedUserTxt Login name of the user.
	 * @return Int The exit code.
	 */
	protected function passwdSetCount( Object $dataStoreObj, String $namedUserTxt ): Int {
		$passArr = $this->givenPassArray($namedUserTxt);
		if( !$dataStoreObj->savePasswdFlag($namedUserTxt, $passArr['pass']) ){
			$this->stopStoreError($dataStoreObj, 'Password not written: the user is unknown, or its store cannot be written');
		}
		if( $this->usersStore=='native' ){
			$this->traceShellDone( 'Password of user '.json_encode($namedUserTxt).' set' );
		}
		$this->printerObj->printShellPair('password set', $namedUserTxt);
		if( $passArr['made'] ){
			$this->printerObj->printShellOnce('Password of '.$namedUserTxt, $passArr['pass']);
		}
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user a new personal API token, shown once, or takes the one
	 * it holds away with --clear.
	 *
	 * @return Int The exit code.
	 */
	public function callToken(): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		if( $this->optnObject->validateOption('clear') ){
			if( !$this->queryAgreedFlg('Take the personal token of '.$usrName.' away') ){
				$this->terminateShell('Nothing was removed: not confirmed', self::EXIT_DENY);
			}
			if( !$storObj->clearTokenFlag($usrName) ){
				$this->stopStoreError($storObj);
			}
			$this->printerObj->printShellPair('token removed', $usrName);
			return self::EXIT_DONE;
		}
		$newTokn = $storObj->makeToknString($usrName);
		if( $newTokn===false ){
			$this->stopStoreError($storObj, 'No token made: the user is unknown or not active, or its store cannot be written');
		}
		if( $this->usersStore=='native' ){
			$this->traceShellDone( 'Personal token of user '.json_encode($usrName).' made' );
		}
		$this->printerObj->printShellPair('token made', $usrName);
		$this->printerObj->printShellOnce('Personal token of '.$usrName, strval($newTokn));
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user a new 2FA secret, shown once with the address an
	 * authenticator reads and its QR code (left out with --plain, and in
	 * JSON), has it give a code mailed at each login instead (--email,
	 * native userbase only), or takes either away with --clear.
	 *
	 * @return Int The exit code.
	 */
	public function callTwofa(): Int {
		$storObj = $this->openUsersStore();
		$usrName = $this->needsShellWord('the user');
		if( $this->optnObject->validateOption('clear') ){
			if( !$this->queryAgreedFlg('Take the 2FA secret of '.$usrName.' away: its password alone will log it in') ){
				$this->terminateShell('Nothing was removed: not confirmed', self::EXIT_DENY);
			}
			if( !$storObj->clearTwoFactor($usrName) ){
				$this->stopStoreError($storObj);
			}
			$this->printerObj->printShellPair('2FA removed', $usrName);
			return self::EXIT_DONE;
		}
		// Codes by mail : the users file is only read, and cannot keep a code
		if( $this->optnObject->validateOption('email') ){
			if( !method_exists($storObj, 'emailTwoFactor') ){
				$this->terminateShell('Nothing was changed: codes by mail need the native userbase; the users file is only read', self::EXIT_DENY);
			}
			if( !$storObj->emailTwoFactor($usrName) ){
				$this->stopStoreError($storObj);
			}
			$this->traceShellDone( 'Mailed codes set for user '.json_encode($usrName) );
			$this->printerObj->printShellPair('2FA set', $usrName.' (codes by mail)');
			return self::EXIT_DONE;
		}
		$newSecr = $storObj->buildTwoFactor($usrName);
		if( $newSecr===false ){
			$this->stopStoreError($storObj, 'No 2FA secret made: the user is unknown or not active, or its store cannot be written');
		}
		if( $this->usersStore=='native' ){
			$this->traceShellDone( '2FA secret of user '.json_encode($usrName).' made' );
		}
		$this->printerObj->printShellPair('2FA set', $usrName);
		$this->printerObj->printShellOnce('2FA secret of '.$usrName, strval($newSecr));
		$otpAddr = 'otpauth://totp/nyuRuns:'.rawurlencode($usrName).'?secret='.$newSecr.'&issuer=nyuRuns';
		$this->printerObj->printShellOnce('Address for the authenticator', $otpAddr);
		// The address as a QR code, for the authenticator to scan : it holds the secret, and is shown once too
		if( !$this->optnObject->validateOption('plain') && !$this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellBody( (new QrCode($otpAddr, 'M'))->drawAsTerminal(2) );
		}
		return self::EXIT_DONE;
	}

	/**
	 * Calls an action of a run. Its inputs are the name=value words
	 * (filters[search]=jazz for a nested one), over those of a JSON file
	 * (--input, - for the standard input). It runs as the user
	 * cli:<system user>, with the role 'system' unless --urole gives
	 * another. Its reply is written to the output, or to --saved.
	 *
	 * @return Int EXITDONE for a success or a redirection, EXITDENY for a 4xx status, EXITFAIL for a 5xx.
	 */
	public function callCalls(): Int {
		$actnPth = $this->needsShellWord('the action, as app/action');
		$inptVls = Array();
		$inptFil = $this->optnObject->retrieveOption('input');
		if( $inptFil!==null ){
			$inptTxt = ($inptFil=='-') ? stream_get_contents($this->inptStream) : @file_get_contents($inptFil);
			$inptVls = json_decode(strval($inptTxt), true);
			if( !is_array($inptVls) ){
				$this->terminateShell('The inputs of --input are not a JSON object', self::EXIT_HELP);
			}
		}
		$pairLst = Array();
		foreach( $this->optnObject->restWordsArray() as $gvnWrd ){
			$pairPrt = explode('=', $gvnWrd, 2);
			if( count($pairPrt)!=2 || $pairPrt[0]=='' ){
				$this->terminateShell('An input is written name=value: '.$gvnWrd, self::EXIT_HELP);
			}
			$pairLst[] = str_replace(Array('%5B', '%5D'), Array('[', ']'), rawurlencode($pairPrt[0])).'='.rawurlencode($pairPrt[1]);
		}
		$wordVal = Array();
		parse_str(implode('&', $pairLst), $wordVal);
		$inptVls = array_replace_recursive($inptVls, $wordVal);
		$roleNme = strval($this->optnObject->retrieveOption('urole', 'system'));
		$mthName = strtoupper(strval($this->optnObject->retrieveOption('mthod', 'GET')));
		if( !in_array($roleNme, Checker::ROLE_LIST, true) || !in_array($mthName, self::MTHD_LIST, true) ){
			$this->terminateShell('--urole takes one of '.implode(', ', Checker::ROLE_LIST).'; --mthod one of '.implode(', ', self::MTHD_LIST), self::EXIT_HELP);
		}
		$replyAr = (new ActCaller())->runAskedAction( strval($this->optnObject->retrieveOption('prset', '')), $actnPth, $inptVls, $mthName, 'cli:'.Console::osShellUsrName(), $roleNme );
		$savPath = $this->optnObject->retrieveOption('saved');
		if( $this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellPair('code', $replyAr['code']);
		}
		if( $savPath!==null ){
			if( @file_put_contents($savPath, $replyAr['body'])===false ){
				$this->terminateShell('The reply cannot be written to '.$savPath);
			}
			$this->printerObj->printShellPair('saved', $savPath);
		}elseif( $this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellPair('reply', json_decode($replyAr['body'], true) ?? $replyAr['body']);
		}elseif( $replyAr['body']!=='' ){
			$this->printerObj->printShellBody( $replyAr['body'].( substr($replyAr['body'], -1)=="\n" ? '' : PHP_EOL ) );
		}
		if( $replyAr['goal']!='' ){
			$this->printerObj->printShellPair('moved', $replyAr['goal']);
		}
		if( $replyAr['code']>=400 ){
			$this->printerObj->printShellFail( $replyAr['code'].' '.$replyAr['mssg'] );
			return ($replyAr['code']>=500) ? self::EXIT_FAIL : self::EXIT_DENY;
		}
		return self::EXIT_DONE;
	}

	/**
	 * Opens the store of the users: the one the login uses, or the one
	 * --store names.
	 *
	 * @return Object The manager of the native userbase (UserBase), or the writer of the users file (UserFile).
	 *
	 * @throws \nyu\Shell\ShellStop When no store is named, or its block is missing.
	 */
	protected function openUsersStore(): Object {
		$lgnConf = $this->accessSettings('login');
		$lgnAuth = strval($lgnConf['authby'] ?? '');
		$storNme = strval( $this->optnObject->retrieveOption('store', in_array($lgnAuth, Array('native', 'usrlst'), true) ? $lgnAuth : '') );
		if( !in_array($storNme, Array('native', 'usrlst'), true) ){
			$this->terminateShell('The login uses neither the native userbase nor the users file: name the store with --store=native or --store=usrlst', self::EXIT_HELP);
		}
		$blckNme = strval( $this->optnObject->retrieveOption('block', ($lgnAuth==$storNme) ? strval($lgnConf['authcf'] ?? $storNme) : $storNme) );
		$blckCfg = $this->accessSettings($blckNme);
		if( empty($blckCfg) && ($this->optnObject->validateOption('block') || $storNme=='native') ){
			$this->terminateShell('conf/access.json has no "'.$blckNme.'" block', self::EXIT_DENY);
		}
		$this->usersStore = $storNme;
		return ($storNme=='native') ? new UserBase($blckCfg) : new UserFile( $blckCfg, new ConfFile(TOP_DIR.DS.'temp'.DS.'backup') );
	}

	/**
	 * Ends the command on a refusal of the store, with its reason: a
	 * store that cannot be used is a failure, anything else a refusal.
	 *
	 * @param Object $dataStoreObj The store.
	 * @param String $defltMessage What is told when the store gives no reason.
	 *
	 * @throws \nyu\Shell\ShellStop Always.
	 */
	protected function stopStoreError( Object $dataStoreObj, String $defltMessage='The store of the users refused' ): Never {
		$whyText = strval($dataStoreObj->finalErrorText());
		$this->terminateShell( ($whyText!='') ? $whyText : $defltMessage, ($dataStoreObj->lastStateCount()>=500) ? self::EXIT_FAIL : self::EXIT_DENY );
	}

	/**
	 * Gives the password of a user: typed, with --typed, or made.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Array The password under 'pass', and whether it was made, and so is to be shown, under 'made'.
	 */
	protected function givenPassArray( String $namedUserTxt ): Array {
		if( !$this->optnObject->validateOption('typed') ){
			return Array('pass'=>bin2hex(random_bytes(10)), 'made'=>true);
		}
		return Array('pass'=>$this->querySecretTxt('Password of '.$namedUserTxt), 'made'=>false);
	}
}
