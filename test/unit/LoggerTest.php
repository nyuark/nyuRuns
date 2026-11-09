<?php

// Tools\Logger : verbosity modes, levels, context, file naming, rotation,
// the shared channels and the actor of the request

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Logger;

$logsDir = inTemporaryDir('logs');
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
checkIdentical('FULL lines', readLines($logsDir.DS.'full.log'), Array(
	'['.$nowDate.'] [ERROR] boom {"k":"v"}',
	'['.$nowDate.'] [WARNING] careful',
	'['.$nowDate.'] [NOTICE] fyi',
	'['.$nowDate.'] [INFO] hello',
	'['.$nowDate.'] [DEBUG] trace',
));

// BASE : level tag, context dropped
$baseLog = new Logger($logsDir, 'base', 'BASE', 0, 'NUMB', 'Y-m-d');
$baseLog->info('hello', Array('dropped'=>1));
checkIdentical('BASE drops the context', readLines($logsDir.DS.'base.log'), Array('['.$nowDate.'] [INFO] hello'));

// MINI : timestamp and message only
$miniLog = new Logger($logsDir, 'mini', 'MINI', 0, 'NUMB', 'Y');
$miniLog->error('short', Array('dropped'=>1));
checkIdentical('MINI line', readLines($logsDir.DS.'mini.log'), Array(date('Y').' short'));

// The log folder is created when missing
$deepDir = $logsDir.DS.'made'.DS.'on'.DS.'demand';
$deepLog = new Logger($deepDir, 'deep');
$deepLog->info('x');
checkTrueValue('missing folder is created', file_exists($deepDir.DS.'deep.log'));

// NUMB rotation : a 1 KB limit must rotate within a single process
$rotaLog = new Logger($logsDir, 'rota', 'BASE', 1, 'NUMB', 'Y');
for( $idx=0; $idx<60; $idx++ ){
	$rotaLog->info(str_repeat('x', 40));
}
checkTrueValue('rotation creates rota.log.1', file_exists($logsDir.DS.'rota.log.1'));
checkTrueValue('rotation creates rota.log.2', file_exists($logsDir.DS.'rota.log.2'));
checkTrueValue('rotation creates rota.log.3', file_exists($logsDir.DS.'rota.log.3'));
checkTrueValue('no fourth backup', !file_exists($logsDir.DS.'rota.log.4'));
clearstatcache();
checkTrueValue('backups hold just over the limit', filesize($logsDir.DS.'rota.log.1')>=1024 && filesize($logsDir.DS.'rota.log.1')<1024+60);
checkTrueValue('active log is under the limit', filesize($logsDir.DS.'rota.log')<1024);
$allLine = 0;
foreach( Array('rota.log', 'rota.log.1', 'rota.log.2', 'rota.log.3') as $fleNme ){
	$allLine += count(readLines($logsDir.DS.$fleNme));
}
checkIdentical('no line lost by rotation', $allLine, 60);

// No size limit : never rotates
$freeLog = new Logger($logsDir, 'free', 'BASE', 0, 'NUMB', 'Y');
for( $idx=0; $idx<60; $idx++ ){
	$freeLog->info(str_repeat('x', 40));
}
checkTrueValue('size 0 never rotates', !file_exists($logsDir.DS.'free.log.1'));

// Dated file names
$mnthLog = new Logger($logsDir, 'month', 'BASE', 0, 'mnth', 'Y');
$mnthLog->info('m');
checkTrueValue('MNTH file name', file_exists($logsDir.DS.'month-'.date('Y-m').'.log'));
$weekLog = new Logger($logsDir, 'week', 'BASE', 0, 'WEEK', 'Y');
$weekLog->info('w');
checkTrueValue('WEEK file name', file_exists($logsDir.DS.'week-W'.date('oW').'.log'));
$uniqLog = new Logger($logsDir, 'uniq', 'BASE', 0, 'NONE', 'Y');
$uniqLog->info('u');
checkIdentical('NONE file name', count(glob($logsDir.DS.'uniq-*-'.getmypid().'.log')), 1);
// The name is the one of the process : an entry written a second later goes to the same file
sleep(1);
$uniqLog->info('v');
$uniqLst = glob($logsDir.DS.'uniq-*-'.getmypid().'.log');
checkIdentical('NONE keeps one file for the process', Array(count($uniqLst), count(readLines($uniqLst[0] ?? ''))), Array(1, 2));

// Levels : MINI writes down to notices, BASE down to infos, FULL everything
$lvlList = Array('MINI'=>Array('e', 'w', 'n'), 'BASE'=>Array('e', 'w', 'n', 'i'), 'FULL'=>Array('e', 'w', 'n', 'i', 'd'));
foreach( $lvlList as $modNme=>$modLst ){
	$lvlLogr = new Logger($logsDir, 'lvl'.strtolower($modNme), $modNme, 0, 'NUMB', 'Y');
	$lvlLogr->error('e');
	$lvlLogr->warning('w');
	$lvlLogr->notice('n');
	$lvlLogr->info('i');
	$lvlLogr->debug('d');
	$gotList = Array();
	foreach( readLines($logsDir.DS.'lvl'.strtolower($modNme).'.log') as $oneLin ){
		$gotList[] = substr($oneLin, -1);
	}
	checkIdentical($modNme.' levels written', $gotList, $modLst);
}

// An entry is one line : a line break in a message cannot start another one
$lineLog = new Logger($logsDir, 'line', 'BASE', 0, 'NUMB', 'Y');
$lineLog->warning("refused for \"x\"\r\n[".date('Y')."] [INFO] Access granted for user \"root\"");
checkIdentical('a line break is written as text', readLines($logsDir.DS.'line.log'), Array('['.date('Y').'] [WARNING] refused for "x"\r\n['.date('Y').'] [INFO] Access granted for user "root"'));
$uniLog = new Logger($logsDir, 'unicode', 'BASE', 0, 'NUMB', 'Y');
$uniLog->warning("a\u{2028}b\u{2029}c\u{0085}d\x0Be\x0Cf\tg\x00h\x7Fi");
checkIdentical('the Unicode line breaks and every control character are written as text', readLines($logsDir.DS.'unicode.log'), Array('['.date('Y').'] [WARNING] a\u2028b\u2029c\u0085d\x0Be\x0Cf\tg\x00h\x7Fi'));
$badLog = new Logger($logsDir, 'badutf', 'BASE', 0, 'NUMB', 'Y');
$badLog->warning("x\xFF\ny");
checkIdentical('in a message that is not UTF-8 too', count(file($logsDir.DS.'badutf.log', FILE_IGNORE_NEW_LINES)), 1);
checkIdentical('an accent is kept as it is', (function() use ( $logsDir ){ $accLog = new Logger($logsDir, 'accent', 'BASE', 0, 'NUMB', 'Y'); $accLog->info('Été à Genève'); return readLines($logsDir.DS.'accent.log'); })(), Array('['.date('Y').'] [INFO] Été à Genève'));

// A context that cannot be encoded as it is keeps what can be
$ctxtLog = new Logger($logsDir, 'ctxt', 'FULL', 0, 'NUMB', 'Y');
$ctxtLog->error('bad bytes', Array('ok'=>1, 'bad'=>"a\xB1b"));
checkPregMatch('context with invalid bytes is still written', readLines($logsDir.DS.'ctxt.log')[0] ?? null, '/^\[\d{4}\] \[ERROR\] bad bytes \{"ok":1,"bad":"a.*b"\}$/');

// The channel names a file : only a plain name is accepted
checkForThrows('channel with a path is refused', function() use ($logsDir){ new Logger($logsDir, '..'.DS.'out'); }, 'RuntimeException');
checkForThrows('empty channel is refused', function() use ($logsDir){ new Logger($logsDir, ''); }, 'RuntimeException');
checkForThrows('getLogger refuses it too', function(){ Logger::retrieveLogger('a/b'); }, 'RuntimeException');

// Shared channels : nothing is written until a setup is plugged
$muteLog = Logger::retrieveLogger('runner');
$muteLog->error('lost');
checkTrueValue('without a setup the logger writes nothing', !file_exists($logsDir.DS.'chan'.DS.'runner.log') && !file_exists(DS.'runner.log') && !file_exists('runner.log'));
Logger::plugLogsConfig( Array('path'=>$logsDir.DS.'chan', 'mode'=>'BASE', 'size'=>0, 'rota'=>'NUMB', 'pref'=>'Y') );
checkTrueValue('a plugged setup replaces the mute loggers', Logger::retrieveLogger('runner')!==$muteLog);
checkTrueValue('a channel has one shared logger', Logger::retrieveLogger('access')===Logger::retrieveLogger('access'));
checkTrueValue('each channel has its own', Logger::retrieveLogger('access')!==Logger::retrieveLogger('runner'));
checkTrueValue('the default channel is runner', Logger::retrieveLogger()===Logger::retrieveLogger('runner'));
Logger::retrieveLogger('access')->warning('denied');
Logger::retrieveLogger('shows')->info('listed');
checkIdentical('each channel writes its own file', Array(readLines($logsDir.DS.'chan'.DS.'access.log'), readLines($logsDir.DS.'chan'.DS.'shows.log'), readLines($logsDir.DS.'chan'.DS.'runner.log')), Array(
	Array('['.date('Y').'] [WARNING] denied'),
	Array('['.date('Y').'] [INFO] listed'),
	Array()
));
Logger::plugLogsConfig( Array() );
Logger::retrieveLogger('access')->warning('lost');
checkIdentical('a setup without a path mutes the channels again', count(readLines($logsDir.DS.'chan'.DS.'access.log')), 1);

// The actor of the request, for the events traced far from the access check
checkIdentical('no actor: nothing is told', Logger::getActorSuffix(), '');
Logger::setCurrentUser('bob', '10.0.0.1');
checkIdentical('actor with a name and an address', Logger::getActorSuffix(), ' by user "bob" from 10.0.0.1');
Logger::setCurrentUser('', '10.0.0.1');
checkIdentical('actor with an address only', Logger::getActorSuffix(), ' from 10.0.0.1');
Logger::setCurrentUser('a "b"', '');
checkIdentical('actor name is written as a JSON text', Logger::getActorSuffix(), ' by user "a \"b\""');
Logger::setCurrentUser('', '');

finishChecking();
