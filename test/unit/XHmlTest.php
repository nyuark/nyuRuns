<?php

// Data\Parsers\XHml : XML documents read into a tree of elements, the
// compact form, accents and encodings, and what is refused.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\XHml;

/**
 * Parses XML and returns its items, or the reason it was refused.
 *
 * @param String $rawContent The XML content.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The items, or a 'THROWN ...' string.
 */
function xmlItems( String $rawContent, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($rawContent, $parseOptns){
		$prsrObj = new XHml();
		return $prsrObj->parseDataArray($rawContent, $parseOptns)['items'];
	});
}

/**
 * Builds the element a test expects.
 *
 * @param String $tagNameStr Name of the element.
 * @param Array  $attrsArray Its attributes.
 * @param String $valueOfTag Its text.
 * @param Array  $childNodes Its children.
 * @return Array The element, with its five keys; its id is taken from its attributes.
 */
function elmt( String $tagNameStr, Array $attrsArray=Array(), String $valueOfTag='', Array $childNodes=Array() ): Array {
	return Array('tagnm'=>$tagNameStr, 'tagid'=>strval($attrsArray['id'] ?? $attrsArray['xml:id'] ?? ''), 'attrs'=>$attrsArray, 'value'=>$valueOfTag, 'nodes'=>$childNodes);
}

$showXml = "<show id=\"3\">\n  <title>Alpha Night</title>\n  <event date=\"2026-12-28\"/>\n  <event date=\"2027-01-03\"/>\n</show>";

// The tree
checkIdentical('every element has the same five keys, in document order', xmlItems($showXml), elmt('show', Array('id'=>'3'), '', Array(
	elmt('title', Array(), 'Alpha Night'),
	elmt('event', Array('date'=>'2026-12-28')),
	elmt('event', Array('date'=>'2027-01-03'))
)));
checkIdentical('the envelope: shape, and the encoding the document declared', runCatchThrown(function() use ($showXml){
	$prsrObj = new XHml();
	$envlope = $prsrObj->parseDataArray('<?xml version="1.0" encoding="iso-8859-1"?>'.$showXml);
	return Array($envlope['shape'], $envlope['infos'], $prsrObj->parseDataArray($showXml)['infos']);
}), Array('nodes', Array('reads'=>'xml', 'encod'=>'ISO-8859-1'), Array('reads'=>'xml', 'encod'=>'UTF-8')));
checkIdentical('a single element is a tree of one', xmlItems('<a/>'), elmt('a'));
checkIdentical('the id of an element stands beside its name, from id or xml:id, and stays in its attributes', Array(xmlItems('<a id="top"><b xml:id="in"/><c/></a>')), Array(Array('tagnm'=>'a', 'tagid'=>'top', 'attrs'=>Array('id'=>'top'), 'value'=>'', 'nodes'=>Array(
	Array('tagnm'=>'b', 'tagid'=>'in', 'attrs'=>Array('xml:id'=>'in'), 'value'=>'', 'nodes'=>Array()),
	Array('tagnm'=>'c', 'tagid'=>'', 'attrs'=>Array(), 'value'=>'', 'nodes'=>Array())
))));
checkIdentical('text and elements mixed keep their order, as #text nodes', xmlItems('<p>Hello <b>world</b>!</p>'), elmt('p', Array(), '', Array(
	elmt('#text', Array(), 'Hello '), elmt('b', Array(), 'world'), elmt('#text', Array(), '!')
)));
checkIdentical('the text of an element keeps its spacing', xmlItems('<a>  two  words  </a>')['value'], '  two  words  ');
checkIdentical('a CDATA section is plain text', xmlItems('<a><![CDATA[<not> & markup]]></a>')['value'], '<not> & markup');
checkIdentical('comments and processing instructions are left out', xmlItems('<?xml version="1.0"?><!-- top --><a><?php echo 1; ?><!-- in --><b>1</b></a>'), elmt('a', Array(), '', Array(elmt('b', Array(), '1'))));
checkIdentical('a prefixed name is kept whole, for elements and attributes; the address given to a prefix is an attribute too', xmlItems('<w:p xmlns:w="urn:w" w:rsid="7"><w:t xml:space="preserve">x</w:t></w:p>'), elmt('w:p', Array('xmlns:w'=>'urn:w', 'w:rsid'=>'7'), '', Array(elmt('w:t', Array('xml:space'=>'preserve'), 'x'))));
checkIdentical('the standard entities are read as their characters', xmlItems('<a t="&quot;x&quot;">&lt;b&gt; &amp; &#233;&#x20AC;</a>'), elmt('a', Array('t'=>'"x"'), '<b> & é€'));
checkIdentical('an empty content gives an empty tree', Array(xmlItems(''), xmlItems(" \n ")), Array(Array(), Array()));

// Accents and encodings: the result is always UTF-8
checkIdentical('accents in a value, an attribute and a name', xmlItems('<événement lieu="Théâtre de l\'Été"><titre>Les Enchaînés</titre></événement>'), elmt('événement', Array('lieu'=>"Théâtre de l'Été"), '', Array(elmt('titre', Array(), 'Les Enchaînés'))));
checkIdentical('a declared ISO-8859-1 document', xmlItems("<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><a lieu=\"Th\xE9\xE2tre\">Les Encha\xEEn\xE9s</a>"), elmt('a', Array('lieu'=>'Théâtre'), 'Les Enchaînés'));
checkIdentical('a declared Windows-1252 document, with its euro sign', xmlItems("<?xml version=\"1.0\" encoding=\"windows-1252\"?><a>12 \x80</a>")['value'], '12 €');
checkIdentical('a declared encoding is followed, not guessed: ISO-8859-2', xmlItems("<?xml version=\"1.0\" encoding=\"ISO-8859-2\"?><a>\xB3\xF3d\xBC</a>")['value'], 'łódź');
checkPregMatch('an encoding that does not exist is refused', xmlItems('<?xml version="1.0" encoding="KLINGON-9"?><a>x</a>'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content: unknown encoding KLINGON-9/');
checkIdentical('a document that declares nothing and is not UTF-8 is read as Windows-1252', xmlItems("<a lieu=\"Th\xE9\xE2tre\">\xE9t\xE9 \x80</a>"), elmt('a', Array('lieu'=>'Théâtre'), 'été €'));
checkIdentical('a UTF-8 document with its marker', xmlItems("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?><a>été</a>")['value'], 'été');
checkIdentical('a UTF-16 document', xmlItems("\xFF\xFE".iconv('UTF-8', 'UTF-16LE', '<?xml version="1.0" encoding="UTF-16"?><a>été</a>'))['value'], 'été');
checkPregMatch('a named entity that XML does not define is refused', xmlItems('<a>&eacute;t&eacute;</a>'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content: .*eacute.* on line 1/');

// The compact form
checkIdentical('short: attributes under @, elements of one name always a list', xmlItems($showXml, Array('short'=>true)), Array('show'=>Array(
	'@id'=>'3', 'title'=>Array('Alpha Night'), 'event'=>Array(Array('@date'=>'2026-12-28'), Array('@date'=>'2027-01-03'))
)));
checkIdentical('short: a single child is a list of one, so the shape never changes', xmlItems('<show><event date="2026-12-28"/></show>', Array('short'=>true)), Array('show'=>Array('event'=>Array(Array('@date'=>'2026-12-28')))));
checkIdentical('short: an element with attributes keeps its text under #text', xmlItems('<a><price cur="EUR">12.5</price></a>', Array('short'=>true)), Array('a'=>Array('price'=>Array(Array('@cur'=>'EUR', '#text'=>'12.5')))));
checkIdentical('short: a root holding only text', xmlItems('<a>x</a>', Array('short'=>true)), Array('a'=>'x'));

// What is refused
checkPregMatch('a document that is not well-formed, with the line', xmlItems("<a>\n<b>\n</a>"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content: .* on line 3/');
checkPregMatch('text that is not XML at all', xmlItems('{"a":1}'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content/');
checkPregMatch('two root elements', xmlItems('<a/><b/>'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content/');
$scrtPth = inTemporaryDir('secret.txt');
file_put_contents($scrtPth, 'TOP-SECRET');
checkPregMatch('an entity reading a local file is refused', xmlItems('<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x SYSTEM "file:///'.str_replace('\\', '/', $scrtPth).'">]><a>&x;</a>'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content: entity declarations are not accepted/');
// Another encoding does not hide a declaration : it is looked for once the text is decoded, and in the document once read
$entyXml = '<!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>';
checkPregMatch('entities declared in a UTF-16 document, with its marker', xmlItems("\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>'.$entyXml, 'UTF-16LE', 'UTF-8')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content: entity declarations are not accepted/');
checkPregMatch('entities declared in a UTF-16 document, without a marker', xmlItems(mb_convert_encoding('<?xml version="1.0" encoding="UTF-16LE"?>'.$entyXml, 'UTF-16LE', 'UTF-8')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content/');
checkPregMatch('entities declared in a UTF-7 document', xmlItems('<?xml version="1.0" encoding="UTF-7"?>'.mb_convert_encoding($entyXml, 'UTF-7', 'UTF-8')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content/');
checkPregMatch('entities declared in lower case', xmlItems('<?xml version="1.0"?><!DOCTYPE a [<!entity x "y">]><a>x</a>'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content/');
checkIdentical('a document telling its kind without declaring anything is read', is_array(xmlItems('<?xml version="1.0"?><!DOCTYPE a SYSTEM "a.dtd"><a>x</a>')), true);
checkPregMatch('entities that multiply each other are refused', xmlItems('<?xml version="1.0"?><!DOCTYPE a [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]><a>&c;</a>'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content: entity declarations are not accepted/');
checkIdentical('a document type without entities is accepted, and not fetched', xmlItems('<?xml version="1.0"?><!DOCTYPE a SYSTEM "http://127.0.0.1:9/never.dtd"><a>ok</a>')['value'], 'ok');
checkPregMatch('nesting deeper than allowed is refused', xmlItems('<a><b><c><d/></c></b></a>', Array('depth'=>3)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid XML content: nested deeper than allowed/');
checkIdentical('...and accepted at the limit', xmlItems('<a><b><c/></b></a>', Array('depth'=>3))['nodes'][0]['nodes'][0]['tagnm'], 'c');
checkIdentical('no PHP warning is left behind by a refusal', takePhpNotices(), Array());

// Through the index and Dcmnt
checkIdentical('index: XML by extension and by content type', Array(get_class(Index::getExtnsParser('xml')), get_class(Index::getExtnsParser('SVG')), get_class(Index::findTypeParser('application/xml; charset=utf-8')), get_class(Index::findTypeParser('text/xml')), get_class(Index::findTypeParser('application/atom+xml'))), array_fill(0, 5, 'nyu\Data\Parsers\XHml'));
checkIdentical('an XML file through Dcmnt', runCatchThrown(function() use ($showXml){
	$docPath = inTemporaryDir('show.xml');
	file_put_contents($docPath, '<?xml version="1.0" encoding="UTF-8"?>'."\n".$showXml);
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf'), $docFile->readFileDetail()['tagnm'], count($docFile->readFileDetail()['nodes']));
}), Array('xml', 'nodes', Array('reads'=>'xml', 'encod'=>'UTF-8'), 'show', 3));
checkPregMatch('an invalid XML file is refused, and named', runCatchThrown(function(){
	$docPath = inTemporaryDir('broken.xml');
	file_put_contents($docPath, '<a><b></a>');
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: broken\.xml: Invalid XML content/');

finishChecking();
