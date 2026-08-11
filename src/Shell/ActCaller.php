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
 * No credential is checked (see Checker::shellPass); the declared routes
 * are not read: the action is named as app/action.
 */
class ActCaller{

	/**
	 * Builds the request and the response of a call that does not come
	 * over HTTP.
	 *
	 * @param String $methodName Method the request is said to use.
	 * @param String $requestURI Path it is said to ask for.
	 * @param Array  $inputsVals Values it carries.
	 * @param Array  $renderVals The 'rnder' block of the preset, for the response.
	 * @param Array  $proxyConfg The reverse proxy settings (see Setup\Loader::proxyConf).
	 * @return Array The Request, then the Response.
	 */
	public static function makeReqst( String $methodName, String $requestURI, Array $inputsVals=Array(), Array $renderVals=Array(), Array $proxyConfg=Array() ): Array {
		// The request reads these, then unsets them : they are given what a call from the command line stands for
		$_SERVER = Array('REQUEST_METHOD'=>strtoupper($methodName), 'REQUEST_URI'=>$requestURI, 'SERVER_NAME'=>'localhost', 'SERVER_PROTOCOL'=>'CLI', 'SCRIPT_NAME'=>'nyuRun', 'QUERY_STRING'=>'');
		$_GET = Array();
		$_POST = $inputsVals;
		$_COOKIE = Array();
		$_FILES = Array();
		$servReg = new ServerReg();
		$clntReg = new ClientReg($servReg, true, $proxyConfg);
		$respObj = new Response($renderVals);
		return Array( new Request($servReg, $respObj, $clntReg), $respObj );
	}

	/**
	 * Runs an action under a preset, and gives its reply.
	 *
	 * @param String $presetName Name of the preset the call runs under.
	 * @param String $actionPath The action, as app/action or app/action/param.
	 * @param Array  $inputsVals Values given to the action, through its input definition.
	 * @param String $methodName Method the request is said to use.
	 * @param String $loggedUser Name the call runs under.
	 * @param String $loggedRole Role the call runs with.
	 * @return Array The status under 'code', its message under 'mssg', the body of the reply under 'body' and, for a redirection, its address under 'goal'.
	 *
	 * @throws ShellStop When the action is not written as app/action.
	 * @throws \RuntimeException When the preset cannot be loaded.
	 */
	public function runAction( String $presetName, String $actionPath, Array $inputsVals, String $methodName, String $loggedUser, String $loggedRole ): Array {
		$pathPrt = Array();
		if( !preg_match('#^([A-Za-z][A-Za-z0-9]*)/(\w+)(?:/(.+))?$#D', trim($actionPath, '/'), $pathPrt) ){
			throw new ShellStop('An action is named as app/action, or app/action/param', Command::EXITUSGE);
		}
		$loadObj = (new Preset($presetName))->makeSetup();
		$GLOBALS['nyuConfig'] = $loadObj->getConfig();
		Logger::plugSetup( $loadObj->logConfig() );
		$rndrCnf = $loadObj->getRegKey('rnder');
		$rteConf = $loadObj->getRegKey('route');
		$callLst = self::makeReqst( $methodName, '/'.trim($actionPath, '/'), $inputsVals, is_array($rndrCnf) ? $rndrCnf : Array(), $loadObj->proxyConf() );
		$rqstObj = $callLst[0];
		$respObj = $callLst[1];
		$inptReg = $loadObj->getInputs($rqstObj);
		$routReg = new Registry( Array('app'=>$pathPrt[1], 'action'=>$pathPrt[2], 'param'=>$pathPrt[3] ?? null, 'rteKind'=>'action', 'rtePath'=>'') );
		$axssReg = (new Checker($loadObj->setAccess(), $rqstObj, $routReg))->shellPass($loggedUser, $loggedRole);
		$statNum = intval($axssReg->getDefVal('statCode', 200));
		$statMsg = strval($axssReg->readValue('statMssg'));
		$outData = null;
		if( $statNum==200 ){
			$ctrlObj = new Controller( $pathPrt[1], $pathPrt[2], $inptReg, $pathPrt[3] ?? null, $axssReg, $rqstObj, $respObj, new Router(is_array($rteConf) ? $rteConf : Array(), $rqstObj) );
			$statArr = $ctrlObj->getStatus();
			$statNum = intval($statArr['code']);
			$statMsg = strval($statArr['mssg']);
			$outData = $ctrlObj->getOutput();
		}
		return $this->replyVals($respObj, $statNum, $statMsg, $outData);
	}

	/**
	 * Builds the reply of a call as the Runner builds the one of a
	 * request: the output of a success, an error body otherwise, nothing
	 * for a redirection or a reply without content.
	 *
	 * @param Response $httpRespns The response the action worked with.
	 * @param Int      $statusCode Status of the action.
	 * @param String   $statusMssg Message of a status that is not a success.
	 * @param mixed    $dataValues Output of the action.
	 * @return Array The 'code', 'mssg', 'body' and 'goal' values (see runAction).
	 */
	protected function replyVals( Response $httpRespns, Int $statusCode, String $statusMssg, Mixed $dataValues ): Array {
		$headLst = $httpRespns->readHeads();
		$bodyTxt = '';
		$isMoved = ($statusCode>=300 && $statusCode<400);
		if( $isMoved || ($statusCode==204 && $dataValues===null) ){
			$httpRespns->clearBody();
		}else{
			if( $statusCode<200 || $statusCode>=300 ){
				// A file or a page is only the reply of a success
				$httpRespns->clearBody();
				$dataValues = Array('error'=>$statusMssg, 'code'=>$statusCode);
			}
			$httpRespns->formatOut( $dataValues ?? Array(), $statusCode<200 || $statusCode>=300 );
			ob_start();
			$httpRespns->sendOutpt();
			$bodyTxt = strval(ob_get_clean());
		}
		return Array('code'=>$statusCode, 'mssg'=>$statusMssg, 'body'=>$bodyTxt, 'goal'=>$isMoved ? strval($headLst['Location'] ?? '') : '');
	}
}
