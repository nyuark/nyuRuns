<?php

// Data\Writers\Html : blocks, sheets and trees written as HTML, what is
// escaped, the addresses that are refused, and a page read then written.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Shape;
use nyu\Data\Parsers\XHml;
use nyu\Data\Writers\Html;
use nyu\Data\Writers\Writer;

/**
 * Writes blocks as HTML, or returns the reason it was refused.
 *
 * @param Array $blocksList The blocks.
 * @param Array $writeOptns Options of the writer.
 * @return String The HTML, or a 'THROWN ...' string.
 */
function htmOfBlk( Array $blocksList, Array $writeOptns=Array() ): String {
	return runSafely(function() use ($blocksList, $writeOptns){
		$wrtrObj = new Html();
		return $wrtrObj->writeData(Array('shape'=>'block', 'infos'=>Array(), 'items'=>$blocksList), $writeOptns);
	});
}

/**
 * Builds a block holding one plain piece of text.
 *
 * @param String $blockLabel Kind of the block.
 * @param String $textString Its text.
 * @param Array  $attrsArray Its attributes.
 * @return Array The block.
 */
function textBlk( String $blockLabel, String $textString, Array $attrsArray=Array() ): Array {
	return Shape::makeBlock($blockLabel, Array(Shape::makeASpan($textString)), Array(), $attrsArray);
}

checkTrue('the HTML writer is a writer', (new Html()) instanceof Writer);

// Text blocks
checkSame('headings, by level, with their id', htmOfBlk(Array(textBlk('headr', 'Alpha Night', Array('level'=>1, 'tagid'=>'show')), textBlk('headr', 'Dates', Array('level'=>3)))), "<h1 id=\"show\">Alpha Night</h1>\n<h3>Dates</h3>\n");
checkSame('a level out of range is brought back into it', htmOfBlk(Array(textBlk('headr', 'a', Array('level'=>9)), textBlk('headr', 'b', Array('level'=>0)), textBlk('headr', 'c'))), "<h6>a</h6>\n<h1>b</h1>\n<h1>c</h1>\n");
checkSame('a paragraph: formatting, a link, a line break', htmOfBlk(Array(Shape::makeBlock('parag', Array(
	Shape::makeASpan('Playing at '), Shape::makeASpan('Hall A', Array(), '/venues/a'), Shape::makeASpan(', '), Shape::makeASpan('120', Array('bold')), Shape::makeASpan(" seats.\nDoors at 8.")
)))), "<p>Playing at <a href=\"/venues/a\">Hall A</a>, <strong>120</strong> seats.<br>\nDoors at 8.</p>\n");
checkSame('every formatting, nested in a fixed order, inside its link', htmOfBlk(Array(Shape::makeBlock('parag', Array(Shape::makeASpan('x', Array('code', 'strk', 'undr', 'ital', 'bold'), 'https://nyuark.test/'))))), "<p><a href=\"https://nyuark.test/\"><strong><em><u><s><code>x</code></s></u></em></strong></a></p>\n");

// Lists
$subList = Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'Jazz'), textBlk('entry', 'Été', Array('tagid'=>'ete'))), Array('order'=>true));
$showLst = Shape::makeBlock('lists', Array(), Array(Shape::makeBlock('entry', Array(Shape::makeASpan('Music')), Array($subList)), textBlk('entry', 'Dance')), Array('tagid'=>'kinds'));
checkSame('a list holding a list', htmOfBlk(Array($showLst)), "<ul id=\"kinds\">\n<li>Music\n<ol>\n<li>Jazz</li>\n<li id=\"ete\">Été</li>\n</ol>\n</li>\n<li>Dance</li>\n</ul>\n");

// Tables
$headRow = Shape::makeBlock('thead', Array(), Array(textBlk('tcell', 'Title'), textBlk('tcell', 'Seats')));
$frstRow = Shape::makeBlock('tbrow', Array(), Array(textBlk('tcell', 'Alpha Night', Array('headr'=>true)), Shape::makeBlock('tcell', Array(Shape::makeASpan('120', Array('bold'))), Array(), Array('colsp'=>2, 'rowsp'=>1))));
$scndRow = Shape::makeBlock('tbrow', Array(), Array(textBlk('tcell', 'Les Enchaînés', Array('rowsp'=>3)), Shape::makeBlock('tcell')));
checkSame('a table: its header rows in a head, its rows in a body', htmOfBlk(Array(Shape::makeBlock('table', Array(), Array($headRow, $frstRow, $scndRow), Array('tagid'=>'tbl')))), "<table id=\"tbl\">\n<thead>\n<tr><th>Title</th><th>Seats</th></tr>\n</thead>\n<tbody>\n<tr><th>Alpha Night</th><td colspan=\"2\"><strong>120</strong></td></tr>\n<tr><td rowspan=\"3\">Les Enchaînés</td><td></td></tr>\n</tbody>\n</table>\n");
checkSame('a table without header row, and one without any row', htmOfBlk(Array(Shape::makeBlock('table', Array(), Array($scndRow)), Shape::makeBlock('table'))), "<table>\n<tbody>\n<tr><td rowspan=\"3\">Les Enchaînés</td><td></td></tr>\n</tbody>\n</table>\n<table>\n</table>\n");

// Quotes, code, rules and images
checkSame('a quote holding a paragraph', htmOfBlk(Array(Shape::makeBlock('quote', Array(Shape::makeASpan('Quoted')), Array(textBlk('parag', 'and more'))))), "<blockquote>Quoted\n<p>and more</p>\n</blockquote>\n");
checkSame('code: its lines as they are, its language as a class', htmOfBlk(Array(textBlk('codes', "if( \$a < 2 ){\n    echo \"x\";\n}", Array('langg'=>'php')), textBlk('codes', 'a  b'))), "<pre><code class=\"language-php\">if( \$a &lt; 2 ){\n    echo &quot;x&quot;;\n}</code></pre>\n<pre><code>a  b</code></pre>\n");
checkSame('a rule and an image', htmOfBlk(Array(Shape::makeBlock('rules'), textBlk('image', 'The hall', Array('sourc'=>'/img/a.png', 'tagid'=>'pic')))), "<hr>\n<img id=\"pic\" src=\"/img/a.png\" alt=\"The hall\">\n");

// What is escaped, and what is refused
checkSame('text is escaped, wherever it stands', htmOfBlk(Array(
	textBlk('headr', '<script>alert(1)</script>', Array('level'=>2)),
	Shape::makeBlock('parag', Array(Shape::makeASpan('a < b & "c" \'d\'', Array('bold'), '/find?a=1&b="2"'))),
	textBlk('image', '"><script>x</script>', Array('sourc'=>'/a.png?x="1"')),
	Shape::makeBlock('table', Array(), Array(Shape::makeBlock('tbrow', Array(), Array(textBlk('tcell', '<td>')))))
)), "<h2>&lt;script&gt;alert(1)&lt;/script&gt;</h2>\n<p><a href=\"/find?a=1&amp;b=&quot;2&quot;\"><strong>a &lt; b &amp; &quot;c&quot; &apos;d&apos;</strong></a></p>\n<img src=\"/a.png?x=&quot;1&quot;\" alt=\"&quot;&gt;&lt;script&gt;x&lt;/script&gt;\">\n<table>\n<tbody>\n<tr><td>&lt;td&gt;</td></tr>\n</tbody>\n</table>\n");
checkSame('an address that would run a script is not written; its text is', htmOfBlk(Array(
	Shape::makeBlock('parag', Array(Shape::makeASpan('one', Array(), 'javascript:alert(1)'), Shape::makeASpan('two', Array(), " JaVa\tScRiPt:alert(1)"), Shape::makeASpan('three', Array(), 'data:text/html,<b>'), Shape::makeASpan('four', Array(), 'mailto:a@nyuark.test'))),
	textBlk('image', 'bad', Array('sourc'=>'javascript:alert(1)'))
)), "<p>onetwothree<a href=\"mailto:a@nyuark.test\">four</a></p>\n");
checkSame('an id or a language that is not a plain name is not written', htmOfBlk(Array(textBlk('parag', 'x', Array('tagid'=>'a" onclick="x')), textBlk('codes', 'y', Array('langg'=>'php" onclick="x')))), "<p>x</p>\n<pre><code>y</code></pre>\n");
checkLike('an unknown kind of block is refused', htmOfBlk(Array(Array('block'=>'title', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''))), '/^THROWN RuntimeException: Unknown kind of block: title/');
checkLike('an unknown shape is refused', runSafely(function(){ return (new Html())->writeData(Array('shape'=>'cloud', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN RuntimeException: Shape that cannot be written as HTML: cloud/');

// The addresses themselves
checkSame('addresses that are kept', array_map(Array('nyu\Data\Shape', 'cleanLink'), Array('https://nyuark.test/a?b=1#c', 'HTTP://nyuark.test', 'mailto:a@nyuark.test', 'tel:+33100000000', '/venues/a', 'a/b.html', '../up', '#top', '?page=2', '//nyuark.test/x', '  /spaced  ', 'a:b/c d', '')), Array('https://nyuark.test/a?b=1#c', 'HTTP://nyuark.test', 'mailto:a@nyuark.test', 'tel:+33100000000', '/venues/a', 'a/b.html', '../up', '#top', '?page=2', '//nyuark.test/x', '/spaced', '', ''));
checkSame('addresses that are dropped', array_map(Array('nyu\Data\Shape', 'cleanLink'), Array('javascript:alert(1)', 'JAVASCRIPT:alert(1)', " java\nscript:alert(1)", "\x01javascript:alert(1)", "jav\tascript:alert(1)", 'vbscript:x', 'data:text/html;base64,AAAA', 'file:///etc/passwd', 'ftp://nyuark.test/x', 'blob:x')), array_fill(0, 10, ''));

// A whole page
checkSame('whole: a page around the content, with the title of infos', runSafely(function(){
	return (new Html())->writeData(Array('shape'=>'block', 'infos'=>Array('title'=>'Alpha <Night>'), 'items'=>Array(textBlk('parag', 'x'))), Array('whole'=>true));
}), "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title>Alpha &lt;Night&gt;</title>\n</head>\n<body>\n<p>x</p>\n</body>\n</html>\n");

// Sheets and trees
$shtEnvl = Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(Shape::makeSheet('Shows & more', Array(Array('Title', 'Seats', 'Open'), Array('Alpha <Night>', 120, true), Array('Les Enchaînés', null, 12.5))), Shape::makeSheet('', Array())));
checkSame('sheets: one table each, its title as caption', runSafely(function() use ($shtEnvl){ return (new Html())->writeData($shtEnvl); }), "<table>\n<caption>Shows &amp; more</caption>\n<tbody>\n<tr><td>Title</td><td>Seats</td><td>Open</td></tr>\n<tr><td>Alpha &lt;Night&gt;</td><td>120</td><td>true</td></tr>\n<tr><td>Les Enchaînés</td><td></td><td>12.5</td></tr>\n</tbody>\n</table>\n<table>\n</table>\n");
checkSame('sheets, heads: the first row as header', runSafely(function() use ($shtEnvl){ return (new Html())->writeData($shtEnvl, Array('heads'=>true)); }), "<table>\n<caption>Shows &amp; more</caption>\n<thead>\n<tr><th>Title</th><th>Seats</th><th>Open</th></tr>\n</thead>\n<tbody>\n<tr><td>Alpha &lt;Night&gt;</td><td>120</td><td>true</td></tr>\n<tr><td>Les Enchaînés</td><td></td><td>12.5</td></tr>\n</tbody>\n</table>\n<table>\n</table>\n");
checkSame('a tree of values is shown as it is, escaped', runSafely(function(){ return (new Html())->writeData(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array('name'=>'<b>Été</b>', 'list'=>Array(1, 2)))); }), "<pre>{\n    &quot;name&quot;: &quot;&lt;b&gt;Été&lt;/b&gt;&quot;,\n    &quot;list&quot;: [\n        1,\n        2\n    ]\n}</pre>\n");

// Read, then written
$pageHtm = "<h1 id=\"show\">Alpha <em>Night</em></h1>\n<p>Playing at <a href=\"/venues/a\">Hall A</a>, <strong>120</strong> seats.<br>\nDoors at 8.</p>\n<ul>\n<li>Music\n<ol>\n<li>Jazz</li>\n<li>Été</li>\n</ol>\n</li>\n<li>Dance</li>\n</ul>\n<table>\n<thead>\n<tr><th>Title</th><th>Seats</th></tr>\n</thead>\n<tbody>\n<tr><td>Les Enchaînés</td><td colspan=\"2\">80</td></tr>\n</tbody>\n</table>\n<blockquote>Quoted\n<p>and more</p>\n</blockquote>\n<pre><code class=\"language-php\">echo 1 &lt; 2;</code></pre>\n<hr>\n<img src=\"/img/a.png\" alt=\"The hall\">\n";
checkSame('a page read then written comes back as it was', runSafely(function() use ($pageHtm){
	return (new Html())->writeData((new XHml())->parseData($pageHtm, Array('reads'=>'html')));
}), $pageHtm);
checkSame('...and a hostile page comes back harmless', runSafely(function(){
	return (new Html())->writeData((new XHml())->parseData('<p onclick="alert(1)" id="a b">Hi <a href="javascript:alert(1)" onmouseover="x()">there</a><script>alert(2)</script></p><img src="x" onerror="alert(3)"><iframe src="//evil.test"></iframe>', Array('reads'=>'html')));
}), "<p>Hi there</p>\n<img src=\"x\" alt=\"\">\n");
checkSame('no PHP warning along the way', takeNotes(), Array());

endChecks();
