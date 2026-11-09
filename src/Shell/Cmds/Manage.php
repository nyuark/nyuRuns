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
 *   php nyuRun manage calls shows/index filters[search]=jazz
 *
 * The users are those of the store the login uses: the native userbase
 * (native) or the users file (usrlst); --store names the other, --block
 * the block of conf/access.json holding its settings. Every change is
 * traced on the 'access' log channel, as those made over HTTP.
 */
class Manage extends Command{

	// Methods a call may be said to use
	const MTHDLIST = Array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

	// The store the users are managed in: 'native' or 'usrlst'
	protected $storKind = '';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Manages the users (native userbase or users file), and calls an action of a run';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
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
		$storObj = $this->openStore();
		$usrList = $storObj->listUsers(
			Array('userStat'=>$this->optnObjc->getOption('ustat', ''), 'userRole'=>$this->optnObjc->getOption('urole', '')),
			intval($this->optnObjc->getOption('count', '0')),
			intval($this->optnObjc->getOption('start', '0'))
		);
		if( $usrList===false ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printRows( empty($usrList) ? Array('userName') : array_keys($usrList[0]), array_map('array_values', $usrList) );
		if( empty($usrList) ){
			$this->prntObjc->printLine('No user.');
		}
		return self::EXITDONE;
	}

	/**
	 * Shows what the store holds of the user named on the command line.
	 *
	 * @return Int The exit code.
	 */
	public function callViews(): Int {
		$storObj = $this->openStore();
		$usrVals = $storObj->userInfos( $this->needsWord('the user') );
		if( $usrVals===false ){
			$this->failStore($storObj);
		}
		foreach( $usrVals as $fldKey => $fldVal ){
			$this->prntObjc->printPair(strval($fldKey), $fldVal);
		}
		return self::EXITDONE;
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
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		$roleNme = strval($this->optnObjc->shiftWord() ?? '');
		$langTag = $this->optnObjc->getOption('ulang');
		$langTag = ($langTag!==null) ? strval($langTag) : null;
		if( $this->storKind=='native' ){
			$newCode = $storObj->enrolUser($usrName, $roleNme, null, $this->codesLast(), $langTag);
			if( $newCode===false ){
				$this->failStore($storObj);
			}
			$this->prntObjc->printPair('created', $usrName);
			$this->prntObjc->printOnce('Activation code of '.$usrName, strval($newCode));
			return self::EXITDONE;
		}
		$passArr = $this->givenPass($usrName);
		if( !$storObj->enrolUser($usrName, $roleNme, $passArr['pass'], $langTag) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair('created', $usrName);
		if( $passArr['made'] ){
			$this->prntObjc->printOnce('Password of '.$usrName, $passArr['pass']);
		}
		return self::EXITDONE;
	}

	/**
	 * Activates a pending user of the native userbase with its code, and
	 * gives it a password.
	 *
	 * @return Int The exit code.
	 */
	public function callActvt(): Int {
		$storObj = $this->openStore();
		if( $this->storKind!='native' ){
			$this->stopShell('The users file knows no activation: a user is created active', self::EXITDENY);
		}
		$usrName = $this->needsWord('the user');
		$gvnCode = $this->needsWord('the activation code');
		$passArr = $this->givenPass($usrName);
		if( !$storObj->actvtUser($usrName, $gvnCode, $passArr['pass']) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair('activated', $usrName);
		if( $passArr['made'] ){
			$this->prntObjc->printOnce('Password of '.$usrName, $passArr['pass']);
		}
		return self::EXITDONE;
	}

	/**
	 * Locks the user named on the command line.
	 *
	 * @return Int The exit code.
	 */
	public function callLocks(): Int {
		return $this->statusSet('locked');
	}

	/**
	 * Unlocks the user named on the command line: it is active again.
	 *
	 * @return Int The exit code.
	 */
	public function callUnlck(): Int {
		return $this->statusSet('active');
	}

	/**
	 * Gives the user named on the command line a status.
	 *
	 * @param String $statString 'locked' or 'active'.
	 * @return Int The exit code.
	 */
	protected function statusSet( String $statString ): Int {
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		if( !$storObj->setStatus($usrName, $statString) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair( ($statString=='locked') ? 'locked' : 'unlocked', $usrName );
		return self::EXITDONE;
	}

	/**
	 * Gives a user another role.
	 *
	 * @return Int The exit code.
	 */
	public function callRoles(): Int {
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		$roleNme = $this->needsWord('the role ('.implode(', ', Checker::ROLELIST).')');
		if( !$storObj->changRole($usrName, $roleNme) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair($usrName, $roleNme);
		return self::EXITDONE;
	}

	/**
	 * Removes a user, once confirmed.
	 *
	 * @return Int The exit code.
	 */
	public function callRemov(): Int {
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		if( !$this->askAgreed('Remove the user '.$usrName) ){
			$this->stopShell('Nothing was removed: not confirmed', self::EXITDENY);
		}
		if( !$storObj->removUser($usrName) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair('removed', $usrName);
		return self::EXITDONE;
	}

	/**
	 * For a user whose password is lost. In the native userbase it is put
	 * back to pending, with a new activation code shown once; in the
	 * users file it is given a new password.
	 *
	 * @return Int The exit code.
	 */
	public function callReset(): Int {
		$storObj = $this->openStore();
		if( $this->storKind!='native' ){
			return $this->passwdSet( $storObj, $this->needsWord('the user') );
		}
		$usrName = $this->needsWord('the user');
		$newCode = $storObj->resetUser($usrName, $this->codesLast());
		if( $newCode===false ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair('reset', $usrName);
		$this->prntObjc->printOnce('Activation code of '.$usrName, strval($newCode));
		return self::EXITDONE;
	}

	/**
	 * Approves a user of the native userbase who signed up itself and
	 * confirmed its address. Without a user, lists those awaiting review.
	 *
	 * @return Int The exit code.
	 */
	public function callProve(): Int {
		$storObj = $this->openStore();
		if( $this->storKind!='native' ){
			$this->stopShell('The users file knows no sign-up: a user is created active', self::EXITDENY);
		}
		$usrName = strval($this->optnObjc->shiftWord() ?? '');
		if( $usrName=='' ){
			$usrList = $storObj->listUsers( Array('userStat'=>'review') );
			if( $usrList===false ){
				$this->failStore($storObj);
			}
			$this->prntObjc->printRows( empty($usrList) ? Array('userName') : array_keys($usrList[0]), array_map('array_values', $usrList) );
			if( empty($usrList) ){
				$this->prntObjc->printLine('No user awaits review.');
			}
			return self::EXITDONE;
		}
		if( !$storObj->proveUser($usrName) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair('approved', $usrName);
		return self::EXITDONE;
	}

	/**
	 * Gives a user a mail address, or takes it away when none is given.
	 *
	 * @return Int The exit code.
	 */
	public function callEmail(): Int {
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		$mailTxt = strval($this->optnObjc->shiftWord() ?? '');
		if( !$storObj->chngeMail($usrName, $mailTxt) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair( ($mailTxt=='') ? 'address removed' : 'address set', $usrName );
		return self::EXITDONE;
	}

	/**
	 * Shows the language of the mails of a user, or gives it one, or
	 * takes it away with an empty word ('').
	 *
	 * @return Int The exit code.
	 */
	public function callLangs(): Int {
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		$langTag = $this->optnObjc->shiftWord();
		if( $langTag===null ){
			$usrVals = $storObj->userInfos($usrName);
			if( $usrVals===false ){
				$this->failStore($storObj);
			}
			$this->prntObjc->printPair( $usrName, (strval($usrVals['userLang'] ?? '')!=='') ? strval($usrVals['userLang']) : '(default)' );
			return self::EXITDONE;
		}
		if( !$storObj->chngeLang($usrName, strval($langTag)) ){
			$this->failStore($storObj);
		}
		$this->prntObjc->printPair( (strval($langTag)=='') ? 'language removed' : 'language set', $usrName );
		return self::EXITDONE;
	}

	/**
	 * Gives the minutes an activation code shown here lasts: those of a
	 * code handed over, or none with --noexp.
	 *
	 * @return Int The minutes, 0 for no end.
	 */
	protected function codesLast(): Int {
		return $this->optnObjc->hasOption('noexp') ? 0 : UserBase::ADMNMINS;
	}

	/**
	 * Gives a user a new password: made and shown once, or typed.
	 *
	 * @return Int The exit code.
	 */
	public function callPassw(): Int {
		return $this->passwdSet( $this->openStore(), $this->needsWord('the user') );
	}

	/**
	 * Gives a user a new password.
	 *
	 * @param Object $storeObjct The store of the users.
	 * @param String $userString Login name of the user.
	 * @return Int The exit code.
	 */
	protected function passwdSet( Object $storeObjct, String $userString ): Int {
		$passArr = $this->givenPass($userString);
		if( !$storeObjct->setPasswd($userString, $passArr['pass']) ){
			$this->failStore($storeObjct, 'Password not written: the user is unknown, or its store cannot be written');
		}
		if( $this->storKind=='native' ){
			$this->traceDone( 'Password of user '.json_encode($userString).' set' );
		}
		$this->prntObjc->printPair('password set', $userString);
		if( $passArr['made'] ){
			$this->prntObjc->printOnce('Password of '.$userString, $passArr['pass']);
		}
		return self::EXITDONE;
	}

	/**
	 * Gives a user a new personal API token, shown once, or takes the one
	 * it holds away with --clear.
	 *
	 * @return Int The exit code.
	 */
	public function callToken(): Int {
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		if( $this->optnObjc->hasOption('clear') ){
			if( !$this->askAgreed('Take the personal token of '.$usrName.' away') ){
				$this->stopShell('Nothing was removed: not confirmed', self::EXITDENY);
			}
			if( !$storObj->dropToken($usrName) ){
				$this->failStore($storObj);
			}
			$this->prntObjc->printPair('token removed', $usrName);
			return self::EXITDONE;
		}
		$newTokn = $storObj->makeToken($usrName);
		if( $newTokn===false ){
			$this->failStore($storObj, 'No token made: the user is unknown or not active, or its store cannot be written');
		}
		if( $this->storKind=='native' ){
			$this->traceDone( 'Personal token of user '.json_encode($usrName).' made' );
		}
		$this->prntObjc->printPair('token made', $usrName);
		$this->prntObjc->printOnce('Personal token of '.$usrName, strval($newTokn));
		return self::EXITDONE;
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
		$storObj = $this->openStore();
		$usrName = $this->needsWord('the user');
		if( $this->optnObjc->hasOption('clear') ){
			if( !$this->askAgreed('Take the 2FA secret of '.$usrName.' away: its password alone will log it in') ){
				$this->stopShell('Nothing was removed: not confirmed', self::EXITDENY);
			}
			if( !$storObj->dropTwoFa($usrName) ){
				$this->failStore($storObj);
			}
			$this->prntObjc->printPair('2FA removed', $usrName);
			return self::EXITDONE;
		}
		// Codes by mail : the users file is only read, and cannot keep a code
		if( $this->optnObjc->hasOption('email') ){
			if( !method_exists($storObj, 'mailTwoFa') ){
				$this->stopShell('Nothing was changed: codes by mail need the native userbase; the users file is only read', self::EXITDENY);
			}
			if( !$storObj->mailTwoFa($usrName) ){
				$this->failStore($storObj);
			}
			$this->traceDone( 'Mailed codes set for user '.json_encode($usrName) );
			$this->prntObjc->printPair('2FA set', $usrName.' (codes by mail)');
			return self::EXITDONE;
		}
		$newScrt = $storObj->makeTwoFa($usrName);
		if( $newScrt===false ){
			$this->failStore($storObj, 'No 2FA secret made: the user is unknown or not active, or its store cannot be written');
		}
		if( $this->storKind=='native' ){
			$this->traceDone( '2FA secret of user '.json_encode($usrName).' made' );
		}
		$this->prntObjc->printPair('2FA set', $usrName);
		$this->prntObjc->printOnce('2FA secret of '.$usrName, strval($newScrt));
		$otpAddr = 'otpauth://totp/nyuRuns:'.rawurlencode($usrName).'?secret='.$newScrt.'&issuer=nyuRuns';
		$this->prntObjc->printOnce('Address for the authenticator', $otpAddr);
		// The address as a QR code, for the authenticator to scan : it holds the secret, and is shown once too
		if( !$this->optnObjc->hasOption('plain') && !$this->prntObjc->jsonShape() ){
			$this->prntObjc->printBody( (new QrCode($otpAddr, 'M'))->asTextArt(2) );
		}
		return self::EXITDONE;
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
		$actPath = $this->needsWord('the action, as app/action');
		$inpVals = Array();
		$inpFile = $this->optnObjc->getOption('input');
		if( $inpFile!==null ){
			$inpText = ($inpFile=='-') ? stream_get_contents($this->inptStrm) : @file_get_contents($inpFile);
			$inpVals = json_decode(strval($inpText), true);
			if( !is_array($inpVals) ){
				$this->stopShell('The inputs of --input are not a JSON object', self::EXITUSGE);
			}
		}
		$pairLst = Array();
		foreach( $this->optnObjc->restWords() as $gvnWrd ){
			$pairPrt = explode('=', $gvnWrd, 2);
			if( count($pairPrt)!=2 || $pairPrt[0]=='' ){
				$this->stopShell('An input is written name=value: '.$gvnWrd, self::EXITUSGE);
			}
			$pairLst[] = str_replace(Array('%5B', '%5D'), Array('[', ']'), rawurlencode($pairPrt[0])).'='.rawurlencode($pairPrt[1]);
		}
		$wordVal = Array();
		parse_str(implode('&', $pairLst), $wordVal);
		$inpVals = array_replace_recursive($inpVals, $wordVal);
		$roleNme = strval($this->optnObjc->getOption('urole', 'system'));
		$mthName = strtoupper(strval($this->optnObjc->getOption('mthod', 'GET')));
		if( !in_array($roleNme, Checker::ROLELIST, true) || !in_array($mthName, self::MTHDLIST, true) ){
			$this->stopShell('--urole takes one of '.implode(', ', Checker::ROLELIST).'; --mthod one of '.implode(', ', self::MTHDLIST), self::EXITUSGE);
		}
		$replyAr = (new ActCaller())->runAction( strval($this->optnObjc->getOption('prset', '')), $actPath, $inpVals, $mthName, 'cli:'.Console::osUserNme(), $roleNme );
		$savePth = $this->optnObjc->getOption('saved');
		if( $this->prntObjc->jsonShape() ){
			$this->prntObjc->printPair('code', $replyAr['code']);
		}
		if( $savePth!==null ){
			if( @file_put_contents($savePth, $replyAr['body'])===false ){
				$this->stopShell('The reply cannot be written to '.$savePth);
			}
			$this->prntObjc->printPair('saved', $savePth);
		}elseif( $this->prntObjc->jsonShape() ){
			$this->prntObjc->printPair('reply', json_decode($replyAr['body'], true) ?? $replyAr['body']);
		}elseif( $replyAr['body']!=='' ){
			$this->prntObjc->printBody( $replyAr['body'].( substr($replyAr['body'], -1)=="\n" ? '' : PHP_EOL ) );
		}
		if( $replyAr['goal']!='' ){
			$this->prntObjc->printPair('moved', $replyAr['goal']);
		}
		if( $replyAr['code']>=400 ){
			$this->prntObjc->printFail( $replyAr['code'].' '.$replyAr['mssg'] );
			return ($replyAr['code']>=500) ? self::EXITFAIL : self::EXITDENY;
		}
		return self::EXITDONE;
	}

	/**
	 * Opens the store of the users: the one the login uses, or the one
	 * --store names.
	 *
	 * @return Object The manager of the native userbase (UserBase), or the writer of the users file (UserFile).
	 *
	 * @throws \nyu\Shell\ShellStop When no store is named, or its block is missing.
	 */
	protected function openStore(): Object {
		$lgnConf = $this->accessCnf('login');
		$lgnAuth = strval($lgnConf['authby'] ?? '');
		$storNme = strval( $this->optnObjc->getOption('store', in_array($lgnAuth, Array('native', 'usrlst'), true) ? $lgnAuth : '') );
		if( !in_array($storNme, Array('native', 'usrlst'), true) ){
			$this->stopShell('The login uses neither the native userbase nor the users file: name the store with --store=native or --store=usrlst', self::EXITUSGE);
		}
		$blkName = strval( $this->optnObjc->getOption('block', ($lgnAuth==$storNme) ? strval($lgnConf['authcf'] ?? $storNme) : $storNme) );
		$blkConf = $this->accessCnf($blkName);
		if( empty($blkConf) && ($this->optnObjc->hasOption('block') || $storNme=='native') ){
			$this->stopShell('conf/access.json has no "'.$blkName.'" block', self::EXITDENY);
		}
		$this->storKind = $storNme;
		return ($storNme=='native') ? new UserBase($blkConf) : new UserFile( $blkConf, new ConfFile(TOP_DIR.DS.'temp'.DS.'backup') );
	}

	/**
	 * Ends the command on a refusal of the store, with its reason: a
	 * store that cannot be used is a failure, anything else a refusal.
	 *
	 * @param Object $storeObjct The store.
	 * @param String $defaultMsg What is told when the store gives no reason.
	 *
	 * @throws \nyu\Shell\ShellStop Always.
	 */
	protected function failStore( Object $storeObjct, String $defaultMsg='The store of the users refused' ): Never {
		$whyText = strval($storeObjct->lastError());
		$this->stopShell( ($whyText!='') ? $whyText : $defaultMsg, ($storeObjct->lastState()>=500) ? self::EXITFAIL : self::EXITDENY );
	}

	/**
	 * Gives the password of a user: typed, with --typed, or made.
	 *
	 * @param String $userString Login name of the user.
	 * @return Array The password under 'pass', and whether it was made, and so is to be shown, under 'made'.
	 */
	protected function givenPass( String $userString ): Array {
		if( !$this->optnObjc->hasOption('typed') ){
			return Array('pass'=>bin2hex(random_bytes(10)), 'made'=>true);
		}
		return Array('pass'=>$this->askSecret('Password of '.$userString), 'made'=>false);
	}
}
