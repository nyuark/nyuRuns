<?php

namespace nyu\Access\Auth;

use nyu\Access\Checker;
use nyu\Data\DBHndlr\Handler as DbHandlr;
use nyu\Tools\Logger;

/**
 * Manages the users of the native userbase: creation, activation with
 * a code, self-registration and its review, passwords and their reset,
 * addresses, locks, roles, removal and reading. It works on the table
 * Native checks credentials against, and is not a credential check of
 * its own. It decides nothing about who may call it, and sends no mail:
 * that is the caller's. Each change is logged on the 'access' channel.
 * After a refusal, lastError() tells why and lastState() gives the
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
	const NAMERULE = '/^[A-Za-z][A-Za-z0-9._\-]{2,31}$/D';
	// Names no user is given, whatever the case
	const NAMEDENY = Array('root', 'admin');
	// What is told about a user : never a hash, a code or a secret
	const SHOWLIST = Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'signDate', 'madeDate', 'lastSeen');
	// Stored in place of a hash until the user sets its password : no password matches it
	const VOIDHASH = '!';
	// The characters of a code : no 0, 1, I, L or O, which are taken for one another
	const CODECHRS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	// Minutes a code handed over by an administrator lasts
	const ADMNMINS = 300;
	// Minutes a code sent by mail lasts, unless 'codexp' says otherwise
	const MAILMINS = 15;
	// Longest mail address
	const MAILSIZE = 254;
	// Most self-registrations waiting at once, confirmed or not, unless the 'regtop' of the limits says otherwise : beyond, sign-ups are closed for a while
	const SIGNSCAP = 256;

	protected $lastCode = 200;
	protected $mailMins;

	/**
	 * Builds the manager from the config block of the native userbase.
	 *
	 * @param Array $confValues The block (see Native), and the minutes a code sent by mail lasts ('codexp').
	 */
	function __construct( Array $confValues ){
		parent::__construct($confValues);
		$this->mailMins = max( 1, intval($confValues['codexp'] ?? self::MAILMINS) );
	}

	/**
	 * Gives the minutes a code sent by mail lasts: those of 'codexp'.
	 *
	 * @return Int The minutes.
	 */
	public function mailedFor(): Int {
		return $this->mailMins;
	}

	/**
	 * Builds the manager of the userbase the login uses: the 'login'
	 * block of the ACCESS config must name the native credential check
	 * ('authby'), and the block of its settings ('authcf', 'native' when
	 * left out).
	 *
	 * @return ?UserBase The manager, or null when the login does not use the native userbase.
	 */
	public static function fromLogin(): ?UserBase {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lgnConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'LOGIN') : null;
		if( !is_array($lgnConf) || strval($lgnConf['authby'] ?? '')!=='native' ){
			return null;
		}
		$blkConf = $nyuConf->getRowVal('ACCESS', strtoupper(strval($lgnConf['authcf'] ?? 'native')));
		return new self( is_array($blkConf) ? $blkConf : Array() );
	}

	/**
	 * Creates a user, pending until it is activated: it has no password
	 * yet, and an activation code to set one with (see actvtUser). Only
	 * the SHA-256 hash of the code is stored: the code is returned once.
	 * A name that only differs from an existing one by its case is refused,
	 * and so is an address another user holds.
	 *
	 * @param String  $userString Login name of the new user (see NAMERULE and NAMEDENY).
	 * @param String  $roleString Its role, one of Checker::ROLELIST; the first one when left empty.
	 * @param ?String $mailString Its mail address, none when null or empty.
	 * @param Int     $codeMinute Minutes the code lasts, 0 for no end: ADMNMINS for a code handed over, mailedFor() for one sent by mail.
	 * @return String|Bool The activation code, or false when the name, the role or the address is not valid or taken, or the table cannot be written.
	 */
	public function enrolUser( String $userString, String $roleString='', ?String $mailString=null, Int $codeMinute=self::ADMNMINS ): String|Bool {
		$this->resetLast();
		$roleStr = ($roleString!='') ? $roleString : Checker::ROLELIST[0];
		if( !preg_match(self::NAMERULE, $userString) || in_array(strtolower($userString), self::NAMEDENY, true) ){
			return $this->refuseFor(400, 'Invalid user name');
		}
		if( !in_array($roleStr, Checker::ROLELIST, true) ){
			return $this->refuseFor(400, 'Invalid role');
		}
		$mailVal = null;
		if( $mailString!==null && $mailString!=='' ){
			$mailVal = $this->checkMail($mailString);
			if( $mailVal===null ){
				return $this->refuseFor(400, 'Invalid mail address');
			}
		}
		$dupRows = $this->sendFetch( 'SELECT {userName} FROM {usrTable} WHERE LOWER({userName})=LOWER(?) LIMIT 1;', Array($userString) );
		if( $dupRows===false ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		if( !empty($dupRows) ){
			return $this->refuseFor(409, 'User name already taken');
		}
		if( $mailVal!==null ){
			$mailUse = $this->mailInUse($mailVal, '');
			if( $mailUse===null ){
				return $this->refuseFor(500, 'Credential store unavailable');
			}
			if( $mailUse ){
				return $this->refuseFor(409, 'Mail address already used');
			}
		}
		$newCode = $this->freshCode();
		$addQury = "INSERT INTO {usrTable} ({userName}, {passHash}, {userRole}, {userStat}, {userMail}, {actvCode}, {codeDate}) VALUES (?, ?, ?, 'pendng', ?, ?, ?);";
		if( $this->sendWrite($addQury, Array($userString, self::VOIDHASH, $roleStr, $mailVal, $this->codeHashs($newCode)[0], $this->endOfCode($codeMinute)))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'User '.json_encode($userString).' created ('.$roleStr.')'.Logger::actorText() );
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
	 * @param String $userString Login name it asks for (see NAMERULE and NAMEDENY).
	 * @param String $mailString Its mail address.
	 * @param Int    $maxWaiting Most sign-ups waiting at once, confirmed or not ('regtop' of the limits).
	 * @return String|Bool The activation code, '' when the name or the address is taken, or false when one is not valid, too many sign-ups are waiting, or the table cannot be used.
	 */
	public function signsUser( String $userString, String $mailString, Int $maxWaiting=self::SIGNSCAP ): String|Bool {
		$this->resetLast();
		if( !preg_match(self::NAMERULE, $userString) || in_array(strtolower($userString), self::NAMEDENY, true) ){
			return $this->refuseFor(400, 'Invalid user name');
		}
		$mailVal = $this->checkMail($mailString);
		if( $mailVal===null ){
			return $this->refuseFor(400, 'Invalid mail address');
		}
		$this->purgeSign();
		$logInst = Logger::getLogger('access');
		$waitNum = $this->waitCount();
		if( $waitNum===false ){
			return false;
		}
		if( $waitNum>=$maxWaiting ){
			$logInst->warning( 'Sign-up refused: '.$waitNum.' sign-ups are already waiting'.Logger::actorText() );
			return $this->refuseFor(503, 'Sign-ups closed for now');
		}
		$dupRows = $this->sendFetch( 'SELECT {userName} FROM {usrTable} WHERE LOWER({userName})=LOWER(?) OR {userMail}=? LIMIT 1;', Array($userString, $mailVal) );
		if( $dupRows===false ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		if( !empty($dupRows) ){
			$logInst->info( 'Sign-up as '.json_encode($userString).': the name or the address is taken'.Logger::actorText() );
			return '';
		}
		$newCode = $this->freshCode();
		$addQury = "INSERT INTO {usrTable} ({userName}, {passHash}, {userRole}, {userStat}, {userMail}, {actvCode}, {codeDate}, {signDate}) VALUES (?, ?, ?, 'pendng', ?, ?, ?, ?);";
		if( $this->sendWrite($addQury, Array($userString, self::VOIDHASH, Checker::ROLELIST[0], $mailVal, $this->codeHashs($newCode)[0], $this->endOfCode($this->mailMins), date('Y-m-d H:i:s')))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		$logInst->notice( 'User '.json_encode($userString).' signed up'.Logger::actorText() );
		return $newCode;
	}

	/**
	 * Counts the sign-ups waiting: not yet confirmed, or in review.
	 *
	 * @return Int|Bool The number, or false when the table cannot be read.
	 */
	public function waitCount(): Int|Bool {
		$cntRows = $this->sendFetch( "SELECT COUNT(*) AS waiting FROM {usrTable} WHERE {signDate} IS NOT NULL AND {userStat} IN ('pendng', 'review');" );
		if( $cntRows===false ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		return intval( array_values($cntRows[0] ?? Array(0))[0] );
	}

	/**
	 * Lists the addresses of the active users of the highest role: those
	 * told when sign-ups pile up.
	 *
	 * @return Array|Bool The addresses, or false when the table cannot be read.
	 */
	public function managMail(): Array|Bool {
		$mngRows = $this->sendFetch( "SELECT {userMail} FROM {usrTable} WHERE {userRole}=? AND {userStat}='active' AND {userMail} IS NOT NULL AND {userMail}<>'' ORDER BY {userName};", Array(array_slice(Checker::ROLELIST, -1)[0]) );
		if( $mngRows===false ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		return array_map(function( Array $userValues ): String { return strval(array_values($userValues)[0]); }, $mngRows);
	}

	/**
	 * Activates a pending user with its activation code, and gives it
	 * its password. The code is used once, before its end. A user who
	 * signed up itself then awaits a manager ('review'), any other is
	 * active. Whatever is wrong with the name, the status or the code, the
	 * refusal is the same.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $codeString The activation code, as given at its creation or its reset.
	 * @param String $passwdText The password it chooses, following the rules (see checkPass).
	 * @return Bool Whether the user is now active, or in review.
	 */
	public function actvtUser( String $userString, String $codeString, String $passwdText ): Bool {
		$this->resetLast();
		$ruleMsg = $this->checkPass($userString, $passwdText);
		if( $ruleMsg!='' ){
			return $this->refuseFor(400, $ruleMsg);
		}
		// One write decides : a code cannot be used twice, even by two requests at once
		$updQury = "UPDATE {usrTable} SET {passHash}=?, {userStat}=CASE WHEN {signDate} IS NULL THEN 'active' ELSE 'review' END,"
			." {actvCode}=NULL, {codeDate}=NULL, {failNmbr}=0, {failDate}=NULL"
			." WHERE {userName}=? AND {userStat}='pendng' AND {actvCode} IN (?, ?) AND ({codeDate} IS NULL OR {codeDate}>=?);";
		$rowNmbr = $this->sendWrite( $updQury, array_merge(Array(password_hash($passwdText, PASSWORD_BCRYPT), $userString), $this->codeHashs($codeString), Array(date('Y-m-d H:i:s'))) );
		if( $rowNmbr<0 ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		if( $rowNmbr<1 ){
			Logger::getLogger('access')->warning( 'Activation refused for user '.json_encode($userString).Logger::actorText() );
			$this->wrongCrd = true;
			return $this->refuseFor(401, 'Invalid activation');
		}
		$usrVals = $this->fetchUser($userString);
		if( is_array($usrVals) && ($usrVals['userStat'] ?? '')=='review' ){
			Logger::getLogger('access')->notice( 'User '.json_encode($userString).' confirmed its address, awaits review'.Logger::actorText() );
		}else{
			Logger::getLogger('access')->info( 'User '.json_encode($userString).' activated'.Logger::actorText() );
		}
		return true;
	}

	/**
	 * Approves a user who signed up itself and confirmed its address: it
	 * becomes active. Refusing it is removing it (see removUser).
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the user is now active.
	 */
	public function proveUser( String $userString ): Bool {
		$this->resetLast();
		$usrVals = $this->knownUser($userString);
		if( $usrVals===null ){
			return false;
		}
		if( ($usrVals['userStat'] ?? '')!=='review' ){
			return $this->refuseFor(409, 'User not awaiting review');
		}
		if( $this->sendWrite("UPDATE {usrTable} SET {userStat}='active', {signDate}=NULL WHERE {userName}=? AND {userStat}='review';", Array($userString))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'User '.json_encode($userString).' approved'.Logger::actorText() );
		return true;
	}

	/**
	 * Gives an active user who lost its password a reset code, to be sent
	 * to its address: its password still works until the reset is done
	 * (see recovUser), and a later request replaces the code. A user that
	 * is unknown, not active or without an address gets none.
	 *
	 * @param String $nameOrMail Its login name, or its mail address.
	 * @return Array|Bool The user ('user'), its address ('mail') and the code ('code'), or false when there is none to give.
	 */
	public function resetPass( String $nameOrMail ): Array|Bool {
		$this->resetLast();
		$lookups = trim($nameOrMail);
		if( $lookups=='' ){
			return $this->refuseFor(404, 'User not found');
		}
		$isAMail = str_contains($lookups, '@');
		$usrRows = $this->sendFetch( 'SELECT {userName}, {userMail}, {userStat} FROM {usrTable} WHERE '.($isAMail ? '{userMail}' : '{userName}').'=? LIMIT 1;', Array($isAMail ? strtolower($lookups) : $lookups) );
		if( $usrRows===false ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		$logInst = Logger::getLogger('access');
		if( empty($usrRows) ){
			$logInst->info( 'Reset asked for an unknown user'.Logger::actorText() );
			return $this->refuseFor(404, 'User not found');
		}
		$usrName = strval($usrRows[0]['userName']);
		if( ($usrRows[0]['userStat'] ?? '')!=='active' || strval($usrRows[0]['userMail'] ?? '')==='' ){
			$logInst->warning( 'Reset refused for user '.json_encode($usrName).': not active, or without an address'.Logger::actorText() );
			return $this->refuseFor(409, 'No reset for this user');
		}
		$newCode = $this->freshCode();
		if( $this->sendWrite("UPDATE {usrTable} SET {resetCod}=?, {codeDate}=? WHERE {userName}=? AND {userStat}='active';", Array($this->codeHashs($newCode)[0], $this->endOfCode($this->mailMins), $usrName))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		$logInst->notice( 'Reset asked for user '.json_encode($usrName).Logger::actorText() );
		return Array('user'=>$usrName, 'mail'=>strval($usrRows[0]['userMail']), 'code'=>$newCode);
	}

	/**
	 * Gives an active user a new password with the reset code it asked
	 * for (see resetPass). The code is used once, before its end. Its
	 * failed attempts are forgotten; its role, token and 2FA secret are
	 * kept. Whatever is wrong with the name or the code, the refusal is
	 * the same.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $codeString The reset code.
	 * @param String $passwdText The new password, following the rules (see checkPass).
	 * @return Bool Whether the password was changed.
	 */
	public function recovUser( String $userString, String $codeString, String $passwdText ): Bool {
		$this->resetLast();
		$ruleMsg = $this->checkPass($userString, $passwdText);
		if( $ruleMsg!='' ){
			return $this->refuseFor(400, $ruleMsg);
		}
		$updQury = "UPDATE {usrTable} SET {passHash}=?, {resetCod}=NULL, {codeDate}=NULL, {failNmbr}=0, {failDate}=NULL"
			." WHERE {userName}=? AND {userStat}='active' AND {resetCod} IN (?, ?) AND {codeDate}>=?;";
		$rowNmbr = $this->sendWrite( $updQury, array_merge(Array(password_hash($passwdText, PASSWORD_BCRYPT), $userString), $this->codeHashs($codeString), Array(date('Y-m-d H:i:s'))) );
		if( $rowNmbr<0 ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		if( $rowNmbr<1 ){
			Logger::getLogger('access')->warning( 'Password reset refused for user '.json_encode($userString).Logger::actorText() );
			$this->wrongCrd = true;
			return $this->refuseFor(401, 'Invalid code');
		}
		Logger::getLogger('access')->info( 'Password of user '.json_encode($userString).' reset with its code'.Logger::actorText() );
		return true;
	}

	/**
	 * Gives a user a mail address, or takes it away. An address another
	 * user holds is refused. A reset code asked for is forgotten: it was
	 * sent to the former address.
	 *
	 * @param String  $userString Login name of the user.
	 * @param ?String $mailString The address, none when null or empty.
	 * @return Bool Whether the address was written.
	 */
	public function chngeMail( String $userString, ?String $mailString ): Bool {
		$this->resetLast();
		$mailVal = null;
		if( $mailString!==null && $mailString!=='' ){
			$mailVal = $this->checkMail($mailString);
			if( $mailVal===null ){
				return $this->refuseFor(400, 'Invalid mail address');
			}
		}
		if( $this->knownUser($userString)===null ){
			return false;
		}
		if( $mailVal!==null ){
			$mailUse = $this->mailInUse($mailVal, $userString);
			if( $mailUse===null ){
				return $this->refuseFor(500, 'Credential store unavailable');
			}
			if( $mailUse ){
				return $this->refuseFor(409, 'Mail address already used');
			}
		}
		$updQury = 'UPDATE {usrTable} SET {userMail}=?, {resetCod}=NULL, {codeDate}=CASE WHEN {actvCode} IS NULL THEN NULL ELSE {codeDate} END WHERE {userName}=?;';
		if( $this->sendWrite($updQury, Array($mailVal, $userString))<0 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'Address of user '.json_encode($userString).' '.($mailVal===null ? 'removed' : 'set').Logger::actorText() );
		return true;
	}

	/**
	 * Changes the password of an active user, who proves it is the one
	 * asking by giving the current one. A wrong current password counts
	 * as a failed attempt, and a held user is refused as login refuses it.
	 * The 2FA secret and the personal API token are left as they are.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $formerPass Its current password.
	 * @param String $passwdText The new one, following the rules (see checkPass) and different from the current one.
	 * @return Bool Whether the password was changed.
	 */
	public function chgPasswd( String $userString, String $formerPass, String $passwdText ): Bool {
		$this->resetLast();
		$usrVals = ($userString!='') ? $this->fetchUser($userString) : null;
		if( $usrVals===false ){
			return $this->refuseFor(500, 'Credential store unavailable');
		}
		if( $usrVals===null || ($usrVals['userStat'] ?? '')!=='active' ){
			return $this->refuseFor(401, 'Account not active');
		}
		$logInst = Logger::getLogger('access');
		$matched = password_verify( $formerPass, strval($usrVals['passHash'] ?? '') );
		if( $this->isHeldNow($usrVals) ){
			$logInst->warning( 'Password change refused: user '.json_encode($userString).' is held after too many attempts'.Logger::actorText() );
			$this->wrongCrd = true;
			return $this->refuseFor(401, 'Invalid credentials');
		}
		if( !$matched ){
			$this->countFail($userString);
			$logInst->warning( 'Password change refused for user '.json_encode($userString).': wrong current password'.Logger::actorText() );
			$this->wrongCrd = true;
			return $this->refuseFor(401, 'Invalid credentials');
		}
		if( $formerPass===$passwdText ){
			return $this->refuseFor(400, 'Password identical to the current one');
		}
		if( !$this->setPasswd($userString, $passwdText) ){
			// setPasswd tells the rule that is broken; without one, the table could not be written
			return ($this->lastMssg!='') ? $this->refuseFor(400, $this->lastMssg) : $this->refuseFor(500, 'Credential store unavailable');
		}
		$logInst->info( 'Password changed for user '.json_encode($userString).Logger::actorText() );
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
	 * @param String $userString Login name of the user.
	 * @param String $statString The status to give: 'locked' or 'active'.
	 * @return Bool Whether the status was changed.
	 */
	public function setStatus( String $userString, String $statString ): Bool {
		$this->resetLast();
		if( !in_array($statString, Array('active', 'locked'), true) ){
			return $this->refuseFor(400, 'Invalid status');
		}
		$usrVals = $this->knownUser($userString);
		if( $usrVals===null ){
			return false;
		}
		$nowStat = strval($usrVals['userStat'] ?? '');
		if( $nowStat=='pendng' ){
			return $this->refuseFor(409, 'A pending user is neither locked nor unlocked');
		}
		if( $nowStat=='review' ){
			return $this->refuseFor(409, 'A user in review is approved or removed');
		}
		if( $nowStat==$statString ){
			return $this->refuseFor(409, 'User already '.$statString);
		}
		if( $statString=='locked' && $this->lastMangr($usrVals) ){
			return $this->refuseFor(409, 'The last active manager cannot be locked');
		}
		$updQury = 'UPDATE {usrTable} SET {userStat}=?, {failNmbr}=0, {failDate}=NULL WHERE {userName}=? AND {userStat}=?;';
		if( $this->sendWrite($updQury, Array($statString, $userString, $nowStat))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'User '.json_encode($userString).' '.($statString=='locked' ? 'locked' : 'unlocked').Logger::actorText() );
		return true;
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
		$usrVals = $this->knownUser($userString);
		if( $usrVals===null ){
			return false;
		}
		$nowRole = strval($usrVals['userRole'] ?? '');
		if( $nowRole==$roleString ){
			return $this->refuseFor(409, 'User already of that role');
		}
		if( $this->lastMangr($usrVals) ){
			return $this->refuseFor(409, 'The last active manager cannot be given another role');
		}
		if( $this->sendWrite('UPDATE {usrTable} SET {userRole}=? WHERE {userName}=?;', Array($roleString, $userString))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'Role of user '.json_encode($userString).' changed from '.$nowRole.' to '.$roleString.Logger::actorText() );
		return true;
	}

	/**
	 * Removes a user from the table. The last active user of the highest
	 * role cannot be removed.
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the user was removed.
	 */
	public function removUser( String $userString ): Bool {
		$this->resetLast();
		$usrVals = $this->knownUser($userString);
		if( $usrVals===null ){
			return false;
		}
		if( $this->lastMangr($usrVals) ){
			return $this->refuseFor(409, 'The last active manager cannot be removed');
		}
		if( $this->sendWrite('DELETE FROM {usrTable} WHERE {userName}=?;', Array($userString))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'User '.json_encode($userString).' removed ('.strval($usrVals['userRole'] ?? '').')'.Logger::actorText() );
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
	 * @param String $userString Login name of the user.
	 * @param Int    $codeMinute Minutes the code lasts, 0 for no end: ADMNMINS for a code handed over, mailedFor() for one sent by mail.
	 * @return String|Bool The activation code, returned once, or false when the user is unknown, cannot be reset, or the table cannot be written.
	 */
	public function resetUser( String $userString, Int $codeMinute=self::ADMNMINS ): String|Bool {
		$this->resetLast();
		$usrVals = $this->knownUser($userString);
		if( $usrVals===null ){
			return false;
		}
		if( ($usrVals['userStat'] ?? '')=='locked' ){
			return $this->refuseFor(409, 'A locked user is unlocked before it is reset');
		}
		if( $this->lastMangr($usrVals) ){
			return $this->refuseFor(409, 'The last active manager cannot be reset');
		}
		$newCode = $this->freshCode();
		$updQury = "UPDATE {usrTable} SET {passHash}=?, {userStat}='pendng', {actvCode}=?, {resetCod}=NULL, {codeDate}=?, {tokenKey}=NULL, {failNmbr}=0, {failDate}=NULL"
			." WHERE {userName}=? AND {userStat}<>'locked';";
		if( $this->sendWrite($updQury, Array(self::VOIDHASH, $this->codeHashs($newCode)[0], $this->endOfCode($codeMinute), $userString))<1 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'User '.json_encode($userString).' reset'.Logger::actorText() );
		return $newCode;
	}

	/**
	 * Takes the personal API token of a user away: the token it holds
	 * is no longer accepted, until it is given a new one (see makeToken).
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the user has no token any more.
	 */
	public function dropToken( String $userString ): Bool {
		$this->resetLast();
		if( $this->knownUser($userString)===null ){
			return false;
		}
		if( $this->sendWrite('UPDATE {usrTable} SET {tokenKey}=NULL WHERE {userName}=?;', Array($userString))<0 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( 'Personal token of user '.json_encode($userString).' removed'.Logger::actorText() );
		return true;
	}

	/**
	 * Takes the 2FA secret of a user away, for one whose authenticator is
	 * lost: its password alone logs it in again, until it is given a new
	 * secret (see makeTwoFa).
	 *
	 * @param String $userString Login name of the user.
	 * @return Bool Whether the user has no 2FA secret any more.
	 */
	public function dropTwoFa( String $userString ): Bool {
		$this->resetLast();
		if( $this->knownUser($userString)===null ){
			return false;
		}
		if( $this->sendWrite('UPDATE {usrTable} SET {twoFaKey}=NULL, {twoFaStp}=NULL WHERE {userName}=?;', Array($userString))<0 ){
			return $this->refuseFor(500, 'User cannot be written');
		}
		Logger::getLogger('access')->notice( '2FA secret of user '.json_encode($userString).' removed'.Logger::actorText() );
		return true;
	}

	/**
	 * Lists the users, by name, with what SHOWLIST tells of each.
	 *
	 * @param Array $filterList Optional 'userStat' and 'userRole' values the users must have; an empty one is left out.
	 * @param Int   $limitCount Most users to give; the default number of rows (LST_ROWS, 100 when not set) when below 1.
	 * @param Int   $offsetRows Users to pass over first.
	 * @return Array|Bool The users, or false when a filter is not valid or the table cannot be read.
	 */
	public function listUsers( Array $filterList=Array(), Int $limitCount=0, Int $offsetRows=0 ): Array|Bool {
		$this->resetLast();
		$whrList = Array();
		$whrVals = Array();
		foreach( Array('userStat'=>self::STATLIST, 'userRole'=>Checker::ROLELIST) as $fldNme=>$alwLst ){
			$gvnText = $filterList[$fldNme] ?? '';
			if( $gvnText==='' || $gvnText===null ){
				continue;
			}
			if( !in_array($gvnText, $alwLst, true) ){
				return $this->refuseFor(400, 'Invalid filter');
			}
			$whrList[] = '{'.$fldNme.'}=?';
			$whrVals[] = $gvnText;
		}
		$rowsLim = $limitCount;
		if( $rowsLim<1 ){
			$nyuConf = $GLOBALS['nyuConfig'] ?? null;
			$sysConf = $nyuConf ? ($nyuConf->readValue('system') ?: Array()) : Array();
			$rowsLim = intval($sysConf['LST_ROWS'] ?? 100) ?: 100;
		}
		$lstQury = 'SELECT {'.implode('}, {', self::SHOWLIST).'} FROM {usrTable}'
			.( !empty($whrList) ? ' WHERE '.implode(' AND ', $whrList) : '' )
			.' ORDER BY {userName} LIMIT '.$rowsLim.' OFFSET '.max(0, $offsetRows).';';
		$usrRows = $this->sendFetch($lstQury, $whrVals);
		return ($usrRows===false) ? $this->refuseFor(500, 'Credential store unavailable') : $usrRows;
	}

	/**
	 * Reads one user, with what SHOWLIST tells of it.
	 *
	 * @param String $userString Login name of the user.
	 * @return Array|Bool The user, or false when it is unknown or the table cannot be read.
	 */
	public function userInfos( String $userString ): Array|Bool {
		$this->resetLast();
		$usrVals = $this->knownUser($userString);
		if( $usrVals===null ){
			return false;
		}
		$shownAr = Array();
		foreach( self::SHOWLIST as $fldNme ){
			$shownAr[$fldNme] = $usrVals[$fldNme] ?? null;
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
	public function lastState(): Int {
		return $this->lastCode;
	}

	/**
	 * Forgets the refusal of the previous operation.
	 */
	protected function resetLast(): Void {
		$this->lastCode = 200;
		$this->lastMssg = '';
		$this->wrongCrd = false;
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

	/**
	 * Reads the row of the user an operation is about, recording the
	 * refusal when there is none.
	 *
	 * @param String $userString Login name of the user.
	 * @return ?Array The user's row, or null when it is unknown or the table cannot be read.
	 */
	protected function knownUser( String $userString ): ?Array {
		$usrVals = ($userString!='') ? $this->fetchUser($userString) : null;
		if( $usrVals===false ){
			$this->refuseFor(500, 'Credential store unavailable');
			return null;
		}
		if( $usrVals===null ){
			$this->refuseFor(404, 'User not found');
		}
		return $usrVals;
	}

	/**
	 * Checks a mail address and gives it as it is stored: in lower case.
	 *
	 * @param String $mailString The address given.
	 * @return ?String The address to store, or null when it is not one.
	 */
	protected function checkMail( String $mailString ): ?String {
		$mailVal = strtolower( trim($mailString) );
		if( strlen($mailVal)>self::MAILSIZE || filter_var($mailVal, FILTER_VALIDATE_EMAIL)===false ){
			return null;
		}
		return $mailVal;
	}

	/**
	 * Tells whether another user holds an address.
	 *
	 * @param String $mailString The address, as stored.
	 * @param String $userString The user left out of the search, '' for none.
	 * @return ?Bool Whether another user holds it, or null when the table cannot be read.
	 */
	protected function mailInUse( String $mailString, String $userString ): ?Bool {
		$usrRows = $this->sendFetch( 'SELECT {userName} FROM {usrTable} WHERE {userMail}=? AND {userName}<>? LIMIT 1;', Array($mailString, $userString) );
		return ($usrRows===false) ? null : !empty($usrRows);
	}

	/**
	 * Makes a new code: 10 characters of CODECHRS, as XXXX-XXXX-XX.
	 *
	 * @return String The code.
	 */
	protected function freshCode(): String {
		$chrText = '';
		$lastChr = strlen(self::CODECHRS) - 1;
		for( $pos=0; $pos<10; $pos++ ){
			$chrText .= self::CODECHRS[ random_int(0, $lastChr) ];
		}
		return substr($chrText, 0, 4).'-'.substr($chrText, 4, 4).'-'.substr($chrText, 8);
	}

	/**
	 * Gives the hashes a code given is looked up by: the one of the code
	 * as it is stored (in upper case, without dashes or spaces), and the
	 * one of the code as it is given, for the codes of the earlier versions.
	 *
	 * @param String $codeString The code given.
	 * @return Array The two SHA-256 hashes, the stored form first.
	 */
	protected function codeHashs( String $codeString ): Array {
		$cmpCode = strtoupper( preg_replace('/[^A-Za-z0-9]/', '', $codeString) );
		return Array( hash('sha256', $cmpCode), hash('sha256', $codeString) );
	}

	/**
	 * Gives the end of a code made now.
	 *
	 * @param Int $codeMinute Minutes the code lasts, 0 for no end.
	 * @return ?String The time it ends, as stored, or null for no end.
	 */
	protected function endOfCode( Int $codeMinute ): ?String {
		return ($codeMinute>0) ? date('Y-m-d H:i:s', time() + 60*$codeMinute) : null;
	}

	/**
	 * Removes the sign-ups whose address was not confirmed before the end
	 * of their code: their name and address are free again.
	 */
	protected function purgeSign(): Void {
		$delNmbr = $this->sendWrite( "DELETE FROM {usrTable} WHERE {signDate} IS NOT NULL AND {userStat}='pendng' AND {codeDate} IS NOT NULL AND {codeDate}<?;", Array(date('Y-m-d H:i:s')) );
		if( $delNmbr>0 ){
			Logger::getLogger('access')->info( $delNmbr.' sign-ups not confirmed in time removed' );
		}
	}

	/**
	 * Tells whether a user is the last one who can manage the others: an
	 * active user of the highest role, with no other one beside it. A
	 * count that cannot be read is answered as if it were.
	 *
	 * @param Array $userValues The user's row.
	 * @return Bool Whether locking, demoting, removing or resetting it would leave no manager.
	 */
	protected function lastMangr( Array $userValues ): Bool {
		$topRole = array_slice(Checker::ROLELIST, -1)[0];
		if( ($userValues['userRole'] ?? '')!==$topRole || ($userValues['userStat'] ?? '')!=='active' ){
			return false;
		}
		$othRows = $this->sendFetch( "SELECT {userName} FROM {usrTable} WHERE {userRole}=? AND {userStat}='active' AND {userName}<>? LIMIT 1;", Array($topRole, strval($userValues['userName'] ?? '')) );
		return empty($othRows);
	}

	/**
	 * Writes a query's {name} parts as the database in use expects them:
	 * {usrTable} is the users table, the others its fields (see quoteFlds).
	 *
	 * @param DbHandlr $handlerObj The database handler.
	 * @param String   $queryShape The query, its table and fields written {name}.
	 * @return String The query, ready to be sent.
	 */
	protected function shapeQury( DbHandlr $handlerObj, String $queryShape ): String {
		$swapLst = Array();
		foreach( $this->quoteFlds($handlerObj) as $fldNme=>$qtdNme ){
			$swapLst['{'.$fldNme.'}'] = $qtdNme;
		}
		return strtr($queryShape, $swapLst);
	}

	/**
	 * Sends a query that changes the users table.
	 *
	 * @param String $queryShape The query, its table and fields written {name} (see shapeQury).
	 * @param Array  $boundArray Values bound, in order, to its ? placeholders.
	 * @return Int The number of rows changed, or -1 when the table cannot be written.
	 */
	protected function sendWrite( String $queryShape, Array $boundArray ): Int {
		if( $this->usrTable=='' ){
			return -1;
		}
		try{
			$dbHandl = $this->linkDBase();
			return $dbHandl->sendQuery($this->shapeQury($dbHandl, $queryShape), true, $boundArray) ? $dbHandl->lastCount() : -1;
		}catch( \Throwable $e ){
			return -1;
		}
	}

	/**
	 * Sends a query that reads the users table.
	 *
	 * @param String $queryShape The query, its table and fields written {name} (see shapeQury).
	 * @param Array  $boundArray Values bound, in order, to its ? placeholders.
	 * @return Array|Bool The rows, or false when the table cannot be read.
	 */
	protected function sendFetch( String $queryShape, Array $boundArray=Array() ): Array|Bool {
		if( $this->usrTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			if( !$dbHandl->sendQuery($this->shapeQury($dbHandl, $queryShape), false, $boundArray) ){
				return false;
			}
			$usrRows = $dbHandl->readQuery(true);
		}catch( \Throwable $e ){
			return false;
		}
		return is_array($usrRows) ? $usrRows : false;
	}
}
