<?php

// Runs\Shared\DeviceAuth, the device tokens plug-in : tokens checked against the `devices` table of
// the sample SQLite database, each device linked to a user of `users`.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require RUN_DIR.DS.'Shared'.DS.'DeviceAuth.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Device', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Runs\Shared\DeviceAuth;

buildData( inTempDir('device.sqlite') );
useSqlite( inTempDir('device.sqlite') );
$sqlLink = new PDO('sqlite:'.inTempDir('device.sqlite'));

// addDevice : only the SHA-256 hash is stored, a user can have several devices
$dvcAuth = new DeviceAuth( Array() );
$phonTkn = $dvcAuth->addDevice('anna', 'Phone', 'app');
$homeTkn = $dvcAuth->addDevice('anna', 'Home', 'home');
checkLike('Device addDevice gives a 64 character token', $phonTkn, '/^[0-9a-f]{64}$/');
checkSame('Device the token itself is not stored', $sqlLink->query("SELECT token_hash FROM devices WHERE name='Phone'")->fetchColumn(), hash('sha256', strval($phonTkn)));
checkSame('Device addDevice refused for a pending user', $dvcAuth->addDevice('cleo', 'Tablet', 'app'), false);
checkSame('Device addDevice refused for an unknown user', $dvcAuth->addDevice('zed', 'Tablet', 'app'), false);

// checkCred : the identity of the user, with the device under 'device'
checkSame('Device token accepted, with the user and the device', $dvcAuth->checkCred(Array('key'=>$phonTkn)), Array('user'=>'anna', 'role'=>'admin', 'id'=>1, 'device'=>Array('id'=>1, 'name'=>'Phone', 'role'=>'app')));
checkSame('Device second device of the same user', $dvcAuth->checkCred(Array('key'=>$homeTkn))['device'] ?? null, Array('id'=>2, 'name'=>'Home', 'role'=>'home'));
checkSame('Device wrong token',          Array($dvcAuth->checkCred(Array('key'=>str_repeat('0', 64))), $dvcAuth->lastError()), Array(false, 'Invalid API key'));
checkSame('Device empty token',          Array($dvcAuth->checkCred(Array('key'=>'')), $dvcAuth->lastError()), Array(false, 'No API key provided'));
checkSame('Device the stored hash is not a token', $dvcAuth->checkCred(Array('key'=>hash('sha256', strval($phonTkn)))), false);
$sqlLink->exec("UPDATE users SET userStat='locked' WHERE userName='anna'");
checkSame('Device of a locked user',     Array($dvcAuth->checkCred(Array('key'=>$phonTkn)), $dvcAuth->lastError()), Array(false, 'Invalid API key'));
$sqlLink->exec("UPDATE users SET userStat='active' WHERE userName='anna'");

// keepAlive : stamps the device verified last, not more often than 'stmptm'
$newAuth = new DeviceAuth( Array() );
checkSame('Device keepAlive before any check', $newAuth->keepAlive('anna'), false);
$dvcAuth->checkCred(Array('key'=>$homeTkn));
checkSame('Device keepAlive stamps the verified device', $dvcAuth->keepAlive('anna'), true);
checkSame('Device only that device is stamped', $sqlLink->query("SELECT name FROM devices WHERE last_seen_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), Array('Home'));
checkSame('Device keepAlive does not stamp twice within the gap', $dvcAuth->keepAlive('anna'), false);
$sqlLink->exec("UPDATE devices SET last_seen_at='".date('Y-m-d H:i:s', time()-120)."' WHERE name='Home'");
checkSame('Device keepAlive stamps again once the gap is over', $dvcAuth->keepAlive('anna'), true);
$evryReq = new DeviceAuth( Array('stmptm'=>0) );
$evryReq->checkCred(Array('key'=>$homeTkn));
checkSame('Device stmptm 0 stamps on every request', Array($evryReq->keepAlive('anna'), $evryReq->keepAlive('anna')), Array(true, true));

// remDevice : the token is refused from then on, the row is kept
checkSame('Device remDevice revokes a device', $dvcAuth->remDevice(1), true);
checkSame('Device revoked token',        Array($dvcAuth->checkCred(Array('key'=>$phonTkn)), $dvcAuth->lastError()), Array(false, 'Invalid API key'));
checkSame('Device the revoked row is kept', intval($sqlLink->query("SELECT COUNT(*) FROM devices WHERE revoked_at IS NOT NULL")->fetchColumn()), 1);
checkSame('Device remDevice twice, and on an unknown device', Array($dvcAuth->remDevice(1), $dvcAuth->remDevice(99)), Array(false, false));
checkSame('Device the other device still passes', $dvcAuth->checkCred(Array('key'=>$homeTkn))['user'] ?? null, 'anna');

// Tables that cannot be used
$noTable = new DeviceAuth( Array('tblnme'=>'nodevices') );
checkSame('Device missing table',        runSafely(function() use ($noTable, $homeTkn){ return Array($noTable->checkCred(Array('key'=>$homeTkn)), $noTable->lastError(), $noTable->addDevice('anna', 'Tablet', 'app'), $noTable->remDevice(2)); }), Array(false, 'Credential store unavailable', false, false));
$badName = new DeviceAuth( Array('usrtbl'=>'users; DROP TABLE users') );
checkSame('Device unusable table name',  Array($badName->checkCred(Array('key'=>$homeTkn)), $badName->lastError()), Array(false, 'Credential store unavailable'));

// Checker : 'device' is resolved as a plug-in of runs/Shared, and the token travels as "Authorization: Bearer <token>"
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('device.sqlite')),
	'access'=>Array('PLUGIN'=>Array('authby'=>Array('device'), 'sessby'=>Array()), 'TESTDVC'=>Array('stmptm'=>0), 'TESTBEAR'=>Array('header'=>'Authorization', 'scheme'=>'Bearer'))
) );
$dvcConf = Array('needed'=>true, 'sessby'=>'header', 'sesscf'=>'testbear', 'authby'=>'device', 'authcf'=>'testdvc');
$sqlLink->exec("UPDATE devices SET last_seen_at=NULL");
$axsRslt = (new nyu\Access\Checker( $dvcConf, fakeReqst(Array('Authorization'=>'Bearer '.$homeTkn)) ))->verifyAxs();
checkSame('Checker device token: user, role and device role', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('user'), $axsRslt->readValue('role'), $axsRslt->readValue('ident')['device']['role'] ?? null), Array(200, 'anna', 'admin', 'home'));
checkSame('Checker device token: the device is stamped', $sqlLink->query("SELECT name FROM devices WHERE last_seen_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN), Array('Home'));
$axsRslt = (new nyu\Access\Checker( $dvcConf, fakeReqst(Array('Authorization'=>'Bearer '.$phonTkn)) ))->verifyAxs();
checkSame('Checker device token: a revoked device is refused', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg')), Array(401, 'Invalid API key'));
$sqlLink = null;

endChecks();
