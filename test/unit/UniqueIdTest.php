<?php

// UniqueId : the UUIDs of version 7 a record keeps wherever it is copied.

require __DIR__.'/../lib/harness.php';

use nyu\Tools\UniqueId;

// The form
$oneUuid = UniqueId::makeUuidVersn7();
checkIdentical('36 characters in lower case', Array(strlen($oneUuid), strtolower($oneUuid)), Array(36, $oneUuid));
checkIdentical('version 7, variant 10', Array($oneUuid[14], in_array($oneUuid[19], Array('8', '9', 'a', 'b'), true)), Array('7', true));
checkIdentical('a UUID made is well-formed', UniqueId::isFormedAsUuid($oneUuid), true);

// The time comes first : the ones made later sort after
$befTime = intval( floor(microtime(true)*1000) );
$madeOne = UniqueId::makeUuidVersn7();
usleep(2000);
$madeTwo = UniqueId::makeUuidVersn7();
$milliTm = hexdec( str_replace('-', '', substr($madeOne, 0, 13)) );
checkIdentical('the first 48 bits are the time in milliseconds', $milliTm>=$befTime && $milliTm<=$befTime+1000, true);
checkIdentical('a later one sorts after', strcmp($madeOne, $madeTwo)<0, true);

// Never the same twice
$uuidSet = Array();
for( $incVar=0; $incVar<1000; $incVar++ ){
	$uuidSet[UniqueId::makeUuidVersn7()] = true;
}
checkIdentical('a thousand made, a thousand different', count($uuidSet), 1000);

// The check
checkIdentical('any version, any case', array_map(Array(UniqueId::class, 'isFormedAsUuid'), Array('550e8400-e29b-41d4-a716-446655440000', '0192F0C4-8B5E-7A3D-9F21-6C0E4B7D2A18')), Array(true, true));
checkIdentical('what is not a UUID', array_map(Array(UniqueId::class, 'isFormedAsUuid'), Array('', '550e8400e29b41d4a716446655440000', '550e8400-e29b-41d4-c716-446655440000', '550e8400-e29b-01d4-a716-446655440000', '550e8400-e29b-41d4-a716-44665544000g', "550e8400-e29b-41d4-a716-446655440000\n")), Array(false, false, false, false, false, false));

finishChecking();
