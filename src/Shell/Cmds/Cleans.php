<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Limiter;
use nyu\Setup\Loader;
use nyu\Shell\Command;

/**
 * Cleans what the framework leaves in the temp folder, and shows it:
 *
 *   php nyuRun cleanup cleanDir logs --keepDays=30    removes the log files older than 30 days
 *   php nyuRun cleanup cleanDir all                   removes every temporary file
 *   php nyuRun cleanup tailLogs access --numLines=50  shows the last lines of a log channel (--watching follows it)
 *   php nyuRun cleanup fullList                       lists the addresses the limiter counts or holds
 *   php nyuRun cleanup freeAddr 203.0.113.7           releases an address (or all)
 *
 * Nothing is ever removed outside the temp folder of the project.
 */
class Cleans extends Command{

	// What can be purged: the folders of temp, by their name
	const KIND_LIST = Array('cacheDir', 'logFiles', 'tryLimit', 'archives');

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Removes temporary files and logs, shows a log, lists and releases the held addresses';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
		return Array(
			'cleanDir'=>Array('args'=>'<cacheDir|logFiles|tryLimit|archives|all> ... [--keepDays=<days>]', 'about'=>'removes the files of those folders of temp'),
			'tailLogs'=>Array('args'=>'<channel> [--numLines=<lines>] [--watching]', 'about'=>'shows the last lines of a log channel'),
			'fullList'=>Array('args'=>'', 'about'=>'lists the addresses the limiter counts or holds'),
			'freeAddr'=>Array('args'=>'<address|all>', 'about'=>'releases an address, or all of them')
		);
	}

	/**
	 * Removes the files of the folders named, all of them or those older
	 * than --keepDays days. The folders themselves are kept.
	 *
	 * @return Int The exit code.
	 */
	public function invokeCleanDir(): Int {
		$kindLst = $this->optionsObj->restWordsArray();
		if( empty($kindLst) ){
			$this->terminateShell('Missing argument: what to purge ('.implode(', ', self::KIND_LIST).', or all)', self::EXIT_HELP);
		}
		if( in_array('all', $kindLst, true) ){
			$kindLst = self::KIND_LIST;
		}
		$badList = array_diff($kindLst, self::KIND_LIST);
		if( !empty($badList) ){
			$this->terminateShell('Nothing is purged under that name: '.implode(', ', $badList), self::EXIT_HELP);
		}
		$oldDays = $this->optionsObj->retrieveOption('keepDays');
		if( $oldDays!==null && !preg_match('/^\d{1,5}$/D', $oldDays) ){
			$this->terminateShell('--keepDays takes a number of days', self::EXIT_HELP);
		}
		$upToTim = ($oldDays===null) ? null : time() - intval($oldDays)*86400;
		$kindLst = array_values(array_unique($kindLst));
		if( !$this->queryAgreedFlg('Remove the files of '.implode(', ', $kindLst).( $oldDays!==null ? ' older than '.$oldDays.' days' : '' )) ){
			$this->terminateShell('Nothing was removed: not confirmed', self::EXIT_DENY);
		}
		$rowList = Array();
		foreach( $kindLst as $kndNme ){
			$fldPath = $this->verifyTempPath( $this->findTempFolder($kndNme) );
			if( $fldPath===null ){
				$rowList[] = Array($kndNme, 0, 'left alone: its folder is missing, or not under temp');
				continue;
			}
			$dropNum = $this->dropFilesCount($fldPath, $upToTim);
			$rowList[] = Array($kndNme, $dropNum, 'purged');
			$this->traceShellDone( 'Purge of '.$kndNme.': '.$dropNum.' files removed'.( $oldDays!==null ? ', older than '.$oldDays.' days' : '' ) );
		}
		$this->printerObj->printShellRows( Array('folder', 'removed', 'state'), $rowList );
		return self::EXIT_DONE;
	}

	/**
	 * Shows the last lines of a log channel, and follows it with --watching.
	 *
	 * @return Int The exit code.
	 */
	public function invokeTailLogs(): Int {
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
		foreach( (array)glob($this->findTempFolder('logFiles').DS.$channel.'*.log') as $logFil ){
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

	/**
	 * Lists the addresses the limiter has a counter for, held or not.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$lmtrObj = new Limiter( $this->accessSettings('tryLimit') );
		$rowList = Array();
		foreach( $lmtrObj->listLimitHolds() as $cntVal ){
			$rowList[] = Array(
				($cntVal['addrs']!='') ? $cntVal['addrs'] : '(not told)',
				$cntVal['fails'],
				date('Y-m-d H:i:s', $cntVal['stamp']),
				($cntVal['until']>0) ? date('Y-m-d H:i:s', $cntVal['until']) : ''
			);
		}
		$this->printerObj->printShellRows( Array('address', 'failures', 'last', 'held until'), $rowList );
		if( empty($rowList) ){
			$this->printerObj->printShellLine('No address is counted.');
		}
		return self::EXIT_DONE;
	}

	/**
	 * Releases an address, or all of them: its failures are forgotten.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFreeAddr(): Int {
		$gvnAddr = $this->needsShellWord('the address to release, or all');
		if( $gvnAddr!='all' && filter_var($gvnAddr, FILTER_VALIDATE_IP)===false ){
			$this->terminateShell('Not an address: '.$gvnAddr, self::EXIT_HELP);
		}
		if( !$this->queryAgreedFlg('Release '.( $gvnAddr=='all' ? 'every address' : $gvnAddr )) ){
			$this->terminateShell('Nothing was released: not confirmed', self::EXIT_DENY);
		}
		$lmtrObj = new Limiter( $this->accessSettings('tryLimit') );
		$dropNum = $lmtrObj->freeHoldsCount( $gvnAddr=='all' ? null : $gvnAddr );
		$this->printerObj->printShellPair('released', $dropNum);
		if( $dropNum>0 ){
			$this->traceShellDone( 'Limiter: '.( $gvnAddr=='all' ? $dropNum.' addresses released' : 'address '.$gvnAddr.' released' ) );
		}
		return self::EXIT_DONE;
	}

	/**
	 * Gives the folder of a kind of temporary files, as the config sets it.
	 *
	 * @param String $tempFileKind One of KIND_LIST.
	 * @return String The full path of the folder.
	 */
	protected function findTempFolder( String $tempFileKind ): String {
		if( $tempFileKind=='logFiles' ){
			return strval( (new Loader(Array('settings'=>Array('confType'=>'all'))))->logConfigArray()['path'] );
		}
		if( $tempFileKind=='tryLimit' ){
			$fldName = trim( strval($this->accessSettings('tryLimit')['dataPath'] ?? ''), '/\\ ' );
			return TOP_DIR.DS.( $fldName!='' ? str_replace(Array('/', '\\'), DS, $fldName) : 'temp'.DS.'limits' );
		}
		return TOP_DIR.DS.'temp'.DS.( ($tempFileKind=='cacheDir') ? 'cache' : $tempFileKind );
	}

	/**
	 * Checks that a folder exists and lies under the temp folder of the
	 * project: nothing is removed anywhere else.
	 *
	 * @param String $fullFldrPath Full path of the folder.
	 * @return ?String Its real path, or null when it is missing or lies elsewhere.
	 */
	protected function verifyTempPath( String $fullFldrPath ): ?String {
		$realFld = realpath($fullFldrPath);
		$realDir = realpath(TOP_DIR.DS.'temp');
		if( $realFld===false || $realDir===false || !is_dir($realFld) || strpos($realFld.DS, $realDir.DS)!==0 || $realFld===$realDir ){
			return null;
		}
		return $realFld;
	}

	/**
	 * Removes the files of a folder and of its sub-folders, which are kept.
	 *
	 * @param String $fullFldrPath Full path of the folder.
	 * @param ?Int   $unixMomentNb Only the files last written before that Unix time are removed; all of them when null.
	 * @return Int The number of files removed.
	 */
	protected function dropFilesCount( String $fullFldrPath, ?Int $unixMomentNb=null ): Int {
		$dropNum = 0;
		foreach( (array)scandir($fullFldrPath) as $entNme ){
			if( $entNme=='.' || $entNme=='..' || $entNme=='.gitkeep' ){
				continue;
			}
			$subPath = $fullFldrPath.DS.$entNme;
			if( is_link($subPath) ){
				continue;
			}
			if( is_dir($subPath) ){
				$dropNum += $this->dropFilesCount($subPath, $unixMomentNb);
			}elseif( ($unixMomentNb===null || filemtime($subPath)<$unixMomentNb) && @unlink($subPath) ){
				$dropNum++;
			}
		}
		return $dropNum;
	}
}
