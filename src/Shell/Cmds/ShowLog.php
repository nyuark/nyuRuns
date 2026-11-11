<?php

namespace nyu\Shell\Cmds;

use nyu\Setup\Loader;
use nyu\Shell\Command;

/**
 * Shows the last lines of a log channel, and follows it:
 *
 *   php nyuRun showlog access --numLines=50  the last 50 lines of the access channel
 *   php nyuRun showlog runner --watching     the last lines, then each line written, until interrupted
 */
class ShowLog extends Command{

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Shows the last lines of a log channel, and follows it';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The command has no sub-command.
	 */
	public function subsTableArray(): Array {
		return Array( ''=>Array('call'=>'invokeShowLogs', 'args'=>'<channel> [--numLines=<lines>] [--watching]', 'about'=>'shows the last lines of a log channel') );
	}

	/**
	 * Shows the last lines of a log channel, and follows it with --watching.
	 *
	 * @return Int The exit code.
	 */
	public function invokeShowLogs(): Int {
		$channel = $this->needsShellWord('the log channel (error, access, runner, shell, or the name of an application)');
		if( !preg_match('/^\w+$/D', $channel) ){
			$this->terminateShell('A log channel is a plain name', self::EXIT_HELP);
		}
		$lineNum = $this->optionsObj->retrieveOption('numLines', '20');
		if( !preg_match('/^\d{1,6}$/D', strval($lineNum)) ){
			$this->terminateShell('--numLines takes a number of lines', self::EXIT_HELP);
		}
		// With dated file names, the channel is read in its newest file
		$logPath = '';
		foreach( (array)glob(strval( (new Loader(Array('settings'=>Array('confType'=>'all'))))->logConfigArray()['path'] ).DS.$channel.'*.log') as $logFil ){
			$logFil = strval($logFil);
			if( preg_match('/^'.$channel.'(-[\dW_\-]+)?\.log$/D', basename($logFil)) && ($logPath=='' || filemtime($logFil)>filemtime($logPath)) ){
				$logPath = $logFil;
			}
		}
		if( $logPath=='' ){
			$this->terminateShell('No log for the channel '.$channel, self::EXIT_DENY);
		}
		$logSize = filesize($logPath);
		$readLen = min($logSize, 2097152);
		$logText = strval( file_get_contents($logPath, false, null, $logSize-$readLen, $readLen) );
		$lineLst = explode("\n", rtrim(str_replace("\r\n", "\n", $logText), "\n"));
		$lineLst = ($logText=='' || intval($lineNum)==0) ? Array() : array_slice($lineLst, -intval($lineNum));
		foreach( $lineLst as $logLin ){
			$this->printerObj->printShellBody($logLin.PHP_EOL);
		}
		if( !$this->optionsObj->validateOption('watching') || $this->printerObj->jsonShellShape() ){
			return self::EXIT_DONE;
		}
		// Followed until the command is interrupted
		$redPstn = $logSize;
		while( true ){
			usleep(500000);
			clearstatcache(true, $logPath);
			$logSize = is_file($logPath) ? filesize($logPath) : 0;
			if( $logSize<$redPstn ){
				// The file was rotated : it is read again from its start
				$redPstn = 0;
			}
			if( $logSize>$redPstn ){
				$this->printerObj->printShellBody( strval(file_get_contents($logPath, false, null, $redPstn, $logSize-$redPstn)) );
				$redPstn = $logSize;
			}
		}
	}
}
