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
	public String $actionName;
	// Extra parameter of the action, from the path
	public ?String $optionText;
	// Inputs of the action, once its definition is applied
	public Registry $inputsRegr;
	// Access check result: 'loggedIn', 'userName', 'userRole' and 'identity' of the requester
	public ?Registry $accessRegr;
	// The shared HTTP request (method, headers, files)
	public ?Request $requestObj;
	// The shared response (headers, file and page replies)
	public ?Response $respondObj;
	// The router of the request (links to the declared routes)
	public ?Router $routingObj;
	// The models of the application
	public ?ModelStack $modelStack;
	// Name of the application, as the request named it with its first letter in upper case
	public String $runAppName;
	// Folder of the application, in runs/
	public String $appsFolder;

	/**
	 * Holds the values a run is given for the request.
	 *
	 * @param String      $runAppAction Name of the action to run.
	 * @param ?String     $runAppOption Extra parameter of the action, or null.
	 * @param Registry    $runInputsReg Inputs of the action.
	 * @param ?Registry   $accsCheckReg Access check result, or null.
	 * @param ?Request    $givenRequest The shared HTTP request, or null.
	 * @param ?Response   $httpResponse The shared response, or null.
	 * @param ?Router     $routerObject The router of the request, or null.
	 * @param ?ModelStack $modelsLoader The models of the application, or null.
	 * @param String      $appNameToRun Name of the application.
	 * @param String      $appsFldrText Folder of the application.
	 */
	public function __construct( String $runAppAction, ?String $runAppOption, Registry $runInputsReg, ?Registry $accsCheckReg, ?Request $givenRequest, ?Response $httpResponse, ?Router $routerObject, ?ModelStack $modelsLoader, String $appNameToRun, String $appsFldrText ){
		$this->actionName = $runAppAction;
		$this->optionText = $runAppOption;
		$this->inputsRegr = $runInputsReg;
		$this->accessRegr = $accsCheckReg;
		$this->requestObj = $givenRequest;
		$this->respondObj = $httpResponse;
		$this->routingObj = $routerObject;
		$this->modelStack = $modelsLoader;
		$this->runAppName = $appNameToRun;
		$this->appsFolder = $appsFldrText;
	}
}
