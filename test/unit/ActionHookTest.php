<?php

// The lifecycle of a run, through the Controller: the actionset is given its context, setupForAction() runs before
// the action, and stopThisAction() in setupForAction() stops the request with its status, the action not being run.
// The application is test/fixtures/runs/Hooked.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';

use nyu\App\Controller;
use nyu\Tools\Registry;

// The hook runs first, then the action
$conTrol = new Controller( 'hooked', 'sequence', new Registry(Array()) );
checkIdentical('the action runs', $conTrol->retrieveStatus()['code'], 200);
checkIdentical('beforeAct runs before the action', $conTrol->retrieveOutput(), Array('setupForAction', 'ordered'));

// stopThisAction() in the hook: its status is the reply's, the action is not run
$conTrol = new Controller( 'hooked', 'sequence', new Registry(Array('refuse'=>'yes')) );
checkIdentical('a stop in beforeAct keeps its status', $conTrol->retrieveStatus(), Array('code'=>403, 'mssg'=>'Refused before the action'));
checkIdentical('a stop in beforeAct keeps the action from running', $conTrol->retrieveOutput(), Array('setupForAction'));

// What the actionset is given
$conTrol = new Controller( 'hooked', 'context', new Registry(Array()), '7' );
$ctxVals = $conTrol->retrieveOutput();
checkIdentical('the action, from the context', $ctxVals['actnName'] ?? null, 'context');
checkIdentical('the option, from the context', $ctxVals['actnOptn'] ?? null, '7');
checkIdentical('the application, from the context', $ctxVals['appIdent'] ?? null, 'Hooked');
checkIdentical('the folder, from the context', $ctxVals['appsFldr'] ?? null, RUN_DIR.DS.'Hooked');
checkTrueValue('the inputs property is the one of the context', $ctxVals['inputReg'] ?? false);
checkTrueValue('the models property is the one of the context', $ctxVals['modStack'] ?? false);

// The checks before the actionset is created are unchanged: a name that is not an action is a 404, the hook not run
$conTrol = new Controller( 'hooked', 'nowhere', new Registry(Array('refuse'=>'yes')) );
checkIdentical('an unknown action is still a 404', $conTrol->retrieveStatus(), Array('code'=>404, 'mssg'=>'Not Found'));

finishChecking();
