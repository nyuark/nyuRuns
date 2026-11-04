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
checkLike('an unknown kind of block is refused', htmOfBlk(Array(Array('block'=>'title', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Unknown kind of block: title/');
checkLike('an unknown shape is refused', runSafely(function(){ return (new Html())->writeData(Array('shape'=>'cloud', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as HTML: cloud/');

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

// Styles
$stylEnv = Array('shape'=>'block', 'infos'=>Array('title'=>'Alpha Night', 'langg'=>'fr', 'frmat'=>Array(
	'heading 1'=>Array('class'=>'heading-1', 'fonts'=>'Calibri Light', 'sizes'=>16, 'color'=>'#2F5496', 'marks'=>Array('bold'), 'align'=>'centr'),
	'Quote'=>Array('class'=>'quote', 'fonts'=>'Calibri', 'sizes'=>11.5, 'marks'=>Array('ital', 'undr', 'strk'), 'align'=>'justf'),
	'Normal'=>Array('class'=>'normal'),
	'3 - Client'=>Array('class'=>'s-3-client', 'sizes'=>9),
	'Hostile'=>Array('class'=>'x{} body{display:none} .y', 'fonts'=>'Arial"; } body { display:none } .z { font-family:"x', 'sizes'=>'12pt; color:red', 'color'=>'red; background:url(javascript:alert(1))', 'marks'=>Array('bold; x', 'huge'), 'align'=>'center; x'),
	'Not a style'=>'text'
)), 'items'=>Array(
	textBlk('headr', 'Alpha Night', Array('level'=>1, 'style'=>'heading 1', 'tagid'=>'top')), textBlk('parag', 'Plain.', Array('style'=>'Normal')), textBlk('parag', 'Centred by hand.', Array('style'=>'Normal', 'align'=>'centr')),
	textBlk('quote', 'A night.', Array('style'=>'Quote', 'align'=>'right')), textBlk('parag', 'Client.', Array('style'=>'3 - Client')), textBlk('parag', 'Hostile.', Array('style'=>'Hostile', 'align'=>'center" onclick="x')),
	textBlk('parag', 'Unlisted.', Array('style'=>'Some Other Style')), textBlk('parag', 'No word in it.', Array('style'=>'***')), textBlk('codes', 'x', Array('style'=>'Code', 'langg'=>'php')),
	Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'entry', Array('style'=>'Normal'))), Array('style'=>'Never on a list')),
	Shape::makeBlock('table', Array(), Array(Shape::makeBlock('tbrow', Array(), Array(textBlk('tcell', 'cell', Array('style'=>'Normal', 'align'=>'justf', 'colsp'=>2))))))
));
$stylBdy = "<h1 id=\"top\" class=\"heading-1\">Alpha Night</h1>\n<p class=\"normal\">Plain.</p>\n<p class=\"normal\" style=\"text-align:center\">Centred by hand.</p>\n<blockquote class=\"quote\" style=\"text-align:right\">A night.</blockquote>\n<p class=\"s-3-client\">Client.</p>\n<p class=\"hostile\">Hostile.</p>\n"
	."<p class=\"some-other-style\">Unlisted.</p>\n<p>No word in it.</p>\n<pre class=\"code\"><code class=\"language-php\">x</code></pre>\n<ul class=\"never-on-a-list\">\n<li class=\"normal\">entry</li>\n</ul>\n<table>\n<tbody>\n<tr><td class=\"normal\" style=\"text-align:justify\" colspan=\"2\">cell</td></tr>\n</tbody>\n</table>\n";
checkSame('the style of a block is its class, its own alignment a style of its own; a class is the one told for the style when it is fit, or made of its name', runSafely(function() use ($stylEnv){ return (new Html())->writeData($stylEnv); }), $stylBdy);
checkSame('class: the styles are left out when told so; the alignments are not', runSafely(function() use ($stylEnv){ return substr((new Html())->writeData($stylEnv, Array('class'=>false)), 0, 108); }), "<h1 id=\"top\">Alpha Night</h1>\n<p>Plain.</p>\n<p style=\"text-align:center\">Centred by hand.</p>\n<blockquote st");
$stylCss = "<style>\n.heading-1{font-family:\"Calibri Light\";font-size:16pt;color:#2F5496;font-weight:bold;text-align:center}\n.quote{font-family:\"Calibri\";font-size:11.5pt;font-style:italic;text-decoration:underline line-through;text-align:justify}\n.s-3-client{font-size:9pt}\n</style>\n";
checkSame('frmat: the style sheet of the styles, each value checked before it is written, what is not fit left out', runSafely(function() use ($stylEnv){ return (new Html())->writeData($stylEnv, Array('frmat'=>true)); }), $stylCss.$stylBdy);
checkSame('a hostile style reaches neither the style sheet nor the page', runSafely(function() use ($stylEnv){
	$htmText = (new Html())->writeData($stylEnv, Array('whole'=>true));
	return Array(strpos($htmText, 'display'), strpos($htmText, 'javascript'), strpos($htmText, 'onclick'), strpos($htmText, 'background'), substr_count($htmText, '{'), substr_count($htmText, '}'));
}), Array(false, false, false, false, 3, 3));
checkSame('whole: the language of the content, its title, its style sheet in the head; frmat can be turned off', runSafely(function() use ($stylEnv, $stylCss, $stylBdy){
	$wrtrObj = new Html();
	return Array($wrtrObj->writeData($stylEnv, Array('whole'=>true))==="<!DOCTYPE html>\n<html lang=\"fr\">\n<head>\n<meta charset=\"utf-8\">\n<title>Alpha Night</title>\n".$stylCss."</head>\n<body>\n".$stylBdy."</body>\n</html>\n", strpos($wrtrObj->writeData($stylEnv, Array('whole'=>true, 'frmat'=>false)), '<style>'), strpos($wrtrObj->writeData(Array('shape'=>'block', 'infos'=>Array('langg'=>'fr" onload="x'), 'items'=>Array()), Array('whole'=>true)), 'onload'));
}), Array(true, false, false));
checkSame('the headers and the footers of the pages stand once around the content of a whole page, and nowhere otherwise', runSafely(function(){
	$pageEnv = Array('shape'=>'block', 'infos'=>Array('title'=>'T', 'phead'=>Array(textBlk('parag', 'nyuArk')), 'pfoot'=>Array(Shape::makeBlock('parag', Array(Shape::makeASpan('page '), Shape::makeASpan('1', Array(), '', 'pgnum'))))), 'items'=>Array(textBlk('parag', 'Body')));
	$wrtrObj = new Html();
	return Array($wrtrObj->writeData($pageEnv), strstr($wrtrObj->writeData($pageEnv, Array('whole'=>true)), '<body>'));
}), Array("<p>Body</p>\n", "<body>\n<header>\n<p>nyuArk</p>\n</header>\n<p>Body</p>\n<footer>\n<p>page <span data-field=\"pgnum\">1</span></p>\n</footer>\n</body>\n</html>\n"));

// Fields, notes, page breaks, tasks, who is quoted
checkSame('a field tells what it stands for, inside its link and around its formatting', htmOfBlk(Array(Shape::makeBlock('parag', Array(
	Shape::makeASpan('Page '), Shape::makeASpan('3', Array(), '', 'pgnum'), Shape::makeASpan(' of '), Shape::makeASpan('12', Array('bold'), '', 'pgtot'), Shape::makeASpan(', '), Shape::makeASpan('Alpha', Array('ital'), '/a', 'props:Client "Ref" <1>')
)))), "<p>Page <span data-field=\"pgnum\">3</span> of <span data-field=\"pgtot\"><strong>12</strong></span>, <a href=\"/a\"><span data-field=\"props:Client &quot;Ref&quot; &lt;1&gt;\"><em>Alpha</em></span></a></p>\n");
checkSame('the reference to a note is raised, the note starts with its number and holds its blocks', htmOfBlk(Array(
	Shape::makeBlock('parag', Array(Shape::makeASpan('Text'), Shape::makeASpan('1', Array(), '#fnote1'), Shape::makeASpan(' and'), Shape::makeASpan('2', Array(), '#enote2'), Shape::makeASpan(', not a note: '), Shape::makeASpan('3', Array(), '#fnotes'))),
	Shape::makeBlock('fnote', Array(Shape::makeASpan('Doors at 8.')), Array(textBlk('parag', 'More.')), Array('tagid'=>'fnote1', 'numbr'=>1)), Shape::makeBlock('fnote', Array(), Array(), Array('tagid'=>'enote2', 'numbr'=>2)), textBlk('fnote', 'No number.')
)), "<p>Text<sup><a href=\"#fnote1\">1</a></sup> and<sup><a href=\"#enote2\">2</a></sup>, not a note: <a href=\"#fnotes\">3</a></p>\n<aside id=\"fnote1\"><sup>1</sup> Doors at 8.\n<p>More.</p>\n</aside>\n<aside id=\"enote2\"><sup>2</sup> </aside>\n<aside>No number.</aside>\n");
checkSame('a page break is a rule of its own kind, and brings its rule to the style sheet', runSafely(function(){
	$brkEnvl = Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array(textBlk('parag', 'a'), Shape::makeBlock('rules', Array(), Array(), Array('break'=>true, 'tagid'=>'ignored')), Shape::makeBlock('rules', Array(), Array(), Array('break'=>false, 'tagid'=>'cut'))));
	$wrtrObj = new Html();
	return Array($wrtrObj->writeData($brkEnvl), $wrtrObj->writeData($brkEnvl, Array('frmat'=>true)), strpos($wrtrObj->writeData(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array(textBlk('parag', 'a'))), Array('frmat'=>true)), '<style>'));
}), Array("<p>a</p>\n<hr class=\"break\">\n<hr id=\"cut\">\n", "<style>\nhr.break{border:0;break-after:page;page-break-after:always}\n</style>\n<p>a</p>\n<hr class=\"break\">\n<hr id=\"cut\">\n", false));
checkSame('a task shows its box, done or not; who is quoted is told under the quotation', htmOfBlk(Array(
	Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'done', Array('check'=>true)), textBlk('entry', 'todo', Array('check'=>false)), textBlk('entry', 'plain'))),
	textBlk('quote', 'Short.', Array('cites'=>'Alice & <Bob>')), Shape::makeBlock('quote', Array(Shape::makeASpan('Long.')), Array(textBlk('parag', 'More.')), Array('cites'=>'Carol')), textBlk('quote', 'Nobody.')
)), "<ul>\n<li><input type=\"checkbox\" disabled checked> done</li>\n<li><input type=\"checkbox\" disabled> todo</li>\n<li>plain</li>\n</ul>\n<blockquote>Short.\n<cite>Alice &amp; &lt;Bob&gt;</cite>\n</blockquote>\n<blockquote>Long.\n<p>More.</p>\n<cite>Carol</cite>\n</blockquote>\n<blockquote>Nobody.</blockquote>\n");

// Sheets: names of columns, merged cells, hidden sheets
$keydSht = Shape::makeSheet('Shows', Array());
$keydSht['cells'] = Array(Array('Title'=>'Alpha <Night>', 'Seats'=>120, 'Open'=>true), Array('Title'=>'Les Enchaînés', 'Seats'=>null, 'Open'=>false, '4'=>'extra'));
checkSame('rows keyed by the names of their columns have those names as header, with or without the heads option', runSafely(function() use ($keydSht){
	$wrtrObj = new Html();
	$shtEnvl = Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array($keydSht));
	return Array($wrtrObj->writeData($shtEnvl), $wrtrObj->writeData($shtEnvl)===$wrtrObj->writeData($shtEnvl, Array('heads'=>true)));
}), Array("<table>\n<caption>Shows</caption>\n<thead>\n<tr><th>Title</th><th>Seats</th><th>Open</th></tr>\n</thead>\n<tbody>\n<tr><td>Alpha &lt;Night&gt;</td><td>120</td><td>true</td></tr>\n<tr><td>Les Enchaînés</td><td></td><td>false</td><td>extra</td></tr>\n</tbody>\n</table>\n", true));
$mrgdSht = Shape::makeSheet('Season', Array(Array('Season 2026', null, null, 'Notes'), Array('Title', 'Venue', 'Seats', 'long'), Array('Alpha', 'Hall A', 120, 'covered'), Array('end')), Array('merge'=>Array(
	Array('atrow'=>0, 'atcol'=>0, 'rowsp'=>1, 'colsp'=>3), Array('atrow'=>1, 'atcol'=>3, 'rowsp'=>2, 'colsp'=>1), Array('atrow'=>3, 'atcol'=>0, 'rowsp'=>5, 'colsp'=>1), Array('atrow'=>9, 'atcol'=>0, 'rowsp'=>2, 'colsp'=>2)
)));
checkSame('merged cells are one cell over the others; an area running past the last row stops there, one starting past it is left out', runSafely(function() use ($mrgdSht){ return (new Html())->writeData(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array($mrgdSht))); }),
	"<table>\n<caption>Season</caption>\n<tbody>\n<tr><td colspan=\"3\">Season 2026</td><td>Notes</td></tr>\n<tr><td>Title</td><td>Venue</td><td>Seats</td><td rowspan=\"2\">long</td></tr>\n<tr><td>Alpha</td><td>Hall A</td><td>120</td></tr>\n<tr><td>end</td></tr>\n</tbody>\n</table>\n");
checkSame('merged cells under a first row written as header: an area of the header is left as it is, the others keep their place', runSafely(function() use ($mrgdSht){ return (new Html())->writeData(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array($mrgdSht)), Array('heads'=>true)); }),
	"<table>\n<caption>Season</caption>\n<thead>\n<tr><th>Season 2026</th><th></th><th></th><th>Notes</th></tr>\n</thead>\n<tbody>\n<tr><td>Title</td><td>Venue</td><td>Seats</td><td rowspan=\"2\">long</td></tr>\n<tr><td>Alpha</td><td>Hall A</td><td>120</td></tr>\n<tr><td>end</td></tr>\n</tbody>\n</table>\n");
checkSame('shown: hidden sheets are written unless told to leave them out', runSafely(function(){
	$wrtrObj = new Html();
	$shtEnvl = Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(Shape::makeSheet('Seen', Array(Array('a'))), Shape::makeSheet('Hidden', Array(Array('b')), Array('shown'=>false))));
	return Array(substr_count($wrtrObj->writeData($shtEnvl), '<table>'), $wrtrObj->writeData($shtEnvl, Array('shown'=>true)));
}), Array(2, "<table>\n<caption>Seen</caption>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n"));
checkSame('one writer, several contents: the styles of one do not reach the next', runSafely(function() use ($stylEnv){
	$wrtrObj = new Html();
	$wrtrObj->writeData($stylEnv, Array('class'=>false, 'frmat'=>true));
	return $wrtrObj->writeData(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array(textBlk('parag', 'x', Array('style'=>'heading 1')))), Array('frmat'=>true));
}), "<p class=\"heading-1\">x</p>\n");
checkSame('no PHP warning from the new checks', takeNotes(), Array());

// The anchors of the headings, made as GitHub makes them for a Markdown page
$hdrList = Array(textBlk('headr', '1.4 What version 1.0 holds', Array('level'=>3)), textBlk('headr', '7.6 bundle: a copy, for each environment', Array('level'=>3)), textBlk('headr', 'Summary', Array('level'=>2)), textBlk('headr', 'Summary', Array('level'=>2)), textBlk('headr', 'Mine', Array('level'=>2, 'tagid'=>'kept')));
checkSame('anchors: none unless asked', htmOfBlk(Array(textBlk('headr', 'Summary', Array('level'=>2)))), "<h2>Summary</h2>\n");
checkSame('anchors: the text in lower case, its dots and commas and colons dropped, its spaces dashes; a title repeated numbered; an id of its own kept', htmOfBlk($hdrList, Array('anchr'=>true)),
	"<h3 id=\"14-what-version-10-holds\">1.4 What version 1.0 holds</h3>\n<h3 id=\"76-bundle-a-copy-for-each-environment\">7.6 bundle: a copy, for each environment</h3>\n<h2 id=\"summary\">Summary</h2>\n<h2 id=\"summary-1\">Summary</h2>\n<h2 id=\"kept\">Mine</h2>\n");
checkSame('anchors: each page starts its own list', Array(htmOfBlk(Array(textBlk('headr', 'Summary', Array('level'=>2))), Array('anchr'=>true)), htmOfBlk(Array(textBlk('headr', 'Summary', Array('level'=>2))), Array('anchr'=>true))), Array("<h2 id=\"summary\">Summary</h2>\n", "<h2 id=\"summary\">Summary</h2>\n"));
checkSame('anchors: the accents kept, as GitHub keeps them', htmOfBlk(Array(textBlk('headr', 'Les modèles', Array('level'=>2))), Array('anchr'=>true)), "<h2 id=\"les-modèles\">Les modèles</h2>\n");

endChecks();
