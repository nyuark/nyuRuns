<?php

// The lifecycle of a run, through the Controller: the actionset is given its context, openEachAction() runs before
// the action, and stopThisAction() in openEachAction() stops the request with its status, the action not being run.
// The application is test/fixtures/runs/Hooked.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';

use nyu\App\Controller;
use nyu\Tools\Registry;

// The hook runs first, then the action
$conTrol = new Controller( 'hooked', 'sequence', new Registry(Array()) );
checkIdentical('the action runs', $conTrol->getStatusOfAct()['code'], 200);
checkIdentical('beforeAct runs before the action', $conTrol->getOutputOfAct(), Array('openEachAction', 'ordered'));

// stopThisAction() in the hook: its status is the reply's, the action is not run
$conTrol = new Controller( 'hooked', 'sequence', new Registry(Array('refuse'=>'yes')) );
checkIdentical('a stop in beforeAct keeps its status', $conTrol->getStatusOfAct(), Array('code'=>403, 'mssg'=>'Refused before the action'));
checkIdentical('a stop in beforeAct keeps the action from running', $conTrol->getOutputOfAct(), Array('openEachAction'));

// What the actionset is given
$conTrol = new Controller( 'hooked', 'context', new Registry(Array()), '7' );
$ctxVals = $conTrol->getOutputOfAct();
checkIdentical('the action, from the context', $ctxVals['actnName'] ?? null, 'context');
checkIdentical('the option, from the context', $ctxVals['actnOptn'] ?? null, '7');
checkIdentical('the application, from the context', $ctxVals['appIdent'] ?? null, 'Hooked');
checkIdentical('the folder, from the context', $ctxVals['appsFldr'] ?? null, RUN_DIR.DS.'Hooked');
checkTrueValue('the inputs property is the one of the context', $ctxVals['inputReg'] ?? false);
checkTrueValue('the models property is the one of the context', $ctxVals['modStack'] ?? false);

// The checks before the actionset is created are unchanged: a name that is not an action is a 404, the hook not run
$conTrol = new Controller( 'hooked', 'nowhere', new Registry(Array('refuse'=>'yes')) );
checkIdentical('an unknown action is still a 404', $conTrol->getStatusOfAct(), Array('code'=>404, 'mssg'=>'Not Found'));

// A Run without $dfltAction has no default action: a request naming none is a 500, the first action no longer standing in for it
checkIdentical('no $dfltAction: a request naming no action is a 500', (new Controller( 'hooked', '', new Registry(Array()) ))->getStatusOfAct(), Array('code'=>500, 'mssg'=>'Internal Server Error'));

finishChecking();
