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
	const CMDS_LIST = Array('installs'=>'Deploy', 'settings'=>'Config', 'runTests'=>'Tester', 'maintain'=>'Cleans', 'accounts'=>'Manage', 'machines'=>'Device', 'bundling'=>'Bundle', 'appsKeys'=>'AppKey');

	protected $gvnArgmnts;
	protected $outsStream;
	protected $errorsStrm;
	protected $inptStream;

	/**
	 * Builds the console from the arguments of the command line and its streams.
	 *
	 * @param Array $commandWords The arguments of the command line, without the name of the script.
	 * @param mixed $outputStream Stream the output is written to; the standard output when null.
	 * @param mixed $errorsStream Stream the questions and errors are written to; the standard error when null.
	 * @param mixed $answerStream Stream the answers are read from; the standard input when null.
	 */
	function __construct( Array $commandWords, Mixed $outputStream=null, Mixed $errorsStream=null, Mixed $answerStream=null ){
		$this->gvnArgmnts = $commandWords;
		$this->outsStream = $outputStream;
		$this->errorsStrm = $errorsStream;
		$this->inptStream = $answerStream;
	}

	/**
	 * Takes over PHP's warnings and notices for the time of the command:
	 * each is logged on the 'error' channel and told on the standard
	 * error, so that none is mixed with the output of the command.
	 */
	public function plugShellError(): Void {
		error_reporting(E_ALL);
		ini_set('display_errors', '0');
		set_error_handler( function( Int $errLevelNumb, String $errorMessage, String $errorFilPath='', Int $errorLineNum=0 ): Bool {
			if( !(error_reporting() & $errLevelNumb) ){
				return true;
			}
			$fullMsg = $errorMessage.' in '.$errorFilPath.' on line '.$errorLineNum;
			Logger::retrieveLogger('error')->warning( 'Command line: '.$fullMsg );
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
	public function executeCommand(): Int {
		$optnObj = new Options($this->gvnArgmnts);
		$printer = new Printer( $optnObj->retrieveOption('outputAs', 'text')=='json', $optnObj->validateOption('silently'), $this->outsStream, $this->errorsStrm );
		$exitNum = Command::EXIT_DONE;
		try{
			self::loadConfigLogs();
			$command = $optnObj->validateOption('versions') ? null : $optnObj->shiftFirstWord();
			if( $optnObj->validateOption('versions') ){
				$printer->printShellPair('nyuRuns', self::NYUR_VERS);
			}elseif( $command===null ){
				$printer->printShellRows( Array('command', 'does'), $this->usageShellList($optnObj, $printer) );
			}else{
				$cmdClss = $this->findClssString($command);
				if( $cmdClss===null ){
					throw new ShellStop('Unknown command: '.$command, Command::EXIT_HELP);
				}
				$handler = new $cmdClss($optnObj, $printer, $this->inptStream);
				$exitNum = $handler->runCalledCount();
			}
		}catch( ShellStop $e ){
			$printer->printShellFail( $e->getMessage() );
			$exitNum = intval($e->getCode()) ?: Command::EXIT_FAIL;
		}catch( \Throwable $e ){
			Logger::retrieveLogger('error')->error( 'Command line: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().' on line '.$e->getLine() );
			$printer->printShellFail( 'Command failed: '.$e->getMessage() );
			$exitNum = Command::EXIT_FAIL;
		}
		$printer->closeShellOuts($exitNum);
		return $exitNum;
	}

	/**
	 * Reads the config files of the project, as a request does, and plugs
	 * the logs: what a command changes is traced like anything else. A
	 * project that is not set up yet has an empty config. The events are
	 * signed with the user of the system, written cli:<name>.
	 */
	public static function loadConfigLogs(): Void {
		try{
			$loadObj = new Loader( Array('setup'=>Array('ftypes'=>'all')) );
			$GLOBALS['nyuConfig'] = $loadObj->retrieveConfig();
			Logger::plugLogsConfig( $loadObj->logConfigArray() );
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
		$usrName = getenv('USERNAME') ?: ( getenv('USER') ?: get_current_user() );
		return preg_replace('/[^\w.\-]/', '_', strval($usrName)) ?: 'unknown';
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
			$fullCls = '\\nyu\\Shell\\Cmds\\'.self::CMDS_LIST[$commandTyped];
			return class_exists($fullCls) ? $fullCls : null;
		}
		if( !preg_match('/^[a-z][a-z0-9]*$/D', $commandTyped) || !defined('RUN_DIR') ){
			return null;
		}
		$appName = ucfirst($commandTyped);
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
	 * @param Options $optinsObject The options of the command line.
	 * @param Printer $printrObject Where the commands write.
	 * @return Array Rows of name and description.
	 */
	protected function usageShellList( Options $optinsObject, Printer $printrObject ): Array {
		$nameLst = array_keys(self::CMDS_LIST);
		foreach( (array)glob(RUN_DIR.DS.'*'.DS.'Shell.php') as $clsFil ){
			$nameLst[] = strtolower( basename(dirname(strval($clsFil))) );
		}
		$rowList = Array();
		foreach( $nameLst as $oneCmd ){
			$cmdClss = $this->findClssString($oneCmd);
			if( $cmdClss!==null ){
				$rowList[] = Array( $oneCmd, (new $cmdClss($optinsObject, $printrObject, $this->inptStream))->describeInLine() );
			}
		}
		return $rowList;
	}
}
