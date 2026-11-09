<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;
use nyu\Shell\ConfFile;

/**
 * Builds a copy of the project for one environment, to be carried to its
 * server, where "php nyuRun deploy start" installs it:
 *
 *   php nyuRun bundle local                 for a developer's machine
 *   php nyuRun bundle stage                 for a server before production
 *   php nyuRun bundle final                 for production
 *   php nyuRun bundle final --archv         the same, and its .tar.gz beside it
 *   php nyuRun bundle final --plans         lists what would be copied, and writes nothing
 *   php nyuRun bundle final --place=<path>  in another folder than dist/<mode>, which must be empty
 *   php nyuRun bundle final --cmpsr=<path>  with the Composer that makes its class map
 *   php nyuRun bundle packs                 lists the modes, those of the framework and those of the project
 *   php nyuRun bundle mkpak qatst           declares a mode of the project (an integration server), or changes it
 *   php nyuRun bundle rmpak qatst           removes a mode of the project
 *
 * A mode the project declares, in conf/bundle.lst, is built as the others
 * are: php nyuRun bundle qatst. Only what KEEPLIST names is copied, with
 * the paths the mode adds, and nothing DROPLIST names within them: no
 * secret, no local setting and no private key reach a bundle. The
 * settings of the mode are written in the system.ini of the copy, never
 * in the project's own. The copy of an environment of MAPSENVS finds its
 * classes from a complete class map, which Composer makes in it.
 */
class Bundle extends Command{

	// The modes of the framework, with the settings written in the system.ini of their bundle
	const MODELIST = Array(
		'local'=>Array('ENV_NAME'=>'DEV', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'TRUE', 'LOG_MODE'=>'FULL'),
		'stage'=>Array('ENV_NAME'=>'PREV', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'FALSE', 'LOG_MODE'=>'BASE'),
		// Production writes errors, warnings and notices: no line for each access of a member
		'final'=>Array('ENV_NAME'=>'PROD', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'FALSE', 'LOG_MODE'=>'MINI')
	);
	// The environments whose bundle finds every class from a complete class map, without looking for files, and caches its config (CNF_CACH)
	const MAPSENVS = Array('PREV', 'PROD');
	// What a bundle holds, from the project root : nothing else is copied, but what a mode adds
	const KEEPLIST = Array('src', 'runs', 'www', 'pages', 'conf', 'vendor', 'composer.json', 'composer.lock', 'nyuRun', 'LICENSE', 'NOTICE');
	// What is left out of those, from the project root : the local settings, their secrets, the private keys, the modes of the project
	const DROPLIST = Array('conf/access.json', 'conf/database.json', 'conf/mailer.json', 'conf/users.lst', 'conf/.htpasswd', 'conf/keys/*', 'conf/bundle.lst');
	// The modes the project declares: { "<mode>": { "system": { "ENV_NAME": ..., ... }, "adding": [ <paths> ] } }
	const BNDLFILE = 'conf/bundle.lst';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Builds a copy of the project for an environment, without secrets, tests or documents';
	}

	/**
	 * Lists the sub-commands of the command: the modes of the framework,
	 * those the project declares, and the management of the latter.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		$optText = '[--place=<path>] [--archv] [--plans] [--agree] [--cmpsr=<path>]';
		$subList = Array(
			'local'=>Array('args'=>$optText, 'about'=>'a bundle for a developer\'s machine: DEV, errors shown, full logs'),
			'stage'=>Array('args'=>$optText, 'about'=>'a bundle for a server before production: PREV, errors hidden, basic logs'),
			'final'=>Array('args'=>$optText, 'about'=>'a bundle for production: PROD, errors hidden, basic logs')
		);
		foreach( $this->readModes() as $modeNm => $modVal ){
			if( !isset(self::MODELIST[$modeNm]) ){
				$subList[$modeNm] = Array('call'=>'makeBundl', 'args'=>$optText, 'about'=>'a bundle of a mode of the project: '.strval($modVal['system']['ENV_NAME'] ?? ''));
			}
		}
		return $subList + Array(
			'packs'=>Array('args'=>'', 'about'=>'lists the modes: those of the framework and those of the project'),
			'mkpak'=>Array('args'=>'<mode> [--noask]', 'about'=>'declares a mode of the project in '.self::BNDLFILE.', or changes it'),
			'rmpak'=>Array('args'=>'<mode> [--agree]', 'about'=>'removes a mode of the project')
		);
	}

	/**
	 * Builds the bundle of a developer's machine.
	 *
	 * @return Int The exit code.
	 */
	public function callLocal(): Int {
		return $this->makeBundl('local');
	}

	/**
	 * Builds the bundle of a server before production.
	 *
	 * @return Int The exit code.
	 */
	public function callStage(): Int {
		return $this->makeBundl('stage');
	}

	/**
	 * Builds the bundle of production.
	 *
	 * @return Int The exit code.
	 */
	public function callFinal(): Int {
		return $this->makeBundl('final');
	}

	/**
	 * Lists the modes: those of the framework and those the project
	 * declares, with their settings and the paths they add.
	 *
	 * @return Int The exit code.
	 */
	public function callPacks(): Int {
		$rowList = Array();
		foreach( $this->readModes() as $modeNm => $modVal ){
			$setText = implode(', ', array_map(function( String $iniKeyName, String $iniKeyValu ): String { return $iniKeyName.'='.$iniKeyValu; }, array_keys($modVal['system']), array_values($modVal['system'])));
			$rowList[] = Array($modeNm, isset(self::MODELIST[$modeNm]) ? 'framework' : 'project', $setText, implode(', ', $modVal['adding']));
		}
		$this->prntObjc->printRows( Array('mode', 'kind', 'settings', 'adding'), $rowList );
		return self::EXITDONE;
	}

	/**
	 * Declares a mode of the project, or changes one: its environment,
	 * the errors reported, whether they are shown, its logging mode, and the paths its
	 * bundle adds to KEEPLIST (a folder of tests for an integration
	 * server, for one). Each is asked, the present value proposed, TEST,
	 * E_ALL, TRUE, FULL and none for a new mode; --noask takes them. Other keys
	 * of system.ini written by hand in the mode are kept.
	 *
	 * @return Int The exit code.
	 */
	public function callMkpak(): Int {
		$modeNme = $this->needsWord('the mode: five lower-case letters or digits');
		if( !preg_match('/^[a-z0-9]{5}$/D', $modeNme) ){
			$this->stopShell('A mode is named with five lower-case letters or digits', self::EXITUSGE);
		}
		if( method_exists($this, 'call'.ucfirst($modeNme)) ){
			$this->stopShell('Nothing was declared: '.$modeNme.' is a mode of the framework or a sub-command', self::EXITDENY);
		}
		$modeLst = $this->readModes();
		$isKnown = isset($modeLst[$modeNme]);
		$nowMode = $modeLst[$modeNme] ?? Array('system'=>Array('ENV_NAME'=>'TEST', 'ERR_LEVL'=>'E_ALL', 'ERR_SHOW'=>'TRUE', 'LOG_MODE'=>'FULL'), 'adding'=>Array());
		$sysVals = $nowMode['system'];
		$sysVals['ENV_NAME'] = strtoupper( $this->askAnswer('Environment (DEV, TEST, PREV, PROD)', strval($sysVals['ENV_NAME'] ?? 'TEST')) );
		$sysVals['ERR_LEVL'] = trim( $this->askAnswer('Errors reported (E_* names joined by |, & and ~)', strval($sysVals['ERR_LEVL'] ?? 'E_ALL')) );
		$sysVals['ERR_SHOW'] = strtoupper( $this->askAnswer('Show errors (TRUE, FALSE)', strval($sysVals['ERR_SHOW'] ?? 'TRUE')) );
		$sysVals['LOG_MODE'] = strtoupper( $this->askAnswer('Logging mode (FULL, BASE, MINI)', strval($sysVals['LOG_MODE'] ?? 'FULL')) );
		if( !preg_match(Config::RULELIST['ERR_LEVL'], $sysVals['ERR_LEVL']) ){
			$this->stopShell('Nothing was declared: ERR_LEVL is E_* names joined by |, & and ~', self::EXITDENY);
		}
		foreach( Array('ERR_SHOW'=>Array('TRUE', 'FALSE'), 'LOG_MODE'=>Array('FULL', 'BASE', 'MINI')) as $iniKey => $alwLst ){
			if( !in_array($sysVals[$iniKey], $alwLst, true) ){
				$this->stopShell('Nothing was declared: '.$iniKey.' is one of '.implode(', ', $alwLst), self::EXITDENY);
			}
		}
		$addText = $this->askAnswer('Paths the bundle adds, separated by commas (none for none)', empty($nowMode['adding']) ? 'none' : implode(', ', $nowMode['adding']));
		$addList = (strtolower(trim($addText))=='none') ? Array() : array_values(array_filter(array_map(function( String $givenEntry ): String { return trim(str_replace('\\', '/', $givenEntry), " /"); }, explode(',', $addText)), 'strlen'));
		$ownList = Array();
		foreach( $modeLst as $modeNm => $modVal ){
			if( !isset(self::MODELIST[$modeNm]) ){
				$ownList[$modeNm] = $modVal;
			}
		}
		$ownList[$modeNme] = Array('system'=>$sysVals, 'adding'=>$addList);
		// Checked as the file will be read, before it is written
		$this->readModes($ownList);
		$this->saveModes($ownList);
		$this->traceDone( 'Bundle: mode '.$modeNme.( $isKnown ? ' changed' : ' declared' ).' ('.$sysVals['ENV_NAME'].')' );
		$this->prntObjc->printPair( $isKnown ? 'mode changed' : 'mode declared', $modeNme );
		return self::EXITDONE;
	}

	/**
	 * Removes a mode the project declared. Those of the framework stay.
	 *
	 * @return Int The exit code.
	 */
	public function callRmpak(): Int {
		$modeNme = $this->needsWord('the mode');
		if( isset(self::MODELIST[$modeNme]) ){
			$this->stopShell('Nothing was removed: '.$modeNme.' is a mode of the framework', self::EXITDENY);
		}
		$ownList = Array();
		foreach( $this->readModes() as $modeNm => $modVal ){
			if( !isset(self::MODELIST[$modeNm]) ){
				$ownList[$modeNm] = $modVal;
			}
		}
		if( !isset($ownList[$modeNme]) ){
			$this->stopShell('Nothing was removed: the project declares no mode '.$modeNme, self::EXITDENY);
		}
		if( !$this->askAgreed('Remove the mode '.$modeNme) ){
			$this->stopShell('Nothing was removed: not confirmed', self::EXITDENY);
		}
		unset($ownList[$modeNme]);
		$this->saveModes($ownList);
		$this->traceDone( 'Bundle: mode '.$modeNme.' removed' );
		$this->prntObjc->printPair('mode removed', $modeNme);
		return self::EXITDONE;
	}

	/**
	 * Builds the bundle of a mode: in dist/<mode>, replaced when it is
	 * there (asked to be confirmed), or in the empty folder --place names.
	 * The settings of the mode are written in its system.ini; --archv
	 * makes its .tar.gz beside it, --plans only lists what would be copied.
	 *
	 * @param String $bundleMode A mode of the framework (MODELIST) or of the project.
	 * @return Int The exit code.
	 *
	 * @throws \nyu\Shell\ShellStop When the mode is unknown, the folder cannot be used, replacing it is not confirmed, or a file cannot be copied.
	 */
	protected function makeBundl( String $bundleMode ): Int {
		$modVals = $this->readModes()[$bundleMode] ?? null;
		if( $modVals===null ){
			$this->stopShell('No bundle made: there is no mode '.$bundleMode, self::EXITUSGE);
		}
		$gvnPlce = strval($this->optnObjc->getOption('place', ''));
		$distDir = TOP_DIR.DS.'dist'.DS.$bundleMode;
		$trgtDir = ($gvnPlce=='') ? $distDir : rtrim($gvnPlce, '/\\');
		if( !preg_match('#^([A-Za-z]:|[/\\\\])#', $trgtDir) ){
			$trgtDir = getcwd().DS.$trgtDir;
		}
		$trgtDir = str_replace(Array('/', '\\'), DS, $trgtDir);
		// A bundle never lands in what it copies : it would copy itself
		$fromTop = (strpos($trgtDir.DS, TOP_DIR.DS)===0) ? str_replace(DS, '/', substr($trgtDir, strlen(TOP_DIR)+1)) : '';
		$topList = array_merge( self::KEEPLIST, array_map(function( String $addedEntry ): String { return explode('/', $addedEntry)[0]; }, $modVals['adding']) );
		if( ($fromTop==='' && strpos($trgtDir.DS, TOP_DIR.DS)===0) || in_array(explode('/', $fromTop)[0], $topList, true) ){
			$this->stopShell('No bundle made: its folder cannot be the project, nor a folder the bundle copies', self::EXITUSGE);
		}
		if( $this->optnObjc->hasOption('plans') ){
			$this->classMaps($trgtDir, $bundleMode, false);
			return $this->listPlans($trgtDir, $modVals['adding']);
		}
		if( is_dir($trgtDir) && count((array)scandir($trgtDir))>2 ){
			if( $trgtDir!==$distDir ){
				$this->stopShell('No bundle made: '.$trgtDir.' is not empty; a folder given with --place must be', self::EXITDENY);
			}
			if( !$this->askAgreed('Replace the bundle in dist/'.$bundleMode) ){
				$this->stopShell('No bundle made: not confirmed', self::EXITDENY);
			}
			$treeItr = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator($trgtDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach( $treeItr as $oldItm ){
				$oldItm->isDir() ? @rmdir($oldItm->getPathname()) : @unlink($oldItm->getPathname());
			}
		}
		if( !is_dir($trgtDir) && !@mkdir($trgtDir, 0775, true) ){
			$this->stopShell('No bundle made: '.$trgtDir.' cannot be created');
		}
		$keptLst = $this->copyTrees($trgtDir, true, $modVals['adding'])['kept'];
		// The folders the framework writes in, empty
		foreach( Array('media', 'temp') as $fldNme ){
			@mkdir($trgtDir.DS.$fldNme, 0775, true);
		}
		$this->setsModes($trgtDir, $bundleMode);
		// Before the archive, which holds it
		$this->classMaps($trgtDir, $bundleMode, true);
		$this->prntObjc->printPair('mode', $bundleMode);
		$this->prntObjc->printPair('folder', $trgtDir);
		$this->prntObjc->printPair('files', count($keptLst));
		// Packages for development only would be in vendor, and so in the bundle
		$cmpVals = json_decode( strval(@file_get_contents(TOP_DIR.DS.'composer.json')), true );
		if( is_array($cmpVals) && !empty($cmpVals['require-dev']) ){
			$this->prntObjc->printFail('composer.json has packages for development: they are in vendor, the bundle cannot leave them out');
		}
		if( $this->optnObjc->hasOption('archv') ){
			$this->prntObjc->printPair('archive', $this->packArchv($trgtDir));
		}
		$this->traceDone( 'Bundle: '.$bundleMode.', '.count($keptLst).' files in '.$trgtDir.( $this->optnObjc->hasOption('archv') ? ', and its archive' : '' ) );
		$this->prntObjc->printLine('On its server: php nyuRun deploy start, then php nyuRun deploy check.');
		return self::EXITDONE;
	}

	/**
	 * Gives every mode: those of the framework, then those the project
	 * declares in conf/bundle.lst, or in the list given, which is checked
	 * as the file would be. A mode of the project is named with five
	 * lower-case letters or digits that no sub-command uses; its 'system'
	 * holds keys of system.ini, ENV_NAME one of the four environments;
	 * its 'adding' holds paths of the project, outside KEEPLIST or files
	 * inside it (a file left out otherwise, named to be copied), none of
	 * them hidden nor going up.
	 *
	 * @param ?Array $modesArray The modes of the project to check; those of conf/bundle.lst when null.
	 * @return Array Each mode, by name, with its 'system' settings and the paths it is 'adding'.
	 *
	 * @throws \nyu\Shell\ShellStop When conf/bundle.lst cannot be read, or a mode is not one.
	 */
	protected function readModes( ?Array $modesArray=null ): Array {
		$modeLst = Array();
		foreach( self::MODELIST as $modeNm => $iniArr ){
			$modeLst[$modeNm] = Array('system'=>$iniArr, 'adding'=>Array());
		}
		$lstPath = TOP_DIR.DS.str_replace('/', DS, self::BNDLFILE);
		if( $modesArray===null && !is_file($lstPath) ){
			return $modeLst;
		}
		$fileVal = $modesArray ?? json_decode(strval(@file_get_contents($lstPath)), true);
		if( !is_array($fileVal) ){
			$this->stopShell(self::BNDLFILE.' is not a JSON object of modes');
		}
		foreach( $fileVal as $modeNm => $modVal ){
			$modeNm = strval($modeNm);
			$sysVals = is_array($modVal) ? ($modVal['system'] ?? null) : null;
			$addVals = is_array($modVal) ? ($modVal['adding'] ?? Array()) : null;
			$whyText = '';
			if( !preg_match('/^[a-z0-9]{5}$/D', $modeNm) || method_exists($this, 'call'.ucfirst($modeNm)) ){
				$whyText = 'not five lower-case letters or digits, or the name of a sub-command';
			}elseif( !is_array($sysVals) || !in_array($sysVals['ENV_NAME'] ?? null, Array('DEV', 'TEST', 'PREV', 'PROD'), true) ){
				$whyText = 'its "system" has no ENV_NAME of DEV, TEST, PREV or PROD';
			}elseif( !is_array($addVals) ){
				$whyText = 'its "adding" is not a list of paths';
			}
			foreach( (array)$sysVals as $iniKey => $iniVal ){
				if( $whyText=='' && (!preg_match('/^[A-Z]{3}_[A-Z]{4}$/D', strval($iniKey)) || !is_scalar($iniVal) || preg_match('/["\r\n]/', strval($iniVal))) ){
					$whyText = 'its "system" holds what is not a key of system.ini with its value: '.strval($iniKey);
				}
			}
			foreach( (array)$addVals as $addPth ){
				$addPth = is_string($addPth) ? $addPth : '';
				// Inside what every bundle holds, only a file is named : one that is left out otherwise, such as the public key of a server issuing the tokens
				$inKeeps = in_array(explode('/', $addPth)[0], self::KEEPLIST, true);
				if( $whyText=='' && ( !preg_match('#^[\w\-]+(/[\w.\-]+)*$#D', $addPth) || preg_match('#(^|/)\.#', $addPth) || ($inKeeps && !is_file(TOP_DIR.DS.str_replace('/', DS, $addPth))) || !file_exists(TOP_DIR.DS.str_replace('/', DS, $addPth)) ) ){
					$whyText = 'it adds what is not a path of the project outside what every bundle holds, nor a file of it: '.$addPth;
				}
			}
			if( $whyText!='' ){
				$this->stopShell(self::BNDLFILE.': the mode '.$modeNm.' cannot be used: '.$whyText, self::EXITDENY);
			}
			$modeLst[$modeNm] = Array('system'=>array_map('strval', $sysVals), 'adding'=>array_values($addVals));
		}
		return $modeLst;
	}

	/**
	 * Writes the modes of the project in conf/bundle.lst, one value per
	 * line.
	 *
	 * @param Array $modesArray The modes of the project, by name, each with its 'system' and its 'adding'.
	 */
	protected function saveModes( Array $modesArray ): Void {
		$treeObj = new \stdClass();
		foreach( $modesArray as $modeNm => $modVal ){
			$treeObj->$modeNm = (object)Array('system'=>(object)$modVal['system'], 'adding'=>array_values($modVal['adding']));
		}
		(new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' ))->saveJsons( TOP_DIR.DS.str_replace('/', DS, self::BNDLFILE), $treeObj );
	}

	/**
	 * Tells whether a file of the project goes into a bundle: under one of
	 * KEEPLIST or a path the mode adds, not named by DROPLIST, and no part
	 * of its path hidden (starting with a dot), except a .htaccess file.
	 * Nothing of conf/keys travels, nor any key, users file or password
	 * file conf/access.json names, wherever it lies (see secrPaths): the
	 * server makes its own keys (deploy start). A mode brings one of them
	 * back only by naming that very file in its 'adding' paths, the public
	 * key of a server issuing the tokens for one.
	 *
	 * @param String $insidePath Path of the file from the project root, written with slashes.
	 * @param Array  $addedArray Paths the mode adds, from the project root.
	 * @return Bool Whether it is copied.
	 */
	protected function keepsPath( String $insidePath, Array $addedArray=Array() ): Bool {
		$partLst = explode('/', $insidePath);
		$isAdded = false;
		foreach( $addedArray as $addPth ){
			$isAdded = $isAdded || $insidePath===$addPth || strpos($insidePath, $addPth.'/')===0;
		}
		if( !$isAdded && !in_array($partLst[0], self::KEEPLIST, true) ){
			return false;
		}
		// A file the mode names itself is copied, whatever drops it otherwise: a folder it names brings back nothing dropped
		$isNamed = in_array($insidePath, $addedArray, true);
		foreach( self::DROPLIST as $dropPt ){
			if( !$isNamed && fnmatch($dropPt, $insidePath, FNM_PATHNAME) ){
				return false;
			}
		}
		if( !$isNamed && ( strpos($insidePath, 'conf/keys/')===0 || in_array($insidePath, $this->secrPaths(), true) ) ){
			return false;
		}
		foreach( $partLst as $partNm ){
			if( $partNm!=='' && $partNm[0]=='.' && $partNm!=='.htaccess' ){
				return false;
			}
		}
		return true;
	}

	/**
	 * Gives the paths of the secrets conf/access.json names, as written:
	 * the keys of the tokens ('prvkey', 'pubkey', 'oldpub' of any block),
	 * the public key of each application of 'apkeys', the users file
	 * ('usrfle') and the password file ('htpasswd'). Read once.
	 *
	 * @return Array The paths, from the project root, written with slashes.
	 */
	protected function secrPaths(): Array {
		static $pathLst = null;
		if( $pathLst!==null ){
			return $pathLst;
		}
		$pathLst = Array();
		$axsTree = json_decode( strval(@file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json')), true );
		$blkList = is_array($axsTree) ? $axsTree : Array();
		foreach( $blkList as $blkVal ){
			// The applications of the application keys hold a public key each
			foreach( (is_array($blkVal) && is_array($blkVal['applst'] ?? null)) ? $blkVal['applst'] : Array() as $appVal ){
				$blkList[] = is_array($appVal) ? Array('pubkey'=>$appVal['pubkey'] ?? '') : Array();
			}
		}
		foreach( $blkList as $blkVal ){
			foreach( Array('prvkey', 'pubkey', 'oldpub', 'usrfle', 'htpasswd') as $keyNme ){
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
	 * @param String $targetPath Full path of the folder of the bundle.
	 * @param Bool   $writeFiles Whether the files are copied, or only listed.
	 * @param Array  $addedArray Paths the mode adds, from the project root.
	 * @return Array The paths kept under 'kept', those left out under 'left': a folder of the root left out is named alone.
	 *
	 * @throws \nyu\Shell\ShellStop When a file cannot be copied.
	 */
	protected function copyTrees( String $targetPath, Bool $writeFiles, Array $addedArray=Array() ): Array {
		$pathLst = Array('kept'=>Array(), 'left'=>Array());
		$topList = array_merge( self::KEEPLIST, array_map(function( String $addedEntry ): String { return explode('/', $addedEntry)[0]; }, $addedArray) );
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
			foreach( $fileLst as $fleInf ){
				if( !$fleInf->isFile() ){
					continue;
				}
				$relPath = str_replace(DS, '/', substr($fleInf->getPathname(), strlen(TOP_DIR)+1));
				if( !$this->keepsPath($relPath, $addedArray) ){
					$pathLst['left'][] = $relPath;
					continue;
				}
				$pathLst['kept'][] = $relPath;
				if( !$writeFiles ){
					continue;
				}
				$destPth = $targetPath.DS.str_replace('/', DS, $relPath);
				if( !is_dir(dirname($destPth)) ){
					@mkdir(dirname($destPth), 0775, true);
				}
				if( !@copy($fleInf->getPathname(), $destPth) ){
					$this->stopShell('The bundle is not complete: '.$relPath.' cannot be copied');
				}
				@chmod($destPth, fileperms($fleInf->getPathname()) & 0777);
			}
		}
		sort($pathLst['kept']);
		sort($pathLst['left']);
		return $pathLst;
	}

	/**
	 * Writes the settings of the mode in the system.ini of the bundle.
	 *
	 * @param String $targetPath Full path of the folder of the bundle.
	 * @param String $bundleMode A mode of the framework or of the project.
	 *
	 * @throws \nyu\Shell\ShellStop When the bundle has no system.ini, or a setting is missing from it.
	 */
	protected function setsModes( String $targetPath, String $bundleMode ): Void {
		$iniPath = $targetPath.DS.'conf'.DS.'system.ini';
		if( !is_file($iniPath) ){
			$this->stopShell('The bundle has no conf/system.ini: the settings of the mode cannot be written');
		}
		$wrtrObj = new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' );
		foreach( $this->readModes()[$bundleMode]['system'] as $iniKey => $iniVal ){
			if( !$wrtrObj->setIniKey($iniPath, $iniKey, $iniVal) ){
				$this->stopShell('The bundle is not set for its mode: its system.ini has no '.$iniKey);
			}
		}
		// The config cache, as the class map, for the environments of MAPSENVS; a system.ini made before it is told, not refused
		if( !$wrtrObj->setIniKey($iniPath, 'CNF_CACH', in_array($this->readModes()[$bundleMode]['system']['ENV_NAME'] ?? '', self::MAPSENVS, true) ? 'TRUE' : 'FALSE') ){
			$this->prntObjc->printFail('The config is read on each request: the system.ini of the project has no CNF_CACH (see conf/system.ini of the framework)');
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
	 * @param String $targetPath Full path of the folder of the bundle.
	 * @param String $bundleMode A mode of the framework or of the project.
	 * @param Bool   $writeFiles Whether Composer is run, or only told about (--plans).
	 * @return Bool Whether the class map was made, or would be.
	 */
	protected function classMaps( String $targetPath, String $bundleMode, Bool $writeFiles ): Bool {
		if( !in_array($this->readModes()[$bundleMode]['system']['ENV_NAME'] ?? '', self::MAPSENVS, true) ){
			$this->prntObjc->printPair('class map', 'the one of the project, for its '.implode(' and ', self::MAPSENVS).' bundles only');
			return false;
		}
		$cmdList = $this->findCmpsr();
		if( $cmdList===null ){
			$this->prntObjc->printFail('The autoloader is not optimised: Composer was not found (--cmpsr=<path>); run composer dump-autoload -o -a --no-dev in the bundle');
			return false;
		}
		if( !$writeFiles ){
			$this->prntObjc->printPair('class map', 'complete, made by '.end($cmdList));
			return true;
		}
		$cmdList = array_merge( $cmdList, Array('dump-autoload', '--optimize', '--classmap-authoritative', '--no-dev', '--no-interaction', '--working-dir='.$targetPath) );
		$outText = Array();
		$exitCod = 0;
		exec( implode(' ', array_map('escapeshellarg', $cmdList)).' 2>&1', $outText, $exitCod );
		if( $exitCod!==0 ){
			$this->prntObjc->printFail('The autoloader is not optimised: Composer ended with '.$exitCod.( empty($outText) ? '' : ': '.trim(strval(end($outText))) ).'; run composer dump-autoload -o -a --no-dev in the bundle');
			return false;
		}
		$this->prntObjc->printPair('class map', 'complete');
		$this->traceDone( 'Bundle: '.$bundleMode.', class map made by Composer in '.$targetPath );
		return true;
	}

	/**
	 * Finds Composer: the file --cmpsr names, else composer.phar at the
	 * root of the project, else Composer on the PATH (composer.phar, or
	 * composer, composer.bat on Windows). A phar is run with the PHP
	 * running this command.
	 *
	 * @return ?Array The command that runs Composer, as a list of words; null when there is none.
	 */
	protected function findCmpsr(): ?Array {
		$cmpPath = strval($this->optnObjc->getOption('cmpsr', ''));
		if( $cmpPath!='' ){
			if( !is_file($cmpPath) ){
				return null;
			}
		}elseif( is_file(TOP_DIR.DS.'composer.phar') ){
			$cmpPath = TOP_DIR.DS.'composer.phar';
		}else{
			foreach( explode(PATH_SEPARATOR, strval(getenv('PATH'))) as $dir ){
				foreach( (DS=='\\') ? Array('composer.phar', 'composer.bat') : Array('composer.phar', 'composer') as $bin ){
					if( $cmpPath=='' && trim($dir)!='' && is_file(rtrim($dir, '/\\').DS.$bin) ){
						$cmpPath = rtrim($dir, '/\\').DS.$bin;
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
	 * @param String $targetPath Full path of the folder of the bundle.
	 * @return String Full path of the archive.
	 *
	 * @throws \nyu\Shell\ShellStop When the archive cannot be made.
	 */
	protected function packArchv( String $targetPath ): String {
		if( !class_exists('PharData') ){
			$this->stopShell('No archive made: the phar extension is not loaded');
		}
		$tarPath = $targetPath.'.tar';
		foreach( Array($tarPath, $tarPath.'.gz') as $oldArc ){
			if( is_file($oldArc) ){
				@unlink($oldArc);
			}
		}
		try{
			$archObj = new \PharData($tarPath);
			$archObj->buildFromDirectory($targetPath);
			$archObj->compress(\Phar::GZ);
			unset($archObj);
			@unlink($tarPath);
		}catch( \Throwable $e ){
			$this->stopShell('No archive made: '.$e->getMessage());
		}
		return $tarPath.'.gz';
	}

	/**
	 * Lists what a bundle would hold and what it would leave out, without
	 * writing anything.
	 *
	 * @param String $targetPath Full path of the folder the bundle would be made in.
	 * @param Array  $addedArray Paths the mode adds, from the project root.
	 * @return Int The exit code.
	 */
	protected function listPlans( String $targetPath, Array $addedArray=Array() ): Int {
		$pathLst = $this->copyTrees($targetPath, false, $addedArray);
		$rowList = Array();
		foreach( $pathLst['kept'] as $keptPt ){
			$rowList[] = Array($keptPt, 'copied');
		}
		foreach( $pathLst['left'] as $leftPt ){
			$rowList[] = Array($leftPt, 'left out');
		}
		$this->prntObjc->printPair('folder', $targetPath);
		$this->prntObjc->printRows( Array('path', 'state'), $rowList );
		return self::EXITDONE;
	}
}
