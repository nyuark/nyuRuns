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

/**
 * Registers a temporary HTML page with its values and returns what is sent.
 *
 * @param String $pageString Contents of the page.
 * @param Array  $pageValues Values of the placeholders.
 * @return String The body.
 */
function pageSent( String $pageString, Array $pageValues=Array() ): String {
	$tmpFile = inTempDir('page.html');
	file_put_contents($tmpFile, $pageString);
	$respons = new Response();
	$respons->formatOut( Array('a'=>1) );
	$respons->buildTmpl();
	$respons->pageReply($tmpFile, $pageValues);
	ob_start();
	$respons->sendOutpt();
	return ob_get_clean();
}

// Page replies : sent in place of the data body, as written, their placeholders filled
checkSame('page: sent as written',                 pageSent('<p>{"not":"json"}</p>'), '<p>{"not":"json"}</p>');
checkSame('page: a placeholder is filled',         pageSent('<p>%name%, %name%</p>', Array('name'=>'Ann')), '<p>Ann, Ann</p>');
checkSame('page: a value is encoded',              pageSent('<a title="%t%">%t%</a>', Array('t'=>'<i>"a" & \'b\'</i>')), '<a title="&lt;i&gt;&quot;a&quot; &amp; &#039;b&#039;&lt;/i&gt;">&lt;i&gt;&quot;a&quot; &amp; &#039;b&#039;&lt;/i&gt;</a>');
checkSame('page: a number is written',             pageSent('%n% of %m%', Array('n'=>3, 'm'=>4.5)), '3 of 4.5');
checkSame('page: a placeholder without value is left', pageSent('%name% %other%', Array('name'=>'Ann')), 'Ann %other%');
checkSame('page: a value that is not a text is left', pageSent('%list% %none%', Array('list'=>Array(1), 'none'=>null)), '%list% %none%');
checkSame('page: a filled value is not filled again', pageSent('%a%', Array('a'=>'%b%', 'b'=>'no')), '%b%');
checkSame('page: percent signs are not placeholders', pageSent('width:50%;height:50% %a%', Array('a'=>'x')), 'width:50%;height:50% x');
checkSame('page: text that is not UTF-8 is still sent', pageSent('%a%', Array('a'=>"caf\xE9"))!=='', true);

$respons = new Response();
checkSame('page: a missing file is refused', $respons->pageReply(inTempDir('nosuchpage.html')), false);
checkSame('page: a folder is refused',       $respons->pageReply(TMP_DIR), false);
$tmpFile = inTempDir('page.html');
file_put_contents($tmpFile, '<p>page</p>');
$respons->pageReply($tmpFile);
$respons->clearBody();
ob_start();
$respons->sendOutpt();
checkSame('page: an emptied response sends nothing', ob_get_clean(), '');

/**
 * Registers a temporary file of a fixed date, lets the client keep it and
 * returns what is sent for the request headers given.
 *
 * @param Array $headersArr Request headers.
 * @param Bool  $sharedFile Whether anyone gets the file.
 * @param Array $renderVals Render configuration for the Response.
 * @return Array The status, the body and the response headers.
 */
function keptFile( Array $headersArr=Array(), Bool $sharedFile=false, Array $renderVals=Array() ): Array {
	$tmpFile = inTempDir('kept.txt');
	file_put_contents($tmpFile, 'abcdefghijklmnopqrstuvwxyz');
	touch($tmpFile, 1700000000);
	clearstatcache();
	$respons = new Response($renderVals);
	$respons->fileReply($tmpFile, 'text/plain', strval($headersArr['Range'] ?? ''));
	$respons->cacheFile($headersArr, $sharedFile);
	$codePrp = new ReflectionProperty($respons, 'fileCode');
	$codePrp->setAccessible(true);
	$headPrp = new ReflectionProperty($respons, 'headrArr');
	$headPrp->setAccessible(true);
	ob_start();
	$respons->sendOutpt();
	return Array($codePrp->getValue($respons), ob_get_clean(), $headPrp->getValue($respons));
}

// Kept files : their version and date, and the 304 given to a client that holds them
$allText = 'abcdefghijklmnopqrstuvwxyz';
$fileTag = '"'.dechex(1700000000).'-1a"';
$fileDat = 'Tue, 14 Nov 2023 22:13:20 GMT';
checkSame('cache: version, date and cache headers', keptFile(), Array(200, $allText, Array('Cache-Control'=>'private, no-cache', 'ETag'=>$fileTag, 'Last-Modified'=>$fileDat)));
checkSame('cache: a file served to anyone', keptFile(Array(), true)[2]['Cache-Control'], 'public, no-cache');
checkSame('cache: the cache headers of the preset are replaced', keptFile(Array(), false, Array('headrs'=>Array('cache-control'=>'no-store', 'Pragma'=>'no-cache', 'EXPIRES'=>'0', 'X-Kept'=>'yes')))[2], Array('X-Kept'=>'yes', 'Cache-Control'=>'private, no-cache', 'ETag'=>$fileTag, 'Last-Modified'=>$fileDat));
checkSame('cache: the same version is a 304, without body', array_slice(keptFile(Array('If-None-Match'=>$fileTag)), 0, 2), Array(304, ''));
checkSame('cache: header names in another case', keptFile(Array('if-none-match'=>$fileTag))[0], 304);
checkSame('cache: a version marked as weak', keptFile(Array('If-None-Match'=>'W/'.$fileTag))[0], 304);
checkSame('cache: one of several versions', keptFile(Array('If-None-Match'=>'"aaa", '.$fileTag.' , "bbb"'))[0], 304);
checkSame('cache: any version', keptFile(Array('If-None-Match'=>'*'))[0], 304);
checkSame('cache: another version gets the file', array_slice(keptFile(Array('If-None-Match'=>'"aaa"')), 0, 2), Array(200, $allText));
checkSame('cache: a version without its quotes is another one', keptFile(Array('If-None-Match'=>trim($fileTag, '"')))[0], 200);
checkSame('cache: not modified since its date', keptFile(Array('If-Modified-Since'=>$fileDat))[0], 304);
checkSame('cache: not modified since a later date', keptFile(Array('If-Modified-Since'=>'Wed, 15 Nov 2023 00:00:00 GMT'))[0], 304);
checkSame('cache: modified since an earlier date', keptFile(Array('If-Modified-Since'=>'Tue, 14 Nov 2023 22:13:19 GMT'))[0], 200);
checkSame('cache: a date that cannot be read is ignored', keptFile(Array('If-Modified-Since'=>'not a date'))[0], 200);
checkSame('cache: the version is asked before the date', keptFile(Array('If-None-Match'=>'"aaa"', 'If-Modified-Since'=>$fileDat))[0], 200);
checkSame('cache: a range of the same version', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>$fileTag)), 0, 2), Array(206, 'abcde'));
checkSame('cache: a range since the same date', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>$fileDat)), 0, 2), Array(206, 'abcde'));
checkSame('cache: a range of another version gets the whole file', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>'"aaa"')), 0, 2), Array(200, $allText));
checkSame('cache: a range of a weak version gets the whole file', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>'W/'.$fileTag)), 0, 2), Array(200, $allText));
checkSame('cache: a range without condition is served', array_slice(keptFile(Array('Range'=>'bytes=0-4')), 0, 2), Array(206, 'abcde'));
checkSame('cache: a range of a version the client holds is a 304', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-None-Match'=>$fileTag)), 0, 2), Array(304, ''));
$respons = new Response();
$respons->cacheFile( Array('If-None-Match'=>'*') );
$headPrp = new ReflectionProperty($respons, 'headrArr');
$headPrp->setAccessible(true);
checkSame('cache: nothing is set without a file', $headPrp->getValue($respons), Array());

// Content types of the files a declared route serves
checkSame('type of a stylesheet',            Response::typeOfExt('css'), 'text/css; charset=utf-8');
checkSame('type: the case is ignored',       Response::typeOfExt('PNG'), 'image/png');
checkSame('type: a script has none',         Response::typeOfExt('php'), null);
checkSame('type: no extension has none',     Response::typeOfExt(''), null);

endChecks();
