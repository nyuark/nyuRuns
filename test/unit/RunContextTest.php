<?php

// The context a run is given (App\RunContext): the values it is built with, read only.

require __DIR__.'/../lib/harness.php';

use nyu\App\RunContext;
use nyu\Tools\Registry;

$inptReg = new Registry( Array('name'=>'Alpha') );
$axesReg = new Registry( Array('logged'=>true, 'user'=>'anna', 'role'=>'member') );
$ctxtObj = new RunContext( 'view', '12', $inptReg, $axesReg, null, null, null, null, 'Shows', RUN_DIR.DS.'Shows' );

checkSame('the action', $ctxtObj->actnName, 'view');
checkSame('the option of the action', $ctxtObj->actnOptn, '12');
checkTrue('the inputs, as given', $ctxtObj->inputReg===$inptReg);
checkTrue('the access result, as given', $ctxtObj->axessReg===$axesReg);
checkSame('no request', $ctxtObj->httpRqst, null);
checkSame('no response', $ctxtObj->httpResp, null);
checkSame('no router', $ctxtObj->routrObj, null);
checkSame('no models', $ctxtObj->modStack, null);
checkSame('the application', $ctxtObj->appIdent, 'Shows');
checkSame('the folder of the application', $ctxtObj->appFoldr, RUN_DIR.DS.'Shows');

$nullCtx = new RunContext( 'index', null, new Registry(Array()), null, null, null, null, null, 'Shows', '' );
checkSame('an action without an option', $nullCtx->actnOptn, null);

// Read only: no property can be changed, none added
checkTrue('the class is read only', (new ReflectionClass(RunContext::class))->isReadOnly());
checkThrw('the action cannot be changed', function() use ($ctxtObj){ $ctxtObj->actnName = 'remove'; }, 'Error');
checkThrw('the inputs cannot be replaced', function() use ($ctxtObj){ $ctxtObj->inputReg = new Registry(Array()); }, 'Error');
checkThrw('the folder cannot be changed', function() use ($ctxtObj){ $ctxtObj->appFoldr = '/tmp'; }, 'Error');
checkThrw('no property can be added', function() use ($ctxtObj){ $ctxtObj->diConObj = new stdClass(); }, 'Error');
checkSame('the action is still the one given', $ctxtObj->actnName, 'view');

endChecks();
