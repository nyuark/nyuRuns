<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Limiter;
use nyu\Setup\Loader;
use nyu\Shell\Command;

/**
 * Cleans what the framework leaves in the temp folder, and shows it:
 *
 *   php nyuRun cleans purge logs --older=30    removes the log files older than 30 days
 *   php nyuRun cleans purge all                removes every temporary file
 *   php nyuRun cleans trace access --lasts=50  shows the last lines of a log channel (--watch follows it)
 *   php nyuRun cleans holds                    lists the addresses the limiter counts or holds
 *   php nyuRun cleans frees 203.0.113.7        releases an address (or all)
 *
 * Nothing is ever removed outside the temp folder of the project.
 */
class Cleans extends Command{

	// What can be purged: the folders of temp, by their name
	const KINDLIST = Array('cache', 'logs', 'limits', 'backup');

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Removes temporary files and logs, shows a log, lists and releases the held addresses';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		return Array(
			'purge'=>Array('args'=>'<cache|logs|limits|backup|all> ... [--older=<days>]', 'about'=>'removes the files of those folders of temp'),
			'trace'=>Array('args'=>'<channel> [--lasts=<lines>] [--watch]', 'about'=>'shows the last lines of a log channel'),
			'holds'=>Array('args'=>'', 'about'=>'lists the addresses the limiter counts or holds'),
			'frees'=>Array('args'=>'<address|all>', 'about'=>'releases an address, or all of them')
		);
	}

	/**
	 * Removes the files of the folders named, all of them or those older
	 * than --older days. The folders themselves are kept.
	 *
	 * @return Int The exit code.
	 */
	public function callPurge(): Int {
		$kindLst = $this->optnObjc->restWords();
		if( empty($kindLst) ){
			$this->stopShell('Missing argument: what to purge ('.implode(', ', self::KINDLIST).', or all)', self::EXITUSGE);
		}
		if( in_array('all', $kindLst, true) ){
			$kindLst = self::KINDLIST;
		}
		$badList = array_diff($kindLst, self::KINDLIST);
		if( !empty($badList) ){
			$this->stopShell('Nothing is purged under that name: '.implode(', ', $badList), self::EXITUSGE);
		}
		$oldDays = $this->optnObjc->getOption('older');
		if( $oldDays!==null && !preg_match('/^\d{1,5}$/D', $oldDays) ){
			$this->stopShell('--older takes a number of days', self::EXITUSGE);
		}
		$upToTme = ($oldDays===null) ? null : time() - intval($oldDays)*86400;
		$kindLst = array_values(array_unique($kindLst));
		if( !$this->askAgreed('Remove the files of '.implode(', ', $kindLst).( $oldDays!==null ? ' older than '.$oldDays.' days' : '' )) ){
			$this->stopShell('Nothing was removed: not confirmed', self::EXITDENY);
		}
		$rowList = Array();
		foreach( $kindLst as $kndNme ){
			$fldPath = $this->tempFoldr( $this->kindFoldr($kndNme) );
			if( $fldPath===null ){
				$rowList[] = Array($kndNme, 0, 'left alone: its folder is missing, or not under temp');
				continue;
			}
			$dropNum = $this->dropFiles($fldPath, $upToTme);
			$rowList[] = Array($kndNme, $dropNum, 'purged');
			$this->traceDone( 'Purge of '.$kndNme.': '.$dropNum.' files removed'.( $oldDays!==null ? ', older than '.$oldDays.' days' : '' ) );
		}
		$this->prntObjc->printRows( Array('folder', 'removed', 'state'), $rowList );
		return self::EXITDONE;
	}

	/**
	 * Shows the last lines of a log channel, and follows it with --watch.
	 *
	 * @return Int The exit code.
	 */
	public function callTrace(): Int {
		$chanNme = $this->needsWord('the log channel (error, access, runner, shell, or the name of an application)');
		if( !preg_match('/^\w+$/D', $chanNme) ){
			$this->stopShell('A log channel is a plain name', self::EXITUSGE);
		}
		$lineNum = $this->optnObjc->getOption('lasts', '20');
		if( !preg_match('/^\d{1,6}$/D', strval($lineNum)) ){
			$this->stopShell('--lasts takes a number of lines', self::EXITUSGE);
		}
		// With dated file names, the channel is read in its newest file
		$logPath = '';
		foreach( (array)glob($this->kindFoldr('logs').DS.$chanNme.'*.log') as $logFle ){
			$logFle = strval($logFle);
			if( preg_match('/^'.$chanNme.'(-[\dW_\-]+)?\.log$/D', basename($logFle)) && ($logPath=='' || filemtime($logFle)>filemtime($logPath)) ){
				$logPath = $logFle;
			}
		}
		if( $logPath=='' ){
			$this->stopShell('No log for the channel '.$chanNme, self::EXITDENY);
		}
		$fileLen = filesize($logPath);
		$readLen = min($fileLen, 2097152);
		$logText = strval( file_get_contents($logPath, false, null, $fileLen-$readLen, $readLen) );
		$lineLst = explode("\n", rtrim(str_replace("\r\n", "\n", $logText), "\n"));
		$lineLst = ($logText=='' || intval($lineNum)==0) ? Array() : array_slice($lineLst, -intval($lineNum));
		foreach( $lineLst as $logLin ){
			$this->prntObjc->printBody($logLin.PHP_EOL);
		}
		if( !$this->optnObjc->hasOption('watch') || $this->prntObjc->jsonShape() ){
			return self::EXITDONE;
		}
		// Followed until the command is interrupted
		$readPos = $fileLen;
		while( true ){
			usleep(500000);
			clearstatcache(true, $logPath);
			$fileLen = is_file($logPath) ? filesize($logPath) : 0;
			if( $fileLen<$readPos ){
				// The file was rotated : it is read again from its start
				$readPos = 0;
			}
			if( $fileLen>$readPos ){
				$this->prntObjc->printBody( strval(file_get_contents($logPath, false, null, $readPos, $fileLen-$readPos)) );
				$readPos = $fileLen;
			}
		}
	}

	/**
	 * Lists the addresses the limiter has a counter for, held or not.
	 *
	 * @return Int The exit code.
	 */
	public function callHolds(): Int {
		$lmtrObj = new Limiter( $this->accessCnf('limits') );
		$rowList = Array();
		foreach( $lmtrObj->listHolds() as $cntVal ){
			$rowList[] = Array(
				($cntVal['addrs']!='') ? $cntVal['addrs'] : '(not told)',
				$cntVal['fails'],
				date('Y-m-d H:i:s', $cntVal['stamp']),
				($cntVal['until']>0) ? date('Y-m-d H:i:s', $cntVal['until']) : ''
			);
		}
		$this->prntObjc->printRows( Array('address', 'failures', 'last', 'held until'), $rowList );
		if( empty($rowList) ){
			$this->prntObjc->printLine('No address is counted.');
		}
		return self::EXITDONE;
	}

	/**
	 * Releases an address, or all of them: its failures are forgotten.
	 *
	 * @return Int The exit code.
	 */
	public function callFrees(): Int {
		$gvnAddr = $this->needsWord('the address to release, or all');
		if( $gvnAddr!='all' && filter_var($gvnAddr, FILTER_VALIDATE_IP)===false ){
			$this->stopShell('Not an address: '.$gvnAddr, self::EXITUSGE);
		}
		if( !$this->askAgreed('Release '.( $gvnAddr=='all' ? 'every address' : $gvnAddr )) ){
			$this->stopShell('Nothing was released: not confirmed', self::EXITDENY);
		}
		$lmtrObj = new Limiter( $this->accessCnf('limits') );
		$dropNum = $lmtrObj->freeHolds( $gvnAddr=='all' ? null : $gvnAddr );
		$this->prntObjc->printPair('released', $dropNum);
		if( $dropNum>0 ){
			$this->traceDone( 'Limiter: '.( $gvnAddr=='all' ? $dropNum.' addresses released' : 'address '.$gvnAddr.' released' ) );
		}
		return self::EXITDONE;
	}

	/**
	 * Gives the folder of a kind of temporary files, as the config sets it.
	 *
	 * @param String $kindsLabel One of KINDLIST.
	 * @return String The full path of the folder.
	 */
	protected function kindFoldr( String $kindsLabel ): String {
		if( $kindsLabel=='logs' ){
			return strval( (new Loader(Array('setup'=>Array('ftypes'=>'all'))))->logConfig()['path'] );
		}
		if( $kindsLabel=='limits' ){
			$fldName = trim( strval($this->accessCnf('limits')['folder'] ?? ''), '/\\ ' );
			return TOP_DIR.DS.( $fldName!='' ? str_replace(Array('/', '\\'), DS, $fldName) : 'temp'.DS.'limits' );
		}
		return TOP_DIR.DS.'temp'.DS.$kindsLabel;
	}

	/**
	 * Checks that a folder exists and lies under the temp folder of the
	 * project: nothing is removed anywhere else.
	 *
	 * @param String $folderPath Full path of the folder.
	 * @return ?String Its real path, or null when it is missing or lies elsewhere.
	 */
	protected function tempFoldr( String $folderPath ): ?String {
		$realFld = realpath($folderPath);
		$realTmp = realpath(TOP_DIR.DS.'temp');
		if( $realFld===false || $realTmp===false || !is_dir($realFld) || strpos($realFld.DS, $realTmp.DS)!==0 || $realFld===$realTmp ){
			return null;
		}
		return $realFld;
	}

	/**
	 * Removes the files of a folder and of its sub-folders, which are kept.
	 *
	 * @param String $folderPath Full path of the folder.
	 * @param ?Int   $unixMoment Only the files last written before that Unix time are removed; all of them when null.
	 * @return Int The number of files removed.
	 */
	protected function dropFiles( String $folderPath, ?Int $unixMoment=null ): Int {
		$dropNum = 0;
		foreach( (array)scandir($folderPath) as $entNme ){
			if( $entNme=='.' || $entNme=='..' || $entNme=='.gitkeep' ){
				continue;
			}
			$entPath = $folderPath.DS.$entNme;
			if( is_link($entPath) ){
				continue;
			}
			if( is_dir($entPath) ){
				$dropNum += $this->dropFiles($entPath, $unixMoment);
			}elseif( ($unixMoment===null || filemtime($entPath)<$unixMoment) && @unlink($entPath) ){
				$dropNum++;
			}
		}
		return $dropNum;
	}
}
