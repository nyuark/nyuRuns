<?php

namespace nyu\Runs\Users;

use \nyu\App\Actionset;
use \nyu\Access\AccessMail;
use \nyu\Access\Checker;
use \nyu\Access\Auth\UserBase;

/**
 * Manages the users of the native userbase (see Access\Auth\UserBase),
 * the one the 'loginSet' block of the ACCESS config file names. Every
 * action is kept for the users of the highest role (see startCall).
 * A refusal of the userbase is reported with its own status: 400 for a
 * value that is not valid, 404 for an unknown user, 409 for a change the
 * state of the user does not allow.
 */
class Actions extends Actionset {

	protected $dfltAction = 'fullList';

	/**
	 * Lists the users, by name: identifier, name, role, status, address,
	 * time of the sign-up awaiting review, creation date and last time
	 * seen of each. Reads the 'stat' and 'role' inputs
	 * as filters, 'limit' and 'offset' as the part of the list to give
	 * (LST_ROWS users when 'limit' is left out). Reports 204 when the
	 * list is empty.
	 */
	public function fullList(): Void {
		$usrBase = $this->requireManager('GET');
		$usrList = $usrBase->listUsersNamed(
			Array('userStat'=>$this->inptRgstry->readFileDetail('stat'), 'userRole'=>$this->inptRgstry->readFileDetail('role')),
			intval($this->inptRgstry->readFileDetail('limit')),
			intval($this->inptRgstry->readFileDetail('offset'))
		);
		if($usrList===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->checkActionLst($usrList);
	}

	/**
	 * Reads the user the 'user' input names: identifier, name, role,
	 * status, address, time of the sign-up awaiting review, creation date
	 * and last time seen.
	 */
	public function viewUser(): Void {
		$usrBase = $this->requireManager('GET');
		$userVls = $usrBase->userInfosArray( $this->givenUsrString(false) );
		if($userVls===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = $userVls;
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
	public function makeUser(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(false);
		$gvnRole = $this->inptRgstry->readFileDetail('role');
		$gvnMail = $this->inptRgstry->readFileDetail('mail');
		$gvnMail = is_string($gvnMail) ? trim($gvnMail) : '';
		$gvnLang = $this->inptRgstry->readFileDetail('lang');
		$gvnLang = is_string($gvnLang) ? $gvnLang : null;
		$mailObj = ($gvnMail!='') ? AccessMail::fromLoginBlock( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCode = $usrBase->enrolUsrString( $gvnUser, is_string($gvnRole) ? $gvnRole : '', $gvnMail, ($mailObj!==null) ? $usrBase->mailedForCount() : UserBase::HAND_MINS, $gvnLang );
		if($newCode===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 201;
		if($mailObj!==null){
			// The mail is written in the language given for the user, never in the one of the manager asking
			$this->replyValue = Array('user'=>$gvnUser, 'mailed'=>$mailObj->sendCodeToUser('actvte', $gvnUser, strtolower($gvnMail), strval($newCode), $gvnLang));
			return;
		}
		$this->replyValue = Array('user'=>$gvnUser, 'code'=>$newCode);
	}

	/**
	 * Approves the user the 'user' input names, who signed up itself and
	 * confirmed its address: it becomes active. Refusing it is removing
	 * it (users/dropUser). Reports 409 for a user not awaiting review.
	 */
	public function approves(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(false);
		if(!$usrBase->approveNewUser($gvnUser)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'stat'=>'active');
	}

	/**
	 * Gives the user the 'user' input names the address of the 'mail'
	 * input, or takes its address away when 'mail' is empty or left out.
	 * Reports 409 for an address another user holds.
	 */
	public function setEmail(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(false);
		$gvnMail = $this->inptRgstry->readFileDetail('mail');
		if(!$usrBase->chngeEmailFlag($gvnUser, is_string($gvnMail) ? $gvnMail : null)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'mail'=>($usrBase->userInfosArray($gvnUser) ?: Array())['userMail'] ?? null);
	}

	/**
	 * Gives the user the 'user' input names the language of the 'lang'
	 * input, for its mails, or takes it away when 'lang' is empty or left
	 * out: its mails are then written in the default one (LNG_DFLT).
	 * Reports 400 for a language that is not a tag (fr, fr-ch).
	 */
	public function language(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(false);
		$gvnLang = $this->inptRgstry->readFileDetail('lang');
		if(!$usrBase->changeLanguage($gvnUser, is_string($gvnLang) ? $gvnLang : null)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'lang'=>($usrBase->userInfosArray($gvnUser) ?: Array())['userLang'] ?? null);
	}

	/**
	 * Locks the user the 'user' input names: it can no longer log in,
	 * and a session it holds ends as soon as its preset names the
	 * userbase as its credential store. A manager cannot lock itself.
	 */
	public function lockUser(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(true);
		if(!$usrBase->saveStatusFlag($gvnUser, 'locked')){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'stat'=>'locked');
	}

	/**
	 * Unlocks the user the 'user' input names.
	 */
	public function freeUser(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(false);
		if(!$usrBase->saveStatusFlag($gvnUser, 'active')){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'stat'=>'active');
	}

	/**
	 * Gives the user the 'user' input names the role of the 'role'
	 * input. A manager cannot change its own role.
	 */
	public function giveRole(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(true);
		$gvnRole = $this->inptRgstry->readFileDetail('role');
		if(!$usrBase->changeRoleFlag($gvnUser, is_string($gvnRole) ? $gvnRole : '')){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'role'=>$gvnRole);
	}

	/**
	 * Removes the user the 'user' input names. A manager cannot remove
	 * itself.
	 */
	public function dropUser(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(true);
		if(!$usrBase->removeUserFlag($gvnUser)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'removed'=>true);
	}

	/**
	 * Puts the user the 'user' input names back to pending, for one
	 * whose password is lost, with a new activation code to choose a
	 * password with (auth/activate). When the userbase sends mails and the
	 * user has an address, the code is mailed to it, and not told here;
	 * otherwise the reply carries it, told this once. A manager cannot
	 * reset itself.
	 */
	public function resetPwd(): Void {
		$usrBase = $this->requireManager('POST');
		$gvnUser = $this->givenUsrString(true);
		$usrInfo = $usrBase->userInfosArray($gvnUser) ?: Array();
		$usrMail = strval( $usrInfo['userMail'] ?? '' );
		$mailObj = ($usrMail!='') ? AccessMail::fromLoginBlock( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCode = $usrBase->resetUsrString( $gvnUser, ($mailObj!==null) ? $usrBase->mailedForCount() : UserBase::HAND_MINS );
		if($newCode===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		if($mailObj!==null){
			// The mail is written in the language of the user, never in the one of the manager asking
			$this->replyValue = Array('user'=>$gvnUser, 'mailed'=>$mailObj->sendCodeToUser('actvte', $gvnUser, $usrMail, strval($newCode), $usrInfo['userLang'] ?? null));
			return;
		}
		$this->replyValue = Array('user'=>$gvnUser, 'code'=>$newCode);
	}

	/**
	 * Opens every action: ends it with a 404 unless the request comes
	 * from a logged user of the highest role of Checker::ROLE_LIST (see
	 * needsRole), a 405 unless it uses the given method, and a 500 when
	 * the login does not use the native userbase. The role is asked for
	 * here whatever the preset does: a 'rteRoles' entry of its 'security'
	 * block only refuses the request earlier.
	 *
	 * @param String $httpMthdText Method the action answers to.
	 * @return UserBase The manager of the userbase.
	 */
	protected function requireManager( String $httpMthdText ): UserBase {
		$this->needsActionRle( array_slice(Checker::ROLE_LIST, -1)[0] );
		if($this->httpRequst===null || !$this->httpRequst->usedMethodFlag($httpMthdText)){
			$this->stopThisAction(405, 'Method Not Allowed');
		}
		$usrBase = UserBase::fromLoginBlock();
		if($usrBase===null){
			$this->stopThisAction(500, 'User management unavailable');
		}
		return $usrBase;
	}

	/**
	 * Reads the 'user' input: the name of the user the action is about.
	 * Ends the action with a 400 when it is not a text, and with a 409
	 * when it must be another user than the one asking and is not.
	 *
	 * @param Bool $notOnOwnAcnt Whether the action is refused on the account of the one asking.
	 * @return String The name of the user.
	 */
	protected function givenUsrString( Bool $notOnOwnAcnt ): String {
		$gvnUser = $this->inptRgstry->readFileDetail('user');
		if(!is_string($gvnUser) || $gvnUser==''){
			$this->stopThisAction(400, 'Invalid user');
		}
		if($notOnOwnAcnt && $gvnUser===strval($this->accessRgst->readFileDetail('user'))){
			$this->stopThisAction(409, 'A manager cannot do this to its own account');
		}
		return $gvnUser;
	}
}
