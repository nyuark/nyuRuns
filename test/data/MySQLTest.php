<?php

// Data\DBHndlr\MySQL : the dialect checks of test/lib/dialect.php on a real
// MySQL server. Optional: the test skips itself when the pdo_mysql
// extension is missing, when no server is configured, or when the server
// does not answer (see test/lib/servers.php and .env.example).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/servers.php';
require __DIR__.'/../lib/dialect.php';

if( !extension_loaded('pdo_mysql') ){
	skipCheck('MySQL', 'pdo_mysql is not available');
	endChecks();
}

$srvConf = serverCnf('mysql');
$skipWhy = ($srvConf['skip']!='') ? $srvConf['skip'] : buildSrvr('mysql', $srvConf);
if( $skipWhy!='' ){
	skipCheck('MySQL', $skipWhy);
	endChecks();
}

useServer('mysql', $srvConf);
runDialct('nyu\Data\DBHndlr\MySQL');

endChecks();
