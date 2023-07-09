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
	return runSafely(function() use ($rawContent){
		$prsrObj = new Mdwn();
		return $prsrObj->parseData($rawContent);
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
		$lineLst[] = rtrim($indentText.$oneBlk['block'].' '.(empty($oneBlk['attrs']) ? '' : showValue($oneBlk['attrs']).' ').$spanTxt);
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
checkSame('a text gives blocks, and its first main heading as title', mdwRead("# Alpha *Night*\n\nPlaying at [Hall A](/venues/a).\n"), Array(
	'shape'=>'block',
	'infos'=>Array('title'=>'Alpha Night', 'front'=>Array()),
	'items'=>Array(
		Array('block'=>'headr', 'attrs'=>Array('level'=>1), 'spans'=>Array(Array('texts'=>'Alpha ', 'marks'=>Array(), 'links'=>''), Array('texts'=>'Night', 'marks'=>Array('ital'), 'links'=>'')), 'items'=>Array(), 'plain'=>'Alpha Night'),
		Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'Playing at ', 'marks'=>Array(), 'links'=>''), Array('texts'=>'Hall A', 'marks'=>Array(), 'links'=>'/venues/a'), Array('texts'=>'.', 'marks'=>Array(), 'links'=>'')), 'items'=>Array(), 'plain'=>'Playing at Hall A.')
	)
));
checkSame('an empty text gives no block', Array(mdwRead(''), mdwRead(" \n\n ")['items']), Array(Array('shape'=>'block', 'infos'=>Array('title'=>'', 'front'=>Array()), 'items'=>Array()), Array()));
checkSame('the title is the first heading of level 1, wherever it stands', Array(mdwRead("## Sub\n\ntext\n\n# Main\n\n# Other")['infos']['title'], mdwRead("## Only sub")['infos']['title']), Array('Main', ''));
checkSame('settings at the start of the text are set apart, and read', runSafely(function(){
	$envlope = mdwRead("---\ntitle: Alpha\ntags: [a, b]\n---\n# Show\n");
	return Array($envlope['infos'], blkLines($envlope['items']));
}), Array(Array('title'=>'Show', 'front'=>Array('title'=>'Alpha', 'tags'=>Array('a', 'b'))), Array('headr {"level":1} [Show]')));
checkSame('a rule starting the text is not a block of settings', mdwLines("---\nJust text\n\n---\n"), Array('rules', 'parag [Just text]', 'rules'));

// Headings
checkSame('the six levels, with or without closing marks', mdwLines("# a\n## b ##\n### c\n#### d\n##### e\n###### f #\n####### not one\n#nor this"), Array('headr {"level":1} [a]', 'headr {"level":2} [b]', 'headr {"level":3} [c]', 'headr {"level":4} [d]', 'headr {"level":5} [e]', 'headr {"level":6} [f]', 'parag [####### not one #nor this]'));
checkSame('headings by the line under them', mdwLines("Alpha Night\n===========\n\nTwo lines\nof title\n---\ntext"), Array('headr {"level":1} [Alpha Night]', 'headr {"level":2} [Two lines of title]', 'parag [text]'));
checkSame('an id after the text of a heading', mdwLines("## Dates and *prices* {#dates}\n\nTitle {#top}\n====="), Array('headr {"level":2,"tagid":"dates"} [Dates and ][prices|ital]', 'headr {"level":1,"tagid":"top"} [Title]'));
checkSame('a heading cuts a paragraph, and may be empty', mdwLines("text\n# Title\nmore\n#\n"), Array('parag [text]', 'headr {"level":1} [Title]', 'parag [more]', 'headr {"level":1}'));

// Paragraphs and line breaks
checkSame('the lines of a paragraph are one text, a blank line ends it', mdwLines("one\n   two\nthree\n\n\nfour"), Array('parag [one two three]', 'parag [four]'));
checkSame('two spaces or a backslash at the end of a line ask for a line break', mdwRead("first  \nsecond\\\nthird\nfourth  ")['items'][0]['plain'], "first\nsecond\nthird fourth");

// Formatting
checkSame('bold, italic, both, struck and code', mdwLines('**bold** __bold__ *ital* _ital_ ***both*** ___both___ ~~struck~~ `code`'), Array('parag [bold|bold][ ][bold|bold][ ][ital|ital][ ][ital|ital][ ][both|bold,ital][ ][both|bold,ital][ ][struck|strk][ ][code|code]'));
checkSame('formatting inside formatting, over several words and lines', mdwLines("**one *two* three** *a **b** c* ~~x **y**~~ **over\ntwo lines**"), Array('parag [one |bold][two|bold,ital][ three|bold][ ][a |ital][b|bold,ital][ c|ital][ ][x |strk][y|bold,strk][ ][over two lines|bold]'));
checkSame('a star marks inside a word, an underscore does not', mdwLines('a*b*c snake_case_name a__b__c 2 * 3 * 4 _ no _ ** no ** __init__.py'), Array('parag [a][b|ital][c snake_case_name a__b__c 2 * 3 * 4 _ no _ ** no ** ][init|bold][.py]'));
checkSame('a mark after a backslash, and a mark never closed, are text', mdwLines('\*not\* \_nor\_ \`this\` \# \[x\] **open *and ~~these'), Array('parag [*not* _nor_ `this` # [x] **open *and ~~these]'));
checkSame('code is given as written, and may hold backticks', mdwLines('`*not* _this_ &amp; <b>` and `` a ` b `` and ` `` ` end'), Array('parag [*not* _this_ &amp; <b>|code][ and ][a ` b|code][ and ][``|code][ end]'));
checkSame('named and numbered entities are read as their characters', mdwRead('&eacute;t&eacute; &amp; &lt;b&gt; &euro; &#233; &copy; AT&T')['items'][0]['plain'], 'été & <b> € é © AT&T');
checkSame('HTML is not interpreted: it stays text', mdwLines("<div>block</div>\n\ntext <b>bold</b> <script>alert(1)</script> <br>"), Array('parag [<div>block</div>]', 'parag [text <b>bold</b> <script>alert(1)</script> <br>]'));

// Links
checkSame('a link carries its address over the formatting inside it; its title is left out', mdwLines('[the **site**](https://nyuark.test/a?x=1&y=2 "Home") and **[bold link](/b)** and [paren](/wiki/A_(b)) and [angle](</a b>)'), Array('parag [the |>https://nyuark.test/a?x=1&y=2][site|bold|>https://nyuark.test/a?x=1&y=2][ and ][bold link|bold|>/b][ and ][paren|>/wiki/A_(b)][ and ][angle|>/a b]'));
checkSame('an address between angle brackets is a link', mdwLines('<https://nyuark.test/x_y> <a@nyuark.test> <mailto:b@nyuark.test> <not a link> <b>'), Array('parag [https://nyuark.test/x_y|>https://nyuark.test/x_y][ ][a@nyuark.test|>mailto:a@nyuark.test][ ][mailto:b@nyuark.test|>mailto:b@nyuark.test][ <not a link> <b>]'));
checkSame('a link that would run a script loses its address, and keeps its text', mdwLines("[one](javascript:alert(1)) [two](JaVaScRiPt:alert(1)) [three](data:text/html,x) <javascript:alert(1)> [mail](mailto:a@nyuark.test) [up](#top)"), Array('parag [one two three <javascript:alert(1)> ][mail|>mailto:a@nyuark.test][ ][up|>#top]'));
checkSame('links by reference and footnotes stay as written', mdwLines("[text][ref] and note[^1]\n\n[ref]: https://nyuark.test"), Array('parag [[text][ref] and note[^1]]', 'parag [[ref]: https://nyuark.test]'));

// Images
checkSame('an image stands between the two halves of its paragraph', mdwLines('Before ![The hall](/img/a.png "title") after ![](b.png)'), Array('parag [Before]', 'image {"sourc":"/img/a.png"} [The hall]', 'parag [after]', 'image {"sourc":"b.png"}'));
checkSame('an image inside a link is the image; an unsafe one is left out', mdwLines('[![Logo](logo.png)](https://nyuark.test) ![bad](javascript:alert(1)) end'), Array('image {"sourc":"logo.png"} [Logo]', 'parag [end]'));
checkSame('where only text can stand, an image is its description', mdwLines("# Title ![pic](p.png) here\n\n| ![a](a.png) |\n|---|\n"), Array('headr {"level":1} [Title pic here]', 'table {"heads":["a"],"colms":1,"lines":0,"bytes":1}', '  thead', '    tcell [a]'));

// Lists
checkSame('a list with any of its marks, a numbered one, and a change of mark', mdwLines("- a\n- b\n* c\n+ d\n1. e\n2. f\n3) g"), Array(
	'lists {"lines":2,"bytes":2,"order":false}', '  entry [a]', '  entry [b]',
	'lists {"lines":1,"bytes":1,"order":false}', '  entry [c]',
	'lists {"lines":1,"bytes":1,"order":false}', '  entry [d]',
	'lists {"lines":2,"bytes":2,"order":true}', '  entry [e]', '  entry [f]',
	'lists {"lines":1,"bytes":1,"order":true}', '  entry [g]'
));
checkSame('a list inside an entry, and paragraphs inside an entry', mdwLines("- Music\n  1. Jazz\n  2. **Été**\n\n  Second paragraph\n- Dance\ncarried on\n\n- Loose\n"), Array(
	'lists {"lines":3,"bytes":51,"order":false}',
	'  entry [Music]',
	'    lists {"lines":2,"bytes":9,"order":true}',
	'      entry [Jazz]',
	'      entry [Été|bold]',
	'    parag [Second paragraph]',
	'  entry [Dance carried on]',
	'  entry [Loose]'
));
checkSame('three levels, and code inside an entry', mdwLines("1. one\n   - two\n     - three\n\n   ```\n   code\n   ```\n2. four"), Array(
	'lists {"lines":2,"bytes":19,"order":true}',
	'  entry [one]',
	'    lists {"lines":1,"bytes":8,"order":false}',
	'      entry [two]',
	'        lists {"lines":1,"bytes":5,"order":false}',
	'          entry [three]',
	'    codes [code]',
	'  entry [four]'
));
checkSame('tasks, done or not', mdwLines("- [x] done\n- [ ] todo\n- [X] also done\n- [y] not a task\n- plain"), Array('lists {"lines":5,"bytes":36,"order":false}', '  entry {"check":true} [done]', '  entry {"check":false} [todo]', '  entry {"check":true} [also done]', '  entry [[y] not a task]', '  entry [plain]'));
checkSame('what only looks like a list; a list cuts a paragraph only when it clearly is one', mdwLines("-not a list\n1.5 is a number\n2. nor this, after text\n\ntext\n- cuts\n\ntext\n1. cuts too"), Array('parag [-not a list 1.5 is a number 2. nor this, after text]', 'parag [text]', 'lists {"lines":1,"bytes":4,"order":false}', '  entry [cuts]', 'parag [text]', 'lists {"lines":1,"bytes":8,"order":true}', '  entry [cuts too]'));
checkSame('a list ends where text follows a blank line', mdwLines("- a\n\ntext\n\n- b"), Array('lists {"lines":1,"bytes":1,"order":false}', '  entry [a]', 'parag [text]', 'lists {"lines":1,"bytes":1,"order":false}', '  entry [b]'));

// Quotes
checkSame('a quote: its text, then the blocks inside it; a line carrying on its paragraph', mdwLines("> Quoted **words**\n> second line\ncarried on\n>\n> - in quote\n> > deeper\n\nafter"), Array('quote [Quoted ][words|bold][ second line carried on]', '  lists {"lines":1,"bytes":8,"order":false}', '    entry [in quote]', '  quote [deeper]', 'parag [after]'));
checkSame('a quote starting with a heading has no text of its own', mdwLines(">## Title\n>text"), Array('quote', '  headr {"level":2} [Title]', '  parag [text]'));

// Code
checkSame('code between marks keeps its lines, its spacing and what looks like Markdown, and gives its language', mdwRead("```php\nif( \$a < 2 ){\n    echo \"*x*\";\n\n# not a heading\n}\n```\nafter")['items'], Array(
	Array('block'=>'codes', 'attrs'=>Array('langg'=>'php'), 'spans'=>Array(Array('texts'=>"if( \$a < 2 ){\n    echo \"*x*\";\n\n# not a heading\n}", 'marks'=>Array(), 'links'=>'')), 'items'=>Array(), 'plain'=>"if( \$a < 2 ){\n    echo \"*x*\";\n\n# not a heading\n}"),
	Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'after', 'marks'=>Array(), 'links'=>'')), 'items'=>Array(), 'plain'=>'after')
));
checkSame('code between tildes, longer marks holding shorter ones, and marks never closed', mdwLines("~~~\na\n~~~\n````\n```\nb\n```\n````\n```js\nopen\nto the end"), Array('codes [a]', "codes [```\nb\n```]", "codes {\"langg\":\"js\"} [open\nto the end]"));
checkSame('indented code, its blank lines kept, and an indented line that only carries on a paragraph', mdwLines("    first\n\n      second\ntext\n    still text"), Array("codes [first\n\n  second]", 'parag [text still text]'));
checkSame('a tabulation starting a line indents as four spaces', mdwLines("\tcode\n\n-\titem"), Array('codes [code]', 'lists {"lines":1,"bytes":4,"order":false}', '  entry [item]'));

// Rules
checkSame('rules, and what is not one', mdwLines("***\n\n- - -\n\n_____\n\n** *\n\n--\n\n**\n\n-*-"), Array('rules', 'rules', 'rules', 'rules', 'parag [--]', 'parag [**]', 'parag [-*-]'));

// Tables
$tblMdwn = "| Title | Seats |\n|:------|------:|\n| **Alpha** | 120 |\n| Les Enchaînés \\| x |\n| a | b | c |\nafter the table? no: a row\n\nafter";
checkSame('a table: its header row, its rows brought to the columns of the header', mdwLines($tblMdwn), Array(
	'table {"heads":["Title","Seats"],"colms":2,"lines":4,"bytes":65}',
	'  thead', '    tcell [Title]', '    tcell [Seats]',
	'  tbrow', '    tcell [Alpha|bold]', '    tcell [120]',
	'  tbrow', '    tcell [Les Enchaînés | x]', '    tcell',
	'  tbrow', '    tcell [a]', '    tcell [b]',
	'  tbrow', '    tcell [after the table? no: a row]', '    tcell',
	'parag [after]'
));
checkSame('a table without outer bars, as a plain grid', Shape::tableGrid(mdwRead("a | b\n--- | ---\n1 | 2\n")['items'][0]), Array(Array('a', 'b'), Array('1', '2')));
checkSame('a line of bars without the line under it is text', mdwLines("a | b\nc | d"), Array('parag [a | b c | d]'));
checkSame('the line under must have the columns of the header', mdwLines("a | b\n---\n"), Array('headr {"level":2} [a | b]'));

// With the rest
$longMdw = "Before.\n\n# Alpha Night\n\nIntro.\n\n## Dates\n\n- Monday\n- Friday\n\n# Beta Evening\n\nOther show.";
checkSame('a text grouped into sections', runSafely(function() use ($longMdw){
	$sctnLst = Shape::bySection(mdwRead($longMdw)['items']);
	return Array(count($sctnLst), $sctnLst[0]['title'], $sctnLst[1]['title'], $sctnLst[1]['items'][0]['plain'], $sctnLst[1]['nodes'][0]['title'], $sctnLst[1]['nodes'][0]['items'][0]['block'], $sctnLst[2]['title']);
}), Array(3, '', 'Alpha Night', 'Intro.', 'Dates', 'lists', 'Beta Evening'));
checkSame('a text read then written as HTML', runSafely(function(){
	return (new Html())->writeData((new Mdwn())->parseData("# Alpha {#a}\n\nAt [Hall A](/a?x=1&y=2), **120** seats.  \nDoors at 8.\n\n- one\n- [x] two\n\n| T | S |\n|---|---|\n| a | 1 |\n\n> quoted\n\n```php\necho 1 < 2;\n```\n\n---\n\n![The hall](/img/a.png)\n"));
}), "<h1 id=\"a\">Alpha</h1>\n<p>At <a href=\"/a?x=1&amp;y=2\">Hall A</a>, <strong>120</strong> seats.<br>\nDoors at 8.</p>\n<ul>\n<li>one</li>\n<li>two</li>\n</ul>\n<table>\n<thead>\n<tr><th>T</th><th>S</th></tr>\n</thead>\n<tbody>\n<tr><td>a</td><td>1</td></tr>\n</tbody>\n</table>\n<blockquote>quoted</blockquote>\n<pre><code class=\"language-php\">echo 1 &lt; 2;</code></pre>\n<hr>\n<img src=\"/img/a.png\" alt=\"The hall\">\n");
checkSame('...and a hostile text comes back harmless', runSafely(function(){
	return (new Html())->writeData((new Mdwn())->parseData("<script>alert(1)</script> [x](javascript:alert(2)) ![y](javascript:alert(3)) <img src=x onerror=alert(4)>\n\n# T {#a\" onclick=\"x}\n\n```a\" onclick=\"x\ncode\n```"));
}), "<p>&lt;script&gt;alert(1)&lt;/script&gt; x</p>\n<p>&lt;img src=x onerror=alert(4)&gt;</p>\n<h1>T {#a&quot; onclick=&quot;x}</h1>\n<pre><code>code</code></pre>\n");
checkSame('any line end, a marker at the start, a text saved by an older tool', Array(mdwLines("# a\r\n\r\ntext\rmore\r\n"), mdwLines("\xEF\xBB\xBF# Été"), mdwLines("# \xC9t\xE9 \x80")), Array(Array('headr {"level":1} [a]', 'parag [text more]'), Array('headr {"level":1} [Été]'), Array('headr {"level":1} [Été €]')));
checkSame('no PHP warning along the way', takeNotes(), Array());

// Through the index and Dcmnt
checkSame('index: Markdown by extension and by content type', Array(get_class(Index::forExtnsn('md')), get_class(Index::forExtnsn('MARKDOWN')), get_class(Index::forCnType('text/markdown; charset=utf-8')), Index::extnOptns('md')), Array('nyu\Data\Parsers\Mdwn', 'nyu\Data\Parsers\Mdwn', 'nyu\Data\Parsers\Mdwn', Array()));
checkSame('a Markdown file through Dcmnt', runSafely(function() use ($longMdw){
	$docPath = inTempDir('shows.md');
	file_put_contents($docPath, $longMdw);
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readValue('shp'), $docFile->readValue('inf'), array_column($docFile->readValue(), 'block'));
}), Array('block', Array('title'=>'Alpha Night', 'front'=>Array()), Array('parag', 'headr', 'parag', 'headr', 'lists', 'headr', 'parag')));

endChecks();
