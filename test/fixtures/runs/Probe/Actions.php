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
	 * Tells whether the 'user' and 'pass' inputs were read from the
	 * request, whatever its method: 401 when both were, 400 otherwise.
	 */
	public function creds(): Void {
		$gotBoth = is_string($this->inputReg->readValue('user')) && is_string($this->inputReg->readValue('pass'));
		$this->statCode = $gotBoth ? 401 : 400;
		$this->statMssg = $gotBoth ? 'Credentials read' : 'Missing user or pass';
	}

	/**
	 * Answers with what the access check reported about the requester.
	 */
	public function whoami(): Void {
		$this->statCode = 200;
		$this->asOutput = Array(
			'logged'=>$this->axessReg->readValue('logged'),
			'user'=>$this->axessReg->readValue('user'),
			'role'=>$this->axessReg->readValue('role')
		);
	}

	/**
	 * Answers with the users of the native userbase who are online.
	 */
	public function online(): Void {
		$natAuth = new \nyu\Access\Auth\Native( Array() );
		$this->statCode = 200;
		$this->asOutput = Array('online'=>$natAuth->onlineNow());
	}

	/**
	 * Raises a warning, then answers "no content" without any output.
	 */
	public function vanish(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		$this->statCode = 204;
	}

	/**
	 * Sets a first output, then stops through a helper in the way named by
	 * its parameter. The lines after the stop must never run.
	 */
	public function halt( String $givenValue ): Void {
		if( $givenValue!='none' ){
			$this->asOutput = Array('step'=>1);
		}
		$this->stopHere($givenValue);
		$this->statCode = 200;
		$this->asOutput = Array('step'=>2);
	}

	/**
	 * A helper, not an action: ends the action it is called from.
	 */
	protected function stopHere( String $givenValue ): Void {
		if( $givenValue=='fail' ){
			$this->endAction(404, 'Stopped early');
		}
		if( $givenValue=='full' ){
			$this->endAction(200, '', Array('done'=>true));
		}
		if( $givenValue=='none' ){
			$this->endAction(204);
		}
		$this->endAction(200);
	}

	/**
	 * Answers with a bare string, not an array.
	 */
	public function text(): Void {
		$this->statCode = 200;
		$this->asOutput = 'plain text';
	}

	/**
	 * Answers with text that is not valid UTF-8, which JSON cannot carry.
	 */
	public function badutf(): Void {
		$this->statCode = 200;
		$this->asOutput = Array('name'=>"caf\xE9");
	}

	/**
	 * Answers with the letters fixture file, or with a missing one when
	 * called with a parameter.
	 */
	public function media( String $givenValue='' ): Void {
		$this->replyFile( TOP_DIR.DS.'test'.DS.'fixtures'.DS.($givenValue=='' ? 'letters.txt' : 'nosuchfile.txt'), 'audio/mp4' );
	}

	/**
	 * Registers the letters fixture file, then answers with an error status.
	 */
	public function mediafail(): Void {
		$this->replyFile( TOP_DIR.DS.'test'.DS.'fixtures'.DS.'letters.txt', 'audio/mp4' );
		$this->statCode = 403;
		$this->statMssg = 'Not yours';
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
