<?php

// Data\Writers\Mdwn and Data\Writers\Text : text blocks written as
// Markdown, as plain text, with the tags of forums and with the marks of
// messaging.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Shape;
use nyu\Data\Parsers\Mdwn as MdwnParser;
use nyu\Data\Parsers\Text as TextParser;
use nyu\Data\Writers\Index;
use nyu\Data\Writers\Mdwn;
use nyu\Data\Writers\Text;

/**
 * Builds a block holding one plain piece of text.
 *
 * @param String $blockLabel Kind of the block.
 * @param String $textString Its text.
 * @param Array  $attrsArray Its attributes.
 * @return Array The block.
 */
function textBlk( String $blockLabel, String $textString, Array $attrsArray=Array() ): Array {
	return Shape::makeBlockArray($blockLabel, Array(Shape::makeASpanArray($textString)), Array(), $attrsArray);
}

/**
 * Writes blocks as Markdown, or returns the reason they were refused.
 *
 * @param Array $blocksList The blocks.
 * @param Array $writeOptns Options of the writer.
 * @param Array $infosArray Details of the content.
 * @return String The Markdown, or a 'THROWN ...' string.
 */
function mdwOut( Array $blocksList, Array $writeOptns=Array(), Array $infosArray=Array() ): String {
	return runCatchThrown(function() use ($blocksList, $writeOptns, $infosArray){
		return (new Mdwn())->renderDocument(Array('shape'=>'block', 'infos'=>$infosArray, 'items'=>$blocksList), $writeOptns);
	});
}

/**
 * Writes blocks as text, or returns the reason they were refused.
 *
 * @param Array  $blocksList The blocks.
 * @param String $givesLabel What the text carries: plain, bbcod or chats.
 * @return String The text, or a 'THROWN ...' string.
 */
function txtOut( Array $blocksList, String $givesLabel='plain' ): String {
	return runCatchThrown(function() use ($blocksList, $givesLabel){
		return (new Text())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>$blocksList), Array('gives'=>$givesLabel));
	});
}

// Markdown: blocks
checkIdentical('headings by level, with their id; one without text is left out', mdwOut(Array(textBlk('headr', 'Alpha Night', Array('level'=>1, 'tagid'=>'show')), textBlk('headr', 'Dates', Array('level'=>3)), textBlk('headr', 'Deep', Array('level'=>9)), Shape::makeBlockArray('headr', Array(), Array(), Array('level'=>2)), textBlk('headr', 'Bad id', Array('level'=>2, 'tagid'=>'a b')))), "# Alpha Night {#show}\n\n### Dates\n\n###### Deep\n\n## Bad id\n");
checkIdentical('paragraphs, an empty line between two; one without text is left out; a line break is a backslash at the end of a line', mdwOut(Array(textBlk('parag', 'One.'), Shape::makeBlockArray('parag'), textBlk('parag', "Two,\nsecond line."))), "One.\n\nTwo,\\\nsecond line.\n");
checkIdentical('formatting: bold, italic, both, struck, code; underlined has no mark; the spaces at the ends of a span stay outside its marks', mdwOut(Array(Shape::makeBlockArray('parag', Array(
	Shape::makeASpanArray('b', Array('bold')), Shape::makeASpanArray(' '), Shape::makeASpanArray('i', Array('ital')), Shape::makeASpanArray(' '), Shape::makeASpanArray('both', Array('bold', 'ital')), Shape::makeASpanArray(' '), Shape::makeASpanArray('s', Array('strk')), Shape::makeASpanArray(' '), Shape::makeASpanArray('c', Array('code')), Shape::makeASpanArray(' '), Shape::makeASpanArray('u', Array('undr')), Shape::makeASpanArray(' bold with spaces ', Array('bold')), Shape::makeASpanArray('end'), Shape::makeASpanArray('  ', Array('bold'))
)))), "**b** *i* ***both*** ~~s~~ `c` u **bold with spaces** end\n");
checkIdentical('marks standing against marks: underscores are written where stars would be read as one mark', mdwOut(Array(Shape::makeBlockArray('parag', Array(Shape::makeASpanArray('one ', Array('bold')), Shape::makeASpanArray('two', Array('bold', 'ital')), Shape::makeASpanArray(' three', Array('bold')), Shape::makeASpanArray('x', Array('bold')), Shape::makeASpanArray('y', Array('ital')))))), "**one** ***two*** **three**__x__*y*\n");
checkIdentical('code: as it is, between more backticks than it holds', mdwOut(Array(Shape::makeBlockArray('parag', Array(Shape::makeASpanArray('*not* _this_', Array('code')), Shape::makeASpanArray(' '), Shape::makeASpanArray('a ` b', Array('code')), Shape::makeASpanArray(' '), Shape::makeASpanArray('``', Array('code')), Shape::makeASpanArray(' '), Shape::makeASpanArray('bold code', Array('bold', 'code')))))), "`*not* _this_` ``a ` b`` ``` `` ``` **`bold code`**\n");
checkIdentical('links: over formatting, with spaces or brackets in their address; one that would run a script is its text; the reference to a note', mdwOut(Array(Shape::makeBlockArray('parag', Array(
	Shape::makeASpanArray('See '), Shape::makeASpanArray('the ', Array(), 'https://nyuark.test/a?x=1&y=2'), Shape::makeASpanArray('site', Array('bold'), 'https://nyuark.test/a?x=1&y=2'), Shape::makeASpanArray(', '), Shape::makeASpanArray('wiki', Array(), '/wiki/A_(b)'), Shape::makeASpanArray(', '), Shape::makeASpanArray('spaced', Array(), '/a b'), Shape::makeASpanArray(', '), Shape::makeASpanArray('bad', Array(), 'javascript:alert(1)'),
	Shape::makeASpanArray('1', Array(), '#fnote1'), Shape::makeASpanArray('2', Array(), '#enote2')
)))), "See [the](https://nyuark.test/a?x=1&y=2) [**site**](https://nyuark.test/a?x=1&y=2), [wiki](</wiki/A_(b)>), [spaced](</a b>), bad[^1][^e2]\n");
checkIdentical('lists: marked and numbered, nested, an entry holding paragraphs and code, tasks', mdwOut(Array(Shape::makeBlockArray('lists', Array(), Array(
	Shape::makeBlockArray('entry', Array(Shape::makeASpanArray('Music')), Array(Shape::makeBlockArray('lists', Array(), Array(textBlk('entry', 'Jazz'), textBlk('entry', "two\nlines")), Array('order'=>true)), textBlk('parag', 'Second paragraph'), textBlk('codes', "a\n  b"))),
	textBlk('entry', 'done', Array('check'=>true)), textBlk('entry', 'todo', Array('check'=>false)), Shape::makeBlockArray('entry', Array(), Array(Shape::makeBlockArray('lists', Array(), Array(textBlk('entry', 'only a list')))))
)))), "- Music\n  1. Jazz\n  2. two\\\n     lines\n\n  Second paragraph\n\n  ```\n  a\n    b\n  ```\n- [x] done\n- [ ] todo\n- - only a list\n");
checkIdentical('a numbered list counts from one; what an entry holds stands under its text, however wide its number', strstr(mdwOut(Array(Shape::makeBlockArray('lists', Array(), array_map(function($entryValue){ return Shape::makeBlockArray('entry', Array(Shape::makeASpanArray('e'.$entryValue)), Array(textBlk('parag', 'more'))); }, range(1, 11)), Array('order'=>true)))), '9. e9'), "9. e9\n\n   more\n10. e10\n\n    more\n11. e11\n\n    more\n");
$headRow = Shape::makeBlockArray('thead', Array(), Array(textBlk('tcell', 'Title'), textBlk('tcell', 'Seats')));
$bodyRow = Shape::makeBlockArray('tbrow', Array(), Array(Shape::makeBlockArray('tcell', Array(Shape::makeASpanArray('Alpha', Array('bold')), Shape::makeASpanArray(" | x\nline")), Array(textBlk('parag', 'more *text*'))), Shape::makeBlockArray('tcell'), textBlk('tcell', 'extra')));
checkIdentical('a table: its header row, a line of dashes, its rows; a bar and a line break inside a cell; rows brought to the same number of cells', mdwOut(Array(Shape::makeBlockArray('table', Array(), Array($headRow, $bodyRow)))), "| Title | Seats |  |\n| --- | --- | --- |\n| **Alpha** \\| x line more \\*text\\* |  | extra |\n");
checkIdentical('a table without header row has an empty one; a second header row is a row; a table without cell is left out', mdwOut(Array(Shape::makeBlockArray('table', Array(), Array(Shape::makeBlockArray('tbrow', Array(), Array(textBlk('tcell', 'a'))), $headRow)), Shape::makeBlockArray('table'))), "|  |  |\n| --- | --- |\n| a |  |\n| Title | Seats |\n");
checkIdentical('a quote: its text, the blocks it holds, who is quoted; one inside another', mdwOut(Array(Shape::makeBlockArray('quote', Array(Shape::makeASpanArray('Quoted '), Shape::makeASpanArray('words', Array('bold'))), Array(textBlk('parag', 'More.'), Shape::makeBlockArray('lists', Array(), Array(textBlk('entry', 'in quote'))), textBlk('quote', 'deeper')), Array('cites'=>'Alice')))), "> Quoted **words**\n>\n> More.\n> - in quote\n>\n> > deeper\n>\n> — Alice\n");
checkIdentical('code: its language, its lines as they are, marks longer than any it holds', mdwOut(Array(textBlk('codes', "if( \$a < 2 ){\n    echo \"*x*\";\n}", Array('langg'=>'php')), textBlk('codes', "```\ninner\n```"), textBlk('codes', 'x', Array('langg'=>'a b')))), "```php\nif( \$a < 2 ){\n    echo \"*x*\";\n}\n```\n\n````\n```\ninner\n```\n````\n\n```\nx\n```\n");
checkIdentical('images, rules, a page break as a rule, notes', mdwOut(Array(textBlk('image', 'The [hall]', Array('sourc'=>'/img/a b.png')), textBlk('image', 'bad', Array('sourc'=>'javascript:alert(1)')), Shape::makeBlockArray('rules'), Shape::makeBlockArray('rules', Array(), Array(), Array('break'=>true)), Shape::makeBlockArray('fnote', Array(Shape::makeASpanArray('Doors at 8.')), Array(textBlk('parag', 'More.')), Array('tagid'=>'fnote1', 'numbr'=>1)), textBlk('fnote', 'End.', Array('tagid'=>'enote2', 'numbr'=>2)))), "![The \\[hall\\]](</img/a b.png>)\n\n---\n\n---\n\n[^1]: Doors at 8.\n\n    More.\n\n[^e2]: End.\n");

// Markdown: what would be read as something else
checkIdentical('the characters Markdown gives a meaning to are written after a backslash where they are text', mdwOut(Array(textBlk('parag', '*not* _nor_ `this` [x] \\ <b> <https://x> &amp; ~~no~~ a_b snake_case 2 * 3 < 4 AT&T a~b'))), "\\*not\\* \\_nor\\_ \\`this\\` \\[x\\] \\\\ \\<b> \\<https://x> \\&amp; \\~~no\\~~ a_b snake_case 2 \\* 3 < 4 AT&T a~b\n");
checkIdentical('the lines that would start something else are text: a heading, a quote, an entry, a number, a rule, a line under a heading', mdwOut(Array(textBlk('parag', "# not a heading\n> not a quote\n- not a list\n+ nor this\n1986. A great year\n2) two\n---\n===\n#hashtag\n-5 degrees\n1.5 is a number"))), "\\# not a heading\\\n\\> not a quote\\\n\\- not a list\\\n\\+ nor this\\\n1986\\. A great year\\\n2\\) two\\\n---\\\n===\\\n#hashtag\\\n-5 degrees\\\n1.5 is a number\n");
checkIdentical('a last line of dashes or of equal signs would make a heading of the text above it: it is text', Array(mdwOut(Array(textBlk('parag', "Title?\n---"))), mdwOut(Array(textBlk('parag', "Title?\n==="))), mdwOut(Array(textBlk('parag', '---')))), Array("Title?\\\n\\---\n", "Title?\\\n\\===\n", "\\---\n"));
checkIdentical('a heading ending with hashes keeps them; its line breaks are spaces', mdwOut(Array(textBlk('headr', "C #\nand F ##", Array('level'=>2)))), "## C # and F \\##\n");

// Markdown: the settings at the start
checkIdentical('front: the settings the content was read with, or else its title, its author and its language; none when there is nothing to tell', Array(
	mdwOut(Array(textBlk('parag', 'x')), Array('front'=>true), Array('title'=>'Shown', 'front'=>Array('title'=>'Alpha', 'tags'=>Array('a', 'b'), 'draft'=>true))),
	mdwOut(Array(textBlk('parag', 'x')), Array('front'=>true), Array('title'=>'Alpha: the night', 'authr'=>'J. J.', 'langg'=>'fr', 'props'=>Array('x'=>1))),
	mdwOut(Array(textBlk('parag', 'x')), Array('front'=>true), Array('title'=>'', 'front'=>Array())),
	mdwOut(Array(), Array('front'=>true), Array('title'=>'Only settings')),
	mdwOut(Array(textBlk('parag', 'x')), Array(), Array('title'=>'Not asked'))
), Array("---\ntitle: Alpha\ntags:\n  - a\n  - b\ndraft: true\n---\n\nx\n", "---\ntitle: \"Alpha: the night\"\nauthor: J. J.\nlang: fr\n---\n\nx\n", "x\n", "---\ntitle: Only settings\n---\n", "x\n"));

// Markdown: read back
$fullMdw = "---\ntitle: Alpha\ntags: [a, b]\n---\n# Alpha *Night* {#show}\n\nPlaying at [Hall A](/venues/a \"the hall\"), **120** _seats_, ***both*** and ~~9~~ `co*de*` \\*not\\* a*b*c snake_case_name.  \nAfter a break, 2 * 3 < 4 &amp; AT&T [x] <b>raw</b> <https://nyuark.test/x_y>.\n\n1986\\. A great year.\n\nSetext two\n----------\n\n- Music\n  1. Jazz\n  2. **Été**\n\n  Second paragraph of music\n- Dance\n- [x] done\n- [ ] todo\n\n1. one\n1. two\n\n* * *\n\n> Quoted **words**\n> second line\n>\n> - in quote\n> > deeper\n\n```php\nif( \$a < 2 ){\n    echo \"*x*\";\n}\n```\n\n    indented code\n      more\n\n| Title | Seats |\n|:------|------:|\n| **Alpha** | 120 |\n| Les Enchaînés \\| x |\n\nBefore ![The hall](/img/a.png) after [ref][1] text[^1]\n\n[paren](/wiki/A_(b)) and [angle](</a b>)\n";
checkIdentical('a text read, written, then read again gives the same blocks and the same settings', runCatchThrown(function() use ($fullMdw){
	$prsrObj = new MdwnParser();
	$frstEnv = $prsrObj->parseDataArray($fullMdw);
	$scndEnv = $prsrObj->parseDataArray((new Mdwn())->renderDocument($frstEnv, Array('front'=>true)));
	foreach( $frstEnv['items'] as $blkIdx=>$oneBlk ){
		if( ($scndEnv['items'][$blkIdx] ?? null)!==$oneBlk ){
			return Array('block '.$blkIdx, $oneBlk, $scndEnv['items'][$blkIdx] ?? null);
		}
	}
	return Array(count($frstEnv['items'])==count($scndEnv['items']), $frstEnv['infos']===$scndEnv['infos'], count($frstEnv['items']));
}), Array(true, true, 15));
checkIdentical('what is written once is written the same again', runCatchThrown(function() use ($fullMdw){
	$prsrObj = new MdwnParser();
	$wrtrObj = new Mdwn();
	$frstTxt = $wrtrObj->renderDocument($prsrObj->parseDataArray($fullMdw), Array('front'=>true));
	return $wrtrObj->renderDocument($prsrObj->parseDataArray($frstTxt), Array('front'=>true))===$frstTxt;
}), true);
checkIdentical('hostile text stays text when it is read again', runCatchThrown(function(){
	$evilBlk = Array(textBlk('parag', "[x](javascript:alert(1)) ![y](javascript:alert(2)) <script>alert(3)</script>\n# heading? **bold?** `code?`"), textBlk('headr', '*title* [link](/a)', Array('level'=>1)));
	$backBlk = (new MdwnParser())->parseDataArray(mdwOut($evilBlk))['items'];
	return Array(array_column($backBlk, 'block'), array_column($backBlk, 'plain'), array_merge(...array_map(function($blockArray){ return array_filter(array_merge(array_column($blockArray['spans'], 'links'), ...array_column($blockArray['spans'], 'marks'))); }, $backBlk)));
}), Array(Array('parag', 'headr'), Array("[x](javascript:alert(1)) ![y](javascript:alert(2)) <script>alert(3)</script>\n# heading? **bold?** `code?`", '*title* [link](/a)'), Array()));

// Text: plain
$showBlk = Array(
	Shape::makeBlockArray('headr', Array(Shape::makeASpanArray('Alpha '), Shape::makeASpanArray('Night', Array('ital'))), Array(), Array('level'=>1)),
	Shape::makeBlockArray('parag', Array(Shape::makeASpanArray('Playing at '), Shape::makeASpanArray('Hall A', Array(), '/venues/a'), Shape::makeASpanArray(', '), Shape::makeASpanArray('120', Array('bold')), Shape::makeASpanArray(' '), Shape::makeASpanArray('seats', Array('undr')), Shape::makeASpanArray(', '), Shape::makeASpanArray('not 9', Array('strk')), Shape::makeASpanArray(', '), Shape::makeASpanArray('code', Array('code')), Shape::makeASpanArray('1', Array(), '#fnote1'), Shape::makeASpanArray(".\nSee "), Shape::makeASpanArray('https://nyuark.test', Array(), 'https://nyuark.test'), Shape::makeASpanArray(', '), Shape::makeASpanArray('bad', Array(), 'javascript:x'))),
	Shape::makeBlockArray('lists', Array(), Array(Shape::makeBlockArray('entry', Array(Shape::makeASpanArray('Music')), Array(Shape::makeBlockArray('lists', Array(), Array(textBlk('entry', 'Jazz'), textBlk('entry', 'Été', Array('check'=>true))), Array('order'=>true)), textBlk('parag', 'Second paragraph'))), textBlk('entry', 'Dance'))),
	Shape::makeBlockArray('table', Array(), Array(Shape::makeBlockArray('thead', Array(), Array(textBlk('tcell', 'Title'), textBlk('tcell', 'Seats'))), Shape::makeBlockArray('tbrow', Array(), Array(Shape::makeBlockArray('tcell', Array(Shape::makeASpanArray("Alpha\nNight", Array('bold'))), Array(textBlk('parag', 'more'))), Shape::makeBlockArray('tcell'))))),
	Shape::makeBlockArray('quote', Array(Shape::makeASpanArray('Quoted.')), Array(textBlk('parag', 'More.')), Array('cites'=>'Alice [x]')),
	textBlk('codes', "if( \$a ){\n    echo 1;\n}", Array('langg'=>'php')), textBlk('image', 'The hall', Array('sourc'=>'/img/a.png')), Shape::makeBlockArray('image', Array(), Array(), Array('sourc'=>'/img/b.png')), Shape::makeBlockArray('rules'), Shape::makeBlockArray('parag'),
	textBlk('fnote', 'Doors at 8.', Array('tagid'=>'fnote1', 'numbr'=>1))
);
checkIdentical('plain: the text alone; an address after its link, lists after their marks, a table with tabulations, a quote after >', txtOut($showBlk), "Alpha Night\n\nPlaying at Hall A (/venues/a), 120 seats, not 9, code[1].\nSee https://nyuark.test, bad\n\n- Music\n  1. Jazz\n  2. Été\n\n  Second paragraph\n- Dance\n\nTitle\tSeats\nAlpha Night more\t\n\n> Quoted.\n>\n> More.\n> — Alice (x)\n\nif( \$a ){\n    echo 1;\n}\n\nThe hall\n\n----------\n\n[1] Doors at 8.\n");
checkIdentical('bbcod: the tags of forums; a heading is bold; what has no tag is its text', txtOut($showBlk, 'bbcod'), "[b]Alpha [i]Night[/i][/b]\n\nPlaying at [url=/venues/a]Hall A[/url], [b]120[/b] [u]seats[/u], [s]not 9[/s], code[1].\nSee [url=https://nyuark.test]https://nyuark.test[/url], bad\n\n[list]\n[*]Music\n[list=1]\n[*]Jazz\n[*]Été\n[/list]\n\nSecond paragraph\n[*]Dance\n[/list]\n\nTitle\tSeats\n[b]Alpha Night[/b] more\t\n\n[quote=Alice (x)]Quoted.\n\nMore.[/quote]\n\n[code=php]\nif( \$a ){\n    echo 1;\n}\n[/code]\n\n[img]/img/a.png[/img]\n\n[img]/img/b.png[/img]\n\n----------\n\n[1] Doors at 8.\n");
checkIdentical('chats: the marks of messaging; a heading is bold', txtOut($showBlk, 'chats'), "*Alpha _Night_*\n\nPlaying at Hall A (/venues/a), *120* __seats__, ~not 9~, code[1].\nSee https://nyuark.test, bad\n\n- Music\n  1. Jazz\n  2. Été\n\n  Second paragraph\n- Dance\n\nTitle\tSeats\n*Alpha Night* more\t\n\n> Quoted.\n>\n> More.\n> — Alice (x)\n\nif( \$a ){\n    echo 1;\n}\n\nThe hall\n\n----------\n\n[1] Doors at 8.\n");
checkIdentical('a heading already bold is not made bold twice; the spaces at the ends of a span stay outside its marks; an empty content is nothing', Array(txtOut(Array(Shape::makeBlockArray('headr', Array(Shape::makeASpanArray('Bold', Array('bold'))), Array(), Array('level'=>1))), 'chats'), txtOut(Array(Shape::makeBlockArray('parag', Array(Shape::makeASpanArray('a'), Shape::makeASpanArray(' bold ', Array('bold')), Shape::makeASpanArray('b')))), 'chats'), txtOut(Array()), txtOut(Array(Shape::makeBlockArray('parag')), 'bbcod')), Array("*Bold*\n", "a *bold* b\n", '', ''));

// Text: read back
checkIdentical('bbcod: what is written reads back as the same text, formatting, links and lists', runCatchThrown(function(){
	$srceBlk = (new TextParser())->parseDataArray("[b]Hi[/b] [i]there[/i], [u]under[/u] [s]struck[/s] [url=/a?b=1]link[/url].\n\n[list]\n[*]Music\n[list=1][*]Jazz[*][b]Été[/b][/list]\n[*]Dance\n[/list]\n\n[quote=Bob]Quoted[/quote]\n\n[code=php]\necho 1;\n[/code]\n\n[img]/img/a.png[/img]", Array('reads'=>'bbcod'))['items'];
	return (new TextParser())->parseDataArray(txtOut($srceBlk, 'bbcod'), Array('reads'=>'bbcod'))['items']===$srceBlk;
}), true);
checkIdentical('chats: what is written reads back as the same text, formatting and lists', runCatchThrown(function(){
	$srceBlk = (new TextParser())->parseDataArray("Hello *all*, _italic_ __under__ ~struck~ *bold* _and italic_ *_both_*\nsecond line\n\n- first\n- *second*\n\n1. one\n2. two\n\nlast", Array('reads'=>'chats'))['items'];
	return (new TextParser())->parseDataArray(txtOut($srceBlk, 'chats'), Array('reads'=>'chats'))['items']===$srceBlk;
}), true);
checkIdentical('plain: what is written reads back as the same paragraphs', runCatchThrown(function(){
	$srceBlk = (new TextParser())->parseDataArray("Alpha Night\nplays tonight.\n\nDoors at 8.")['items'];
	return (new TextParser())->parseDataArray(txtOut($srceBlk))['items']===$srceBlk;
}), true);

// What is refused
checkPregMatch('markdown: a content that is not text blocks', runCatchThrown(function(){ return (new Mdwn())->renderDocument(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as Markdown: sheet/');
checkPregMatch('markdown: a block of an unknown kind', mdwOut(Array(Array('block'=>'title', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Unknown kind of block: title/');
checkPregMatch('text: a content that is not text blocks', runCatchThrown(function(){ return (new Text())->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as text: nodes/');
checkPregMatch('text: a writing that is not a known one', txtOut(Array(), 'markd'), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid text option: unknown writing markd/');
checkPregMatch('text: a block of an unknown kind', txtOut(Array(Array('block'=>'title', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Unknown kind of block: title/');

// Through the index
checkIdentical('index: Markdown and text by extension, with their content type', Array(get_class(Index::forExtnsParser('MD')), get_class(Index::forExtnsParser('markdown')), get_class(Index::forExtnsParser('txt')), Index::contentTypeFor('md'), Index::contentTypeFor('txt'), Index::optionsOfExtns('txt')), Array('nyu\Data\Writers\Mdwn', 'nyu\Data\Writers\Mdwn', 'nyu\Data\Writers\Text', 'text/markdown; charset=utf-8', 'text/plain; charset=utf-8', Array('gives'=>'plain')));
checkIdentical('no PHP warning along the way', takeNotesArray(), Array());

finishChecking();
