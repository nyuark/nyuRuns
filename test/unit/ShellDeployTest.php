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
		'conf/presets/default.rps'=>'{ "presetId": "default", "settings": { "confType": "all" }, "security": { "required": false } }',
		'conf/presets/secure.rps'=>'{ "presetId": "secure", "settings": { "confType": "all" }, "security": { "carrWith": "jwtToken", "carrConf": "jwtToken", "minRoles": { "users/*": "managers" } } }'
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
$jsnVals = runShellAsJson($projDir, Array('install', 'testHost'));
$statLst = stateOf($jsnVals);
checkIdentical('servr checks PHP and the folders, which it creates', Array($jsnVals['exit'] ?? null, $statLst['php'] ?? null, $statLst['folder temp/logs'] ?? null, $statLst['folder temp/limits'] ?? null, is_dir($projDir.DS.'temp'.DS.'cache'), is_dir($projDir.DS.'temp'.DS.'archives')), Array(0, 'fine', 'fine', 'fine', true, true));
checkIdentical('files creates the local files from their examples', Array(runShellAsJson($projDir, Array('install', 'makeConf'))['rows'] ?? null, fetchInProject($projDir, '.env'), fetchInProject($projDir, 'conf/access.json')), Array(
	Array(Array('file'=>'.env', 'state'=>'created from .env.example'), Array('file'=>'conf/access.json', 'state'=>'created from conf/access.json.dist'), Array('file'=>'conf/database.json', 'state'=>'not created: its example conf/database.json.dist is missing'), Array('file'=>'conf/mailer.json', 'state'=>'not created: its example conf/mailer.json.dist is missing')),
	"# Copy to .env and fill in\nNYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n", str_replace("\r\n", "\n", distAccss())
));
writeInProject($projDir, '.env', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n");
checkIdentical('a local file that exists is kept', Array(array_column(runShellAsJson($projDir, Array('install', 'makeConf'))['rows'] ?? Array(), 'state', 'file')['.env'] ?? null, fetchInProject($projDir, '.env')), Array('kept', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n"));

// check, before anything is set
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
$statLst = stateOf($jsnVals);
checkIdentical('the health check tells what is missing', Array($jsnVals['exit'] ?? null, $statLst['preset default'] ?? null, $statLst['preset secure'] ?? null, $statLst['access login'] ?? null, $statLst[$tknRow] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $statLst['keys regdKeys'] ?? null, $statLst['errors'] ?? null, ($jsnVals['fail'] ?? 0)>=2),
	Array(4, 'fine', 'fine', 'fine', 'fail', 'fine', 'fail', 'warn', 'fine', true));

// start, without a question
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=userFile'));
$statLst = stateOf($jsnVals);
$envVals = parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW);
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$usrList = array_column(json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'] ?? Array(), null, 'systemName');
$keyText = fetchInProject($projDir, 'conf/keys/jwtToken.key');
checkIdentical('start points the login at the store, makes the keys of the tokens, the secrets and the users (the simple version), and ends on a check whose one fail is the fixture Lost', Array($jsnVals['exit'] ?? null, $axsVals['loginSet']['authWith'] ?? null, $axsVals['loginSet']['authConf'] ?? null, function_exists('openssl_pkey_new') ? is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key') : strlen($envVals['NYU_JWT_SECRET'] ?? '')>=48, $envVals['NYU_API_KEY'] ?? '', array_column($usrList, 'accessRole', 'systemName'), $statLst[$tknRow] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $jsnVals['fail'] ?? null),
	Array(4, 'userFile', 'userFile', true, $jsnVals['secret']['API key'] ?? 'none', Array('userDemo'=>'standard', 'bossDemo'=>'managers'), 'fine', 'fine', 'fine', 1));
// The Runs are the fixtures of the tests: Lost names no action as its default on purpose, its row is the one that fails
checkIdentical('the default of each open Run is told; Lost, naming no action, fails', Array(array_keys(array_filter(stateOf($jsnVals), function( String $rowState ): Bool { return $rowState=='fail'; })), stateOf($jsnVals)['default Probe'] ?? null, stateOf($jsnVals)['default Hooked'] ?? null), Array(Array('default Lost'), 'fine', 'warn'));
checkIdentical('start makes the key of the 2FA secrets with the store, never shown', Array(strlen($envVals['NYU_TFA_KEY'] ?? '')>=48, $statLst['2FA key userFile'] ?? null, isset($jsnVals['secret']['Key of the 2FA secrets'])), Array(true, 'fine', false));
checkIdentical('the passwords of the basic users are shown once', Array(password_verify($jsnVals['secret']['Password of bossDemo (managers)'] ?? '', $usrList['bossDemo']['passwdHash'] ?? ''), password_verify($jsnVals['secret']['Password of userDemo (standard)'] ?? '', $usrList['userDemo']['passwdHash'] ?? ''), isset($jsnVals['secret']['Secret of the tokens'])), Array(true, true, false));
checkIdentical('start writes the presets served, / to the default preset, and the check reads it', Array(json_decode(strval(fetchInProject($projDir, 'conf/doorways.lst')), true)['doorways'] ?? null, $statLst['doorways'] ?? null), Array(Array(Array('basePath'=>'/', 'presetId'=>'default')), 'warn'));
checkIdentical('--noPrompt takes allInOne, which the check reads back', Array(stateOf($jsnVals)['profile'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null), Array('fine', 'allInOne'));
checkPregMatch('the layout of the access config is kept', strval(fetchInProject($projDir, 'conf/access.json')), '/^\{\n\t"plugLibs": \{ "authWith": \[\], "carrWith": \[\] \},\n\t"loginSet": \{ "authWith": "userFile", "authConf": "userFile", /');
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt'));
checkIdentical('run again, nothing that exists is replaced', Array($jsnVals['exit'] ?? null, fetchInProject($projDir, 'conf/keys/jwtToken.key')===$keyText, parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW), json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'][1]['passwdHash'] ?? null, isset($jsnVals['secret']), in_array('The users file already holds users: nothing was changed.', $jsnVals['notes'] ?? Array(), true)), Array(4, true, $envVals, $usrList['bossDemo']['passwdHash'], false, true));
checkPregMatch('the installation is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Deploy: basic users created in userFile \(userDemo, bossDemo\) by user "cli:/');
// A public key laid over the server's own, by a bundle of another pair : the check fails
if( function_exists('openssl_pkey_new') ){
	$pubText = strval(fetchInProject($projDir, 'conf/keys/jwtToken.pub'));
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf') );
	writeInProject($projDir, 'conf/keys/jwtToken.pub', strval(openssl_pkey_get_details($keyObjc)['key'] ?? ''));
	$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
	checkIdentical('a public key that is not the one of the private key fails the check', Array(stateOf($jsnVals)[$tknRow] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')[$tknRow] ?? null),
		Array('fail', 'the public key is not the one of the private key: "setting mkSecret jwtToken" makes a new pair'));
	writeInProject($projDir, 'conf/keys/jwtToken.pub', $pubText);
	checkIdentical('its own public key back, the check is fine', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))[$tknRow] ?? null, 'fine');
}
checkIdentical('users alone: a store that holds users is left as it is; a store that is not one', Array(callShellArray($projDir, Array('install', 'userBase'))['text'], callShellArray($projDir, Array('install', 'userBase', '--useStore=other'))['code']), Array("The users file already holds users: nothing was changed.\n", 2));

// start : the settings of a database are made with the native userbase only
$baseDst = '{ "nyuRuns": { "dbEngine": "mysql_db", "hostName": "localhost", "portNumb": "", "baseName": "nyuRuns", "userName": "nyuruns", "passWord": "env:NYU_DB_PASS", "encoding": "utf8" } }';
$projDir = freshPrjt('nodbase');
writeInProject($projDir, 'conf/database.json.dist', $baseDst);
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=userFile'));
checkIdentical('in the users file, no settings of a database are made, and the check fails on the fixture Lost alone', Array($jsnVals['exit'] ?? null, is_file($projDir.DS.'conf'.DS.'database.json'), stateOf($jsnVals)['databases'] ?? null), Array(4, false, 'fine'));
$projDir = freshPrjt('wthbase');
writeInProject($projDir, 'conf/database.json.dist', $baseDst);
runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=nativeDb'));
checkIdentical('with the native userbase, they are made from their example', fetchInProject($projDir, 'conf/database.json'), $baseDst);
checkIdentical('files alone still makes them all', Array(runShellAsJson(freshPrjt('allfles'), Array('install', 'makeConf'))['rows'][2]['file'] ?? null), Array('conf/database.json'));

// start, with its questions
$projDir = freshPrjt('asked');
$runVals = callShellArray($projDir, Array('install', 'complete', '--sendWith=none', '--silently'), "\n\n\n");
checkIdentical('each question proposes a default, taken on Enter: all in one, the users file, which needs no database', Array($runVals['errs'], strpos(strval(fetchInProject($projDir, 'conf/system.ini')), 'ENV_NAME = DEVS')!==false, is_file($projDir.DS.'conf'.DS.'users.lst')), Array('What this project is (allInOne, authServ, dataServ, openSite) [allInOne]: Environment (DEVS, TEST, PREV, PROD) [DEVS]: Store of the users (nativeDb, userFile, none) [userFile]: Accounts to create: simple (userDemo and bossDemo) or full (userDemo, powrDemo, deptDemo, bossDemo) [simple]: Name of the standard account [userDemo]: Name of the managers account [bossDemo]: Presets to serve, and where (basePath=preset, comma-separated; a host is given with setting entryPts --hostName) [/=default]: ', true, true));
// The basic accounts: the simple or the full version, then each name asked, the one proposed taken on Enter; a version that is neither, a name a user cannot have, or one given twice, creates none
$projDir = freshPrjt('accounts');
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=allInOne', '--useStore=userFile'), "\n\nFull\nann.k\n\n\nBen-2\n");
checkIdentical('names: the full version, the names answered, in the order of the roles', array_column(json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'] ?? Array(), 'accessRole', 'systemName'), Array('ann.k'=>'standard', 'powrDemo'=>'advanced', 'deptDemo'=>'delegate', 'Ben-2'=>'managers'));
$projDir = freshPrjt('refused');
runShellAsJson($projDir, Array('install', 'makeConf'));
$runVals = callShellArray($projDir, Array('install', 'userBase', '--useStore=userFile'), "\nroot\n");
$othVals = callShellArray($projDir, Array('install', 'userBase', '--useStore=userFile'), "half\n");
$twoVals = callShellArray($projDir, Array('install', 'userBase', '--useStore=userFile'), "\nann\nANN\n");
checkIdentical('names: a version that is neither, one a user cannot have, one given twice: refused, nobody created', Array($othVals['code'], $othVals['errs'], $runVals['code'], $runVals['errs'], $twoVals['code'], $twoVals['errs'], is_file($projDir.DS.'conf'.DS.'users.lst')),
	Array(3, "Accounts to create: simple (userDemo and bossDemo) or full (userDemo, powrDemo, deptDemo, bossDemo) [simple]: No user created: \"half\" is neither simple nor full\n", 3, "Accounts to create: simple (userDemo and bossDemo) or full (userDemo, powrDemo, deptDemo, bossDemo) [simple]: Name of the standard account [userDemo]: No user created: \"root\" is not a name a user can have\n", 3, "Accounts to create: simple (userDemo and bossDemo) or full (userDemo, powrDemo, deptDemo, bossDemo) [simple]: Name of the standard account [userDemo]: Name of the managers account [bossDemo]: No user created: two accounts cannot share the name \"ANN\"\n", false));
$projDir = freshPrjt('answer');
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=allInOne'), "prod\nuserFile\n");
$statLst = stateOf($jsnVals);
checkIdentical('the answers are written; ERR_SHOW left on in production is told, where it is ignored', Array($jsnVals['exit'] ?? null, strpos(strval(fetchInProject($projDir, 'conf/system.ini')), "ENV_NAME = PROD\n")!==false, strpos(strval(fetchInProject($projDir, 'conf/system.ini')), '; Environment platform (DEVS, TEST, PREV, PROD)')!==false, count(json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'] ?? Array()), $statLst['errors'] ?? null, $statLst['users'] ?? null), Array(4, true, true, 2, 'warn', 'fine'));
checkPregMatch('the row tells the setting is ignored there', strval(array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['errors'] ?? ''), '/^ERR_SHOW is on in the environment PROD, where it is ignored: set it to FALSE$/');
checkIdentical('once it is off, the row is fine', Array(callShellArray($projDir, Array('setting', 'setValue', 'coreConf', 'ERR_SHOW', 'FALSE'))['code'], stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['errors'] ?? null), Array(0, 'fine'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'phpSessn.secureOn', 'false'));
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
checkIdentical('a session cookie sent over plain HTTP in production is told, without failing the check', Array(json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['phpSessn']['secureOn'] ?? null, stateOf($jsnVals)['cookie phpSessn'] ?? null, $jsnVals['exit'] ?? null), Array(false, 'warn', 4));
// The issuer and the audiences of the tokens, the previous key, the audiences of the application keys
checkIdentical('tokens bound to no project are told, without failing the check', Array(stateOf($jsnVals)['token claims jwtToken'] ?? null, $jsnVals['exit'] ?? null), Array('warn', 4));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'jwtToken.issuerId', 'https://auth.example.org'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'jwtToken.audience', '["api.example.org"]'));
checkIdentical('once the issuer and the audiences are set, the row is fine', array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'found', 'check')['token claims jwtToken'] ?? null, 'issuer "https:\/\/auth.example.org", audience ["api.example.org"]');
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$axsVals['jwtToken']['issuerId'] = 42;
$axsVals['jwtToken']['prevFile'] = 'conf/access.json';
$axsVals['appsKeys'] = Array('headName'=>'X-App-Key', 'appsList'=>Array('portal'=>Array('keyDigst'=>'', 'issuerId'=>Array('x'))), 'audience'=>Array());
$axsVals['apkeyz'] = Array('headName'=>'X-App-Key', 'appsList'=>Array(), 'audience'=>Array());
writeInProject($projDir, 'conf/access.json', strval(json_encode($axsVals, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)));
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
$fndList = array_column($jsnVals['rows'] ?? Array(), 'found', 'check');
checkIdentical('values of the wrong kind fail the check', Array(stateOf($jsnVals)['token claims jwtToken'] ?? null, $fndList['token claims jwtToken'] ?? null, stateOf($jsnVals)['token claims appsKeys'] ?? null, $fndList['token claims appsKeys'] ?? null),
	Array('fail', 'issuer is a text; prevFile is not the public RSA key of 2048 bits at least: empty it, or "setting mkSecret jwtToken" writes it', 'fail', 'the issuer of the application "portal" is a text'));
checkIdentical('application keys without the audiences of this project are told', stateOf($jsnVals)['token claims apkeyz'] ?? null, 'warn');
$axsVals['jwtToken']['issuerId'] = 'https://auth.example.org';
$axsVals['jwtToken']['prevFile'] = 'conf/keys/jwtToken.pub';
$axsVals['jwtToken']['signAlgo'] = 'HS256';
unset($axsVals['appsKeys'], $axsVals['apkeyz']);
writeInProject($projDir, 'conf/access.json', strval(json_encode($axsVals, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)));
if( function_exists('openssl_pkey_new') ){
	checkIdentical('a previous key with HS256 is told, as it is not read', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['previous key jwtToken'] ?? null, 'warn');
}
$projDir = freshPrjt('wrongs');
checkIdentical('an answer that is not one of those proposed', Array(callShellArray($projDir, Array('install', 'complete', '--sendWith=none'), "website\n")['code'], callShellArray($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=allInOne'), "LIVE\n")['code'], callShellArray($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=allInOne'), "\nldap\n")['code']), Array(3, 3, 3));
// No store is taken only for a login that needs none: here, through the htpasswd file
callShellArray($projDir, Array('install', 'makeConf'));
$axsTree = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$axsTree['loginSet'] = Array('authWith'=>'htpasswd', 'authConf'=>'htpasswd') + (array)($axsTree['loginSet'] ?? Array());
writeInProject($projDir, 'conf/access.json', strval(json_encode($axsTree)));
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=none'));
checkIdentical('a store without users of its own',Array($jsnVals['exit'] ?? null, is_file($projDir.DS.'conf'.DS.'users.lst')), Array(4, false));
checkIdentical('without a store, no key of the 2FA secrets is made: the check tells the secrets would be readable', Array(parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? '', stateOf($jsnVals)['2FA key nativeDb'] ?? null), Array('', 'warn'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.tfaCrypt', 'too-short'));
checkIdentical('a key of the 2FA secrets that is too short fails the check', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['2FA key nativeDb'] ?? null, 'fail');
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.tfaCrypt', 'env:NYU_TFA_KEY'));
// The access mails : an account of conf/mailer.json, a front-end link holding the code, sign-ups a mail confirms
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.mailAcnt', 'nobody', '--overrule'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.linkTmpl', 'app.example.ch/access', '--overrule'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.codeMins', '0', '--overrule'));
$statLst = stateOf(runShellAsJson($projDir, Array('install', 'checkAll')));
checkIdentical('an account that is not one, a link that is not one, a code lasting no minute', Array($statLst['access mails nativeDb'] ?? null, $statLst['access link nativeDb'] ?? null, $statLst['access codes nativeDb'] ?? null), Array('fail', 'fail', 'fail'));
writeInProject($projDir, 'conf/mailer.json', '{ "devbox": { "sendWith": "emlFiles", "fromAddr": "dev@example.com", "mailsDir": "temp/mails" } }');
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.mailAcnt', 'devbox'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.linkTmpl', 'https://app.example.ch/access?do=%mailKind%&code=%sentCode%'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.codeMins', '15'));
$statLst = stateOf(runShellAsJson($projDir, Array('install', 'checkAll')));
checkIdentical('a mail account that is one, a link holding the code', Array($statLst['access mails nativeDb'] ?? null, isset($statLst['access link nativeDb']), isset($statLst['access codes nativeDb'])), Array('fine', false, false));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.mailAcnt', ''));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.signupOn', 'true', '--overrule'));
checkIdentical('sign-ups without a mail account are told', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['sign-ups nativeDb'] ?? null, 'warn');
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.signupOn', 'false'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'tryLimit.maxQueue', '0', '--overrule'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'tryLimit.alertsAt', 'many', '--overrule'));
checkIdentical('sign-up limits that are not numbers fail the check', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['sign-up limits tryLimit'] ?? null, 'fail');
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'tryLimit.maxQueue', '256'));
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'tryLimit.alertsAt', '[128, 200, 256]'));
checkIdentical('sign-up limits that are numbers', isset(stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['sign-up limits tryLimit']), false);
unlink($projDir.DS.'conf'.DS.'mailer.json');

// What the check refuses
writeInProject($projDir, 'conf/presets/broken.rps', '{ "presetId": "broken", ');
writeInProject($projDir, 'conf/presets/noblok.rps', '{ "presetId": "noblok", "settings": { "confType": "all" }, "security": { "carrWith": "jwtToken", "carrConf": "nowhere" } }');
writeInProject($projDir, 'conf/presets/norole.rps', '{ "presetId": "norole", "settings": { "confType": "all" }, "security": { "carrWith": "jwtToken", "carrConf": "jwtToken", "minRoles": { "users/*": "boss" } } }');
writeInProject($projDir, 'conf/system.ini', buildSystemIni("PRX_LIST = \"10.0.0.5, 0.0.0.0/0\"\n"));
$cookRps = '{ "presetId": "%s", "settings": { "confType": "all" }, "security": { "carrWith": "phpSessn", "carrConf": "phpSessn" }, "response": { "outputAs": "jsonData", %s"headList": { "access-control-allow-origin": "*" } } }';
writeInProject($projDir, 'conf/presets/cookie.rps', sprintf($cookRps, 'cookie', ''));
writeInProject($projDir, 'conf/presets/listed.rps', sprintf($cookRps, 'listed', '"corsList": ["https://app.example.org"], '));
$statLst = stateOf(runShellAsJson($projDir, Array('install', 'checkAll')));
checkIdentical('a session cookie with every origin allowed is told, unless the origins are listed', Array($statLst['origins cookie'] ?? null, $statLst['preset cookie'] ?? null, array_key_exists('origins listed', $statLst), array_key_exists('origins default', $statLst)), Array('warn', 'fine', false, false));
checkIdentical('a preset that does not load, names a block that is not there, or asks for a role that is not one; proxies trusted from anywhere', Array($statLst['preset broken'] ?? null, $statLst['preset noblok'] ?? null, $statLst['preset norole'] ?? null, $statLst['preset default'] ?? null, $statLst['proxies'] ?? null), Array('fail', 'fail', 'fail', 'fine', 'fail'));

// An error level that cannot be read stops every request : the check fails
writeInProject($projDir, 'conf/system.ini', str_replace('ERR_LEVL = E_ALL', 'ERR_LEVL = "E_ALL & ~E_FOO"', buildSystemIni()));
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
checkIdentical('an error level that cannot be read fails the check', Array(stateOf($jsnVals)['error level'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['error level'] ?? null), Array('fail', 'ERR_LEVL holds what is not an error level: E_FOO: no request can be served'));
writeInProject($projDir, 'conf/system.ini', buildSystemIni());
checkIdentical('E_ALL, every error is reported', array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'found', 'check')['error level'] ?? null, 'every error is reported (E_ALL)');

// The default language of the mails : a tag, or the check fails
checkIdentical('no default language, or a tag: nothing told', Array(array_key_exists('mail language', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))), array_key_exists('mail language', stateOf((function() use ( $projDir ): Array { writeInProject($projDir, 'conf/system.ini', buildSystemIni("DEF_LANG = fr_CH\n")); return runShellAsJson($projDir, Array('install', 'checkAll')); })()))), Array(false, false));
writeInProject($projDir, 'conf/system.ini', buildSystemIni("DEF_LANG = french\n"));
checkIdentical('a default language that is not a tag fails the check', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['mail language'] ?? null, 'fail');
writeInProject($projDir, 'conf/system.ini', buildSystemIni());

// A public key found without its private key : made again, unless the block says the tokens come from another server
if( function_exists('openssl_pkey_new') ){
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf') );
	$srcPubl = strval(openssl_pkey_get_details($keyObjc)['key'] ?? '');
	$projDir = freshPrjt('pubonly');
	runShellAsJson($projDir, Array('install', 'makeConf'));
	writeInProject($projDir, 'conf/keys/jwtToken.pub', $srcPubl);
	$chkVals = runShellAsJson($projDir, Array('install', 'checkAll'));
	checkIdentical('in DEVS, a login issuing tokens with a public key alone is told', stateOf($chkVals)['token keys jwtToken'] ?? null, 'warn');
	writeInProject($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEVS', 'ENV_NAME = PROD', buildSystemIni()));
	checkIdentical('in PROD, it fails the check', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['token keys jwtToken'] ?? null, 'fail');
	writeInProject($projDir, 'conf/system.ini', buildSystemIni());
	runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=userFile'));
	checkIdentical('deploy start makes a pair of its own, the public key found replaced', Array(is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key'), fetchInProject($projDir, 'conf/keys/jwtToken.pub')!==$srcPubl), Array(true, true));
	$projDir = freshPrjt('external');
	runShellAsJson($projDir, Array('install', 'makeConf'));
	writeInProject($projDir, 'conf/keys/jwtToken.pub', $srcPubl);
	callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'jwtToken.external', 'true', '--overrule'));
	writeInProject($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEVS', 'ENV_NAME = PROD', buildSystemIni()));
	checkIdentical('external: the public key alone is the one of the server issuing the tokens, fine in PROD', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['token keys jwtToken'] ?? null, 'fine');
	writeInProject($projDir, 'conf/system.ini', buildSystemIni());
	runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=userFile'));
	checkIdentical('external: install complete keeps it and makes no pair', Array(is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key'), fetchInProject($projDir, 'conf/keys/jwtToken.pub')), Array(false, $srcPubl));
}

// The profile of the project : an authentication server, a resource server, read back by the check
if( function_exists('openssl_pkey_new') ){
	$projDir = freshPrjt('authsrv');
	$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=authServ', '--useStore=userFile'), "https://auth.example.org\napi.example.org, app.example.org\n\n\n\n\n\n");
	$tknVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['jwtToken'] ?? Array();
	checkIdentical('authServ: RS256 with a pair of its own, the issuer and the audiences asked, users in its store', Array($tknVals['signAlgo'] ?? null, $tknVals['issuerId'] ?? null, $tknVals['audience'] ?? null, is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key'), is_file($projDir.DS.'conf'.DS.'users.lst'), stateOf($jsnVals)['profile'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null),
		Array('RS256', 'https://auth.example.org', Array('api.example.org', 'app.example.org'), true, true, 'fine', 'authServ'));
	$projDir = freshPrjt('authnop');
	$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=authServ', '--noPrompt'));
	checkIdentical('authServ without a question: the host name as issuer, no audience, which the check tells', Array(json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['jwtToken']['issuerId'] ?? null, stateOf($jsnVals)['profile'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null),
		Array(strval(gethostname()), 'warn', 'authServ: no audience, the tokens are not bound to the projects accepting them'));
	// allInOne, when its login works through a store, refuses to go without it
	$noneDir = freshPrjt('allnone');
	$runVals = callShellArray($noneDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=none'));
	checkIdentical('allInOne without a store, its login working through one: refused, no users made', Array($runVals['code'], strpos($runVals['errs'], 'A project with users of its own (allInOne) logs them in through a store')!==false, is_file($noneDir.DS.'conf'.DS.'users.lst')), Array(3, true, false));
	checkIdentical('authServ without a store is refused', callShellArray(freshPrjt('authnone'), Array('install', 'complete', '--sendWith=none', '--projKind=authServ', '--noPrompt', '--useStore=none'))['code'], 3);

	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf') );
	$srcPubl = strval(openssl_pkey_get_details($keyObjc)['key'] ?? '');
	$projDir = freshPrjt('datasrv');
	writeInProject($projDir, 'temp/issuer.pub', $srcPubl);
	$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=dataServ'), "temp/issuer.pub\nhttps://auth.example.org\napi.example.org\n\n");
	$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
	checkIdentical('dataServ: the public key copied and alone, the claims asked, a login with no credential check, no users', Array($axsVals['jwtToken']['signAlgo'] ?? null, $axsVals['jwtToken']['publFile'] ?? null, $axsVals['jwtToken']['external'] ?? null, array_key_exists('privFile', $axsVals['jwtToken'] ?? Array()), $axsVals['jwtToken']['issuerId'] ?? null, $axsVals['jwtToken']['audience'] ?? null, array_key_exists('authWith', $axsVals['loginSet'] ?? Array()), fetchInProject($projDir, 'conf/keys/jwtToken.pub'), is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key'), is_file($projDir.DS.'conf'.DS.'users.lst')),
		Array('RS256', 'conf/keys/jwtToken.pub', true, false, 'https://auth.example.org', Array('api.example.org'), false, $srcPubl, false, false));
	checkIdentical('dataServ: the check reads it back, its key fine', Array(stateOf($jsnVals)['profile'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null, stateOf($jsnVals)['token keys jwtToken'] ?? null), Array('fine', 'dataServ', 'fine'));
	$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=dataServ', '--noPrompt'));
	checkIdentical('dataServ again without a question: the key in use kept', Array(fetchInProject($projDir, 'conf/keys/jwtToken.pub'), stateOf($jsnVals)['profile'] ?? null), Array($srcPubl, 'fine'));
	unlink($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.pub');
	checkIdentical('dataServ: a public key file missing is told', array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'found', 'check')['profile'] ?? null, 'dataServ: the public key file conf/keys/jwtToken.pub is missing: copy the one of the authentication server');
	writeInProject($projDir, 'conf/keys/jwtToken.pub', $srcPubl);
	callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'jwtToken.audience', '[]'));
	checkIdentical('dataServ: no audience is told', array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'found', 'check')['profile'] ?? null, 'dataServ: no audience');
	$axsVals['loginSet']['authWith'] = 'userFile';
	$axsVals['loginSet']['authConf'] = 'userFile';
	writeInProject($projDir, 'conf/access.json', strval(json_encode($axsVals, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)));
	$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
	checkPregMatch('a public key alone beside a users\' store makes none of the three', strval(array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? ''), '/^none of allInOne, authServ, dataServ, openSite: the tokens are checked with a public key alone/');
	// Without a question on a new project: the options give the key, the issuer and the audiences
	$optsDir = freshPrjt('dataopt');
	writeInProject($optsDir, 'temp/issuer.pub', $srcPubl);
	$jsnVals = runShellAsJson($optsDir, Array('install', 'complete', '--sendWith=none', '--projKind=dataServ', '--noPrompt', '--publFile=temp/issuer.pub', '--issuerId=https://auth.example.org', '--audience=api.example.org, files.example.org'));
	$axsVals = json_decode(strval(fetchInProject($optsDir, 'conf/access.json')), true);
	checkIdentical('dataServ without a question: --publFile, --issuerId and --audience answer it', Array(fetchInProject($optsDir, 'conf/keys/jwtToken.pub'), $axsVals['jwtToken']['external'] ?? null, $axsVals['jwtToken']['issuerId'] ?? null, $axsVals['jwtToken']['audience'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null, stateOf($jsnVals)['profile'] ?? null),
		Array($srcPubl, true, 'https://auth.example.org', Array('api.example.org', 'files.example.org'), 'dataServ', 'fine'));
	checkPregMatch('dataServ without a question nor the option: stopped, the option named', callShellArray(freshPrjt('datanof'), Array('install', 'complete', '--sendWith=none', '--projKind=dataServ', '--noPrompt'))['errs'], '/Nothing was installed: no file of the public key given \(--publFile names it\)/');
	$jsnVals = runShellAsJson(freshPrjt('authopt'), Array('install', 'complete', '--sendWith=none', '--projKind=authServ', '--noPrompt', '--issuerId=https://auth.example.org', '--audience=api.example.org'));
	checkIdentical('authServ without a question: the two options bind its tokens', Array(stateOf($jsnVals)['profile'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null), Array('fine', 'authServ'));
	checkIdentical('dataServ: a store, a missing key, or a file that is not a public key: refused', Array(callShellArray(freshPrjt('datastr'), Array('install', 'complete', '--sendWith=none', '--projKind=dataServ', '--useStore=userFile'))['code'], callShellArray(freshPrjt('datanop'), Array('install', 'complete', '--sendWith=none', '--projKind=dataServ', '--noPrompt'))['code'], callShellArray(freshPrjt('databad'), Array('install', 'complete', '--sendWith=none', '--projKind=dataServ'), "conf/access.json.dist\n")['code']), Array(2, 3, 3));
}

// An open site : nothing to log in to, only presets that reach no action, closed again once it has users
$sttcRps = strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'presets'.DS.'statics.rps'));
$sttcCfg = json_decode($sttcRps, true)['dispatch'] ?? Array();
checkIdentical('statics with "*": false reaches no action; with the Runs mounted, it does', Array((new nyu\Tools\Router($sttcCfg, nyu\Shell\ActCaller::makeRequestLst('GET', '/')[0]))->canReachAction(), (new nyu\Tools\Router(Array('runMount'=>Array('auth'=>false)) + $sttcCfg, nyu\Shell\ActCaller::makeRequestLst('GET', '/')[0]))->canReachAction()), Array(false, true));
checkIdentical('"*" names every Run not named on its own line', Array((new nyu\Tools\Router(Array('runMount'=>Array('*'=>false, 'auth'=>'login')), nyu\Shell\ActCaller::makeRequestLst('GET', '/')[0]))->mountRoutePath('Auth'), (new nyu\Tools\Router(Array('runMount'=>Array('*'=>false, 'auth'=>'login')), nyu\Shell\ActCaller::makeRequestLst('GET', '/')[0]))->mountRoutePath('Users')), Array('login', null));
$projDir = freshPrjt('opensite');
writeInProject($projDir, 'conf/presets/statics.rps', $sttcRps);
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=openSite', '--noPrompt'));
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
checkIdentical('openSite: no store, no users, no token keys nor secrets, the map pointing at statics, read back by the check', Array(array_key_exists('authWith', $axsVals['loginSet'] ?? Array()), is_file($projDir.DS.'conf'.DS.'users.lst'), is_file($projDir.DS.'conf'.DS.'keys'.DS.'jwtToken.key'), parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_API_KEY'] ?? '', json_decode(strval(fetchInProject($projDir, 'conf/doorways.lst')), true)['doorways'] ?? null, stateOf($jsnVals)['profile'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null),
	Array(false, false, false, '', Array(Array('basePath'=>'/', 'presetId'=>'statics')), 'fine', 'openSite'));
if( extension_loaded('openssl') ){
	// An open site reads no token: the keys it has none of are not asked for
	checkIdentical('openSite: the token keys are asked of nobody', Array(stateOf($jsnVals)['token keys jwtToken'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['token keys jwtToken'] ?? null), Array('fine', 'none, and none needed: no login, and every preset served is open'));
	writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "/", "presetId": "statics" }, { "basePath": "/app", "presetId": "secure" } ] }');
	checkIdentical('a preset of the map checking access: the keys are asked for again', array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'found', 'check')['token keys jwtToken'] ?? null, 'missing: "setting mkSecret jwtToken" makes them');
	writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "/", "presetId": "statics" } ] }');
}
checkIdentical('openSite: a preset of the map reaching an action is never opened, nor the map written', Array(callShellArray($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=openSite'), "\n/=default\n")['code'], json_decode(strval(fetchInProject($projDir, 'conf/doorways.lst')), true)['doorways'][0]['presetId'] ?? null, array_key_exists('isPublic', json_decode(strval(fetchInProject($projDir, 'conf/presets/default.rps')), true)['security'] ?? Array())), Array(3, 'statics', false));
checkIdentical('openSite: a store refused', callShellArray($projDir, Array('install', 'complete', '--sendWith=none', '--projKind=openSite', '--noPrompt', '--useStore=userFile'))['code'], 2);
writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "/", "presetId": "statics" }, { "basePath": "/app", "presetId": "default" } ] }');
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
checkIdentical('openSite: the check fails when an open preset of the map reaches an action', Array(stateOf($jsnVals)['profile'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['profile'] ?? null), Array('fail', 'openSite, but default can reach an action: a Run is public without anyone deciding it; serve statics, or a preset that serves no Run, or open the Run on purpose (openList) in a preset that checks access'));
writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "/", "presetId": "statics" }, { "basePath": "/app", "presetId": "secure" } ] }');
checkIdentical('no store, a preset of the map checking access: none of the four', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['profile'] ?? null, 'warn');
writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "/", "presetId": "statics" } ] }');
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=userFile'));
$sttcSec = json_decode(strval(fetchInProject($projDir, 'conf/presets/statics.rps')), true)['security'] ?? Array();
checkIdentical('given a store, the presets the open site had opened are closed again, and named', Array($sttcSec['required'] ?? null, array_key_exists('isPublic', $sttcSec), in_array('Preset statics closed, with the carrier of the login (jwtToken): the project has users now; open it again on purpose when it is meant to be public.', $jsnVals['notes'] ?? Array(), true), stateOf($jsnVals)['profile'] ?? null), Array(true, false, true, 'fine'));
// A preset closed without a carrier would refuse every request: it takes the one of the login, and its row of the check passes
checkIdentical('the closed preset has the carrier of the login, and loads as a preset that checks access', Array($sttcSec['carrWith'] ?? null, $sttcSec['carrConf'] ?? null, stateOf($jsnVals)['preset statics'] ?? null), Array('jwtToken', 'jwtToken', 'fine'));
// One that names its own carrier keeps it
writeInProject($projDir, 'conf/presets/statics.rps', strval(json_encode(Array('security'=>Array('required'=>false, 'isPublic'=>true, 'carrWith'=>'phpSessn', 'carrConf'=>'phpSessn')) + (array)json_decode(strval(fetchInProject($projDir, 'conf/presets/statics.rps')), true))));
$jsnVals = runShellAsJson($projDir, Array('install', 'complete', '--sendWith=none', '--noPrompt', '--useStore=userFile'));
$sttcSec = json_decode(strval(fetchInProject($projDir, 'conf/presets/statics.rps')), true)['security'] ?? Array();
checkIdentical('a preset naming its own carrier keeps it when closed', Array($sttcSec['required'] ?? null, $sttcSec['carrWith'] ?? null, in_array('Preset statics closed: the project has users now; open it again on purpose when it is meant to be public.', $jsnVals['notes'] ?? Array(), true)), Array(true, 'phpSessn', true));

// The rights of the secret files : not read on Windows, which is told
checkIdentical('the rights not checked on Windows are told once; elsewhere, no such row', array_count_values(array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'check'))['file rights'] ?? 0, (DS=='\\') ? 1 : 0);

// The entry points : the preset each one starts, open or not, open by intent or not
$projDir = freshPrjt('entries');
runShellAsJson($projDir, Array('install', 'makeConf'));
writeInProject($projDir, 'conf/presets/statics.rps', '{ "presetId": "statics", "settings": { "confType": "all" }, "security": { "required": false, "isPublic": true } }');
writeInProject($projDir, 'conf/presets/tokens.rps', '{ "presetId": "tokens", "settings": { "confType": "all" }, "security": { "carrWith": "jwtToken", "carrConf": "jwtToken", "isPublic": true }, "response": { "headList": { "Access-Control-Allow-Origin": "*" } } }');
writeInProject($projDir, 'www/index.php', "<?php\n\$nyuRun = new \\nyu\\Runner();\n");
writeInProject($projDir, 'www/api.php', "<?php\n\$nyuRun = new \\nyu\\Runner( 'secure' );\n");
writeInProject($projDir, 'www/site.php', "<?php\n\$nyuRun = new \\nyu\\Runner('statics');\n");
writeInProject($projDir, 'www/own.php', "<?php\n\$nyuRun = new \\nyu\\Runner(\$presetNm);\n");
writeInProject($projDir, 'www/plain.php', "<?php echo 1;\n");
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
$statLst = stateOf($jsnVals);
$fndList = array_column($jsnVals['rows'] ?? Array(), 'found', 'check');
checkIdentical('in DEVS, an entry on an open preset is told; one that checks access, or open by intent, is fine', Array($statLst['entry index'] ?? null, $statLst['entry api'] ?? null, $statLst['entry site'] ?? null, $statLst['entry own'] ?? null, array_key_exists('entry plain', $statLst)), Array('warn', 'fine', 'fine', 'warn', false));
checkIdentical('what the rows say', Array($fndList['entry index'] ?? null, $fndList['entry site'] ?? null), Array('starts default: every request is served without a check; name a preset that checks access, or set "isPublic": true in its security block when the site is meant to be open', 'starts statics, open by intent (isPublic)'));
checkIdentical('public on a preset that checks access, every origin with tokens: both told', Array($statLst['public tokens'] ?? null, $fndList['origins tokens'] ?? null), Array('warn', 'every origin is allowed while the preset reads tokens: list the origins under "corsList"'));
writeInProject($projDir, 'conf/presets/example.rps', '{ "presetId": "example", "settings": { "confType": "all" } }');
writeInProject($projDir, 'www/docs.php', "<?php\n\$nyuRun = new \\nyu\\Runner('example');\n");
$fndList = stateOf(runShellAsJson($projDir, Array('install', 'checkAll')));
checkIdentical('the example preset: its keys checked, never loaded; an entry starting it fails', Array($fndList['preset example'] ?? null, $fndList['entry docs'] ?? null), Array('fine', 'fail'));
@unlink($projDir.DS.'www'.DS.'docs.php');
@unlink($projDir.DS.'conf'.DS.'presets'.DS.'example.rps');
writeInProject($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEVS', 'ENV_NAME = PROD', buildSystemIni()));
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
checkIdentical('in PROD, an entry on an open preset fails the check; one open by intent does not', Array(stateOf($jsnVals)['entry index'] ?? null, stateOf($jsnVals)['entry site'] ?? null, $jsnVals['exit'] ?? null), Array('fail', 'fine', 4));

// The map of the presets served, conf/doorways.lst
$doorRow = function( Array $commandReply ): Array {
	$rowLst = Array();
	foreach( $commandReply['rows'] ?? Array() as $oneRow ){
		if( ($oneRow['check'] ?? '')=='doorways' ){
			$rowLst[] = $oneRow['state'];
		}
	}
	return $rowLst;
};
writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "/", "presetId": "default" }, { "basePath": "/", "presetId": "secure" }, { "basePath": "/docs", "presetId": "example" }, { "basePath": "/x", "presetId": "nosuch" }, { "hostName": "api.example.org", "basePath": "/", "presetId": "secure" } ] }');
checkIdentical('doorways: an open preset without isPublic told, a second line for the same host and basePath, the example and a preset that does not load: failed', $doorRow(runShellAsJson($projDir, Array('install', 'checkAll'))), Array('warn', 'fail', 'fail', 'fail'));
writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "/api", "presetId": "secure" }, { "hostName": "api.example.org", "basePath": "/api", "presetId": "secure" } ] }');
checkIdentical('doorways: presets that check access, the same basePath on another host: fine', $doorRow(runShellAsJson($projDir, Array('install', 'checkAll'))), Array('fine'));
writeInProject($projDir, 'conf/doorways.lst', '{ "doorways": [ { "basePath": "api", "presetId": "secure" } ] }');
checkIdentical('doorways: a map that cannot be read fails', $doorRow(runShellAsJson($projDir, Array('install', 'checkAll'))), Array('fail'));
@unlink($projDir.DS.'conf'.DS.'doorways.lst');
checkIdentical('doorways: no map, every request to the default preset', $doorRow(runShellAsJson($projDir, Array('install', 'checkAll'))), Array('fine'));

// The access mails : the example of the mail accounts, the questions asked with the native userbase alone, the test mail
$mailDst = strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'mailer.json.dist'));
// A database file nothing opens: the mail questions come before the users, which have no schema here
$liteDst = '{ "nyuRuns": { "dbEngine": "sqlitedb", "hostName": "", "portNumb": "", "baseName": "temp/none.sqlite", "userName": "", "passWord": "" } }';
$typeTxt = class_exists('\\PHPMailer\\PHPMailer\\PHPMailer') ? 'selfSmtp, phpMailr, emlFiles' : 'selfSmtp, emlFiles';
$projDir = freshPrjt('mailfle');
writeInProject($projDir, 'conf/mailer.json.dist', $mailDst);
checkIdentical('makeConf creates the mail accounts from their example, and keeps those that exist', Array(array_column(runShellAsJson($projDir, Array('install', 'makeConf'))['rows'] ?? Array(), 'state', 'file')['conf/mailer.json'] ?? null, fetchInProject($projDir, 'conf/mailer.json'), array_column(runShellAsJson($projDir, Array('install', 'makeConf'))['rows'] ?? Array(), 'state', 'file')['conf/mailer.json'] ?? null), Array('created from conf/mailer.json.dist', str_replace("\r\n", "\n", $mailDst), 'kept'));
$projDir = freshPrjt('mailnop');
writeInProject($projDir, 'conf/database.json.dist', $liteDst);
writeInProject($projDir, 'conf/mailer.json.dist', $mailDst);
runShellAsJson($projDir, Array('install', 'complete', '--noPrompt', '--useStore=nativeDb'));
$ntvVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['nativeDb'] ?? Array();
checkIdentical('mails: --noPrompt takes none, no account is made, the codes stay in the replies', Array(is_file($projDir.DS.'conf'.DS.'mailer.json'), $ntvVals['mailAcnt'] ?? null, $ntvVals['signupOn'] ?? null), Array(false, '', false));
checkIdentical('mails: a test mail without an account named, or without an address, is refused', Array(callShellArray($projDir, Array('install', 'testMail', 'ann@example.org'))['code'], callShellArray($projDir, Array('install', 'testMail', '--noPrompt'))['code']), Array(3, 2));
checkIdentical('mails: an account that sends cannot be asked its server with --noPrompt; a type that is not one', Array(callShellArray($projDir, Array('install', 'complete', '--noPrompt', '--useStore=nativeDb', '--sendWith=selfSmtp'))['code'], callShellArray($projDir, Array('install', 'complete', '--noPrompt', '--useStore=nativeDb', '--sendWith=sendmail'))['code'], is_file($projDir.DS.'conf'.DS.'mailer.json')), Array(3, 3, false));
runShellAsJson($projDir, Array('install', 'complete', '--noPrompt', '--useStore=nativeDb', '--sendWith=EMLFILES', '--signupOn'));
$ntvVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['nativeDb'] ?? Array();
checkIdentical('mails: --sendWith and --signupOn answer beforehand; the file made holds that account alone, its mails kept in temp/mails', Array(json_decode(strval(fetchInProject($projDir, 'conf/mailer.json')), true), $ntvVals['mailAcnt'] ?? null, $ntvVals['signupOn'] ?? null),
	Array(Array('nyuRuns'=>Array('sendWith'=>'emlFiles', 'hostName'=>'', 'portNumb'=>'', 'userName'=>'', 'passWord'=>'', 'fromAddr'=>'no-reply@example.com', 'fromName'=>'nyuRuns', 'waitSecs'=>'', 'mailsDir'=>'', 'copiesTo'=>'')), 'nyuRuns', true));
// The questions, in their order, after the store and its database, before the users and the map
$projDir = freshPrjt('mailask');
writeInProject($projDir, 'conf/database.json.dist', $liteDst);
$runVals = callShellArray($projDir, Array('install', 'complete', '--projKind=allInOne', '--useStore=nativeDb', '--silently'), "\n\n\n\nyes\nemlfiles\nnews@example.org\nThe Team\noffice\nhttps://app.example.org/access?do=%mailKind%&code=%sentCode%\nyes\nann@example.org\n\n");
$ntvVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['nativeDb'] ?? Array();
$emlList = glob($projDir.DS.'temp'.DS.'mails'.DS.'*.eml') ?: Array();
checkIdentical('mails: asked right after the store of the native userbase', strpos($runVals['errs'], 'Database file [temp/none.sqlite]: Send the access mails (activation, reset, sign-ups)? (yes/no) [no]: Mail account type ('.$typeTxt.') [selfSmtp]: Sender address [no-reply@example.com]: Sender name [nyuRuns]: Name of the mail account [nyuRuns]: Front-end link of the mails (empty for none): Open the sign-ups? (yes/no) [no]: Send a test mail to (empty for none): ')!==false, true);
checkIdentical('mails: the account written under the name asked and named in the block, the link, the sign-ups, the test mail written', Array(json_decode(strval(fetchInProject($projDir, 'conf/mailer.json')), true)['office'] ?? null, $ntvVals['mailAcnt'] ?? null, $ntvVals['linkTmpl'] ?? null, $ntvVals['signupOn'] ?? null, count($emlList), strpos(strval(@file_get_contents($emlList[0] ?? '')), 'ann@example.org')!==false),
	Array(Array('sendWith'=>'emlFiles', 'hostName'=>'', 'portNumb'=>'', 'userName'=>'', 'passWord'=>'', 'fromAddr'=>'news@example.org', 'fromName'=>'The Team', 'waitSecs'=>'', 'mailsDir'=>'', 'copiesTo'=>''), 'office', 'https://app.example.org/access?do=%mailKind%&code=%sentCode%', true, 1, true));
checkIdentical('mails: the check finds the account', stateOf(runShellAsJson($projDir, Array('install', 'checkAll')))['access mails nativeDb'] ?? null, 'fine');
// install testMail : through the account the block names, nothing changed
$axsText = fetchInProject($projDir, 'conf/access.json');
$jsnVals = runShellAsJson($projDir, Array('install', 'testMail', 'ben@example.org'));
checkIdentical('testMail: written by an emlFiles account, which is told; nothing is changed', Array($jsnVals['exit'] ?? null, in_array('Test mail to ben@example.org written as a file by the account office (emlFiles): nothing was sent.', $jsnVals['notes'] ?? Array(), true), count(glob($projDir.DS.'temp'.DS.'mails'.DS.'*.eml') ?: Array()), fetchInProject($projDir, 'conf/access.json')===$axsText), Array(0, true, 2, true));
checkPregMatch('testMail: logged on runner as the other mails', strval(fetchInProject($projDir, 'temp/logs/runner.log')), '/Mail sent to \["ben@example\.org"\] through account "office"/');
checkIdentical('testMail: the address asked when it is missing', callShellArray($projDir, Array('install', 'testMail'), "cat@example.org\n")['code'], 0);
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'nativeDb.mailAcnt', 'nobody'));
$runVals = callShellArray($projDir, Array('install', 'testMail', 'ben@example.org'));
checkIdentical('testMail: why the mail did not go', Array($runVals['code'], $runVals['errs']), Array(1, "Test mail not sent to ben@example.org through the account nobody: Unknown mail account: \"nobody\"\n"));
// An account that sends : the password in the file of the secrets, only named in the account
$projDir = freshPrjt('mailsmt');
writeInProject($projDir, 'conf/database.json.dist', $liteDst);
$runVals = callShellArray($projDir, Array('install', 'complete', '--projKind=allInOne', '--useStore=nativeDb', '--sendWith=selfSmtp', '--signupOn', '--silently'), "\n\n\n\nsmtp.example.org\n465\nmailer@example.org\ns3cret-pw\nnews@example.org\n\n\n\n\n\n");
$mailTxt = strval(fetchInProject($projDir, 'conf/mailer.json'));
checkIdentical('mails: with --sendWith and --signupOn, the type and the sign-ups are not asked', strpos($runVals['errs'], 'Database file [temp/none.sqlite]: Mail server host: Port (465 or 587) [587]: Mail user: Mail password (Enter keeps the present one): Sender address: Sender name [nyuRuns]: Name of the mail account [nyuRuns]: Front-end link of the mails (empty for none): Send a test mail to (empty for none): ')!==false, true);
checkIdentical('mails: the password goes to .env, the account only names it', Array(json_decode($mailTxt, true)['nyuRuns'] ?? null, parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_MAIL_PASS'] ?? null, strpos($mailTxt, 's3cret-pw'), json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['nativeDb']['signupOn'] ?? null),
	Array(Array('sendWith'=>'selfSmtp', 'hostName'=>'smtp.example.org', 'portNumb'=>'465', 'userName'=>'mailer@example.org', 'passWord'=>'env:NYU_MAIL_PASS', 'fromAddr'=>'news@example.org', 'fromName'=>'nyuRuns', 'waitSecs'=>'10', 'mailsDir'=>'', 'copiesTo'=>''), 's3cret-pw', false, true));
// A file that exists : the other accounts are left as they are
writeInProject($projDir, 'conf/mailer.json', $mailDst);
runShellAsJson($projDir, Array('install', 'complete', '--projKind=allInOne', '--useStore=nativeDb', '--sendWith=emlFiles'), "\n\n\n\n\n\n\n\n\n\n\n");
$mailLst = json_decode(strval(fetchInProject($projDir, 'conf/mailer.json')), true);
checkIdentical('mails: in a file that exists, the account in use is the one written, the others kept', Array(array_keys($mailLst ?? Array()), $mailLst['nyuRuns']['sendWith'] ?? null, $mailLst['nyuRuns']['fromAddr'] ?? null, $mailLst['complex'] ?? null), Array(Array('nyuRuns', 'complex', 'devbox'), 'emlFiles', 'no-reply@example.com', json_decode($mailDst, true)['complex']));
// No store of the native userbase, no access mails : not asked, and --sendWith refused unless it is none
$projDir = freshPrjt('mailusr');
checkIdentical('mails: not for the users file, a resource server or an open site', Array(callShellArray($projDir, Array('install', 'complete', '--noPrompt', '--useStore=userFile', '--sendWith=emlFiles'))['code'], callShellArray(freshPrjt('mailopn'), Array('install', 'complete', '--projKind=openSite', '--noPrompt', '--sendWith=emlFiles'))['code'], is_file($projDir.DS.'conf'.DS.'mailer.json'), is_file(inTemporaryDir('opensite').DS.'conf'.DS.'mailer.json'), is_file(inTemporaryDir('datasrv').DS.'conf'.DS.'mailer.json')), Array(2, 2, false, false, false));

finishChecking();