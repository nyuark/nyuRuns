<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Access\Auth\Device as DeviceAuth;

/**
 * Manages the devices: the machines allowed in with a token of their
 * own, each in the name of a user of the native userbase (see
 * Access\Auth\Device):
 *
 *   php nyuRun device token home anna home    gives a device a token, with its role: shown once
 *   php nyuRun device lists anna              lists the devices, of a user or of every user
 *   php nyuRun device revok home anna         revokes the device of that name of a user
 *
 * The role is the one the application gives its devices: the framework
 * knows none. Only the hash of a token is stored; a revoked device is
 * kept in the list, with the time of its revocation. The settings are
 * those of the 'device' block of conf/access.json. Every change is
 * traced on the 'shell' log channel.
 */
class Device extends Command{

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Manages the devices working in the name of a user: their tokens, the list, the revocation';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		return Array(
			'token'=>Array('args'=>'<name> <user> <role>', 'about'=>'gives a device a token, in the name of an active user: shown once'),
			'lists'=>Array('args'=>'[user]', 'about'=>'lists the devices, of a user or of every user'),
			'revok'=>Array('args'=>'<name> <user>', 'about'=>'revokes the device of that name of a user: its token is refused from then on')
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
	public function callToken(): Int {
		$dvcName = $this->needsWord('the name of the device');
		$usrName = $this->needsWord('the user');
		$dvcRole = $this->needsWord('the role of the device');
		if( !preg_match('/^[a-z][a-z0-9]{0,7}$/D', $dvcRole) ){
			$this->stopShell('Invalid role: a lower-case word of 8 characters at most', self::EXITUSGE);
		}
		$dvcAuth = $this->deviceChk();
		if( $this->findDevce($dvcAuth, $dvcName, $usrName)!==null ){
			$this->stopShell('No token made: the user already has a device named '.json_encode($dvcName).' (device revok it first)', self::EXITDENY);
		}
		$newTokn = $dvcAuth->addDevice($usrName, $dvcName, $dvcRole);
		if( $newTokn===false ){
			$this->stopShell('No token made: the user is unknown or not active, or the devices cannot be written');
		}
		$this->traceDone( 'Device: token '.json_encode($dvcName).' ('.$dvcRole.') made for user '.json_encode($usrName) );
		$this->prntObjc->printPair('token made', $dvcName);
		$this->prntObjc->printOnce('Token of '.$dvcName, strval($newTokn));
		return self::EXITDONE;
	}

	/**
	 * Lists the devices, of the user the command names or of every user:
	 * name, user, role, when it was made, last seen and revoked.
	 *
	 * @return Int The exit code.
	 */
	public function callLists(): Int {
		$usrName = strval($this->optnObjc->shiftWord() ?? '');
		$dvcRows = $this->deviceChk()->listDevcs($usrName);
		if( $dvcRows===false ){
			$this->stopShell('The devices cannot be read');
		}
		$rowList = Array();
		foreach( $dvcRows as $dvcRow ){
			$rowList[] = Array(strval($dvcRow['name']), strval($dvcRow['userName']), strval($dvcRow['role']), strval($dvcRow['created_at']), strval($dvcRow['last_seen_at'] ?? ''), strval($dvcRow['revoked_at'] ?? ''));
		}
		$this->prntObjc->printRows( Array('device', 'user', 'role', 'made', 'last seen', 'revoked'), $rowList );
		return self::EXITDONE;
	}

	/**
	 * Revokes the device of a user the command names: its token is
	 * refused from then on.
	 *
	 * @return Int The exit code.
	 */
	public function callRevok(): Int {
		$dvcName = $this->needsWord('the name of the device');
		$usrName = $this->needsWord('the user');
		$dvcAuth = $this->deviceChk();
		$dvcIdnt = $this->findDevce($dvcAuth, $dvcName, $usrName);
		if( $dvcIdnt===null ){
			$this->stopShell('Nothing revoked: the user has no device named '.json_encode($dvcName).' still standing', self::EXITDENY);
		}
		if( !$dvcAuth->remDevice($dvcIdnt) ){
			$this->stopShell('The device cannot be revoked: the devices cannot be written');
		}
		$this->traceDone( 'Device: '.json_encode($dvcName).' of user '.json_encode($usrName).' revoked' );
		$this->prntObjc->printPair('device revoked', $dvcName);
		return self::EXITDONE;
	}

	/**
	 * Gives the check of the devices, from the 'device' block of the
	 * ACCESS config.
	 *
	 * @return DeviceAuth The check.
	 */
	protected function deviceChk(): DeviceAuth {
		return new DeviceAuth( $this->accessCnf('device') );
	}

	/**
	 * Finds the device of a user that bears a name and is not revoked.
	 *
	 * @param DeviceAuth $deviceAuth The check of the devices.
	 * @param String     $deviceName Name of the device.
	 * @param String     $userString Name of the user.
	 * @return ?Int The identifier of the device, null when there is none.
	 */
	protected function findDevce( DeviceAuth $deviceAuth, String $deviceName, String $userString ): ?Int {
		$dvcRows = $deviceAuth->listDevcs($userString);
		if( $dvcRows===false ){
			$this->stopShell('The devices cannot be read');
		}
		foreach( $dvcRows as $dvcRow ){
			if( $dvcRow['name']===$deviceName && $dvcRow['revoked_at']===null ){
				return intval($dvcRow['id']);
			}
		}
		return null;
	}
}
