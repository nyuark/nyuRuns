<?php

// Command line, config : the config files shown and written with their
// comments and layout kept, the entry points, the secrets, the routes.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

$iniText = buildSystemIni("\n; REVERSE PROXY\n; Addresses of the proxies\nPRX_LIST = \"\"\n");
$axsText = "{\n"
	."\t\"plugin\": { \"authby\": [], \"sessby\": [] },\n"
	."\t\"login\":  { \"authby\": \"native\", \"authcf\": \"native\", \"sessby\": \"jtoken\", \"sesscf\": \"dftokn\" },\n"
	."\t\"dfpass\": { \"htpasswd\": \"conf/.htpasswd\" },\n"
	."\t\"native\": { \"basnme\": \"\", \"tblnme\": \"users\", \"minlen\": 10 },\n"
	."\t\"dfkeys\": { \"header\": \"X-Api-Key\", \"keylst\": [\"env:NYU_API_KEY\"] },\n"
	."\t\"dftokn\": { \"secret\": \"env:NYU_JWT_SECRET\", \"lifetm\": 3600, \"algrtm\": \"HS256\" },\n"
	."\t\"inline\": { \"secret\": \"written-here-0123456789\", \"keylst\": [\"k-1\", \"k-2\"] }\n"
	."}\n";
$dbsText = "{\n\t\"main\": {\n\t\t\"type\": \"sqlte\",\n\t\t\"host\": \"\",\n\t\t\"base\": \"data.sqlite\",\n\t\t\"user\": \"\",\n\t\t\"pass\": \"env:NYU_DB_PASS\"\n\t}\n}\n";
$rpsText = "{\n\t// Name of the preset\n\t\"label\": \"sample\",\n\t\"about\": \"A preset of the tests\",\n\t\"setup\": { \"ftypes\": \"all\", \"fnames\": \"any\" },\n"
	."\t# Access\n\t\"auths\": {\n\t\t// Carrier : \"sessby\": \"hbasic\" would be another\n\t\t\"sessby\": \"jtoken\",\n\t\t\"sesscf\": \"dftokn\",\n\t\t\"authby\":\t\"native\",\n\t\t\"needed\": true,\n"
	."\t\t\"openrt\": [\"auth/login\", \"/hello\"],\n\t\t\"rolert\": { \"users/*\": \"manage\", \"/admin/\$id\": \"system\" }\n\t},\n"
	."\t\"input\": [ { \"naming\": \"QUERY\", \"source\": \"QUERY\", \"fields\": \"all\" } ],\n"
	."\t\"route\": { \"detect\": \"autopath\", \"mapper\": \"\$app/\$action/\$param\", \"folder\": \"conf/routes\" },\n"
	."\t\"rnder\": { \"format\": \"json\", \"headrs\": { \"Cache-Control\": \"no-cache\" } }\n}\n";
$rteText = '{ "hello": { "target": { "pge": "hello" } }, "admin/$id": { "allows": "GET, POST", "target": { "app": "users", "act": "view" } },'
	.' "old": { "target": { "url": "/hello", "cod": 301 } }, "things": { "target": { "GET": { "app": "shows", "act": "index" }, "POST": { "app": "shows", "act": "create" } } } }';

$projDir = makeProjectTxt('config', Array(
	'conf/system.ini'=>$iniText, 'conf/access.json'=>$axsText, 'conf/database.json'=>$dbsText,
	'conf/presets/sample.rps'=>$rpsText, 'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false } }',
	'conf/presets/wrongs.rps'=>'{ "label": "wrongs", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "rolert": { "users/*": "boss" } } }',
	'conf/routes/main.rte'=>$rteText, 'runs/Shelf/routes.rte'=>'{ "board": { "allows": "GET", "target": { "act": "index" } } }',
	'www/index.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner();\n", 'www/api.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner( \"dfault\", \$overLoad );\n",
	'www/own.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner(\$presetNm);\n", 'www/plain.php'=>"<?php echo 1;\n",
	'.env'=>"# Secrets\nNYU_JWT_SECRET=\nNYU_API_KEY=old-key\n"
));

// shows
checkIdentical('shows system lists the keys as written', array_slice(runShellAsJson($projDir, Array('config', 'shows', 'system'))['rows'] ?? Array(), 0, 2), Array(Array('key'=>'ENV_NAME', 'value'=>'DEV'), Array('key'=>'LOG_MODE', 'value'=>'FULL')));
checkIdentical('shows one key', callShellArray($projDir, Array('config', 'shows', 'system', 'LOG_PATH'))['text'], "key       value\nLOG_PATH  /temp/logs\n");
checkIdentical('shows a block, its values as written', runShellAsJson($projDir, Array('config', 'shows', 'access', 'dftokn'))['rows'] ?? null, Array(Array('key'=>'dftokn.secret', 'value'=>'env:NYU_JWT_SECRET'), Array('key'=>'dftokn.lifetm', 'value'=>3600), Array('key'=>'dftokn.algrtm', 'value'=>'HS256')));
checkIdentical('a secret written in the file is masked', runShellAsJson($projDir, Array('config', 'shows', 'access', 'inline'))['rows'] ?? null, Array(Array('key'=>'inline.secret', 'value'=>'********'), Array('key'=>'inline.keylst', 'value'=>Array('********', '********'))));
checkIdentical('nothing under a path', Array(callShellArray($projDir, Array('config', 'shows', 'access', 'login.nothing'))['code'], callShellArray($projDir, Array('config', 'shows', 'system', 'NOT_HERE'))['code'], callShellArray($projDir, Array('config', 'shows', 'other'))['code']), Array(3, 3, 2));
checkIdentical('shows the databases, the password as the name it is kept under', runShellAsJson($projDir, Array('config', 'shows', 'dbases', 'main.pass'))['rows'] ?? null, Array(Array('key'=>'main.pass', 'value'=>'env:NYU_DB_PASS')));
$rowList = runShellAsJson($projDir, Array('config', 'shows', 'preset'))['rows'] ?? Array();
checkIdentical('shows the presets', Array(array_column($rowList, 'preset'), $rowList[1]['about'] ?? null), Array(Array('dfault', 'sample', 'wrongs'), 'A preset of the tests'));
checkIdentical('shows a key of a preset', runShellAsJson($projDir, Array('config', 'shows', 'preset', 'sample.auths.openrt'))['rows'] ?? null, Array(Array('key'=>'auths.openrt', 'value'=>Array('auth/login', '/hello'))));
checkIdentical('a preset that does not exist', callShellArray($projDir, Array('config', 'shows', 'preset', 'nosuch'))['code'], 1);

// write : system.ini
checkIdentical('write system changes the value on its line', Array(callShellArray($projDir, Array('config', 'write', 'system', 'LOG_MODE', 'BASE'))['text'], fetchInProject($projDir, 'conf/system.ini')), Array("written: system LOG_MODE\n", str_replace('LOG_MODE = FULL', 'LOG_MODE = BASE', $iniText)));
checkIdentical('the former file is copied to temp/backup', array_map(function( String $copyPath ): String { return strval(preg_replace('/\d{8}-\d{6}/', 'TIME', basename($copyPath))); }, glob($projDir.DS.'temp'.DS.'backup'.DS.'*')), Array('system.ini.TIME'));
checkIdentical('a value that is not plain words is written between quotes', Array(callShellArray($projDir, Array('config', 'write', 'system', 'PRX_LIST', '10.0.0.5, 10.0.0.6'))['code'], strpos(strval(fetchInProject($projDir, 'conf/system.ini')), 'PRX_LIST = "10.0.0.5, 10.0.0.6"'."\n")!==false), Array(0, true));
checkIdentical('a value a key does not take, a key that is not there, a key that is not one', Array(callShellArray($projDir, Array('config', 'write', 'system', 'LOG_MODE', 'LOUD'))['code'], callShellArray($projDir, Array('config', 'write', 'system', 'NOT_HERE', '1'))['code'], callShellArray($projDir, Array('config', 'write', 'system', 'logmode', '1'))['code'], callShellArray($projDir, Array('config', 'write', 'system', 'LOG_MODE'))['code']), Array(3, 3, 2, 2));
checkIdentical('nothing was changed by the refusals', strpos(strval(fetchInProject($projDir, 'conf/system.ini')), 'LOG_MODE = BASE')!==false, true);

// write : access.json, database.json
checkIdentical('write access keeps the layout of the file', Array(callShellArray($projDir, Array('config', 'write', 'access', 'native.minlen', '12'))['code'], fetchInProject($projDir, 'conf/access.json')), Array(0, str_replace('"minlen": 10', '"minlen": 12', $axsText)));
checkIdentical('a check that does not exist, a key or a block that is not there', Array(callShellArray($projDir, Array('config', 'write', 'access', 'login.authby', 'nothing'))['code'], callShellArray($projDir, Array('config', 'write', 'access', 'native.newkey', 'x'))['code'], callShellArray($projDir, Array('config', 'write', 'access', 'newblk.newkey', 'x'))['code'], callShellArray($projDir, Array('config', 'write', 'access', 'native', 'x'))['code']), Array(3, 3, 3, 2));
checkIdentical('--force adds a key and a block', Array(callShellArray($projDir, Array('config', 'write', 'access', 'newblk.keylst', '["a", "b"]', '--force'))['code'], fetchInProject($projDir, 'conf/access.json')), Array(0, str_replace(Array('"minlen": 10', "\"k-2\"] }\n"), Array('"minlen": 12', "\"k-2\"] },\n\t\"newblk\": { \"keylst\": [\"a\", \"b\"] }\n"), $axsText)));
checkIdentical('write access takes a known check', Array(callShellArray($projDir, Array('config', 'write', 'access', 'login.authby', 'usrlst'))['code'], runShellAsJson($projDir, Array('config', 'shows', 'access', 'login.authby'))['rows'][0]['value'] ?? null), Array(0, 'usrlst'));
checkIdentical('write dbases keeps the layout of the file', Array(callShellArray($projDir, Array('config', 'write', 'dbases', 'main.host', 'db.local'))['code'], fetchInProject($projDir, 'conf/database.json')), Array(0, str_replace('"host": ""', '"host": "db.local"', $dbsText)));

// write : presets
checkIdentical('write preset changes the value in its place, comments kept', Array(callShellArray($projDir, Array('config', 'write', 'preset', 'sample.auths.sessby', 'hbasic'))['code'], fetchInProject($projDir, 'conf/presets/sample.rps')), Array(0, str_replace("\t\t\"sessby\": \"jtoken\"", "\t\t\"sessby\": \"hbasic\"", $rpsText)));
checkIdentical('a value after a tab, a value that is not a text, a key of the preset itself', Array(callShellArray($projDir, Array('config', 'write', 'preset', 'sample.auths.authby', 'usrlst'))['code'], callShellArray($projDir, Array('config', 'write', 'preset', 'sample.auths.needed', 'false'))['code'], callShellArray($projDir, Array('config', 'write', 'preset', 'sample.about', 'Changed, "quoted"'))['code'], fetchInProject($projDir, 'conf/presets/sample.rps')),
	Array(0, 0, 0, str_replace(Array("\t\t\"sessby\": \"jtoken\"", "\"authby\":\t\"native\"", '"needed": true', '"about": "A preset of the tests"'), Array("\t\t\"sessby\": \"hbasic\"", "\"authby\":\t\"usrlst\"", '"needed": false', '"about": "Changed, \"quoted\""'), $rpsText)));
checkIdentical('a list of a preset is not written, nor a key it does not have, nor a preset that does not exist', Array(callShellArray($projDir, Array('config', 'write', 'preset', 'sample.auths.openrt', 'x'))['code'], callShellArray($projDir, Array('config', 'write', 'preset', 'sample.auths.signin', '/in'))['code'], callShellArray($projDir, Array('config', 'write', 'preset', 'sample.input.fields', 'none'))['code'], callShellArray($projDir, Array('config', 'write', 'preset', 'nosuch.auths.needed', 'true'))['code'], callShellArray($projDir, Array('config', 'write', 'preset', 'sample', 'x'))['code']), Array(3, 3, 3, 3, 2));
checkPregMatch('a write is traced, without its value', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Config: preset sample\.about written by user "cli:[\w.\-]+"\n/');

// entry
checkIdentical('entry lists the entry points and their preset', runShellAsJson($projDir, Array('config', 'entry'))['rows'] ?? null, Array(Array('entry'=>'api', 'preset'=>'dfault'), Array('entry'=>'index', 'preset'=>'dfault'), Array('entry'=>'own', 'preset'=>'dfault')));
checkIdentical('entry names a preset where there was none', Array(callShellArray($projDir, Array('config', 'entry', 'index', 'sample'))['text'], fetchInProject($projDir, 'www/index.php')), Array("index: sample\n", "<?php\n\$nyuRun = new \\nyu\\Runner('sample');\n"));
checkIdentical('entry replaces the preset named, what follows it kept', Array(callShellArray($projDir, Array('config', 'entry', 'api', 'sample'))['code'], fetchInProject($projDir, 'www/api.php'), callShellArray($projDir, Array('config', 'entry', 'api'))['text']), Array(0, "<?php\n\$nyuRun = new \\nyu\\Runner( 'sample', \$overLoad );\n", "api: sample\n"));
checkIdentical('a preset that does not exist, an entry that names its preset its own way, a file that starts no Runner or does not exist', Array(callShellArray($projDir, Array('config', 'entry', 'index', 'nosuch'))['code'], callShellArray($projDir, Array('config', 'entry', 'own', 'sample'))['code'], callShellArray($projDir, Array('config', 'entry', 'plain', 'sample'))['code'], callShellArray($projDir, Array('config', 'entry', '../conf/x', 'sample'))['code']), Array(3, 3, 3, 3));

// secrt
$runVals = callShellArray($projDir, Array('config', 'secrt', 'jtoken'));
$envVals = parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW);
checkIdentical('the secret of the tokens is written in the environment file, and not shown', Array($runVals['code'], $runVals['text'], strlen($envVals['NYU_JWT_SECRET'])>=48, $envVals['NYU_API_KEY'], strpos(strval(fetchInProject($projDir, '.env')), "# Secrets\n")), Array(0, "written: .env NYU_JWT_SECRET\n", true, 'old-key', 0));
checkIdentical('a secret in use is only replaced when confirmed', Array(callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--noask'))['code'], parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_JWT_SECRET']), Array(3, $envVals['NYU_JWT_SECRET']));
$jsnVals = runShellAsJson($projDir, Array('config', 'secrt', 'jtoken', '--shown'), "y\n");
checkIdentical('--shown shows it', Array($jsnVals['secret']['Secret of the tokens'] ?? '', ($jsnVals['secret']['Secret of the tokens'] ?? '')!=$envVals['NYU_JWT_SECRET']), Array(parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_JWT_SECRET'], true));
$jsnVals = runShellAsJson($projDir, Array('config', 'secrt', 'apikey', '--agree'));
checkIdentical('an API key is shown once, and written where the block reads it', Array(strlen($jsnVals['secret']['API key'] ?? ''), $jsnVals['written'] ?? null, parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_API_KEY']), Array(64, '.env NYU_API_KEY', $jsnVals['secret']['API key'] ?? ''));
$jsnVals = runShellAsJson($projDir, Array('config', 'secrt', 'jtoken', '--block=inline', '--agree', '--shown'));
checkIdentical('a secret the block holds itself is written in the block', Array($jsnVals['written'] ?? null, json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['inline'], json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['dftokn']['secret']), Array('conf/access.json inline', Array('secret'=>$jsnVals['secret']['Secret of the tokens'] ?? '', 'keylst'=>Array('k-1', 'k-2')), 'env:NYU_JWT_SECRET'));
checkIdentical('a block that holds no such secret', Array(callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--block=native', '--agree'))['code'], callShellArray($projDir, Array('config', 'secrt', 'other'))['code']), Array(3, 2));
// With RS256, a key pair in conf/keys
if( function_exists('openssl_pkey_new') ){
	callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.algrtm', 'RS256', '--force'));
	$runVals = callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--block=rstokn'));
	$prvText = strval(fetchInProject($projDir, 'conf/keys/rstokn.key'));
	$keyInfo = ($prvText!='') ? openssl_pkey_get_details(openssl_pkey_get_private($prvText)) : Array();
	$rsaBlok = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array();
	checkIdentical('with RS256, a key pair of 4096 bits is made in conf/keys, its paths given to the block', Array($runVals['code'], $runVals['text'], $keyInfo['bits'] ?? null, ($keyInfo['type'] ?? null)===OPENSSL_KEYTYPE_RSA, $rsaBlok), Array(0, "written: conf/keys/rstokn.key, conf/keys/rstokn.pub\n", 4096, true, Array('algrtm'=>'RS256', 'prvkey'=>'conf/keys/rstokn.key', 'pubkey'=>'conf/keys/rstokn.pub')));
	$rsaJwt = new nyu\Access\Sess\JWT( Array('algrtm'=>'RS256', 'prvkey'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key', 'pubkey'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.pub') );
	checkIdentical('the pair signs and checks a token', ($rsaJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$rsaJwt->issueTokenText(Array('user'=>'anna'))))) ?: Array())['user'] ?? null, 'anna');
	if( DS=='/' ){
		checkIdentical('the private key is readable by its owner only', fileperms($projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key') & 0777, 0600);
	}
	checkIdentical('keys in use are only replaced when confirmed', Array(callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--block=rstokn', '--noask'))['code'], fetchInProject($projDir, 'conf/keys/rstokn.key')), Array(3, $prvText));
	$pubText = strval(fetchInProject($projDir, 'conf/keys/rstokn.pub'));
	checkIdentical('confirmed, a new pair replaces them, and no copy of the former key is kept', Array(callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--block=rstokn', '--agree'))['code'], fetchInProject($projDir, 'conf/keys/rstokn.key')!==$prvText, glob($projDir.DS.'temp'.DS.'backup'.DS.'rstokn*')), Array(0, true, Array()));
	checkIdentical('the former public key is kept as oldpub, and checks the tokens it signed', Array(fetchInProject($projDir, 'conf/keys/rstokn.old.pub'), json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn']['oldpub'] ?? null), Array($pubText, 'conf/keys/rstokn.old.pub'));
	$oldTokn = $rsaJwt->issueTokenText( Array('user'=>'anna') );
	$nowJwt = new nyu\Access\Sess\JWT( Array('algrtm'=>'RS256', 'prvkey'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key', 'oldpub'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.old.pub', 'retire'=>time() + 5) );
	checkIdentical('a token of the former pair passes with oldpub', ($nowJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$oldTokn))) ?: Array())['user'] ?? null, 'anna');
	checkPregMatch('replacing them tells the former tokens are still accepted', callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--block=rstokn'), "n\n")['errs'], '/still accepted until they end, checked with conf\/keys\/rstokn\.old\.pub/');
	$rsaBlok = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array();
	checkIdentical('the time the former key was retired is written beside it', is_int($rsaBlok['retire'] ?? null) && abs($rsaBlok['retire'] - time())<60, true);
	// A key that leaked : --drops keeps no former key, every token in use ends
	checkPregMatch('--drops tells every token in use ends', callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--block=rstokn', '--drops'), "n\n")['errs'], '/Replace the keys of the tokens in use: every token in use ends now/');
	$befKey = strval(fetchInProject($projDir, 'conf/keys/rstokn.key'));
	$runVals = callShellArray($projDir, Array('config', 'secrt', 'jtoken', '--block=rstokn', '--drops', '--agree'));
	$rsaBlok = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array();
	checkIdentical('--drops: a new pair, no former key file, oldpub empty, no retire time', Array($runVals['code'], fetchInProject($projDir, 'conf/keys/rstokn.key')!==$befKey, is_file($projDir.DS.'conf'.DS.'keys'.DS.'rstokn.old.pub'), $rsaBlok['oldpub'] ?? null, array_key_exists('retire', $rsaBlok)), Array(0, true, false, '', false));
	$dropJwt = new nyu\Access\Sess\JWT( Array('algrtm'=>'RS256', 'prvkey'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key') + $rsaBlok );
	file_put_contents(inTemporaryDir('former.key'), $befKey);
	$befTokn = (new nyu\Access\Sess\JWT( Array('algrtm'=>'RS256', 'prvkey'=>inTemporaryDir('former.key')) ))->issueTokenText( Array('user'=>'anna') );
	checkIdentical('--drops: a token of the former pair is refused', Array($dropJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$befTokn))), $dropJwt->finalErrorText()), Array(false, 'Invalid token'));
	// write : the keys binding the tokens to this project
	checkIdentical('write access: an issuer, audiences, a previous key that is one', Array(
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.issuer', 'https://auth.example.org', '--force'))['code'],
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.audnce', '["api.example.org", "app.example.org"]', '--force'))['code'],
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.oldpub', 'conf/keys/rstokn.pub'))['code'],
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.oldpub', ''))['code']
	), Array(0, 0, 0, 0));
	checkIdentical('write access: refused, an issuer or audiences that are not texts, a previous key that is not one', Array(
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.issuer', '42'))['code'],
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.audnce', '[1, 2]'))['code'],
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.audnce', '{"a": "b"}'))['code'],
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.oldpub', 'conf/access.json'))['code'],
		callShellArray($projDir, Array('config', 'write', 'access', 'rstokn.oldpub', 'conf/keys/rstokn.key.missing'))['code']
	), Array(3, 3, 3, 3, 3));
	checkIdentical('write access: what was refused was not written', array_intersect_key(json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array(), Array('issuer'=>1, 'audnce'=>1, 'oldpub'=>1)), Array('oldpub'=>'', 'issuer'=>'https://auth.example.org', 'audnce'=>Array('api.example.org', 'app.example.org')));
}
// The key of the 2FA secrets, in the block of the store the login uses
checkIdentical('a store whose block holds no key of the 2FA secrets', callShellArray($projDir, Array('config', 'secrt', 'tfakey'))['code'], 3);
callShellArray($projDir, Array('config', 'write', 'access', 'native.tfakey', 'env:NYU_TFA_KEY', '--force'));
$runVals = callShellArray($projDir, Array('config', 'secrt', 'tfakey'));
$tfaKey = strval(parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? '');
checkIdentical('the key of the 2FA secrets is written in the environment file, and not shown', Array($runVals['code'], $runVals['text'], strlen($tfaKey)>=48), Array(0, "written: .env NYU_TFA_KEY\n", true));
checkIdentical('a key in use is only replaced when confirmed', Array(callShellArray($projDir, Array('config', 'secrt', 'tfakey', '--noask'))['code'], parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? null), Array(3, $tfaKey));
checkPregMatch('replacing it tells what is lost', callShellArray($projDir, Array('config', 'secrt', 'tfakey'), "n\n")['errs'], '/Replace the key of the 2FA secrets in use: every secret stored with it stops being readable/');
callShellArray($projDir, Array('config', 'write', 'access', 'native.tfakey', 'written-in-the-block'));
checkIdentical('a key written in the file is masked', runShellAsJson($projDir, Array('config', 'shows', 'access', 'native.tfakey'))['rows'] ?? null, Array(Array('key'=>'native.tfakey', 'value'=>'********')));
$jsnVals = runShellAsJson($projDir, Array('config', 'secrt', 'htpass', 'bob'));
$htpLine = trim(strval(fetchInProject($projDir, 'conf/.htpasswd')));
checkIdentical('a htpasswd line is written with a password made and shown once', Array(strpos($htpLine, 'bob:$2y$'), password_verify($jsnVals['secret']['Password of bob'] ?? '', substr($htpLine, 4))), Array(0, true));
checkIdentical('the line of a user is only replaced when confirmed', Array(callShellArray($projDir, Array('config', 'secrt', 'htpass', 'bob', '--noask'))['code'], trim(strval(fetchInProject($projDir, 'conf/.htpasswd')))), Array(3, $htpLine));
$runVals = callShellArray($projDir, Array('config', 'secrt', 'htpass', 'carol', '--typed'), "typed-pass\n");
$htpList = explode("\n", trim(strval(fetchInProject($projDir, 'conf/.htpasswd'))));
checkIdentical('a typed password is not shown, and the other lines are kept', Array($runVals['text'], $htpList[0], password_verify('typed-pass', substr($htpList[1] ?? '', 6))), Array("written: conf/.htpasswd carol\n", $htpLine, true));
$logText = strval(fetchInProject($projDir, 'temp/logs/shell.log'));
checkIdentical('no secret goes to the log', Array(strpos($logText, parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_API_KEY']), strpos($logText, 'typed-pass'), preg_match('/API key made, written in \.env NYU_API_KEY by user/', $logText)), Array(false, false, 1));

// route
callShellArray($projDir, Array('config', 'write', 'preset', 'sample.auths.needed', 'true'));
$jsnVals = runShellAsJson($projDir, Array('config', 'route', 'sample'));
checkIdentical('route lists the declared routes, of the site then of the Runs with their owner, then the actions the access names', $jsnVals['rows'] ?? null, Array(
	Array('path'=>'/hello', 'methods'=>'GET', 'kind'=>'htpage', 'target'=>'hello', 'owner'=>'site', 'access'=>'open'),
	Array('path'=>'/admin/$id', 'methods'=>'GET, POST', 'kind'=>'action', 'target'=>'users/view', 'owner'=>'site', 'access'=>'manage'),
	Array('path'=>'/old', 'methods'=>'GET', 'kind'=>'redrct', 'target'=>'/hello', 'owner'=>'site', 'access'=>'logged'),
	Array('path'=>'/things', 'methods'=>'GET', 'kind'=>'action', 'target'=>'shows/index', 'owner'=>'site', 'access'=>'logged'),
	Array('path'=>'/things', 'methods'=>'POST', 'kind'=>'action', 'target'=>'shows/create', 'owner'=>'site', 'access'=>'logged'),
	Array('path'=>'/shelf/board', 'methods'=>'GET', 'kind'=>'action', 'target'=>'Shelf/index', 'owner'=>'Shelf', 'access'=>'logged'),
	Array('path'=>'', 'methods'=>'any', 'kind'=>'action', 'target'=>'auth/login', 'owner'=>'', 'access'=>'open'),
	Array('path'=>'', 'methods'=>'any', 'kind'=>'action', 'target'=>'users/*', 'owner'=>'', 'access'=>'manage')
));
checkIdentical('route tells the preset, how it finds a route, and who the other routes are served to', Array($jsnVals['exit'] ?? null, $jsnVals['preset'] ?? null, $jsnVals['detect'] ?? null, $jsnVals['others'] ?? null), Array(0, 'sample', 'autopath', 'logged'));
checkIdentical('an open preset', runShellAsJson($projDir, Array('config', 'route'))['others'] ?? null, 'open');
$runVals = callShellArray($projDir, Array('config', 'route', 'wrongs'));
checkIdentical('roles that cannot be applied are told', Array($runVals['code'], $runVals['errs']), Array(4, "Every request of this preset is refused: unknown role for the route \"users\\/*\"\n"));

finishChecking();
