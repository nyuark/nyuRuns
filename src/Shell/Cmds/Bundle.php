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
	const DROP_LIST = Array('conf/access.json', 'conf/database.json', 'conf/mailer.json', 'conf/users.lst', 'conf/.htpasswd', 'conf/keys/*', 'conf/bundle.lst', 'conf/doorways.lst', 'runs/*/runspec.json');
	// The modes the project declares: { "<mode>": { "settings": { "ENV_NAME": ..., ... }, "addPaths": [ <paths> ] } }
	const BNDL_FILE = 'conf/bundle.lst';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function renderSynopsis(): String {
		return 'Builds a copy of the project for an environment, without secrets, tests or documents';
	}

	/**
	 * Lists the sub-commands of the command: the modes of the framework,
	 * those the project declares, and the management of the latter.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function listSubCmndDct(): Array {
		$optsText = '[--destPath=<path>] [--archived] [--planOnly] [--yesToAll] [--composer=<path>]';
		$subLstng = Array(
			'local'=>Array('call'=>'makeModeBundle', 'args'=>$optsText, 'about'=>'a bundle for a developer\'s machine: DEVS, errors shown, full logs'),
			'stage'=>Array('call'=>'makeModeBundle', 'args'=>$optsText, 'about'=>'a bundle for a server before production: PREV, errors hidden, basic logs'),
			'final'=>Array('call'=>'makeModeBundle', 'args'=>$optsText, 'about'=>'a bundle for production: PROD, errors hidden, basic logs')
		);
		foreach( $this->readModesArray() as $oneNme => $oneVal ){
			if( !isset(self::MODE_LIST[$oneNme]) ){
				$subLstng[$oneNme] = Array('call'=>'makeModeBundle', 'args'=>$optsText, 'about'=>'a bundle of a mode of the project: '.strval($oneVal['settings']['ENV_NAME'] ?? ''));
			}
		}
		return $subLstng + Array(
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
		$rowsList = Array();
		foreach( $this->readModesArray() as $oneNme => $oneVal ){
			$setStrng = implode(', ', array_map(function( String $iniEntryName, String $iniKeyValTxt ): String { return $iniEntryName.'='.$iniKeyValTxt; }, array_keys($oneVal['settings']), array_values($oneVal['settings'])));
			$rowsList[] = Array($oneNme, isset(self::MODE_LIST[$oneNme]) ? 'framework' : 'project', $setStrng, implode(', ', $oneVal['addPaths']));
		}
		$this->printerObj->writeShellRows( Array('mode', 'kind', 'settings', 'addPaths'), $rowsList );
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
		$modeName = $this->needsShellWord('the mode: lower-case letters or digits, of any length');
		if( !preg_match('/^[a-z0-9]+$/D', $modeName) ){
			$this->stopWithReason('A mode is named with lower-case letters or digits', self::EXIT_HELP);
		}
		if( method_exists($this, 'invoke'.ucfirst($modeName)) ){
			$this->stopWithReason('Nothing was declared: '.$modeName.' is a mode of the framework or a sub-command', self::EXIT_DENY);
		}
		$modeList = $this->readModesArray();
		$isListed = isset($modeList[$modeName]);
		$nowCodig = $modeList[$modeName] ?? Array('settings'=>Array('ENV_NAME'=>'TEST', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'TRUE', 'LOG_MODE'=>'FULL'), 'addPaths'=>Array());
		$iniValrs = $nowCodig['settings'];
		$iniValrs['ENV_NAME'] = strtoupper( $this->promptValueTxt('Environment (DEVS, TEST, PREV, PROD)', strval($iniValrs['ENV_NAME'] ?? 'TEST')) );
		$iniValrs['ERR_LEVL'] = trim( $this->promptValueTxt('Errors reported (E_* names joined by |, & and ~)', strval($iniValrs['ERR_LEVL'] ?? 'E_ALL')) );
		$iniValrs['ERR_SHOW'] = strtoupper( $this->promptValueTxt('Show errors (TRUE, FALSE)', strval($iniValrs['ERR_SHOW'] ?? 'TRUE')) );
		$iniValrs['LOG_MODE'] = strtoupper( $this->promptValueTxt('Logging mode (FULL, BASE, MINI)', strval($iniValrs['LOG_MODE'] ?? 'FULL')) );
		if( !preg_match(Config::RULE_LIST['ERR_LEVL'], $iniValrs['ERR_LEVL']) ){
			$this->stopWithReason('Nothing was declared: ERR_LEVL is E_* names joined by |, & and ~', self::EXIT_DENY);
		}
		foreach( Array('ERR_SHOW'=>Array('TRUE', 'FALSE'), 'LOG_MODE'=>Array('FULL', 'BASE', 'MINI')) as $oneKey => $oneLst ){
			if( !in_array($iniValrs[$oneKey], $oneLst, true) ){
				$this->stopWithReason('Nothing was declared: '.$oneKey.' is one of '.implode(', ', $oneLst), self::EXIT_DENY);
			}
		}
		$addStrng = $this->promptValueTxt('Paths the bundle adds, separated by commas (none for none)', empty($nowCodig['addPaths']) ? 'none' : implode(', ', $nowCodig['addPaths']));
		$addLstng = (strtolower(trim($addStrng))=='none') ? Array() : array_values(array_filter(array_map(function( String $givenItemTxt ): String { return trim(str_replace('\\', '/', $givenItemTxt), " /"); }, explode(',', $addStrng)), 'strlen'));
		$ownLstng = Array();
		foreach( $modeList as $oneNme => $oneVal ){
			if( !isset(self::MODE_LIST[$oneNme]) ){
				$ownLstng[$oneNme] = $oneVal;
			}
		}
		$ownLstng[$modeName] = Array('settings'=>$iniValrs, 'addPaths'=>$addLstng);
		// Checked as the file will be read, before it is written
		$this->readModesArray($ownLstng);
		$this->saveBundleList($ownLstng);
		$this->logShellChange( 'Bundle: mode '.$modeName.( $isListed ? ' changed' : ' declared' ).' ('.$iniValrs['ENV_NAME'].')' );
		$this->printerObj->writeShellPair( $isListed ? 'mode changed' : 'mode declared', $modeName );
		return self::EXIT_DONE;
	}

	/**
	 * Removes a mode the project declared. Those of the framework stay.
	 *
	 * @return Int The exit code.
	 */
	public function invokeDropMode(): Int {
		$modeName = $this->needsShellWord('the mode');
		if( isset(self::MODE_LIST[$modeName]) ){
			$this->stopWithReason('Nothing was removed: '.$modeName.' is a mode of the framework', self::EXIT_DENY);
		}
		$ownLstng = Array();
		foreach( $this->readModesArray() as $oneNme => $oneVal ){
			if( !isset(self::MODE_LIST[$oneNme]) ){
				$ownLstng[$oneNme] = $oneVal;
			}
		}
		if( !isset($ownLstng[$modeName]) ){
			$this->stopWithReason('Nothing was removed: the project declares no mode '.$modeName, self::EXIT_DENY);
		}
		if( !$this->promptApproval('Remove the mode '.$modeName) ){
			$this->stopWithReason('Nothing was removed: not confirmed', self::EXIT_DENY);
		}
		unset($ownLstng[$modeName]);
		$this->saveBundleList($ownLstng);
		$this->logShellChange( 'Bundle: mode '.$modeName.' removed' );
		$this->printerObj->writeShellPair('mode removed', $modeName);
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
	protected function makeModeBundle( String $packModeName ): Int {
		$modeConf = $this->readModesArray()[$packModeName] ?? null;
		if( $modeConf===null ){
			$this->stopWithReason('No bundle made: there is no mode '.$packModeName, self::EXIT_HELP);
		}
		$outFoldr = strval($this->optionsObj->getOptionValue('destPath', ''));
		$distPath = TOP_DIR.DS.'dist'.DS.$packModeName;
		$trgtPath = ($outFoldr=='') ? $distPath : rtrim($outFoldr, '/\\');
		if( !preg_match('#^([A-Za-z]:|[/\\\\])#', $trgtPath) ){
			$trgtPath = getcwd().DS.$trgtPath;
		}
		$trgtPath = str_replace(Array('/', '\\'), DS, $trgtPath);
		// A bundle never lands in what it copies : it would copy itself
		$fromRoot = (strpos($trgtPath.DS, TOP_DIR.DS)===0) ? str_replace(DS, '/', substr($trgtPath, strlen(TOP_DIR)+1)) : '';
		$topLstng = array_merge( self::KEEP_LIST, array_map(function( String $addedItemTxt ): String { return explode('/', $addedItemTxt)[0]; }, $modeConf['addPaths']) );
		if( ($fromRoot==='' && strpos($trgtPath.DS, TOP_DIR.DS)===0) || in_array(explode('/', $fromRoot)[0], $topLstng, true) ){
			$this->stopWithReason('No bundle made: its folder cannot be the project, nor a folder the bundle copies', self::EXIT_HELP);
		}
		if( $this->optionsObj->isOptionPassed('planOnly') ){
			$this->makeBundleMaps($trgtPath, $packModeName, false);
			return $this->listPlansCount($trgtPath, $modeConf['addPaths']);
		}
		if( is_dir($trgtPath) && count((array)scandir($trgtPath))>2 ){
			if( $trgtPath!==$distPath ){
				$this->stopWithReason('No bundle made: '.$trgtPath.' is not empty; a folder given with --destPath must be', self::EXIT_DENY);
			}
			if( !$this->promptApproval('Replace the bundle in dist/'.$packModeName) ){
				$this->stopWithReason('No bundle made: not confirmed', self::EXIT_DENY);
			}
			$drctWalk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator($trgtPath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach( $drctWalk as $oneItm ){
				$oneItm->isDir() ? @rmdir($oneItm->getPathname()) : @unlink($oneItm->getPathname());
			}
		}
		if( !is_dir($trgtPath) && !@mkdir($trgtPath, 0775, true) ){
			$this->stopWithReason('No bundle made: '.$trgtPath.' cannot be created');
		}
		$keptList = $this->copyTreesArray($trgtPath, true, $modeConf['addPaths'])['kept'];
		// The folders the framework writes in, empty
		foreach( Array('media', 'temp') as $oneNme ){
			@mkdir($trgtPath.DS.$oneNme, 0775, true);
		}
		$this->writeModeToIni($trgtPath, $packModeName);
		// Before the archive, which holds it
		$this->makeBundleMaps($trgtPath, $packModeName, true);
		$this->printerObj->writeShellPair('mode', $packModeName);
		$this->printerObj->writeShellPair('folder', $trgtPath);
		$this->printerObj->writeShellPair('files', count($keptList));
		// Packages for development only would be in vendor, and so in the bundle
		$cmplVals = json_decode( strval(@file_get_contents(TOP_DIR.DS.'composer.json')), true );
		if( is_array($cmplVals) && !empty($cmplVals['require-dev']) ){
			$this->printerObj->writeShellFail('composer.json has packages for development: they are in vendor, the bundle cannot leave them out');
		}
		if( $this->optionsObj->isOptionPassed('archived') ){
			$this->printerObj->writeShellPair('archive', $this->makeTarArchive($trgtPath));
		}
		$this->logShellChange( 'Bundle: '.$packModeName.', '.count($keptList).' files in '.$trgtPath.( $this->optionsObj->isOptionPassed('archived') ? ', and its archive' : '' ) );
		$this->printerObj->writeShellLine('On its server: php nyuRun install complete, then php nyuRun install checkAll.');
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
		$modeList = Array();
		foreach( self::MODE_LIST as $oneNme => $oneIni ){
			$modeList[$oneNme] = Array('settings'=>$oneIni, 'addPaths'=>Array());
		}
		$listPath = TOP_DIR.DS.str_replace('/', DS, self::BNDL_FILE);
		if( $projectModes===null && !is_file($listPath) ){
			return $modeList;
		}
		$fileVals = $projectModes ?? json_decode(strval(@file_get_contents($listPath)), true);
		if( !is_array($fileVals) ){
			$this->stopWithReason(self::BNDL_FILE.' is not a JSON object of modes');
		}
		foreach( $fileVals as $oneNme => $oneVal ){
			$oneNme = strval($oneNme);
			$iniValrs = is_array($oneVal) ? ($oneVal['settings'] ?? null) : null;
			$newArray = is_array($oneVal) ? ($oneVal['addPaths'] ?? Array()) : null;
			$whyStrng = '';
			if( !preg_match('/^[a-z0-9]+$/D', $oneNme) || isset(self::MODE_LIST[$oneNme]) || method_exists($this, 'invoke'.ucfirst($oneNme)) ){
				$whyStrng = 'not lower-case letters or digits, or the name of a mode of the framework or of a sub-command';
			}elseif( !is_array($iniValrs) || !in_array($iniValrs['ENV_NAME'] ?? null, Array('DEVS', 'TEST', 'PREV', 'PROD'), true) ){
				$whyStrng = 'its "settings" has no ENV_NAME of DEVS, TEST, PREV or PROD';
			}elseif( !is_array($newArray) ){
				$whyStrng = 'its "addPaths" is not a list of paths';
			}
			foreach( (array)$iniValrs as $oneKey => $oneIni ){
				if( $whyStrng=='' && (!preg_match('/^[A-Z]{3}_[A-Z]{4}$/D', strval($oneKey)) || !is_scalar($oneIni) || preg_match('/["\r\n]/', strval($oneIni))) ){
					$whyStrng = 'its "settings" holds what is not a key of system.ini with its value: '.strval($oneKey);
				}
			}
			foreach( (array)$newArray as $onePth ){
				$onePth = is_string($onePth) ? $onePth : '';
				// Inside what every bundle holds, only a file is named : one that is left out otherwise, such as the public key of a server issuing the tokens
				$isInKeep = in_array(explode('/', $onePth)[0], self::KEEP_LIST, true);
				if( $whyStrng=='' && ( !preg_match('#^[\w\-]+(/[\w.\-]+)*$#D', $onePth) || preg_match('#(^|/)\.#', $onePth) || ($isInKeep && !is_file(TOP_DIR.DS.str_replace('/', DS, $onePth))) || !file_exists(TOP_DIR.DS.str_replace('/', DS, $onePth)) ) ){
					$whyStrng = 'it adds what is not a path of the project outside what every bundle holds, nor a file of it: '.$onePth;
				}
			}
			if( $whyStrng!='' ){
				$this->stopWithReason(self::BNDL_FILE.': the mode '.$oneNme.' cannot be used: '.$whyStrng, self::EXIT_DENY);
			}
			$modeList[$oneNme] = Array('settings'=>array_map('strval', $iniValrs), 'addPaths'=>array_values($newArray));
		}
		return $modeList;
	}

	/**
	 * Writes the modes of the project in conf/bundle.lst, one value per
	 * line.
	 *
	 * @param Array $projectModes The modes of the project, by name, each with its 'settings' and its 'addPaths'.
	 */
	protected function saveBundleList( Array $projectModes ): Void {
		$treeInst = new \stdClass();
		foreach( $projectModes as $oneNme => $oneVal ){
			$treeInst->$oneNme = (object)Array('settings'=>(object)$oneVal['settings'], 'addPaths'=>array_values($oneVal['addPaths']));
		}
		(new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' ))->saveJsonConfig( TOP_DIR.DS.str_replace('/', DS, self::BNDL_FILE), $treeInst );
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
	protected function isKeptInBundle( String $pathFromRoot, Array $addedEntries=Array() ): Bool {
		$partList = explode('/', $pathFromRoot);
		$wasAdded = false;
		foreach( $addedEntries as $onePth ){
			$wasAdded = $wasAdded || $pathFromRoot===$onePth || strpos($pathFromRoot, $onePth.'/')===0;
		}
		if( !$wasAdded && !in_array($partList[0], self::KEEP_LIST, true) ){
			return false;
		}
		// A file the mode names itself is copied, whatever drops it otherwise: a folder it names brings back nothing dropped
		$isNameOk = in_array($pathFromRoot, $addedEntries, true);
		foreach( self::DROP_LIST as $oneDrp ){
			if( !$isNameOk && fnmatch($oneDrp, $pathFromRoot, FNM_PATHNAME) ){
				return false;
			}
		}
		if( !$isNameOk && ( strpos($pathFromRoot, 'conf/keys/')===0 || in_array($pathFromRoot, $this->getSecretPaths(), true) ) ){
			return false;
		}
		foreach( $partList as $onePth ){
			if( $onePth!=='' && $onePth[0]=='.' && $onePth!=='.htaccess' ){
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
	protected function getSecretPaths(): Array {
		static $pathList = null;
		if( $pathList!==null ){
			return $pathList;
		}
		$pathList = Array();
		$accsTree = json_decode( strval(@file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json')), true );
		$blockLst = is_array($accsTree) ? $accsTree : Array();
		foreach( $blockLst as $oneVal ){
			// The applications of the application keys hold a public key each
			foreach( (is_array($oneVal) && is_array($oneVal['appsList'] ?? null)) ? $oneVal['appsList'] : Array() as $oneApp ){
				$blockLst[] = is_array($oneApp) ? Array('publFile'=>$oneApp['publFile'] ?? '') : Array();
			}
		}
		foreach( $blockLst as $oneVal ){
			foreach( Array('privFile', 'publFile', 'prevFile', 'listFile', 'passFile') as $oneNme ){
				$pathText = is_array($oneVal) && is_string($oneVal[$oneNme] ?? null) ? trim(str_replace('\\', '/', $oneVal[$oneNme]), '/ ') : '';
				if( $pathText!=='' ){
					$pathList[] = $pathText;
				}
			}
		}
		return $pathList;
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
		$pathList = Array('kept'=>Array(), 'left'=>Array());
		$topLstng = array_merge( self::KEEP_LIST, array_map(function( String $addedItemTxt ): String { return explode('/', $addedItemTxt)[0]; }, $addedEntries) );
		foreach( (array)scandir(TOP_DIR) as $oneNme ){
			$oneNme = strval($oneNme);
			if( $oneNme=='.' || $oneNme=='..' ){
				continue;
			}
			if( !in_array($oneNme, $topLstng, true) ){
				$pathList['left'][] = $oneNme.( is_dir(TOP_DIR.DS.$oneNme) ? '/' : '' );
				continue;
			}
			$fileList = is_dir(TOP_DIR.DS.$oneNme) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TOP_DIR.DS.$oneNme, \FilesystemIterator::SKIP_DOTS)) : Array(new \SplFileInfo(TOP_DIR.DS.$oneNme));
			foreach( $fileList as $oneInf ){
				if( !$oneInf->isFile() ){
					continue;
				}
				$rltnPath = str_replace(DS, '/', substr($oneInf->getPathname(), strlen(TOP_DIR)+1));
				if( !$this->isKeptInBundle($rltnPath, $addedEntries) ){
					$pathList['left'][] = $rltnPath;
					continue;
				}
				$pathList['kept'][] = $rltnPath;
				if( !$writeEnabled ){
					continue;
				}
				$distPath = $saveFilePath.DS.str_replace('/', DS, $rltnPath);
				if( !is_dir(dirname($distPath)) ){
					@mkdir(dirname($distPath), 0775, true);
				}
				if( !@copy($oneInf->getPathname(), $distPath) ){
					$this->stopWithReason('The bundle is not complete: '.$rltnPath.' cannot be copied');
				}
				@chmod($distPath, fileperms($oneInf->getPathname()) & 0777);
			}
		}
		sort($pathList['kept']);
		sort($pathList['left']);
		return $pathList;
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
		$iniTrail = $saveFilePath.DS.'conf'.DS.'system.ini';
		if( !is_file($iniTrail) ){
			$this->stopWithReason('The bundle has no conf/system.ini: the settings of the mode cannot be written');
		}
		// The system.ini of the bundle was just copied from the project: the copies made before each write are kept nowhere,
		// neither in the archives of the project nor in the bundle
		$backFldr = sys_get_temp_dir().DS.'nyuruns-bundle-'.getmypid();
		$wrtrInst = new ConfFile( $backFldr );
		try{
			foreach( $this->readModesArray()[$packModeName]['settings'] as $oneKey => $oneIni ){
				if( !$wrtrInst->saveIniFileVal($iniTrail, $oneKey, $oneIni) ){
					$this->stopWithReason('The bundle is not set for its mode: its system.ini has no '.$oneKey);
				}
			}
			// The config cache, as the class map, for the environments of MAPSENVS; a system.ini made before it is told, not refused
			if( !$wrtrInst->saveIniFileVal($iniTrail, 'CNF_CACH', in_array($this->readModesArray()[$packModeName]['settings']['ENV_NAME'] ?? '', self::MAPS_ENVS, true) ? 'TRUE' : 'FALSE') ){
				$this->printerObj->writeShellFail('The config is read on each request: the system.ini of the project has no CNF_CACH (see conf/system.ini of the framework)');
			}
		}finally{
			array_map('unlink', array_filter((array)glob($backFldr.DS.'*'), 'is_file'));
			@rmdir($backFldr);
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
	protected function makeBundleMaps( String $saveFilePath, String $packModeName, Bool $writeEnabled ): Bool {
		if( !in_array($this->readModesArray()[$packModeName]['settings']['ENV_NAME'] ?? '', self::MAPS_ENVS, true) ){
			$this->printerObj->writeShellPair('class map', 'the one of the project, for its '.implode(' and ', self::MAPS_ENVS).' bundles only');
			return false;
		}
		$cmmnList = $this->locateComposer();
		if( $cmmnList===null ){
			$this->printerObj->writeShellFail('The autoloader is not optimised: Composer was not found (--composer=<path>); run composer dump-autoload -o -a --no-dev in the bundle');
			return false;
		}
		if( !$writeEnabled ){
			$this->printerObj->writeShellPair('class map', 'complete, made by '.end($cmmnList));
			return true;
		}
		$cmmnList = array_merge( $cmmnList, Array('dump-autoload', '--optimize', '--classmap-authoritative', '--no-dev', '--no-interaction', '--working-dir='.$saveFilePath) );
		$outStrng = Array();
		$extnCode = 0;
		exec( implode(' ', array_map('escapeshellarg', $cmmnList)).' 2>&1', $outStrng, $extnCode );
		if( $extnCode!==0 ){
			$this->printerObj->writeShellFail('The autoloader is not optimised: Composer ended with '.$extnCode.( empty($outStrng) ? '' : ': '.trim(strval(end($outStrng))) ).'; run composer dump-autoload -o -a --no-dev in the bundle');
			return false;
		}
		$this->printerObj->writeShellPair('class map', 'complete');
		$this->logShellChange( 'Bundle: '.$packModeName.', class map made by Composer in '.$saveFilePath );
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
		$cmplPath = strval($this->optionsObj->getOptionValue('composer', ''));
		if( $cmplPath!='' ){
			if( !is_file($cmplPath) ){
				return null;
			}
		}elseif( is_file(TOP_DIR.DS.'composer.phar') ){
			$cmplPath = TOP_DIR.DS.'composer.phar';
		}else{
			foreach( explode(PATH_SEPARATOR, strval(getenv('PATH'))) as $nthPth ){
				foreach( (DS=='\\') ? Array('composer.phar', 'composer.bat') : Array('composer.phar', 'composer') as $nthBnr ){
					if( $cmplPath=='' && trim($nthPth)!='' && is_file(rtrim($nthPth, '/\\').DS.$nthBnr) ){
						$cmplPath = rtrim($nthPth, '/\\').DS.$nthBnr;
					}
				}
			}
		}
		if( $cmplPath=='' ){
			return null;
		}
		return (strtolower(pathinfo($cmplPath, PATHINFO_EXTENSION))=='phar') ? Array(PHP_BINARY, $cmplPath) : Array($cmplPath);
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
			$this->stopWithReason('No archive made: the phar extension is not loaded');
		}
		$tarTrail = $saveFilePath.'.tar';
		foreach( Array($tarTrail, $tarTrail.'.gz') as $oneTar ){
			if( is_file($oneTar) ){
				@unlink($oneTar);
			}
		}
		try{
			$archInst = new \PharData($tarTrail);
			$archInst->buildFromDirectory($saveFilePath);
			$archInst->compress(\Phar::GZ);
			unset($archInst);
			@unlink($tarTrail);
		}catch( \Throwable $e ){
			$this->stopWithReason('No archive made: '.$e->getMessage());
		}
		return $tarTrail.'.gz';
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
		$pathList = $this->copyTreesArray($saveFilePath, false, $addedEntries);
		$rowsList = Array();
		foreach( $pathList['kept'] as $oneKpt ){
			$rowsList[] = Array($oneKpt, 'copied');
		}
		foreach( $pathList['left'] as $oneLft ){
			$rowsList[] = Array($oneLft, 'left out');
		}
		$this->printerObj->writeShellPair('folder', $saveFilePath);
		$this->printerObj->writeShellRows( Array('path', 'state'), $rowsList );
		return self::EXIT_DONE;
	}
}
