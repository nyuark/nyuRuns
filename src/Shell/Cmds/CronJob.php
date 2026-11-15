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
		$actnPath = $this->needsShellWord('the action, as app/action');
		$inputVal = Array();
		$inputFle = $this->optionsObj->retrieveOption('bodyFrom');
		if( $inputFle!==null ){
			$inputTxt = ($inputFle=='-') ? stream_get_contents($this->readStream) : @file_get_contents($inputFle);
			$inputVal = json_decode(strval($inputTxt), true);
			if( !is_array($inputVal) ){
				$this->terminateShell('The inputs of --bodyFrom are not a JSON object', self::EXIT_HELP);
			}
		}
		$pairList = Array();
		foreach( $this->optionsObj->restWordsArray() as $gvnWrd ){
			$pairPart = explode('=', $gvnWrd, 2);
			if( count($pairPart)!=2 || $pairPart[0]=='' ){
				$this->terminateShell('An input is written name=value: '.$gvnWrd, self::EXIT_HELP);
			}
			$pairList[] = str_replace(Array('%5B', '%5D'), Array('[', ']'), rawurlencode($pairPart[0])).'='.rawurlencode($pairPart[1]);
		}
		$wordVals = Array();
		parse_str(implode('&', $pairList), $wordVals);
		$inputVal = array_replace_recursive($inputVal, $wordVals);
		$roleName = strval($this->optionsObj->retrieveOption('roleName', 'advanced'));
		$mthdName = strtoupper(strval($this->optionsObj->retrieveOption('httpVerb', 'GET')));
		if( !in_array($roleName, Checker::ROLE_LIST, true) || !in_array($mthdName, self::MTHD_LIST, true) ){
			$this->terminateShell('--roleName takes one of '.implode(', ', Checker::ROLE_LIST).'; --httpVerb one of '.implode(', ', self::MTHD_LIST), self::EXIT_HELP);
		}
		$rplyList = (new ActCaller())->treatAskedActn( strval($this->optionsObj->retrieveOption('presetId', '')), $actnPath, $inputVal, $mthdName, 'cli:'.Console::osShellUsrName(), $roleName );
		$savePath = $this->optionsObj->retrieveOption('saveInto');
		if( $this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellPair('code', $rplyList['code']);
		}
		if( $savePath!==null ){
			if( @file_put_contents($savePath, $rplyList['body'])===false ){
				$this->terminateShell('The reply cannot be written to '.$savePath);
			}
			$this->printerObj->printShellPair('saved', $savePath);
		}elseif( $this->printerObj->jsonShellShape() ){
			$this->printerObj->printShellPair('reply', json_decode($rplyList['body'], true) ?? $rplyList['body']);
		}elseif( $rplyList['body']!=='' ){
			$this->printerObj->printShellBody( $rplyList['body'].( substr($rplyList['body'], -1)=="\n" ? '' : PHP_EOL ) );
		}
		if( $rplyList['goal']!='' ){
			$this->printerObj->printShellPair('moved', $rplyList['goal']);
		}
		if( $rplyList['code']>=400 ){
			$this->printerObj->printShellFail( $rplyList['code'].' '.$rplyList['mssg'] );
			return ($rplyList['code']>=500) ? self::EXIT_FAIL : self::EXIT_DENY;
		}
		return self::EXIT_DONE;
	}
}
