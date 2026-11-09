<?php

namespace nyu\Runs\Probe;

use nyu\Shell\Command;

// The command the Probe application brings to the command line (php nyuRun probe ...)
class Shell extends Command{

	/**
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Probe of the command line';
	}

	/**
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
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
		$this->printerObj->printShellLine('Hello '.$this->needsShellWord('name'));
		$this->printerObj->printShellPair('rest', $this->optnObject->restWordsArray());
		$this->printerObj->printShellPair('tone', $this->optnObject->retrieveOption('greeting', 'plain'));
		$this->printerObj->printShellRows( Array('name', 'size'), Array(Array('a', 1), Array('long one', 22)) );
		return self::EXIT_DONE;
	}

	/**
	 * Asks a value, a confirmation and a secret, and tells the answers.
	 *
	 * @return Int The exit code.
	 */
	public function invokeAskInput(): Int {
		$this->printerObj->printShellPair('value', $this->queryAnswerTxt('Value', 'dflt'));
		$this->printerObj->printShellPair('agree', $this->queryAgreedFlg('Remove it'));
		$this->printerObj->printShellOnce('typed', $this->querySecretTxt('Secret'));
		return self::EXIT_DONE;
	}

	/**
	 * Is refused.
	 *
	 * @return Int Never given.
	 */
	public function invokeRefusing(): Int {
		$this->terminateShell('Not allowed here', self::EXIT_DENY);
	}
}
