<?php

// Runner start-up : a failure that happens before the error handler is
// installed (missing or broken preset) is answered as a plain JSON 500, with
// the details in PHP's error log only. Each case runs in a child process
// (test/lib/bootChild.php), where the error log is the standard error stream.

require __DIR__.'/../lib/harness.php';

use nyu\Setup\Preset;

checkForThrows('a missing preset throws', function(){ new Preset('nosuchpreset'); }, 'RuntimeException');
checkTrueValue('the default preset loads', runCatchThrown(function(){ return (new Preset('')) instanceof Preset; }));
checkPregMatch('a missing preset says so', runCatchThrown(function(){ return new Preset('nosuchpreset'); }), '/THROWN.*Preset "nosuchpreset" is missing/');
checkPregMatch('a name leading out of the presets folder is refused', runCatchThrown(function(){ return new Preset('../routes/general'); }), '/THROWN.*Preset "\.\.\/routes\/general" has no valid name/');
checkPregMatch('a name with a dot is refused', runCatchThrown(function(){ return new Preset('dfault.rps'); }), '/THROWN.*Preset "dfault\.rps" has no valid name/');
checkPregMatch('a name with a space is refused', runCatchThrown(function(){ return new Preset('my preset'); }), '/THROWN.*Preset "my preset" has no valid name/');
checkTrueValue('every preset of the project loads', runCatchThrown(function(){
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
	$outcome = runScriptArray( Array(TST_DIR.DS.'lib'.DS.'bootChild.php', $prsNme, inTemporaryDir('boot_'.(++$caseNum))) );
	checkIdentical($prsNme.' preset: the reply ends the run with the generic JSON error', substr(trim($outcome['text']), -strlen($jsonTxt)), $jsonTxt);
	checkPregMatch($prsNme.' preset: the details go to the error log', $outcome['text'], '/nyuRuns start-up failure: RuntimeException: '.$prsMsg.' in .*Preset\.php on line \d+/');
	checkIdentical($prsNme.' preset: nothing else is shown', preg_match('/Fatal error|Stack trace|the runner went on/', $outcome['text']), 0);
}

finishChecking();
