<?php

// Data\Writers\Xmlx : XML written from a tree of values, and read back
// by Data\Parsers\XHml.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Parsers\XHml;
use nyu\Data\Writers\Index;
use nyu\Data\Writers\Xmlx;

/**
 * Writes values as XML, or returns the reason they were refused.
 *
 * @param mixed $itemsValue The values.
 * @param Array $writeOptns Options of the writer.
 * @param Array $infosArray What is told of the content.
 * @return mixed The XML, or a 'THROWN ...' string.
 */
function xmlWrite( Mixed $itemsValue, Array $writeOptns=Array(), Array $infosArray=Array() ): Mixed {
	return runCatchThrown(function() use ($itemsValue, $writeOptns, $infosArray){
		return (new Xmlx())->renderDocument(Array('shape'=>'nodes', 'infos'=>$infosArray, 'items'=>$itemsValue), $writeOptns);
	});
}

/**
 * Tells whether a text is one an XML reader takes.
 *
 * @param String $xmlContent The text.
 * @return Bool True when it is.
 */
function xmlIsOne( String $xmlContent ): Bool {
	return (new DOMDocument())->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
}

$headTxt = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
$feedXml = '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:x="urn:x" xml:lang="en"><title id="t1">A &amp; B &lt;c&gt;</title><entry x:kind="one" note="say &quot;hi&quot;"><p>mixed <b>bold</b> text</p><empty/></entry><entry>two</entry></feed>';

// The tree of elements the parser reads
$treeSet = (new XHml())->parseDataArray($headTxt.$feedXml);
checkIdentical('a document read as a tree is written back as it was', xmlWrite($treeSet['items']), $headTxt.$feedXml."\n");
checkIdentical('the addresses given to the prefixes of names are read, and written back', Array($treeSet['items']['attrs'], strpos(xmlWrite($treeSet['items']), 'xmlns:x="urn:x"')!==false), Array(Array('xmlns'=>'http://www.w3.org/2005/Atom', 'xmlns:x'=>'urn:x', 'xml:lang'=>'en'), true));
checkIdentical('without the line telling it is XML', xmlWrite($treeSet['items'], Array('heads'=>false)), $feedXml);
checkIdentical('over several lines; an element mixing text and elements stays on one', xmlWrite($treeSet['items'], Array('prety'=>true)), $headTxt.'<feed xmlns="http://www.w3.org/2005/Atom" xmlns:x="urn:x" xml:lang="en">'."\n".'  <title id="t1">A &amp; B &lt;c&gt;</title>'."\n".'  <entry x:kind="one" note="say &quot;hi&quot;">'."\n".'    <p>mixed <b>bold</b> text</p>'."\n".'    <empty/>'."\n".'  </entry>'."\n".'  <entry>two</entry>'."\n".'</feed>'."\n");
checkIdentical('written over several lines, the document is read back the same', (new XHml())->parseDataArray(xmlWrite($treeSet['items'], Array('prety'=>true)))['items'], $treeSet['items']);
checkIdentical('what an attribute holds is kept: its line ends and its tabs written as what they are', Array(xmlWrite(Array('tagnm'=>'a', 'tagid'=>'', 'attrs'=>Array('t'=>"one\ttwo\nthree", 'n'=>12, 'b'=>true, 'none'=>null), 'value'=>'', 'nodes'=>Array()), Array('heads'=>false)), (new XHml())->parseDataArray('<a t="one&#9;two&#10;three"/>')['items']['attrs']['t']), Array('<a t="one&#9;two&#10;three" n="12" b="true"/>', "one\ttwo\nthree"));
checkIdentical('what XML cannot hold is left out of a text', xmlWrite(Array('tagnm'=>'a', 'tagid'=>'', 'attrs'=>Array(), 'value'=>"bell\x07 and\x00 nul \u{FFFE}end", 'nodes'=>Array()), Array('heads'=>false)), '<a>bell and nul end</a>');

// Names
checkPregMatch('the name of an element XML does not take is refused', xmlWrite(Array('tagnm'=>'a b><c', 'tagid'=>'', 'attrs'=>Array(), 'value'=>'x', 'nodes'=>Array())), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Name that XML does not take: a b><c/');
checkPregMatch('so is the name of an attribute', xmlWrite(Array('tagnm'=>'a', 'tagid'=>'', 'attrs'=>Array('on click="x" y'=>'1'), 'value'=>'', 'nodes'=>Array())), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Name that XML does not take: on click="x" y/');
checkPregMatch('and the name asked for the root', xmlWrite(Array('a'=>1), Array('roots'=>'1st')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Name that XML does not take: 1st/');
checkIdentical('names of other alphabets, and with a prefix, are taken', xmlWrite(Array('tagnm'=>'données', 'tagid'=>'', 'attrs'=>Array('ns:clé'=>'é'), 'value'=>'été', 'nodes'=>Array()), Array('heads'=>false)), '<données ns:clé="é">été</données>');

// The compact form
$shrtSet = (new XHml())->parseDataArray($headTxt.$feedXml, Array('short'=>true));
checkIdentical('the compact form: the one key names the root, @ an attribute, #text the text, a list the same element again', xmlWrite($shrtSet['items'], Array('heads'=>false)), '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:x="urn:x" xml:lang="en"><title id="t1">A &amp; B &lt;c&gt;</title><entry x:kind="one" note="say &quot;hi&quot;"><p>mixed  text<b>bold</b></p><empty/></entry><entry>two</entry></feed>');
checkIdentical('written from the compact form, a document without mixed text is read back the same', (new XHml())->parseDataArray(xmlWrite((new XHml())->parseDataArray('<a x="1"><b>one</b><b>two</b><c><d/></c></a>', Array('short'=>true))['items']), Array('short'=>true))['items'], Array('a'=>Array('@x'=>'1', 'b'=>Array('one', 'two'), 'c'=>Array(Array('d'=>Array(''))))));

// Any other values
$dataArr = Array('status'=>'ok', 'count'=>2, 'ratio'=>0.1, 'open'=>true, 'none'=>null, 'users'=>Array(Array('id'=>1, 'name'=>'Anna & <Ben>'), Array('id'=>2, 'name'=>'Cléo')), 'tags'=>Array('a', 'b'), 'empty'=>Array(), 'a key'=>'spaced', '2nd'=>'starts with a digit', 'file'=>Fluxd::openBytesFluxd("\x00binary"));
checkIdentical('values: a key is an element, a list the same element again, a key XML does not take an item telling it', xmlWrite($dataArr, Array('heads'=>false)), '<root><status>ok</status><count>2</count><ratio>0.1</ratio><open>true</open><none/><users><id>1</id><name>Anna &amp; &lt;Ben&gt;</name></users><users><id>2</id><name>Cléo</name></users><tags>a</tags><tags>b</tags><empty/><item key="a key">spaced</item><item key="2nd">starts with a digit</item><file/></root>');
checkIdentical('the root named', xmlWrite(Array('status'=>'ok'), Array('heads'=>false, 'roots'=>'reply')), '<reply><status>ok</status></reply>');
checkIdentical('a list of values, a list in a list, a single value, nothing', Array(xmlWrite(Array('a', Array('b', 'c'), Array('k'=>'v')), Array('heads'=>false)), xmlWrite('just text', Array('heads'=>false)), xmlWrite(null, Array('heads'=>false)), xmlWrite(Array(), Array('heads'=>false, 'roots'=>'none'))), Array('<root><item>a</item><item><item>b</item><item>c</item></item><item><k>v</k></item></root>', '<root>just text</root>', '<root/>', '<none/>'));
checkIdentical('one key naming a list is not a root: the list is written under the root', xmlWrite(Array('users'=>Array('a', 'b')), Array('heads'=>false)), '<root><users>a</users><users>b</users></root>');
checkIdentical('attributes and text told with the values', xmlWrite(Array('book'=>Array('@id'=>7, '@lang'=>'fr', '#text'=>'Title', 'page'=>Array(Array('@n'=>1, '#text'=>'one'), Array('@n'=>2)))), Array('heads'=>false)), '<book id="7" lang="fr">Title<page n="1">one</page><page n="2"/></book>');
checkIdentical('values over several lines', xmlWrite(Array('status'=>'ok', 'users'=>Array(Array('id'=>1), Array('id'=>2))), Array('prety'=>true, 'roots'=>'reply')), $headTxt."<reply>\n  <status>ok</status>\n  <users>\n    <id>1</id>\n  </users>\n  <users>\n    <id>2</id>\n  </users>\n</reply>\n");
checkTrueValue('what is written from values is what an XML reader takes', xmlIsOne(strval(xmlWrite($dataArr))) && xmlIsOne(strval(xmlWrite(Array('a', Array('b')), Array('prety'=>true)))));
$deepArr = Array();
$deepRef = &$deepArr;
for($lvlIdx=0; $lvlIdx<300; $lvlIdx++){
	$deepRef['a'] = Array();
	$deepRef = &$deepRef['a'];
}
unset($deepRef);
checkPregMatch('values nested deeper than accepted are refused', xmlWrite($deepArr), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Tree nested deeper than the 256 levels allowed/');

// The kind of the document
checkIdentical('a document tells its kind and where its rules are told', Array(
	xmlWrite(Array('note'=>Array('to'=>'x')), Array('dtype'=>'note.dtd')),
	xmlWrite(Array('html'=>Array('body'=>'')), Array('heads'=>false, 'dtype'=>Array('publc'=>'-//W3C//DTD XHTML 1.0 Strict//EN', 'systm'=>'http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd'))),
	xmlWrite(Array('a'=>Array('b'=>1)), Array('heads'=>false, 'dtype'=>Array('names'=>'a')))
), Array($headTxt.'<!DOCTYPE note SYSTEM "note.dtd">'."\n".'<note><to>x</to></note>'."\n", '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">'."\n".'<html><body/></html>', '<!DOCTYPE a>'."\n".'<a><b>1</b></a>'));
$typdSet = (new XHml())->parseDataArray('<!DOCTYPE note SYSTEM "note.dtd"><note><to>x</to></note>');
checkIdentical('the kind a document was read with is told with it', $typdSet['infos']['dtype'], Array('names'=>'note', 'publc'=>'', 'systm'=>'note.dtd'));
checkIdentical('and written back with it, unless told otherwise', Array(xmlWrite($typdSet['items'], Array('heads'=>false), $typdSet['infos']), xmlWrite($typdSet['items'], Array('heads'=>false, 'dtype'=>false), $typdSet['infos']), xmlWrite($typdSet['items'], Array('heads'=>false, 'dtype'=>'other.dtd'), $typdSet['infos'])), Array('<!DOCTYPE note SYSTEM "note.dtd">'."\n".'<note><to>x</to></note>', '<note><to>x</to></note>', '<!DOCTYPE note SYSTEM "other.dtd">'."\n".'<note><to>x</to></note>'));
checkIdentical('a document that tells no kind is read without one', isset((new XHml())->parseDataArray('<note/>')['infos']['dtype']), false);
checkPregMatch('what would declare something in that line is refused: a quote ending the address', xmlWrite(Array('a'=>1), Array('dtype'=>'x.dtd" [<!ENTITY e SYSTEM "file:///etc/passwd">] "')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Kind of document that cannot be written/');
checkPregMatch('a sign a public name does not take', xmlWrite(Array('a'=>1), Array('dtype'=>Array('publc'=>'x" [<!ENTITY e "y">', 'systm'=>'a.dtd'))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Kind of document that cannot be written/');
checkPregMatch('a name of kind XML does not take', xmlWrite(Array('a'=>1), Array('dtype'=>Array('names'=>'a [<!ENTITY', 'systm'=>'a.dtd'))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Name that XML does not take/');
checkIdentical('no document written declares an entity, whatever it is given', Array(strpos(strval(xmlWrite(Array('a'=>'<!ENTITY e "x"> &e; <!DOCTYPE b>'), Array('dtype'=>'a.dtd'))), '<!ENTITY'), xmlWrite(Array('a'=>'&e; <!-- x --> <?php y ?> ]]>'), Array('heads'=>false))), Array(false, '<a>&amp;e; &lt;!-- x --&gt; &lt;?php y ?&gt; ]]&gt;</a>'));

// The writer and its shape
checkPregMatch('a shape that is not a tree of values', runCatchThrown(function(){ return (new Xmlx())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as XML: block/');
checkIdentical('the writer of the xml extension', Array(get_class(Index::forExtnsWriter('XML')), Index::contentTypeFor('xml')), Array('nyu\\Data\\Writers\\Xmlx', 'application/xml; charset=utf-8'));

finishChecking();
