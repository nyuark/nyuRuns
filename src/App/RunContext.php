<?php

namespace nyu\App;

use nyu\Tools\Registry;
use nyu\Tools\Request;
use nyu\Tools\Router;
use nyu\Output\Response;

/**
 * What a run is given for one request: the action asked for, its inputs,
 * the result of the access check, the request, the response, the router,
 * the models and the application itself. Built by the Controller once the
 * inputs and the models are ready, read only: values, never the container.
 */
readonly class RunContext {

	// Action being run, as the request named it (or the default action)
	public String $actnName;
	// Extra parameter of the action, from the path
	public ?String $actnOptn;
	// Inputs of the action, once its definition is applied
	public Registry $inputReg;
	// Access check result: 'logged', 'user', 'role' and 'ident' of the requester
	public ?Registry $axessReg;
	// The shared HTTP request (method, headers, files)
	public ?Request $httpRqst;
	// The shared response (headers, file and page replies)
	public ?Response $httpResp;
	// The router of the request (links to the declared routes)
	public ?Router $routrObj;
	// The models of the application
	public ?ModelStack $modStack;
	// Name of the application, as the request named it with its first letter in upper case
	public String $appIdent;
	// Folder of the application, in runs/
	public String $appFoldr;

	/**
	 * Holds the values a run is given for the request.
	 *
	 * @param String      $runAppActn Name of the action to run.
	 * @param ?String     $runAppOptn Extra parameter of the action, or null.
	 * @param Registry    $inRegistry Inputs of the action.
	 * @param ?Registry   $axRegistry Access check result, or null.
	 * @param ?Request    $requestObj The shared HTTP request, or null.
	 * @param ?Response   $httpRespns The shared response, or null.
	 * @param ?Router     $routingObj The router of the request, or null.
	 * @param ?ModelStack $modelStack The models of the application, or null.
	 * @param String      $runAppName Name of the application.
	 * @param String      $appsFolder Folder of the application.
	 */
	public function __construct( String $runAppActn, ?String $runAppOptn, Registry $inRegistry, ?Registry $axRegistry, ?Request $requestObj, ?Response $httpRespns, ?Router $routingObj, ?ModelStack $modelStack, String $runAppName, String $appsFolder ){
		$this->actnName = $runAppActn;
		$this->actnOptn = $runAppOptn;
		$this->inputReg = $inRegistry;
		$this->axessReg = $axRegistry;
		$this->httpRqst = $requestObj;
		$this->httpResp = $httpRespns;
		$this->routrObj = $routingObj;
		$this->modStack = $modelStack;
		$this->appIdent = $runAppName;
		$this->appFoldr = $appsFolder;
	}
}
