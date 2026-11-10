<?php

// Tools\Variable : every format reachable through castWrappedVal()

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Variable;

/**
 * Formats a value through Variable::castWrappedVal().
 *
 * @param mixed  $givenValue Raw value to wrap.
 * @param String $formatName Format key (the to* method name without 'to').
 * @param mixed  $formatOpts Options passed to the formatter.
 * @return mixed The formatted value, or a 'THROWN ...' string.
 */
function formatVal( Mixed $givenValue, String $formatName, Mixed $formatOpts=false ): Mixed {
	return runCatchThrown(function() use ($givenValue, $formatName, $formatOpts){
		$varObjc = new Variable($givenValue);
		return $varObjc->castWrappedVal($formatName, $formatOpts);
	});
}

// OutText : tags stripped, quotes encoded
checkIdentical('OutText plain',            formatVal('plain text', 'OutText'), 'plain text');
checkIdentical('OutText is the default',   runCatchThrown(function(){ $varObjc = new Variable('<i>x</i>'); return $varObjc->castWrappedVal(); }), 'x');
checkIdentical('OutText tags and quote',   formatVal("<b>it's</b>", 'OutText'), 'it&#39;s');
checkIdentical('OutText double quotes',    formatVal('say "hi" & <i>bye</i>', 'OutText'), 'say &#34;hi&#34; & bye');
checkIdentical('OutText script tag',       formatVal('<script>alert(1)</script>x', 'OutText'), 'alert(1)x');
checkIdentical('OutText nested brackets',  formatVal('<<double>>', 'OutText'), '');
checkIdentical('OutText NUL byte',         formatVal("nul\0byte", 'OutText'), 'nulbyte');
checkIdentical('OutText number',           formatVal(12345, 'OutText'), '12345');
checkIdentical('OutText empty',            formatVal('', 'OutText'), '');

// Integer
checkIdentical('Integer digits kept',      formatVal('12abc', 'Integer'), 12);
checkIdentical('Integer negative',         formatVal('-7', 'Integer'), -7);
checkIdentical('Integer no digits',        formatVal('abc', 'Integer'), 0);

// Numeric : sanitised, then formatted with the given separators
$numOpts = Array('decimals'=>2, 'decimsep'=>',', 'thsndsep'=>' ');
$numCase = Array(
	Array('1234.5', '1 234,50'), Array(1234.5, '1 234,50'), Array(1234, '1 234,00'), Array('0', '0,00'),
	Array('-42.1', '-42,10'), Array('+7', '7,00'), Array('1234567.891', '1 234 567,89'), Array('0.005', '0,01'),
	Array('12abc', '12,00'), Array('abc', '0,00'), Array('', '0,00'), Array(' 15.5 ', '15,50'),
	Array("1'234.50", '1 234,50'), Array('CHF 99.90', '99,90'), Array(null, '0,00'), Array(false, '0,00'),
);
foreach( $numCase as $oneCas ){
	checkIdentical('Numeric '.printValueText($oneCas[0]), formatVal($oneCas[0], 'Numeric', $numOpts), $oneCas[1]);
}

// PriceNb : two decimals, '.' and "'" separators
checkIdentical('PriceNb string',           formatVal('1234.5', 'PriceNb'), "1'234.50");
checkIdentical('PriceNb float',            formatVal(1234.5, 'PriceNb'), "1'234.50");
checkIdentical('PriceNb large',            formatVal('1234567.891', 'PriceNb'), "1'234'567.89");
checkIdentical('PriceNb zero',             formatVal('0', 'PriceNb'), '0.00');
checkIdentical('PriceNb negative',         formatVal('-42.1', 'PriceNb'), '-42.10');
checkIdentical('PriceNb already formatted', formatVal("1'234.50", 'PriceNb'), "1'234.50");
checkIdentical('PriceNb not a number',     formatVal('abc', 'PriceNb'), '0.00');

// FloatNb : what Numeric reads, as a number
checkIdentical('FloatNb string',           formatVal('1234.5', 'FloatNb'), 1234.5);
checkIdentical('FloatNb with separators',  formatVal("CHF 1'234.50", 'FloatNb'), 1234.5);
checkIdentical('FloatNb negative',         formatVal('-42.1', 'FloatNb'), -42.1);
checkIdentical('FloatNb integer',          formatVal(7, 'FloatNb'), 7.0);
checkIdentical('FloatNb not a number',     formatVal('abc', 'FloatNb'), 0.0);
checkIdentical('FloatNb array',            formatVal(Array('1.5'), 'FloatNb'), 0.0);

// EmlAddr : cleaned, then refused when still not an address
checkIdentical('EmlAddr valid',            formatVal('a@b.c', 'EmlAddr'), 'a@b.c');
checkIdentical('EmlAddr spaces removed',   formatVal('a b@c.d', 'EmlAddr'), 'ab@c.d');
checkIdentical('EmlAddr usual address',    formatVal(' Site@Example.ch ', 'EmlAddr'), 'Site@Example.ch');
checkIdentical('EmlAddr without a domain', formatVal('nobody@', 'EmlAddr'), '');
checkIdentical('EmlAddr without an @',     formatVal('nobody.example.org', 'EmlAddr'), '');
checkIdentical('EmlAddr two @',            formatVal('a@b@c.d', 'EmlAddr'), '');
checkIdentical('EmlAddr empty',            formatVal('', 'EmlAddr'), '');
checkIdentical('EmlAddr array',            formatVal(Array('a@b.c'), 'EmlAddr'), '');
checkIdentical('the old MailAdr name is gone', formatVal('a b@c.d', 'MailAdr'), 'a b@c.d');

// PhoneNb : digits, after a + for an international number
checkIdentical('PhoneNb international',    formatVal('+41 22 345 67 89', 'PhoneNb'), '+41223456789');
checkIdentical('PhoneNb 00 prefix',        formatVal('0041 22 345 67 89', 'PhoneNb'), '+41223456789');
checkIdentical('PhoneNb national prefix in brackets', formatVal('+41 (0)22 345 67 89', 'PhoneNb'), '+41223456789');
checkIdentical('PhoneNb national',         formatVal('022 345 67 89', 'PhoneNb'), '0223456789');
checkIdentical('PhoneNb dots and dashes',  formatVal('022.345-67/89', 'PhoneNb'), '0223456789');
checkIdentical('PhoneNb brackets kept as digits in a national number', formatVal('(022) 345 67 89', 'PhoneNb'), '0223456789');
checkIdentical('PhoneNb number given as a number', formatVal(223456789, 'PhoneNb'), '223456789');
checkIdentical('PhoneNb too short',        formatVal('12345', 'PhoneNb'), '');
checkIdentical('PhoneNb too long',         formatVal('+1234567890123456', 'PhoneNb'), '');
checkIdentical('PhoneNb with letters',     formatVal('call 022 345 67 89', 'PhoneNb'), '');
checkIdentical('PhoneNb empty',            formatVal('', 'PhoneNb'), '');

// UrlAddr : http and https only
checkIdentical('UrlAddr https',            formatVal('https://example.org/path?x=1#top', 'UrlAddr'), 'https://example.org/path?x=1#top');
checkIdentical('UrlAddr http, trimmed',    formatVal('  http://example.org  ', 'UrlAddr'), 'http://example.org');
checkIdentical('UrlAddr scheme in capitals', formatVal('HTTPS://example.org', 'UrlAddr'), 'HTTPS://example.org');
checkIdentical('UrlAddr javascript refused', formatVal('javascript:alert(1)', 'UrlAddr'), '');
checkIdentical('UrlAddr data refused',     formatVal('data:text/html,<b>x</b>', 'UrlAddr'), '');
checkIdentical('UrlAddr ftp refused',      formatVal('ftp://example.org/file', 'UrlAddr'), '');
checkIdentical('UrlAddr file refused',     formatVal('file:///etc/passwd', 'UrlAddr'), '');
checkIdentical('UrlAddr without a scheme', formatVal('example.org', 'UrlAddr'), '');
checkIdentical('UrlAddr with a space',     formatVal('https://example.org/a b', 'UrlAddr'), '');
checkIdentical('UrlAddr with a quote',     formatVal('https://example.org/"onx="1', 'UrlAddr'), '');
checkIdentical('UrlAddr with a tag after a line break', formatVal("https://example.org\n<script>", 'UrlAddr'), '');
checkIdentical('UrlAddr empty',           formatVal('', 'UrlAddr'), '');

// UrlText : a text that can stand in an address
checkIdentical('UrlText accents and spaces', formatVal('Avenue des Rêves', 'UrlText'), 'avenue-des-reves');
checkIdentical('UrlText capitals with accents', formatVal('ÉTÉ À GENÈVE', 'UrlText'), 'ete-a-geneve');
checkIdentical('UrlText punctuation',      formatVal("  L'île -- aux_trésors !  ", 'UrlText'), 'l-ile-aux-tresors');
checkIdentical('UrlText ligatures',        formatVal('Œuvre & Straße', 'UrlText'), 'oeuvre-strasse');
checkIdentical('UrlText digits kept',      formatVal('Saison 2026/27', 'UrlText'), 'saison-2026-27');
checkIdentical('UrlText tags are not kept', formatVal('<b>Titre</b>', 'UrlText'), 'b-titre-b');
checkIdentical('UrlText nothing left',     formatVal('!!!', 'UrlText'), '');

// HtmText : a safe subset of HTML
checkIdentical('HtmText plain text',       formatVal('plain text', 'HtmText'), 'plain text');
checkIdentical('HtmText text tags kept',   formatVal('<p>One <b>bold</b> and <em>soft</em><br>line</p>', 'HtmText'), '<p>One <b>bold</b> and <em>soft</em><br>line</p>');
checkIdentical('HtmText lists and headings', formatVal('<h2>Title</h2><ul><li>a</li><li>b</li></ul>', 'HtmText'), '<h2>Title</h2><ul><li>a</li><li>b</li></ul>');
checkIdentical('HtmText script removed with its content', formatVal('a<script>alert(1)</script>b', 'HtmText'), 'ab');
checkIdentical('HtmText style removed with its content', formatVal('a<style>p{color:red}</style>b', 'HtmText'), 'ab');
checkIdentical('HtmText frame removed',    formatVal('a<iframe src="https://example.org">x</iframe>b', 'HtmText'), 'ab');
checkIdentical('HtmText attributes dropped', formatVal('<p onclick="x()" style="color:red" class="c">text</p>', 'HtmText'), '<p>text</p>');
checkIdentical('HtmText other tags removed, their text kept', formatVal('<div><span>in</span> <font color="red">red</font></div>', 'HtmText'), 'in red');
checkIdentical('HtmText picture removed',  formatVal('a<img src="x" onerror="alert(1)">b', 'HtmText'), 'ab');
checkIdentical('HtmText link kept with its address only', formatVal('<a href="https://example.org/?a=1&amp;b=2" target="_blank" onclick="x()">site</a>', 'HtmText'), '<a href="https://example.org/?a=1&amp;b=2">site</a>');
checkIdentical('HtmText mail link kept',   formatVal('<a href="mailto:site@example.org">write</a>', 'HtmText'), '<a href="mailto:site@example.org">write</a>');
checkIdentical('HtmText script link loses its address', formatVal('<a href="javascript:alert(1)">click</a>', 'HtmText'), 'click');
checkIdentical('HtmText script link hidden by spacing', formatVal("<a href=\" java\tscript:alert(1)\">click</a>", 'HtmText'), 'click');
checkIdentical('HtmText data link loses its address', formatVal('<a href="data:text/html,x">click</a>', 'HtmText'), 'click');
checkIdentical('HtmText link without address', formatVal('<a name="x">anchor</a>', 'HtmText'), 'anchor');
checkIdentical('HtmText signs in the text are written safely', formatVal('1 &lt; 2 &amp; 3 > 2', 'HtmText'), '1 &lt; 2 &amp; 3 &gt; 2');
checkIdentical('HtmText comment removed',  formatVal('a<!-- hidden -->b', 'HtmText'), 'ab');
checkIdentical('HtmText tags left open are closed', formatVal('<p>one <b>two', 'HtmText'), '<p>one <b>two</b></p>');
checkIdentical('HtmText accents kept',     formatVal('<p>Théâtre d\'été</p>', 'HtmText'), '<p>Théâtre d\'été</p>');
checkIdentical('HtmText nested script tags leave no tag', formatVal('<scr<script>ipt>alert(1)</scr</script>ipt>', 'HtmText'), 'ipt&gt;alert(1)ipt&gt;');
checkIdentical('HtmText drawing removed with its script', formatVal('<svg onload=alert(1)><script>alert(2)</script></svg>after', 'HtmText'), 'after');
checkIdentical('HtmText form removed, its text kept', formatVal('<form action="https://evil.example"><input name="p"><button>Go</button></form>', 'HtmText'), 'Go');
checkIdentical('HtmText a body closed early loses no text and runs nothing', formatVal('</body><script>alert(1)</script><body onload=alert(1)>tail', 'HtmText'), 'tail');
checkIdentical('HtmText a quote in an address cannot close it', formatVal('<a href="https://example.org/&quot;onmouseover=&quot;alert(1)">q</a>', 'HtmText'), '<a href="https://example.org/&quot;onmouseover=&quot;alert(1)">q</a>');
checkIdentical('HtmText empty',           formatVal('', 'HtmText'), '');
checkIdentical('HtmText array',            formatVal(Array('<b>x</b>'), 'HtmText'), '');

// DateStr
checkIdentical('DateStr default format',   formatVal('2026-10-01', 'DateStr'), '01/10/26');
checkIdentical('DateStr given format',     formatVal('2026-10-01', 'DateStr', 'Y/m/d'), '2026/10/01');
checkIdentical('DateStr with a time',      formatVal('2026-12-25 18:30:00', 'DateStr'), '25/12/26');
checkIdentical('DateStr from a timestamp', formatVal(mktime(12, 0, 0, 3, 15, 2020), 'DateStr', 'Y-m-d'), '2020-03-15');
checkIdentical('DateStr not a date',       formatVal('soon', 'DateStr'), '');
checkIdentical('DateStr three parts that are no date', formatVal('a-b-c', 'DateStr'), '');
checkIdentical('DateStr day that does not exist', formatVal('2026-02-30', 'DateStr'), '');
checkIdentical('DateStr a text of digits is no timestamp', Array(formatVal('12', 'DateStr'), formatVal('2026', 'DateStr'), formatVal('20261001', 'DateStr'), formatVal((string)mktime(12, 0, 0, 3, 15, 2020), 'DateStr')), Array('', '', '', ''));
checkIdentical('DateStr true, a float and a negative number are no timestamp', Array(formatVal(true, 'DateStr'), formatVal(12.7, 'DateStr'), formatVal(-5, 'DateStr')), Array('', '', ''));
checkIdentical('DateStr empty, null and array',Array(formatVal('', 'DateStr'), formatVal(null, 'DateStr'), formatVal(Array('2026-10-01'), 'DateStr')), Array('', '', ''));

// ShrDate and TxtDate
checkIdentical('ShrDate',                  formatVal('2026-10-01', 'ShrDate'), '1 Oct 2026');
checkIdentical('ShrDate with a time',      formatVal('2026-12-25 18:30:00', 'ShrDate'), '25 Dec 2026');
checkIdentical('ShrDate from a timestamp', formatVal(mktime(12, 0, 0, 3, 15, 2020), 'ShrDate'), '15 Mar 2020');
checkIdentical('ShrDate not a date',       formatVal('soon', 'ShrDate'), '');
checkIdentical('ShrDate day that does not exist', formatVal('2026-02-30', 'ShrDate'), '');
checkIdentical('ShrDate empty',            formatVal('', 'ShrDate'), '');
checkIdentical('ShrDate, TxtDate, WeekDay and FullDay: a text of digits is no timestamp', Array(formatVal('2026', 'ShrDate'), formatVal('2026', 'TxtDate'), formatVal('2026', 'WeekDay'), formatVal('2026', 'FullDay')), Array('', '', '', ''));
checkIdentical('TxtDate',                  formatVal('2026-10-01', 'TxtDate'), '1 October 2026');
checkIdentical('TxtDate single digits',    formatVal('2026-3-5', 'TxtDate'), '5 March 2026');
checkIdentical('TxtDate not a date',       formatVal('2026-13-01', 'TxtDate'), '');
checkIdentical('TxtDate array',            formatVal(Array('2026-10-01'), 'TxtDate'), '');

// WeekDay and FullDay
checkIdentical('WeekDay',                  formatVal('2026-10-01', 'WeekDay'), 'Thursday');
checkIdentical('FullDay today',            formatVal(date('Y-m-d'), 'FullDay'), 'Today');
checkIdentical('FullDay yesterday',        formatVal(date('Y-m-d', time()-86400), 'FullDay'), 'Yesterday');
checkIdentical('FullDay tomorrow',         formatVal(date('Y-m-d', time()+86400), 'FullDay'), 'Tomorrow');
checkIdentical('FullDay other day',        formatVal('2020-03-15', 'FullDay'), 'Sunday, 15 March');
checkIdentical('WeekDay from a timestamp', formatVal(mktime(12, 0, 0, 3, 15, 2020), 'WeekDay'), 'Sunday');
checkIdentical('WeekDay not a date',       Array(formatVal('soon', 'WeekDay'), formatVal('a-b-c', 'WeekDay'), formatVal('', 'WeekDay')), Array('', '', ''));
checkIdentical('FullDay not a date',       Array(formatVal('soon', 'FullDay'), formatVal('2026-13-01', 'FullDay'), formatVal(Array(), 'FullDay')), Array('', '', ''));

// DateSpn
checkIdentical('DateSpn same day',         formatVal('2026-12-01', 'DateSpn', '2026-12-01'), '01 December 2026');
checkIdentical('DateSpn same month',       formatVal('2026-12-01', 'DateSpn', '2026-12-05'), 'From 01 until 05 December 2026');
checkIdentical('DateSpn same year',        formatVal('2026-11-28', 'DateSpn', '2026-12-03'), 'From 28 November until 03 December 2026');
checkIdentical('DateSpn across years',     formatVal('2026-12-28', 'DateSpn', '2027-01-03'), 'From 28 December 2026 until 03 January 2027');
checkIdentical('DateSpn same month, next year', formatVal('2026-12-28', 'DateSpn', '2027-12-03'), 'From 28 December 2026 until 03 December 2027');
checkIdentical('DateSpn same day, next year',   formatVal('2026-12-01', 'DateSpn', '2027-12-01'), 'From 01 December 2026 until 01 December 2027');
checkIdentical('DateSpn without an end',   formatVal('2026-12-01', 'DateSpn'), '');
checkIdentical('DateSpn with an empty end, or no end and no start', Array(formatVal('2026-12-01', 'DateSpn', ''), formatVal('2026-12-01', 'DateSpn', '0'), formatVal('soon', 'DateSpn')), Array('', '', ''));
checkIdentical('DateSpn between two timestamps', formatVal(mktime(12, 0, 0, 12, 1, 2026), 'DateSpn', mktime(12, 0, 0, 12, 5, 2026)), 'From 01 until 05 December 2026');
checkIdentical('DateSpn end that is a text of digits', formatVal('2026-12-01', 'DateSpn', '2026'), '');
checkIdentical('DateSpn ends with a time', formatVal('2026-12-01 20:00:00', 'DateSpn', '2026-12-05 22:00:00'), 'From 01 until 05 December 2026');
checkIdentical('DateSpn start that is no date', formatVal('soon', 'DateSpn', '2026-12-05'), '');
checkIdentical('DateSpn end that is no date', Array(formatVal('2026-12-01', 'DateSpn', 'later'), formatVal('2026-12-01', 'DateSpn', '2026-02-30')), Array('', ''));

// Unknown format : the value is returned untouched
checkIdentical('unknown format',           formatVal('x', 'Nope'), 'x');

// saveValue replaces the wrapped value
checkIdentical('replaceWrapped', runCatchThrown(function(){ $varObjc = new Variable('1'); $varObjc->replaceWrapped('<b>2</b>'); return $varObjc->castWrappedVal('OutText'); }), '2');

// OutText must keep behaving like the filter it replaced, for as long as PHP still ships it
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
		$newText = formatVal($rndText, 'OutText');
		if( $newText!==$oldText && count($diffLst)<5 ){
			$diffLst[] = Array('input'=>$rndText, 'old'=>$oldText, 'new'=>$newText);
		}
	}
	checkIdentical('OutText equals FILTER_SANITIZE_STRING on 20000 random inputs', $diffLst, Array());
}else{
	recordSkipping('OutText equals FILTER_SANITIZE_STRING', 'this PHP no longer has the filter');
}

finishChecking();
