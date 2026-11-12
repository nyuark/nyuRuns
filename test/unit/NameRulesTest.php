<?php

// The naming rules read for the audit (test/lib/namerules.php), on a small file
// shaped as NAMERULES.md (test/fixtures/namerules.md), then on NAMERULES.md itself.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/namerules.php';

$rulesDoc = TST_DIR.DS.'fixtures'.DS.'namerules.md';
$rulesRaw = str_replace("\r", '', strval(file_get_contents($rulesDoc)));

// A table read
checkIdentical('the rows of the first table of a section, without its header', parseRuleTable($rulesRaw, 3), Array(
	Array('`get`', 'give a value', 'retrieve, `fetch`'),
	Array('`read`', 'read from a source', ''),
	Array('`make`', 'make something new', 'build')
));
checkIdentical('the terms of one column: what the other columns hold in backticks is left out', parseTermsList(parseRuleTable($rulesRaw, 3), Array(0)), Array('get', 'read', 'make'));
checkIdentical('a cell holding two terms gives both; a cell without backticks gives none', parseTermsList(parseRuleTable($rulesRaw, 4), Array(0)), Array('Text', 'Secs', 'Mins'));
checkIdentical('the forms: columns 2, 4 and 6, the empty cells left out', parseTermsList(parseRuleTable($rulesRaw, 5), Array(1, 3, 5)), Array('Accnt', 'Factr', 'Proj', 'Char', 'Chars', 'Mthd'));
checkIdentical('a section without a table, a section that is not there', Array(parseRuleTable($rulesRaw, 1), parseRuleTable($rulesRaw, 9)), Array(Array(), Array()));

// A list written in a sentence
checkIdentical('the terms of the first sentence under a bold label', Array(parseLabelList($rulesRaw, 3, 'Question verbs'), parseLabelList($rulesRaw, 3, 'Domain verbs')), Array(Array('is', 'has'), Array('sign', 'seal', 'delete', 'mark')));
checkIdentical('the exceptions and the standard abbreviations stop at their first sentence', Array(parseLabelList($rulesRaw, 5, 'Exceptions decided by the project manager'), parseLabelList($rulesRaw, 5, 'Standard abbreviations')), Array(Array('Obj', 'Col'), Array('Url', 'Html')));
checkIdentical('a label read in its own section only; one that is not there', Array(parseLabelList($rulesRaw, 4, 'Question verbs'), parseLabelList($rulesRaw, 3, 'Other verbs')), Array(Array(), Array()));

// Every list at once
checkIdentical('every list of the file', readNamingDict($rulesDoc), Array(
	'verb'=>Array('get', 'read', 'make'),
	'question'=>Array('is', 'has'),
	'domain'=>Array('sign', 'seal', 'delete', 'mark'),
	'kind'=>Array('Text', 'Secs', 'Mins'),
	'form'=>Array('Accnt', 'Factr', 'Proj', 'Char', 'Chars', 'Mthd'),
	'exception'=>Array('Obj', 'Col'),
	'standard'=>Array('Url', 'Html')
));

// A table or a list missing is a failure, never an empty list
$lostDoc = inTemporaryDir('notable.md');
file_put_contents($lostDoc, preg_replace('/^\| `Text`.*?(?=^## 5)/ms', "\n", $rulesRaw));
checkPregMatch('a table missing stops the reading', runCatchThrown(function() use ( $lostDoc ){ return readNamingDict($lostDoc); }), '/^THROWN RuntimeException: The naming rules give no list "kind"/');
file_put_contents($lostDoc, str_replace('**Question verbs**', 'Question verbs', $rulesRaw));
checkPregMatch('a list missing stops it too', runCatchThrown(function() use ( $lostDoc ){ return readNamingDict($lostDoc); }), '/^THROWN RuntimeException: The naming rules give no list "question"/');
checkForThrows('and so does a file that is not there', function(){ readNamingDict(TMP_DIR.DS.'nofile.md'); }, 'RuntimeException');

// The pieces of a name, and its area
checkIdentical('a name split into its pieces', Array(splitWordsList('readRouteList'), splitWordsList('isLogged'), splitWordsList('utf8Text'), splitWordsList('rawURLValue'), splitWordsList('response')), Array(Array('read', 'Route', 'List'), Array('is', 'Logged'), Array('utf8', 'Text'), Array('raw', 'URL', 'Value'), Array('response')));
checkIdentical('the area of a file', array_map('findAreaOfFile', Array('src/Tools/Router.php', 'src/Data/Mail/Mailer.php', 'src/Output/Response.php', 'src/Runner.php', 'runs/Users/Actions.php', 'test/run.php')), Array('Tools', 'Data', 'App', 'App', 'runs', ''));

// The checks, on the lists of the fixture
$nameDict = readNamingDict($rulesDoc);
checkIdentical('a local: one word of 8, two words, a question verb first', Array(checkLocalName('response', $nameDict), checkLocalName('fileName', $nameDict), checkLocalName('newEntry', $nameDict), checkLocalName('isLogged', $nameDict)), Array('', '', '', ''));
checkIdentical('a local refused: its length, three pieces, a piece under 3 letters', Array(checkLocalName('endDate', $nameDict), checkLocalName('oneRowOk', $nameDict), checkLocalName('dbHandle', $nameDict), checkLocalName('File_one', $nameDict)), Array('7 characters, not 8', '3 pieces, not one or two', 'the piece "db" is under 3 letters', 'not camelCase'));
checkIdentical('a loop variable: one or nth + a whole word of 3 letters', Array(checkLoopLabel('oneRow', $nameDict, Array()), checkLoopLabel('nthRow', $nameDict, Array()), checkLoopLabel('rowIdx', $nameDict, Array()), checkLoopLabel('oneCol', $nameDict, Array()), checkLoopLabel('oneFnd', $nameDict, Array())), Array('', '', 'not one or nth + an item of 3 letters', 'the item "Col" is not a whole word', 'the item "Fnd" is not a whole word'));
checkIdentical('with the whole words listed, the item is one of them', Array(checkLoopLabel('oneRow', $nameDict, Array('row'=>true)), checkLoopLabel('oneLin', $nameDict, Array('row'=>true))), Array('', 'the item "Lin" is not a whole word'));
checkIdentical('the pieces: forms, exceptions, abbreviations, the words of the rules; the others by their look', Array(checkNameWords('readAccntUrl', $nameDict, Array()), checkNameWords('makeColText', $nameDict, Array()), checkNameWords('givenUserName', $nameDict, Array()), checkNameWords('accessRgst', $nameDict, Array()), checkNameWords('usrLst', $nameDict, Array())), Array('', '', '', 'neither a word nor a listed form: Rgst', 'neither a word nor a listed form: usr, Lst'));
checkIdentical('the pieces, with the whole words listed', Array(checkNameWords('givenUserName', $nameDict, Array('given'=>true, 'user'=>true)), checkNameWords('oneRow', $nameDict, Array('row'=>true))), Array('neither a word nor a listed form: Name', ''));
checkIdentical('a method starts with a verb, a question verb or a domain verb', Array(checkFirstVerb('readRouteList', 'Array', $nameDict), checkFirstVerb('hasWrongSecret', 'Bool', $nameDict), checkFirstVerb('signTokenText', 'String', $nameDict), checkFirstVerb('makeUserEntry', 'Bool', $nameDict)), Array('', '', '', ''));
checkIdentical('a method that does not', Array(checkFirstVerb('finalErrorText', 'String', $nameDict), checkFirstVerb('validateOption', 'Bool', $nameDict)), Array('"final" is not a verb', 'returns a Bool: "validate" is neither a question verb nor an action verb'));
checkIdentical('a Bool starts with a question verb', Array(checkBoolNames('isLoggedIn', $nameDict), checkBoolNames('hasRole', $nameDict), checkBoolNames('docFlag', $nameDict)), Array('', '', 'a Bool: "doc" is not a question verb'));
checkIdentical('the last word: a kind term, or reported', Array(checkFinalKind('errorText', $nameDict), checkFinalKind('holdSecs', $nameDict), checkFinalKind('requiredRole', $nameDict)), Array('', '', 'ends with "Role", not a kind term'));

// NAMERULES.md itself: the shape the audit relies on
$realDict = runCatchThrown(function(){ return readNamingDict(TOP_DIR.DS.'NAMERULES.md'); });
checkTrueValue('NAMERULES.md gives every list', is_array($realDict));
if( is_array($realDict) ){
	checkIdentical('its question verbs and its exceptions', Array($realDict['question'], $realDict['exception']), Array(Array('is', 'has', 'can', 'needs'), Array('Obj', 'Pos', 'Reg', 'Col')));
	checkIdentical('its verbs, domain verbs, kind terms, forms and abbreviations hold what they should', Array(in_array('get', $realDict['verb'], true), in_array('wipe', $realDict['verb'], true), in_array('retrieve', $realDict['verb'], true), in_array('sign', $realDict['domain'], true), in_array('mark', $realDict['domain'], true), in_array('cook', $realDict['domain'], true), in_array('Mins', $realDict['kind'], true), in_array('Obj', $realDict['kind'], true), in_array('Chars', $realDict['form'], true), in_array('Wrtr', $realDict['form'], true), in_array('Url', $realDict['standard'], true), in_array('Apr1', $realDict['standard'], true)),
		Array(true, true, false, true, true, false, true, true, true, true, true, true));
}

finishChecking();
