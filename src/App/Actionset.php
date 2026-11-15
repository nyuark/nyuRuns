<?php

namespace nyu\App;

use nyu\Tools\Registry;
use nyu\Tools\Request;
use nyu\Tools\Router;
use nyu\Tools\Logger;
use nyu\Output\Response;
use nyu\Access\Checker;

/**
 * The actions of an application, which extends it: each of its own public
 * methods is an action. Gives them their helpers: reply with a file, a
 * page or a redirection, link to a route, log, test a role, end the
 * action.
 */
class Actionset {
	
	public 		$replyValue;
	public		$statusCode;
	public		$statusText;
	
	protected 	$inputsRegr;
	protected 	$modelStack;
	protected 	$accessRegr;
	protected 	$requestObj;
	protected 	$respondObj;
	protected 	$routingObj;
	// Action run when the request names none; when empty, the first action declared is used
	protected 	$dfltAction = '';
	// Action being run, as the request named it
	protected 	$actionName = '';
	// What the run is given for the request (see RunContext)
	protected	RunContext $runContext;

	/**
	 * Keeps the context of the request and gives its values to the
	 * properties the actions use. The Controller runs the action after
	 * (see runAction).
	 *
	 * @param RunContext $contextOfRun The action, its inputs, the access result, request, response, router, models and application.
	 */
	function __construct( RunContext $contextOfRun ){
		$this->runContext = $contextOfRun;
		$this->inputsRegr = $contextOfRun->inputsRegr;
		$this->modelStack = $contextOfRun->modelStack;
		$this->accessRegr = $contextOfRun->accessRegr;
		$this->requestObj = $contextOfRun->requestObj;
		$this->respondObj = $contextOfRun->respondObj;
		$this->routingObj = $contextOfRun->routingObj;
		$this->actionName = $contextOfRun->actionName;
	}

	/**
	 * Runs before the action, once the actionset holds the context: the
	 * place to prepare the models the actions share, or to refuse the
	 * request with stopThisAction(), in which case the action is not run and
	 * the status given is the reply's. Does nothing by default.
	 */
	protected function openEachAction(): Void {
	}

	/**
	 * Runs the action of the context if it is one of the application's
	 * actions (see ownAction): openEachAction() first, then the action, given
	 * the option of the context when there is one; falls back to
	 * denyUnknownAct() otherwise. A StopRun thrown by openEachAction() or the action
	 * ends the run and sets its status; one from openEachAction() keeps the
	 * action from running.
	 */
	public function treatAskedActn(): Void {
		if( static::hasActionNamed($this->runContext->actionName) ){
			try{
				$this->openEachAction();
				if($this->runContext->optionText!=null){
					call_user_func( array($this, $this->runContext->actionName), $this->runContext->optionText );
				}else{
					call_user_func( array($this, $this->runContext->actionName) );
				}
			}catch( StopRun $e ){
				// Deliberate stop : its status is the action's, and its output when it carries one
				$this->statusCode = $e->getCode();
				$this->statusText = $e->getMessage();
				if( $e->getOutputOfAct()!==null ){
					$this->replyValue = $e->getOutputOfAct();
				}
			}
		}else{
			$this->denyUnknownAct();
		}
	}

	/**
	 * Tells whether a name is an action of this application: a public,
	 * non-static method that the framework's own Actionset does not declare.
	 * Called on the class of the application, before it is created, by the
	 * Controller.
	 *
	 * @param String $methodToTest Name of the method to check.
	 * @return Bool True when the method can be run as an action.
	 */
	public static function hasActionNamed( String $methodToTest ): Bool {
		if( !method_exists(static::class, $methodToTest) ){
			return false;
		}
		$mthdInst = new \ReflectionMethod(static::class, $methodToTest);
		return $mthdInst->isPublic() && !$mthdInst->isStatic()
			&& substr($mthdInst->getName(), 0, 2)!='__'
			&& $mthdInst->getDeclaringClass()->getName()!=self::class;
	}
	
	/**
	 * Fallback action run when the requested name is not an action of the
	 * actionset; reports a 404 "Not Found" status, as for anything else
	 * the client cannot reach.
	 *
	 * @return Bool Always false.
	 */
	protected function denyUnknownAct(): Bool {
		$this->statusCode = 404;
		$this->statusText = 'Not Found';
		$this->replyValue = null;
		return false;
	}

	/**
	 * Helper for list-style actions: sets the action's status and output from
	 * the rows it found, a 200 with them, a 204 when there is none. A list
	 * that could not be read is the action's to refuse before (see Users::index).
	 *
	 * @param Array $resultsArray The rows of the list.
	 */
	function checkActionLst( Array $resultsArray ): Void {
		if(!empty($resultsArray)){
			$this->statusCode = 200;
			$this->replyValue = $resultsArray;
		}else{
			$this->statusCode = 204;
			$this->statusText = 'List returned no results';
			$this->replyValue = $resultsArray;
		}
	}

	/**
	 * Writes the reply of this action in another format than the one of
	 * the preset (see Response::FORM_LIST): a table ('csvTable') for an
	 * export, raw output ('rawBytes') for a text or a Fluxd sent as it is,
	 * with the Content-Type the action sets.
	 *
	 * @param String $outputFormat The format.
	 *
	 * @throws \RuntimeException When the format is not one of Response::FORM_LIST.
	 */
	protected function setReplyFormat( String $outputFormat ): Void {
		if( $this->respondObj!==null ){
			$this->respondObj->setReplyFormat($outputFormat);
		}
	}

	/**
	 * Helper for file-serving actions: registers a file as the response,
	 * honouring the Range header of the request, and sets the action's
	 * status. A file that cannot be read is reported as a 404.
	 *
	 * @param String $sentFilePath Path of the file to send.
	 * @param String $mimeTypeText Content-Type of the file.
	 * @return Bool True when the file was registered.
	 */
	protected function serveFileReply( String $sentFilePath, String $mimeTypeText ): Bool {
		$rangeTxt = ($this->requestObj!==null) ? strval($this->requestObj->getHeaderValue('Range')) : '';
		if( $this->respondObj===null || !$this->respondObj->setFileToReply($sentFilePath, $mimeTypeText, $rangeTxt) ){
			$this->statusCode = 404;
			$this->statusText = 'Not Found';
			$this->replyValue = null;
			return false;
		}
		$this->statusCode = 200;
		$this->replyValue = null;
		return true;
	}

	/**
	 * Helper for page-serving actions: replies with an HTML page of the
	 * application, pages/<name>.html in its folder, sent as text/html. The
	 * %name% placeholders of the page are filled with the given values,
	 * their HTML characters encoded. A page that cannot be read is
	 * reported as a 404.
	 *
	 * @param String $pageFileName Name of the page, without its extension; a sub-folder is written with slashes.
	 * @param Array  $pageValsList Values of the placeholders, by name.
	 * @param Int    $responseCode HTTP status to reply with.
	 * @return Bool True when the page was registered.
	 */
	protected function servePageReply( String $pageFileName, Array $pageValsList=Array(), Int $responseCode=200 ): Bool {
		$pagePath = $this->runContext->appsFolder.DS.'pages'.DS.str_replace('/', DS, $pageFileName).'.html';
		// Letters, digits, dashes and underscores only : the name cannot lead out of the folder
		$isNameOk = (preg_match('#^[\w\-]+(/[\w\-]+)*$#D', $pageFileName)===1);
		if( !$isNameOk || $this->respondObj===null || !$this->respondObj->setPageToReply($pagePath, $pageValsList, $responseCode) ){
			$this->statusCode = 404;
			$this->statusText = 'Not Found';
			$this->replyValue = null;
			return false;
		}
		$this->statusCode = $responseCode;
		$this->replyValue = null;
		return true;
	}

	/**
	 * Ends the running action at once and sends the client to another
	 * address: a path of this site, starting with one slash, or a full
	 * http(s) address.
	 *
	 * @param String $redirectAddr Address to send the client to.
	 * @param Int    $responseCode Status of the redirection: 301, 302, 303, 307 or 308.
	 *
	 * @throws \RuntimeException When the address or the status is not one of a redirection.
	 */
	protected function redirectClient( String $redirectAddr, Int $responseCode=302 ): Never {
		if( !Router::isRedirectAddr($redirectAddr) || !in_array($responseCode, Router::MOVE_LIST, true) ){
			throw new \RuntimeException('Redirection refused: the address or the status '.$responseCode.' is not one of a redirection');
		}
		if( $this->respondObj!==null ){
			$this->respondObj->addReplyHeader('Location', $redirectAddr);
		}
		$this->stopThisAction($responseCode);
	}

	/**
	 * Builds the link to a declared route (see Router::makeUrlOfRoute). A path
	 * without a leading slash is first one of the routes the Run declares in
	 * its own routes.rte, as written there: the link follows the Run's
	 * current mount (see Router::getMountedPath). When the Run declares no such
	 * route, and for a path with a leading slash, it is the final path of a
	 * route, as the site serves it.
	 *
	 * @param String $routShapeTxt Path of the route, such as profiles/$id: as the Run's file declares it, or its final path.
	 * @param Array  $pathPartVals Values of the named parts of the path.
	 * @param Array  $linkQueryLst Values to add as a query string.
	 * @return String The link, from the root of the site.
	 *
	 * @throws \RuntimeException When no such route is declared, a named part has no value, or no router was given.
	 */
	protected function makeUrlOfRoute( String $routShapeTxt, Array $pathPartVals=Array(), Array $linkQueryLst=Array() ): String {
		if( $this->routingObj===null ){
			throw new \RuntimeException('No router to build a link with');
		}
		$routePth = (substr($routShapeTxt, 0, 1)!='/') ? $this->routingObj->getMountedPath($this->runContext->runAppName, $routShapeTxt) : null;
		return $this->routingObj->makeUrlOfRoute($routePth ?? $routShapeTxt, $pathPartVals, $linkQueryLst);
	}

	/**
	 * Gives the logger of the application: its own channel, a log file
	 * named after the application, in lower case (shows.log for Shows, the example Run of test/fixtures/runs).
	 * It writes with the level methods of Tools\Logger: error(),
	 * warning(), notice(), info() and debug().
	 *
	 * @return Logger The logger of the application.
	 */
	protected function getLoggerOfApp(): Logger {
		return Logger::getLoggerOfChn( strtolower($this->runContext->runAppName) );
	}

	/**
	 * Tells whether the request comes from a logged user holding a role,
	 * or a higher one (see Checker::rleAccessMeets). Nobody logged holds none.
	 *
	 * @param String $requiredRole The role, one of Checker::ROLE_LIST.
	 * @return Bool True when the user holds the role or a higher one.
	 *
	 * @throws \RuntimeException When the role is not one of Checker::ROLE_LIST.
	 */
	protected function hasRoleOrAbove( String $requiredRole ): Bool {
		if( !in_array($requiredRole, Checker::ROLE_LIST, true) ){
			throw new \RuntimeException('Unknown role "'.$requiredRole.'"');
		}
		if( $this->accessRegr===null || $this->accessRegr->readValueByKey('loggedIn')!==true ){
			return false;
		}
		$userRole = $this->accessRegr->readValueByKey('userRole');
		return Checker::rleAccessMeets( is_string($userRole) ? $userRole : null, $requiredRole );
	}

	/**
	 * Ends the running action at once with a 403 when the request comes
	 * with a device token (Access\Auth\Device): a device never changes the
	 * account of its user (its password, its 2FA, its address), which needs
	 * the password login. The refusal is logged on the 'access' channel.
	 */
	protected function needsPassLogin(): Void {
		$idntList = ($this->accessRegr!==null) ? $this->accessRegr->readValueByKey('identity') : null;
		if( !is_array($idntList) || !isset($idntList['device']) ){
			return;
		}
		$nameList = explode('\\', static::class);
		Logger::getLoggerOfChn('access')->warning( 'Account change refused for user '.json_encode(strval($this->accessRegr->readValueByKey('userName'))).' to '.strtolower(strval($nameList[count($nameList)-2] ?? '')).'/'.$this->actionName.': a device ('.json_encode(strval($idntList['device']['name'] ?? '')).') needs the password login' );
		$this->stopThisAction(403, 'Sign in with the password: a device cannot change its account');
	}

	/**
	 * Ends the running action at once with a 404 unless the request comes
	 * from a logged user holding a role, or a higher one (see holdsRole):
	 * the client is not told the action exists. The refusal is logged on
	 * the 'access' channel, as the one of a route asking for a role (see
	 * Checker::logRoleRefusal).
	 *
	 * @param String $requiredRole The role, one of Checker::ROLE_LIST.
	 *
	 * @throws \RuntimeException When the role is not one of Checker::ROLE_LIST.
	 */
	protected function needsActionRle( String $requiredRole ): Void {
		if( $this->hasRoleOrAbove($requiredRole) ){
			return;
		}
		$isLogged = ($this->accessRegr!==null && $this->accessRegr->readValueByKey('loggedIn')===true);
		$userRole = $isLogged ? $this->accessRegr->readValueByKey('userRole') : null;
		$clntRegr = ($this->requestObj!==null) ? $this->requestObj->getClientOfRqt() : null;
		$nameList = explode('\\', static::class);
		Checker::logRoleRefusal(
			$isLogged ? strval($this->accessRegr->readValueByKey('userName')) : '',
			is_string($userRole) ? $userRole : null,
			$requiredRole,
			($clntRegr!==null) ? strval($clntRegr->readValueByKey('userAddr')) : '',
			strtolower(strval($nameList[count($nameList)-2] ?? '')).'/'.$this->actionName
		);
		$this->stopThisAction(404, 'Not Found');
	}

	/**
	 * Ends the running action at once, from the action itself or from any
	 * helper it calls, and replies with the given status.
	 *
	 * @param Int    $responseCode HTTP status to reply with.
	 * @param String $statusReason Message describing a non-2xx status.
	 * @param Array|Object|String|Int|Float|Bool|Null $outputToSend Output to send; null keeps what the action had already set.
	 */
	protected function stopThisAction( Int $responseCode, String $statusReason='', Array|Object|String|Int|Float|Bool|Null $outputToSend=null ): Never {
		throw new StopRun($responseCode, $statusReason, $outputToSend);
	}
}