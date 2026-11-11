<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Shell\ConfFile;

/**
 * Builds a copy of the project for one environment, to be carried to its
 * server, where "php nyuRun install complete" installs it:
 *
 *   php nyuRun bundles local                       for a developer's machine
 *   php nyuRun bundles stage                       for a server before production
 *   php nyuRun bundles final                       for production
 *   php nyuRun bundles final --archived            the same, and its .tar.gz beside it
 *   php nyuRun bundles final --planOnly            lists what would be copied, and writes nothing
 *   php nyuRun bundles final --destPath=<path>     in another folder than dist/<mode>, which must be empty
 *   php nyuRun bundles final --composer=<path>     with the Composer that makes its class map
 *   php nyuRun bundles fullList                    lists the modes, those of the framework and those of the project
 *   php nyuRun bundles makeMode qaserver           declares a mode of the project (an integration server), or changes it
 *   php nyuRun bundles dropMode qaserver           removes a mode of the project
 *
 * A mode the project declares, in conf/bundle.lst, is built as the others
 * are: php nyuRun bundles qaserver. Only what KEEPLIST names is copied, with
 * the paths the mode adds, and nothing DROPLIST names within them: no
 * secret, no local setting and no private key reach a bundle. The
 * settings of the mode are written in the system.ini of the copy, never
 * in the project's own. The copy of an environment of MAPSENVS finds its
 * classes from a complete class map, which Composer makes in it.
 */
class Bundle extends Command{

	// The modes of the framework, with the settings written in the system.ini of their bundle
	const MODE_LIST = Array(
		'local'=>Array('ENV_NAME'=>'DEVS', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'TRUE', 'LOG_MODE'=>'FULL'),
		'stage'=>Array('ENV_NAME'=>'PREV', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'FALSE', 'LOG_MODE'=>'BASE'),
		// Production writes errors, warnings and notices: no line for each access of a standard user
		'final'=>Array('ENV_NAME'=>'PROD', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'FALSE', 'LOG_MODE'=>'MINI')
	);
	// The environments whose bundle finds every class from a complete class map, without looking for files, and caches its config (CNF_CACH)
	const MAPS_ENVS = Array('PREV', 'PROD');
	// What a bundle holds, from the project root : nothing else is copied, but what a mode adds
	const KEEP_LIST = Array('src', 'runs', 'www', 'pages', 'conf', 'vendor', 'composer.json', 'composer.lock', 'nyuRun', 'LICENSE', 'NOTICE', '.htaccess');
	// What is left out of those, from the project root : the local settings, their secrets, the private keys, the modes of the project
	const DROP_LIST = Array('conf/access.json', 'conf/database.json', 'conf/mailer.json', 'conf/users.lst', 'conf/.htpasswd', 'conf/keys/*', 'conf/bundle.lst', 'runs/*/runspec.json');
	// The modes the project declares: { "<mode>": { "settings": { "ENV_NAME": ..., ... }, "addPaths": [ <paths> ] } }
	const BNDL_FILE = 'conf/bundle.lst';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Builds a copy of the project for an environment, without secrets, tests or documents';
	}

	/**
	 * Lists the sub-commands of the command: the modes of the framework,
	 * those the project declares, and the management of the latter.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
		$optnTxt = '[--destPath=<path>] [--archived] [--planOnly] [--yesToAll] [--composer=<path>]';
		$subList = Array(
			'local'=>Array('call'=>'generateBundle', 'args'=>$optnTxt, 'about'=>'a bundle for a developer\'s machine: DEVS, errors shown, full logs'),
			'stage'=>Array('call'=>'generateBundle', 'args'=>$optnTxt, 'about'=>'a bundle for a server before production: PREV, errors hidden, basic logs'),
			'final'=>Array('call'=>'generateBundle', 'args'=>$optnTxt, 'about'=>'a bundle for production: PROD, errors hidden, basic logs')
		);
		foreach( $this->readModesArray() as $modNme => $mdlVal ){
			if( !isset(self::MODE_LIST[$modNme]) ){
				$subList[$modNme] = Array('call'=>'generateBundle', 'args'=>$optnTxt, 'about'=>'a bundle of a mode of the project: '.strval($mdlVal['settings']['ENV_NAME'] ?? ''));
			}
		}
		return $subList + Array(
			'fullList'=>Array('args'=>'', 'about'=>'lists the modes: those of the framework and those of the project'),
			'makeMode'=>Array('args'=>'<mode> [--noPrompt]', 'about'=>'declares a mode of the project in '.self::BNDL_FILE.', or changes it'),
			'dropMode'=>Array('args'=>'<mode> [--yesToAll]', 'about'=>'removes a mode of the project')
		);
	}

	/**
	 * Lists the modes: those of the framework and those the project
	 * declares, with their settings and the paths they add.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$rowList = Array();
		foreach( $this->readModesArray() as $modNme => $mdlVal ){
			$setText = implode(', ', array_map(function( String $iniEntryName, String $iniKeyValTxt ): String { return $iniEntryName.'='.$iniKeyValTxt; }, array_keys($mdlVal['settings']), array_values($mdlVal['settings'])));
			$rowList[] = Array($modNme, isset(self::MODE_LIST[$modNme]) ? 'framework' : 'project', $setText, implode(', ', $mdlVal['addPaths']));
		}
		$this->printerObj->printShellRows( Array('mode', 'kind', 'settings', 'addPaths'), $rowList );
		return self::EXIT_DONE;
	}

	/**
	 * Declares a mode of the project, or changes one: its environment,
	 * the errors reported, whether they are shown, its logging mode, and the paths its
	 * bundle adds to KEEPLIST (a folder of tests for an integration
	 * server, for one). Each is asked, the present value proposed, TEST,
	 * E_ALL, TRUE, FULL and none for a new mode; --noPrompt takes them. Other keys
	 * of system.ini written by hand in the mode are kept.
	 *
	 * @return Int The exit code.
	 */
	public function invokeMakeMode(): Int {
		$modeNme = $this->needsShellWord('the mode: lower-case letters or digits, of any length');
		if( !preg_match('/^[a-z0-9]+$/D', $modeNme) ){
			$this->terminateShell('A mode is named with lower-case letters or digits', self::EXIT_HELP);
		}
		if( method_exists($this, 'invoke'.ucfirst($modeNme)) ){
			$this->terminateShell('Nothing was declared: '.$modeNme.' is a mode of the framework or a sub-command', self::EXIT_DENY);
		}
		$modeLst = $this->readModesArray();
		$isKnown = isset($modeLst[$modeNme]);
		$nowMode = $modeLst[$modeNme] ?? Array('settings'=>Array('ENV_NAME'=>'TEST', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'TRUE', 'LOG_MODE'=>'FULL'), 'addPaths'=>Array());
		$iniVals = $nowMode['settings'];
		$iniVals['ENV_NAME'] = strtoupper( $this->queryAnswerTxt('Environment (DEVS, TEST, PREV, PROD)', strval($iniVals['ENV_NAME'] ?? 'TEST')) );
		$iniVals['ERR_LEVL'] = trim( $this->queryAnswerTxt('Errors reported (E_* names joined by |, & and ~)', strval($iniVals['ERR_LEVL'] ?? 'E_ALL')) );
		$iniVals['ERR_SHOW'] = strtoupper( $this->queryAnswerTxt('Show errors (TRUE, FALSE)', strval($iniVals['ERR_SHOW'] ?? 'TRUE')) );
		$iniVals['LOG_MODE'] = strtoupper( $this->queryAnswerTxt('Logging mode (FULL, BASE, MINI)', strval($iniVals['LOG_MODE'] ?? 'FULL')) );
		if( !preg_match(Config::RULE_LIST['ERR_LEVL'], $iniVals['ERR_LEVL']) ){
			$this->terminateShell('Nothing was declared: ERR_LEVL is E_* names joined by |, & and ~', self::EXIT_DENY);
		}
		foreach( Array('ERR_SHOW'=>Array('TRUE', 'FALSE'), 'LOG_MODE'=>Array('FULL', 'BASE', 'MINI')) as $iniKey => $allows ){
			if( !in_array($iniVals[$iniKey], $allows, true) ){
				$this->terminateShell('Nothing was declared: '.$iniKey.' is one of '.implode(', ', $allows), self::EXIT_DENY);
			}
		}
		$addText = $this->queryAnswerTxt('Paths the bundle adds, separated by commas (none for none)', empty($nowMode['addPaths']) ? 'none' : implode(', ', $nowMode['addPaths']));
		$addList = (strtolower(trim($addText))=='none') ? Array() : array_values(array_filter(array_map(function( String $givenItemTxt ): String { return trim(str_replace('\\', '/', $givenItemTxt), " /"); }, explode(',', $addText)), 'strlen'));
		$ownList = Array();
		foreach( $modeLst as $modNme => $mdlVal ){
			if( !isset(self::MODE_LIST[$modNme]) ){
				$ownList[$modNme] = $mdlVal;
			}
		}
		$ownList[$modeNme] = Array('settings'=>$iniVals, 'addPaths'=>$addList);
		// Checked as the file will be read, before it is written
		$this->readModesArray($ownList);
		$this->saveBundleList($ownList);
		$this->traceShellDone( 'Bundle: mode '.$modeNme.( $isKnown ? ' changed' : ' declared' ).' ('.$iniVals['ENV_NAME'].')' );
		$this->printerObj->printShellPair( $isKnown ? 'mode changed' : 'mode declared', $modeNme );
		return self::EXIT_DONE;
	}

	/**
	 * Removes a mode the project declared. Those of the framework stay.
	 *
	 * @return Int The exit code.
	 */
	public function invokeDropMode(): Int {
		$modeNme = $this->needsShellWord('the mode');
		if( isset(self::MODE_LIST[$modeNme]) ){
			$this->terminateShell('Nothing was removed: '.$modeNme.' is a mode of the framework', self::EXIT_DENY);
		}
		$ownList = Array();
		foreach( $this->readModesArray() as $modNme => $mdlVal ){
			if( !isset(self::MODE_LIST[$modNme]) ){
				$ownList[$modNme] = $mdlVal;
			}
		}
		if( !isset($ownList[$modeNme]) ){
			$this->terminateShell('Nothing was removed: the project declares no mode '.$modeNme, self::EXIT_DENY);
		}
		if( !$this->queryAgreedFlg('Remove the mode '.$modeNme) ){
			$this->terminateShell('Nothing was removed: not confirmed', self::EXIT_DENY);
		}
		unset($ownList[$modeNme]);
		$this->saveBundleList($ownList);
		$this->traceShellDone( 'Bundle: mode '.$modeNme.' removed' );
		$this->printerObj->printShellPair('mode removed', $modeNme);
		return self::EXIT_DONE;
	}

	/**
	 * Builds the bundle of a mode: in dist/<mode>, replaced when it is
	 * there (asked to be confirmed), or in the empty folder --destPath names.
	 * The settings of the mode are written in its system.ini; --archived
	 * makes its .tar.gz beside it, --planOnly only lists what would be copied.
	 *
	 * @param String $packModeName A mode of the framework (MODELIST) or of the project.
	 * @return Int The exit code.
	 *
	 * @throws \nyu\Shell\ShellStop When the mode is unknown, the folder cannot be used, replacing it is not confirmed, or a file cannot be copied.
	 */
	protected function generateBundle( String $packModeName ): Int {
		$modeCfg = $this->readModesArray()[$packModeName] ?? null;
		if( $modeCfg===null ){
			$this->terminateShell('No bundle made: there is no mode '.$packModeName, self::EXIT_HELP);
		}
		$outFldr = strval($this->optionsObj->retrieveOption('destPath', ''));
		$distDir = TOP_DIR.DS.'dist'.DS.$packModeName;
		$trgtDir = ($outFldr=='') ? $distDir : rtrim($outFldr, '/\\');
		if( !preg_match('#^([A-Za-z]:|[/\\\\])#', $trgtDir) ){
			$trgtDir = getcwd().DS.$trgtDir;
		}
		$trgtDir = str_replace(Array('/', '\\'), DS, $trgtDir);
		// A bundle never lands in what it copies : it would copy itself
		$fromTop = (strpos($trgtDir.DS, TOP_DIR.DS)===0) ? str_replace(DS, '/', substr($trgtDir, strlen(TOP_DIR)+1)) : '';
		$topList = array_merge( self::KEEP_LIST, array_map(function( String $addedItemTxt ): String { return explode('/', $addedItemTxt)[0]; }, $modeCfg['addPaths']) );
		if( ($fromTop==='' && strpos($trgtDir.DS, TOP_DIR.DS)===0) || in_array(explode('/', $fromTop)[0], $topList, true) ){
			$this->terminateShell('No bundle made: its folder cannot be the project, nor a folder the bundle copies', self::EXIT_HELP);
		}
		if( $this->optionsObj->validateOption('planOnly') ){
			$this->buildClassMaps($trgtDir, $packModeName, false);
			return $this->listPlansCount($trgtDir, $modeCfg['addPaths']);
		}
		if( is_dir($trgtDir) && count((array)scandir($trgtDir))>2 ){
			if( $trgtDir!==$distDir ){
				$this->terminateShell('No bundle made: '.$trgtDir.' is not empty; a folder given with --destPath must be', self::EXIT_DENY);
			}
			if( !$this->queryAgreedFlg('Replace the bundle in dist/'.$packModeName) ){
				$this->terminateShell('No bundle made: not confirmed', self::EXIT_DENY);
			}
			$dirWalk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator($trgtDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach( $dirWalk as $oldItm ){
				$oldItm->isDir() ? @rmdir($oldItm->getPathname()) : @unlink($oldItm->getPathname());
			}
		}
		if( !is_dir($trgtDir) && !@mkdir($trgtDir, 0775, true) ){
			$this->terminateShell('No bundle made: '.$trgtDir.' cannot be created');
		}
		$keptLst = $this->copyTreesArray($trgtDir, true, $modeCfg['addPaths'])['kept'];
		// The folders the framework writes in, empty
		foreach( Array('media', 'temp') as $fldNme ){
			@mkdir($trgtDir.DS.$fldNme, 0775, true);
		}
		$this->writeModeToIni($trgtDir, $packModeName);
		// Before the archive, which holds it
		$this->buildClassMaps($trgtDir, $packModeName, true);
		$this->printerObj->printShellPair('mode', $packModeName);
		$this->printerObj->printShellPair('folder', $trgtDir);
		$this->printerObj->printShellPair('files', count($keptLst));
		// Packages for development only would be in vendor, and so in the bundle
		$cmpVals = json_decode( strval(@file_get_contents(TOP_DIR.DS.'composer.json')), true );
		if( is_array($cmpVals) && !empty($cmpVals['require-dev']) ){
			$this->printerObj->printShellFail('composer.json has packages for development: they are in vendor, the bundle cannot leave them out');
		}
		if( $this->optionsObj->validateOption('archived') ){
			$this->printerObj->printShellPair('archive', $this->makeTarArchive($trgtDir));
		}
		$this->traceShellDone( 'Bundle: '.$packModeName.', '.count($keptLst).' files in '.$trgtDir.( $this->optionsObj->validateOption('archived') ? ', and its archive' : '' ) );
		$this->printerObj->printShellLine('On its server: php nyuRun install complete, then php nyuRun install checkAll.');
		return self::EXIT_DONE;
	}

	/**
	 * Gives every mode: those of the framework, then those the project
	 * declares in conf/bundle.lst, or in the list given, which is checked
	 * as the file would be. A mode of the project is named freely, with
	 * lower-case letters or digits, of any length, that neither a mode of
	 * the framework nor a sub-command uses; its 'settings'
	 * holds keys of system.ini, ENV_NAME one of the four environments;
	 * its 'addPaths' holds paths of the project, outside KEEPLIST or files
	 * inside it (a file left out otherwise, named to be copied), none of
	 * them hidden nor going up.
	 *
	 * @param ?Array $projectModes The modes of the project to check; those of conf/bundle.lst when null.
	 * @return Array Each mode, by name, with its 'settings' settings and the paths it is 'addPaths'.
	 *
	 * @throws \nyu\Shell\ShellStop When conf/bundle.lst cannot be read, or a mode is not one.
	 */
	protected function readModesArray( ?Array $projectModes=null ): Array {
		$modeLst = Array();
		foreach( self::MODE_LIST as $modNme => $iniArr ){
			$modeLst[$modNme] = Array('settings'=>$iniArr, 'addPaths'=>Array());
		}
		$lstPath = TOP_DIR.DS.str_replace('/', DS, self::BNDL_FILE);
		if( $projectModes===null && !is_file($lstPath) ){
			return $modeLst;
		}
		$fileVal = $projectModes ?? json_decode(strval(@file_get_contents($lstPath)), true);
		if( !is_array($fileVal) ){
			$this->terminateShell(self::BNDL_FILE.' is not a JSON object of modes');
		}
		foreach( $fileVal as $modNme => $mdlVal ){
			$modNme = strval($modNme);
			$iniVals = is_array($mdlVal) ? ($mdlVal['settings'] ?? null) : null;
			$newData = is_array($mdlVal) ? ($mdlVal['addPaths'] ?? Array()) : null;
			$whyText = '';
			if( !preg_match('/^[a-z0-9]+$/D', $modNme) || isset(self::MODE_LIST[$modNme]) || method_exists($this, 'invoke'.ucfirst($modNme)) ){
				$whyText = 'not lower-case letters or digits, or the name of a mode of the framework or of a sub-command';
			}elseif( !is_array($iniVals) || !in_array($iniVals['ENV_NAME'] ?? null, Array('DEVS', 'TEST', 'PREV', 'PROD'), true) ){
				$whyText = 'its "settings" has no ENV_NAME of DEVS, TEST, PREV or PROD';
			}elseif( !is_array($newData) ){
				$whyText = 'its "addPaths" is not a list of paths';
			}
			foreach( (array)$iniVals as $iniKey => $iniVal ){
				if( $whyText=='' && (!preg_match('/^[A-Z]{3}_[A-Z]{4}$/D', strval($iniKey)) || !is_scalar($iniVal) || preg_match('/["\r\n]/', strval($iniVal))) ){
					$whyText = 'its "settings" holds what is not a key of system.ini with its value: '.strval($iniKey);
				}
			}
			foreach( (array)$newData as $addPth ){
				$addPth = is_string($addPth) ? $addPth : '';
				// Inside what every bundle holds, only a file is named : one that is left out otherwise, such as the public key of a server issuing the tokens
				$inKeeps = in_array(explode('/', $addPth)[0], self::KEEP_LIST, true);
				if( $whyText=='' && ( !preg_match('#^[\w\-]+(/[\w.\-]+)*$#D', $addPth) || preg_match('#(^|/)\.#', $addPth) || ($inKeeps && !is_file(TOP_DIR.DS.str_replace('/', DS, $addPth))) || !file_exists(TOP_DIR.DS.str_replace('/', DS, $addPth)) ) ){
					$whyText = 'it adds what is not a path of the project outside what every bundle holds, nor a file of it: '.$addPth;
				}
			}
			if( $whyText!='' ){
				$this->terminateShell(self::BNDL_FILE.': the mode '.$modNme.' cannot be used: '.$whyText, self::EXIT_DENY);
			}
			$modeLst[$modNme] = Array('settings'=>array_map('strval', $iniVals), 'addPaths'=>array_values($newData));
		}
		return $modeLst;
	}

	/**
	 * Writes the modes of the project in conf/bundle.lst, one value per
	 * line.
	 *
	 * @param Array $projectModes The modes of the project, by name, each with its 'settings' and its 'addPaths'.
	 */
	protected function saveBundleList( Array $projectModes ): Void {
		$treeObj = new \stdClass();
		foreach( $projectModes as $modNme => $mdlVal ){
			$treeObj->$modNme = (object)Array('settings'=>(object)$mdlVal['settings'], 'addPaths'=>array_values($mdlVal['addPaths']));
		}
		(new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' ))->saveJsonConfig( TOP_DIR.DS.str_replace('/', DS, self::BNDL_FILE), $treeObj );
	}

	/**
	 * Tells whether a file of the project goes into a bundle: under one of
	 * KEEPLIST or a path the mode adds, not named by DROPLIST, and no part
	 * of its path hidden (starting with a dot), except a .htaccess file.
	 * Nothing of conf/keys travels, nor any key, users file or password
	 * file conf/access.json names, wherever it lies (see secrPaths): the
	 * server makes its own keys (install complete). A mode brings one of them
	 * back only by naming that very file in its 'addPaths' paths, the public
	 * key of a server issuing the tokens for one.
	 *
	 * @param String $pathFromRoot Path of the file from the project root, written with slashes.
	 * @param Array  $addedEntries Paths the mode adds, from the project root.
	 * @return Bool Whether it is copied.
	 */
	protected function goesIntoBundle( String $pathFromRoot, Array $addedEntries=Array() ): Bool {
		$partLst = explode('/', $pathFromRoot);
		$isAdded = false;
		foreach( $addedEntries as $addPth ){
			$isAdded = $isAdded || $pathFromRoot===$addPth || strpos($pathFromRoot, $addPth.'/')===0;
		}
		if( !$isAdded && !in_array($partLst[0], self::KEEP_LIST, true) ){
			return false;
		}
		// A file the mode names itself is copied, whatever drops it otherwise: a folder it names brings back nothing dropped
		$isNamed = in_array($pathFromRoot, $addedEntries, true);
		foreach( self::DROP_LIST as $dropPt ){
			if( !$isNamed && fnmatch($dropPt, $pathFromRoot, FNM_PATHNAME) ){
				return false;
			}
		}
		if( !$isNamed && ( strpos($pathFromRoot, 'conf/keys/')===0 || in_array($pathFromRoot, $this->secretPathsArr(), true) ) ){
			return false;
		}
		foreach( $partLst as $folder ){
			if( $folder!=='' && $folder[0]=='.' && $folder!=='.htaccess' ){
				return false;
			}
		}
		return true;
	}

	/**
	 * Gives the paths of the secrets conf/access.json names, as written:
	 * the keys of the tokens ('privFile', 'publFile', 'prevFile' of any block),
	 * the public key of each application of 'appsKeys', the users file
	 * ('listFile') and the password file ('passFile'). Read once.
	 *
	 * @return Array The paths, from the project root, written with slashes.
	 */
	protected function secretPathsArr(): Array {
		static $pathLst = null;
		if( $pathLst!==null ){
			return $pathLst;
		}
		$pathLst = Array();
		$accTree = json_decode( strval(@file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json')), true );
		$blckLst = is_array($accTree) ? $accTree : Array();
		foreach( $blckLst as $blkVal ){
			// The applications of the application keys hold a public key each
			foreach( (is_array($blkVal) && is_array($blkVal['appsList'] ?? null)) ? $blkVal['appsList'] : Array() as $appVal ){
				$blckLst[] = is_array($appVal) ? Array('publFile'=>$appVal['publFile'] ?? '') : Array();
			}
		}
		foreach( $blckLst as $blkVal ){
			foreach( Array('privFile', 'publFile', 'prevFile', 'listFile', 'passFile') as $keyNme ){
				$pathTxt = is_array($blkVal) && is_string($blkVal[$keyNme] ?? null) ? trim(str_replace('\\', '/', $blkVal[$keyNme]), '/ ') : '';
				if( $pathTxt!=='' ){
					$pathLst[] = $pathTxt;
				}
			}
		}
		return $pathLst;
	}

	/**
	 * Goes through the project and copies what a bundle holds, or only
	 * lists it.
	 *
	 * @param String $saveFilePath Full path of the folder of the bundle.
	 * @param Bool   $writeEnabled Whether the files are copied, or only listed.
	 * @param Array  $addedEntries Paths the mode adds, from the project root.
	 * @return Array The paths kept under 'kept', those left out under 'left': a folder of the root left out is named alone.
	 *
	 * @throws \nyu\Shell\ShellStop When a file cannot be copied.
	 */
	protected function copyTreesArray( String $saveFilePath, Bool $writeEnabled, Array $addedEntries=Array() ): Array {
		$pathLst = Array('kept'=>Array(), 'left'=>Array());
		$topList = array_merge( self::KEEP_LIST, array_map(function( String $addedItemTxt ): String { return explode('/', $addedItemTxt)[0]; }, $addedEntries) );
		foreach( (array)scandir(TOP_DIR) as $topNme ){
			$topNme = strval($topNme);
			if( $topNme=='.' || $topNme=='..' ){
				continue;
			}
			if( !in_array($topNme, $topList, true) ){
				$pathLst['left'][] = $topNme.( is_dir(TOP_DIR.DS.$topNme) ? '/' : '' );
				continue;
			}
			$fileLst = is_dir(TOP_DIR.DS.$topNme) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TOP_DIR.DS.$topNme, \FilesystemIterator::SKIP_DOTS)) : Array(new \SplFileInfo(TOP_DIR.DS.$topNme));
			foreach( $fileLst as $filInf ){
				if( !$filInf->isFile() ){
					continue;
				}
				$relPath = str_replace(DS, '/', substr($filInf->getPathname(), strlen(TOP_DIR)+1));
				if( !$this->goesIntoBundle($relPath, $addedEntries) ){
					$pathLst['left'][] = $relPath;
					continue;
				}
				$pathLst['kept'][] = $relPath;
				if( !$writeEnabled ){
					continue;
				}
				$dstPath = $saveFilePath.DS.str_replace('/', DS, $relPath);
				if( !is_dir(dirname($dstPath)) ){
					@mkdir(dirname($dstPath), 0775, true);
				}
				if( !@copy($filInf->getPathname(), $dstPath) ){
					$this->terminateShell('The bundle is not complete: '.$relPath.' cannot be copied');
				}
				@chmod($dstPath, fileperms($filInf->getPathname()) & 0777);
			}
		}
		sort($pathLst['kept']);
		sort($pathLst['left']);
		return $pathLst;
	}

	/**
	 * Writes the settings of the mode in the system.ini of the bundle.
	 *
	 * @param String $saveFilePath Full path of the folder of the bundle.
	 * @param String $packModeName A mode of the framework or of the project.
	 *
	 * @throws \nyu\Shell\ShellStop When the bundle has no system.ini, or a setting is missing from it.
	 */
	protected function writeModeToIni( String $saveFilePath, String $packModeName ): Void {
		$iniPath = $saveFilePath.DS.'conf'.DS.'system.ini';
		if( !is_file($iniPath) ){
			$this->terminateShell('The bundle has no conf/system.ini: the settings of the mode cannot be written');
		}
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
		foreach( $this->readModesArray()[$packModeName]['settings'] as $iniKey => $iniVal ){
			if( !$wrtrObj->changeIniValue($iniPath, $iniKey, $iniVal) ){
				$this->terminateShell('The bundle is not set for its mode: its system.ini has no '.$iniKey);
			}
		}
		// The config cache, as the class map, for the environments of MAPSENVS; a system.ini made before it is told, not refused
		if( !$wrtrObj->changeIniValue($iniPath, 'CNF_CACH', in_array($this->readModesArray()[$packModeName]['settings']['ENV_NAME'] ?? '', self::MAPS_ENVS, true) ? 'TRUE' : 'FALSE') ){
			$this->printerObj->printShellFail('The config is read on each request: the system.ini of the project has no CNF_CACH (see conf/system.ini of the framework)');
		}
	}

	/**
	 * Makes the autoloader of a bundle for an environment of MAPSENVS find
	 * every class from its class map, without looking for files: Composer
	 * is run on the copy (dump-autoload, optimised, authoritative, without
	 * the packages for development). Another environment keeps the
	 * autoloader of the project, as does a bundle Composer could not
	 * work on: it is told, and the bundle works without the map.
	 *
	 * @param String $saveFilePath Full path of the folder of the bundle.
	 * @param String $packModeName A mode of the framework or of the project.
	 * @param Bool   $writeEnabled Whether Composer is run, or only told about (--planOnly).
	 * @return Bool Whether the class map was made, or would be.
	 */
	protected function buildClassMaps( String $saveFilePath, String $packModeName, Bool $writeEnabled ): Bool {
		if( !in_array($this->readModesArray()[$packModeName]['settings']['ENV_NAME'] ?? '', self::MAPS_ENVS, true) ){
			$this->printerObj->printShellPair('class map', 'the one of the project, for its '.implode(' and ', self::MAPS_ENVS).' bundles only');
			return false;
		}
		$cmdList = $this->locateComposer();
		if( $cmdList===null ){
			$this->printerObj->printShellFail('The autoloader is not optimised: Composer was not found (--composer=<path>); run composer dump-autoload -o -a --no-dev in the bundle');
			return false;
		}
		if( !$writeEnabled ){
			$this->printerObj->printShellPair('class map', 'complete, made by '.end($cmdList));
			return true;
		}
		$cmdList = array_merge( $cmdList, Array('dump-autoload', '--optimize', '--classmap-authoritative', '--no-dev', '--no-interaction', '--working-dir='.$saveFilePath) );
		$outText = Array();
		$extCode = 0;
		exec( implode(' ', array_map('escapeshellarg', $cmdList)).' 2>&1', $outText, $extCode );
		if( $extCode!==0 ){
			$this->printerObj->printShellFail('The autoloader is not optimised: Composer ended with '.$extCode.( empty($outText) ? '' : ': '.trim(strval(end($outText))) ).'; run composer dump-autoload -o -a --no-dev in the bundle');
			return false;
		}
		$this->printerObj->printShellPair('class map', 'complete');
		$this->traceShellDone( 'Bundle: '.$packModeName.', class map made by Composer in '.$saveFilePath );
		return true;
	}

	/**
	 * Finds Composer: the file --composer names, else composer.phar at the
	 * root of the project, else Composer on the PATH (composer.phar, or
	 * composer, composer.bat on Windows). A phar is run with the PHP
	 * running this command.
	 *
	 * @return ?Array The command that runs Composer, as a list of words; null when there is none.
	 */
	protected function locateComposer(): ?Array {
		$cmpPath = strval($this->optionsObj->retrieveOption('composer', ''));
		if( $cmpPath!='' ){
			if( !is_file($cmpPath) ){
				return null;
			}
		}elseif( is_file(TOP_DIR.DS.'composer.phar') ){
			$cmpPath = TOP_DIR.DS.'composer.phar';
		}else{
			foreach( explode(PATH_SEPARATOR, strval(getenv('PATH'))) as $dirInc ){
				foreach( (DS=='\\') ? Array('composer.phar', 'composer.bat') : Array('composer.phar', 'composer') as $binInc ){
					if( $cmpPath=='' && trim($dirInc)!='' && is_file(rtrim($dirInc, '/\\').DS.$binInc) ){
						$cmpPath = rtrim($dirInc, '/\\').DS.$binInc;
					}
				}
			}
		}
		if( $cmpPath=='' ){
			return null;
		}
		return (strtolower(pathinfo($cmpPath, PATHINFO_EXTENSION))=='phar') ? Array(PHP_BINARY, $cmpPath) : Array($cmpPath);
	}

	/**
	 * Makes the .tar.gz of a bundle, beside its folder, replacing one of
	 * the same name.
	 *
	 * @param String $saveFilePath Full path of the folder of the bundle.
	 * @return String Full path of the archive.
	 *
	 * @throws \nyu\Shell\ShellStop When the archive cannot be made.
	 */
	protected function makeTarArchive( String $saveFilePath ): String {
		if( !class_exists('PharData') ){
			$this->terminateShell('No archive made: the phar extension is not loaded');
		}
		$tarPath = $saveFilePath.'.tar';
		foreach( Array($tarPath, $tarPath.'.gz') as $oldTar ){
			if( is_file($oldTar) ){
				@unlink($oldTar);
			}
		}
		try{
			$archive = new \PharData($tarPath);
			$archive->buildFromDirectory($saveFilePath);
			$archive->compress(\Phar::GZ);
			unset($archive);
			@unlink($tarPath);
		}catch( \Throwable $e ){
			$this->terminateShell('No archive made: '.$e->getMessage());
		}
		return $tarPath.'.gz';
	}

	/**
	 * Lists what a bundle would hold and what it would leave out, without
	 * writing anything.
	 *
	 * @param String $saveFilePath Full path of the folder the bundle would be made in.
	 * @param Array  $addedEntries Paths the mode adds, from the project root.
	 * @return Int The exit code.
	 */
	protected function listPlansCount( String $saveFilePath, Array $addedEntries=Array() ): Int {
		$pathLst = $this->copyTreesArray($saveFilePath, false, $addedEntries);
		$rowList = Array();
		foreach( $pathLst['kept'] as $keptPt ){
			$rowList[] = Array($keptPt, 'copied');
		}
		foreach( $pathLst['left'] as $leftPt ){
			$rowList[] = Array($leftPt, 'left out');
		}
		$this->printerObj->printShellPair('folder', $saveFilePath);
		$this->printerObj->printShellRows( Array('path', 'state'), $rowList );
		return self::EXIT_DONE;
	}
}
