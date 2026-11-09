<?php

// Binary values where no server is needed: what is written as text in
// their place, what the SQL store does of what PostgreSQL and MySQL give
// (played here by handlers answering what those databases answer), and
// what the MongoDB store does of its binary values.

require __DIR__.'/../lib/harness.php';

use nyu\Data\DBHndlr\MySQL;
use nyu\Data\DBHndlr\PgSQL;
use nyu\Data\DBStore\MongoStore;
use nyu\Data\DBStore\SqlStore;
use nyu\Data\Fluxd;
use nyu\Data\Writers\Json;

// A handler that opens nothing, tells the queries it is sent and answers what it was told to
trait PlayedBase {
	public $sentList = Array();
	public $answrSet = Array();
	public function __construct(String $serverHost='', String $serverUser='', String $serverPass='', String $serverBase='', Array $moreParams=Array()){
	}
	public function sendBuiltQuery(String $builtQuery, Bool $query2Exec=false, Array $boundArray=Array()): Bool {
		$this->sentList[] = Array($builtQuery, $boundArray);
		return true;
	}
	public function readLastResult( Bool $keepAsList=false ): Mixed {
		return array_shift($this->answrSet);
	}
	public function finalAddedText(): String|Bool {
		return '3';
	}
}
class PlayedPgs extends PgSQL {
	use PlayedBase;
}
class PlayedMys extends MySQL {
	use PlayedBase;
}

/**
 * Gives what a value read from a store is: the bytes of a flux, or the value.
 *
 * @param mixed $givenValue The value.
 * @return mixed 'FLUXD ' followed by its bytes, or the value as it is.
 */
function blobSeen( Mixed $givenValue ): Mixed {
	return ($givenValue instanceof Fluxd) ? 'FLUXD '.$givenValue->getWholeString() : $givenValue;
}

/**
 * Makes a stream holding bytes, as PostgreSQL gives its binary values.
 *
 * @param String $rawContent The bytes.
 * @return mixed The stream.
 */
function blobStrm( String $rawContent ): Mixed {
	$strmHdl = fopen('php://memory', 'w+b');
	fwrite($strmHdl, $rawContent);
	rewind($strmHdl);
	return $strmHdl;
}

$rawBlob = "\x00\x01\xFF\xFEbinary\x00end";

// Written as text
checkIdentical('a flux in a value is given as null, at any depth', Fluxd::nullifySources(Array('name'=>'One', 'file'=>Fluxd::openBytesFluxd($rawBlob), 'more'=>Array(Array('pict'=>Fluxd::openBytesFluxd('x'), 'size'=>3)), 'none'=>null)), Array('name'=>'One', 'file'=>null, 'more'=>Array(Array('pict'=>null, 'size'=>3)), 'none'=>null));
checkIdentical('a flux alone, and what is not one', Array(Fluxd::nullifySources(Fluxd::openBytesFluxd('x')), Fluxd::nullifySources('text'), Fluxd::nullifySources(12)), Array(null, 'text', 12));
checkIdentical('the JSON writer gives null for a binary value', (new Json())->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array('name'=>'One', 'file'=>Fluxd::openBytesFluxd($rawBlob)))), '{"name":"One","file":null}');

// PostgreSQL: its catalogue tells the binary fields, and it gives them as streams
$pgsHndl = new PlayedPgs();
$pgsHndl->answrSet = Array(Array(Array('name'=>'id_track', 'pk'=>true, 'type'=>'integer'), Array('name'=>'trckName', 'pk'=>false, 'type'=>'character varying'), Array('name'=>'fileData', 'pk'=>false, 'type'=>'bytea')));
checkIdentical('PostgreSQL: the binary fields of a table', $pgsHndl->tableInfoArray('tracks'), Array('flds'=>Array('id_track', 'trckName', 'fileData'), 'pkey'=>'id_track', 'blob'=>Array('fileData')));
checkPregMatch('PostgreSQL: the catalogue is asked for the type of each field', $pgsHndl->sentList[0][0], '/format_type\(a\.atttypid, NULL\) AS type/');
$pgsHndl = new PlayedPgs();
$pgsStor = new SqlStore($pgsHndl);
$pgsHndl->answrSet = Array(
	Array(Array('name'=>'id_track', 'pk'=>true, 'type'=>'integer'), Array('name'=>'trckName', 'pk'=>false, 'type'=>'text'), Array('name'=>'fileData', 'pk'=>false, 'type'=>'bytea')),
	Array(Array('id_track'=>1, 'trckName'=>'One'))
);
checkIdentical('PostgreSQL: a reading that names no field asks for all but the binary ones', Array($pgsStor->findItemsArray('tracks', Array('id_track'=>1)), end($pgsHndl->sentList)[0]), Array(Array(Array('id_track'=>1, 'trckName'=>'One')), 'SELECT "id_track","trckName" FROM "tracks" WHERE "id_track" = ?'));
$pgsHndl->answrSet = Array(Array(Array('trckName'=>'One', 'fileData'=>blobStrm($rawBlob)), Array('trckName'=>'Two', 'fileData'=>null)));
$fndRows = $pgsStor->findItemsArray('tracks', Array(), Array('field'=>Array('trckName', 'fileData')));
checkIdentical('PostgreSQL: a stream is read as a flux, a field holding nothing as null', Array(blobSeen($fndRows[0]['fileData']), $fndRows[1]['fileData'], count($pgsHndl->sentList)), Array('FLUXD '.$rawBlob, null, 3));
$pgsHndl->answrSet = Array(Array(Array('fileData'=>blobStrm($rawBlob))));
checkPregMatch('PostgreSQL: a stream larger than accepted is refused', runCatchThrown(function() use ($pgsStor){ return $pgsStor->findItemsArray('tracks', Array(), Array('field'=>Array('fileData'), 'bytes'=>5)); }), '/^THROWN RuntimeException: Binary field larger than the 5 bytes allowed: fileData/');
$pgsHndl->answrSet = Array(Array(Array('nb'=>blobStrm('7'))));
checkIdentical('PostgreSQL: a stream is read as a flux wherever it comes from', blobSeen($pgsStor->findItemsArray('tracks', Array(), Array('funcs'=>Array(Array('funct'=>'max', 'field'=>'fileData', 'alias'=>'nb'))))[0]['nb']), 'FLUXD 7');
$sentFlx = Fluxd::openBytesFluxd($rawBlob);
$pgsStor->insertTableRow('tracks', Array('trckName'=>'Three', 'fileData'=>$sentFlx));
checkIdentical('PostgreSQL: a flux reaches the handler as it is, to be bound as binary', Array(end($pgsHndl->sentList)[0], end($pgsHndl->sentList)[1][1]===$sentFlx), Array('INSERT INTO "tracks" ("trckName","fileData") VALUES (?,?);', true));

// MySQL: the description of a table tells the binary fields
$mysHndl = new PlayedMys();
$mysHndl->answrSet = Array(Array(
	Array('Field'=>'id_track', 'Type'=>'int(11)', 'Key'=>'PRI'), Array('Field'=>'trckName', 'Type'=>'varchar(80)', 'Key'=>''), Array('Field'=>'fileNote', 'Type'=>'longtext', 'Key'=>''),
	Array('Field'=>'fileData', 'Type'=>'longblob', 'Key'=>''), Array('Field'=>'thumbPic', 'Type'=>'BLOB', 'Key'=>''), Array('Field'=>'hashCode', 'Type'=>'binary(16)', 'Key'=>''),
	Array('Field'=>'bearerHash', 'Type'=>'varbinary(64)', 'Key'=>''), Array('Field'=>'tinyData', 'Type'=>'tinyblob', 'Key'=>''), Array('Field'=>'midlData', 'Type'=>'mediumblob', 'Key'=>'')
));
checkIdentical('MySQL: the binary fields of a table', $mysHndl->tableInfoArray('tracks')['blob'], Array('fileData', 'thumbPic', 'hashCode', 'bearerHash', 'tinyData', 'midlData'));
$mysHndl = new PlayedMys();
$mysStor = new SqlStore($mysHndl);
$mysHndl->answrSet = Array(
	Array(Array('Field'=>'id_track', 'Type'=>'int(11)', 'Key'=>'PRI'), Array('Field'=>'trckName', 'Type'=>'varchar(80)', 'Key'=>''), Array('Field'=>'fileData', 'Type'=>'longblob', 'Key'=>'')),
	Array(Array('trckName'=>'One', 'fileData'=>$rawBlob))
);
$fndRows = $mysStor->findItemsArray('tracks', Array(), Array('field'=>Array('trckName', 'fileData')));
checkIdentical('MySQL: a binary field given as text is read as a flux, a text field as text', Array($fndRows[0]['trckName'], blobSeen($fndRows[0]['fileData']), end($mysHndl->sentList)[0]), Array('One', 'FLUXD '.$rawBlob, 'SELECT `trckName`,`fileData` FROM `tracks`'));
$mysHndl->answrSet = Array(Array(Array('id_track'=>1, 'trckName'=>'One')));
checkIdentical('MySQL: the table is described once', Array($mysStor->findItemsArray('tracks'), end($mysHndl->sentList)[0], count($mysHndl->sentList)), Array(Array(Array('id_track'=>1, 'trckName'=>'One')), 'SELECT `id_track`,`trckName` FROM `tracks`', 3));

// MongoDB: binary values have their own type
if( !class_exists('\\MongoDB\\BSON\\Binary') ){
	recordSkipping('MongoDB: binary values', 'the mongodb extension is not loaded');
	finishChecking();
}
$sentArr = MongoStore::blobsSentArray(Array('trckName'=>'One', 'fileData'=>Fluxd::openBytesFluxd($rawBlob), 'seats'=>3, 'none'=>null));
checkIdentical('MongoDB: a flux is written as a binary value', Array(get_class($sentArr['fileData']), $sentArr['fileData']->getData()===$rawBlob, $sentArr['fileData']->getType(), $sentArr['trckName'], $sentArr['seats'], $sentArr['none']), Array('MongoDB\\BSON\\Binary', true, 0, 'One', 3, null));
checkPregMatch('MongoDB: a flux larger than a document may hold is refused', runCatchThrown(function(){ return MongoStore::blobsSentArray(Array('fileData'=>Fluxd::openBytesFluxd(str_repeat('x', MongoStore::BLOB_MAXI+1)))); }), '/^THROWN RuntimeException: Content larger than the 15728640 bytes allowed/');
$readRow = Array('_id'=>'key1', 'trckName'=>'One', 'fileData'=>$sentArr['fileData'], 'thumbPic'=>$sentArr['fileData']);
checkIdentical('MongoDB: a reading that names no field leaves the binary values out', MongoStore::blobsReadArray($readRow, Array(), MongoStore::BLOB_SIZE), Array('_id'=>'key1', 'trckName'=>'One'));
$namdRow = MongoStore::blobsReadArray($readRow, Array('trckName', 'fileData'), MongoStore::BLOB_SIZE);
checkIdentical('MongoDB: a binary value named is read as a flux', Array(array_keys($namdRow), blobSeen($namdRow['fileData'])), Array(Array('_id', 'trckName', 'fileData'), 'FLUXD '.$rawBlob));
checkPregMatch('MongoDB: a binary value larger than accepted is refused', runCatchThrown(function() use ($readRow){ return MongoStore::blobsReadArray($readRow, Array('fileData'), 5); }), '/^THROWN RuntimeException: Binary field larger than the 5 bytes allowed: fileData/');
checkIdentical('MongoDB: the description of a table tells no binary field', (new ReflectionClass('nyu\\Data\\DBStore\\MongoStore'))->newInstanceWithoutConstructor()->tableInfoArray('tracks'), Array('flds'=>Array(), 'pkey'=>'_id', 'blob'=>Array()));

finishChecking();
