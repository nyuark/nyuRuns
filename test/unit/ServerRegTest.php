<?php

// Server registry : $_SERVER read into a registry under lower-case keys, the
// extra values given over it, and the superglobal unset once read.

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Registry;
use nyu\Tools\ServerReg;

// The registry unsets $_SERVER : the test sets its own, and builds the registries last
$_SERVER = Array('REQUEST_METHOD'=>'GET', 'REMOTE_ADDR'=>'203.0.113.7', 'HTTP_X_SAMPLE'=>'one');
$servReg = new ServerReg();
checkIdentical('the server values are held under lower-case keys', $servReg->readLinesArray(), Array('request_method'=>'GET', 'remote_addr'=>'203.0.113.7', 'http_x_sample'=>'one'));
checkIdentical('a value is read whatever the case asked', $servReg->readFileDetail('REMOTE_ADDR'), '203.0.113.7');
checkIdentical('the values are filtered by the start of their key', $servReg->readLinesArray('http_'), Array('http_x_sample'=>'one'));
checkTrueValue('$_SERVER is unset once read', !isset($_SERVER));

$_SERVER = Array('REQUEST_METHOD'=>'GET', 'REMOTE_ADDR'=>'203.0.113.7');
$servReg = new ServerReg( Array('REQUEST_METHOD'=>'POST', 'REQUEST_URI'=>'/shows/index') );
checkIdentical('an extra value is given over the one of $_SERVER', $servReg->readFileDetail('request_method'), 'POST');
checkIdentical('an extra value $_SERVER does not hold is added', $servReg->readFileDetail('request_uri'), '/shows/index');
checkIdentical('a value of $_SERVER no extra one replaces is kept', $servReg->readFileDetail('remote_addr'), '203.0.113.7');

$_SERVER = Array();
$servReg = new ServerReg();
checkIdentical('an empty $_SERVER gives an empty registry, without a notice', Array($servReg->readLinesArray(), $servReg->readFileDetail('request_method')), Array(Array(), null));

// The registry it is built on
$emptReg = new Registry( Array() );
checkIdentical('an empty registry lists nothing, without a notice', Array($emptReg->readLinesArray(), $emptReg->readLinesArray('http_')), Array(Array(), Array()));

finishChecking();
