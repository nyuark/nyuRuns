<?php

// Tools\Variable : every format reachable through readValue()

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Variable;

/**
 * Formats a value through Variable::readValue().
 *
 * @param mixed  $givenValue Raw value to wrap.
 * @param String $formatName Format key (the to* method name without 'to').
 * @param mixed  $formatOpts Options passed to the formatter.
 * @return mixed The formatted value, or a 'THROWN ...' string.
 */
function formatVal( Mixed $givenValue, String $formatName, Mixed $formatOpts=false ): Mixed {
	return runSafely(function() use ($givenValue, $formatName, $formatOpts){
		$varObjc = new Variable($givenValue);
		return $varObjc->readValue($formatName, $formatOpts);
	});
}

// TextStr : tags stripped, quotes encoded
checkSame('TextStr plain',            formatVal('plain text', 'TextStr'), 'plain text');
checkSame('TextStr is the default',   runSafely(function(){ $varObjc = new Variable('<i>x</i>'); return $varObjc->readValue(); }), 'x');
checkSame('TextStr tags and quote',   formatVal("<b>it's</b>", 'TextStr'), 'it&#39;s');
checkSame('TextStr double quotes',    formatVal('say "hi" & <i>bye</i>', 'TextStr'), 'say &#34;hi&#34; & bye');
checkSame('TextStr script tag',       formatVal('<script>alert(1)</script>x', 'TextStr'), 'alert(1)x');
checkSame('TextStr nested brackets',  formatVal('<<double>>', 'TextStr'), '');
checkSame('TextStr NUL byte',         formatVal("nul\0byte", 'TextStr'), 'nulbyte');
checkSame('TextStr number',           formatVal(12345, 'TextStr'), '12345');
checkSame('TextStr empty',            formatVal('', 'TextStr'), '');

// Integer
checkSame('Integer digits kept',      formatVal('12abc', 'Integer'), 12);
checkSame('Integer negative',         formatVal('-7', 'Integer'), -7);
checkSame('Integer no digits',        formatVal('abc', 'Integer'), 0);

// Numeric : sanitised, then formatted with the given separators
$numOpts = Array('decimals'=>2, 'decimsep'=>',', 'thsndsep'=>' ');
$numCase = Array(
	Array('1234.5', '1 234,50'), Array(1234.5, '1 234,50'), Array(1234, '1 234,00'), Array('0', '0,00'),
	Array('-42.1', '-42,10'), Array('+7', '7,00'), Array('1234567.891', '1 234 567,89'), Array('0.005', '0,01'),
	Array('12abc', '12,00'), Array('abc', '0,00'), Array('', '0,00'), Array(' 15.5 ', '15,50'),
	Array("1'234.50", '1 234,50'), Array('CHF 99.90', '99,90'), Array(null, '0,00'), Array(false, '0,00'),
);
foreach( $numCase as $oneCas ){
	checkSame('Numeric '.showValue($oneCas[0]), formatVal($oneCas[0], 'Numeric', $numOpts), $oneCas[1]);
}

// PriceNb : two decimals, '.' and "'" separators
checkSame('PriceNb string',           formatVal('1234.5', 'PriceNb'), "1'234.50");
checkSame('PriceNb float',            formatVal(1234.5, 'PriceNb'), "1'234.50");
checkSame('PriceNb large',            formatVal('1234567.891', 'PriceNb'), "1'234'567.89");
checkSame('PriceNb zero',             formatVal('0', 'PriceNb'), '0.00');
checkSame('PriceNb negative',         formatVal('-42.1', 'PriceNb'), '-42.10');
checkSame('PriceNb already formatted', formatVal("1'234.50", 'PriceNb'), "1'234.50");
checkSame('PriceNb not a number',     formatVal('abc', 'PriceNb'), '0.00');

// MailAdr
checkSame('MailAdr valid',            formatVal('a@b.c', 'MailAdr'), 'a@b.c');
checkSame('MailAdr spaces removed',   formatVal('a b@c.d', 'MailAdr'), 'ab@c.d');

// DateStr
checkSame('DateStr default format',   formatVal('2026-10-01', 'DateStr'), '01/10/26');
checkSame('DateStr given format',     formatVal('2026-10-01', 'DateStr', 'Y/m/d'), '2026/10/01');

// WeekDay and FullDay
checkSame('WeekDay',                  formatVal('2026-10-01', 'WeekDay'), 'Thursday');
checkSame('FullDay today',            formatVal(date('Y-m-d'), 'FullDay'), 'Today');
checkSame('FullDay yesterday',        formatVal(date('Y-m-d', time()-86400), 'FullDay'), 'Yesterday');
checkSame('FullDay tomorrow',         formatVal(date('Y-m-d', time()+86400), 'FullDay'), 'Tomorrow');
checkSame('FullDay other day',        formatVal('2020-03-15', 'FullDay'), 'Sunday, 15 March');

// DateSpn
checkSame('DateSpn same day',         formatVal('2026-12-01', 'DateSpn', '2026-12-01'), '01 December 2026');
checkSame('DateSpn same month',       formatVal('2026-12-01', 'DateSpn', '2026-12-05'), 'From 01 until 05 December 2026');
checkSame('DateSpn same year',        formatVal('2026-11-28', 'DateSpn', '2026-12-03'), 'From 28 November until 03 December 2026');
checkSame('DateSpn across years',     formatVal('2026-12-28', 'DateSpn', '2027-01-03'), 'From 28 December 2026 until 03 January 2027');
checkSame('DateSpn same month, next year', formatVal('2026-12-28', 'DateSpn', '2027-12-03'), 'From 28 December 2026 until 03 December 2027');
checkSame('DateSpn same day, next year',   formatVal('2026-12-01', 'DateSpn', '2027-12-01'), 'From 01 December 2026 until 01 December 2027');
checkSame('DateSpn without an end',   formatVal('2026-12-01', 'DateSpn'), false);

// Unknown format : the value is returned untouched
checkSame('unknown format',           formatVal('x', 'Nope'), 'x');

// saveValue replaces the wrapped value
checkSame('saveValue', runSafely(function(){ $varObjc = new Variable('1'); $varObjc->saveValue('<b>2</b>'); return $varObjc->readValue('TextStr'); }), '2');

// TextStr must keep behaving like the filter it replaced, for as long as PHP still ships it
if( defined('FILTER_SANITIZE_STRING') ){
	$alphabt = Array('<','<','<','>','>','>','!','?','-','-','(',')',"'",'"','a','x','m','l','L','E','e','d','o','c','t','y','p',' ',"\0",'&','/','=');
	$wordLst = Array('<?xml', '<!DOCTYPE', '<!--', '-->', '?>', '<?php', '<b>', '</b>', '<!', '<?', '--', '->');
	mt_srand(20261001);
	$diffLst = Array();
	for( $idx=0; $idx<20000; $idx++ ){
		$rndText = '';
		$rndSize = mt_rand(0, 28);
		for( $jdx=0; $jdx<$rndSize; $jdx++ ){
			$rndText .= mt_rand(0, 6)==0 ? $wordLst[mt_rand(0, count($wordLst)-1)] : $alphabt[mt_rand(0, count($alphabt)-1)];
		}
		$oldText = @filter_var($rndText, FILTER_SANITIZE_STRING);
		$newText = formatVal($rndText, 'TextStr');
		if( $newText!==$oldText && count($diffLst)<5 ){
			$diffLst[] = Array('input'=>$rndText, 'old'=>$oldText, 'new'=>$newText);
		}
	}
	checkSame('TextStr equals FILTER_SANITIZE_STRING on 20000 random inputs', $diffLst, Array());
}else{
	skipCheck('TextStr equals FILTER_SANITIZE_STRING', 'this PHP no longer has the filter');
}

endChecks();
