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
	return Shape::makeBlockArray($blockLabel, Array(Shape::makeASpanArray($textString)), Array(), $attrsArray);
}

// Spans
checkIdentical('a plain span', Shape::makeASpanArray('Hall A'), Array('texts'=>'Hall A', 'marks'=>Array(), 'links'=>'', 'field'=>''));
checkIdentical('a span with formatting and a link; unknown marks are left out, the rest put in a fixed order', Shape::makeASpanArray('120', Array('code', 'huge', 'bold'), '/venues/a'), Array('texts'=>'120', 'marks'=>Array('bold', 'code'), 'links'=>'/venues/a', 'field'=>''));

// Blocks with text of their own
checkIdentical('a paragraph: five keys, its text in plain', Shape::makeBlockArray('parag', Array(Shape::makeASpanArray('Playing at '), Shape::makeASpanArray('Hall A', Array(), '/venues/a'), Shape::makeASpanArray(', '), Shape::makeASpanArray('120', Array('bold')), Shape::makeASpanArray(' seats.'))), Array(
	'block'=>'parag', 'attrs'=>Array(),
	'spans'=>Array(
		Array('texts'=>'Playing at ', 'marks'=>Array(), 'links'=>'', 'field'=>''), Array('texts'=>'Hall A', 'marks'=>Array(), 'links'=>'/venues/a', 'field'=>''), Array('texts'=>', ', 'marks'=>Array(), 'links'=>'', 'field'=>''),
		Array('texts'=>'120', 'marks'=>Array('bold'), 'links'=>'', 'field'=>''), Array('texts'=>' seats.', 'marks'=>Array(), 'links'=>'', 'field'=>'')
	),
	'items'=>Array(), 'plain'=>'Playing at Hall A, 120 seats.'
));
checkIdentical('a heading keeps its level in attrs', textBlk('headr', 'Alpha Night', Array('level'=>2)), Array('block'=>'headr', 'attrs'=>Array('level'=>2), 'spans'=>Array(Array('texts'=>'Alpha Night', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Alpha Night'));
checkIdentical('a block without text', Shape::makeBlockArray('rules'), Array('block'=>'rules', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>''));
checkForThrows('an unknown kind is refused', function(){ Shape::makeBlockArray('title'); }, 'RuntimeException');

// Lists
$subList = Shape::makeBlockArray('lists', Array(), Array(textBlk('entry', 'Jazz'), textBlk('entry', 'Été')));
$musicEn = Shape::makeBlockArray('entry', Array(Shape::makeASpanArray('Music')), Array($subList));
$showLst = Shape::makeBlockArray('lists', Array(), Array($musicEn, textBlk('entry', 'Dance')), Array('order'=>true));
checkIdentical('a list describes itself in attrs, and has no plain', Array($showLst['attrs'], $showLst['plain'], $showLst['spans'], count($showLst['items'])), Array(Array('lines'=>2, 'bytes'=>19, 'order'=>true), '', Array(), 2));
checkIdentical('an entry holding a list keeps its own text in plain', Array($musicEn['plain'], $musicEn['items'][0]['attrs']), Array('Music', Array('lines'=>2, 'bytes'=>9)));
checkIdentical('the whole text of a list, on request', Shape::blockPlainText($showLst), "Music\nJazz\nÉté\nDance");

// Tables
$headRow = Shape::makeBlockArray('thead', Array(), Array(textBlk('tcell', 'Title'), textBlk('tcell', 'Venue'), textBlk('tcell', 'Seats')));
$frstRow = Shape::makeBlockArray('tbrow', Array(), Array(textBlk('tcell', 'Alpha Night'), textBlk('tcell', 'Hall A'), Shape::makeBlockArray('tcell', Array(Shape::makeASpanArray('120', Array('bold'))))));
$scndRow = Shape::makeBlockArray('tbrow', Array(), Array(textBlk('tcell', 'Les Enchaînés'), Shape::makeBlockArray('tcell'), textBlk('tcell', '80')));
$showTbl = Shape::makeBlockArray('table', Array(), Array($headRow, $frstRow, $scndRow));
checkIdentical('a row has its cells in plain, separated by tabs', Array($headRow['plain'], $frstRow['plain'], $scndRow['plain']), Array("Title\tVenue\tSeats", "Alpha Night\tHall A\t120", "Les Enchaînés\t\t80"));
checkIdentical('a table describes itself in attrs, and has no plain', Array($showTbl['attrs'], $showTbl['plain']), Array(Array('heads'=>Array('Title', 'Venue', 'Seats'), 'colms'=>3, 'lines'=>2, 'bytes'=>52), ''));
checkIdentical('the bytes of a table count its text only', Shape::textBytesCount($showTbl['items']), strlen('TitleVenueSeats'.'Alpha NightHall A120'.'Les Enchaînés80'));
checkIdentical('an empty cell is still a cell', Array(count($scndRow['items']), $scndRow['items'][1]['plain'], $scndRow['items'][1]['spans']), Array(3, '', Array()));
checkIdentical('a table without header row', Shape::makeBlockArray('table', Array(), Array($frstRow))['attrs'], Array('heads'=>Array(), 'colms'=>3, 'lines'=>1, 'bytes'=>20));
checkIdentical('the whole text of a table, on request', Shape::blockPlainText($showTbl), "Title\tVenue\tSeats\nAlpha Night\tHall A\t120\nLes Enchaînés\t\t80");
checkIdentical('a table as a plain grid', Shape::tableGridArray($showTbl), Array(Array('Title', 'Venue', 'Seats'), Array('Alpha Night', 'Hall A', '120'), Array('Les Enchaînés', '', '80')));

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
checkIdentical('the whole text of a document', Shape::documentAsText(Array($docBlks[1], $docBlks[2], $showLst)), "Alpha Night\n\nIntro.\n\nMusic\nJazz\nÉté\nDance");

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
checkIdentical('sections follow the levels of the headings', sctnMap(Shape::bySectionArray($docBlks)), Array(
	'/0/1',
	Array('Alpha Night/1/1'=>Array(Array('A list/2/1'=>Array('Details/3/1')), 'A table/2/1')),
	'Beta Evening/1/1'
));
checkIdentical('a section keeps the attrs of its heading, and its blocks as they were', runCatchThrown(function() use ($docBlks, $showTbl){
	$tblSect = Shape::bySectionArray($docBlks)[1]['nodes'][1];
	return Array($tblSect['attrs'], $tblSect['items'][0]===$showTbl);
}), Array(Array('level'=>2, 'tagid'=>'tbl'), true));
checkIdentical('a document starting with a heading has no untitled section', sctnMap(Shape::bySectionArray(array_slice($docBlks, 1, 2))), Array('Alpha Night/1/1'));
checkIdentical('a document without heading is one untitled section', sctnMap(Shape::bySectionArray(Array($docBlks[0], $docBlks[2]))), Array('/0/2'));
checkIdentical('a heading deeper than the one before it, with a level skipped', sctnMap(Shape::bySectionArray(Array(textBlk('headr', 'One', Array('level'=>1)), textBlk('headr', 'Three', Array('level'=>3)), textBlk('headr', 'Two', Array('level'=>2))))), Array(Array('One/1/0'=>Array('Three/3/0', 'Two/2/0'))));
checkIdentical('no block, no section', Shape::bySectionArray(Array()), Array());

// Sheets
checkIdentical('a sheet: a title and rows of values', Shape::makeSheetArray('Shows', Array(Array('Title', 'Seats'), Array('a'=>'Alpha Night', 'b'=>120), Array('Les Enchaînés', null))), Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Title', 'Seats'), Array('Alpha Night', 120), Array('Les Enchaînés', null))));
checkIdentical('a sheet with what is particular to it: what is not told keeps its usual value', Shape::makeSheetArray('Old', Array(), Array('shown'=>false))['attrs'], Array('shown'=>false, 'merge'=>Array()));

finishChecking();
