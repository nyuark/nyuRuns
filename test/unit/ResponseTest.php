<?php

// Output\Response : the body in each format, and the derived Content-Type.
// Headers themselves are checked over HTTP (see test/http), since PHP does not
// keep them when run from the command line.

require __DIR__.'/../lib/harness.php';

use nyu\Output\Response;
use nyu\Data\Fluxd;

/**
 * Builds a response body and returns what would be sent.
 *
 * @param Array $renderVals Render configuration for the Response.
 * @param Array|Object|String|Int|Float|Bool $dataValues Data to format.
 * @param Bool  $errorReply Whether the data is the body of an error.
 * @return String The body.
 */
function builtBody( Array $renderVals, Array|Object|String|Int|Float|Bool $dataValues, Bool $errorReply=false ): String {
	$respons = new Response($renderVals);
	$respons->formatOut($dataValues, $errorReply);
	ob_start();
	$respons->sendOutpt();
	return ob_get_clean();
}

/**
 * Reads the Content-Type a Response would derive once its body is written.
 *
 * @param Array $renderVals Render configuration for the Response.
 * @param Bool  $errorReply Whether the body is the one of an error.
 * @return String The Content-Type value.
 */
function foundType( Array $renderVals, Bool $errorReply=false ): String {
	$respons = new Response($renderVals);
	$respons->formatOut( Array(Array('a'=>1)), $errorReply );
	$typeFnc = new ReflectionMethod($respons, 'contnType');
	$typeFnc->setAccessible(true);
	return $typeFnc->invoke($respons);
}

$rowList = Array( Array('id'=>1, 'name'=>'Ann', 'admin'=>true), Array('id'=>2, 'name'=>'Bo, "B"', 'mail'=>null) );

// JSON, the default
checkSame('JSON body',               builtBody(Array(), Array('a'=>1, 'b'=>Array('c'))), '{"a":1,"b":["c"]}');
checkSame('JSON is the default',     builtBody(Array('format'=>'json'), Array()), '[]');
checkSame('format name is case-insensitive', builtBody(Array('format'=>'Json'), Array('a'=>1)), '{"a":1}');
checkSame('JSON body: a string',     builtBody(Array(), 'plain text'), '"plain text"');
checkSame('JSON body: a number',     builtBody(Array(), 42), '42');
checkSame('JSON body: a boolean',    builtBody(Array(), false), 'false');
checkSame('JSON body: an object',    builtBody(Array(), (object)Array('a'=>1)), '{"a":1}');

// YAML, XML
checkSame('YAML body', builtBody(Array('format'=>'yaml'), Array('a'=>1, 'b'=>Array('c', 'd'))), "a: 1\nb:\n  - c\n  - d\n");
checkLike('XML body', builtBody(Array('format'=>'xmlx'), Array('a'=>1, 'b'=>'x & y')), '/^<\?xml version="1\.0" encoding="UTF-8"\?>.*<a>1<\/a>.*<b>x &amp; y<\/b>/s');

// A table : a list of records, its columns in the order they first come
checkSame('CSV body of records', builtBody(Array('format'=>'tabl'), $rowList), "id,name,admin,mail\r\n1,Ann,true,\r\n2,\"Bo, \"\"B\"\"\",,\r\n");
checkSame('CSV body of no record', builtBody(Array('format'=>'tabl'), Array()), '');
checkSame('what is not a list of records cannot be a table', Array(builtBody(Array('format'=>'tabl'), Array('a'=>1)), count(preg_grep('/Response could not be encoded as tabl: a table is written from a list of records/', takeNotes()))), Array('{"error":"Response could not be encoded","code":500}', 1));
checkSame('nor a list holding a nested value', builtBody(Array('format'=>'tabl'), Array(Array('a'=>Array(1)))), '{"error":"Response could not be encoded","code":500}');
takeNotes();
checkSame('an error is written as JSON with tabl', builtBody(Array('format'=>'tabl'), Array('error'=>'Not found', 'code'=>404), true), '{"error":"Not found","code":404}');

// An HTML page : records in a table, any other values in definition lists
$htmPage = builtBody(Array('format'=>'html'), $rowList);
checkSame('HTML page of records: a whole page, its table with a header row', Array(strpos($htmPage, '<!DOCTYPE html>')===0, preg_match('/<table.*<th[^>]*>id<\/th>.*<td[^>]*>Bo, &quot;B&quot;<\/td>/s', $htmPage)), Array(true, 1));
checkSame('HTML page of other values', builtBody(Array('format'=>'html'), Array('user'=>'<b>Ann</b>', 'roles'=>Array('a', 'b'), 'on'=>true)),
	"<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title></title>\n</head>\n<body>\n<dl>\n<dt>user</dt>\n<dd><p>&lt;b&gt;Ann&lt;/b&gt;</p>\n</dd>\n<dt>roles</dt>\n<dd><dl>\n<dt>0</dt>\n<dd><p>a</p>\n</dd>\n<dt>1</dt>\n<dd><p>b</p>\n</dd>\n</dl>\n</dd>\n<dt>on</dt>\n<dd><p>true</p>\n</dd>\n</dl>\n</body>\n</html>\n");
checkLike('an error is a page with html', builtBody(Array('format'=>'html'), Array('error'=>'Not found', 'code'=>404), true), '/<dt>error<\/dt>\n<dd><p>Not found<\/p>/');

// Raw output : a text as it is, a Fluxd piece by piece
checkSame('raw output of a text', builtBody(Array('format'=>'flux'), "line 1\nline 2"), "line 1\nline 2");
$bigText = str_repeat('0123456789', 3000);
checkSame('raw output of a Fluxd, across several pieces', builtBody(Array('format'=>'flux'), Fluxd::openBytes($bigText)), $bigText);
checkSame('raw output of what is neither', builtBody(Array('format'=>'flux'), Array('a'=>1)), '{"error":"Response could not be encoded","code":500}');
takeNotes();
checkSame('an error is written as JSON with flux', builtBody(Array('format'=>'flux'), Array('error'=>'Gone', 'code'=>410), true), '{"error":"Gone","code":410}');

// The format of one reply, chosen by its action
$respons = new Response( Array('format'=>'json') );
$respons->useFormat('TABL');
$respons->formatOut( Array(Array('a'=>1)) );
ob_start();
$respons->sendOutpt();
checkSame('useFormat writes the reply in another format', ob_get_clean(), "a\r\n1\r\n");
checkThrw('useFormat refuses a format that is not one', function(){ (new Response())->useFormat('php'); }, 'RuntimeException');
checkSame('warnings have no place in a table or raw output', Array((new Response(Array('format'=>'tabl')))->takesWarn(), (new Response(Array('format'=>'flux')))->takesWarn(), (new Response(Array('format'=>'yaml')))->takesWarn()), Array(false, false, true));

// Data that cannot be encoded : an error body, a false result and one warning
$respons = new Response();
checkSame('unencodable data is reported', $respons->formatOut( Array('name'=>"caf\xE9") ), false);
ob_start();
$respons->sendOutpt();
checkSame('unencodable data gives an error body', ob_get_clean(), '{"error":"Response could not be encoded","code":500}');
checkSame('unencodable data raises one warning', count(preg_grep('/Response could not be encoded as json: /', takeNotes())), 1);

// An emptied response sends nothing, whatever was prepared before
$respons = new Response();
$respons->formatOut( Array('a'=>1) );
$respons->clearBody();
ob_start();
$respons->sendOutpt();
checkSame('emptied response sends nothing', ob_get_clean(), '');

// The body can be written twice in one request (error reply after a body was prepared)
$respons = new Response( Array('format'=>'json') );
$respons->formatOut( Array('first'=>1) );
$respons->formatOut( Array('error'=>'second', 'code'=>500), true );
ob_start();
$respons->sendOutpt();
checkSame('body built twice keeps the last one', ob_get_clean(), '{"error":"second","code":500}');

// Content types
checkSame('type for json',           foundType(Array('format'=>'json')), 'application/json; charset=utf-8');
checkSame('type for the defaults',   foundType(Array()), 'application/json; charset=utf-8');
checkSame('type for yaml',           foundType(Array('format'=>'yaml')), 'application/yaml; charset=utf-8');
checkSame('type for xmlx',           foundType(Array('format'=>'xmlx')), 'application/xml; charset=utf-8');
checkSame('type for tabl',           foundType(Array('format'=>'tabl')), 'text/csv; charset=utf-8');
checkSame('type for html',           foundType(Array('format'=>'html')), 'text/html; charset=utf-8');
$respons = new Response( Array('format'=>'flux') );
$respons->formatOut('raw bytes');
$typeFnc = new ReflectionMethod($respons, 'contnType');
$typeFnc->setAccessible(true);
checkSame('type for flux, which its action sets', $typeFnc->invoke($respons), 'application/octet-stream');
takeNotes();
checkSame('type of an error with tabl', foundType(Array('format'=>'tabl'), true), 'application/json; charset=utf-8');

// A preset names a format that exists, or does not load
checkSame('the example preset writes json', (new nyu\Setup\Preset('templt'))->makeSetup()->getRegKey('rnder')['format'] ?? null, 'json');
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

// Origins : with a list, only a listed origin is named, whatever the headers of the preset write
/**
 * Gives the CORS headers of a reply to a request of an origin.
 *
 * @param Array   $renderVals The 'rnder' block of the preset.
 * @param ?String $originHead Origin header of the request, null for none.
 * @return Array The Access-Control-Allow-Origin and Vary headers, null for one that is not sent.
 */
function originOf( Array $renderVals, ?String $originHead ): Array {
	$respons = new Response($renderVals);
	$respons->setOrigin($originHead);
	$headLst = array_change_key_case($respons->readHeads(), CASE_LOWER);
	return Array($headLst['access-control-allow-origin'] ?? null, $headLst['vary'] ?? null);
}
$orgConf = Array('origns'=>Array('https://app.example.org', 'HTTPS://Admin.Example.org:8443/', '*', '', Array('x')), 'headrs'=>Array('access-control-allow-origin'=>'*', 'Vary'=>'Accept', 'Cache-Control'=>'no-cache'));
checkSame('origins: a listed origin is named', originOf($orgConf, 'https://app.example.org'), Array('https://app.example.org', 'Origin'));
checkSame('origins: whatever its case, or a slash at its end', originOf($orgConf, 'https://ADMIN.example.org:8443'), Array('https://admin.example.org:8443', 'Origin'));
checkSame('origins: another one is not, and the * of the preset is not sent', originOf($orgConf, 'https://evil.example'), Array(null, 'Origin'));
checkSame('origins: another port, another scheme', Array(originOf($orgConf, 'https://app.example.org:444')[0], originOf($orgConf, 'http://app.example.org')[0]), Array(null, null));
checkSame('origins: a request that tells none', Array(originOf($orgConf, null), originOf($orgConf, '')), Array(Array(null, 'Origin'), Array(null, 'Origin')));
checkSame('origins: a * in the list allows nothing', Array(originOf($orgConf, '*')[0], originOf(Array('origns'=>Array('*')) + $orgConf, 'https://app.example.org')), Array(null, Array('*', 'Accept')));
checkSame('origins: without a list, the headers are sent as written', Array(originOf(Array('headrs'=>$orgConf['headrs']), 'https://evil.example'), originOf(Array('origns'=>Array()) + $orgConf, null)), Array(Array('*', 'Accept'), Array('*', 'Accept')));
$respons = new Response($orgConf);
$respons->setOrigin('https://app.example.org');
checkSame('origins: the other headers of the preset are kept', $respons->readHeads()['Cache-Control'] ?? null, 'no-cache');
checkSame('origins: the example preset loads with its empty list', (new nyu\Setup\Preset('templt'))->makeSetup()->getRegKey('rnder')['origns'] ?? null, Array());

endChecks();
