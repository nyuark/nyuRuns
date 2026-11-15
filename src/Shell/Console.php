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
 * Options every command takes: --noPrompt (no question is asked, the default
 * answers are taken), --yesToAll (what removes or replaces is confirmed
 * beforehand), --outputAs=json (one JSON object, for a script to read),
 * --silently (no remark), --helpText (what the command does), --versions.
 */
class Console{

	const NYUR_VERS = '1.0.0';
	// The framework's commands and their classes, under nyu\Shell\Cmds. Any other name is the one of an application (see findClass)
	const CMDS_LIST = Array('install'=>'Deploy', 'setting'=>'Config', 'testing'=>'Tester', 'cleanup'=>'Cleans', 'showlog'=>'ShowLog', 'cronjob'=>'CronJob', 'account'=>'Manage', 'devices'=>'Device', 'bundles'=>'Bundle', 'appkeys'=>'AppKey', 'makerun'=>'RunGen');

	protected $commandArr;
	protected $outsStream;
	protected $failStream;
	protected $readStream;

	/**
	 * Builds the console from the arguments of the command line and its streams.
	 *
	 * @param Array $commandWords The arguments of the command line, without the name of the script.
	 * @param mixed $outputStream Stream the output is written to; the standard output when null.
	 * @param mixed $errorsStream Stream the questions and errors are written to; the standard error when null.
	 * @param mixed $answerStream Stream the answers are read from; the standard input when null.
	 */
	function __construct( Array $commandWords, Mixed $outputStream=null, Mixed $errorsStream=null, Mixed $answerStream=null ){
		$this->commandArr = $commandWords;
		$this->outsStream = $outputStream;
		$this->failStream = $errorsStream;
		$this->readStream = $answerStream;
	}

	/**
	 * Takes over PHP's warnings and notices for the time of the command:
	 * each is logged on the 'error' channel and told on the standard
	 * error, so that none is mixed with the output of the command.
	 */
	public function plugShellError(): Void {
		error_reporting(E_ALL);
		ini_set('display_errors', '0');
		set_error_handler( function( Int $errLevelNumb, String $errorMessage, String $errorFileTxt='', Int $errorLineNum=0 ): Bool {
			if( !(error_reporting() & $errLevelNumb) ){
				return true;
			}
			$fullText = $errorMessage.' in '.$errorFileTxt.' on line '.$errorLineNum;
			Logger::retrieveLogger('error')->warning( 'Command line: '.$fullText );
			fwrite(STDERR, 'Warning: '.$fullText.PHP_EOL);
			return true;
		} );
	}

	/**
	 * Runs the command the arguments name. Without one, the commands are
	 * listed.
	 *
	 * @return Int The exit code: 0 done, 1 failed, 2 wrong usage, 3 refused or declined, 4 problems found.
	 */
	public function executeCommand(): Int {
		$optsInst = new Options($this->commandArr);
		$printObj = new Printer( $optsInst->retrieveOption('outputAs', 'text')=='json', $optsInst->validateOption('silently'), $this->outsStream, $this->failStream );
		$exitNumb = Command::EXIT_DONE;
		try{
			self::loadConfigLogs();
			$cmndText = $optsInst->validateOption('versions') ? null : $optsInst->shiftFirstWord();
			if( $optsInst->validateOption('versions') ){
				$printObj->printShellPair('nyuRuns', self::NYUR_VERS);
			}elseif( $cmndText===null ){
				$printObj->printShellRows( Array('command', 'does'), $this->usageShellList($optsInst, $printObj) );
			}else{
				$cmmnClss = $this->findClssString($cmndText);
				if( $cmmnClss===null ){
					throw new ShellStop('Unknown command: '.$cmndText, Command::EXIT_HELP);
				}
				$hndrInst = new $cmmnClss($optsInst, $printObj, $this->readStream);
				$exitNumb = $hndrInst->runCalledCount();
			}
		}catch( ShellStop $e ){
			$printObj->printShellFail( $e->getMessage() );
			$exitNumb = intval($e->getCode()) ?: Command::EXIT_FAIL;
		}catch( \Throwable $e ){
			Logger::retrieveLogger('error')->error( 'Command line: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().' on line '.$e->getLine() );
			$printObj->printShellFail( 'Command failed: '.$e->getMessage() );
			$exitNumb = Command::EXIT_FAIL;
		}
		$printObj->closeShellOuts($exitNumb);
		return $exitNumb;
	}

	/**
	 * Reads the config files of the project, as a request does, and plugs
	 * the logs: what a command changes is traced like anything else. A
	 * project that is not set up yet has an empty config. The events are
	 * signed with the user of the system, written cli:<name>.
	 */
	public static function loadConfigLogs(): Void {
		try{
			$loadInst = new Loader( Array('settings'=>Array('confType'=>'all')) );
			$GLOBALS['nyuConfig'] = $loadInst->retrieveConfig();
			Logger::plugLogsConfig( $loadInst->logConfigArray() );
		}catch( \Throwable $e ){
			$GLOBALS['nyuConfig'] = new Registry( Array() );
			Logger::plugLogsConfig( Array('path'=>'') );
		}
		Logger::setCurrentUser( 'cli:'.self::osShellUsrName(), '' );
	}

	/**
	 * Gives the name of the user of the system running the command.
	 *
	 * @return String The name, 'unknown' when the system tells none.
	 */
	public static function osShellUsrName(): String {
		$userName = getenv('USERNAME') ?: ( getenv('USER') ?: get_current_user() );
		return preg_replace('/[^\w.\-]/', '_', strval($userName)) ?: 'unknown';
	}

	/**
	 * Finds the class of a command: one of the framework's (see CMDSLIST),
	 * otherwise the one an application brings, named as the application
	 * in lower case: runs/<App>/Shell.php must declare the class Shell of
	 * nyu\Runs\<App>, extending Command.
	 *
	 * @param String $commandTyped Name of the command, as typed.
	 * @return ?String The class with its namespace, or null when there is no such command.
	 */
	protected function findClssString( String $commandTyped ): ?String {
		if( isset(self::CMDS_LIST[$commandTyped]) ){
			$fullClss = '\\nyu\\Shell\\Cmds\\'.self::CMDS_LIST[$commandTyped];
			return class_exists($fullClss) ? $fullClss : null;
		}
		if( !preg_match('/^[a-z][a-z0-9]*$/D', $commandTyped) || !defined('RUN_DIR') ){
			return null;
		}
		$applName = ucfirst($commandTyped);
		$classFle = RUN_DIR.DS.$applName.DS.'Shell.php';
		$fullClss = '\\nyu\\Runs\\'.$applName.'\\Shell';
		if( !is_file($classFle) ){
			return null;
		}
		require_once $classFle;
		return ( class_exists($fullClss, false) && is_subclass_of($fullClss, Command::class) ) ? $fullClss : null;
	}

	/**
	 * Lists the commands: the framework's, then those the applications bring.
	 *
	 * @param Options $givenOptions The options of the command line.
	 * @param Printer $givenPrinter Where the commands write.
	 * @return Array Rows of name and description.
	 */
	protected function usageShellList( Options $givenOptions, Printer $givenPrinter ): Array {
		$nameList = array_keys(self::CMDS_LIST);
		foreach( (array)glob(RUN_DIR.DS.'*'.DS.'Shell.php') as $clsFil ){
			$nameList[] = strtolower( basename(dirname(strval($clsFil))) );
		}
		$rowsList = Array();
		foreach( $nameList as $oneCmd ){
			$cmmnClss = $this->findClssString($oneCmd);
			if( $cmmnClss!==null ){
				$rowsList[] = Array( $oneCmd, (new $cmmnClss($givenOptions, $givenPrinter, $this->readStream))->describeInLine() );
			}
		}
		return $rowsList;
	}
}
