<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Checker;
use nyu\Shell\ActCaller;
use nyu\Shell\Command;
use nyu\Shell\Console;

/**
 * Calls an action of a run from the command line, as a scheduled task would:
 *
 *   php nyuRun cronjob shows filters[title]=jazz
 *   php nyuRun cronjob users currStat=inReview --roleName=managers --saveInto=review.json
 *
 * The action runs as the user cli:<system user>, through the preset
 * --presetId names (the default one when none is named).
 */
class CronJob extends Command{

	// Methods a call may be said to use
	const MTHD_LIST = Array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Calls an action of a run, as the user cli:<system user>';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The command has no sub-command.
	 */
	public function subsTableArray(): Array {
		return Array( ''=>Array('call'=>'invokeCronJobs', 'args'=>'<app/action[/param]> [name=value ...] [--bodyFrom=<file|->] [--httpVerb=<method>] [--presetId=<preset>] [--roleName=<role>] [--saveInto=<file>]', 'about'=>'calls an action of a run, as the user cli:<system user>') );
	}

	/**
	 * Calls an action of a run. Its inputs are the name=value words
	 * (filters[search]=jazz for a nested one), over those of a JSON file
	 * (--bodyFrom, - for the standard input). It runs as the user
	 * cli:<system user>, with the role 'advanced' unless --roleName gives
	 * another. Its reply is written to the output, or to --saveInto.
	 *
	 * @return Int EXITDONE for a success or a redirection, EXITDENY for a 4xx status, EXITFAIL for a 5xx.
	 */
	public function invokeCronJobs(): Int {
		$actnPth = $this->needsShellWord('the action, as app/action');
		$inptVls = Array();
		$inptFil = $this->optionsObj->retrieveOption('bodyFrom');
		if( $inptFil!==null ){
			$inptTxt = ($inptFil=='-') ? stream_get_contents($this->readStream) : @file_get_contents($inptFil);
			$inptVls = json_decode(strval($inptTxt), true);
			if( !is_array($inptVls) ){
				$this->terminateShell('The inputs of --bodyFrom are not a JSON object', self::EXIT_HELP);
			}
		}
		$pairLst = Array();
		foreach( $this->optionsObj->restWordsArray() as $gvnWrd ){
			$pairPrt = explode('=', $gvnWrd, 2);
			if( count($pairPrt)!=2 || $pairPrt[0]=='' ){
				$this->terminateShell('An input is written name=value: '.$gvnWrd, self::EXIT_HELP);
			}
			$pairLst[] = str_replace(Array('%5B', '%5D'), Array('[', ']'), rawurlencode($pairPrt[0])).'='.rawurlencode($pairPrt[1]);
		}
		$wordVal = Array();
		parse_str(implode('&', $pairLst), $wordVal);
		$inptVls = array_replace_recursive($inptVls, $wordVal);
		$roleNme = strval($this->optionsObj->retrieveOption('roleName', 'advanced'));
		$mthName = strtoupper(strval($this->optionsObj->retrieveOption('httpVerb', 'GET')));
		if( !in_array($roleNme, Checker::ROLE_LIST, true) || !in_array($mthName, self::MTHD_LIST, true) ){
			$this->terminateShell('--roleName takes one of '.implode(', ', Checker::ROLE_LIST).'; --httpVerb one of '.implode(', ', self::MTHD_LIST), self::EXIT_HELP);
		}
		$replyAr = (new ActCaller())->runAskedAction( strval($this->optionsObj->retrieveOption('presetId', '')), $actnPth, $inptVls, $mthName, 'cli:'.Console::osShellUsrName(), $roleNme );
		$savPath = $this->optionsObj->retrieveOption('saveInto');
		if( $this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellPair('code', $replyAr['code']);
		}
		if( $savPath!==null ){
			if( @file_put_contents($savPath, $replyAr['body'])===false ){
				$this->terminateShell('The reply cannot be written to '.$savPath);
			}
			$this->printerObj->printShellPair('saved', $savPath);
		}elseif( $this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellPair('reply', json_decode($replyAr['body'], true) ?? $replyAr['body']);
		}elseif( $replyAr['body']!=='' ){
			$this->printerObj->printShellBody( $replyAr['body'].( substr($replyAr['body'], -1)=="\n" ? '' : PHP_EOL ) );
		}
		if( $replyAr['goal']!='' ){
			$this->printerObj->printShellPair('moved', $replyAr['goal']);
		}
		if( $replyAr['code']>=400 ){
			$this->printerObj->printShellFail( $replyAr['code'].' '.$replyAr['mssg'] );
			return ($replyAr['code']>=500) ? self::EXIT_FAIL : self::EXIT_DENY;
		}
		return self::EXIT_DONE;
	}
}
