<?php

// Data\Parsers\XHml on HTML : pages read into text blocks or into a tree,
// what is left out, spacing, encodings, and pages that are not well-formed.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Shape;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\XHml;

/**
 * Parses HTML and returns its envelope, or the reason it was refused.
 *
 * @param String $rawContent The HTML content.
 * @param Array  $parseOptns Options of the parser, 'reads' being 'html' unless given.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function htmRead( String $rawContent, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($rawContent, $parseOptns){
		$prsrObj = new XHml();
		return $prsrObj->parseDataArray($rawContent, $parseOptns + Array('reads'=>'html'));
	});
}

/**
 * Writes blocks in a short form, one per line: the kind, the attributes
 * when there are some, then each span as [text|marks|>link]. The blocks
 * a block contains follow it, indented.
 *
 * @param Array  $blocksList The blocks.
 * @param String $indentText Indentation of this level.
 * @return Array The lines.
 */
function blkLines( Array $blocksList, String $indentText='' ): Array {
	$lineLst = Array();
	foreach( $blocksList as $oneBlk ){
		$spanTxt = '';
		foreach( $oneBlk['spans'] as $oneSpn ){
			$spanTxt .= '['.$oneSpn['texts'].(empty($oneSpn['marks']) ? '' : '|'.implode(',', $oneSpn['marks'])).(($oneSpn['links']==='') ? '' : '|>'.$oneSpn['links']).']';
		}
		$lineLst[] = rtrim($indentText.$oneBlk['block'].' '.(empty($oneBlk['attrs']) ? '' : printValueText($oneBlk['attrs']).' ').$spanTxt);
		$lineLst = array_merge($lineLst, blkLines($oneBlk['items'], $indentText.'  '));
	}
	return $lineLst;
}

/**
 * Parses HTML and returns its blocks in the short form.
 *
 * @param String $rawContent The HTML content.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The lines, or a 'THROWN ...' string.
 */
function htmLines( String $rawContent, Array $parseOptns=Array() ): Mixed {
	$envlope = htmRead($rawContent, $parseOptns);
	return is_array($envlope) ? blkLines($envlope['items']) : $envlope;
}

// The envelope
$showHtm = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>  Alpha \n  Night </title>\n</head>\n<body>\n<h1 id=\"show\">Alpha <em>Night</em></h1>\n<p>Playing at <a href=\"/venues/a\">Hall A</a>.</p>\n</body>\n</html>";
checkIdentical('a page gives text blocks, and says what it was read as, its encoding and its title', htmRead($showHtm), Array(
	'shape'=>'block',
	'infos'=>Array('reads'=>'html', 'encod'=>'UTF-8', 'title'=>'Alpha Night'),
	'items'=>Array(
		Array('block'=>'headline', 'attrs'=>Array('level'=>1, 'tagid'=>'show'), 'spans'=>Array(Array('texts'=>'Alpha ', 'marks'=>Array(), 'links'=>'', 'field'=>''), Array('texts'=>'Night', 'marks'=>Array('emphasis'), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Alpha Night'),
		Array('block'=>'paragrph', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'Playing at ', 'marks'=>Array(), 'links'=>'', 'field'=>''), Array('texts'=>'Hall A', 'marks'=>Array(), 'links'=>'/venues/a', 'field'=>''), Array('texts'=>'.', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Playing at Hall A.')
	)
));
checkIdentical('an empty page gives no block', Array(htmRead(''), htmRead(" \n ")['items']), Array(Array('shape'=>'block', 'infos'=>Array('reads'=>'html', 'encod'=>'UTF-8', 'title'=>''), 'items'=>Array()), Array()));
checkIdentical('a page without title', htmRead('<p>x</p>')['infos']['title'], '');
checkIdentical('the title is the one of the head, not one met in the body', htmRead('<html><head><title>Real</title></head><body><svg><title>Drawing</title></svg><p>x</p></body></html>')['infos']['title'], 'Real');

// Headings and paragraphs
checkIdentical('the six levels of heading', htmLines('<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6>'), Array('headline {"level":1} [a]', 'headline {"level":2} [b]', 'headline {"level":3} [c]', 'headline {"level":4} [d]', 'headline {"level":5} [e]', 'headline {"level":6} [f]'));
checkIdentical('formatting, alone and nested, in a fixed order', htmLines('<p><b>a</b><strong>b</strong> <i>c</i><em>d</em> <u>e</u> <s>f</s><del>g</del> <code>h</code> <strong>i<em>j<u>k</u></em></strong></p>'), Array('paragrph [ab|boldFace][ ][cd|emphasis][ ][e|undrLine][ ][fg|crossOut][ ][h|codeSpan][ ][i|boldFace][j|boldFace,emphasis][k|boldFace,emphasis,undrLine]'));
checkIdentical('a link carries its address over the formatting inside it', htmLines('<p><a href="https://nyuark.test/a?x=1&amp;y=2">See <b>this</b></a></p>'), Array('paragrph [See |>https://nyuark.test/a?x=1&y=2][this|boldFace|>https://nyuark.test/a?x=1&y=2]'));
checkIdentical('an empty paragraph, or one of spaces, gives nothing', htmLines('<p></p><p>   </p><h2> </h2><p>x</p>'), Array('paragrph [x]'));
checkIdentical('the id of a block is kept in its attrs', htmLines('<p id="intro">x</p><h2 id="more">y</h2><hr id="cut">'), Array('paragrph {"tagid":"intro"} [x]', 'headline {"level":2,"tagid":"more"} [y]', 'ruleLine {"tagid":"cut"}'));

// Spacing: as a browser shows it
checkIdentical('runs of spaces and line ends are one space, none at the ends', htmRead("<p>\n   Two\n   words   <b> and </b>  more \n</p>")['items'][0]['plain'], 'Two words and more');
checkIdentical('a space between two inline elements is kept', htmRead('<p><b>120</b> <i>seats</i></p>')['items'][0]['plain'], '120 seats');
checkIdentical('a line break stays a line break', htmRead("<p>First line <br>\n  second line<br/>third<br></p>")['items'][0]['plain'], "First line\nsecond line\nthird");
checkIdentical('a non-breaking space is not a space to be merged', htmRead('<p>8&nbsp;&nbsp;pm</p>')['items'][0]['plain'], "8\u{A0}\u{A0}pm");
checkIdentical('named and numbered entities are read as their characters', htmRead('<p>&eacute;t&eacute; &amp; &lt;b&gt; &euro; &#233; &#x20AC; &copy;</p>')['items'][0]['plain'], 'été & <b> € é € ©');

// Elements that only group others
checkIdentical('a div disappears: its text is a paragraph, its blocks follow in order', htmLines('<div id="top">Text in a div, <b>bold</b> then<p>a paragraph inside</p>tail text<div><div>deeper</div></div></div>'), Array('paragrph [Text in a div, ][bold|boldFace][ then]', 'paragrph [a paragraph inside]', 'paragrph [tail text]', 'paragrph [deeper]'));
checkIdentical('inline elements side by side are one paragraph', htmLines('<body><span>inline</span> <span>siblings</span><section><article>inside</article></section></body>'), Array('paragrph [inline siblings]', 'paragrph [inside]'));
checkIdentical('text alone is a paragraph', htmLines('just words'), Array('paragrph [just words]'));

// What is left out
checkIdentical('scripts, styles, forms and the head are left out', htmLines('<html><head><title>T</title><style>p{color:red}</style><script>alert(1)</script></head><body><p>kept<script>alert(2)</script></p><style>b{}</style><form><input name="q" value="typed"><button>Go</button></form><noscript>no</noscript><iframe src="/x">frame</iframe><p>too</p></body></html>'), Array('paragrph [kept]', 'paragrph [too]'));
checkIdentical('comments are left out', htmLines('<p>a<!-- hidden -->b</p><!-- <p>gone</p> -->'), Array('paragrph [ab]'));
checkIdentical('a link that would run a script loses its address, and keeps its text', htmLines("<p><a href=\"javascript:alert(1)\">one</a> <a href=\" JaVa\nScRiPt:alert(1)\">two</a> <a href=\"data:text/html,x\">three</a> <a href=\"vbscript:x\">four</a> <a href=\"mailto:a@nyuark.test\">mail</a> <a href=\"tel:+33100000000\">call</a> <a href=\"#top\">up</a></p>"), Array('paragrph [one two three four ][mail|>mailto:a@nyuark.test][ ][call|>tel:+33100000000][ ][up|>#top]'));

// Lists
checkIdentical('a list, its kind in attrs, with a list inside an entry', htmLines('<ul><li>Music<ol id="kinds"><li>Jazz</li><li id="ete">Été</li></ol></li><li>Dance</li></ul>'), Array(
	'itemList {"lines":2,"bytes":19,"order":false}',
	'  listItem [Music]',
	'    itemList {"lines":2,"bytes":9,"order":true,"tagid":"kinds"}',
	'      listItem [Jazz]',
	'      listItem {"tagid":"ete"} [Été]',
	'  listItem [Dance]'
));
checkIdentical('an entry made of paragraphs: the first is its text, the others are inside it', htmLines('<ul><li><p>Dance</p><p>Second <b>paragraph</b></p></li><li><ul><li>only a list</li></ul></li></ul>'), Array(
	'itemList {"lines":2,"bytes":32,"order":false}',
	'  listItem [Dance]',
	'    paragrph [Second ][paragraph|boldFace]',
	'  listItem',
	'    itemList {"lines":1,"bytes":11,"order":false}',
	'      listItem [only a list]'
));
checkIdentical('a list without entry gives nothing', htmLines('<ul> </ul><ol></ol>'), Array());

// Tables
$tblHtml = '<table id="tbl"><thead><tr><th>Title</th><th>Seats</th></tr></thead><tbody><tr><th>Alpha Night</th><td colspan="2"><b>120</b></td></tr><tr><td rowspan="3">Les Enchaînés</td><td></td></tr></tbody></table>';
checkIdentical('a table: header rows, rows, cells, and what describes it', htmLines($tblHtml), Array(
	'tableSet {"heads":["Title","Seats"],"colms":2,"lines":2,"bytes":39,"tagid":"tbl"}',
	'  tableTop',
	'    cellText [Title]',
	'    cellText [Seats]',
	'  tableRow',
	'    cellText {"headr":true} [Alpha Night]',
	'    cellText {"colsp":2} [120|boldFace]',
	'  tableRow',
	'    cellText {"rowsp":3} [Les Enchaînés]',
	'    cellText'
));
checkIdentical('rows have their cells in plain, a table has none', runCatchThrown(function() use ($tblHtml){
	$tblBlck = htmRead($tblHtml)['items'][0];
	return Array($tblBlck['plain'], $tblBlck['items'][0]['plain'], $tblBlck['items'][1]['plain'], $tblBlck['items'][2]['plain'], Shape::tableGridArray($tblBlck));
}), Array('', "Title\tSeats", "Alpha Night\t120", "Les Enchaînés\t", Array(Array('Title', 'Seats'), Array('Alpha Night', '120'), Array('Les Enchaînés', ''))));
checkIdentical('without a head, a row made of header cells only is the header row', htmLines('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'), Array('tableSet {"heads":["A","B"],"colms":2,"lines":1,"bytes":4}', '  tableTop', '    cellText [A]', '    cellText [B]', '  tableRow', '    cellText [1]', '    cellText [2]'));
checkIdentical('a cell holding blocks: its first paragraph is its text, the rest is inside it', htmLines('<table><tr><td><p>Shows</p><ul><li>Alpha</li></ul></td></tr></table>'), Array('tableSet {"heads":[],"colms":1,"lines":1,"bytes":10}', '  tableRow', '    cellText [Shows]', '      itemList {"lines":1,"bytes":5,"order":false}', '        listItem [Alpha]'));

// Quotes, code, rules and images
checkIdentical('a quote: its text, then the blocks inside it', htmLines('<blockquote id="q">Quoted <i>words</i><p>and more</p></blockquote>'), Array('citation {"tagid":"q"} [Quoted ][words|emphasis]', '  paragrph [and more]'));
checkIdentical('code keeps its spacing and its lines, and gives its language', htmRead("<pre><code class=\"hl language-php\">if( \$a &lt; 2 ){\n    echo \"x\";\n}\n</code></pre>")['items'], Array(Array('block'=>'codeBlck', 'attrs'=>Array('langg'=>'php'), 'spans'=>Array(Array('texts'=>"if( \$a < 2 ){\n    echo \"x\";\n}", 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>"if( \$a < 2 ){\n    echo \"x\";\n}")));
checkIdentical('code without language', htmLines("<pre>a  b</pre>"), Array('codeBlck [a  b]'));
checkIdentical('a rule, and images: the address in attrs, the description as text', htmLines('<hr><img src="/img/a.png" alt="The hall"><img src="https://nyuark.test/b.png"><img src="javascript:alert(1)" alt="bad"><img alt="no address">'), Array('ruleLine', 'imageBox {"sourc":"/img/a.png"} [The hall]', 'imageBox {"sourc":"https://nyuark.test/b.png"}'));
checkIdentical('an image inside a paragraph stands between its two halves', htmLines('<p>before <img src="a.png" alt="A"> after</p>'), Array('paragrph [before]', 'imageBox {"sourc":"a.png"} [A]', 'paragrph [after]'));

// The whole text, and sections
$longHtm = '<p>Before.</p><h1>Alpha Night</h1><p>Intro.</p><h2>Dates</h2><ul><li>Monday</li><li>Friday</li></ul><h1>Beta Evening</h1><p>Other show.</p>';
checkIdentical('the whole text of a page', Shape::documentAsText(htmRead($longHtm)['items']), "Before.\n\nAlpha Night\n\nIntro.\n\nDates\n\nMonday\nFriday\n\nBeta Evening\n\nOther show.");
checkIdentical('a page grouped into sections', runCatchThrown(function() use ($longHtm){
	$sctnLst = Shape::bySectionArray(htmRead($longHtm)['items']);
	return Array(count($sctnLst), $sctnLst[0]['title'], $sctnLst[1]['title'], $sctnLst[1]['items'][0]['plain'], $sctnLst[1]['nodes'][0]['title'], $sctnLst[1]['nodes'][0]['items'][0]['block'], $sctnLst[2]['title']);
}), Array(3, '', 'Alpha Night', 'Intro.', 'Dates', 'itemList', 'Beta Evening'));

// The tree, on request
checkIdentical('shape nodes: the page as a tree, completed as a browser does', htmRead('<p id="a">Hi <b>you</b> <i>two</i></p>', Array('shape'=>'nodes')), Array(
	'shape'=>'nodes',
	'infos'=>Array('reads'=>'html', 'encod'=>'UTF-8', 'title'=>''),
	'items'=>Array('tagnm'=>'html', 'tagid'=>'', 'attrs'=>Array(), 'value'=>'', 'nodes'=>Array(
		Array('tagnm'=>'body', 'tagid'=>'', 'attrs'=>Array(), 'value'=>'', 'nodes'=>Array(
			Array('tagnm'=>'p', 'tagid'=>'a', 'attrs'=>Array('id'=>'a'), 'value'=>'', 'nodes'=>Array(
				Array('tagnm'=>'#text', 'tagid'=>'', 'attrs'=>Array(), 'value'=>'Hi ', 'nodes'=>Array()),
				Array('tagnm'=>'b', 'tagid'=>'', 'attrs'=>Array(), 'value'=>'you', 'nodes'=>Array()),
				Array('tagnm'=>'#text', 'tagid'=>'', 'attrs'=>Array(), 'value'=>' ', 'nodes'=>Array()),
				Array('tagnm'=>'i', 'tagid'=>'', 'attrs'=>Array(), 'value'=>'two', 'nodes'=>Array())
			))
		))
	))
));
checkIdentical('shape nodes: the head and the scripts are there, the spacing between blocks is not', runCatchThrown(function() use ($showHtm){
	$rootArr = htmRead($showHtm, Array('shape'=>'nodes'))['items'];
	return Array($rootArr['attrs'], array_column($rootArr['nodes'], 'tagnm'), array_column($rootArr['nodes'][0]['nodes'], 'tagnm'), array_column($rootArr['nodes'][1]['nodes'], 'tagnm'));
}), Array(Array('lang'=>'en'), Array('head', 'body'), Array('meta', 'title'), Array('h1', 'p')));
checkIdentical('shape nodes, short form', htmRead('<p id="a">Hi</p>', Array('shape'=>'nodes', 'short'=>true))['items'], Array('html'=>Array('body'=>Array(Array('p'=>Array(Array('@id'=>'a', '#text'=>'Hi')))))));
checkIdentical('an XML document read as blocks, on request', runCatchThrown(function(){
	$prsrObj = new XHml();
	$envlope = $prsrObj->parseDataArray('<doc><h1>T</h1><p>Hello <b>w</b></p><item>loose</item></doc>', Array('shape'=>'block'));
	return Array($envlope['infos'], blkLines($envlope['items']));
}), Array(Array('reads'=>'xml', 'encod'=>'UTF-8'), Array('headline {"level":1} [T]', 'paragrph [Hello ][w|boldFace]', 'paragrph [loose]')));

// What the content is, when nothing says it
checkIdentical('a page is told from its first bytes; a piece of markup is XML unless said otherwise', runCatchThrown(function(){
	$prsrObj = new XHml();
	return Array(
		$prsrObj->parseDataArray("<!DOCTYPE html>\n<p>x</p>")['infos']['reads'], $prsrObj->parseDataArray('<html><body><p>x</p></body></html>')['shape'],
		$prsrObj->parseDataArray('<p>Just <b>this</b></p>')['infos']['reads'], $prsrObj->parseDataArray('<p>Just <b>this</b></p>')['shape']
	);
}), Array('html', 'block', 'xml', 'nodes'));

// Encodings: the result is always UTF-8
checkIdentical('a page declaring ISO-8859-1', runCatchThrown(function(){
	$envlope = htmRead("<html><head><meta charset=\"iso-8859-1\"><title>\xE9t\xE9</title></head><body><p>Th\xE9\xE2tre</p></body></html>");
	return Array($envlope['infos'], $envlope['items'][0]['plain']);
}), Array(Array('reads'=>'html', 'encod'=>'ISO-8859-1', 'title'=>'été'), 'Théâtre'));
checkIdentical('a page declaring Windows-1252 the old way', runCatchThrown(function(){
	$envlope = htmRead("<meta http-equiv=\"Content-Type\" content=\"text/html; charset=windows-1252\"><p>12 \x80</p>");
	return Array($envlope['infos']['encod'], $envlope['items'][0]['plain']);
}), Array('WINDOWS-1252', '12 €'));
checkIdentical('a declared encoding is followed, not guessed: ISO-8859-2', htmRead("<meta charset=\"iso-8859-2\"><p>\xB3\xF3d\xBC</p>")['items'][0]['plain'], 'łódź');
checkIdentical('a page that declares an encoding but is UTF-8 already is left as it is', htmRead('<meta charset="iso-8859-1"><p>Théâtre</p>')['items'][0]['plain'], 'Théâtre');
checkIdentical('a page that declares nothing and is not UTF-8 is read as Windows-1252', htmRead("<p>Th\xE9\xE2tre \x80</p>")['items'][0]['plain'], 'Théâtre €');
checkIdentical('a UTF-8 page with its marker, and a UTF-16 one', Array(htmRead("\xEF\xBB\xBF<p>été</p>")['items'][0]['plain'], htmRead("\xFF\xFE".iconv('UTF-8', 'UTF-16LE', '<p>été</p>'))['items'][0]['plain']), Array('été', 'été'));

// Pages that are not well-formed are repaired, never refused
checkIdentical('tags left open or closed twice', htmLines('<p>one<p>two <b>open</p></i><div>div'), Array('paragrph [one]', 'paragrph [two ][open|boldFace]', 'paragrph [div]'));
checkIdentical('unknown elements are read through', htmLines('<my-card><p>inside <x-tag>custom</x-tag></p></my-card>'), Array('paragrph [inside custom]'));
checkPregMatch('nesting deeper than allowed is refused', htmRead('<div><div><div><div><p>x</p></div></div></div></div>', Array('depth'=>4)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid HTML content: nested deeper than allowed/');
checkIdentical('no PHP warning is left behind by a page that is not well-formed', takePhpNotices(), Array());

// Through the index and Dcmnt
checkIdentical('index: HTML by extension and by content type', Array(get_class(Index::forExtnsParser('html')), get_class(Index::forExtnsParser('HTM')), get_class(Index::forExtnsParser('xhtml')), get_class(Index::findTypeParser('text/html; charset=utf-8')), Index::optionsOfExtns('html'), Index::optionsOfExtns('xml')), Array('nyu\Data\Parsers\XHml', 'nyu\Data\Parsers\XHml', 'nyu\Data\Parsers\XHml', 'nyu\Data\Parsers\XHml', Array('reads'=>'html'), Array('reads'=>'xml')));
checkIdentical('an HTML file through Dcmnt', runCatchThrown(function() use ($showHtm){
	$docPath = inTemporaryDir('show.html');
	file_put_contents($docPath, $showHtm);
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf'), array_column($docFile->readFileDetail(), 'block'));
}), Array('html', 'block', Array('reads'=>'html', 'encod'=>'UTF-8', 'title'=>'Alpha Night'), Array('headline', 'paragrph')));
checkIdentical('a piece of HTML in a .htm file, without anything of a page around it', runCatchThrown(function(){
	$docPath = inTemporaryDir('piece.htm');
	file_put_contents($docPath, '<p>Just <b>this</b></p>');
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readFileDetail('shp'), blkLines($docFile->readFileDetail()));
}), Array('block', Array('paragrph [Just ][this|boldFace]')));

finishChecking();
