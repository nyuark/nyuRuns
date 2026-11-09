<?php

// The context a run is given (App\RunContext): the values it is built with, read only.

require __DIR__.'/../lib/harness.php';

use nyu\App\RunContext;
use nyu\Tools\Registry;

$inptReg = new Registry( Array('name'=>'Alpha') );
$axesReg = new Registry( Array('logged'=>true, 'user'=>'anna', 'role'=>'member') );
$ctxtObj = new RunContext( 'viewShow', '12', $inptReg, $axesReg, null, null, null, null, 'Shows', RUN_DIR.DS.'Shows' );

checkIdentical('the action', $ctxtObj->actionName, 'viewShow');
checkIdentical('the option of the action', $ctxtObj->actnOption, '12');
checkTrueValue('the inputs, as given', $ctxtObj->inptRgstry===$inptReg);
checkTrueValue('the access result, as given', $ctxtObj->accessRgst===$axesReg);
checkIdentical('no request', $ctxtObj->httpRequst, null);
checkIdentical('no response', $ctxtObj->httpRespns, null);
checkIdentical('no router', $ctxtObj->rotrObject, null);
checkIdentical('no models', $ctxtObj->modelStack, null);
checkIdentical('the application', $ctxtObj->appIdentty, 'Shows');
checkIdentical('the folder of the application', $ctxtObj->appsFolder, RUN_DIR.DS.'Shows');

$nullCtx = new RunContext( 'overview', null, new Registry(Array()), null, null, null, null, null, 'Shows', '' );
checkIdentical('an action without an option', $nullCtx->actnOption, null);

// Read only: no property can be changed, none added
checkTrueValue('the class is read only', (new ReflectionClass(RunContext::class))->isReadOnly());
checkForThrows('the action cannot be changed', function() use ($ctxtObj){ $ctxtObj->actionName = 'remove'; }, 'Error');
checkForThrows('the inputs cannot be replaced', function() use ($ctxtObj){ $ctxtObj->inptRgstry = new Registry(Array()); }, 'Error');
checkForThrows('the folder cannot be changed', function() use ($ctxtObj){ $ctxtObj->appsFolder = '/tmp'; }, 'Error');
checkForThrows('no property can be added', function() use ($ctxtObj){ $ctxtObj->diConObj = new stdClass(); }, 'Error');
checkIdentical('the action is still the one given', $ctxtObj->actionName, 'viewShow');

finishChecking();
