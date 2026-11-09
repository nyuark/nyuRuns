<?php

// Error\handling : the error handler follows the error level PHP reports
// (ERR_LEVL) and the @ operator: what they leave out is neither kept for
// the reply nor logged.

require __DIR__.'/../lib/harness.php';
require_once TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php';

use nyu\Tools\Logger;

Logger::plugSetup( Array('path'=>inTempDir('errlogs'), 'mode'=>'FULL') );
$prevHnd = $GLOBALS['ErrorHandler'] ?? null;
$prevRsp = $GLOBALS['ErrorResponse'] ?? null;
$GLOBALS['ErrorHandler'] = new ErrorHandler( Logger::getLogger('error') );
$GLOBALS['ErrorResponse'] = true;
$prevLvl = error_reporting();
set_error_handler('hndlError');

error_reporting( E_ALL & ~E_USER_WARNING );
trigger_error('left out by the level', E_USER_WARNING);
trigger_error('kept by the level', E_USER_NOTICE);
error_reporting( E_ALL );
@trigger_error('silenced by the at sign', E_USER_WARNING);
trigger_error('reported warning', E_USER_WARNING);
// An exception is handled whatever the level
error_reporting( 0 );
hndlError('E_EXCEPTION', 'exception at level 0', __FILE__, __LINE__);
error_reporting( E_ALL );

restore_error_handler();
error_reporting( $prevLvl );
Logger::plugSetup( Array() );
$GLOBALS['ErrorHandler'] = $prevHnd;
$GLOBALS['ErrorResponse'] = $prevRsp;
$logText = strval(@file_get_contents(inTempDir('errlogs').DS.'error.log'));

checkSame('what the level leaves out is not logged; what it keeps is', Array(strpos($logText, 'left out by the level'), strpos($logText, 'kept by the level')!==false), Array(false, true));
checkSame('what @ silences is not logged; the same error reported is', Array(strpos($logText, 'silenced by the at sign'), strpos($logText, 'reported warning')!==false), Array(false, true));
checkSame('an exception is logged whatever the level', strpos($logText, 'exception at level 0')!==false, true);
error_reporting( E_ALL & ~E_USER_DEPRECATED );
ob_start();
$hndRslt = hndlError(E_USER_DEPRECATED, 'any', __FILE__, __LINE__);
$shownTx = ob_get_clean();
error_reporting( $prevLvl );
checkSame('left out, nothing is shown either, and PHP is told it was handled', Array($hndRslt, $shownTx), Array(true, ''));

endChecks();
