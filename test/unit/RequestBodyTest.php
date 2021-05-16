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
		$_GET = Array();
		$_POST = Array();
		$_COOKIE = Array();
		$_FILES = Array();
		$reflect = new ReflectionClass($request);
		$loadFnc = $reflect->getMethod('getContnt');
		$loadFnc->setAccessible(true);
		$loadFnc->invoke($request, Array());
		$rawProp = $reflect->getProperty('rawInput');
		$rawProp->setAccessible(true);
		$rawVals = $rawProp->getValue($request);
		$flsProp = $reflect->getProperty('fileData');
		$flsProp->setAccessible(true);
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
checkSame('multipart, field + file : files', $outVals['files'] ?? $outVals, Array('notes.txt'=>"line 1\nline 2"));
checkSame('multipart, field + file : head', $outVals['head'] ?? $outVals, Array('Content-Disposition'=>'form-data', 'Content-Type'=>'text/plain'));

$outVals = parseBody(multiPart(Array(Array('doc','file body','notes.txt'))), $multiTp);
checkSame('multipart, file only : vars', $outVals['vars'] ?? $outVals, Array());
checkSame('multipart, file only : files', $outVals['files'] ?? $outVals, Array('notes.txt'=>'file body'));

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
checkSame('getHeader missing', $request->getHeader('X-Two'), null);

endChecks();
