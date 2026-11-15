<?php

// Data\Dcmnt and the parsers of Data\Parsers: the contract and its
// envelope, the INI and JSON parsers, the index that picks a parser, the
// signatures read by Bina, and the encodings brought to UTF-8.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Parsers\Base;
use nyu\Data\Parsers\Bina;
use nyu\Data\Parsers\Index;
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
	$tmpPath = inTemporaryDir($insideName);
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
	return runCatchThrown(function() use ($sourcePath){
		$docFile = new Dcmnt($sourcePath);
		return Array('nme'=>$docFile->readFileDetail('nme'), 'tit'=>$docFile->readFileDetail('tit'), 'ext'=>$docFile->readFileDetail('ext'), 'out'=>$docFile->readFileDetail());
	});
}

/**
 * Parses a content and returns its items, or the reason it was refused.
 *
 * @param String $parserName Short name of the parser class.
 * @param String $rawContent The content.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The items, or a 'THROWN ...' string.
 */
function itemsOf( String $parserName, String $rawContent, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($parserName, $rawContent, $parseOptns){
		$prsrCls = 'nyu\\Data\\Parsers\\'.$parserName;
		$prsrObj = new $prsrCls();
		return $prsrObj->parseDataArray($rawContent, $parseOptns)['items'];
	});
}

$iniText = "; a comment\nname = nyuRuns\nPort = 8080\nflag = true\nswitch = off\nblank = null\n[section]\ninside = yes\nquoted = \"a b\"\n";
$jsnText = '{"name":"nyuRuns","nested":{"inner":1},"list":[1,2]}';

// Name, title and extension
checkIdentical('a JSON file: name, title, extension, content', docFacts(tempFile('app.json', $jsnText)), Array('nme'=>'app.json', 'tit'=>'app', 'ext'=>'json', 'out'=>Array('NAME'=>'nyuRuns', 'NESTED'=>Array('inner'=>1), 'LIST'=>Array(1, 2))));
checkIdentical('several dots: the last one starts the extension, read in lower case', docFacts(tempFile('archive.tar.JSON', '{"a":1}')), Array('nme'=>'archive.tar.JSON', 'tit'=>'archive.tar', 'ext'=>'json', 'out'=>Array('A'=>1)));
checkIdentical('a file without extension has none, and no content when its bytes tell nothing', docFacts(tempFile('README', 'hello')), Array('nme'=>'README', 'tit'=>'README', 'ext'=>'', 'out'=>Array()));
checkIdentical('a file without extension takes the kind its content shows', runCatchThrown(function(){
	$docFile = new Dcmnt(tempFile('picture', "\x89PNG\r\n\x1A\n".str_repeat("\x00", 20)));
	return Array($docFile->readFileDetail('ext'), $docFile->readFileDetail('sig'), $docFile->readFileDetail());
}), Array('png', 'png', Array()));
checkIdentical('a name starting with a dot: no title, the rest is the extension', docFacts(tempFile('.htpasswd', 'alice:x'))['ext'], 'htpasswd');
checkIdentical('an extension without a parser gives no content', docFacts(tempFile('notes.xyz', '{"a":1}')), Array('nme'=>'notes.xyz', 'tit'=>'notes', 'ext'=>'xyz', 'out'=>Array()));
checkIdentical('an XML file is read by its own parser', docFacts(tempFile('doc.xml', '<?xml version="1.0"?><a><b>1</b></a>'))['out']['tagnm'], 'a');
checkPregMatch('a missing file is refused', docFacts(inTemporaryDir('nowhere.ini')), '/^THROWN RuntimeException: File not found: nowhere\.ini/');
checkIdentical('the raw content, the shape, and an unknown detail', runCatchThrown(function(){
	$docFile = new Dcmnt(tempFile('raw.json', '{"a":1}'));
	return Array($docFile->readFileDetail('raw'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf'), $docFile->readFileDetail('sig'), $docFile->readFileDetail('bogus'));
}), Array('{"a":1}', 'nodes', Array(), '', false));
checkPregMatch('a content that is not what the name announces is refused', docFacts(tempFile('settings.json', "PK\x03\x04".str_repeat("\x00", 40))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: settings\.json: the content is not what the name announces/');
checkPregMatch('an invalid file is refused, and named', docFacts(tempFile('bad.json', '{"a":')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: bad\.json: Invalid JSON content: Syntax error/');
checkIdentical('an empty JSON file gives an empty tree', docFacts(tempFile('empty.json', ''))['out'], Array());

// A route file is plain JSON, read once with its keys as written, as the router does
checkPregMatch('a route file with a comment line is refused', runCatchThrown(function(){
	return new Dcmnt(tempFile('declared.rte', "# routes\n{\n\t\"shows/list\":{\"GET\":\"shows/index\"}\n}"), false);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: declared\.rte: Invalid JSON content/');
checkIdentical('a route file is read with its keys as written', runCatchThrown(function(){
	return (new Dcmnt(tempFile('routes.rte', '{"shows/list":{"GET":"shows/index"}}'), false))->readFileDetail();
}), Array('shows/list'=>Array('GET'=>'shows/index')));
checkIdentical('a file parsed as JSON on request, keys kept as written', runCatchThrown(function(){
	$docFile = new Dcmnt(tempFile('plain.rte', '{"shows/list":{"GET":"shows/index"}}'));
	$docFile->parseFileBytes('json', false);
	return $docFile->readFileDetail();
}), Array('shows/list'=>Array('GET'=>'shows/index')));
checkIdentical('a route file as a project declares it is read that way', runCatchThrown(function(){
	// A route file of the test's own, in a folder no router reads : the route files a project delivers may be empty
	$rteFile = TST_DIR.DS.'fixtures'.DS.'routes'.DS.'dcmnt'.DS.'declared.rte';
	$docFile = new Dcmnt($rteFile);
	$docFile->parseFileBytes('json', false);
	return Array(is_file($rteFile), array_keys((array)$docFile->readFileDetail()), $docFile->readFileDetail()['shows/$id']['pointsTo']['DELETE'] ?? null);
}), Array(true, Array('shows/list', 'shows/$id'), Array('appToRun'=>'shows', 'doAction'=>'remove')));
checkIdentical('the preset files of the project are JSON with comment lines too', runCatchThrown(function(){
	$rpsOkay = Array();
	foreach( glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps') as $rpsFle ){
		$docFile = new Dcmnt($rpsFle);
		$docFile->parseFileBytes('rps', false);
		$rpsOkay[] = isset($docFile->readFileDetail()['presetId']);
	}
	return Array(count($rpsOkay)>0, !in_array(false, $rpsOkay, true));
}), Array(true, true));

// The configuration loader reads its files through Dcmnt
checkIdentical('config files: named in upper case; JSON keys upper-cased, INI keys as written', runCatchThrown(function(){
	$cnfLoad = new nyu\Setup\Loader( Array('settings'=>Array('confType'=>'all', 'confPath'=>'test'.DS.'fixtures'.DS.'conf', 'confList'=>'database,system')) );
	$cnfVals = $cnfLoad->readConfigRegr();
	return Array(array_keys($cnfVals->readLinesArray()), array_keys((array)$cnfVals->readValueByKey('database')), array_slice(array_keys((array)$cnfVals->readValueByKey('system')), 0, 3));
}), Array(Array('DATABASE', 'SYSTEM'), Array('DBENGINE', 'HOSTNAME', 'BASENAME', 'USERNAME', 'PASSWORD'), Array('ENV_NAME', 'LOG_MODE', 'LOG_PATH')));

// The envelope
checkIdentical('a parser returns its content in the envelope', runCatchThrown(function(){ $prsrObj = new Json(); return $prsrObj->parseDataArray('{"a":1}'); }), Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array('a'=>1)));
checkIdentical('a parser reads a source piece by piece the same way', runCatchThrown(function(){ $prsrObj = new Json(); return $prsrObj->parseFluxArray(Fluxd::openBytesFluxd('{"a":1}'))['items']; }), Array('a'=>1));
checkPregMatch('a source larger than the limit is refused', runCatchThrown(function(){ $prsrObj = new Json(); return $prsrObj->parseFluxArray(Fluxd::openBytesFluxd('{"a":"'.str_repeat('x', 100).'"}'), Array('limit'=>50)); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Content larger than the 50 bytes allowed/');

// INI
checkIdentical('Init: typed values, sections flattened, keys as written', itemsOf('Init', $iniText), Array('name'=>'nyuRuns', 'Port'=>8080, 'flag'=>true, 'switch'=>false, 'blank'=>null, 'inside'=>true, 'quoted'=>'a b'));
checkIdentical('Init: keys in upper case when asked', array_keys(itemsOf('Init', $iniText, Array('upper'=>true))), Array('NAME', 'PORT', 'FLAG', 'SWITCH', 'BLANK', 'INSIDE', 'QUOTED'));
checkIdentical('an INI file through Dcmnt: its keys are upper-cased when asked, and only then', runCatchThrown(function() use ($iniText){
	$docFile = new Dcmnt(tempFile('keys.ini', $iniText));
	$uprKeys = array_slice(array_keys($docFile->readFileDetail()), 0, 2);
	$docFile->parseFileBytes('ini', false);
	return Array($uprKeys, array_slice(array_keys($docFile->readFileDetail()), 0, 2));
}), Array(Array('NAME', 'PORT'), Array('name', 'Port')));
checkIdentical('Init: empty text gives an empty tree', itemsOf('Init', ''), Array());
checkPregMatch('Init: invalid INI is refused, with the reason', itemsOf('Init', "a = (\n[broken\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid INI content: syntax error, unexpected .* on line \d+/');
checkPregMatch('Init: a reserved word used as a key is refused, and explained', itemsOf('Init', "name = x\noff = false\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid INI content: .*a key cannot be named on, off, yes, no, true, false, null or none/');
checkIdentical('...without any PHP warning left behind', takePhpNotices(), Array());
checkIdentical('Regi: the same reading, keys always in upper case', itemsOf('Regi', "a = 1\nB = two\n[s]\nc = 3\n"), Array('A'=>1, 'B'=>'two', 'C'=>3));
checkPregMatch('Regi: invalid INI is refused like any other', itemsOf('Regi', "a = (\n[broken\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid INI content/');

// JSON
checkIdentical('Json: keys kept as written', itemsOf('Json', '{"a":{"b":1}}'), Array('a'=>Array('b'=>1)));
checkIdentical('Json: keys in upper case at the first level only, when asked', itemsOf('Json', '{"a":{"b":1}}', Array('upper'=>true)), Array('A'=>Array('b'=>1)));
checkIdentical('Json: a list', itemsOf('Json', '[1,2]'), Array(1, 2));
checkIdentical('Json: empty or blank text gives an empty tree', Array(itemsOf('Json', ''), itemsOf('Json', " \n ")), Array(Array(), Array()));
checkPregMatch('Json: invalid JSON is refused, with the reason', itemsOf('Json', '{"a":'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content: Syntax error/');
checkIdentical('Json: a value that is not an object or a list is that value', Array(itemsOf('Json', '5'), itemsOf('Json', '"x"'), itemsOf('Json', '0'), itemsOf('Json', 'null'), itemsOf('Json', 'false')), Array(5, 'x', 0, null, false));
checkPregMatch('Json: nesting deeper than allowed is refused', itemsOf('Json', '{"a":{"b":{"c":1}}}', Array('depth'=>2)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content: Maximum stack depth exceeded/');
checkIdentical('Json: comment lines left out when asked', itemsOf('Json', "# top\n{\n  // inside\n  \"a\": \"# not a comment\",\n  \"b\": \"http://x\"\n}", Array('cmnts'=>true)), Array('a'=>'# not a comment', 'b'=>'http://x'));
checkPregMatch('Json: comment lines are refused when not asked for', itemsOf('Json', "# top\n{\"a\":1}"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content/');
checkIdentical('Json Lines: one value per line, blank lines passed over', itemsOf('Json', "{\"a\":1}\n\n{\"a\":2}\r\n[3]\n", Array('lines'=>true)), Array(Array('a'=>1), Array('a'=>2), Array(3)));
checkPregMatch('Json Lines: an invalid line is refused, with its number', itemsOf('Json', "{\"a\":1}\n{\"a\":\n", Array('lines'=>true)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content on line 2: Syntax error/');
checkIdentical('a .jsonl file is read line by line from its extension', docFacts(tempFile('events.jsonl', "{\"id\":1}\n{\"id\":2}\n"))['out'], Array(Array('id'=>1), Array('id'=>2)));

// Encodings
checkIdentical('the UTF-8 marker is removed', Array(Base::recodeTextUtf8("\xEF\xBB\xBFa = 1"), itemsOf('Json', "\xEF\xBB\xBF{\"a\":1}"), itemsOf('Init', "\xEF\xBB\xBFname = x")), Array('a = 1', Array('a'=>1), Array('name'=>'x')));
checkIdentical('UTF-16 is converted from its marker, either byte order', Array(Base::recodeTextUtf8("\xFF\xFE".iconv('UTF-8', 'UTF-16LE', 'été')), Base::recodeTextUtf8("\xFE\xFF".iconv('UTF-8', 'UTF-16BE', 'été'))), Array('été', 'été'));
checkIdentical('a text that is not UTF-8 is read as Windows-1252', Array(Base::recodeTextUtf8("Les Encha\xEEn\xE9s \x80"), itemsOf('Json', "{\"title\":\"\xE9t\xE9\"}")), Array('Les Enchaînés €', Array('title'=>'été')));
checkIdentical('a UTF-8 text is left as it is', Base::recodeTextUtf8('Les Enchaînés €'), 'Les Enchaînés €');

// The index
checkIdentical('index: the parser of an extension, whatever its case', Array(get_class(Index::forExtnsParser('json')), get_class(Index::forExtnsParser('INI')), get_class(Index::forExtnsParser('rps')), Index::forExtnsParser('xyz')), Array('nyu\Data\Parsers\Json', 'nyu\Data\Parsers\Init', 'nyu\Data\Parsers\Json', null));
checkIdentical('index: the default options of an extension', Array(Index::optionsOfExtns('json'), Index::optionsOfExtns('rte'), Index::optionsOfExtns('jsonl'), Index::optionsOfExtns('xyz')), Array(Array(), Array(), Array('lines'=>true), Array()));
checkIdentical('index: the parser of a request content type', Array(get_class(Index::findTypeParser('application/json')), get_class(Index::findTypeParser('Application/JSON; charset=utf-8')), get_class(Index::findTypeParser('application/problem+json')), Index::findTypeParser('image/png'), Index::findTypeParser('')), Array('nyu\Data\Parsers\Json', 'nyu\Data\Parsers\Json', 'nyu\Data\Parsers\Json', null, null));
checkIdentical('index: the options of a request content type', Array(Index::typeOptionsArr('application/x-ndjson'), Index::typeOptionsArr('application/json'), Index::typeOptionsArr('image/png')), Array(Array('lines'=>true), Array(), Array()));
checkIdentical('index: a parser added for an extension is used for it', runCatchThrown(function(){
	Index::registerParser('reg', 'Regi');
	return docFacts(tempFile('settings.reg', "name = x\n"))['out'];
}), Array('NAME'=>'x'));
checkPregMatch('index: what is not a parser is refused', runCatchThrown(function(){ Index::registerParser('abc', 'Bina'); }), '/^THROWN RuntimeException: Not a parser: Bina/');
checkPregMatch('index: an unknown class is refused', runCatchThrown(function(){ Index::registerParser('abc', 'app\Nowhere'); }), '/^THROWN RuntimeException: Not a parser/');
checkPregMatch('index: an extension that is not a plain name is refused', runCatchThrown(function(){ Index::registerParser('a.b', 'Json'); }), '/^THROWN RuntimeException: Invalid file extension/');

// Signatures
$signLst = Array(
	'zip'=>"PK\x03\x04\x14\x00", 'pdf'=>'%PDF-1.7', 'png'=>"\x89PNG\r\n\x1A\n", 'jpg'=>"\xFF\xD8\xFF\xE0", 'gif'=>'GIF89a', 'wav'=>"RIFF\x24\x00\x00\x00WAVEfmt ",
	'webp'=>"RIFF\x24\x00\x00\x00WEBPVP8 ", 'mp3'=>"ID3\x04\x00", 'ogg'=>'OggS', 'flac'=>'fLaC', 'mp4'=>"\x00\x00\x00\x20ftypisom", 'ole'=>"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'gz'=>"\x1F\x8B\x08", 'rtf'=>'{\rtf1\ansi',
	'xml'=>"\xEF\xBB\xBF  <?xml version=\"1.0\"?><a/>", 'html'=>"<!DOCTYPE html>\n<html>", 'ics'=>"BEGIN:VCALENDAR\r\nVERSION:2.0"
);
$signGot = Array();
foreach( $signLst as $sgnKnd=>$sgnTxt ){
	$signGot[$sgnKnd] = Bina::detectFileKind($sgnTxt);
}
checkIdentical('signatures: each kind is told from its first bytes', $signGot, array_combine(array_keys($signLst), array_keys($signLst)));
checkIdentical('signatures: plain text formats tell nothing', Array(Bina::detectFileKind('{"a":1}'), Bina::detectFileKind("name = x\n"), Bina::detectFileKind('# Title'), Bina::detectFileKind(''), Bina::detectFileKind('<p>not a whole page</p>')), Array('', '', '', '', ''));
$offcDir = TST_DIR.DS.'fixtures'.DS.'office';
checkIdentical('signatures: the sample office files', runCatchThrown(function() use ($offcDir){
	$kindLst = Array();
	foreach( Array('sample.odt', 'sample.ods', 'SampleDoc.docx', 'SampleSS.xlsx') as $offcFle ){
		$kindLst[$offcFle] = Bina::detectFileKind(Fluxd::openLocalFluxd($offcDir.DS.$offcFle)->getRangeString(0, 511));
	}
	return $kindLst;
}), Array('sample.odt'=>'odt', 'sample.ods'=>'ods', 'SampleDoc.docx'=>'zip', 'SampleSS.xlsx'=>'zip'));
checkIdentical('an office file is recognised by Dcmnt, even without its extension', runCatchThrown(function() use ($offcDir){
	copy($offcDir.DS.'sample.odt', inTemporaryDir('unnamed'));
	$docFile = new Dcmnt(inTemporaryDir('unnamed'));
	return Array($docFile->readFileDetail('ext'), $docFile->readFileDetail('sig'));
}), Array('odt', 'odt'));

finishChecking();
