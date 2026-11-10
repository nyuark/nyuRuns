<?php

// Binary values through the SQL store, on a real SQLite database: written
// from a flux, read back as one, and left out of what does not name them.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Binary values in a store', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBCon;
use nyu\Data\DBRec;
use nyu\Data\DBStore\SqlStore;
use nyu\Data\Fluxd;
use nyu\Data\Image;

$basePth = inTemporaryDir('blobs.sqlite');
if( file_exists($basePth) ){
	unlink($basePth);
}
$pdoLink = new PDO('sqlite:'.$basePth);
$pdoLink->exec("CREATE TABLE albums (id_album INTEGER PRIMARY KEY, albmName TEXT, coverPic BLOB)");
$pdoLink->exec("CREATE TABLE tracks (id_track INTEGER PRIMARY KEY, trckName TEXT, albumKey INTEGER, fileData LONGBLOB, fileNote TEXT)");
$pdoLink = null;
switchToSqlite($basePth);

$dbHandl = DBCon::linkNamedDbCon()->readConnection();
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
	return ($givenValue instanceof Fluxd) ? 'FLUXD '.bin2hex($givenValue->getWholeString()) : $givenValue;
}

// What the database tells of its tables
checkIdentical('the binary fields of a table', Array($dbStore->tableInfoArray('albums'), $dbStore->tableInfoArray('tracks')['blob']), Array(Array('flds'=>Array('id_album', 'albmName', 'coverPic'), 'pkey'=>'id_album', 'blob'=>Array('coverPic')), Array('fileData')));

// Writing
$albmKey = $dbStore->insertTableRow('albums', Array('albmName'=>'First', 'coverPic'=>Fluxd::openBytesFluxd($dotPng)));
$trckKey = $dbStore->insertTableRow('tracks', Array('trckName'=>'One', 'albumKey'=>$albmKey, 'fileData'=>Fluxd::openBytesFluxd($rawBlob), 'fileNote'=>'a note'));
$dbStore->insertTableRow('tracks', Array('trckName'=>'Two', 'albumKey'=>$albmKey, 'fileData'=>null, 'fileNote'=>null));
checkIdentical('a row written with a binary value', Array($albmKey, $trckKey, $dbStore->finalErrorText()), Array('1', '1', false));
$dbHandl->sendBuiltQuery('SELECT typeof(fileData) AS kind, length(fileData) AS size, hex(fileData) AS hexa FROM tracks WHERE id_track=1');
checkIdentical('the value is stored as binary, byte for byte', $dbHandl->readLastResult(), Array('kind'=>'blob', 'size'=>strlen($rawBlob), 'hexa'=>strtoupper(bin2hex($rawBlob))));

// Reading
checkIdentical('a reading that names no field leaves the binary ones out', $dbStore->findItemsArray('tracks', Array('id_track'=>1)), Array(Array('id_track'=>1, 'trckName'=>'One', 'albumKey'=>1, 'fileNote'=>'a note')));
$fndRows = $dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('trckName', 'fileData')));
checkIdentical('a binary field named is read as a flux', Array($fndRows[0]['trckName'], blobSeen($fndRows[0]['fileData'])), Array('One', 'FLUXD '.bin2hex($rawBlob)));
$fndRows = $dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array(Array('field'=>'tracks.fileData', 'alias'=>'sound'))));
checkIdentical('under another name, and with the name of its table', Array(array_keys($fndRows[0]), blobSeen($fndRows[0]['sound'])), Array(Array('sound'), 'FLUXD '.bin2hex($rawBlob)));
checkIdentical('a binary field holding nothing is read as null', $dbStore->findItemsArray('tracks', Array('id_track'=>2), Array('field'=>Array('fileData', 'fileNote'))), Array(Array('fileData'=>null, 'fileNote'=>null)));
checkIdentical('a text field is never read as a flux', $dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileNote'))), Array(Array('fileNote'=>'a note')));
checkPregMatch('a binary value larger than accepted is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileData'), 'bytes'=>10)); }), '/^THROWN RuntimeException: Binary field larger than the 10 bytes allowed: fileData/');
checkIdentical('the size accepted can be set to what is needed', blobSeen($dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileData'), 'bytes'=>strlen($rawBlob)))[0]['fileData']), 'FLUXD '.bin2hex($rawBlob));
checkIdentical('functions still count the rows', $dbStore->findItemsArray('tracks', Array(), Array('funcs'=>Array(Array('funct'=>'count', 'field'=>'*', 'alias'=>'nb')))), Array(Array('nb'=>2)));
checkIdentical('a joined reading leaves the binary fields of both tables out', $dbStore->findItemsArray('tracks', Array('tracks.id_track'=>1), Array('joins'=>Array('albums', 'tracks.albumKey', 'albums.id_album'))), Array(Array('id_track'=>1, 'trckName'=>'One', 'albumKey'=>1, 'fileNote'=>'a note', 'id_album'=>1, 'albmName'=>'First')));
$fndRows = $dbStore->findItemsArray('tracks', Array('tracks.id_track'=>1), Array('field'=>Array('tracks.trckName', 'albums.coverPic'), 'joins'=>Array('albums', 'tracks.albumKey', 'albums.id_album')));
checkIdentical('a binary field of a joined table, named', Array($fndRows[0]['trckName'], blobSeen($fndRows[0]['coverPic'])), Array('One', 'FLUXD '.bin2hex($dotPng)));
checkIdentical('a table without binary fields is read as before', (new SqlStore($dbHandl))->findItemsArray('albums', Array(), Array('field'=>Array('albmName'))), Array(Array('albmName'=>'First')));

// A picture, from the database to what is made of it
$covrFlx = $dbStore->findItemsArray('albums', Array('id_album'=>1), Array('field'=>Array('coverPic')))[0]['coverPic'];
checkIdentical('a picture read from a store is opened as any picture', Array(Image::openFluxdImage($covrFlx)->imageInfoArray()['kinds'], Image::openFluxdImage($covrFlx)->imageInfoArray()['width']), Array('png', 1));
$savePth = inTemporaryDir('blob-cover.png');
checkIdentical('and written to a file', Array(basename($covrFlx->setLocalString($savePth)), file_get_contents($savePth)===$dotPng), Array('blob-cover.png', true));
checkIdentical('a file is written to a store from where it lies', Array($dbStore->updateRowCount('tracks', Array('id_track'=>2), Array('fileData'=>Fluxd::openLocalFluxd($savePth))), blobSeen($dbStore->findItemsArray('tracks', Array('id_track'=>2), Array('field'=>Array('fileData')))[0]['fileData'])), Array(1, 'FLUXD '.bin2hex($dotPng)));
unlink($savePth);

// Changing
checkIdentical('a binary value is changed', Array($dbStore->updateRowCount('tracks', Array('id_track'=>1), Array('fileData'=>Fluxd::openBytesFluxd('new bytes'), 'fileNote'=>'changed')), blobSeen($dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileData', 'fileNote')))[0]['fileData'])), Array(1, 'FLUXD '.bin2hex('new bytes')));
$usedFlx = Fluxd::openBytesFluxd('read once already');
$usedFlx->getWholeString();
checkIdentical('a flux already read is written from its start', Array($dbStore->updateRowCount('tracks', Array('id_track'=>1), Array('fileData'=>$usedFlx)), blobSeen($dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))[0]['fileData'])), Array(1, 'FLUXD '.bin2hex('read once already')));
checkIdentical('a binary value is emptied', Array($dbStore->updateRowCount('tracks', Array('id_track'=>1), Array('fileData'=>null)), $dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))), Array(1, Array(Array('fileData'=>null))));

// A record: what it does not read, it does not write
$dbStore->updateRowCount('tracks', Array('id_track'=>1), Array('fileData'=>Fluxd::openBytesFluxd($rawBlob)));
$trckRec = new DBRec('tracks');
checkIdentical('a record is loaded without its binary fields', $trckRec->loadRecordList('1'), Array('id_track'=>1, 'trckName'=>'One', 'albumKey'=>1, 'fileNote'=>'changed'));
$trckRec->bindFieldArray(Array('trckName'=>'One, renamed'));
checkIdentical('saving it leaves its binary value as it was', Array($trckRec->insertOrUpdate(), $dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('trckName')))[0]['trckName'], blobSeen($dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))[0]['fileData'])), Array(1, 'One, renamed', 'FLUXD '.bin2hex($rawBlob)));
$trckRec->bindFieldArray(Array('fileData'=>Fluxd::openBytesFluxd($dotPng)));
checkIdentical('a binary value bound to a record is saved with it', Array($trckRec->insertOrUpdate(), blobSeen($dbStore->findItemsArray('tracks', Array('id_track'=>1), Array('field'=>Array('fileData')))[0]['fileData'])), Array(1, 'FLUXD '.bin2hex($dotPng)));
$trckRec = new DBRec('tracks');
$trckRec->bindFieldArray(Array('trckName'=>'Three', 'albumKey'=>1, 'fileData'=>Fluxd::openBytesFluxd($rawBlob)));
$newKey = $trckRec->insertOrUpdate();
checkIdentical('a new record with a binary value', Array($newKey, blobSeen($dbStore->findItemsArray('tracks', Array('id_track'=>$newKey), Array('field'=>Array('fileData')))[0]['fileData'])), Array(3, 'FLUXD '.bin2hex($rawBlob)));

$trckRec = null;
$covrFlx = null;
$fndRows = null;
$dbStore = null;
$dbHandl->exitConnection();

finishChecking();
