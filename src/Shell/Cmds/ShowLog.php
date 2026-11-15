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
	public function renderSynopsis(): String {
		return 'Shows the last lines of a log channel, and follows it';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The command has no sub-command.
	 */
	public function listSubCmndDct(): Array {
		return Array( ''=>Array('call'=>'invokeShowLogs', 'args'=>'<channel> [--numLines=<lines>] [--watching]', 'about'=>'shows the last lines of a log channel') );
	}

	/**
	 * Shows the last lines of a log channel, and follows it with --watching.
	 *
	 * @return Int The exit code.
	 */
	public function invokeShowLogs(): Int {
		$logrInst = $this->needsShellWord('the log channel (error, access, runner, shell, or the name of an application)');
		if( !preg_match('/^\w+$/D', $logrInst) ){
			$this->stopWithReason('A log channel is a plain name', self::EXIT_HELP);
		}
		$lineNumb = $this->optionsObj->getOptionValue('numLines', '20');
		if( !preg_match('/^\d{1,6}$/D', strval($lineNumb)) ){
			$this->stopWithReason('--numLines takes a number of lines', self::EXIT_HELP);
		}
		// With dated file names, the channel is read in its newest file
		$logTrail = '';
		foreach( (array)glob(strval( (new Loader(Array('settings'=>Array('confType'=>'all'))))->logConfigArray()['path'] ).DS.$logrInst.'*.log') as $oneFil ){
			$oneFil = strval($oneFil);
			if( preg_match('/^'.$logrInst.'(-[\dW_\-]+)?\.log$/D', basename($oneFil)) && ($logTrail=='' || filemtime($oneFil)>filemtime($logTrail)) ){
				$logTrail = $oneFil;
			}
		}
		if( $logTrail=='' ){
			$this->stopWithReason('No log for the channel '.$logrInst, self::EXIT_DENY);
		}
		$logBytes = filesize($logTrail);
		$readSize = min($logBytes, 2097152);
		$logStrng = strval( file_get_contents($logTrail, false, null, $logBytes-$readSize, $readSize) );
		$lineList = explode("\n", rtrim(str_replace("\r\n", "\n", $logStrng), "\n"));
		$lineList = ($logStrng=='' || intval($lineNumb)==0) ? Array() : array_slice($lineList, -intval($lineNumb));
		foreach( $lineList as $oneLne ){
			$this->printerObj->writeShellBody($oneLne.PHP_EOL);
		}
		if( !$this->optionsObj->isOptionPassed('watching') || $this->printerObj->isOutputAsJson() ){
			return self::EXIT_DONE;
		}
		// Followed until the command is interrupted
		$crsorPos = $logBytes;
		while( true ){
			usleep(500000);
			clearstatcache(true, $logTrail);
			$logBytes = is_file($logTrail) ? filesize($logTrail) : 0;
			if( $logBytes<$crsorPos ){
				// The file was rotated : it is read again from its start
				$crsorPos = 0;
			}
			if( $logBytes>$crsorPos ){
				$this->printerObj->writeShellBody( strval(file_get_contents($logTrail, false, null, $crsorPos, $logBytes-$crsorPos)) );
				$crsorPos = $logBytes;
			}
		}
	}
}
