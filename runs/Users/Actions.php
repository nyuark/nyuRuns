<?php

namespace nyu\Runs\Users;

use \nyu\App\Actionset;
use \nyu\Access\AccessMail;
use \nyu\Access\Checker;
use \nyu\Access\Auth\UserBase;

/**
 * Manages the users of the native userbase (see Access\Auth\UserBase),
 * the one the 'loginSet' block of the ACCESS config file names. Every
 * action is kept for the users of the highest role (see setupForAction);
 * the routes of routes.rte name the user in the path and set the methods.
 * A refusal of the userbase is reported with its own status: 400 for a
 * value that is not valid, 404 for an unknown user, 409 for a change the
 * state of the user does not allow.
 */
class Actions extends Actionset {

	// The native userbase, opened for the action by setupForAction()
	protected $userBaseDb;

	/**
	 * Lists the users, by name: identifier, name, role, status, address,
	 * time of the sign-up awaiting review, creation date and last time
	 * seen of each. Reads the 'currStat' and 'saveRole' inputs
	 * as filters, 'limitVal' and 'rowIndex' as the part of the list to give
	 * (DEF_ROWS users when 'limitVal' is left out). Reports 204 when the
	 * list is empty.
	 */
	public function index(): Void {
		$usrBase = $this->userBaseDb;
		$usrList = $usrBase->listUsersNamed(
			Array('userStatus'=>$this->inputsRgst->readValueByKey('currStat'), 'accessRole'=>$this->inputsRgst->readValueByKey('saveRole')),
			intval($this->inputsRgst->readValueByKey('limitVal')),
			intval($this->inputsRgst->readValueByKey('rowIndex'))
		);
		if($usrList===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->checkActionLst($usrList);
	}

	/**
	 * Reads the user its path names: identifier, name, role,
	 * status, address, time of the sign-up awaiting review, creation date
	 * and last time seen.
	 */
	public function view(): Void {
		$usrBase = $this->userBaseDb;
		$userVls = $usrBase->userInfosArray( $this->givenUsrString(false) );
		if($userVls===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = $userVls;
	}

	/**
	 * Creates the user the 'userName' input names, with the role of the
	 * 'saveRole' input (the lowest one when left out), the address of the
	 * 'userMail' input (none when left out) and the language of its mails of
	 * the 'saveLang' input (the default one when left out). The user is pending, with an
	 * activation code to choose its password with (auth/active). When
	 * the userbase sends mails and the user has an address, the code is
	 * mailed to it, and not told here; otherwise the reply carries it,
	 * told this once. Reports 201, or 409 when the name or the address is
	 * taken.
	 */
	public function create(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->inputsRgst->readValueByKey('userName');
		if(!is_string($gvnUser) || $gvnUser==''){
			$this->stopThisAction(400, 'Invalid userName');
		}
		$gvnRole = $this->inputsRgst->readValueByKey('saveRole');
		$gvnMail = $this->inputsRgst->readValueByKey('userMail');
		$gvnMail = is_string($gvnMail) ? trim($gvnMail) : '';
		$gvnLang = $this->inputsRgst->readValueByKey('saveLang');
		$gvnLang = is_string($gvnLang) ? $gvnLang : null;
		$mailObj = ($gvnMail!='') ? AccessMail::fromLoginBlock( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCode = $usrBase->enrolUsrString( $gvnUser, is_string($gvnRole) ? $gvnRole : '', $gvnMail, ($mailObj!==null) ? $usrBase->mailedForCount() : UserBase::HAND_MINS, $gvnLang );
		if($newCode===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 201;
		if($mailObj!==null){
			// The mail is written in the language given for the user, never in the one of the manager asking
			$this->replyValue = Array('user'=>$gvnUser, 'mailed'=>$mailObj->sendCodeToUser('activate', $gvnUser, strtolower($gvnMail), strval($newCode), $gvnLang));
			return;
		}
		$this->replyValue = Array('user'=>$gvnUser, 'code'=>$newCode);
	}

	/**
	 * Approves the user its path names, who signed up itself and
	 * confirmed its address: it becomes active. Refusing it is removing
	 * it (users/remove). Reports 409 for a user not awaiting review.
	 */
	public function approve(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(false);
		if(!$usrBase->approveNewUser($gvnUser)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'stat'=>'approved');
	}

	/**
	 * Gives the user its path names the address of the 'userMail'
	 * input, or takes its address away when 'userMail' is empty or left out.
	 * Reports 409 for an address another user holds, and 403 for a device
	 * changing the address of its own user (see needsPasswdLgn).
	 */
	public function setmail(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(false);
		// A device never changes the address of its own user: that needs the password login
		if( $gvnUser===strval($this->accessRgst->readValueByKey('userName')) ){
			$this->needsPasswdLgn();
		}
		$gvnMail = $this->inputsRgst->readValueByKey('userMail');
		if(!$usrBase->changeMailFlag($gvnUser, is_string($gvnMail) ? $gvnMail : null)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'mail'=>($usrBase->userInfosArray($gvnUser) ?: Array())['contactEml'] ?? null);
	}

	/**
	 * Gives the user its path names the language of the 'saveLang'
	 * input, for its mails, or takes it away when 'saveLang' is empty or left
	 * out: its mails are then written in the default one (DEF_LANG).
	 * Reports 400 for a language that is not a tag (fr, fr-ch).
	 */
	public function langs(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(false);
		$gvnLang = $this->inputsRgst->readValueByKey('saveLang');
		if(!$usrBase->changeLanguage($gvnUser, is_string($gvnLang) ? $gvnLang : null)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'lang'=>($usrBase->userInfosArray($gvnUser) ?: Array())['userLocale'] ?? null);
	}

	/**
	 * Locks the user its path names: it can no longer log in,
	 * and a session it holds ends as soon as its preset names the
	 * userbase as its credential store. A manager cannot lock itself.
	 */
	public function lock(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(true);
		if(!$usrBase->saveStatusFlag($gvnUser, 'disabled')){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'stat'=>'disabled');
	}

	/**
	 * Unlocks the user its path names.
	 */
	public function unlock(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(false);
		if(!$usrBase->saveStatusFlag($gvnUser, 'approved')){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'stat'=>'approved');
	}

	/**
	 * Gives the user its path names the role of the 'saveRole'
	 * input. A manager cannot change its own role.
	 */
	public function role(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(true);
		$gvnRole = $this->inputsRgst->readValueByKey('saveRole');
		if(!$usrBase->changeRoleFlag($gvnUser, is_string($gvnRole) ? $gvnRole : '')){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'role'=>$gvnRole);
	}

	/**
	 * Removes the user its path names. A manager cannot remove
	 * itself.
	 */
	public function remove(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(true);
		if(!$usrBase->removeUserFlag($gvnUser)){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$gvnUser, 'removed'=>true);
	}

	/**
	 * Puts the user its path names back to pending, for one
	 * whose password is lost, with a new activation code to choose a
	 * password with (auth/active). When the userbase sends mails and the
	 * user has an address, the code is mailed to it, and not told here;
	 * otherwise the reply carries it, told this once. A manager cannot
	 * reset itself.
	 */
	public function reset(): Void {
		$usrBase = $this->userBaseDb;
		$gvnUser = $this->givenUsrString(true);
		$usrInfo = $usrBase->userInfosArray($gvnUser) ?: Array();
		$usrMail = strval( $usrInfo['contactEml'] ?? '' );
		$mailObj = ($usrMail!='') ? AccessMail::fromLoginBlock( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCode = $usrBase->resetUsrString( $gvnUser, ($mailObj!==null) ? $usrBase->mailedForCount() : UserBase::HAND_MINS );
		if($newCode===false){
			$this->stopThisAction( $usrBase->lastStateCount(), $usrBase->finalErrorText() );
		}
		$this->statusCode = 200;
		if($mailObj!==null){
			// The mail is written in the language of the user, never in the one of the manager asking
			$this->replyValue = Array('user'=>$gvnUser, 'mailed'=>$mailObj->sendCodeToUser('activate', $gvnUser, $usrMail, strval($newCode), $usrInfo['userLocale'] ?? null));
			return;
		}
		$this->replyValue = Array('user'=>$gvnUser, 'code'=>$newCode);
	}

	/**
	 * Opens every action: ends it with a 404 unless the request comes from a
	 * logged user of the highest role of Checker::ROLE_LIST (see
	 * needsActionRle), and with a 500 when the login does not use the native
	 * userbase, which it keeps for the action. The role is asked for here
	 * whatever the preset does: a 'minRoles' entry of its 'security' block
	 * only refuses the request earlier. The methods are the routes' (routes.rte):
	 * another one is answered 405 before the action is reached.
	 */
	protected function setupForAction(): Void {
		$this->needsActionRle( array_slice(Checker::ROLE_LIST, -1)[0] );
		$usrBase = UserBase::fromLoginBlock();
		if($usrBase===null){
			$this->stopThisAction(500, 'User management unavailable');
		}
		$this->userBaseDb = $usrBase;
	}

	/**
	 * Reads the user the action is about: the option of the action, the
	 * name its route takes from the path (users/<user>/...). Ends the action
	 * with a 400 when there is none, and with a 409 when it must be another
	 * user than the one asking and is not.
	 *
	 * @param Bool $notOnOwnAcnt Whether the action is refused on the account of the one asking.
	 * @return String The name of the user.
	 */
	protected function givenUsrString( Bool $notOnOwnAcnt ): String {
		$gvnUser = strval( $this->runContext->optionText ?? '' );
		if($gvnUser==''){
			$this->stopThisAction(400, 'Invalid user');
		}
		if($notOnOwnAcnt && $gvnUser===strval($this->accessRgst->readValueByKey('userName'))){
			$this->stopThisAction(409, 'A manager cannot do this to its own account');
		}
		return $gvnUser;
	}
}
