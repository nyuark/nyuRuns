<?php

namespace nyu\Access\Auth;

use nyu\Access\Checker;
use nyu\Data\DBHndlr\Handler as DbHandlr;
use nyu\Tools\Language;
use nyu\Tools\Logger;

/**
 * Manages the users of the native userbase: creation, activation with
 * a code, self-registration and its review, passwords and their reset,
 * addresses, locks, roles, removal and reading. It works on the table
 * Native checks credentials against, and is not a credential check of
 * its own. It decides nothing about who may call it, and sends no mail:
 * that is the caller's. Each change is logged on the 'access' channel.
 * After a refusal, finalErrorText() tells why and lastStateCount() gives the
 * status it stands for.
 *
 * A code (activation, reset) is 10 characters of CODECHRS, given as
 * XXXX-XXXX-XX: it is read whatever its case, dashes and spaces, and
 * only its SHA-256 hash is stored. It ends after a time: the minutes
 * the caller gives for a code an administrator hands over (ADMNMINS by
 * default, 0 for no end), those of 'codexp' for a code sent by mail.
 */
class UserBase extends Native{

	// A user name : a letter, then letters, digits, dots, underscores or dashes, 3 to 32 characters in all
	const NAME_RULE = '/^[A-Za-z][A-Za-z0-9._\-]{2,31}$/D';
	// Names no user is given, whatever the case
	const NAME_DENY = Array('root', 'admin');
	// What is told about a user : never a hash, a code or a secret
	const SHOW_LIST = Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'userLang', 'signDate', 'madeDate', 'lastSeen');
	// Stored in place of a hash until the user sets its password : no password matches it
	const VOID_HASH = '!';
	// Minutes a code handed over by an administrator lasts
	const HAND_MINS = 300;
	// Longest mail address
	const MAIL_SIZE = 254;
	// Most self-registrations waiting at once, confirmed or not, unless the 'regtop' of the limits says otherwise : beyond, sign-ups are closed for a while
	const SIGN_MAXI = 256;

	protected $latestCode = 200;

	/**
	 * Builds the manager from the config block of the native userbase.
	 *
	 * @param Array $configValues The block (see Native), with the minutes a code sent by mail lasts ('codexp').
	 */
	function __construct( Array $configValues ){
		parent::__construct($configValues);
	}

	/**
	 * Gives the minutes a code sent by mail lasts: those of 'codexp'.
	 *
	 * @return Int The minutes.
	 */
	public function mailedForCount(): Int {
		return $this->mailExpiry;
	}

	/**
	 * Builds the manager of the userbase the login uses: the 'login'
	 * block of the ACCESS config must name the native credential check
	 * ('authby'), and the block of its settings ('authcf', 'native' when
	 * left out).
	 *
	 * @return ?UserBase The manager, or null when the login does not use the native userbase.
	 */
	public static function fromLoginBlock(): ?UserBase {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lgnConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'LOGIN') : null;
		if( !is_array($lgnConf) || strval($lgnConf['authby'] ?? '')!=='native' ){
			return null;
		}
		$blckCfg = $nyuConf->obtainRowValue('ACCESS', strtoupper(strval($lgnConf['authcf'] ?? 'native')));
		return new self( is_array($blckCfg) ? $blckCfg : Array() );
	}

	/**
	 * Creates a user, pending until it is activated: it has no password
	 * yet, and an activation code to set one with (see actvtUser). Only
	 * the SHA-256 hash of the code is stored: the code is returned once.
	 * A name that only differs from an existing one by its case is refused,
	 * and so is an address another user holds.
	 *
	 * @param String  $namedUserTxt Login name of the new user (see NAMERULE and NAMEDENY).
	 * @param String  $usrRleString Its role, one of Checker::ROLE_LIST; the first one when left empty.
	 * @param ?String $emailAddress Its mail address, none when null or empty.
	 * @param Int     $codeMinuteNb Minutes the code lasts, 0 for no end: ADMNMINS for a code handed over, mailedForCount() for one sent by mail.
	 * @param ?String $langTagStrng The language of its mails (see Tools\Language), none when null or empty.
	 * @return String|Bool The activation code, or false when the name, the role, the address or the language is not valid, the name or the address taken, or the table cannot be written.
	 */
	public function enrolUsrString( String $namedUserTxt, String $usrRleString='', ?String $emailAddress=null, Int $codeMinuteNb=self::HAND_MINS, ?String $langTagStrng=null ): String|Bool {
		$this->forgetLastFail();
		$roleVal = ($usrRleString!='') ? $usrRleString : Checker::ROLE_LIST[0];
		if( !preg_match(self::NAME_RULE, $namedUserTxt) || in_array(strtolower($namedUserTxt), self::NAME_DENY, true) ){
			return $this->declineForFlag(400, 'Invalid user name');
		}
		if( !in_array($roleVal, Checker::ROLE_LIST, true) ){
			return $this->declineForFlag(400, 'Invalid role');
		}
		$langVal = $this->testLangString($langTagStrng);
		if( $langVal===false ){
			return $this->declineForFlag(400, 'Invalid language');
		}
		$mailVal = null;
		if( $emailAddress!==null && $emailAddress!=='' ){
			$mailVal = $this->testMailString($emailAddress);
			if( $mailVal===null ){
				return $this->declineForFlag(400, 'Invalid mail address');
			}
		}
		$dupRows = $this->sendFetchArray( 'SELECT {userName} FROM {usrTable} WHERE LOWER({userName})=LOWER(?) LIMIT 1;', Array($namedUserTxt) );
		if( $dupRows===false ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		if( !empty($dupRows) ){
			return $this->declineForFlag(409, 'User name already taken');
		}
		if( $mailVal!==null ){
			$mailUse = $this->emailInUseFlag($mailVal, '');
			if( $mailUse===null ){
				return $this->declineForFlag(500, 'Credential store unavailable');
			}
			if( $mailUse ){
				return $this->declineForFlag(409, 'Mail address already used');
			}
		}
		$newCode = $this->makeAccessCode();
		$addStmt = "INSERT INTO {usrTable} ({userName}, {passHash}, {userRole}, {userStat}, {userMail}, {userLang}, {actvCode}, {codeDate}) VALUES (?, ?, ?, 'pendng', ?, ?, ?, ?);";
		if( $this->sendWriteCount($addStmt, Array($namedUserTxt, self::VOID_HASH, $roleVal, $mailVal, $langVal, $this->codeHashesList($newCode)[0], $this->getCodeEndDate($codeMinuteNb)))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'User '.json_encode($namedUserTxt).' created ('.$roleVal.')'.Logger::getActorSuffix() );
		return $newCode;
	}

	/**
	 * Registers a user who signs up itself: pending, of the lowest role,
	 * with its address, and an activation code that confirms the address
	 * and sets its password (see actvtUser). Once activated, it awaits a
	 * manager in review (see proveUser). The sign-ups never confirmed are
	 * removed once their code has ended.
	 *
	 * A name or an address already taken is not a refusal: '' is returned,
	 * for the caller to answer as it answers any sign-up, and to tell the
	 * address by mail.
	 *
	 * @param String  $namedUserTxt Login name it asks for (see NAMERULE and NAMEDENY).
	 * @param String  $emailAddress Its mail address.
	 * @param Int     $maxWaitingNb Most sign-ups waiting at once, confirmed or not ('regtop' of the limits).
	 * @param ?String $langTagStrng The language of its mails: the one its request asks for; left out when it is not a tag.
	 * @return String|Bool The activation code, '' when the name or the address is taken, or false when one is not valid, too many sign-ups are waiting, or the table cannot be used.
	 */
	public function signsUsrString( String $namedUserTxt, String $emailAddress, Int $maxWaitingNb=self::SIGN_MAXI, ?String $langTagStrng=null ): String|Bool {
		$this->forgetLastFail();
		if( !preg_match(self::NAME_RULE, $namedUserTxt) || in_array(strtolower($namedUserTxt), self::NAME_DENY, true) ){
			return $this->declineForFlag(400, 'Invalid user name');
		}
		$mailVal = $this->testMailString($emailAddress);
		if( $mailVal===null ){
			return $this->declineForFlag(400, 'Invalid mail address');
		}
		$this->dropOldSignups();
		$accsLog = Logger::retrieveLogger('access');
		$waitNum = $this->waitingSignups();
		if( $waitNum===false ){
			return false;
		}
		if( $waitNum>=$maxWaitingNb ){
			$accsLog->warning( 'Sign-up refused: '.$waitNum.' sign-ups are already waiting'.Logger::getActorSuffix() );
			return $this->declineForFlag(503, 'Sign-ups closed for now');
		}
		$dupRows = $this->sendFetchArray( 'SELECT {userName} FROM {usrTable} WHERE LOWER({userName})=LOWER(?) OR {userMail}=? LIMIT 1;', Array($namedUserTxt, $mailVal) );
		if( $dupRows===false ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		if( !empty($dupRows) ){
			// A refusal the client is not told about: always traced
			$accsLog->notice( 'Sign-up as '.json_encode($namedUserTxt).': the name or the address is taken'.Logger::getActorSuffix() );
			return '';
		}
		$newCode = $this->makeAccessCode();
		// The language of the request is a hint : one that is not a tag is left out, never refused
		$langVal = $this->testLangString($langTagStrng);
		$addStmt = "INSERT INTO {usrTable} ({userName}, {passHash}, {userRole}, {userStat}, {userMail}, {userLang}, {actvCode}, {codeDate}, {signDate}) VALUES (?, ?, ?, 'pendng', ?, ?, ?, ?, ?);";
		if( $this->sendWriteCount($addStmt, Array($namedUserTxt, self::VOID_HASH, Checker::ROLE_LIST[0], $mailVal, ($langVal===false) ? null : $langVal, $this->codeHashesList($newCode)[0], $this->getCodeEndDate($this->mailExpiry), date('Y-m-d H:i:s')))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		$accsLog->notice( 'User '.json_encode($namedUserTxt).' signed up'.Logger::getActorSuffix() );
		return $newCode;
	}

	/**
	 * Counts the sign-ups waiting: not yet confirmed, or in review.
	 *
	 * @return Int|Bool The number, or false when the table cannot be read.
	 */
	public function waitingSignups(): Int|Bool {
		$cntRows = $this->sendFetchArray( "SELECT COUNT(*) AS waiting FROM {usrTable} WHERE {signDate} IS NOT NULL AND {userStat} IN ('pendng', 'review');" );
		if( $cntRows===false ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		return intval( array_values($cntRows[0] ?? Array(0))[0] );
	}

	/**
	 * Lists the addresses of the active users of the highest role: those
	 * told when sign-ups pile up, each with the language of its mails.
	 *
	 * @return Array|Bool The languages (null for none) by address, or false when the table cannot be read.
	 */
	public function manageMailList(): Array|Bool {
		$mngrRws = $this->sendFetchArray( "SELECT {userMail}, {userLang} FROM {usrTable} WHERE {userRole}=? AND {userStat}='active' AND {userMail} IS NOT NULL AND {userMail}<>'' ORDER BY {userName};", Array(array_slice(Checker::ROLE_LIST, -1)[0]) );
		if( $mngrRws===false ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		$mgrLang = Array();
		foreach( $mngrRws as $mgrRow ){
			$mgrLang[strval($mgrRow['userMail'])] = (strval($mgrRow['userLang'] ?? '')!=='') ? strval($mgrRow['userLang']) : null;
		}
		return $mgrLang;
	}

	/**
	 * Activates a pending user with its activation code, and gives it
	 * its password. The code is used once, before its end. A user who
	 * signed up itself then awaits a manager ('review'), any other is
	 * active. Whatever is wrong with the name, the status or the code, the
	 * refusal is the same.
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param String  $codeAsString The activation code, as given at its creation or its reset.
	 * @param String  $usrPasswdTxt The password it chooses, following the rules (see checkPass).
	 * @param ?String $langTagStrng The language its request asks for: given to a user who has none yet; left out when it is not a tag.
	 * @return Bool Whether the user is now active, or in review.
	 */
	public function activateUsrFlg( String $namedUserTxt, String $codeAsString, String $usrPasswdTxt, ?String $langTagStrng=null ): Bool {
		$this->forgetLastFail();
		$ruleMsg = $this->testPassString($namedUserTxt, $usrPasswdTxt);
		if( $ruleMsg!='' ){
			return $this->declineForFlag(400, $ruleMsg);
		}
		$langVal = $this->testLangString($langTagStrng);
		// One write decides : a code cannot be used twice, even by two requests at once
		$updtQry = "UPDATE {usrTable} SET {passHash}=?, {passDate}='".date('Y-m-d H:i:s')."', {userStat}=CASE WHEN {signDate} IS NULL THEN 'active' ELSE 'review' END,"
			." {userLang}=CASE WHEN {userLang} IS NULL OR {userLang}='' THEN ? ELSE {userLang} END,"
			." {actvCode}=NULL, {codeDate}=NULL, {failNmbr}=0, {failDate}=NULL"
			." WHERE {userName}=? AND {userStat}='pendng' AND {actvCode} IN (?, ?) AND ({codeDate} IS NULL OR {codeDate}>=?);";
		$rowNumb = $this->sendWriteCount( $updtQry, array_merge(Array(password_hash($usrPasswdTxt, PASSWORD_BCRYPT), is_string($langVal) ? $langVal : null, $namedUserTxt), $this->codeHashesList($codeAsString), Array(date('Y-m-d H:i:s'))) );
		if( $rowNumb<0 ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		if( $rowNumb<1 ){
			Logger::retrieveLogger('access')->warning( 'Activation refused for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
			$this->invalidCrd = true;
			return $this->declineForFlag(401, 'Invalid activation');
		}
		$userVls = $this->fetchUserArray($namedUserTxt);
		if( is_array($userVls) && ($userVls['userStat'] ?? '')=='review' ){
			Logger::retrieveLogger('access')->notice( 'User '.json_encode($namedUserTxt).' confirmed its address, awaits review'.Logger::getActorSuffix() );
		}else{
			Logger::retrieveLogger('access')->notice( 'User '.json_encode($namedUserTxt).' activated'.Logger::getActorSuffix() );
		}
		return true;
	}

	/**
	 * Approves a user who signed up itself and confirmed its address: it
	 * becomes active. Refusing it is removing it (see removUser).
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user is now active.
	 */
	public function approveNewUser( String $namedUserTxt ): Bool {
		$this->forgetLastFail();
		$userVls = $this->knownUserArray($namedUserTxt);
		if( $userVls===null ){
			return false;
		}
		if( ($userVls['userStat'] ?? '')!=='review' ){
			return $this->declineForFlag(409, 'User not awaiting review');
		}
		if( $this->sendWriteCount("UPDATE {usrTable} SET {userStat}='active', {signDate}=NULL WHERE {userName}=? AND {userStat}='review';", Array($namedUserTxt))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'User '.json_encode($namedUserTxt).' approved'.Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Gives an active user who lost its password a reset code, to be sent
	 * to its address: its password still works until the reset is done
	 * (see recovUser), and a later request replaces the code. A user that
	 * is unknown, not active or without an address gets none.
	 *
	 * @param String $nmeOrMailTxt Its login name, or its mail address.
	 * @return Array|Bool The user ('user'), its address ('mail'), the language of its mails ('lang', null for none) and the code ('code'), or false when there is none to give.
	 */
	public function resetPassArray( String $nmeOrMailTxt ): Array|Bool {
		$this->forgetLastFail();
		$lookups = trim($nmeOrMailTxt);
		if( $lookups=='' ){
			return $this->declineForFlag(404, 'User not found');
		}
		$isAMail = str_contains($lookups, '@');
		$usrRows = $this->sendFetchArray( 'SELECT {userName}, {userMail}, {userLang}, {userStat} FROM {usrTable} WHERE '.($isAMail ? '{userMail}' : '{userName}').'=? LIMIT 1;', Array($isAMail ? strtolower($lookups) : $lookups) );
		if( $usrRows===false ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		$accsLog = Logger::retrieveLogger('access');
		if( empty($usrRows) ){
			// A failure, and the trace of names tried: always traced
			$accsLog->notice( 'Reset asked for an unknown user'.Logger::getActorSuffix() );
			return $this->declineForFlag(404, 'User not found');
		}
		$usrName = strval($usrRows[0]['userName']);
		if( ($usrRows[0]['userStat'] ?? '')!=='active' || strval($usrRows[0]['userMail'] ?? '')==='' ){
			$accsLog->warning( 'Reset refused for user '.json_encode($usrName).': not active, or without an address'.Logger::getActorSuffix() );
			return $this->declineForFlag(409, 'No reset for this user');
		}
		$newCode = $this->makeAccessCode();
		if( $this->sendWriteCount("UPDATE {usrTable} SET {resetCod}=?, {codeDate}=? WHERE {userName}=? AND {userStat}='active';", Array($this->codeHashesList($newCode)[0], $this->getCodeEndDate($this->mailExpiry), $usrName))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		$accsLog->notice( 'Reset asked for user '.json_encode($usrName).Logger::getActorSuffix() );
		return Array('user'=>$usrName, 'mail'=>strval($usrRows[0]['userMail']), 'lang'=>(strval($usrRows[0]['userLang'] ?? '')!=='') ? strval($usrRows[0]['userLang']) : null, 'code'=>$newCode);
	}

	/**
	 * Gives an active user a new password with the reset code it asked
	 * for (see resetPass). The code is used once, before its end. Its
	 * failed attempts are forgotten; its role, token and 2FA secret are
	 * kept. Whatever is wrong with the name or the code, the refusal is
	 * the same.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $codeAsString The reset code.
	 * @param String $usrPasswdTxt The new password, following the rules (see checkPass).
	 * @return Bool Whether the password was changed.
	 */
	public function recoverUserFlg( String $namedUserTxt, String $codeAsString, String $usrPasswdTxt ): Bool {
		$this->forgetLastFail();
		$ruleMsg = $this->testPassString($namedUserTxt, $usrPasswdTxt);
		if( $ruleMsg!='' ){
			return $this->declineForFlag(400, $ruleMsg);
		}
		$updtQry = "UPDATE {usrTable} SET {passHash}=?, {passDate}='".date('Y-m-d H:i:s')."', {tokenKey}=NULL, {resetCod}=NULL, {codeDate}=NULL, {failNmbr}=0, {failDate}=NULL"
			." WHERE {userName}=? AND {userStat}='active' AND {resetCod} IN (?, ?) AND {codeDate}>=?;";
		$rowNumb = $this->sendWriteCount( $updtQry, array_merge(Array(password_hash($usrPasswdTxt, PASSWORD_BCRYPT), $namedUserTxt), $this->codeHashesList($codeAsString), Array(date('Y-m-d H:i:s'))) );
		if( $rowNumb<0 ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		if( $rowNumb<1 ){
			Logger::retrieveLogger('access')->warning( 'Password reset refused for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
			$this->invalidCrd = true;
			return $this->declineForFlag(401, 'Invalid code');
		}
		Logger::retrieveLogger('access')->notice( 'Password of user '.json_encode($namedUserTxt).' reset with its code'.Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Gives a user a mail address, or takes it away. An address another
	 * user holds is refused. A reset code asked for is forgotten: it was
	 * sent to the former address.
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param ?String $emailAddress The address, none when null or empty.
	 * @return Bool Whether the address was written.
	 */
	public function chngeEmailFlag( String $namedUserTxt, ?String $emailAddress ): Bool {
		$this->forgetLastFail();
		$mailVal = null;
		if( $emailAddress!==null && $emailAddress!=='' ){
			$mailVal = $this->testMailString($emailAddress);
			if( $mailVal===null ){
				return $this->declineForFlag(400, 'Invalid mail address');
			}
		}
		if( $this->knownUserArray($namedUserTxt)===null ){
			return false;
		}
		if( $mailVal!==null ){
			$mailUse = $this->emailInUseFlag($mailVal, $namedUserTxt);
			if( $mailUse===null ){
				return $this->declineForFlag(500, 'Credential store unavailable');
			}
			if( $mailUse ){
				return $this->declineForFlag(409, 'Mail address already used');
			}
		}
		$updtQry = 'UPDATE {usrTable} SET {userMail}=?, {resetCod}=NULL, {codeDate}=CASE WHEN {actvCode} IS NULL THEN NULL ELSE {codeDate} END WHERE {userName}=?;';
		if( $this->sendWriteCount($updtQry, Array($mailVal, $namedUserTxt))<0 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'Address of user '.json_encode($namedUserTxt).' '.($mailVal===null ? 'removed' : 'set').Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Gives a user the language of its mails, or takes it away: its mails
	 * are then written in the default one (LNG_DFLT).
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param ?String $langTagStrng The language (see Tools\Language), none when null or empty.
	 * @return Bool Whether the language was written.
	 */
	public function changeLanguage( String $namedUserTxt, ?String $langTagStrng ): Bool {
		$this->forgetLastFail();
		$langVal = $this->testLangString($langTagStrng);
		if( $langVal===false ){
			return $this->declineForFlag(400, 'Invalid language');
		}
		if( $this->knownUserArray($namedUserTxt)===null ){
			return false;
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {userLang}=? WHERE {userName}=?;', Array($langVal, $namedUserTxt))<0 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->info( 'Language of user '.json_encode($namedUserTxt).' '.($langVal===null ? 'removed' : 'set to '.$langVal).Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Changes the password of an active user, who proves it is the one
	 * asking by giving the current one. A wrong current password counts
	 * as a failed attempt, and a held user is refused as login refuses it.
	 * The 2FA secret and the personal API token are left as they are.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $previousPass Its current password.
	 * @param String $usrPasswdTxt The new one, following the rules (see checkPass) and different from the current one.
	 * @return Bool Whether the password was changed.
	 */
	public function editPasswdFlag( String $namedUserTxt, String $previousPass, String $usrPasswdTxt ): Bool {
		$this->forgetLastFail();
		$userVls = ($namedUserTxt!='') ? $this->fetchUserArray($namedUserTxt) : null;
		if( $userVls===false ){
			return $this->declineForFlag(500, 'Credential store unavailable');
		}
		if( $userVls===null || ($userVls['userStat'] ?? '')!=='active' ){
			return $this->declineForFlag(401, 'Account not active');
		}
		$accsLog = Logger::retrieveLogger('access');
		$matched = password_verify( $previousPass, strval($userVls['passHash'] ?? '') );
		if( $this->hasHeldNowFlag($userVls) ){
			$accsLog->warning( 'Password change refused: user '.json_encode($namedUserTxt).' is held after too many attempts'.Logger::getActorSuffix() );
			$this->invalidCrd = true;
			return $this->declineForFlag(401, 'Invalid credentials');
		}
		if( !$matched ){
			$this->countFailedTry($namedUserTxt);
			$accsLog->warning( 'Password change refused for user '.json_encode($namedUserTxt).': wrong current password'.Logger::getActorSuffix() );
			$this->invalidCrd = true;
			return $this->declineForFlag(401, 'Invalid credentials');
		}
		if( $previousPass===$usrPasswdTxt ){
			return $this->declineForFlag(400, 'Password identical to the current one');
		}
		if( !$this->savePasswdFlag($namedUserTxt, $usrPasswdTxt) ){
			// setPasswd tells the rule that is broken; without one, the table could not be written
			return ($this->lastMessag!='') ? $this->declineForFlag(400, $this->lastMessag) : $this->declineForFlag(500, 'Credential store unavailable');
		}
		$accsLog->notice( 'Password changed for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Locks an active user, or unlocks a locked one. A lock applies to a
	 * running session as soon as its preset names the userbase as its
	 * credential store. Unlocking forgets the failed attempts. A pending
	 * user is neither: it is activated, or removed; nor is a user in
	 * review: it is approved, or removed. The last active user of the
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
		$userVls = $this->knownUserArray($namedUserTxt);
		if( $userVls===null ){
			return false;
		}
		$nowStts = strval($userVls['userStat'] ?? '');
		if( $nowStts=='pendng' ){
			return $this->declineForFlag(409, 'A pending user is neither locked nor unlocked');
		}
		if( $nowStts=='review' ){
			return $this->declineForFlag(409, 'A user in review is approved or removed');
		}
		if( $nowStts==$statusString ){
			return $this->declineForFlag(409, 'User already '.$statusString);
		}
		if( $statusString=='locked' && $this->lastManagerFlg($userVls) ){
			return $this->declineForFlag(409, 'The last active manager cannot be locked');
		}
		$updtQry = 'UPDATE {usrTable} SET {userStat}=?, {failNmbr}=0, {failDate}=NULL WHERE {userName}=? AND {userStat}=?;';
		if( $this->sendWriteCount($updtQry, Array($statusString, $namedUserTxt, $nowStts))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'User '.json_encode($namedUserTxt).' '.($statusString=='locked' ? 'locked' : 'unlocked').Logger::getActorSuffix() );
		return true;
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
		$userVls = $this->knownUserArray($namedUserTxt);
		if( $userVls===null ){
			return false;
		}
		$nowRole = strval($userVls['userRole'] ?? '');
		if( $nowRole==$usrRleString ){
			return $this->declineForFlag(409, 'User already of that role');
		}
		if( $this->lastManagerFlg($userVls) ){
			return $this->declineForFlag(409, 'The last active manager cannot be given another role');
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {userRole}=? WHERE {userName}=?;', Array($usrRleString, $namedUserTxt))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'Role of user '.json_encode($namedUserTxt).' changed from '.$nowRole.' to '.$usrRleString.Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Removes a user from the table. The last active user of the highest
	 * role cannot be removed.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user was removed.
	 */
	public function removeUserFlag( String $namedUserTxt ): Bool {
		$this->forgetLastFail();
		$userVls = $this->knownUserArray($namedUserTxt);
		if( $userVls===null ){
			return false;
		}
		if( $this->lastManagerFlg($userVls) ){
			return $this->declineForFlag(409, 'The last active manager cannot be removed');
		}
		if( $this->sendWriteCount('DELETE FROM {usrTable} WHERE {userName}=?;', Array($namedUserTxt))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'User '.json_encode($namedUserTxt).' removed ('.strval($userVls['userRole'] ?? '').')'.Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Puts a user back to pending, for one whose password is lost: its
	 * password and its personal API token are taken away, and it is given
	 * a new activation code to choose a password with (see actvtUser).
	 * Its role, its address and its 2FA secret are kept; a reset code it
	 * asked for itself is forgotten. A pending user only gets a new code.
	 * A locked user is unlocked first; the last active user of the highest
	 * role cannot be reset.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param Int    $codeMinuteNb Minutes the code lasts, 0 for no end: ADMNMINS for a code handed over, mailedForCount() for one sent by mail.
	 * @return String|Bool The activation code, returned once, or false when the user is unknown, cannot be reset, or the table cannot be written.
	 */
	public function resetUsrString( String $namedUserTxt, Int $codeMinuteNb=self::HAND_MINS ): String|Bool {
		$this->forgetLastFail();
		$userVls = $this->knownUserArray($namedUserTxt);
		if( $userVls===null ){
			return false;
		}
		if( ($userVls['userStat'] ?? '')=='locked' ){
			return $this->declineForFlag(409, 'A locked user is unlocked before it is reset');
		}
		if( $this->lastManagerFlg($userVls) ){
			return $this->declineForFlag(409, 'The last active manager cannot be reset');
		}
		$newCode = $this->makeAccessCode();
		$updtQry = "UPDATE {usrTable} SET {passHash}=?, {passDate}='".date('Y-m-d H:i:s')."', {userStat}='pendng', {actvCode}=?, {resetCod}=NULL, {codeDate}=?, {tokenKey}=NULL, {failNmbr}=0, {failDate}=NULL"
			." WHERE {userName}=? AND {userStat}<>'locked';";
		if( $this->sendWriteCount($updtQry, Array(self::VOID_HASH, $this->codeHashesList($newCode)[0], $this->getCodeEndDate($codeMinuteNb), $namedUserTxt))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'User '.json_encode($namedUserTxt).' reset'.Logger::getActorSuffix() );
		return $newCode;
	}

	/**
	 * Takes the personal API token of a user away: the token it holds
	 * is no longer accepted, until it is given a new one (see makeToken).
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user has no token any more.
	 */
	public function clearTokenFlag( String $namedUserTxt ): Bool {
		$this->forgetLastFail();
		if( $this->knownUserArray($namedUserTxt)===null ){
			return false;
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {tokenKey}=NULL WHERE {userName}=?;', Array($namedUserTxt))<0 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'Personal token of user '.json_encode($namedUserTxt).' removed'.Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Takes the 2FA secret of a user away, for one whose authenticator is
	 * lost: its password alone logs it in again, until it is given a new
	 * secret (see makeTwoFa).
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user has no 2FA secret any more.
	 */
	public function clearTwoFactor( String $namedUserTxt ): Bool {
		$this->forgetLastFail();
		if( $this->knownUserArray($namedUserTxt)===null ){
			return false;
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {twoFaKey}=NULL, {twoFaStp}=NULL, {twoFaWay}=NULL, {mailCode}=NULL, {mailDate}=NULL WHERE {userName}=?;', Array($namedUserTxt))<0 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( '2FA secret of user '.json_encode($namedUserTxt).' removed'.Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Has an active user give, after its password, a code sent to its
	 * address at each login, in place of the code of an authenticator: a
	 * 2FA secret it held is taken away. It needs an address, and a mail
	 * account named by the block of the login (see Access\AccessMail).
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user now logs in with mailed codes; false when it is unknown, not active, has no address, no mail is sent, or the table cannot be written.
	 */
	public function emailTwoFactor( String $namedUserTxt ): Bool {
		$this->forgetLastFail();
		$userVls = $this->knownUserArray($namedUserTxt);
		if( $userVls===null ){
			return false;
		}
		if( ($userVls['userStat'] ?? '')!=='active' ){
			return $this->declineForFlag(409, 'User not active');
		}
		if( strval($userVls['userMail'] ?? '')=='' ){
			return $this->declineForFlag(409, 'User without a mail address');
		}
		if( \nyu\Access\AccessMail::fromLoginBlock()===null ){
			return $this->declineForFlag(409, 'No mail account sends the codes: mailer is not set');
		}
		$updtQry = "UPDATE {usrTable} SET {twoFaWay}='mailed', {twoFaKey}=NULL, {twoFaStp}=NULL, {mailCode}=NULL, {mailDate}=NULL WHERE {userName}=? AND {userStat}='active';";
		if( $this->sendWriteCount($updtQry, Array($namedUserTxt))<1 ){
			return $this->declineForFlag(500, 'User cannot be written');
		}
		Logger::retrieveLogger('access')->notice( 'Mailed codes set for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Lists the users, by name, with what SHOWLIST tells of each.
	 *
	 * @param Array $criteriaList Optional 'userStat' and 'userRole' values the users must have, and a 'userMail' address, compared in lower case; an empty one is left out.
	 * @param Int   $ceilingCount Most users to give; the default number of rows (LST_ROWS, 100 when not set) when below 1.
	 * @param Int   $offsetRowsNb Users to pass over first.
	 * @return Array|Bool The users, or false when a filter is not valid or the table cannot be read.
	 */
	public function listUsersNamed( Array $criteriaList=Array(), Int $ceilingCount=0, Int $offsetRowsNb=0 ): Array|Bool {
		$this->forgetLastFail();
		$whrList = Array();
		$filters = Array();
		$gvnMail = $criteriaList['userMail'] ?? '';
		if( $gvnMail!=='' && $gvnMail!==null ){
			if( !is_string($gvnMail) ){
				return $this->declineForFlag(400, 'Invalid filter');
			}
			$whrList[] = '{userMail}=?';
			$filters[] = strtolower(trim($gvnMail));
		}
		foreach( Array('userStat'=>self::STAT_LIST, 'userRole'=>Checker::ROLE_LIST) as $fldNme=>$allows ){
			$gvnText = $criteriaList[$fldNme] ?? '';
			if( $gvnText==='' || $gvnText===null ){
				continue;
			}
			if( !in_array($gvnText, $allows, true) ){
				return $this->declineForFlag(400, 'Invalid filter');
			}
			$whrList[] = '{'.$fldNme.'}=?';
			$filters[] = $gvnText;
		}
		$rowsLmt = $ceilingCount;
		if( $rowsLmt<1 ){
			$nyuConf = $GLOBALS['nyuConfig'] ?? null;
			$iniConf = $nyuConf ? ($nyuConf->readFileDetail('system') ?: Array()) : Array();
			$rowsLmt = intval($iniConf['LST_ROWS'] ?? 100) ?: 100;
		}
		$listQry = 'SELECT {'.implode('}, {', self::SHOW_LIST).'} FROM {usrTable}'
			.( !empty($whrList) ? ' WHERE '.implode(' AND ', $whrList) : '' )
			.' ORDER BY {userName} LIMIT '.$rowsLmt.' OFFSET '.max(0, $offsetRowsNb).';';
		$usrRows = $this->sendFetchArray($listQry, $filters);
		return ($usrRows===false) ? $this->declineForFlag(500, 'Credential store unavailable') : $usrRows;
	}

	/**
	 * Reads one user, with what SHOWLIST tells of it.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Array|Bool The user, or false when it is unknown or the table cannot be read.
	 */
	public function userInfosArray( String $namedUserTxt ): Array|Bool {
		$this->forgetLastFail();
		$userVls = $this->knownUserArray($namedUserTxt);
		if( $userVls===null ){
			return false;
		}
		$shownAr = Array();
		foreach( self::SHOW_LIST as $fldNme ){
			$shownAr[$fldNme] = $userVls[$fldNme] ?? null;
		}
		return $shownAr;
	}

	/**
	 * Gives the status the last refusal stands for, as an HTTP status:
	 * 400 for a value that is not valid, 401 for a proof that is wrong,
	 * 404 for an unknown user, 409 for a change the state of the user
	 * does not allow, 500 when the table cannot be used.
	 *
	 * @return Int The status, 200 when the last operation was not refused.
	 */
	public function lastStateCount(): Int {
		return $this->latestCode;
	}

	/**
	 * Forgets the refusal of the previous operation.
	 */
	protected function forgetLastFail(): Void {
		$this->latestCode = 200;
		$this->lastMessag = '';
		$this->invalidCrd = false;
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

	/**
	 * Reads the row of the user an operation is about, recording the
	 * refusal when there is none.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return ?Array The user's row, or null when it is unknown or the table cannot be read.
	 */
	protected function knownUserArray( String $namedUserTxt ): ?Array {
		$userVls = ($namedUserTxt!='') ? $this->fetchUserArray($namedUserTxt) : null;
		if( $userVls===false ){
			$this->declineForFlag(500, 'Credential store unavailable');
			return null;
		}
		if( $userVls===null ){
			$this->declineForFlag(404, 'User not found');
		}
		return $userVls;
	}

	/**
	 * Checks a mail address and gives it as it is stored: in lower case.
	 *
	 * @param String $emailAddress The address given.
	 * @return ?String The address to store, or null when it is not one.
	 */
	protected function testMailString( String $emailAddress ): ?String {
		$mailVal = strtolower( trim($emailAddress) );
		if( strlen($mailVal)>self::MAIL_SIZE || filter_var($mailVal, FILTER_VALIDATE_EMAIL)===false ){
			return null;
		}
		return $mailVal;
	}

	/**
	 * Checks a language and gives it as it is stored: a tag in lower case,
	 * '_' read as '-' (see Tools\Language).
	 *
	 * @param ?String $langTagStrng The language given.
	 * @return String|Bool|null The language to store, null when none is given, or false when it is not a tag.
	 */
	protected function testLangString( ?String $langTagStrng ): String|Bool|null {
		$langVal = strtolower( trim(str_replace('_', '-', strval($langTagStrng))) );
		if( $langVal==='' ){
			return null;
		}
		return Language::isValidLangTag($langVal) ? $langVal : false;
	}

	/**
	 * Tells whether another user holds an address.
	 *
	 * @param String $emailAddress The address, as stored.
	 * @param String $namedUserTxt The user left out of the search, '' for none.
	 * @return ?Bool Whether another user holds it, or null when the table cannot be read.
	 */
	protected function emailInUseFlag( String $emailAddress, String $namedUserTxt ): ?Bool {
		$usrRows = $this->sendFetchArray( 'SELECT {userName} FROM {usrTable} WHERE {userMail}=? AND {userName}<>? LIMIT 1;', Array($emailAddress, $namedUserTxt) );
		return ($usrRows===false) ? null : !empty($usrRows);
	}

	/**
	 * Gives the end of a code made now.
	 *
	 * @param Int $codeMinuteNb Minutes the code lasts, 0 for no end.
	 * @return ?String The time it ends, as stored, or null for no end.
	 */
	protected function getCodeEndDate( Int $codeMinuteNb ): ?String {
		return ($codeMinuteNb>0) ? date('Y-m-d H:i:s', time() + 60*$codeMinuteNb) : null;
	}

	/**
	 * Removes the sign-ups whose address was not confirmed before the end
	 * of their code: their name and address are free again.
	 */
	protected function dropOldSignups(): Void {
		$deltNum = $this->sendWriteCount( "DELETE FROM {usrTable} WHERE {signDate} IS NOT NULL AND {userStat}='pendng' AND {codeDate} IS NOT NULL AND {codeDate}<?;", Array(date('Y-m-d H:i:s')) );
		if( $deltNum>0 ){
			Logger::retrieveLogger('access')->info( $deltNum.' sign-ups not confirmed in time removed' );
		}
	}

	/**
	 * Tells whether a user is the last one who can manage the others: an
	 * active user of the highest role, with no other one beside it. A
	 * count that cannot be read is answered as if it were.
	 *
	 * @param Array $usrValuesArr The user's row.
	 * @return Bool Whether locking, demoting, removing or resetting it would leave no manager.
	 */
	protected function lastManagerFlg( Array $usrValuesArr ): Bool {
		$topRole = array_slice(Checker::ROLE_LIST, -1)[0];
		if( ($usrValuesArr['userRole'] ?? '')!==$topRole || ($usrValuesArr['userStat'] ?? '')!=='active' ){
			return false;
		}
		$othrRws = $this->sendFetchArray( "SELECT {userName} FROM {usrTable} WHERE {userRole}=? AND {userStat}='active' AND {userName}<>? LIMIT 1;", Array($topRole, strval($usrValuesArr['userName'] ?? '')) );
		return empty($othrRws);
	}

	/**
	 * Writes a query's {name} parts as the database in use expects them:
	 * {usrTable} is the users table, the others its fields (see quoteFlds).
	 *
	 * @param DbHandlr $dbHandlerObj The database handler.
	 * @param String   $queryPattern The query, its table and fields written {name}.
	 * @return String The query, ready to be sent.
	 */
	protected function shapeQueryText( DbHandlr $dbHandlerObj, String $queryPattern ): String {
		$swapLst = Array();
		foreach( $this->quoteFieldsArr($dbHandlerObj) as $fldNme=>$qtdNme ){
			$swapLst['{'.$fldNme.'}'] = $qtdNme;
		}
		return strtr($queryPattern, $swapLst);
	}

	/**
	 * Sends a query that changes the users table.
	 *
	 * @param String $queryPattern The query, its table and fields written {name} (see shapeQury).
	 * @param Array  $valuesToBind Values bound, in order, to its ? placeholders.
	 * @return Int The number of rows changed, or -1 when the table cannot be written.
	 */
	protected function sendWriteCount( String $queryPattern, Array $valuesToBind ): Int {
		if( $this->usersTable=='' ){
			return -1;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			return $dbHndlr->sendBuiltQuery($this->shapeQueryText($dbHndlr, $queryPattern), true, $valuesToBind) ? $dbHndlr->countLastWrite() : -1;
		}catch( \Throwable $e ){
			return -1;
		}
	}

	/**
	 * Sends a query that reads the users table.
	 *
	 * @param String $queryPattern The query, its table and fields written {name} (see shapeQury).
	 * @param Array  $valuesToBind Values bound, in order, to its ? placeholders.
	 * @return Array|Bool The rows, or false when the table cannot be read.
	 */
	protected function sendFetchArray( String $queryPattern, Array $valuesToBind=Array() ): Array|Bool {
		if( $this->usersTable=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			if( !$dbHndlr->sendBuiltQuery($this->shapeQueryText($dbHndlr, $queryPattern), false, $valuesToBind) ){
				return false;
			}
			$usrRows = $dbHndlr->readLastResult(true);
		}catch( \Throwable $e ){
			return false;
		}
		return is_array($usrRows) ? $usrRows : false;
	}
}
