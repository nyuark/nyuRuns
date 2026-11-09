<?php

// Data\Parsers\Text : plain text, BBCode and the marks of messaging read
// into text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Shape;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Text;
use nyu\Data\Writers\Html;

/**
 * Parses a text and returns its envelope, or the reason it was refused.
 *
 * @param String $rawContent The text.
 * @param String $readsLabel What the text carries: plain, bbcod or chats.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function txtRead( String $rawContent, String $readsLabel='plain' ): Mixed {
	return runCatchThrown(function() use ($rawContent, $readsLabel){
		$prsrObj = new Text();
		return $prsrObj->parseDataArray($rawContent, Array('reads'=>$readsLabel));
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
 * Parses a text and returns its blocks in the short form.
 *
 * @param String $rawContent The text.
 * @param String $readsLabel What the text carries.
 * @return mixed The lines, or a 'THROWN ...' string.
 */
function txtLines( String $rawContent, String $readsLabel='plain' ): Mixed {
	$envlope = txtRead($rawContent, $readsLabel);
	return is_array($envlope) ? blkLines($envlope['items']) : $envlope;
}

// Plain text
checkIdentical('plain: a blank line between two paragraphs, the envelope', txtRead("Alpha Night\nplays tonight.\n\nDoors at 8.\n"), Array(
	'shape'=>'block',
	'infos'=>Array('reads'=>'plain'),
	'items'=>Array(
		Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>"Alpha Night\nplays tonight.", 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>"Alpha Night\nplays tonight."),
		Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'Doors at 8.', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Doors at 8.')
	)
));
checkIdentical('plain is what a text is read as when nothing is said', runCatchThrown(function(){ return (new Text())->parseDataArray('x')['infos']; }), Array('reads'=>'plain'));
checkIdentical('plain: nothing is interpreted', txtLines("*not bold* [b]nor this[/b] <b>nor that</b> https://nyuark.test\n- not a list"), Array("parag [*not bold* [b]nor this[/b] <b>nor that</b> https://nyuark.test\n- not a list]"));
checkIdentical('plain: any line end, several blank lines, lines of spaces, spaces at the end of lines', txtLines("one  \r\ntwo\r\n\r\n   \r\n\r\n\rthree\r  four\n\n\n"), Array("parag [one\ntwo]", "parag [three\n  four]"));
checkIdentical('an empty text gives no block', Array(txtRead('')['items'], txtRead(" \n\n ")['items'], txtRead('', 'bbcod')['items'], txtRead("\n", 'chats')['items']), array_fill(0, 4, Array()));
checkPregMatch('a reading that is not a known one is refused', txtRead('x', 'markd'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid text option: unknown reading markd/');
checkIdentical('a text saved by an older tool, and one with its marker', Array(txtRead("\xC9t\xE9 \x80")['items'][0]['plain'], txtRead("\xEF\xBB\xBFÉté")['items'][0]['plain']), Array('Été €', 'Été'));

// BBCode: formatting and links
checkIdentical('bbcod: the four kinds of formatting, whatever the case of the tag', txtLines('[b]bold[/b] [I]italic[/i] [u]under[/u] [s]struck[/S] plain', 'bbcod'), Array('parag [bold|bold][ ][italic|ital][ ][under|undr][ ][struck|strk][ plain]'));
checkIdentical('bbcod: formatting inside formatting, and a tag holding one of its name', txtLines('[b]one [i]two [u]three[/u][/i] four[/b] [b]a [b]b[/b] c[/b]', 'bbcod'), Array('parag [one |bold][two |bold,ital][three|bold,ital,undr][ four|bold][ ][a b c|bold]'));
checkIdentical('bbcod: a link by its address, and one that is its own text', txtLines('See [url=https://nyuark.test/a?x=1&y=2]the [b]site[/b][/url] or [url]https://nyuark.test/b[/url] or [url="/venues/a"]here[/url].', 'bbcod'), Array('parag [See ][the |>https://nyuark.test/a?x=1&y=2][site|bold|>https://nyuark.test/a?x=1&y=2][ or ][https://nyuark.test/b|>https://nyuark.test/b][ or ][here|>/venues/a][.]'));
checkIdentical('bbcod: a link that would run a script loses its address, and keeps its text', txtLines('[url=javascript:alert(1)]one[/url] [url]javascript:alert(2)[/url] [url= JaVaScRiPt:x]three[/url] [url=data:text/html,x]four[/url]', 'bbcod'), Array('parag [one javascript:alert(2) three four]'));
checkIdentical('bbcod: a tag that does nothing is left out, and what it surrounds is kept: never closed, never opened, or not known', txtLines('[b]open, [/i] alone, [color=red]red[/color], [b] and [u]fine[/u]', 'bbcod'), Array('parag [open,  alone, red,  and ][fine|undr]'));
checkIdentical('bbcod: unknown tags with values, and closing tags of blocks left alone', txtLines('Hello [size=20][color=#f00]big red[/color][/size] [center]mid[/center] [/b][/quote]end', 'bbcod'), Array('parag [Hello big red mid end]'));
checkIdentical('bbcod: square brackets that are not a tag stay', txtLines('Note [1], box [ ], array[0], [a b] and [b]bold[/b]', 'bbcod'), Array('parag [Note [1], box [ ], array[0], [a b] and ][bold|bold]'));
checkIdentical('bbcod: lines and paragraphs', txtLines("line [b]one[/b]\nline two\n\nnext [i]paragraph[/i]", 'bbcod'), Array("parag [line ][one|bold][\nline two]", 'parag [next ][paragraph|ital]'));
checkIdentical('bbcod: markup of another kind is text', txtRead('<script>alert(1)</script> *x*', 'bbcod')['items'][0]['plain'], '<script>alert(1)</script> *x*');

// BBCode: blocks
checkIdentical('bbcod: code keeps what it holds as written, tags included, and its language', txtRead("Before\n[code=php]\nif( \$a ){\n    echo '[b]x[/b]';\n}\n[/code]\nAfter", 'bbcod')['items'], Array(
	Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'Before', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Before'),
	Array('block'=>'codes', 'attrs'=>Array('langg'=>'php'), 'spans'=>Array(Array('texts'=>"if( \$a ){\n    echo '[b]x[/b]';\n}", 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>"if( \$a ){\n    echo '[b]x[/b]';\n}"),
	Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'After', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'After')
));
checkIdentical('bbcod: code without language, and a language that is not a plain name', txtLines('[code]a  b[/code][code=a b"c]x[/code]', 'bbcod'), Array('codes [a  b]', 'codes [x]'));
checkIdentical('bbcod: a quote, the one it answers, and who is quoted', txtLines("[quote=Alice]First [b]words[/b]\n\nSecond paragraph[quote]older[/quote][/quote]Reply", 'bbcod'), Array('quote {"cites":"Alice"} [First ][words|bold]', '  parag [Second paragraph]', '  quote [older]', 'parag [Reply]'));
checkIdentical('bbcod: a list, numbered or not, holding a list', txtLines("Shows:\n[list]\n[*]Music\n[list=1][*]Jazz[*][b]Été[/b][/list]\n[*]Dance\n[/list]", 'bbcod'), Array(
	'parag [Shows:]',
	'lists {"lines":2,"bytes":19,"order":false}',
	'  entry [Music]',
	'    lists {"lines":2,"bytes":9,"order":true}',
	'      entry [Jazz]',
	'      entry [Été|bold]',
	'  entry [Dance]'
));
checkIdentical('bbcod: a list without entry gives nothing, text before the first entry is one', txtLines('[list][/list][list] [/list][list]lead[*]a[/list]', 'bbcod'), Array('lists {"lines":2,"bytes":5,"order":false}', '  entry [lead]', '  entry [a]'));
checkIdentical('bbcod: an image stands between the two halves of its paragraph, an unsafe one is left out', txtLines('before [img]/img/a.png[/img] after [img]javascript:alert(1)[/img][img] https://nyuark.test/b.png [/img]', 'bbcod'), Array('parag [before]', 'image {"sourc":"/img/a.png"}', 'parag [after]', 'image {"sourc":"https://nyuark.test/b.png"}'));
checkIdentical('bbcod: a block tag opened and never closed is left out, and so is an entry mark outside a list', txtLines('[quote]open [b]bold[/b] [code] [list][*]x', 'bbcod'), Array('parag [open ][bold|bold][  x]'));
checkIdentical('bbcod: code keeps the tags it holds, whatever they are', txtLines('[code][color=red]kept[/color] [/b][/code]', 'bbcod'), Array('codes [[color=red]kept[/color] [/b]]'));

// Messaging: formatting
checkIdentical('chats: bold, italic, underlined and strike', txtLines('*bold* _italic_ __underlined__ ~struck~ plain', 'chats'), Array('parag [bold|bold][ ][italic|ital][ ][underlined|undr][ ][struck|strk][ plain]'));
checkIdentical('chats: a mark holds several words, accents included', txtLines('*two bold words* and _Les Enchaînés_ and __two underlined__ and ~struck out, Été~', 'chats'), Array('parag [two bold words|bold][ and ][Les Enchaînés|ital][ and ][two underlined|undr][ and ][struck out, Été|strk]'));
checkIdentical('chats: marks inside marks', txtLines('*one _two ~three~_ four* *bold _italic __all three__ back_ bold* _*both*_ __*these*__', 'chats'), Array('parag [one |bold][two |bold,ital][three|bold,ital,strk][ four|bold][ ][bold |bold][italic |bold,ital][all three|bold,ital,undr][ back|bold,ital][ bold|bold][ ][both|bold,ital][ ][these|bold,undr]'));
checkIdentical('chats: a mark counts only against a word', txtLines('2 * 3 * 4, snake_case_name, a*b*c, * alone *, ~ no ~, _ no_, __ no __, 5 *', 'chats'), Array('parag [2 * 3 * 4, snake_case_name, a*b*c, * alone *, ~ no ~, _ no_, __ no __, 5 *]'));
checkIdentical('chats: underscores that do not pair up are text', Array(txtLines('___c___ a__b__c _d__', 'chats'), txtLines('__e_ and f', 'chats')), Array(Array('parag [___c___ a__b__c _d__]'), Array('parag [__e_ and f]')));
checkIdentical('chats: punctuation around a mark, and a mark left open', txtLines('(*bold*), "_italic_"! *open and _closed_', 'chats'), Array('parag [(][bold|bold][), "][italic|ital]["! *open and ][closed|ital]'));
checkIdentical('chats: a mark runs over the lines of a paragraph, not over a blank line', txtLines("*one\ntwo* _three\nfour __five\nsix__ seven_\n\n*open\n\nclosed*", 'chats'), Array("parag [one\ntwo|bold][ ][three\nfour |ital][five\nsix|ital,undr][ seven|ital]", 'parag [*open]', 'parag [closed*]'));
checkIdentical('chats: an address is given as written, its marks left alone, and is not a link', txtLines('See https://nyuark.test/a_b_c?x=_y_&z=*w* and _this_', 'chats'), Array('parag [See https://nyuark.test/a_b_c?x=_y_&z=*w* and ][this|ital]'));
checkIdentical('chats: nothing else is interpreted', txtRead("`code` ```block``` <b>x</b> [b]y[/b]\n> not a quote\n```\nnot code\n```", 'chats')['items'], Array(
	Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>"`code` ```block``` <b>x</b> [b]y[/b]\n> not a quote\n```\nnot code\n```", 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>"`code` ```block``` <b>x</b> [b]y[/b]\n> not a quote\n```\nnot code\n```")
));

// Messaging: lists
checkIdentical('chats: paragraphs and lists, in order', txtLines("Hello *all*\nsecond line\n\nShows:\n- first\n- *second*\n* third\n1. one\n2) two\nlast", 'chats'), Array(
	"parag [Hello ][all|bold][\nsecond line]",
	'parag [Shows:]',
	'lists {"lines":3,"bytes":16,"order":false}',
	'  entry [first]',
	'  entry [second|bold]',
	'  entry [third]',
	'lists {"lines":2,"bytes":6,"order":true}',
	'  entry [one]',
	'  entry [two]',
	'parag [last]'
));
checkIdentical('chats: what only looks like a list', txtLines("-not a list\n*bold* start\n1.5 is a number\n1.no space", 'chats'), Array("parag [-not a list\n][bold|bold][ start\n1.5 is a number\n1.no space]"));

// With the rest
checkIdentical('the whole text, and the sections helper on a text without headings', runCatchThrown(function(){
	$blckLst = txtRead("One\n\n- a\n- b", 'chats')['items'];
	return Array(Shape::documentAsText($blckLst), count(Shape::bySectionArray($blckLst)), Shape::bySectionArray($blckLst)[0]['level']);
}), Array("One\n\na\nb", 1, 0));
checkIdentical('a text read then written as HTML is harmless', runCatchThrown(function(){
	return (new Html())->renderDocument((new Text())->parseDataArray("[b]Hi[/b] <script>alert(1)</script> [url=javascript:alert(2)]x[/url]\nnext [url=/a?b=1&c=2]link[/url][quote=Bob]q[/quote]", Array('reads'=>'bbcod')));
}), "<p><strong>Hi</strong> &lt;script&gt;alert(1)&lt;/script&gt; x<br>\nnext <a href=\"/a?b=1&amp;c=2\">link</a></p>\n<blockquote>q\n<cite>Bob</cite>\n</blockquote>\n");
checkIdentical('joining spans: empty ones left out, same neighbours made one', Shape::joinSpansArray(Array(Shape::makeASpanArray('a'), Shape::makeASpanArray(''), Shape::makeASpanArray('b'), Shape::makeASpanArray('c', Array('bold')), Shape::makeASpanArray('d', Array('bold')), Shape::makeASpanArray('e', Array('bold'), '/x'), Shape::makeASpanArray('f'))), Array(Shape::makeASpanArray('ab'), Shape::makeASpanArray('cd', Array('bold')), Shape::makeASpanArray('e', Array('bold'), '/x'), Shape::makeASpanArray('f')));
checkIdentical('no PHP warning along the way', takeNotesArray(), Array());

// Through the index and Dcmnt
checkIdentical('index: text by extension and by content type', Array(get_class(Index::forExtnsParser('txt')), get_class(Index::findTypeParser('text/plain; charset=utf-8')), Index::optionsOfExtns('TXT')), Array('nyu\Data\Parsers\Text', 'nyu\Data\Parsers\Text', Array('reads'=>'plain')));
checkIdentical('a text file through Dcmnt', runCatchThrown(function(){
	$docPath = inTemporaryDir('notes.txt');
	file_put_contents($docPath, "First *line*\n\nSecond");
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readFileDetail('shp'), $docFile->readFileDetail('inf'), array_column($docFile->readFileDetail(), 'plain'));
}), Array('block', Array('reads'=>'plain'), Array('First *line*', 'Second')));

finishChecking();
