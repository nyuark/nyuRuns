<?php

namespace nyu\Runs\Probe;

use nyu\Shell\Command;

// The command the Probe application brings to the command line (php nyuRun probe ...)
class Shell extends Command{

	/**
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Probe of the command line';
	}

	/**
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		return Array(
			'hello'=>Array('args'=>'<name>', 'about'=>'greets'),
			'asked'=>Array('args'=>'', 'about'=>'asks a value, a confirmation and a secret'),
			'stops'=>Array('args'=>'', 'about'=>'is refused'),
			'ghost'=>Array('args'=>'', 'about'=>'is declared and not written')
		);
	}

	/**
	 * Greets the name given, and tells the words and option left.
	 *
	 * @return Int The exit code.
	 */
	public function callHello(): Int {
		$this->prntObjc->printLine('Hello '.$this->needsWord('name'));
		$this->prntObjc->printPair('rest', $this->optnObjc->restWords());
		$this->prntObjc->printPair('tone', $this->optnObjc->getOption('tone', 'plain'));
		$this->prntObjc->printRows( Array('name', 'size'), Array(Array('a', 1), Array('long one', 22)) );
		return self::EXITDONE;
	}

	/**
	 * Asks a value, a confirmation and a secret, and tells the answers.
	 *
	 * @return Int The exit code.
	 */
	public function callAsked(): Int {
		$this->prntObjc->printPair('value', $this->askAnswer('Value', 'dflt'));
		$this->prntObjc->printPair('agree', $this->askAgreed('Remove it'));
		$this->prntObjc->printOnce('typed', $this->askSecret('Secret'));
		return self::EXITDONE;
	}

	/**
	 * Is refused.
	 *
	 * @return Int Never given.
	 */
	public function callStops(): Int {
		$this->stopShell('Not allowed here', self::EXITDENY);
	}
}
