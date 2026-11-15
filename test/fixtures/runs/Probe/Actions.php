<?php

// Test-only application : actions that misbehave on purpose, served by
// test/lib/httpRouter.php when a request carries the X-Test-Runs header.

namespace nyu\Runs\Probe;

use \nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltAction = 'sanitize';

	/**
	 * Answers normally. Declared first, so it is also the default action.
	 */
	public function sanitize(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('ok'=>true);
	}

	/**
	 * Answers with the parameter it was called with.
	 */
	public function mirrored( String $valueAsGiven ): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('got'=>$valueAsGiven);
	}

	/**
	 * Tells whether the 'user' and 'pass' inputs were read from the
	 * request, whatever its method: 401 when both were, 400 otherwise.
	 */
	public function userPass(): Void {
		$gotBoth = is_string($this->inputsRegr->readValueByKey('user')) && is_string($this->inputsRegr->readValueByKey('pass'));
		$this->statusCode = $gotBoth ? 401 : 400;
		$this->statusText = $gotBoth ? 'Credentials read' : 'Missing user or pass';
	}

	/**
	 * Sets a cookie of its own, then ends the session of the request as a
	 * logout does: the cookie of the application must still go out.
	 */
	public function signsOut(): Void {
		header('Set-Cookie: probeKept=1; path=/', false);
		(new \nyu\Access\Carr\PHPSess( Array('cookName'=>'nyuTests', 'secureOn'=>false) ))->dropUsrSession( $this->requestObj );
		$this->statusCode = 200;
		$this->replyValue = Array('left'=>true);
	}

	/**
	 * Answers with what the access check reported about the requester.
	 */
	public function identify(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array(
			'logged'=>$this->accessRegr->readValueByKey('loggedIn'),
			'user'=>$this->accessRegr->readValueByKey('userName'),
			'role'=>$this->accessRegr->readValueByKey('userRole')
		);
	}

	/**
	 * Answers with the roles the requester holds, after asking for the
	 * role named by its parameter when it is given one.
	 */
	public function roleList( String $valueAsGiven='' ): Void {
		if( $valueAsGiven!='' ){
			$this->needsActionRle($valueAsGiven);
		}
		$this->statusCode = 200;
		$this->replyValue = Array('standard'=>$this->hasRoleOrAbove('standard'), 'advanced'=>$this->hasRoleOrAbove('advanced'), 'managers'=>$this->hasRoleOrAbove('managers'));
	}

	/**
	 * Answers with the users of the native userbase who are online.
	 */
	public function online(): Void {
		$ntvAuth = new \nyu\Access\Auth\Native( Array() );
		$this->statusCode = 200;
		$this->replyValue = Array('online'=>$ntvAuth->listOnlineRecs());
	}

	/**
	 * Raises a warning, then answers "no content" without any output.
	 */
	public function noOutput(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		$this->statusCode = 204;
	}

	/**
	 * Sets a first output, then stops through a helper in the way named by
	 * its parameter. The lines after the stop must never run.
	 */
	public function stopping( String $valueAsGiven ): Void {
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
	public function txtProbe(): Void {
		$this->statusCode = 200;
		$this->replyValue = 'plain text';
	}

	/**
	 * Answers with text that is not valid UTF-8, which JSON cannot carry.
	 */
	public function badBytes(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('name'=>"caf\xE9");
	}

	/**
	 * Answers with the letters fixture file, or with a missing one when
	 * called with a parameter.
	 */
	public function sendFile( String $valueAsGiven='' ): Void {
		$this->serveFileReply( TOP_DIR.DS.'test'.DS.'fixtures'.DS.($valueAsGiven=='' ? 'letters.txt' : 'nosuchfile.txt'), 'audio/mp4' );
	}

	/**
	 * Registers the letters fixture file, then answers with an error status.
	 */
	public function mediaErr(): Void {
		$this->serveFileReply( TOP_DIR.DS.'test'.DS.'fixtures'.DS.'letters.txt', 'audio/mp4' );
		$this->statusCode = 403;
		$this->statusText = 'Not yours';
	}

	/**
	 * Sends the client elsewhere, in the way named by its parameter. The
	 * lines after the redirection must never run.
	 */
	public function redirect( String $valueAsGiven='' ): Void {
		if( $valueAsGiven=='out' ){
			$this->redirectClient('https://example.org/', 303);
		}
		if( $valueAsGiven=='bad' ){
			$this->redirectClient('//example.org/');
		}
		if( $valueAsGiven=='code' ){
			$this->redirectClient('/probe/sanitize', 200);
		}
		$this->replyValue = Array('step'=>1);
		$this->redirectClient('/probe/sanitize');
		$this->statusCode = 200;
		$this->replyValue = Array('step'=>2);
	}

	/**
	 * Answers with the card page of the application, or with a page that
	 * cannot be served, in the way named by its parameter.
	 */
	public function pgeProbe( String $valueAsGiven='' ): Void {
		if( $valueAsGiven=='none' ){
			$this->servePageReply('nosuchpage');
		}elseif( $valueAsGiven=='out' ){
			$this->servePageReply('../Actions');
		}elseif( $valueAsGiven=='gone' ){
			$this->servePageReply('card', Array('title'=>'Gone'), 404);
		}elseif( $valueAsGiven=='fail' ){
			$this->servePageReply('card', Array('title'=>'Never sent'));
			$this->statusCode = 403;
			$this->statusText = 'Not yours';
		}else{
			$this->servePageReply('card', Array('title'=>'<b>Tom & "Jerry"</b>', 'link'=>$this->makeUrlOfRoute('items/$id', Array('id'=>'a b'), Array('tab'=>'x y'))));
		}
	}

	/**
	 * Answers with the link to a declared route, or fails on one that is
	 * not declared when called with a parameter.
	 */
	public function routeUrl( String $valueAsGiven='' ): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('link'=>($valueAsGiven=='') ? $this->makeUrlOfRoute('things/$id', Array('id'=>7)) : $this->makeUrlOfRoute('nosuch/$id', Array('id'=>7)));
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
	public function warnings(): Void {
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
		$this->statusText = 'Nothing here';
	}

	/**
	 * Raises a warning, then throws.
	 */
	public function throwing(): Void {
		trigger_error('probe warning', E_USER_WARNING);
		throw new \RuntimeException('probe failure', 7);
	}

	/**
	 * Exhausts the memory limit, which PHP reports as a fatal error at shutdown.
	 */
	public function fatalErr(): Void {
		ini_set('memory_limit', '24M');
		$bigText = str_repeat('x', 64*1024*1024);
		$this->statusCode = 200;
		$this->replyValue = Array(strlen($bigText));
	}
}
