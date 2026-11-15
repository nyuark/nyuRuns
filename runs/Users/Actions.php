<?php

namespace nyu\Runs\Users;

use \nyu\App\Actionset;
use \nyu\Access\AccessMail;
use \nyu\Access\Checker;
use \nyu\Access\Auth\UserBase;

/**
 * Manages the users of the native userbase (see Access\Auth\UserBase),
 * the one the 'loginSet' block of the ACCESS config file names. A
 * delegate reads the users and approves the sign-ups (ACTN_ROLE); every
 * other action, and any one added later, is kept for the users of the
 * highest role (see setupForAction). Approving a sign-up leaves its role
 * as it is (standard). The routes of routes.rte name the user in the path
 * and set the methods.
 * A refusal of the userbase is reported with its own status: 400 for a
 * value that is not valid, 404 for an unknown user, 409 for a change the
 * state of the user does not allow.
 */
class Actions extends Actionset {

	// The actions asking for less than the highest role, with the one each asks for; any other, and any added later, asks for the highest
	const ACTN_ROLE = Array('index'=>'delegate', 'view'=>'delegate', 'approve'=>'delegate');

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
		$userBase = $this->userBaseDb;
		$userList = $userBase->listUsersNamed(
			Array('userStatus'=>$this->inputsRgst->readValueByKey('currStat'), 'accessRole'=>$this->inputsRgst->readValueByKey('saveRole')),
			intval($this->inputsRgst->readValueByKey('limitVal')),
			intval($this->inputsRgst->readValueByKey('rowIndex'))
		);
		if($userList===false){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->checkActionLst($userList);
	}

	/**
	 * Reads the user its path names: identifier, name, role,
	 * status, address, time of the sign-up awaiting review, creation date
	 * and last time seen.
	 */
	public function view(): Void {
		$userBase = $this->userBaseDb;
		$userVals = $userBase->userInfosArray( $this->givenUsrString(false) );
		if($userVals===false){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = $userVals;
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
		$userBase = $this->userBaseDb;
		$givenUsr = $this->inputsRgst->readValueByKey('userName');
		if(!is_string($givenUsr) || $givenUsr==''){
			$this->stopThisAction(400, 'Invalid userName');
		}
		$givenRle = $this->inputsRgst->readValueByKey('saveRole');
		$givenEml = $this->inputsRgst->readValueByKey('userMail');
		$givenEml = is_string($givenEml) ? trim($givenEml) : '';
		$givenLng = $this->inputsRgst->readValueByKey('saveLang');
		$givenLng = is_string($givenLng) ? $givenLng : null;
		$mailInst = ($givenEml!='') ? AccessMail::fromLoginBlock( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCodig = $userBase->enrolUsrString( $givenUsr, is_string($givenRle) ? $givenRle : '', $givenEml, ($mailInst!==null) ? $userBase->mailedForCount() : UserBase::HAND_MINS, $givenLng );
		if($newCodig===false){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 201;
		if($mailInst!==null){
			// The mail is written in the language given for the user, never in the one of the manager asking
			$this->replyValue = Array('user'=>$givenUsr, 'mailed'=>$mailInst->sendCodeToUser('activate', $givenUsr, strtolower($givenEml), strval($newCodig), $givenLng));
			return;
		}
		$this->replyValue = Array('user'=>$givenUsr, 'code'=>$newCodig);
	}

	/**
	 * Approves the user its path names, who signed up itself and
	 * confirmed its address: it becomes active. Refusing it is removing
	 * it (users/remove). Reports 409 for a user not awaiting review.
	 */
	public function approve(): Void {
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(false);
		if(!$userBase->approveNewUser($givenUsr)){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$givenUsr, 'stat'=>'approved');
	}

	/**
	 * Gives the user its path names the address of the 'userMail'
	 * input, or takes its address away when 'userMail' is empty or left out.
	 * Reports 409 for an address another user holds, and 403 for a device
	 * changing the address of its own user (see needsPasswdLgn).
	 */
	public function setmail(): Void {
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(false);
		// A device never changes the address of its own user: that needs the password login
		if( $givenUsr===strval($this->accessRgst->readValueByKey('userName')) ){
			$this->needsPasswdLgn();
		}
		$givenEml = $this->inputsRgst->readValueByKey('userMail');
		if(!$userBase->changeMailFlag($givenUsr, is_string($givenEml) ? $givenEml : null)){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$givenUsr, 'mail'=>($userBase->userInfosArray($givenUsr) ?: Array())['contactEml'] ?? null);
	}

	/**
	 * Gives the user its path names the language of the 'saveLang'
	 * input, for its mails, or takes it away when 'saveLang' is empty or left
	 * out: its mails are then written in the default one (DEF_LANG).
	 * Reports 400 for a language that is not a tag (fr, fr-ch).
	 */
	public function langs(): Void {
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(false);
		$givenLng = $this->inputsRgst->readValueByKey('saveLang');
		if(!$userBase->changeLanguage($givenUsr, is_string($givenLng) ? $givenLng : null)){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$givenUsr, 'lang'=>($userBase->userInfosArray($givenUsr) ?: Array())['userLocale'] ?? null);
	}

	/**
	 * Locks the user its path names: it can no longer log in,
	 * and a session it holds ends as soon as its preset names the
	 * userbase as its credential store. A manager cannot lock itself.
	 */
	public function lock(): Void {
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(true);
		if(!$userBase->saveStatusFlag($givenUsr, 'disabled')){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$givenUsr, 'stat'=>'disabled');
	}

	/**
	 * Unlocks the user its path names.
	 */
	public function unlock(): Void {
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(false);
		if(!$userBase->saveStatusFlag($givenUsr, 'approved')){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$givenUsr, 'stat'=>'approved');
	}

	/**
	 * Gives the user its path names the role of the 'saveRole'
	 * input. A manager cannot change its own role.
	 */
	public function role(): Void {
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(true);
		$givenRle = $this->inputsRgst->readValueByKey('saveRole');
		if(!$userBase->changeRoleFlag($givenUsr, is_string($givenRle) ? $givenRle : '')){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$givenUsr, 'role'=>$givenRle);
	}

	/**
	 * Removes the user its path names. A manager cannot remove
	 * itself.
	 */
	public function remove(): Void {
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(true);
		if(!$userBase->removeUserFlag($givenUsr)){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		$this->replyValue = Array('user'=>$givenUsr, 'removed'=>true);
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
		$userBase = $this->userBaseDb;
		$givenUsr = $this->givenUsrString(true);
		$userDict = $userBase->userInfosArray($givenUsr) ?: Array();
		$userMail = strval( $userDict['contactEml'] ?? '' );
		$mailInst = ($userMail!='') ? AccessMail::fromLoginBlock( dirname(__DIR__).DS.'Auth', PHP_SAPI!=='cli' ) : null;
		$newCodig = $userBase->resetUsrString( $givenUsr, ($mailInst!==null) ? $userBase->mailedForCount() : UserBase::HAND_MINS );
		if($newCodig===false){
			$this->stopThisAction( $userBase->lastStateCount(), $userBase->finalErrorText() );
		}
		$this->statusCode = 200;
		if($mailInst!==null){
			// The mail is written in the language of the user, never in the one of the manager asking
			$this->replyValue = Array('user'=>$givenUsr, 'mailed'=>$mailInst->sendCodeToUser('activate', $givenUsr, $userMail, strval($newCodig), $userDict['userLocale'] ?? null));
			return;
		}
		$this->replyValue = Array('user'=>$givenUsr, 'code'=>$newCodig);
	}

	/**
	 * Opens every action: ends it with a 404 unless the request comes from a
	 * logged user of the role the action asks for, the one ACTN_ROLE gives it
	 * or else the highest of Checker::ROLE_LIST (see needsActionRle), and with
	 * a 500 when the login does not use the native
	 * userbase, which it keeps for the action. The role is asked for here
	 * whatever the preset does: a 'minRoles' entry of its 'security' block
	 * only refuses the request earlier. The methods are the routes' (routes.rte):
	 * another one is answered 405 before the action is reached.
	 */
	protected function setupForAction(): Void {
		$this->needsActionRle( self::ACTN_ROLE[strtolower($this->actionName)] ?? array_slice(Checker::ROLE_LIST, -1)[0] );
		$userBase = UserBase::fromLoginBlock();
		if($userBase===null){
			$this->stopThisAction(500, 'User management unavailable');
		}
		$this->userBaseDb = $userBase;
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
		$givenUsr = strval( $this->runContext->optionText ?? '' );
		if($givenUsr==''){
			$this->stopThisAction(400, 'Invalid user');
		}
		if($notOnOwnAcnt && $givenUsr===strval($this->accessRgst->readValueByKey('userName'))){
			$this->stopThisAction(409, 'A manager cannot do this to its own account');
		}
		return $givenUsr;
	}
}
