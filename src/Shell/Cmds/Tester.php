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
	const GRPS_LIST = Array('unit', 'audit', 'data');
	// Extensions given to the tests when PHP does not load them by itself
	const ADDS_LIST = Array('pdo_sqlite', 'pdo_mysql', 'pdo_pgsql', 'mongodb', 'mbstring', 'gd', 'exif', 'zip', 'openssl', 'curl', 'fileinfo');

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Runs the tests: the unit, audit and data groups, or the groups named';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The command has no sub-command.
	 */
	public function subsTableArray(): Array {
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
			$this->terminateShell('The test runner is missing: test/run.php');
		}
		$grpList = $this->optnObject->restWordsArray();
		if( empty($grpList) ){
			$grpList = self::GRPS_LIST;
		}elseif( in_array('all', $grpList, true) ){
			$grpList = Array();
		}
		$envVals = getenv();
		$iniFold = $this->newAddonString();
		if( $iniFold!==null ){
			$envVals['PHPRC'] = $iniFold;
		}
		$pipeArr = Array();
		$process = proc_open( array_merge(Array(PHP_BINARY, $runFile), $grpList), Array(0=>Array('pipe', 'r'), 1=>Array('pipe', 'w'), 2=>Array('redirect', 1)), $pipeArr, TOP_DIR, $envVals );
		if( !is_resource($process) ){
			$this->terminateShell('The tests cannot be started');
		}
		fclose($pipeArr[0]);
		$lastLin = '';
		$sumLine = '';
		while( ($outLine = fgets($pipeArr[1]))!==false ){
			// In JSON only the result is told; in text the tests are followed as they run
			if( !$this->printerObj->jsonShellShape() ){
				$this->printerObj->printShellBody($outLine);
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
		if( $iniFold!==null ){
			@unlink($iniFold.DS.'php.ini');
			@rmdir($iniFold);
		}
		$summary = Array();
		if( preg_match('/^(\d+) checks passed, (\d+) failed(?:, (\d+) skipped)?/', $sumLine, $summary) && $this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellPair('passed', intval($summary[1]));
			$this->printerObj->printShellPair('failed', intval($summary[2]));
			$this->printerObj->printShellPair('skipped', intval($summary[3] ?? 0));
			if( strpos($lastLin, 'Failing scripts:')===0 ){
				$this->printerObj->printShellPair('failing', array_map('trim', explode(',', substr($lastLin, 16))));
			}
		}
		if( $sumLine=='' ){
			$this->terminateShell('The tests ended without a result');
		}
		return ($exitNum===0) ? self::EXIT_DONE : self::EXIT_FLAW;
	}

	/**
	 * Writes the php.ini of the run: the one PHP loaded, when there is
	 * one, followed by the extensions to add. The processes the tests
	 * start themselves read it too, which options given to this one would
	 * not reach.
	 *
	 * @return ?String The folder holding that php.ini, to name in PHPRC; null when nothing is to add.
	 */
	protected function newAddonString(): ?String {
		$askList = $this->optnObject->validateOption('addon') ? array_filter(array_map('trim', explode(',', strval($this->optnObject->retrieveOption('addon', ''))))) : self::ADDS_LIST;
		$extFold = strval(ini_get('extension_dir'));
		if( $extFold=='' || !is_dir($extFold) ){
			$extFold = dirname(PHP_BINARY).DS.'ext';
		}
		$addList = Array();
		foreach( $askList as $oneExt ){
			if( !preg_match('/^\w+$/D', $oneExt) || extension_loaded($oneExt) ){
				continue;
			}
			if( is_file($extFold.DS.'php_'.$oneExt.'.dll') || is_file($extFold.DS.$oneExt.'.so') ){
				$addList[] = $oneExt;
			}
		}
		if( empty($addList) ){
			return null;
		}
		$iniFold = sys_get_temp_dir().DS.'nyuruns_tester_'.getmypid();
		if( !is_dir($iniFold) && !@mkdir($iniFold, 0700, true) ){
			return null;
		}
		$loadIni = php_ini_loaded_file();
		$iniText = is_string($loadIni) ? strval(@file_get_contents($loadIni)).PHP_EOL : '';
		$iniText .= 'extension_dir="'.$extFold.'"'.PHP_EOL;
		foreach( $addList as $oneExt ){
			$iniText .= 'extension='.$oneExt.PHP_EOL;
		}
		if( @file_put_contents($iniFold.DS.'php.ini', $iniText)===false ){
			return null;
		}
		$this->printerObj->printShellLine( 'Extensions given for the run: '.implode(', ', $addList) );
		return $iniFold;
	}
}
