<?php

namespace nyu\Shell;

use nyu\Access\Checker;
use nyu\App\Controller;
use nyu\Output\Response;
use nyu\Setup\Preset;
use nyu\Tools\ClientReg;
use nyu\Tools\Logger;
use nyu\Tools\Registry;
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
	 * @param String $reqUriString Path it is said to ask for.
	 * @param Array  $inputsValues Values it carries.
	 * @param Array  $renderValues The 'rnder' block of the preset, for the response.
	 * @param Array  $proxyConfArr The reverse proxy settings (see Setup\Loader::proxySetupConf).
	 * @return Array The Request, then the Response.
	 */
	public static function makeRequestArr( String $actionMthNme, String $reqUriString, Array $inputsValues=Array(), Array $renderValues=Array(), Array $proxyConfArr=Array() ): Array {
		// The request reads these, then unsets them : they are given what a call from the command line stands for
		$_SERVER = Array('REQUEST_METHOD'=>strtoupper($actionMthNme), 'REQUEST_URI'=>$reqUriString, 'SERVER_NAME'=>'localhost', 'SERVER_PROTOCOL'=>'CLI', 'SCRIPT_NAME'=>'nyuRun', 'QUERY_STRING'=>'');
		$_GET = Array();
		$_POST = $inputsValues;
		$_COOKIE = Array();
		$_FILES = Array();
		$srvrReg = new ServerReg();
		$clntReg = new ClientReg($srvrReg, true, $proxyConfArr);
		$respObj = new Response($renderValues);
		return Array( new Request($srvrReg, $respObj, $clntReg), $respObj );
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
		$pthPart = Array();
		if( !preg_match('#^([A-Za-z][A-Za-z0-9]*)/(\w+)(?:/(.+))?$#D', trim($actionTarget, '/'), $pthPart) ){
			throw new ShellStop('An action is named as app/action, or app/action/param', Command::EXIT_HELP);
		}
		$loadObj = (new Preset($nameOfPreset))->newSetupLoader();
		$GLOBALS['nyuConfig'] = $loadObj->retrieveConfig();
		Logger::plugLogsConfig( $loadObj->logConfigArray() );
		$rndrCnf = $loadObj->getRegistryKey('rnder');
		$rteConf = $loadObj->getRegistryKey('route');
		$callLst = self::makeRequestArr( $actionMthNme, '/'.trim($actionTarget, '/'), $inputsValues, is_array($rndrCnf) ? $rndrCnf : Array(), $loadObj->proxySetupConf() );
		$rqstObj = $callLst[0];
		$respObj = $callLst[1];
		$inptReg = $loadObj->retrieveInputs($rqstObj);
		$routReg = new Registry( Array('app'=>$pthPart[1], 'action'=>$pthPart[2], 'param'=>$pthPart[3] ?? null, 'routKind'=>'toAction', 'routPath'=>'') );
		$accsReg = (new Checker($loadObj->setAccessArray(), $rqstObj, $routReg))->grantShellCall($signedInUser, $signedInRole);
		$sttsNum = intval($accsReg->obtainDefValue('statCode', 200));
		$sttsMsg = strval($accsReg->readFileDetail('statMssg'));
		$outData = null;
		if( $sttsNum==200 ){
			$control = new Controller( $pthPart[1], $pthPart[2], $inptReg, $pthPart[3] ?? null, $accsReg, $rqstObj, $respObj, new Router(is_array($rteConf) ? $rteConf : Array(), $rqstObj) );
			$sttsArr = $control->retrieveStatus();
			$sttsNum = intval($sttsArr['code']);
			$sttsMsg = strval($sttsArr['mssg']);
			$outData = $control->retrieveOutput();
		}
		return $this->replyValuesArr($respObj, $sttsNum, $sttsMsg, $outData);
	}

	/**
	 * Builds the reply of a call as the Runner builds the one of a
	 * request: the output of a success, an error body otherwise, nothing
	 * for a redirection or a reply without content.
	 *
	 * @param Response $httpResponse The response the action worked with.
	 * @param Int      $responseCode Status of the action.
	 * @param String   $statusMessag Message of a status that is not a success.
	 * @param mixed    $outputToSend Output of the action.
	 * @return Array The 'code', 'mssg', 'body' and 'goal' values (see runAction).
	 */
	protected function replyValuesArr( Response $httpResponse, Int $responseCode, String $statusMessag, Mixed $outputToSend ): Array {
		$headLst = $httpResponse->readHeadsArray();
		$bodyTxt = '';
		$isMoved = ($responseCode>=300 && $responseCode<400);
		if( $isMoved || ($responseCode==204 && $outputToSend===null) ){
			$httpResponse->clearReplyBody();
		}else{
			if( $responseCode<200 || $responseCode>=300 ){
				// A file or a page is only the reply of a success
				$httpResponse->clearReplyBody();
				$outputToSend = Array('error'=>$statusMessag, 'code'=>$responseCode);
			}
			$httpResponse->convertOutFlag( $outputToSend ?? Array(), $responseCode<200 || $responseCode>=300 );
			ob_start();
			$httpResponse->transmitOutput();
			$bodyTxt = strval(ob_get_clean());
		}
		return Array('code'=>$responseCode, 'mssg'=>$statusMessag, 'body'=>$bodyTxt, 'goal'=>$isMoved ? strval($headLst['Location'] ?? '') : '');
	}
}
