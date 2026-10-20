<?php

// Tools\Request : parsing of the raw request body (getRawDat / getContnt),
// which matters for PUT and PATCH where PHP does not parse the body itself.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/FakeInput.php';

FakeInput::plugInput();

/**
 * Runs the request's content loading on a chosen body.
 *
 * @param String  $bodyString Raw request body.
 * @param ?String $contntType Content-Type header of the request, if any.
 * @return mixed Array with 'vars', 'files', 'head' and 'size', or a 'THROWN ...' string.
 */
function parseBody( String $bodyString, ?String $contntType ): Mixed {
	return runSafely(function() use ($bodyString, $contntType){
		FakeInput::$bodyText = $bodyString;
		$request = fakeReqst( $contntType===null ? Array() : Array('Content-Type'=>$contntType) );
		$GLOBALS['lastRqst'] = $request;
		$_GET = Array();
		$_POST = Array();
		$_COOKIE = Array();
		$_FILES = Array();
		$reflect = new ReflectionClass($request);
		$loadFnc = $reflect->getMethod('getContnt');
		$loadFnc->invoke($request, Array());
		$rawProp = $reflect->getProperty('rawInput');
		$rawVals = $rawProp->getValue($request);
		$flsProp = $reflect->getProperty('fileData');
		return Array('vars'=>$request->getAllVar(), 'files'=>$flsProp->getValue($request), 'head'=>$rawVals['head'], 'size'=>strlen($rawVals['data']));
	});
}

/**
 * Builds a multipart/form-data body.
 *
 * @param Array  $partsArray Parts as [name, content] or [name, content, filename].
 * @param String $lineEnding Line ending to use.
 * @return String The body.
 */
function multiPart( Array $partsArray, String $lineEnding="\r\n" ): String {
	$bodyTxt = '';
	foreach( $partsArray as $onePrt ){
		$bodyTxt .= '------TestBoundary123'.$lineEnding.'Content-Disposition: form-data; name="'.$onePrt[0].'"';
		if( isset($onePrt[2]) ){
			$bodyTxt .= '; filename="'.$onePrt[2].'"'.$lineEnding.'Content-Type: text/plain';
		}
		$bodyTxt .= $lineEnding.$lineEnding.$onePrt[1].$lineEnding;
	}
	return $bodyTxt.'------TestBoundary123--'.$lineEnding;
}
$multiTp = 'multipart/form-data; boundary=----TestBoundary123';
$formTyp = 'application/x-www-form-urlencoded';

/**
 * Describes a received file entry, replacing the path of its temporary
 * file by the content of that file.
 *
 * @param mixed $fileValues A file entry of the request's file registry.
 * @return mixed The entry with 'text' instead of 'tmp_name', or the value itself when it is not an entry.
 */
function fileShown( Mixed $fileValues ): Mixed {
	if( !is_array($fileValues) || !isset($fileValues['tmp_name']) ){
		return $fileValues;
	}
	$fileValues['text'] = ($fileValues['tmp_name']!='' && is_file($fileValues['tmp_name'])) ? file_get_contents($fileValues['tmp_name']) : null;
	unset($fileValues['tmp_name']);
	return $fileValues;
}

checkSame('empty body',
	parseBody('', null),
	Array('vars'=>Array(), 'files'=>Array(), 'head'=>Array(), 'size'=>0));

checkSame('JSON body',
	parseBody('{"user":"a","pass":"b"}', 'application/json'),
	Array('vars'=>Array('user'=>'a', 'pass'=>'b'), 'files'=>Array(), 'head'=>Array('Content-Type'=>'application/json'), 'size'=>23));

checkSame('invalid JSON gives no variables',
	parseBody('{not json', 'application/json')['vars'] ?? null,
	Array());

$outVals = parseBody(multiPart(Array(Array('user','alice'), Array('pass','s3cret'))), $multiTp);
checkSame('multipart, fields only : vars', $outVals['vars'] ?? $outVals, Array('user'=>'alice', 'pass'=>'s3cret'));
checkSame('multipart, fields only : files', $outVals['files'] ?? $outVals, Array());
checkSame('multipart, fields only : head', $outVals['head'] ?? $outVals, Array('Content-Disposition'=>'form-data'));

$outVals = parseBody(multiPart(Array(Array('user','alice'), Array('doc',"line 1\nline 2",'notes.txt'))), $multiTp);
checkSame('multipart, field + file : vars', $outVals['vars'] ?? $outVals, Array('user'=>'alice'));
checkSame('multipart, field + file : files are keyed by field name', array_keys($outVals['files'] ?? Array()), Array('doc'));
checkSame('multipart, field + file : file entry', fileShown($outVals['files']['doc'] ?? null),
	Array('name'=>'notes.txt', 'full_path'=>'notes.txt', 'type'=>'text/plain', 'error'=>UPLOAD_ERR_OK, 'size'=>13, 'text'=>"line 1\nline 2"));
checkSame('multipart, field + file : head', $outVals['head'] ?? $outVals, Array('Content-Disposition'=>'form-data', 'Content-Type'=>'text/plain'));

$outVals = parseBody(multiPart(Array(Array('doc','file body','notes.txt'))), $multiTp);
checkSame('multipart, file only : vars', $outVals['vars'] ?? $outVals, Array());
checkSame('multipart, file only : file entry', fileShown($outVals['files']['doc'] ?? null),
	Array('name'=>'notes.txt', 'full_path'=>'notes.txt', 'type'=>'text/plain', 'error'=>UPLOAD_ERR_OK, 'size'=>9, 'text'=>'file body'));

// A file name given with folders : 'name' keeps the file name only, as in $_FILES
foreach( Array('photos/2026/a.txt', 'C:\\photos\\2026\\a.txt') as $pthNme ){
	$outVals = parseBody(multiPart(Array(Array('doc','x',$pthNme))), $multiTp);
	checkSame('multipart, file name with folders : '.$pthNme, Array($outVals['files']['doc']['name'] ?? null, $outVals['files']['doc']['full_path'] ?? null), Array('a.txt', $pthNme));
}

// Binary content must arrive byte for byte : every byte value, line breaks and surrounding whitespace included
$binText = "\r\n \t".implode('', array_map('chr', range(0, 255)))."\r\n\r\n--not the boundary\r\n ";
$outVals = parseBody(multiPart(Array(Array('blob', $binText, 'data.bin'))), $multiTp);
$binFile = fileShown($outVals['files']['blob'] ?? null);
checkSame('multipart, binary file : size', $binFile['size'] ?? null, strlen($binText));
checkTrue('multipart, binary file : content is unchanged', ($binFile['text'] ?? null)===$binText);
$outVals = parseBody(multiPart(Array(Array('blob', $binText, 'data.bin')), "\n"), $multiTp);
checkTrue('multipart, binary file with LF framing : content is unchanged', (fileShown($outVals['files']['blob'] ?? null)['text'] ?? null)===$binText);

$outVals = parseBody(multiPart(Array(Array('docs[]','one','a.txt'), Array('docs[]','two','b.txt'))), $multiTp);
// Several files under field[] : one array per property, as PHP fills $_FILES
$docsArr = $outVals['files']['docs'] ?? Array();
$docsTxt = array_map('file_get_contents', array_filter($docsArr['tmp_name'] ?? Array(), 'is_file'));
unset($docsArr['tmp_name']);
checkSame('multipart, several files under field[]', $docsArr, Array(
	'name'=>Array('a.txt', 'b.txt'),
	'full_path'=>Array('a.txt', 'b.txt'),
	'type'=>Array('text/plain', 'text/plain'),
	'error'=>Array(UPLOAD_ERR_OK, UPLOAD_ERR_OK),
	'size'=>Array(3, 3)
));
checkSame('multipart, several files under field[] : contents', $docsTxt, Array('one', 'two'));

// Keyed and nested file fields are nested the same way, the property level right under the field name
$outVals = parseBody(multiPart(Array(
	Array('docs[cover]','front','c.txt'), Array('pics[a][b]','deep','d.txt'),
	Array('sets[a][]','s1','e.txt'), Array('sets[a][]','s2','f.txt')
)), $multiTp);
$flesArr = $outVals['files'] ?? Array();
checkSame('multipart, files : field names', array_keys($flesArr), Array('docs', 'pics', 'sets'));
checkSame('multipart, file under field[key] : name', $flesArr['docs']['name'] ?? null, Array('cover'=>'c.txt'));
checkSame('multipart, file under field[key] : size and error stay integers', Array($flesArr['docs']['size'] ?? null, $flesArr['docs']['error'] ?? null), Array(Array('cover'=>5), Array('cover'=>UPLOAD_ERR_OK)));
checkSame('multipart, file under field[key] : content', @file_get_contents($flesArr['docs']['tmp_name']['cover'] ?? ''), 'front');
checkSame('multipart, file under field[a][b] : name', $flesArr['pics']['name'] ?? null, Array('a'=>Array('b'=>'d.txt')));
checkSame('multipart, file under field[a][b] : content', @file_get_contents($flesArr['pics']['tmp_name']['a']['b'] ?? ''), 'deep');
checkSame('multipart, files under field[a][] : names', $flesArr['sets']['name'] ?? null, Array('a'=>Array('e.txt', 'f.txt')));
checkSame('multipart, files under field[a][] : contents', array_map('file_get_contents', $flesArr['sets']['tmp_name']['a'] ?? Array()), Array('s1', 's2'));
checkSame('multipart, files under field[a][] : every property is nested', array_keys($flesArr['sets'] ?? Array()), Array('name', 'full_path', 'type', 'tmp_name', 'error', 'size'));

$outVals = parseBody(multiPart(Array(Array('docs[cover]','first','a.txt'), Array('docs[cover]','second','b.txt'))), $multiTp);
checkSame('multipart, same file key twice : the last one wins', $outVals['files']['docs']['name'] ?? null, Array('cover'=>'b.txt'));
checkSame('multipart, same file key twice : content', @file_get_contents($outVals['files']['docs']['tmp_name']['cover'] ?? ''), 'second');
$tmpsPrp = new ReflectionProperty($GLOBALS['lastRqst'], 'tmpFiles');
$tmpsLst = $tmpsPrp->getValue($GLOBALS['lastRqst']);
$GLOBALS['lastRqst']->dropTemps();
checkSame('multipart, same file key twice : both temporary files are removed', Array(count($tmpsLst), count(array_filter($tmpsLst, 'file_exists'))), Array(2, 0));

$outVals = parseBody(multiPart(Array(Array('doc','',''))), $multiTp);
checkSame('multipart, file input left empty', $outVals['files']['doc'] ?? null,
	Array('name'=>'', 'full_path'=>'', 'type'=>'text/plain', 'tmp_name'=>'', 'error'=>UPLOAD_ERR_NO_FILE, 'size'=>0));

// Temporary files : removed at the end of the request, unless the application moved them
$outVals = parseBody(multiPart(Array(Array('one','1','a.txt'), Array('two','2','b.txt'))), $multiTp);
$tmpOne = $outVals['files']['one']['tmp_name'] ?? '';
$tmpTwo = $outVals['files']['two']['tmp_name'] ?? '';
$movedFl = inTempDir('moved.txt');
checkTrue('temporary files exist during the request', is_file($tmpOne) && is_file($tmpTwo));
rename($tmpTwo, $movedFl);
$GLOBALS['lastRqst']->dropTemps();
checkTrue('dropTemps removes what is left', !file_exists($tmpOne));
checkSame('dropTemps leaves a moved file alone', file_get_contents($movedFl), '2');

checkSame('multipart, nested and repeated variable names',
	parseBody(multiPart(Array(Array('filters[search]','Alpha*'), Array('tags[]','a'), Array('tags[]','b'), Array('plain',' x '))), $multiTp)['vars'] ?? null,
	Array('filters'=>Array('search'=>'Alpha*'), 'tags'=>Array('a', 'b'), 'plain'=>' x '));

checkSame('multipart, quoted boundary in the header',
	parseBody(multiPart(Array(Array('user','alice'))), 'multipart/form-data; boundary="----TestBoundary123"')['vars'] ?? null,
	Array('user'=>'alice'));

checkSame('multipart without a boundary in the header is left untouched',
	parseBody(multiPart(Array(Array('user','alice'))), 'multipart/form-data')['vars'] ?? null,
	Array());

checkSame('multipart with LF line ends',
	parseBody(multiPart(Array(Array('user','alice')), "\n"), $multiTp)['vars'] ?? null,
	Array('user'=>'alice'));

checkSame('multipart value containing ; and :',
	parseBody(multiPart(Array(Array('note','a: b; c=d'))), $multiTp)['vars'] ?? null,
	Array('note'=>'a: b; c=d'));

checkSame('multipart with an empty value',
	parseBody(multiPart(Array(Array('user',''), Array('pass','x'))), $multiTp)['vars'] ?? null,
	Array('user'=>'', 'pass'=>'x'));

checkSame('multipart type, body that is not multipart',
	parseBody('not really multipart', $multiTp),
	Array('vars'=>Array(), 'files'=>Array(), 'head'=>Array(), 'size'=>20));

checkSame('plain text on several lines is left untouched',
	parseBody("hello\nworld\nagain", 'text/plain'),
	Array('vars'=>Array(), 'files'=>Array(), 'head'=>Array(), 'size'=>17));

checkSame('several lines without a content type',
	parseBody("a=1\nb=2", null),
	Array('vars'=>Array(), 'files'=>Array(), 'head'=>Array(), 'size'=>7));

checkSame('URL-encoded form body',
	parseBody('user=alice&pass=s3cret', $formTyp),
	Array('vars'=>Array('user'=>'alice', 'pass'=>'s3cret'), 'files'=>Array(), 'head'=>Array('Content-Type'=>$formTyp), 'size'=>22));

checkSame('URL-encoded arrays and escapes',
	parseBody('filters%5Bsearch%5D=Alpha*&tags[]=a&tags[]=b&note=a%26b+c', $formTyp.'; charset=UTF-8')['vars'] ?? null,
	Array('filters'=>Array('search'=>'Alpha*'), 'tags'=>Array('a', 'b'), 'note'=>'a&b c'));

// Header and value readers
$request = fakeReqst( Array('X-One'=>'1') );
checkSame('getHeader present', $request->getHeader('X-One'), '1');
checkSame('getHeader in another case', $request->getHeader('x-one'), '1');
checkSame('getHeader missing', $request->getHeader('X-Two'), null);

// fromQuery : what came in the query string of the address is known by name, whatever the body holds
FakeInput::$bodyText = 'pass=in-the-body&code=123456';
$request = fakeReqst( Array('Content-Type'=>$formTyp) );
checkSame('fromQuery before anything is read', $request->fromQuery('pass'), false);
$_GET = Array('Former'=>'x');
$_POST = Array();
$_COOKIE = Array();
$_FILES = Array();
$loadFnc = new ReflectionMethod($request, 'getContnt');
$loadFnc->invoke($request, Array('User'=>'alice', 'pass'=>'in-the-address'));
checkSame('fromQuery: a name of the address, one of both, one of the body', Array($request->fromQuery('user'), $request->fromQuery('pass'), $request->fromQuery('code')), Array(true, true, false));
checkSame('fromQuery: whatever the case, and what PHP read from the address', Array($request->fromQuery('PASS'), $request->fromQuery('former'), $request->fromQuery('nothing')), Array(true, true, false));

// Limits : a body larger than allowed, or of more parts, is not read
/**
 * Loads a body under limits, and tells what was kept of it.
 *
 * @param String  $bodyString Raw request body.
 * @param String  $contntType Content-Type header of the request.
 * @param Int     $sizeKBytes Largest body, in KB; 0 for no limit.
 * @param Int     $partsCount Most parts of a multipart body; 0 for no limit.
 * @param ?String $toldLength Content-Length header of the request, null for none.
 * @return Array Whether the request is over its limit, and the names of the values read.
 */
function limitBody( String $bodyString, String $contntType, Int $sizeKBytes, Int $partsCount=0, ?String $toldLength=null ): Array {
	FakeInput::$bodyText = $bodyString;
	$request = fakeReqst( Array('Content-Type'=>$contntType) + ($toldLength===null ? Array() : Array('Content-Length'=>$toldLength)) );
	foreach( Array('sizeLimt'=>$sizeKBytes, 'partLimt'=>$partsCount) as $prpNme=>$prpVal ){
		$propObj = new ReflectionProperty($request, $prpNme);
		$propObj->setValue($request, $prpVal);
	}
	$_GET = Array();
	$_POST = Array();
	$_COOKIE = Array();
	$_FILES = Array();
	$loadFnc = new ReflectionMethod($request, 'getContnt');
	$loadFnc->invoke($request, Array());
	return Array($request->overLimit(), array_keys($request->getAllVar()));
}
$bigJson = json_encode(Array('note'=>str_repeat('x', 2000)));
checkSame('limit: no limit reads any body', limitBody($bigJson, 'application/json', 0), Array(false, Array('note')));
checkSame('limit: a body within the size is read', limitBody('{"a":1}', 'application/json', 1), Array(false, Array('a')));
checkSame('limit: a body of exactly the size is read', limitBody(str_repeat('a', 1022).'=1', $formTyp, 1)[0], false);
checkSame('limit: a larger body is not read', limitBody($bigJson, 'application/json', 1), Array(true, Array()));
checkSame('limit: a larger body announced as such is refused on its header', limitBody('{"a":1}', 'application/json', 1, 0, '5000'), Array(true, Array()));
checkSame('limit: a header that lies is not believed', limitBody($bigJson, 'application/json', 1, 0, '10'), Array(true, Array()));
$twoPart = multiPart(Array(Array('a', '1'), Array('b', '2')));
checkSame('limit: a multipart body within its parts is read', limitBody($twoPart, $multiTp, 0, 2), Array(false, Array('a', 'b')));
checkSame('limit: one of more parts is not', limitBody(multiPart(Array(Array('a', '1'), Array('b', '2'), Array('c', '3'))), $multiTp, 0, 2), Array(true, Array()));
checkSame('limit: no limit on the parts reads them all', count(limitBody(multiPart(array_map(function( Int $partIndex ): Array { return Array('f'.$partIndex, 'v'); }, range(1, 40))), $multiTp, 0, 0)[1]), 40);

endChecks();
