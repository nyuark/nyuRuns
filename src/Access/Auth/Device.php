<?php

namespace nyu\Access\Auth;

use nyu\Data\DBCon;
use nyu\Data\DBHndlr\Handler as DbHandlr;

/**
 * Verifies a device token against a table of devices, each linked to a
 * user of the native userbase: the SHA-256 hash of the token must be the
 * token_hash of a device that is not revoked, and its user must be
 * 'active'. Also stamps the time each device was last seen.
 */
class Device implements Handler{

	protected $userBase;
	protected $dvcTable;
	protected $usrTable;
	protected $stmpSecs;
	protected $seenDevc;
	protected $lastMssg;

	/**
	 * @param Array $confValues Config block, optionally with the 'basnme' configured database holding both tables
	 *                          (defaults to the default database), the 'tblnme' table of the devices (defaults to
	 *                          'devices'), the 'usrtbl' table of their users (defaults to 'users') and the 'stmptm'
	 *                          time in seconds during which a device already seen is not stamped again (defaults to 60).
	 */
	function __construct( Array $confValues ){
		$this->userBase = is_scalar($confValues['basnme'] ?? '') ? strval($confValues['basnme'] ?? '') : '';
		$dvcName = strval($confValues['tblnme'] ?? 'devices');
		$usrName = strval($confValues['usrtbl'] ?? 'users');
		$this->dvcTable = preg_match('/^\w+$/D', $dvcName) ? $dvcName : '';
		$this->usrTable = preg_match('/^\w+$/D', $usrName) ? $usrName : '';
		$this->stmpSecs = max( 0, intval($confValues['stmptm'] ?? 60) );
		$this->lastMssg = '';
	}

	/**
	 * Verifies a device token. The identity is the one of the device's
	 * user, with the device itself under 'device'.
	 *
	 * @param Array $credsArray Must contain a 'key' entry: the token, as sent by the client.
	 * @return Array|Bool An identity array ('user', 'role', 'id', 'device') on success, or false on failure.
	 */
	function checkCred( Array $credsArray ): Array|Bool {
		$gvnTokn = strval($credsArray['key'] ?? '');
		if( $gvnTokn=='' ){
			$this->lastMssg = 'No API key provided';
			return false;
		}
		$dvcVals = $this->fetchDevc( hash('sha256', $gvnTokn) );
		if( $dvcVals===false ){
			$this->lastMssg = 'Credential store unavailable';
			return false;
		}
		// A revoked device, or one whose user is not active, is answered like an unknown token
		if( $dvcVals===null || ($dvcVals['revoked_at'] ?? null)!==null || ($dvcVals['userStat'] ?? '')!=='active' ){
			$this->lastMssg = 'Invalid API key';
			return false;
		}
		$this->seenDevc = $dvcVals['id'];
		return Array(
			'user'=>$dvcVals['userName'], 'role'=>strval($dvcVals['userRole'] ?? ''), 'id'=>$dvcVals['id_user'] ?? null,
			'device'=>Array('id'=>$dvcVals['id'], 'name'=>$dvcVals['name'], 'role'=>$dvcVals['role'])
		);
	}

	/**
	 * Stamps the time the device verified by checkCred() was last seen.
	 * Meant to be called on every authenticated request: the row is only
	 * written when its stamp is at least 'stmptm' seconds old.
	 *
	 * @param String $userString Login name of the device's user (not used: the device is the one just verified).
	 * @return Bool Whether the stamp was written.
	 */
	public function keepAlive( String $userString ): Bool {
		if( $this->seenDevc===null || $this->dvcTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$seenFld = $dbHandl->quoteName('last_seen_at');
			$nowTime = time();
			$updQury = 'UPDATE '.$dbHandl->quoteName($this->dvcTable)." SET $seenFld='".date('Y-m-d H:i:s', $nowTime)."'"
				.' WHERE '.$dbHandl->quoteName('id')."=? AND ($seenFld IS NULL OR $seenFld<='".date('Y-m-d H:i:s', $nowTime - $this->stmpSecs)."');";
			return $dbHandl->sendQuery($updQury, true, Array($this->seenDevc)) && $dbHandl->lastCount()>0;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Gives an active user a new device. Only the hash of its token is
	 * stored: the token is returned once.
	 *
	 * @param String $userString Login name of the user.
	 * @param String $deviceName Name of the device.
	 * @param String $deviceRole Role of the device, as the application understands it.
	 * @return String|Bool The new token, or false when the user is unknown, not active, or the table cannot be written.
	 */
	public function addDevice( String $userString, String $deviceName, String $deviceRole ): String|Bool {
		if( $this->dvcTable=='' || $this->usrTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$fldList = array_map( Array($dbHandl, 'quoteName'), Array('name', 'role', 'token_hash', 'user_id') );
			$newTokn = bin2hex(random_bytes(32));
			$addQury = 'INSERT INTO '.$dbHandl->quoteName($this->dvcTable).' ('.implode(', ', $fldList).')'
				.' SELECT ?, ?, ?, '.$dbHandl->quoteName('id_user').' FROM '.$dbHandl->quoteName($this->usrTable)
				.' WHERE '.$dbHandl->quoteName('userName').'=? AND '.$dbHandl->quoteName('userStat')."='active';";
			$addVals = Array($deviceName, $deviceRole, hash('sha256', $newTokn), $userString);
			return ( $dbHandl->sendQuery($addQury, true, $addVals) && $dbHandl->lastCount()>0 ) ? $newTokn : false;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Revokes a device: its token is refused from then on. The row is
	 * kept, with the time of the revocation.
	 *
	 * @param Int|String $deviceIdnt Identifier of the device.
	 * @return Bool Whether the device was revoked: false when it is unknown, already revoked, or the table cannot be written.
	 */
	public function remDevice(Int|String $deviceIdnt ): Bool {
		if( $this->dvcTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$revkFld = $dbHandl->quoteName('revoked_at');
			$updQury = 'UPDATE '.$dbHandl->quoteName($this->dvcTable)." SET $revkFld='".date('Y-m-d H:i:s')."'"
				.' WHERE '.$dbHandl->quoteName('id')."=? AND $revkFld IS NULL;";
			return $dbHandl->sendQuery($updQury, true, Array($deviceIdnt)) && $dbHandl->lastCount()>0;
		}catch( \Throwable $e ){
			return false;
		}
	}

	/**
	 * Reads a device and its user by the hash of the device's token.
	 *
	 * @param String $hashedTokn SHA-256 hash of the token.
	 * @return Array|Bool|Null The device and user values, null when there is none, or false when the tables cannot be read.
	 */
	protected function fetchDevc( String $hashedTokn ): Array|Bool|Null {
		if( $this->dvcTable=='' || $this->usrTable=='' ){
			return false;
		}
		try{
			$dbHandl = $this->linkDBase();
			$fldList = Array();
			foreach( Array('id', 'name', 'role', 'revoked_at') as $fldNme ){
				$fldList[] = $dbHandl->quoteName($this->dvcTable.'.'.$fldNme);
			}
			foreach( Array('id_user', 'userName', 'userRole', 'userStat') as $fldNme ){
				$fldList[] = $dbHandl->quoteName($this->usrTable.'.'.$fldNme);
			}
			$dvcQury = 'SELECT '.implode(', ', $fldList).' FROM '.$dbHandl->quoteName($this->dvcTable)
				.' INNER JOIN '.$dbHandl->quoteName($this->usrTable)
				.' ON '.$dbHandl->quoteName($this->usrTable.'.id_user').'='.$dbHandl->quoteName($this->dvcTable.'.user_id')
				.' WHERE '.$dbHandl->quoteName($this->dvcTable.'.token_hash').'=? LIMIT 1;';
			if( !$dbHandl->sendQuery($dvcQury, false, Array($hashedTokn)) ){
				return false;
			}
			$dvcRows = $dbHandl->readQuery(true);
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
	 * users: the one named by 'basnme', or the default database.
	 *
	 * @return DbHandlr The database handler.
	 *
	 * @throws \RuntimeException When that database is not configured.
	 */
	protected function linkDBase(): DbHandlr {
		return DBCon::linkNamed($this->userBase)->getConnex();
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
