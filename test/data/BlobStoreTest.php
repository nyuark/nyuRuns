<?php

// Binary values through the SQL store, on a real SQLite database: written
// from a flux, read back as one, and left out of what does not name them.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Binary values in a store', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBCon;
use nyu\Data\DBRec;
use nyu\Data\DBStore\SqlStore;
use nyu\Data\Fluxd;
use nyu\Data\Image;

$basePth = inTempDir('blobs.sqlite');
if( file_exists($basePth) ){
	unlink($basePth);
}
$pdoLink = new PDO('sqlite:'.$basePth);
$pdoLink->exec("CREATE TABLE albums (id_album INTEGER PRIMARY KEY, albmName TEXT, coverPic BLOB)");
$pdoLink->exec("CREATE TABLE tracks (id_track INTEGER PRIMARY KEY, trckName TEXT, albumKey INTEGER, fileData LONGBLOB, fileNote TEXT)");
$pdoLink = null;
useSqlite($basePth);

$dbHandl = DBCon::linkNamed()->getConnex();
$dbStore = new SqlStore($dbHandl);

// Bytes no text could hold, and a real picture
$rawBlob = "\x00\x01\xFF\xFE\x89PNG\r\n\x00binary\x00end\xC3\x28";
$dotPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

/**
 * Gives what a value read from a store is: the bytes of a flux, or the value.
 *
 * @param mixed $givenValue The value.
 * @return mixed 'FLUXD ' followed by its bytes in hexadecimal, or the value as it is.
 */
function blobSeen( Mixed $givenValue ): Mixed {
	return ($givenValue instanceof Fluxd) ? 'FLUXD '.bin2hex($givenValue->readWhole()) : $givenValue;
}

// What the database tells of its tables
checkSame('the binary fields of a table', Array($dbStore->tableInfo('albums'), $dbStore->tableInfo('tracks')['blob']), Array(Array('flds'=>Array('id_album', 'albmName', 'coverPic'), 'pkey'=>'id_album', 'blob'=>Array('coverPic')), Array('fileData')));

// Writing
$albmKey = $dbStore->insertRow('albums', Array('albmName'=>'First', 'coverPic'=>Fluxd::openBytes($dotPng)));
$trckKey = $dbStore->insertRow('tracks', Array('trckName'=>'One', 'albumKey'=>$albmKey, 'fileData'=>Fluxd::openBytes($rawBlob), 'fileNote'=>'a note'));
$dbStore->insertRow('tracks', Array('trckName'=>'Two', 'albumKey'=>$albmKey, 'fileData'=>null, 'fileNote'=>null));
checkSame('a row written with a binary value', Array($albmKey, $trckKey, $dbStore->lastError()), Array('1', '1', false));
$dbHandl->sendQuery('SELECT typeof(fileData) AS kind, length(fileData) AS size, hex(fileData) AS hexa FROM tracks WHERE id_track=1');
checkSame('the value is stored as binary, byte for byte', $dbHandl->readQuery(), Array('kind'=>'blob', 'size'=>strlen($rawBlob), 'hexa'=>strtoupper(bin2hex($rawBlob))));

// Reading
checkSame('a reading that names no field leaves the binary ones out', $dbStore->findItems('tracks', Array('id_track'=>1)), Array(Array('id_track'=>1, 'trckName'=>'One', 'albumKey'=>1, 'fileNote'=>'a note')));
$fndRows = $dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('trckName', 'fileData')));
checkSame('a binary field named is read as a flux', Array($fndRows[0]['trckName'], blobSeen($fndRows[0]['fileData'])), Array('One', 'FLUXD '.bin2hex($rawBlob)));
$fndRows = $dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array(Array('field'=>'tracks.fileData', 'alias'=>'sound'))));
checkSame('under another name, and with the name of its table', Array(array_keys($fndRows[0]), blobSeen($fndRows[0]['sound'])), Array(Array('sound'), 'FLUXD '.bin2hex($rawBlob)));
checkSame('a binary field holding nothing is read as null', $dbStore->findItems('tracks', Array('id_track'=>2), Array('field'=>Array('fileData', 'fileNote'))), Array(Array('fileData'=>null, 'fileNote'=>null)));
checkSame('a text field is never read as a flux', $dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileNote'))), Array(Array('fileNote'=>'a note')));
checkLike('a binary value larger than accepted is refused', runSafely(function() use ($dbStore){ return $dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileData'), 'bytes'=>10)); }), '/^THROWN RuntimeException: Binary field larger than the 10 bytes allowed: fileData/');
checkSame('the size accepted can be set to what is needed', blobSeen($dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileData'), 'bytes'=>strlen($rawBlob)))[0]['fileData']), 'FLUXD '.bin2hex($rawBlob));
checkSame('functions still count the rows', $dbStore->findItems('tracks', Array(), Array('funcs'=>Array(Array('funct'=>'count', 'field'=>'*', 'alias'=>'nb')))), Array(Array('nb'=>2)));
checkSame('a joined reading leaves the binary fields of both tables out', $dbStore->findItems('tracks', Array('tracks.id_track'=>1), Array('joins'=>Array('albums', 'tracks.albumKey', 'albums.id_album'))), Array(Array('id_track'=>1, 'trckName'=>'One', 'albumKey'=>1, 'fileNote'=>'a note', 'id_album'=>1, 'albmName'=>'First')));
$fndRows = $dbStore->findItems('tracks', Array('tracks.id_track'=>1), Array('field'=>Array('tracks.trckName', 'albums.coverPic'), 'joins'=>Array('albums', 'tracks.albumKey', 'albums.id_album')));
checkSame('a binary field of a joined table, named', Array($fndRows[0]['trckName'], blobSeen($fndRows[0]['coverPic'])), Array('One', 'FLUXD '.bin2hex($dotPng)));
checkSame('a table without binary fields is read as before', (new SqlStore($dbHandl))->findItems('albums', Array(), Array('field'=>Array('albmName'))), Array(Array('albmName'=>'First')));

// A picture, from the database to what is made of it
$covrFlx = $dbStore->findItems('albums', Array('id_album'=>1), Array('field'=>Array('coverPic')))[0]['coverPic'];
checkSame('a picture read from a store is opened as any picture', Array(Image::openFluxd($covrFlx)->imageInfo()['kinds'], Image::openFluxd($covrFlx)->imageInfo()['width']), Array('png', 1));
$savePth = inTempDir('blob-cover.png');
checkSame('and written to a file', Array(basename($covrFlx->saveLocal($savePth)), file_get_contents($savePth)===$dotPng), Array('blob-cover.png', true));
checkSame('a file is written to a store from where it lies', Array($dbStore->updateRow('tracks', Array('id_track'=>2), Array('fileData'=>Fluxd::openLocal($savePth))), blobSeen($dbStore->findItems('tracks', Array('id_track'=>2), Array('field'=>Array('fileData')))[0]['fileData'])), Array(1, 'FLUXD '.bin2hex($dotPng)));
unlink($savePth);

// Changing
checkSame('a binary value is changed', Array($dbStore->updateRow('tracks', Array('id_track'=>1), Array('fileData'=>Fluxd::openBytes('new bytes'), 'fileNote'=>'changed')), blobSeen($dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileData', 'fileNote')))[0]['fileData'])), Array(1, 'FLUXD '.bin2hex('new bytes')));
$usedFlx = Fluxd::openBytes('read once already');
$usedFlx->readWhole();
checkSame('a flux already read is written from its start', Array($dbStore->updateRow('tracks', Array('id_track'=>1), Array('fileData'=>$usedFlx)), blobSeen($dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))[0]['fileData'])), Array(1, 'FLUXD '.bin2hex('read once already')));
checkSame('a binary value is emptied', Array($dbStore->updateRow('tracks', Array('id_track'=>1), Array('fileData'=>null)), $dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))), Array(1, Array(Array('fileData'=>null))));

// A record: what it does not read, it does not write
$dbStore->updateRow('tracks', Array('id_track'=>1), Array('fileData'=>Fluxd::openBytes($rawBlob)));
$trckRec = new DBRec('tracks');
checkSame('a record is loaded without its binary fields', $trckRec->loadRecrd('1'), Array('id_track'=>1, 'trckName'=>'One', 'albumKey'=>1, 'fileNote'=>'changed'));
$trckRec->bindArray(Array('trckName'=>'One, renamed'));
checkSame('saving it leaves its binary value as it was', Array($trckRec->saveRecrd(), $dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('trckName')))[0]['trckName'], blobSeen($dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))[0]['fileData'])), Array('1', 'One, renamed', 'FLUXD '.bin2hex($rawBlob)));
$trckRec->bindArray(Array('fileData'=>Fluxd::openBytes($dotPng)));
checkSame('a binary value bound to a record is saved with it', Array($trckRec->saveRecrd(), blobSeen($dbStore->findItems('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))[0]['fileData'])), Array('1', 'FLUXD '.bin2hex($dotPng)));
$trckRec = new DBRec('tracks');
$trckRec->bindArray(Array('trckName'=>'Three', 'albumKey'=>1, 'fileData'=>Fluxd::openBytes($rawBlob)));
$newKey = $trckRec->saveRecrd();
checkSame('a new record with a binary value', Array($newKey, blobSeen($dbStore->findItems('tracks', Array('id_track'=>$newKey), Array('field'=>Array('fileData')))[0]['fileData'])), Array('3', 'FLUXD '.bin2hex($rawBlob)));

$trckRec = null;
$covrFlx = null;
$fndRows = null;
$dbStore = null;
$dbHandl->exitDBase();

endChecks();
