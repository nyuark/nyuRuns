<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Access\Checker;
use nyu\Access\Auth\Device as DeviceAuth;

/**
 * Manages the devices: the machines allowed in with a token of their
 * own, each in the name of a user of the native userbase (see
 * Access\Auth\Device):
 *
 *   php nyuRun devices newToken home anna home --roleTops=advanced   gives a device a token, with its role and its cap: shown once
 *   php nyuRun devices fullList anna            lists the devices, of a user or of every user
 *   php nyuRun devices revokeIt home anna       revokes the device of that name of a user
 *
 * The role is the one the application gives its devices: the framework
 * knows none. The cap (--roleTops, standard when not given) is the highest
 * role the device acts with: on each request, the lower of it and the
 * user's current role applies. A device never changes its user's account
 * (password, 2FA, address). Only the hash of a token is stored; a revoked device is
 * kept in the list, with the time of its revocation. The settings are
 * those of the 'dvcToken' block of conf/access.json. Every change is
 * traced on the 'shell' log channel.
 */
class Device extends Command{

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Manages the devices working in the name of a user: their tokens, the list, the revocation';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
		return Array(
			'newToken'=>Array('args'=>'<name> <user> <role> [--roleTops=standard]', 'about'=>'gives a device a token, in the name of an active user, capped at a role no higher than the user\'s: shown once'),
			'fullList'=>Array('args'=>'[user]', 'about'=>'lists the devices, of a user or of every user'),
			'revokeIt'=>Array('args'=>'<name> <user>', 'about'=>'revokes the device of that name of a user: its token is refused from then on')
		);
	}

	/**
	 * Gives a device a token, in the name of an active user, with the
	 * role the command tells: a lower-case word of 8 characters at most,
	 * and the cap --roleTops tells (standard when not given). A cap that is
	 * not a role, or above the user's current role, is refused: it is never
	 * lowered silently. A name the user already gives one of its devices
	 * still standing is refused. The token is shown once.
	 *
	 * @return Int The exit code.
	 */
	public function invokeNewToken(): Int {
		$dvceName = $this->needsShellWord('the name of the device');
		$userName = $this->needsShellWord('the user');
		$dvceRole = $this->needsShellWord('the role of the device');
		if( !preg_match('/^[a-z][a-z0-9]{0,7}$/D', $dvceRole) ){
			$this->terminateShell('Invalid role: a lower-case word of 8 characters at most', self::EXIT_HELP);
		}
		$roleTops = strval($this->optionsObj->retrieveOption('roleTops', Checker::ROLE_LIST[0]));
		if( !in_array($roleTops, Checker::ROLE_LIST, true) ){
			$this->terminateShell('Invalid cap: one of '.implode(', ', Checker::ROLE_LIST), self::EXIT_HELP);
		}
		$dvceAuth = $this->getDeviceCheck();
		$userRole = $dvceAuth->userRoleString($userName);
		if( $userRole===null ){
			$this->terminateShell('No token made: the user is unknown or not active, or the users cannot be read');
		}
		if( array_search($roleTops, Checker::ROLE_LIST, true) > array_search($userRole, Checker::ROLE_LIST, true) ){
			$this->terminateShell('No token made: the cap '.$roleTops.' is above the role of '.json_encode($userName).', '.$userRole.': give it '.$userRole.' at most', self::EXIT_DENY);
		}
		if( $this->findDeviceNumb($dvceAuth, $dvceName, $userName)!==null ){
			$this->terminateShell('No token made: the user already has a device named '.json_encode($dvceName).' (devices revokeIt it first)', self::EXIT_DENY);
		}
		$newToken = $dvceAuth->pushDeviceText($userName, $dvceName, $dvceRole, $roleTops);
		if( $newToken===false ){
			$this->terminateShell('No token made: the user is unknown or not active, or the devices cannot be written');
		}
		$this->traceShellDone( 'Device: token '.json_encode($dvceName).' ('.$dvceRole.', capped at '.$roleTops.') made for user '.json_encode($userName) );
		$this->printerObj->printShellPair('token made', $dvceName);
		$this->printerObj->printShellOnce('Token of '.$dvceName, strval($newToken));
		return self::EXIT_DONE;
	}

	/**
	 * Lists the devices, of the user the command names or of every user:
	 * name, user, role, cap (empty for a device made before the caps, which
	 * acts with its user's role), when it was made, last seen and revoked.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$userName = strval($this->optionsObj->shiftFirstWord() ?? '');
		$dvceNtry = $this->getDeviceCheck()->lstUserDevices($userName);
		if( $dvceNtry===false ){
			$this->terminateShell('The devices cannot be read');
		}
		$rowsList = Array();
		foreach( $dvceNtry as $dvcRow ){
			$rowsList[] = Array(strval($dvcRow['deviceName']), strval($dvcRow['systemName']), strval($dvcRow['deviceRole']), strval($dvcRow['deviceTops'] ?? ''), strval($dvcRow['createDate']), strval($dvcRow['accessDate'] ?? ''), strval($dvcRow['revokeDate'] ?? ''));
		}
		$this->printerObj->printShellRows( Array('device', 'user', 'role', 'cap', 'made', 'last seen', 'revoked'), $rowsList );
		return self::EXIT_DONE;
	}

	/**
	 * Revokes the device of a user the command names: its token is
	 * refused from then on.
	 *
	 * @return Int The exit code.
	 */
	public function invokeRevokeIt(): Int {
		$dvceName = $this->needsShellWord('the name of the device');
		$userName = $this->needsShellWord('the user');
		$dvceAuth = $this->getDeviceCheck();
		$dvceIdnt = $this->findDeviceNumb($dvceAuth, $dvceName, $userName);
		if( $dvceIdnt===null ){
			$this->terminateShell('Nothing revoked: the user has no device named '.json_encode($dvceName).' still standing', self::EXIT_DENY);
		}
		if( !$dvceAuth->remUserDevcFlg($dvceIdnt) ){
			$this->terminateShell('The device cannot be revoked: the devices cannot be written');
		}
		$this->traceShellDone( 'Device: '.json_encode($dvceName).' of user '.json_encode($userName).' revoked' );
		$this->printerObj->printShellPair('device revoked', $dvceName);
		return self::EXIT_DONE;
	}

	/**
	 * Gives the check of the devices, from the 'dvcToken' block of the
	 * ACCESS config.
	 *
	 * @return DeviceAuth The check.
	 */
	protected function getDeviceCheck(): DeviceAuth {
		return new DeviceAuth( $this->accessSettings('dvcToken') );
	}

	/**
	 * Finds the device of a user that bears a name and is not revoked.
	 *
	 * @param DeviceAuth $devicesCheck The check of the devices.
	 * @param String     $userDevcName Name of the device.
	 * @param String     $namedUserTxt Name of the user.
	 * @return ?Int The identifier of the device, null when there is none.
	 */
	protected function findDeviceNumb( DeviceAuth $devicesCheck, String $userDevcName, String $namedUserTxt ): ?Int {
		$dvceNtry = $devicesCheck->lstUserDevices($namedUserTxt);
		if( $dvceNtry===false ){
			$this->terminateShell('The devices cannot be read');
		}
		foreach( $dvceNtry as $dvcRow ){
			if( $dvcRow['deviceName']===$userDevcName && $dvcRow['revokeDate']===null ){
				return intval($dvcRow['id_device']);
			}
		}
		return null;
	}
}
