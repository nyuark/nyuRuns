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
	 * Answers with the roles the requester holds, after asking for the
	 * role named by its parameter when it is given one.
	 */
	public function roles( String $givenValue='' ): Void {
		if( $givenValue!='' ){
			$this->needsRole($givenValue);
		}
		$this->statCode = 200;
		$this->asOutput = Array('member'=>$this->holdsRole('member'), 'system'=>$this->holdsRole('system'), 'manage'=>$this->holdsRole('manage'));
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
	 * Sends the client elsewhere, in the way named by its parameter. The
	 * lines after the redirection must never run.
	 */
	public function moved( String $givenValue='' ): Void {
		if( $givenValue=='out' ){
			$this->replyMove('https://example.org/', 303);
		}
		if( $givenValue=='bad' ){
			$this->replyMove('//example.org/');
		}
		if( $givenValue=='code' ){
			$this->replyMove('/probe/clean', 200);
		}
		$this->asOutput = Array('step'=>1);
		$this->replyMove('/probe/clean');
		$this->statCode = 200;
		$this->asOutput = Array('step'=>2);
	}

	/**
	 * Answers with the card page of the application, or with a page that
	 * cannot be served, in the way named by its parameter.
	 */
	public function page( String $givenValue='' ): Void {
		if( $givenValue=='none' ){
			$this->replyPage('nosuchpage');
		}elseif( $givenValue=='out' ){
			$this->replyPage('../Actions');
		}elseif( $givenValue=='gone' ){
			$this->replyPage('card', Array('title'=>'Gone'), 404);
		}elseif( $givenValue=='fail' ){
			$this->replyPage('card', Array('title'=>'Never sent'));
			$this->statCode = 403;
			$this->statMssg = 'Not yours';
		}else{
			$this->replyPage('card', Array('title'=>'<b>Tom & "Jerry"</b>', 'link'=>$this->routeLink('items/$id', Array('id'=>'a b'), Array('tab'=>'x y'))));
		}
	}

	/**
	 * Answers with the link to a declared route, or fails on one that is
	 * not declared when called with a parameter.
	 */
	public function link( String $givenValue='' ): Void {
		$this->statCode = 200;
		$this->asOutput = Array('link'=>($givenValue=='') ? $this->routeLink('things/$id', Array('id'=>7)) : $this->routeLink('nosuch/$id', Array('id'=>7)));
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
