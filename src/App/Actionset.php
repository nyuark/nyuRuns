<?php

namespace nyu\App;

use nyu\Tools\Registry;

class Actionset {
	
	public 		$asOutput;
	public		$statCode;
	public		$statMssg;
	
	protected 	$inputReg;
	protected 	$modStack;
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
	 */
	function __construct( String $actionName, ?String $actnOption=null, ?Registry $inRegistry = null, ?ModelStack $modelStack = null){
		$this->inputReg = $inRegistry;
		$this->modStack = $modelStack;
		$this->runAction( $actionName, $actnOption );
	}
	
	/**
	 * Calls the named action method on this actionset if it is one of the
	 * application's actions (see ownAction), passing the optional parameter
	 * along; falls back to defAction() otherwise.
	 *
	 * @param String $actionName Name of the action method to call.
	 * @param String $actnOption Optional extra parameter to pass to the action.
	 */
	function runAction( String $actionName, ?String $actnOption=null ): Void {
		if( $this->ownAction($actionName) ){
			if($actnOption!=null){
				call_user_func( array($this, $actionName), $actnOption );
			}else{
				call_user_func( array($this, $actionName) );
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
}