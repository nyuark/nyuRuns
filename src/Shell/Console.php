<?php

namespace nyu\Shell;

use nyu\Setup\Loader;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

/**
 * The command line of the framework: reads its arguments, finds the
 * command they name, runs it and gives the exit code.
 *
 *   php nyuRun <command> <sub-command> [arguments] [--options]
 *
 * Options every command takes: --noask (no question is asked, the default
 * answers are taken), --agree (what removes or replaces is confirmed
 * beforehand), --shape=json (one JSON object, for a script to read),
 * --quiet (no remark), --usage (what the command does), --versn.
 */
class Console{

	const NYUVERSN = '1.0.0';
	// The framework's commands and their classes, under nyu\Shell\Cmds. Any other name is the one of an application (see findClass)
	const CMDSLIST = Array('deploy'=>'Deploy', 'config'=>'Config', 'tester'=>'Tester', 'cleans'=>'Cleans', 'manage'=>'Manage', 'device'=>'Device', 'bundle'=>'Bundle', 'appkey'=>'AppKey');

	protected $givnArgs;
	protected $outsStrm;
	protected $errsStrm;
	protected $inptStrm;

	/**
	 * Builds the console from the arguments of the command line and its streams.
	 *
	 * @param Array $givenWords The arguments of the command line, without the name of the script.
	 * @param mixed $outsStream Stream the output is written to; the standard output when null.
	 * @param mixed $errsStream Stream the questions and errors are written to; the standard error when null.
	 * @param mixed $inptStream Stream the answers are read from; the standard input when null.
	 */
	function __construct( Array $givenWords, Mixed $outsStream=null, Mixed $errsStream=null, Mixed $inptStream=null ){
		$this->givnArgs = $givenWords;
		$this->outsStrm = $outsStream;
		$this->errsStrm = $errsStream;
		$this->inptStrm = $inptStream;
	}

	/**
	 * Takes over PHP's warnings and notices for the time of the command:
	 * each is logged on the 'error' channel and told on the standard
	 * error, so that none is mixed with the output of the command.
	 */
	public function plugError(): Void {
		error_reporting(E_ALL);
		ini_set('display_errors', '0');
		set_error_handler( function( Int $errorLevel, String $errMessage, String $errFilePth='', Int $errLineNum=0 ): Bool {
			if( !(error_reporting() & $errorLevel) ){
				return true;
			}
			$fullMsg = $errMessage.' in '.$errFilePth.' on line '.$errLineNum;
			Logger::getLogger('error')->warning( 'Command line: '.$fullMsg );
			fwrite(STDERR, 'Warning: '.$fullMsg.PHP_EOL);
			return true;
		} );
	}

	/**
	 * Runs the command the arguments name. Without one, the commands are
	 * listed.
	 *
	 * @return Int The exit code: 0 done, 1 failed, 2 wrong usage, 3 refused or declined, 4 problems found.
	 */
	public function runCommnd(): Int {
		$optnObj = new Options($this->givnArgs);
		$prntObj = new Printer( $optnObj->getOption('shape', 'text')=='json', $optnObj->hasOption('quiet'), $this->outsStrm, $this->errsStrm );
		$exitNum = Command::EXITDONE;
		try{
			self::loadConfg();
			$cmdName = $optnObj->hasOption('versn') ? null : $optnObj->shiftWord();
			if( $optnObj->hasOption('versn') ){
				$prntObj->printPair('nyuRuns', self::NYUVERSN);
			}elseif( $cmdName===null ){
				$prntObj->printRows( Array('command', 'does'), $this->usageList($optnObj, $prntObj) );
			}else{
				$cmdClss = $this->findClass($cmdName);
				if( $cmdClss===null ){
					throw new ShellStop('Unknown command: '.$cmdName, Command::EXITUSGE);
				}
				$cmdObjc = new $cmdClss($optnObj, $prntObj, $this->inptStrm);
				$exitNum = $cmdObjc->runCalled();
			}
		}catch( ShellStop $e ){
			$prntObj->printFail( $e->getMessage() );
			$exitNum = intval($e->getCode()) ?: Command::EXITFAIL;
		}catch( \Throwable $e ){
			Logger::getLogger('error')->error( 'Command line: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().' on line '.$e->getLine() );
			$prntObj->printFail( 'Command failed: '.$e->getMessage() );
			$exitNum = Command::EXITFAIL;
		}
		$prntObj->closeOuts($exitNum);
		return $exitNum;
	}

	/**
	 * Reads the config files of the project, as a request does, and plugs
	 * the logs: what a command changes is traced like anything else. A
	 * project that is not set up yet has an empty config. The events are
	 * signed with the user of the system, written cli:<name>.
	 */
	public static function loadConfg(): Void {
		try{
			$loadObj = new Loader( Array('setup'=>Array('ftypes'=>'all')) );
			$GLOBALS['nyuConfig'] = $loadObj->getConfig();
			Logger::plugSetup( $loadObj->logConfig() );
		}catch( \Throwable $e ){
			$GLOBALS['nyuConfig'] = new Registry( Array() );
			Logger::plugSetup( Array('path'=>'') );
		}
		Logger::plugActor( 'cli:'.self::osUserNme(), '' );
	}

	/**
	 * Gives the name of the user of the system running the command.
	 *
	 * @return String The name, 'unknown' when the system tells none.
	 */
	public static function osUserNme(): String {
		$usrName = getenv('USERNAME') ?: ( getenv('USER') ?: get_current_user() );
		return preg_replace('/[^\w.\-]/', '_', strval($usrName)) ?: 'unknown';
	}

	/**
	 * Finds the class of a command: one of the framework's (see CMDSLIST),
	 * otherwise the one an application brings, named as the application
	 * in lower case: runs/<App>/Shell.php must declare the class Shell of
	 * nyu\Runs\<App>, extending Command.
	 *
	 * @param String $commndName Name of the command, as typed.
	 * @return ?String The class with its namespace, or null when there is no such command.
	 */
	protected function findClass( String $commndName ): ?String {
		if( isset(self::CMDSLIST[$commndName]) ){
			$fullCls = '\\nyu\\Shell\\Cmds\\'.self::CMDSLIST[$commndName];
			return class_exists($fullCls) ? $fullCls : null;
		}
		if( !preg_match('/^[a-z][a-z0-9]*$/D', $commndName) || !defined('RUN_DIR') ){
			return null;
		}
		$appName = ucfirst($commndName);
		$clsFile = RUN_DIR.DS.$appName.DS.'Shell.php';
		$fullCls = '\\nyu\\Runs\\'.$appName.'\\Shell';
		if( !is_file($clsFile) ){
			return null;
		}
		require_once $clsFile;
		return ( class_exists($fullCls, false) && is_subclass_of($fullCls, Command::class) ) ? $fullCls : null;
	}

	/**
	 * Lists the commands: the framework's, then those the applications bring.
	 *
	 * @param Options $optionsObj The options of the command line.
	 * @param Printer $printerObj Where the commands write.
	 * @return Array Rows of name and description.
	 */
	protected function usageList( Options $optionsObj, Printer $printerObj ): Array {
		$nameLst = array_keys(self::CMDSLIST);
		foreach( (array)glob(RUN_DIR.DS.'*'.DS.'Shell.php') as $clsFle ){
			$nameLst[] = strtolower( basename(dirname(strval($clsFle))) );
		}
		$rowList = Array();
		foreach( $nameLst as $cmdNme ){
			$cmdClss = $this->findClass($cmdNme);
			if( $cmdClss!==null ){
				$rowList[] = Array( $cmdNme, (new $cmdClss($optionsObj, $printerObj, $this->inptStrm))->aboutText() );
			}
		}
		return $rowList;
	}
}
