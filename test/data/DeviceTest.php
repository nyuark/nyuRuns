<?php

// Access\Auth\Device, the check of the device tokens : tokens checked against the `devices` table of
// the sample SQLite database, each device linked to a user of `users`.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Device', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Auth\Device as DeviceAuth;

createSampleDb( inTemporaryDir('device.sqlite') );
switchToSqlite( inTemporaryDir('device.sqlite') );
$sqlLink = new PDO('sqlite:'.inTemporaryDir('device.sqlite'));

// addDevice : only the SHA-256 hash is stored, a user can have several devices
$dvcAuth = new DeviceAuth( Array() );
$phonTkn = $dvcAuth->pushDeviceText('anna', 'Phone', 'app');
$homeTkn = $dvcAuth->pushDeviceText('anna', 'Home', 'home');
checkPregMatch('Device addDevice gives a 64 character token', $phonTkn, '/^[0-9a-f]{64}$/');
checkIdentical('Device the token itself is not stored', $sqlLink->query("SELECT bearerHash FROM devices WHERE deviceName='Phone'")->fetchColumn(), hash('sha256', strval($phonTkn)));
checkIdentical('Device addDevice refused for a pending user', $dvcAuth->pushDeviceText('cleo', 'Tablet', 'app'), false);
checkIdentical('Device addDevice refused for an unknown user', $dvcAuth->pushDeviceText('zed', 'Tablet', 'app'), false);

// checkCred : the identity of the user, with the device under 'device'
checkIdentical('Device token accepted, with the user and the device', $dvcAuth->checkSecretArr(Array('key'=>$phonTkn)), Array('userName'=>'anna', 'userRole'=>'sysadmin', 'idOfUser'=>1, 'device'=>Array('id'=>1, 'name'=>'Phone', 'role'=>'app')));
checkIdentical('Device second device of the same user', $dvcAuth->checkSecretArr(Array('key'=>$homeTkn))['device'] ?? null, Array('id'=>2, 'name'=>'Home', 'role'=>'home'));
checkIdentical('Device wrong token',          Array($dvcAuth->checkSecretArr(Array('key'=>str_repeat('0', 64))), $dvcAuth->finalErrorText()), Array(false, 'Invalid API key'));
checkIdentical('Device empty token',          Array($dvcAuth->checkSecretArr(Array('key'=>'')), $dvcAuth->finalErrorText()), Array(false, 'No API key provided'));
checkIdentical('Device the stored hash is not a token', $dvcAuth->checkSecretArr(Array('key'=>hash('sha256', strval($phonTkn)))), false);
$sqlLink->exec("UPDATE users SET userStatus='disabled' WHERE userHandle='anna'");
checkIdentical('Device of a locked user',     Array($dvcAuth->checkSecretArr(Array('key'=>$phonTkn)), $dvcAuth->finalErrorText()), Array(false, 'Invalid API key'));
$sqlLink->exec("UPDATE users SET userStatus='approved' WHERE userHandle='anna'");

// keepAlive : stamps the device verified last, not more often than 'seenSecs'
$newAuth = new DeviceAuth( Array() );
checkIdentical('Device keepAlive before any check', $newAuth->retainAliveFlg('anna'), false);
$dvcAuth->checkSecretArr(Array('key'=>$homeTkn));
checkIdentical('Device keepAlive stamps the verified device', $dvcAuth->retainAliveFlg('anna'), true);
checkIdentical('Device only that device is stamped', $sqlLink->query("SELECT deviceName FROM devices WHERE accessDate IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), Array('Home'));
checkIdentical('Device keepAlive does not stamp twice within the gap', $dvcAuth->retainAliveFlg('anna'), false);
$sqlLink->exec("UPDATE devices SET accessDate='".date('Y-m-d H:i:s', time()-120)."' WHERE deviceName='Home'");
checkIdentical('Device keepAlive stamps again once the gap is over', $dvcAuth->retainAliveFlg('anna'), true);
$evryReq = new DeviceAuth( Array('seenSecs'=>0) );
$evryReq->checkSecretArr(Array('key'=>$homeTkn));
checkIdentical('Device seenSecs 0 stamps on every request', Array($evryReq->retainAliveFlg('anna'), $evryReq->retainAliveFlg('anna')), Array(true, true));

// remDevice : the token is refused from then on, the row is kept
checkIdentical('Device remDevice revokes a device', $dvcAuth->remUserDevcFlg(1), true);
checkIdentical('Device revoked token',        Array($dvcAuth->checkSecretArr(Array('key'=>$phonTkn)), $dvcAuth->finalErrorText()), Array(false, 'Invalid API key'));
checkIdentical('Device the revoked row is kept', intval($sqlLink->query("SELECT COUNT(*) FROM devices WHERE revokeDate IS NOT NULL")->fetchColumn()), 1);
checkIdentical('Device remDevice twice, and on an unknown device', Array($dvcAuth->remUserDevcFlg(1), $dvcAuth->remUserDevcFlg(99)), Array(false, false));
checkIdentical('Device the other device still passes', $dvcAuth->checkSecretArr(Array('key'=>$homeTkn))['userName'] ?? null, 'anna');

// listDevcs : the devices with their user, by user then by name, never a hash
$dvcAuth->pushDeviceText('ben', 'Laptop', 'app');
$allDvcs = $dvcAuth->lstUserDevices();
checkIdentical('Device listDevcs lists every device, with its user', array_map(function(Array $deviceRow){ return $deviceRow['userHandle'].'/'.$deviceRow['deviceName'].'/'.$deviceRow['deviceRole']; }, (array)$allDvcs), Array('anna/Home/home', 'anna/Phone/app', 'ben/Laptop/app'));
checkIdentical('Device listDevcs gives no hash', array_keys($allDvcs[0] ?? Array()), Array('id_device', 'deviceName', 'deviceRole', 'accessDate', 'revokeDate', 'createDate', 'userHandle'));
checkIdentical('Device listDevcs of one user, of a user without any, of an unknown one', Array(array_column((array)$dvcAuth->lstUserDevices('ben'), 'deviceName'), $dvcAuth->lstUserDevices('dave'), $dvcAuth->lstUserDevices('zed')), Array(Array('Laptop'), Array(), Array()));
checkIdentical('Device listDevcs tells the revoked one', array_map(function(Array $deviceRow){ return $deviceRow['revokeDate']!==null; }, (array)$dvcAuth->lstUserDevices('anna')), Array(false, true));
// Tables that cannot be used
$noTable = new DeviceAuth( Array('useTable'=>'nodevices') );
checkIdentical('Device missing table',        runCatchThrown(function() use ($noTable, $homeTkn){ return Array($noTable->checkSecretArr(Array('key'=>$homeTkn)), $noTable->finalErrorText(), $noTable->pushDeviceText('anna', 'Tablet', 'app'), $noTable->remUserDevcFlg(2), $noTable->lstUserDevices()); }), Array(false, 'Credential store unavailable', false, false, false));
$badName = new DeviceAuth( Array('usersTbl'=>'users; DROP TABLE users') );
checkIdentical('Device unusable table name',  Array($badName->checkSecretArr(Array('key'=>$homeTkn)), $badName->finalErrorText()), Array(false, 'Credential store unavailable'));

// Checker : 'device' is a check of the framework, needing no plug-in, and the token travels as "Authorization: Bearer <token>"
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('device.sqlite')),
	'access'=>Array('TESTDVC'=>Array('seenSecs'=>0), 'TESTBEAR'=>Array('headName'=>'Authorization', 'authSchm'=>'Bearer'))
) );
$dvcConf = Array('required'=>true, 'sessWith'=>'apiHeadr', 'sessConf'=>'testbear', 'authWith'=>'devToken', 'authConf'=>'testdvc');
$sqlLink->exec("UPDATE devices SET accessDate=NULL");
$axsRslt = (new nyu\Access\Checker( $dvcConf, mockRequestObj(Array('Authorization'=>'Bearer '.$homeTkn)) ))->validateAccess();
checkIdentical('Checker device token: user, role and device role', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole'), $axsRslt->readValueByKey('identity')['device']['role'] ?? null), Array(200, 'anna', 'sysadmin', 'home'));
checkIdentical('Checker device token: the device is stamped', $sqlLink->query("SELECT deviceName FROM devices WHERE accessDate IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), Array('Home'));
$axsRslt = (new nyu\Access\Checker( $dvcConf, mockRequestObj(Array('Authorization'=>'Bearer '.$phonTkn)) ))->validateAccess();
checkIdentical('Checker device token: a revoked device is refused', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg')), Array(401, 'Invalid API key'));

// The devices of a removed user go with it : the framework's SQLite handler turns the foreign keys on
nyu\Data\DBCon::linkNamedDbCon('')->readConnection()->sendBuiltQuery('DELETE FROM users WHERE userHandle=?;', true, Array('ben'));
checkIdentical('Device the devices of a removed user go with it', intval($sqlLink->query("SELECT COUNT(*) FROM devices WHERE deviceName='Laptop'")->fetchColumn()), 0);
$sqlLink = null;

finishChecking();
