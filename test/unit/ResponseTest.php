<?php

// Output\Response : body formatting, templates and the derived Content-Type.
// Headers themselves are checked over HTTP (see test/http), since PHP does not
// keep them when run from the command line.

require __DIR__.'/../lib/harness.php';

use nyu\Output\Response;

/**
 * Builds a response body and returns what would be sent.
 *
 * @param Array $renderVals Render configuration for the Response.
 * @param Array|Object|String|Int|Float|Bool $dataValues Data to format.
 * @return String The body.
 */
function builtBody( Array $renderVals, Array|Object|String|Int|Float|Bool $dataValues ): String {
	$respons = new Response($renderVals);
	$respons->formatOut($dataValues);
	$respons->buildTmpl();
	ob_start();
	$respons->sendOutpt();
	return ob_get_clean();
}

/**
 * Reads the Content-Type a Response would derive.
 *
 * @param Array $renderVals Render configuration for the Response.
 * @return String The Content-Type value.
 */
function foundType( Array $renderVals ): String {
	$respons = new Response($renderVals);
	$typeFnc = new ReflectionMethod($respons, 'contnType');
	$typeFnc->setAccessible(true);
	return $typeFnc->invoke($respons);
}

checkSame('JSON body',               builtBody(Array(), Array('a'=>1, 'b'=>Array('c'))), '{"a":1,"b":["c"]}');
checkSame('JSON is the default',     builtBody(Array('return'=>'json'), Array()), '[]');
checkSame('format name is case-insensitive', builtBody(Array('return'=>'Json'), Array('a'=>1)), '{"a":1}');
checkSame('other format is serialized', unserialize(builtBody(Array('return'=>'php'), Array('a'=>1))), Array('a'=>1));
checkLike('excel template wraps the data', builtBody(Array('tmplte'=>'excel'), Array('a'=>1)), '/^<html.*\{"a":1\}/s');

// Any encodable value is a body, not only arrays
checkSame('JSON body: a string',     builtBody(Array(), 'plain text'), '"plain text"');
checkSame('JSON body: a number',     builtBody(Array(), 42), '42');
checkSame('JSON body: a boolean',    builtBody(Array(), false), 'false');
checkSame('JSON body: an object',    builtBody(Array(), (object)Array('a'=>1)), '{"a":1}');
checkSame('serialized body: a string', unserialize(builtBody(Array('return'=>'php'), 'plain text')), 'plain text');

// Data that cannot be encoded : an error body, a false result and one warning
$respons = new Response();
checkSame('unencodable data is reported', $respons->formatOut( Array('name'=>"caf\xE9") ), false);
$respons->buildTmpl();
ob_start();
$respons->sendOutpt();
checkSame('unencodable data gives an error body', ob_get_clean(), '{"error":"Response could not be encoded","code":500}');
checkSame('unencodable data raises one warning', count(preg_grep('/Response could not be encoded as JSON: /', takeNotes())), 1);

// An emptied response sends nothing, whatever was prepared before
$respons = new Response();
$respons->formatOut( Array('a'=>1) );
$respons->buildTmpl();
$respons->clearBody();
ob_start();
$respons->sendOutpt();
checkSame('emptied response sends nothing', ob_get_clean(), '');

// An unknown template : the data is still sent, through the plain template, and a warning is raised
checkSame('unknown template falls back to plain', builtBody(Array('tmplte'=>'nosuchtemplate'), Array('a'=>1)), '{"a":1}');
checkSame('unknown template raises one warning', count(preg_grep('/Unknown output template "nosuchtemplate", using "plain"/', takeNotes())), 1);

// The template must be usable twice in one request (error reply after a body was prepared)
$respons = new Response( Array('tmplte'=>'plain', 'return'=>'json') );
$respons->formatOut( Array('first'=>1) );
$respons->buildTmpl();
$respons->formatOut( Array('error'=>'second', 'code'=>500) );
$respons->buildTmpl();
ob_start();
$respons->sendOutpt();
checkSame('body built twice keeps the last one', ob_get_clean(), '{"error":"second","code":500}');

checkSame('type for plain + json',   foundType(Array('tmplte'=>'plain', 'return'=>'json')), 'application/json; charset=utf-8');
checkSame('type for the defaults',   foundType(Array()), 'application/json; charset=utf-8');
checkSame('type for plain + other',  foundType(Array('return'=>'php')), 'text/plain; charset=utf-8');
checkSame('type for excel',          foundType(Array('tmplte'=>'excel')), 'application/vnd.ms-excel');
checkSame('type for mswrd',          foundType(Array('tmplte'=>'mswrd')), 'application/msword');
checkSame('type for optab',          foundType(Array('tmplte'=>'optab')), 'application/vnd.oasis.opendocument.spreadsheet');

/**
 * Registers a temporary file with a Range value and returns what is sent.
 *
 * @param String $rangeValue Range header value.
 * @param String $fileContnt Contents of the file.
 * @return Array The status and the body.
 */
function fileSent( String $rangeValue, String $fileContnt='abcdefghijklmnopqrstuvwxyz' ): Array {
	$tmpFile = inTempDir('range.bin');
	file_put_contents($tmpFile, $fileContnt);
	$respons = new Response();
	$respons->fileReply($tmpFile, 'audio/mp4', $rangeValue);
	$codePrp = new ReflectionProperty($respons, 'fileCode');
	$codePrp->setAccessible(true);
	ob_start();
	$respons->sendOutpt();
	return Array($codePrp->getValue($respons), ob_get_clean());
}

// File replies : the status and the bytes sent for each kind of Range header
checkSame('file: no range sends it all',        fileSent(''), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkSame('file: first bytes',                  fileSent('bytes=0-4'), Array(206, 'abcde'));
checkSame('file: from a byte to the end',       fileSent('bytes=20-'), Array(206, 'uvwxyz'));
checkSame('file: last bytes',                   fileSent('bytes=-3'), Array(206, 'xyz'));
checkSame('file: a suffix longer than the file sends it all as a range', fileSent('bytes=-99'), Array(206, 'abcdefghijklmnopqrstuvwxyz'));
checkSame('file: an end past the file is cut',  fileSent('bytes=24-99'), Array(206, 'yz'));
checkSame('file: one byte',                     fileSent('bytes=5-5'), Array(206, 'f'));
checkSame('file: spaces and case are accepted', fileSent(' Bytes = 1 - 2 '), Array(206, 'bc'));
checkSame('file: a start past the file is 416', fileSent('bytes=26-'), Array(416, ''));
checkSame('file: an empty suffix is 416',       fileSent('bytes=-0'), Array(416, ''));
checkSame('file: a reversed range is ignored',  fileSent('bytes=9-2'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkSame('file: several ranges are ignored',   fileSent('bytes=0-1,4-5'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkSame('file: another unit is ignored',      fileSent('items=0-1'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkSame('file: a range without bounds is ignored', fileSent('bytes=-'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkSame('file: an empty file is sent empty',  fileSent('', ''), Array(200, ''));
checkSame('file: a range of an empty file is 416', fileSent('bytes=0-', ''), Array(416, ''));
$bigText = str_repeat('0123456789', 3000);
checkSame('file: a range across several pieces', fileSent('bytes=5-20004', $bigText), Array(206, substr($bigText, 5, 20000)));

// A file that cannot be read is refused, and the data body is still the one sent
$respons = new Response();
checkSame('file: a missing file is refused', $respons->fileReply(inTempDir('nosuchfile.bin'), 'audio/mp4'), false);
checkSame('file: a folder is refused',       $respons->fileReply(TMP_DIR, 'audio/mp4'), false);
$respons->formatOut( Array('a'=>1) );
$respons->buildTmpl();
ob_start();
$respons->sendOutpt();
checkSame('file: a refused file leaves the data body', ob_get_clean(), '{"a":1}');

endChecks();
