<?php

// Tools\Input : the fields a definition keeps and the format it gives each,
// and the definition an application gives for an action (input/<action>.json),
// applied by its Controller.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');

require __DIR__.'/../lib/harness.php';

use nyu\App\Controller;
use nyu\Tools\Input;
use nyu\Tools\Registry;

/**
 * Passes values through a definition of fields.
 *
 * @param String|Array $fieldsDefn The definition.
 * @param Array        $givenVals  Values as a request would send them.
 * @return mixed The values kept, or a 'THROWN ...' string.
 */
function inputVals( String|Array $fieldsDefn, Array $givenVals ): Mixed {
	return runSafely(function() use ($fieldsDefn, $givenVals){
		$inptObj = new Input( null, 'TESTS', $fieldsDefn, 'TESTS' );
		return $inptObj->parseVals($givenVals);
	});
}

$sentArr = Array('name'=>'<b>Ann</b>', 'mail'=>'ann @example.org', 'seats'=>'12 seats', 'extra'=>'left out');

// What a definition keeps
checkSame('all : every value, as sent', inputVals('all', $sentArr), $sentArr);
checkSame('none : nothing', inputVals('none', $sentArr), Array());
checkSame('an empty definition keeps nothing', Array(inputVals('', $sentArr), inputVals(Array(), $sentArr)), Array(Array(), Array()));
checkSame('a list of names keeps them as sent', inputVals(Array('name', 'seats'), $sentArr), Array('name'=>'<b>Ann</b>', 'seats'=>'12 seats'));
checkSame('a field without a format is kept as sent', inputVals(Array('name'=>''), $sentArr), Array('name'=>'<b>Ann</b>'));
checkSame('a field the request did not send is not made up', inputVals(Array('name'=>'TextStr', 'phone'=>'PhoneNb'), $sentArr), Array('name'=>'Ann'));
checkSame('the names of the first level are matched whatever their case', inputVals(Array('Name'=>'TextStr'), Array('NAME'=>'<i>x</i>')), Array('NAME'=>'x'));

// The formats
checkSame('each field is given its format', inputVals(Array('name'=>'TextStr', 'mail'=>'EmlAddr', 'seats'=>'Integer'), $sentArr), Array('name'=>'Ann', 'mail'=>'ann@example.org', 'seats'=>12));
checkSame('a format with its option', inputVals(Array('since'=>'DateStr(Y/m/d)', 'until'=>'DateStr'), Array('since'=>'2026-10-01', 'until'=>'2026-10-01')), Array('since'=>'2026/10/01', 'until'=>'01/10/26'));
checkSame('an option may hold a colon and brackets', inputVals(Array('since'=>'DateStr(d.m.Y (H:i))'), Array('since'=>'2026-10-01')), Array('since'=>'01.10.2026 (12:00)'));
checkSame('a value that is not valid is emptied', inputVals(Array('mail'=>'EmlAddr', 'site'=>'UrlAddr', 'phone'=>'PhoneNb'), Array('mail'=>'nobody', 'site'=>'javascript:alert(1)', 'phone'=>'12')), Array('mail'=>'', 'site'=>'', 'phone'=>''));
checkSame('a value the format cannot read is emptied', inputVals(Array('since'=>'DateStr', 'day'=>'WeekDay'), Array('since'=>'soon', 'day'=>'a-b-c')), Array('since'=>'', 'day'=>''));
checkSame('an array is formatted value by value', inputVals(Array('tags'=>'UrlText'), Array('tags'=>Array('Été à Genève', 'x'=>Array('Deux Mots')))), Array('tags'=>Array('ete-a-geneve', 'x'=>Array('deux-mots'))));
checkSame('a password kept as sent is not altered', inputVals(Array('pass'=>''), Array('pass'=>'p<a"s\'s&w>d')), Array('pass'=>'p<a"s\'s&w>d'));

// Fields holding fields
$nestDef = Array('filters'=>Array('search'=>'TextStr', 'price'=>'FloatNb', 'range'=>Array('from'=>'Integer')), 'sorting'=>'');
checkSame('fields are read level by level', inputVals($nestDef, Array('filters'=>Array('search'=>'<i>night</i>', 'price'=>'12.50 CHF', 'other'=>'x', 'range'=>Array('from'=>'3rd', 'to'=>'9')), 'sorting'=>Array('^title'))), Array('filters'=>Array('search'=>'night', 'price'=>12.5, 'range'=>Array('from'=>3)), 'sorting'=>Array('^title')));
checkSame('below the first level, a name is matched with its case', inputVals($nestDef, Array('filters'=>Array('Search'=>'x'))), Array('filters'=>Array()));
checkSame('a value that should hold fields and does not is emptied', inputVals($nestDef, Array('filters'=>'search=x')), Array('filters'=>Array()));

// The definition written as a text
checkSame('a text of name:Format items', inputVals('name:TextStr; mail:EmlAddr ;seats:Integer', $sentArr), Array('name'=>'Ann', 'mail'=>'ann@example.org', 'seats'=>12));
checkSame('a text: names alone, and an option holding a colon', inputVals('name;since:DateStr(H:i d/m)', Array('name'=>'<b>x</b>', 'since'=>'2026-10-01', 'more'=>1)), Array('name'=>'<b>x</b>', 'since'=>'12:00 01/10'));

// Definitions that are wrong
checkLike('a format that does not exist is refused, the field named', inputVals(Array('name'=>'NoSuchFormat'), $sentArr), '/THROWN.*Input misconfigured: unknown format "NoSuchFormat" for the field "name"/');
checkLike('a format badly written is refused', inputVals(Array('name'=>'Text Str('), $sentArr), '/THROWN.*Input misconfigured: "Text Str\(" is not a format/');
checkLike('a format that does not exist is refused in a text too', inputVals('name:Nope', $sentArr), '/THROWN.*unknown format "Nope"/');

/**
 * Runs an action of the Forms fixture application with inputs, and
 * gives its status and what it answered.
 *
 * @param String $actionName Action asked for, as a request would write it.
 * @param Array  $givenVals  Values as a request would send them.
 * @return mixed The status code and the output, or a 'THROWN ...' string.
 */
function formsCall( String $actionName, Array $givenVals ): Mixed {
	return runSafely(function() use ($actionName, $givenVals){
		$conTrol = new Controller( 'forms', $actionName, new Registry($givenVals) );
		return Array($conTrol->getStatus()['code'], $conTrol->getOutput());
	});
}

$formArr = Array('name'=>'<b>Ann</b>', 'mail'=>'ann @example.org', 'seats'=>'12', 'since'=>'2026-10-01', 'pass'=>'p<a"ss', 'tags'=>Array('Été'), 'filters'=>Array('search'=>'<i>night</i>', 'price'=>'9.5', 'other'=>'x'), 'extra'=>'left out');
$formOut = Array('NAME'=>'Ann', 'MAIL'=>'ann@example.org', 'SEATS'=>12, 'SINCE'=>'2026/10/01', 'PASS'=>'p<a"ss', 'TAGS'=>Array('ete'), 'FILTERS'=>Array('search'=>'night', 'price'=>9.5));

// The definition of an action, applied by the Controller
checkSame('an action with a definition gets its fields, formatted', formsCall('send', $formArr), Array(200, $formOut));
checkSame('the action asked for in another case gets the same definition', formsCall('SEND', $formArr), Array(200, $formOut));
checkSame('the default action gets its definition too', formsCall('', $formArr), Array(200, $formOut));
checkSame('an action without a definition keeps the inputs as the preset built them', formsCall('free', Array('name'=>'<b>Ann</b>', 'extra'=>'kept')), Array(200, Array('NAME'=>'<b>Ann</b>', 'EXTRA'=>'kept')));
checkLike('a definition naming a format that does not exist stops the run', formsCall('wrong', $formArr), '/THROWN.*Input misconfigured: unknown format "NoSuchFormat"/');
checkLike('a file that is not a definition stops the run', formsCall('broke', $formArr), '/THROWN.*Input misconfigured: Forms\/input\/broke\.json is not a definition of fields/');
checkSame('an action that does not exist reads no file', formsCall('../send', $formArr)[0], 500);

// The definition the Shows application gives for its list
$showDef = json_decode( file_get_contents(TOP_DIR.DS.'runs'.DS.'Shows'.DS.'input'.DS.'index.json'), true );
checkSame('Shows: the list keeps its filters, its order and its limits, as sent', inputVals($showDef['fields'] ?? 'none', Array('filters'=>Array('search'=>"L'île", 'categ'=>'kids', 'other'=>'x'), 'sorting'=>'^title', 'indexes'=>'10,20', 'debug'=>'1')), Array('filters'=>Array('search'=>"L'île", 'categ'=>'kids'), 'sorting'=>'^title', 'indexes'=>'10,20'));

endChecks();
