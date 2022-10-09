<?php

namespace nyu\App;

use nyu\Tools\Registry;
use nyu\Tools\Request;
use nyu\Output\Response;

class Actionset {
	
	public 		$asOutput;
	public		$statCode;
	public		$statMssg;
	
	protected 	$inputReg;
	protected 	$modStack;
	protected 	$axessReg;
	protected 	$httpRqst;
	protected 	$httpResp;
	// Action run when the request names none; when empty, the first action declared is used
	protected 	$dfltActn = '';
	
	/**
	 * Stores the input registry and model stack, then runs the requested
	 * action.
	 *
	 * @param String     $actionName Name of the action method to run.
	 * @param String     $actnOption Optional extra parameter for the action.
	 * @param Registry   $inRegistry Registry of input values available to the action.
	 * @param ModelStack $modelStack Preloaded models available to the action.
	 * @param ?Registry  $axRegistry Access check result: 'logged', 'user', 'role' and 'ident' of the requester.
	 * @param ?Request   $requestObj The shared HTTP request (method, headers, files).
	 * @param ?Response  $httpRespns The shared response (headers, file replies).
	 */
	function __construct( String $actionName, ?String $actnOption=null, ?Registry $inRegistry = null, ?ModelStack $modelStack = null, ?Registry $axRegistry = null, ?Request $requestObj = null, ?Response $httpRespns = null ){
		$this->inputReg = $inRegistry;
		$this->modStack = $modelStack;
		$this->axessReg = $axRegistry;
		$this->httpRqst = $requestObj;
		$this->httpResp = $httpRespns;
		$this->runAction( $actionName, $actnOption );
	}
	
	/**
	 * Calls the named action method on this actionset if it is one of the
	 * application's actions (see ownAction), passing the optional parameter
	 * along; falls back to defAction() otherwise. A StopRun thrown while
	 * the action runs ends it and sets its status.
	 *
	 * @param String $actionName Name of the action method to call.
	 * @param String $actnOption Optional extra parameter to pass to the action.
	 */
	function runAction( String $actionName, ?String $actnOption=null ): Void {
		if( $this->ownAction($actionName) ){
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
	 *
	 * @param String $actionName Name of the method to check.
	 * @return Bool True when the method can be run as an action.
	 */
	protected function ownAction( String $actionName ): Bool {
		if( !method_exists($this, $actionName) ){
			return false;
		}
		$mthdObj = new \ReflectionMethod($this, $actionName);
		return $mthdObj->isPublic() && !$mthdObj->isStatic()
			&& substr($mthdObj->getName(), 0, 2)!='__'
			&& $mthdObj->getDeclaringClass()->getName()!=self::class;
	}
	
	/**
	 * Fallback action run when the requested action method doesn't exist
	 * on the actionset; reports a 500 "Unknown Action" status.
	 *
	 * @return Bool Always false.
	 */
	function defAction(): Bool {
		// Raise error
		$this->statCode = 500;
		$this->statMssg = 'Unknown Action';
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
			$this->statMssg = 'An error occured while processing query for this list';
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
			$this->statMssg = 'File not found';
			$this->asOutput = null;
			return false;
		}
		$this->statCode = 200;
		$this->asOutput = null;
		return true;
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