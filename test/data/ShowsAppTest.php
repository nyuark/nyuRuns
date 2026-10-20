<?php

// runs/Shows : the Single and Listing models and the index / view actions,
// run through the framework's Actionset and ModelStack against the sample
// SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Shows app', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\App\ModelStack;
use nyu\Tools\Registry;

buildData( inTempDir('shows.sqlite') );
useSqlite( inTempDir('shows.sqlite') );
require RUN_DIR.DS.'Shows'.DS.'Actions.php';

/**
 * Runs an action of the Shows application.
 *
 * @param String  $actionName Action to run.
 * @param ?String $actnOption Option passed to the action.
 * @param Array   $inputArray Input values available to the action.
 * @return mixed Array with 'code', 'mssg' and 'out', or a 'THROWN ...' string.
 */
function runAction( String $actionName, ?String $actnOption=null, Array $inputArray=Array() ): Mixed {
	return runSafely(function() use ($actionName, $actnOption, $inputArray){
		$actnSet = new nyu\Runs\Shows\Actions( $actionName, $actnOption, new Registry($inputArray), new ModelStack('Shows') );
		return Array('code'=>$actnSet->statCode, 'mssg'=>$actnSet->statMssg, 'out'=>$actnSet->asOutput);
	});
}

/**
 * Reduces a list of shows to their titles.
 *
 * @param mixed $actnResult Result of runAction() for a list action.
 * @return mixed The titles, or the result itself when it is not a list.
 */
function titleList( Mixed $actnResult ): Mixed {
	return is_array($actnResult) && is_array($actnResult['out']) ? array_column($actnResult['out'], 'title') : $actnResult;
}

// index
$outcome = runAction('index');
checkSame('index: status', $outcome['code'] ?? $outcome, 200);
checkSame('index: every show', titleList($outcome), Array('Alpha Night', 'Beta Evening', 'Alpha Matinee', 'Solo Gig', 'Empty Stage'));
checkSame('index: items are flattened to their types', array_column($outcome['out'] ?? Array(), 'Items'),
	Array(Array('music', 'dance'), Array('theatre', 'music'), Array('dance', 'circus'), Array('solo'), Array()));
checkSame('index: event spans', array_column($outcome['out'] ?? Array(), 'evntSpan'), Array(
	'From 28 December 2026 until 03 January 2027',
	'From 02 until 09 November 2026',
	'From 05 until 06 October 2026',
	'01 December 2026',
	false
));

checkSame('index: search on the title', titleList(runAction('index', null, Array('filters'=>Array('search'=>'Alpha*')))), Array('Alpha Night', 'Alpha Matinee'));
checkSame('index: category filter', titleList(runAction('index', null, Array('filters'=>Array('categ'=>'music')))), Array('Alpha Night', 'Beta Evening'));
checkSame('index: search and category together', titleList(runAction('index', null, Array('filters'=>Array('search'=>'Alpha*', 'categ'=>'circus')))), Array('Alpha Matinee'));
checkSame('index: exactly one match', titleList(runAction('index', null, Array('filters'=>Array('search'=>'Beta*')))), Array('Beta Evening'));
checkSame('index: a category given as an array is ignored', titleList(runAction('index', null, Array('filters'=>Array('search'=>'Beta*', 'categ'=>Array('x'))))), Array('Beta Evening'));
checkSame('index: sorting', titleList(runAction('index', null, Array('sorting'=>'^id_show', 'indexes'=>'2'))), Array('Empty Stage', 'Solo Gig'));

$outcome = runAction('index', null, Array('filters'=>Array('search'=>'Zzz*')));
checkSame('index: no match is 204', $outcome['code'] ?? $outcome, 204);
checkSame('index: no match output', $outcome['out'] ?? $outcome, Array());
checkSame('index: no category match is 204', runAction('index', null, Array('filters'=>Array('categ'=>'opera')))['code'] ?? null, 204);

// view
$outcome = runAction('view', '1');
checkSame('view: status', $outcome['code'] ?? $outcome, 200);
checkSame('view: record', $outcome['out']['title'] ?? null, 'Alpha Night');
checkSame('view: events are loaded in date order', array_column($outcome['out']['Events'] ?? Array(), 'date'), Array('2026-12-28', '2027-01-03'));
checkSame('view: items', $outcome['out']['Items'] ?? null, Array('music', 'dance'));
checkSame('view: event span', $outcome['out']['evntSpan'] ?? null, 'From 28 December 2026 until 03 January 2027');

$outcome = runAction('view', '4');
checkSame('view: single event and item', Array($outcome['out']['Items'] ?? null, $outcome['out']['evntSpan'] ?? null), Array(Array('solo'), '01 December 2026'));

$outcome = runAction('view', '5');
checkSame('view: show without events or items', $outcome['out'] ?? $outcome,
	Array('id_show'=>5, 'title'=>'Empty Stage', 'venue'=>null, 'Events'=>Array(), 'Items'=>Array(), 'evntSpan'=>false));

$outcome = runAction('view', '99');
checkSame('view: missing show is 404', Array($outcome['code'] ?? null, $outcome['mssg'] ?? null), Array(404, 'Show not found'));

// unknown action
$outcome = runAction('nope');
checkSame('unknown action is 404', Array($outcome['code'] ?? null, $outcome['mssg'] ?? null, $outcome['out'] ?? null), Array(404, 'Not Found', null));

endChecks();
