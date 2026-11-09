<?php

namespace nyu\Access\Auth;

use nyu\Data\DBCon;
use nyu\Data\DBHndlr\Handler as DbHandlr;

/**
 * Credential check of the device tokens, used as "authWith": "device".
 * Verifies a device token against a table of devices, each linked to a
 * user of the native userbase: the SHA-256 hash of the token must be the
 * bearerHash of a device that is not revoked, and its user must be
 * 'approved'. Also stamps the time each device was last seen. A device is
 * given a token, listed and revoked from the command line (php nyuRun
 * device), or by an application of its own.
 */
class Device implements Handler{

	protected $usersStore;
	protected $deviceTble;
	protected $usersTable;
	protected $stampEvery;
	protected $seenDevice;
	protected $lastMessag;
	protected $invalidCrd = false;

	/**
	 * @param Array $configValues Config block, optionally with the 'linkName' configured database holding both tables
	 *                          (defaults to the default database), the 'useTable' table of the devices (defaults to
	 *                          'devices'), the 'usersTbl' table of their users (defaults to 'users') and the 'stmpSecs'
	 *                          time in seconds during which a device already seen is not stamped again (defaults to 60).
	 */
	function __construct( Array $configValues ){
		$this->usersStore = is_scalar($configValues['linkName'] ?? '') ? strval($configValues['linkName'] ?? '') : '';
		$devcNme = strval($configValues['useTable'] ?? 'devices');
		$usrName = strval($configValues['usersTbl'] ?? 'users');
		$this->deviceTble = preg_match('/^\w+$/D', $devcNme) ? $devcNme : '';
		$this->usersTable = preg_match('/^\w+$/D', $usrName) ? $usrName : '';
		$this->stampEvery = max( 0, intval($configValues['stmpSecs'] ?? 60) );
		$this->lastMessag = '';
	}

	/**
	 * Verifies a device token. The identity is the one of the device's
	 * user, with the device itself under 'device'.
	 *
	 * @param Array $crdntlsArray Must contain a 'key' entry: the token, as sent by the client.
	 * @return Array|Bool An identity array ('user', 'role', 'id', 'device') on success, or false on failure.
	 */
	function chckCredential( Array $crdntlsArray ): Array|Bool {
		$gvnTokn = strval($crdntlsArray['key'] ?? '');
		$this->invalidCrd = false;
		if( $gvnTokn=='' ){
			$this->lastMessag = 'No API key provided';
			return false;
		}
		$devcVls = $this->retrieveDevice( hash('sha256', $gvnTokn) );
		if( $devcVls===false ){
			$this->lastMessag = 'Credential store unavailable';
			return false;
		}
		// A revoked device, or one whose user is not active, is answered like an unknown token
		if( $devcVls===null || ($devcVls['revokeDate'] ?? null)!==null || ($devcVls['userStatus'] ?? '')!=='approved' ){
			$this->lastMessag = 'Invalid API key';
			$this->invalidCrd = true;
			return false;
		}
		$this->seenDevice = $devcVls['id_device'];
		return Array(
			'user'=>$devcVls['userHandle'], 'role'=>strval($devcVls['accessRole'] ?? ''), 'id'=>$devcVls['id_user'] ?? null,
			'device'=>Array('id'=>$devcVls['id_device'], 'name'=>$devcVls['deviceName'], 'role'=>$devcVls['deviceRole'])
		);
	}

	/**
	 * Stamps the time the device verified by chckCredential() was last seen.
	 * Meant to be called on every authenticated request: the row is only
	 * written when its stamp is at least 'stmpSecs' seconds old.
	 *
	 * @param String $namedUserTxt Login name of the device's user (not used: the device is the one just verified).
	 * @return Bool Whether the stamp was written.
	 */
	public function retainAliveFlg( String $namedUserTxt ): Bool {
		if( $this->seenDevice===null || $this->deviceTble=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$seenFld = $dbHndlr->quoteFieldName('accessDate');
			$nowTime = time();
			$updtQry = 'UPDATE '.$dbHndlr->quoteFieldName($this->deviceTble)." SET $seenFld='".date('Y-m-d H:i:s', $nowTime)."'"
				.' WHERE '.$dbHndlr->quoteFieldName('id_device')."=? AND ($seenFld IS NULL OR $seenFld<='".date('Y-m-d H:i:s', $nowTime - $this->stampEvery)."');";
			return $dbHndlr->sendBuiltQuery($updtQry, true, Array($this->seenDevice)) && $dbHndlr->countLastWrite()>0;
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
	 * @return String|Bool The new token, or false when the user is unknown, not active, or the table cannot be written.
	 */
	public function pushDeviceText( String $namedUserTxt, String $userDevcName, String $deviceRleTxt ): String|Bool {
		if( $this->deviceTble=='' || $this->usersTable=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$fldList = array_map( Array($dbHndlr, 'quoteFieldName'), Array('deviceName', 'deviceRole', 'bearerHash', 'user_id') );
			$newTokn = bin2hex(random_bytes(32));
			$addStmt = 'INSERT INTO '.$dbHndlr->quoteFieldName($this->deviceTble).' ('.implode(', ', $fldList).')'
				.' SELECT ?, ?, ?, '.$dbHndlr->quoteFieldName('id_user').' FROM '.$dbHndlr->quoteFieldName($this->usersTable)
				.' WHERE '.$dbHndlr->quoteFieldName('userHandle').'=? AND '.$dbHndlr->quoteFieldName('userStatus')."='approved';";
			$newData = Array($userDevcName, $deviceRleTxt, hash('sha256', $newTokn), $namedUserTxt);
			return ( $dbHndlr->sendBuiltQuery($addStmt, true, $newData) && $dbHndlr->countLastWrite()>0 ) ? $newTokn : false;
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
			$dbHndlr = $this->attachDatabase();
			$revkFld = $dbHndlr->quoteFieldName('revokeDate');
			$updtQry = 'UPDATE '.$dbHndlr->quoteFieldName($this->deviceTble)." SET $revkFld='".date('Y-m-d H:i:s')."'"
				.' WHERE '.$dbHndlr->quoteFieldName('id_device')."=? AND $revkFld IS NULL;";
			return $dbHndlr->sendBuiltQuery($updtQry, true, Array($devcIdentity)) && $dbHndlr->countLastWrite()>0;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Lists the devices, with the name of their user: those of one user,
	 * or all of them. The hash of a token is never given.
	 *
	 * @param String $namedUserTxt Login name of the user, '' for the devices of every user.
	 * @return Array|Bool The devices, by user then by name: 'id_device', 'deviceName', 'deviceRole', 'accessDate', 'revokeDate', 'createDate' and 'userHandle'; false when the tables cannot be read.
	 */
	public function lstUserDevices( String $namedUserTxt='' ): Array|Bool {
		if( $this->deviceTble=='' || $this->usersTable=='' ){
			return false;
		}
		try{
			$dbHndlr = $this->attachDatabase();
			$fldList = Array();
			foreach( Array('id_device', 'deviceName', 'deviceRole', 'accessDate', 'revokeDate', 'createDate') as $fldNme ){
				$fldList[] = $dbHndlr->quoteFieldName($this->deviceTble.'.'.$fldNme);
			}
			$fldList[] = $dbHndlr->quoteFieldName($this->usersTable.'.userHandle');
			$usrName = $dbHndlr->quoteFieldName($this->usersTable.'.userHandle');
			$listQry = 'SELECT '.implode(', ', $fldList).' FROM '.$dbHndlr->quoteFieldName($this->deviceTble)
				.' INNER JOIN '.$dbHndlr->quoteFieldName($this->usersTable)
				.' ON '.$dbHndlr->quoteFieldName($this->usersTable.'.id_user').'='.$dbHndlr->quoteFieldName($this->deviceTble.'.user_id')
				.(($namedUserTxt!=='') ? ' WHERE '.$usrName.'=?' : '')
				.' ORDER BY '.$usrName.', '.$dbHndlr->quoteFieldName($this->deviceTble.'.deviceName').', '.$dbHndlr->quoteFieldName($this->deviceTble.'.id_device').';';
			if( !$dbHndlr->sendBuiltQuery($listQry, false, ($namedUserTxt!=='') ? Array($namedUserTxt) : Array()) ){
				return false;
			}
			$dvcRows = $dbHndlr->readLastResult(true);
		}catch( \Throwable $e ){
			return false;
		}
		return is_array($dvcRows) ? $dvcRows : false;
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
			$dbHndlr = $this->attachDatabase();
			$fldList = Array();
			foreach( Array('id_device', 'deviceName', 'deviceRole', 'revokeDate') as $fldNme ){
				$fldList[] = $dbHndlr->quoteFieldName($this->deviceTble.'.'.$fldNme);
			}
			foreach( Array('id_user', 'userHandle', 'accessRole', 'userStatus') as $fldNme ){
				$fldList[] = $dbHndlr->quoteFieldName($this->usersTable.'.'.$fldNme);
			}
			$devcQry = 'SELECT '.implode(', ', $fldList).' FROM '.$dbHndlr->quoteFieldName($this->deviceTble)
				.' INNER JOIN '.$dbHndlr->quoteFieldName($this->usersTable)
				.' ON '.$dbHndlr->quoteFieldName($this->usersTable.'.id_user').'='.$dbHndlr->quoteFieldName($this->deviceTble.'.user_id')
				.' WHERE '.$dbHndlr->quoteFieldName($this->deviceTble.'.bearerHash').'=? LIMIT 1;';
			if( !$dbHndlr->sendBuiltQuery($devcQry, false, Array($hashedTknTxt)) ){
				return false;
			}
			$dvcRows = $dbHndlr->readLastResult(true);
		}catch( \Throwable $e ){
			return false;
		}
		if( !is_array($dvcRows) ){
			return false;
		}
		return empty($dvcRows) ? null : $dvcRows[0];
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
	public function wrngCredential(): Bool {
		return $this->invalidCrd;
	}

	/**
	 * @return String The last error message.
	 */
	function finalErrorText(): String {
		return $this->lastMessag;
	}
}
