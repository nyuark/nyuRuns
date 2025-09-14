<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;

/**
 * Runs the test suite (test/run.php):
 *
 *   php nyuRun tester                 the unit, audit and data groups
 *   php nyuRun tester http            the named groups, or the scripts whose name holds the word
 *   php nyuRun tester all             every group, http included
 *
 * The extensions the tests use and this PHP does not load are given for
 * the run, when their files are in the extension folder: --addon=a,b
 * names them, in place of the usual ones (see ADDNLIST).
 */
class Tester extends Command{

	// Groups run when none is named: the http group starts a web server, and is only run on request
	const GRPSLIST = Array('unit', 'audit', 'data');
	// Extensions given to the tests when PHP does not load them by itself
	const ADDNLIST = Array('pdo_sqlite', 'pdo_mysql', 'pdo_pgsql', 'mongodb', 'mbstring', 'gd', 'exif', 'zip', 'openssl', 'curl', 'fileinfo');

	/**
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Runs the tests: the unit, audit and data groups, or the groups named';
	}

	/**
	 * @return Array The command has no sub-command.
	 */
	public function subsTable(): Array {
		return Array( ''=>Array('call'=>'callTests', 'args'=>'[groups or words | all] [--addon=<extensions>]', 'about'=>'runs test/run.php; the http group only when named') );
	}

	/**
	 * Runs the tests in a process of their own, and tells their result.
	 *
	 * @return Int EXITDONE when every check passed, EXITFLAW when one failed.
	 */
	public function callTests(): Int {
		$runFile = TOP_DIR.DS.'test'.DS.'run.php';
		if( !is_file($runFile) ){
			$this->stopShell('The test runner is missing: test/run.php');
		}
		$grpList = $this->optnObjc->restWords();
		if( empty($grpList) ){
			$grpList = self::GRPSLIST;
		}elseif( in_array('all', $grpList, true) ){
			$grpList = Array();
		}
		$envVals = getenv();
		$iniFldr = $this->makeAddon();
		if( $iniFldr!==null ){
			$envVals['PHPRC'] = $iniFldr;
		}
		$pipeArr = Array();
		$process = proc_open( array_merge(Array(PHP_BINARY, $runFile), $grpList), Array(0=>Array('pipe', 'r'), 1=>Array('pipe', 'w'), 2=>Array('redirect', 1)), $pipeArr, TOP_DIR, $envVals );
		if( !is_resource($process) ){
			$this->stopShell('The tests cannot be started');
		}
		fclose($pipeArr[0]);
		$lastLin = '';
		$sumLine = '';
		while( ($outLine = fgets($pipeArr[1]))!==false ){
			// In JSON only the result is told; in text the tests are followed as they run
			if( !$this->prntObjc->jsonShape() ){
				$this->prntObjc->printBody($outLine);
			}
			if( trim($outLine)!='' ){
				$lastLin = trim($outLine);
			}
			if( preg_match('/^\d+ checks passed/', trim($outLine)) ){
				$sumLine = trim($outLine);
			}
		}
		fclose($pipeArr[1]);
		$exitNum = proc_close($process);
		if( $iniFldr!==null ){
			@unlink($iniFldr.DS.'php.ini');
			@rmdir($iniFldr);
		}
		$sumVals = Array();
		if( preg_match('/^(\d+) checks passed, (\d+) failed(?:, (\d+) skipped)?/', $sumLine, $sumVals) && $this->prntObjc->jsonShape() ){
			$this->prntObjc->printPair('passed', intval($sumVals[1]));
			$this->prntObjc->printPair('failed', intval($sumVals[2]));
			$this->prntObjc->printPair('skipped', intval($sumVals[3] ?? 0));
			if( strpos($lastLin, 'Failing scripts:')===0 ){
				$this->prntObjc->printPair('failing', array_map('trim', explode(',', substr($lastLin, 16))));
			}
		}
		if( $sumLine=='' ){
			$this->stopShell('The tests ended without a result');
		}
		return ($exitNum===0) ? self::EXITDONE : self::EXITFLAW;
	}

	/**
	 * Writes the php.ini of the run: the one PHP loaded, when there is
	 * one, followed by the extensions to add. The processes the tests
	 * start themselves read it too, which options given to this one would
	 * not reach.
	 *
	 * @return ?String The folder holding that php.ini, to name in PHPRC; null when nothing is to add.
	 */
	protected function makeAddon(): ?String {
		$askList = $this->optnObjc->hasOption('addon') ? array_filter(array_map('trim', explode(',', strval($this->optnObjc->getOption('addon', ''))))) : self::ADDNLIST;
		$extFldr = strval(ini_get('extension_dir'));
		if( $extFldr=='' || !is_dir($extFldr) ){
			$extFldr = dirname(PHP_BINARY).DS.'ext';
		}
		$addList = Array();
		foreach( $askList as $extNme ){
			if( !preg_match('/^\w+$/D', $extNme) || extension_loaded($extNme) ){
				continue;
			}
			if( is_file($extFldr.DS.'php_'.$extNme.'.dll') || is_file($extFldr.DS.$extNme.'.so') ){
				$addList[] = $extNme;
			}
		}
		if( empty($addList) ){
			return null;
		}
		$iniFldr = sys_get_temp_dir().DS.'nyuruns_tester_'.getmypid();
		if( !is_dir($iniFldr) && !@mkdir($iniFldr, 0700, true) ){
			return null;
		}
		$loadIni = php_ini_loaded_file();
		$iniText = is_string($loadIni) ? strval(@file_get_contents($loadIni)).PHP_EOL : '';
		$iniText .= 'extension_dir="'.$extFldr.'"'.PHP_EOL;
		foreach( $addList as $extNme ){
			$iniText .= 'extension='.$extNme.PHP_EOL;
		}
		if( @file_put_contents($iniFldr.DS.'php.ini', $iniText)===false ){
			return null;
		}
		$this->prntObjc->printLine( 'Extensions given for the run: '.implode(', ', $addList) );
		return $iniFldr;
	}
}
