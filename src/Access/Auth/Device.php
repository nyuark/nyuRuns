<?php

namespace nyu\Access\Auth;

use nyu\Access\Checker;
use nyu\Data\DBCon;
use nyu\Data\DBHndlr\Handler as DbHandlr;
use nyu\Tools\Logger;

/**
 * Credential check of the device tokens, used as "authWith": "dvcToken".
 * Verifies a device token against a table of devices, each linked to a
 * user of the native userbase: the SHA-256 hash of the token must be the
 * bearerHash of a device that is not revoked, and its user must be
 * 'approved'. A device acts within its cap (deviceTops): its role is the
 * lower of the cap and its user's current role, so demoting the user
 * lowers all their devices at once; an empty cap, left by a device made
 * before the caps, is the user's role. A device never changes its user's
 * account (see Actionset::needsPasswdLgn). Also stamps the time each device
 * was last seen. A device is given a token, listed and revoked from the
 * command line (php nyuRun devices), or by an application of its own.
 */
class Device implements Handler{

	protected $usersStore;
	protected $deviceTble;
	protected $usersTable;
	protected $stampEvery;
	protected $seenDevice;
	protected $errMessage;
	protected $badTryFlag = false;

	/**
	 * @param Array $configValues Config block, optionally with the 'linkName' configured database holding both tables
	 *                          (defaults to the default database), the 'useTable' table of the devices (defaults to
	 *                          'devices'), the 'usersTbl' table of their users (defaults to 'users') and the 'seenSecs'
	 *                          time in seconds during which a device already seen is not stamped again (defaults to 60).
	 */
	function __construct( Array $configValues ){
		$this->usersStore = is_scalar($configValues['linkName'] ?? '') ? strval($configValues['linkName'] ?? '') : '';
		$dvceName = strval($configValues['useTable'] ?? 'devices');
		$userName = strval($configValues['usersTbl'] ?? 'users');
		$this->deviceTble = preg_match('/^\w+$/D', $dvceName) ? $dvceName : '';
		$this->usersTable = preg_match('/^\w+$/D', $userName) ? $userName : '';
		$this->stampEvery = max( 0, intval($configValues['seenSecs'] ?? 60) );
		$this->errMessage = '';
	}

	/**
	 * Verifies a device token. The identity is the one of the device's
	 * user, with the device itself under 'device'; its role is the lower of
	 * the device's cap and the user's current role. A device whose cap is
	 * not a role is refused as an invalid key, with a warning on 'access'.
	 *
	 * @param Array $loginDataArr Must contain a 'key' entry: the token, as sent by the client.
	 * @return Array|Bool An identity array ('userName', 'userRole', 'idOfUser', 'device') on success, or false on failure.
	 */
	function checkSecretArr( Array $loginDataArr ): Array|Bool {
		$givenTkn = strval($loginDataArr['key'] ?? '');
		$this->badTryFlag = false;
		if( $givenTkn=='' ){
			$this->errMessage = 'No API key provided';
			return false;
		}
		$dvceVals = $this->retrieveDevice( hash('sha256', $givenTkn) );
		if( $dvceVals===false ){
			$this->errMessage = 'Credential store unavailable';
			return false;
		}
		// A revoked device, or one whose user is not active, is answered like an unknown token
		if( $dvceVals===null || ($dvceVals['revokeDate'] ?? null)!==null || ($dvceVals['userStatus'] ?? '')!=='approved' ){
			$this->errMessage = 'Invalid API key';
			$this->badTryFlag = true;
			return false;
		}
		// The role is the lower of the cap and the user's current role; a cap that is not a role refuses the device, never falls back to the user's role
		$userRole = strval($dvceVals['accessRole'] ?? '');
		$devicTop = strval($dvceVals['deviceTops'] ?? '');
		if( $devicTop!=='' && !in_array($devicTop, Checker::ROLE_LIST, true) ){
			$this->errMessage = 'Invalid API key';
			Logger::retrieveLogger('access')->warning( 'Device '.json_encode(strval($dvceVals['deviceName'] ?? '')).' of user '.json_encode(strval($dvceVals['systemName'] ?? '')).' refused: its cap '.json_encode($devicTop).' is not a role' );
			return false;
		}
		if( $devicTop!=='' && array_search($devicTop, Checker::ROLE_LIST, true) < array_search($userRole, Checker::ROLE_LIST, true) ){
			$userRole = $devicTop;
		}
		$this->seenDevice = $dvceVals['id_device'];
		return Array(
			'userName'=>$dvceVals['systemName'], 'userRole'=>$userRole, 'idOfUser'=>$dvceVals['id_user'] ?? null,
			'device'=>Array('id'=>$dvceVals['id_device'], 'name'=>$dvceVals['deviceName'], 'role'=>$dvceVals['deviceRole'])
		);
	}

	/**
	 * Stamps the time the device verified by checkSecretArr() was last seen.
	 * Meant to be called on every authenticated request: the row is only
	 * written when its stamp is at least 'seenSecs' seconds old.
	 *
	 * @param String $namedUserTxt Login name of the device's user (not used: the device is the one just verified).
	 * @return Bool Whether the stamp was written.
	 */
	public function retainAliveFlg( String $namedUserTxt ): Bool {
		if( $this->seenDevice===null || $this->deviceTble=='' ){
			return false;
		}
		try{
			$dtbsHndr = $this->attachDatabase();
			$seenFild = $dtbsHndr->quoteFieldName('accessDate');
			$nowStamp = time();
			$updtQuer = 'UPDATE '.$dtbsHndr->quoteFieldName($this->deviceTble)." SET $seenFild='".date('Y-m-d H:i:s', $nowStamp)."'"
				.' WHERE '.$dtbsHndr->quoteFieldName('id_device')."=? AND ($seenFild IS NULL OR $seenFild<='".date('Y-m-d H:i:s', $nowStamp - $this->stampEvery)."');";
			return $dtbsHndr->sendBuiltQuery($updtQuer, true, Array($this->seenDevice)) && $dtbsHndr->countLastWrite()>0;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Gives an active user a new device. Only the hash of its token is
	 * stored: the token is returned once.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $userDevcName Name of the device.
	 * @param String $deviceRleTxt Role of the device, as the application understands it.
	 * @param String $roleTopsText The cap of the device, the highest role it acts with: one of Checker::ROLE_LIST.
	 * @return String|Bool The new token, or false when the cap is not a role, the user is unknown, not active, or the table cannot be written.
	 */
	public function pushDeviceText( String $namedUserTxt, String $userDevcName, String $deviceRleTxt, String $roleTopsText=Checker::ROLE_LIST[0] ): String|Bool {
		if( $this->deviceTble=='' || $this->usersTable=='' || !in_array($roleTopsText, Checker::ROLE_LIST, true) ){
			return false;
		}
		try{
			$dtbsHndr = $this->attachDatabase();
			$fieldLst = array_map( Array($dtbsHndr, 'quoteFieldName'), Array('deviceName', 'deviceRole', 'deviceTops', 'bearerHash', 'user_id') );
			$newToken = bin2hex(random_bytes(32));
			$addSttmt = 'INSERT INTO '.$dtbsHndr->quoteFieldName($this->deviceTble).' ('.implode(', ', $fieldLst).')'
				.' SELECT ?, ?, ?, ?, '.$dtbsHndr->quoteFieldName('id_user').' FROM '.$dtbsHndr->quoteFieldName($this->usersTable)
				.' WHERE '.$dtbsHndr->quoteFieldName('systemName').'=? AND '.$dtbsHndr->quoteFieldName('userStatus')."='approved';";
			$newArray = Array($userDevcName, $deviceRleTxt, $roleTopsText, hash('sha256', $newToken), $namedUserTxt);
			return ( $dtbsHndr->sendBuiltQuery($addSttmt, true, $newArray) && $dtbsHndr->countLastWrite()>0 ) ? $newToken : false;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Revokes a device: its token is refused from then on. The row is
	 * kept, with the time of the revocation.
	 *
	 * @param Int|String $devcIdentity Identifier of the device.
	 * @return Bool Whether the device was revoked: false when it is unknown, already revoked, or the table cannot be written.
	 */
	public function remUserDevcFlg(Int|String $devcIdentity ): Bool {
		if( $this->deviceTble=='' ){
			return false;
		}
		try{
			$dtbsHndr = $this->attachDatabase();
			$revokFld = $dtbsHndr->quoteFieldName('revokeDate');
			$updtQuer = 'UPDATE '.$dtbsHndr->quoteFieldName($this->deviceTble)." SET $revokFld='".date('Y-m-d H:i:s')."'"
				.' WHERE '.$dtbsHndr->quoteFieldName('id_device')."=? AND $revokFld IS NULL;";
			return $dtbsHndr->sendBuiltQuery($updtQuer, true, Array($devcIdentity)) && $dtbsHndr->countLastWrite()>0;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Gives the role of an active user of the devices' users table: the
	 * ceiling a device of that user can be given.
	 *
	 * @param String $namedUserTxt Login name of the user.
	 * @return ?String The role, or null when the user is unknown, not active, or the table cannot be read.
	 */
	public function userRoleString( String $namedUserTxt ): ?String {
		if( $this->usersTable=='' ){
			return null;
		}
		try{
			$dtbsHndr = $this->attachDatabase();
			$roleQuer = 'SELECT '.$dtbsHndr->quoteFieldName('accessRole').' FROM '.$dtbsHndr->quoteFieldName($this->usersTable)
				.' WHERE '.$dtbsHndr->quoteFieldName('systemName').'=? AND '.$dtbsHndr->quoteFieldName('userStatus')."='approved' LIMIT 1;";
			if( !$dtbsHndr->sendBuiltQuery($roleQuer, false, Array($namedUserTxt)) ){
				return null;
			}
			$userNtry = $dtbsHndr->readLastResult(true);
		}catch( \Throwable $e ){
			return null;
		}
		return ( is_array($userNtry) && isset($userNtry[0]['accessRole']) ) ? strval($userNtry[0]['accessRole']) : null;
	}

	/**
	 * Lists the devices, with the name of their user: those of one user,
	 * or all of them. The hash of a token is never given.
	 *
	 * @param String $namedUserTxt Login name of the user, '' for the devices of every user.
	 * @return Array|Bool The devices, by user then by name: 'id_device', 'deviceName', 'deviceRole', 'deviceTops', 'accessDate', 'revokeDate', 'createDate', and of their user 'systemName' and 'accessRole'; false when the tables cannot be read.
	 */
	public function lstUserDevices( String $namedUserTxt='' ): Array|Bool {
		if( $this->deviceTble=='' || $this->usersTable=='' ){
			return false;
		}
		try{
			$dtbsHndr = $this->attachDatabase();
			$fieldLst = Array();
			foreach( Array('id_device', 'deviceName', 'deviceRole', 'deviceTops', 'accessDate', 'revokeDate', 'createDate') as $fldNme ){
				$fieldLst[] = $dtbsHndr->quoteFieldName($this->deviceTble.'.'.$fldNme);
			}
			$fieldLst[] = $dtbsHndr->quoteFieldName($this->usersTable.'.systemName');
			$fieldLst[] = $dtbsHndr->quoteFieldName($this->usersTable.'.accessRole');
			$userName = $dtbsHndr->quoteFieldName($this->usersTable.'.systemName');
			$listQuer = 'SELECT '.implode(', ', $fieldLst).' FROM '.$dtbsHndr->quoteFieldName($this->deviceTble)
				.' INNER JOIN '.$dtbsHndr->quoteFieldName($this->usersTable)
				.' ON '.$dtbsHndr->quoteFieldName($this->usersTable.'.id_user').'='.$dtbsHndr->quoteFieldName($this->deviceTble.'.user_id')
				.(($namedUserTxt!=='') ? ' WHERE '.$userName.'=?' : '')
				.' ORDER BY '.$userName.', '.$dtbsHndr->quoteFieldName($this->deviceTble.'.deviceName').', '.$dtbsHndr->quoteFieldName($this->deviceTble.'.id_device').';';
			if( !$dtbsHndr->sendBuiltQuery($listQuer, false, ($namedUserTxt!=='') ? Array($namedUserTxt) : Array()) ){
				return false;
			}
			$dvceNtry = $dtbsHndr->readLastResult(true);
		}catch( \Throwable $e ){
			return false;
		}
		return is_array($dvceNtry) ? $dvceNtry : false;
	}

	/**
	 * Reads a device and its user by the hash of the device's token.
	 *
	 * @param String $hashedTknTxt SHA-256 hash of the token.
	 * @return Array|Bool|Null The device and user values, null when there is none, or false when the tables cannot be read.
	 */
	protected function retrieveDevice( String $hashedTknTxt ): Array|Bool|Null {
		if( $this->deviceTble=='' || $this->usersTable=='' ){
			return false;
		}
		try{
			$dtbsHndr = $this->attachDatabase();
			$fieldLst = Array();
			foreach( Array('id_device', 'deviceName', 'deviceRole', 'deviceTops', 'revokeDate') as $fldNme ){
				$fieldLst[] = $dtbsHndr->quoteFieldName($this->deviceTble.'.'.$fldNme);
			}
			foreach( Array('id_user', 'systemName', 'accessRole', 'userStatus') as $fldNme ){
				$fieldLst[] = $dtbsHndr->quoteFieldName($this->usersTable.'.'.$fldNme);
			}
			$dvceQuer = 'SELECT '.implode(', ', $fieldLst).' FROM '.$dtbsHndr->quoteFieldName($this->deviceTble)
				.' INNER JOIN '.$dtbsHndr->quoteFieldName($this->usersTable)
				.' ON '.$dtbsHndr->quoteFieldName($this->usersTable.'.id_user').'='.$dtbsHndr->quoteFieldName($this->deviceTble.'.user_id')
				.' WHERE '.$dtbsHndr->quoteFieldName($this->deviceTble.'.bearerHash').'=? LIMIT 1;';
			if( !$dtbsHndr->sendBuiltQuery($dvceQuer, false, Array($hashedTknTxt)) ){
				return false;
			}
			$dvceNtry = $dtbsHndr->readLastResult(true);
		}catch( \Throwable $e ){
			return false;
		}
		if( !is_array($dvceNtry) ){
			return false;
		}
		return empty($dvceNtry) ? null : $dvceNtry[0];
	}

	/**
	 * Returns the handler of the database holding the devices and their
	 * users: the one named by 'linkName', or the default database.
	 *
	 * @return DbHandlr The database handler.
	 *
	 * @throws \RuntimeException When that database is not configured.
	 */
	protected function attachDatabase(): DbHandlr {
		return DBCon::linkNamedDbCon($this->usersStore)->readConnection();
	}

	/**
	 * Tells whether the last refusal was a wrong token, as opposed to a
	 * missing one or tables that cannot be read: only a wrong credential
	 * counts against the address that presented it (see Access\Limiter).
	 *
	 * @return Bool True when a token was presented and refused.
	 */
	public function hasWrongSecret(): Bool {
		return $this->badTryFlag;
	}

	/**
	 * @return String The last error message.
	 */
	function finalErrorText(): String {
		return $this->errMessage;
	}
}
