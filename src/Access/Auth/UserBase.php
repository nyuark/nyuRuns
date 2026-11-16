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
 * After a refusal, getLastErrText() tells why and getRefusalCode() gives the
 * status it stands for.
 *
 * A code (activation, reset) is 10 characters of CODECHRS, given as
 * XXXX-XXXX-XX: it is read whatever its case, dashes and spaces, and
 * only its SHA-256 hash is stored. It ends after a time: the minutes
 * the caller gives for a code an administrator hands over (ADMNMINS by
 * default, 0 for no end), those of 'codeMins' for a code sent by mail.
 */
class UserBase extends Native{

	// A user name : a letter, then letters, digits, dots, underscores or dashes, 3 to 32 characters in all
	const NAME_RULE = '/^[A-Za-z][A-Za-z0-9._\-]{2,31}$/D';
	// Names no user is given, whatever the case
	const NAME_DENY = Array('root', 'admin');
	// What is told about a user : never a hash, a code or a secret
	const SHOW_LIST = Array('id_user', 'systemName', 'accessRole', 'userStatus', 'contactEml', 'userLocale', 'signupDate', 'createDate', 'accessDate');
	// Stored in place of a hash until the user sets its password : no password matches it
	const VOID_HASH = '!';
	// Minutes a code handed over by an administrator lasts
	const HAND_MINS = 300;
	// Longest mail address
	const MAIL_SIZE = 254;
	// Most self-registrations waiting at once, confirmed or not, unless the 'maxQueue' of the limits says otherwise : beyond, sign-ups are closed for a while
	const SIGN_MAXI = 256;

	protected $latestCode = 200;

	/**
	 * Builds the manager from the config block of the native userbase.
	 *
	 * @param Array $configValues The block (see Native), with the minutes a code sent by mail lasts ('codeMins').
	 */
	function __construct( Array $configValues ){
		parent::__construct($configValues);
	}

	/**
	 * Gives the minutes a code sent by mail lasts: those of 'codeMins'.
	 *
	 * @return Int The minutes.
	 */
	public function getEmlCodeMins(): Int {
		return $this->twoFactExp;
	}

	/**
	 * Builds the manager of the userbase the login uses: the 'loginSet'
	 * block of the ACCESS config must name the native credential check
	 * ('authWith'), and the block of its settings ('authConf', 'nativeDb' when
	 * left out).
	 *
	 * @return ?UserBase The manager, or null when the login does not use the native userbase.
	 */
	public static function makeByLoginCfg(): ?UserBase {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$loginCfg = ($nyuConfg!==null) ? $nyuConfg->getNestedValue('ACCESS', 'LOGINSET') : null;
		if( !is_array($loginCfg) || strval($loginCfg['authWith'] ?? '')!=='nativeDb' ){
			return null;
		}
		$blockCfg = $nyuConfg->getNestedValue('ACCESS', strtoupper(strval($loginCfg['authConf'] ?? 'nativeDb')));
		return new self( is_array($blockCfg) ? $blockCfg : Array() );
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
	 * @param Int     $codMinuteNum Minutes the code lasts, 0 for no end: ADMNMINS for a code handed over, getEmlCodeMins() for one sent by mail.
	 * @param ?String $givenLangTag The language of its mails (see Tools\Language), none when null or empty.
	 * @return String|Bool The activation code, or false when the name, the role, the address or the language is not valid, the name or the address taken, or the table cannot be written.
	 */
	public function enrolUsrString( String $namedUserTxt, String $usrRleString='', ?String $emailAddress=null, Int $codMinuteNum=self::HAND_MINS, ?String $givenLangTag=null ): String|Bool {
		$this->dropOldRefusal();
		$roleVals = ($usrRleString!='') ? $usrRleString : Checker::ROLE_LIST[0];
		if( !preg_match(self::NAME_RULE, $namedUserTxt) || in_array(strtolower($namedUserTxt), self::NAME_DENY, true) ){
			return $this->denyWithReason(400, 'Invalid user name');
		}
		if( !in_array($roleVals, Checker::ROLE_LIST, true) ){
			return $this->denyWithReason(400, 'Invalid role');
		}
		$langVals = $this->checkLangAsTag($givenLangTag);
		if( $langVals===false ){
			return $this->denyWithReason(400, 'Invalid language');
		}
		$mailVals = null;
		if( $emailAddress!==null && $emailAddress!=='' ){
			$mailVals = $this->checkEmailAddr($emailAddress);
			if( $mailVals===null ){
				return $this->denyWithReason(400, 'Invalid mail address');
			}
		}
		$duplRows = $this->sendFetchArray( 'SELECT {systemName} FROM {usrTable} WHERE LOWER({systemName})=LOWER(?) LIMIT 1;', Array($namedUserTxt) );
		if( $duplRows===false ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		if( !empty($duplRows) ){
			return $this->denyWithReason(409, 'User name already taken');
		}
		if( $mailVals!==null ){
			$mailUses = $this->isEmailClaimed($mailVals, '');
			if( $mailUses===null ){
				return $this->denyWithReason(500, 'Credential store unavailable');
			}
			if( $mailUses ){
				return $this->denyWithReason(409, 'Mail address already used');
			}
		}
		$newCodig = $this->makeAccessCode();
		$addSttmt = "INSERT INTO {usrTable} ({systemName}, {passwdHash}, {accessRole}, {userStatus}, {contactEml}, {userLocale}, {actvtnCode}, {codeExpiry}, {createDate}) VALUES (?, ?, ?, 'awaiting', ?, ?, ?, ?, '".date('Y-m-d H:i:s')."');";
		if( $this->sendWriteCount($addSttmt, Array($namedUserTxt, self::VOID_HASH, $roleVals, $mailVals, $langVals, $this->listCodeHashes($newCodig)[0], $this->getCodeEndDate($codMinuteNum)))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'User '.json_encode($namedUserTxt).' created ('.$roleVals.')'.Logger::getActorSuffix() );
		return $newCodig;
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
	 * @param Int     $waitingLimit Most sign-ups waiting at once, confirmed or not ('maxQueue' of the limits).
	 * @param ?String $givenLangTag The language of its mails: the one its request asks for; left out when it is not a tag.
	 * @return String|Bool The activation code, '' when the name or the address is taken, or false when one is not valid, too many sign-ups are waiting, or the table cannot be used.
	 */
	public function registerSignup( String $namedUserTxt, String $emailAddress, Int $waitingLimit=self::SIGN_MAXI, ?String $givenLangTag=null ): String|Bool {
		$this->dropOldRefusal();
		if( !preg_match(self::NAME_RULE, $namedUserTxt) || in_array(strtolower($namedUserTxt), self::NAME_DENY, true) ){
			return $this->denyWithReason(400, 'Invalid user name');
		}
		$mailVals = $this->checkEmailAddr($emailAddress);
		if( $mailVals===null ){
			return $this->denyWithReason(400, 'Invalid mail address');
		}
		$this->dropOldSignups();
		$accsJrnl = Logger::getLoggerOfChn('access');
		$waitNumb = $this->countWaitUsers();
		if( $waitNumb===false ){
			return false;
		}
		if( $waitNumb>=$waitingLimit ){
			$accsJrnl->warning( 'Sign-up refused: '.$waitNumb.' sign-ups are already waiting'.Logger::getActorSuffix() );
			return $this->denyWithReason(503, 'Sign-ups closed for now');
		}
		$duplRows = $this->sendFetchArray( 'SELECT {systemName} FROM {usrTable} WHERE LOWER({systemName})=LOWER(?) OR {contactEml}=? LIMIT 1;', Array($namedUserTxt, $mailVals) );
		if( $duplRows===false ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		if( !empty($duplRows) ){
			// A refusal the client is not told about: always traced
			$accsJrnl->notice( 'Sign-up as '.json_encode($namedUserTxt).': the name or the address is taken'.Logger::getActorSuffix() );
			return '';
		}
		$newCodig = $this->makeAccessCode();
		// The language of the request is a hint : one that is not a tag is left out, never refused
		$langVals = $this->checkLangAsTag($givenLangTag);
		$addSttmt = "INSERT INTO {usrTable} ({systemName}, {passwdHash}, {accessRole}, {userStatus}, {contactEml}, {userLocale}, {actvtnCode}, {codeExpiry}, {signupDate}, {createDate}) VALUES (?, ?, ?, 'awaiting', ?, ?, ?, ?, ?, '".date('Y-m-d H:i:s')."');";
		if( $this->sendWriteCount($addSttmt, Array($namedUserTxt, self::VOID_HASH, Checker::ROLE_LIST[0], $mailVals, ($langVals===false) ? null : $langVals, $this->listCodeHashes($newCodig)[0], $this->getCodeEndDate($this->twoFactExp), date('Y-m-d H:i:s')))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		$accsJrnl->notice( 'User '.json_encode($namedUserTxt).' signed up'.Logger::getActorSuffix() );
		return $newCodig;
	}

	/**
	 * Counts the sign-ups waiting: not yet confirmed, or in review.
	 *
	 * @return Int|Bool The number, or false when the table cannot be read.
	 */
	public function countWaitUsers(): Int|Bool {
		$countRow = $this->sendFetchArray( "SELECT COUNT(*) AS waiting FROM {usrTable} WHERE {signupDate} IS NOT NULL AND {userStatus} IN ('awaiting', 'inReview');" );
		if( $countRow===false ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		return intval( array_values($countRow[0] ?? Array(0))[0] );
	}

	/**
	 * Lists the addresses of the active users of the highest role: those
	 * told when sign-ups pile up, each with the language of its mails.
	 *
	 * @return Array|Bool The languages (null for none) by address, or false when the table cannot be read.
	 */
	public function listMngrEmails(): Array|Bool {
		$mngrRows = $this->sendFetchArray( "SELECT {contactEml}, {userLocale} FROM {usrTable} WHERE {accessRole}=? AND {userStatus}='approved' AND {contactEml} IS NOT NULL AND {contactEml}<>'' ORDER BY {systemName};", Array(array_slice(Checker::ROLE_LIST, -1)[0]) );
		if( $mngrRows===false ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		$mngrLang = Array();
		foreach( $mngrRows as $oneRow ){
			$mngrLang[strval($oneRow['contactEml'])] = (strval($oneRow['userLocale'] ?? '')!=='') ? strval($oneRow['userLocale']) : null;
		}
		return $mngrLang;
	}

	/**
	 * Activates a pending user with its activation code, and gives it
	 * its password. The code is used once, before its end. A user who
	 * signed up itself then awaits a manager ('inReview'), any other is
	 * active. Whatever is wrong with the name, the status or the code, the
	 * refusal is the same.
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param String  $codeAsString The activation code, as given at its creation or its reset.
	 * @param String  $usrPasswdTxt The password it chooses, following the rules (see checkPass).
	 * @param ?String $givenLangTag The language its request asks for: given to a user who has none yet; left out when it is not a tag.
	 * @return Bool Whether the user is now active, or in review.
	 */
	public function activateByCode( String $namedUserTxt, String $codeAsString, String $usrPasswdTxt, ?String $givenLangTag=null ): Bool {
		$this->dropOldRefusal();
		$ruleText = $this->checkPassRules($namedUserTxt, $usrPasswdTxt);
		if( $ruleText!='' ){
			return $this->denyWithReason(400, $ruleText);
		}
		$langVals = $this->checkLangAsTag($givenLangTag);
		// One write decides : a code cannot be used twice, even by two requests at once
		$updtQuer = "UPDATE {usrTable} SET {passwdHash}=?, {passwdDate}='".date('Y-m-d H:i:s')."', {userStatus}=CASE WHEN {signupDate} IS NULL THEN 'approved' ELSE 'inReview' END,"
			." {userLocale}=CASE WHEN {userLocale} IS NULL OR {userLocale}='' THEN ? ELSE {userLocale} END,"
			." {actvtnCode}=NULL, {codeExpiry}=NULL, {failsCount}=0, {failedTime}=NULL"
			." WHERE {systemName}=? AND {userStatus}='awaiting' AND {actvtnCode} IN (?, ?) AND ({codeExpiry} IS NULL OR {codeExpiry}>=?);";
		$rowCount = $this->sendWriteCount( $updtQuer, array_merge(Array(password_hash($usrPasswdTxt, PASSWORD_BCRYPT), is_string($langVals) ? $langVals : null, $namedUserTxt), $this->listCodeHashes($codeAsString), Array(date('Y-m-d H:i:s'))) );
		if( $rowCount<0 ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		if( $rowCount<1 ){
			Logger::getLoggerOfChn('access')->warning( 'Activation refused for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
			$this->isWrongTry = true;
			return $this->denyWithReason(401, 'Invalid activation');
		}
		$userVals = $this->readUserRecord($namedUserTxt);
		if( is_array($userVals) && ($userVals['userStatus'] ?? '')=='inReview' ){
			Logger::getLoggerOfChn('access')->notice( 'User '.json_encode($namedUserTxt).' confirmed its address, awaits review'.Logger::getActorSuffix() );
		}else{
			Logger::getLoggerOfChn('access')->notice( 'User '.json_encode($namedUserTxt).' activated'.Logger::getActorSuffix() );
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
		$this->dropOldRefusal();
		$userVals = $this->readUserOrDeny($namedUserTxt);
		if( $userVals===null ){
			return false;
		}
		if( ($userVals['userStatus'] ?? '')!=='inReview' ){
			return $this->denyWithReason(409, 'User not awaiting review');
		}
		if( $this->sendWriteCount("UPDATE {usrTable} SET {userStatus}='approved', {signupDate}=NULL WHERE {systemName}=? AND {userStatus}='inReview';", Array($namedUserTxt))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'User '.json_encode($namedUserTxt).' approved'.Logger::getActorSuffix() );
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
	public function makeRecovrCode( String $nmeOrMailTxt ): Array|Bool {
		$this->dropOldRefusal();
		$lkpsText = trim($nmeOrMailTxt);
		if( $lkpsText=='' ){
			return $this->denyWithReason(404, 'User not found');
		}
		$isEmlAdr = str_contains($lkpsText, '@');
		$userNtry = $this->sendFetchArray( 'SELECT {systemName}, {contactEml}, {userLocale}, {userStatus} FROM {usrTable} WHERE '.($isEmlAdr ? '{contactEml}' : '{systemName}').'=? LIMIT 1;', Array($isEmlAdr ? strtolower($lkpsText) : $lkpsText) );
		if( $userNtry===false ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		$accsJrnl = Logger::getLoggerOfChn('access');
		if( empty($userNtry) ){
			// A failure, and the trace of names tried: always traced
			$accsJrnl->notice( 'Reset asked for an unknown user'.Logger::getActorSuffix() );
			return $this->denyWithReason(404, 'User not found');
		}
		$userName = strval($userNtry[0]['systemName']);
		if( ($userNtry[0]['userStatus'] ?? '')!=='approved' || strval($userNtry[0]['contactEml'] ?? '')==='' ){
			$accsJrnl->warning( 'Reset refused for user '.json_encode($userName).': not active, or without an address'.Logger::getActorSuffix() );
			return $this->denyWithReason(409, 'No reset for this user');
		}
		$newCodig = $this->makeAccessCode();
		if( $this->sendWriteCount("UPDATE {usrTable} SET {recovrCode}=?, {codeExpiry}=? WHERE {systemName}=? AND {userStatus}='approved';", Array($this->listCodeHashes($newCodig)[0], $this->getCodeEndDate($this->twoFactExp), $userName))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		$accsJrnl->notice( 'Reset asked for user '.json_encode($userName).Logger::getActorSuffix() );
		return Array('user'=>$userName, 'mail'=>strval($userNtry[0]['contactEml']), 'lang'=>(strval($userNtry[0]['userLocale'] ?? '')!=='') ? strval($userNtry[0]['userLocale']) : null, 'code'=>$newCodig);
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
	public function recoverAccount( String $namedUserTxt, String $codeAsString, String $usrPasswdTxt ): Bool {
		$this->dropOldRefusal();
		$ruleText = $this->checkPassRules($namedUserTxt, $usrPasswdTxt);
		if( $ruleText!='' ){
			return $this->denyWithReason(400, $ruleText);
		}
		$updtQuer = "UPDATE {usrTable} SET {passwdHash}=?, {passwdDate}='".date('Y-m-d H:i:s')."', {bearerHash}=NULL, {recovrCode}=NULL, {codeExpiry}=NULL, {failsCount}=0, {failedTime}=NULL"
			." WHERE {systemName}=? AND {userStatus}='approved' AND {recovrCode} IN (?, ?) AND {codeExpiry}>=?;";
		$rowCount = $this->sendWriteCount( $updtQuer, array_merge(Array(password_hash($usrPasswdTxt, PASSWORD_BCRYPT), $namedUserTxt), $this->listCodeHashes($codeAsString), Array(date('Y-m-d H:i:s'))) );
		if( $rowCount<0 ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		if( $rowCount<1 ){
			Logger::getLoggerOfChn('access')->warning( 'Password reset refused for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
			$this->isWrongTry = true;
			return $this->denyWithReason(401, 'Invalid code');
		}
		Logger::getLoggerOfChn('access')->notice( 'Password of user '.json_encode($namedUserTxt).' reset with its code'.Logger::getActorSuffix() );
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
	public function alterUserEmail( String $namedUserTxt, ?String $emailAddress ): Bool {
		$this->dropOldRefusal();
		$mailVals = null;
		if( $emailAddress!==null && $emailAddress!=='' ){
			$mailVals = $this->checkEmailAddr($emailAddress);
			if( $mailVals===null ){
				return $this->denyWithReason(400, 'Invalid mail address');
			}
		}
		if( $this->readUserOrDeny($namedUserTxt)===null ){
			return false;
		}
		if( $mailVals!==null ){
			$mailUses = $this->isEmailClaimed($mailVals, $namedUserTxt);
			if( $mailUses===null ){
				return $this->denyWithReason(500, 'Credential store unavailable');
			}
			if( $mailUses ){
				return $this->denyWithReason(409, 'Mail address already used');
			}
		}
		$updtQuer = 'UPDATE {usrTable} SET {contactEml}=?, {recovrCode}=NULL, {codeExpiry}=CASE WHEN {actvtnCode} IS NULL THEN NULL ELSE {codeExpiry} END WHERE {systemName}=?;';
		if( $this->sendWriteCount($updtQuer, Array($mailVals, $namedUserTxt))<0 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'Address of user '.json_encode($namedUserTxt).' '.($mailVals===null ? 'removed' : 'set').Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Gives a user the language of its mails, or takes it away: its mails
	 * are then written in the default one (DEF_LANG).
	 *
	 * @param String  $namedUserTxt Login name of the user.
	 * @param ?String $givenLangTag The language (see Tools\Language), none when null or empty.
	 * @return Bool Whether the language was written.
	 */
	public function alterMailsLang( String $namedUserTxt, ?String $givenLangTag ): Bool {
		$this->dropOldRefusal();
		$langVals = $this->checkLangAsTag($givenLangTag);
		if( $langVals===false ){
			return $this->denyWithReason(400, 'Invalid language');
		}
		if( $this->readUserOrDeny($namedUserTxt)===null ){
			return false;
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {userLocale}=? WHERE {systemName}=?;', Array($langVals, $namedUserTxt))<0 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->info( 'Language of user '.json_encode($namedUserTxt).' '.($langVals===null ? 'removed' : 'set to '.$langVals).Logger::getActorSuffix() );
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
	public function alterPassByOld( String $namedUserTxt, String $previousPass, String $usrPasswdTxt ): Bool {
		$this->dropOldRefusal();
		$userVals = ($namedUserTxt!='') ? $this->readUserRecord($namedUserTxt) : null;
		if( $userVals===false ){
			return $this->denyWithReason(500, 'Credential store unavailable');
		}
		if( $userVals===null || ($userVals['userStatus'] ?? '')!=='approved' ){
			return $this->denyWithReason(401, 'Account not active');
		}
		$accsJrnl = Logger::getLoggerOfChn('access');
		$matxList = password_verify( $previousPass, strval($userVals['passwdHash'] ?? '') );
		if( $this->isUserHeldBack($userVals) ){
			$accsJrnl->warning( 'Password change refused: user '.json_encode($namedUserTxt).' is held after too many attempts'.Logger::getActorSuffix() );
			$this->isWrongTry = true;
			return $this->denyWithReason(401, 'Invalid credentials');
		}
		if( !$matxList ){
			$this->countFailedTry($namedUserTxt);
			$accsJrnl->warning( 'Password change refused for user '.json_encode($namedUserTxt).': wrong current password'.Logger::getActorSuffix() );
			$this->isWrongTry = true;
			return $this->denyWithReason(401, 'Invalid credentials');
		}
		if( $previousPass===$usrPasswdTxt ){
			return $this->denyWithReason(400, 'Password identical to the current one');
		}
		if( !$this->savePassOfUser($namedUserTxt, $usrPasswdTxt) ){
			// setPasswd tells the rule that is broken; without one, the table could not be written
			return ($this->errMessage!='') ? $this->denyWithReason(400, $this->errMessage) : $this->denyWithReason(500, 'Credential store unavailable');
		}
		$accsJrnl->notice( 'Password changed for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
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
	 * @param String $statusString The status to give: 'disabled' or 'approved'.
	 * @return Bool Whether the status was changed.
	 */
	public function saveUserStatus( String $namedUserTxt, String $statusString ): Bool {
		$this->dropOldRefusal();
		if( !in_array($statusString, Array('approved', 'disabled'), true) ){
			return $this->denyWithReason(400, 'Invalid status');
		}
		$userVals = $this->readUserOrDeny($namedUserTxt);
		if( $userVals===null ){
			return false;
		}
		$nowStats = strval($userVals['userStatus'] ?? '');
		if( $nowStats=='awaiting' ){
			return $this->denyWithReason(409, 'A pending user is neither locked nor unlocked');
		}
		if( $nowStats=='inReview' ){
			return $this->denyWithReason(409, 'A user in review is approved or removed');
		}
		if( $nowStats==$statusString ){
			return $this->denyWithReason(409, 'User already '.$statusString);
		}
		if( $statusString=='disabled' && $this->isFinalManager($userVals) ){
			return $this->denyWithReason(409, 'The last active manager cannot be locked');
		}
		$updtQuer = 'UPDATE {usrTable} SET {userStatus}=?, {failsCount}=0, {failedTime}=NULL WHERE {systemName}=? AND {userStatus}=?;';
		if( $this->sendWriteCount($updtQuer, Array($statusString, $namedUserTxt, $nowStats))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'User '.json_encode($namedUserTxt).' '.($statusString=='disabled' ? 'locked' : 'unlocked').Logger::getActorSuffix() );
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
	public function alterRoleOfUsr( String $namedUserTxt, String $usrRleString ): Bool {
		$this->dropOldRefusal();
		if( !in_array($usrRleString, Checker::ROLE_LIST, true) ){
			return $this->denyWithReason(400, 'Invalid role');
		}
		$userVals = $this->readUserOrDeny($namedUserTxt);
		if( $userVals===null ){
			return false;
		}
		$currRole = strval($userVals['accessRole'] ?? '');
		if( $currRole==$usrRleString ){
			return $this->denyWithReason(409, 'User already of that role');
		}
		if( $this->isFinalManager($userVals) ){
			return $this->denyWithReason(409, 'The last active manager cannot be given another role');
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {accessRole}=? WHERE {systemName}=?;', Array($usrRleString, $namedUserTxt))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'Role of user '.json_encode($namedUserTxt).' changed from '.$currRole.' to '.$usrRleString.Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Removes a user from the table. The last active user of the highest
	 * role cannot be removed.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user was removed.
	 */
	public function dropUsrAccount( String $namedUserTxt ): Bool {
		$this->dropOldRefusal();
		$userVals = $this->readUserOrDeny($namedUserTxt);
		if( $userVals===null ){
			return false;
		}
		if( $this->isFinalManager($userVals) ){
			return $this->denyWithReason(409, 'The last active manager cannot be removed');
		}
		if( $this->sendWriteCount('DELETE FROM {usrTable} WHERE {systemName}=?;', Array($namedUserTxt))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'User '.json_encode($namedUserTxt).' removed ('.strval($userVals['accessRole'] ?? '').')'.Logger::getActorSuffix() );
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
	 * @param Int    $codMinuteNum Minutes the code lasts, 0 for no end: ADMNMINS for a code handed over, getEmlCodeMins() for one sent by mail.
	 * @return String|Bool The activation code, returned once, or false when the user is unknown, cannot be reset, or the table cannot be written.
	 */
	public function clearUserAccnt( String $namedUserTxt, Int $codMinuteNum=self::HAND_MINS ): String|Bool {
		$this->dropOldRefusal();
		$userVals = $this->readUserOrDeny($namedUserTxt);
		if( $userVals===null ){
			return false;
		}
		if( ($userVals['userStatus'] ?? '')=='disabled' ){
			return $this->denyWithReason(409, 'A locked user is unlocked before it is reset');
		}
		if( $this->isFinalManager($userVals) ){
			return $this->denyWithReason(409, 'The last active manager cannot be reset');
		}
		$newCodig = $this->makeAccessCode();
		$updtQuer = "UPDATE {usrTable} SET {passwdHash}=?, {passwdDate}='".date('Y-m-d H:i:s')."', {userStatus}='awaiting', {actvtnCode}=?, {recovrCode}=NULL, {codeExpiry}=?, {bearerHash}=NULL, {failsCount}=0, {failedTime}=NULL"
			." WHERE {systemName}=? AND {userStatus}<>'disabled';";
		if( $this->sendWriteCount($updtQuer, Array(self::VOID_HASH, $this->listCodeHashes($newCodig)[0], $this->getCodeEndDate($codMinuteNum), $namedUserTxt))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'User '.json_encode($namedUserTxt).' reset'.Logger::getActorSuffix() );
		return $newCodig;
	}

	/**
	 * Takes the personal API token of a user away: the token it holds
	 * is no longer accepted, until it is given a new one (see makeToken).
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Bool Whether the user has no token any more.
	 */
	public function clearUserToken( String $namedUserTxt ): Bool {
		$this->dropOldRefusal();
		if( $this->readUserOrDeny($namedUserTxt)===null ){
			return false;
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {bearerHash}=NULL WHERE {systemName}=?;', Array($namedUserTxt))<0 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'Personal token of user '.json_encode($namedUserTxt).' removed'.Logger::getActorSuffix() );
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
		$this->dropOldRefusal();
		if( $this->readUserOrDeny($namedUserTxt)===null ){
			return false;
		}
		if( $this->sendWriteCount('UPDATE {usrTable} SET {twoFactKey}=NULL, {twoFactPos}=NULL, {twoFactWay}=NULL, {twoFactHsh}=NULL, {twoFactExp}=NULL WHERE {systemName}=?;', Array($namedUserTxt))<0 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( '2FA secret of user '.json_encode($namedUserTxt).' removed'.Logger::getActorSuffix() );
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
	public function setEmailOtpWay( String $namedUserTxt ): Bool {
		$this->dropOldRefusal();
		$userVals = $this->readUserOrDeny($namedUserTxt);
		if( $userVals===null ){
			return false;
		}
		if( ($userVals['userStatus'] ?? '')!=='approved' ){
			return $this->denyWithReason(409, 'User not active');
		}
		if( strval($userVals['contactEml'] ?? '')=='' ){
			return $this->denyWithReason(409, 'User without a mail address');
		}
		if( \nyu\Access\AccessMail::makeByLoginCfg()===null ){
			return $this->denyWithReason(409, 'No mail account sends the codes: mailer is not set');
		}
		$updtQuer = "UPDATE {usrTable} SET {twoFactWay}='emailOtp', {twoFactKey}=NULL, {twoFactPos}=NULL, {twoFactHsh}=NULL, {twoFactExp}=NULL WHERE {systemName}=? AND {userStatus}='approved';";
		if( $this->sendWriteCount($updtQuer, Array($namedUserTxt))<1 ){
			return $this->denyWithReason(500, 'User cannot be written');
		}
		Logger::getLoggerOfChn('access')->notice( 'Mailed codes set for user '.json_encode($namedUserTxt).Logger::getActorSuffix() );
		return true;
	}

	/**
	 * Lists the users, by name, with what SHOWLIST tells of each.
	 *
	 * @param Array $criteriaList Optional 'userStatus' and 'accessRole' values the users must have, and a 'contactEml' address, compared in lower case; an empty one is left out.
	 * @param Int   $ceilingCount Most users to give; the default number of rows (DEF_ROWS, 100 when not set) when below 1.
	 * @param Int   $skippedCount Users to pass over first.
	 * @return Array|Bool The users, or false when a filter is not valid or the table cannot be read.
	 */
	public function listUsersNamed( Array $criteriaList=Array(), Int $ceilingCount=0, Int $skippedCount=0 ): Array|Bool {
		$this->dropOldRefusal();
		$whereLst = Array();
		$fltrList = Array();
		$givenEml = $criteriaList['contactEml'] ?? '';
		if( $givenEml!=='' && $givenEml!==null ){
			if( !is_string($givenEml) ){
				return $this->denyWithReason(400, 'Invalid filter');
			}
			$whereLst[] = '{contactEml}=?';
			$fltrList[] = strtolower(trim($givenEml));
		}
		foreach( Array('userStatus'=>self::STAT_LIST, 'accessRole'=>Checker::ROLE_LIST) as $oneNme=>$oneLst ){
			$givenTxt = $criteriaList[$oneNme] ?? '';
			if( $givenTxt==='' || $givenTxt===null ){
				continue;
			}
			if( !in_array($givenTxt, $oneLst, true) ){
				return $this->denyWithReason(400, 'Invalid filter');
			}
			$whereLst[] = '{'.$oneNme.'}=?';
			$fltrList[] = $givenTxt;
		}
		$rowsLimt = $ceilingCount;
		if( $rowsLimt<1 ){
			$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
			$iniConfg = $nyuConfg ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
			$rowsLimt = intval($iniConfg['DEF_ROWS'] ?? 100) ?: 100;
		}
		$listQuer = 'SELECT {'.implode('}, {', self::SHOW_LIST).'} FROM {usrTable}'
			.( !empty($whereLst) ? ' WHERE '.implode(' AND ', $whereLst) : '' )
			.' ORDER BY {systemName} LIMIT '.$rowsLimt.' OFFSET '.max(0, $skippedCount).';';
		$userNtry = $this->sendFetchArray($listQuer, $fltrList);
		return ($userNtry===false) ? $this->denyWithReason(500, 'Credential store unavailable') : $userNtry;
	}

	/**
	 * Reads one user, with what SHOWLIST tells of it.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return Array|Bool The user, or false when it is unknown or the table cannot be read.
	 */
	public function readUserToShow( String $namedUserTxt ): Array|Bool {
		$this->dropOldRefusal();
		$userVals = $this->readUserOrDeny($namedUserTxt);
		if( $userVals===null ){
			return false;
		}
		$shwnList = Array();
		foreach( self::SHOW_LIST as $oneNme ){
			$shwnList[$oneNme] = $userVals[$oneNme] ?? null;
		}
		return $shwnList;
	}

	/**
	 * Gives the status the last refusal stands for, as an HTTP status:
	 * 400 for a value that is not valid, 401 for a proof that is wrong,
	 * 404 for an unknown user, 409 for a change the state of the user
	 * does not allow, 500 when the table cannot be used.
	 *
	 * @return Int The status, 200 when the last operation was not refused.
	 */
	public function getRefusalCode(): Int {
		return $this->latestCode;
	}

	/**
	 * Forgets the refusal of the previous operation.
	 */
	protected function dropOldRefusal(): Void {
		$this->latestCode = 200;
		$this->errMessage = '';
		$this->isWrongTry = false;
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

	/**
	 * Reads the row of the user an operation is about, recording the
	 * refusal when there is none.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return ?Array The user's row, or null when it is unknown or the table cannot be read.
	 */
	protected function readUserOrDeny( String $namedUserTxt ): ?Array {
		$userVals = ($namedUserTxt!='') ? $this->readUserRecord($namedUserTxt) : null;
		if( $userVals===false ){
			$this->denyWithReason(500, 'Credential store unavailable');
			return null;
		}
		if( $userVals===null ){
			$this->denyWithReason(404, 'User not found');
		}
		return $userVals;
	}

	/**
	 * Checks a mail address and gives it as it is stored: in lower case.
	 *
	 * @param String $emailAddress The address given.
	 * @return ?String The address to store, or null when it is not one.
	 */
	protected function checkEmailAddr( String $emailAddress ): ?String {
		$mailVals = strtolower( trim($emailAddress) );
		if( strlen($mailVals)>self::MAIL_SIZE || filter_var($mailVals, FILTER_VALIDATE_EMAIL)===false ){
			return null;
		}
		return $mailVals;
	}

	/**
	 * Checks a language and gives it as it is stored: a tag in lower case,
	 * '_' read as '-' (see Tools\Language).
	 *
	 * @param ?String $givenLangTag The language given.
	 * @return String|Bool|null The language to store, null when none is given, or false when it is not a tag.
	 */
	protected function checkLangAsTag( ?String $givenLangTag ): String|Bool|null {
		$langVals = strtolower( trim(str_replace('_', '-', strval($givenLangTag))) );
		if( $langVals==='' ){
			return null;
		}
		return Language::isValidLangTag($langVals) ? $langVals : false;
	}

	/**
	 * Tells whether another user holds an address.
	 *
	 * @param String $emailAddress The address, as stored.
	 * @param String $namedUserTxt The user left out of the search, '' for none.
	 * @return ?Bool Whether another user holds it, or null when the table cannot be read.
	 */
	protected function isEmailClaimed( String $emailAddress, String $namedUserTxt ): ?Bool {
		$userNtry = $this->sendFetchArray( 'SELECT {systemName} FROM {usrTable} WHERE {contactEml}=? AND {systemName}<>? LIMIT 1;', Array($emailAddress, $namedUserTxt) );
		return ($userNtry===false) ? null : !empty($userNtry);
	}

	/**
	 * Gives the end of a code made now.
	 *
	 * @param Int $codMinuteNum Minutes the code lasts, 0 for no end.
	 * @return ?String The time it ends, as stored, or null for no end.
	 */
	protected function getCodeEndDate( Int $codMinuteNum ): ?String {
		return ($codMinuteNum>0) ? date('Y-m-d H:i:s', time() + 60*$codMinuteNum) : null;
	}

	/**
	 * Removes the sign-ups whose address was not confirmed before the end
	 * of their code: their name and address are free again.
	 */
	protected function dropOldSignups(): Void {
		$dfltNumb = $this->sendWriteCount( "DELETE FROM {usrTable} WHERE {signupDate} IS NOT NULL AND {userStatus}='awaiting' AND {codeExpiry} IS NOT NULL AND {codeExpiry}<?;", Array(date('Y-m-d H:i:s')) );
		if( $dfltNumb>0 ){
			Logger::getLoggerOfChn('access')->info( $dfltNumb.' sign-ups not confirmed in time removed' );
		}
	}

	/**
	 * Tells whether a user is the last one who can manage the others: an
	 * active user of the highest role, with no other one beside it. A
	 * count that cannot be read is answered as if it were.
	 *
	 * @param Array $userValsList The user's row.
	 * @return Bool Whether locking, demoting, removing or resetting it would leave no manager.
	 */
	protected function isFinalManager( Array $userValsList ): Bool {
		$highRole = array_slice(Checker::ROLE_LIST, -1)[0];
		if( ($userValsList['accessRole'] ?? '')!==$highRole || ($userValsList['userStatus'] ?? '')!=='approved' ){
			return false;
		}
		$othrRows = $this->sendFetchArray( "SELECT {systemName} FROM {usrTable} WHERE {accessRole}=? AND {userStatus}='approved' AND {systemName}<>? LIMIT 1;", Array($highRole, strval($userValsList['systemName'] ?? '')) );
		return empty($othrRows);
	}

	/**
	 * Writes a query's {name} parts as the database in use expects them:
	 * {usrTable} is the users table, the others its fields (see quoteFlds).
	 *
	 * @param DbHandlr $dbHandlerObj The database handler.
	 * @param String   $queryPattern The query, its table and fields written {name}.
	 * @return String The query, ready to be sent.
	 */
	protected function castQueryNames( DbHandlr $dbHandlerObj, String $queryPattern ): String {
		$swapList = Array();
		foreach( $this->quoteFieldsDct($dbHandlerObj) as $oneNme=>$oneQtd ){
			$swapList['{'.$oneNme.'}'] = $oneQtd;
		}
		return strtr($queryPattern, $swapList);
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
			$dtbsHndr = $this->openUserBaseDb();
			return $dtbsHndr->sendBuiltQuery($this->castQueryNames($dtbsHndr, $queryPattern), true, $valuesToBind) ? $dtbsHndr->countLastWrite() : -1;
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
			$dtbsHndr = $this->openUserBaseDb();
			if( !$dtbsHndr->sendBuiltQuery($this->castQueryNames($dtbsHndr, $queryPattern), false, $valuesToBind) ){
				return false;
			}
			$userNtry = $dtbsHndr->readLastResult(true);
		}catch( \Throwable $e ){
			return false;
		}
		return is_array($userNtry) ? $userNtry : false;
	}
}
