<?php

// makerun: the spec of a generated Run (Shell\RunSpec) read and checked, the
// files the generator writes from it (Shell\RunMaker), and what the command
// refuses: a name, a table, a database that is not SQL. The whole Run,
// generated and called, is tested in test/data/RunGenTest.php.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Shell\RunMaker;
use nyu\Shell\RunSpec;

$goodSpec = Array(
	'appsName'=>'Shows', 'database'=>'', 'tableRef'=>'shows', 'idColumn'=>'id_show',
	'fieldSet'=>Array('title!'=>'', 'seats'=>'Integer', 'opening'=>'CustDte(Y-m-d)'),
	'actnList'=>Array('index', 'view'), 'minmRole'=>'advanced', 'runMount'=>'api/shows',
	'childSet'=>Array('events'=>Array('linkedBy'=>'show_id', 'fieldSet'=>Array('date'=>'', 'time'=>''), 'sortedBy'=>'^date', 'ownRoute'=>true, 'listsToo'=>false))
);

// Checked
checkIdentical('a spec as makerun writes it holds no flaw', RunSpec::checkSpecFlaws($goodSpec), '');
$flawLst = Array();
foreach( Array(
	'a key missing'=>array_diff_key($goodSpec, Array('runMount'=>1)),
	'a key more'=>$goodSpec + Array('extraKey'=>1),
	'a name starting with a digit'=>Array('appsName'=>'9lives') + $goodSpec,
	'a format that is not one'=>Array('fieldSet'=>Array('title'=>'Bogus')) + $goodSpec,
	'the key among the fields'=>Array('fieldSet'=>Array('id_show'=>'')) + $goodSpec,
	'an action that is not one'=>Array('actnList'=>Array('index', 'purge')) + $goodSpec,
	'an action twice'=>Array('actnList'=>Array('view', 'view')) + $goodSpec,
	'a role that is not one'=>Array('minmRole'=>'root') + $goodSpec,
	'a mount with slashes around it'=>Array('runMount'=>'/shows/') + $goodSpec,
	'a child named as an action'=>Array('childSet'=>Array('view'=>$goodSpec['childSet']['events'])) + $goodSpec,
	'a child sorted by a field it does not read'=>Array('childSet'=>Array('events'=>Array('sortedBy'=>'venue') + $goodSpec['childSet']['events'])) + $goodSpec,
	'a child field required'=>Array('childSet'=>Array('events'=>Array('fieldSet'=>Array('date!'=>'')) + $goodSpec['childSet']['events'])) + $goodSpec
) as $caseNme => $badSpec ){
	$flawLst[$caseNme] = RunSpec::checkSpecFlaws($badSpec)!=='';
}
checkIdentical('a flaw is found in each wrong spec', $flawLst, array_fill_keys(array_keys($flawLst), true));

// The format a column type proposes
checkIdentical('formats: whole and decimal numbers, dates, dates and times, any other kept as sent', array_map(Array(RunSpec::class, 'formatOfColumn'), Array('INTEGER', 'int(11)', 'bigint', 'tinyint(1)', 'boolean', 'decimal(10,2)', 'REAL', 'double precision', 'DATE', 'datetime', 'timestamp without time zone', 'TEXT', 'varchar(100)', '')),
	Array('Integer', 'Integer', 'Integer', 'Integer', 'Integer', 'FloatNb', 'FloatNb', 'FloatNb', 'CustDte(Y-m-d)', 'CustDte(Y-m-d H:i:s)', 'CustDte(Y-m-d H:i:s)', '', '', ''));

// Written, then read
$specDir = inTemporaryDir('specruns');
@mkdir($specDir, 0775, true);
$specPth = RunSpec::writeSpecsFile($specDir, $goodSpec);
nyu\App\Controller::clearFldrCache();
checkIdentical('written in the folder of the Run, read back the same, whatever the case of its name', Array($specPth, RunSpec::readSpecsArray($specDir, 'SHOWS')), Array($specDir.DS.'Shows'.DS.'runspec.json', $goodSpec));
file_put_contents($specPth, '{ "appsName": "Shows" }');
checkPregMatch('a spec that is not whole is refused', runCatchThrown(function() use ( $specDir ){ return RunSpec::readSpecsArray($specDir, 'Shows'); }), '/^THROWN RuntimeException: Shows\/runspec\.json: the keys must be /');
checkPregMatch('a Run without a spec, no Run', implode("\n", Array(runCatchThrown(function() use ( $specDir ){ @mkdir($specDir.DS.'Plain'); nyu\App\Controller::clearFldrCache(); return RunSpec::readSpecsArray($specDir, 'Plain'); }), runCatchThrown(function() use ( $specDir ){ return RunSpec::readSpecsArray($specDir, 'Nowhere'); }))), '/has no runspec\.json.*No Run is named "Nowhere"/s');
checkPregMatch('a spec with a flaw is never written', runCatchThrown(function() use ( $specDir, $goodSpec ){ return RunSpec::writeSpecsFile($specDir, Array('minmRole'=>'root') + $goodSpec); }), '/^THROWN RuntimeException: Spec not written: minmRole must be one of /');

// What the generator writes from it : the actions kept only
$makrObj = new RunMaker($goodSpec, $specDir);
checkIdentical('routes: the list, the record and the child route, for the actions kept', json_decode($makrObj->routesFileText(), true), Array(''=>Array('pointsTo'=>Array('GET'=>Array('doAction'=>'index'))), '$id'=>Array('pointsTo'=>Array('GET'=>Array('doAction'=>'view'))), '$id/events'=>Array('mthdList'=>'GET', 'pointsTo'=>Array('doAction'=>'events'))));
checkIdentical('inputs: the listing ones for index, none for view and the child route', array_map(function( String $jsonText ): Mixed { return json_decode($jsonText, true)['fieldSet']; }, $makrObj->inputFileArray()), Array('index'=>Array('filters'=>Array('title'=>'', 'seats'=>'', 'opening'=>''), 'sortedBy'=>'', 'limitVal'=>'Integer', 'rowIndex'=>'Integer'), 'view'=>Array(), 'events'=>Array()));
checkIdentical('the files, by their path, and the line of the preset', Array(array_map('basename', array_keys($makrObj->buildFilesList())), $makrObj->minRolesString()), Array(Array('routes.rte', 'Actions.php', 'index.json', 'view.json', 'events.json'), '"minRoles": { "shows/*": "advanced" }'));
$actText = $makrObj->actionsPhpText();
checkIdentical('actions: those kept, the child route, the role asked, no default action', Array(str_contains($actText, 'public function index('), str_contains($actText, 'public function create('), str_contains($actText, 'public function events('), str_contains($actText, "\$this->needsActionRle('advanced');"), str_contains($actText, 'dfltAction')), Array(true, false, true, true, false));
checkPregMatch('a spec with a flaw generates nothing', runCatchThrown(function() use ( $goodSpec, $specDir ){ return new RunMaker(Array('actnList'=>Array()) + $goodSpec, $specDir); }), '/^THROWN RuntimeException: Nothing generated: actnList /');

// What the command refuses : a name, a database that is not SQL, a table
$mngDir = makeProjectTxt('rungenmng', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/database.json'=>json_encode(Array('dbEngine'=>'mongo_db', 'hostName'=>'127.0.0.1', 'baseName'=>'nyuruns_test', 'userName'=>'', 'passWord'=>'')),
	'runs/Probe/Actions.php'=>"<?php\n"
));
$nameRun = Array(callShellArray($mngDir, Array('makerun', 'crudlRun', '9lives', '--noPrompt')), callShellArray($mngDir, Array('makerun', 'crudlRun', 'PROBE', '--noPrompt')), callShellArray($mngDir, Array('makerun', 'crudlRun')));
checkIdentical('name: letters and digits with a letter first, no Run of that name whatever its case, a name given', array_column($nameRun, 'code'), Array(2, 3, 2));
$mongRun = callShellArray($mngDir, Array('makerun', 'crudlRun', 'Shows', '--noPrompt'));
checkIdentical('database: MongoDB is refused, nothing written', Array($mongRun['code'], str_starts_with(trim($mongRun['errs']), 'No Run generated: '), is_dir($mngDir.DS.'runs'.DS.'Shows')), Array(3, true, false));
if( extension_loaded('pdo_sqlite') ){
	$sqlBase = inTemporaryDir('rungentbl.sqlite');
	(new PDO('sqlite:'.$sqlBase))->exec('CREATE TABLE things (id_thing INTEGER PRIMARY KEY, label TEXT)');
	$tblDir = makeProjectTxt('rungentbl', Array(
		'conf/system.ini'=>buildSystemIni(),
		'conf/database.json'=>json_encode(Array('dbEngine'=>'sqlitedb', 'hostName'=>'', 'baseName'=>$sqlBase, 'userName'=>'', 'passWord'=>''))
	));
	@mkdir($tblDir.DS.'runs', 0775, true);
	$tblRun = callShellArray($tblDir, Array('makerun', 'crudlRun', 'Shows'), "\nnowhere\n");
	checkIdentical('table: one the database does not have is refused, nothing written', Array($tblRun['code'], str_ends_with(trim($tblRun['errs']), 'No Run generated: the database has no table "nowhere"'), is_dir($tblDir.DS.'runs'.DS.'Shows')), Array(3, true, false));
	checkIdentical('table: the name of the Run in lower case is proposed when the database has it', callShellArray($tblDir, Array('makerun', 'crudlRun', 'Things', '--noPrompt'))['code'], 0);
}else{
	recordSkipping('makerun: a refused table', 'pdo_sqlite is not available');
}
checkIdentical('help: what is not in 1.0 is told with the command', str_contains(callShellArray($mngDir, Array('makerun', '--helpText'))['text'].callShellArray($mngDir, Array())['text'], 'not in 1.0: creating tables, MongoDB'), true);

finishChecking();
