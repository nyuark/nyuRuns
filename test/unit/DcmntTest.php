<?php

// Data\Dcmnt and the parsers of Data\Parsers, as they behave today. The
// checks marked "today" record a behaviour that is a known fault: they are
// there so that changing it is a deliberate difference, not an accident.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Parsers\Init;
use nyu\Data\Parsers\Json;
use nyu\Data\Parsers\Regi;

/**
 * Writes a file of the test's temporary folder.
 *
 * @param String $insideName Name of the file.
 * @param String $fileContnt Its content.
 * @return String The path of the file.
 */
function tempFile( String $insideName, String $fileContnt ): String {
	$tmpPath = inTempDir($insideName);
	file_put_contents($tmpPath, $fileContnt);
	return $tmpPath;
}

/**
 * Reads what a document says of itself.
 *
 * @param String $sourcePath Path of the file.
 * @return mixed Its name, title, extension and parsed content, or a 'THROWN ...' string.
 */
function docFacts( String $sourcePath ): Mixed {
	return runSafely(function() use ($sourcePath){
		$docFile = new Dcmnt($sourcePath);
		return Array('nme'=>$docFile->readValue('nme'), 'tit'=>$docFile->readValue('tit'), 'ext'=>$docFile->readValue('ext'), 'out'=>$docFile->readValue());
	});
}

$iniText = "; a comment\nname = nyuRuns\nPort = 8080\nflag = true\nswitch = off\nblank = null\n[section]\ninside = yes\nquoted = \"a b\"\n";
$jsnText = '{"name":"nyuRuns","nested":{"inner":1},"list":[1,2]}';

// Name, title and extension
checkSame('a JSON file: name, title, extension, content', docFacts(tempFile('app.json', $jsnText)), Array('nme'=>'app.json', 'tit'=>'app', 'ext'=>'json', 'out'=>Array('NAME'=>'nyuRuns', 'NESTED'=>Array('inner'=>1), 'LIST'=>Array(1, 2))));
checkSame('several dots: the last one starts the extension, read in lower case', docFacts(tempFile('archive.tar.JSON', '{"a":1}')), Array('nme'=>'archive.tar.JSON', 'tit'=>'archive.tar', 'ext'=>'json', 'out'=>Array('A'=>1)));
checkSame('today: a file without extension takes its whole name as extension', docFacts(tempFile('README', 'hello')), Array('nme'=>'README', 'tit'=>'README', 'ext'=>'readme', 'out'=>Array()));
checkSame('an extension without a parser gives no content', docFacts(tempFile('routes.rte', '{"a":1}')), Array('nme'=>'routes.rte', 'tit'=>'routes', 'ext'=>'rte', 'out'=>Array()));
checkSame('today: an XML file gives no content, the parser is not written', docFacts(tempFile('doc.xml', '<a><b>1</b></a>'))['out'], Array());
checkSame('today: a missing file raises nothing and leaves everything unset', docFacts(inTempDir('nowhere.ini')), Array('nme'=>null, 'tit'=>null, 'ext'=>null, 'out'=>null));
checkSame('the raw content, and an unknown detail', runSafely(function(){
	$docFile = new Dcmnt(tempFile('raw.json', '{"a":1}'));
	return Array($docFile->readValue('raw'), $docFile->readValue('bogus'));
}), Array('{"a":1}', false));

// Parsing again with a chosen parser, as the router does for its .rte files
checkSame('a file parsed as JSON on request, keys kept as written', runSafely(function(){
	$docFile = new Dcmnt(tempFile('declared.rte', '{"shows/list":{"GET":"shows/index"}}'));
	$docFile->fileParse('json', false);
	return $docFile->readValue();
}), Array('shows/list'=>Array('GET'=>'shows/index')));
checkSame('the route files of the project are read that way', runSafely(function(){
	$rteList = glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*.rte');
	$docFile = new Dcmnt($rteList[0]);
	$docFile->fileParse('json', false);
	return Array(count($rteList)>0, is_array($docFile->readValue()));
}), Array(true, true));

// The configuration loader reads its files through Dcmnt
checkSame('config files: named in upper case; JSON keys upper-cased, INI keys as written', runSafely(function(){
	$cnfLoad = new nyu\Setup\Loader( Array('setup'=>Array('ftypes'=>'all', 'folder'=>'test'.DS.'fixtures'.DS.'conf', 'fnames'=>'database,system')) );
	$cnfVals = $cnfLoad->getConfig();
	return Array(array_keys($cnfVals->readLines()), array_keys((array)$cnfVals->readValue('database')), array_slice(array_keys((array)$cnfVals->readValue('system')), 0, 3));
}), Array(Array('DATABASE', 'SYSTEM'), Array('TYPE', 'HOST', 'BASE', 'USER', 'PASS'), Array('ENV_NAME', 'LOG_MODE', 'LOG_PATH')));

// INI
checkSame('an INI file: typed values, sections flattened', docFacts(tempFile('app.ini', $iniText))['out'], Array('name'=>'nyuRuns', 'Port'=>8080, 'flag'=>true, 'switch'=>false, 'blank'=>null, 'inside'=>true, 'quoted'=>'a b'));
checkSame('today: the keys of an INI file are never upper-cased, whatever is asked', runSafely(function() use ($iniText){
	$docFile = new Dcmnt(tempFile('keys.ini', $iniText));
	$docFile->fileParse('ini', true);
	return array_keys($docFile->readValue());
}), Array('name', 'Port', 'flag', 'switch', 'blank', 'inside', 'quoted'));
checkSame('Init: text, and empty text', Array(Init::parseData("a = 1\nB = two\n"), Init::parseData('')), Array(Array('a'=>1, 'B'=>'two'), Array()));
checkSame('today: invalid INI gives false', runSafely(function(){ return Init::parseData("a = (\n[broken\n"); }), false);
checkTrue('...and a PHP warning', count(takeNotes())>0);
checkSame('today: a reserved word used as a key makes the whole INI file invalid', runSafely(function(){ return Init::parseData("name = x\noff = false\n"); }), false);
checkTrue('...with a PHP warning too', count(takeNotes())>0);
checkSame('Regi: the same reading, keys in upper case', Regi::parseData("a = 1\nB = two\n[s]\nc = 3\n"), Array('A'=>1, 'B'=>'two', 'C'=>3));
checkSame('today: invalid INI through Regi gives an empty array', runSafely(function(){ return Regi::parseData("a = (\n[broken\n"); }), Array());
checkTrue('...and two PHP warnings, one for walking through false', count(takeNotes())>=2);

// JSON
checkSame('Json: keys in upper case at the first level only', Json::parseData('{"a":{"b":1}}'), Array('A'=>Array('b'=>1)));
checkSame('Json: keys kept when asked', Json::parseData('{"a":{"b":1}}', false), Array('a'=>Array('b'=>1)));
checkSame('Json: a list', Json::parseData('[1,2]'), Array(1, 2));
checkSame('Json: empty text gives null', Json::parseData(''), null);
checkSame('today: invalid JSON gives its error as a text, in place of the data', Json::parseData('{"a":'), 'Syntax error, malformed JSON.');
checkSame('today: a JSON value that is not an object or a list gives an empty text', Array(Json::parseData('5'), Json::parseData('"x"')), Array('', ''));
checkSame('today: the JSON text 0 is taken for an empty file', Json::parseData('0'), null);
checkSame('today: an invalid JSON file hands its error text over as content', docFacts(tempFile('bad.json', '{"a":'))['out'], 'Syntax error, malformed JSON.');
checkSame('an empty JSON file gives null', docFacts(tempFile('empty.json', ''))['out'], null);

// What is not there yet
checkSame('today: the XML parser file is empty', class_exists('nyu\Data\Parsers\XHml'), false);

endChecks();
