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
checkIdentical('headings by level, with their id; one without text is left out', mdwOut(Array(textBlk('headline', 'Alpha Night', Array('level'=>1, 'tagid'=>'show')), textBlk('headline', 'Dates', Array('level'=>3)), textBlk('headline', 'Deep', Array('level'=>9)), Shape::makeBlockArray('headline', Array(), Array(), Array('level'=>2)), textBlk('headline', 'Bad id', Array('level'=>2, 'tagid'=>'a b')))), "# Alpha Night {#show}\n\n### Dates\n\n###### Deep\n\n## Bad id\n");
checkIdentical('paragraphs, an empty line between two; one without text is left out; a line break is a backslash at the end of a line', mdwOut(Array(textBlk('paragrph', 'One.'), Shape::makeBlockArray('paragrph'), textBlk('paragrph', "Two,\nsecond line."))), "One.\n\nTwo,\\\nsecond line.\n");
checkIdentical('formatting: bold, italic, both, struck, code; underlined has no mark; the spaces at the ends of a span stay outside its marks', mdwOut(Array(Shape::makeBlockArray('paragrph', Array(
	Shape::makeASpanArray('b', Array('boldFace')), Shape::makeASpanArray(' '), Shape::makeASpanArray('i', Array('emphasis')), Shape::makeASpanArray(' '), Shape::makeASpanArray('both', Array('boldFace', 'emphasis')), Shape::makeASpanArray(' '), Shape::makeASpanArray('s', Array('crossOut')), Shape::makeASpanArray(' '), Shape::makeASpanArray('c', Array('codeSpan')), Shape::makeASpanArray(' '), Shape::makeASpanArray('u', Array('undrLine')), Shape::makeASpanArray(' bold with spaces ', Array('boldFace')), Shape::makeASpanArray('end'), Shape::makeASpanArray('  ', Array('boldFace'))
)))), "**b** *i* ***both*** ~~s~~ `c` u **bold with spaces** end\n");
checkIdentical('marks standing against marks: underscores are written where stars would be read as one mark', mdwOut(Array(Shape::makeBlockArray('paragrph', Array(Shape::makeASpanArray('one ', Array('boldFace')), Shape::makeASpanArray('two', Array('boldFace', 'emphasis')), Shape::makeASpanArray(' three', Array('boldFace')), Shape::makeASpanArray('x', Array('boldFace')), Shape::makeASpanArray('y', Array('emphasis')))))), "**one** ***two*** **three**__x__*y*\n");
checkIdentical('code: as it is, between more backticks than it holds', mdwOut(Array(Shape::makeBlockArray('paragrph', Array(Shape::makeASpanArray('*not* _this_', Array('codeSpan')), Shape::makeASpanArray(' '), Shape::makeASpanArray('a ` b', Array('codeSpan')), Shape::makeASpanArray(' '), Shape::makeASpanArray('``', Array('codeSpan')), Shape::makeASpanArray(' '), Shape::makeASpanArray('bold code', Array('boldFace', 'codeSpan')))))), "`*not* _this_` ``a ` b`` ``` `` ``` **`bold code`**\n");
checkIdentical('links: over formatting, with spaces or brackets in their address; one that would run a script is its text; the reference to a note', mdwOut(Array(Shape::makeBlockArray('paragrph', Array(
	Shape::makeASpanArray('See '), Shape::makeASpanArray('the ', Array(), 'https://nyuark.test/a?x=1&y=2'), Shape::makeASpanArray('site', Array('boldFace'), 'https://nyuark.test/a?x=1&y=2'), Shape::makeASpanArray(', '), Shape::makeASpanArray('wiki', Array(), '/wiki/A_(b)'), Shape::makeASpanArray(', '), Shape::makeASpanArray('spaced', Array(), '/a b'), Shape::makeASpanArray(', '), Shape::makeASpanArray('bad', Array(), 'javascript:alert(1)'),
	Shape::makeASpanArray('1', Array(), '#fnote1'), Shape::makeASpanArray('2', Array(), '#enote2')
)))), "See [the](https://nyuark.test/a?x=1&y=2) [**site**](https://nyuark.test/a?x=1&y=2), [wiki](</wiki/A_(b)>), [spaced](</a b>), bad[^1][^e2]\n");
checkIdentical('itemList: marked and numbered, nested, an entry holding paragraphs and code, tasks', mdwOut(Array(Shape::makeBlockArray('itemList', Array(), Array(
	Shape::makeBlockArray('listItem', Array(Shape::makeASpanArray('Music')), Array(Shape::makeBlockArray('itemList', Array(), Array(textBlk('listItem', 'Jazz'), textBlk('listItem', "two\nlines")), Array('order'=>true)), textBlk('paragrph', 'Second paragraph'), textBlk('codeBlck', "a\n  b"))),
	textBlk('listItem', 'done', Array('check'=>true)), textBlk('listItem', 'todo', Array('check'=>false)), Shape::makeBlockArray('listItem', Array(), Array(Shape::makeBlockArray('itemList', Array(), Array(textBlk('listItem', 'only a list')))))
)))), "- Music\n  1. Jazz\n  2. two\\\n     lines\n\n  Second paragraph\n\n  ```\n  a\n    b\n  ```\n- [x] done\n- [ ] todo\n- - only a list\n");
checkIdentical('a numbered list counts from one; what an entry holds stands under its text, however wide its number', strstr(mdwOut(Array(Shape::makeBlockArray('itemList', Array(), array_map(function($entryValue){ return Shape::makeBlockArray('listItem', Array(Shape::makeASpanArray('e'.$entryValue)), Array(textBlk('paragrph', 'more'))); }, range(1, 11)), Array('order'=>true)))), '9. e9'), "9. e9\n\n   more\n10. e10\n\n    more\n11. e11\n\n    more\n");
$headRow = Shape::makeBlockArray('tableTop', Array(), Array(textBlk('cellText', 'Title'), textBlk('cellText', 'Seats')));
$bodyRow = Shape::makeBlockArray('tableRow', Array(), Array(Shape::makeBlockArray('cellText', Array(Shape::makeASpanArray('Alpha', Array('boldFace')), Shape::makeASpanArray(" | x\nline")), Array(textBlk('paragrph', 'more *text*'))), Shape::makeBlockArray('cellText'), textBlk('cellText', 'extra')));
checkIdentical('a table: its header row, a line of dashes, its rows; a bar and a line break inside a cell; rows brought to the same number of cells', mdwOut(Array(Shape::makeBlockArray('tableSet', Array(), Array($headRow, $bodyRow)))), "| Title | Seats |  |\n| --- | --- | --- |\n| **Alpha** \\| x line more \\*text\\* |  | extra |\n");
checkIdentical('a table without header row has an empty one; a second header row is a row; a table without cell is left out', mdwOut(Array(Shape::makeBlockArray('tableSet', Array(), Array(Shape::makeBlockArray('tableRow', Array(), Array(textBlk('cellText', 'a'))), $headRow)), Shape::makeBlockArray('tableSet'))), "|  |  |\n| --- | --- |\n| a |  |\n| Title | Seats |\n");
checkIdentical('a quote: its text, the blocks it holds, who is quoted; one inside another', mdwOut(Array(Shape::makeBlockArray('citation', Array(Shape::makeASpanArray('Quoted '), Shape::makeASpanArray('words', Array('boldFace'))), Array(textBlk('paragrph', 'More.'), Shape::makeBlockArray('itemList', Array(), Array(textBlk('listItem', 'in quote'))), textBlk('citation', 'deeper')), Array('cites'=>'Alice')))), "> Quoted **words**\n>\n> More.\n> - in quote\n>\n> > deeper\n>\n> — Alice\n");
checkIdentical('code: its language, its lines as they are, marks longer than any it holds', mdwOut(Array(textBlk('codeBlck', "if( \$a < 2 ){\n    echo \"*x*\";\n}", Array('langg'=>'php')), textBlk('codeBlck', "```\ninner\n```"), textBlk('codeBlck', 'x', Array('langg'=>'a b')))), "```php\nif( \$a < 2 ){\n    echo \"*x*\";\n}\n```\n\n````\n```\ninner\n```\n````\n\n```\nx\n```\n");
checkIdentical('images, rules, a page break as a rule, notes', mdwOut(Array(textBlk('imageBox', 'The [hall]', Array('sourc'=>'/img/a b.png')), textBlk('imageBox', 'bad', Array('sourc'=>'javascript:alert(1)')), Shape::makeBlockArray('ruleLine'), Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true)), Shape::makeBlockArray('footnote', Array(Shape::makeASpanArray('Doors at 8.')), Array(textBlk('paragrph', 'More.')), Array('tagid'=>'fnote1', 'numbr'=>1)), textBlk('footnote', 'End.', Array('tagid'=>'enote2', 'numbr'=>2)))), "![The \\[hall\\]](</img/a b.png>)\n\n---\n\n---\n\n[^1]: Doors at 8.\n\n    More.\n\n[^e2]: End.\n");

// Markdown: what would be read as something else
checkIdentical('the characters Markdown gives a meaning to are written after a backslash where they are text', mdwOut(Array(textBlk('paragrph', '*not* _nor_ `this` [x] \\ <b> <https://x> &amp; ~~no~~ a_b snake_case 2 * 3 < 4 AT&T a~b'))), "\\*not\\* \\_nor\\_ \\`this\\` \\[x\\] \\\\ \\<b> \\<https://x> \\&amp; \\~~no\\~~ a_b snake_case 2 \\* 3 < 4 AT&T a~b\n");
checkIdentical('the lines that would start something else are text: a heading, a quote, an entry, a number, a rule, a line under a heading', mdwOut(Array(textBlk('paragrph', "# not a heading\n> not a quote\n- not a list\n+ nor this\n1986. A great year\n2) two\n---\n===\n#hashtag\n-5 degrees\n1.5 is a number"))), "\\# not a heading\\\n\\> not a quote\\\n\\- not a list\\\n\\+ nor this\\\n1986\\. A great year\\\n2\\) two\\\n---\\\n===\\\n#hashtag\\\n-5 degrees\\\n1.5 is a number\n");
checkIdentical('a last line of dashes or of equal signs would make a heading of the text above it: it is text', Array(mdwOut(Array(textBlk('paragrph', "Title?\n---"))), mdwOut(Array(textBlk('paragrph', "Title?\n==="))), mdwOut(Array(textBlk('paragrph', '---')))), Array("Title?\\\n\\---\n", "Title?\\\n\\===\n", "\\---\n"));
checkIdentical('a heading ending with hashes keeps them; its line breaks are spaces', mdwOut(Array(textBlk('headline', "C #\nand F ##", Array('level'=>2)))), "## C # and F \\##\n");

// Markdown: the settings at the start
checkIdentical('front: the settings the content was read with, or else its title, its author and its language; none when there is nothing to tell', Array(
	mdwOut(Array(textBlk('paragrph', 'x')), Array('front'=>true), Array('title'=>'Shown', 'front'=>Array('title'=>'Alpha', 'tags'=>Array('a', 'b'), 'draft'=>true))),
	mdwOut(Array(textBlk('paragrph', 'x')), Array('front'=>true), Array('title'=>'Alpha: the night', 'authr'=>'J. J.', 'langg'=>'fr', 'props'=>Array('x'=>1))),
	mdwOut(Array(textBlk('paragrph', 'x')), Array('front'=>true), Array('title'=>'', 'front'=>Array())),
	mdwOut(Array(), Array('front'=>true), Array('title'=>'Only settings')),
	mdwOut(Array(textBlk('paragrph', 'x')), Array(), Array('title'=>'Not asked'))
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
	$evilBlk = Array(textBlk('paragrph', "[x](javascript:alert(1)) ![y](javascript:alert(2)) <script>alert(3)</script>\n# heading? **bold?** `code?`"), textBlk('headline', '*title* [link](/a)', Array('level'=>1)));
	$backBlk = (new MdwnParser())->parseDataArray(mdwOut($evilBlk))['items'];
	return Array(array_column($backBlk, 'block'), array_column($backBlk, 'plain'), array_merge(...array_map(function($blockArray){ return array_filter(array_merge(array_column($blockArray['spans'], 'links'), ...array_column($blockArray['spans'], 'marks'))); }, $backBlk)));
}), Array(Array('paragrph', 'headline'), Array("[x](javascript:alert(1)) ![y](javascript:alert(2)) <script>alert(3)</script>\n# heading? **bold?** `code?`", '*title* [link](/a)'), Array()));

// Text: plain
$showBlk = Array(
	Shape::makeBlockArray('headline', Array(Shape::makeASpanArray('Alpha '), Shape::makeASpanArray('Night', Array('emphasis'))), Array(), Array('level'=>1)),
	Shape::makeBlockArray('paragrph', Array(Shape::makeASpanArray('Playing at '), Shape::makeASpanArray('Hall A', Array(), '/venues/a'), Shape::makeASpanArray(', '), Shape::makeASpanArray('120', Array('boldFace')), Shape::makeASpanArray(' '), Shape::makeASpanArray('seats', Array('undrLine')), Shape::makeASpanArray(', '), Shape::makeASpanArray('not 9', Array('crossOut')), Shape::makeASpanArray(', '), Shape::makeASpanArray('code', Array('codeSpan')), Shape::makeASpanArray('1', Array(), '#fnote1'), Shape::makeASpanArray(".\nSee "), Shape::makeASpanArray('https://nyuark.test', Array(), 'https://nyuark.test'), Shape::makeASpanArray(', '), Shape::makeASpanArray('bad', Array(), 'javascript:x'))),
	Shape::makeBlockArray('itemList', Array(), Array(Shape::makeBlockArray('listItem', Array(Shape::makeASpanArray('Music')), Array(Shape::makeBlockArray('itemList', Array(), Array(textBlk('listItem', 'Jazz'), textBlk('listItem', 'Été', Array('check'=>true))), Array('order'=>true)), textBlk('paragrph', 'Second paragraph'))), textBlk('listItem', 'Dance'))),
	Shape::makeBlockArray('tableSet', Array(), Array(Shape::makeBlockArray('tableTop', Array(), Array(textBlk('cellText', 'Title'), textBlk('cellText', 'Seats'))), Shape::makeBlockArray('tableRow', Array(), Array(Shape::makeBlockArray('cellText', Array(Shape::makeASpanArray("Alpha\nNight", Array('boldFace'))), Array(textBlk('paragrph', 'more'))), Shape::makeBlockArray('cellText'))))),
	Shape::makeBlockArray('citation', Array(Shape::makeASpanArray('Quoted.')), Array(textBlk('paragrph', 'More.')), Array('cites'=>'Alice [x]')),
	textBlk('codeBlck', "if( \$a ){\n    echo 1;\n}", Array('langg'=>'php')), textBlk('imageBox', 'The hall', Array('sourc'=>'/img/a.png')), Shape::makeBlockArray('imageBox', Array(), Array(), Array('sourc'=>'/img/b.png')), Shape::makeBlockArray('ruleLine'), Shape::makeBlockArray('paragrph'),
	textBlk('footnote', 'Doors at 8.', Array('tagid'=>'fnote1', 'numbr'=>1))
);
checkIdentical('plain: the text alone; an address after its link, lists after their marks, a table with tabulations, a quote after >', txtOut($showBlk), "Alpha Night\n\nPlaying at Hall A (/venues/a), 120 seats, not 9, code[1].\nSee https://nyuark.test, bad\n\n- Music\n  1. Jazz\n  2. Été\n\n  Second paragraph\n- Dance\n\nTitle\tSeats\nAlpha Night more\t\n\n> Quoted.\n>\n> More.\n> — Alice (x)\n\nif( \$a ){\n    echo 1;\n}\n\nThe hall\n\n----------\n\n[1] Doors at 8.\n");
checkIdentical('bbcod: the tags of forums; a heading is bold; what has no tag is its text', txtOut($showBlk, 'bbcod'), "[b]Alpha [i]Night[/i][/b]\n\nPlaying at [url=/venues/a]Hall A[/url], [b]120[/b] [u]seats[/u], [s]not 9[/s], code[1].\nSee [url=https://nyuark.test]https://nyuark.test[/url], bad\n\n[list]\n[*]Music\n[list=1]\n[*]Jazz\n[*]Été\n[/list]\n\nSecond paragraph\n[*]Dance\n[/list]\n\nTitle\tSeats\n[b]Alpha Night[/b] more\t\n\n[quote=Alice (x)]Quoted.\n\nMore.[/quote]\n\n[code=php]\nif( \$a ){\n    echo 1;\n}\n[/code]\n\n[img]/img/a.png[/img]\n\n[img]/img/b.png[/img]\n\n----------\n\n[1] Doors at 8.\n");
checkIdentical('chats: the marks of messaging; a heading is bold', txtOut($showBlk, 'chats'), "*Alpha _Night_*\n\nPlaying at Hall A (/venues/a), *120* __seats__, ~not 9~, code[1].\nSee https://nyuark.test, bad\n\n- Music\n  1. Jazz\n  2. Été\n\n  Second paragraph\n- Dance\n\nTitle\tSeats\n*Alpha Night* more\t\n\n> Quoted.\n>\n> More.\n> — Alice (x)\n\nif( \$a ){\n    echo 1;\n}\n\nThe hall\n\n----------\n\n[1] Doors at 8.\n");
checkIdentical('a heading already bold is not made bold twice; the spaces at the ends of a span stay outside its marks; an empty content is nothing', Array(txtOut(Array(Shape::makeBlockArray('headline', Array(Shape::makeASpanArray('Bold', Array('boldFace'))), Array(), Array('level'=>1))), 'chats'), txtOut(Array(Shape::makeBlockArray('paragrph', Array(Shape::makeASpanArray('a'), Shape::makeASpanArray(' bold ', Array('boldFace')), Shape::makeASpanArray('b')))), 'chats'), txtOut(Array()), txtOut(Array(Shape::makeBlockArray('paragrph')), 'bbcod')), Array("*Bold*\n", "a *bold* b\n", '', ''));

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
checkIdentical('index: Markdown and text by extension, with their content type', Array(get_class(Index::forExtnsWriter('MD')), get_class(Index::forExtnsWriter('markdown')), get_class(Index::forExtnsWriter('txt')), Index::contentTypeFor('md'), Index::contentTypeFor('txt'), Index::optionsOfExtns('txt')), Array('nyu\Data\Writers\Mdwn', 'nyu\Data\Writers\Mdwn', 'nyu\Data\Writers\Text', 'text/markdown; charset=utf-8', 'text/plain; charset=utf-8', Array('gives'=>'plain')));
checkIdentical('no PHP warning along the way', takePhpNotices(), Array());

finishChecking();
