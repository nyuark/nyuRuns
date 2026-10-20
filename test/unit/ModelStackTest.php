<?php

// App\ModelStack : loading of an application's models and of the shared
// models, against the fixture runs (see test/fixtures/runs).

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');

require __DIR__.'/../lib/harness.php';

use nyu\App\ModelStack;

$modStck = new ModelStack('Shelf');

// Application models
checkSame('an app model is loaded', runSafely(function() use ($modStck){ return get_class($modStck->readModel('book')); }), 'nyu\Runs\Shelf\mods\Book');
checkSame('an unknown model gives null', runSafely(function() use ($modStck){ return $modStck->readModel('nothing'); }), null);
checkSame('an app without a mods folder has no model', runSafely(function(){
	$noMods = new ModelStack('Blank');
	return $noMods->readModel('book');
}), null);
checkTrue('an app model is built once', runSafely(function() use ($modStck){ return $modStck->readModel('book')===$modStck->readModel('BOOK'); }));
checkSame('a model is only made when asked for', runSafely(function(){ return get_class(new ModelStack('Broke')); }), 'nyu\App\ModelStack');
checkLike('a model that cannot be created throws, naming it', runSafely(function(){ return (new ModelStack('Broke'))->readModel('wrong'); }), '/^THROWN RuntimeException: Model "Wrong" of "Broke" could not be loaded: /');

// Shared models
checkSame('a shared model is loaded', runSafely(function() use ($modStck){ return get_class($modStck->readModel('tally')); }), 'nyu\Runs\Shared\Tally');
checkTrue('a shared model is built once', runSafely(function() use ($modStck){ return $modStck->readModel('tally')===$modStck->readModel('tally'); }));
checkLike('a shared model that cannot be created throws, naming it', runSafely(function() use ($modStck){ return $modStck->readModel('crack'); }), '/^THROWN RuntimeException: Shared model "crack" could not be loaded: /');

endChecks();
