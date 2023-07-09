<?php

// Data\Shape : blocks, spans, their plain text, tables as grids, and the
// grouping of a document into sections.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Shape;

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

// Spans
checkSame('a plain span', Shape::makeASpan('Hall A'), Array('texts'=>'Hall A', 'marks'=>Array(), 'links'=>''));
checkSame('a span with formatting and a link; unknown marks are left out, the rest put in a fixed order', Shape::makeASpan('120', Array('code', 'huge', 'bold'), '/venues/a'), Array('texts'=>'120', 'marks'=>Array('bold', 'code'), 'links'=>'/venues/a'));

// Blocks with text of their own
checkSame('a paragraph: five keys, its text in plain', Shape::makeBlock('parag', Array(Shape::makeASpan('Playing at '), Shape::makeASpan('Hall A', Array(), '/venues/a'), Shape::makeASpan(', '), Shape::makeASpan('120', Array('bold')), Shape::makeASpan(' seats.'))), Array(
	'block'=>'parag', 'attrs'=>Array(),
	'spans'=>Array(
		Array('texts'=>'Playing at ', 'marks'=>Array(), 'links'=>''), Array('texts'=>'Hall A', 'marks'=>Array(), 'links'=>'/venues/a'), Array('texts'=>', ', 'marks'=>Array(), 'links'=>''),
		Array('texts'=>'120', 'marks'=>Array('bold'), 'links'=>''), Array('texts'=>' seats.', 'marks'=>Array(), 'links'=>'')
	),
	'items'=>Array(), 'plain'=>'Playing at Hall A, 120 seats.'
));
checkSame('a heading keeps its level in attrs', textBlk('headr', 'Alpha Night', Array('level'=>2)), Array('block'=>'headr', 'attrs'=>Array('level'=>2), 'spans'=>Array(Array('texts'=>'Alpha Night', 'marks'=>Array(), 'links'=>'')), 'items'=>Array(), 'plain'=>'Alpha Night'));
checkSame('a block without text', Shape::makeBlock('rules'), Array('block'=>'rules', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''));
checkThrw('an unknown kind is refused', function(){ Shape::makeBlock('title'); }, 'RuntimeException');

// Lists
$subList = Shape::makeBlock('lists', Array(), Array(textBlk('entry', 'Jazz'), textBlk('entry', 'Été')));
$musicEn = Shape::makeBlock('entry', Array(Shape::makeASpan('Music')), Array($subList));
$showLst = Shape::makeBlock('lists', Array(), Array($musicEn, textBlk('entry', 'Dance')), Array('order'=>true));
checkSame('a list describes itself in attrs, and has no plain', Array($showLst['attrs'], $showLst['plain'], $showLst['spans'], count($showLst['items'])), Array(Array('lines'=>2, 'bytes'=>19, 'order'=>true), '', Array(), 2));
checkSame('an entry holding a list keeps its own text in plain', Array($musicEn['plain'], $musicEn['items'][0]['attrs']), Array('Music', Array('lines'=>2, 'bytes'=>9)));
checkSame('the whole text of a list, on request', Shape::plainText($showLst), "Music\nJazz\nÉté\nDance");

// Tables
$headRow = Shape::makeBlock('thead', Array(), Array(textBlk('tcell', 'Title'), textBlk('tcell', 'Venue'), textBlk('tcell', 'Seats')));
$frstRow = Shape::makeBlock('tbrow', Array(), Array(textBlk('tcell', 'Alpha Night'), textBlk('tcell', 'Hall A'), Shape::makeBlock('tcell', Array(Shape::makeASpan('120', Array('bold'))))));
$scndRow = Shape::makeBlock('tbrow', Array(), Array(textBlk('tcell', 'Les Enchaînés'), Shape::makeBlock('tcell'), textBlk('tcell', '80')));
$showTbl = Shape::makeBlock('table', Array(), Array($headRow, $frstRow, $scndRow));
checkSame('a row has its cells in plain, separated by tabs', Array($headRow['plain'], $frstRow['plain'], $scndRow['plain']), Array("Title\tVenue\tSeats", "Alpha Night\tHall A\t120", "Les Enchaînés\t\t80"));
checkSame('a table describes itself in attrs, and has no plain', Array($showTbl['attrs'], $showTbl['plain']), Array(Array('heads'=>Array('Title', 'Venue', 'Seats'), 'colms'=>3, 'lines'=>2, 'bytes'=>52), ''));
checkSame('the bytes of a table count its text only', Shape::textBytes($showTbl['items']), strlen('TitleVenueSeats'.'Alpha NightHall A120'.'Les Enchaînés80'));
checkSame('an empty cell is still a cell', Array(count($scndRow['items']), $scndRow['items'][1]['plain'], $scndRow['items'][1]['spans']), Array(3, '', Array()));
checkSame('a table without header row', Shape::makeBlock('table', Array(), Array($frstRow))['attrs'], Array('heads'=>Array(), 'colms'=>3, 'lines'=>1, 'bytes'=>20));
checkSame('the whole text of a table, on request', Shape::plainText($showTbl), "Title\tVenue\tSeats\nAlpha Night\tHall A\t120\nLes Enchaînés\t\t80");
checkSame('a table as a plain grid', Shape::tableGrid($showTbl), Array(Array('Title', 'Venue', 'Seats'), Array('Alpha Night', 'Hall A', '120'), Array('Les Enchaînés', '', '80')));

// A whole document
$docBlks = Array(
	textBlk('parag', 'Before any heading.'),
	textBlk('headr', 'Alpha Night', Array('level'=>1)),
	textBlk('parag', 'Intro.'),
	textBlk('headr', 'A list', Array('level'=>2)),
	$showLst,
	textBlk('headr', 'Details', Array('level'=>3)),
	textBlk('parag', 'Deep.'),
	textBlk('headr', 'A table', Array('level'=>2, 'tagid'=>'tbl')),
	$showTbl,
	textBlk('headr', 'Beta Evening', Array('level'=>1)),
	textBlk('parag', 'Other show.')
);
checkSame('the whole text of a document', Shape::wholeText(Array($docBlks[1], $docBlks[2], $showLst)), "Alpha Night\n\nIntro.\n\nMusic\nJazz\nÉté\nDance");

/**
 * Reduces a tree of sections to its titles, levels and number of blocks.
 *
 * @param Array $sectnsList The sections.
 * @return Array The same tree, each section as 'title/level/blocks' and its sub-sections.
 */
function sctnMap( Array $sectnsList ): Array {
	$mapList = Array();
	foreach( $sectnsList as $oneSct ){
		$mapList[] = empty($oneSct['nodes']) ? $oneSct['title'].'/'.$oneSct['level'].'/'.count($oneSct['items']) : Array($oneSct['title'].'/'.$oneSct['level'].'/'.count($oneSct['items'])=>sctnMap($oneSct['nodes']));
	}
	return $mapList;
}
checkSame('sections follow the levels of the headings', sctnMap(Shape::bySection($docBlks)), Array(
	'/0/1',
	Array('Alpha Night/1/1'=>Array(Array('A list/2/1'=>Array('Details/3/1')), 'A table/2/1')),
	'Beta Evening/1/1'
));
checkSame('a section keeps the attrs of its heading, and its blocks as they were', runSafely(function() use ($docBlks, $showTbl){
	$tblSect = Shape::bySection($docBlks)[1]['nodes'][1];
	return Array($tblSect['attrs'], $tblSect['items'][0]===$showTbl);
}), Array(Array('level'=>2, 'tagid'=>'tbl'), true));
checkSame('a document starting with a heading has no untitled section', sctnMap(Shape::bySection(array_slice($docBlks, 1, 2))), Array('Alpha Night/1/1'));
checkSame('a document without heading is one untitled section', sctnMap(Shape::bySection(Array($docBlks[0], $docBlks[2]))), Array('/0/2'));
checkSame('a heading deeper than the one before it, with a level skipped', sctnMap(Shape::bySection(Array(textBlk('headr', 'One', Array('level'=>1)), textBlk('headr', 'Three', Array('level'=>3)), textBlk('headr', 'Two', Array('level'=>2))))), Array(Array('One/1/0'=>Array('Three/3/0', 'Two/2/0'))));
checkSame('no block, no section', Shape::bySection(Array()), Array());

// Sheets
checkSame('a sheet: a title and rows of values', Shape::makeSheet('Shows', Array(Array('Title', 'Seats'), Array('a'=>'Alpha Night', 'b'=>120), Array('Les Enchaînés', null))), Array('title'=>'Shows', 'cells'=>Array(Array('Title', 'Seats'), Array('Alpha Night', 120), Array('Les Enchaînés', null))));

endChecks();
