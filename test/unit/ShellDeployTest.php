<?php

// Command line, deploy : the server checked, the local files created from
// their examples, the questions and their defaults, the secrets and the
// users made once, and the health check.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

/**
 * Creates a project as a fresh checkout has it: the examples, and no
 * local file.
 *
 * @param String $folderName Name of the folder.
 * @return String The full path of the project.
 */
function freshPrjt( String $folderName ): String {
	return makeProjt($folderName, Array(
		'.env.example'=>"# Copy to .env and fill in\nNYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n",
		'conf/access.json.dist'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json.dist')),
		'conf/system.ini'=>sampleIni(),
		'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false } }',
		'conf/presets/secure.rps'=>'{ "label": "secure", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "dftokn", "rolert": { "users/*": "manage" } } }'
	));
}

/**
 * Gives the rows of a health check by what they checked.
 *
 * @param mixed $jsonValues The output of the command, read as JSON.
 * @return Array The state of each check, by its name.
 */
function stateOf( Mixed $jsonValues ): Array {
	return array_column( is_array($jsonValues) ? ($jsonValues['rows'] ?? Array()) : Array(), 'state', 'check' );
}

$projDir = freshPrjt('deploy');

// servr, files
$jsnVals = jsonShell($projDir, Array('deploy', 'servr'));
$statLst = stateOf($jsnVals);
checkSame('servr checks PHP and the folders, which it creates', Array($jsnVals['exit'] ?? null, $statLst['php'] ?? null, $statLst['folder temp/logs'] ?? null, $statLst['folder temp/limits'] ?? null, is_dir($projDir.DS.'temp'.DS.'cache'), is_dir($projDir.DS.'temp'.DS.'backup')), Array(0, 'fine', 'fine', 'fine', true, true));
checkSame('files creates the local files from their examples', Array(jsonShell($projDir, Array('deploy', 'files'))['rows'] ?? null, readInPrj($projDir, '.env'), readInPrj($projDir, 'conf/access.json')), Array(
	Array(Array('file'=>'.env', 'state'=>'created from .env.example'), Array('file'=>'conf/access.json', 'state'=>'created from conf/access.json.dist'), Array('file'=>'conf/database.json', 'state'=>'not created: its example conf/database.json.dist is missing')),
	"# Copy to .env and fill in\nNYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n", str_replace("\r\n", "\n", strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json.dist')))
));
putInProj($projDir, '.env', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n");
checkSame('a local file that exists is kept', Array(array_column(jsonShell($projDir, Array('deploy', 'files'))['rows'] ?? Array(), 'state', 'file')['.env'] ?? null, readInPrj($projDir, '.env')), Array('kept', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n"));

// check, before anything is set
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
$statLst = stateOf($jsnVals);
checkSame('the health check tells what is missing', Array($jsnVals['exit'] ?? null, $statLst['preset dfault'] ?? null, $statLst['preset secure'] ?? null, $statLst['access login'] ?? null, $statLst['secret dftokn'] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $statLst['keys dfkeys'] ?? null, $statLst['errors'] ?? null, ($jsnVals['fail'] ?? 0)>=3),
	Array(4, 'fine', 'fine', 'fine', 'fail', 'fail', 'fail', 'warn', 'fine', true));

// start, without a question
$jsnVals = jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=usrlst'));
$statLst = stateOf($jsnVals);
$envVals = parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW);
$axsVals = json_decode(strval(readInPrj($projDir, 'conf/access.json')), true);
$usrList = array_column(json_decode(strval(readInPrj($projDir, 'conf/users.lst')), true)['users'] ?? Array(), null, 'userName');
checkSame('start points the login at the store, makes the secrets and the users, and ends on a clean check', Array($jsnVals['exit'] ?? null, $axsVals['login']['authby'] ?? null, $axsVals['login']['authcf'] ?? null, strlen($envVals['NYU_JWT_SECRET'] ?? '')>=48, $envVals['NYU_API_KEY'] ?? '', array_column($usrList, 'userRole', 'userName'), $statLst['secret dftokn'] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $jsnVals['fail'] ?? null),
	Array(0, 'usrlst', 'usrlst', true, $jsnVals['secret']['API key'] ?? 'none', Array('member'=>'member', 'system'=>'system', 'managr'=>'manage'), 'fine', 'fine', 'fine', 0));
checkSame('start makes the key of the 2FA secrets with the store, never shown', Array(strlen($envVals['NYU_TFA_KEY'] ?? '')>=48, $statLst['2FA key usrlst'] ?? null, isset($jsnVals['secret']['Key of the 2FA secrets'])), Array(true, 'fine', false));
checkSame('the passwords of the basic users are shown once', Array(password_verify($jsnVals['secret']['Password of managr (manage)'] ?? '', $usrList['managr']['passHash'] ?? ''), password_verify($jsnVals['secret']['Password of member (member)'] ?? '', $usrList['member']['passHash'] ?? ''), isset($jsnVals['secret']['Secret of the tokens'])), Array(true, true, false));
checkLike('the layout of the access config is kept', strval(readInPrj($projDir, 'conf/access.json')), '/^\{\n\t"plugin": \{ "authby": \[\], "sessby": \[\] \},\n\t"login":  \{ "authby": "usrlst", "authcf": "usrlst", /');
$jsnVals = jsonShell($projDir, Array('deploy', 'start', '--noask'));
checkSame('run again, nothing that exists is replaced', Array($jsnVals['exit'] ?? null, parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW), json_decode(strval(readInPrj($projDir, 'conf/users.lst')), true)['users'][2]['passHash'] ?? null, isset($jsnVals['secret']), in_array('The users file already holds users: nothing was changed.', $jsnVals['notes'] ?? Array(), true)), Array(0, $envVals, $usrList['managr']['passHash'], false, true));
checkLike('the installation is traced', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Deploy: basic users created in usrlst \(member, system, managr\) by user "cli:/');
checkSame('users alone: a store that holds users is left as it is; a store that is not one', Array(runShell($projDir, Array('deploy', 'users'))['text'], runShell($projDir, Array('deploy', 'users', '--store=other'))['code']), Array("The users file already holds users: nothing was changed.\n", 2));

// start, with its questions
$projDir = freshPrjt('asked');
$runVals = runShell($projDir, Array('deploy', 'start', '--quiet'), "\n\n");
checkSame('each question proposes a default, taken on Enter', Array($runVals['errs'], strpos(strval(readInPrj($projDir, 'conf/system.ini')), 'ENV_NAME = DEV')!==false, is_file($projDir.DS.'conf'.DS.'users.lst')), Array('Environment (DEV, TEST, PREV, PROD) [DEV]: Store of the users (native, usrlst, none) [native]: No user created: No database is configured'."\n", true, false));
$projDir = freshPrjt('answer');
$jsnVals = jsonShell($projDir, Array('deploy', 'start'), "prod\nusrlst\n");
$statLst = stateOf($jsnVals);
checkSame('the answers are written; ERR_SHOW left on in production is told, where it is ignored', Array($jsnVals['exit'] ?? null, strpos(strval(readInPrj($projDir, 'conf/system.ini')), "ENV_NAME = PROD\n")!==false, strpos(strval(readInPrj($projDir, 'conf/system.ini')), '; Environment platform (DEV, TEST, PREV, PROD)')!==false, count(json_decode(strval(readInPrj($projDir, 'conf/users.lst')), true)['users'] ?? Array()), $statLst['errors'] ?? null, $statLst['users'] ?? null), Array(0, true, true, 3, 'warn', 'fine'));
checkLike('the row tells the setting is ignored there', strval(array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['errors'] ?? ''), '/^ERR_SHOW is on in the environment PROD, where it is ignored: set it to FALSE$/');
checkSame('once it is off, the row is fine', Array(runShell($projDir, Array('config', 'write', 'system', 'ERR_SHOW', 'FALSE'))['code'], stateOf(jsonShell($projDir, Array('deploy', 'check')))['errors'] ?? null), Array(0, 'fine'));
runShell($projDir, Array('config', 'write', 'access', 'dfsess.secure', 'false'));
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
checkSame('a session cookie sent over plain HTTP in production is told, without failing the check', Array(json_decode(strval(readInPrj($projDir, 'conf/access.json')), true)['dfsess']['secure'] ?? null, stateOf($jsnVals)['cookie dfsess'] ?? null, $jsnVals['exit'] ?? null), Array(false, 'warn', 0));
$projDir = freshPrjt('wrongs');
checkSame('an answer that is not one of those proposed', Array(runShell($projDir, Array('deploy', 'start'), "LIVE\n")['code'], runShell($projDir, Array('deploy', 'start'), "\nldap\n")['code']), Array(3, 3));
$jsnVals = jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=none'));
checkSame('a store without users of its own', Array($jsnVals['exit'] ?? null, is_file($projDir.DS.'conf'.DS.'users.lst')), Array(4, false));
checkSame('without a store, no key of the 2FA secrets is made: the check tells the secrets would be readable', Array(parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? '', stateOf($jsnVals)['2FA key native'] ?? null), Array('', 'warn'));
runShell($projDir, Array('config', 'write', 'access', 'native.tfakey', 'too-short'));
checkSame('a key of the 2FA secrets that is too short fails the check', stateOf(jsonShell($projDir, Array('deploy', 'check')))['2FA key native'] ?? null, 'fail');
runShell($projDir, Array('config', 'write', 'access', 'native.tfakey', 'env:NYU_TFA_KEY'));

// What the check refuses
putInProj($projDir, 'conf/presets/broken.rps', '{ "label": "broken", ');
putInProj($projDir, 'conf/presets/noblok.rps', '{ "label": "noblok", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "nowhere" } }');
putInProj($projDir, 'conf/presets/norole.rps', '{ "label": "norole", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "dftokn", "rolert": { "users/*": "boss" } } }');
putInProj($projDir, 'conf/system.ini', sampleIni("PRX_LIST = \"10.0.0.5, 0.0.0.0/0\"\n"));
$cookRps = '{ "label": "%s", "setup": { "ftypes": "all" }, "auths": { "sessby": "phsess", "sesscf": "dfsess" }, "rnder": { "tmplte": "plain", "return": "json", %s"headrs": { "access-control-allow-origin": "*" } } }';
putInProj($projDir, 'conf/presets/cookie.rps', sprintf($cookRps, 'cookie', ''));
putInProj($projDir, 'conf/presets/listed.rps', sprintf($cookRps, 'listed', '"origns": ["https://app.example.org"], '));
$statLst = stateOf(jsonShell($projDir, Array('deploy', 'check')));
checkSame('a session cookie with every origin allowed is told, unless the origins are listed', Array($statLst['origins cookie'] ?? null, $statLst['preset cookie'] ?? null, array_key_exists('origins listed', $statLst), array_key_exists('origins dfault', $statLst)), Array('warn', 'fine', false, false));
checkSame('a preset that does not load, names a block that is not there, or asks for a role that is not one; proxies trusted from anywhere', Array($statLst['preset broken'] ?? null, $statLst['preset noblok'] ?? null, $statLst['preset norole'] ?? null, $statLst['preset dfault'] ?? null, $statLst['proxies'] ?? null), Array('fail', 'fail', 'fail', 'fine', 'fail'));

endChecks();
