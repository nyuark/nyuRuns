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
 * with its password. After a refusal, lastError() tells why and
 * lastState() gives the status it stands for, as UserBase does.
 */
class UserFile{

	// The fields of a user, in the order they are written; any other field a person added is kept after them
	const FLDSLIST = Array('id_user', 'userName', 'passHash', 'userRole', 'userStat', 'userMail', 'userLang', 'tokenKey', 'twoFaKey');
	// What is told about a user : never a hash or a secret
	const SHOWLIST = Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'userLang');
	// Longest password, in bytes : bcrypt reads nothing beyond
	const PASSSIZE = 72;

	protected $usrsName;
	protected $usrsFile;
	protected $minPassw;
	protected $sealPass;
	protected $fileWrtr;
	protected $lastMssg = '';
	protected $lastCode = 200;

	/**
	 * Builds the writer of the users file from its config block.
	 *
	 * @param Array    $confValues Config block with the 'usrfle' path of the users file, relative to the project root
	 *                             (conf/users.lst when left out), and optionally the shortest password accepted,
	 *                             in characters ('minlen', defaults to 10) and the key the 2FA secrets are written
	 *                             encrypted with ('tfakey', 32 characters at least; without one they are written readable).
	 * @param ConfFile $writerObjc The writer of config files.
	 */
	function __construct( Array $confValues, ConfFile $writerObjc ){
		$fleName = is_scalar($confValues['usrfle'] ?? '') ? trim( strval($confValues['usrfle'] ?? ''), '/\\ ' ) : '';
		$this->usrsName = ($fleName!='') ? $fleName : 'conf/users.lst';
		$this->usrsFile = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $this->usrsName);
		$this->minPassw = min( self::PASSSIZE, max(1, intval($confValues['minlen'] ?? 10)) );
		$this->sealPass = is_scalar($confValues['tfakey'] ?? '') ? strval($confValues['tfakey'] ?? '') : '';
		$this->fileWrtr = $writerObjc;
	}

	/**
	 * Lists the users of the file, by name, with what SHOWLIST tells of
	 * each, and whether it holds a personal token and a 2FA secret.
	 *
	 * @param Array $filterList Optional 'userStat' and 'userRole' values the users must have; an empty one is left out.
	 * @param Int   $limitCount Most users to give; all of them when below 1.
	 * @param Int   $offsetRows Users to pass over first.
	 * @return Array|Bool The users, or false when a filter is not valid or the file cannot be used.
	 */
	public function listUsers( Array $filterList=Array(), Int $limitCount=0, Int $offsetRows=0 ): Array|Bool {
		$this->resetLast();
		foreach( Array('userStat'=>Native::STATLIST, 'userRole'=>Checker::ROLELIST) as $fldNme => $alwLst ){
			$gvnText = $filterList[$fldNme] ?? '';
			if( $gvnText!=='' && $gvnText!==null && !in_array($gvnText, $alwLst, true) ){
				return $this->refuseFor(400, 'Invalid filter');
			}
		}
		$usrList = $this->loadUsers();
		if( $usrList===null ){
			return false;
		}
		$rowList = Array();
		foreach( $usrList as $usrRow ){
			$shownAr = $this->shownVals($usrRow);
			if( in_array($filterList['userStat'] ?? '', Array('', null, $shownAr['userStat']), true) && in_array($filterList['userRole'] ?? '', Array('', null, $shownAr['userRole']), true) ){
				$rowList[] = $shownAr;
			}
		}
		usort($rowList, function( Array $leftUsrRow, Array $rghtUsrRow ): Int {
			return strcmp($leftUsrRow['userName'], $rghtUsrRow['userName']);
		});
		return array_slice( $rowList, max(0, $offsetRows), ($limitCount>0) ? $limitCount : null );
	}

	/**
	 * Reads one user of the file.
	 *
	 * @param String $userString Login name of the user.
	 * @return Array|Bool The user, or false when it is unknown or the file cannot be used.
	 */
	public function userInfos( String $userString ): Array|Bool {
		$this->resetLast();
		$usrList = $this->loadUsers();
		if( $usrList===null ){
			return false;
		}
		$usrIndx = $this->findIndex($usrList, $userString);
		return ($usrIndx===null) ? $this->refuseFor(404, 'User not found') : $this->shownVals($usrList[$usrIndx]);
	}

	/**
	 * Adds a user to the file, active, with its password. A name that
	 * only differs from an existing one by its case is refused.
	 *
	 * @param String $userString Login name of the new user (see UserBase::NAMERULE and NAMEDENY).
	 * @param String $roleString Its role, one of Checker::ROLELIST; the first one when left empty.
	 * @param String  $passwdText Its password, following the rules (see checkPass).
	 * @param ?String $langTagStr The language of its mails (see Tools\Language), none when null or empty.
	 * @return Bool Whether the user was added.
	 */
	public function enrolUser( String $userString, String $roleString, String $passwdText, ?String $langTagStr=null ): Bool {
		$this->resetLast();
		$roleStr = ($roleString!='') ? $roleString : Checker::ROLELIST[0];
		if( !preg_match(UserBase::NAMERULE, $userString) || in_array(strtolower($userString), UserBase::NAMEDENY, true) ){
			return $this->refuseFor(400, 'Invalid user name');
		}
		if( !in_array($roleStr, Checker::ROLELIST, true) ){
			return $this->refuseFor(400, 'Invalid role');
		}
		$langVal = strtolower( trim(str_replace('_', '-', strval($langTagStr))) );
		if( $langVal!=='' && !Language::checksTag($langVal) ){
			return $this->refuseFor(400, 'Invalid language');
		}
		$ruleMsg = $this->checkPass($userString, $passwdText);
		if( $ruleMsg!='' ){
			return $this->refuseFor(400, $ruleMsg);
		}
		$isAdded = $this->editUsers( null, function( Array $usersArray ) use ( $userString, $roleStr, $passwdText, $langVal ): ?Array {
			$lastIdn = 0;
			foreach( $usersArray as $usrRow ){
				if( strcasecmp(strval($usrRow['userName'] ?? ''), $userString)==0 ){
					$this->refuseFor(409, 'User name already taken');
					return null;
				}
				$lastIdn = max( $lastIdn, intval($usrRow['id_user'] ?? 0) );
			}
			$usersArray[] = Array('id_user'=>$lastIdn+1, 'userName'=>$userString, 'passHash'=>password_hash($passwdText, PASSWORD_BCRYPT), 'userRole'=>$roleStr, 'userStat'=>'active') + ( ($langVal!=='') ? Array('userLang'=>$langVal) : Array() );
			return $usersArray;
		} );
		if( $isAdded ){
			$this->logChange( 'User '.json_encode($userString).' created ('.$roleStr.')' );
		}
		return $isAdded;
	}

	/**
	 * Locks a user, or makes it active. The last active user of the
	 * highest role cannot be locked.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $statString The status to give: 'locked' or 'active'.
	 * @return Bool Whether the status was changed.
	 */
	public function setStatus( String $userString, String $statString ): Bool {
		$this->resetLast();
		if( !in_array($statString, Array('active', 'locked'), true) ){
			return $this->refuseFor(400, 'Invalid status');
		}
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $statString ): ?Array {
			if( strval($usersArray[$usersIndex]['userStat'] ?? 'active')==$statString ){
				$this->refuseFor(409, 'User already '.$statString);
				return null;
			}
			if( $statString=='locked' && $this->lastMangr($usersArray, $usersIndex) ){
				$this->refuseFor(409, 'The last active manager cannot be locked');
				return null;
			}
			$usersArray[$usersIndex]['userStat'] = $statString;
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( 'User '.json_encode($userString).' '.($statString=='locked' ? 'locked' : 'unlocked') );
		}
		return $isSaved;
	}

	/**
	 * Gives a user another role. The last active user of the highest
	 * role cannot be given a lower one.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $roleString The role to give, one of Checker::ROLELIST.
	 * @return Bool Whether the role was changed.
	 */
	public function changRole( String $userString, String $roleString ): Bool {
		$this->resetLast();
		if( !in_array($roleString, Checker::ROLELIST, true) ){
			return $this->refuseFor(400, 'Invalid role');
		}
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $roleString ): ?Array {
			if( strval($usersArray[$usersIndex]['userRole'] ?? Checker::ROLELIST[0])==$roleString ){
				$this->refuseFor(409, 'User already of that role');
				return null;
			}
			if( $this->lastMangr($usersArray, $usersIndex) ){
				$this->refuseFor(409, 'The last active manager cannot be given another role');
				return null;
			}
			$usersArray[$usersIndex]['userRole'] = $roleString;
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( 'Role of user '.json_encode($userString).' changed to '.$roleString );
		}
		return $isSaved;
	}

	/**
	 * Gives a user a mail address, in lower case, or takes it away. An
	 * address another user holds is refused.
	 *
	 * @param String  $userString Login name of the user.
	 * @param ?String $mailString The address, none when null or empty.
	 * @return Bool Whether the address was written.
	 */
	public function chngeMail( String $userString, ?String $mailString ): Bool {
		$this->resetLast();
		$mailVal = strtolower( trim(strval($mailString)) );
		if( $mailVal!=='' && (strlen($mailVal)>254 || filter_var($mailVal, FILTER_VALIDATE_EMAIL)===false) ){
			return $this->refuseFor(400, 'Invalid mail address');
		}
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $mailVal ): ?Array {
			foreach( $usersArray as $usrPos => $usrRow ){
				if( $mailVal!=='' && $usrPos!==$usersIndex && strtolower(strval($usrRow['userMail'] ?? ''))===$mailVal ){
					$this->refuseFor(409, 'Mail address already used');
					return null;
				}
			}
			if( $mailVal==='' ){
				unset($usersArray[$usersIndex]['userMail']);
			}else{
				$usersArray[$usersIndex]['userMail'] = $mailVal;
			}
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( 'Address of user '.json_encode($userString).' '.($mailVal==='' ? 'removed' : 'set') );
		}
		return $isSaved;
	}

	/**
	 * Gives a user the language of its mails, in lower case, or takes it
	 * away.
	 *
	 * @param String  $userString Login name of the user.
	 * @param ?String $langTagStr The language (see Tools\Language), none when null or empty.
	 * @return Bool Whether the language was written.
	 */
	public function chngeLang( String $userString, ?String $langTagStr ): Bool {
		$this->resetLast();
		$langVal = strtolower( trim(str_replace('_', '-', strval($langTagStr))) );
		if( $langVal!=='' && !Language::checksTag($langVal) ){
			return $this->refuseFor(400, 'Invalid language');
		}
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $langVal ): ?Array {
			if( $langVal==='' ){
				unset($usersArray[$usersIndex]['userLang']);
			}else{
				$usersArray[$usersIndex]['userLang'] = $langVal;
			}
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( 'Language of user '.json_encode($userString).' '.($langVal==='' ? 'removed' : 'set to '.$langVal) );
		}
		return $isSaved;
	}

	/**
	 * Removes a user from the file. The last active user of the highest
	 * role cannot be removed.
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the user was removed.
	 */
	public function removUser( String $userString ): Bool {
		$this->resetLast();
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ): ?Array {
			if( $this->lastMangr($usersArray, $usersIndex) ){
				$this->refuseFor(409, 'The last active manager cannot be removed');
				return null;
			}
			unset($usersArray[$usersIndex]);
			return array_values($usersArray);
		} );
		if( $isSaved ){
			$this->logChange( 'User '.json_encode($userString).' removed' );
		}
		return $isSaved;
	}

	/**
	 * Gives a user a new password, replacing any previous one. Only its
	 * bcrypt hash is written.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $passwdText The new password, following the rules (see checkPass).
	 * @return Bool Whether the password was written.
	 */
	public function setPasswd( String $userString, String $passwdText ): Bool {
		$this->resetLast();
		$ruleMsg = $this->checkPass($userString, $passwdText);
		if( $ruleMsg!='' ){
			return $this->refuseFor(400, $ruleMsg);
		}
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $passwdText ): ?Array {
			$usersArray[$usersIndex]['passHash'] = password_hash($passwdText, PASSWORD_BCRYPT);
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( 'Password of user '.json_encode($userString).' set' );
		}
		return $isSaved;
	}

	/**
	 * Gives an active user a new personal API token, replacing any
	 * previous one. Only its hash is written: the token is returned once.
	 *
	 * @param String $userString Login name of the user.
	 * @return String|Bool The new token, or false when the user is unknown, not active, or the file cannot be written.
	 */
	public function makeToken( String $userString ): String|Bool {
		$this->resetLast();
		$newTokn = bin2hex(random_bytes(32));
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $newTokn ): ?Array {
			if( strval($usersArray[$usersIndex]['userStat'] ?? 'active')!='active' ){
				$this->refuseFor(409, 'User not active');
				return null;
			}
			$usersArray[$usersIndex]['tokenKey'] = hash('sha256', $newTokn);
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( 'Personal token of user '.json_encode($userString).' made' );
		}
		return $isSaved ? $newTokn : false;
	}

	/**
	 * Takes the personal API token of a user away.
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the user has no token any more.
	 */
	public function dropToken( String $userString ): Bool {
		return $this->dropField($userString, 'tokenKey', 'Personal token');
	}

	/**
	 * Gives an active user a new 2FA secret, replacing any previous one.
	 *
	 * @param String $userString Login name of the user.
	 * @return String|Bool The new base32 secret, or false when the user is unknown, not active, or the file cannot be written.
	 */
	public function makeTwoFa( String $userString ): String|Bool {
		$this->resetLast();
		$newScrt = Totp::makeSecrt();
		$storTxt = Totp::sealSecrt($newScrt, $this->sealPass);
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $storTxt ): ?Array {
			if( strval($usersArray[$usersIndex]['userStat'] ?? 'active')!='active' ){
				$this->refuseFor(409, 'User not active');
				return null;
			}
			$usersArray[$usersIndex]['twoFaKey'] = $storTxt;
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( '2FA secret of user '.json_encode($userString).' made' );
			if( $storTxt===$newScrt ){
				Logger::getLogger('access')->warning( '2FA secret of user '.json_encode($userString).' stored readable: no tfakey of '.Totp::SEALSIZE.' characters is set' );
			}
		}
		return $isSaved ? $newScrt : false;
	}

	/**
	 * Takes the 2FA secret of a user away, for one whose authenticator is lost.
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the user has no 2FA secret any more.
	 */
	public function dropTwoFa( String $userString ): Bool {
		return $this->dropField($userString, 'twoFaKey', '2FA secret');
	}

	/**
	 * Gives every user of the file as it is written, hashes and secrets
	 * included: for moving the users to another store, never to be shown.
	 *
	 * @return Array|Bool The users, or false when the file cannot be used.
	 */
	public function readUsers(): Array|Bool {
		$this->resetLast();
		$usrList = $this->loadUsers();
		return ($usrList===null) ? false : $usrList;
	}

	/**
	 * Writes users into a file that holds none, as they are given: their
	 * password hashes, tokens and 2FA secrets are kept. A file that
	 * already holds users is left as it is.
	 *
	 * @param Array $usersArray The users, each with the fields of FLDSLIST it has.
	 * @return Bool Whether the file was written.
	 */
	public function fillUsers( Array $usersArray ): Bool {
		$this->resetLast();
		$isSaved = $this->editUsers( null, function( Array $storedList ) use ( $usersArray ): ?Array {
			if( !empty($storedList) ){
				$this->refuseFor(409, 'The users file already holds users');
				return null;
			}
			return array_values($usersArray);
		} );
		if( $isSaved ){
			$this->logChange( count($usersArray).' users written from another store' );
		}
		return $isSaved;
	}

	/**
	 * Tells why the last operation was refused.
	 *
	 * @return String The last error message, empty when it was not refused.
	 */
	public function lastError(): String {
		return $this->lastMssg;
	}

	/**
	 * Gives the status the last refusal stands for, as UserBase does:
	 * 400 for a value that is not valid, 404 for an unknown user, 409 for
	 * a change the state of the user does not allow, 500 when the file
	 * cannot be used.
	 *
	 * @return Int The status, 200 when the last operation was not refused.
	 */
	public function lastState(): Int {
		return $this->lastCode;
	}

	/**
	 * Removes a field of a user.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $fieldNamed Name of the field.
	 * @param String $fieldLabel What the field is, for the log.
	 * @return Bool Whether the user no longer has it.
	 */
	protected function dropField( String $userString, String $fieldNamed, String $fieldLabel ): Bool {
		$this->resetLast();
		$isSaved = $this->editUsers( $userString, function( Array $usersArray, Int $usersIndex ) use ( $fieldNamed ): ?Array {
			unset($usersArray[$usersIndex][$fieldNamed]);
			return $usersArray;
		} );
		if( $isSaved ){
			$this->logChange( $fieldLabel.' of user '.json_encode($userString).' removed' );
		}
		return $isSaved;
	}

	/**
	 * Changes the users of the file, alone on it: the file is read, the
	 * change applied, and the result written, while any other command
	 * waits.
	 *
	 * @param ?String  $userString Login name of the user the change is about; null when it is about none.
	 * @param callable $changeFunc Gets the users and, when a user is named, its place in them; returns the users to write, or null once it has recorded a refusal.
	 * @return Bool Whether the file was written.
	 */
	protected function editUsers( ?String $userString, callable $changeFunc ): Bool {
		$lockHdl = @fopen( $this->lockPaths(), 'c' );
		if( $lockHdl===false || !flock($lockHdl, LOCK_EX) ){
			return $this->refuseFor(500, 'Users file cannot be locked for writing');
		}
		try{
			$usrList = $this->loadUsers();
			if( $usrList===null ){
				return false;
			}
			if( $userString===null ){
				$newList = $changeFunc($usrList);
			}else{
				$usrIndx = $this->findIndex($usrList, $userString);
				if( $usrIndx===null ){
					return $this->refuseFor(404, 'User not found');
				}
				$newList = $changeFunc($usrList, $usrIndx);
			}
			return is_array($newList) ? $this->saveUsers($newList) : false;
		}finally{
			flock($lockHdl, LOCK_UN);
			fclose($lockHdl);
		}
	}

	/**
	 * Gives the file two commands wait for each other on, in the temp
	 * folder of the project.
	 *
	 * @return String Its full path.
	 */
	protected function lockPaths(): String {
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
	protected function loadUsers(): ?Array {
		if( !is_file($this->usrsFile) ){
			return Array();
		}
		$fleVals = json_decode( strval(@file_get_contents($this->usrsFile)), true );
		$usrList = is_array($fleVals) ? ($fleVals['users'] ?? null) : null;
		$isValid = is_array($usrList);
		foreach( (array)$usrList as $usrRow ){
			$isValid = $isValid && is_array($usrRow) && is_string($usrRow['userName'] ?? null) && $usrRow['userName']!='';
		}
		if( !$isValid ){
			$this->refuseFor(500, 'Users file cannot be used: '.$this->usrsName.' is not a JSON text holding a "users" list');
			return null;
		}
		return array_values($usrList);
	}

	/**
	 * Writes the users to the file, one per line, their fields in the
	 * order of FLDSLIST. The file is checked as the application reads it:
	 * one it would refuse is put back as it was.
	 *
	 * @param Array $usersArray The users.
	 * @return Bool Whether the file was written.
	 */
	protected function saveUsers( Array $usersArray ): Bool {
		$jsnFlag = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$lineLst = Array();
		foreach( $usersArray as $usrRow ){
			$partLst = Array();
			foreach( array_merge(self::FLDSLIST, array_diff(array_keys($usrRow), self::FLDSLIST)) as $fldNme ){
				if( isset($usrRow[$fldNme]) && $usrRow[$fldNme]!=='' ){
					$partLst[] = json_encode(strval($fldNme), $jsnFlag).': '.json_encode($usrRow[$fldNme], $jsnFlag);
				}
			}
			$lineLst[] = "\t\t".'{ '.implode(', ', $partLst).' }';
		}
		try{
			// A new file is readable by its owner and its group only, from its first byte
			$this->fileWrtr->writeFile( $this->usrsFile, '{'."\n\t".'"users": ['.( empty($lineLst) ? '' : "\n".implode(','."\n", $lineLst)."\n\t" ).']'."\n".'}'."\n", 0640 );
		}catch( \RuntimeException $e ){
			return $this->refuseFor(500, 'Users file cannot be written: '.$e->getMessage());
		}
		// Read as the application reads it : a file it refuses would lock everyone out
		$chkObjc = new UserList( Array('usrfle'=>$this->usrsName) );
		$chkObjc->userIdent('-');
		if( $chkObjc->lastError()=='Credential store unavailable' ){
			$this->fileWrtr->undoWrite($this->usrsFile);
			return $this->refuseFor(500, 'Users file not written: the application would no longer accept it');
		}
		return true;
	}

	/**
	 * Finds a user in the users read.
	 *
	 * @param Array  $usersArray The users.
	 * @param String $userString Login name of the user.
	 * @return ?Int Its place, or null when there is no such user.
	 */
	protected function findIndex( Array $usersArray, String $userString ): ?Int {
		foreach( $usersArray as $usrIdx => $usrRow ){
			if( ($usrRow['userName'] ?? null)===$userString ){
				return intval($usrIdx);
			}
		}
		return null;
	}

	/**
	 * Gives what is told about a user: the fields of SHOWLIST, and
	 * whether it holds a personal token and a 2FA secret.
	 *
	 * @param Array $userValues The user, as the file holds it.
	 * @return Array What is shown of it.
	 */
	protected function shownVals( Array $userValues ): Array {
		return Array(
			'id_user'=>$userValues['id_user'] ?? null,
			'userName'=>strval($userValues['userName'] ?? ''),
			'userRole'=>strval($userValues['userRole'] ?? Checker::ROLELIST[0]),
			'userStat'=>strval($userValues['userStat'] ?? 'active'),
			'userMail'=>strval($userValues['userMail'] ?? ''),
			'userLang'=>strval($userValues['userLang'] ?? ''),
			'hasToken'=>strval($userValues['tokenKey'] ?? '')!='',
			'hasTwoFa'=>strval($userValues['twoFaKey'] ?? '')!=''
		);
	}

	/**
	 * Tells whether a user is the last one who can manage the others: an
	 * active user of the highest role, with no other one beside it.
	 *
	 * @param Array $usersArray The users.
	 * @param Int   $usersIndex Place of the user in them.
	 * @return Bool Whether locking, demoting or removing it would leave no manager.
	 */
	protected function lastMangr( Array $usersArray, Int $usersIndex ): Bool {
		$topRole = array_slice(Checker::ROLELIST, -1)[0];
		$mngrNum = 0;
		foreach( $usersArray as $usrRow ){
			if( strval($usrRow['userRole'] ?? '')===$topRole && strval($usrRow['userStat'] ?? 'active')==='active' ){
				$mngrNum++;
			}
		}
		$usrVals = $this->shownVals($usersArray[$usersIndex]);
		return $usrVals['userRole']===$topRole && $usrVals['userStat']==='active' && $mngrNum<2;
	}

	/**
	 * Tells which rule a password breaks, the rules being those of the
	 * native userbase: at least 'minlen' characters and at most PASSSIZE
	 * bytes, no control character, and not the name of its user.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $passwdText The password, as given.
	 * @return String The rule that is broken, empty when the password is accepted.
	 */
	protected function checkPass( String $userString, String $passwdText ): String {
		$chrNmbr = preg_match_all('/./su', $passwdText);
		if( ($chrNmbr ?: strlen($passwdText))<$this->minPassw ){
			return 'Password too short: '.$this->minPassw.' characters at least';
		}
		if( strlen($passwdText)>self::PASSSIZE ){
			return 'Password too long: '.self::PASSSIZE.' bytes at most';
		}
		if( preg_match('/[\x00-\x1F\x7F]/', $passwdText) ){
			return 'Password holding a control character';
		}
		if( strcasecmp($passwdText, $userString)==0 ){
			return 'Password identical to the user name';
		}
		return '';
	}

	/**
	 * Traces a change of the file on the 'access' channel, as the native
	 * userbase traces its own.
	 *
	 * @param String $eventMssge What was changed.
	 */
	protected function logChange( String $eventMssge ): Void {
		Logger::getLogger('access')->notice( $eventMssge.' in the users file'.Logger::actorText() );
	}

	/**
	 * Forgets the refusal of the previous operation.
	 */
	protected function resetLast(): Void {
		$this->lastCode = 200;
		$this->lastMssg = '';
	}

	/**
	 * Records why the running operation is refused.
	 *
	 * @param Int    $statusCode The status the refusal stands for (see lastState).
	 * @param String $statusMssg Why it is refused.
	 * @return Bool Always false, for the operation to return.
	 */
	protected function refuseFor( Int $statusCode, String $statusMssg ): Bool {
		$this->lastCode = $statusCode;
		$this->lastMssg = $statusMssg;
		return false;
	}
}
