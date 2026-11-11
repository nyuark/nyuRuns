<?php

// Command line, cronjob : an action of a run called from the command line,
// as the user of the system: its inputs, its role, its preset, its reply.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

$rndrTxt = '"inputSet": [ { "sourceId": "QUERY", "readFrom": "QUERY", "fieldSet": "all" } ], "dispatch": { "detectBy": "autopath", "pathForm": "$appToRun/$doAction/$argument" }, "response": { "outputAs": "jsonData", "headList": {} }';
$projDir = makeProjectTxt('cronjob', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/access.json'=>"{\n\t\"loginSet\":  { \"authWith\": \"userFile\", \"authConf\": \"userFile\", \"carrWith\": \"jwtToken\", \"carrConf\": \"jwtToken\" },\n\t\"userFile\": { \"listFile\": \"conf/users.lst\", \"minChars\": 8 },\n\t\"jwtToken\": { \"hmacSecr\": \"test-only-secret-0123456789-abcdefghij\", \"lifeSecs\": 300, \"signAlgo\": \"HS256\" }\n}\n",
	'conf/presets/default.rps'=>'{ "presetId": "default", "settings": { "confType": "all" }, "security": { "required": false }, '.$rndrTxt.' }',
	'conf/presets/secure.rps'=>'{ "presetId": "secure", "settings": { "confType": "all" }, "security": { "carrWith": "jwtToken", "carrConf": "jwtToken", "authWith": "userFile", "authConf": "userFile", "minRoles": { "probe/identify": "managers" } }, '.$rndrTxt.' }',
	'test/fixtures/letters.txt'=>strval(file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt'))
));

checkIdentical('calls runs an action and writes its reply', callShellArray($projDir, Array('cronjob', 'probe/mirrored/hello')), Array('code'=>0, 'text'=>"{\"got\":\"hello\"}\n", 'errs'=>''));
checkIdentical('the call runs as the user of the system, with the role system', Array(preg_match('/^cli:[\w.\-]+$/D', strval(runShellAsJson($projDir, Array('cronjob', 'probe/identify'))['reply']['user'] ?? '')), runShellAsJson($projDir, Array('cronjob', 'probe/identify'))['reply']['role'] ?? null, runShellAsJson($projDir, Array('cronjob', 'probe/roleList'))['reply'] ?? null), Array(1, 'advanced', Array('standard'=>true, 'advanced'=>true, 'managers'=>false)));
checkIdentical('--roleName gives it another role; a role the action asks for applies', Array(runShellAsJson($projDir, Array('cronjob', 'probe/roleList/managers', '--roleName=managers'))['reply']['managers'] ?? null, callShellArray($projDir, Array('cronjob', 'probe/roleList/managers'))['code'], callShellArray($projDir, Array('cronjob', 'probe/roleList', '--roleName=boss'))['code']), Array(true, 3, 2));
$runVals = callShellArray($projDir, Array('cronjob', 'probe/identify', '--presetId=secure'));
checkIdentical('the role a preset asks for applies', Array($runVals['code'], $runVals['errs'], runShellAsJson($projDir, Array('cronjob', 'probe/identify', '--presetId=secure', '--roleName=managers'))['reply']['logged'] ?? null), Array(3, "403 Forbidden\n", true));
$inpVals = runShellAsJson($projDir, Array('cronjob', 'forms/transmit', 'name=Ann', 'filters[search]=jazz', 'other=dropped'))['reply'] ?? Array();
checkIdentical('the inputs go through the definition of the action', Array($inpVals['NAME'] ?? null, $inpVals['FILTERS'] ?? null, array_key_exists('OTHER', $inpVals)), Array('Ann', Array('search'=>'jazz'), false));
$inpVals = runShellAsJson($projDir, Array('cronjob', 'forms/freeForm', 'b=2', '--bodyFrom=-'), '{"a": 1, "b": 1}')['reply'] ?? Array();
checkIdentical('inputs read as JSON, the words written over them', Array($inpVals['A'] ?? null, $inpVals['B'] ?? null), Array(1, '2'));
checkIdentical('what is not an input, or not an action', Array(callShellArray($projDir, Array('cronjob', 'forms/freeForm', 'novalue'))['code'], callShellArray($projDir, Array('cronjob', 'forms'))['code'], callShellArray($projDir, Array('cronjob', 'forms/freeForm', '--httpVerb=FETCH'))['code'], callShellArray($projDir, Array('cronjob', 'forms/freeForm', '--bodyFrom='.$projDir.DS.'nofile.json'))['code']), Array(2, 2, 2, 2));
$runVals = callShellArray($projDir, Array('cronjob', 'probe/stopping/fail'));
checkIdentical('a refusal of the action is told, with its exit code', $runVals, Array('code'=>3, 'text'=>"{\"error\":\"Stopped early\",\"code\":404}\n", 'errs'=>"404 Stopped early\n"));
checkIdentical('an unknown application or action: the same refusal', Array(callShellArray($projDir, Array('cronjob', 'nothing/here'))['code'], callShellArray($projDir, Array('cronjob', 'probe/nothing'))['code']), Array(3, 3));
checkIdentical('a reply without content, a redirection', Array(callShellArray($projDir, Array('cronjob', 'probe/stopping/none')), callShellArray($projDir, Array('cronjob', 'probe/redirect/out'))), Array(Array('code'=>0, 'text'=>'', 'errs'=>''), Array('code'=>0, 'text'=>"moved: https://example.org/\n", 'errs'=>'')));
$savePth = inTemporaryDir('reply.bin');
checkIdentical('a file reply is written where --saveInto tells', Array(callShellArray($projDir, Array('cronjob', 'probe/sendFile', '--saveInto='.$savePth))['code'], is_file($savePth) ? file_get_contents($savePth) : null), Array(0, file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt')));
checkIdentical('a method the request is said to use', runShellAsJson($projDir, Array('cronjob', 'probe/userPass', 'user=a', 'pass=b', '--httpVerb=POST'))['code'] ?? null, 401);
checkPregMatch('a call is traced on the access channel', strval(fetchInProject($projDir, 'temp/logs/access.log')), '/\[NOTICE\] Action probe\/mirrored called from the command line by user "cli:[\w.\-]+" \(advanced\)\n/');

finishChecking();
