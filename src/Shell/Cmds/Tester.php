<?php

namespace nyu\Shell\Cmds;

use nyu\Shell\Command;

/**
 * Runs the test suite (test/run.php):
 *
 *   php nyuRun testing       the unit, audit and data groups
 *   php nyuRun testing http  the named groups, or the scripts whose name holds the word
 *   php nyuRun testing all   every group, http included
 *
 * The extensions the tests use and this PHP does not load are given for
 * the run, when their files are in the extension folder: --addExtns=a,b
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
	public function renderSynopsis(): String {
		return 'Runs the tests: the unit, audit and data groups, or the groups named';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The command has no sub-command.
	 */
	public function listSubCmndDct(): Array {
		return Array( ''=>Array('call'=>'invokeRunTests', 'args'=>'[groups or words | all] [--addExtns=<extensions>]', 'about'=>'runs test/run.php; the http group only when named') );
	}

	/**
	 * Runs the tests in a process of their own, and tells their result.
	 *
	 * @return Int EXITDONE when every check passed, EXITFLAW when one failed.
	 */
	public function invokeRunTests(): Int {
		$runFichr = TOP_DIR.DS.'test'.DS.'run.php';
		if( !is_file($runFichr) ){
			$this->stopWithReason('The test runner is missing: test/run.php');
		}
		$groupLst = $this->optionsObj->getRestOfWords();
		if( empty($groupLst) ){
			$groupLst = self::GRPS_LIST;
		}elseif( in_array('all', $groupLst, true) ){
			$groupLst = Array();
		}
		$envrVals = getenv();
		$iniFoldr = $this->writeRunPhpIni();
		if( $iniFoldr!==null ){
			$envrVals['PHPRC'] = $iniFoldr;
		}
		$pipeList = Array();
		$procHndl = proc_open( array_merge(Array(PHP_BINARY, $runFichr), $groupLst), Array(0=>Array('pipe', 'r'), 1=>Array('pipe', 'w'), 2=>Array('redirect', 1)), $pipeList, TOP_DIR, $envrVals );
		if( !is_resource($procHndl) ){
			$this->stopWithReason('The tests cannot be started');
		}
		fclose($pipeList[0]);
		$lastLine = '';
		$smryLine = '';
		while( ($outLigne = fgets($pipeList[1]))!==false ){
			// In JSON only the result is told; in text the tests are followed as they run
			if( !$this->printerObj->isOutputAsJson() ){
				$this->printerObj->writeShellBody($outLigne);
			}
			if( trim($outLigne)!='' ){
				$lastLine = trim($outLigne);
			}
			if( preg_match('/^\d+ checks passed/', trim($outLigne)) ){
				$smryLine = trim($outLigne);
			}
		}
		fclose($pipeList[1]);
		$exitNumb = proc_close($procHndl);
		if( $iniFoldr!==null ){
			@unlink($iniFoldr.DS.'php.ini');
			@rmdir($iniFoldr);
		}
		$smryList = Array();
		if( preg_match('/^(\d+) checks passed, (\d+) failed(?:, (\d+) skipped)?/', $smryLine, $smryList) && $this->printerObj->isOutputAsJson() ){
			$this->printerObj->writeShellPair('passed', intval($smryList[1]));
			$this->printerObj->writeShellPair('failed', intval($smryList[2]));
			$this->printerObj->writeShellPair('skipped', intval($smryList[3] ?? 0));
			if( strpos($lastLine, 'Failing scripts:')===0 ){
				$this->printerObj->writeShellPair('failing', array_map('trim', explode(',', substr($lastLine, 16))));
			}
		}
		if( $smryLine=='' ){
			$this->stopWithReason('The tests ended without a result');
		}
		return ($exitNumb===0) ? self::EXIT_DONE : self::EXIT_FLAW;
	}

	/**
	 * Writes the php.ini of the run: the one PHP loaded, when there is
	 * one, followed by the extensions to add. The processes the tests
	 * start themselves read it too, which options given to this one would
	 * not reach.
	 *
	 * @return ?String The folder holding that php.ini, to name in PHPRC; null when nothing is to add.
	 */
	protected function writeRunPhpIni(): ?String {
		$askLstng = $this->optionsObj->isOptionPassed('addExtns') ? array_filter(array_map('trim', explode(',', strval($this->optionsObj->getOptionValue('addExtns', ''))))) : self::ADDS_LIST;
		$extnFldr = strval(ini_get('extension_dir'));
		if( $extnFldr=='' || !is_dir($extnFldr) ){
			$extnFldr = dirname(PHP_BINARY).DS.'ext';
		}
		$addLstng = Array();
		foreach( $askLstng as $oneExt ){
			if( !preg_match('/^\w+$/D', $oneExt) || extension_loaded($oneExt) ){
				continue;
			}
			if( is_file($extnFldr.DS.'php_'.$oneExt.'.dll') || is_file($extnFldr.DS.$oneExt.'.so') ){
				$addLstng[] = $oneExt;
			}
		}
		if( empty($addLstng) ){
			return null;
		}
		$iniFoldr = sys_get_temp_dir().DS.'nyuruns_tester_'.getmypid();
		if( !is_dir($iniFoldr) && !@mkdir($iniFoldr, 0700, true) ){
			return null;
		}
		$iniFichr = php_ini_loaded_file();
		$iniStrng = is_string($iniFichr) ? strval(@file_get_contents($iniFichr)).PHP_EOL : '';
		$iniStrng .= 'extension_dir="'.$extnFldr.'"'.PHP_EOL;
		foreach( $addLstng as $oneExt ){
			$iniStrng .= 'extension='.$oneExt.PHP_EOL;
		}
		if( @file_put_contents($iniFoldr.DS.'php.ini', $iniStrng)===false ){
			return null;
		}
		$this->printerObj->writeShellLine( 'Extensions given for the run: '.implode(', ', $addLstng) );
		return $iniFoldr;
	}
}
