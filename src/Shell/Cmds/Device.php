<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Access\Auth\Device as DeviceAuth;

/**
 * Manages the devices: the machines allowed in with a token of their
 * own, each in the name of a user of the native userbase (see
 * Access\Auth\Device):
 *
 *   php nyuRun machines newToken home anna home  gives a device a token, with its role: shown once
 *   php nyuRun machines fullList anna            lists the devices, of a user or of every user
 *   php nyuRun machines revokeIt home anna       revokes the device of that name of a user
 *
 * The role is the one the application gives its devices: the framework
 * knows none. Only the hash of a token is stored; a revoked device is
 * kept in the list, with the time of its revocation. The settings are
 * those of the 'devToken' block of conf/access.json. Every change is
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
			'newToken'=>Array('args'=>'<name> <user> <role>', 'about'=>'gives a device a token, in the name of an active user: shown once'),
			'fullList'=>Array('args'=>'[user]', 'about'=>'lists the devices, of a user or of every user'),
			'revokeIt'=>Array('args'=>'<name> <user>', 'about'=>'revokes the device of that name of a user: its token is refused from then on')
		);
	}

	/**
	 * Gives a device a token, in the name of an active user, with the
	 * role the command tells: a lower-case word of 8 characters at most.
	 * A name the user already gives one of its devices still standing is
	 * refused. The token is shown once.
	 *
	 * @return Int The exit code.
	 */
	public function invokeNewToken(): Int {
		$devcNme = $this->needsShellWord('the name of the device');
		$usrName = $this->needsShellWord('the user');
		$devcRle = $this->needsShellWord('the role of the device');
		if( !preg_match('/^[a-z][a-z0-9]{0,7}$/D', $devcRle) ){
			$this->terminateShell('Invalid role: a lower-case word of 8 characters at most', self::EXIT_HELP);
		}
		$dvcAuth = $this->getDeviceCheck();
		if( $this->findDeviceNumb($dvcAuth, $devcNme, $usrName)!==null ){
			$this->terminateShell('No token made: the user already has a device named '.json_encode($devcNme).' (machines revokeIt it first)', self::EXIT_DENY);
		}
		$newTokn = $dvcAuth->pushDeviceText($usrName, $devcNme, $devcRle);
		if( $newTokn===false ){
			$this->terminateShell('No token made: the user is unknown or not active, or the devices cannot be written');
		}
		$this->traceShellDone( 'Device: token '.json_encode($devcNme).' ('.$devcRle.') made for user '.json_encode($usrName) );
		$this->printerObj->printShellPair('token made', $devcNme);
		$this->printerObj->printShellOnce('Token of '.$devcNme, strval($newTokn));
		return self::EXIT_DONE;
	}

	/**
	 * Lists the devices, of the user the command names or of every user:
	 * name, user, role, when it was made, last seen and revoked.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$usrName = strval($this->optnObject->shiftFirstWord() ?? '');
		$dvcRows = $this->getDeviceCheck()->lstUserDevices($usrName);
		if( $dvcRows===false ){
			$this->terminateShell('The devices cannot be read');
		}
		$rowList = Array();
		foreach( $dvcRows as $dvcRow ){
			$rowList[] = Array(strval($dvcRow['devcName']), strval($dvcRow['userName']), strval($dvcRow['devcRole']), strval($dvcRow['madeDate']), strval($dvcRow['lastSeen'] ?? ''), strval($dvcRow['revkDate'] ?? ''));
		}
		$this->printerObj->printShellRows( Array('device', 'user', 'role', 'made', 'last seen', 'revoked'), $rowList );
		return self::EXIT_DONE;
	}

	/**
	 * Revokes the device of a user the command names: its token is
	 * refused from then on.
	 *
	 * @return Int The exit code.
	 */
	public function invokeRevokeIt(): Int {
		$devcNme = $this->needsShellWord('the name of the device');
		$usrName = $this->needsShellWord('the user');
		$dvcAuth = $this->getDeviceCheck();
		$dvcIdnt = $this->findDeviceNumb($dvcAuth, $devcNme, $usrName);
		if( $dvcIdnt===null ){
			$this->terminateShell('Nothing revoked: the user has no device named '.json_encode($devcNme).' still standing', self::EXIT_DENY);
		}
		if( !$dvcAuth->remUserDevcFlg($dvcIdnt) ){
			$this->terminateShell('The device cannot be revoked: the devices cannot be written');
		}
		$this->traceShellDone( 'Device: '.json_encode($devcNme).' of user '.json_encode($usrName).' revoked' );
		$this->printerObj->printShellPair('device revoked', $devcNme);
		return self::EXIT_DONE;
	}

	/**
	 * Gives the check of the devices, from the 'devToken' block of the
	 * ACCESS config.
	 *
	 * @return DeviceAuth The check.
	 */
	protected function getDeviceCheck(): DeviceAuth {
		return new DeviceAuth( $this->accessSettings('devToken') );
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
		$dvcRows = $devicesCheck->lstUserDevices($namedUserTxt);
		if( $dvcRows===false ){
			$this->terminateShell('The devices cannot be read');
		}
		foreach( $dvcRows as $dvcRow ){
			if( $dvcRow['devcName']===$userDevcName && $dvcRow['revkDate']===null ){
				return intval($dvcRow['id_device']);
			}
		}
		return null;
	}
}
