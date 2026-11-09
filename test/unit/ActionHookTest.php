<?php

// The lifecycle of a run, through the Controller: the actionset is given its context, beforeAct() runs before
// the action, and endAction() in beforeAct() stops the request with its status, the action not being run.
// The application is test/fixtures/runs/Hooked.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';

use nyu\App\Controller;
use nyu\Tools\Registry;

// The hook runs first, then the action
$conTrol = new Controller( 'hooked', 'ordered', new Registry(Array()) );
checkSame('the action runs', $conTrol->getStatus()['code'], 200);
checkSame('beforeAct runs before the action', $conTrol->getOutput(), Array('beforeAct', 'ordered'));

// endAction() in the hook: its status is the reply's, the action is not run
$conTrol = new Controller( 'hooked', 'ordered', new Registry(Array('refuse'=>'yes')) );
checkSame('a stop in beforeAct keeps its status', $conTrol->getStatus(), Array('code'=>403, 'mssg'=>'Refused before the action'));
checkSame('a stop in beforeAct keeps the action from running', $conTrol->getOutput(), Array('beforeAct'));

// What the actionset is given
$conTrol = new Controller( 'hooked', 'context', new Registry(Array()), '7' );
$ctxVals = $conTrol->getOutput();
checkSame('the action, from the context', $ctxVals['actnName'] ?? null, 'context');
checkSame('the option, from the context', $ctxVals['actnOptn'] ?? null, '7');
checkSame('the application, from the context', $ctxVals['appIdent'] ?? null, 'Hooked');
checkSame('the folder, from the context', $ctxVals['appFoldr'] ?? null, RUN_DIR.DS.'Hooked');
checkTrue('the inputs property is the one of the context', $ctxVals['inputReg'] ?? false);
checkTrue('the models property is the one of the context', $ctxVals['modStack'] ?? false);

// The checks before the actionset is created are unchanged: a name that is not an action is a 404, the hook not run
$conTrol = new Controller( 'hooked', 'nowhere', new Registry(Array('refuse'=>'yes')) );
checkSame('an unknown action is still a 404', $conTrol->getStatus(), Array('code'=>404, 'mssg'=>'Not Found'));

endChecks();
