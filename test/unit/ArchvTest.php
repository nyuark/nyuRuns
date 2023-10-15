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
	skipCheck('archives', 'the zip extension is not loaded');
	endChecks();
}

/**
 * Builds a zip archive in the temporary folder.
 *
 * @param String $archivName Name of the file to make.
 * @param Array  $entryTexts Its entries, name => content.
 * @return String The path of the archive.
 */
function makeZip( String $archivName, Array $entryTexts ): String {
	$zipPath = inTempDir($archivName);
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
	const SHAPEOUT = 'sheet';
	protected function readArchv( Array $parseOptns ): Array {
		$rootArr = $this->partNodes(strval($parseOptns['parts'] ?? 'main.xml'), empty($parseOptns['maybe']));
		if( $rootArr===null ){
			return $this->makeShape('sheet', Array(), Array('found'=>false));
		}
		return $this->makeShape('sheet', Array(
			'roots'=>$rootArr['tagnm'],
			'named'=>array_column($this->kidsNamed($rootArr, 'w:p'), 'tagid'),
			'child'=>$this->findChild($rootArr, Array('w:p', 'w:r', 'w:t'))['value'] ?? null,
			'lost'=>$this->findChild($rootArr, Array('w:p', 'w:x', 'w:t')),
			'texts'=>$this->textOfTag($rootArr)
		), Array('found'=>true));
	}
}

$showZip = makeZip('show.zip', Array('mimetype'=>'application/x-test', 'word/document.xml'=>'<a>Été</a>', 'word/media/'=>'', 'word/media/pic.bin'=>str_repeat("\x00", 5000), 'empty.txt'=>''));

// Opening and listing
checkSame('the entries of an archive, with their size once unpacked, folders left out', runSafely(function() use ($showZip){
	$archObj = Archv::openFluxd(Fluxd::openLocal($showZip));
	return $archObj->entryList();
}), Array('mimetype'=>18, 'word/document.xml'=>12, 'word/media/pic.bin'=>5000, 'empty.txt'=>0));
checkSame('finding an entry: its whole name, case included', runSafely(function() use ($showZip){
	$archObj = Archv::openFluxd(Fluxd::openLocal($showZip));
	return Array($archObj->findEntry('word/document.xml'), $archObj->findEntry('document.xml'), $archObj->findEntry('Word/Document.xml'), $archObj->findEntry('word/media/'), $archObj->findEntry(''));
}), Array(true, false, false, false, false));

// Reading
checkSame('reading entries: text, bytes, and one that is empty', runSafely(function() use ($showZip){
	$archObj = Archv::openFluxd(Fluxd::openLocal($showZip));
	return Array($archObj->readEntry('word/document.xml'), strlen($archObj->readEntry('word/media/pic.bin')), $archObj->readEntry('empty.txt'), $archObj->readEntry('mimetype'));
}), Array('<a>Été</a>', 5000, '', 'application/x-test'));
checkLike('an entry that is not there', runSafely(function() use ($showZip){ return Archv::openFluxd(Fluxd::openLocal($showZip))->readEntry('nowhere.xml'); }), '/^THROWN RuntimeException: Entry not found in the archive: nowhere\.xml/');
checkSame('an entry within the limit, and at the limit', runSafely(function() use ($showZip){
	$archObj = Archv::openFluxd(Fluxd::openLocal($showZip));
	return Array(strlen($archObj->readEntry('word/media/pic.bin', 5000)), $archObj->readEntry('word/document.xml', 12));
}), Array(5000, '<a>Été</a>'));
checkLike('an entry larger than the limit is refused, not loaded', runSafely(function() use ($showZip){ return Archv::openFluxd(Fluxd::openLocal($showZip))->readEntry('word/media/pic.bin', 4999); }), '/^THROWN RuntimeException: Entry larger than the 4999 bytes allowed: word\/media\/pic\.bin/');
checkLike('an entry that unpacks to far more than it weighs is refused the same way', runSafely(function(){
	$bombZip = makeZip('bomb.zip', Array('big.xml'=>str_repeat('A', 4000000)));
	return Array(filesize($bombZip)<20000, Archv::openFluxd(Fluxd::openLocal($bombZip))->readEntry('big.xml', 1000000));
}), '/^THROWN RuntimeException: Entry larger than the 1000000 bytes allowed: big\.xml/');

// Sources
checkSame('an archive given as bytes: a temporary file is made, and deleted with the source', runSafely(function() use ($showZip){
	$byteFlx = Fluxd::openBytes(file_get_contents($showZip));
	$archObj = Archv::openFluxd($byteFlx);
	$tempPth = $byteFlx->saveLocal();
	$entText = $archObj->readEntry('word/document.xml');
	$wasFile = is_file($tempPth);
	$archObj->closeArch();
	$byteFlx->closeFlux();
	return Array($entText, $wasFile, is_file($tempPth), $tempPth!==$showZip);
}), Array('<a>Été</a>', true, false, true));
checkSame('closing: twice does nothing, the source stays open, the archive answers no more', runSafely(function() use ($showZip){
	$fileFlx = Fluxd::openLocal($showZip);
	$archObj = Archv::openFluxd($fileFlx);
	$archObj->closeArch();
	$archObj->closeArch();
	return Array($fileFlx->readRange(0, 1), preg_replace('/ @.*$/', '', runSafely(function() use ($archObj){ return $archObj->entryList(); })), preg_replace('/ @.*$/', '', runSafely(function() use ($archObj){ return $archObj->readEntry('mimetype'); })));
}), Array('PK', 'THROWN RuntimeException: The archive is closed', 'THROWN RuntimeException: The archive is closed'));
checkSame('the file is free again once the archive is closed', runSafely(function(){
	$freeZip = makeZip('free.zip', Array('a.txt'=>'a'));
	$fileFlx = Fluxd::openLocal($freeZip);
	$archObj = Archv::openFluxd($fileFlx);
	$archObj->closeArch();
	$fileFlx->closeFlux();
	return @unlink($freeZip);
}), true);

// What is refused
checkLike('a content that is not an archive', runSafely(function(){ return Archv::openFluxd(Fluxd::openBytes('<a>not a zip</a>')); }), '/^THROWN RuntimeException: Not a zip archive/');
checkLike('an empty content', runSafely(function(){ return Archv::openFluxd(Fluxd::openBytes('')); }), '/^THROWN RuntimeException: Not a zip archive/');
checkLike('an archive cut short', runSafely(function() use ($showZip){ return Archv::openFluxd(Fluxd::openBytes(substr(file_get_contents($showZip), 0, 60))); }), '/^THROWN RuntimeException: Not a zip archive/');
checkLike('more entries than allowed', runSafely(function() use ($showZip){ return Archv::openFluxd(Fluxd::openLocal($showZip), 3); }), '/^THROWN RuntimeException: Archive holding more than the 3 entries allowed/');
foreach( Array('../evil.txt', 'a/../../evil.txt', '/etc/passwd', 'C:/evil.txt', 'a\\b.txt') as $evilNme ){
	checkLike('an entry whose name leaves the archive: '.$evilNme, runSafely(function() use ($evilNme){
		return Archv::openFluxd(Fluxd::openLocal(makeZip('evil.zip', Array('ok.txt'=>'ok', $evilNme=>'x'))));
	}), '/^THROWN RuntimeException: Archive holding an entry whose name is not safe/');
}
checkSame('dots that leave nothing are fine', runSafely(function(){
	return array_keys(Archv::openFluxd(Fluxd::openLocal(makeZip('dots.zip', Array('a..b.txt'=>'x', '.hidden'=>'y', 'a/.b/c..'=>'z'))))->entryList());
}), Array('a..b.txt', '.hidden', 'a/.b/c..'));
$lockZip = inTempDir('lock.zip');
$lockArc = new ZipArchive();
$lockArc->open($lockZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$lockArc->addFromString('secret.txt', 'hidden');
$lockArc->addFromString('open.txt', 'shown');
$lockOky = method_exists($lockArc, 'setEncryptionName') && @$lockArc->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, 'pass');
$lockArc->close();
if( $lockOky ){
	checkSame('an entry protected by a password is refused; the others are read', runSafely(function() use ($lockZip){
		$archObj = Archv::openFluxd(Fluxd::openLocal($lockZip));
		return Array($archObj->readEntry('open.txt'), preg_replace('/ @.*$/', '', runSafely(function() use ($archObj){ return $archObj->readEntry('secret.txt'); })));
	}), Array('shown', 'THROWN RuntimeException: Entry protected by a password: secret.txt'));
}else{
	skipCheck('an entry protected by a password', 'this zip extension cannot make one');
}

// The base of the parsers of zipped documents
$wordXml = '<w:document xmlns:w="urn:w"><w:p xml:id="p1"><w:r><w:t>Alpha </w:t></w:r><w:r><w:t>Night</w:t></w:r></w:p><w:tbl/><w:p xml:id="p2"><w:r><w:t>Été</w:t></w:r></w:p></w:document>';
$packZip = makeZip('pack.zip', Array('main.xml'=>$wordXml, 'other.xml'=>'<a>other</a>', 'broken.xml'=>'<a><b></a>', 'entity.xml'=>'<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>', 'empty.xml'=>''));
checkSame('a part read into its tree, and the helpers to walk it', runSafely(function() use ($packZip){ return (new TestPack())->parseFlux(Fluxd::openLocal($packZip)); }), Array(
	'shape'=>'sheet', 'infos'=>Array('found'=>true),
	'items'=>Array('roots'=>'w:document', 'named'=>Array('p1', 'p2'), 'child'=>'Alpha ', 'lost'=>null, 'texts'=>'Alpha NightÉté')
));
checkSame('the same from bytes, and an empty content gives an empty result of the shape of the parser', Array(
	runSafely(function() use ($packZip){ return (new TestPack())->parseData(file_get_contents($packZip), Array('parts'=>'other.xml'))['items']['texts']; }),
	runSafely(function(){ return (new TestPack())->parseData(''); })
), Array('other', Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array())));
checkLike('a required part that is missing', runSafely(function() use ($packZip){ return (new TestPack())->parseFlux(Fluxd::openLocal($packZip), Array('parts'=>'nowhere.xml')); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part nowhere\.xml is missing/');
checkSame('a part that may be missing, or is empty', Array(
	runSafely(function() use ($packZip){ return (new TestPack())->parseFlux(Fluxd::openLocal($packZip), Array('parts'=>'nowhere.xml', 'maybe'=>true))['infos']; }),
	runSafely(function() use ($packZip){ return (new TestPack())->parseFlux(Fluxd::openLocal($packZip), Array('parts'=>'empty.xml'))['infos']; })
), Array(Array('found'=>false), Array('found'=>false)));
checkLike('a part that is not valid XML is refused, and named', runSafely(function() use ($packZip){ return (new TestPack())->parseFlux(Fluxd::openLocal($packZip), Array('parts'=>'broken.xml')); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part broken\.xml: Invalid XML content: .* on line 1/');
checkLike('a part declaring entities is refused', runSafely(function() use ($packZip){ return (new TestPack())->parseFlux(Fluxd::openLocal($packZip), Array('parts'=>'entity.xml')); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part entity\.xml: Invalid XML content: entity declarations are not accepted/');
checkLike('a part larger than the limit option', runSafely(function() use ($packZip){ return (new TestPack())->parseFlux(Fluxd::openLocal($packZip), Array('limit'=>50)); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: Entry larger than the 50 bytes allowed: main\.xml/');
checkLike('a content that is not an archive', runSafely(function(){ return (new TestPack())->parseData('<a>text</a>'); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: Not a zip archive/');
checkSame('the archive is released after a refusal: its file can be deleted', runSafely(function(){
	$gonePth = makeZip('gone.zip', Array('main.xml'=>'<a><b></a>'));
	$prsrObj = new TestPack();
	$fileFlx = Fluxd::openLocal($gonePth);
	try{ $prsrObj->parseFlux($fileFlx); }catch( Throwable $e ){}
	$fileFlx->closeFlux();
	return @unlink($gonePth);
}), true);
checkSame('one parser, several archives', runSafely(function() use ($packZip){
	$prsrObj = new TestPack();
	try{ $prsrObj->parseFlux(Fluxd::openLocal($packZip), Array('parts'=>'broken.xml')); }catch( Throwable $e ){}
	return $prsrObj->parseFlux(Fluxd::openLocal($packZip), Array('parts'=>'other.xml'))['items']['roots'];
}), 'a');

// Through Dcmnt: an archive is accepted where a parser of archives reads it, and only there
Index::addParser('tpk', '\TestPack');
checkSame('a zipped document through Dcmnt', runSafely(function() use ($packZip){
	$docPath = inTempDir('show.tpk');
	copy($packZip, $docPath);
	$docFile = new Dcmnt($docPath);
	return Array($docFile->readValue('sig'), $docFile->readValue('shp'), $docFile->readValue()['roots']);
}), Array('zip', 'sheet', 'w:document'));
checkLike('text under the name of a zipped document is refused', runSafely(function(){
	$docPath = inTempDir('text.tpk');
	file_put_contents($docPath, '<?xml version="1.0"?><a/>');
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: text\.tpk: the content is not what the name announces/');
checkLike('an archive under the name of a text file is still refused', runSafely(function() use ($packZip){
	$docPath = inTempDir('archive.json');
	copy($packZip, $docPath);
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: archive\.json: the content is not what the name announces/');
checkSame('no PHP warning along the way', takeNotes(), Array());

endChecks();
