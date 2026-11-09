<?php

// Registry : the default a value falls back to. Only a missing, null or blank
// value takes it; 0, false and an empty array are values.

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Registry;

$valsReg = new Registry( Array('zeroInt'=>0, 'zeroFlt'=>0.0, 'falseVl'=>false, 'zeroStr'=>'0', 'emptArr'=>Array(), 'nullVal'=>null, 'blankVl'=>'', 'textVal'=>'kept') );

checkIdentical('0 is a value',               $valsReg->obtainDefValue('zeroInt', 'dflt'), 0);
checkIdentical('0.0 is a value',             $valsReg->obtainDefValue('zeroFlt', 'dflt'), 0.0);
checkIdentical('false is a value',           $valsReg->obtainDefValue('falseVl', 'dflt'), false);
checkIdentical('"0" is a value',             $valsReg->obtainDefValue('zeroStr', 'dflt'), '0');
checkIdentical('an empty array is a value',  $valsReg->obtainDefValue('emptArr', 'dflt'), Array());
checkIdentical('a text is returned as stored', $valsReg->obtainDefValue('textVal', 'dflt'), 'kept');
checkIdentical('a missing key takes the default',   $valsReg->obtainDefValue('missing', 'dflt'), 'dflt');
checkIdentical('a null value takes the default',    $valsReg->obtainDefValue('nullVal', 'dflt'), 'dflt');
checkIdentical('an empty string takes the default', $valsReg->obtainDefValue('blankVl', 'dflt'), 'dflt');
checkIdentical('without a default, a missing key gives null', $valsReg->obtainDefValue('missing'), null);
checkIdentical('the key is read whatever its case', $valsReg->obtainDefValue('ZEROINT', 'dflt'), 0);

finishChecking();
