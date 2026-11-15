<?php

// Data\Fluxd : a source read piece by piece, from a file, from bytes in
// memory and from the body of the request.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/FakeInput.php';

use nyu\Data\Fluxd;

$lttrPth = TST_DIR.DS.'fixtures'.DS.'letters.txt';
$fixtDir = TST_DIR.DS.'fixtures';

// A file of the server
checkIdentical('file: size, and not at the end', runCatchThrown(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocalFluxd($lttrPth);
	return Array($fileFlx->getSourceBytes(), $fileFlx->isFluxFinished());
}), Array(26, false));
checkIdentical('file: read piece by piece until the end', runCatchThrown(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocalFluxd($lttrPth);
	$chnkLst = Array();
	while( ($chnkTxt = $fileFlx->getChunkString(10))!==false ){
		$chnkLst[] = $chnkTxt;
	}
	return Array($chnkLst, $fileFlx->isFluxFinished(), $fileFlx->getChunkString(10));
}), Array(Array('abcdefghij', 'klmnopqrst', 'uvwxyz'), true, false));
checkIdentical('file: the end is known as soon as the last byte is read', runCatchThrown(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocalFluxd($lttrPth);
	$fileFlx->getChunkString(26);
	return $fileFlx->isFluxFinished();
}), true);
checkIdentical('file: a range, both ends included, then the reading goes on', runCatchThrown(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocalFluxd($lttrPth);
	return Array($fileFlx->getRangeString(0, 4), $fileFlx->getChunkString(3), $fileFlx->getRangeString(23, 99), $fileFlx->getRangeString(5, 5));
}), Array('abcde', 'fgh', 'xyz', 'f'));
checkIdentical('file: back to the start', runCatchThrown(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocalFluxd($lttrPth);
	$fileFlx->getChunkString(20);
	$fileFlx->setFluxToStart();
	return $fileFlx->getChunkString(3);
}), 'abc');
checkIdentical('file: the whole content, from the start wherever the reading was', runCatchThrown(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocalFluxd($lttrPth);
	$fileFlx->getChunkString(20);
	return Array($fileFlx->getWholeString(), $fileFlx->getWholeString(26));
}), Array('abcdefghijklmnopqrstuvwxyz', 'abcdefghijklmnopqrstuvwxyz'));
checkPregMatch('file: a content larger than the limit is refused', runCatchThrown(function() use ($lttrPth){ return Fluxd::openLocalFluxd($lttrPth)->getWholeString(25); }), '/^THROWN RuntimeException: Content larger than the 25 bytes allowed/');
checkIdentical('file: as a local file, it is its own path, and nothing is copied', runCatchThrown(function() use ($lttrPth){
	return Fluxd::openLocalFluxd($lttrPth)->setLocalString()===realpath($lttrPth);
}), true);

// Refusals
checkPregMatch('a missing file is refused, without its full path', runCatchThrown(function(){ return Fluxd::openLocalFluxd(inTemporaryDir('nowhere.txt')); }), '/^THROWN RuntimeException: File not found: nowhere\.txt/');
checkPregMatch('a folder is not a file', runCatchThrown(function() use ($fixtDir){ return Fluxd::openLocalFluxd($fixtDir); }), '/^THROWN RuntimeException: File not found/');
checkIdentical('a file inside the base folder is accepted', runCatchThrown(function() use ($lttrPth, $fixtDir){ return Fluxd::openLocalFluxd($lttrPth, $fixtDir)->getSourceBytes(); }), 26);
checkPregMatch('a path climbing out of the base folder is refused', runCatchThrown(function() use ($fixtDir){
	return Fluxd::openLocalFluxd($fixtDir.DS.'conf'.DS.'..'.DS.'..'.DS.'README.md', $fixtDir.DS.'conf');
}), '/^THROWN RuntimeException: File outside the allowed folder: README\.md/');
checkPregMatch('a folder whose name only starts like the base folder is outside it', runCatchThrown(function() use ($fixtDir){
	return Fluxd::openLocalFluxd($fixtDir.DS.'confbad'.DS.'system.ini', $fixtDir.DS.'conf');
}), '/^THROWN RuntimeException: File outside the allowed folder/');
checkPregMatch('a range that ends before it starts is refused', runCatchThrown(function() use ($lttrPth){ return Fluxd::openLocalFluxd($lttrPth)->getRangeString(5, 4); }), '/^THROWN RuntimeException: Invalid byte range: 5-4/');
checkPregMatch('a piece of no byte is refused', runCatchThrown(function() use ($lttrPth){ return Fluxd::openLocalFluxd($lttrPth)->getChunkString(0); }), '/^THROWN RuntimeException: A piece holds at least one byte/');

// Bytes in memory
checkIdentical('bytes: lines, whatever their ending', runCatchThrown(function(){
	$byteFlx = Fluxd::openBytesFluxd("first\nsecond\r\n\nfourth");
	$lineLst = Array();
	while( ($lineTxt = $byteFlx->readLineOfFlux())!==false ){
		$lineLst[] = $lineTxt;
	}
	return Array($lineLst, $byteFlx->getSourceBytes(), $byteFlx->isFluxFinished());
}), Array(Array('first', 'second', '', 'fourth'), 21, true));
checkIdentical('bytes: binary content is kept byte for byte', runCatchThrown(function(){
	$rawBlob = "\x00\x01\xFF\x89PNG\r\n";
	$byteFlx = Fluxd::openBytesFluxd($rawBlob);
	return Array($byteFlx->getWholeString()===$rawBlob, $byteFlx->getSourceBytes());
}), Array(true, 9));
checkIdentical('bytes: an empty source is at its end at once', runCatchThrown(function(){
	$byteFlx = Fluxd::openBytesFluxd('');
	return Array($byteFlx->getSourceBytes(), $byteFlx->isFluxFinished(), $byteFlx->getChunkString(), $byteFlx->readLineOfFlux(), $byteFlx->getWholeString());
}), Array(0, true, false, false, ''));
checkIdentical('bytes: saved to a temporary file, the position kept, the file removed on closing', runCatchThrown(function(){
	$byteFlx = Fluxd::openBytesFluxd('blob of a table');
	$byteFlx->getChunkString(5);
	$savePth = $byteFlx->setLocalString();
	$readOut = Array(is_file($savePth), file_get_contents($savePth), $byteFlx->getChunkString(3));
	$byteFlx->shutFluxSource();
	return array_merge($readOut, Array(is_file($savePth)));
}), Array(true, 'blob of a table', 'of ', false));
checkIdentical('bytes: saved to a chosen path, which is kept after closing', runCatchThrown(function(){
	$savePth = inTemporaryDir('kept.bin');
	$byteFlx = Fluxd::openBytesFluxd('kept content');
	$gotPath = $byteFlx->setLocalString($savePth);
	$byteFlx->shutFluxSource();
	return Array($gotPath===$savePth, file_get_contents($savePth));
}), Array(true, 'kept content'));
checkPregMatch('a path that cannot be written is refused', runCatchThrown(function(){ return Fluxd::openBytesFluxd('x')->setLocalString(inTemporaryDir('no'.DS.'such'.DS.'folder'.DS.'x.bin')); }), '/^THROWN RuntimeException: File cannot be written: x\.bin/');

// Closing
checkPregMatch('a closed source refuses to be read', runCatchThrown(function(){
	$byteFlx = Fluxd::openBytesFluxd('abc');
	$byteFlx->shutFluxSource();
	return $byteFlx->getChunkString();
}), '/^THROWN RuntimeException: The source is closed/');
checkIdentical('closing twice does nothing', runCatchThrown(function(){
	$byteFlx = Fluxd::openBytesFluxd('abc');
	$byteFlx->shutFluxSource();
	$byteFlx->shutFluxSource();
	return true;
}), true);
checkIdentical('a temporary file is removed when the source is dropped', runCatchThrown(function(){
	$byteFlx = Fluxd::openBytesFluxd('abc');
	$savePth = $byteFlx->setLocalString();
	unset($byteFlx);
	return is_file($savePth);
}), false);

// The body of the request
FakeInput::plugFakeStream();
FakeInput::$bodyString = '{"title":"Alpha Night","seats":120}';
checkIdentical('request body: measured, read, and read again', runCatchThrown(function(){
	$bodyFlx = Fluxd::openInputFluxd();
	return Array($bodyFlx->getSourceBytes(), $bodyFlx->getChunkString(8), $bodyFlx->getWholeString(), $bodyFlx->isFluxFinished());
}), Array(35, '{"title"', '{"title":"Alpha Night","seats":120}', true));
FakeInput::$bodyString = '';
checkIdentical('request body: empty', runCatchThrown(function(){
	$bodyFlx = Fluxd::openInputFluxd();
	return Array($bodyFlx->getSourceBytes(), $bodyFlx->getWholeString(), $bodyFlx->getChunkString());
}), Array(0, '', false));

finishChecking();
