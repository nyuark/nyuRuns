<?php

// makerun (Shell\Cmds\RunGen, RunSpec, RunMaker): a Run generated from the
// shows table of the sample database (test/lib/sample.php), its events and
// items as children, into a project folder of the test's own; its actions
// then called through the Controller, and its routes read by the Router.
// The Run it generates is the example Run of the framework.

// The Runs are those of the project folder of the test: RUN_DIR is set before the harness, at the folder makeProjectTxt() makes
define('RUN_DIR', sys_get_temp_dir().DIRECTORY_SEPARATOR.'nyuruns_test_'.getmypid().DIRECTORY_SEPARATOR.'rungen'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require __DIR__.'/../lib/shellKit.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('makerun', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\App\Controller;
use nyu\Tools\Registry;

$basePth = inTemporaryDir('rungen.sqlite');
createSampleDb($basePth);
$projDir = makeProjectTxt('rungen', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/database.json'=>json_encode(Array('dbEngine'=>'sqlitedb', 'hostName'=>'', 'baseName'=>$basePth, 'userName'=>'', 'passWord'=>''))
));
// The command reads the Runs of the project when it has a runs folder
@mkdir(RUN_DIR, 0775, true);

// The answers, question by question: the default database, table and key; title required, venue as proposed; the five actions;
// events followed with all its fields, the latest first, its own route and nested in index; items with its type alone, neither;
// the least role and the mount proposed
$answers = "\n\n\n!\n\n\n"."y\n\n^date\ny\ny\n"."y\ntype\n\nn\nn\n"."\n\n";
$runVals = callShellArray($projDir, Array('makerun', 'crudlRun', 'shows'), $answers);
checkIdentical('crudlRun: generated, the lines of the preset printed', Array($runVals['code'], strpos($runVals['text'], '"minRoles": { "shows/*": "standard" }')!==false), Array(0, true));
$genList = Array('runspec.json', 'routes.rte', 'Actions.php', 'input/index.json', 'input/view.json', 'input/create.json', 'input/update.json', 'input/remove.json', 'input/events.json');
checkIdentical('crudlRun: the spec, the routes, the actions and an input file for each', array_map(function( String $fileRelPath ): Bool { return is_file(RUN_DIR.DS.'Shows'.DS.str_replace('/', DS, $fileRelPath)); }, $genList), array_fill(0, count($genList), true));
// The example Run of the framework, test/fixtures/runs/Shows, is this one: generate it again with makerun when the generator changes
$sameLst = Array();
foreach( $genList as $fileRel ){
	$sameLst[$fileRel] = str_replace("\r\n", "\n", strval(@file_get_contents(TST_DIR.DS.'fixtures'.DS.'runs'.DS.'Shows'.DS.str_replace('/', DS, $fileRel))))===str_replace("\r\n", "\n", strval(fetchInProject($projDir, 'runs/Shows/'.$fileRel)));
}
checkIdentical('the example Run of the fixtures is what makerun generates', $sameLst, array_fill_keys($genList, true));
$specArr = json_decode(strval(fetchInProject($projDir, 'runs/Shows/runspec.json')), true);
checkIdentical('crudlRun: the spec holds the answers', Array($specArr['fieldSet'] ?? null, array_keys($specArr['childSet'] ?? Array()), $specArr['childSet']['events']['sortedBy'] ?? null, $specArr['childSet']['items']['fieldSet'] ?? null, $specArr['runMount'] ?? null), Array(Array('title!'=>'', 'venue'=>''), Array('events', 'items'), '^date', Array('type'=>''), 'shows'));
checkIdentical('crudlRun: every file written is traced on shell', substr_count(strval(fetchInProject($projDir, 'temp/logs/shell.log')), 'Makerun: '), count($genList));
checkIdentical('crudlRun: a Run of that name, whatever its case, is refused', Array(callShellArray($projDir, Array('makerun', 'crudlRun', 'SHOWS', '--noPrompt'))['code'], callShellArray($projDir, Array('makerun', 'crudlRun', '9lives', '--noPrompt'))['code']), Array(3, 2));

// The generated code follows the naming conventions, as test/audit/ConventionTest checks them : the action names are free
require_once __DIR__.'/../lib/auditor.php';
$genAudt = auditCodeFiles($projDir, Array('runs'));
$badList = Array();
foreach( Array('parse', 'class', 'method', 'param', 'prop', 'loop', 'local', 'nodoc', 'notype') as $kndNme ){
	foreach( $genAudt[$kndNme] as $oneFnd ){
		if( ($kndNme=='method' && in_array($oneFnd['name'], Array('index', 'view', 'create', 'update', 'remove', 'events'), true)) || ($kndNme=='local' && $oneFnd['name']=='e') ){
			continue;
		}
		$badList[] = $kndNme.' '.$oneFnd['file'].':'.$oneFnd['line'].' '.$oneFnd['name'];
	}
}
preg_match_all('/\bconst\s+(\w+)\s*=/', strval(fetchInProject($projDir, 'runs/Shows/Actions.php')), $cnstMtch);
foreach( $cnstMtch[1] as $cnstNme ){
	if( !preg_match('/^[A-Z0-9]{4}_[A-Z0-9]{4}$/', $cnstNme) ){
		$badList[] = 'const '.$cnstNme;
	}
}
checkIdentical('audit: the generated code was checked, and holds no name outside the conventions', Array($genAudt['count']['method']>=10, $badList), Array(true, Array()));

// Generated again from its spec : nothing replaced unasked, everything with --yesToAll, the same files
$actText = strval(fetchInProject($projDir, 'runs/Shows/Actions.php'));
$keptRun = callShellArray($projDir, Array('makerun', 'fromSpec', 'Shows', '--noPrompt'));
checkIdentical('fromSpec: with --noPrompt, the files that exist are kept', Array($keptRun['code'], substr_count($keptRun['text'], 'kept as it was'), substr_count($keptRun['text'], 'written:')), Array(0, count($genList) - 1, 0));
$sameRun = callShellArray($projDir, Array('makerun', 'fromSpec', 'shows', '--yesToAll'));
checkIdentical('fromSpec: with --yesToAll, written again, the same', Array($sameRun['code'], substr_count($sameRun['text'], 'written:'), fetchInProject($projDir, 'runs/Shows/Actions.php')), Array(0, count($genList) - 1, $actText));
checkIdentical('fullList: the Runs with a spec', runShellAsJson($projDir, Array('makerun', 'fullList'))['rows'] ?? null, Array(Array('run'=>'Shows', 'database'=>'(default)', 'table'=>'shows', 'actions'=>'index, view, create, update, remove', 'children'=>'events, items')));

// Its routes, as the Router reads them
$routObj = new nyu\Tools\Router( Array('detectBy'=>'autopath', 'pathForm'=>'$appToRun/$doAction/$argument'), mockRequestObj() );
$rteSeen = Array();
foreach( $routObj->runRoutesArray() as $rteItem ){
	$rteSeen[$rteItem['routPath']] = $rteItem['pointsTo'];
}
checkIdentical('routes: the list and create, the record, and the events of a record', Array(array_keys($rteSeen), $rteSeen['shows/$id/events']['doAction'] ?? null), Array(Array('shows', 'shows/$id', 'shows/$id/events'), 'events'));

/**
 * Runs an action of the generated Run through the Controller, as a user
 * holding a role.
 *
 * @param String  $actionNamed The action.
 * @param Array   $inputArray  The inputs sent.
 * @param ?String $optionGiven The key of the record, as the path gives it.
 * @param String  $loggedRole  The role of the user; '' for nobody logged.
 * @return Mixed The status code and the reply, or what was thrown.
 */
function callShow( String $actionNamed, Array $inputArray=Array(), ?String $optionGiven=null, String $loggedRole='standard' ): Mixed {
	return runCatchThrown(function() use ($actionNamed, $inputArray, $optionGiven, $loggedRole){
		$axssReg = new Registry( ($loggedRole!='')
			? Array('loggedIn'=>true, 'userName'=>'anna', 'userRole'=>$loggedRole, 'statCode'=>200, 'statMssg'=>'')
			: Array('loggedIn'=>false, 'userName'=>'', 'userRole'=>null, 'statCode'=>200, 'statMssg'=>'') );
		$control = new Controller( 'shows', $actionNamed, new Registry($inputArray), $optionGiven, $axssReg, mockRequestObj() );
		$statArr = $control->retrieveStatus();
		return Array($statArr['code'], $statArr['mssg'], $control->retrieveOutput());
	});
}

$GLOBALS['nyuConfig'] = new Registry( Array('database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>$basePth)) );
Controller::clearRunFoldrs();

// index
$lstVals = callShow('index');
checkIdentical('index: the records in key order, their fields, the events nested latest first, not the items', Array($lstVals[0], array_column($lstVals[2] ?? Array(), 'title'), array_keys($lstVals[2][0] ?? Array()), array_column($lstVals[2][0]['events'] ?? Array(), 'date'), $lstVals[2][4]['events'] ?? null),
	Array(200, Array('Alpha Night', 'Beta Evening', 'Alpha Matinee', 'Solo Gig', 'Empty Stage'), Array('id_show', 'title', 'venue', 'events'), Array('2027-01-03', '2026-12-28'), Array()));
checkIdentical('index: a filter matches anywhere in the field, whatever the case', array_column(callShow('index', Array('filters'=>Array('title'=>'alpha')))[2] ?? Array(), 'title'), Array('Alpha Night', 'Alpha Matinee'));
checkIdentical('index: a star in a filter is a star, a field not listed is left out', Array(callShow('index', Array('filters'=>Array('title'=>'Alpha*')))[0], count(callShow('index', Array('filters'=>Array('id_show'=>'1', 'nowhere'=>'x')))[2] ?? Array())), Array(204, 5));
checkIdentical('index: sorted, paged', array_column(callShow('index', Array('sortedBy'=>'^title', 'limitVal'=>'2', 'rowIndex'=>'1'))[2] ?? Array(), 'title'), Array('Empty Stage', 'Beta Evening'));
checkIdentical('index: a sort on a field not listed falls back to the key', array_column(callShow('index', Array('sortedBy'=>'nowhere', 'limitVal'=>'2'))[2] ?? Array(), 'id_show'), Array(1, 2));

// view
$recVals = callShow('view', Array(), '2');
checkIdentical('view: the record, its events latest first and its items, each with its fields', Array($recVals[0], $recVals[2]['title'] ?? null, array_column($recVals[2]['events'] ?? Array(), 'date'), $recVals[2]['items'] ?? null),
	Array(200, 'Beta Evening', Array('2026-11-09', '2026-11-02'), Array(Array('show_id'=>2, 'type'=>'theatre'), Array('show_id'=>2, 'type'=>'music'))));
checkIdentical('view: an unknown key, a key that is not one', Array(callShow('view', Array(), '99')[0], callShow('view', Array(), 'x')[0]), Array(404, 404));

// create, update, remove
checkIdentical('create: 201 with the key', callShow('create', Array('title'=>'Gamma', 'venue'=>'Hall G', 'id_show'=>'77')), Array(201, '', Array('id_show'=>6)));
checkIdentical('create: the required title missing, nothing sent', Array(callShow('create', Array('venue'=>'Hall G'))[0], callShow('create', Array())[0]), Array(400, 400));
checkIdentical('update: 200 with the record as saved, the key never written', callShow('update', Array('venue'=>'Hall H', 'id_show'=>'5'), '6'), Array(200, '', Array('id_show'=>6, 'title'=>'Gamma', 'venue'=>'Hall H')));
checkIdentical('update: an unknown key, nothing sent, a list sent for a field', Array(callShow('update', Array('venue'=>'x'), '99')[0], callShow('update', Array(), '6')[0], callShow('update', Array('venue'=>Array('x')), '6')[0]), Array(404, 400, 400));
checkIdentical('remove: 204, then 404', Array(callShow('remove', Array(), '6')[0], callShow('remove', Array(), '6')[0], callShow('view', Array(), '6')[0]), Array(204, 404, 404));

// The child route, and what is not an action
checkIdentical('events: the events of a record, latest first', array_column(callShow('events', Array(), '1')[2] ?? Array(), 'date'), Array('2027-01-03', '2026-12-28'));
checkIdentical('events: an unknown record, a record without events', Array(callShow('events', Array(), '99')[0], callShow('events', Array(), '5')[0]), Array(404, 204));
checkIdentical('items: without its own route, no action; a helper is none either', Array(callShow('items', Array(), '1')[0], callShow('readRecordById', Array(), '1')[0]), Array(404, 404));
checkIdentical('roles: nobody logged is refused as for a route that does not exist', callShow('index', Array(), null, '')[0], 404);

finishChecking();
