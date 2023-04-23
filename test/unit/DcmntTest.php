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

/**
 * Parses a content and returns its items, or the reason it was refused.
 *
 * @param String $parserName Short name of the parser class.
 * @param String $rawContent The content.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The items, or a 'THROWN ...' string.
 */
function itemsOf( String $parserName, String $rawContent, Array $parseOptns=Array() ): Mixed {
	return runSafely(function() use ($parserName, $rawContent, $parseOptns){
		$prsrCls = 'nyu\\Data\\Parsers\\'.$parserName;
		$prsrObj = new $prsrCls();
		return $prsrObj->parseData($rawContent, $parseOptns)['items'];
	});
}

$iniText = "; a comment\nname = nyuRuns\nPort = 8080\nflag = true\nswitch = off\nblank = null\n[section]\ninside = yes\nquoted = \"a b\"\n";
$jsnText = '{"name":"nyuRuns","nested":{"inner":1},"list":[1,2]}';

// Name, title and extension
checkSame('a JSON file: name, title, extension, content', docFacts(tempFile('app.json', $jsnText)), Array('nme'=>'app.json', 'tit'=>'app', 'ext'=>'json', 'out'=>Array('NAME'=>'nyuRuns', 'NESTED'=>Array('inner'=>1), 'LIST'=>Array(1, 2))));
checkSame('several dots: the last one starts the extension, read in lower case', docFacts(tempFile('archive.tar.JSON', '{"a":1}')), Array('nme'=>'archive.tar.JSON', 'tit'=>'archive.tar', 'ext'=>'json', 'out'=>Array('A'=>1)));
checkSame('a file without extension has none, and no content when its bytes tell nothing', docFacts(tempFile('README', 'hello')), Array('nme'=>'README', 'tit'=>'README', 'ext'=>'', 'out'=>Array()));
checkSame('a file without extension takes the kind its content shows', runSafely(function(){
	$docFile = new Dcmnt(tempFile('picture', "\x89PNG\r\n\x1A\n".str_repeat("\x00", 20)));
	return Array($docFile->readValue('ext'), $docFile->readValue('sig'), $docFile->readValue());
}), Array('png', 'png', Array()));
checkSame('a name starting with a dot: no title, the rest is the extension', docFacts(tempFile('.htpasswd', 'alice:x'))['ext'], 'htpasswd');
checkSame('an extension without a parser gives no content', docFacts(tempFile('notes.xyz', '{"a":1}')), Array('nme'=>'notes.xyz', 'tit'=>'notes', 'ext'=>'xyz', 'out'=>Array()));
checkSame('an XML file is read by its own parser', docFacts(tempFile('doc.xml', '<?xml version="1.0"?><a><b>1</b></a>'))['out']['tagnm'], 'a');
checkLike('a missing file is refused', docFacts(inTempDir('nowhere.ini')), '/^THROWN RuntimeException: File not found: nowhere\.ini/');
checkSame('the raw content, the shape, and an unknown detail', runSafely(function(){
	$docFile = new Dcmnt(tempFile('raw.json', '{"a":1}'));
	return Array($docFile->readValue('raw'), $docFile->readValue('shp'), $docFile->readValue('inf'), $docFile->readValue('sig'), $docFile->readValue('bogus'));
}), Array('{"a":1}', 'nodes', Array(), '', false));
checkLike('a content that is not what the name announces is refused', docFacts(tempFile('settings.json', "PK\x03\x04".str_repeat("\x00", 40))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: settings\.json: the content is not what the name announces/');
checkLike('an invalid file is refused, and named', docFacts(tempFile('bad.json', '{"a":')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: bad\.json: Invalid JSON content: Syntax error/');
checkSame('an empty JSON file gives an empty tree', docFacts(tempFile('empty.json', ''))['out'], Array());

// Parsing again with a chosen parser, as the router does for its .rte files
checkSame('a route file is JSON with comment lines, read as such by its extension', runSafely(function(){
	$docFile = new Dcmnt(tempFile('declared.rte', "# routes\n{\n\t// the list\n\t\"shows/list\":{\"GET\":\"shows/index\"}\n}"));
	$uprKeys = array_keys($docFile->readValue());
	$docFile->fileParse('rte', false);
	return Array($uprKeys, $docFile->readValue());
}), Array(Array('SHOWS/LIST'), Array('shows/list'=>Array('GET'=>'shows/index'))));
checkSame('a file parsed as JSON on request, keys kept as written', runSafely(function(){
	$docFile = new Dcmnt(tempFile('plain.rte', '{"shows/list":{"GET":"shows/index"}}'));
	$docFile->fileParse('json', false);
	return $docFile->readValue();
}), Array('shows/list'=>Array('GET'=>'shows/index')));
checkSame('the route files of the project are read that way', runSafely(function(){
	$rteList = glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*.rte');
	$docFile = new Dcmnt($rteList[0]);
	$docFile->fileParse('json', false);
	return Array(count($rteList)>0, is_array($docFile->readValue()) && !empty($docFile->readValue()));
}), Array(true, true));
checkSame('the preset files of the project are JSON with comment lines too', runSafely(function(){
	$rpsOkay = Array();
	foreach( glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps') as $rpsFle ){
		$docFile = new Dcmnt($rpsFle);
		$docFile->fileParse('rps', false);
		$rpsOkay[] = isset($docFile->readValue()['name']);
	}
	return Array(count($rpsOkay)>0, !in_array(false, $rpsOkay, true));
}), Array(true, true));

// The configuration loader reads its files through Dcmnt
checkSame('config files: named in upper case; JSON keys upper-cased, INI keys as written', runSafely(function(){
	$cnfLoad = new nyu\Setup\Loader( Array('setup'=>Array('ftypes'=>'all', 'folder'=>'test'.DS.'fixtures'.DS.'conf', 'fnames'=>'database,system')) );
	$cnfVals = $cnfLoad->getConfig();
	return Array(array_keys($cnfVals->readLines()), array_keys((array)$cnfVals->readValue('database')), array_slice(array_keys((array)$cnfVals->readValue('system')), 0, 3));
}), Array(Array('DATABASE', 'SYSTEM'), Array('TYPE', 'HOST', 'BASE', 'USER', 'PASS'), Array('ENV_NAME', 'LOG_MODE', 'LOG_PATH')));

// The envelope
checkSame('a parser returns its content in the envelope', runSafely(function(){ $prsrObj = new Json(); return $prsrObj->parseData('{"a":1}'); }), Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array('a'=>1)));
checkSame('a parser reads a source piece by piece the same way', runSafely(function(){ $prsrObj = new Json(); return $prsrObj->parseFlux(Fluxd::openBytes('{"a":1}'))['items']; }), Array('a'=>1));
checkLike('a source larger than the limit is refused', runSafely(function(){ $prsrObj = new Json(); return $prsrObj->parseFlux(Fluxd::openBytes('{"a":"'.str_repeat('x', 100).'"}'), Array('limit'=>50)); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Content larger than the 50 bytes allowed/');

// INI
checkSame('Init: typed values, sections flattened, keys as written', itemsOf('Init', $iniText), Array('name'=>'nyuRuns', 'Port'=>8080, 'flag'=>true, 'switch'=>false, 'blank'=>null, 'inside'=>true, 'quoted'=>'a b'));
checkSame('Init: keys in upper case when asked', array_keys(itemsOf('Init', $iniText, Array('upper'=>true))), Array('NAME', 'PORT', 'FLAG', 'SWITCH', 'BLANK', 'INSIDE', 'QUOTED'));
checkSame('an INI file through Dcmnt: its keys are upper-cased when asked, and only then', runSafely(function() use ($iniText){
	$docFile = new Dcmnt(tempFile('keys.ini', $iniText));
	$uprKeys = array_slice(array_keys($docFile->readValue()), 0, 2);
	$docFile->fileParse('ini', false);
	return Array($uprKeys, array_slice(array_keys($docFile->readValue()), 0, 2));
}), Array(Array('NAME', 'PORT'), Array('name', 'Port')));
checkSame('Init: empty text gives an empty tree', itemsOf('Init', ''), Array());
checkLike('Init: invalid INI is refused, with the reason', itemsOf('Init', "a = (\n[broken\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid INI content: syntax error, unexpected .* on line \d+/');
checkLike('Init: a reserved word used as a key is refused, and explained', itemsOf('Init', "name = x\noff = false\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid INI content: .*a key cannot be named on, off, yes, no, true, false, null or none/');
checkSame('...without any PHP warning left behind', takeNotes(), Array());
checkSame('Regi: the same reading, keys always in upper case', itemsOf('Regi', "a = 1\nB = two\n[s]\nc = 3\n"), Array('A'=>1, 'B'=>'two', 'C'=>3));
checkLike('Regi: invalid INI is refused like any other', itemsOf('Regi', "a = (\n[broken\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid INI content/');

// JSON
checkSame('Json: keys kept as written', itemsOf('Json', '{"a":{"b":1}}'), Array('a'=>Array('b'=>1)));
checkSame('Json: keys in upper case at the first level only, when asked', itemsOf('Json', '{"a":{"b":1}}', Array('upper'=>true)), Array('A'=>Array('b'=>1)));
checkSame('Json: a list', itemsOf('Json', '[1,2]'), Array(1, 2));
checkSame('Json: empty or blank text gives an empty tree', Array(itemsOf('Json', ''), itemsOf('Json', " \n ")), Array(Array(), Array()));
checkLike('Json: invalid JSON is refused, with the reason', itemsOf('Json', '{"a":'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content: Syntax error/');
checkSame('Json: a value that is not an object or a list is that value', Array(itemsOf('Json', '5'), itemsOf('Json', '"x"'), itemsOf('Json', '0'), itemsOf('Json', 'null'), itemsOf('Json', 'false')), Array(5, 'x', 0, null, false));
checkLike('Json: nesting deeper than allowed is refused', itemsOf('Json', '{"a":{"b":{"c":1}}}', Array('depth'=>2)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content: Maximum stack depth exceeded/');
checkSame('Json: comment lines left out when asked', itemsOf('Json', "# top\n{\n  // inside\n  \"a\": \"# not a comment\",\n  \"b\": \"http://x\"\n}", Array('cmnts'=>true)), Array('a'=>'# not a comment', 'b'=>'http://x'));
checkLike('Json: comment lines are refused when not asked for', itemsOf('Json', "# top\n{\"a\":1}"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content/');
checkSame('Json Lines: one value per line, blank lines passed over', itemsOf('Json', "{\"a\":1}\n\n{\"a\":2}\r\n[3]\n", Array('lines'=>true)), Array(Array('a'=>1), Array('a'=>2), Array(3)));
checkLike('Json Lines: an invalid line is refused, with its number', itemsOf('Json', "{\"a\":1}\n{\"a\":\n", Array('lines'=>true)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid JSON content on line 2: Syntax error/');
checkSame('a .jsonl file is read line by line from its extension', docFacts(tempFile('events.jsonl', "{\"id\":1}\n{\"id\":2}\n"))['out'], Array(Array('id'=>1), Array('id'=>2)));

// Encodings
checkSame('the UTF-8 marker is removed', Array(Base::cleanText("\xEF\xBB\xBFa = 1"), itemsOf('Json', "\xEF\xBB\xBF{\"a\":1}"), itemsOf('Init', "\xEF\xBB\xBFname = x")), Array('a = 1', Array('a'=>1), Array('name'=>'x')));
checkSame('UTF-16 is converted from its marker, either byte order', Array(Base::cleanText("\xFF\xFE".iconv('UTF-8', 'UTF-16LE', 'été')), Base::cleanText("\xFE\xFF".iconv('UTF-8', 'UTF-16BE', 'été'))), Array('été', 'été'));
checkSame('a text that is not UTF-8 is read as Windows-1252', Array(Base::cleanText("Les Encha\xEEn\xE9s \x80"), itemsOf('Json', "{\"title\":\"\xE9t\xE9\"}")), Array('Les Enchaînés €', Array('title'=>'été')));
checkSame('a UTF-8 text is left as it is', Base::cleanText('Les Enchaînés €'), 'Les Enchaînés €');

// The index
checkSame('index: the parser of an extension, whatever its case', Array(get_class(Index::forExtnsn('json')), get_class(Index::forExtnsn('INI')), get_class(Index::forExtnsn('rps')), Index::forExtnsn('xyz')), Array('nyu\Data\Parsers\Json', 'nyu\Data\Parsers\Init', 'nyu\Data\Parsers\Json', null));
checkSame('index: the default options of an extension', Array(Index::extnOptns('json'), Index::extnOptns('rte'), Index::extnOptns('jsonl'), Index::extnOptns('xyz')), Array(Array(), Array('cmnts'=>true), Array('lines'=>true), Array()));
checkSame('index: the parser of a request content type', Array(get_class(Index::forCnType('application/json')), get_class(Index::forCnType('Application/JSON; charset=utf-8')), get_class(Index::forCnType('application/problem+json')), Index::forCnType('text/plain'), Index::forCnType('')), Array('nyu\Data\Parsers\Json', 'nyu\Data\Parsers\Json', 'nyu\Data\Parsers\Json', null, null));
checkSame('index: the options of a request content type', Array(Index::typeOptns('application/x-ndjson'), Index::typeOptns('application/json'), Index::typeOptns('text/plain')), Array(Array('lines'=>true), Array(), Array()));
checkSame('index: a parser added for an extension is used for it', runSafely(function(){
	Index::addParser('reg', 'Regi');
	return docFacts(tempFile('settings.reg', "name = x\n"))['out'];
}), Array('NAME'=>'x'));
checkLike('index: what is not a parser is refused', runSafely(function(){ Index::addParser('abc', 'Bina'); }), '/^THROWN RuntimeException: Not a parser: Bina/');
checkLike('index: an unknown class is refused', runSafely(function(){ Index::addParser('abc', 'app\Nowhere'); }), '/^THROWN RuntimeException: Not a parser/');
checkLike('index: an extension that is not a plain name is refused', runSafely(function(){ Index::addParser('a.b', 'Json'); }), '/^THROWN RuntimeException: Invalid file extension/');

// Signatures
$signLst = Array(
	'zip'=>"PK\x03\x04\x14\x00", 'pdf'=>'%PDF-1.7', 'png'=>"\x89PNG\r\n\x1A\n", 'jpg'=>"\xFF\xD8\xFF\xE0", 'gif'=>'GIF89a', 'wav'=>"RIFF\x24\x00\x00\x00WAVEfmt ",
	'webp'=>"RIFF\x24\x00\x00\x00WEBPVP8 ", 'mp3'=>"ID3\x04\x00", 'ogg'=>'OggS', 'flac'=>'fLaC', 'mp4'=>"\x00\x00\x00\x20ftypisom", 'ole'=>"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'gz'=>"\x1F\x8B\x08", 'rtf'=>'{\rtf1\ansi',
	'xml'=>"\xEF\xBB\xBF  <?xml version=\"1.0\"?><a/>", 'html'=>"<!DOCTYPE html>\n<html>", 'ics'=>"BEGIN:VCALENDAR\r\nVERSION:2.0"
);
$signGot = Array();
foreach( $signLst as $sgnKnd=>$sgnTxt ){
	$signGot[$sgnKnd] = Bina::signature($sgnTxt);
}
checkSame('signatures: each kind is told from its first bytes', $signGot, array_combine(array_keys($signLst), array_keys($signLst)));
checkSame('signatures: plain text formats tell nothing', Array(Bina::signature('{"a":1}'), Bina::signature("name = x\n"), Bina::signature('# Title'), Bina::signature(''), Bina::signature('<p>not a whole page</p>')), Array('', '', '', '', ''));
$offcDir = TST_DIR.DS.'fixtures'.DS.'office';
checkSame('signatures: the sample office files', runSafely(function() use ($offcDir){
	$kindLst = Array();
	foreach( Array('sample.odt', 'sample.ods', 'SampleDoc.docx', 'SampleSS.xlsx') as $offcFle ){
		$kindLst[$offcFle] = Bina::signature(Fluxd::openLocal($offcDir.DS.$offcFle)->readRange(0, 511));
	}
	return $kindLst;
}), Array('sample.odt'=>'odt', 'sample.ods'=>'ods', 'SampleDoc.docx'=>'zip', 'SampleSS.xlsx'=>'zip'));
checkSame('an office file is recognised by Dcmnt, even without its extension', runSafely(function() use ($offcDir){
	copy($offcDir.DS.'sample.odt', inTempDir('unnamed'));
	$docFile = new Dcmnt(inTempDir('unnamed'));
	return Array($docFile->readValue('ext'), $docFile->readValue('sig'));
}), Array('odt', 'odt'));

endChecks();
