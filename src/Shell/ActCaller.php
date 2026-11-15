<?php

namespace nyu\Shell;

use nyu\Access\Checker;
use nyu\App\Controller;
use nyu\Output\Response;
use nyu\Setup\Preset;
use nyu\Tools\ClientReg;
use nyu\Tools\Logger;
use nyu\Tools\Request;
use nyu\Tools\Router;
use nyu\Tools\ServerReg;

/**
 * Calls an action of a run from the command line, for automated tasks:
 * the action gets a request, inputs, an access result and a response as
 * it does over HTTP, and its reply is given back in place of being sent.
 * No credential is checked (see Checker::grantShellCall); the declared routes
 * are not read: the action is named as app/action.
 */
class ActCaller{

	/**
	 * Builds the request and the response of a call that does not come
	 * over HTTP.
	 *
	 * @param String $actionMthNme Method the request is said to use.
	 * @param String $reqstUriText Path it is said to ask for.
	 * @param Array  $inputsValues Values it carries.
	 * @param Array  $renderValues The 'response' block of the preset, for the response.
	 * @param Array  $proxyCfgList The reverse proxy settings (see Setup\Loader::proxySetupConf).
	 * @return Array The Request, then the Response.
	 */
	public static function makeRequestArr( String $actionMthNme, String $reqstUriText, Array $inputsValues=Array(), Array $renderValues=Array(), Array $proxyCfgList=Array() ): Array {
		// The request reads these, then unsets them : they are given what a call from the command line stands for
		$_SERVER = Array('REQUEST_METHOD'=>strtoupper($actionMthNme), 'REQUEST_URI'=>$reqstUriText, 'SERVER_NAME'=>'localhost', 'SERVER_PROTOCOL'=>'CLI', 'SCRIPT_NAME'=>'nyuRun', 'QUERY_STRING'=>'');
		$_GET = Array();
		$_POST = $inputsValues;
		$_COOKIE = Array();
		$_FILES = Array();
		$srvrRegr = new ServerReg();
		$clntRegr = new ClientReg($srvrRegr, true, $proxyCfgList);
		$respInst = new Response($renderValues);
		return Array( new Request($srvrRegr, $respInst, $clntRegr), $respInst );
	}

	/**
	 * Runs an action under a preset, and gives its reply.
	 *
	 * @param String $nameOfPreset Name of the preset the call runs under.
	 * @param String $actionTarget The action, as app/action or app/action/param.
	 * @param Array  $inputsValues Values given to the action, through its input definition.
	 * @param String $actionMthNme Method the request is said to use.
	 * @param String $signedInUser Name the call runs under.
	 * @param String $signedInRole Role the call runs with.
	 * @return Array The status under 'code', its message under 'mssg', the body of the reply under 'body' and, for a redirection, its address under 'goal'.
	 *
	 * @throws ShellStop When the action is not written as app/action.
	 * @throws \RuntimeException When the preset cannot be loaded.
	 */
	public function runAskedAction( String $nameOfPreset, String $actionTarget, Array $inputsValues, String $actionMthNme, String $signedInUser, String $signedInRole ): Array {
		// The path is resolved as the Router resolves a request : a Run's own routes and their methods apply
		if( !preg_match('#^[A-Za-z][A-Za-z0-9]*(/[^/\s]+)*$#D', trim($actionTarget, '/')) ){
			throw new ShellStop('An action is named by its path: app, app/action, app/action/param, or a path a route of its Run declares', Command::EXIT_HELP);
		}
		$loadInst = (new Preset($nameOfPreset))->newSetupLoader();
		$GLOBALS['nyuConfig'] = $loadInst->retrieveConfig();
		Logger::plugLogsConfig( $loadInst->logConfigArray() );
		$rndrConf = $loadInst->getRegistryKey('response');
		$callList = self::makeRequestArr( $actionMthNme, '/'.trim($actionTarget, '/'), $inputsValues, is_array($rndrConf) ? $rndrConf : Array(), $loadInst->proxySetupConf() );
		$reqtInst = $callList[0];
		$respInst = $callList[1];
		$inputReg = $loadInst->retrieveInputs($reqtInst);
		$routRegr = $loadInst->importRouteReg($reqtInst);
		$routInst = $loadInst->retrieveRouter();
		// A path no route answers, or a method its route refuses, is the Router's reply (404, 405); only an action is called,		// found from the path (no kind) or by a declared route (toAction)
		$sttsNumb = intval($routRegr->obtainDefValue('statCode', 200));
		$sttsText = strval($routRegr->readValueByKey('statMssg'));
		if( $sttsNumb==200 && !in_array($routRegr->readValueByKey('routKind'), Array(null, 'toAction'), true) ){
			$sttsNumb = 404;
			$sttsText = 'Not Found';
		}
		$outArray = null;
		if( $sttsNumb==200 ){
			$accsRegr = (new Checker($loadInst->setAccessArray(), $reqtInst, $routRegr, $routInst))->grantShellCall($signedInUser, $signedInRole);
			$sttsNumb = intval($accsRegr->obtainDefValue('statCode', 200));
			$sttsText = strval($accsRegr->readValueByKey('statMssg'));
		}
		if( $sttsNumb==200 ){
			$ctrlInst = new Controller( strval($routRegr->readValueByKey('appToRun')), strval($routRegr->readValueByKey('doAction')), $inputReg, $routRegr->readValueByKey('argument'), $accsRegr, $reqtInst, $respInst, $routInst );
			$sttsList = $ctrlInst->retrieveStatus();
			$sttsNumb = intval($sttsList['code']);
			$sttsText = strval($sttsList['mssg']);
			$outArray = $ctrlInst->retrieveOutput();
		}
		return $this->replyValuesArr($respInst, $sttsNumb, $sttsText, $outArray);
	}

	/**
	 * Builds the reply of a call as the Runner builds the one of a
	 * request: the output of a success, an error body otherwise, nothing
	 * for a redirection or a reply without content.
	 *
	 * @param Response $httpResponse The response the action worked with.
	 * @param Int      $responseCode Status of the action.
	 * @param String   $statusReason Message of a status that is not a success.
	 * @param mixed    $outputToSend Output of the action.
	 * @return Array The 'code', 'mssg', 'body' and 'goal' values (see runAction).
	 */
	protected function replyValuesArr( Response $httpResponse, Int $responseCode, String $statusReason, Mixed $outputToSend ): Array {
		$headList = $httpResponse->readHeadsArray();
		$bodyText = '';
		$wasMoved = ($responseCode>=300 && $responseCode<400);
		if( $wasMoved || ($responseCode==204 && $outputToSend===null) ){
			$httpResponse->clearReplyBody();
		}else{
			if( $responseCode<200 || $responseCode>=300 ){
				// A file or a page is only the reply of a success
				$httpResponse->clearReplyBody();
				$outputToSend = Array('error'=>$statusReason, 'code'=>$responseCode);
			}
			$httpResponse->convertOutFlag( $outputToSend ?? Array(), $responseCode<200 || $responseCode>=300 );
			ob_start();
			$httpResponse->transmitOutput();
			$bodyText = strval(ob_get_clean());
		}
		return Array('code'=>$responseCode, 'mssg'=>$statusReason, 'body'=>$bodyText, 'goal'=>$wasMoved ? strval($headList['Location'] ?? '') : '');
	}
}
