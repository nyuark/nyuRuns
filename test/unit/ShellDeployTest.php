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
		'conf/access.json.dist'=>distAccss(),
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

/**
 * The example access config the project ships; signing its tokens with
 * HS256 when PHP has no openssl, which RS256 needs to make its keys.
 *
 * @return String The content of conf/access.json.dist.
 */
function distAccss(): String {
	$distTxt = strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json.dist'));
	return function_exists('openssl_pkey_new') ? $distTxt : str_replace('"algrtm": "RS256"', '"algrtm": "HS256"', $distTxt);
}
// Without openssl the tokens are signed with a secret, checked by its own row
$tknRow = function_exists('openssl_pkey_new') ? 'token keys dftokn' : 'secret dftokn';
$projDir = freshPrjt('deploy');

// servr, files
$jsnVals = jsonShell($projDir, Array('deploy', 'servr'));
$statLst = stateOf($jsnVals);
checkSame('servr checks PHP and the folders, which it creates', Array($jsnVals['exit'] ?? null, $statLst['php'] ?? null, $statLst['folder temp/logs'] ?? null, $statLst['folder temp/limits'] ?? null, is_dir($projDir.DS.'temp'.DS.'cache'), is_dir($projDir.DS.'temp'.DS.'backup')), Array(0, 'fine', 'fine', 'fine', true, true));
checkSame('files creates the local files from their examples', Array(jsonShell($projDir, Array('deploy', 'files'))['rows'] ?? null, readInPrj($projDir, '.env'), readInPrj($projDir, 'conf/access.json')), Array(
	Array(Array('file'=>'.env', 'state'=>'created from .env.example'), Array('file'=>'conf/access.json', 'state'=>'created from conf/access.json.dist'), Array('file'=>'conf/database.json', 'state'=>'not created: its example conf/database.json.dist is missing')),
	"# Copy to .env and fill in\nNYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n", str_replace("\r\n", "\n", distAccss())
));
putInProj($projDir, '.env', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n");
checkSame('a local file that exists is kept', Array(array_column(jsonShell($projDir, Array('deploy', 'files'))['rows'] ?? Array(), 'state', 'file')['.env'] ?? null, readInPrj($projDir, '.env')), Array('kept', "# Mine\nNYU_JWT_SECRET=\nNYU_API_KEY=\n"));

// check, before anything is set
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
$statLst = stateOf($jsnVals);
checkSame('the health check tells what is missing', Array($jsnVals['exit'] ?? null, $statLst['preset dfault'] ?? null, $statLst['preset secure'] ?? null, $statLst['access login'] ?? null, $statLst[$tknRow] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $statLst['keys dfkeys'] ?? null, $statLst['errors'] ?? null, ($jsnVals['fail'] ?? 0)>=2),
	Array(4, 'fine', 'fine', 'fine', 'fail', 'fine', 'fail', 'warn', 'fine', true));

// start, without a question
$jsnVals = jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=usrlst'));
$statLst = stateOf($jsnVals);
$envVals = parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW);
$axsVals = json_decode(strval(readInPrj($projDir, 'conf/access.json')), true);
$usrList = array_column(json_decode(strval(readInPrj($projDir, 'conf/users.lst')), true)['users'] ?? Array(), null, 'userName');
$keyText = readInPrj($projDir, 'conf/keys/dftokn.key');
checkSame('start points the login at the store, makes the keys of the tokens, the secrets and the users, and ends on a clean check', Array($jsnVals['exit'] ?? null, $axsVals['login']['authby'] ?? null, $axsVals['login']['authcf'] ?? null, function_exists('openssl_pkey_new') ? is_file($projDir.DS.'conf'.DS.'keys'.DS.'dftokn.key') : strlen($envVals['NYU_JWT_SECRET'] ?? '')>=48, $envVals['NYU_API_KEY'] ?? '', array_column($usrList, 'userRole', 'userName'), $statLst[$tknRow] ?? null, $statLst['databases'] ?? null, $statLst['users'] ?? null, $jsnVals['fail'] ?? null),
	Array(0, 'usrlst', 'usrlst', true, $jsnVals['secret']['API key'] ?? 'none', Array('member'=>'member', 'system'=>'system', 'managr'=>'manage'), 'fine', 'fine', 'fine', 0));
checkSame('start makes the key of the 2FA secrets with the store, never shown', Array(strlen($envVals['NYU_TFA_KEY'] ?? '')>=48, $statLst['2FA key usrlst'] ?? null, isset($jsnVals['secret']['Key of the 2FA secrets'])), Array(true, 'fine', false));
checkSame('the passwords of the basic users are shown once', Array(password_verify($jsnVals['secret']['Password of managr (manage)'] ?? '', $usrList['managr']['passHash'] ?? ''), password_verify($jsnVals['secret']['Password of member (member)'] ?? '', $usrList['member']['passHash'] ?? ''), isset($jsnVals['secret']['Secret of the tokens'])), Array(true, true, false));
checkLike('the layout of the access config is kept', strval(readInPrj($projDir, 'conf/access.json')), '/^\{\n\t"plugin": \{ "authby": \[\], "sessby": \[\] \},\n\t"login":  \{ "authby": "usrlst", "authcf": "usrlst", /');
$jsnVals = jsonShell($projDir, Array('deploy', 'start', '--noask'));
checkSame('run again, nothing that exists is replaced', Array($jsnVals['exit'] ?? null, readInPrj($projDir, 'conf/keys/dftokn.key')===$keyText, parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW), json_decode(strval(readInPrj($projDir, 'conf/users.lst')), true)['users'][2]['passHash'] ?? null, isset($jsnVals['secret']), in_array('The users file already holds users: nothing was changed.', $jsnVals['notes'] ?? Array(), true)), Array(0, true, $envVals, $usrList['managr']['passHash'], false, true));
checkLike('the installation is traced', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Deploy: basic users created in usrlst \(member, system, managr\) by user "cli:/');
// A public key laid over the server's own, by a bundle of another pair : the check fails
if( function_exists('openssl_pkey_new') ){
	$pubText = strval(readInPrj($projDir, 'conf/keys/dftokn.pub'));
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf') );
	putInProj($projDir, 'conf/keys/dftokn.pub', strval(openssl_pkey_get_details($keyObjc)['key'] ?? ''));
	$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
	checkSame('a public key that is not the one of the private key fails the check', Array(stateOf($jsnVals)[$tknRow] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')[$tknRow] ?? null),
		Array('fail', 'the public key is not the one of the private key: "config secrt jtoken" makes a new pair'));
	putInProj($projDir, 'conf/keys/dftokn.pub', $pubText);
	checkSame('its own public key back, the check is fine', stateOf(jsonShell($projDir, Array('deploy', 'check')))[$tknRow] ?? null, 'fine');
}
checkSame('users alone: a store that holds users is left as it is; a store that is not one', Array(runShell($projDir, Array('deploy', 'users'))['text'], runShell($projDir, Array('deploy', 'users', '--store=other'))['code']), Array("The users file already holds users: nothing was changed.\n", 2));

// start : the settings of a database are made with the native userbase only
$baseDst = '{ "nyuRuns": { "type": "mysql", "host": "localhost", "port": "", "base": "nyuRuns", "user": "nyuruns", "pass": "env:NYU_DB_PASS", "char": "utf8" } }';
$projDir = freshPrjt('nodbase');
putInProj($projDir, 'conf/database.json.dist', $baseDst);
$jsnVals = jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=usrlst'));
checkSame('in the users file, no settings of a database are made, and the check is clean', Array($jsnVals['exit'] ?? null, is_file($projDir.DS.'conf'.DS.'database.json'), stateOf($jsnVals)['databases'] ?? null), Array(0, false, 'fine'));
$projDir = freshPrjt('wthbase');
putInProj($projDir, 'conf/database.json.dist', $baseDst);
jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=native'));
checkSame('with the native userbase, they are made from their example', readInPrj($projDir, 'conf/database.json'), $baseDst);
checkSame('files alone still makes them all', Array(jsonShell(freshPrjt('allfles'), Array('deploy', 'files'))['rows'][2]['file'] ?? null), Array('conf/database.json'));

// start, with its questions
$projDir = freshPrjt('asked');
$runVals = runShell($projDir, Array('deploy', 'start', '--quiet'), "\n\n");
checkSame('each question proposes a default, taken on Enter: the users file, which needs no database', Array($runVals['errs'], strpos(strval(readInPrj($projDir, 'conf/system.ini')), 'ENV_NAME = DEV')!==false, is_file($projDir.DS.'conf'.DS.'users.lst')), Array('Environment (DEV, TEST, PREV, PROD) [DEV]: Store of the users (native, usrlst, none) [usrlst]: ', true, true));
$projDir = freshPrjt('answer');
$jsnVals = jsonShell($projDir, Array('deploy', 'start'), "prod\nusrlst\n");
$statLst = stateOf($jsnVals);
checkSame('the answers are written; ERR_SHOW left on in production is told, where it is ignored', Array($jsnVals['exit'] ?? null, strpos(strval(readInPrj($projDir, 'conf/system.ini')), "ENV_NAME = PROD\n")!==false, strpos(strval(readInPrj($projDir, 'conf/system.ini')), '; Environment platform (DEV, TEST, PREV, PROD)')!==false, count(json_decode(strval(readInPrj($projDir, 'conf/users.lst')), true)['users'] ?? Array()), $statLst['errors'] ?? null, $statLst['users'] ?? null), Array(0, true, true, 3, 'warn', 'fine'));
checkLike('the row tells the setting is ignored there', strval(array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['errors'] ?? ''), '/^ERR_SHOW is on in the environment PROD, where it is ignored: set it to FALSE$/');
checkSame('once it is off, the row is fine', Array(runShell($projDir, Array('config', 'write', 'system', 'ERR_SHOW', 'FALSE'))['code'], stateOf(jsonShell($projDir, Array('deploy', 'check')))['errors'] ?? null), Array(0, 'fine'));
runShell($projDir, Array('config', 'write', 'access', 'dfsess.secure', 'false'));
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
checkSame('a session cookie sent over plain HTTP in production is told, without failing the check', Array(json_decode(strval(readInPrj($projDir, 'conf/access.json')), true)['dfsess']['secure'] ?? null, stateOf($jsnVals)['cookie dfsess'] ?? null, $jsnVals['exit'] ?? null), Array(false, 'warn', 0));
// The issuer and the audiences of the tokens, the previous key, the audiences of the application keys
checkSame('tokens bound to no project are told, without failing the check', Array(stateOf($jsnVals)['token claims dftokn'] ?? null, $jsnVals['exit'] ?? null), Array('warn', 0));
runShell($projDir, Array('config', 'write', 'access', 'dftokn.issuer', 'https://auth.example.org'));
runShell($projDir, Array('config', 'write', 'access', 'dftokn.audnce', '["api.example.org"]'));
checkSame('once the issuer and the audiences are set, the row is fine', array_column(jsonShell($projDir, Array('deploy', 'check'))['rows'] ?? Array(), 'found', 'check')['token claims dftokn'] ?? null, 'issuer "https:\/\/auth.example.org", audience ["api.example.org"]');
$axsVals = json_decode(strval(readInPrj($projDir, 'conf/access.json')), true);
$axsVals['dftokn']['issuer'] = 42;
$axsVals['dftokn']['oldpub'] = 'conf/access.json';
$axsVals['apkeys'] = Array('header'=>'X-App-Key', 'applst'=>Array('portal'=>Array('keyhsh'=>'', 'issuer'=>Array('x'))), 'audnce'=>Array());
$axsVals['apkeyz'] = Array('header'=>'X-App-Key', 'applst'=>Array(), 'audnce'=>Array());
putInProj($projDir, 'conf/access.json', strval(json_encode($axsVals, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)));
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
$fndList = array_column($jsnVals['rows'] ?? Array(), 'found', 'check');
checkSame('values of the wrong kind fail the check', Array(stateOf($jsnVals)['token claims dftokn'] ?? null, $fndList['token claims dftokn'] ?? null, stateOf($jsnVals)['token claims apkeys'] ?? null, $fndList['token claims apkeys'] ?? null),
	Array('fail', 'issuer is a text; oldpub is not the public RSA key of 2048 bits at least: empty it, or "config secrt jtoken" writes it', 'fail', 'the issuer of the application "portal" is a text'));
checkSame('application keys without the audiences of this project are told', stateOf($jsnVals)['token claims apkeyz'] ?? null, 'warn');
$axsVals['dftokn']['issuer'] = 'https://auth.example.org';
$axsVals['dftokn']['oldpub'] = 'conf/keys/dftokn.pub';
$axsVals['dftokn']['algrtm'] = 'HS256';
unset($axsVals['apkeys'], $axsVals['apkeyz']);
putInProj($projDir, 'conf/access.json', strval(json_encode($axsVals, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)));
if( function_exists('openssl_pkey_new') ){
	checkSame('a previous key with HS256 is told, as it is not read', stateOf(jsonShell($projDir, Array('deploy', 'check')))['previous key dftokn'] ?? null, 'warn');
}
$projDir = freshPrjt('wrongs');
checkSame('an answer that is not one of those proposed', Array(runShell($projDir, Array('deploy', 'start'), "LIVE\n")['code'], runShell($projDir, Array('deploy', 'start'), "\nldap\n")['code']), Array(3, 3));
$jsnVals = jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=none'));
checkSame('a store without users of its own', Array($jsnVals['exit'] ?? null, is_file($projDir.DS.'conf'.DS.'users.lst')), Array(4, false));
checkSame('without a store, no key of the 2FA secrets is made: the check tells the secrets would be readable', Array(parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? '', stateOf($jsnVals)['2FA key native'] ?? null), Array('', 'warn'));
runShell($projDir, Array('config', 'write', 'access', 'native.tfakey', 'too-short'));
checkSame('a key of the 2FA secrets that is too short fails the check', stateOf(jsonShell($projDir, Array('deploy', 'check')))['2FA key native'] ?? null, 'fail');
runShell($projDir, Array('config', 'write', 'access', 'native.tfakey', 'env:NYU_TFA_KEY'));
// The access mails : an account of conf/mailer.json, a front-end link holding the code, sign-ups a mail confirms
runShell($projDir, Array('config', 'write', 'access', 'native.mailer', 'nobody', '--force'));
runShell($projDir, Array('config', 'write', 'access', 'native.lnkurl', 'app.example.ch/access', '--force'));
runShell($projDir, Array('config', 'write', 'access', 'native.codexp', '0', '--force'));
$statLst = stateOf(jsonShell($projDir, Array('deploy', 'check')));
checkSame('an account that is not one, a link that is not one, a code lasting no minute', Array($statLst['access mails native'] ?? null, $statLst['access link native'] ?? null, $statLst['access codes native'] ?? null), Array('fail', 'fail', 'fail'));
putInProj($projDir, 'conf/mailer.json', '{ "devbox": { "type": "tofile", "from": "dev@example.com", "fold": "temp/mails" } }');
runShell($projDir, Array('config', 'write', 'access', 'native.mailer', 'devbox'));
runShell($projDir, Array('config', 'write', 'access', 'native.lnkurl', 'https://app.example.ch/access?do=%kind%&code=%code%'));
runShell($projDir, Array('config', 'write', 'access', 'native.codexp', '15'));
$statLst = stateOf(jsonShell($projDir, Array('deploy', 'check')));
checkSame('a mail account that is one, a link holding the code', Array($statLst['access mails native'] ?? null, isset($statLst['access link native']), isset($statLst['access codes native'])), Array('fine', false, false));
runShell($projDir, Array('config', 'write', 'access', 'native.mailer', ''));
runShell($projDir, Array('config', 'write', 'access', 'native.signup', 'true', '--force'));
checkSame('sign-ups without a mail account are told', stateOf(jsonShell($projDir, Array('deploy', 'check')))['sign-ups native'] ?? null, 'warn');
runShell($projDir, Array('config', 'write', 'access', 'native.signup', 'false'));
runShell($projDir, Array('config', 'write', 'access', 'limits.regtop', '0', '--force'));
runShell($projDir, Array('config', 'write', 'access', 'limits.regwrn', 'many', '--force'));
checkSame('sign-up limits that are not numbers fail the check', stateOf(jsonShell($projDir, Array('deploy', 'check')))['sign-up limits limits'] ?? null, 'fail');
runShell($projDir, Array('config', 'write', 'access', 'limits.regtop', '256'));
runShell($projDir, Array('config', 'write', 'access', 'limits.regwrn', '[128, 200, 256]'));
checkSame('sign-up limits that are numbers', isset(stateOf(jsonShell($projDir, Array('deploy', 'check')))['sign-up limits limits']), false);
unlink($projDir.DS.'conf'.DS.'mailer.json');

// What the check refuses
putInProj($projDir, 'conf/presets/broken.rps', '{ "label": "broken", ');
putInProj($projDir, 'conf/presets/noblok.rps', '{ "label": "noblok", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "nowhere" } }');
putInProj($projDir, 'conf/presets/norole.rps', '{ "label": "norole", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "dftokn", "rolert": { "users/*": "boss" } } }');
putInProj($projDir, 'conf/system.ini', sampleIni("PRX_LIST = \"10.0.0.5, 0.0.0.0/0\"\n"));
$cookRps = '{ "label": "%s", "setup": { "ftypes": "all" }, "auths": { "sessby": "phsess", "sesscf": "dfsess" }, "rnder": { "format": "json", %s"headrs": { "access-control-allow-origin": "*" } } }';
putInProj($projDir, 'conf/presets/cookie.rps', sprintf($cookRps, 'cookie', ''));
putInProj($projDir, 'conf/presets/listed.rps', sprintf($cookRps, 'listed', '"origns": ["https://app.example.org"], '));
$statLst = stateOf(jsonShell($projDir, Array('deploy', 'check')));
checkSame('a session cookie with every origin allowed is told, unless the origins are listed', Array($statLst['origins cookie'] ?? null, $statLst['preset cookie'] ?? null, array_key_exists('origins listed', $statLst), array_key_exists('origins dfault', $statLst)), Array('warn', 'fine', false, false));
checkSame('a preset that does not load, names a block that is not there, or asks for a role that is not one; proxies trusted from anywhere', Array($statLst['preset broken'] ?? null, $statLst['preset noblok'] ?? null, $statLst['preset norole'] ?? null, $statLst['preset dfault'] ?? null, $statLst['proxies'] ?? null), Array('fail', 'fail', 'fail', 'fine', 'fail'));

// An error level that cannot be read stops every request : the check fails
putInProj($projDir, 'conf/system.ini', str_replace('ERR_LEVL = E_ALL', 'ERR_LEVL = "E_ALL & ~E_FOO"', sampleIni()));
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
checkSame('an error level that cannot be read fails the check', Array(stateOf($jsnVals)['error level'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'found', 'check')['error level'] ?? null), Array('fail', 'ERR_LEVL holds what is not an error level: E_FOO: no request can be served'));
putInProj($projDir, 'conf/system.ini', sampleIni());
checkSame('E_ALL, every error is reported', array_column(jsonShell($projDir, Array('deploy', 'check'))['rows'] ?? Array(), 'found', 'check')['error level'] ?? null, 'every error is reported (E_ALL)');

// The default language of the mails : a tag, or the check fails
checkSame('no default language, or a tag: nothing told', Array(array_key_exists('mail language', stateOf(jsonShell($projDir, Array('deploy', 'check')))), array_key_exists('mail language', stateOf((function() use ( $projDir ): Array { putInProj($projDir, 'conf/system.ini', sampleIni("LNG_DFLT = fr_CH\n")); return jsonShell($projDir, Array('deploy', 'check')); })()))), Array(false, false));
putInProj($projDir, 'conf/system.ini', sampleIni("LNG_DFLT = french\n"));
checkSame('a default language that is not a tag fails the check', stateOf(jsonShell($projDir, Array('deploy', 'check')))['mail language'] ?? null, 'fail');
putInProj($projDir, 'conf/system.ini', sampleIni());

// A public key found without its private key : made again, unless the block says the tokens come from another server
if( function_exists('openssl_pkey_new') ){
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA, 'config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf') );
	$srcPubl = strval(openssl_pkey_get_details($keyObjc)['key'] ?? '');
	$projDir = freshPrjt('pubonly');
	jsonShell($projDir, Array('deploy', 'files'));
	putInProj($projDir, 'conf/keys/dftokn.pub', $srcPubl);
	$chkVals = jsonShell($projDir, Array('deploy', 'check'));
	checkSame('in DEV, a login issuing tokens with a public key alone is told', stateOf($chkVals)['token keys dftokn'] ?? null, 'warn');
	putInProj($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEV', 'ENV_NAME = PROD', sampleIni()));
	checkSame('in PROD, it fails the check', stateOf(jsonShell($projDir, Array('deploy', 'check')))['token keys dftokn'] ?? null, 'fail');
	putInProj($projDir, 'conf/system.ini', sampleIni());
	jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=usrlst'));
	checkSame('deploy start makes a pair of its own, the public key found replaced', Array(is_file($projDir.DS.'conf'.DS.'keys'.DS.'dftokn.key'), readInPrj($projDir, 'conf/keys/dftokn.pub')!==$srcPubl), Array(true, true));
	$projDir = freshPrjt('external');
	jsonShell($projDir, Array('deploy', 'files'));
	putInProj($projDir, 'conf/keys/dftokn.pub', $srcPubl);
	runShell($projDir, Array('config', 'write', 'access', 'dftokn.extern', 'true', '--force'));
	putInProj($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEV', 'ENV_NAME = PROD', sampleIni()));
	checkSame('extern: the public key alone is the one of the server issuing the tokens, fine in PROD', stateOf(jsonShell($projDir, Array('deploy', 'check')))['token keys dftokn'] ?? null, 'fine');
	putInProj($projDir, 'conf/system.ini', sampleIni());
	jsonShell($projDir, Array('deploy', 'start', '--noask', '--store=usrlst'));
	checkSame('extern: deploy start keeps it and makes no pair', Array(is_file($projDir.DS.'conf'.DS.'keys'.DS.'dftokn.key'), readInPrj($projDir, 'conf/keys/dftokn.pub')), Array(false, $srcPubl));
}

// The rights of the secret files : not read on Windows, which is told
checkSame('the rights not checked on Windows are told once; elsewhere, no such row', array_count_values(array_column(jsonShell($projDir, Array('deploy', 'check'))['rows'] ?? Array(), 'check'))['file rights'] ?? 0, (DS=='\\') ? 1 : 0);

// The entry points : the preset each one starts, open or not, open by intent or not
$projDir = freshPrjt('entries');
jsonShell($projDir, Array('deploy', 'files'));
putInProj($projDir, 'conf/presets/static.rps', '{ "label": "static", "setup": { "ftypes": "all" }, "auths": { "needed": false, "public": true } }');
putInProj($projDir, 'conf/presets/tokens.rps', '{ "label": "tokens", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "dftokn", "public": true }, "rnder": { "headrs": { "Access-Control-Allow-Origin": "*" } } }');
putInProj($projDir, 'www/index.php', "<?php\n\$nyuRun = new \\nyu\\Runner();\n");
putInProj($projDir, 'www/api.php', "<?php\n\$nyuRun = new \\nyu\\Runner( 'secure' );\n");
putInProj($projDir, 'www/site.php', "<?php\n\$nyuRun = new \\nyu\\Runner('static');\n");
putInProj($projDir, 'www/own.php', "<?php\n\$nyuRun = new \\nyu\\Runner(\$presetNm);\n");
putInProj($projDir, 'www/plain.php', "<?php echo 1;\n");
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
$statLst = stateOf($jsnVals);
$fndList = array_column($jsnVals['rows'] ?? Array(), 'found', 'check');
checkSame('in DEV, an entry on an open preset is told; one that checks access, or open by intent, is fine', Array($statLst['entry index'] ?? null, $statLst['entry api'] ?? null, $statLst['entry site'] ?? null, $statLst['entry own'] ?? null, array_key_exists('entry plain', $statLst)), Array('warn', 'fine', 'fine', 'warn', false));
checkSame('what the rows say', Array($fndList['entry index'] ?? null, $fndList['entry site'] ?? null), Array('starts dfault: every request is served without a check; name a preset that checks access, or set "public": true in its auths block when the site is meant to be open', 'starts static, open by intent (public)'));
checkSame('public on a preset that checks access, every origin with tokens: both told', Array($statLst['public tokens'] ?? null, $fndList['origins tokens'] ?? null), Array('warn', 'every origin is allowed while the preset reads tokens: list the origins under "origns"'));
putInProj($projDir, 'conf/system.ini', str_replace('ENV_NAME = DEV', 'ENV_NAME = PROD', sampleIni()));
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
checkSame('in PROD, an entry on an open preset fails the check; one open by intent does not', Array(stateOf($jsnVals)['entry index'] ?? null, stateOf($jsnVals)['entry site'] ?? null, $jsnVals['exit'] ?? null), Array('fail', 'fine', 4));

endChecks();
