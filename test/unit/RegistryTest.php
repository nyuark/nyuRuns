<?php

// Registry : the default a value falls back to. Only a missing, null or blank
// value takes it; 0, false and an empty array are values.

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Registry;

$valsReg = new Registry( Array('zeroInt'=>0, 'zeroFlt'=>0.0, 'falseVl'=>false, 'zeroStr'=>'0', 'emptArr'=>Array(), 'nullVal'=>null, 'blankVl'=>'', 'textVal'=>'kept') );

checkSame('0 is a value',               $valsReg->getDefVal('zeroInt', 'dflt'), 0);
checkSame('0.0 is a value',             $valsReg->getDefVal('zeroFlt', 'dflt'), 0.0);
checkSame('false is a value',           $valsReg->getDefVal('falseVl', 'dflt'), false);
checkSame('"0" is a value',             $valsReg->getDefVal('zeroStr', 'dflt'), '0');
checkSame('an empty array is a value',  $valsReg->getDefVal('emptArr', 'dflt'), Array());
checkSame('a text is returned as stored', $valsReg->getDefVal('textVal', 'dflt'), 'kept');
checkSame('a missing key takes the default',   $valsReg->getDefVal('missing', 'dflt'), 'dflt');
checkSame('a null value takes the default',    $valsReg->getDefVal('nullVal', 'dflt'), 'dflt');
checkSame('an empty string takes the default', $valsReg->getDefVal('blankVl', 'dflt'), 'dflt');
checkSame('without a default, a missing key gives null', $valsReg->getDefVal('missing'), null);
checkSame('the key is read whatever its case', $valsReg->getDefVal('ZEROINT', 'dflt'), 0);

endChecks();
