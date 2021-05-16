<?php

// Tools\Logger : verbosity modes, context, file naming and rotation

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Logger;

$logsDir = inTempDir('logs');
$nowDate = date('Y-m-d');

/**
 * Reads a log file as an array of lines.
 *
 * @param String $logFilePth Path of the log file.
 * @return Array The lines, or an empty array when the file does not exist.
 */
function readLines( String $logFilePth ): Array {
	return file_exists($logFilePth) ? file($logFilePth, FILE_IGNORE_NEW_LINES) : Array();
}

// FULL : level tag and context; mode and rotation names are case-insensitive
$fullLog = new Logger($logsDir, 'full', 'full', 0, 'number', 'Y-m-d');
$fullLog->error('boom', Array('k'=>'v'));
$fullLog->warning('careful');
$fullLog->notice('fyi');
$fullLog->info('hello');
$fullLog->debug('trace');
checkSame('FULL lines', readLines($logsDir.DS.'full.log'), Array(
	'['.$nowDate.'] [ERROR] boom {"k":"v"}',
	'['.$nowDate.'] [WARNING] careful',
	'['.$nowDate.'] [NOTICE] fyi',
	'['.$nowDate.'] [INFO] hello',
	'['.$nowDate.'] [DEBUG] trace',
));

// BASE : level tag, context dropped
$baseLog = new Logger($logsDir, 'base', 'BASE', 0, 'NUMBER', 'Y-m-d');
$baseLog->info('hello', Array('dropped'=>1));
checkSame('BASE drops the context', readLines($logsDir.DS.'base.log'), Array('['.$nowDate.'] [INFO] hello'));

// MINI : timestamp and message only
$miniLog = new Logger($logsDir, 'mini', 'MINI', 0, 'NUMBER', 'Y');
$miniLog->error('short', Array('dropped'=>1));
checkSame('MINI line', readLines($logsDir.DS.'mini.log'), Array(date('Y').' short'));

// The log folder is created when missing
$deepDir = $logsDir.DS.'made'.DS.'on'.DS.'demand';
$deepLog = new Logger($deepDir, 'deep');
$deepLog->info('x');
checkTrue('missing folder is created', file_exists($deepDir.DS.'deep.log'));

// NUMBER rotation : a 1 KB limit must rotate within a single process
$rotaLog = new Logger($logsDir, 'rota', 'BASE', 1, 'NUMBER', 'Y');
for( $idx=0; $idx<60; $idx++ ){
	$rotaLog->info(str_repeat('x', 40));
}
checkTrue('rotation creates rota.log.1', file_exists($logsDir.DS.'rota.log.1'));
checkTrue('rotation creates rota.log.2', file_exists($logsDir.DS.'rota.log.2'));
checkTrue('rotation creates rota.log.3', file_exists($logsDir.DS.'rota.log.3'));
checkTrue('no fourth backup', !file_exists($logsDir.DS.'rota.log.4'));
clearstatcache();
checkTrue('backups hold just over the limit', filesize($logsDir.DS.'rota.log.1')>=1024 && filesize($logsDir.DS.'rota.log.1')<1024+60);
checkTrue('active log is under the limit', filesize($logsDir.DS.'rota.log')<1024);
$allLine = 0;
foreach( Array('rota.log', 'rota.log.1', 'rota.log.2', 'rota.log.3') as $fleNme ){
	$allLine += count(readLines($logsDir.DS.$fleNme));
}
checkSame('no line lost by rotation', $allLine, 60);

// No size limit : never rotates
$freeLog = new Logger($logsDir, 'free', 'BASE', 0, 'NUMBER', 'Y');
for( $idx=0; $idx<60; $idx++ ){
	$freeLog->info(str_repeat('x', 40));
}
checkTrue('size 0 never rotates', !file_exists($logsDir.DS.'free.log.1'));

// Dated file names
$mnthLog = new Logger($logsDir, 'month', 'BASE', 0, 'months', 'Y');
$mnthLog->info('m');
checkTrue('MONTHS file name', file_exists($logsDir.DS.'month-'.date('Y-m').'.log'));
$weekLog = new Logger($logsDir, 'week', 'BASE', 0, 'WEEKLY', 'Y');
$weekLog->info('w');
checkTrue('WEEKLY file name', file_exists($logsDir.DS.'week-W'.date('oW').'.log'));
$uniqLog = new Logger($logsDir, 'uniq', 'BASE', 0, 'UNIQUE', 'Y');
$uniqLog->info('u');
checkSame('UNIQUE file name', count(glob($logsDir.DS.'uniq-*-'.getmypid().'.log')), 1);

endChecks();
