<?php

namespace nyu\Runs\Users;

use \nyu\App\Actionset;
use \nyu\Access\AccessMail;
use \nyu\Access\Checker;
use \nyu\Access\Auth\UserBase;

/**
 * Manages the users of the native userbase (see Access\Auth\UserBase),
 * the one the 'login' block of the ACCESS config file names. Every
 * action is kept for the users of the highest role (see startCall).
 * A refusal of the userbase is reported with its own status: 400 for a
 * value that is not valid, 404 for an unknown user, 409 for a change the
 * state of the user does not allow.
 */
class Actions extends Actionset {

	protected $dfltActn = 'index';

	/**
	 * Lists the users, by name: identifier, name, role, status, address,
	 * time of the sign-up awaiting review, creation date and last time
	 * seen of each. Reads the 'stat' and 'role' inputs
	 * as filters, 'limit' and 'offset' as the part of the list to give
	 * (LST_ROWS users when 'limit' is left out). Reports 204 when the
	 * list is empty.
	 */
	public function index(): Void {
		$usrBase = $this->startCall('GET');
		$usrList = $usrBase->listUsers(
			Array('userStat'=>$this->inputReg->readValue('stat'), 'userRole'=>$this->inputReg->readValue('role')),
			intval($this->inputReg->readValue('limit')),
			intval($this->inputReg->readValue('offset'))
		);
		if($usrList===false){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->checkList($usrList);
	}

	/**
	 * Reads the user the 'user' input names: identifier, name, role,
	 * status, address, time of the sign-up awaiting review, creation date
	 * and last time seen.
	 */
	public function view(): Void {
		$usrBase = $this->startCall('GET');
		$usrVals = $usrBase->userInfos( $this->givenUser(false) );
		if($usrVals===false){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = $usrVals;
	}

	/**
	 * Creates the user the 'user' input names, with the role of the
	 * 'role' input (the lowest one when left out), the address of the
	 * 'mail' input (none when left out) and the language of its mails of
	 * the 'lang' input (the default one when left out). The user is pending, with an
	 * activation code to choose its password with (auth/activate). When
	 * the userbase sends mails and the user has an address, the code is
	 * mailed to it, and not told here; otherwise the reply carries it,
	 * told this once. Reports 201, or 409 when the name or the address is
	 * taken.
	 */
	public function create(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(false);
		$gvnRole = $this->inputReg->readValue('role');
		$gvnMail = $this->inputReg->readValue('mail');
		$gvnMail = is_string($gvnMail) ? trim($gvnMail) : '';
		$gvnLang = $this->inputReg->readValue('lang');
		$gvnLang = is_string($gvnLang) ? $gvnLang : null;
		$mailObj = ($gvnMail!='') ? AccessMail::fromLogin( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCode = $usrBase->enrolUser( $gvnUser, is_string($gvnRole) ? $gvnRole : '', $gvnMail, ($mailObj!==null) ? $usrBase->mailedFor() : UserBase::ADMNMINS, $gvnLang );
		if($newCode===false){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 201;
		if($mailObj!==null){
			// The mail is written in the language given for the user, never in the one of the manager asking
			$this->asOutput = Array('user'=>$gvnUser, 'mailed'=>$mailObj->sendCodes('actvte', $gvnUser, strtolower($gvnMail), strval($newCode), $gvnLang));
			return;
		}
		$this->asOutput = Array('user'=>$gvnUser, 'code'=>$newCode);
	}

	/**
	 * Approves the user the 'user' input names, who signed up itself and
	 * confirmed its address: it becomes active. Refusing it is removing
	 * it (users/remove). Reports 409 for a user not awaiting review.
	 */
	public function approve(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(false);
		if(!$usrBase->proveUser($gvnUser)){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = Array('user'=>$gvnUser, 'stat'=>'active');
	}

	/**
	 * Gives the user the 'user' input names the address of the 'mail'
	 * input, or takes its address away when 'mail' is empty or left out.
	 * Reports 409 for an address another user holds.
	 */
	public function email(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(false);
		$gvnMail = $this->inputReg->readValue('mail');
		if(!$usrBase->chngeMail($gvnUser, is_string($gvnMail) ? $gvnMail : null)){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = Array('user'=>$gvnUser, 'mail'=>($usrBase->userInfos($gvnUser) ?: Array())['userMail'] ?? null);
	}

	/**
	 * Gives the user the 'user' input names the language of the 'lang'
	 * input, for its mails, or takes it away when 'lang' is empty or left
	 * out: its mails are then written in the default one (LNG_DFLT).
	 * Reports 400 for a language that is not a tag (fr, fr-ch).
	 */
	public function langs(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(false);
		$gvnLang = $this->inputReg->readValue('lang');
		if(!$usrBase->chngeLang($gvnUser, is_string($gvnLang) ? $gvnLang : null)){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = Array('user'=>$gvnUser, 'lang'=>($usrBase->userInfos($gvnUser) ?: Array())['userLang'] ?? null);
	}

	/**
	 * Locks the user the 'user' input names: it can no longer log in,
	 * and a session it holds ends as soon as its preset names the
	 * userbase as its credential store. A manager cannot lock itself.
	 */
	public function lock(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(true);
		if(!$usrBase->setStatus($gvnUser, 'locked')){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = Array('user'=>$gvnUser, 'stat'=>'locked');
	}

	/**
	 * Unlocks the user the 'user' input names.
	 */
	public function unlock(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(false);
		if(!$usrBase->setStatus($gvnUser, 'active')){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = Array('user'=>$gvnUser, 'stat'=>'active');
	}

	/**
	 * Gives the user the 'user' input names the role of the 'role'
	 * input. A manager cannot change its own role.
	 */
	public function role(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(true);
		$gvnRole = $this->inputReg->readValue('role');
		if(!$usrBase->changRole($gvnUser, is_string($gvnRole) ? $gvnRole : '')){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = Array('user'=>$gvnUser, 'role'=>$gvnRole);
	}

	/**
	 * Removes the user the 'user' input names. A manager cannot remove
	 * itself.
	 */
	public function remove(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(true);
		if(!$usrBase->removUser($gvnUser)){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		$this->asOutput = Array('user'=>$gvnUser, 'removed'=>true);
	}

	/**
	 * Puts the user the 'user' input names back to pending, for one
	 * whose password is lost, with a new activation code to choose a
	 * password with (auth/activate). When the userbase sends mails and the
	 * user has an address, the code is mailed to it, and not told here;
	 * otherwise the reply carries it, told this once. A manager cannot
	 * reset itself.
	 */
	public function reset(): Void {
		$usrBase = $this->startCall('POST');
		$gvnUser = $this->givenUser(true);
		$usrInfo = $usrBase->userInfos($gvnUser) ?: Array();
		$usrMail = strval( $usrInfo['userMail'] ?? '' );
		$mailObj = ($usrMail!='') ? AccessMail::fromLogin( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCode = $usrBase->resetUser( $gvnUser, ($mailObj!==null) ? $usrBase->mailedFor() : UserBase::ADMNMINS );
		if($newCode===false){
			$this->endAction( $usrBase->lastState(), $usrBase->lastError() );
		}
		$this->statCode = 200;
		if($mailObj!==null){
			// The mail is written in the language of the user, never in the one of the manager asking
			$this->asOutput = Array('user'=>$gvnUser, 'mailed'=>$mailObj->sendCodes('actvte', $gvnUser, $usrMail, strval($newCode), $usrInfo['userLang'] ?? null));
			return;
		}
		$this->asOutput = Array('user'=>$gvnUser, 'code'=>$newCode);
	}

	/**
	 * Opens every action: ends it with a 403 unless the request comes
	 * from a logged user of the highest role of Checker::ROLELIST (see
	 * needsRole), a 405 unless it uses the given method, and a 500 when
	 * the login does not use the native userbase. The role is asked for
	 * here whatever the preset does: a 'rolert' entry of its 'auths'
	 * block only refuses the request earlier.
	 *
	 * @param String $httpMethod Method the action answers to.
	 * @return UserBase The manager of the userbase.
	 */
	protected function startCall( String $httpMethod ): UserBase {
		$this->needsRole( array_slice(Checker::ROLELIST, -1)[0] );
		if($this->httpRqst===null || !$this->httpRqst->usedMethd($httpMethod)){
			$this->endAction(405, 'Method Not Allowed');
		}
		$usrBase = UserBase::fromLogin();
		if($usrBase===null){
			$this->endAction(500, 'User management unavailable');
		}
		return $usrBase;
	}

	/**
	 * Reads the 'user' input: the name of the user the action is about.
	 * Ends the action with a 400 when it is not a text, and with a 409
	 * when it must be another user than the one asking and is not.
	 *
	 * @param Bool $othersOnly Whether the action is refused on the account of the one asking.
	 * @return String The name of the user.
	 */
	protected function givenUser( Bool $othersOnly ): String {
		$gvnUser = $this->inputReg->readValue('user');
		if(!is_string($gvnUser) || $gvnUser==''){
			$this->endAction(400, 'Invalid user');
		}
		if($othersOnly && $gvnUser===strval($this->axessReg->readValue('user'))){
			$this->endAction(409, 'A manager cannot do this to its own account');
		}
		return $gvnUser;
	}
}
