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
		return 'Removes temporary files and logs, lists and releases the held addresses';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
		return Array(
			'cleanDir'=>Array('args'=>'<cacheDir|logFiles|tryLimit|archives|all> ... [--keepDays=<days>]', 'about'=>'removes the files of those folders of temp'),
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
		$kindList = $this->optionsObj->restWordsArray();
		if( empty($kindList) ){
			$this->terminateShell('Missing argument: what to purge ('.implode(', ', self::KIND_LIST).', or all)', self::EXIT_HELP);
		}
		if( in_array('all', $kindList, true) ){
			$kindList = self::KIND_LIST;
		}
		$badLstng = array_diff($kindList, self::KIND_LIST);
		if( !empty($badLstng) ){
			$this->terminateShell('Nothing is purged under that name: '.implode(', ', $badLstng), self::EXIT_HELP);
		}
		$keepDays = $this->optionsObj->retrieveOption('keepDays');
		if( $keepDays!==null && !preg_match('/^\d{1,5}$/D', $keepDays) ){
			$this->terminateShell('--keepDays takes a number of days', self::EXIT_HELP);
		}
		$uptoUnix = ($keepDays===null) ? null : time() - intval($keepDays)*86400;
		$kindList = array_values(array_unique($kindList));
		if( !$this->queryAgreedFlg('Remove the files of '.implode(', ', $kindList).( $keepDays!==null ? ' older than '.$keepDays.' days' : '' )) ){
			$this->terminateShell('Nothing was removed: not confirmed', self::EXIT_DENY);
		}
		$rowsList = Array();
		foreach( $kindList as $kndNme ){
			$fieldPth = $this->verifyTempPath( $this->findTempFolder($kndNme) );
			if( $fieldPth===null ){
				$rowsList[] = Array($kndNme, 0, 'left alone: its folder is missing, or not under temp');
				continue;
			}
			$dropNumb = $this->dropFilesCount($fieldPth, $uptoUnix);
			$rowsList[] = Array($kndNme, $dropNumb, 'purged');
			$this->traceShellDone( 'Purge of '.$kndNme.': '.$dropNumb.' files removed'.( $keepDays!==null ? ', older than '.$keepDays.' days' : '' ) );
		}
		$this->printerObj->printShellRows( Array('folder', 'removed', 'state'), $rowsList );
		return self::EXIT_DONE;
	}

	/**
	 * Lists the addresses the limiter has a counter for, held or not.
	 *
	 * @return Int The exit code.
	 */
	public function invokeFullList(): Int {
		$lmtrInst = new Limiter( $this->accessSettings('tryLimit') );
		$rowsList = Array();
		foreach( $lmtrInst->listLimitHolds() as $cntVal ){
			$rowsList[] = Array(
				($cntVal['addrs']!='') ? $cntVal['addrs'] : '(not told)',
				$cntVal['fails'],
				date('Y-m-d H:i:s', $cntVal['stamp']),
				($cntVal['until']>0) ? date('Y-m-d H:i:s', $cntVal['until']) : ''
			);
		}
		$this->printerObj->printShellRows( Array('address', 'failures', 'last', 'held until'), $rowsList );
		if( empty($rowsList) ){
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
		$givenAdr = $this->needsShellWord('the address to release, or all');
		if( $givenAdr!='all' && filter_var($givenAdr, FILTER_VALIDATE_IP)===false ){
			$this->terminateShell('Not an address: '.$givenAdr, self::EXIT_HELP);
		}
		if( !$this->queryAgreedFlg('Release '.( $givenAdr=='all' ? 'every address' : $givenAdr )) ){
			$this->terminateShell('Nothing was released: not confirmed', self::EXIT_DENY);
		}
		$lmtrInst = new Limiter( $this->accessSettings('tryLimit') );
		$dropNumb = $lmtrInst->freeHoldsCount( $givenAdr=='all' ? null : $givenAdr );
		$this->printerObj->printShellPair('released', $dropNumb);
		if( $dropNumb>0 ){
			$this->traceShellDone( 'Limiter: '.( $givenAdr=='all' ? $dropNumb.' addresses released' : 'address '.$givenAdr.' released' ) );
		}
		return self::EXIT_DONE;
	}

	/**
	 * Gives the folder of a kind of temporary files, as the config sets it.
	 *
	 * @param String $tempFileType One of KIND_LIST.
	 * @return String The full path of the folder.
	 */
	protected function findTempFolder( String $tempFileType ): String {
		if( $tempFileType=='logFiles' ){
			return strval( (new Loader(Array('settings'=>Array('confType'=>'all'))))->logConfigArray()['path'] );
		}
		if( $tempFileType=='tryLimit' ){
			$fieldNme = trim( strval($this->accessSettings('tryLimit')['dataPath'] ?? ''), '/\\ ' );
			return TOP_DIR.DS.( $fieldNme!='' ? str_replace(Array('/', '\\'), DS, $fieldNme) : 'temp'.DS.'limits' );
		}
		return TOP_DIR.DS.'temp'.DS.( ($tempFileType=='cacheDir') ? 'cache' : $tempFileType );
	}

	/**
	 * Checks that a folder exists and lies under the temp folder of the
	 * project: nothing is removed anywhere else.
	 *
	 * @param String $fullFldrPath Full path of the folder.
	 * @return ?String Its real path, or null when it is missing or lies elsewhere.
	 */
	protected function verifyTempPath( String $fullFldrPath ): ?String {
		$realFild = realpath($fullFldrPath);
		$realPath = realpath(TOP_DIR.DS.'temp');
		if( $realFild===false || $realPath===false || !is_dir($realFild) || strpos($realFild.DS, $realPath.DS)!==0 || $realFild===$realPath ){
			return null;
		}
		return $realFild;
	}

	/**
	 * Removes the files of a folder and of its sub-folders, which are kept.
	 *
	 * @param String $fullFldrPath Full path of the folder.
	 * @param ?Int   $momentAsUnix Only the files last written before that Unix time are removed; all of them when null.
	 * @return Int The number of files removed.
	 */
	protected function dropFilesCount( String $fullFldrPath, ?Int $momentAsUnix=null ): Int {
		$dropNumb = 0;
		foreach( (array)scandir($fullFldrPath) as $entNme ){
			if( $entNme=='.' || $entNme=='..' || $entNme=='.gitkeep' ){
				continue;
			}
			$subTrail = $fullFldrPath.DS.$entNme;
			if( is_link($subTrail) ){
				continue;
			}
			if( is_dir($subTrail) ){
				$dropNumb += $this->dropFilesCount($subTrail, $momentAsUnix);
			}elseif( ($momentAsUnix===null || filemtime($subTrail)<$momentAsUnix) && @unlink($subTrail) ){
				$dropNumb++;
			}
		}
		return $dropNumb;
	}
}
