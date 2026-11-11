<?php

// Command line, showlog : the last lines of a log channel shown, the newest
// file of the channel read, what is not a channel refused.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

$projDir = makeProjectTxt('showlog', Array(
	'conf/system.ini'=>buildSystemIni(),
	'temp/logs/access.log'=>"[2026-10-01 10:00] [INFO] one\n[2026-10-01 10:01] [INFO] two\n[2026-10-01 10:02] [WARNING] three\n",
	'temp/logs/access.log.1'=>"old\n", 'temp/logs/runner.log'=>"run\n"
));

checkIdentical('showlog shows the last lines of a channel', callShellArray($projDir, Array('showlog', 'access', '--numLines=2'))['text'], "[2026-10-01 10:01] [INFO] two\n[2026-10-01 10:02] [WARNING] three\n");
checkIdentical('showlog shows 20 lines when not told', substr_count(callShellArray($projDir, Array('showlog', 'access'))['text'], "\n"), 3);
checkIdentical('a channel without a log', callShellArray($projDir, Array('showlog', 'nothing'))['code'], 3);
checkIdentical('a channel is a plain name', callShellArray($projDir, Array('showlog', '../conf/system'))['code'], 2);

finishChecking();
