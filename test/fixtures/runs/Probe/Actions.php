<?php

// Test-only application : actions that misbehave on purpose, served by
// test/lib/httpRouter.php when a request carries the X-Test-Runs header.

namespace nyu\Runs\Probe;

use \nyu\App\Actionset;

class Actions extends Actionset {

	/**
	 * Answers normally. Declared first, so it is also the default action.
	 */
	public function clean(): Void {
		$this->statCode = 200;
		$this->asOutput = Array('ok'=>true);
	}

	/**
	 * Answers with the parameter it was called with.
	 */
	public function mirror( String $givenValue ): Void {
		$this->statCode = 200;
		$this->asOutput = Array('got'=>$givenValue);
	}

	/**
	 * A helper, not an action: must not be reachable by URL.
	 */
	protected function hidden(): Void {
		$this->statCode = 200;
		$this->asOutput = Array('hidden'=>true);
	}

	/**
	 * A static method, not an action: must not be reachable by URL.
	 */
	public static function fixed(): Void {
	}

	/**
	 * Raises a user warning and a PHP warning, then answers normally with a list.
	 */
	public function warn(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		$emptyAr = Array();
		$missing = $emptyAr['missing'];
		$this->statCode = 200;
		$this->asOutput = Array(1, 2, 3);
	}

	/**
	 * Raises a warning, then answers with an error status.
	 */
	public function warnfail(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		$this->statCode = 404;
		$this->statMssg = 'Nothing here';
	}

	/**
	 * Raises a warning, then throws.
	 */
	public function boom(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		throw new \RuntimeException('probe failure', 7);
	}

	/**
	 * Exhausts the memory limit, which PHP reports as a fatal error at shutdown.
	 */
	public function fatal(): Void {
		ini_set('memory_limit', '24M');
		$bigText = str_repeat('x', 64*1024*1024);
		$this->statCode = 200;
		$this->asOutput = Array(strlen($bigText));
	}
}
