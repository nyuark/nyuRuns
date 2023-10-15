<?php

// Data\Parsers\Tabl : CSV and TSV read into a sheet, the separator told
// or guessed, quoted cells, column names, typed values, and encodings.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Tabl;
use nyu\Data\Writers\Html;

/**
 * Parses a table and returns its envelope, or the reason it was refused.
 *
 * @param String $rawContent The table.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function tblRead( String $rawContent, Array $parseOptns=Array() ): Mixed {
	return runSafely(function() use ($rawContent, $parseOptns){
		$prsrObj = new Tabl();
		return $prsrObj->parseData($rawContent, $parseOptns);
	});
}

/**
 * Parses a table and returns the rows of its sheet.
 *
 * @param String $rawContent The table.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The rows, or a 'THROWN ...' string.
 */
function tblRows( String $rawContent, Array $parseOptns=Array() ): Mixed {
	$envlope = tblRead($rawContent, $parseOptns);
	return is_array($envlope) ? $envlope['items'][0]['cells'] : $envlope;
}

$showCsv = "Title,Venue,Seats\nAlpha Night,Hall A,120\nLes Enchaînés,,80\n";

// The envelope
checkSame('a table gives one sheet, and says what it found', tblRead($showCsv), Array(
	'shape'=>'sheet',
	'infos'=>Array('separ'=>'comma', 'heads'=>Array(), 'colms'=>3, 'lines'=>3),
	'items'=>Array(Array('title'=>'', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Title', 'Venue', 'Seats'), Array('Alpha Night', 'Hall A', '120'), Array('Les Enchaînés', '', '80'))))
));
checkSame('an empty content gives no sheet', Array(tblRead(''), tblRead(" \n \n")['items']), Array(Array('shape'=>'sheet', 'infos'=>Array('separ'=>'comma', 'heads'=>Array(), 'colms'=>0, 'lines'=>0), 'items'=>Array()), Array()));

// Lines
checkSame('the three kinds of line end, and a last line without one', Array(tblRows("a,b\r\nc,d\r\n"), tblRows("a,b\rc,d"), tblRows("a,b\nc,d")), array_fill(0, 3, Array(Array('a', 'b'), Array('c', 'd'))));
checkSame('empty lines are left out, a line of separators is not', tblRows("a,b\n\n\r\n,\nc,d\n\n"), Array(Array('a', 'b'), Array('', ''), Array('c', 'd')));
checkSame('rows keep the number of cells they were written with', Array(tblRows("a,b,c\nd\ne,f,g,h"), tblRead("a,b,c\nd\ne,f,g,h")['infos']['colms']), Array(Array(Array('a', 'b', 'c'), Array('d'), Array('e', 'f', 'g', 'h')), 4));
checkSame('a separator ending a line, or the content, is followed by an empty cell', Array(tblRows("a,b,\nc,d,\n"), tblRows('a,b,')), Array(Array(Array('a', 'b', ''), Array('c', 'd', '')), Array(Array('a', 'b', ''))));
checkSame('the spaces of a cell are kept', tblRows(" a , b\n"), Array(Array(' a ', ' b')));

// Quoted cells
checkSame('a quoted cell holds the separator, a quote written twice, and line ends', tblRows("Title,Note\n\"Alpha, the \"\"first\"\" night\",\"line one\r\nline two\"\nNext,\"\"\n"), Array(Array('Title', 'Note'), Array('Alpha, the "first" night', "line one\nline two"), Array('Next', '')));
checkSame('a quote inside a cell that does not start with one is a quote of the text', tblRows("5\" disk,say \"hi\"\n"), Array(Array('5" disk', 'say "hi"')));
checkSame('a line holding only an empty quoted cell is a row', tblRows("a\n\"\"\nb"), Array(Array('a'), Array(''), Array('b')));
checkLike('a quote left open is refused, with the line it opened on', tblRead("a,b\nc,d\n\"e,f\ng,h\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid table content: quote left open on line 3/');
checkLike('...counting the lines held by the quoted cells before it', tblRead("\"a\nb\nc\",d\n\"e"), '/quote left open on line 4/');

// The separator
$semiCsv = "Titre;Prix\nAlpha Night;12,50\nBeta;8,00\n";
checkSame('the separator is told from the first lines', Array(tblRead($semiCsv)['infos']['separ'], tblRows($semiCsv)[1], tblRead("a\tb, c\n1\t2, 3\n")['infos']['separ'], tblRead("a|b\n1|2\n")['infos']['separ'], tblRead("alone\nwords\n")['infos']['separ']), Array('semic', Array('Alpha Night', '12,50'), 'tabul', 'pipes', 'comma'));
checkSame('what stands between quotes does not count when guessing', tblRead("\"a;b;c;d\",e\n\"f;g\",h\n")['infos']['separ'], 'comma');
checkSame('the separator can be told', Array(tblRows("a;b,c\n", Array('separ'=>'comma')), tblRows("a;b,c\n", Array('separ'=>'SEMIC')), tblRows("a\tb c\n", Array('separ'=>'tabul')), tblRows("a|b\n", Array('separ'=>'pipes'))), Array(Array(Array('a;b', 'c')), Array(Array('a', 'b,c')), Array(Array('a', 'b c')), Array(Array('a', 'b'))));
checkLike('a separator that is not a known one is refused', tblRead('a,b', Array('separ'=>'colon')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid table option: unknown separator colon/');

// Column names
checkSame('heads: the first row names the columns, the others are keyed by them', tblRead($showCsv, Array('heads'=>true)), Array(
	'shape'=>'sheet',
	'infos'=>Array('separ'=>'comma', 'heads'=>Array('Title', 'Venue', 'Seats'), 'colms'=>3, 'lines'=>2),
	'items'=>Array(Array('title'=>'', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Title'=>'Alpha Night', 'Venue'=>'Hall A', 'Seats'=>'120'), Array('Title'=>'Les Enchaînés', 'Venue'=>'', 'Seats'=>'80'))))
));
checkSame('heads: a short row has null for what it lacks, a long one keeps its extra cells by position', tblRows("a,b,c\n1\n1,2,3,4,5\n", Array('heads'=>true)), Array(Array('a'=>'1', 'b'=>null, 'c'=>null), Array('a'=>'1', 'b'=>'2', 'c'=>'3', '4'=>'4', '5'=>'5')));
checkSame('heads: a column without name takes its position, a name met twice gets its position after it', Array(tblRead(" id ,,id,\n1,2,3,4\n", Array('heads'=>true))['infos']['heads'], tblRows(" id ,,id,\n1,2,3,4\n", Array('heads'=>true))), Array(Array('id', '2', 'id_3', '4'), Array(Array('id'=>'1', '2'=>'2', 'id_3'=>'3', '4'=>'4'))));
checkSame('heads: a table made of its names only', tblRead("a,b\n", Array('heads'=>true)), Array('shape'=>'sheet', 'infos'=>Array('separ'=>'comma', 'heads'=>Array('a', 'b'), 'colms'=>2, 'lines'=>0), 'items'=>Array(Array('title'=>'', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array()))));

// Typed values
checkSame('types: numbers, booleans and empty cells as such', tblRows("12,-7,0,12.5,-0.25,1e3,2.5E-1,true,FALSE,,word\n", Array('types'=>true)), Array(Array(12, -7, 0, 12.5, -0.25, 1000.0, 0.25, true, false, null, 'word')));
checkSame('types: what only looks like a number stays text', tblRows("0123,+33100,1 200,12.,.5,1-2,0x1F,1234567890123456789012,12a, yes\n", Array('types'=>true)), Array(Array('0123', '+33100', '1 200', '12.', '.5', '1-2', '0x1F', '1234567890123456789012', '12a', ' yes')));
checkSame('types: a quoted cell stays text, and the spaces around a number do not matter', tblRows("\"12\",\"true\",\"\", 12 ,\ttrue \n", Array('types'=>true, 'separ'=>'comma')), Array(Array('12', 'true', '', 12, true)));
checkSame('types with heads: the names stay text', tblRead("2026,2027\n1,2.5\n", Array('types'=>true, 'heads'=>true)), Array('shape'=>'sheet', 'infos'=>Array('separ'=>'comma', 'heads'=>Array('2026', '2027'), 'colms'=>2, 'lines'=>1), 'items'=>Array(Array('title'=>'', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('2026'=>1, '2027'=>2.5))))));
checkSame('without types, everything is text', tblRows("12,true,\n"), Array(Array('12', 'true', '')));

// Encodings: the result is always UTF-8
checkSame('a UTF-8 table with its marker', tblRows("\xEF\xBB\xBFTitre,Lieu\nÉté,Théâtre\n", Array('heads'=>true)), Array(Array('Titre'=>'Été', 'Lieu'=>'Théâtre')));
checkSame('a table saved by an older tool, read as Windows-1252', tblRows("Titre;Prix\n\xC9t\xE9;12 \x80\n"), Array(Array('Titre', 'Prix'), Array('Été', '12 €')));
checkSame('a UTF-16 table, as a spreadsheet exports its text', tblRows("\xFF\xFE".iconv('UTF-8', 'UTF-16LE', "Titre\tLieu\r\nÉté\tThéâtre\r\n")), Array(Array('Titre', 'Lieu'), Array('Été', 'Théâtre')));
checkSame('no PHP warning along the way', takeNotes(), Array());

// Through the index, Dcmnt and the HTML writer
checkSame('index: tables by extension and by content type', Array(get_class(Index::forExtnsn('csv')), get_class(Index::forExtnsn('TSV')), get_class(Index::forExtnsn('tab')), get_class(Index::forCnType('text/csv; charset=utf-8')), get_class(Index::forCnType('text/tab-separated-values')), Index::extnOptns('csv'), Index::extnOptns('tsv'), Index::typeOptns('text/tab-separated-values')), Array('nyu\Data\Parsers\Tabl', 'nyu\Data\Parsers\Tabl', 'nyu\Data\Parsers\Tabl', 'nyu\Data\Parsers\Tabl', 'nyu\Data\Parsers\Tabl', Array(), Array('separ'=>'tabul'), Array('separ'=>'tabul')));
checkSame('a CSV file through Dcmnt', runSafely(function() use ($showCsv){
	$docPath = inTempDir('shows.csv');
	file_put_contents($docPath, $showCsv);
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readValue('shp'), $docFile->readValue('inf'), $docFile->readValue()[0]['cells'][2]);
}), Array('sheet', Array('separ'=>'comma', 'heads'=>Array(), 'colms'=>3, 'lines'=>3), Array('Les Enchaînés', '', '80')));
checkSame('a TSV file holding commas, through Dcmnt', runSafely(function(){
	$docPath = inTempDir('shows.tsv');
	file_put_contents($docPath, "Alpha, the first\tHall A\n");
	return (new Dcmnt($docPath))->readValue()[0]['cells'];
}), Array(Array('Alpha, the first', 'Hall A')));
checkSame('a table read then written as HTML', runSafely(function(){
	return (new Html())->writeData((new Tabl())->parseData("Title,Seats\n<Alpha>,120\n"), Array('heads'=>true));
}), "<table>\n<thead>\n<tr><th>Title</th><th>Seats</th></tr>\n</thead>\n<tbody>\n<tr><td>&lt;Alpha&gt;</td><td>120</td></tr>\n</tbody>\n</table>\n");

endChecks();
