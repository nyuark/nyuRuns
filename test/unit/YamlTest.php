<?php

// Data\Parsers\Yaml : the part of YAML that settings files use, and what
// is refused outside it.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Mdwn;
use nyu\Data\Parsers\Yaml;

/**
 * Parses YAML and returns its items, or the reason it was refused.
 *
 * @param String $rawContent The YAML content.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The items, or a 'THROWN ...' string.
 */
function ymlItems( String $rawContent, Array $parseOptns=Array() ): Mixed {
	return runSafely(function() use ($rawContent, $parseOptns){
		$prsrObj = new Yaml();
		return $prsrObj->parseData($rawContent, $parseOptns)['items'];
	});
}

/**
 * Builds the pattern a refusal is expected to match.
 *
 * @param String $failReason What is wrong, as a piece of regular expression.
 * @param Int    $lineNumber The line it stands on.
 * @return String The pattern.
 */
function ymlFail( String $failReason, Int $lineNumber ): String {
	return '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid YAML content: '.$failReason.' on line '.$lineNumber.' @/';
}

// The envelope
checkSame('keys and values, and the envelope', runSafely(function(){ return (new Yaml())->parseData("name: Alpha Night\nseats: 120\n"); }), Array('shape'=>'nodes', 'infos'=>Array('docus'=>1), 'items'=>Array('name'=>'Alpha Night', 'seats'=>120)));
checkSame('an empty content, or one of comments only, gives an empty tree', Array(runSafely(function(){ return (new Yaml())->parseData(''); }), ymlItems("# only\n\n   \n# comments\n"), ymlItems("---\n...\n")), Array(Array('shape'=>'nodes', 'infos'=>Array('docus'=>0), 'items'=>Array()), Array(), Array()));

// Values
checkSame('bare values are read as what they write', ymlItems("a: 120\nb: -7\nc: +3\nd: 12.50\ne: -0.25\nf: 1e3\ng: .5\nh: true\ni: False\nj: ~\nk: null\nl:\nm: 0x1F\nn: 0o17\no: word\np: two words"), Array('a'=>120, 'b'=>-7, 'c'=>3, 'd'=>12.5, 'e'=>-0.25, 'f'=>1000.0, 'g'=>0.5, 'h'=>true, 'i'=>false, 'j'=>null, 'k'=>null, 'l'=>null, 'm'=>31, 'n'=>15, 'o'=>'word', 'p'=>'two words'));
checkSame('what only looks like a number or a boolean stays text', ymlItems("a: 0123\nb: 2026-12-28\nc: 20:30\nd: 1.2.3\ne: yes\nf: no\ng: on\nh: 1234567890123456789012\ni: 12a\nj: nulls\nk: 1_000"), Array('a'=>'0123', 'b'=>'2026-12-28', 'c'=>'20:30', 'd'=>'1.2.3', 'e'=>'yes', 'f'=>'no', 'g'=>'on', 'h'=>'1234567890123456789012', 'i'=>'12a', 'j'=>'nulls', 'k'=>'1_000'));
checkSame('infinity, and what is not a number', runSafely(function(){
	$valsArr = (new Yaml())->parseData("a: .inf\nb: -.INF\nc: .nan")['items'];
	return Array($valsArr['a'], $valsArr['b'], is_nan($valsArr['c']));
}), Array(INF, -INF, true));
checkSame('quoted values are text, whatever they look like', ymlItems("a: \"120\"\nb: 'true'\nc: \"\"\nd: ''\ne: 'it''s'\nf: \"say \\\"hi\\\"\"\ng: '#not a comment'\nh: \"a: b\"\ni: '[1, 2]'"), Array('a'=>'120', 'b'=>'true', 'c'=>'', 'd'=>'', 'e'=>"it's", 'f'=>'say "hi"', 'g'=>'#not a comment', 'h'=>'a: b', 'i'=>'[1, 2]'));
checkSame('between double quotes, a backslash writes a character', ymlItems('a: "tab\there\nline \\\\ \/ \x41 \u00e9 \U0001F600 \e"'), Array('a'=>"tab\there\nline \\ / A é \u{1F600} \x1B"));
checkSame('between single quotes, a backslash is a backslash', ymlItems("a: 'C:\\dir\\n'"), Array('a'=>'C:\\dir\\n'));
checkSame('colons, hashes and dashes inside a bare value', ymlItems("url: http://nyuark.test/a#b\npath: C:\\dir\ntime: 20:30:00\nnote: a - b\ntag: a#b\nmath: 1 + 1"), Array('url'=>'http://nyuark.test/a#b', 'path'=>'C:\\dir', 'time'=>'20:30:00', 'note'=>'a - b', 'tag'=>'a#b', 'math'=>'1 + 1'));
checkSame('accents, in keys and in values', ymlItems("événement: Les Enchaînés\nlieu: \"Théâtre de l'Été\""), Array('événement'=>'Les Enchaînés', 'lieu'=>"Théâtre de l'Été"));

// Comments
checkSame('a comment starts a line or follows a space', ymlItems("# top\nname: Alpha # the show\n  # indented comment\nseats: 120#not one\nnote: \"quoted # kept\" # dropped\nlist: [a, b] # dropped\n"), Array('name'=>'Alpha', 'seats'=>'120#not one', 'note'=>'quoted # kept', 'list'=>Array('a', 'b')));

// Keys
checkSame('keys: quoted, with spaces, numbers, and words that are values elsewhere', ymlItems("\"quoted key\": 1\n'single ''q''': 2\nkey with spaces: 3\n12: 4\ntrue: 5\n-dash: 6\na.b-c_d: 7"), Array('quoted key'=>1, "single 'q'"=>2, 'key with spaces'=>3, 12=>4, 'true'=>5, '-dash'=>6, 'a.b-c_d'=>7));
checkSame('a colon needs a space after it to end a key', ymlItems("a:b"), 'a:b');

// Nesting
checkSame('maps inside maps, by indentation', ymlItems("venue:\n  name: Hall A\n  place:\n      city: Lyon\n      zip: \"69001\"\n  open: true\nlast: end"), Array('venue'=>Array('name'=>'Hall A', 'place'=>Array('city'=>'Lyon', 'zip'=>'69001'), 'open'=>true), 'last'=>'end'));
checkSame('lists, further in than their key or at its level', ymlItems("a:\n  - one\n  - two\nb:\n- three\n- four\nc: end"), Array('a'=>Array('one', 'two'), 'b'=>Array('three', 'four'), 'c'=>'end'));
checkSame('a list of maps, of lists, of nothing', ymlItems("- name: one\n  seats: 40\n-\n  name: two\n- - a\n  - b\n-\n- ~\n- [c, d]\n-   name: three"), Array(Array('name'=>'one', 'seats'=>40), Array('name'=>'two'), Array('a', 'b'), null, null, Array('c', 'd'), Array('name'=>'three')));
checkSame('a document that is a list, or a single value', Array(ymlItems("- a\n- 2"), ymlItems('just text'), ymlItems('42'), ymlItems('"quoted"')), Array(Array('a', 2), 'just text', 42, 'quoted'));

// Between brackets
checkSame('lists and maps on one line, one inside the other', ymlItems("tags: [a, \"b, c\", 3, [x, y], {k: v, n: 2}, 'd']\nmap: {host: localhost, port: 3306, \"q k\": [1, 2], flag, none: }\nempty: []\nvoid: {}"), Array('tags'=>Array('a', 'b, c', 3, Array('x', 'y'), Array('k'=>'v', 'n'=>2), 'd'), 'map'=>Array('host'=>'localhost', 'port'=>3306, 'q k'=>Array(1, 2), 'flag'=>null, 'none'=>null), 'empty'=>Array(), 'void'=>Array()));
checkSame('a comma may end a list or a map', ymlItems("a: [1, 2, ]\nb: {x: 1, }"), Array('a'=>Array(1, 2), 'b'=>Array('x'=>1)));

// Text over several lines
checkSame('| keeps the lines, > joins them; the text ends with one line end', ymlItems("a: |\n  line one\n    indented\n\n  line four\nb: >\n  one\n  two\n\n  three\nc: end"), Array('a'=>"line one\n  indented\n\nline four\n", 'b'=>"one two\nthree\n", 'c'=>'end'));
checkSame('- takes the line end off, + keeps all of them', ymlItems("a: |-\n  x\n\nb: >-\n  one\n  two\nc: |+\n  y\n\n\nd: >+\n  z\n\ne: end"), Array('a'=>'x', 'b'=>'one two', 'c'=>"y\n\n\n", 'd'=>"z\n\n", 'e'=>'end'));
checkSame('a digit tells how far in the text is written', ymlItems("a: |2\n    kept indent\n  flush\nb: |1-\n  x"), Array('a'=>"  kept indent\nflush\n", 'b'=>' x'));
checkSame('> keeps the line ends around a line written further in', ymlItems("a: >\n  one\n    code\n  two\n"), Array('a'=>"one\n  code\ntwo\n"));
checkSame('what looks like YAML inside such a text is text', ymlItems("a: |\n  key: value\n  - item\n  # not a comment\n  &x *y !!z\nb: 1"), Array('a'=>"key: value\n- item\n# not a comment\n&x *y !!z\n", 'b'=>1));
checkSame('such a text in a list, and without any line', ymlItems("- |\n  one\n- >-\n  two\n  three\n- |\n- end"), Array("one\n", 'two three', '', 'end'));
checkSame('a bare value carrying on over the lines further in', ymlItems("a: this goes\n  over three\n  lines # really\nb:\n  on its own\n  lines\nc: 1"), Array('a'=>'this goes over three lines', 'b'=>'on its own lines', 'c'=>1));

// Casts
checkSame('the four plain casts', ymlItems("a: !!str 120\nb: !!str true\nc: !!int \"42\"\nd: !!int 007\ne: !!float 3\nf: !!float '1.5'\ng: !!bool TRUE\nh: !!str \"x y\""), Array('a'=>'120', 'b'=>'true', 'c'=>42, 'd'=>7, 'e'=>3.0, 'f'=>1.5, 'g'=>true, 'h'=>'x y'));
checkLike('a cast that cannot be made is refused', ymlItems("a: 1\nb: !!int twelve"), ymlFail('"twelve" cannot be read as int', 2));
checkLike('a cast applies to a single value', ymlItems('a: !!str [1, 2]'), ymlFail('a cast applies to a single value', 1));

// Documents
$twoDocs = "%YAML 1.2\n---\nname: one\n...\n---\nname: two\n---\n# nothing here\n---\n- three\n";
checkSame('the first document is the one read; the envelope says how many there are', runSafely(function() use ($twoDocs){ return (new Yaml())->parseData($twoDocs); }), Array('shape'=>'nodes', 'infos'=>Array('docus'=>3), 'items'=>Array('name'=>'one')));
checkSame('multi: every document, in a list; those holding nothing are left out', ymlItems($twoDocs, Array('multi'=>true)), Array(Array('name'=>'one'), Array('name'=>'two'), Array('three')));
checkSame('multi, on a single document and on none', Array(ymlItems('a: 1', Array('multi'=>true)), ymlItems('', Array('multi'=>true))), Array(Array(Array('a'=>1)), Array()));
checkLike('a value on the line of --- is refused', ymlItems("--- text\n"), ymlFail('a value on the line of a document mark is not supported', 1));

// Options
checkSame('upper: the keys of the first level', ymlItems("name: a\nvenue:\n  city: b", Array('upper'=>true)), Array('NAME'=>'a', 'VENUE'=>Array('city'=>'b')));
checkLike('depth: nesting deeper than allowed is refused', ymlItems("a:\n  b:\n    c: 1", Array('depth'=>2)), ymlFail('nested deeper than allowed', 3));
checkSame('...and accepted at the limit, brackets counting too', Array(ymlItems("a:\n  b:\n    c: 1", Array('depth'=>3)), ymlItems('a: [[1]]', Array('depth'=>3))), Array(Array('a'=>Array('b'=>Array('c'=>1))), Array('a'=>Array(Array(1)))));
checkLike('...brackets too deep', ymlItems('a: [[[1]]]', Array('depth'=>3)), ymlFail('nested deeper than allowed', 1));

// What is refused: the parts of YAML that are not read
checkLike('an anchor', ymlItems("base: &dflt\n  host: localhost"), ymlFail('anchors and aliases are not supported', 1));
checkLike('an alias', ymlItems("a: 1\nb: *dflt"), ymlFail('anchors and aliases are not supported', 2));
checkLike('a map merged into another', ymlItems("live:\n  <<: {a: 1}"), ymlFail('merging a map into another is not supported', 2));
checkLike('an anchor between brackets', ymlItems('a: [&b 1, *b]'), ymlFail('anchors and aliases are not supported', 1));
checkLike('a tag that is not a plain cast', ymlItems("a: 1\nwhen: !!timestamp 2026-12-28"), ymlFail('tag !!timestamp is not supported', 2));
checkLike('a tag of an application', ymlItems('obj: !php/object "O:8:\"stdClass\":0:{}"'), ymlFail('tag !php\/object is not supported', 1));
checkLike('a tag on a map', ymlItems("a: !!map\n  b: 1"), ymlFail('tag !!map is not supported', 1));
checkLike('a tag between brackets', ymlItems('a: [!!str 1]'), ymlFail('a tag between brackets is not supported', 1));
checkLike('a key that is not plain text', ymlItems("? [a, b]\n: value"), ymlFail('a key that is not plain text is not supported', 1));
checkLike('a tabulation used to indent', ymlItems("a:\n\tb: 1"), ymlFail('tabulation used to indent', 2));

// What is refused: content that is not valid
checkLike('a bracket left open on its line', ymlItems("a: [1,\n  2]"), ymlFail('bracket not closed on its line', 1));
checkLike('a quote left open on its line', ymlItems("a: \"open\n  closed\""), ymlFail('quoted text not closed on its line', 1));
checkLike('text after a closing bracket', ymlItems('a: [1] x'), ymlFail('unexpected text after a closing bracket', 1));
checkLike('a key and its value inside a list, without braces', ymlItems('a: [x: 1]'), ymlFail('a key and its value inside a list need their own braces', 1));
checkLike('nothing between two commas', ymlItems('a: [1, , 2]'), ymlFail('a value was expected between brackets', 1));
checkLike('a key met twice', ymlItems("a: 1\nb: 2\na: 3"), ymlFail('key "a" met twice', 3));
checkLike('a key met twice between braces', ymlItems('m: {a: 1, a: 2}'), ymlFail('key "a" met twice', 1));
checkLike('a key indented under a value', ymlItems("a: 1\n  b: 2"), ymlFail('unexpected indentation', 2));
checkLike('a key between two levels', ymlItems("a:\n    b: 1\n  c: 2"), ymlFail('unexpected indentation', 3));
checkLike('a line that is not a key, in a map', ymlItems("a: 1\njust text\n"), ymlFail('a key was expected', 2));
checkLike('a map after a list, at the same level', ymlItems("- a\nb: 1"), ymlFail('unexpected content', 2));
checkLike('an unknown escape', ymlItems('a: "\q"'), ymlFail('\\\\q is not a known escape', 1));
checkLike('an unknown mark for a text over several lines', ymlItems("a: |x\n  t"), ymlFail('unknown mark "\|x" for a text over several lines', 1));
checkSame('no PHP warning is left behind by a refusal', takeNotes(), Array());

// Encodings and line ends
checkSame('any line end, a marker at the start, a file saved by an older tool', Array(ymlItems("a: 1\r\nb:\r\n  - x\r\n"), ymlItems("\xEF\xBB\xBFa: été"), ymlItems("a: \xE9t\xE9 \x80")), Array(Array('a'=>1, 'b'=>Array('x')), Array('a'=>'été'), Array('a'=>'été €')));

// A parser can be used again after a refusal
checkSame('one parser, several contents', runSafely(function(){
	$prsrObj = new Yaml();
	try{ $prsrObj->parseData("a: &x 1\nb: 2"); }catch( Throwable $e ){}
	return $prsrObj->parseData("c: 3")['items'];
}), Array('c'=>3));

// Through the index, Dcmnt and Markdown
checkSame('index: YAML by extension and by content type', Array(get_class(Index::forExtnsn('yaml')), get_class(Index::forExtnsn('YML')), get_class(Index::forCnType('application/yaml')), get_class(Index::forCnType('text/yaml; charset=utf-8')), get_class(Index::forCnType('application/x-yaml'))), array_fill(0, 5, 'nyu\Data\Parsers\Yaml'));
checkSame('a YAML file through Dcmnt, its keys in upper case as for the other settings files', runSafely(function(){
	$docPath = inTempDir('show.yml');
	file_put_contents($docPath, "name: Alpha\nvenue:\n  city: Lyon\n");
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readValue('shp'), $docFile->readValue('inf'), $docFile->readValue());
}), Array('nodes', Array('docus'=>1), Array('NAME'=>'Alpha', 'VENUE'=>Array('city'=>'Lyon'))));
checkLike('an invalid YAML file is refused, and named', runSafely(function(){
	$docPath = inTempDir('broken.yaml');
	file_put_contents($docPath, "a: 1\nb: *x\n");
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: broken\.yaml: Invalid YAML content: anchors and aliases are not supported on line 2/');
checkSame('the settings starting a Markdown text are read as YAML', runSafely(function(){
	return (new Mdwn())->parseData("---\ntitle: Alpha\ntags: [a, b]\ndraft: true\n---\n# Show\n")['infos'];
}), Array('title'=>'Show', 'front'=>Array('title'=>'Alpha', 'tags'=>Array('a', 'b'), 'draft'=>true)));
checkLike('...and refused with the text when they are not valid', runSafely(function(){
	return (new Mdwn())->parseData("---\ntitle: Alpha\nbase: *x\n---\n# Show\n");
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid Markdown settings: anchors and aliases are not supported on line 3/');

endChecks();
