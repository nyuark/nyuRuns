<?php

// Command line, config : the config files shown and written with their
// comments and layout kept, the entry points, the secrets, the routes.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

$iniText = sampleIni("\n; REVERSE PROXY\n; Addresses of the proxies\nPRX_LIST = \"\"\n");
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
	."\t\"rnder\": { \"tmplte\": \"plain\", \"return\": \"json\", \"headrs\": { \"Cache-Control\": \"no-cache\" } }\n}\n";
$rteText = '{ "hello": { "target": { "pge": "hello" } }, "admin/$id": { "allows": "GET, POST", "target": { "app": "users", "act": "view" } },'
	.' "old": { "target": { "url": "/hello", "cod": 301 } }, "things": { "target": { "GET": { "app": "shows", "act": "index" }, "POST": { "app": "shows", "act": "create" } } } }';

$projDir = makeProjt('config', Array(
	'conf/system.ini'=>$iniText, 'conf/access.json'=>$axsText, 'conf/database.json'=>$dbsText,
	'conf/presets/sample.rps'=>$rpsText, 'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false } }',
	'conf/presets/wrongs.rps'=>'{ "label": "wrongs", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "rolert": { "users/*": "boss" } } }',
	'conf/routes/main.rte'=>$rteText,
	'www/index.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner();\n", 'www/api.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner( \"dfault\", \$overLoad );\n",
	'www/own.php'=>"<?php\n\$nyuRun = new \\nyu\\Runner(\$presetNm);\n", 'www/plain.php'=>"<?php echo 1;\n",
	'.env'=>"# Secrets\nNYU_JWT_SECRET=\nNYU_API_KEY=old-key\n"
));

// shows
checkSame('shows system lists the keys as written', array_slice(jsonShell($projDir, Array('config', 'shows', 'system'))['rows'] ?? Array(), 0, 2), Array(Array('key'=>'ENV_NAME', 'value'=>'DEV'), Array('key'=>'LOG_MODE', 'value'=>'FULL')));
checkSame('shows one key', runShell($projDir, Array('config', 'shows', 'system', 'LOG_PATH'))['text'], "key       value\nLOG_PATH  /temp/logs\n");
checkSame('shows a block, its values as written', jsonShell($projDir, Array('config', 'shows', 'access', 'dftokn'))['rows'] ?? null, Array(Array('key'=>'dftokn.secret', 'value'=>'env:NYU_JWT_SECRET'), Array('key'=>'dftokn.lifetm', 'value'=>3600), Array('key'=>'dftokn.algrtm', 'value'=>'HS256')));
checkSame('a secret written in the file is masked', jsonShell($projDir, Array('config', 'shows', 'access', 'inline'))['rows'] ?? null, Array(Array('key'=>'inline.secret', 'value'=>'********'), Array('key'=>'inline.keylst', 'value'=>Array('********', '********'))));
checkSame('nothing under a path', Array(runShell($projDir, Array('config', 'shows', 'access', 'login.nothing'))['code'], runShell($projDir, Array('config', 'shows', 'system', 'NOT_HERE'))['code'], runShell($projDir, Array('config', 'shows', 'other'))['code']), Array(3, 3, 2));
checkSame('shows the databases, the password as the name it is kept under', jsonShell($projDir, Array('config', 'shows', 'dbases', 'main.pass'))['rows'] ?? null, Array(Array('key'=>'main.pass', 'value'=>'env:NYU_DB_PASS')));
$rowList = jsonShell($projDir, Array('config', 'shows', 'preset'))['rows'] ?? Array();
checkSame('shows the presets', Array(array_column($rowList, 'preset'), $rowList[1]['about'] ?? null), Array(Array('dfault', 'sample', 'wrongs'), 'A preset of the tests'));
checkSame('shows a key of a preset', jsonShell($projDir, Array('config', 'shows', 'preset', 'sample.auths.openrt'))['rows'] ?? null, Array(Array('key'=>'auths.openrt', 'value'=>Array('auth/login', '/hello'))));
checkSame('a preset that does not exist', runShell($projDir, Array('config', 'shows', 'preset', 'nosuch'))['code'], 1);

// write : system.ini
checkSame('write system changes the value on its line', Array(runShell($projDir, Array('config', 'write', 'system', 'LOG_MODE', 'BASE'))['text'], readInPrj($projDir, 'conf/system.ini')), Array("written: system LOG_MODE\n", str_replace('LOG_MODE = FULL', 'LOG_MODE = BASE', $iniText)));
checkSame('the former file is copied to temp/backup', array_map(function( String $copyPath ): String { return strval(preg_replace('/\d{8}-\d{6}/', 'TIME', basename($copyPath))); }, glob($projDir.DS.'temp'.DS.'backup'.DS.'*')), Array('system.ini.TIME'));
checkSame('a value that is not plain words is written between quotes', Array(runShell($projDir, Array('config', 'write', 'system', 'PRX_LIST', '10.0.0.5, 10.0.0.6'))['code'], strpos(strval(readInPrj($projDir, 'conf/system.ini')), 'PRX_LIST = "10.0.0.5, 10.0.0.6"'."\n")!==false), Array(0, true));
checkSame('a value a key does not take, a key that is not there, a key that is not one', Array(runShell($projDir, Array('config', 'write', 'system', 'LOG_MODE', 'LOUD'))['code'], runShell($projDir, Array('config', 'write', 'system', 'NOT_HERE', '1'))['code'], runShell($projDir, Array('config', 'write', 'system', 'logmode', '1'))['code'], runShell($projDir, Array('config', 'write', 'system', 'LOG_MODE'))['code']), Array(3, 3, 2, 2));
checkSame('nothing was changed by the refusals', strpos(strval(readInPrj($projDir, 'conf/system.ini')), 'LOG_MODE = BASE')!==false, true);

// write : access.json, database.json
checkSame('write access keeps the layout of the file', Array(runShell($projDir, Array('config', 'write', 'access', 'native.minlen', '12'))['code'], readInPrj($projDir, 'conf/access.json')), Array(0, str_replace('"minlen": 10', '"minlen": 12', $axsText)));
checkSame('a check that does not exist, a key or a block that is not there', Array(runShell($projDir, Array('config', 'write', 'access', 'login.authby', 'nothing'))['code'], runShell($projDir, Array('config', 'write', 'access', 'native.newkey', 'x'))['code'], runShell($projDir, Array('config', 'write', 'access', 'newblk.newkey', 'x'))['code'], runShell($projDir, Array('config', 'write', 'access', 'native', 'x'))['code']), Array(3, 3, 3, 2));
checkSame('--force adds a key and a block', Array(runShell($projDir, Array('config', 'write', 'access', 'newblk.keylst', '["a", "b"]', '--force'))['code'], readInPrj($projDir, 'conf/access.json')), Array(0, str_replace(Array('"minlen": 10', "\"k-2\"] }\n"), Array('"minlen": 12', "\"k-2\"] },\n\t\"newblk\": { \"keylst\": [\"a\", \"b\"] }\n"), $axsText)));
checkSame('write access takes a known check', Array(runShell($projDir, Array('config', 'write', 'access', 'login.authby', 'usrlst'))['code'], jsonShell($projDir, Array('config', 'shows', 'access', 'login.authby'))['rows'][0]['value'] ?? null), Array(0, 'usrlst'));
checkSame('write dbases keeps the layout of the file', Array(runShell($projDir, Array('config', 'write', 'dbases', 'main.host', 'db.local'))['code'], readInPrj($projDir, 'conf/database.json')), Array(0, str_replace('"host": ""', '"host": "db.local"', $dbsText)));

// write : presets
checkSame('write preset changes the value in its place, comments kept', Array(runShell($projDir, Array('config', 'write', 'preset', 'sample.auths.sessby', 'hbasic'))['code'], readInPrj($projDir, 'conf/presets/sample.rps')), Array(0, str_replace("\t\t\"sessby\": \"jtoken\"", "\t\t\"sessby\": \"hbasic\"", $rpsText)));
checkSame('a value after a tab, a value that is not a text, a key of the preset itself', Array(runShell($projDir, Array('config', 'write', 'preset', 'sample.auths.authby', 'usrlst'))['code'], runShell($projDir, Array('config', 'write', 'preset', 'sample.auths.needed', 'false'))['code'], runShell($projDir, Array('config', 'write', 'preset', 'sample.about', 'Changed, "quoted"'))['code'], readInPrj($projDir, 'conf/presets/sample.rps')),
	Array(0, 0, 0, str_replace(Array("\t\t\"sessby\": \"jtoken\"", "\"authby\":\t\"native\"", '"needed": true', '"about": "A preset of the tests"'), Array("\t\t\"sessby\": \"hbasic\"", "\"authby\":\t\"usrlst\"", '"needed": false', '"about": "Changed, \"quoted\""'), $rpsText)));
checkSame('a list of a preset is not written, nor a key it does not have, nor a preset that does not exist', Array(runShell($projDir, Array('config', 'write', 'preset', 'sample.auths.openrt', 'x'))['code'], runShell($projDir, Array('config', 'write', 'preset', 'sample.auths.signin', '/in'))['code'], runShell($projDir, Array('config', 'write', 'preset', 'sample.input.fields', 'none'))['code'], runShell($projDir, Array('config', 'write', 'preset', 'nosuch.auths.needed', 'true'))['code'], runShell($projDir, Array('config', 'write', 'preset', 'sample', 'x'))['code']), Array(3, 3, 3, 3, 2));
checkLike('a write is traced, without its value', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Config: preset sample\.about written by user "cli:[\w.\-]+"\n/');

// entry
checkSame('entry lists the entry points and their preset', jsonShell($projDir, Array('config', 'entry'))['rows'] ?? null, Array(Array('entry'=>'api', 'preset'=>'dfault'), Array('entry'=>'index', 'preset'=>'dfault'), Array('entry'=>'own', 'preset'=>'dfault')));
checkSame('entry names a preset where there was none', Array(runShell($projDir, Array('config', 'entry', 'index', 'sample'))['text'], readInPrj($projDir, 'www/index.php')), Array("index: sample\n", "<?php\n\$nyuRun = new \\nyu\\Runner('sample');\n"));
checkSame('entry replaces the preset named, what follows it kept', Array(runShell($projDir, Array('config', 'entry', 'api', 'sample'))['code'], readInPrj($projDir, 'www/api.php'), runShell($projDir, Array('config', 'entry', 'api'))['text']), Array(0, "<?php\n\$nyuRun = new \\nyu\\Runner( 'sample', \$overLoad );\n", "api: sample\n"));
checkSame('a preset that does not exist, an entry that names its preset its own way, a file that starts no Runner or does not exist', Array(runShell($projDir, Array('config', 'entry', 'index', 'nosuch'))['code'], runShell($projDir, Array('config', 'entry', 'own', 'sample'))['code'], runShell($projDir, Array('config', 'entry', 'plain', 'sample'))['code'], runShell($projDir, Array('config', 'entry', '../conf/x', 'sample'))['code']), Array(3, 3, 3, 3));

// secrt
$runVals = runShell($projDir, Array('config', 'secrt', 'jtoken'));
$envVals = parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW);
checkSame('the secret of the tokens is written in the environment file, and not shown', Array($runVals['code'], $runVals['text'], strlen($envVals['NYU_JWT_SECRET'])>=48, $envVals['NYU_API_KEY'], strpos(strval(readInPrj($projDir, '.env')), "# Secrets\n")), Array(0, "written: .env NYU_JWT_SECRET\n", true, 'old-key', 0));
checkSame('a secret in use is only replaced when confirmed', Array(runShell($projDir, Array('config', 'secrt', 'jtoken', '--noask'))['code'], parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_JWT_SECRET']), Array(3, $envVals['NYU_JWT_SECRET']));
$jsnVals = jsonShell($projDir, Array('config', 'secrt', 'jtoken', '--shown'), "y\n");
checkSame('--shown shows it', Array($jsnVals['secret']['Secret of the tokens'] ?? '', ($jsnVals['secret']['Secret of the tokens'] ?? '')!=$envVals['NYU_JWT_SECRET']), Array(parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_JWT_SECRET'], true));
$jsnVals = jsonShell($projDir, Array('config', 'secrt', 'apikey', '--agree'));
checkSame('an API key is shown once, and written where the block reads it', Array(strlen($jsnVals['secret']['API key'] ?? ''), $jsnVals['written'] ?? null, parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_API_KEY']), Array(64, '.env NYU_API_KEY', $jsnVals['secret']['API key'] ?? ''));
$jsnVals = jsonShell($projDir, Array('config', 'secrt', 'jtoken', '--block=inline', '--agree', '--shown'));
checkSame('a secret the block holds itself is written in the block', Array($jsnVals['written'] ?? null, json_decode(strval(readInPrj($projDir, 'conf/access.json')), true)['inline'], json_decode(strval(readInPrj($projDir, 'conf/access.json')), true)['dftokn']['secret']), Array('conf/access.json inline', Array('secret'=>$jsnVals['secret']['Secret of the tokens'] ?? '', 'keylst'=>Array('k-1', 'k-2')), 'env:NYU_JWT_SECRET'));
checkSame('a block that holds no such secret', Array(runShell($projDir, Array('config', 'secrt', 'jtoken', '--block=native', '--agree'))['code'], runShell($projDir, Array('config', 'secrt', 'other'))['code']), Array(3, 2));
// The key of the 2FA secrets, in the block of the store the login uses
checkSame('a store whose block holds no key of the 2FA secrets', runShell($projDir, Array('config', 'secrt', 'tfakey'))['code'], 3);
runShell($projDir, Array('config', 'write', 'access', 'native.tfakey', 'env:NYU_TFA_KEY', '--force'));
$runVals = runShell($projDir, Array('config', 'secrt', 'tfakey'));
$tfaKey = strval(parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? '');
checkSame('the key of the 2FA secrets is written in the environment file, and not shown', Array($runVals['code'], $runVals['text'], strlen($tfaKey)>=48), Array(0, "written: .env NYU_TFA_KEY\n", true));
checkSame('a key in use is only replaced when confirmed', Array(runShell($projDir, Array('config', 'secrt', 'tfakey', '--noask'))['code'], parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_TFA_KEY'] ?? null), Array(3, $tfaKey));
checkLike('replacing it tells what is lost', runShell($projDir, Array('config', 'secrt', 'tfakey'), "n\n")['errs'], '/Replace the key of the 2FA secrets in use: every secret stored with it stops being readable/');
runShell($projDir, Array('config', 'write', 'access', 'native.tfakey', 'written-in-the-block'));
checkSame('a key written in the file is masked', jsonShell($projDir, Array('config', 'shows', 'access', 'native.tfakey'))['rows'] ?? null, Array(Array('key'=>'native.tfakey', 'value'=>'********')));
$jsnVals = jsonShell($projDir, Array('config', 'secrt', 'htpass', 'bob'));
$htpLine = trim(strval(readInPrj($projDir, 'conf/.htpasswd')));
checkSame('a htpasswd line is written with a password made and shown once', Array(strpos($htpLine, 'bob:$2y$'), password_verify($jsnVals['secret']['Password of bob'] ?? '', substr($htpLine, 4))), Array(0, true));
checkSame('the line of a user is only replaced when confirmed', Array(runShell($projDir, Array('config', 'secrt', 'htpass', 'bob', '--noask'))['code'], trim(strval(readInPrj($projDir, 'conf/.htpasswd')))), Array(3, $htpLine));
$runVals = runShell($projDir, Array('config', 'secrt', 'htpass', 'carol', '--typed'), "typed-pass\n");
$htpList = explode("\n", trim(strval(readInPrj($projDir, 'conf/.htpasswd'))));
checkSame('a typed password is not shown, and the other lines are kept', Array($runVals['text'], $htpList[0], password_verify('typed-pass', substr($htpList[1] ?? '', 6))), Array("written: conf/.htpasswd carol\n", $htpLine, true));
$logText = strval(readInPrj($projDir, 'temp/logs/shell.log'));
checkSame('no secret goes to the log', Array(strpos($logText, parse_ini_string(strval(readInPrj($projDir, '.env')), false, INI_SCANNER_RAW)['NYU_API_KEY']), strpos($logText, 'typed-pass'), preg_match('/API key made, written in \.env NYU_API_KEY by user/', $logText)), Array(false, false, 1));

// route
runShell($projDir, Array('config', 'write', 'preset', 'sample.auths.needed', 'true'));
$jsnVals = jsonShell($projDir, Array('config', 'route', 'sample'));
checkSame('route lists the declared routes, then the actions the access names', $jsnVals['rows'] ?? null, Array(
	Array('path'=>'/hello', 'methods'=>'GET', 'kind'=>'htpage', 'target'=>'hello', 'access'=>'open'),
	Array('path'=>'/admin/$id', 'methods'=>'GET, POST', 'kind'=>'action', 'target'=>'users/view', 'access'=>'manage'),
	Array('path'=>'/old', 'methods'=>'GET', 'kind'=>'redrct', 'target'=>'/hello', 'access'=>'logged'),
	Array('path'=>'/things', 'methods'=>'GET', 'kind'=>'action', 'target'=>'shows/index', 'access'=>'logged'),
	Array('path'=>'/things', 'methods'=>'POST', 'kind'=>'action', 'target'=>'shows/create', 'access'=>'logged'),
	Array('path'=>'', 'methods'=>'any', 'kind'=>'action', 'target'=>'auth/login', 'access'=>'open'),
	Array('path'=>'', 'methods'=>'any', 'kind'=>'action', 'target'=>'users/*', 'access'=>'manage')
));
checkSame('route tells the preset, how it finds a route, and who the other routes are served to', Array($jsnVals['exit'] ?? null, $jsnVals['preset'] ?? null, $jsnVals['detect'] ?? null, $jsnVals['others'] ?? null), Array(0, 'sample', 'autopath', 'logged'));
checkSame('an open preset', jsonShell($projDir, Array('config', 'route'))['others'] ?? null, 'open');
$runVals = runShell($projDir, Array('config', 'route', 'wrongs'));
checkSame('roles that cannot be applied are told', Array($runVals['code'], $runVals['errs']), Array(4, "Every request of this preset is refused: unknown role for the route \"users\\/*\"\n"));

endChecks();
