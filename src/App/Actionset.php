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
	
	public 		$asOutput;
	public		$statCode;
	public		$statMssg;
	
	protected 	$inputReg;
	protected 	$modStack;
	protected 	$axessReg;
	protected 	$httpRqst;
	protected 	$httpResp;
	protected 	$routrObj;
	// Action run when the request names none; when empty, the first action declared is used
	protected 	$dfltActn = '';
	// Action being run, as the request named it
	protected 	$actnName = '';
	
	/**
	 * Stores the input registry and model stack, then runs the requested
	 * action.
	 *
	 * @param String     $actionName Name of the action method to run.
	 * @param ?String    $actnOption Optional extra parameter for the action.
	 * @param ?Registry  $inRegistry Registry of input values available to the action.
	 * @param ?ModelStack $modelStack Preloaded models available to the action.
	 * @param ?Registry  $axRegistry Access check result: 'logged', 'user', 'role' and 'ident' of the requester.
	 * @param ?Request   $requestObj The shared HTTP request (method, headers, files).
	 * @param ?Response  $httpRespns The shared response (headers, file replies).
	 * @param ?Router    $routingObj The router of the request (links to the declared routes).
	 */
	function __construct( String $actionName, ?String $actnOption=null, ?Registry $inRegistry = null, ?ModelStack $modelStack = null, ?Registry $axRegistry = null, ?Request $requestObj = null, ?Response $httpRespns = null, ?Router $routingObj = null ){
		$this->inputReg = $inRegistry;
		$this->modStack = $modelStack;
		$this->axessReg = $axRegistry;
		$this->httpRqst = $requestObj;
		$this->httpResp = $httpRespns;
		$this->routrObj = $routingObj;
		$this->runAction( $actionName, $actnOption );
	}
	
	/**
	 * Calls the named action method on this actionset if it is one of the
	 * application's actions (see ownAction), passing the optional parameter
	 * along; falls back to defAction() otherwise. A StopRun thrown while
	 * the action runs ends it and sets its status.
	 *
	 * @param String $actionName Name of the action method to call.
	 * @param ?String $actnOption Optional extra parameter to pass to the action.
	 */
	function runAction( String $actionName, ?String $actnOption=null ): Void {
		$this->actnName = $actionName;
		if( static::ownAction($actionName) ){
			try{
				if($actnOption!=null){
					call_user_func( array($this, $actionName), $actnOption );
				}else{
					call_user_func( array($this, $actionName) );
				}
			}catch( StopRun $e ){
				// Deliberate stop : its status is the action's, and its output when it carries one
				$this->statCode = $e->getCode();
				$this->statMssg = $e->getMessage();
				if( $e->getOutput()!==null ){
					$this->asOutput = $e->getOutput();
				}
			}
		}else{
			$this->defAction();
		}
	}

	/**
	 * Tells whether a name is an action of this application: a public,
	 * non-static method that the framework's own Actionset does not declare.
	 * Called on the class of the application, before it is created, by the
	 * Controller.
	 *
	 * @param String $actionName Name of the method to check.
	 * @return Bool True when the method can be run as an action.
	 */
	public static function ownAction( String $actionName ): Bool {
		if( !method_exists(static::class, $actionName) ){
			return false;
		}
		$mthdObj = new \ReflectionMethod(static::class, $actionName);
		return $mthdObj->isPublic() && !$mthdObj->isStatic()
			&& substr($mthdObj->getName(), 0, 2)!='__'
			&& $mthdObj->getDeclaringClass()->getName()!=self::class;
	}
	
	/**
	 * Fallback action run when the requested name is not an action of the
	 * actionset; reports a 404 "Not Found" status, as for anything else
	 * the client cannot reach.
	 *
	 * @return Bool Always false.
	 */
	function defAction(): Bool {
		$this->statCode = 404;
		$this->statMssg = 'Not Found';
		$this->asOutput = null;
		return false;
	}

	/**
	 * Helper for list-style actions: sets the action's status/output based
	 * on whether the given results are present and non-empty.
	 *
	 * @param Array $resultsArr The query/list results to report, or false on failure.
	 */
	function checkList( Array $resultsArr ): Void {
		if($resultsArr!==false){
			if(!empty($resultsArr)){
				$this->statCode = 200;
				$this->asOutput = $resultsArr;
			}else{
				$this->statCode = 204;
				$this->statMssg = 'List returned no results';
				$this->asOutput = $resultsArr;
			}
		}else{
			$this->statCode = 500;
			$this->statMssg = 'An error occurred while processing query for this list';
		}
	}

	/**
	 * Writes the reply of this action in another format than the one of
	 * the preset (see Response::FORMTLST): a table ('tabl') for an
	 * export, raw output ('flux') for a text or a Fluxd sent as it is,
	 * with the Content-Type the action sets.
	 *
	 * @param String $formatCode The format.
	 *
	 * @throws \RuntimeException When the format is not one of Response::FORMTLST.
	 */
	protected function useFormat( String $formatCode ): Void {
		if( $this->httpResp!==null ){
			$this->httpResp->useFormat($formatCode);
		}
	}

	/**
	 * Helper for file-serving actions: registers a file as the response,
	 * honouring the Range header of the request, and sets the action's
	 * status. A file that cannot be read is reported as a 404.
	 *
	 * @param String $sourcePath Path of the file to send.
	 * @param String $mimeString Content-Type of the file.
	 * @return Bool True when the file was registered.
	 */
	protected function replyFile( String $sourcePath, String $mimeString ): Bool {
		$rangStr = ($this->httpRqst!==null) ? strval($this->httpRqst->getHeader('Range')) : '';
		if( $this->httpResp===null || !$this->httpResp->fileReply($sourcePath, $mimeString, $rangStr) ){
			$this->statCode = 404;
			$this->statMssg = 'Not Found';
			$this->asOutput = null;
			return false;
		}
		$this->statCode = 200;
		$this->asOutput = null;
		return true;
	}

	/**
	 * Helper for page-serving actions: replies with an HTML page of the
	 * application, pages/<name>.html in its folder, sent as text/html. The
	 * %name% placeholders of the page are filled with the given values,
	 * their HTML characters encoded. A page that cannot be read is
	 * reported as a 404.
	 *
	 * @param String $sourceName Name of the page, without its extension; a sub-folder is written with slashes.
	 * @param Array  $pageValues Values of the placeholders, by name.
	 * @param Int    $statusCode HTTP status to reply with.
	 * @return Bool True when the page was registered.
	 */
	protected function replyPage( String $sourceName, Array $pageValues=Array(), Int $statusCode=200 ): Bool {
		$appFldr = dirname( strval((new \ReflectionClass($this))->getFileName()) );
		$pagePth = $appFldr.DS.'pages'.DS.str_replace('/', DS, $sourceName).'.html';
		// Letters, digits, dashes and underscores only : the name cannot lead out of the folder
		$isNamed = (preg_match('#^[\w\-]+(/[\w\-]+)*$#D', $sourceName)===1);
		if( !$isNamed || $this->httpResp===null || !$this->httpResp->pageReply($pagePth, $pageValues, $statusCode) ){
			$this->statCode = 404;
			$this->statMssg = 'Not Found';
			$this->asOutput = null;
			return false;
		}
		$this->statCode = $statusCode;
		$this->asOutput = null;
		return true;
	}

	/**
	 * Ends the running action at once and sends the client to another
	 * address: a path of this site, starting with one slash, or a full
	 * http(s) address.
	 *
	 * @param String $targetAddr Address to send the client to.
	 * @param Int    $statusCode Status of the redirection: 301, 302, 303, 307 or 308.
	 *
	 * @throws \RuntimeException When the address or the status is not one of a redirection.
	 */
	protected function replyMove( String $targetAddr, Int $statusCode=302 ): Never {
		if( !Router::validAddr($targetAddr) || !in_array($statusCode, Router::MOVELIST, true) ){
			throw new \RuntimeException('Redirection refused: the address or the status '.$statusCode.' is not one of a redirection');
		}
		if( $this->httpResp!==null ){
			$this->httpResp->addHeader('Location', $targetAddr);
		}
		$this->endAction($statusCode);
	}

	/**
	 * Builds the link to a declared route, from the path it is declared
	 * with (see Router::buildLink).
	 *
	 * @param String $routeShape Path the route is declared with, such as profiles/$id.
	 * @param Array  $pathValues Values of the named parts of the path.
	 * @param Array  $queryParam Values to add as a query string.
	 * @return String The link, from the root of the site.
	 *
	 * @throws \RuntimeException When no such route is declared, a named part has no value, or no router was given.
	 */
	protected function routeLink( String $routeShape, Array $pathValues=Array(), Array $queryParam=Array() ): String {
		if( $this->routrObj===null ){
			throw new \RuntimeException('No router to build a link with');
		}
		return $this->routrObj->buildLink($routeShape, $pathValues, $queryParam);
	}

	/**
	 * Gives the logger of the application: its own channel, a log file
	 * named after the application, in lower case (shows.log for Shows).
	 * It writes with the level methods of Tools\Logger: error(),
	 * warning(), notice(), info() and debug().
	 *
	 * @return Logger The logger of the application.
	 */
	protected function appLogger(): Logger {
		$nameLst = explode('\\', static::class);
		return Logger::getLogger( strtolower(strval($nameLst[count($nameLst)-2] ?? 'runner')) );
	}

	/**
	 * Tells whether the request comes from a logged user holding a role,
	 * or a higher one (see Checker::roleMeets). Nobody logged holds none.
	 *
	 * @param String $neededRole The role, one of Checker::ROLELIST.
	 * @return Bool True when the user holds the role or a higher one.
	 *
	 * @throws \RuntimeException When the role is not one of Checker::ROLELIST.
	 */
	protected function holdsRole( String $neededRole ): Bool {
		if( !in_array($neededRole, Checker::ROLELIST, true) ){
			throw new \RuntimeException('Unknown role "'.$neededRole.'"');
		}
		if( $this->axessReg===null || $this->axessReg->readValue('logged')!==true ){
			return false;
		}
		$usrRole = $this->axessReg->readValue('role');
		return Checker::roleMeets( is_string($usrRole) ? $usrRole : null, $neededRole );
	}

	/**
	 * Ends the running action at once with a 404 unless the request comes
	 * from a logged user holding a role, or a higher one (see holdsRole):
	 * the client is not told the action exists. The refusal is logged on
	 * the 'access' channel, as the one of a route asking for a role (see
	 * Checker::logDenial).
	 *
	 * @param String $neededRole The role, one of Checker::ROLELIST.
	 *
	 * @throws \RuntimeException When the role is not one of Checker::ROLELIST.
	 */
	protected function needsRole( String $neededRole ): Void {
		if( $this->holdsRole($neededRole) ){
			return;
		}
		$isLoggd = ($this->axessReg!==null && $this->axessReg->readValue('logged')===true);
		$usrRole = $isLoggd ? $this->axessReg->readValue('role') : null;
		$clntReg = ($this->httpRqst!==null) ? $this->httpRqst->getClient() : null;
		$nameLst = explode('\\', static::class);
		Checker::logDenial(
			$isLoggd ? strval($this->axessReg->readValue('user')) : '',
			is_string($usrRole) ? $usrRole : null,
			$neededRole,
			($clntReg!==null) ? strval($clntReg->readValue('userAddr')) : '',
			strtolower(strval($nameLst[count($nameLst)-2] ?? '')).'/'.$this->actnName
		);
		$this->endAction(404, 'Not Found');
	}

	/**
	 * Ends the running action at once, from the action itself or from any
	 * helper it calls, and replies with the given status.
	 *
	 * @param Int    $statusCode HTTP status to reply with.
	 * @param String $statusMssg Message describing a non-2xx status.
	 * @param Array|Object|String|Int|Float|Bool|Null $dataValues Output to send; null keeps what the action had already set.
	 */
	protected function endAction( Int $statusCode, String $statusMssg='', Array|Object|String|Int|Float|Bool|Null $dataValues=null ): Never {
		throw new StopRun($statusCode, $statusMssg, $dataValues);
	}
}