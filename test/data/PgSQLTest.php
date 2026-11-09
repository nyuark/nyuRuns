<?php

// Data\DBHndlr\PgSQL : the dialect checks of test/lib/dialect.php on a real
// PostgreSQL server. Optional: the test skips itself when the pdo_pgsql
// extension is missing, when no server is configured, or when the server
// does not answer (see test/lib/servers.php and .env.example).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/servers.php';
require __DIR__.'/../lib/dialect.php';

if( !extension_loaded('pdo_pgsql') ){
	recordSkipping('PostgreSQL', 'pdo_pgsql is not available');
	finishChecking();
}

$srvConf = serverSettings('pgsql');
$skipWhy = ($srvConf['skip']!='') ? $srvConf['skip'] : generateServer('pgsql', $srvConf);
if( $skipWhy!='' ){
	recordSkipping('PostgreSQL', $skipWhy);
	finishChecking();
}

switchToServer('pgsql', $srvConf);
executeDialect('nyu\Data\DBHndlr\PgSQL');

finishChecking();
