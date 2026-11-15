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
checkIdentical('a cell holding several terms gives them all; a cell without backticks gives none', parseTermsList(parseRuleTable($rulesRaw, 4), Array(0)), Array('Text', 'Txt', 'Strng', 'Vals', 'Val', 'Secs', 'Mins'));
checkIdentical('the writings: columns 2, 4 and 6, each cell its ladder, a writing listed once, the empty cells left out', parseTermsList(parseRuleTable($rulesRaw, 5), Array(1, 3, 5)), Array('Acct', 'Accnt', 'Col', 'Clmn', 'Colmn', 'Frm', 'Form', 'Lvl', 'Levl', 'Level', 'Mth', 'Mthd', 'Methd', 'Rgx', 'Rgxp', 'Row', 'Ntry', 'Entry', 'Tag'));
checkIdentical('a section without a table, a section that is not there', Array(parseRuleTable($rulesRaw, 1), parseRuleTable($rulesRaw, 9)), Array(Array(), Array()));

// A list written in a sentence
checkIdentical('the terms of the first sentence under a bold label', Array(parseLabelList($rulesRaw, 3, 'Question verbs'), parseLabelList($rulesRaw, 3, 'Domain verbs')), Array(Array('is', 'has', 'was'), Array('sign', 'seal', 'delete', 'mark')));
checkIdentical('the exceptions and the standard abbreviations stop at their first sentence', Array(parseLabelList($rulesRaw, 5, 'Exceptions decided by the project manager'), parseLabelList($rulesRaw, 5, 'Standard abbreviations')), Array(Array('Obj', 'Col'), Array('Bom', 'Db', 'Id', 'Id3', 'Id3v1', 'Mp3', 'Url', 'Html')));
checkIdentical('a label read in its own section only; one that is not there', Array(parseLabelList($rulesRaw, 4, 'Question verbs'), parseLabelList($rulesRaw, 3, 'Other verbs')), Array(Array(), Array()));

// Every list at once
checkIdentical('every list of the file', readNamingDict($rulesDoc), Array(
	'verb'=>Array('get', 'read', 'make'),
	'question'=>Array('is', 'has', 'was'),
	'domain'=>Array('sign', 'seal', 'delete', 'mark'),
	'kind'=>Array('Text', 'Txt', 'Strng', 'Vals', 'Val', 'Secs', 'Mins'),
	'form'=>Array('Acct', 'Accnt', 'Col', 'Clmn', 'Colmn', 'Frm', 'Form', 'Lvl', 'Levl', 'Level', 'Mth', 'Mthd', 'Methd', 'Rgx', 'Rgxp', 'Row', 'Ntry', 'Entry', 'Tag'),
	'exception'=>Array('Obj', 'Col'),
	'standard'=>Array('Bom', 'Db', 'Id', 'Id3', 'Id3v1', 'Mp3', 'Url', 'Html'),
	'word'=>Array('Form', 'Level', 'Row', 'Tag')
));

// A table or a list missing is a failure, never an empty list
$lostFile = inTemporaryDir('notable.md');
file_put_contents($lostFile, preg_replace('/^\| `Text`.*?(?=^## 5)/ms', "\n", $rulesRaw));
checkPregMatch('a table missing stops the reading', runCatchThrown(function() use ( $lostFile ){ return readNamingDict($lostFile); }), '/^THROWN RuntimeException: The naming rules give no list "kind"/');
file_put_contents($lostFile, str_replace('**Question verbs**', 'Question verbs', $rulesRaw));
checkPregMatch('a list missing stops it too', runCatchThrown(function() use ( $lostFile ){ return readNamingDict($lostFile); }), '/^THROWN RuntimeException: The naming rules give no list "question"/');
checkForThrows('and so does a file that is not there', function(){ readNamingDict(TMP_DIR.DS.'nofile.md'); }, 'RuntimeException');

// The pieces of a name, and its area
$nameDict = readNamingDict($rulesDoc);
checkIdentical('a name split into its pieces', Array(splitWordsList('readRouteList'), splitWordsList('isLogged'), splitWordsList('utf8Text'), splitWordsList('rawURLValue'), splitWordsList('response')), Array(Array('read', 'Route', 'List'), Array('is', 'Logged'), Array('utf8', 'Text'), Array('raw', 'URL', 'Value'), Array('response')));
checkIdentical('the digits closing a piece belong to it', Array(splitWordsList('lvl1Vals', $nameDict), splitWordsList('hasBom16', $nameDict), splitWordsList('isMp3Frm', $nameDict)), Array(Array('lvl1', 'Vals'), Array('has', 'Bom16'), Array('is', 'Mp3', 'Frm')));
checkIdentical('a standard abbreviation holding digits is one piece, once the rules are given', Array(splitWordsList('id3v1Tag', $nameDict), splitWordsList('oldId3v1Tag', $nameDict), splitWordsList('id3v1Tag'), splitWordsList('id3v2Tag', $nameDict)), Array(Array('id3v1', 'Tag'), Array('old', 'Id3v1', 'Tag'), Array('id3', 'v1', 'Tag'), Array('id3', 'v2', 'Tag')));
checkIdentical('the area of a file', array_map('findAreaOfFile', Array('src/Tools/Router.php', 'src/Data/Mail/Mailer.php', 'src/Output/Response.php', 'src/Runner.php', 'runs/Users/Actions.php', 'test/run.php')), Array('Tools', 'Data', 'App', 'App', 'runs', ''));

// A local: 8 characters, one word or two
checkIdentical('a local: one word of 8, two words', Array(checkLocalName('response', $nameDict), checkLocalName('fileName', $nameDict), checkLocalName('newEntry', $nameDict)), Array('', '', ''));
checkIdentical('a local: a standard abbreviation of 2 letters, digits closing a piece, an abbreviation holding digits', Array(checkLocalName('idColumn', $nameDict), checkLocalName('lvl1Vals', $nameDict), checkLocalName('id3v1Tag', $nameDict)), Array('', '', ''));
checkIdentical('a local refused: its length, three pieces, a piece under 3 letters that is no abbreviation', Array(checkLocalName('endDate', $nameDict), checkLocalName('oneRowOk', $nameDict), checkLocalName('okHandle', $nameDict), checkLocalName('fileName2x', $nameDict), checkLocalName('File_one', $nameDict)), Array('7 characters, not 8', '3 pieces, not one or two', 'the piece "ok" is under 3 letters', '10 characters, not 8', 'not camelCase'));
// A Bool local: a question verb, then one or two pieces, a whole word of 1 or 2 letters allowed there
checkIdentical('a Bool local: 8 characters, one or two pieces after its question verb', Array(checkLocalName('isLogged', $nameDict), checkLocalName('wasFound', $nameDict), checkLocalName('isMp3Frm', $nameDict), checkLocalName('hasBom16', $nameDict)), Array('', '', '', ''));
checkIdentical('a Bool local: a whole word of 1 or 2 letters after the verb', Array(checkLocalName('isSentOk', $nameDict), checkLocalName('isToFlip', $nameDict), checkLocalName('isAList', $nameDict)), Array('', '', '7 characters, not 8'));
checkIdentical('a Bool local refused: 7 letters, a short piece that is no word, three pieces after the verb', Array(checkLocalName('isFound', $nameDict), checkLocalName('isIntlNb', $nameDict), checkLocalName('isAnOkId', $nameDict)), Array('7 characters, not 8', 'the piece "Nb" is neither a word nor a standard abbreviation', '3 pieces after "is", not one or two'));
checkIdentical('with the whole words listed, the short word is one of them', Array(checkLocalName('isSentOk', $nameDict, Array('ok'=>true)), checkLocalName('isSentOk', $nameDict, Array('to'=>true))), Array('', 'the piece "Ok" is neither a word nor a standard abbreviation'));

// The other checks, on the lists of the fixture
checkIdentical('a loop variable: one or nth + a whole word of 3 letters', Array(checkLoopLabel('oneRow', $nameDict, Array()), checkLoopLabel('nthRow', $nameDict, Array()), checkLoopLabel('rowIdx', $nameDict, Array()), checkLoopLabel('oneCol', $nameDict, Array()), checkLoopLabel('oneFnd', $nameDict, Array())), Array('', '', 'not one or nth + an item of 3 letters', 'the item "Col" is not a whole word', 'the item "Fnd" is not a whole word'));
checkIdentical('a loop item that is a whole word listed as its own writing is accepted; a writing of another word is not', Array(checkLoopLabel('oneRow', $nameDict, Array()), checkLoopLabel('oneTag', $nameDict, Array()), checkLoopLabel('oneRgx', $nameDict, Array()), checkLoopLabel('oneTag', $nameDict, Array('row'=>true)), checkLoopLabel('oneRgx', $nameDict, Array('row'=>true))), Array('', '', 'the item "Rgx" is not a whole word', '', 'the item "Rgx" is not a whole word'));
checkIdentical('with the whole words listed, the item is one of them',Array(checkLoopLabel('oneRow', $nameDict, Array('row'=>true)), checkLoopLabel('oneLin', $nameDict, Array('row'=>true))), Array('', 'the item "Lin" is not a whole word'));
checkIdentical('the pieces: writings, exceptions, abbreviations, the words of the rules; the others by their look', Array(checkNameWords('readAccntUrl', $nameDict, Array()), checkNameWords('makeColText', $nameDict, Array()), checkNameWords('givenUserName', $nameDict, Array()), checkNameWords('accessRgst', $nameDict, Array()), checkNameWords('usrLst', $nameDict, Array())), Array('', '', '', 'neither a word nor a listed form: Rgst', 'neither a word nor a listed form: usr, Lst'));
checkIdentical('the pieces: one ending in digits is checked by its letters, unless it is an abbreviation as a whole', Array(checkNameWords('lvl1Vals', $nameDict, Array()), checkNameWords('hasBom16', $nameDict, Array()), checkNameWords('id3v1Tag', $nameDict, Array('tag'=>true)), checkNameWords('isMp3Frm', $nameDict, Array()), checkNameWords('rgst2Vals', $nameDict, Array())), Array('', '', '', '', 'neither a word nor a listed form: rgst2'));
checkIdentical('the pieces, with the whole words listed', Array(checkNameWords('givenUserName', $nameDict, Array('given'=>true, 'user'=>true)), checkNameWords('oneRow', $nameDict, Array('row'=>true))), Array('neither a word nor a listed form: Name', ''));
checkIdentical('a method starts with a verb, a question verb or a domain verb', Array(checkFirstVerb('readRouteList', 'Array', $nameDict), checkFirstVerb('hasWrongSecret', 'Bool', $nameDict), checkFirstVerb('signTokenText', 'String', $nameDict), checkFirstVerb('makeUserEntry', 'Bool', $nameDict)), Array('', '', '', ''));
checkIdentical('a method that does not', Array(checkFirstVerb('finalErrorText', 'String', $nameDict), checkFirstVerb('validateOption', 'Bool', $nameDict)), Array('"final" is not a verb', 'returns a Bool: "validate" is neither a question verb nor an action verb'));
checkIdentical('a Bool starts with a question verb', Array(checkBoolNames('isLoggedIn', $nameDict), checkBoolNames('hasRole', $nameDict), checkBoolNames('wasSaved', $nameDict), checkBoolNames('docFlag', $nameDict)), Array('', '', '', 'a Bool: "doc" is not a question verb'));
checkIdentical('the last word: a kind term in any of its writings, or reported', Array(checkFinalKind('errorText', $nameDict), checkFinalKind('errorTxt', $nameDict), checkFinalKind('holdSecs', $nameDict), checkFinalKind('requiredRole', $nameDict)), Array('', '', '', 'ends with "Role", not a kind term'));

// NAMERULES.md itself: the shape the audit relies on
$realDict = runCatchThrown(function(){ return readNamingDict(TOP_DIR.DS.'NAMERULES.md'); });
checkTrueValue('NAMERULES.md gives every list', is_array($realDict));
if( is_array($realDict) ){
	checkIdentical('its question verbs and its exceptions', Array($realDict['question'], $realDict['exception']), Array(Array('is', 'has', 'can', 'needs', 'was'), Array('Obj', 'Pos', 'Reg', 'Col')));
	checkIdentical('its verbs, domain verbs, kind terms, writings and abbreviations hold what they should', Array(in_array('get', $realDict['verb'], true), in_array('wipe', $realDict['verb'], true), in_array('retrieve', $realDict['verb'], true), in_array('sign', $realDict['domain'], true), in_array('mark', $realDict['domain'], true), in_array('cook', $realDict['domain'], true), in_array('Mins', $realDict['kind'], true), in_array('Obj', $realDict['kind'], true), in_array('Matx', $realDict['kind'], true), in_array('Chars', $realDict['form'], true), in_array('Wrtr', $realDict['form'], true), in_array('Nmsp', $realDict['form'], true), in_array('Url', $realDict['standard'], true), in_array('Apr1', $realDict['standard'], true), in_array('Id3v1', $realDict['standard'], true), in_array('Db', $realDict['standard'], true)),
		Array(true, true, false, true, true, false, true, true, true, true, true, true, true, true, true, true));
	checkIdentical('its whole words as loop items: accepted, and a writing of another word refused', Array(checkLoopLabel('oneRow', $realDict, Array()), checkLoopLabel('oneTag', $realDict, Array()), checkLoopLabel('oneRgx', $realDict, Array())), Array('', '', 'the item "Rgx" is not a whole word'));
	// The names the review of 2026-11-14 approved or refused, on the real lists
	checkIdentical('the locals the review approved', array_map(function( String $fullNameText ) use ( $realDict ): String { return checkLocalName($fullNameText, $realDict); }, Array('wasFound', 'isMp3Frm', 'isSentOk', 'idColumn', 'lvl1Vals', 'id3v1Tag', 'hasBom16')), Array('', '', '', '', '', '', ''));
	checkIdentical('the locals it refused', array_map(function( String $fullNameText ) use ( $realDict ): String { return checkLocalName($fullNameText, $realDict); }, Array('isFound', 'isIntlNb', 'fileName2x')), Array('7 characters, not 8', 'the piece "Nb" is neither a word nor a standard abbreviation', '10 characters, not 8'));
}

finishChecking();
