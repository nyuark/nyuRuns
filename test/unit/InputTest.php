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
	return runCatchThrown(function() use ($fieldsDefn, $givenVals){
		$inptObj = new Input( null, 'TESTS', $fieldsDefn, 'TESTS' );
		return $inptObj->parseValuesDct($givenVals);
	});
}

/**
 * Passes values through a definition of fields, and gives the required
 * fields found missing or empty.
 *
 * @param String|Array $fieldsDefn The definition.
 * @param Array        $givenVals  Values as a request would send them.
 * @return mixed The names of the fields, or a 'THROWN ...' string.
 */
function inputMiss( String|Array $fieldsDefn, Array $givenVals ): Mixed {
	return runCatchThrown(function() use ($fieldsDefn, $givenVals){
		$inptObj = new Input( null, 'TESTS', $fieldsDefn, 'TESTS' );
		$inptObj->parseValuesDct($givenVals);
		return $inptObj->getMissingFlds();
	});
}

$sentArr = Array('name'=>'<b>Ann</b>', 'mail'=>'ann @example.org', 'seats'=>'12 seats', 'extra'=>'left out');

// What a definition keeps
checkIdentical('all : every value, as sent', inputVals('all', $sentArr), $sentArr);
checkIdentical('none : nothing', inputVals('none', $sentArr), Array());
checkIdentical('an empty definition keeps nothing', Array(inputVals('', $sentArr), inputVals(Array(), $sentArr)), Array(Array(), Array()));
checkIdentical('a list of names keeps them as sent', inputVals(Array('name', 'seats'), $sentArr), Array('name'=>'<b>Ann</b>', 'seats'=>'12 seats'));
checkIdentical('a field without a format is kept as sent', inputVals(Array('name'=>''), $sentArr), Array('name'=>'<b>Ann</b>'));
checkIdentical('a field the request did not send is not made up', inputVals(Array('name'=>'OutText', 'phone'=>'PhoneNb'), $sentArr), Array('name'=>'Ann'));
checkIdentical('the names of the first level are matched whatever their case', inputVals(Array('Name'=>'OutText'), Array('NAME'=>'<i>x</i>')), Array('NAME'=>'x'));

// The formats
checkIdentical('each field is given its format', inputVals(Array('name'=>'OutText', 'mail'=>'EmlAddr', 'seats'=>'Integer'), $sentArr), Array('name'=>'Ann', 'mail'=>'ann@example.org', 'seats'=>12));
checkIdentical('a format with its option', inputVals(Array('since'=>'CustDte(Y/m/d)', 'until'=>'CustDte'), Array('since'=>'2026-10-01', 'until'=>'2026-10-01')), Array('since'=>'2026/10/01', 'until'=>'01/10/26'));
checkIdentical('an option may hold a colon and brackets', inputVals(Array('since'=>'CustDte(d.m.Y (H:i))'), Array('since'=>'2026-10-01')), Array('since'=>'01.10.2026 (12:00)'));
checkIdentical('a value that is not valid is emptied', inputVals(Array('mail'=>'EmlAddr', 'site'=>'UrlAddr', 'phone'=>'PhoneNb'), Array('mail'=>'nobody', 'site'=>'javascript:alert(1)', 'phone'=>'12')), Array('mail'=>'', 'site'=>'', 'phone'=>''));
checkIdentical('a value the format cannot read is emptied', inputVals(Array('since'=>'CustDte', 'day'=>'WeekDay'), Array('since'=>'soon', 'day'=>'a-b-c')), Array('since'=>'', 'day'=>''));
checkIdentical('an array is formatted value by value', inputVals(Array('tags'=>'UrlText'), Array('tags'=>Array('Été à Genève', 'x'=>Array('Deux Mots')))), Array('tags'=>Array('ete-a-geneve', 'x'=>Array('deux-mots'))));
checkIdentical('a password kept as sent is not altered', inputVals(Array('pass'=>''), Array('pass'=>'p<a"s\'s&w>d')), Array('pass'=>'p<a"s\'s&w>d'));

// Fields holding fields
$nestDef = Array('filters'=>Array('search'=>'OutText', 'price'=>'FloatNb', 'range'=>Array('from'=>'Integer')), 'sorting'=>'');
checkIdentical('fields are read level by level', inputVals($nestDef, Array('filters'=>Array('search'=>'<i>night</i>', 'price'=>'12.50 CHF', 'other'=>'x', 'range'=>Array('from'=>'3rd', 'to'=>'9')), 'sorting'=>Array('^title'))), Array('filters'=>Array('search'=>'night', 'price'=>12.5, 'range'=>Array('from'=>3)), 'sorting'=>Array('^title')));
checkIdentical('below the first level, a name is matched with its case', inputVals($nestDef, Array('filters'=>Array('Search'=>'x'))), Array('filters'=>Array()));
checkIdentical('a value that should hold fields and does not is emptied', inputVals($nestDef, Array('filters'=>'search=x')), Array('filters'=>Array()));

// The definition written as a text
checkIdentical('a text of name:Format items', inputVals('name:OutText; mail:EmlAddr ;seats:Integer', $sentArr), Array('name'=>'Ann', 'mail'=>'ann@example.org', 'seats'=>12));
checkIdentical('a text: names alone, and an option holding a colon', inputVals('name;since:CustDte(H:i d/m)', Array('name'=>'<b>x</b>', 'since'=>'2026-10-01', 'more'=>1)), Array('name'=>'<b>x</b>', 'since'=>'12:00 01/10'));

// Definitions that are wrong
checkPregMatch('a format that does not exist is refused, the field named', inputVals(Array('name'=>'NoSuchFormat'), $sentArr), '/THROWN.*Input misconfigured: unknown format "NoSuchFormat" for the field "name"/');
checkPregMatch('a format badly written is refused', inputVals(Array('name'=>'Text Str('), $sentArr), '/THROWN.*Input misconfigured: "Text Str\(" is not a format/');
checkPregMatch('a format that does not exist is refused in a text too', inputVals('name:Nope', $sentArr), '/THROWN.*unknown format "Nope"/');

// Required fields : a name ending with '!'
checkIdentical('without a required field, nothing is missing', Array(inputMiss(Array('name'=>'OutText', 'phone'=>'PhoneNb'), Array()), inputMiss('all', Array()), inputMiss('none', Array())), Array(Array(), Array(), Array()));
checkIdentical('required fields that are not sent are named, in the order of the definition', inputMiss(Array('user!'=>'', 'code'=>'', 'pass!'=>''), Array('code'=>'123')), Array('user', 'pass'));
checkIdentical('a required field that is sent is not missing', inputMiss(Array('user!'=>'', 'pass!'=>''), Array('user'=>'ann', 'pass'=>'x')), Array());
checkIdentical('the mark is not part of the name of the field', inputVals(Array('name!'=>'OutText', 'pass!'=>''), $sentArr), Array('name'=>'Ann'));
checkIdentical('a required field sent empty is missing', inputMiss(Array('user!'=>'', 'pass!'=>'OutText', 'tags!'=>''), Array('user'=>'', 'pass'=>null, 'tags'=>Array())), Array('user', 'pass', 'tags'));
checkIdentical('a required field emptied by its format is missing', inputMiss(Array('mail!'=>'EmlAddr', 'since!'=>'CustDte(Y/m/d)', 'name!'=>'OutText'), Array('mail'=>'nobody', 'since'=>'soon', 'name'=>'<b></b>')), Array('mail', 'since', 'name'));
checkIdentical('a required number sent empty is missing, though its format gives 0', inputMiss(Array('seats!'=>'Integer', 'price!'=>'FloatNb'), Array('seats'=>'', 'price'=>'')), Array('seats', 'price'));
checkIdentical('0, false and a text of spaces are values', inputMiss(Array('seats!'=>'Integer', 'count!'=>'', 'agree!'=>'', 'pass!'=>''), Array('seats'=>'0', 'count'=>0, 'agree'=>false, 'pass'=>'   ')), Array());
checkIdentical('a number format never empties: a required number that is none passes as 0', Array(inputMiss(Array('seats!'=>'Integer'), Array('seats'=>'abc')), inputVals(Array('seats!'=>'Integer'), Array('seats'=>'abc'))), Array(Array(), Array('seats'=>0)));
checkIdentical('a required array holding nothing but empty values is missing', Array(inputMiss(Array('tags!'=>'UrlText'), Array('tags'=>Array('!!!', Array('')))), inputMiss(Array('tags!'=>'UrlText'), Array('tags'=>Array('!!!', 'Été')))), Array(Array('tags'), Array()));
checkIdentical('a required field is matched whatever its case, and named as the definition writes it', Array(inputMiss(Array('User!'=>''), Array('USER'=>'ann')), inputMiss(Array('User!'=>''), Array('NAME'=>'ann'))), Array(Array(), Array('User')));
checkIdentical('required names in a plain list', Array(inputMiss(Array('user!', 'pass!', 'code'), Array('user'=>'ann')), inputVals(Array('user!', 'pass!', 'code'), Array('user'=>'ann', 'more'=>1))), Array(Array('pass'), Array('user'=>'ann')));
checkIdentical('required names in a text', Array(inputMiss('user! ; mail!:EmlAddr;since:CustDte(Y/m/d)', Array('mail'=>'nobody')), inputVals('user! ; mail!:EmlAddr', Array('user'=>'ann', 'mail'=>'ann @example.org'))), Array(Array('user', 'mail'), Array('user'=>'ann', 'mail'=>'ann@example.org')));

// Required fields held by another
$needDef = Array('filters'=>Array('search!'=>'OutText', 'categ'=>''), 'range!'=>Array('from'=>'Integer', 'to!'=>'Integer'));
checkIdentical('a required field is required even when the field holding it is not sent', inputMiss($needDef, Array('range'=>Array('to'=>'9'))), Array('filters.search'));
checkIdentical('a required field holding fields is named alone when nothing is kept under it', Array(inputMiss($needDef, Array('filters'=>Array('search'=>'x'))), inputMiss($needDef, Array('filters'=>Array('search'=>'x'), 'range'=>Array('other'=>'1'))), inputMiss($needDef, Array('filters'=>Array('search'=>'x'), 'range'=>'1-9'))), Array(Array('range'), Array('range'), Array('range')));
checkIdentical('with a value kept under it, its own required fields are checked', inputMiss($needDef, Array('filters'=>Array('search'=>'x'), 'range'=>Array('from'=>'3'))), Array('range.to'));
checkIdentical('below the first level, a required name is matched with its case', inputMiss($needDef, Array('filters'=>Array('Search'=>'x'), 'range'=>Array('to'=>'9'))), Array('filters.search'));
checkIdentical('nothing missing at any level', inputMiss($needDef, Array('filters'=>Array('search'=>'x'), 'range'=>Array('to'=>'9'))), Array());
checkPregMatch('a format that does not exist is refused on a required field too, named without its mark', inputMiss(Array('name!'=>'NoSuchFormat'), $sentArr), '/THROWN.*unknown format "NoSuchFormat" for the field "name"/');

/**
 * Runs an action of the Forms fixture application with inputs, and
 * gives its status and what it answered.
 *
 * @param String $actionName Action asked for, as a request would write it.
 * @param Array  $givenVals  Values as a request would send them.
 * @return mixed The status code and the output, or a 'THROWN ...' string.
 */
function formsCall( String $actionName, Array $givenVals ): Mixed {
	return runCatchThrown(function() use ($actionName, $givenVals){
		$conTrol = new Controller( 'forms', $actionName, new Registry($givenVals) );
		return Array($conTrol->getStatusOfAct()['code'], $conTrol->getOutputOfAct());
	});
}

$formArr = Array('name'=>'<b>Ann</b>', 'mail'=>'ann @example.org', 'seats'=>'12', 'since'=>'2026-10-01', 'pass'=>'p<a"ss', 'tags'=>Array('Été'), 'filters'=>Array('search'=>'<i>night</i>', 'price'=>'9.5', 'other'=>'x'), 'extra'=>'left out');
$formOut = Array('NAME'=>'Ann', 'MAIL'=>'ann@example.org', 'SEATS'=>12, 'SINCE'=>'2026/10/01', 'PASS'=>'p<a"ss', 'TAGS'=>Array('ete'), 'FILTERS'=>Array('search'=>'night', 'price'=>9.5));

// The definition of an action, applied by the Controller
checkIdentical('an action with a definition gets its fields, formatted', formsCall('transmit', $formArr), Array(200, $formOut));
checkIdentical('the action asked for in another case gets the same definition', formsCall('TRANSMIT', $formArr), Array(200, $formOut));
checkIdentical('the default action gets its definition too', formsCall('', $formArr), Array(200, $formOut));
checkIdentical('an action without a definition gets no input', formsCall('freeForm', Array('name'=>'<b>Ann</b>', 'extra'=>'kept')), Array(200, Array()));
checkIdentical('{"fieldSet": "all"} keeps every input the preset built', runCatchThrown(function(){ return (new Controller( 'probe', 'userPass', new Registry(Array('user'=>'a', 'pass'=>'b')) ))->getStatusOfAct(); }), Array('code'=>401, 'mssg'=>'Credentials read'));
checkPregMatch('a definition naming a format that does not exist stops the run', formsCall('noFormat', $formArr), '/THROWN.*Input misconfigured: unknown format "NoSuchFormat"/');
checkPregMatch('a file that is not a definition stops the run', formsCall('badInput', $formArr), '/THROWN.*Input misconfigured: Forms\/input\/badInput\.json is not a definition of fields/');
checkIdentical('an action that does not exist reads no file', formsCall('../send', $formArr)[0], 404);

/**
 * Runs an action of the Forms fixture application with inputs, and
 * gives its whole status and what it answered.
 *
 * @param String $actionName Action asked for, as a request would write it.
 * @param Array  $givenVals  Values as a request would send them.
 * @return mixed The status code, its message and the output, or a 'THROWN ...' string.
 */
function formsStat( String $actionName, Array $givenVals ): Mixed {
	return runCatchThrown(function() use ($actionName, $givenVals){
		$conTrol = new Controller( 'forms', $actionName, new Registry($givenVals) );
		$statArr = $conTrol->getStatusOfAct();
		return Array($statArr['code'], $statArr['mssg'], $conTrol->getOutputOfAct());
	});
}

// Required fields, checked by the Controller before the action runs
$needArr = Array('name'=>'<b>Ann</b>', 'mail'=>'ann @example.org', 'filters'=>Array('search'=>'night'), 'extra'=>'left out');
checkIdentical('an action whose required fields are all there runs', formsStat('needForm', $needArr), Array(200, '', Array('NAME'=>'Ann', 'MAIL'=>'ann@example.org', 'FILTERS'=>Array('search'=>'night'))));
checkIdentical('a required field that is missing refuses the request with a 400, the action not run', formsStat('needForm', Array('name'=>'Ann', 'filters'=>Array('search'=>'night'))), Array(400, 'Missing or invalid input: mail', null));
checkIdentical('the 400 names every field, missing, empty or not valid', formsStat('needForm', Array('name'=>'', 'mail'=>'nobody', 'seats'=>'3')), Array(400, 'Missing or invalid input: name, mail, filters.search', null));
checkIdentical('the 400 shows no value', formsStat('needForm', Array('name'=>'Ann', 'mail'=>'se<cr>et', 'filters'=>Array('search'=>'x'))), Array(400, 'Missing or invalid input: mail', null));
checkIdentical('the action asked for in another case has the same required fields', formsStat('NEEDFORM', Array())[0], 400);
checkIdentical('a field that is not required may be left out', formsStat('needForm', $needArr)[0], 200);

// The definition the Auth application gives for its login
$authDef = json_decode( file_get_contents(TOP_DIR.DS.'runs'.DS.'Auth'.DS.'input'.DS.'login.json'), true );
checkIdentical('Auth: the login requires its userName and its passWord, not its sentCode', Array(inputMiss($authDef['fieldSet'] ?? 'none', Array()), inputMiss($authDef['fieldSet'] ?? 'none', Array('userName'=>'ann', 'passWord'=>'')), inputMiss($authDef['fieldSet'] ?? 'none', Array('userName'=>'ann', 'passWord'=>'x'))), Array(Array('userName', 'passWord'), Array('passWord'), Array()));
checkIdentical('Auth: the login keeps its three fields as sent', inputVals($authDef['fieldSet'] ?? 'none', Array('userName'=>"o'neil", 'passWord'=>'p<a"s\'s&w>d', 'sentCode'=>'012345', 'debug'=>'1')), Array('userName'=>"o'neil", 'passWord'=>'p<a"s\'s&w>d', 'sentCode'=>'012345'));

// A definition for a list, as the Shows example gave it: nested filters, an order and limits, kept as sent
$showDef = Array('fieldSet'=>Array('filters'=>Array('search'=>'', 'categ'=>''), 'sorting'=>'', 'indexes'=>''));
checkIdentical('a list keeps its filters, its order and its limits, as sent', inputVals($showDef['fieldSet'] ?? 'none', Array('filters'=>Array('search'=>"L'île", 'categ'=>'kids', 'other'=>'x'), 'sorting'=>'^title', 'indexes'=>'10,20', 'debug'=>'1')), Array('filters'=>Array('search'=>"L'île", 'categ'=>'kids'), 'sorting'=>'^title', 'indexes'=>'10,20'));

finishChecking();
