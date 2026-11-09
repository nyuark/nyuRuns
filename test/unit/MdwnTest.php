<?php

// Data\Parsers\Mdwn : Markdown read into text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Shape;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Mdwn;
use nyu\Data\Writers\Html;

/**
 * Parses Markdown and returns its envelope, or the reason it was refused.
 *
 * @param String $rawContent The Markdown text.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function mdwRead( String $rawContent ): Mixed {
	return runCatchThrown(function() use ($rawContent){
		$prsrObj = new Mdwn();
		return $prsrObj->parseDataArray($rawContent);
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
 * Parses Markdown and returns its blocks in the short form.
 *
 * @param String $rawContent The Markdown text.
 * @return mixed The lines, or a 'THROWN ...' string.
 */
function mdwLines( String $rawContent ): Mixed {
	$envlope = mdwRead($rawContent);
	return is_array($envlope) ? blkLines($envlope['items']) : $envlope;
}

// The envelope
checkIdentical('a text gives blocks, and its first main heading as title', mdwRead("# Alpha *Night*\n\nPlaying at [Hall A](/venues/a).\n"), Array(
	'shape'=>'block',
	'infos'=>Array('title'=>'Alpha Night', 'front'=>Array()),
	'items'=>Array(
		Array('block'=>'headline', 'attrs'=>Array('level'=>1), 'spans'=>Array(Array('texts'=>'Alpha ', 'marks'=>Array(), 'links'=>'', 'field'=>''), Array('texts'=>'Night', 'marks'=>Array('emphasis'), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Alpha Night'),
		Array('block'=>'paragrph', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'Playing at ', 'marks'=>Array(), 'links'=>'', 'field'=>''), Array('texts'=>'Hall A', 'marks'=>Array(), 'links'=>'/venues/a', 'field'=>''), Array('texts'=>'.', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Playing at Hall A.')
	)
));
checkIdentical('an empty text gives no block', Array(mdwRead(''), mdwRead(" \n\n ")['items']), Array(Array('shape'=>'block', 'infos'=>Array('title'=>'', 'front'=>Array()), 'items'=>Array()), Array()));
checkIdentical('the title is the first heading of level 1, wherever it stands', Array(mdwRead("## Sub\n\ntext\n\n# Main\n\n# Other")['infos']['title'], mdwRead("## Only sub")['infos']['title']), Array('Main', ''));
checkIdentical('settings at the start of the text are set apart, and read', runCatchThrown(function(){
	$envlope = mdwRead("---\ntitle: Alpha\ntags: [a, b]\n---\n# Show\n");
	return Array($envlope['infos'], blkLines($envlope['items']));
}), Array(Array('title'=>'Show', 'front'=>Array('title'=>'Alpha', 'tags'=>Array('a', 'b'))), Array('headline {"level":1} [Show]')));
checkIdentical('a rule starting the text is not a block of settings', mdwLines("---\nJust text\n\n---\n"), Array('ruleLine', 'paragrph [Just text]', 'ruleLine'));

// Headings
checkIdentical('the six levels, with or without closing marks', mdwLines("# a\n## b ##\n### c\n#### d\n##### e\n###### f #\n####### not one\n#nor this"), Array('headline {"level":1} [a]', 'headline {"level":2} [b]', 'headline {"level":3} [c]', 'headline {"level":4} [d]', 'headline {"level":5} [e]', 'headline {"level":6} [f]', 'paragrph [####### not one #nor this]'));
checkIdentical('headings by the line under them', mdwLines("Alpha Night\n===========\n\nTwo lines\nof title\n---\ntext"), Array('headline {"level":1} [Alpha Night]', 'headline {"level":2} [Two lines of title]', 'paragrph [text]'));
checkIdentical('an id after the text of a heading', mdwLines("## Dates and *prices* {#dates}\n\nTitle {#top}\n====="), Array('headline {"level":2,"tagid":"dates"} [Dates and ][prices|emphasis]', 'headline {"level":1,"tagid":"top"} [Title]'));
checkIdentical('a heading cuts a paragraph, and may be empty', mdwLines("text\n# Title\nmore\n#\n"), Array('paragrph [text]', 'headline {"level":1} [Title]', 'paragrph [more]', 'headline {"level":1}'));

// Paragraphs and line breaks
checkIdentical('the lines of a paragraph are one text, a blank line ends it', mdwLines("one\n   two\nthree\n\n\nfour"), Array('paragrph [one two three]', 'paragrph [four]'));
checkIdentical('two spaces or a backslash at the end of a line ask for a line break', mdwRead("first  \nsecond\\\nthird\nfourth  ")['items'][0]['plain'], "first\nsecond\nthird fourth");

// Formatting
checkIdentical('bold, italic, both, struck and code', mdwLines('**bold** __bold__ *ital* _ital_ ***both*** ___both___ ~~struck~~ `code`'), Array('paragrph [bold|boldFace][ ][bold|boldFace][ ][ital|emphasis][ ][ital|emphasis][ ][both|boldFace,emphasis][ ][both|boldFace,emphasis][ ][struck|crossOut][ ][code|codeSpan]'));
checkIdentical('formatting inside formatting, over several words and lines', mdwLines("**one *two* three** *a **b** c* ~~x **y**~~ **over\ntwo lines**"), Array('paragrph [one |boldFace][two|boldFace,emphasis][ three|boldFace][ ][a |emphasis][b|boldFace,emphasis][ c|emphasis][ ][x |crossOut][y|boldFace,crossOut][ ][over two lines|boldFace]'));
checkIdentical('a star marks inside a word, an underscore does not', mdwLines('a*b*c snake_case_name a__b__c 2 * 3 * 4 _ no _ ** no ** __init__.py'), Array('paragrph [a][b|emphasis][c snake_case_name a__b__c 2 * 3 * 4 _ no _ ** no ** ][init|boldFace][.py]'));
checkIdentical('a mark after a backslash, and a mark never closed, are text', mdwLines('\*not\* \_nor\_ \`this\` \# \[x\] **open *and ~~these'), Array('paragrph [*not* _nor_ `this` # [x] **open *and ~~these]'));
checkIdentical('code is given as written, and may hold backticks', mdwLines('`*not* _this_ &amp; <b>` and `` a ` b `` and ` `` ` end'), Array('paragrph [*not* _this_ &amp; <b>|codeSpan][ and ][a ` b|codeSpan][ and ][``|codeSpan][ end]'));
checkIdentical('named and numbered entities are read as their characters', mdwRead('&eacute;t&eacute; &amp; &lt;b&gt; &euro; &#233; &copy; AT&T')['items'][0]['plain'], 'été & <b> € é © AT&T');
checkIdentical('HTML is not interpreted: it stays text', mdwLines("<div>block</div>\n\ntext <b>bold</b> <script>alert(1)</script> <br>"), Array('paragrph [<div>block</div>]', 'paragrph [text <b>bold</b> <script>alert(1)</script> <br>]'));

// Links
checkIdentical('a link carries its address over the formatting inside it; its title is left out', mdwLines('[the **site**](https://nyuark.test/a?x=1&y=2 "Home") and **[bold link](/b)** and [paren](/wiki/A_(b)) and [angle](</a b>)'), Array('paragrph [the |>https://nyuark.test/a?x=1&y=2][site|boldFace|>https://nyuark.test/a?x=1&y=2][ and ][bold link|boldFace|>/b][ and ][paren|>/wiki/A_(b)][ and ][angle|>/a b]'));
checkIdentical('an address between angle brackets is a link', mdwLines('<https://nyuark.test/x_y> <a@nyuark.test> <mailto:b@nyuark.test> <not a link> <b>'), Array('paragrph [https://nyuark.test/x_y|>https://nyuark.test/x_y][ ][a@nyuark.test|>mailto:a@nyuark.test][ ][mailto:b@nyuark.test|>mailto:b@nyuark.test][ <not a link> <b>]'));
checkIdentical('a link that would run a script loses its address, and keeps its text', mdwLines("[one](javascript:alert(1)) [two](JaVaScRiPt:alert(1)) [three](data:text/html,x) <javascript:alert(1)> [mail](mailto:a@nyuark.test) [up](#top)"), Array('paragrph [one two three <javascript:alert(1)> ][mail|>mailto:a@nyuark.test][ ][up|>#top]'));
checkIdentical('links by reference and footnotes stay as written', mdwLines("[text][ref] and note[^1]\n\n[ref]: https://nyuark.test"), Array('paragrph [[text][ref] and note[^1]]', 'paragrph [[ref]: https://nyuark.test]'));

// Images
checkIdentical('an image stands between the two halves of its paragraph', mdwLines('Before ![The hall](/img/a.png "title") after ![](b.png)'), Array('paragrph [Before]', 'imageBox {"sourc":"/img/a.png"} [The hall]', 'paragrph [after]', 'imageBox {"sourc":"b.png"}'));
checkIdentical('an image inside a link is the image; an unsafe one is left out', mdwLines('[![Logo](logo.png)](https://nyuark.test) ![bad](javascript:alert(1)) end'), Array('imageBox {"sourc":"logo.png"} [Logo]', 'paragrph [end]'));
checkIdentical('where only text can stand, an image is its description', mdwLines("# Title ![pic](p.png) here\n\n| ![a](a.png) |\n|---|\n"), Array('headline {"level":1} [Title pic here]', 'tableSet {"heads":["a"],"colms":1,"lines":0,"bytes":1}', '  tableTop', '    cellText [a]'));

// Lists
checkIdentical('a list with any of its marks, a numbered one, and a change of mark', mdwLines("- a\n- b\n* c\n+ d\n1. e\n2. f\n3) g"), Array(
	'itemList {"lines":2,"bytes":2,"order":false}', '  listItem [a]', '  listItem [b]',
	'itemList {"lines":1,"bytes":1,"order":false}', '  listItem [c]',
	'itemList {"lines":1,"bytes":1,"order":false}', '  listItem [d]',
	'itemList {"lines":2,"bytes":2,"order":true}', '  listItem [e]', '  listItem [f]',
	'itemList {"lines":1,"bytes":1,"order":true}', '  listItem [g]'
));
checkIdentical('a list inside an entry, and paragraphs inside an entry', mdwLines("- Music\n  1. Jazz\n  2. **Été**\n\n  Second paragraph\n- Dance\ncarried on\n\n- Loose\n"), Array(
	'itemList {"lines":3,"bytes":51,"order":false}',
	'  listItem [Music]',
	'    itemList {"lines":2,"bytes":9,"order":true}',
	'      listItem [Jazz]',
	'      listItem [Été|boldFace]',
	'    paragrph [Second paragraph]',
	'  listItem [Dance carried on]',
	'  listItem [Loose]'
));
checkIdentical('three levels, and code inside an entry', mdwLines("1. one\n   - two\n     - three\n\n   ```\n   code\n   ```\n2. four"), Array(
	'itemList {"lines":2,"bytes":19,"order":true}',
	'  listItem [one]',
	'    itemList {"lines":1,"bytes":8,"order":false}',
	'      listItem [two]',
	'        itemList {"lines":1,"bytes":5,"order":false}',
	'          listItem [three]',
	'    codeBlck [code]',
	'  listItem [four]'
));
checkIdentical('tasks, done or not', mdwLines("- [x] done\n- [ ] todo\n- [X] also done\n- [y] not a task\n- plain"), Array('itemList {"lines":5,"bytes":36,"order":false}', '  listItem {"check":true} [done]', '  listItem {"check":false} [todo]', '  listItem {"check":true} [also done]', '  listItem [[y] not a task]', '  listItem [plain]'));
checkIdentical('what only looks like a list; a list cuts a paragraph only when it clearly is one', mdwLines("-not a list\n1.5 is a number\n2. nor this, after text\n\ntext\n- cuts\n\ntext\n1. cuts too"), Array('paragrph [-not a list 1.5 is a number 2. nor this, after text]', 'paragrph [text]', 'itemList {"lines":1,"bytes":4,"order":false}', '  listItem [cuts]', 'paragrph [text]', 'itemList {"lines":1,"bytes":8,"order":true}', '  listItem [cuts too]'));
checkIdentical('a list ends where text follows a blank line', mdwLines("- a\n\ntext\n\n- b"), Array('itemList {"lines":1,"bytes":1,"order":false}', '  listItem [a]', 'paragrph [text]', 'itemList {"lines":1,"bytes":1,"order":false}', '  listItem [b]'));

// Quotes
checkIdentical('a quote: its text, then the blocks inside it; a line carrying on its paragraph', mdwLines("> Quoted **words**\n> second line\ncarried on\n>\n> - in quote\n> > deeper\n\nafter"), Array('citation [Quoted ][words|boldFace][ second line carried on]', '  itemList {"lines":1,"bytes":8,"order":false}', '    listItem [in quote]', '  citation [deeper]', 'paragrph [after]'));
checkIdentical('a quote starting with a heading has no text of its own', mdwLines(">## Title\n>text"), Array('citation', '  headline {"level":2} [Title]', '  paragrph [text]'));

// Code
checkIdentical('code between marks keeps its lines, its spacing and what looks like Markdown, and gives its language', mdwRead("```php\nif( \$a < 2 ){\n    echo \"*x*\";\n\n# not a heading\n}\n```\nafter")['items'], Array(
	Array('block'=>'codeBlck', 'attrs'=>Array('langg'=>'php'), 'spans'=>Array(Array('texts'=>"if( \$a < 2 ){\n    echo \"*x*\";\n\n# not a heading\n}", 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>"if( \$a < 2 ){\n    echo \"*x*\";\n\n# not a heading\n}"),
	Array('block'=>'paragrph', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'after', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'after')
));
checkIdentical('code between tildes, longer marks holding shorter ones, and marks never closed', mdwLines("~~~\na\n~~~\n````\n```\nb\n```\n````\n```js\nopen\nto the end"), Array('codeBlck [a]', "codeBlck [```\nb\n```]", "codeBlck {\"langg\":\"js\"} [open\nto the end]"));
checkIdentical('indented code, its blank lines kept, and an indented line that only carries on a paragraph', mdwLines("    first\n\n      second\ntext\n    still text"), Array("codeBlck [first\n\n  second]", 'paragrph [text still text]'));
checkIdentical('a tabulation starting a line indents as four spaces', mdwLines("\tcode\n\n-\titem"), Array('codeBlck [code]', 'itemList {"lines":1,"bytes":4,"order":false}', '  listItem [item]'));

// Rules
checkIdentical('rules, and what is not one', mdwLines("***\n\n- - -\n\n_____\n\n** *\n\n--\n\n**\n\n-*-"), Array('ruleLine', 'ruleLine', 'ruleLine', 'ruleLine', 'paragrph [--]', 'paragrph [**]', 'paragrph [-*-]'));

// Tables
$tblMdwn = "| Title | Seats |\n|:------|------:|\n| **Alpha** | 120 |\n| Les Enchaînés \\| x |\n| a | b | c |\nafter the table? no: a row\n\nafter";
checkIdentical('a table: its header row, its rows brought to the columns of the header', mdwLines($tblMdwn), Array(
	'tableSet {"heads":["Title","Seats"],"colms":2,"lines":4,"bytes":65}',
	'  tableTop', '    cellText [Title]', '    cellText [Seats]',
	'  tableRow', '    cellText [Alpha|boldFace]', '    cellText [120]',
	'  tableRow', '    cellText [Les Enchaînés | x]', '    cellText',
	'  tableRow', '    cellText [a]', '    cellText [b]',
	'  tableRow', '    cellText [after the table? no: a row]', '    cellText',
	'paragrph [after]'
));
checkIdentical('a table without outer bars, as a plain grid', Shape::tableGridArray(mdwRead("a | b\n--- | ---\n1 | 2\n")['items'][0]), Array(Array('a', 'b'), Array('1', '2')));
checkIdentical('a line of bars without the line under it is text', mdwLines("a | b\nc | d"), Array('paragrph [a | b c | d]'));
checkIdentical('the line under must have the columns of the header', mdwLines("a | b\n---\n"), Array('headline {"level":2} [a | b]'));

// With the rest
$longMdw = "Before.\n\n# Alpha Night\n\nIntro.\n\n## Dates\n\n- Monday\n- Friday\n\n# Beta Evening\n\nOther show.";
checkIdentical('a text grouped into sections', runCatchThrown(function() use ($longMdw){
	$sctnLst = Shape::bySectionArray(mdwRead($longMdw)['items']);
	return Array(count($sctnLst), $sctnLst[0]['title'], $sctnLst[1]['title'], $sctnLst[1]['items'][0]['plain'], $sctnLst[1]['nodes'][0]['title'], $sctnLst[1]['nodes'][0]['items'][0]['block'], $sctnLst[2]['title']);
}), Array(3, '', 'Alpha Night', 'Intro.', 'Dates', 'itemList', 'Beta Evening'));
checkIdentical('a text read then written as HTML', runCatchThrown(function(){
	return (new Html())->renderDocument((new Mdwn())->parseDataArray("# Alpha {#a}\n\nAt [Hall A](/a?x=1&y=2), **120** seats.  \nDoors at 8.\n\n- one\n- [x] two\n\n| T | S |\n|---|---|\n| a | 1 |\n\n> quoted\n\n```php\necho 1 < 2;\n```\n\n---\n\n![The hall](/img/a.png)\n"));
}), "<h1 id=\"a\">Alpha</h1>\n<p>At <a href=\"/a?x=1&amp;y=2\">Hall A</a>, <strong>120</strong> seats.<br>\nDoors at 8.</p>\n<ul>\n<li>one</li>\n<li><input type=\"checkbox\" disabled checked> two</li>\n</ul>\n<table>\n<thead>\n<tr><th>T</th><th>S</th></tr>\n</thead>\n<tbody>\n<tr><td>a</td><td>1</td></tr>\n</tbody>\n</table>\n<blockquote>quoted</blockquote>\n<pre><code class=\"language-php\">echo 1 &lt; 2;</code></pre>\n<hr>\n<img src=\"/img/a.png\" alt=\"The hall\">\n");
checkIdentical('...and a hostile text comes back harmless', runCatchThrown(function(){
	return (new Html())->renderDocument((new Mdwn())->parseDataArray("<script>alert(1)</script> [x](javascript:alert(2)) ![y](javascript:alert(3)) <img src=x onerror=alert(4)>\n\n# T {#a\" onclick=\"x}\n\n```a\" onclick=\"x\ncode\n```"));
}), "<p>&lt;script&gt;alert(1)&lt;/script&gt; x</p>\n<p>&lt;img src=x onerror=alert(4)&gt;</p>\n<h1>T {#a&quot; onclick=&quot;x}</h1>\n<pre><code>code</code></pre>\n");
checkIdentical('any line end, a marker at the start, a text saved by an older tool', Array(mdwLines("# a\r\n\r\ntext\rmore\r\n"), mdwLines("\xEF\xBB\xBF# Été"), mdwLines("# \xC9t\xE9 \x80")), Array(Array('headline {"level":1} [a]', 'paragrph [text more]'), Array('headline {"level":1} [Été]'), Array('headline {"level":1} [Été €]')));
checkIdentical('no PHP warning along the way', takeNotesArray(), Array());

// Through the index and Dcmnt
checkIdentical('index: Markdown by extension and by content type', Array(get_class(Index::forExtnsParser('md')), get_class(Index::forExtnsParser('MARKDOWN')), get_class(Index::findTypeParser('text/markdown; charset=utf-8')), Index::optionsOfExtns('md')), Array('nyu\Data\Parsers\Mdwn', 'nyu\Data\Parsers\Mdwn', 'nyu\Data\Parsers\Mdwn', Array()));
checkIdentical('a Markdown file through Dcmnt', runCatchThrown(function() use ($longMdw){
	$docPath = inTemporaryDir('shows.md');
	file_put_contents($docPath, $longMdw);
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readFileDetail('shp'), $docFile->readFileDetail('inf'), array_column($docFile->readFileDetail(), 'block'));
}), Array('block', Array('title'=>'Alpha Night', 'front'=>Array()), Array('paragrph', 'headline', 'paragrph', 'headline', 'itemList', 'headline', 'paragrph')));

finishChecking();
