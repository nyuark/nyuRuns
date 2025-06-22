<?php

// Runner start-up : a failure that happens before the error handler is
// installed (missing or broken preset) is answered as a plain JSON 500, with
// the details in PHP's error log only. Each case runs in a child process
// (test/lib/bootChild.php), where the error log is the standard error stream.

require __DIR__.'/../lib/harness.php';

use nyu\Setup\Preset;

checkThrw('a missing preset throws', function(){ new Preset('nosuchpreset'); }, 'RuntimeException');
checkTrue('the default preset loads', runSafely(function(){ return (new Preset('')) instanceof Preset; }));
checkLike('a missing preset says so', runSafely(function(){ return new Preset('nosuchpreset'); }), '/THROWN.*Preset "nosuchpreset" is missing/');
checkLike('a name leading out of the presets folder is refused', runSafely(function(){ return new Preset('../routes/general'); }), '/THROWN.*Preset "\.\.\/routes\/general" has no valid name/');
checkLike('a name with a dot is refused', runSafely(function(){ return new Preset('dfault.rps'); }), '/THROWN.*Preset "dfault\.rps" has no valid name/');
checkLike('a name with a space is refused', runSafely(function(){ return new Preset('my preset'); }), '/THROWN.*Preset "my preset" has no valid name/');
checkTrue('every preset of the project loads', runSafely(function(){
	foreach( glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps') as $rpsFle ){
		new Preset( pathinfo($rpsFle, PATHINFO_FILENAME) );
	}
	return true;
}));

// What each failing preset is told to be, in the error log
$failLst = Array(
	'nosuchpreset' => 'Preset "nosuchpreset" is missing',
	'broken'       => 'Preset "broken" is not valid JSON: broken\.rps: Invalid JSON content: .+',
	'empty'        => 'Preset "empty" is empty',
	'stale'        => 'Preset "stale" holds an unknown key: setup\.config',
	'nested'       => 'Preset "nested" holds an unknown key: name',
	'no.name'      => 'Preset "no\.name" has no valid name',
);
$jsonTxt = '{"error":"Internal Server Error","code":500}';
$caseNum = 0;
foreach( $failLst as $prsNme => $prsMsg ){
	$outcome = runScript( Array(TST_DIR.DS.'lib'.DS.'bootChild.php', $prsNme, inTempDir('boot_'.(++$caseNum))) );
	checkSame($prsNme.' preset: the reply ends the run with the generic JSON error', substr(trim($outcome['text']), -strlen($jsonTxt)), $jsonTxt);
	checkLike($prsNme.' preset: the details go to the error log', $outcome['text'], '/nyuRuns start-up failure: RuntimeException: '.$prsMsg.' in .*Preset\.php on line \d+/');
	checkSame($prsNme.' preset: nothing else is shown', preg_match('/Fatal error|Stack trace|the runner went on/', $outcome['text']), 0);
}

endChecks();
