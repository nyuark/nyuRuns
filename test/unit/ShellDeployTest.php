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
	return makeProjectTxt($folderName, Array(
		'.env.example'=>"# Copy to .env and fill in\nNYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n",
		'conf/access.json.dist'=>distAccss(),
		'conf/system.ini'=>buildSystemIni(),
		'conf/presets/defaults.rps'=>'{ "presetId": "defaults", "settings": { "fileType": "all" }, "security": { "required": false } }',
		'conf/presets/secure.rps'=>'{ "presetId": "secure", "settings": { "fileType": "all" }, "security": { "sessWith": "jwtToken", "sessConf": "jwtToken", "rteRoles": { "users/*": "manage" } } }'
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

/**
 * The example access config the project ships; signing its tokens with
 * HS256 when PHP has no openssl, which RS256 needs to make its keys.
 *
 * @return String The content of conf/access.json.dist.
 */
function distAccss(): String {
	$distTxt = strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json.dist'));
	return function_exists('openssl_pkey_new') ? $distTxt : str_replace('"signAlgo": "RS256"', '"signAlgo": "HS256"', $distTxt);
}
// Without openssl the tokens are signed with a secret, checked by its own row
$tknRow = function_exists('openssl_pkey_new') ? 'token keys jwtToken' : 'secret jwtToken';
$projDir = freshPrjt('deploy');

// servr, files
$jsnVals = runShellAsJson($projDir, Array('installs', 'testHost'));
$statLst = stateOf($jsnVals);
checkIdentical('servr checks PHP and the folders, which it creates', Array($jsnVals['exit'] ?? null, $statLst['php'] ?? null, $statLst['folder temp/logs'] ?? null, $statLst['folder temp/limits'] ?? null, is_dir($projDir.DS.'temp'.DS.'cache'), is_dir($projDir.DS.'temp'.DS.'backup')), Array(0, 'fine', 'fine', 'fine', true, true));
checkIdentical('files creates the local files from their examples', Array(runShellAsJson($projDir, Array('installs', 'makeConf'))['rows'] ?? null, fetchInProject($projDir, '.env'), fetchInProject($projDir, 'conf/access.json')), Array(
	Array(Array('file'=>'.env', 'state'=>'created from .env.example'), Array('file'=>'conf/access.json', 'state'=>'created from conf/access.json.dist'), Array('file'=>'conf/database.json', 'state'=>'not created: its example conf/database.json.dist is missing')),
	"# Copy to .env and fill in\nNYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n", str_replace("\r\n", "\n", distAccss())
));
writeInProject($projDir, '.env', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n");
checkIdentical('a local file that exists is kept', Array(array_column(runShellAsJson($projDir, Array('installs', 'makeConf'))['rows'] ?? Array(), 'state', 'file')['.env'] ?? null, fetchInProject($projDir, '.env')), Array('kept', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n"));

// check, before anything is set
$jsnVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
$statLst = stateOf($jsnVals);
checkIdentical('the health check tells what is missing', Array($jsnVals['exit'] ?? null, $statLst['preset defaults'] ?? null, $statLst['preset secure'] ?? null, $statLst['access login'] ?? null, $statLst[$tknRow] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $statLst['keys regdKeys'] ?? null, $statLst['errors'] ?? null, ($jsnVals['fail'] ?? 0)>=2),
	Array(4, 'fine', 'fine', 'fine', 'fail', 'fine', 'fail', 'warn', 'fine', true));

// start, without a question
$jsnVals = runShellAsJson($projDir, Array('installs', 'allSteps', '--noPrompt', '--useStore=userFile'));
$statLst = stateOf($jsnVals);
$envVals = parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW);
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$usrList = array_column(json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'] ?? Array(), null, 'userHandle');
$keyText = fetchInProject($projDir, 'conf/keys/jwtToken.key');
checkIdentical('start points the login at the store, makes the keys of the tokens, the secrets and the users, and ends on a clean check', Array($jsnVals['exit'] ?? null, $axsVals['loginSet']['authWith'] ?? null, $axsVals['loginSet']['authConf'] ?? null, function_exists('openssl_pkey_new') ? is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key') : strlen($envVals['NYU_JWT_SECRET'] ?? '')>=48, $envVals['NYU_API_KEY'] ?? '', array_column($usrList, 'accessRole', 'userHandle'), $statLst[$tknRow] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $jsnVals['fail'] ?? null),
	Array(0, 'userFile', 'userFile', true, $jsnVals['secret']['API key'] ?? 'none', Array('member'=>'member', 'system'=>'system', 'managr'=>'manage'), 'fine', 'fine', 'fine', 0));
checkIdentical('start makes the key of the 2FA secrets with the store, never shown', Array(strlen($envVals['NYU_TFA_KEY'] ?? '')>=48, $statLst['2FA key userFile'] ?? null, isset($jsnVals['secret']['Key of the 2FA secrets'])), Array(true, 'fine', false));
checkIdentical('the passwords of the basic users are shown once', Array(password_verify($jsnVals['secret']['Password of managr (manage)'] ?? '', $usrList['managr']['passwdHash'] ?? ''), password_verify($jsnVals['secret']['Password of member (member)'] ?? '', $usrList['member']['passwdHash'] ?? ''), isset($jsnVals['secret']['Secret of the tokens'])), Array(true, true, false));
checkPregMatch('the layout of the access config is kept', strval(fetchInProject($projDir, 'conf/access.json')), '/^\{\n\t"plugLibs": \{ "authWith": \[\], "sessWith": \[\] \},\n\t"loginSet": \{ "authWith": "userFile", "authConf": "userFile", /');
$jsnVals = runShellAsJson($projDir, Array('installs', 'allSteps', '--noPrompt'));
checkIdentical('run again, nothing that exists is replaced', Array($jsnVals['exit'] ?? null, fetchInProject($projDir, 'conf/keys/jwtToken.key')===$keyText, parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW), json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'][2]['passwdHash'] ?? null, isset($jsnVals['secret']), in_array('The users file already holds users: nothing was changed.', $jsnVals['notes'] ?? Array(), true)), Array(0, true, $envVals, $usrList['managr']['passwdHash'], false, true));
checkPregMatch('the installation is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Deploy: basic users created in userFile \(member, system, managr\) by user "cli:/');
// A public key laid over the server's own, by a bundle of another pair : the check fails
if( function_exists('openssl_pkey_new') ){
	$pubText = strval(fetchInProject($projDir, 'conf/keys/jwtToken.pub'));
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf') );
	writeInProject($projDir, 'conf/keys/jwtToken.pub', strval(openssl_pkey_get_details($keyObjc)['key'] ?? ''));
	$jsnVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
	checkIdentical('a public key that is not the one of the private key fails the check', Array(stateOf($jsnVals)[$tknRow] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')[$tknRow] ?? null),
		Array('fail', 'the public key is not the one of the private key: "settings makeSecr jwtToken" makes a new pair'));
	writeInProject($projDir, 'conf/keys/jwtToken.pub', $pubText);
	checkIdentical('its own public key back, the check is fine', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))[$tknRow] ?? null, 'fine');
}
checkIdentical('users alone: a store that holds users is left as it is; a store that is not one', Array(callShellArray($projDir, Array('installs', 'userBase'))['text'], callShellArray($projDir, Array('installs', 'userBase', '--useStore=other'))['code']), Array("The users file already holds users: nothing was changed.\n", 2));

// start : the settings of a database are made with the native userbase only
$baseDst = '{ "nyuRuns": { "dbEngine": "mysqldbs", "hostName": "localhost", "portNumb": "", "baseName": "nyuRuns", "userName": "nyuruns", "password": "env:NYU_DB_PASS", "encoding": "utf8" } }';
$projDir = freshPrjt('nodbase');
writeInProject($projDir, 'conf/database.json.dist', $baseDst);
$jsnVals = runShellAsJson($projDir, Array('installs', 'allSteps', '--noPrompt', '--useStore=userFile'));
checkIdentical('in the users file, no settings of a database are made, and the check is clean', Array($jsnVals['exit'] ?? null, is_file($projDir.DS.'conf'.DS.'database.json'), stateOf($jsnVals)['databases'] ?? null), Array(0, false, 'fine'));
$projDir = freshPrjt('wthbase');
writeInProject($projDir, 'conf/database.json.dist', $baseDst);
runShellAsJson($projDir, Array('installs', 'allSteps', '--noPrompt', '--useStore=nativeDb'));
checkIdentical('with the native userbase, they are made from their example', fetchInProject($projDir, 'conf/database.json'), $baseDst);
checkIdentical('files alone still makes them all', Array(runShellAsJson(freshPrjt('allfles'), Array('installs', 'makeConf'))['rows'][2]['file'] ?? null), Array('conf/database.json'));

// start, with its questions
$projDir = freshPrjt('asked');
$runVals = callShellArray($projDir, Array('installs', 'allSteps', '--silently'), "\n\n");
checkIdentical('each question proposes a default, taken on Enter: the users file, which needs no database', Array($runVals['errs'], strpos(strval(fetchInProject($projDir, 'conf/system.ini')), 'ENV_NAME = DEV')!==false, is_file($projDir.DS.'conf'.DS.'users.lst')), Array('Environment (DEV, TEST, PREV, PROD) [DEV]: Store of the users (nativeDb, userFile, none) [userFile]: ', true, true));
$projDir = freshPrjt('answer');
$jsnVals = runShellAsJson($projDir, Array('installs', 'allSteps'), "prod\nuserFile\n");
$statLst = stateOf($jsnVals);
checkIdentical('the answers are written; ERR_SHOW left on in production is told, where it is ignored', Array($jsnVals['exit'] ?? null, strpos(strval(fetchInProject($projDir, 'conf/system.ini')), "ENV_NAME = PROD\n")!==false, strpos(strval(fetchInProject($projDir, 'conf/system.ini')), '; Environment platform (DEV, TEST, PREV, PROD)')!==false, count(json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'] ?? Array()), $statLst['errors'] ?? null, $statLst['users'] ?? null), Array(0, true, true, 3, 'warn', 'fine'));
checkPregMatch('the row tells the setting is ignored there', strval(array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['errors'] ?? ''), '/^ERR_SHOW is on in the environment PROD, where it is ignored: set it to FALSE$/');
checkIdentical('once it is off, the row is fine', Array(callShellArray($projDir, Array('settings', 'setValue', 'system', 'ERR_SHOW', 'FALSE'))['code'], stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['errors'] ?? null), Array(0, 'fine'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'phpSessn.secureOn', 'false'));
$jsnVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
checkIdentical('a session cookie sent over plain HTTP in production is told, without failing the check', Array(json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['phpSessn']['secureOn'] ?? null, stateOf($jsnVals)['cookie phpSessn'] ?? null, $jsnVals['exit'] ?? null), Array(false, 'warn', 0));
// The issuer and the audiences of the tokens, the previous key, the audiences of the application keys
checkIdentical('tokens bound to no project are told, without failing the check', Array(stateOf($jsnVals)['token claims jwtToken'] ?? null, $jsnVals['exit'] ?? null), Array('warn', 0));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'jwtToken.issuerId', 'https://auth.example.org'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'jwtToken.audience', '["api.example.org"]'));
checkIdentical('once the issuer and the audiences are set, the row is fine', array_column(runShellAsJson($projDir, Array('installs', 'checkAll'))['rows'] ?? Array(), 'found', 'check')['token claims jwtToken'] ?? null, 'issuer "https:\/\/auth.example.org", audience ["api.example.org"]');
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$axsVals['jwtToken']['issuerId'] = 42;
$axsVals['jwtToken']['prevFile'] = 'conf/access.json';
$axsVals['appsKeys'] = Array('headName'=>'X-App-Key', 'appsList'=>Array('portal'=>Array('keyDigst'=>'', 'issuerId'=>Array('x'))), 'audience'=>Array());
$axsVals['apkeyz'] = Array('headName'=>'X-App-Key', 'appsList'=>Array(), 'audience'=>Array());
writeInProject($projDir, 'conf/access.json', strval(json_encode($axsVals, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)));
$jsnVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
$fndList = array_column($jsnVals['rows'] ?? Array(), 'found', 'check');
checkIdentical('values of the wrong kind fail the check', Array(stateOf($jsnVals)['token claims jwtToken'] ?? null, $fndList['token claims jwtToken'] ?? null, stateOf($jsnVals)['token claims appsKeys'] ?? null, $fndList['token claims appsKeys'] ?? null),
	Array('fail', 'issuer is a text; prevFile is not the public RSA key of 2048 bits at least: empty it, or "settings makeSecr jwtToken" writes it', 'fail', 'the issuer of the application "portal" is a text'));
checkIdentical('application keys without the audiences of this project are told', stateOf($jsnVals)['token claims apkeyz'] ?? null, 'warn');
$axsVals['jwtToken']['issuerId'] = 'https://auth.example.org';
$axsVals['jwtToken']['prevFile'] = 'conf/keys/jwtToken.pub';
$axsVals['jwtToken']['signAlgo'] = 'HS256';
unset($axsVals['appsKeys'], $axsVals['apkeyz']);
writeInProject($projDir, 'conf/access.json', strval(json_encode($axsVals, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)));
if( function_exists('openssl_pkey_new') ){
	checkIdentical('a previous key with HS256 is told, as it is not read', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['previous key jwtToken'] ?? null, 'warn');
}
$projDir = freshPrjt('wrongs');
checkIdentical('an answer that is not one of those proposed', Array(callShellArray($projDir, Array('installs', 'allSteps'), "LIVE\n")['code'], callShellArray($projDir, Array('installs', 'allSteps'), "\nldap\n")['code']), Array(3, 3));
$jsnVals = runShellAsJson($projDir, Array('installs', 'allSteps', '--noPrompt', '--useStore=none'));
checkIdentical('a store without users of its own', Array($jsnVals['exit'] ?? null, is_file($projDir.DS.'conf'.DS.'users.lst')), Array(4, false));
checkIdentical('without a store, no key of the 2FA secrets is made: the check tells the secrets would be readable', Array(parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? '', stateOf($jsnVals)['2FA key nativeDb'] ?? null), Array('', 'warn'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.cryptKey', 'too-short'));
checkIdentical('a key of the 2FA secrets that is too short fails the check', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['2FA key nativeDb'] ?? null, 'fail');
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.cryptKey', 'env:NYU_TFA_KEY'));
// The access mails : an account of conf/mailer.json, a front-end link holding the code, sign-ups a mail confirms
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.mailAcnt', 'nobody', '--overrule'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.linkTmpl', 'app.example.ch/access', '--overrule'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.codeMins', '0', '--overrule'));
$statLst = stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')));
checkIdentical('an account that is not one, a link that is not one, a code lasting no minute', Array($statLst['access mails nativeDb'] ?? null, $statLst['access link nativeDb'] ?? null, $statLst['access codes nativeDb'] ?? null), Array('fail', 'fail', 'fail'));
writeInProject($projDir, 'conf/mailer.json', '{ "devbox": { "sendWith": "emlFiles", "fromAddr": "dev@example.com", "mailsDir": "temp/mails" } }');
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.mailAcnt', 'devbox'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.linkTmpl', 'https://app.example.ch/access?do=%kind%&code=%code%'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.codeMins', '15'));
$statLst = stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')));
checkIdentical('a mail account that is one, a link holding the code', Array($statLst['access mails nativeDb'] ?? null, isset($statLst['access link nativeDb']), isset($statLst['access codes nativeDb'])), Array('fine', false, false));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.mailAcnt', ''));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.signupOn', 'true', '--overrule'));
checkIdentical('sign-ups without a mail account are told', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['sign-ups nativeDb'] ?? null, 'warn');
callShellArray($projDir, Array('settings', 'setValue', 'access', 'nativeDb.signupOn', 'false'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'tryLimit.maxQueue', '0', '--overrule'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'tryLimit.alertsAt', 'many', '--overrule'));
checkIdentical('sign-up limits that are not numbers fail the check', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['sign-up limits tryLimit'] ?? null, 'fail');
callShellArray($projDir, Array('settings', 'setValue', 'access', 'tryLimit.maxQueue', '256'));
callShellArray($projDir, Array('settings', 'setValue', 'access', 'tryLimit.alertsAt', '[128, 200, 256]'));
checkIdentical('sign-up limits that are numbers', isset(stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['sign-up limits tryLimit']), false);
unlink($projDir.DS.'conf'.DS.'mailer.json');

// What the check refuses
writeInProject($projDir, 'conf/presets/broken.rps', '{ "presetId": "broken", ');
writeInProject($projDir, 'conf/presets/noblok.rps', '{ "presetId": "noblok", "settings": { "fileType": "all" }, "security": { "sessWith": "jwtToken", "sessConf": "nowhere" } }');
writeInProject($projDir, 'conf/presets/norole.rps', '{ "presetId": "norole", "settings": { "fileType": "all" }, "security": { "sessWith": "jwtToken", "sessConf": "jwtToken", "rteRoles": { "users/*": "boss" } } }');
writeInProject($projDir, 'conf/system.ini', buildSystemIni("PRX_LIST = \"10.0.0.5, 0.0.0.0/0\"\n"));
$cookRps = '{ "presetId": "%s", "settings": { "fileType": "all" }, "security": { "sessWith": "phpSessn", "sessConf": "phpSessn" }, "response": { "outputAs": "jsonData", %s"headList": { "access-control-allow-origin": "*" } } }';
writeInProject($projDir, 'conf/presets/cookie.rps', sprintf($cookRps, 'cookie', ''));
writeInProject($projDir, 'conf/presets/listed.rps', sprintf($cookRps, 'listed', '"corsList": ["https://app.example.org"], '));
$statLst = stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')));
checkIdentical('a session cookie with every origin allowed is told, unless the origins are listed', Array($statLst['origins cookie'] ?? null, $statLst['preset cookie'] ?? null, array_key_exists('origins listed', $statLst), array_key_exists('origins defaults', $statLst)), Array('warn', 'fine', false, false));
checkIdentical('a preset that does not load, names a block that is not there, or asks for a role that is not one; proxies trusted from anywhere', Array($statLst['preset broken'] ?? null, $statLst['preset noblok'] ?? null, $statLst['preset norole'] ?? null, $statLst['preset defaults'] ?? null, $statLst['proxies'] ?? null), Array('fail', 'fail', 'fail', 'fine', 'fail'));

// An error level that cannot be read stops every request : the check fails
writeInProject($projDir, 'conf/system.ini', str_replace('ERR_LEVL = E_ALL', 'ERR_LEVL = "E_ALL & ~E_FOO"', buildSystemIni()));
$jsnVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
checkIdentical('an error level that cannot be read fails the check', Array(stateOf($jsnVals)['error level'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['error level'] ?? null), Array('fail', 'ERR_LEVL holds what is not an error level: E_FOO: no request can be served'));
writeInProject($projDir, 'conf/system.ini', buildSystemIni());
checkIdentical('E_ALL, every error is reported', array_column(runShellAsJson($projDir, Array('installs', 'checkAll'))['rows'] ?? Array(), 'found', 'check')['error level'] ?? null, 'every error is reported (E_ALL)');

// The default language of the mails : a tag, or the check fails
checkIdentical('no default language, or a tag: nothing told', Array(array_key_exists('mail language', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))), array_key_exists('mail language', stateOf((function() use ( $projDir ): Array { writeInProject($projDir, 'conf/system.ini', buildSystemIni("LNG_DFLT = fr_CH\n")); return runShellAsJson($projDir, Array('installs', 'checkAll')); })()))), Array(false, false));
writeInProject($projDir, 'conf/system.ini', buildSystemIni("LNG_DFLT = french\n"));
checkIdentical('a default language that is not a tag fails the check', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['mail language'] ?? null, 'fail');
writeInProject($projDir, 'conf/system.ini', buildSystemIni());

// A public key found without its private key : made again, unless the block says the tokens come from another server
if( function_exists('openssl_pkey_new') ){
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf') );
	$srcPubl = strval(openssl_pkey_get_details($keyObjc)['key'] ?? '');
	$projDir = freshPrjt('pubonly');
	runShellAsJson($projDir, Array('installs', 'makeConf'));
	writeInProject($projDir, 'conf/keys/jwtToken.pub', $srcPubl);
	$chkVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
	checkIdentical('in DEV, a login issuing tokens with a public key alone is told', stateOf($chkVals)['token keys jwtToken'] ?? null, 'warn');
	writeInProject($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEV', 'ENV_NAME = PROD', buildSystemIni()));
	checkIdentical('in PROD, it fails the check', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['token keys jwtToken'] ?? null, 'fail');
	writeInProject($projDir, 'conf/system.ini', buildSystemIni());
	runShellAsJson($projDir, Array('installs', 'allSteps', '--noPrompt', '--useStore=userFile'));
	checkIdentical('deploy start makes a pair of its own, the public key found replaced', Array(is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key'), fetchInProject($projDir, 'conf/keys/jwtToken.pub')!==$srcPubl), Array(true, true));
	$projDir = freshPrjt('external');
	runShellAsJson($projDir, Array('installs', 'makeConf'));
	writeInProject($projDir, 'conf/keys/jwtToken.pub', $srcPubl);
	callShellArray($projDir, Array('settings', 'setValue', 'access', 'jwtToken.external', 'true', '--overrule'));
	writeInProject($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEV', 'ENV_NAME = PROD', buildSystemIni()));
	checkIdentical('external: the public key alone is the one of the server issuing the tokens, fine in PROD', stateOf(runShellAsJson($projDir, Array('installs', 'checkAll')))['token keys jwtToken'] ?? null, 'fine');
	writeInProject($projDir, 'conf/system.ini', buildSystemIni());
	runShellAsJson($projDir, Array('installs', 'allSteps', '--noPrompt', '--useStore=userFile'));
	checkIdentical('external: installs allSteps keeps it and makes no pair', Array(is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key'), fetchInProject($projDir, 'conf/keys/jwtToken.pub')), Array(false, $srcPubl));
}

// The rights of the secret files : not read on Windows, which is told
checkIdentical('the rights not checked on Windows are told once; elsewhere, no such row', array_count_values(array_column(runShellAsJson($projDir, Array('installs', 'checkAll'))['rows'] ?? Array(), 'check'))['file rights'] ?? 0, (DS=='\\') ? 1 : 0);

// The entry points : the preset each one starts, open or not, open by intent or not
$projDir = freshPrjt('entries');
runShellAsJson($projDir, Array('installs', 'makeConf'));
writeInProject($projDir, 'conf/presets/showcase.rps', '{ "presetId": "showcase", "settings": { "fileType": "all" }, "security": { "required": false, "isPublic": true } }');
writeInProject($projDir, 'conf/presets/tokens.rps', '{ "presetId": "tokens", "settings": { "fileType": "all" }, "security": { "sessWith": "jwtToken", "sessConf": "jwtToken", "isPublic": true }, "response": { "headList": { "Access-Control-Allow-Origin": "*" } } }');
writeInProject($projDir, 'www/index.php', "<?php\n\$nyuRun = new \\nyu\\Runner();\n");
writeInProject($projDir, 'www/api.php', "<?php\n\$nyuRun = new \\nyu\\Runner( 'secure' );\n");
writeInProject($projDir, 'www/site.php', "<?php\n\$nyuRun = new \\nyu\\Runner('showcase');\n");
writeInProject($projDir, 'www/own.php', "<?php\n\$nyuRun = new \\nyu\\Runner(\$presetNm);\n");
writeInProject($projDir, 'www/plain.php', "<?php echo 1;\n");
$jsnVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
$statLst = stateOf($jsnVals);
$fndList = array_column($jsnVals['rows'] ?? Array(), 'found', 'check');
checkIdentical('in DEV, an entry on an open preset is told; one that checks access, or open by intent, is fine', Array($statLst['entry index'] ?? null, $statLst['entry api'] ?? null, $statLst['entry site'] ?? null, $statLst['entry own'] ?? null, array_key_exists('entry plain', $statLst)), Array('warn', 'fine', 'fine', 'warn', false));
checkIdentical('what the rows say', Array($fndList['entry index'] ?? null, $fndList['entry site'] ?? null), Array('starts defaults: every request is served without a check; name a preset that checks access, or set "isPublic": true in its security block when the site is meant to be open', 'starts showcase, open by intent (isPublic)'));
checkIdentical('public on a preset that checks access, every origin with tokens: both told', Array($statLst['public tokens'] ?? null, $fndList['origins tokens'] ?? null), Array('warn', 'every origin is allowed while the preset reads tokens: list the origins under "corsList"'));
writeInProject($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEV', 'ENV_NAME = PROD', buildSystemIni()));
$jsnVals = runShellAsJson($projDir, Array('installs', 'checkAll'));
checkIdentical('in PROD, an entry on an open preset fails the check; one open by intent does not', Array(stateOf($jsnVals)['entry index'] ?? null, stateOf($jsnVals)['entry site'] ?? null, $jsnVals['exit'] ?? null), Array('fail', 'fine', 4));

finishChecking();
