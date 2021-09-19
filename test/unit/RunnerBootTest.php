<?php

// Runner start-up : a failure that happens before the error handler is
// installed (missing or broken preset) is answered as a plain JSON 500, with
// the details in PHP's error log only. Each case runs in a child process
// (test/lib/bootChild.php), where the error log is the standard error stream.

require __DIR__.'/../lib/harness.php';

use nyu\Setup\Preset;

checkThrw('a missing preset throws', function(){ new Preset('nosuchpreset'); }, 'RuntimeException');
checkTrue('the default preset loads', runSafely(function(){ return (new Preset('')) instanceof Preset; }));

$jsonTxt = '{"error":"Internal Server Error","code":500}';
foreach( Array('nosuchpreset', 'broken') as $prsNme ){
	$outcome = runScript( Array(TST_DIR.DS.'lib'.DS.'bootChild.php', $prsNme, inTempDir('boot_'.$prsNme)) );
	checkSame($prsNme.' preset: the reply ends the run with the generic JSON error', substr(trim($outcome['text']), -strlen($jsonTxt)), $jsonTxt);
	checkLike($prsNme.' preset: the details go to the error log', $outcome['text'], '/nyuRuns start-up failure: RuntimeException: Preset "'.$prsNme.'" is missing or is not valid JSON in .*Preset\.php on line \d+/');
	checkSame($prsNme.' preset: nothing else is shown', preg_match('/Fatal error|Stack trace|the runner went on/', $outcome['text']), 0);
}

endChecks();
