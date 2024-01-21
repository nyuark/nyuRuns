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
	return Shape::makeBlock($blockLabel, Array(Shape::makeASpan($textString)), Array(), $attrsArray);
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
	return runSafely(function() use ($blocksList, $writeOptns, $infosArray){
		return (new Mdwn())->writeData(Array('shape'=>'block', 'infos'=>$infosArray, 'items'=>$blocksList), $writeOptns);
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
	return runSafely(function() use ($blocksList, $givesLabel){
		return (new Text())->writeData(Array('shape'=>'block', 'infos'=>Array(), 'items'=>$blocksList), Array('gives'=>$givesLabel));
	});
}

// Markdown: blocks
checkSame('headings by level, with their id; one without text is left out', mdwOut(Array(textBlk('headr', 'Alpha Night', Array('level'=>1, 'tagid'=>'show')), textBlk('headr', 'Dates', Array('level'=>3)), textBlk('headr', 'Deep', Array('level'=>9)), Shape::makeBlock('headr', Array(), Array(), Array('level'=>2)), textBlk('headr', 'Bad id', Array('level'=>2, 'tagid'=>'a b')))), "# Alpha Night {#show}\n\n### Dates\n\n###### Deep\n\n## Bad id\n");
checkSame('paragraphs, an empty line between two; one without text is left out; a line break is a backslash at the end of a line', mdwOut(Array(textBlk('parag', 'One.'), Shape::makeBlock('parag'), textBlk('parag', "Two,\nsecond line."))), "One.\n\nTwo,\\\nsecond line.\n");
checkSame('formatting: bold, italic, both, struck, code; underlined has no mark; the spaces at the ends of a span stay outside its marks', mdwOut(Array(Shape::makeBlock('parag', Array(
	Shape::makeASpan('b', Array('bold')), Shape::makeASpan(' '), Shape::makeASpan('i', Array('ital')), Shape::makeASpan(' '), Shape::makeASpan('both', Array('bold', 'ital')), Shape::makeASpan(' '), Shape::makeASpan('s', Array('strk')), Shape::makeASpan(' '), Shape::makeASpan('c', Array('code')), Shape::makeASpan(' '), Shape::makeASpan('u', Array('undr')), Shape::makeASpan(' bold with spaces ', Array('bold')), Shape::makeASpan('end'), Shape::makeASpan('  ', Array('bold'))
)))), "**b** *i* ***both*** ~~s~~ `c` u **bold with spaces** end\n");
checkSame('marks standing against marks: underscores are written where stars would be read as one mark', mdwOut(Array(Shape::makeBlock('parag', Array(Shape::makeASpan('one ', Array('bold')), Shape::makeASpan('two', Array('bold', 'ital')), Shape::makeASpan(' three', Array('bold')), Shape::makeASpan('x', Array('bold')), Shape::makeASpan('y', Array('ital')))))), "**one** ***two*** **three**__x__*y*\n");
checkSame('code: as it is, between more backticks than it holds', mdwOut(Array(Shape::makeBlock('parag', Array(Shape::makeASpan('*not* _this_', Array('code')), Shape::makeASpan(' '), Shape::makeASpan('a ` b', Array('code')), Shape::makeASpan(' '), Shape::makeASpan('``', Array('code')), Shape::makeASpan(' '), Shape::makeASpan('bold code', Array('bold', 'code')))))), "`*not* _this_` ``a ` b`` ``` `` ``` **`bold code`**\n");
checkSame('links: over formatting, with spaces or brackets in their address; one that would run a script is its text; the reference to a note', mdwOut(Array(Shape::makeBlock('parag', Array(
	Shape::makeASpan('See '), Shape::makeASpan('the ', Array(), 'https://nyuark.test/a?x=1&y=2'), Shape::makeASpan('site', Array('bold'), 'https://nyuark.test/a?x=1&y=2'), Shape::makeASpan(', '), Shape::makeASpan('wiki', Array(), '/wiki/A_(b)'), Shape::makeASpan(', '), Shape::makeASpan('spaced', Array(), '/a b'), Shape::makeASpan(', '), Shape::makeASpan('bad', Array(), 'javascript:alert(1)'),
	Shape::makeASpan('1', Array(), '#fnote1'), Shape::makeASpan('2', Array(), '#enote2')
)))), "See [the](https://nyuark.test/a?x=1&y=2) [**site**](https://nyuark.test/a?x=1&y=2), [wiki](</wiki/A_(b)>), [spaced](</a b>), bad[^1][^e2]\n");
checkSame('lists: marked and numbered, nested, an entry holding paragraphs and code, tasks', mdwOut(Array(Shape::makeBlock('lists', Array(), Array(
	Shape::makeBlock('entry', Array(Shape::makeASpan('Music')), Array(Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'Jazz'), textBlk('entry', "two\nlines")), Array('order'=>true)), textBlk('parag', 'Second paragraph'), textBlk('codes', "a\n  b"))),
	textBlk('entry', 'done', Array('check'=>true)), textBlk('entry', 'todo', Array('check'=>false)), Shape::makeBlock('entry', Array(), Array(Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'only a list')))))
)))), "- Music\n  1. Jazz\n  2. two\\\n     lines\n\n  Second paragraph\n\n  ```\n  a\n    b\n  ```\n- [x] done\n- [ ] todo\n- - only a list\n");
checkSame('a numbered list counts from one; what an entry holds stands under its text, however wide its number', strstr(mdwOut(Array(Shape::makeBlock('lists', Array(), array_map(function($entryValue){ return Shape::makeBlock('entry', Array(Shape::makeASpan('e'.$entryValue)), Array(textBlk('parag', 'more'))); }, range(1, 11)), Array('order'=>true)))), '9. e9'), "9. e9\n\n   more\n10. e10\n\n    more\n11. e11\n\n    more\n");
$headRow = Shape::makeBlock('thead', Array(), Array(textBlk('tcell', 'Title'), textBlk('tcell', 'Seats')));
$bodyRow = Shape::makeBlock('tbrow', Array(), Array(Shape::makeBlock('tcell', Array(Shape::makeASpan('Alpha', Array('bold')), Shape::makeASpan(" | x\nline")), Array(textBlk('parag', 'more *text*'))), Shape::makeBlock('tcell'), textBlk('tcell', 'extra')));
checkSame('a table: its header row, a line of dashes, its rows; a bar and a line break inside a cell; rows brought to the same number of cells', mdwOut(Array(Shape::makeBlock('table', Array(), Array($headRow, $bodyRow)))), "| Title | Seats |  |\n| --- | --- | --- |\n| **Alpha** \\| x line more \\*text\\* |  | extra |\n");
checkSame('a table without header row has an empty one; a second header row is a row; a table without cell is left out', mdwOut(Array(Shape::makeBlock('table', Array(), Array(Shape::makeBlock('tbrow', Array(), Array(textBlk('tcell', 'a'))), $headRow)), Shape::makeBlock('table'))), "|  |  |\n| --- | --- |\n| a |  |\n| Title | Seats |\n");
checkSame('a quote: its text, the blocks it holds, who is quoted; one inside another', mdwOut(Array(Shape::makeBlock('quote', Array(Shape::makeASpan('Quoted '), Shape::makeASpan('words', Array('bold'))), Array(textBlk('parag', 'More.'), Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'in quote'))), textBlk('quote', 'deeper')), Array('cites'=>'Alice')))), "> Quoted **words**\n>\n> More.\n> - in quote\n>\n> > deeper\n>\n> — Alice\n");
checkSame('code: its language, its lines as they are, marks longer than any it holds', mdwOut(Array(textBlk('codes', "if( \$a < 2 ){\n    echo \"*x*\";\n}", Array('langg'=>'php')), textBlk('codes', "```\ninner\n```"), textBlk('codes', 'x', Array('langg'=>'a b')))), "```php\nif( \$a < 2 ){\n    echo \"*x*\";\n}\n```\n\n````\n```\ninner\n```\n````\n\n```\nx\n```\n");
checkSame('images, rules, a page break as a rule, notes', mdwOut(Array(textBlk('image', 'The [hall]', Array('sourc'=>'/img/a b.png')), textBlk('image', 'bad', Array('sourc'=>'javascript:alert(1)')), Shape::makeBlock('rules'), Shape::makeBlock('rules', Array(), Array(), Array('break'=>true)), Shape::makeBlock('fnote', Array(Shape::makeASpan('Doors at 8.')), Array(textBlk('parag', 'More.')), Array('tagid'=>'fnote1', 'numbr'=>1)), textBlk('fnote', 'End.', Array('tagid'=>'enote2', 'numbr'=>2)))), "![The \\[hall\\]](</img/a b.png>)\n\n---\n\n---\n\n[^1]: Doors at 8.\n\n    More.\n\n[^e2]: End.\n");

// Markdown: what would be read as something else
checkSame('the characters Markdown gives a meaning to are written after a backslash where they are text', mdwOut(Array(textBlk('parag', '*not* _nor_ `this` [x] \\ <b> <https://x> &amp; ~~no~~ a_b snake_case 2 * 3 < 4 AT&T a~b'))), "\\*not\\* \\_nor\\_ \\`this\\` \\[x\\] \\\\ \\<b> \\<https://x> \\&amp; \\~~no\\~~ a_b snake_case 2 \\* 3 < 4 AT&T a~b\n");
checkSame('the lines that would start something else are text: a heading, a quote, an entry, a number, a rule, a line under a heading', mdwOut(Array(textBlk('parag', "# not a heading\n> not a quote\n- not a list\n+ nor this\n1986. A great year\n2) two\n---\n===\n#hashtag\n-5 degrees\n1.5 is a number"))), "\\# not a heading\\\n\\> not a quote\\\n\\- not a list\\\n\\+ nor this\\\n1986\\. A great year\\\n2\\) two\\\n---\\\n===\\\n#hashtag\\\n-5 degrees\\\n1.5 is a number\n");
checkSame('a last line of dashes or of equal signs would make a heading of the text above it: it is text', Array(mdwOut(Array(textBlk('parag', "Title?\n---"))), mdwOut(Array(textBlk('parag', "Title?\n==="))), mdwOut(Array(textBlk('parag', '---')))), Array("Title?\\\n\\---\n", "Title?\\\n\\===\n", "\\---\n"));
checkSame('a heading ending with hashes keeps them; its line breaks are spaces', mdwOut(Array(textBlk('headr', "C #\nand F ##", Array('level'=>2)))), "## C # and F \\##\n");

// Markdown: the settings at the start
checkSame('front: the settings the content was read with, or else its title, its author and its language; none when there is nothing to tell', Array(
	mdwOut(Array(textBlk('parag', 'x')), Array('front'=>true), Array('title'=>'Shown', 'front'=>Array('title'=>'Alpha', 'tags'=>Array('a', 'b'), 'draft'=>true))),
	mdwOut(Array(textBlk('parag', 'x')), Array('front'=>true), Array('title'=>'Alpha: the night', 'authr'=>'J. J.', 'langg'=>'fr', 'props'=>Array('x'=>1))),
	mdwOut(Array(textBlk('parag', 'x')), Array('front'=>true), Array('title'=>'', 'front'=>Array())),
	mdwOut(Array(), Array('front'=>true), Array('title'=>'Only settings')),
	mdwOut(Array(textBlk('parag', 'x')), Array(), Array('title'=>'Not asked'))
), Array("---\ntitle: Alpha\ntags:\n  - a\n  - b\ndraft: true\n---\n\nx\n", "---\ntitle: \"Alpha: the night\"\nauthor: J. J.\nlang: fr\n---\n\nx\n", "x\n", "---\ntitle: Only settings\n---\n", "x\n"));

// Markdown: read back
$fullMdw = "---\ntitle: Alpha\ntags: [a, b]\n---\n# Alpha *Night* {#show}\n\nPlaying at [Hall A](/venues/a \"the hall\"), **120** _seats_, ***both*** and ~~9~~ `co*de*` \\*not\\* a*b*c snake_case_name.  \nAfter a break, 2 * 3 < 4 &amp; AT&T [x] <b>raw</b> <https://nyuark.test/x_y>.\n\n1986\\. A great year.\n\nSetext two\n----------\n\n- Music\n  1. Jazz\n  2. **Été**\n\n  Second paragraph of music\n- Dance\n- [x] done\n- [ ] todo\n\n1. one\n1. two\n\n* * *\n\n> Quoted **words**\n> second line\n>\n> - in quote\n> > deeper\n\n```php\nif( \$a < 2 ){\n    echo \"*x*\";\n}\n```\n\n    indented code\n      more\n\n| Title | Seats |\n|:------|------:|\n| **Alpha** | 120 |\n| Les Enchaînés \\| x |\n\nBefore ![The hall](/img/a.png) after [ref][1] text[^1]\n\n[paren](/wiki/A_(b)) and [angle](</a b>)\n";
checkSame('a text read, written, then read again gives the same blocks and the same settings', runSafely(function() use ($fullMdw){
	$prsrObj = new MdwnParser();
	$frstEnv = $prsrObj->parseData($fullMdw);
	$scndEnv = $prsrObj->parseData((new Mdwn())->writeData($frstEnv, Array('front'=>true)));
	foreach( $frstEnv['items'] as $blkIdx=>$oneBlk ){
		if( ($scndEnv['items'][$blkIdx] ?? null)!==$oneBlk ){
			return Array('block '.$blkIdx, $oneBlk, $scndEnv['items'][$blkIdx] ?? null);
		}
	}
	return Array(count($frstEnv['items'])==count($scndEnv['items']), $frstEnv['infos']===$scndEnv['infos'], count($frstEnv['items']));
}), Array(true, true, 15));
checkSame('what is written once is written the same again', runSafely(function() use ($fullMdw){
	$prsrObj = new MdwnParser();
	$wrtrObj = new Mdwn();
	$frstTxt = $wrtrObj->writeData($prsrObj->parseData($fullMdw), Array('front'=>true));
	return $wrtrObj->writeData($prsrObj->parseData($frstTxt), Array('front'=>true))===$frstTxt;
}), true);
checkSame('hostile text stays text when it is read again', runSafely(function(){
	$evilBlk = Array(textBlk('parag', "[x](javascript:alert(1)) ![y](javascript:alert(2)) <script>alert(3)</script>\n# heading? **bold?** `code?`"), textBlk('headr', '*title* [link](/a)', Array('level'=>1)));
	$backBlk = (new MdwnParser())->parseData(mdwOut($evilBlk))['items'];
	return Array(array_column($backBlk, 'block'), array_column($backBlk, 'plain'), array_merge(...array_map(function($blockArray){ return array_filter(array_merge(array_column($blockArray['spans'], 'links'), ...array_column($blockArray['spans'], 'marks'))); }, $backBlk)));
}), Array(Array('parag', 'headr'), Array("[x](javascript:alert(1)) ![y](javascript:alert(2)) <script>alert(3)</script>\n# heading? **bold?** `code?`", '*title* [link](/a)'), Array()));

// Text: plain
$showBlk = Array(
	Shape::makeBlock('headr', Array(Shape::makeASpan('Alpha '), Shape::makeASpan('Night', Array('ital'))), Array(), Array('level'=>1)),
	Shape::makeBlock('parag', Array(Shape::makeASpan('Playing at '), Shape::makeASpan('Hall A', Array(), '/venues/a'), Shape::makeASpan(', '), Shape::makeASpan('120', Array('bold')), Shape::makeASpan(' '), Shape::makeASpan('seats', Array('undr')), Shape::makeASpan(', '), Shape::makeASpan('not 9', Array('strk')), Shape::makeASpan(', '), Shape::makeASpan('code', Array('code')), Shape::makeASpan('1', Array(), '#fnote1'), Shape::makeASpan(".\nSee "), Shape::makeASpan('https://nyuark.test', Array(), 'https://nyuark.test'), Shape::makeASpan(', '), Shape::makeASpan('bad', Array(), 'javascript:x'))),
	Shape::makeBlock('lists', Array(), Array(Shape::makeBlock('entry', Array(Shape::makeASpan('Music')), Array(Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'Jazz'), textBlk('entry', 'Été', Array('check'=>true))), Array('order'=>true)), textBlk('parag', 'Second paragraph'))), textBlk('entry', 'Dance'))),
	Shape::makeBlock('table', Array(), Array(Shape::makeBlock('thead', Array(), Array(textBlk('tcell', 'Title'), textBlk('tcell', 'Seats'))), Shape::makeBlock('tbrow', Array(), Array(Shape::makeBlock('tcell', Array(Shape::makeASpan("Alpha\nNight", Array('bold'))), Array(textBlk('parag', 'more'))), Shape::makeBlock('tcell'))))),
	Shape::makeBlock('quote', Array(Shape::makeASpan('Quoted.')), Array(textBlk('parag', 'More.')), Array('cites'=>'Alice [x]')),
	textBlk('codes', "if( \$a ){\n    echo 1;\n}", Array('langg'=>'php')), textBlk('image', 'The hall', Array('sourc'=>'/img/a.png')), Shape::makeBlock('image', Array(), Array(), Array('sourc'=>'/img/b.png')), Shape::makeBlock('rules'), Shape::makeBlock('parag'),
	textBlk('fnote', 'Doors at 8.', Array('tagid'=>'fnote1', 'numbr'=>1))
);
checkSame('plain: the text alone; an address after its link, lists after their marks, a table with tabulations, a quote after >', txtOut($showBlk), "Alpha Night\n\nPlaying at Hall A (/venues/a), 120 seats, not 9, code[1].\nSee https://nyuark.test, bad\n\n- Music\n  1. Jazz\n  2. Été\n\n  Second paragraph\n- Dance\n\nTitle\tSeats\nAlpha Night more\t\n\n> Quoted.\n>\n> More.\n> — Alice (x)\n\nif( \$a ){\n    echo 1;\n}\n\nThe hall\n\n----------\n\n[1] Doors at 8.\n");
checkSame('bbcod: the tags of forums; a heading is bold; what has no tag is its text', txtOut($showBlk, 'bbcod'), "[b]Alpha [i]Night[/i][/b]\n\nPlaying at [url=/venues/a]Hall A[/url], [b]120[/b] [u]seats[/u], [s]not 9[/s], code[1].\nSee [url=https://nyuark.test]https://nyuark.test[/url], bad\n\n[list]\n[*]Music\n[list=1]\n[*]Jazz\n[*]Été\n[/list]\n\nSecond paragraph\n[*]Dance\n[/list]\n\nTitle\tSeats\n[b]Alpha Night[/b] more\t\n\n[quote=Alice (x)]Quoted.\n\nMore.[/quote]\n\n[code=php]\nif( \$a ){\n    echo 1;\n}\n[/code]\n\n[img]/img/a.png[/img]\n\n[img]/img/b.png[/img]\n\n----------\n\n[1] Doors at 8.\n");
checkSame('chats: the marks of messaging; a heading is bold', txtOut($showBlk, 'chats'), "*Alpha _Night_*\n\nPlaying at Hall A (/venues/a), *120* __seats__, ~not 9~, code[1].\nSee https://nyuark.test, bad\n\n- Music\n  1. Jazz\n  2. Été\n\n  Second paragraph\n- Dance\n\nTitle\tSeats\n*Alpha Night* more\t\n\n> Quoted.\n>\n> More.\n> — Alice (x)\n\nif( \$a ){\n    echo 1;\n}\n\nThe hall\n\n----------\n\n[1] Doors at 8.\n");
checkSame('a heading already bold is not made bold twice; the spaces at the ends of a span stay outside its marks; an empty content is nothing', Array(txtOut(Array(Shape::makeBlock('headr', Array(Shape::makeASpan('Bold', Array('bold'))), Array(), Array('level'=>1))), 'chats'), txtOut(Array(Shape::makeBlock('parag', Array(Shape::makeASpan('a'), Shape::makeASpan(' bold ', Array('bold')), Shape::makeASpan('b')))), 'chats'), txtOut(Array()), txtOut(Array(Shape::makeBlock('parag')), 'bbcod')), Array("*Bold*\n", "a *bold* b\n", '', ''));

// Text: read back
checkSame('bbcod: what is written reads back as the same text, formatting, links and lists', runSafely(function(){
	$srceBlk = (new TextParser())->parseData("[b]Hi[/b] [i]there[/i], [u]under[/u] [s]struck[/s] [url=/a?b=1]link[/url].\n\n[list]\n[*]Music\n[list=1][*]Jazz[*][b]Été[/b][/list]\n[*]Dance\n[/list]\n\n[quote=Bob]Quoted[/quote]\n\n[code=php]\necho 1;\n[/code]\n\n[img]/img/a.png[/img]", Array('reads'=>'bbcod'))['items'];
	return (new TextParser())->parseData(txtOut($srceBlk, 'bbcod'), Array('reads'=>'bbcod'))['items']===$srceBlk;
}), true);
checkSame('chats: what is written reads back as the same text, formatting and lists', runSafely(function(){
	$srceBlk = (new TextParser())->parseData("Hello *all*, _italic_ __under__ ~struck~ *bold* _and italic_ *_both_*\nsecond line\n\n- first\n- *second*\n\n1. one\n2. two\n\nlast", Array('reads'=>'chats'))['items'];
	return (new TextParser())->parseData(txtOut($srceBlk, 'chats'), Array('reads'=>'chats'))['items']===$srceBlk;
}), true);
checkSame('plain: what is written reads back as the same paragraphs', runSafely(function(){
	$srceBlk = (new TextParser())->parseData("Alpha Night\nplays tonight.\n\nDoors at 8.")['items'];
	return (new TextParser())->parseData(txtOut($srceBlk))['items']===$srceBlk;
}), true);

// What is refused
checkLike('markdown: a content that is not text blocks', runSafely(function(){ return (new Mdwn())->writeData(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as Markdown: sheet/');
checkLike('markdown: a block of an unknown kind', mdwOut(Array(Array('block'=>'title', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Unknown kind of block: title/');
checkLike('text: a content that is not text blocks', runSafely(function(){ return (new Text())->writeData(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as text: nodes/');
checkLike('text: a writing that is not a known one', txtOut(Array(), 'markd'), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid text option: unknown writing markd/');
checkLike('text: a block of an unknown kind', txtOut(Array(Array('block'=>'title', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Unknown kind of block: title/');

// Through the index
checkSame('index: Markdown and text by extension, with their content type', Array(get_class(Index::forExtnsn('MD')), get_class(Index::forExtnsn('markdown')), get_class(Index::forExtnsn('txt')), Index::cnTypeFor('md'), Index::cnTypeFor('txt'), Index::extnOptns('txt')), Array('nyu\Data\Writers\Mdwn', 'nyu\Data\Writers\Mdwn', 'nyu\Data\Writers\Text', 'text/markdown; charset=utf-8', 'text/plain; charset=utf-8', Array('gives'=>'plain')));
checkSame('no PHP warning along the way', takeNotes(), Array());

endChecks();
