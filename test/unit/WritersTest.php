<?php

// Data\Writers : the index of the writers, and the writers of values,
// Json and Yaml.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Shape;
use nyu\Data\Parsers\Json as JsonParser;
use nyu\Data\Parsers\Yaml as YamlParser;
use nyu\Data\Writers\Html;
use nyu\Data\Writers\Index;
use nyu\Data\Writers\Json;
use nyu\Data\Writers\Writer;
use nyu\Data\Writers\WriteError;
use nyu\Data\Writers\Yaml;

/**
 * Writes values with a writer, or returns the reason they were refused.
 *
 * @param Writer $writerObjc The writer.
 * @param mixed  $itemsValue The values.
 * @param Array  $writeOptns Options of the writer.
 * @param String $shapeLabel Shape the values are given as.
 * @return String What was written, or a 'THROWN ...' string.
 */
function putVals( Writer $writerObjc, Mixed $itemsValue, Array $writeOptns=Array(), String $shapeLabel='nodes' ): String {
	return runCatchThrown(function() use ($writerObjc, $itemsValue, $writeOptns, $shapeLabel){
		return $writerObjc->renderDocument(Array('shape'=>$shapeLabel, 'infos'=>Array('docus'=>1), 'items'=>$itemsValue), $writeOptns);
	});
}

// A writer an application adds: it writes the number of its items
class CountOut implements Writer {
	public function renderDocument( Array $shapedData, Array $writeOptns=Array() ): String {
		return strval(count((array)$shapedData['items']));
	}
}

// The index
checkIdentical('index: the writer of an extension, whatever its case', Array(get_class(Index::forExtnsParser('html')), get_class(Index::forExtnsParser('HTM')), get_class(Index::forExtnsParser('json')), get_class(Index::forExtnsParser('Jsonl')), get_class(Index::forExtnsParser('yaml')), get_class(Index::forExtnsParser('yml')), Index::forExtnsParser('exe'), Index::forExtnsParser('')), Array('nyu\Data\Writers\Html', 'nyu\Data\Writers\Html', 'nyu\Data\Writers\Json', 'nyu\Data\Writers\Json', 'nyu\Data\Writers\Yaml', 'nyu\Data\Writers\Yaml', null, null));
checkIdentical('index: the options an extension is written with', Array(Index::optionsOfExtns('json'), Index::optionsOfExtns('jsonl'), Index::optionsOfExtns('NDJSON'), Index::optionsOfExtns('exe')), Array(Array(), Array('lines'=>true), Array('lines'=>true), Array()));
checkIdentical('index: the content type a reply is sent with', Array(Index::contentTypeFor('html'), Index::contentTypeFor('JSON'), Index::contentTypeFor('ndjson'), Index::contentTypeFor('yml'), Index::contentTypeFor('exe')), Array('text/html; charset=utf-8', 'application/json; charset=utf-8', 'application/x-ndjson; charset=utf-8', 'application/yaml; charset=utf-8', ''));
checkIdentical('index: a writer of the application is added, with its options and its content type', runCatchThrown(function(){
	Index::registerWriter('CNT', '\CountOut', Array('x'=>1), 'text/plain; charset=utf-8');
	return Array(Index::forExtnsParser('cnt')->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array(1, 2, 3))), Index::optionsOfExtns('cnt'), Index::contentTypeFor('Cnt'));
}), Array('3', Array('x'=>1), 'text/plain; charset=utf-8'));
checkIdentical('index: a writer added without content type is sent as bytes; one of this namespace by its short name', runCatchThrown(function(){
	Index::registerWriter('bin', '\CountOut');
	Index::registerWriter('page', 'Html', Array('whole'=>true), 'text/html');
	return Array(Index::contentTypeFor('bin'), get_class(Index::forExtnsParser('page')), Index::optionsOfExtns('page'));
}), Array('application/octet-stream', 'nyu\Data\Writers\Html', Array('whole'=>true)));
checkPregMatch('index: a class that is not a writer is refused', runCatchThrown(function(){ Index::registerWriter('bad', '\stdClass'); }), '/^THROWN RuntimeException: Not a writer: \\\\stdClass/');
checkPregMatch('index: a class that does not exist is refused', runCatchThrown(function(){ Index::registerWriter('bad', 'Nowhere'); }), '/^THROWN RuntimeException: Not a writer: Nowhere/');
checkPregMatch('index: an extension that is not a plain name is refused', runCatchThrown(function(){ Index::registerWriter('../x', 'Html'); }), '/^THROWN RuntimeException: Invalid file extension/');
checkPregMatch('index: a content type that could start another header is refused', runCatchThrown(function(){ Index::registerWriter('evil', 'Html', Array(), "text/html\r\nSet-Cookie: a=b"); }), '/^THROWN RuntimeException: Invalid content type/');
checkTrueValue('a refusal of a writer is an error of its own kind, and a RuntimeException', (new WriteError('x')) instanceof RuntimeException);

// Json
$valsArr = Array('name'=>'Les Enchaînés', 'seats'=>120, 'price'=>12.5, 'whole'=>3.0, 'open'=>true, 'none'=>null, 'url'=>'https://nyuark.test/a?b=1', 'tags'=>Array('a', 'b'), 'venue'=>Array('city'=>'Lyon'), 'empty'=>Array());
$jsonObj = new Json();
checkIdentical('json: values on one line, accents and slashes as they are, a number that is not whole with its point', putVals($jsonObj, $valsArr), '{"name":"Les Enchaînés","seats":120,"price":12.5,"whole":3.0,"open":true,"none":null,"url":"https://nyuark.test/a?b=1","tags":["a","b"],"venue":{"city":"Lyon"},"empty":[]}');
checkIdentical('numbers are written with the digits they need, whatever the server is set to', runCatchThrown(function() use ($jsonObj){
	$keptPrc = ini_set('serialize_precision', '100');
	$outList = Array(putVals($jsonObj, Array(0.1, 12.5, 1/3)), putVals(new Yaml(), Array(0.1, 1.5E-7)), ini_get('serialize_precision'));
	ini_set('serialize_precision', strval($keptPrc));
	return $outList;
}), Array('[0.1,12.5,0.3333333333333333]', "- 0.1\n- 1.5e-7\n", '100'));
checkIdentical('json prety: over several lines, ending with one', putVals($jsonObj, Array('a'=>1, 'b'=>Array(2, 3)), Array('prety'=>true)), "{\n    \"a\": 1,\n    \"b\": [\n        2,\n        3\n    ]\n}\n");
checkIdentical('json lines: one value per line', putVals($jsonObj, Array(Array('id'=>1, 'name'=>'Été'), 'text', 3, null, Array()), Array('lines'=>true)), "{\"id\":1,\"name\":\"Été\"}\n\"text\"\n3\nnull\n[]\n");
checkIdentical('json lines: nothing for an empty list; lines are never indented', Array(putVals($jsonObj, Array(), Array('lines'=>true)), putVals($jsonObj, Array(Array('a'=>1)), Array('lines'=>true, 'prety'=>true))), Array('', "{\"a\":1}\n"));
checkPregMatch('json lines: what is not a list is refused', putVals($jsonObj, Array('a'=>1), Array('lines'=>true)), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Content that cannot be written as JSON Lines: it is not a list/');
checkIdentical('json whole: the envelope, with its shape and its details', putVals($jsonObj, Array('a'=>1), Array('whole'=>true)), '{"shape":"nodes","infos":{"docus":1},"items":{"a":1}}');
checkIdentical('json: any shape is written as its values', Array(
	putVals($jsonObj, Array(Shape::makeSheetArray('S', Array(Array('a', 1)))), Array(), 'sheet'),
	putVals($jsonObj, Array(Shape::makeBlockArray('paragrph', Array(Shape::makeASpanArray('x', Array('boldFace'))))), Array(), 'block'),
	putVals($jsonObj, 'just text'), putVals($jsonObj, 42), putVals($jsonObj, null)
), Array(
	'[{"title":"S","attrs":{"shown":true,"merge":[]},"cells":[["a",1]]}]',
	'[{"block":"paragrph","attrs":[],"spans":[{"texts":"x","marks":["boldFace"],"links":"","field":""}],"items":[],"plain":"x"}]',
	'"just text"', '42', 'null'
));
checkPregMatch('json: a shape that is not a known one is refused', putVals($jsonObj, Array(), Array(), 'cloud'), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as JSON: cloud/');
checkPregMatch('json: what JSON cannot say is refused: a number without end', putVals($jsonObj, Array('a'=>INF)), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Content that cannot be written as JSON: /');
checkPregMatch('json: what JSON cannot say is refused: text that is not UTF-8', putVals($jsonObj, Array('a'=>"\xE9t\xE9")), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Content that cannot be written as JSON: /');
checkIdentical('json: what is written reads back the same', runCatchThrown(function() use ($valsArr, $jsonObj){
	$prsrObj = new JsonParser();
	return Array($prsrObj->parseDataArray(putVals($jsonObj, $valsArr))['items']===$valsArr, $prsrObj->parseDataArray(putVals($jsonObj, $valsArr, Array('prety'=>true)))['items']===$valsArr, $prsrObj->parseDataArray(putVals($jsonObj, Array($valsArr, $valsArr), Array('lines'=>true)), Array('lines'=>true))['items']===Array($valsArr, $valsArr));
}), Array(true, true, true));

// Yaml
$yamlObj = new Yaml();
checkIdentical('yaml: keys and values, lists and maps nested by indentation', putVals($yamlObj, Array('name'=>'Alpha Night', 'seats'=>120, 'price'=>12.5, 'whole'=>3.0, 'open'=>true, 'shut'=>false, 'none'=>null, 'tags'=>Array('a', 'b'), 'venue'=>Array('city'=>'Lyon', 'rooms'=>Array(Array('name'=>'one', 'seats'=>40), Array('name'=>'two'))), 'empty'=>Array())),
	"name: Alpha Night\nseats: 120\nprice: 12.5\nwhole: 3.0\nopen: true\nshut: false\nnone: null\ntags:\n  - a\n  - b\nvenue:\n  city: Lyon\n  rooms:\n    - name: one\n      seats: 40\n    - name: two\nempty: []\n");
checkIdentical('yaml: a list of lists, of maps holding maps, of nothing', putVals($yamlObj, Array(Array('a', Array('b', 'c')), Array('venue'=>Array('city'=>'Lyon'), 'open'=>true), Array(), null, 'text')), "- - a\n  - - b\n    - c\n- venue:\n    city: Lyon\n  open: true\n- []\n- null\n- text\n");
checkIdentical('yaml: a text is bare when nothing else can be read in it, with its accents, its spaces, its dots and its dashes', putVals($yamlObj, Array('a'=>'Les Enchaînés', 'b'=>'été', 'c'=>'a.b-c_d/e + f', 'd'=>'_private', 'e'=>'Hall A')), "a: Les Enchaînés\nb: été\nc: a.b-c_d/e + f\nd: _private\ne: Hall A\n");
checkIdentical('yaml: a text that could be read as something else is quoted', putVals($yamlObj, Array('120', '12.5', '0123', '2026-12-28', '20:30', 'true', 'False', 'NULL', 'yes', 'No', 'on', '~', '', ' lead', 'trail ', 'a: b', 'a #b', '- item', '#tag', '&x', '*x', '!t', '[a]', '{a}', '@a', '%a', '"q"', "it's", 'a, b', '.inf', '-', '?')),
	"- \"120\"\n- \"12.5\"\n- \"0123\"\n- \"2026-12-28\"\n- \"20:30\"\n- \"true\"\n- \"False\"\n- \"NULL\"\n- \"yes\"\n- \"No\"\n- \"on\"\n- \"~\"\n- \"\"\n- \" lead\"\n- \"trail \"\n- \"a: b\"\n- \"a #b\"\n- \"- item\"\n- \"#tag\"\n- \"&x\"\n- \"*x\"\n- \"!t\"\n- \"[a]\"\n- \"{a}\"\n- \"@a\"\n- \"%a\"\n- \"\\\"q\\\"\"\n- \"it's\"\n- \"a, b\"\n- \".inf\"\n- \"-\"\n- \"?\"\n");
checkIdentical('yaml: line ends, tabulations, backslashes and control characters are written after a backslash', putVals($yamlObj, Array('a'=>"line one\nline two\ttab", 'b'=>'C:\\dir', 'c'=>"bell\x07 end\r")), "a: \"line one\\nline two\\ttab\"\nb: \"C:\\\\dir\"\nc: \"bell\\x07 end\\r\"\n");
checkIdentical('yaml: keys are quoted as texts are; numbers stay numbers', putVals($yamlObj, Array('plain key'=>1, 'true'=>2, 'a: b'=>3, 12=>4, ''=>5, 'été'=>6)), "plain key: 1\n\"true\": 2\n\"a: b\": 3\n12: 4\n\"\": 5\nété: 6\n");
checkIdentical('yaml: numbers: negative, very large, very small, without end, not a number', putVals($yamlObj, Array(-7, 0, -0.25, 1.0E+25, 1.5E-7, INF, -INF, NAN)), "- -7\n- 0\n- -0.25\n- 1.0e+25\n- 1.5e-7\n- .inf\n- -.inf\n- .nan\n");
checkIdentical('yaml: a content that is one value, or holds nothing', Array(putVals($yamlObj, 'just text'), putVals($yamlObj, 'needs: quotes'), putVals($yamlObj, 42), putVals($yamlObj, null), putVals($yamlObj, Array())), Array("just text\n", "\"needs: quotes\"\n", "42\n", "null\n", "[]\n"));
checkIdentical('yaml multi: each value as a document of its own', putVals($yamlObj, Array(Array('name'=>'one'), Array('a', 'b'), 'text'), Array('multi'=>true)), "---\nname: one\n---\n- a\n- b\n---\ntext\n");
checkPregMatch('yaml multi: what is not a list is refused', putVals($yamlObj, Array('a'=>1), Array('multi'=>true)), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Content that cannot be written as several YAML documents: it is not a list/');
checkPregMatch('yaml: a shape that is not values is refused', putVals($yamlObj, Array(), Array(), 'block'), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as YAML: block/');
checkPregMatch('yaml: what YAML cannot say is refused', putVals($yamlObj, Array('a'=>new stdClass())), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Content that cannot be written as YAML: a value that is neither text, number, boolean nor nothing/');
checkPregMatch('yaml: values nested deeper than allowed are refused', runCatchThrown(function() use ($yamlObj){
	$deepArr = 'x';
	for( $lvlIdx=0; $lvlIdx<70; $lvlIdx++ ){
		$deepArr = Array('k'=>$deepArr);
	}
	return putVals($yamlObj, $deepArr);
}), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Content that cannot be written as YAML: nested deeper than allowed/');

// Yaml: what is written reads back the same
$hardArr = Array(
	'name'=>'Les Enchaînés', 'seats'=>120, 'price'=>12.5, 'whole'=>3.0, 'open'=>true, 'none'=>null, 'zip'=>'0123', 'when'=>'2026-12-28', 'word'=>'yes', 'void'=>'', 'text'=>"line one\nline two\t\"quoted\" \\ end", 'note'=>'a: b # c', 'path'=>'C:\\dir',
	'tags'=>Array('a', 'true', '12', '- x', Array('deep', Array('deeper'))), 'venue'=>Array('city'=>'Lyon', 'rooms'=>Array(Array('name'=>'one', 'seats'=>40, 'kit'=>Array('piano', 'lights')), Array('name'=>'two'))),
	'true'=>'a key that is a word', 'a: b'=>'a key with a colon', 12=>'a key that is a number', 'empty'=>Array(), 'list of maps'=>Array(Array('a'=>Array('b'=>Array('c'=>1)))), 'big'=>1.0E+25, 'neg'=>-0.5
);
checkIdentical('yaml: what is written reads back the same, whatever it holds', runCatchThrown(function() use ($hardArr, $yamlObj){
	$backArr = (new YamlParser())->parseDataArray(putVals($yamlObj, $hardArr))['items'];
	return ($backArr===$hardArr) ? true : $backArr;
}), true);
checkIdentical('yaml: several documents read back the same', runCatchThrown(function() use ($hardArr, $yamlObj){
	return (new YamlParser())->parseDataArray(putVals($yamlObj, Array($hardArr, Array('a', 'b'), 'text', 12), Array('multi'=>true)), Array('multi'=>true))['items']===Array($hardArr, Array('a', 'b'), 'text', 12);
}), true);
checkIdentical('yaml: a file read then written then read is unchanged', runCatchThrown(function() use ($yamlObj){
	$prsrObj = new YamlParser();
	$frstArr = $prsrObj->parseDataArray("# A settings file\nname: Alpha Night   # the show\n\"quoted key\": 'it''s'\nzip: 0123\nurl: http://nyuark.test/a#b\ntags: [a, \"b, c\", 3, {k: v}]\nvenue:\n  rooms:\n    - one\n    - name: two\n      seats: 40\ntext: |\n  line one\n    indented\nfolded: >-\n  one\n  two\n")['items'];
	return $prsrObj->parseDataArray(putVals($yamlObj, $frstArr))['items']===$frstArr;
}), true);
checkIdentical('one writer, several contents; no PHP warning along the way', Array(putVals($yamlObj, Array('a'=>1)), putVals($jsonObj, Array('a'=>1)), takeNotesArray()), Array("a: 1\n", '{"a":1}', Array()));

finishChecking();
