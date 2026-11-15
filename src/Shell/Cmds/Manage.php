<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Checker;
use nyu\Access\Auth\UserBase;
use nyu\Data\QrCode;
use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Shell\UserFile;

/**
 * Manages the users:
 *
 *   php nyuRun account fullList --roleName=managers  the users, filtered or not
 *   php nyuRun account makeUser ann system         creates a user
 *   php nyuRun account lockUser ann                locks it (unlck unlocks)
 *   php nyuRun account password ann                gives it a new password, made and shown once (--typeItIn to type it)
 *   php nyuRun account setTwoFa ann --removeIt     takes its 2FA secret away, when its authenticator is lost
 *   php nyuRun account setTwoFa ann --emailOtp     has it give a code sent to its address at each login
 *   php nyuRun account approves                    the sign-ups awaiting review (prove ann approves one)
 *
 * The users are those of the store the login uses: the native userbase
 * (nativeDb) or the users file (userFile); --useStore names the other, --useBlock
 * the block of conf/access.json holding its settings. Every change is
 * traced on the 'access' log channel, as those made over HTTP.
 */
class Manage extends Command{

	// The store the users are managed in: 'nativeDb' or 'userFile'
	protected $usersStore = '';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function renderSynopsis(): String {
		return 'Manages the users (native userbase or users file)';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function listSubCmndDct(): Array {
		return Array(
			'fullList'=>Array('args'=>'[--statusIs=<status>] [--roleName=<role>] [--rowCount=<n>] [--startRow=<n>]', 'about'=>'lists the users'),
			'viewUser'=>Array('args'=>'<user>', 'about'=>'shows a user'),
			'makeUser'=>Array('args'=>'<user> [role] [--typeItIn] [--noExpiry] [--language=<language>]', 'about'=>'creates a user: pending with an activation code lasting 300 minutes (--noExpiry: no end) (nativeDb), active with its password (userFile); --language gives the language of its mails'),
			'activate'=>Array('args'=>'<user> <code> [--typeItIn]', 'about'=>'activates a pending user with its code, and gives it a password (nativeDb)'),
			'lockUser'=>Array('args'=>'<user>', 'about'=>'locks a user'),
			'freeUser'=>Array('args'=>'<user>', 'about'=>'unlocks a user'),
			'giveRole'=>Array('args'=>'<user> <role>', 'about'=>'gives a user another role'),
			'dropUser'=>Array('args'=>'<user>', 'about'=>'removes a user'),
			'resetPwd'=>Array('args'=>'<user> [--noExpiry]', 'about'=>'for a lost password: a new activation code lasting 300 minutes (--noExpiry: no end) (nativeDb), a new password (userFile)'),
			'approves'=>Array('args'=>'[user]', 'about'=>'approves a user who signed up and confirmed its address; without a user, lists those awaiting review (nativeDb)'),
			'setEmail'=>Array('args'=>'<user> [address]', 'about'=>'gives a user a mail address, or takes it away when none is given'),
			'language'=>Array('args'=>'<user> [language]', 'about'=>'gives a user the language of its mails (fr, fr-ch), or takes it away when none is given: the default one (DEF_LANG) then'),
			'password'=>Array('args'=>'<user> [--typeItIn]', 'about'=>'gives a user a new password'),
			'apiToken'=>Array('args'=>'<user> [--removeIt]', 'about'=>'gives a user a new personal API token, or takes it away'),
			'setTwoFa'=>Array('args'=>'<user> [--removeIt] [--noQrCode] [--emailOtp]', 'about'=>'gives a user a new 2FA secret, shown with its QR code (--noQrCode: without), codes by mail (--emailOtp), or takes it away')
		);
	}

	/**
	 * Lists the users, by status and role when asked, a part of the list at a time.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$storeObj = $this->openUsersStore();
		$userList = $storeObj->listUsersNamed(
			Array('userStatus'=>$this->optionsObj->getOptionValue('statusIs', ''), 'accessRole'=>$this->optionsObj->getOptionValue('roleName', '')),
			intval($this->optionsObj->getOptionValue('rowCount', '0')),
			intval($this->optionsObj->getOptionValue('startRow', '0'))
		);
		if( $userList===false ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellRows( empty($userList) ? Array('systemName') : array_keys($userList[0]), array_map('array_values', $userList) );
		if( empty($userList) ){
			$this->printerObj->writeShellLine('No user.');
		}
		return self::EXIT_DONE;
	}

	/**
	 * Shows what the store holds of the user named on the command line.
	 *
	 * @return Int The exit code.
	 */
	public function invokeViewUser(): Int {
		$storeObj = $this->openUsersStore();
		$userVals = $storeObj->readUserToShow( $this->needsShellWord('the user') );
		if( $userVals===false ){
			$this->stopStoreError($storeObj);
		}
		foreach( $userVals as $oneKey => $oneVal ){
			$this->printerObj->writeShellPair(strval($oneKey), $oneVal);
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
	public function invokeMakeUser(): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		$roleName = strval($this->optionsObj->shiftFirstWord() ?? '');
		$langgTag = $this->optionsObj->getOptionValue('language');
		$langgTag = ($langgTag!==null) ? strval($langgTag) : null;
		if( $this->usersStore=='nativeDb' ){
			$newCodig = $storeObj->enrolUsrString($userName, $roleName, null, $this->getCodeMinutes(), $langgTag);
			if( $newCodig===false ){
				$this->stopStoreError($storeObj);
			}
			$this->printerObj->writeShellPair('created', $userName);
			$this->printerObj->writeShellOnce('Activation code of '.$userName, strval($newCodig));
			return self::EXIT_DONE;
		}
		$passList = $this->getNewPassword($userName);
		if( !$storeObj->enrolUsrString($userName, $roleName, $passList['pass'], $langgTag) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair('created', $userName);
		if( $passList['made'] ){
			$this->printerObj->writeShellOnce('Password of '.$userName, $passList['pass']);
		}
		return self::EXIT_DONE;
	}

	/**
	 * Activates a pending user of the native userbase with its code, and
	 * gives it a password.
	 *
	 * @return Int The exit code.
	 */
	public function invokeActivate(): Int {
		$storeObj = $this->openUsersStore();
		if( $this->usersStore!='nativeDb' ){
			$this->stopWithReason('The users file knows no activation: a user is created active', self::EXIT_DENY);
		}
		$userName = $this->needsShellWord('the user');
		$givenCod = $this->needsShellWord('the activation code');
		$passList = $this->getNewPassword($userName);
		if( !$storeObj->activateByCode($userName, $givenCod, $passList['pass']) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair('activated', $userName);
		if( $passList['made'] ){
			$this->printerObj->writeShellOnce('Password of '.$userName, $passList['pass']);
		}
		return self::EXIT_DONE;
	}

	/**
	 * Locks the user named on the command line.
	 *
	 * @return Int The exit code.
	 */
	public function invokeLockUser(): Int {
		return $this->saveUserStatus('disabled');
	}

	/**
	 * Unlocks the user named on the command line: it is active again.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFreeUser(): Int {
		return $this->saveUserStatus('approved');
	}

	/**
	 * Gives the user named on the command line a status.
	 *
	 * @param String $statusString 'disabled' or 'approved'.
	 * @return Int The exit code.
	 */
	protected function saveUserStatus( String $statusString ): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		if( !$storeObj->saveUserStatus($userName, $statusString) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair( ($statusString=='disabled') ? 'locked' : 'unlocked', $userName );
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user another role.
	 *
	 * @return Int The exit code.
	 */
	public function invokeGiveRole(): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		$roleName = $this->needsShellWord('the role ('.implode(', ', Checker::ROLE_LIST).')');
		if( !$storeObj->alterRoleOfUsr($userName, $roleName) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair($userName, $roleName);
		return self::EXIT_DONE;
	}

	/**
	 * Removes a user, once confirmed.
	 *
	 * @return Int The exit code.
	 */
	public function invokeDropUser(): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		if( !$this->promptApproval('Remove the user '.$userName) ){
			$this->stopWithReason('Nothing was removed: not confirmed', self::EXIT_DENY);
		}
		if( !$storeObj->dropUsrAccount($userName) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair('removed', $userName);
		return self::EXIT_DONE;
	}

	/**
	 * For a user whose password is lost. In the native userbase it is put
	 * back to pending, with a new activation code shown once; in the
	 * users file it is given a new password.
	 *
	 * @return Int The exit code.
	 */
	public function invokeResetPwd(): Int {
		$storeObj = $this->openUsersStore();
		if( $this->usersStore!='nativeDb' ){
			return $this->savePassOfUser( $storeObj, $this->needsShellWord('the user') );
		}
		$userName = $this->needsShellWord('the user');
		$newCodig = $storeObj->clearUserAccnt($userName, $this->getCodeMinutes());
		if( $newCodig===false ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair('reset', $userName);
		$this->printerObj->writeShellOnce('Activation code of '.$userName, strval($newCodig));
		return self::EXIT_DONE;
	}

	/**
	 * Approves a user of the native userbase who signed up itself and
	 * confirmed its address. Without a user, lists those awaiting review.
	 *
	 * @return Int The exit code.
	 */
	public function invokeApproves(): Int {
		$storeObj = $this->openUsersStore();
		if( $this->usersStore!='nativeDb' ){
			$this->stopWithReason('The users file knows no sign-up: a user is created active', self::EXIT_DENY);
		}
		$userName = strval($this->optionsObj->shiftFirstWord() ?? '');
		if( $userName=='' ){
			$userList = $storeObj->listUsersNamed( Array('userStatus'=>'inReview') );
			if( $userList===false ){
				$this->stopStoreError($storeObj);
			}
			$this->printerObj->writeShellRows( empty($userList) ? Array('systemName') : array_keys($userList[0]), array_map('array_values', $userList) );
			if( empty($userList) ){
				$this->printerObj->writeShellLine('No user awaits review.');
			}
			return self::EXIT_DONE;
		}
		if( !$storeObj->approveNewUser($userName) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair('approved', $userName);
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user a mail address, or takes it away when none is given.
	 *
	 * @return Int The exit code.
	 */
	public function invokeSetEmail(): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		$mailText = strval($this->optionsObj->shiftFirstWord() ?? '');
		if( !$storeObj->alterUserEmail($userName, $mailText) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair( ($mailText=='') ? 'address removed' : 'address set', $userName );
		return self::EXIT_DONE;
	}

	/**
	 * Shows the language of the mails of a user, or gives it one, or
	 * takes it away with an empty word ('').
	 *
	 * @return Int The exit code.
	 */
	public function invokeLanguage(): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		$langgTag = $this->optionsObj->shiftFirstWord();
		if( $langgTag===null ){
			$userVals = $storeObj->readUserToShow($userName);
			if( $userVals===false ){
				$this->stopStoreError($storeObj);
			}
			$this->printerObj->writeShellPair( $userName, (strval($userVals['userLocale'] ?? '')!=='') ? strval($userVals['userLocale']) : '(default)' );
			return self::EXIT_DONE;
		}
		if( !$storeObj->alterMailsLang($userName, strval($langgTag)) ){
			$this->stopStoreError($storeObj);
		}
		$this->printerObj->writeShellPair( (strval($langgTag)=='') ? 'language removed' : 'language set', $userName );
		return self::EXIT_DONE;
	}

	/**
	 * Gives the minutes an activation code shown here lasts: those of a
	 * code handed over, or none with --noExpiry.
	 *
	 * @return Int The minutes, 0 for no end.
	 */
	protected function getCodeMinutes(): Int {
		return $this->optionsObj->isOptionPassed('noExpiry') ? 0 : UserBase::HAND_MINS;
	}

	/**
	 * Gives a user a new password: made and shown once, or typed.
	 *
	 * @return Int The exit code.
	 */
	public function invokePassword(): Int {
		return $this->savePassOfUser( $this->openUsersStore(), $this->needsShellWord('the user') );
	}

	/**
	 * Gives a user a new password.
	 *
	 * @param Object $dataStoreObj The store of the users.
	 * @param String $namedUserTxt Login name of the user.
	 * @return Int The exit code.
	 */
	protected function savePassOfUser( Object $dataStoreObj, String $namedUserTxt ): Int {
		$passList = $this->getNewPassword($namedUserTxt);
		if( !$dataStoreObj->savePassOfUser($namedUserTxt, $passList['pass']) ){
			$this->stopStoreError($dataStoreObj, 'Password not written: the user is unknown, or its store cannot be written');
		}
		if( $this->usersStore=='nativeDb' ){
			$this->logShellChange( 'Password of user '.json_encode($namedUserTxt).' set' );
		}
		$this->printerObj->writeShellPair('password set', $namedUserTxt);
		if( $passList['made'] ){
			$this->printerObj->writeShellOnce('Password of '.$namedUserTxt, $passList['pass']);
		}
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user a new personal API token, shown once, or takes the one
	 * it holds away with --removeIt.
	 *
	 * @return Int The exit code.
	 */
	public function invokeApiToken(): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		if( $this->optionsObj->isOptionPassed('removeIt') ){
			if( !$this->promptApproval('Take the personal token of '.$userName.' away') ){
				$this->stopWithReason('Nothing was removed: not confirmed', self::EXIT_DENY);
			}
			if( !$storeObj->clearUserToken($userName) ){
				$this->stopStoreError($storeObj);
			}
			$this->printerObj->writeShellPair('token removed', $userName);
			return self::EXIT_DONE;
		}
		$newToken = $storeObj->makeToknString($userName);
		if( $newToken===false ){
			$this->stopStoreError($storeObj, 'No token made: the user is unknown or not active, or its store cannot be written');
		}
		if( $this->usersStore=='nativeDb' ){
			$this->logShellChange( 'Personal token of user '.json_encode($userName).' made' );
		}
		$this->printerObj->writeShellPair('token made', $userName);
		$this->printerObj->writeShellOnce('Personal token of '.$userName, strval($newToken));
		return self::EXIT_DONE;
	}

	/**
	 * Gives a user a new 2FA secret, shown once with the address an
	 * authenticator reads and its QR code (left out with --noQrCode, and in
	 * JSON), has it give a code mailed at each login instead (--emailOtp,
	 * native userbase only), or takes either away with --removeIt.
	 *
	 * @return Int The exit code.
	 */
	public function invokeSetTwoFa(): Int {
		$storeObj = $this->openUsersStore();
		$userName = $this->needsShellWord('the user');
		if( $this->optionsObj->isOptionPassed('removeIt') ){
			if( !$this->promptApproval('Take the 2FA secret of '.$userName.' away: its password alone will log it in') ){
				$this->stopWithReason('Nothing was removed: not confirmed', self::EXIT_DENY);
			}
			if( !$storeObj->clearTwoFactor($userName) ){
				$this->stopStoreError($storeObj);
			}
			$this->printerObj->writeShellPair('2FA removed', $userName);
			return self::EXIT_DONE;
		}
		// Codes by mail : the users file is only read, and cannot keep a code
		if( $this->optionsObj->isOptionPassed('emailOtp') ){
			if( !method_exists($storeObj, 'setEmailOtpWay') ){
				$this->stopWithReason('Nothing was changed: codes by mail need the native userbase; the users file is only read', self::EXIT_DENY);
			}
			if( !$storeObj->setEmailOtpWay($userName) ){
				$this->stopStoreError($storeObj);
			}
			$this->logShellChange( 'Mailed codes set for user '.json_encode($userName) );
			$this->printerObj->writeShellPair('2FA set', $userName.' (codes by mail)');
			return self::EXIT_DONE;
		}
		$newSecrt = $storeObj->makeTotpSecret($userName);
		if( $newSecrt===false ){
			$this->stopStoreError($storeObj, 'No 2FA secret made: the user is unknown or not active, or its store cannot be written');
		}
		if( $this->usersStore=='nativeDb' ){
			$this->logShellChange( '2FA secret of user '.json_encode($userName).' made' );
		}
		$this->printerObj->writeShellPair('2FA set', $userName);
		$this->printerObj->writeShellOnce('2FA secret of '.$userName, strval($newSecrt));
		$otpAddrs = 'otpauth://totp/nyuRuns:'.rawurlencode($userName).'?secret='.$newSecrt.'&issuer=nyuRuns';
		$this->printerObj->writeShellOnce('Address for the authenticator', $otpAddrs);
		// The address as a QR code, for the authenticator to scan : it holds the secret, and is shown once too
		if( !$this->optionsObj->isOptionPassed('noQrCode') && !$this->printerObj->isOutputAsJson() ){
			$this->printerObj->writeShellBody( (new QrCode($otpAddrs, 'M'))->renderShellTxt(2) );
		}
		return self::EXIT_DONE;
	}

	/**
	 * Opens the store of the users: the one the login uses, or the one
	 * --useStore names.
	 *
	 * @return Object The manager of the native userbase (UserBase), or the writer of the users file (UserFile).
	 *
	 * @throws \nyu\Shell\ShellStop When no store is named, or its block is missing.
	 */
	protected function openUsersStore(): Object {
		$loginCfg = $this->findAccessDict('loginSet');
		$lognAuth = strval($loginCfg['authWith'] ?? '');
		$storeNme = strval( $this->optionsObj->getOptionValue('useStore', in_array($lognAuth, Array('nativeDb', 'userFile'), true) ? $lognAuth : '') );
		if( !in_array($storeNme, Array('nativeDb', 'userFile'), true) ){
			$this->stopWithReason('The login uses neither the native userbase nor the users file: name the store with --useStore=nativeDb or --useStore=userFile', self::EXIT_HELP);
		}
		$blockNme = strval( $this->optionsObj->getOptionValue('useBlock', ($lognAuth==$storeNme) ? strval($loginCfg['authConf'] ?? $storeNme) : $storeNme) );
		$blockCfg = $this->findAccessDict($blockNme);
		if( empty($blockCfg) && ($this->optionsObj->isOptionPassed('useBlock') || $storeNme=='nativeDb') ){
			$this->stopWithReason('conf/access.json has no "'.$blockNme.'" block', self::EXIT_DENY);
		}
		$this->usersStore = $storeNme;
		return ($storeNme=='nativeDb') ? new UserBase($blockCfg) : new UserFile( $blockCfg, new ConfFile(TOP_DIR.DS.'temp'.DS.'archives') );
	}

	/**
	 * Ends the command on a refusal of the store, with its reason: a
	 * store that cannot be used is a failure, anything else a refusal.
	 *
	 * @param Object $dataStoreObj The store.
	 * @param String $fallbackText What is told when the store gives no reason.
	 *
	 * @throws \nyu\Shell\ShellStop Always.
	 */
	protected function stopStoreError( Object $dataStoreObj, String $fallbackText='The store of the users refused' ): Never {
		$whyStrng = strval($dataStoreObj->getLastErrText());
		$this->stopWithReason( ($whyStrng!='') ? $whyStrng : $fallbackText, ($dataStoreObj->getRefusalCode()>=500) ? self::EXIT_FAIL : self::EXIT_DENY );
	}

	/**
	 * Gives the password of a user: typed, with --typeItIn, or made.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Array The password under 'pass', and whether it was made, and so is to be shown, under 'made'.
	 */
	protected function getNewPassword( String $namedUserTxt ): Array {
		if( !$this->optionsObj->isOptionPassed('typeItIn') ){
			return Array('pass'=>bin2hex(random_bytes(10)), 'made'=>true);
		}
		return Array('pass'=>$this->promptSecrText('Password of '.$namedUserTxt), 'made'=>false);
	}
}
