<?php

// Data\Archv : zip archives read entry by entry, and Data\Parsers\Pack,
// what the parsers of zipped documents share.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Archv;
use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Pack;

if( !class_exists('ZipArchive') ){
	recordSkipping('archives', 'the zip extension is not loaded');
	finishChecking();
}

/**
 * Builds a zip archive in the temporary folder.
 *
 * @param String $archivName Name of the file to make.
 * @param Array  $entryTexts Its entries, name => content.
 * @return String The path of the archive.
 */
function makeZip( String $archivName, Array $entryTexts ): String {
	$zipPath = inTemporaryDir($archivName);
	$zipArch = new ZipArchive();
	$zipArch->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	foreach( $entryTexts as $entNme=>$entTxt ){
		$zipArch->addFromString(strval($entNme), $entTxt);
	}
	$zipArch->close();
	return $zipPath;
}

// A parser of zipped documents, as small as one can be: it gives the tree
// of the part named by the 'parts' option, and what the helpers find in it
class TestPack extends Pack {
	const SHPE_OUTS = 'sheet';
	protected function readArchvArray( Array $parseOptns ): Array {
		$rootArr = $this->partNodesArray(strval($parseOptns['parts'] ?? 'main.xml'), empty($parseOptns['maybe']));
		if( $rootArr===null ){
			return $this->makeShapeArray('sheet', Array(), Array('found'=>false));
		}
		return $this->makeShapeArray('sheet', Array(
			'roots'=>$rootArr['tagnm'],
			'named'=>array_column($this->kidsNamedArray($rootArr, 'w:p'), 'tagid'),
			'child'=>$this->findChildArray($rootArr, Array('w:p', 'w:r', 'w:t'))['value'] ?? null,
			'lost'=>$this->findChildArray($rootArr, Array('w:p', 'w:x', 'w:t')),
			'texts'=>$this->collectAllText($rootArr)
		), Array('found'=>true));
	}
}

$showZip = makeZip('show.zip', Array('mimetype'=>'application/x-test', 'word/document.xml'=>'<a>Été</a>', 'word/media/'=>'', 'word/media/pic.bin'=>str_repeat("\x00", 5000), 'empty.txt'=>''));

// Opening and listing
checkIdentical('the entries of an archive, with their size once unpacked, folders left out', runCatchThrown(function() use ($showZip){
	$archObj = Archv::openFluxdArchv(Fluxd::openLocalFluxd($showZip));
	return $archObj->listEntryNames();
}), Array('mimetype'=>18, 'word/document.xml'=>12, 'word/media/pic.bin'=>5000, 'empty.txt'=>0));
checkIdentical('finding an entry: its whole name, case included', runCatchThrown(function() use ($showZip){
	$archObj = Archv::openFluxdArchv(Fluxd::openLocalFluxd($showZip));
	return Array($archObj->locateEntryFlg('word/document.xml'), $archObj->locateEntryFlg('document.xml'), $archObj->locateEntryFlg('Word/Document.xml'), $archObj->locateEntryFlg('word/media/'), $archObj->locateEntryFlg(''));
}), Array(true, false, false, false, false));

// Reading
checkIdentical('reading entries: text, bytes, and one that is empty', runCatchThrown(function() use ($showZip){
	$archObj = Archv::openFluxdArchv(Fluxd::openLocalFluxd($showZip));
	return Array($archObj->getEntryString('word/document.xml'), strlen($archObj->getEntryString('word/media/pic.bin')), $archObj->getEntryString('empty.txt'), $archObj->getEntryString('mimetype'));
}), Array('<a>Été</a>', 5000, '', 'application/x-test'));
checkPregMatch('an entry that is not there', runCatchThrown(function() use ($showZip){ return Archv::openFluxdArchv(Fluxd::openLocalFluxd($showZip))->getEntryString('nowhere.xml'); }), '/^THROWN RuntimeException: Entry not found in the archive: nowhere\.xml/');
checkIdentical('an entry within the limit, and at the limit', runCatchThrown(function() use ($showZip){
	$archObj = Archv::openFluxdArchv(Fluxd::openLocalFluxd($showZip));
	return Array(strlen($archObj->getEntryString('word/media/pic.bin', 5000)), $archObj->getEntryString('word/document.xml', 12));
}), Array(5000, '<a>Été</a>'));
checkPregMatch('an entry larger than the limit is refused, not loaded', runCatchThrown(function() use ($showZip){ return Archv::openFluxdArchv(Fluxd::openLocalFluxd($showZip))->getEntryString('word/media/pic.bin', 4999); }), '/^THROWN RuntimeException: Entry larger than the 4999 bytes allowed: word\/media\/pic\.bin/');
checkPregMatch('an entry that unpacks to far more than it weighs is refused the same way', runCatchThrown(function(){
	$bombZip = makeZip('bomb.zip', Array('big.xml'=>str_repeat('A', 4000000)));
	return Array(filesize($bombZip)<20000, Archv::openFluxdArchv(Fluxd::openLocalFluxd($bombZip))->getEntryString('big.xml', 1000000));
}), '/^THROWN RuntimeException: Entry larger than the 1000000 bytes allowed: big\.xml/');

// Sources
checkIdentical('an archive given as bytes: a temporary file is made, and deleted with the source', runCatchThrown(function() use ($showZip){
	$byteFlx = Fluxd::openBytesFluxd(file_get_contents($showZip));
	$archObj = Archv::openFluxdArchv($byteFlx);
	$tempPth = $byteFlx->setLocalString();
	$entText = $archObj->getEntryString('word/document.xml');
	$wasFile = is_file($tempPth);
	$archObj->releaseArchive();
	$byteFlx->closeTheSource();
	return Array($entText, $wasFile, is_file($tempPth), $tempPth!==$showZip);
}), Array('<a>Été</a>', true, false, true));
checkIdentical('closing: twice does nothing, the source stays open, the archive answers no more', runCatchThrown(function() use ($showZip){
	$fileFlx = Fluxd::openLocalFluxd($showZip);
	$archObj = Archv::openFluxdArchv($fileFlx);
	$archObj->releaseArchive();
	$archObj->releaseArchive();
	return Array($fileFlx->getRangeString(0, 1), preg_replace('/ @.*$/', '', runCatchThrown(function() use ($archObj){ return $archObj->listEntryNames(); })), preg_replace('/ @.*$/', '', runCatchThrown(function() use ($archObj){ return $archObj->getEntryString('mimetype'); })));
}), Array('PK', 'THROWN RuntimeException: The archive is closed', 'THROWN RuntimeException: The archive is closed'));
checkIdentical('the file is free again once the archive is closed', runCatchThrown(function(){
	$freeZip = makeZip('free.zip', Array('a.txt'=>'a'));
	$fileFlx = Fluxd::openLocalFluxd($freeZip);
	$archObj = Archv::openFluxdArchv($fileFlx);
	$archObj->releaseArchive();
	$fileFlx->closeTheSource();
	return @unlink($freeZip);
}), true);

// What is refused
checkPregMatch('a content that is not an archive', runCatchThrown(function(){ return Archv::openFluxdArchv(Fluxd::openBytesFluxd('<a>not a zip</a>')); }), '/^THROWN RuntimeException: Not a zip archive/');
checkPregMatch('an empty content', runCatchThrown(function(){ return Archv::openFluxdArchv(Fluxd::openBytesFluxd('')); }), '/^THROWN RuntimeException: Not a zip archive/');
checkPregMatch('an archive cut short', runCatchThrown(function() use ($showZip){ return Archv::openFluxdArchv(Fluxd::openBytesFluxd(substr(file_get_contents($showZip), 0, 60))); }), '/^THROWN RuntimeException: Not a zip archive/');
checkPregMatch('more entries than allowed', runCatchThrown(function() use ($showZip){ return Archv::openFluxdArchv(Fluxd::openLocalFluxd($showZip), 3); }), '/^THROWN RuntimeException: Archive holding more than the 3 entries allowed/');
foreach( Array('../evil.txt', 'a/../../evil.txt', '/etc/passwd', 'C:/evil.txt', 'a\\b.txt') as $evilNme ){
	checkPregMatch('an entry whose name leaves the archive: '.$evilNme, runCatchThrown(function() use ($evilNme){
		return Archv::openFluxdArchv(Fluxd::openLocalFluxd(makeZip('evil.zip', Array('ok.txt'=>'ok', $evilNme=>'x'))));
	}), '/^THROWN RuntimeException: Archive holding an entry whose name is not safe/');
}
checkIdentical('dots that leave nothing are fine', runCatchThrown(function(){
	return array_keys(Archv::openFluxdArchv(Fluxd::openLocalFluxd(makeZip('dots.zip', Array('a..b.txt'=>'x', '.hidden'=>'y', 'a/.b/c..'=>'z'))))->listEntryNames());
}), Array('a..b.txt', '.hidden', 'a/.b/c..'));
$lockZip = inTemporaryDir('lock.zip');
$lockArc = new ZipArchive();
$lockArc->open($lockZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$lockArc->addFromString('secret.txt', 'hidden');
$lockArc->addFromString('open.txt', 'shown');
$lockOky = method_exists($lockArc, 'setEncryptionName') && @$lockArc->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, 'pass');
$lockArc->close();
if( $lockOky ){
	checkIdentical('an entry protected by a password is refused; the others are read', runCatchThrown(function() use ($lockZip){
		$archObj = Archv::openFluxdArchv(Fluxd::openLocalFluxd($lockZip));
		return Array($archObj->getEntryString('open.txt'), preg_replace('/ @.*$/', '', runCatchThrown(function() use ($archObj){ return $archObj->getEntryString('secret.txt'); })));
	}), Array('shown', 'THROWN RuntimeException: Entry protected by a password: secret.txt'));
}else{
	recordSkipping('an entry protected by a password', 'this zip extension cannot make one');
}

// The base of the parsers of zipped documents
$wordXml = '<w:document xmlns:w="urn:w"><w:p xml:id="p1"><w:r><w:t>Alpha </w:t></w:r><w:r><w:t>Night</w:t></w:r></w:p><w:tbl/><w:p xml:id="p2"><w:r><w:t>Été</w:t></w:r></w:p></w:document>';
$packZip = makeZip('pack.zip', Array('main.xml'=>$wordXml, 'other.xml'=>'<a>other</a>', 'broken.xml'=>'<a><b></a>', 'entity.xml'=>'<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>', 'empty.xml'=>''));
checkIdentical('a part read into its tree, and the helpers to walk it', runCatchThrown(function() use ($packZip){ return (new TestPack())->parseFluxArray(Fluxd::openLocalFluxd($packZip)); }), Array(
	'shape'=>'sheet', 'infos'=>Array('found'=>true),
	'items'=>Array('roots'=>'w:document', 'named'=>Array('p1', 'p2'), 'child'=>'Alpha ', 'lost'=>null, 'texts'=>'Alpha NightÉté')
));
checkIdentical('the same from bytes, and an empty content gives an empty result of the shape of the parser', Array(
	runCatchThrown(function() use ($packZip){ return (new TestPack())->parseDataArray(file_get_contents($packZip), Array('parts'=>'other.xml'))['items']['texts']; }),
	runCatchThrown(function(){ return (new TestPack())->parseDataArray(''); })
), Array('other', Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array())));
checkPregMatch('a required part that is missing', runCatchThrown(function() use ($packZip){ return (new TestPack())->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('parts'=>'nowhere.xml')); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part nowhere\.xml is missing/');
checkIdentical('a part that may be missing, or is empty', Array(
	runCatchThrown(function() use ($packZip){ return (new TestPack())->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('parts'=>'nowhere.xml', 'maybe'=>true))['infos']; }),
	runCatchThrown(function() use ($packZip){ return (new TestPack())->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('parts'=>'empty.xml'))['infos']; })
), Array(Array('found'=>false), Array('found'=>false)));
checkPregMatch('a part that is not valid XML is refused, and named', runCatchThrown(function() use ($packZip){ return (new TestPack())->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('parts'=>'broken.xml')); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part broken\.xml: Invalid XML content: .* on line 1/');
checkPregMatch('a part declaring entities is refused', runCatchThrown(function() use ($packZip){ return (new TestPack())->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('parts'=>'entity.xml')); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part entity\.xml: Invalid XML content: entity declarations are not accepted/');
checkPregMatch('a part larger than the limit option', runCatchThrown(function() use ($packZip){ return (new TestPack())->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('limit'=>50)); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: Entry larger than the 50 bytes allowed: main\.xml/');
checkPregMatch('a content that is not an archive', runCatchThrown(function(){ return (new TestPack())->parseDataArray('<a>text</a>'); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: Not a zip archive/');
checkIdentical('the archive is released after a refusal: its file can be deleted', runCatchThrown(function(){
	$gonePth = makeZip('gone.zip', Array('main.xml'=>'<a><b></a>'));
	$prsrObj = new TestPack();
	$fileFlx = Fluxd::openLocalFluxd($gonePth);
	try{ $prsrObj->parseFluxArray($fileFlx); }catch( Throwable $e ){}
	$fileFlx->closeTheSource();
	return @unlink($gonePth);
}), true);
checkIdentical('one parser, several archives', runCatchThrown(function() use ($packZip){
	$prsrObj = new TestPack();
	try{ $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('parts'=>'broken.xml')); }catch( Throwable $e ){}
	return $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($packZip), Array('parts'=>'other.xml'))['items']['roots'];
}), 'a');

// Through Dcmnt: an archive is accepted where a parser of archives reads it, and only there
Index::registerParser('tpk', '\TestPack');
checkIdentical('a zipped document through Dcmnt', runCatchThrown(function() use ($packZip){
	$docPath = inTemporaryDir('show.tpk');
	copy($packZip, $docPath);
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail()['roots']);
}), Array('zip', 'sheet', 'w:document'));
checkPregMatch('text under the name of a zipped document is refused', runCatchThrown(function(){
	$docPath = inTemporaryDir('text.tpk');
	file_put_contents($docPath, '<?xml version="1.0"?><a/>');
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: text\.tpk: the content is not what the name announces/');
checkPregMatch('an archive under the name of a text file is still refused', runCatchThrown(function() use ($packZip){
	$docPath = inTemporaryDir('archive.json');
	copy($packZip, $docPath);
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: archive\.json: the content is not what the name announces/');
checkIdentical('no PHP warning along the way', takePhpNotices(), Array());

finishChecking();
