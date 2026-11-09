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
	public ?String $actnOption;
	// Inputs of the action, once its definition is applied
	public Registry $inptRgstry;
	// Access check result: 'logged', 'user', 'role' and 'ident' of the requester
	public ?Registry $accessRgst;
	// The shared HTTP request (method, headers, files)
	public ?Request $httpRequst;
	// The shared response (headers, file and page replies)
	public ?Response $httpRespns;
	// The router of the request (links to the declared routes)
	public ?Router $rotrObject;
	// The models of the application
	public ?ModelStack $modelStack;
	// Name of the application, as the request named it with its first letter in upper case
	public String $appIdentty;
	// Folder of the application, in runs/
	public String $appsFolder;

	/**
	 * Holds the values a run is given for the request.
	 *
	 * @param String      $runAppAction Name of the action to run.
	 * @param ?String     $runAppOption Extra parameter of the action, or null.
	 * @param Registry    $actionInRgst Inputs of the action.
	 * @param ?Registry   $actionAxRgst Access check result, or null.
	 * @param ?Request    $requstObject The shared HTTP request, or null.
	 * @param ?Response   $httpResponse The shared response, or null.
	 * @param ?Router     $routerObject The router of the request, or null.
	 * @param ?ModelStack $modelsLoader The models of the application, or null.
	 * @param String      $appNameToRun Name of the application.
	 * @param String      $appsFoldText Folder of the application.
	 */
	public function __construct( String $runAppAction, ?String $runAppOption, Registry $actionInRgst, ?Registry $actionAxRgst, ?Request $requstObject, ?Response $httpResponse, ?Router $routerObject, ?ModelStack $modelsLoader, String $appNameToRun, String $appsFoldText ){
		$this->actionName = $runAppAction;
		$this->actnOption = $runAppOption;
		$this->inptRgstry = $actionInRgst;
		$this->accessRgst = $actionAxRgst;
		$this->httpRequst = $requstObject;
		$this->httpRespns = $httpResponse;
		$this->rotrObject = $routerObject;
		$this->modelStack = $modelsLoader;
		$this->appIdentty = $appNameToRun;
		$this->appsFolder = $appsFoldText;
	}
}
