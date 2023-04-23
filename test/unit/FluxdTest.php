<?php

// Data\Fluxd : a source read piece by piece, from a file, from bytes in
// memory and from the body of the request.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/FakeInput.php';

use nyu\Data\Fluxd;

$lttrPth = TST_DIR.DS.'fixtures'.DS.'letters.txt';
$fixtDir = TST_DIR.DS.'fixtures';

// A file of the server
checkSame('file: size, and not at the end', runSafely(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocal($lttrPth);
	return Array($fileFlx->totalSize(), $fileFlx->endOfFlux());
}), Array(26, false));
checkSame('file: read piece by piece until the end', runSafely(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocal($lttrPth);
	$chnkLst = Array();
	while( ($chnkTxt = $fileFlx->readChunk(10))!==false ){
		$chnkLst[] = $chnkTxt;
	}
	return Array($chnkLst, $fileFlx->endOfFlux(), $fileFlx->readChunk(10));
}), Array(Array('abcdefghij', 'klmnopqrst', 'uvwxyz'), true, false));
checkSame('file: the end is known as soon as the last byte is read', runSafely(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocal($lttrPth);
	$fileFlx->readChunk(26);
	return $fileFlx->endOfFlux();
}), true);
checkSame('file: a range, both ends included, then the reading goes on', runSafely(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocal($lttrPth);
	return Array($fileFlx->readRange(0, 4), $fileFlx->readChunk(3), $fileFlx->readRange(23, 99), $fileFlx->readRange(5, 5));
}), Array('abcde', 'fgh', 'xyz', 'f'));
checkSame('file: back to the start', runSafely(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocal($lttrPth);
	$fileFlx->readChunk(20);
	$fileFlx->goToStart();
	return $fileFlx->readChunk(3);
}), 'abc');
checkSame('file: the whole content, from the start wherever the reading was', runSafely(function() use ($lttrPth){
	$fileFlx = Fluxd::openLocal($lttrPth);
	$fileFlx->readChunk(20);
	return Array($fileFlx->readWhole(), $fileFlx->readWhole(26));
}), Array('abcdefghijklmnopqrstuvwxyz', 'abcdefghijklmnopqrstuvwxyz'));
checkLike('file: a content larger than the limit is refused', runSafely(function() use ($lttrPth){ return Fluxd::openLocal($lttrPth)->readWhole(25); }), '/^THROWN RuntimeException: Content larger than the 25 bytes allowed/');
checkSame('file: as a local file, it is its own path, and nothing is copied', runSafely(function() use ($lttrPth){
	return Fluxd::openLocal($lttrPth)->saveLocal()===realpath($lttrPth);
}), true);

// Refusals
checkLike('a missing file is refused, without its full path', runSafely(function(){ return Fluxd::openLocal(inTempDir('nowhere.txt')); }), '/^THROWN RuntimeException: File not found: nowhere\.txt/');
checkLike('a folder is not a file', runSafely(function() use ($fixtDir){ return Fluxd::openLocal($fixtDir); }), '/^THROWN RuntimeException: File not found/');
checkSame('a file inside the base folder is accepted', runSafely(function() use ($lttrPth, $fixtDir){ return Fluxd::openLocal($lttrPth, $fixtDir)->totalSize(); }), 26);
checkLike('a path climbing out of the base folder is refused', runSafely(function() use ($fixtDir){
	return Fluxd::openLocal($fixtDir.DS.'conf'.DS.'..'.DS.'..'.DS.'README.md', $fixtDir.DS.'conf');
}), '/^THROWN RuntimeException: File outside the allowed folder: README\.md/');
checkLike('a folder whose name only starts like the base folder is outside it', runSafely(function() use ($fixtDir){
	return Fluxd::openLocal($fixtDir.DS.'confbad'.DS.'system.ini', $fixtDir.DS.'conf');
}), '/^THROWN RuntimeException: File outside the allowed folder/');
checkLike('a range that ends before it starts is refused', runSafely(function() use ($lttrPth){ return Fluxd::openLocal($lttrPth)->readRange(5, 4); }), '/^THROWN RuntimeException: Invalid byte range: 5-4/');
checkLike('a piece of no byte is refused', runSafely(function() use ($lttrPth){ return Fluxd::openLocal($lttrPth)->readChunk(0); }), '/^THROWN RuntimeException: A piece holds at least one byte/');

// Bytes in memory
checkSame('bytes: lines, whatever their ending', runSafely(function(){
	$byteFlx = Fluxd::openBytes("first\nsecond\r\n\nfourth");
	$lineLst = Array();
	while( ($lineTxt = $byteFlx->readALine())!==false ){
		$lineLst[] = $lineTxt;
	}
	return Array($lineLst, $byteFlx->totalSize(), $byteFlx->endOfFlux());
}), Array(Array('first', 'second', '', 'fourth'), 21, true));
checkSame('bytes: binary content is kept byte for byte', runSafely(function(){
	$rawBlob = "\x00\x01\xFF\x89PNG\r\n";
	$byteFlx = Fluxd::openBytes($rawBlob);
	return Array($byteFlx->readWhole()===$rawBlob, $byteFlx->totalSize());
}), Array(true, 9));
checkSame('bytes: an empty source is at its end at once', runSafely(function(){
	$byteFlx = Fluxd::openBytes('');
	return Array($byteFlx->totalSize(), $byteFlx->endOfFlux(), $byteFlx->readChunk(), $byteFlx->readALine(), $byteFlx->readWhole());
}), Array(0, true, false, false, ''));
checkSame('bytes: saved to a temporary file, the position kept, the file removed on closing', runSafely(function(){
	$byteFlx = Fluxd::openBytes('blob of a table');
	$byteFlx->readChunk(5);
	$savePth = $byteFlx->saveLocal();
	$readOut = Array(is_file($savePth), file_get_contents($savePth), $byteFlx->readChunk(3));
	$byteFlx->closeFlux();
	return array_merge($readOut, Array(is_file($savePth)));
}), Array(true, 'blob of a table', 'of ', false));
checkSame('bytes: saved to a chosen path, which is kept after closing', runSafely(function(){
	$savePth = inTempDir('kept.bin');
	$byteFlx = Fluxd::openBytes('kept content');
	$gotPath = $byteFlx->saveLocal($savePth);
	$byteFlx->closeFlux();
	return Array($gotPath===$savePth, file_get_contents($savePth));
}), Array(true, 'kept content'));
checkLike('a path that cannot be written is refused', runSafely(function(){ return Fluxd::openBytes('x')->saveLocal(inTempDir('no'.DS.'such'.DS.'folder'.DS.'x.bin')); }), '/^THROWN RuntimeException: File cannot be written: x\.bin/');

// Closing
checkLike('a closed source refuses to be read', runSafely(function(){
	$byteFlx = Fluxd::openBytes('abc');
	$byteFlx->closeFlux();
	return $byteFlx->readChunk();
}), '/^THROWN RuntimeException: The source is closed/');
checkSame('closing twice does nothing', runSafely(function(){
	$byteFlx = Fluxd::openBytes('abc');
	$byteFlx->closeFlux();
	$byteFlx->closeFlux();
	return true;
}), true);
checkSame('a temporary file is removed when the source is dropped', runSafely(function(){
	$byteFlx = Fluxd::openBytes('abc');
	$savePth = $byteFlx->saveLocal();
	unset($byteFlx);
	return is_file($savePth);
}), false);

// The body of the request
FakeInput::plugInput();
FakeInput::$bodyText = '{"title":"Alpha Night","seats":120}';
checkSame('request body: measured, read, and read again', runSafely(function(){
	$bodyFlx = Fluxd::openInput();
	return Array($bodyFlx->totalSize(), $bodyFlx->readChunk(8), $bodyFlx->readWhole(), $bodyFlx->endOfFlux());
}), Array(35, '{"title"', '{"title":"Alpha Night","seats":120}', true));
FakeInput::$bodyText = '';
checkSame('request body: empty', runSafely(function(){
	$bodyFlx = Fluxd::openInput();
	return Array($bodyFlx->totalSize(), $bodyFlx->readWhole(), $bodyFlx->readChunk());
}), Array(0, '', false));

endChecks();
