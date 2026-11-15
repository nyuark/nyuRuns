<?php

namespace nyu\Runs\Probe;

use nyu\Shell\Command;

// The command the Probe application brings to the command line (php nyuRun probe ...)
class Shell extends Command{

	/**
	 * @return String The line shown in the list of commands.
	 */
	public function renderSynopsis(): String {
		return 'Probe of the command line';
	}

	/**
	 * @return Array The sub-commands, by name.
	 */
	public function listSubCmndDct(): Array {
		return Array(
			'sayHello'=>Array('args'=>'<name>', 'about'=>'greets'),
			'askInput'=>Array('args'=>'', 'about'=>'asks a value, a confirmation and a secret'),
			'refusing'=>Array('args'=>'', 'about'=>'is refused'),
			'ghostSub'=>Array('args'=>'', 'about'=>'is declared and not written')
		);
	}

	/**
	 * Greets the name given, and tells the words and option left.
	 *
	 * @return Int The exit code.
	 */
	public function invokeSayHello(): Int {
		$this->printerObj->writeShellLine('Hello '.$this->needsShellWord('name'));
		$this->printerObj->writeShellPair('rest', $this->optionsObj->getRestOfWords());
		$this->printerObj->writeShellPair('tone', $this->optionsObj->getOptionValue('greeting', 'plain'));
		$this->printerObj->writeShellRows( Array('name', 'size'), Array(Array('a', 1), Array('long one', 22)) );
		return self::EXIT_DONE;
	}

	/**
	 * Asks a value, a confirmation and a secret, and tells the answers.
	 *
	 * @return Int The exit code.
	 */
	public function invokeAskInput(): Int {
		$this->printerObj->writeShellPair('value', $this->promptValueTxt('Value', 'dflt'));
		$this->printerObj->writeShellPair('agree', $this->promptApproval('Remove it'));
		$this->printerObj->writeShellOnce('typed', $this->promptSecrText('Secret'));
		return self::EXIT_DONE;
	}

	/**
	 * Is refused.
	 *
	 * @return Int Never given.
	 */
	public function invokeRefusing(): Int {
		$this->stopWithReason('Not allowed here', self::EXIT_DENY);
	}
}
