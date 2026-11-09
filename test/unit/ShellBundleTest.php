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

$projDir = makeProjt('bundle', Array(
	'src/Kernel.php'=>"<?php\n", 'runs/Shows/Actions.php'=>"<?php\n", 'runs/Shows/input/index.json'=>'{ "fields": {} }',
	'www/index.php'=>"<?php\n", 'www/.htaccess'=>"RewriteEngine On\n", 'pages/index.html'=>'<p>home</p>',
	'conf/system.ini'=>sampleIni("CNF_CACH = FALSE\n"), 'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false } }',
	'conf/access.json'=>'{ "secret": "real" }', 'conf/access.json.dist'=>'{ "secret": "env:NYU_JWT_SECRET" }', 'conf/database.json'=>'{ "pass": "real" }',
	'conf/mailer.json'=>'{ "pass": "real" }', 'conf/users.lst'=>'{ "users": [] }', 'conf/.htpasswd'=>"anna:hash\n",
	'conf/keys/dftokn.key'=>'PRIVATE', 'conf/keys/dftokn.pub'=>'PUBLIC', 'conf/keys/dftokn.old.pub'=>'FORMER',
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
$keptLst = Array('LICENSE', 'NOTICE', 'composer.json', 'composer.lock', 'conf/access.json.dist', 'conf/presets/dfault.rps', 'conf/system.ini',
	'nyuRun', 'pages/index.html', 'runs/Shows/Actions.php', 'runs/Shows/input/index.json', 'src/Kernel.php', 'vendor/autoload.php', 'vendor/some/pkg/src/Lib.php', 'www/.htaccess', 'www/index.php');
$distDir = $projDir.DS.'dist';

// final
$runVals = runShell($projDir, Array('bundle', 'final'));
$bndlIni = strval(readInPrj($projDir, 'dist/final/conf/system.ini'));
checkSame('a final bundle holds what KEEPLIST names, nothing else', Array($runVals['code'], filesOfDr($distDir.DS.'final')), Array(0, $keptLst));
checkSame('its system.ini is set for production: errors hidden, errors, warnings and notices logged', Array(strpos($bndlIni, 'ENV_NAME = PROD')!==false, strpos($bndlIni, 'ERR_SHOW = FALSE')!==false, strpos($bndlIni, 'LOG_MODE = MINI')!==false), Array(true, true, true));
checkSame('its config is cached', strpos($bndlIni, 'CNF_CACH = TRUE')!==false, true);
checkSame('the project keeps its own settings', readInPrj($projDir, 'conf/system.ini'), sampleIni("CNF_CACH = FALSE\n"));
checkSame('the folders the framework writes in are there, empty', Array(is_dir($distDir.DS.'final'.DS.'media'), is_dir($distDir.DS.'final'.DS.'temp'), filesOfDr($distDir.DS.'final'.DS.'temp')), Array(true, true, Array()));
checkSame('the next steps are told', strpos($runVals['text'], 'On its server: php nyuRun deploy start, then php nyuRun deploy check.')!==false, true);
checkLike('the bundle is traced', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Bundle: final, 16 files in .*dist.final by user "cli:/');

// The class map of a PREV or PROD bundle, made by Composer in the copy
checkSame('Composer makes a complete class map in a final bundle', json_decode(strval(readInPrj($projDir, $cmpsLog)), true),
	Array('dump-autoload', '--optimize', '--classmap-authoritative', '--no-dev', '--no-interaction', '--working-dir='.$distDir.DS.'final'));
checkSame('it is told and traced', Array(strpos($runVals['text'], 'class map')!==false, strpos(strval(readInPrj($projDir, 'temp/logs/shell.log')), 'Bundle: final, class map made by Composer')!==false), Array(true, true));
putInProj($projDir, $cmpsLog, '');
$runVals = runShell($projDir, Array('bundle', 'final', '--agree', '--cmpsr='.$projDir.DS.'tools'.DS.'failing.phar'));
checkSame('Composer failing: the bundle is made, the autoloader of the project kept, and it is told', Array($runVals['code'], filesOfDr($distDir.DS.'final'), strpos($runVals['errs'], 'Composer ended with 3: Could not scan the classes')!==false), Array(0, $keptLst, true));
$runVals = runShell($projDir, Array('bundle', 'final', '--agree', '--cmpsr='.$projDir.DS.'nowhere.phar'));
checkSame('Composer not found: the bundle is made, and it is told', Array($runVals['code'], strpos($runVals['errs'], 'Composer was not found')!==false, readInPrj($projDir, $cmpsLog)), Array(0, true, ''));

// A bundle that is there is only replaced when confirmed
putInProj($projDir, 'dist/final/stale.txt', 'old');
checkSame('replacing a bundle is confirmed', Array(runShell($projDir, Array('bundle', 'final', '--noask'))['code'], is_file($distDir.DS.'final'.DS.'stale.txt')), Array(3, true));
checkSame('confirmed, the former bundle is replaced as a whole', Array(runShell($projDir, Array('bundle', 'final', '--agree'))['code'], filesOfDr($distDir.DS.'final')), Array(0, $keptLst));

// local, stage
putInProj($projDir, $cmpsLog, '');
runShell($projDir, Array('bundle', 'local'));
checkSame('a local bundle shows its errors and logs in full, and reads its config on each request', Array(strpos(strval(readInPrj($projDir, 'dist/local/conf/system.ini')), 'ENV_NAME = DEV')!==false, strpos(strval(readInPrj($projDir, 'dist/local/conf/system.ini')), 'LOG_MODE = FULL')!==false, strpos(strval(readInPrj($projDir, 'dist/local/conf/system.ini')), 'CNF_CACH = FALSE')!==false), Array(true, true, true));
// A system.ini made before the config cache: told, not refused
putInProj($projDir, 'conf/system.ini', sampleIni());
$runVals = runShell($projDir, Array('bundle', 'final', '--agree'));
checkSame('a system.ini without CNF_CACH: the bundle is made, and it is told', Array($runVals['code'], strpos($runVals['errs'], 'the system.ini of the project has no CNF_CACH')!==false), Array(0, true));
putInProj($projDir, 'conf/system.ini', sampleIni("CNF_CACH = FALSE\n"));
putInProj($projDir, $cmpsLog, '');
$jsnVals = jsonShell($projDir, Array('bundle', 'stage', '--plans'));
$plnRows = array_column($jsnVals['rows'] ?? Array(), 'state', 'path');
checkSame('--plans lists, and writes nothing', Array(is_dir($distDir.DS.'stage'), $plnRows['conf/system.ini'] ?? null, $plnRows['conf/access.json'] ?? null, $plnRows['conf/keys/dftokn.key'] ?? null, $plnRows['test/'] ?? null, $plnRows['.env'] ?? null, $plnRows['vendor/some/pkg/.git/HEAD'] ?? null),
	Array(false, 'copied', 'left out', 'left out', 'left out', 'left out', 'left out'));
checkSame('--plans: nothing of conf/keys travels, public keys included', Array($plnRows['conf/keys/dftokn.pub'] ?? null, $plnRows['conf/keys/dftokn.old.pub'] ?? null, $plnRows['conf/keys/issuer.pub'] ?? null),
	Array('left out', 'left out', 'left out'));
checkSame('--plans tells the class map, and runs no Composer', Array($jsnVals['class map'] ?? null, $plnRows['composer.phar'] ?? null, readInPrj($projDir, $cmpsLog)), Array('complete, made by '.$projDir.DS.'composer.phar', 'left out', ''));
checkSame('a local bundle keeps the autoloader of the project', readInPrj($projDir, $cmpsLog), '');

// --place
$plceDir = inTempDir('bundleplace');
checkSame('--place: an empty folder elsewhere', Array(runShell($projDir, Array('bundle', 'stage', '--place='.$plceDir))['code'], filesOfDr($plceDir)), Array(0, $keptLst));
checkSame('--place: a folder that is not empty is never replaced', Array(runShell($projDir, Array('bundle', 'stage', '--place='.$plceDir, '--agree'))['code'], is_file($plceDir.DS.'conf'.DS.'system.ini')), Array(3, true));
checkSame('--place: never in the project, nor in a folder it copies', Array(runShell($projDir, Array('bundle', 'stage', '--place='.$projDir))['code'], runShell($projDir, Array('bundle', 'stage', '--place='.$projDir.DS.'www'.DS.'out'))['code']), Array(2, 2));

// --archv
if( class_exists('PharData') ){
	$runVals = runShell($projDir, Array('bundle', 'local', '--agree', '--archv'));
	$arcPath = $distDir.DS.'local.tar.gz';
	$arcText = is_file($arcPath) ? strval(file_get_contents('phar://'.$arcPath.'/conf/system.ini')) : '';
	checkSame('--archv makes the archive beside the folder, as the folder holds it', Array($runVals['code'], is_file($arcPath), is_file($distDir.DS.'local.tar'), strpos($arcText, 'ENV_NAME = DEV')!==false, file_exists('phar://'.$arcPath.'/conf/access.json')), Array(0, true, false, true, false));
}

// Packages for development only cannot be left out : it is told
putInProj($projDir, 'composer.json', '{ "require": {}, "require-dev": { "some/tool": "^1" } }');
checkSame('packages for development are told', strpos(runShell($projDir, Array('bundle', 'final', '--agree'))['errs'], 'composer.json has packages for development')!==false, true);
checkSame('an unknown mode', runShell($projDir, Array('bundle', 'other'))['code'], 2);
putInProj($projDir, 'composer.json', '{ "require": {} }');

// The modes of the project : declared, listed, built, changed and removed
checkSame('packs lists the modes of the framework', array_column(jsonShell($projDir, Array('bundle', 'packs'))['rows'] ?? Array(), 'kind', 'mode'), Array('local'=>'framework', 'stage'=>'framework', 'final'=>'framework'));
$jsnVals = jsonShell($projDir, Array('bundle', 'mkpak', 'qatst', '--noask'));
checkSame('mkpak declares a mode, TEST, errors shown and full logs unless told otherwise', Array($jsnVals['mode declared'] ?? null, json_decode(strval(readInPrj($projDir, 'conf/bundle.lst')), true)), Array('qatst', Array('qatst'=>Array('system'=>Array('ENV_NAME'=>'TEST', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'TRUE', 'LOG_MODE'=>'FULL'), 'adding'=>Array()))));
$runVals = runShell($projDir, Array('bundle', 'mkpak', 'qatst'), "\nE_ALL & ~E_DEPRECATED\nfalse\nbase\ntest\n");
checkSame('mkpak changes it, the present values proposed', Array($runVals['code'], $runVals['errs'], json_decode(strval(readInPrj($projDir, 'conf/bundle.lst')), true)['qatst'] ?? null), Array(0,
	'Environment (DEV, TEST, PREV, PROD) [TEST]: Errors reported (E_* names joined by |, & and ~) [E_ALL]: Show errors (TRUE, FALSE) [TRUE]: Logging mode (FULL, BASE, MINI) [FULL]: Paths the bundle adds, separated by commas (none for none) [none]: ',
	Array('system'=>Array('ENV_NAME'=>'TEST', 'ERR_LEVL'=>'E_ALL & ~E_DEPRECATED', 'ERR_SHOW'=>'FALSE', 'LOG_MODE'=>'BASE'), 'adding'=>Array('test'))));
checkSame('packs lists it with the others', jsonShell($projDir, Array('bundle', 'packs'))['rows'][3] ?? null, Array('mode'=>'qatst', 'kind'=>'project', 'settings'=>'ENV_NAME=TEST, ERR_LEVL=E_ALL & ~E_DEPRECATED, ERR_SHOW=FALSE, LOG_MODE=BASE', 'adding'=>'test'));
$runVals = runShell($projDir, Array('bundle', 'qatst'));
$qatList = array_merge($keptLst, Array('test/unit/SomeTest.php'));
sort($qatList);
checkSame('a mode of the project is built as the others, with the paths it adds, without the list of modes', Array($runVals['code'], filesOfDr($distDir.DS.'qatst'), strpos(strval(readInPrj($projDir, 'dist/qatst/conf/system.ini')), 'ENV_NAME = TEST')!==false), Array(0, $qatList, true));
checkSame('a mode of the project is a sub-command of bundle', isset(array_column(jsonShell($projDir, Array('bundle', '--usage'))['rows'] ?? Array(), 'does', 'sub-command')['qatst']), true);

// What a mode cannot be
checkSame('a name that is not one, a mode of the framework, a sub-command', Array(runShell($projDir, Array('bundle', 'mkpak', 'Bad!x', '--noask'))['code'], runShell($projDir, Array('bundle', 'mkpak', 'final', '--noask'))['code'], runShell($projDir, Array('bundle', 'mkpak', 'packs', '--noask'))['code']), Array(2, 3, 3));
checkSame('an environment, a setting, a path, an error level that cannot be', Array(runShell($projDir, Array('bundle', 'mkpak', 'other'), "live\n\n\n\n\n")['code'], runShell($projDir, Array('bundle', 'mkpak', 'other'), "\n\nmaybe\n\n\n")['code'],
	runShell($projDir, Array('bundle', 'mkpak', 'other'), "\n\n\n\n../outside\n")['code'], runShell($projDir, Array('bundle', 'mkpak', 'other'), "\n\n\n\nsrc\n")['code'], runShell($projDir, Array('bundle', 'mkpak', 'other'), "\n\n\n\n.git\n")['code'], runShell($projDir, Array('bundle', 'mkpak', 'other'), "\n\n\n\nnowhere\n")['code'], runShell($projDir, Array('bundle', 'mkpak', 'other'), "\nE_ALL or E_ERROR\n\n\n\n")['code']), Array(3, 3, 3, 3, 3, 3, 3));
checkSame('nothing refused was written', array_keys(json_decode(strval(readInPrj($projDir, 'conf/bundle.lst')), true)), Array('qatst'));
$lstText = strval(readInPrj($projDir, 'conf/bundle.lst'));
putInProj($projDir, 'conf/bundle.lst', '{ "badmd": { "system": { "ENV_NAME": "LIVE" }, "adding": [] } }');
checkSame('a list of modes written by hand that holds what is not one', Array(runShell($projDir, Array('bundle', 'packs'))['code'], strpos(runShell($projDir, Array('bundle', 'packs'))['errs'], 'conf/bundle.lst: the mode badmd cannot be used')!==false), Array(3, true));
putInProj($projDir, 'conf/bundle.lst', $lstText);

// A key comes along only when a mode names that very file
putInProj($projDir, 'conf/bundle.lst', '{ "check": { "system": { "ENV_NAME": "PROD" }, "adding": [ "conf/keys/issuer.pub" ] } }');
$runVals = runShell($projDir, Array('bundle', 'check'));
checkSame('a mode naming the public key of the server issuing the tokens gets it, and only it', Array($runVals['code'], array_values(array_filter(filesOfDr($distDir.DS.'check'), function( String $filePathNm ): Bool { return strpos($filePathNm, 'conf/keys/')===0; }))), Array(0, Array('conf/keys/issuer.pub')));
putInProj($projDir, 'conf/bundle.lst', '{ "check": { "system": { "ENV_NAME": "PROD" }, "adding": [ "conf/keys" ] } }');
checkSame('a folder of keys named by a mode brings back nothing', runShell($projDir, Array('bundle', 'check'))['code'], 3);
putInProj($projDir, 'conf/bundle.lst', $lstText);
// The secrets conf/access.json names, wherever they lie
$axsText = strval(readInPrj($projDir, 'conf/access.json'));
putInProj($projDir, 'conf/access.json', '{ "dftokn": { "algrtm": "RS256", "prvkey": "conf/secret/jwt.pem", "pubkey": "/conf/secret/jwt.pub" }, "apkeys": { "applst": { "voice": { "pubkey": "pages/other.pub" } } }, "usrlst": { "usrfle": "conf/people.lst" }, "dfpass": { "htpasswd": "conf/web.pass" } }');
foreach( Array('conf/secret/jwt.pem', 'conf/secret/jwt.pub', 'pages/other.pub', 'conf/people.lst', 'conf/web.pass') as $scrtPth ){
	putInProj($projDir, $scrtPth, 'SECRET');
}
$plnRows = array_column(jsonShell($projDir, Array('bundle', 'final', '--plans'))['rows'] ?? Array(), 'state', 'path');
checkSame('the keys, users and password files access.json names are left out, wherever they lie', Array($plnRows['conf/secret/jwt.pem'] ?? null, $plnRows['conf/secret/jwt.pub'] ?? null, $plnRows['pages/other.pub'] ?? null, $plnRows['conf/people.lst'] ?? null, $plnRows['conf/web.pass'] ?? null, $plnRows['pages/index.html'] ?? null),
	Array('left out', 'left out', 'left out', 'left out', 'left out', 'copied'));
putInProj($projDir, 'conf/access.json', $axsText);

// rmpak
checkSame('rmpak is confirmed, and leaves the modes of the framework', Array(runShell($projDir, Array('bundle', 'rmpak', 'qatst', '--noask'))['code'], runShell($projDir, Array('bundle', 'rmpak', 'final', '--agree'))['code'], runShell($projDir, Array('bundle', 'rmpak', 'nomod', '--agree'))['code']), Array(3, 3, 3));
checkSame('confirmed, the mode is removed', Array(runShell($projDir, Array('bundle', 'rmpak', 'qatst', '--agree'))['code'], json_decode(strval(readInPrj($projDir, 'conf/bundle.lst')), true), runShell($projDir, Array('bundle', 'qatst'))['code']), Array(0, Array(), 2));
checkLike('the modes are traced', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/Bundle: mode qatst declared \(TEST\).*Bundle: mode qatst changed \(TEST\).*Bundle: mode qatst removed/s');

endChecks();
