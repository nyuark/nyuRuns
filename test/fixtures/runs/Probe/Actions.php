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
		$this->statusCode = 200;
		$this->replyValue = Array('ok'=>true);
	}

	/**
	 * Answers with the parameter it was called with.
	 */
	public function mirror( String $valueAsGiven ): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('got'=>$valueAsGiven);
	}

	/**
	 * Tells whether the 'user' and 'pass' inputs were read from the
	 * request, whatever its method: 401 when both were, 400 otherwise.
	 */
	public function creds(): Void {
		$gotBoth = is_string($this->inptRgstry->readFileDetail('user')) && is_string($this->inptRgstry->readFileDetail('pass'));
		$this->statusCode = $gotBoth ? 401 : 400;
		$this->statusMssg = $gotBoth ? 'Credentials read' : 'Missing user or pass';
	}

	/**
	 * Sets a cookie of its own, then ends the session of the request as a
	 * logout does: the cookie of the application must still go out.
	 */
	public function leave(): Void {
		header('Set-Cookie: probeKept=1; path=/', false);
		(new \nyu\Access\Sess\PHPSess( Array('cookie'=>'nyuTests', 'secure'=>false) ))->dropUsrSession( $this->httpRequst );
		$this->statusCode = 200;
		$this->replyValue = Array('left'=>true);
	}

	/**
	 * Answers with what the access check reported about the requester.
	 */
	public function whoami(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array(
			'logged'=>$this->accessRgst->readFileDetail('logged'),
			'user'=>$this->accessRgst->readFileDetail('user'),
			'role'=>$this->accessRgst->readFileDetail('role')
		);
	}

	/**
	 * Answers with the roles the requester holds, after asking for the
	 * role named by its parameter when it is given one.
	 */
	public function roles( String $valueAsGiven='' ): Void {
		if( $valueAsGiven!='' ){
			$this->needsActionRle($valueAsGiven);
		}
		$this->statusCode = 200;
		$this->replyValue = Array('member'=>$this->holdsActionRle('member'), 'system'=>$this->holdsActionRle('system'), 'manage'=>$this->holdsActionRle('manage'));
	}

	/**
	 * Answers with the users of the native userbase who are online.
	 */
	public function online(): Void {
		$ntvAuth = new \nyu\Access\Auth\Native( Array() );
		$this->statusCode = 200;
		$this->replyValue = Array('online'=>$ntvAuth->onlineNowArray());
	}

	/**
	 * Raises a warning, then answers "no content" without any output.
	 */
	public function vanish(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		$this->statusCode = 204;
	}

	/**
	 * Sets a first output, then stops through a helper in the way named by
	 * its parameter. The lines after the stop must never run.
	 */
	public function halt( String $valueAsGiven ): Void {
		if( $valueAsGiven!='none' ){
			$this->replyValue = Array('step'=>1);
		}
		$this->stopActionHere($valueAsGiven);
		$this->statusCode = 200;
		$this->replyValue = Array('step'=>2);
	}

	/**
	 * A helper, not an action: ends the action it is called from.
	 */
	protected function stopActionHere( String $valueAsGiven ): Void {
		if( $valueAsGiven=='fail' ){
			$this->stopThisAction(404, 'Stopped early');
		}
		if( $valueAsGiven=='full' ){
			$this->stopThisAction(200, '', Array('done'=>true));
		}
		if( $valueAsGiven=='none' ){
			$this->stopThisAction(204);
		}
		$this->stopThisAction(200);
	}

	/**
	 * Answers with a bare string, not an array.
	 */
	public function text(): Void {
		$this->statusCode = 200;
		$this->replyValue = 'plain text';
	}

	/**
	 * Answers with text that is not valid UTF-8, which JSON cannot carry.
	 */
	public function badutf(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('name'=>"caf\xE9");
	}

	/**
	 * Answers with the letters fixture file, or with a missing one when
	 * called with a parameter.
	 */
	public function media( String $valueAsGiven='' ): Void {
		$this->serveFileReply( TOP_DIR.DS.'test'.DS.'fixtures'.DS.($valueAsGiven=='' ? 'letters.txt' : 'nosuchfile.txt'), 'audio/mp4' );
	}

	/**
	 * Registers the letters fixture file, then answers with an error status.
	 */
	public function mediafail(): Void {
		$this->serveFileReply( TOP_DIR.DS.'test'.DS.'fixtures'.DS.'letters.txt', 'audio/mp4' );
		$this->statusCode = 403;
		$this->statusMssg = 'Not yours';
	}

	/**
	 * Sends the client elsewhere, in the way named by its parameter. The
	 * lines after the redirection must never run.
	 */
	public function moved( String $valueAsGiven='' ): Void {
		if( $valueAsGiven=='out' ){
			$this->redirectClient('https://example.org/', 303);
		}
		if( $valueAsGiven=='bad' ){
			$this->redirectClient('//example.org/');
		}
		if( $valueAsGiven=='code' ){
			$this->redirectClient('/probe/clean', 200);
		}
		$this->replyValue = Array('step'=>1);
		$this->redirectClient('/probe/clean');
		$this->statusCode = 200;
		$this->replyValue = Array('step'=>2);
	}

	/**
	 * Answers with the card page of the application, or with a page that
	 * cannot be served, in the way named by its parameter.
	 */
	public function page( String $valueAsGiven='' ): Void {
		if( $valueAsGiven=='none' ){
			$this->answerPageFlag('nosuchpage');
		}elseif( $valueAsGiven=='out' ){
			$this->answerPageFlag('../Actions');
		}elseif( $valueAsGiven=='gone' ){
			$this->answerPageFlag('card', Array('title'=>'Gone'), 404);
		}elseif( $valueAsGiven=='fail' ){
			$this->answerPageFlag('card', Array('title'=>'Never sent'));
			$this->statusCode = 403;
			$this->statusMssg = 'Not yours';
		}else{
			$this->answerPageFlag('card', Array('title'=>'<b>Tom & "Jerry"</b>', 'link'=>$this->routLinkString('items/$id', Array('id'=>'a b'), Array('tab'=>'x y'))));
		}
	}

	/**
	 * Answers with the link to a declared route, or fails on one that is
	 * not declared when called with a parameter.
	 */
	public function link( String $valueAsGiven='' ): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('link'=>($valueAsGiven=='') ? $this->routLinkString('things/$id', Array('id'=>7)) : $this->routLinkString('nosuch/$id', Array('id'=>7)));
	}

	/**
	 * A helper, not an action: must not be reachable by URL.
	 */
	protected function hiddenFromUrls(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('hidden'=>true);
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
		$this->statusCode = 200;
		$this->replyValue = Array(1, 2, 3);
	}

	/**
	 * Raises a warning, then answers with an error status.
	 */
	public function warnfail(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		$this->statusCode = 404;
		$this->statusMssg = 'Nothing here';
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
		$this->statusCode = 200;
		$this->replyValue = Array(strlen($bigText));
	}
}
