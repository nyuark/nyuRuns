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

// FloatNb : what Numeric reads, as a number
checkSame('FloatNb string',           formatVal('1234.5', 'FloatNb'), 1234.5);
checkSame('FloatNb with separators',  formatVal("CHF 1'234.50", 'FloatNb'), 1234.5);
checkSame('FloatNb negative',         formatVal('-42.1', 'FloatNb'), -42.1);
checkSame('FloatNb integer',          formatVal(7, 'FloatNb'), 7.0);
checkSame('FloatNb not a number',     formatVal('abc', 'FloatNb'), 0.0);
checkSame('FloatNb array',            formatVal(Array('1.5'), 'FloatNb'), 0.0);

// EmlAddr : cleaned, then refused when still not an address
checkSame('EmlAddr valid',            formatVal('a@b.c', 'EmlAddr'), 'a@b.c');
checkSame('EmlAddr spaces removed',   formatVal('a b@c.d', 'EmlAddr'), 'ab@c.d');
checkSame('EmlAddr usual address',    formatVal(' Site@Example.ch ', 'EmlAddr'), 'Site@Example.ch');
checkSame('EmlAddr without a domain', formatVal('nobody@', 'EmlAddr'), '');
checkSame('EmlAddr without an @',     formatVal('nobody.example.org', 'EmlAddr'), '');
checkSame('EmlAddr two @',            formatVal('a@b@c.d', 'EmlAddr'), '');
checkSame('EmlAddr empty',            formatVal('', 'EmlAddr'), '');
checkSame('EmlAddr array',            formatVal(Array('a@b.c'), 'EmlAddr'), '');
checkSame('the old MailAdr name is gone', formatVal('a b@c.d', 'MailAdr'), 'a b@c.d');

// PhoneNb : digits, after a + for an international number
checkSame('PhoneNb international',    formatVal('+41 22 345 67 89', 'PhoneNb'), '+41223456789');
checkSame('PhoneNb 00 prefix',        formatVal('0041 22 345 67 89', 'PhoneNb'), '+41223456789');
checkSame('PhoneNb national prefix in brackets', formatVal('+41 (0)22 345 67 89', 'PhoneNb'), '+41223456789');
checkSame('PhoneNb national',         formatVal('022 345 67 89', 'PhoneNb'), '0223456789');
checkSame('PhoneNb dots and dashes',  formatVal('022.345-67/89', 'PhoneNb'), '0223456789');
checkSame('PhoneNb brackets kept as digits in a national number', formatVal('(022) 345 67 89', 'PhoneNb'), '0223456789');
checkSame('PhoneNb number given as a number', formatVal(223456789, 'PhoneNb'), '223456789');
checkSame('PhoneNb too short',        formatVal('12345', 'PhoneNb'), '');
checkSame('PhoneNb too long',         formatVal('+1234567890123456', 'PhoneNb'), '');
checkSame('PhoneNb with letters',     formatVal('call 022 345 67 89', 'PhoneNb'), '');
checkSame('PhoneNb empty',            formatVal('', 'PhoneNb'), '');

// UrlAddr : http and https only
checkSame('UrlAddr https',            formatVal('https://example.org/path?x=1#top', 'UrlAddr'), 'https://example.org/path?x=1#top');
checkSame('UrlAddr http, trimmed',    formatVal('  http://example.org  ', 'UrlAddr'), 'http://example.org');
checkSame('UrlAddr scheme in capitals', formatVal('HTTPS://example.org', 'UrlAddr'), 'HTTPS://example.org');
checkSame('UrlAddr javascript refused', formatVal('javascript:alert(1)', 'UrlAddr'), '');
checkSame('UrlAddr data refused',     formatVal('data:text/html,<b>x</b>', 'UrlAddr'), '');
checkSame('UrlAddr ftp refused',      formatVal('ftp://example.org/file', 'UrlAddr'), '');
checkSame('UrlAddr file refused',     formatVal('file:///etc/passwd', 'UrlAddr'), '');
checkSame('UrlAddr without a scheme', formatVal('example.org', 'UrlAddr'), '');
checkSame('UrlAddr with a space',     formatVal('https://example.org/a b', 'UrlAddr'), '');
checkSame('UrlAddr with a quote',     formatVal('https://example.org/"onx="1', 'UrlAddr'), '');
checkSame('UrlAddr with a tag after a line break', formatVal("https://example.org\n<script>", 'UrlAddr'), '');
checkSame('UrlAddr empty',           formatVal('', 'UrlAddr'), '');

// UrlText : a text that can stand in an address
checkSame('UrlText accents and spaces', formatVal('Avenue des Rêves', 'UrlText'), 'avenue-des-reves');
checkSame('UrlText capitals with accents', formatVal('ÉTÉ À GENÈVE', 'UrlText'), 'ete-a-geneve');
checkSame('UrlText punctuation',      formatVal("  L'île -- aux_trésors !  ", 'UrlText'), 'l-ile-aux-tresors');
checkSame('UrlText ligatures',        formatVal('Œuvre & Straße', 'UrlText'), 'oeuvre-strasse');
checkSame('UrlText digits kept',      formatVal('Saison 2026/27', 'UrlText'), 'saison-2026-27');
checkSame('UrlText tags are not kept', formatVal('<b>Titre</b>', 'UrlText'), 'b-titre-b');
checkSame('UrlText nothing left',     formatVal('!!!', 'UrlText'), '');

// HtmText : a safe subset of HTML
checkSame('HtmText plain text',       formatVal('plain text', 'HtmText'), 'plain text');
checkSame('HtmText text tags kept',   formatVal('<p>One <b>bold</b> and <em>soft</em><br>line</p>', 'HtmText'), '<p>One <b>bold</b> and <em>soft</em><br>line</p>');
checkSame('HtmText lists and headings', formatVal('<h2>Title</h2><ul><li>a</li><li>b</li></ul>', 'HtmText'), '<h2>Title</h2><ul><li>a</li><li>b</li></ul>');
checkSame('HtmText script removed with its content', formatVal('a<script>alert(1)</script>b', 'HtmText'), 'ab');
checkSame('HtmText style removed with its content', formatVal('a<style>p{color:red}</style>b', 'HtmText'), 'ab');
checkSame('HtmText frame removed',    formatVal('a<iframe src="https://example.org">x</iframe>b', 'HtmText'), 'ab');
checkSame('HtmText attributes dropped', formatVal('<p onclick="x()" style="color:red" class="c">text</p>', 'HtmText'), '<p>text</p>');
checkSame('HtmText other tags removed, their text kept', formatVal('<div><span>in</span> <font color="red">red</font></div>', 'HtmText'), 'in red');
checkSame('HtmText picture removed',  formatVal('a<img src="x" onerror="alert(1)">b', 'HtmText'), 'ab');
checkSame('HtmText link kept with its address only', formatVal('<a href="https://example.org/?a=1&amp;b=2" target="_blank" onclick="x()">site</a>', 'HtmText'), '<a href="https://example.org/?a=1&amp;b=2">site</a>');
checkSame('HtmText mail link kept',   formatVal('<a href="mailto:site@example.org">write</a>', 'HtmText'), '<a href="mailto:site@example.org">write</a>');
checkSame('HtmText script link loses its address', formatVal('<a href="javascript:alert(1)">click</a>', 'HtmText'), 'click');
checkSame('HtmText script link hidden by spacing', formatVal("<a href=\" java\tscript:alert(1)\">click</a>", 'HtmText'), 'click');
checkSame('HtmText data link loses its address', formatVal('<a href="data:text/html,x">click</a>', 'HtmText'), 'click');
checkSame('HtmText link without address', formatVal('<a name="x">anchor</a>', 'HtmText'), 'anchor');
checkSame('HtmText signs in the text are written safely', formatVal('1 &lt; 2 &amp; 3 > 2', 'HtmText'), '1 &lt; 2 &amp; 3 &gt; 2');
checkSame('HtmText comment removed',  formatVal('a<!-- hidden -->b', 'HtmText'), 'ab');
checkSame('HtmText tags left open are closed', formatVal('<p>one <b>two', 'HtmText'), '<p>one <b>two</b></p>');
checkSame('HtmText accents kept',     formatVal('<p>Théâtre d\'été</p>', 'HtmText'), '<p>Théâtre d\'été</p>');
checkSame('HtmText nested script tags leave no tag', formatVal('<scr<script>ipt>alert(1)</scr</script>ipt>', 'HtmText'), 'ipt&gt;alert(1)ipt&gt;');
checkSame('HtmText drawing removed with its script', formatVal('<svg onload=alert(1)><script>alert(2)</script></svg>after', 'HtmText'), 'after');
checkSame('HtmText form removed, its text kept', formatVal('<form action="https://evil.example"><input name="p"><button>Go</button></form>', 'HtmText'), 'Go');
checkSame('HtmText a body closed early loses no text and runs nothing', formatVal('</body><script>alert(1)</script><body onload=alert(1)>tail', 'HtmText'), 'tail');
checkSame('HtmText a quote in an address cannot close it', formatVal('<a href="https://example.org/&quot;onmouseover=&quot;alert(1)">q</a>', 'HtmText'), '<a href="https://example.org/&quot;onmouseover=&quot;alert(1)">q</a>');
checkSame('HtmText empty',           formatVal('', 'HtmText'), '');
checkSame('HtmText array',            formatVal(Array('<b>x</b>'), 'HtmText'), '');

// DateStr
checkSame('DateStr default format',   formatVal('2026-10-01', 'DateStr'), '01/10/26');
checkSame('DateStr given format',     formatVal('2026-10-01', 'DateStr', 'Y/m/d'), '2026/10/01');
checkSame('DateStr with a time',      formatVal('2026-12-25 18:30:00', 'DateStr'), '25/12/26');
checkSame('DateStr from a timestamp', formatVal(mktime(12, 0, 0, 3, 15, 2020), 'DateStr', 'Y-m-d'), '2020-03-15');
checkSame('DateStr not a date',       formatVal('soon', 'DateStr'), '');
checkSame('DateStr three parts that are no date', formatVal('a-b-c', 'DateStr'), '');
checkSame('DateStr day that does not exist', formatVal('2026-02-30', 'DateStr'), '');
checkSame('DateStr a text of digits is no timestamp', Array(formatVal('12', 'DateStr'), formatVal('2026', 'DateStr'), formatVal('20261001', 'DateStr'), formatVal((string)mktime(12, 0, 0, 3, 15, 2020), 'DateStr')), Array('', '', '', ''));
checkSame('DateStr true, a float and a negative number are no timestamp', Array(formatVal(true, 'DateStr'), formatVal(12.7, 'DateStr'), formatVal(-5, 'DateStr')), Array('', '', ''));
checkSame('DateStr empty, null and array',Array(formatVal('', 'DateStr'), formatVal(null, 'DateStr'), formatVal(Array('2026-10-01'), 'DateStr')), Array('', '', ''));

// ShrDate and TxtDate
checkSame('ShrDate',                  formatVal('2026-10-01', 'ShrDate'), '1 Oct 2026');
checkSame('ShrDate with a time',      formatVal('2026-12-25 18:30:00', 'ShrDate'), '25 Dec 2026');
checkSame('ShrDate from a timestamp', formatVal(mktime(12, 0, 0, 3, 15, 2020), 'ShrDate'), '15 Mar 2020');
checkSame('ShrDate not a date',       formatVal('soon', 'ShrDate'), '');
checkSame('ShrDate day that does not exist', formatVal('2026-02-30', 'ShrDate'), '');
checkSame('ShrDate empty',            formatVal('', 'ShrDate'), '');
checkSame('ShrDate, TxtDate, WeekDay and FullDay: a text of digits is no timestamp', Array(formatVal('2026', 'ShrDate'), formatVal('2026', 'TxtDate'), formatVal('2026', 'WeekDay'), formatVal('2026', 'FullDay')), Array('', '', '', ''));
checkSame('TxtDate',                  formatVal('2026-10-01', 'TxtDate'), '1 October 2026');
checkSame('TxtDate single digits',    formatVal('2026-3-5', 'TxtDate'), '5 March 2026');
checkSame('TxtDate not a date',       formatVal('2026-13-01', 'TxtDate'), '');
checkSame('TxtDate array',            formatVal(Array('2026-10-01'), 'TxtDate'), '');

// WeekDay and FullDay
checkSame('WeekDay',                  formatVal('2026-10-01', 'WeekDay'), 'Thursday');
checkSame('FullDay today',            formatVal(date('Y-m-d'), 'FullDay'), 'Today');
checkSame('FullDay yesterday',        formatVal(date('Y-m-d', time()-86400), 'FullDay'), 'Yesterday');
checkSame('FullDay tomorrow',         formatVal(date('Y-m-d', time()+86400), 'FullDay'), 'Tomorrow');
checkSame('FullDay other day',        formatVal('2020-03-15', 'FullDay'), 'Sunday, 15 March');
checkSame('WeekDay from a timestamp', formatVal(mktime(12, 0, 0, 3, 15, 2020), 'WeekDay'), 'Sunday');
checkSame('WeekDay not a date',       Array(formatVal('soon', 'WeekDay'), formatVal('a-b-c', 'WeekDay'), formatVal('', 'WeekDay')), Array('', '', ''));
checkSame('FullDay not a date',       Array(formatVal('soon', 'FullDay'), formatVal('2026-13-01', 'FullDay'), formatVal(Array(), 'FullDay')), Array('', '', ''));

// DateSpn
checkSame('DateSpn same day',         formatVal('2026-12-01', 'DateSpn', '2026-12-01'), '01 December 2026');
checkSame('DateSpn same month',       formatVal('2026-12-01', 'DateSpn', '2026-12-05'), 'From 01 until 05 December 2026');
checkSame('DateSpn same year',        formatVal('2026-11-28', 'DateSpn', '2026-12-03'), 'From 28 November until 03 December 2026');
checkSame('DateSpn across years',     formatVal('2026-12-28', 'DateSpn', '2027-01-03'), 'From 28 December 2026 until 03 January 2027');
checkSame('DateSpn same month, next year', formatVal('2026-12-28', 'DateSpn', '2027-12-03'), 'From 28 December 2026 until 03 December 2027');
checkSame('DateSpn same day, next year',   formatVal('2026-12-01', 'DateSpn', '2027-12-01'), 'From 01 December 2026 until 01 December 2027');
checkSame('DateSpn without an end',   formatVal('2026-12-01', 'DateSpn'), '');
checkSame('DateSpn with an empty end, or no end and no start', Array(formatVal('2026-12-01', 'DateSpn', ''), formatVal('2026-12-01', 'DateSpn', '0'), formatVal('soon', 'DateSpn')), Array('', '', ''));
checkSame('DateSpn between two timestamps', formatVal(mktime(12, 0, 0, 12, 1, 2026), 'DateSpn', mktime(12, 0, 0, 12, 5, 2026)), 'From 01 until 05 December 2026');
checkSame('DateSpn end that is a text of digits', formatVal('2026-12-01', 'DateSpn', '2026'), '');
checkSame('DateSpn ends with a time', formatVal('2026-12-01 20:00:00', 'DateSpn', '2026-12-05 22:00:00'), 'From 01 until 05 December 2026');
checkSame('DateSpn start that is no date', formatVal('soon', 'DateSpn', '2026-12-05'), '');
checkSame('DateSpn end that is no date', Array(formatVal('2026-12-01', 'DateSpn', 'later'), formatVal('2026-12-01', 'DateSpn', '2026-02-30')), Array('', ''));

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
