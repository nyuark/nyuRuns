<?php

namespace nyu\Shell;

use nyu\Access\Checker;
use nyu\Access\Totp;
use nyu\Access\Auth\Native;
use nyu\Access\Auth\UserBase;
use nyu\Access\Auth\UserList;
use nyu\Tools\Language;
use nyu\Tools\Logger;

/**
 * Writes the users file (see Access\Auth\UserList), which the
 * application only reads: the command line is the one to change it. It
 * answers to the names UserBase has for the native userbase. The file
 * is written whole (a temporary file, then renamed), so a request reading
 * it sees the former users or the new ones, never a part of them; two
 * commands at once wait for each other. A file the application would no
 * longer accept is not left in place.
 *
 * Without a database there is no activation: a user is created active,
 * with its password. After a refusal, finalErrorText() tells why and
 * lastStateCount() gives the status it stands for, as UserBase does.
 */
class UserFile{

	// The fields of a user, in the order they are written; any other field a person added is kept after them
	const FLDS_LIST = Array('id_user', 'userName', 'passHash', 'userRole', 'userStat', 'userMail', 'userLang', 'tokenKey', 'twoFaKey');
	// What is told about a user : never a hash or a secret
	const SHOW_LIST = Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'userLang');
	// Longest password, in bytes : bcrypt reads nothing beyond
	const PASS_SIZE = 72;

	protected $nameOfFile;
	protected $usrLstPath;
	protected $minPasswrd;
	protected $sealingKey;
	protected $fileWriter;
	protected $lastMessag = '';
	protected $latestCode = 200;

	/**
	 * Builds the writer of the users file from its config block.
	 *
	 * @param Array    $configValues Config block with the 'usrfle' path of the users file, relative to the project root
	 *                             (conf/users.lst when left out), and optionally the shortest password accepted,
	 *                             in characters ('minlen', defaults to 10) and the key the 2FA secrets are written
	 *                             encrypted with ('tfakey', 32 characters at least; without one they are written readable).
	 * @param ConfFile $writerObject The writer of config files.
	 */
	function __construct( Array $configValues, ConfFile $writerObject ){
		$fileNme = is_scalar($configValues['usrfle'] ?? '') ? trim( strval($configValues['usrfle'] ?? ''), '/\\ ' ) : '';
		$this->nameOfFile = ($fileNme!='') ? $fileNme : 'conf/users.lst';
		$this->usrLstPath = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $this->nameOfFile);
		$this->minPasswrd = min( self::PASS_SIZE, max(1, intval($configValues['minlen'] ?? 10)) );
		$this->sealingKey = is_scalar($configValues['tfakey'] ?? '') ? strval($configValues['tfakey'] ?? '') : '';
		$this->fileWriter = $writerObject;
	}

	/**
	 * Lists the users of the file, by name, with what SHOWLIST tells of
	 * each, and whether it holds a personal token and a 2FA secret.
	 *
	 * @param Array $criteriaList Optional 'userStat' and 'userRole' values the users must have; an empty one is left out.
	 * @param Int   $ceilingCount Most users to give; all of them when below 1.
	 * @param Int   $offsetRowsNb Users to pass over first.
	 * @return Array|Bool The users, or false when a filter is not valid or the file cannot be used.
	 */
	public function listUsersNamed( Array $criteriaList=Array(), Int $ceilingCount=0, Int $offsetRowsNb=0 ): Array|Bool {
		$this->forgetLastFail();
		foreach( Array('userStat'=>Native::STAT_LIST, 'userRole'=>Checker::ROLE_LIST) as $fldNme => $allows ){
			$gvnText = $criteriaList[$fldNme] ?? '';
			if( $gvnText!=='' && $gvnText!==null && !in_array($gvnText, $allows, true) ){
				return $this->declineForFlag(400, 'Invalid filter');
			}
		}
		$usrList = $this->loadUsersArray();
		if( $usrList===null ){
			return false;
		}
		$rowList = Array();
		foreach( $usrList as $usrRow ){
			$shownAr = $this->shownValuesArr($usrRow);
			if( in_array($criteriaList['userStat'] ?? '', Array('', null, $shownAr['userStat']), true) && in_array($criteriaList['userRole'] ?? '', Array('', null, $shownAr['userRole']), true) ){
				$rowList[] = $shownAr;
			}
		}
		usort($rowList, function( Array $lftUsrRowArr, Array $secondUsrRow ): Int {
			return strcmp($lftUsrRowArr['userName'], $secondUsrRow['userName']);
		});
		return array_slice( $rowList, max(0, $offsetRowsNb), ($ceilingCount>0) ? $ceilingCount : null );
	}

	/**
	 * Reads one user of the file.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Array|Bool The user, or false when it is unknown or the file cannot be used.
	 */
	public function userInfosArray( String $namedUserTxt ): Array|Bool {
		$this->forgetLastFail();
		$usrList = $this->loadUsersArray();
		if( $usrList===null ){
			return false;
		}
		$usrIndx = $this->findIndexCount($usrList, $namedUserTxt);
		return ($usrIndx===null) ? $this->declineForFlag(404, 'User not found') : $this->shownValuesArr($usrList[$usrIndx]);
	}

	/**
	 * Adds a user to the file, active, with its password. A name that
	 * only differs from an existing one by its case is refused.
	 *
	 * @param String $namedUserTxt Login name of the new user (see UserBase::NAME_RULE and NAMEDENY).
	 * @param String $usrRleString Its role, one of Checker::ROLE_LIST; the first one when left empty.
	 * @param String  $usrPasswdTxt Its password, following the rules (see checkPass).
	 * @param ?String $langTagStrng The language of its mails (see Tools\Language), none when null or empty.
	 * @return Bool Whether the user was added.
	 */
	public function enrolUsrString( String $namedUserTxt, String $usrRleString, String $usrPasswdTxt, ?String $langTagStrng=null ): Bool {
		$this->forgetLastFail();
		$roleVal = ($usrRleString!='') ? $usrRleString : Checker::ROLE_LIST[0];
		if( !preg_match(UserBase::NAME_RULE, $namedUserTxt) || in_array(strtolower($namedUserTxt), UserBase::NAME_DENY, true) ){
			return $this->declineForFlag(400, 'Invalid user name');
		}
		if( !in_array($roleVal, Checker::ROLE_LIST, true) ){
			return $this->declineForFlag(400, 'Invalid role');
		}
		$langVal = strtolower( trim(str_replace('_', '-', strval($langTagStrng))) );
		if( $langVal!=='' && !Language::isValidLangTag($langVal) ){
			return $this->declineForFlag(400, 'Invalid language');
		}
		$ruleMsg = $this->testPassString($namedUserTxt, $usrPasswdTxt);
		if( $ruleMsg!='' ){
			return $this->declineForFlag(400, $ruleMsg);
		}
		$isAdded = $this->alterUsersFlag( null, function( Array $usersRecords ) use ( $namedUserTxt, $roleVal, $usrPasswdTxt, $langVal ): ?Array {
			$lastNum = 0;
			foreach( $usersRecords as $usrRow ){
				if( strcasecmp(strval($usrRow['userName'] ?? ''), $namedUserTxt)==0 ){
					$this->declineForFlag(409, 'User name already taken');
					return null;
				}
				$lastNum = max( $lastNum, intval($usrRow['id_user'] ?? 0) );
			}
			$usersRecords[] = Array('id_user'=>$lastNum+1, 'userName'=>$namedUserTxt, 'passHash'=>password_hash($usrPasswdTxt, PASSWORD_BCRYPT), 'userRole'=>$roleVal, 'userStat'=>'active') + ( ($langVal!=='') ? Array('userLang'=>$langVal) : Array() );
			return $usersRecords;
		} );
		if( $isAdded ){
			$this->logFileChanges( 'User '.json_encode($namedUserTxt).' created ('.$roleVal.')' );
		}
		return $isAdded;
	}

	/**
	 * Locks a user, or makes it active. The last active user of the
	 * highest role cannot be locked.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $statusString The status to give: 'locked' or 'active'.
	 * @return Bool Whether the status was changed.
	 */
	public function saveStatusFlag( String $namedUserTxt, String $statusString ): Bool {
		$this->forgetLastFail();
		if( !in_array($statusString, Array('active', 'locked'), true) ){
			return $this->declineForFlag(400, 'Invalid status');
		}
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $statusString ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['userStat'] ?? 'active')==$statusString ){
				$this->declineForFlag(409, 'User already '.$statusString);
				return null;
			}
			if( $statusString=='locked' && $this->lastManagerFlg($usersRecords, $usersIndxNum) ){
				$this->declineForFlag(409, 'The last active manager cannot be locked');
				return null;
			}
			$usersRecords[$usersIndxNum]['userStat'] = $statusString;
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( 'User '.json_encode($namedUserTxt).' '.($statusString=='locked' ? 'locked' : 'unlocked') );
		}
		return $isSaved;
	}

	/**
	 * Gives a user another role. The last active user of the highest
	 * role cannot be given a lower one.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrRleString The role to give, one of Checker::ROLE_LIST.
	 * @return Bool Whether the role was changed.
	 */
	public function changeRoleFlag( String $namedUserTxt, String $usrRleString ): Bool {
		$this->forgetLastFail();
		if( !in_array($usrRleString, Checker::ROLE_LIST, true) ){
			return $this->declineForFlag(400, 'Invalid role');
		}
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $usrRleString ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['userRole'] ?? Checker::ROLE_LIST[0])==$usrRleString ){
				$this->declineForFlag(409, 'User already of that role');
				return null;
			}
			if( $this->lastManagerFlg($usersRecords, $usersIndxNum) ){
				$this->declineForFlag(409, 'The last active manager cannot be given another role');
				return null;
			}
			$usersRecords[$usersIndxNum]['userRole'] = $usrRleString;
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( 'Role of user '.json_encode($namedUserTxt).' changed to '.$usrRleString );
		}
		return $isSaved;
	}

	/**
	 * Gives a user a mail address, in lower case, or takes it away. An
	 * address another user holds is refused.
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param ?String $emailAddress The address, none when null or empty.
	 * @return Bool Whether the address was written.
	 */
	public function chngeEmailFlag( String $namedUserTxt, ?String $emailAddress ): Bool {
		$this->forgetLastFail();
		$mailVal = strtolower( trim(strval($emailAddress)) );
		if( $mailVal!=='' && (strlen($mailVal)>254 || filter_var($mailVal, FILTER_VALIDATE_EMAIL)===false) ){
			return $this->declineForFlag(400, 'Invalid mail address');
		}
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $mailVal ): ?Array {
			foreach( $usersRecords as $usrPos => $usrRow ){
				if( $mailVal!=='' && $usrPos!==$usersIndxNum && strtolower(strval($usrRow['userMail'] ?? ''))===$mailVal ){
					$this->declineForFlag(409, 'Mail address already used');
					return null;
				}
			}
			if( $mailVal==='' ){
				unset($usersRecords[$usersIndxNum]['userMail']);
			}else{
				$usersRecords[$usersIndxNum]['userMail'] = $mailVal;
			}
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( 'Address of user '.json_encode($namedUserTxt).' '.($mailVal==='' ? 'removed' : 'set') );
		}
		return $isSaved;
	}

	/**
	 * Gives a user the language of its mails, in lower case, or takes it
	 * away.
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param ?String $langTagStrng The language (see Tools\Language), none when null or empty.
	 * @return Bool Whether the language was written.
	 */
	public function changeLanguage( String $namedUserTxt, ?String $langTagStrng ): Bool {
		$this->forgetLastFail();
		$langVal = strtolower( trim(str_replace('_', '-', strval($langTagStrng))) );
		if( $langVal!=='' && !Language::isValidLangTag($langVal) ){
			return $this->declineForFlag(400, 'Invalid language');
		}
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $langVal ): ?Array {
			if( $langVal==='' ){
				unset($usersRecords[$usersIndxNum]['userLang']);
			}else{
				$usersRecords[$usersIndxNum]['userLang'] = $langVal;
			}
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( 'Language of user '.json_encode($namedUserTxt).' '.($langVal==='' ? 'removed' : 'set to '.$langVal) );
		}
		return $isSaved;
	}

	/**
	 * Removes a user from the file. The last active user of the highest
	 * role cannot be removed.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user was removed.
	 */
	public function removeUserFlag( String $namedUserTxt ): Bool {
		$this->forgetLastFail();
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ): ?Array {
			if( $this->lastManagerFlg($usersRecords, $usersIndxNum) ){
				$this->declineForFlag(409, 'The last active manager cannot be removed');
				return null;
			}
			unset($usersRecords[$usersIndxNum]);
			return array_values($usersRecords);
		} );
		if( $isSaved ){
			$this->logFileChanges( 'User '.json_encode($namedUserTxt).' removed' );
		}
		return $isSaved;
	}

	/**
	 * Gives a user a new password, replacing any previous one. Only its
	 * bcrypt hash is written.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The new password, following the rules (see checkPass).
	 * @return Bool Whether the password was written.
	 */
	public function savePasswdFlag( String $namedUserTxt, String $usrPasswdTxt ): Bool {
		$this->forgetLastFail();
		$ruleMsg = $this->testPassString($namedUserTxt, $usrPasswdTxt);
		if( $ruleMsg!='' ){
			return $this->declineForFlag(400, $ruleMsg);
		}
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $usrPasswdTxt ): ?Array {
			$usersRecords[$usersIndxNum]['passHash'] = password_hash($usrPasswdTxt, PASSWORD_BCRYPT);
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( 'Password of user '.json_encode($namedUserTxt).' set' );
		}
		return $isSaved;
	}

	/**
	 * Gives an active user a new personal API token, replacing any
	 * previous one. Only its hash is written: the token is returned once.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool The new token, or false when the user is unknown, not active, or the file cannot be written.
	 */
	public function makeToknString( String $namedUserTxt ): String|Bool {
		$this->forgetLastFail();
		$newTokn = bin2hex(random_bytes(32));
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $newTokn ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['userStat'] ?? 'active')!='active' ){
				$this->declineForFlag(409, 'User not active');
				return null;
			}
			$usersRecords[$usersIndxNum]['tokenKey'] = hash('sha256', $newTokn);
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( 'Personal token of user '.json_encode($namedUserTxt).' made' );
		}
		return $isSaved ? $newTokn : false;
	}

	/**
	 * Takes the personal API token of a user away.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user has no token any more.
	 */
	public function clearTokenFlag( String $namedUserTxt ): Bool {
		return $this->clearFieldFlag($namedUserTxt, 'tokenKey', 'Personal token');
	}

	/**
	 * Gives an active user a new 2FA secret, replacing any previous one.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool The new base32 secret, or false when the user is unknown, not active, or the file cannot be written.
	 */
	public function buildTwoFactor( String $namedUserTxt ): String|Bool {
		$this->forgetLastFail();
		$newSecr = Totp::generateSecret();
		$storTxt = Totp::sealSecretText($newSecr, $this->sealingKey);
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $storTxt ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['userStat'] ?? 'active')!='active' ){
				$this->declineForFlag(409, 'User not active');
				return null;
			}
			$usersRecords[$usersIndxNum]['twoFaKey'] = $storTxt;
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( '2FA secret of user '.json_encode($namedUserTxt).' made' );
			if( $storTxt===$newSecr ){
				Logger::retrieveLogger('access')->warning( '2FA secret of user '.json_encode($namedUserTxt).' stored readable: no tfakey of '.Totp::SEAL_SIZE.' characters is set' );
			}
		}
		return $isSaved ? $newSecr : false;
	}

	/**
	 * Takes the 2FA secret of a user away, for one whose authenticator is lost.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user has no 2FA secret any more.
	 */
	public function clearTwoFactor( String $namedUserTxt ): Bool {
		return $this->clearFieldFlag($namedUserTxt, 'twoFaKey', '2FA secret');
	}

	/**
	 * Gives every user of the file as it is written, hashes and secrets
	 * included: for moving the users to another store, never to be shown.
	 *
	 * @return Array|Bool The users, or false when the file cannot be used.
	 */
	public function readUsersArray(): Array|Bool {
		$this->forgetLastFail();
		$usrList = $this->loadUsersArray();
		return ($usrList===null) ? false : $usrList;
	}

	/**
	 * Writes users into a file that holds none, as they are given: their
	 * password hashes, tokens and 2FA secrets are kept. A file that
	 * already holds users is left as it is.
	 *
	 * @param Array $usersRecords The users, each with the fields of FLDSLIST it has.
	 * @return Bool Whether the file was written.
	 */
	public function fillEmptyStore( Array $usersRecords ): Bool {
		$this->forgetLastFail();
		$isSaved = $this->alterUsersFlag( null, function( Array $storedValues ) use ( $usersRecords ): ?Array {
			if( !empty($storedValues) ){
				$this->declineForFlag(409, 'The users file already holds users');
				return null;
			}
			return array_values($usersRecords);
		} );
		if( $isSaved ){
			$this->logFileChanges( count($usersRecords).' users written from another store' );
		}
		return $isSaved;
	}

	/**
	 * Tells why the last operation was refused.
	 *
	 * @return String The last error message, empty when it was not refused.
	 */
	public function finalErrorText(): String {
		return $this->lastMessag;
	}

	/**
	 * Gives the status the last refusal stands for, as UserBase does:
	 * 400 for a value that is not valid, 404 for an unknown user, 409 for
	 * a change the state of the user does not allow, 500 when the file
	 * cannot be used.
	 *
	 * @return Int The status, 200 when the last operation was not refused.
	 */
	public function lastStateCount(): Int {
		return $this->latestCode;
	}

	/**
	 * Removes a field of a user.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $fldNamedText Name of the field.
	 * @param String $fldLabelText What the field is, for the log.
	 * @return Bool Whether the user no longer has it.
	 */
	protected function clearFieldFlag( String $namedUserTxt, String $fldNamedText, String $fldLabelText ): Bool {
		$this->forgetLastFail();
		$isSaved = $this->alterUsersFlag( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $fldNamedText ): ?Array {
			unset($usersRecords[$usersIndxNum][$fldNamedText]);
			return $usersRecords;
		} );
		if( $isSaved ){
			$this->logFileChanges( $fldLabelText.' of user '.json_encode($namedUserTxt).' removed' );
		}
		return $isSaved;
	}

	/**
	 * Changes the users of the file, alone on it: the file is read, the
	 * change applied, and the result written, while any other command
	 * waits.
	 *
	 * @param ?String  $namedUserTxt Login name of the user the change is about; null when it is about none.
	 * @param callable $chngFunction Gets the users and, when a user is named, its place in them; returns the users to write, or null once it has recorded a refusal.
	 * @return Bool Whether the file was written.
	 */
	protected function alterUsersFlag( ?String $namedUserTxt, callable $chngFunction ): Bool {
		$lckHndl = @fopen( $this->freezePathsTxt(), 'c' );
		if( $lckHndl===false || !flock($lckHndl, LOCK_EX) ){
			return $this->declineForFlag(500, 'Users file cannot be locked for writing');
		}
		try{
			$usrList = $this->loadUsersArray();
			if( $usrList===null ){
				return false;
			}
			if( $namedUserTxt===null ){
				$newList = $chngFunction($usrList);
			}else{
				$usrIndx = $this->findIndexCount($usrList, $namedUserTxt);
				if( $usrIndx===null ){
					return $this->declineForFlag(404, 'User not found');
				}
				$newList = $chngFunction($usrList, $usrIndx);
			}
			return is_array($newList) ? $this->storeUsersFlag($newList) : false;
		}finally{
			flock($lckHndl, LOCK_UN);
			fclose($lckHndl);
		}
	}

	/**
	 * Gives the file two commands wait for each other on, in the temp
	 * folder of the project.
	 *
	 * @return String Its full path.
	 */
	protected function freezePathsTxt(): String {
		if( !is_dir(TOP_DIR.DS.'temp') ){
			@mkdir(TOP_DIR.DS.'temp', 0770, true);
		}
		return TOP_DIR.DS.'temp'.DS.'users.lock';
	}

	/**
	 * Reads the users of the file, as they are written. A file that does
	 * not exist yet holds no user.
	 *
	 * @return ?Array The users, or null when the file is not a JSON text holding a 'users' list of users with a name.
	 */
	protected function loadUsersArray(): ?Array {
		if( !is_file($this->usrLstPath) ){
			return Array();
		}
		$fileVls = json_decode( strval(@file_get_contents($this->usrLstPath)), true );
		$usrList = is_array($fileVls) ? ($fileVls['users'] ?? null) : null;
		$isValid = is_array($usrList);
		foreach( (array)$usrList as $usrRow ){
			$isValid = $isValid && is_array($usrRow) && is_string($usrRow['userName'] ?? null) && $usrRow['userName']!='';
		}
		if( !$isValid ){
			$this->declineForFlag(500, 'Users file cannot be used: '.$this->nameOfFile.' is not a JSON text holding a "users" list');
			return null;
		}
		return array_values($usrList);
	}

	/**
	 * Writes the users to the file, one per line, their fields in the
	 * order of FLDSLIST. The file is checked as the application reads it:
	 * one it would refuse is put back as it was.
	 *
	 * @param Array $usersRecords The users.
	 * @return Bool Whether the file was written.
	 */
	protected function storeUsersFlag( Array $usersRecords ): Bool {
		$jsonFlg = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$lineLst = Array();
		foreach( $usersRecords as $usrRow ){
			$partLst = Array();
			foreach( array_merge(self::FLDS_LIST, array_diff(array_keys($usrRow), self::FLDS_LIST)) as $fldNme ){
				if( isset($usrRow[$fldNme]) && $usrRow[$fldNme]!=='' ){
					$partLst[] = json_encode(strval($fldNme), $jsonFlg).': '.json_encode($usrRow[$fldNme], $jsonFlg);
				}
			}
			$lineLst[] = "\t\t".'{ '.implode(', ', $partLst).' }';
		}
		try{
			// A new file is readable by its owner and its group only, from its first byte
			$this->fileWriter->saveFileAtomic( $this->usrLstPath, '{'."\n\t".'"users": ['.( empty($lineLst) ? '' : "\n".implode(','."\n", $lineLst)."\n\t" ).']'."\n".'}'."\n", 0640 );
		}catch( \RuntimeException $e ){
			return $this->declineForFlag(500, 'Users file cannot be written: '.$e->getMessage());
		}
		// Read as the application reads it : a file it refuses would lock everyone out
		$chckObj = new UserList( Array('usrfle'=>$this->nameOfFile) );
		$chckObj->usrIdentityArr('-');
		if( $chckObj->finalErrorText()=='Credential store unavailable' ){
			$this->fileWriter->revertWriteFlg($this->usrLstPath);
			return $this->declineForFlag(500, 'Users file not written: the application would no longer accept it');
		}
		return true;
	}

	/**
	 * Finds a user in the users read.
	 *
	 * @param Array  $usersRecords The users.
	 * @param String $namedUserTxt Login name of the user.
	 * @return ?Int Its place, or null when there is no such user.
	 */
	protected function findIndexCount( Array $usersRecords, String $namedUserTxt ): ?Int {
		foreach( $usersRecords as $usrIdx => $usrRow ){
			if( ($usrRow['userName'] ?? null)===$namedUserTxt ){
				return intval($usrIdx);
			}
		}
		return null;
	}

	/**
	 * Gives what is told about a user: the fields of SHOWLIST, and
	 * whether it holds a personal token and a 2FA secret.
	 *
	 * @param Array $usrValuesArr The user, as the file holds it.
	 * @return Array What is shown of it.
	 */
	protected function shownValuesArr( Array $usrValuesArr ): Array {
		return Array(
			'id_user'=>$usrValuesArr['id_user'] ?? null,
			'userName'=>strval($usrValuesArr['userName'] ?? ''),
			'userRole'=>strval($usrValuesArr['userRole'] ?? Checker::ROLE_LIST[0]),
			'userStat'=>strval($usrValuesArr['userStat'] ?? 'active'),
			'userMail'=>strval($usrValuesArr['userMail'] ?? ''),
			'userLang'=>strval($usrValuesArr['userLang'] ?? ''),
			'hasToken'=>strval($usrValuesArr['tokenKey'] ?? '')!='',
			'hasTwoFa'=>strval($usrValuesArr['twoFaKey'] ?? '')!=''
		);
	}

	/**
	 * Tells whether a user is the last one who can manage the others: an
	 * active user of the highest role, with no other one beside it.
	 *
	 * @param Array $usersRecords The users.
	 * @param Int   $usersIndxNum Place of the user in them.
	 * @return Bool Whether locking, demoting or removing it would leave no manager.
	 */
	protected function lastManagerFlg( Array $usersRecords, Int $usersIndxNum ): Bool {
		$topRole = array_slice(Checker::ROLE_LIST, -1)[0];
		$mngrNum = 0;
		foreach( $usersRecords as $usrRow ){
			if( strval($usrRow['userRole'] ?? '')===$topRole && strval($usrRow['userStat'] ?? 'active')==='active' ){
				$mngrNum++;
			}
		}
		$userVls = $this->shownValuesArr($usersRecords[$usersIndxNum]);
		return $userVls['userRole']===$topRole && $userVls['userStat']==='active' && $mngrNum<2;
	}

	/**
	 * Tells which rule a password breaks, the rules being those of the
	 * native userbase: at least 'minlen' characters and at most PASSSIZE
	 * bytes, no control character, and not the name of its user.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The password, as given.
	 * @return String The rule that is broken, empty when the password is accepted.
	 */
	protected function testPassString( String $namedUserTxt, String $usrPasswdTxt ): String {
		$charNum = preg_match_all('/./su', $usrPasswdTxt);
		if( ($charNum ?: strlen($usrPasswdTxt))<$this->minPasswrd ){
			return 'Password too short: '.$this->minPasswrd.' characters at least';
		}
		if( strlen($usrPasswdTxt)>self::PASS_SIZE ){
			return 'Password too long: '.self::PASS_SIZE.' bytes at most';
		}
		if( preg_match('/[\x00-\x1F\x7F]/', $usrPasswdTxt) ){
			return 'Password holding a control character';
		}
		if( strcasecmp($usrPasswdTxt, $namedUserTxt)==0 ){
			return 'Password identical to the user name';
		}
		return '';
	}

	/**
	 * Traces a change of the file on the 'access' channel, as the native
	 * userbase traces its own.
	 *
	 * @param String $eventMessage What was changed.
	 */
	protected function logFileChanges( String $eventMessage ): Void {
		Logger::retrieveLogger('access')->notice( $eventMessage.' in the users file'.Logger::getActorSuffix() );
	}

	/**
	 * Forgets the refusal of the previous operation.
	 */
	protected function forgetLastFail(): Void {
		$this->latestCode = 200;
		$this->lastMessag = '';
	}

	/**
	 * Records why the running operation is refused.
	 *
	 * @param Int    $responseCode The status the refusal stands for (see lastState).
	 * @param String $statusMessag Why it is refused.
	 * @return Bool Always false, for the operation to return.
	 */
	protected function declineForFlag( Int $responseCode, String $statusMessag ): Bool {
		$this->latestCode = $responseCode;
		$this->lastMessag = $statusMessag;
		return false;
	}
}
