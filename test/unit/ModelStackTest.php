<?php

// App\ModelStack : loading of an application's models and of the shared
// models, against the fixture runs (see test/fixtures/runs).

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');

require __DIR__.'/../lib/harness.php';

use nyu\App\ModelStack;

$modStck = new ModelStack('Shelf');

// Application models
checkIdentical('an app model is loaded', runCatchThrown(function() use ($modStck){ return get_class($modStck->getModelObject('book')); }), 'nyu\Runs\Shelf\mods\Book');
checkIdentical('an unknown model gives null', runCatchThrown(function() use ($modStck){ return $modStck->getModelObject('nothing'); }), null);
checkIdentical('an app without a mods folder has no model', runCatchThrown(function(){
	$noMods = new ModelStack('Blank');
	return $noMods->getModelObject('book');
}), null);
checkTrueValue('an app model is built once', runCatchThrown(function() use ($modStck){ return $modStck->getModelObject('book')===$modStck->getModelObject('BOOK'); }));
checkIdentical('a model is only made when asked for', runCatchThrown(function(){ return get_class(new ModelStack('Broke')); }), 'nyu\App\ModelStack');
checkPregMatch('a model that cannot be created throws, naming it', runCatchThrown(function(){ return (new ModelStack('Broke'))->getModelObject('wrong'); }), '/^THROWN RuntimeException: Model "Wrong" of "Broke" could not be loaded: /');

// Shared models
checkIdentical('a shared model is loaded', runCatchThrown(function() use ($modStck){ return get_class($modStck->getModelObject('tally')); }), 'nyu\Runs\Shared\Tally');
checkTrueValue('a shared model is built once', runCatchThrown(function() use ($modStck){ return $modStck->getModelObject('tally')===$modStck->getModelObject('tally'); }));
checkPregMatch('a shared model that cannot be created throws, naming it', runCatchThrown(function() use ($modStck){ return $modStck->getModelObject('crack'); }), '/^THROWN RuntimeException: Shared model "crack" could not be loaded: /');

finishChecking();
