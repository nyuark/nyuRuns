<?php

// Data\Writers\Tabl : sheets written as CSV and TSV.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Shape;
use nyu\Data\Parsers\Tabl as TablParser;
use nyu\Data\Writers\Index;
use nyu\Data\Writers\Tabl;

/**
 * Builds a sheet whose rows are given as they are, keyed or not.
 *
 * @param String $sheetTitle Name of the sheet.
 * @param Array  $rowsOfGrid Its rows.
 * @return Array The sheet.
 */
function shtOf( String $sheetTitle, Array $rowsOfGrid ): Array {
	$shetArr = Shape::makeSheet($sheetTitle, Array());
	$shetArr['cells'] = $rowsOfGrid;
	return $shetArr;
}

/**
 * Writes sheets as a table, or returns the reason they were refused.
 *
 * @param Array $sheetsList The sheets; rows alone are taken as one sheet.
 * @param Array $writeOptns Options of the writer.
 * @return String The table, or a 'THROWN ...' string.
 */
function tblOut( Array $sheetsList, Array $writeOptns=Array() ): String {
	return runSafely(function() use ($sheetsList, $writeOptns){
		$shetLst = isset($sheetsList[0]['cells']) || empty($sheetsList) ? $sheetsList : Array(shtOf('', $sheetsList));
		return (new Tabl())->writeData(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>$shetLst), $writeOptns);
	});
}

$showRws = Array(Array('Title', 'Venue', 'Seats'), Array('Alpha Night', 'Hall A', 120), Array('Les Enchaînés', null, 80));

// Rows and values
checkSame('rows as lines, cells separated by commas, lines ending as the format asks', tblOut($showRws), "Title,Venue,Seats\r\nAlpha Night,Hall A,120\r\nLes Enchaînés,,80\r\n");
checkSame('values: numbers, booleans, nothing, an empty text, what is not a single value', tblOut(Array(Array(120, -7, 12.5, 3.0, 0.1, 1.0E+25, true, false, null, '', Array('x'), INF))), "120,-7,12.5,3,0.1,1.0e+25,true,false,,,,\r\n");
checkSame('a cell holding the separator, a quote, a line end or a space at one of its ends is quoted', tblOut(Array(Array('a,b', 'say "hi"', "line one\nline two", ' lead', 'trail ', 'in side', 'semi;colon', "tab\there"))), "\"a,b\",\"say \"\"hi\"\"\",\"line one\nline two\",\" lead\",\"trail \",in side,semi;colon,tab\there\r\n");
checkSame('rows keep the number of cells they have; an empty row is an empty line; a content without row, or without sheet, is nothing', Array(tblOut(Array(Array('a', 'b', 'c'), Array('d'), Array(), Array('e', 'f'))), tblOut(Array(shtOf('S', Array()))), tblOut(Array())), Array("a,b,c\r\nd\r\n\r\ne,f\r\n", '', ''));

// Separators
checkSame('separ: the other separators, each quoting what holds it', Array(tblOut(Array(Array('a;b', 'c,d')), Array('separ'=>'semic')), tblOut(Array(Array("a\tb", 'c,d')), Array('separ'=>'TABUL')), tblOut(Array(Array('a|b', 'c')), Array('separ'=>'pipes'))), Array("\"a;b\";c,d\r\n", "\"a\tb\"\tc,d\r\n", "\"a|b\"|c\r\n"));
checkLike('separ: one that is not a known one is refused', tblOut($showRws, Array('separ'=>'colon')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid table option: unknown separator colon/');

// Names of columns
$keydRws = Array(Array('Title'=>'Alpha Night', 'Seats'=>120), Array('Title'=>'Les Enchaînés', 'Seats'=>null, 'Note'=>'late'), Array('Seats'=>80));
checkSame('rows keyed by the names of their columns have those names as first row; every name met makes a column, a row lacking one has nothing in it', tblOut($keydRws), "Title,Seats,Note\r\nAlpha Night,120,\r\nLes Enchaînés,,late\r\n,80,\r\n");
checkSame('heads: the names are left out when told so, the columns stay', tblOut($keydRws, Array('heads'=>false)), "Alpha Night,120,\r\nLes Enchaînés,,late\r\n,80,\r\n");
checkSame('heads: nothing is added to rows that are not keyed', tblOut($showRws, Array('heads'=>true)), "Title,Venue,Seats\r\nAlpha Night,Hall A,120\r\nLes Enchaînés,,80\r\n");
checkSame('names are written as any text is: quoted when they have to be, guarded when they could run', tblOut(Array(Array('a,b'=>1, '=x'=>2, 3=>3))), "\"a,b\",'=x,3\r\n1,2,3\r\n");

// Formulas
$evilRws = Array(Array('=SUM(A1:A9)', '+33100', '-7', '@cmd', "\tx", '=1+1,2', 'a=b', ' =x'), Array(-7, +3, -0.5, '', null, true, 'safe', '\'=already'));
checkSame('guard: a text a spreadsheet would run as a formula is written after an apostrophe; numbers and what does not start with such a mark are left alone', tblOut($evilRws), "'=SUM(A1:A9),'+33100,'-7,'@cmd,'\tx,\"'=1+1,2\",a=b,\" =x\"\r\n-7,3,-0.5,,,true,safe,'=already\r\n");
checkSame('guard: texts are written as they are when told so', tblOut($evilRws, Array('guard'=>false)), "=SUM(A1:A9),+33100,-7,@cmd,\"\tx\",\"=1+1,2\",a=b,\" =x\"\r\n-7,3,-0.5,,,true,safe,'=already\r\n");

// Sheets
$twoShts = Array(shtOf('Shows', Array(Array('a'))), shtOf('2', Array(Array('b'))), shtOf('Notes', Array(Array('c'))));
checkSame('sheet: the first sheet unless told otherwise, one by its name, by its position, a name made of digits first', Array(tblOut($twoShts), tblOut($twoShts, Array('sheet'=>'Notes')), tblOut($twoShts, Array('sheet'=>3)), tblOut($twoShts, Array('sheet'=>'2')), tblOut($twoShts, Array('sheet'=>1))), Array("a\r\n", "c\r\n", "c\r\n", "b\r\n", "a\r\n"));
checkLike('sheet: one that is not in the content is refused', tblOut($twoShts, Array('sheet'=>'Nowhere')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid table option: no sheet Nowhere/');
checkLike('a content that is not sheets is refused', runSafely(function(){ return (new Tabl())->writeData(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as a table: block/');

// Read back
$hardRws = Array(Array('Title', 'Note', 'Seats'), Array('Alpha, the "first" night', "line one\nline two", '120'), Array(' spaced ', '', '12.5'), Array('Les Enchaînés', 'semi;colon | pipe', 'true'), Array('a', 'b'));
checkSame('what is written reads back the same, with any separator', runSafely(function() use ($hardRws){
	$prsrObj = new TablParser();
	$backLst = Array();
	foreach( Array('comma', 'semic', 'tabul', 'pipes') as $sepKey ){
		$backLst[] = ($prsrObj->parseData(tblOut($hardRws, Array('separ'=>$sepKey)), Array('separ'=>$sepKey))['items'][0]['cells']===$hardRws);
	}
	return $backLst;
}), Array(true, true, true, true));
checkSame('typed values read back with their type', runSafely(function(){
	$typdRws = Array(Array(120, -7, 12.5, true, false, null, 'word', 0.1));
	return (new TablParser())->parseData(tblOut($typdRws), Array('types'=>true))['items'][0]['cells']===$typdRws;
}), true);
checkSame('keyed rows read back keyed', runSafely(function(){
	$keydRws = Array(Array('Title'=>'Alpha Night', 'Seats'=>'120'), Array('Title'=>'Les Enchaînés', 'Seats'=>'80'));
	$envlope = (new TablParser())->parseData(tblOut($keydRws), Array('heads'=>true));
	return Array($envlope['items'][0]['cells']===$keydRws, $envlope['infos']['heads']);
}), Array(true, Array('Title', 'Seats')));
checkSame('a file read then written is the same file', runSafely(function(){
	$fileTxt = "Title,Venue,Seats\r\n\"Alpha, the \"\"first\"\"\",Hall A,120\r\nLes Enchaînés,,80\r\n";
	return (new Tabl())->writeData((new TablParser())->parseData($fileTxt))===$fileTxt;
}), true);

// The marker at the start
checkSame('bomrk: the marker is written only when asked for, and never before nothing', Array(tblOut($showRws)===tblOut($showRws, Array('bomrk'=>false)), substr(tblOut($showRws, Array('bomrk'=>true)), 0, 9), tblOut(Array(), Array('bomrk'=>true))), Array(true, "\xEF\xBB\xBFTitle,", ''));
checkSame('a table read with its marker is written without it, unless asked; the reading tells it was there', runSafely(function(){
	$envlope = (new TablParser())->parseData("\xEF\xBB\xBFTitre,Lieu\r\nÉté,Théâtre\r\n");
	$wrtrObj = new Tabl();
	return Array($envlope['infos']['bomrk'], $wrtrObj->writeData($envlope), $wrtrObj->writeData($envlope, Array('bomrk'=>$envlope['infos']['bomrk']))==="\xEF\xBB\xBFTitre,Lieu\r\nÉté,Théâtre\r\n");
}), Array(true, "Titre,Lieu\r\nÉté,Théâtre\r\n", true));

// Through the index
checkSame('index: tables by extension, with their separator and their content type', Array(get_class(Index::forExtnsn('CSV')), get_class(Index::forExtnsn('tsv')), Index::extnOptns('csv'), Index::extnOptns('tsv'), Index::extnOptns('tab'), Index::cnTypeFor('csv'), Index::cnTypeFor('tsv')), Array('nyu\Data\Writers\Tabl', 'nyu\Data\Writers\Tabl', Array(), Array('separ'=>'tabul'), Array('separ'=>'tabul'), 'text/csv; charset=utf-8', 'text/tab-separated-values; charset=utf-8'));
checkSame('no PHP warning along the way', takeNotes(), Array());

endChecks();
