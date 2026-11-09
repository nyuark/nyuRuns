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
	$respons->convertOutFlag($dataValues, $errorReply);
	ob_start();
	$respons->transmitOutput();
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
	$respons->convertOutFlag( Array(Array('a'=>1)), $errorReply );
	$typeFnc = new ReflectionMethod($respons, 'contnReplyType');
	return $typeFnc->invoke($respons);
}

$rowList = Array( Array('id'=>1, 'name'=>'Ann', 'admin'=>true), Array('id'=>2, 'name'=>'Bo, "B"', 'mail'=>null) );

// JSON, the default
checkIdentical('JSON body',               builtBody(Array(), Array('a'=>1, 'b'=>Array('c'))), '{"a":1,"b":["c"]}');
checkIdentical('JSON is the default',     builtBody(Array('outputAs'=>'jsonData'), Array()), '[]');
checkIdentical('format name is case-insensitive', builtBody(Array('outputAs'=>'JsonData'), Array('a'=>1)), '{"a":1}');
checkIdentical('JSON body: a string',     builtBody(Array(), 'plain text'), '"plain text"');
checkIdentical('JSON body: a number',     builtBody(Array(), 42), '42');
checkIdentical('JSON body: a boolean',    builtBody(Array(), false), 'false');
checkIdentical('JSON body: an object',    builtBody(Array(), (object)Array('a'=>1)), '{"a":1}');

// YAML, XML
checkIdentical('YAML body', builtBody(Array('outputAs'=>'yamlData'), Array('a'=>1, 'b'=>Array('c', 'd'))), "a: 1\nb:\n  - c\n  - d\n");
checkPregMatch('XML body', builtBody(Array('outputAs'=>'xmlNodes'), Array('a'=>1, 'b'=>'x & y')), '/^<\?xml version="1\.0" encoding="UTF-8"\?>.*<a>1<\/a>.*<b>x &amp; y<\/b>/s');

// A table : a list of records, its columns in the order they first come
checkIdentical('CSV body of records', builtBody(Array('outputAs'=>'csvTable'), $rowList), "id,name,admin,mail\r\n1,Ann,true,\r\n2,\"Bo, \"\"B\"\"\",,\r\n");
checkIdentical('CSV body of no record', builtBody(Array('outputAs'=>'csvTable'), Array()), '');
checkIdentical('what is not a list of records cannot be a table', Array(builtBody(Array('outputAs'=>'csvTable'), Array('a'=>1)), count(preg_grep('/Response could not be encoded as csvTable: a table is written from a list of records/', takeNotesArray()))), Array('{"error":"Response could not be encoded","code":500}', 1));
checkIdentical('nor a list holding a nested value', builtBody(Array('outputAs'=>'csvTable'), Array(Array('a'=>Array(1)))), '{"error":"Response could not be encoded","code":500}');
takeNotesArray();
checkIdentical('an error is written as JSON with csvTable', builtBody(Array('outputAs'=>'csvTable'), Array('error'=>'Not found', 'code'=>404), true), '{"error":"Not found","code":404}');

// An HTML page : records in a table, any other values in definition lists
$htmPage = builtBody(Array('outputAs'=>'htmlView'), $rowList);
checkIdentical('HTML page of records: a whole page, its table with a header row', Array(strpos($htmPage, '<!DOCTYPE html>')===0, preg_match('/<table.*<th[^>]*>id<\/th>.*<td[^>]*>Bo, &quot;B&quot;<\/td>/s', $htmPage)), Array(true, 1));
checkIdentical('HTML page of other values', builtBody(Array('outputAs'=>'htmlView'), Array('user'=>'<b>Ann</b>', 'roles'=>Array('a', 'b'), 'on'=>true)),
	"<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title></title>\n</head>\n<body>\n<dl>\n<dt>user</dt>\n<dd><p>&lt;b&gt;Ann&lt;/b&gt;</p>\n</dd>\n<dt>roles</dt>\n<dd><dl>\n<dt>0</dt>\n<dd><p>a</p>\n</dd>\n<dt>1</dt>\n<dd><p>b</p>\n</dd>\n</dl>\n</dd>\n<dt>on</dt>\n<dd><p>true</p>\n</dd>\n</dl>\n</body>\n</html>\n");
checkPregMatch('an error is a page with html', builtBody(Array('outputAs'=>'htmlView'), Array('error'=>'Not found', 'code'=>404), true), '/<dt>error<\/dt>\n<dd><p>Not found<\/p>/');

// Raw output : a text as it is, a Fluxd piece by piece
checkIdentical('raw output of a text', builtBody(Array('outputAs'=>'rawBytes'), "line 1\nline 2"), "line 1\nline 2");
$bigText = str_repeat('0123456789', 3000);
checkIdentical('raw output of a Fluxd, across several pieces', builtBody(Array('outputAs'=>'rawBytes'), Fluxd::openBytesFluxd($bigText)), $bigText);
checkIdentical('raw output of what is neither', builtBody(Array('outputAs'=>'rawBytes'), Array('a'=>1)), '{"error":"Response could not be encoded","code":500}');
takeNotesArray();
checkIdentical('an error is written as JSON with rawBytes', builtBody(Array('outputAs'=>'rawBytes'), Array('error'=>'Gone', 'code'=>410), true), '{"error":"Gone","code":410}');

// The format of one reply, chosen by its action
$respons = new Response( Array('outputAs'=>'jsonData') );
$respons->useReplyFormat('CSVTABLE');
$respons->convertOutFlag( Array(Array('a'=>1)) );
ob_start();
$respons->transmitOutput();
checkIdentical('useFormat writes the reply in another format', ob_get_clean(), "a\r\n1\r\n");
checkForThrows('useFormat refuses a format that is not one', function(){ (new Response())->useReplyFormat('php'); }, 'RuntimeException');
checkIdentical('warnings have no place in a table or raw output', Array((new Response(Array('outputAs'=>'csvTable')))->takesReplyWarn(), (new Response(Array('outputAs'=>'rawBytes')))->takesReplyWarn(), (new Response(Array('outputAs'=>'yamlData')))->takesReplyWarn()), Array(false, false, true));

// Data that cannot be encoded : an error body, a false result and one warning
$respons = new Response();
checkIdentical('unencodable data is reported', $respons->convertOutFlag( Array('name'=>"caf\xE9") ), false);
ob_start();
$respons->transmitOutput();
checkIdentical('unencodable data gives an error body', ob_get_clean(), '{"error":"Response could not be encoded","code":500}');
checkIdentical('unencodable data raises one warning', count(preg_grep('/Response could not be encoded as jsonData: /', takeNotesArray())), 1);

// An emptied response sends nothing, whatever was prepared before
$respons = new Response();
$respons->convertOutFlag( Array('a'=>1) );
$respons->clearReplyBody();
ob_start();
$respons->transmitOutput();
checkIdentical('emptied response sends nothing', ob_get_clean(), '');

// The body can be written twice in one request (error reply after a body was prepared)
$respons = new Response( Array('outputAs'=>'jsonData') );
$respons->convertOutFlag( Array('first'=>1) );
$respons->convertOutFlag( Array('error'=>'second', 'code'=>500), true );
ob_start();
$respons->transmitOutput();
checkIdentical('body built twice keeps the last one', ob_get_clean(), '{"error":"second","code":500}');

// Content types
checkIdentical('type for jsonData',           foundType(Array('outputAs'=>'jsonData')), 'application/json; charset=utf-8');
checkIdentical('type for the defaults',   foundType(Array()), 'application/json; charset=utf-8');
checkIdentical('type for yamlData',           foundType(Array('outputAs'=>'yamlData')), 'application/yaml; charset=utf-8');
checkIdentical('type for xmlNodes',           foundType(Array('outputAs'=>'xmlNodes')), 'application/xml; charset=utf-8');
checkIdentical('type for csvTable',           foundType(Array('outputAs'=>'csvTable')), 'text/csv; charset=utf-8');
checkIdentical('type for htmlView',           foundType(Array('outputAs'=>'htmlView')), 'text/html; charset=utf-8');
$respons = new Response( Array('outputAs'=>'rawBytes') );
$respons->convertOutFlag('raw bytes');
$typeFnc = new ReflectionMethod($respons, 'contnReplyType');
checkIdentical('type for flux, which its action sets', $typeFnc->invoke($respons), 'application/octet-stream');
takeNotesArray();
checkIdentical('type of an error with csvTable', foundType(Array('outputAs'=>'csvTable'), true), 'application/json; charset=utf-8');

// A preset names a format that exists, or does not load
checkIdentical('the example preset writes json', (new nyu\Setup\Preset('template'))->newSetupLoader()->getRegistryKey('response')['outputAs'] ?? null, 'jsonData');
/**
 * Registers a temporary file with a Range value and returns what is sent.
 *
 * @param String $rangeValue Range header value.
 * @param String $fileContnt Contents of the file.
 * @return Array The status and the body.
 */
function fileSent( String $rangeValue, String $fileContnt='abcdefghijklmnopqrstuvwxyz' ): Array {
	$tmpFile = inTemporaryDir('range.bin');
	file_put_contents($tmpFile, $fileContnt);
	$respons = new Response();
	$respons->setFileToReply($tmpFile, 'audio/mp4', $rangeValue);
	$codePrp = new ReflectionProperty($respons, 'fileStatus');
	ob_start();
	$respons->transmitOutput();
	return Array($codePrp->getValue($respons), ob_get_clean());
}

// File replies : the status and the bytes sent for each kind of Range header
checkIdentical('file: no range sends it all',        fileSent(''), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkIdentical('file: first bytes',                  fileSent('bytes=0-4'), Array(206, 'abcde'));
checkIdentical('file: from a byte to the end',       fileSent('bytes=20-'), Array(206, 'uvwxyz'));
checkIdentical('file: last bytes',                   fileSent('bytes=-3'), Array(206, 'xyz'));
checkIdentical('file: a suffix longer than the file sends it all as a range', fileSent('bytes=-99'), Array(206, 'abcdefghijklmnopqrstuvwxyz'));
checkIdentical('file: an end past the file is cut',  fileSent('bytes=24-99'), Array(206, 'yz'));
checkIdentical('file: one byte',                     fileSent('bytes=5-5'), Array(206, 'f'));
checkIdentical('file: spaces and case are accepted', fileSent(' Bytes = 1 - 2 '), Array(206, 'bc'));
checkIdentical('file: a start past the file is 416', fileSent('bytes=26-'), Array(416, ''));
checkIdentical('file: an empty suffix is 416',       fileSent('bytes=-0'), Array(416, ''));
checkIdentical('file: a reversed range is ignored',  fileSent('bytes=9-2'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkIdentical('file: several ranges are ignored',   fileSent('bytes=0-1,4-5'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkIdentical('file: another unit is ignored',      fileSent('items=0-1'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkIdentical('file: a range without bounds is ignored', fileSent('bytes=-'), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
checkIdentical('file: an empty file is sent empty',  fileSent('', ''), Array(200, ''));
checkIdentical('file: a range of an empty file is 416', fileSent('bytes=0-', ''), Array(416, ''));
$bigText = str_repeat('0123456789', 3000);
checkIdentical('file: a range across several pieces', fileSent('bytes=5-20004', $bigText), Array(206, substr($bigText, 5, 20000)));

// A file that cannot be read is refused, and the data body is still the one sent
$respons = new Response();
checkIdentical('file: a missing file is refused', $respons->setFileToReply(inTemporaryDir('nosuchfile.bin'), 'audio/mp4'), false);
checkIdentical('file: a folder is refused',       $respons->setFileToReply(TMP_DIR, 'audio/mp4'), false);
$respons->convertOutFlag( Array('a'=>1) );
ob_start();
$respons->transmitOutput();
checkIdentical('file: a refused file leaves the data body', ob_get_clean(), '{"a":1}');

/**
 * Registers a temporary HTML page with its values and returns what is sent.
 *
 * @param String $pageString Contents of the page.
 * @param Array  $pageValues Values of the placeholders.
 * @return String The body.
 */
function pageSent( String $pageString, Array $pageValues=Array() ): String {
	$tmpFile = inTemporaryDir('page.html');
	file_put_contents($tmpFile, $pageString);
	$respons = new Response();
	$respons->convertOutFlag( Array('a'=>1) );
	$respons->setPageToReply($tmpFile, $pageValues);
	ob_start();
	$respons->transmitOutput();
	return ob_get_clean();
}

// Page replies : sent in place of the data body, as written, their placeholders filled
checkIdentical('page: sent as written',                 pageSent('<p>{"not":"json"}</p>'), '<p>{"not":"json"}</p>');
checkIdentical('page: a placeholder is filled',         pageSent('<p>%name%, %name%</p>', Array('name'=>'Ann')), '<p>Ann, Ann</p>');
checkIdentical('page: a value is encoded',              pageSent('<a title="%t%">%t%</a>', Array('t'=>'<i>"a" & \'b\'</i>')), '<a title="&lt;i&gt;&quot;a&quot; &amp; &#039;b&#039;&lt;/i&gt;">&lt;i&gt;&quot;a&quot; &amp; &#039;b&#039;&lt;/i&gt;</a>');
checkIdentical('page: a number is written',             pageSent('%n% of %m%', Array('n'=>3, 'm'=>4.5)), '3 of 4.5');
checkIdentical('page: a placeholder without value is left', pageSent('%name% %other%', Array('name'=>'Ann')), 'Ann %other%');
checkIdentical('page: a value that is not a text is left', pageSent('%list% %none%', Array('list'=>Array(1), 'none'=>null)), '%list% %none%');
checkIdentical('page: a filled value is not filled again', pageSent('%a%', Array('a'=>'%b%', 'b'=>'no')), '%b%');
checkIdentical('page: percent signs are not placeholders', pageSent('width:50%;height:50% %a%', Array('a'=>'x')), 'width:50%;height:50% x');
checkIdentical('page: text that is not UTF-8 is still sent', pageSent('%a%', Array('a'=>"caf\xE9"))!=='', true);

$respons = new Response();
checkIdentical('page: a missing file is refused', $respons->setPageToReply(inTemporaryDir('nosuchpage.html')), false);
checkIdentical('page: a folder is refused',       $respons->setPageToReply(TMP_DIR), false);
$tmpFile = inTemporaryDir('page.html');
file_put_contents($tmpFile, '<p>page</p>');
$respons->setPageToReply($tmpFile);
$respons->clearReplyBody();
ob_start();
$respons->transmitOutput();
checkIdentical('page: an emptied response sends nothing', ob_get_clean(), '');

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
	$tmpFile = inTemporaryDir('kept.txt');
	file_put_contents($tmpFile, 'abcdefghijklmnopqrstuvwxyz');
	touch($tmpFile, 1700000000);
	clearstatcache();
	$respons = new Response($renderVals);
	$respons->setFileToReply($tmpFile, 'text/plain', strval($headersArr['Range'] ?? ''));
	$respons->cacheReplyFile($headersArr, $sharedFile);
	$codePrp = new ReflectionProperty($respons, 'fileStatus');
	$headPrp = new ReflectionProperty($respons, 'headrArray');
	ob_start();
	$respons->transmitOutput();
	return Array($codePrp->getValue($respons), ob_get_clean(), $headPrp->getValue($respons));
}

// Kept files : their version and date, and the 304 given to a client that holds them
$allText = 'abcdefghijklmnopqrstuvwxyz';
$fileTag = '"'.dechex(1700000000).'-1a"';
$fileDat = 'Tue, 14 Nov 2023 22:13:20 GMT';
checkIdentical('cache: version, date and cache headers', keptFile(), Array(200, $allText, Array('Cache-Control'=>'private, no-cache', 'ETag'=>$fileTag, 'Last-Modified'=>$fileDat)));
checkIdentical('cache: a file served to anyone', keptFile(Array(), true)[2]['Cache-Control'], 'public, no-cache');
checkIdentical('cache: the cache headers of the preset are replaced', keptFile(Array(), false, Array('headList'=>Array('cache-control'=>'no-store', 'Pragma'=>'no-cache', 'EXPIRES'=>'0', 'X-Kept'=>'yes')))[2], Array('X-Kept'=>'yes', 'Cache-Control'=>'private, no-cache', 'ETag'=>$fileTag, 'Last-Modified'=>$fileDat));
checkIdentical('cache: the same version is a 304, without body', array_slice(keptFile(Array('If-None-Match'=>$fileTag)), 0, 2), Array(304, ''));
checkIdentical('cache: header names in another case', keptFile(Array('if-none-match'=>$fileTag))[0], 304);
checkIdentical('cache: a version marked as weak', keptFile(Array('If-None-Match'=>'W/'.$fileTag))[0], 304);
checkIdentical('cache: one of several versions', keptFile(Array('If-None-Match'=>'"aaa", '.$fileTag.' , "bbb"'))[0], 304);
checkIdentical('cache: any version', keptFile(Array('If-None-Match'=>'*'))[0], 304);
checkIdentical('cache: another version gets the file', array_slice(keptFile(Array('If-None-Match'=>'"aaa"')), 0, 2), Array(200, $allText));
checkIdentical('cache: a version without its quotes is another one', keptFile(Array('If-None-Match'=>trim($fileTag, '"')))[0], 200);
checkIdentical('cache: not modified since its date', keptFile(Array('If-Modified-Since'=>$fileDat))[0], 304);
checkIdentical('cache: not modified since a later date', keptFile(Array('If-Modified-Since'=>'Wed, 15 Nov 2023 00:00:00 GMT'))[0], 304);
checkIdentical('cache: modified since an earlier date', keptFile(Array('If-Modified-Since'=>'Tue, 14 Nov 2023 22:13:19 GMT'))[0], 200);
checkIdentical('cache: a date that cannot be read is ignored', keptFile(Array('If-Modified-Since'=>'not a date'))[0], 200);
checkIdentical('cache: the version is asked before the date', keptFile(Array('If-None-Match'=>'"aaa"', 'If-Modified-Since'=>$fileDat))[0], 200);
checkIdentical('cache: a range of the same version', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>$fileTag)), 0, 2), Array(206, 'abcde'));
checkIdentical('cache: a range since the same date', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>$fileDat)), 0, 2), Array(206, 'abcde'));
checkIdentical('cache: a range of another version gets the whole file', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>'"aaa"')), 0, 2), Array(200, $allText));
checkIdentical('cache: a range of a weak version gets the whole file', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-Range'=>'W/'.$fileTag)), 0, 2), Array(200, $allText));
checkIdentical('cache: a range without condition is served', array_slice(keptFile(Array('Range'=>'bytes=0-4')), 0, 2), Array(206, 'abcde'));
checkIdentical('cache: a range of a version the client holds is a 304', array_slice(keptFile(Array('Range'=>'bytes=0-4', 'If-None-Match'=>$fileTag)), 0, 2), Array(304, ''));
$respons = new Response();
$respons->cacheReplyFile( Array('If-None-Match'=>'*') );
$headPrp = new ReflectionProperty($respons, 'headrArray');
checkIdentical('cache: nothing is set without a file', $headPrp->getValue($respons), Array());

// Content types of the files a declared route serves
checkIdentical('type of a stylesheet',            Response::typOfExtension('css'), 'text/css; charset=utf-8');
checkIdentical('type: the case is ignored',       Response::typOfExtension('PNG'), 'image/png');
checkIdentical('type: a script has none',         Response::typOfExtension('php'), null);
checkIdentical('type: no extension has none',     Response::typOfExtension(''), null);

// Origins : with a list, only a listed origin is named, whatever the headers of the preset write
/**
 * Gives the CORS headers of a reply to a request of an origin.
 *
 * @param Array   $renderVals The 'response' block of the preset.
 * @param ?String $originHead Origin header of the request, null for none.
 * @return Array The Access-Control-Allow-Origin and Vary headers, null for one that is not sent.
 */
function originOf( Array $renderVals, ?String $originHead ): Array {
	$respons = new Response($renderVals);
	$respons->setReplyOrigin($originHead);
	$headLst = array_change_key_case($respons->readHeadsArray(), CASE_LOWER);
	return Array($headLst['access-control-allow-origin'] ?? null, $headLst['vary'] ?? null);
}
$orgConf = Array('corsList'=>Array('https://app.example.org', 'HTTPS://Admin.Example.org:8443/', '*', '', Array('x')), 'headList'=>Array('access-control-allow-origin'=>'*', 'Vary'=>'Accept', 'Cache-Control'=>'no-cache'));
checkIdentical('origins: a listed origin is named', originOf($orgConf, 'https://app.example.org'), Array('https://app.example.org', 'Origin'));
checkIdentical('origins: whatever its case, or a slash at its end', originOf($orgConf, 'https://ADMIN.example.org:8443'), Array('https://admin.example.org:8443', 'Origin'));
checkIdentical('origins: another one is not, and the * of the preset is not sent', originOf($orgConf, 'https://evil.example'), Array(null, 'Origin'));
checkIdentical('origins: another port, another scheme', Array(originOf($orgConf, 'https://app.example.org:444')[0], originOf($orgConf, 'http://app.example.org')[0]), Array(null, null));
checkIdentical('origins: a request that tells none', Array(originOf($orgConf, null), originOf($orgConf, '')), Array(Array(null, 'Origin'), Array(null, 'Origin')));
checkIdentical('origins: a * in the list allows nothing', Array(originOf($orgConf, '*')[0], originOf(Array('corsList'=>Array('*')) + $orgConf, 'https://app.example.org')), Array(null, Array('*', 'Accept')));
checkIdentical('origins: without a list, the headers are sent as written', Array(originOf(Array('headList'=>$orgConf['headList']), 'https://evil.example'), originOf(Array('corsList'=>Array()) + $orgConf, null)), Array(Array('*', 'Accept'), Array('*', 'Accept')));
$respons = new Response($orgConf);
$respons->setReplyOrigin('https://app.example.org');
checkIdentical('origins: the other headers of the preset are kept', $respons->readHeadsArray()['Cache-Control'] ?? null, 'no-cache');
checkIdentical('origins: the example preset loads with its empty list', (new nyu\Setup\Preset('template'))->newSetupLoader()->getRegistryKey('response')['corsList'] ?? null, Array());

finishChecking();
