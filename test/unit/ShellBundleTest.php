<?php

// Command line, bundle : a copy of the project for an environment, holding
// only what KEEPLIST names, without secrets, private keys, tests or
// documents, its system.ini set for the mode.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

/**
 * Lists the files under a folder, from it, written with slashes.
 *
 * @param String $folderPath Full path of the folder.
 * @return Array The paths, sorted.
 */
function filesOfDr( String $folderPath ): Array {
	$pathLst = Array();
	if( !is_dir($folderPath) ){
		return $pathLst;
	}
	foreach( new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath, FilesystemIterator::SKIP_DOTS)) as $fleInf ){
		$pathLst[] = str_replace(DS, '/', substr($fleInf->getPathname(), strlen($folderPath)+1));
	}
	sort($pathLst);
	return $pathLst;
}

$projDir = makeProjectTxt('bundle', Array(
	'src/Kernel.php'=>"<?php\n", 'runs/Shows/Actions.php'=>"<?php\n", 'runs/Shows/input/index.json'=>'{ "fieldSet": {} }',
	'www/index.php'=>"<?php\n", 'www/.htaccess'=>"RewriteEngine On\n", 'pages/index.html'=>'<p>home</p>',
	'conf/system.ini'=>buildSystemIni("CNF_CACH = FALSE\n"), 'conf/presets/defaults.rps'=>'{ "presetId": "defaults", "settings": { "fileType": "all" }, "security": { "required": false } }',
	'conf/access.json'=>'{ "secret": "real" }', 'conf/access.json.dist'=>'{ "secret": "env:NYU_JWT_SECRET" }', 'conf/database.json'=>'{ "pass": "real" }',
	'conf/mailer.json'=>'{ "password": "real" }', 'conf/users.lst'=>'{ "users": [] }', 'conf/.htpasswd'=>"anna:hash\n",
	'conf/keys/jwtToken.key'=>'PRIVATE', 'conf/keys/jwtToken.pub'=>'PUBLIC', 'conf/keys/jwtToken.old.pub'=>'FORMER',
	// The public key of a server issuing tokens, without its private key: a project checking them carries it
	'conf/keys/issuer.pub'=>'ISSUER',
	'vendor/autoload.php'=>"<?php\n", 'vendor/some/pkg/src/Lib.php'=>"<?php\n", 'vendor/some/pkg/.git/HEAD'=>'ref',
	'composer.json'=>'{ "require": {} }', 'composer.lock'=>'{}', 'nyuRun'=>"<?php\n", 'LICENSE'=>'MIT', 'NOTICE'=>'Libraries',
	'.env'=>"NYU_JWT_SECRET=real\n", 'test/unit/SomeTest.php'=>"<?php\n", 'README.md'=>'# doc', 'notes.md'=>'# notes',
	'temp/logs/runner.log'=>'log line', 'media/big.bin'=>'bytes',
	// A Composer of the project's own, which only tells how it was called: no bundle is ever given to a real one here
	'composer.phar'=>"<?php\nfile_put_contents(__DIR__.'/temp/cmpsr.log', json_encode(array_slice(\$argv, 1)).\"\\n\", FILE_APPEND);\n",
	'tools/failing.phar'=>"<?php\necho \"Could not scan the classes\\n\";\nexit(3);\n"
));
$cmpsLog = 'temp/cmpsr.log';
$keptLst = Array('LICENSE', 'NOTICE', 'composer.json', 'composer.lock', 'conf/access.json.dist', 'conf/presets/defaults.rps', 'conf/system.ini',
	'nyuRun', 'pages/index.html', 'runs/Shows/Actions.php', 'runs/Shows/input/index.json', 'src/Kernel.php', 'vendor/autoload.php', 'vendor/some/pkg/src/Lib.php', 'www/.htaccess', 'www/index.php');
$distDir = $projDir.DS.'dist';

// final
$runVals = callShellArray($projDir, Array('bundling', 'releases'));
$bndlIni = strval(fetchInProject($projDir, 'dist/releases/conf/system.ini'));
checkIdentical('a final bundle holds what KEEPLIST names, nothing else', Array($runVals['code'], filesOfDr($distDir.DS.'releases')), Array(0, $keptLst));
checkIdentical('its system.ini is set for production: errors hidden, errors, warnings and notices logged', Array(strpos($bndlIni, 'ENV_NAME = PROD')!==false, strpos($bndlIni, 'ERR_SHOW = FALSE')!==false, strpos($bndlIni, 'LOG_MODE = MINI')!==false), Array(true, true, true));
checkIdentical('its config is cached', strpos($bndlIni, 'CNF_CACH = TRUE')!==false, true);
checkIdentical('the project keeps its own settings', fetchInProject($projDir, 'conf/system.ini'), buildSystemIni("CNF_CACH = FALSE\n"));
checkIdentical('the folders the framework writes in are there, empty', Array(is_dir($distDir.DS.'releases'.DS.'media'), is_dir($distDir.DS.'releases'.DS.'temp'), filesOfDr($distDir.DS.'releases'.DS.'temp')), Array(true, true, Array()));
checkIdentical('the next steps are told', strpos($runVals['text'], 'On its server: php nyuRun installs allSteps, then php nyuRun installs checkAll.')!==false, true);
checkPregMatch('the bundle is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Bundle: releases, 16 files in .*dist.releases by user "cli:/');

// The class map of a PREV or PROD bundle, made by Composer in the copy
checkIdentical('Composer makes a complete class map in a final bundle', json_decode(strval(fetchInProject($projDir, $cmpsLog)), true),
	Array('dump-autoload', '--optimize', '--classmap-authoritative', '--no-dev', '--no-interaction', '--working-dir='.$distDir.DS.'releases'));
checkIdentical('it is told and traced', Array(strpos($runVals['text'], 'class map')!==false, strpos(strval(fetchInProject($projDir, 'temp/logs/shell.log')), 'Bundle: releases, class map made by Composer')!==false), Array(true, true));
writeInProject($projDir, $cmpsLog, '');
$runVals = callShellArray($projDir, Array('bundling', 'releases', '--yesToAll', '--composer='.$projDir.DS.'tools'.DS.'failing.phar'));
checkIdentical('Composer failing: the bundle is made, the autoloader of the project kept, and it is told', Array($runVals['code'], filesOfDr($distDir.DS.'releases'), strpos($runVals['errs'], 'Composer ended with 3: Could not scan the classes')!==false), Array(0, $keptLst, true));
$runVals = callShellArray($projDir, Array('bundling', 'releases', '--yesToAll', '--composer='.$projDir.DS.'nowhere.phar'));
checkIdentical('Composer not found: the bundle is made, and it is told', Array($runVals['code'], strpos($runVals['errs'], 'Composer was not found')!==false, fetchInProject($projDir, $cmpsLog)), Array(0, true, ''));

// A bundle that is there is only replaced when confirmed
writeInProject($projDir, 'dist/releases/stale.txt', 'old');
checkIdentical('replacing a bundle is confirmed', Array(callShellArray($projDir, Array('bundling', 'releases', '--noPrompt'))['code'], is_file($distDir.DS.'releases'.DS.'stale.txt')), Array(3, true));
checkIdentical('confirmed, the former bundle is replaced as a whole', Array(callShellArray($projDir, Array('bundling', 'releases', '--yesToAll'))['code'], filesOfDr($distDir.DS.'releases')), Array(0, $keptLst));

// local, stage
writeInProject($projDir, $cmpsLog, '');
callShellArray($projDir, Array('bundling', 'localdev'));
checkIdentical('a local bundle shows its errors and logs in full, and reads its config on each request', Array(strpos(strval(fetchInProject($projDir, 'dist/localdev/conf/system.ini')), 'ENV_NAME = DEVS')!==false, strpos(strval(fetchInProject($projDir, 'dist/localdev/conf/system.ini')), 'LOG_MODE = FULL')!==false, strpos(strval(fetchInProject($projDir, 'dist/localdev/conf/system.ini')), 'CNF_CACH = FALSE')!==false), Array(true, true, true));
// A system.ini made before the config cache: told, not refused
writeInProject($projDir, 'conf/system.ini', buildSystemIni());
$runVals = callShellArray($projDir, Array('bundling', 'releases', '--yesToAll'));
checkIdentical('a system.ini without CNF_CACH: the bundle is made, and it is told', Array($runVals['code'], strpos($runVals['errs'], 'the system.ini of the project has no CNF_CACH')!==false), Array(0, true));
writeInProject($projDir, 'conf/system.ini', buildSystemIni("CNF_CACH = FALSE\n"));
writeInProject($projDir, $cmpsLog, '');
$jsnVals = runShellAsJson($projDir, Array('bundling', 'preprods', '--planOnly'));
$plnRows = array_column($jsnVals['rows'] ?? Array(), 'state', 'path');
checkIdentical('--planOnly lists, and writes nothing', Array(is_dir($distDir.DS.'preprods'), $plnRows['conf/system.ini'] ?? null, $plnRows['conf/access.json'] ?? null, $plnRows['conf/keys/jwtToken.key'] ?? null, $plnRows['test/'] ?? null, $plnRows['.env'] ?? null, $plnRows['vendor/some/pkg/.git/HEAD'] ?? null),
	Array(false, 'copied', 'left out', 'left out', 'left out', 'left out', 'left out'));
checkIdentical('--planOnly: nothing of conf/keys travels, public keys included', Array($plnRows['conf/keys/jwtToken.pub'] ?? null, $plnRows['conf/keys/jwtToken.old.pub'] ?? null, $plnRows['conf/keys/issuer.pub'] ?? null),
	Array('left out', 'left out', 'left out'));
checkIdentical('--planOnly tells the class map, and runs no Composer', Array($jsnVals['class map'] ?? null, $plnRows['composer.phar'] ?? null, fetchInProject($projDir, $cmpsLog)), Array('complete, made by '.$projDir.DS.'composer.phar', 'left out', ''));
checkIdentical('a local bundle keeps the autoloader of the project', fetchInProject($projDir, $cmpsLog), '');

// --destPath
$plceDir = inTemporaryDir('bundleplace');
checkIdentical('--destPath: an empty folder elsewhere', Array(callShellArray($projDir, Array('bundling', 'preprods', '--destPath='.$plceDir))['code'], filesOfDr($plceDir)), Array(0, $keptLst));
checkIdentical('--destPath: a folder that is not empty is never replaced', Array(callShellArray($projDir, Array('bundling', 'preprods', '--destPath='.$plceDir, '--yesToAll'))['code'], is_file($plceDir.DS.'conf'.DS.'system.ini')), Array(3, true));
checkIdentical('--destPath: never in the project, nor in a folder it copies', Array(callShellArray($projDir, Array('bundling', 'preprods', '--destPath='.$projDir))['code'], callShellArray($projDir, Array('bundling', 'preprods', '--destPath='.$projDir.DS.'www'.DS.'out'))['code']), Array(2, 2));

// --archived
if( class_exists('PharData') ){
	$runVals = callShellArray($projDir, Array('bundling', 'localdev', '--yesToAll', '--archived'));
	$arcPath = $distDir.DS.'localdev.tar.gz';
	$arcText = is_file($arcPath) ? strval(file_get_contents('phar://'.$arcPath.'/conf/system.ini')) : '';
	checkIdentical('--archived makes the archive beside the folder, as the folder holds it', Array($runVals['code'], is_file($arcPath), is_file($distDir.DS.'localdev.tar'), strpos($arcText, 'ENV_NAME = DEVS')!==false, file_exists('phar://'.$arcPath.'/conf/access.json')), Array(0, true, false, true, false));
}

// Packages for development only cannot be left out : it is told
writeInProject($projDir, 'composer.json', '{ "require": {}, "require-dev": { "some/tool": "^1" } }');
checkIdentical('packages for development are told', strpos(callShellArray($projDir, Array('bundling', 'releases', '--yesToAll'))['errs'], 'composer.json has packages for development')!==false, true);
checkIdentical('an unknown mode', callShellArray($projDir, Array('bundling', 'other'))['code'], 2);
writeInProject($projDir, 'composer.json', '{ "require": {} }');

// The modes of the project : declared, listed, built, changed and removed
checkIdentical('packs lists the modes of the framework', array_column(runShellAsJson($projDir, Array('bundling', 'fullList'))['rows'] ?? Array(), 'kind', 'mode'), Array('localdev'=>'framework', 'preprods'=>'framework', 'releases'=>'framework'));
$jsnVals = runShellAsJson($projDir, Array('bundling', 'makeMode', 'qaserver', '--noPrompt'));
checkIdentical('mkpak declares a mode, TEST, errors shown and full logs unless told otherwise', Array($jsnVals['mode declared'] ?? null, json_decode(strval(fetchInProject($projDir, 'conf/bundle.lst')), true)), Array('qaserver', Array('qaserver'=>Array('system'=>Array('ENV_NAME'=>'TEST', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'TRUE', 'LOG_MODE'=>'FULL'), 'adding'=>Array()))));
$runVals = callShellArray($projDir, Array('bundling', 'makeMode', 'qaserver'), "\nE_ALL & ~E_DEPRECATED\nfalse\nbase\ntest\n");
checkIdentical('mkpak changes it, the present values proposed', Array($runVals['code'], $runVals['errs'], json_decode(strval(fetchInProject($projDir, 'conf/bundle.lst')), true)['qaserver'] ?? null), Array(0,
	'Environment (DEVS, TEST, PREV, PROD) [TEST]: Errors reported (E_* names joined by |, & and ~) [E_ALL]: Show errors (TRUE, FALSE) [TRUE]: Logging mode (FULL, BASE, MINI) [FULL]: Paths the bundle adds, separated by commas (none for none) [none]: ',
	Array('system'=>Array('ENV_NAME'=>'TEST', 'ERR_LEVL'=>'E_ALL & ~E_DEPRECATED', 'ERR_SHOW'=>'FALSE', 'LOG_MODE'=>'BASE'), 'adding'=>Array('test'))));
checkIdentical('packs lists it with the others', runShellAsJson($projDir, Array('bundling', 'fullList'))['rows'][3] ?? null, Array('mode'=>'qaserver', 'kind'=>'project', 'settings'=>'ENV_NAME=TEST, ERR_LEVL=E_ALL & ~E_DEPRECATED, ERR_SHOW=FALSE, LOG_MODE=BASE', 'adding'=>'test'));
$runVals = callShellArray($projDir, Array('bundling', 'qaserver'));
$qatList = array_merge($keptLst, Array('test/unit/SomeTest.php'));
sort($qatList);
checkIdentical('a mode of the project is built as the others, with the paths it adds, without the list of modes', Array($runVals['code'], filesOfDr($distDir.DS.'qaserver'), strpos(strval(fetchInProject($projDir, 'dist/qaserver/conf/system.ini')), 'ENV_NAME = TEST')!==false), Array(0, $qatList, true));
checkIdentical('a mode of the project is a sub-command of bundle', isset(array_column(runShellAsJson($projDir, Array('bundling', '--helpText'))['rows'] ?? Array(), 'does', 'sub-command')['qaserver']), true);

// What a mode cannot be
checkIdentical('a name that is not one, a mode of the framework, a sub-command', Array(callShellArray($projDir, Array('bundling', 'makeMode', 'Bad!x', '--noPrompt'))['code'], callShellArray($projDir, Array('bundling', 'makeMode', 'releases', '--noPrompt'))['code'], callShellArray($projDir, Array('bundling', 'makeMode', 'fulllist', '--noPrompt'))['code']), Array(2, 3, 3));
checkIdentical('an environment, a setting, a path, an error level that cannot be', Array(callShellArray($projDir, Array('bundling', 'makeMode', 'othermod'), "live\n\n\n\n\n")['code'], callShellArray($projDir, Array('bundling', 'makeMode', 'othermod'), "\n\nmaybe\n\n\n")['code'],
	callShellArray($projDir, Array('bundling', 'makeMode', 'othermod'), "\n\n\n\n../outside\n")['code'], callShellArray($projDir, Array('bundling', 'makeMode', 'othermod'), "\n\n\n\nsrc\n")['code'], callShellArray($projDir, Array('bundling', 'makeMode', 'othermod'), "\n\n\n\n.git\n")['code'], callShellArray($projDir, Array('bundling', 'makeMode', 'othermod'), "\n\n\n\nnowhere\n")['code'], callShellArray($projDir, Array('bundling', 'makeMode', 'othermod'), "\nE_ALL or E_ERROR\n\n\n\n")['code']), Array(3, 3, 3, 3, 3, 3, 3));
checkIdentical('nothing refused was written', array_keys(json_decode(strval(fetchInProject($projDir, 'conf/bundle.lst')), true)), Array('qaserver'));
$lstText = strval(fetchInProject($projDir, 'conf/bundle.lst'));
writeInProject($projDir, 'conf/bundle.lst', '{ "badmodes": { "system": { "ENV_NAME": "LIVE" }, "adding": [] } }');
checkIdentical('a list of modes written by hand that holds what is not one', Array(callShellArray($projDir, Array('bundling', 'fullList'))['code'], strpos(callShellArray($projDir, Array('bundling', 'fullList'))['errs'], 'conf/bundle.lst: the mode badmodes cannot be used')!==false), Array(3, true));
writeInProject($projDir, 'conf/bundle.lst', $lstText);

// A key comes along only when a mode names that very file
writeInProject($projDir, 'conf/bundle.lst', '{ "checkkey": { "system": { "ENV_NAME": "PROD" }, "adding": [ "conf/keys/issuer.pub" ] } }');
$runVals = callShellArray($projDir, Array('bundling', 'checkkey'));
checkIdentical('a mode naming the public key of the server issuing the tokens gets it, and only it', Array($runVals['code'], array_values(array_filter(filesOfDr($distDir.DS.'checkkey'), function( String $filePathNm ): Bool { return strpos($filePathNm, 'conf/keys/')===0; }))), Array(0, Array('conf/keys/issuer.pub')));
writeInProject($projDir, 'conf/bundle.lst', '{ "checkkey": { "system": { "ENV_NAME": "PROD" }, "adding": [ "conf/keys" ] } }');
checkIdentical('a folder of keys named by a mode brings back nothing', callShellArray($projDir, Array('bundling', 'checkkey'))['code'], 3);
writeInProject($projDir, 'conf/bundle.lst', $lstText);
// The secrets conf/access.json names, wherever they lie
$axsText = strval(fetchInProject($projDir, 'conf/access.json'));
writeInProject($projDir, 'conf/access.json', '{ "jwtToken": { "signAlgo": "RS256", "privFile": "conf/secret/jwt.pem", "publFile": "/conf/secret/jwt.pub" }, "appsKeys": { "appsList": { "voice": { "publFile": "pages/other.pub" } } }, "userFile": { "listFile": "conf/people.lst" }, "htpasswd": { "passFile": "conf/web.pass" } }');
foreach( Array('conf/secret/jwt.pem', 'conf/secret/jwt.pub', 'pages/other.pub', 'conf/people.lst', 'conf/web.pass') as $scrtPth ){
	writeInProject($projDir, $scrtPth, 'SECRET');
}
$plnRows = array_column(runShellAsJson($projDir, Array('bundling', 'releases', '--planOnly'))['rows'] ?? Array(), 'state', 'path');
checkIdentical('the keys, users and password files access.json names are left out, wherever they lie', Array($plnRows['conf/secret/jwt.pem'] ?? null, $plnRows['conf/secret/jwt.pub'] ?? null, $plnRows['pages/other.pub'] ?? null, $plnRows['conf/people.lst'] ?? null, $plnRows['conf/web.pass'] ?? null, $plnRows['pages/index.html'] ?? null),
	Array('left out', 'left out', 'left out', 'left out', 'left out', 'copied'));
writeInProject($projDir, 'conf/access.json', $axsText);

// rmpak
checkIdentical('rmpak is confirmed, and leaves the modes of the framework', Array(callShellArray($projDir, Array('bundling', 'dropMode', 'qaserver', '--noPrompt'))['code'], callShellArray($projDir, Array('bundling', 'dropMode', 'releases', '--yesToAll'))['code'], callShellArray($projDir, Array('bundling', 'dropMode', 'nomod', '--yesToAll'))['code']), Array(3, 3, 3));
checkIdentical('confirmed, the mode is removed', Array(callShellArray($projDir, Array('bundling', 'dropMode', 'qaserver', '--yesToAll'))['code'], json_decode(strval(fetchInProject($projDir, 'conf/bundle.lst')), true), callShellArray($projDir, Array('bundling', 'qaserver'))['code']), Array(0, Array(), 2));
checkPregMatch('the modes are traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/Bundle: mode qaserver declared \(TEST\).*Bundle: mode qaserver changed \(TEST\).*Bundle: mode qaserver removed/s');

finishChecking();
