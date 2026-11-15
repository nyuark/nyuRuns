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
 * with its password. After a refusal, getLastErrText() tells why and
 * getRefusalCode() gives the status it stands for, as UserBase does.
 */
class UserFile{

	// The fields of a user, in the order they are written; any other field a person added is kept after them
	const FLDS_LIST = Array('id_user', 'systemName', 'passwdHash', 'accessRole', 'userStatus', 'contactEml', 'userLocale', 'bearerHash', 'twoFactKey');
	// What is told about a user : never a hash or a secret
	const SHOW_LIST = Array('id_user', 'systemName', 'accessRole', 'userStatus', 'contactEml', 'userLocale');
	// Longest password, in bytes : bcrypt reads nothing beyond
	const PASS_SIZE = 72;

	protected $nameOfFile;
	protected $usrLstPath;
	protected $minPwdSize;
	protected $sealingKey;
	protected $fileWriter;
	protected $errMessage = '';
	protected $latestCode = 200;

	/**
	 * Builds the writer of the users file from its config block.
	 *
	 * @param Array    $configValues Config block with the 'listFile' path of the users file, relative to the project root
	 *                             (conf/users.lst when left out), and optionally the shortest password accepted,
	 *                             in characters ('minChars', defaults to 10) and the key the 2FA secrets are written
	 *                             encrypted with ('tfaCrypt', 32 characters at least; without one they are written readable).
	 * @param ConfFile $writerObject The writer of config files.
	 */
	function __construct( Array $configValues, ConfFile $writerObject ){
		$fileName = is_scalar($configValues['listFile'] ?? '') ? trim( strval($configValues['listFile'] ?? ''), '/\\ ' ) : '';
		$this->nameOfFile = ($fileName!='') ? $fileName : 'conf/users.lst';
		$this->usrLstPath = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $this->nameOfFile);
		$this->minPwdSize = min( self::PASS_SIZE, max(1, intval($configValues['minChars'] ?? 10)) );
		$this->sealingKey = is_scalar($configValues['tfaCrypt'] ?? '') ? strval($configValues['tfaCrypt'] ?? '') : '';
		$this->fileWriter = $writerObject;
	}

	/**
	 * Lists the users of the file, by name, with what SHOWLIST tells of
	 * each, and whether it holds a personal token and a 2FA secret.
	 *
	 * @param Array $criteriaList Optional 'userStatus' and 'accessRole' values the users must have; an empty one is left out.
	 * @param Int   $ceilingCount Most users to give; all of them when below 1.
	 * @param Int   $skippedCount Users to pass over first.
	 * @return Array|Bool The users, or false when a filter is not valid or the file cannot be used.
	 */
	public function listUsersNamed( Array $criteriaList=Array(), Int $ceilingCount=0, Int $skippedCount=0 ): Array|Bool {
		$this->dropOldRefusal();
		foreach( Array('userStatus'=>Native::STAT_LIST, 'accessRole'=>Checker::ROLE_LIST) as $oneNme => $oneLst ){
			$givenTxt = $criteriaList[$oneNme] ?? '';
			if( $givenTxt!=='' && $givenTxt!==null && !in_array($givenTxt, $oneLst, true) ){
				return $this->denyWithReason(400, 'Invalid filter');
			}
		}
		$userList = $this->loadUsersArray();
		if( $userList===null ){
			return false;
		}
		$rowsList = Array();
		foreach( $userList as $oneRow ){
			$shwnList = $this->getShownValues($oneRow);
			if( in_array($criteriaList['userStatus'] ?? '', Array('', null, $shwnList['userStatus']), true) && in_array($criteriaList['accessRole'] ?? '', Array('', null, $shwnList['accessRole']), true) ){
				$rowsList[] = $shwnList;
			}
		}
		usort($rowsList, function( Array $firstUserRow, Array $secondUsrRow ): Int {
			return strcmp($firstUserRow['systemName'], $secondUsrRow['systemName']);
		});
		return array_slice( $rowsList, max(0, $skippedCount), ($ceilingCount>0) ? $ceilingCount : null );
	}

	/**
	 * Reads one user of the file.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Array|Bool The user, or false when it is unknown or the file cannot be used.
	 */
	public function readUserToShow( String $namedUserTxt ): Array|Bool {
		$this->dropOldRefusal();
		$userList = $this->loadUsersArray();
		if( $userList===null ){
			return false;
		}
		$userIndx = $this->findIndexCount($userList, $namedUserTxt);
		return ($userIndx===null) ? $this->denyWithReason(404, 'User not found') : $this->getShownValues($userList[$userIndx]);
	}

	/**
	 * Adds a user to the file, active, with its password. A name that
	 * only differs from an existing one by its case is refused.
	 *
	 * @param String $namedUserTxt Login name of the new user (see UserBase::NAME_RULE and NAMEDENY).
	 * @param String $usrRleString Its role, one of Checker::ROLE_LIST; the first one when left empty.
	 * @param String  $usrPasswdTxt Its password, following the rules (see checkPass).
	 * @param ?String $givenLangTag The language of its mails (see Tools\Language), none when null or empty.
	 * @return Bool Whether the user was added.
	 */
	public function enrolUsrString( String $namedUserTxt, String $usrRleString, String $usrPasswdTxt, ?String $givenLangTag=null ): Bool {
		$this->dropOldRefusal();
		$roleVals = ($usrRleString!='') ? $usrRleString : Checker::ROLE_LIST[0];
		if( !preg_match(UserBase::NAME_RULE, $namedUserTxt) || in_array(strtolower($namedUserTxt), UserBase::NAME_DENY, true) ){
			return $this->denyWithReason(400, 'Invalid user name');
		}
		if( !in_array($roleVals, Checker::ROLE_LIST, true) ){
			return $this->denyWithReason(400, 'Invalid role');
		}
		$langVals = strtolower( trim(str_replace('_', '-', strval($givenLangTag))) );
		if( $langVals!=='' && !Language::isValidLangTag($langVals) ){
			return $this->denyWithReason(400, 'Invalid language');
		}
		$ruleText = $this->checkPassRules($namedUserTxt, $usrPasswdTxt);
		if( $ruleText!='' ){
			return $this->denyWithReason(400, $ruleText);
		}
		$wasAdded = $this->alterUsersFile( null, function( Array $usersRecords ) use ( $namedUserTxt, $roleVals, $usrPasswdTxt, $langVals ): ?Array {
			$lastNumb = 0;
			foreach( $usersRecords as $oneRow ){
				if( strcasecmp(strval($oneRow['systemName'] ?? ''), $namedUserTxt)==0 ){
					$this->denyWithReason(409, 'User name already taken');
					return null;
				}
				$lastNumb = max( $lastNumb, intval($oneRow['id_user'] ?? 0) );
			}
			$usersRecords[] = Array('id_user'=>$lastNumb+1, 'systemName'=>$namedUserTxt, 'passwdHash'=>password_hash($usrPasswdTxt, PASSWORD_BCRYPT), 'accessRole'=>$roleVals, 'userStatus'=>'approved') + ( ($langVals!=='') ? Array('userLocale'=>$langVals) : Array() );
			return $usersRecords;
		} );
		if( $wasAdded ){
			$this->logFileChanges( 'User '.json_encode($namedUserTxt).' created ('.$roleVals.')' );
		}
		return $wasAdded;
	}

	/**
	 * Locks a user, or makes it active. The last active user of the
	 * highest role cannot be locked.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $statusString The status to give: 'disabled' or 'approved'.
	 * @return Bool Whether the status was changed.
	 */
	public function saveUserStatus( String $namedUserTxt, String $statusString ): Bool {
		$this->dropOldRefusal();
		if( !in_array($statusString, Array('approved', 'disabled'), true) ){
			return $this->denyWithReason(400, 'Invalid status');
		}
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $statusString ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['userStatus'] ?? 'approved')==$statusString ){
				$this->denyWithReason(409, 'User already '.$statusString);
				return null;
			}
			if( $statusString=='disabled' && $this->isFinalManager($usersRecords, $usersIndxNum) ){
				$this->denyWithReason(409, 'The last active manager cannot be locked');
				return null;
			}
			$usersRecords[$usersIndxNum]['userStatus'] = $statusString;
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( 'User '.json_encode($namedUserTxt).' '.($statusString=='disabled' ? 'locked' : 'unlocked') );
		}
		return $wasSaved;
	}

	/**
	 * Gives a user another role. The last active user of the highest
	 * role cannot be given a lower one.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrRleString The role to give, one of Checker::ROLE_LIST.
	 * @return Bool Whether the role was changed.
	 */
	public function alterRoleOfUsr( String $namedUserTxt, String $usrRleString ): Bool {
		$this->dropOldRefusal();
		if( !in_array($usrRleString, Checker::ROLE_LIST, true) ){
			return $this->denyWithReason(400, 'Invalid role');
		}
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $usrRleString ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['accessRole'] ?? Checker::ROLE_LIST[0])==$usrRleString ){
				$this->denyWithReason(409, 'User already of that role');
				return null;
			}
			if( $this->isFinalManager($usersRecords, $usersIndxNum) ){
				$this->denyWithReason(409, 'The last active manager cannot be given another role');
				return null;
			}
			$usersRecords[$usersIndxNum]['accessRole'] = $usrRleString;
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( 'Role of user '.json_encode($namedUserTxt).' changed to '.$usrRleString );
		}
		return $wasSaved;
	}

	/**
	 * Gives a user a mail address, in lower case, or takes it away. An
	 * address another user holds is refused.
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param ?String $emailAddress The address, none when null or empty.
	 * @return Bool Whether the address was written.
	 */
	public function alterUserEmail( String $namedUserTxt, ?String $emailAddress ): Bool {
		$this->dropOldRefusal();
		$mailVals = strtolower( trim(strval($emailAddress)) );
		if( $mailVals!=='' && (strlen($mailVals)>254 || filter_var($mailVals, FILTER_VALIDATE_EMAIL)===false) ){
			return $this->denyWithReason(400, 'Invalid mail address');
		}
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $mailVals ): ?Array {
			foreach( $usersRecords as $nthUsr => $oneRow ){
				if( $mailVals!=='' && $nthUsr!==$usersIndxNum && strtolower(strval($oneRow['contactEml'] ?? ''))===$mailVals ){
					$this->denyWithReason(409, 'Mail address already used');
					return null;
				}
			}
			if( $mailVals==='' ){
				unset($usersRecords[$usersIndxNum]['contactEml']);
			}else{
				$usersRecords[$usersIndxNum]['contactEml'] = $mailVals;
			}
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( 'Address of user '.json_encode($namedUserTxt).' '.($mailVals==='' ? 'removed' : 'set') );
		}
		return $wasSaved;
	}

	/**
	 * Gives a user the language of its mails, in lower case, or takes it
	 * away.
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param ?String $givenLangTag The language (see Tools\Language), none when null or empty.
	 * @return Bool Whether the language was written.
	 */
	public function alterMailsLang( String $namedUserTxt, ?String $givenLangTag ): Bool {
		$this->dropOldRefusal();
		$langVals = strtolower( trim(str_replace('_', '-', strval($givenLangTag))) );
		if( $langVals!=='' && !Language::isValidLangTag($langVals) ){
			return $this->denyWithReason(400, 'Invalid language');
		}
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $langVals ): ?Array {
			if( $langVals==='' ){
				unset($usersRecords[$usersIndxNum]['userLocale']);
			}else{
				$usersRecords[$usersIndxNum]['userLocale'] = $langVals;
			}
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( 'Language of user '.json_encode($namedUserTxt).' '.($langVals==='' ? 'removed' : 'set to '.$langVals) );
		}
		return $wasSaved;
	}

	/**
	 * Removes a user from the file. The last active user of the highest
	 * role cannot be removed.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user was removed.
	 */
	public function dropUsrAccount( String $namedUserTxt ): Bool {
		$this->dropOldRefusal();
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ): ?Array {
			if( $this->isFinalManager($usersRecords, $usersIndxNum) ){
				$this->denyWithReason(409, 'The last active manager cannot be removed');
				return null;
			}
			unset($usersRecords[$usersIndxNum]);
			return array_values($usersRecords);
		} );
		if( $wasSaved ){
			$this->logFileChanges( 'User '.json_encode($namedUserTxt).' removed' );
		}
		return $wasSaved;
	}

	/**
	 * Gives a user a new password, replacing any previous one. Only its
	 * bcrypt hash is written.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The new password, following the rules (see checkPass).
	 * @return Bool Whether the password was written.
	 */
	public function savePassOfUser( String $namedUserTxt, String $usrPasswdTxt ): Bool {
		$this->dropOldRefusal();
		$ruleText = $this->checkPassRules($namedUserTxt, $usrPasswdTxt);
		if( $ruleText!='' ){
			return $this->denyWithReason(400, $ruleText);
		}
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $usrPasswdTxt ): ?Array {
			$usersRecords[$usersIndxNum]['passwdHash'] = password_hash($usrPasswdTxt, PASSWORD_BCRYPT);
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( 'Password of user '.json_encode($namedUserTxt).' set' );
		}
		return $wasSaved;
	}

	/**
	 * Gives an active user a new personal API token, replacing any
	 * previous one. Only its hash is written: the token is returned once.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool The new token, or false when the user is unknown, not active, or the file cannot be written.
	 */
	public function makeToknString( String $namedUserTxt ): String|Bool {
		$this->dropOldRefusal();
		$newToken = bin2hex(random_bytes(32));
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $newToken ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['userStatus'] ?? 'approved')!='approved' ){
				$this->denyWithReason(409, 'User not active');
				return null;
			}
			$usersRecords[$usersIndxNum]['bearerHash'] = hash('sha256', $newToken);
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( 'Personal token of user '.json_encode($namedUserTxt).' made' );
		}
		return $wasSaved ? $newToken : false;
	}

	/**
	 * Takes the personal API token of a user away.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user has no token any more.
	 */
	public function clearUserToken( String $namedUserTxt ): Bool {
		return $this->clearUserField($namedUserTxt, 'bearerHash', 'Personal token');
	}

	/**
	 * Gives an active user a new 2FA secret, replacing any previous one.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return String|Bool The new base32 secret, or false when the user is unknown, not active, or the file cannot be written.
	 */
	public function makeTotpSecret( String $namedUserTxt ): String|Bool {
		$this->dropOldRefusal();
		$newSecrt = Totp::makeSecretText();
		$storeTxt = Totp::sealSecretText($newSecrt, $this->sealingKey);
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $storeTxt ): ?Array {
			if( strval($usersRecords[$usersIndxNum]['userStatus'] ?? 'approved')!='approved' ){
				$this->denyWithReason(409, 'User not active');
				return null;
			}
			$usersRecords[$usersIndxNum]['twoFactKey'] = $storeTxt;
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( '2FA secret of user '.json_encode($namedUserTxt).' made' );
			if( $storeTxt===$newSecrt ){
				Logger::getLoggerOfChn('access')->warning( '2FA secret of user '.json_encode($namedUserTxt).' stored readable: no tfaCrypt of '.Totp::SEAL_SIZE.' characters is set' );
			}
		}
		return $wasSaved ? $newSecrt : false;
	}

	/**
	 * Takes the 2FA secret of a user away, for one whose authenticator is lost.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user has no 2FA secret any more.
	 */
	public function clearTwoFactor( String $namedUserTxt ): Bool {
		return $this->clearUserField($namedUserTxt, 'twoFactKey', '2FA secret');
	}

	/**
	 * Gives every user of the file as it is written, hashes and secrets
	 * included: for moving the users to another store, never to be shown.
	 *
	 * @return Array|Bool The users, or false when the file cannot be used.
	 */
	public function readUsersArray(): Array|Bool {
		$this->dropOldRefusal();
		$userList = $this->loadUsersArray();
		return ($userList===null) ? false : $userList;
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
		$this->dropOldRefusal();
		$wasSaved = $this->alterUsersFile( null, function( Array $storedValues ) use ( $usersRecords ): ?Array {
			if( !empty($storedValues) ){
				$this->denyWithReason(409, 'The users file already holds users');
				return null;
			}
			return array_values($usersRecords);
		} );
		if( $wasSaved ){
			$this->logFileChanges( count($usersRecords).' users written from another store' );
		}
		return $wasSaved;
	}

	/**
	 * Tells why the last operation was refused.
	 *
	 * @return String The last error message, empty when it was not refused.
	 */
	public function getLastErrText(): String {
		return $this->errMessage;
	}

	/**
	 * Gives the status the last refusal stands for, as UserBase does:
	 * 400 for a value that is not valid, 404 for an unknown user, 409 for
	 * a change the state of the user does not allow, 500 when the file
	 * cannot be used.
	 *
	 * @return Int The status, 200 when the last operation was not refused.
	 */
	public function getRefusalCode(): Int {
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
	protected function clearUserField( String $namedUserTxt, String $fldNamedText, String $fldLabelText ): Bool {
		$this->dropOldRefusal();
		$wasSaved = $this->alterUsersFile( $namedUserTxt, function( Array $usersRecords, Int $usersIndxNum ) use ( $fldNamedText ): ?Array {
			unset($usersRecords[$usersIndxNum][$fldNamedText]);
			return $usersRecords;
		} );
		if( $wasSaved ){
			$this->logFileChanges( $fldLabelText.' of user '.json_encode($namedUserTxt).' removed' );
		}
		return $wasSaved;
	}

	/**
	 * Changes the users of the file, alone on it: the file is read, the
	 * change applied, and the result written, while any other command
	 * waits.
	 *
	 * @param ?String  $namedUserTxt Login name of the user the change is about; null when it is about none.
	 * @param callable $editCallable Gets the users and, when a user is named, its place in them; returns the users to write, or null once it has recorded a refusal.
	 * @return Bool Whether the file was written.
	 */
	protected function alterUsersFile( ?String $namedUserTxt, callable $editCallable ): Bool {
		$lockHndl = @fopen( $this->freezePathsTxt(), 'c' );
		if( $lockHndl===false || !flock($lockHndl, LOCK_EX) ){
			return $this->denyWithReason(500, 'Users file cannot be locked for writing');
		}
		try{
			$userList = $this->loadUsersArray();
			if( $userList===null ){
				return false;
			}
			if( $namedUserTxt===null ){
				$newLstng = $editCallable($userList);
			}else{
				$userIndx = $this->findIndexCount($userList, $namedUserTxt);
				if( $userIndx===null ){
					return $this->denyWithReason(404, 'User not found');
				}
				$newLstng = $editCallable($userList, $userIndx);
			}
			return is_array($newLstng) ? $this->saveUsersLines($newLstng) : false;
		}finally{
			flock($lockHndl, LOCK_UN);
			fclose($lockHndl);
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
		$fileVals = json_decode( strval(@file_get_contents($this->usrLstPath)), true );
		$userList = is_array($fileVals) ? ($fileVals['users'] ?? null) : null;
		$isAddrOk = is_array($userList);
		foreach( (array)$userList as $oneRow ){
			$isAddrOk = $isAddrOk && is_array($oneRow) && is_string($oneRow['systemName'] ?? null) && $oneRow['systemName']!='';
		}
		if( !$isAddrOk ){
			$this->denyWithReason(500, 'Users file cannot be used: '.$this->nameOfFile.' is not a JSON text holding a "users" list');
			return null;
		}
		return array_values($userList);
	}

	/**
	 * Writes the users to the file, one per line, their fields in the
	 * order of FLDSLIST. The file is checked as the application reads it:
	 * one it would refuse is put back as it was.
	 *
	 * @param Array $usersRecords The users.
	 * @return Bool Whether the file was written.
	 */
	protected function saveUsersLines( Array $usersRecords ): Bool {
		$jsonFlag = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$lineList = Array();
		foreach( $usersRecords as $oneRow ){
			$partList = Array();
			foreach( array_merge(self::FLDS_LIST, array_diff(array_keys($oneRow), self::FLDS_LIST)) as $oneNme ){
				if( isset($oneRow[$oneNme]) && $oneRow[$oneNme]!=='' ){
					$partList[] = json_encode(strval($oneNme), $jsonFlag).': '.json_encode($oneRow[$oneNme], $jsonFlag);
				}
			}
			$lineList[] = "\t\t".'{ '.implode(', ', $partList).' }';
		}
		try{
			// A new file is readable by its owner and its group only, from its first byte
			$this->fileWriter->saveFileAtomic( $this->usrLstPath, '{'."\n\t".'"users": ['.( empty($lineList) ? '' : "\n".implode(','."\n", $lineList)."\n\t" ).']'."\n".'}'."\n", 0640 );
		}catch( \RuntimeException $e ){
			return $this->denyWithReason(500, 'Users file cannot be written: '.$e->getMessage());
		}
		// Read as the application reads it : a file it refuses would lock everyone out
		$checkObj = new UserList( Array('listFile'=>$this->nameOfFile) );
		$checkObj->readIdntOfUser('-');
		if( $checkObj->getLastErrText()=='Credential store unavailable' ){
			$this->fileWriter->clearLastWrite($this->usrLstPath);
			return $this->denyWithReason(500, 'Users file not written: the application would no longer accept it');
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
		foreach( $usersRecords as $nthUsr => $oneRow ){
			if( ($oneRow['systemName'] ?? null)===$namedUserTxt ){
				return intval($nthUsr);
			}
		}
		return null;
	}

	/**
	 * Gives what is told about a user: the fields of SHOWLIST, and
	 * whether it holds a personal token and a 2FA secret.
	 *
	 * @param Array $userValsList The user, as the file holds it.
	 * @return Array What is shown of it.
	 */
	protected function getShownValues( Array $userValsList ): Array {
		return Array(
			'id_user'=>$userValsList['id_user'] ?? null,
			'systemName'=>strval($userValsList['systemName'] ?? ''),
			'accessRole'=>strval($userValsList['accessRole'] ?? Checker::ROLE_LIST[0]),
			'userStatus'=>strval($userValsList['userStatus'] ?? 'approved'),
			'contactEml'=>strval($userValsList['contactEml'] ?? ''),
			'userLocale'=>strval($userValsList['userLocale'] ?? ''),
			'hasToken'=>strval($userValsList['bearerHash'] ?? '')!='',
			'hasTwoFa'=>strval($userValsList['twoFactKey'] ?? '')!=''
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
	protected function isFinalManager( Array $usersRecords, Int $usersIndxNum ): Bool {
		$highRole = array_slice(Checker::ROLE_LIST, -1)[0];
		$mngrNumb = 0;
		foreach( $usersRecords as $oneRow ){
			if( strval($oneRow['accessRole'] ?? '')===$highRole && strval($oneRow['userStatus'] ?? 'approved')==='approved' ){
				$mngrNumb++;
			}
		}
		$userVals = $this->getShownValues($usersRecords[$usersIndxNum]);
		return $userVals['accessRole']===$highRole && $userVals['userStatus']==='approved' && $mngrNumb<2;
	}

	/**
	 * Tells which rule a password breaks, the rules being those of the
	 * native userbase: at least 'minChars' characters and at most PASSSIZE
	 * bytes, no control character, and not the name of its user.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $usrPasswdTxt The password, as given.
	 * @return String The rule that is broken, empty when the password is accepted.
	 */
	protected function checkPassRules( String $namedUserTxt, String $usrPasswdTxt ): String {
		$charNumb = preg_match_all('/./su', $usrPasswdTxt);
		if( ($charNumb ?: strlen($usrPasswdTxt))<$this->minPwdSize ){
			return 'Password too short: '.$this->minPwdSize.' characters at least';
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
		Logger::getLoggerOfChn('access')->notice( $eventMessage.' in the users file'.Logger::getActorSuffix() );
	}

	/**
	 * Forgets the refusal of the previous operation.
	 */
	protected function dropOldRefusal(): Void {
		$this->latestCode = 200;
		$this->errMessage = '';
	}

	/**
	 * Records why the running operation is refused.
	 *
	 * @param Int    $responseCode The status the refusal stands for (see lastState).
	 * @param String $statusReason Why it is refused.
	 * @return Bool Always false, for the operation to return.
	 */
	protected function denyWithReason( Int $responseCode, String $statusReason ): Bool {
		$this->latestCode = $responseCode;
		$this->errMessage = $statusReason;
		return false;
	}
}
