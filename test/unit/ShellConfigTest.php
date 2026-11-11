<?php

// Command line, config : the config files shown and written with their
// comments and layout kept, the entry points, the secrets, the routes.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

$iniText = buildSystemIni("\n; REVERSE PROXY\n; Addresses of the proxies\nPRX_LIST = \"\"\n");
$axsText = "{\n"
	."\t\"plugLibs\": { \"authWith\": [], \"sessWith\": [] },\n"
	."\t\"loginSet\": { \"authWith\": \"nativeDb\", \"authConf\": \"nativeDb\", \"sessWith\": \"jwtToken\", \"sessConf\": \"jwtToken\" },\n"
	."\t\"htpasswd\": { \"passFile\": \"conf/.htpasswd\" },\n"
	."\t\"nativeDb\": { \"linkName\": \"\", \"useTable\": \"users\", \"minChars\": 10 },\n"
	."\t\"regdKeys\": { \"headName\": \"X-Api-Key\", \"keysList\": [\"env:NYU_API_KEY\"] },\n"
	."\t\"jwtToken\": { \"hmacSecr\": \"env:NYU_JWT_SECRET\", \"lifeSecs\": 3600, \"signAlgo\": \"HS256\" },\n"
	."\t\"inline\":   { \"hmacSecr\": \"written-here-0123456789\", \"keysList\": [\"k-1\", \"k-2\"] }\n"
	."}\n";
$dbsText = "{\n\t\"main\": {\n\t\t\"dbEngine\": \"sqlitedb\",\n\t\t\"hostName\": \"\",\n\t\t\"baseName\": \"data.sqlite\",\n\t\t\"userName\": \"\",\n\t\t\"password\": \"env:NYU_DB_PASS\"\n\t}\n}\n";
$rpsText = "{\n\t// Name of the preset\n\t\"presetId\": \"sample\",\n\t\"describe\": \"A preset of the tests\",\n\t\"settings\": { \"fileType\": \"all\", \"fileName\": \"any\" },\n"
	."\t# Access\n\t\"security\": {\n\t\t// Carrier : \"sessWith\": \"httpBasc\" would be another\n\t\t\"sessWith\": \"jwtToken\",\n\t\t\"sessConf\": \"jwtToken\",\n\t\t\"authWith\":\t\"native\",\n\t\t\"required\": true,\n"
	."\t\t\"openList\": [\"auth/login\", \"/hello\"],\n\t\t\"rteRoles\": { \"users/*\": \"sysadmin\", \"/admin/\$id\": \"operator\" }\n\t},\n"
	."\t\"incoming\": [ { \"nameFrom\": \"QUERY\", \"dataFrom\": \"QUERY\", \"fieldSet\": \"all\" } ],\n"
	."\t\"routings\": { \"detectBy\": \"autopath\", \"pathForm\": \"\$appToRun/\$doAction/\$argument\", \"routeDir\": \"conf/routes\" },\n"
	."\t\"response\": { \"outputAs\": \"jsonData\", \"headList\": { \"Cache-Control\": \"no-cache\" } }\n}\n";
$rteText = '{ "hello": { "pointsTo": { "pageFile": "hello" } }, "admin/$id": { "mthdList": "GET, POST", "pointsTo": { "appToRun": "users", "doAction": "view" } },'
	.' "old": { "pointsTo": { "redirect": "/hello", "httpCode": 301 } }, "things": { "pointsTo": { "GET": { "appToRun": "shows", "doAction": "overview" }, "POST": { "appToRun": "shows", "doAction": "create" } } } }';

$projDir = makeProjectTxt('config', Array(
	'conf/system.ini'=>$iniText, 'conf/access.json'=>$axsText, 'conf/database.json'=>$dbsText,
	'conf/presets/sample.rps'=>$rpsText, 'conf/presets/default.rps'=>'{ "presetId": "default", "settings": { "fileType": "all" }, "security": { "required": false } }',
	'conf/presets/wrongs.rps'=>'{ "presetId": "wrongs", "settings": { "fileType": "all" }, "security": { "sessWith": "jwtToken", "rteRoles": { "users/*": "boss" } } }',
	'conf/routes/main.rte'=>$rteText, 'runs/Shelf/routes.rte'=>'{ "board": { "mthdList": "GET", "pointsTo": { "doAction": "index" } } }',
	'www/index.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner();\n", 'www/api.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner( \"default\", \$overLoad );\n",
	'www/own.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner(\$presetNm);\n", 'www/plain.php'=>"<?php echo 1;\n",
	'.env'=>"# Secrets\nNYU_JWT_SECRET=\nNYU_API_KEY=old-key\n"
));

// shows
checkIdentical('shows system lists the keys as written', array_slice(runShellAsJson($projDir, Array('settings', 'showConf', 'coreConf'))['rows'] ?? Array(), 0, 2), Array(Array('key'=>'ENV_NAME', 'value'=>'DEVS'), Array('key'=>'LOG_MODE', 'value'=>'FULL')));
checkIdentical('shows one key', callShellArray($projDir, Array('settings', 'showConf', 'coreConf', 'LOG_PATH'))['text'], "key       value\nLOG_PATH  /temp/logs\n");
checkIdentical('shows a block, its values as written', runShellAsJson($projDir, Array('settings', 'showConf', 'accsConf', 'jwtToken'))['rows'] ?? null, Array(Array('key'=>'jwtToken.hmacSecr', 'value'=>'env:NYU_JWT_SECRET'), Array('key'=>'jwtToken.lifeSecs', 'value'=>3600), Array('key'=>'jwtToken.signAlgo', 'value'=>'HS256')));
checkIdentical('a secret written in the file is masked', runShellAsJson($projDir, Array('settings', 'showConf', 'accsConf', 'inline'))['rows'] ?? null, Array(Array('key'=>'inline.hmacSecr', 'value'=>'********'), Array('key'=>'inline.keysList', 'value'=>Array('********', '********'))));
checkIdentical('nothing under a path', Array(callShellArray($projDir, Array('settings', 'showConf', 'accsConf', 'loginSet.nothing'))['code'], callShellArray($projDir, Array('settings', 'showConf', 'coreConf', 'NOT_HERE'))['code'], callShellArray($projDir, Array('settings', 'showConf', 'other'))['code']), Array(3, 3, 2));
checkIdentical('shows the databases, the password as the name it is kept under', runShellAsJson($projDir, Array('settings', 'showConf', 'dataConf', 'main.password'))['rows'] ?? null, Array(Array('key'=>'main.password', 'value'=>'env:NYU_DB_PASS')));
$rowList = runShellAsJson($projDir, Array('settings', 'showConf', 'preset'))['rows'] ?? Array();
checkIdentical('shows the presets', Array(array_column($rowList, 'preset'), $rowList[1]['about'] ?? null), Array(Array('default', 'sample', 'wrongs'), 'A preset of the tests'));
checkIdentical('shows a key of a preset', runShellAsJson($projDir, Array('settings', 'showConf', 'preset', 'sample.security.openList'))['rows'] ?? null, Array(Array('key'=>'security.openList', 'value'=>Array('auth/login', '/hello'))));
checkIdentical('a preset that does not exist', callShellArray($projDir, Array('settings', 'showConf', 'preset', 'nosuch'))['code'], 1);

// write : system.ini
checkIdentical('write system changes the value on its line', Array(callShellArray($projDir, Array('settings', 'setValue', 'coreConf', 'LOG_MODE', 'BASE'))['text'], fetchInProject($projDir, 'conf/system.ini')), Array("written: coreConf LOG_MODE\n", str_replace('LOG_MODE = FULL', 'LOG_MODE = BASE', $iniText)));
checkIdentical('the former file is copied to temp/archives', array_map(function( String $copyPath ): String { return strval(preg_replace('/\d{8}-\d{6}/', 'TIME', basename($copyPath))); }, glob($projDir.DS.'temp'.DS.'archives'.DS.'*')), Array('system.ini.TIME'));
checkIdentical('a value that is not plain words is written between quotes', Array(callShellArray($projDir, Array('settings', 'setValue', 'coreConf', 'PRX_LIST', '10.0.0.5, 10.0.0.6'))['code'], strpos(strval(fetchInProject($projDir, 'conf/system.ini')), 'PRX_LIST = "10.0.0.5, 10.0.0.6"'."\n")!==false), Array(0, true));
checkIdentical('a value a key does not take, a key that is not there, a key that is not one', Array(callShellArray($projDir, Array('settings', 'setValue', 'coreConf', 'LOG_MODE', 'LOUD'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'coreConf', 'NOT_HERE', '1'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'coreConf', 'logmode', '1'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'coreConf', 'LOG_MODE'))['code']), Array(3, 3, 2, 2));
checkIdentical('nothing was changed by the refusals', strpos(strval(fetchInProject($projDir, 'conf/system.ini')), 'LOG_MODE = BASE')!==false, true);

// write : access.json, database.json
checkIdentical('write access keeps the layout of the file', Array(callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'nativeDb.minChars', '12'))['code'], fetchInProject($projDir, 'conf/access.json')), Array(0, str_replace('"minChars": 10', '"minChars": 12', $axsText)));
checkIdentical('a check that does not exist, a key or a block that is not there', Array(callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'loginSet.authWith', 'nothing'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'nativeDb.newkey', 'x'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'newblk.newkey', 'x'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'native', 'x'))['code']), Array(3, 3, 3, 2));
checkIdentical('--overrule adds a key and a block', Array(callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'newblk.keysList', '["a", "b"]', '--overrule'))['code'], fetchInProject($projDir, 'conf/access.json')), Array(0, str_replace(Array('"minChars": 10', "\"k-2\"] }\n"), Array('"minChars": 12', "\"k-2\"] },\n\t\"newblk\":   { \"keysList\": [\"a\", \"b\"] }\n"), $axsText)));
checkIdentical('write access takes a known check', Array(callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'loginSet.authWith', 'userFile'))['code'], runShellAsJson($projDir, Array('settings', 'showConf', 'accsConf', 'loginSet.authWith'))['rows'][0]['value'] ?? null), Array(0, 'userFile'));
checkIdentical('write dbases keeps the layout of the file', Array(callShellArray($projDir, Array('settings', 'setValue', 'dataConf', 'main.hostName', 'db.local'))['code'], fetchInProject($projDir, 'conf/database.json')), Array(0, str_replace('"hostName": ""', '"hostName": "db.local"', $dbsText)));

// write : presets
checkIdentical('write preset changes the value in its place, comments kept', Array(callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.security.sessWith', 'httpBasc'))['code'], fetchInProject($projDir, 'conf/presets/sample.rps')), Array(0, str_replace("\t\t\"sessWith\": \"jwtToken\"", "\t\t\"sessWith\": \"httpBasc\"", $rpsText)));
checkIdentical('a value after a tab, a value that is not a text, a key of the preset itself', Array(callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.security.authWith', 'userFile'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.security.required', 'false'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.describe', 'Changed, "quoted"'))['code'], fetchInProject($projDir, 'conf/presets/sample.rps')),
	Array(0, 0, 0, str_replace(Array("\t\t\"sessWith\": \"jwtToken\"", "\"authWith\":\t\"native\"", '"required": true', '"describe": "A preset of the tests"'), Array("\t\t\"sessWith\": \"httpBasc\"", "\"authWith\":\t\"userFile\"", '"required": false', '"describe": "Changed, \"quoted\""'), $rpsText)));
checkIdentical('a list of a preset is not written, nor a key it does not have, nor a preset that does not exist', Array(callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.security.openList', 'x'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.security.signin', '/in'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.input.fields', 'none'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'preset', 'nosuch.security.required', 'true'))['code'], callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample', 'x'))['code']), Array(3, 3, 3, 3, 2));
checkPregMatch('a write is traced, without its value', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Config: preset sample\.describe written by user "cli:[\w.\-]+"\n/');

// entry
checkIdentical('entry lists the entry points and their preset', runShellAsJson($projDir, Array('settings', 'wwwEntry'))['rows'] ?? null, Array(Array('entry'=>'api', 'preset'=>'default'), Array('entry'=>'index', 'preset'=>'default'), Array('entry'=>'own', 'preset'=>'default')));
checkIdentical('entry names a preset where there was none', Array(callShellArray($projDir, Array('settings', 'wwwEntry', 'index', 'sample'))['text'], fetchInProject($projDir, 'www/index.php')), Array("index: sample\n", "<?php\n\$nyuRun = new \\nyu\\Runner('sample');\n"));
checkIdentical('entry replaces the preset named, what follows it kept', Array(callShellArray($projDir, Array('settings', 'wwwEntry', 'api', 'sample'))['code'], fetchInProject($projDir, 'www/api.php'), callShellArray($projDir, Array('settings', 'wwwEntry', 'api'))['text']), Array(0, "<?php\n\$nyuRun = new \\nyu\\Runner( 'sample', \$overLoad );\n", "api: sample\n"));
checkIdentical('a preset that does not exist, an entry that names its preset its own way, a file that starts no Runner or does not exist', Array(callShellArray($projDir, Array('settings', 'wwwEntry', 'index', 'nosuch'))['code'], callShellArray($projDir, Array('settings', 'wwwEntry', 'own', 'sample'))['code'], callShellArray($projDir, Array('settings', 'wwwEntry', 'plain', 'sample'))['code'], callShellArray($projDir, Array('settings', 'wwwEntry', '../conf/x', 'sample'))['code']), Array(3, 3, 3, 3));

// secrt
$runVals = callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken'));
$envVals = parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW);
checkIdentical('the secret of the tokens is written in the environment file, and not shown', Array($runVals['code'], $runVals['text'], strlen($envVals['NYU_JWT_SECRET'])>=48, $envVals['NYU_API_KEY'], strpos(strval(fetchInProject($projDir, '.env')), "# Secrets\n")), Array(0, "written: .env NYU_JWT_SECRET\n", true, 'old-key', 0));
checkIdentical('a secret in use is only replaced when confirmed', Array(callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--noPrompt'))['code'], parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_JWT_SECRET']), Array(3, $envVals['NYU_JWT_SECRET']));
$jsnVals = runShellAsJson($projDir, Array('settings', 'makeSecr', 'jwtToken', '--showSecr'), "y\n");
checkIdentical('--showSecr shows it', Array($jsnVals['secret']['Secret of the tokens'] ?? '', ($jsnVals['secret']['Secret of the tokens'] ?? '')!=$envVals['NYU_JWT_SECRET']), Array(parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_JWT_SECRET'], true));
$jsnVals = runShellAsJson($projDir, Array('settings', 'makeSecr', 'keysList', '--yesToAll'));
checkIdentical('an API key is shown once, and written where the block reads it', Array(strlen($jsnVals['secret']['API key'] ?? ''), $jsnVals['written'] ?? null, parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_API_KEY']), Array(64, '.env NYU_API_KEY', $jsnVals['secret']['API key'] ?? ''));
$jsnVals = runShellAsJson($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=inline', '--yesToAll', '--showSecr'));
checkIdentical('a secret the block holds itself is written in the block', Array($jsnVals['written'] ?? null, json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['inline'], json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['jwtToken']['hmacSecr']), Array('conf/access.json inline', Array('hmacSecr'=>$jsnVals['secret']['Secret of the tokens'] ?? '', 'keysList'=>Array('k-1', 'k-2')), 'env:NYU_JWT_SECRET'));
checkIdentical('a block that holds no such secret', Array(callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=native', '--yesToAll'))['code'], callShellArray($projDir, Array('settings', 'makeSecr', 'other'))['code']), Array(3, 2));
// With RS256, a key pair in conf/keys
if( function_exists('openssl_pkey_new') ){
	callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.signAlgo', 'RS256', '--overrule'));
	$runVals = callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=rstokn'));
	$prvText = strval(fetchInProject($projDir, 'conf/keys/rstokn.key'));
	$keyInfo = ($prvText!='') ? openssl_pkey_get_details(openssl_pkey_get_private($prvText)) : Array();
	$rsaBlok = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array();
	checkIdentical('with RS256, a key pair of 4096 bits is made in conf/keys, its paths given to the block', Array($runVals['code'], $runVals['text'], $keyInfo['bits'] ?? null, ($keyInfo['type'] ?? null)===OPENSSL_KEYTYPE_RSA, $rsaBlok), Array(0, "written: conf/keys/rstokn.key, conf/keys/rstokn.pub\n", 4096, true, Array('signAlgo'=>'RS256', 'privFile'=>'conf/keys/rstokn.key', 'publFile'=>'conf/keys/rstokn.pub')));
	$rsaJwt = new nyu\Access\Sess\JWT( Array('signAlgo'=>'RS256', 'privFile'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key', 'publFile'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.pub') );
	checkIdentical('the pair signs and checks a token', ($rsaJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$rsaJwt->issueTokenText(Array('userName'=>'anna'))))) ?: Array())['userName'] ?? null, 'anna');
	if( DS=='/' ){
		checkIdentical('the private key is readable by its owner only', fileperms($projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key') & 0777, 0600);
	}
	checkIdentical('keys in use are only replaced when confirmed', Array(callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=rstokn', '--noPrompt'))['code'], fetchInProject($projDir, 'conf/keys/rstokn.key')), Array(3, $prvText));
	$pubText = strval(fetchInProject($projDir, 'conf/keys/rstokn.pub'));
	checkIdentical('confirmed, a new pair replaces them, and no copy of the former key is kept', Array(callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=rstokn', '--yesToAll'))['code'], fetchInProject($projDir, 'conf/keys/rstokn.key')!==$prvText, glob($projDir.DS.'temp'.DS.'archives'.DS.'rstokn*')), Array(0, true, Array()));
	checkIdentical('the former public key is kept as prevFile, and checks the tokens it signed', Array(fetchInProject($projDir, 'conf/keys/rstokn.old.pub'), json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn']['prevFile'] ?? null), Array($pubText, 'conf/keys/rstokn.old.pub'));
	$oldTokn = $rsaJwt->issueTokenText( Array('userName'=>'anna') );
	$nowJwt = new nyu\Access\Sess\JWT( Array('signAlgo'=>'RS256', 'privFile'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key', 'prevFile'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.old.pub', 'retireAt'=>time() + 5) );
	checkIdentical('a token of the former pair passes with prevFile', ($nowJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$oldTokn))) ?: Array())['userName'] ?? null, 'anna');
	checkPregMatch('replacing them tells the former tokens are still accepted', callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=rstokn'), "n\n")['errs'], '/still accepted until they end, checked with conf\/keys\/rstokn\.old\.pub/');
	$rsaBlok = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array();
	checkIdentical('the time the former key was retired is written beside it', is_int($rsaBlok['retireAt'] ?? null) && abs($rsaBlok['retireAt'] - time())<60, true);
	// A key that leaked : --dropsAll keeps no former key, every token in use ends
	checkPregMatch('--dropsAll tells every token in use ends', callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=rstokn', '--dropsAll'), "n\n")['errs'], '/Replace the keys of the tokens in use: every token in use ends now/');
	$befKey = strval(fetchInProject($projDir, 'conf/keys/rstokn.key'));
	$runVals = callShellArray($projDir, Array('settings', 'makeSecr', 'jwtToken', '--useBlock=rstokn', '--dropsAll', '--yesToAll'));
	$rsaBlok = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array();
	checkIdentical('--dropsAll: a new pair, no former key file, prevFile empty, no retire time', Array($runVals['code'], fetchInProject($projDir, 'conf/keys/rstokn.key')!==$befKey, is_file($projDir.DS.'conf'.DS.'keys'.DS.'rstokn.old.pub'), $rsaBlok['prevFile'] ?? null, array_key_exists('retireAt', $rsaBlok)), Array(0, true, false, '', false));
	$dropJwt = new nyu\Access\Sess\JWT( Array('signAlgo'=>'RS256', 'privFile'=>$projDir.DS.'conf'.DS.'keys'.DS.'rstokn.key') + $rsaBlok );
	file_put_contents(inTemporaryDir('former.key'), $befKey);
	$befTokn = (new nyu\Access\Sess\JWT( Array('signAlgo'=>'RS256', 'privFile'=>inTemporaryDir('former.key')) ))->issueTokenText( Array('userName'=>'anna') );
	checkIdentical('--dropsAll: a token of the former pair is refused', Array($dropJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$befTokn))), $dropJwt->finalErrorText()), Array(false, 'Invalid token'));
	// write : the keys binding the tokens to this project
	checkIdentical('write access: an issuer, audiences, a previous key that is one', Array(
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.issuerId', 'https://auth.example.org', '--overrule'))['code'],
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.audience', '["api.example.org", "app.example.org"]', '--overrule'))['code'],
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.prevFile', 'conf/keys/rstokn.pub'))['code'],
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.prevFile', ''))['code']
	), Array(0, 0, 0, 0));
	checkIdentical('write access: refused, an issuer or audiences that are not texts, a previous key that is not one', Array(
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.issuerId', '42'))['code'],
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.audience', '[1, 2]'))['code'],
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.audience', '{"a": "b"}'))['code'],
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.prevFile', 'conf/access.json'))['code'],
		callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'rstokn.prevFile', 'conf/keys/rstokn.key.missing'))['code']
	), Array(3, 3, 3, 3, 3));
	checkIdentical('write access: what was refused was not written', array_intersect_key(json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['rstokn'] ?? Array(), Array('issuerId'=>1, 'audience'=>1, 'prevFile'=>1)), Array('prevFile'=>'', 'issuerId'=>'https://auth.example.org', 'audience'=>Array('api.example.org', 'app.example.org')));
}
// The key of the 2FA secrets, in the block of the store the login uses
checkIdentical('a store whose block holds no key of the 2FA secrets', callShellArray($projDir, Array('settings', 'makeSecr', 'tfaCrypt'))['code'], 3);
callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'nativeDb.tfaCrypt', 'env:NYU_TFA_KEY', '--overrule'));
$runVals = callShellArray($projDir, Array('settings', 'makeSecr', 'tfaCrypt'));
$tfaKey = strval(parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? '');
checkIdentical('the key of the 2FA secrets is written in the environment file, and not shown', Array($runVals['code'], $runVals['text'], strlen($tfaKey)>=48), Array(0, "written: .env NYU_TFA_KEY\n", true));
checkIdentical('a key in use is only replaced when confirmed', Array(callShellArray($projDir, Array('settings', 'makeSecr', 'tfaCrypt', '--noPrompt'))['code'], parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? null), Array(3, $tfaKey));
checkPregMatch('replacing it tells what is lost', callShellArray($projDir, Array('settings', 'makeSecr', 'tfaCrypt'), "n\n")['errs'], '/Replace the key of the 2FA secrets in use: every secret stored with it stops being readable/');
callShellArray($projDir, Array('settings', 'setValue', 'accsConf', 'nativeDb.tfaCrypt', 'written-in-the-block'));
checkIdentical('a key written in the file is masked', runShellAsJson($projDir, Array('settings', 'showConf', 'accsConf', 'nativeDb.tfaCrypt'))['rows'] ?? null, Array(Array('key'=>'nativeDb.tfaCrypt', 'value'=>'********')));
$jsnVals = runShellAsJson($projDir, Array('settings', 'makeSecr', 'htpasswd', 'bob'));
$htpLine = trim(strval(fetchInProject($projDir, 'conf/.htpasswd')));
checkIdentical('a htpasswd line is written with a password made and shown once', Array(strpos($htpLine, 'bob:$2y$'), password_verify($jsnVals['secret']['Password of bob'] ?? '', substr($htpLine, 4))), Array(0, true));
checkIdentical('the line of a user is only replaced when confirmed', Array(callShellArray($projDir, Array('settings', 'makeSecr', 'htpasswd', 'bob', '--noPrompt'))['code'], trim(strval(fetchInProject($projDir, 'conf/.htpasswd')))), Array(3, $htpLine));
$runVals = callShellArray($projDir, Array('settings', 'makeSecr', 'htpasswd', 'carol', '--typeItIn'), "typed-pass\n");
$htpList = explode("\n", trim(strval(fetchInProject($projDir, 'conf/.htpasswd'))));
checkIdentical('a typed password is not shown, and the other lines are kept', Array($runVals['text'], $htpList[0], password_verify('typed-pass', substr($htpList[1] ?? '', 6))), Array("written: conf/.htpasswd carol\n", $htpLine, true));
$logText = strval(fetchInProject($projDir, 'temp/logs/shell.log'));
checkIdentical('no secret goes to the log', Array(strpos($logText, parse_ini_string(strval(fetchInProject($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_API_KEY']), strpos($logText, 'typed-pass'), preg_match('/API key made, written in \.env NYU_API_KEY by user/', $logText)), Array(false, false, 1));

// route
callShellArray($projDir, Array('settings', 'setValue', 'preset', 'sample.security.required', 'true'));
$jsnVals = runShellAsJson($projDir, Array('settings', 'fullList', 'sample'));
checkIdentical('route lists the declared routes, of the site then of the Runs with their owner, then the actions the access names', $jsnVals['rows'] ?? null, Array(
	Array('path'=>'/hello', 'methods'=>'GET', 'kind'=>'htmlPage', 'target'=>'hello', 'owner'=>'site', 'access'=>'open'),
	Array('path'=>'/admin/$id', 'methods'=>'GET, POST', 'kind'=>'toAction', 'target'=>'users/view', 'owner'=>'site', 'access'=>'sysadmin'),
	Array('path'=>'/old', 'methods'=>'GET', 'kind'=>'redirect', 'target'=>'/hello', 'owner'=>'site', 'access'=>'logged'),
	Array('path'=>'/things', 'methods'=>'GET', 'kind'=>'toAction', 'target'=>'shows/overview', 'owner'=>'site', 'access'=>'logged'),
	Array('path'=>'/things', 'methods'=>'POST', 'kind'=>'toAction', 'target'=>'shows/create', 'owner'=>'site', 'access'=>'logged'),
	Array('path'=>'/shelf/board', 'methods'=>'GET', 'kind'=>'toAction', 'target'=>'Shelf/index', 'owner'=>'Shelf', 'access'=>'logged'),
	Array('path'=>'', 'methods'=>'any', 'kind'=>'toAction', 'target'=>'auth/login', 'owner'=>'', 'access'=>'open'),
	Array('path'=>'', 'methods'=>'any', 'kind'=>'toAction', 'target'=>'users/*', 'owner'=>'', 'access'=>'sysadmin')
));
checkIdentical('route tells the preset, how it finds a route, and who the other routes are served to', Array($jsnVals['exit'] ?? null, $jsnVals['preset'] ?? null, $jsnVals['detectBy'] ?? null, $jsnVals['others'] ?? null), Array(0, 'sample', 'autopath', 'logged'));
checkIdentical('an open preset', runShellAsJson($projDir, Array('settings', 'fullList'))['others'] ?? null, 'open');
$runVals = callShellArray($projDir, Array('settings', 'fullList', 'wrongs'));
checkIdentical('roles that cannot be applied are told', Array($runVals['code'], $runVals['errs']), Array(4, "Every request of this preset is refused: unknown role for the route \"users\\/*\"\n"));

finishChecking();
