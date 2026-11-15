<?php

// The naming rules of NAMERULES.md, read for the audit (test/audit/ConventionTest.php).
//
// The lists are read from the file on each run, never copied:
//   section 3   the verbs (first column of its table), the question verbs and the domain verbs
//   section 4   the kind terms (first column of its table, several per cell)
//   section 5   the short forms (columns 2, 4 and 6 of its table), the 3-letter exceptions,
//               the standard abbreviations, the words between pieces and the names kept
//
// A list in a sentence is read from the paragraph starting with its bold label: the terms in
// backticks of its first sentence. A table or a list that cannot be read stops the audit.
//
// Each check gives what is wrong with a name as a text, empty when nothing is.

/**
 * Reads the rows of the first table of a section.
 *
 * @param String $rulesRawText The naming rules, as written.
 * @param Int    $givenSection Number of the section ('## 3. Verbs' is 3).
 * @return Array The rows under the header, each a list of cells; empty when the section has no table.
 */
function parseRuleTable( String $rulesRawText, Int $givenSection ): Array {
	$rowsList = Array();
	if( !preg_match('/^## '.$givenSection.'\. .*?(?=^## |\z)/ms', $rulesRawText, $sectHits) ){
		return $rowsList;
	}
	$isInside = false;
	foreach( explode("\n", $sectHits[0]) as $oneRow ){
		$lineText = trim($oneRow);
		if( !str_starts_with($lineText, '|') ){
			if( $isInside ){
				break;
			}
			continue;
		}
		$isInside = true;
		// The line under the header
		if( preg_match('/^[\s:\-|]+$/', $lineText) ){
			continue;
		}
		$lineText = str_ends_with($lineText, '|') ? substr($lineText, 1, -1) : substr($lineText, 1);
		$rowsList[] = array_map('trim', explode('|', $lineText));
	}
	// The first row is the header
	array_shift($rowsList);
	return $rowsList;
}

/**
 * Gives the terms written in backticks in some columns of a table.
 *
 * @param Array $tableRowList Rows of the table, each a list of cells.
 * @param Array $readColsList Columns to read, the first one being 0.
 * @return Array The terms, as written, each once; a cell may hold several.
 */
function parseTermsList( Array $tableRowList, Array $readColsList ): Array {
	$termList = Array();
	foreach( $tableRowList as $oneRow ){
		$cellsRaw = implode(' ', array_intersect_key($oneRow, array_flip($readColsList)));
		if( preg_match_all('/`([A-Za-z][A-Za-z0-9]*)`/', $cellsRaw, $termHits) ){
			$termList = array_merge($termList, $termHits[1]);
		}
	}
	return array_values(array_unique($termList));
}

/**
 * Gives the terms of a list written in a sentence: those in backticks in
 * the first sentence of the paragraph starting with a bold label.
 *
 * @param String $rulesRawText The naming rules, as written.
 * @param Int    $givenSection Number of the section holding the paragraph.
 * @param String $boldListName The label, without its asterisks.
 * @return Array The terms, as written, each once; empty when the label is not found.
 */
function parseLabelList( String $rulesRawText, Int $givenSection, String $boldListName ): Array {
	if( !preg_match('/^## '.$givenSection.'\. .*?(?=^## |\z)/ms', $rulesRawText, $sectHits) ){
		return Array();
	}
	if( !preg_match('/^\*\*'.preg_quote($boldListName, '/').'\*\*(.*?)(?:\.(?=\s|$)|\z)/ms', $sectHits[0], $listHits) ){
		return Array();
	}
	preg_match_all('/`([A-Za-z][A-Za-z0-9]*)`/', $listHits[1], $termHits);
	return array_values(array_unique($termHits[1]));
}

/**
 * Reads every list of the naming rules.
 *
 * @param String $rulesDocPath Full path of NAMERULES.md.
 * @return Array The lists by name: 'verb', 'question', 'domain', 'kind', 'form', 'exception', 'standard', 'between'
 *               (the whole words of 2 and 3 letters a name may hold), 'kept' (the names kept as they are), and
 *               'word', the words of the glossary that are their own writing.
 *
 * @throws RuntimeException When the file, a table or a list cannot be read.
 */
function readNamingDict( String $rulesDocPath ): Array {
	$rulesRaw = @file_get_contents($rulesDocPath);
	if( $rulesRaw===false ){
		throw new RuntimeException('The naming rules cannot be read: '.$rulesDocPath);
	}
	$rulesRaw = str_replace("\r", '', $rulesRaw);
	$nameDict = Array(
		'verb'=>parseTermsList(parseRuleTable($rulesRaw, 3), Array(0)),
		'question'=>parseLabelList($rulesRaw, 3, 'Question verbs'),
		'domain'=>parseLabelList($rulesRaw, 3, 'Domain verbs'),
		'kind'=>parseTermsList(parseRuleTable($rulesRaw, 4), Array(0)),
		'form'=>parseTermsList(parseRuleTable($rulesRaw, 5), Array(1, 3, 5)),
		'exception'=>parseLabelList($rulesRaw, 5, 'Exceptions decided by the project manager'),
		'standard'=>parseLabelList($rulesRaw, 5, 'Standard abbreviations'),
		'between'=>parseLabelList($rulesRaw, 5, 'Words between pieces'),
		'kept'=>parseLabelList($rulesRaw, 5, 'Names kept as they are')
	);
	foreach( $nameDict as $oneKey => $oneSet ){
		if( count($oneSet)==0 ){
			throw new RuntimeException('The naming rules give no list "'.$oneKey.'": its table or its paragraph cannot be read');
		}
	}
	// The whole words of the glossary: a writing equal to its word is the word (row is Row, tag is Tag)
	$nameDict['word'] = Array();
	foreach( parseRuleTable($rulesRaw, 5) as $oneRow ){
		foreach( Array(0, 2, 4) as $nthOne ){
			$wordText = ucfirst(strtolower(trim($oneRow[$nthOne] ?? '')));
			if( $wordText!=='' && in_array($wordText, parseTermsList(Array($oneRow), Array($nthOne+1)), true) ){
				$nameDict['word'][] = $wordText;
			}
		}
	}
	$nameDict['word'] = array_values(array_unique($nameDict['word']));
	return $nameDict;
}

/**
 * Splits a camelCase name into its pieces. The digits closing a piece
 * belong to it ('lvl1Vals' gives lvl1, Vals), and a standard abbreviation
 * holding digits is one piece ('id3v1Tag' gives id3v1, Tag).
 *
 * @param String $fullNameText The name, without its '$'.
 * @param Array  $nameRuleDict The lists of the naming rules (see readNamingDict); without them no abbreviation is known.
 * @return Array The pieces, as written ('readRouteList' gives read, Route, List).
 */
function splitWordsList( String $fullNameText, Array $nameRuleDict=Array() ): Array {
	preg_match_all('/[A-Z]?[a-z]+[0-9]*|[A-Z]+(?![a-z])[0-9]*|[0-9]+/', $fullNameText, $wordHits);
	$wordList = $wordHits[0];
	$joinList = Array();
	for( $nthBit=0; $nthBit<count($wordList); $nthBit++ ){
		// Three pieces, then two, that are one abbreviation of the rules when read together
		$pickSize = 1;
		foreach( Array(3, 2) as $oneRun ){
			$joinText = implode('', array_slice($wordList, $nthBit, $oneRun));
			if( $nthBit+$oneRun<=count($wordList) && preg_match('/[0-9]/', $joinText) && in_array(ucfirst(strtolower($joinText)), $nameRuleDict['standard'] ?? Array(), true) ){
				$pickSize = $oneRun;
				break;
			}
		}
		$joinList[] = implode('', array_slice($wordList, $nthBit, $pickSize));
		$nthBit += $pickSize-1;
	}
	return $joinList;
}

/**
 * Tells the area a file belongs to: a folder of src, or the runs. The
 * response and the Runner go with the applications.
 *
 * @param String $relativePath Path of the file from the project root, with forward slashes.
 * @return String 'Tools', 'Access', 'Data', 'Shell', 'App', 'Setup', 'Error' or 'runs'; empty for any other file.
 */
function findAreaOfFile( String $relativePath ): String {
	if( str_starts_with($relativePath, 'runs/') ){
		return 'runs';
	}
	if( $relativePath=='src/Runner.php' || str_starts_with($relativePath, 'src/Output/') ){
		return 'App';
	}
	if( preg_match('#^src/(Tools|Access|Data|Shell|App|Setup|Error)/#', $relativePath, $areaHits) ){
		return $areaHits[1];
	}
	return '';
}

/**
 * Checks a local: 8 characters, one word or two, no piece under 3 letters
 * but a standard abbreviation ('idColumn'). A Bool local, its first piece
 * a question verb, holds one or two pieces after the verb, and a whole
 * word of 1 or 2 letters is allowed there ('isSentOk'). Without the list
 * of whole words, such a word is one that holds a vowel.
 *
 * @param String $fullNameText The name, without its '$'.
 * @param Array  $nameRuleDict The lists of the naming rules (see readNamingDict).
 * @param Array  $fullWordDict The whole words in lower case, as keys; empty while there is no list of them.
 * @return String What is wrong, empty when nothing is.
 */
function checkLocalName( String $fullNameText, Array $nameRuleDict, Array $fullWordDict=Array() ): String {
	if( !preg_match('/^[a-z][a-zA-Z0-9]*$/', $fullNameText) ){
		return 'not camelCase';
	}
	if( strlen($fullNameText)!=8 ){
		return strlen($fullNameText).' characters, not 8';
	}
	$wordList = splitWordsList($fullNameText, $nameRuleDict);
	$isAsking = count($wordList)>1 && in_array($wordList[0], $nameRuleDict['question'], true);
	$partList = $isAsking ? array_slice($wordList, 1) : $wordList;
	if( count($partList)>2 ){
		return count($partList).' pieces'.( $isAsking ? ' after "'.$wordList[0].'"' : '' ).', not one or two';
	}
	foreach( $partList as $oneBit ){
		if( strlen($oneBit)>=3 || in_array(ucfirst(strtolower($oneBit)), $nameRuleDict['standard'], true) ){
			continue;
		}
		if( !$isAsking ){
			return 'the piece "'.$oneBit.'" is under 3 letters';
		}
		$isListed = empty($fullWordDict) ? preg_match('/^[a-z]*[aeiou][a-z]*$/', strtolower($oneBit))===1 : isset($fullWordDict[strtolower($oneBit)]);
		if( !$isListed ){
			return 'the piece "'.$oneBit.'" is neither a word nor a standard abbreviation';
		}
	}
	return '';
}

/**
 * Checks a loop variable: 'one' or 'nth', then an item of 3 letters. The
 * item is a whole word ('Row', 'Tag'), or the writing of 3 letters of its
 * word: a writing of the glossary ('Rgx'), a kind term ('Val'), a standard
 * abbreviation or an exception ('Col'). 'Arr' is never an item.
 * Without the list of whole words, any other item is taken for a word
 * when it holds a vowel.
 *
 * @param String $fullNameText The name, without its '$'.
 * @param Array  $nameRuleDict The lists of the naming rules (see readNamingDict).
 * @param Array  $fullWordDict The whole words in lower case, as keys; empty while there is no list of them.
 * @return String What is wrong, empty when nothing is.
 */
function checkLoopLabel( String $fullNameText, Array $nameRuleDict, Array $fullWordDict ): String {
	if( !preg_match('/^(?:one|nth)([A-Z][a-z]{2})$/', $fullNameText, $itemHits) ){
		return 'not one or nth + an item of 3 letters';
	}
	$itemWord = $itemHits[1];
	if( $itemWord==='Arr' ){
		return 'the item "Arr" is never an item';
	}
	// A whole word the glossary lists as its own writing, or the writing of 3 letters of a word
	foreach( Array('word', 'form', 'kind', 'standard', 'exception') as $oneKey ){
		if( in_array($itemWord, $nameRuleDict[$oneKey] ?? Array(), true) ){
			return '';
		}
	}
	$isListed = empty($fullWordDict) ? preg_match('/[aeiou]/', strtolower($itemWord))===1 : isset($fullWordDict[strtolower($itemWord)]);
	return $isListed ? '' : 'the item "'.$itemWord.'" is neither a whole word nor a listed writing';
}

/**
 * Checks every piece of a name: a whole word, a writing of the glossary,
 * a 3-letter exception or a standard abbreviation. A piece ending in
 * digits is checked by its letters, unless the whole piece is a standard
 * abbreviation. The words the rules list themselves (verbs, kind terms,
 * one, nth, the words between pieces) are whole words, and a name the
 * rules keep as it is has no piece checked.
 * Without the list of whole words, a piece of 4 letters or more holding a
 * vowel is taken for one.
 *
 * @param String $fullNameText The name, without its '$'.
 * @param Array  $nameRuleDict The lists of the naming rules (see readNamingDict).
 * @param Array  $fullWordDict The whole words in lower case, as keys; empty while there is no list of them.
 * @return String What is wrong, empty when nothing is.
 */
function checkNameWords( String $fullNameText, Array $nameRuleDict, Array $fullWordDict ): String {
	$badWords = Array();
	if( in_array($fullNameText, $nameRuleDict['kept'] ?? Array(), true) ){
		return '';
	}
	$gapsList = array_map('strtolower', $nameRuleDict['between'] ?? Array());
	foreach( splitWordsList($fullNameText, $nameRuleDict) as $oneBit ){
		if( ctype_digit($oneBit) || in_array(ucfirst(strtolower($oneBit)), $nameRuleDict['standard'], true) ){
			continue;
		}
		// A piece ending in digits is checked by its letters: Lvl1 as Lvl
		$lowerBit = rtrim(strtolower($oneBit), '0123456789');
		$upperBit = ucfirst($lowerBit);
		if( in_array($upperBit, $nameRuleDict['form'], true) || in_array($upperBit, $nameRuleDict['exception'], true) || in_array($upperBit, $nameRuleDict['standard'], true) ){
			continue;
		}
		if( in_array($lowerBit, Array('one', 'nth'), true) || in_array($lowerBit, $gapsList, true) || in_array($lowerBit, $nameRuleDict['verb'], true) || in_array($lowerBit, $nameRuleDict['question'], true) || in_array($lowerBit, $nameRuleDict['domain'], true) || in_array($upperBit, $nameRuleDict['kind'], true) ){
			continue;
		}
		$isListed = empty($fullWordDict) ? ( strlen($lowerBit)>=4 && preg_match('/[aeiou]/', $lowerBit)===1 ) : isset($fullWordDict[$lowerBit]);
		if( !$isListed ){
			$badWords[] = $oneBit;
		}
	}
	return empty($badWords) ? '' : 'neither a word nor a listed form: '.implode(', ', $badWords);
}

/**
 * Checks the first word of a method: a verb, a question verb or a domain
 * verb. A method returning a Bool starts with a question verb, or with an
 * action verb whose success it tells.
 *
 * @param String $fullNameText The name of the method.
 * @param String $returnedType Its return type as declared, empty when it has none.
 * @param Array  $nameRuleDict The lists of the naming rules (see readNamingDict).
 * @return String What is wrong, empty when nothing is.
 */
function checkFirstVerb( String $fullNameText, String $returnedType, Array $nameRuleDict ): String {
	$verbWord = strtolower(splitWordsList($fullNameText, $nameRuleDict)[0] ?? '');
	if( in_array($verbWord, $nameRuleDict['verb'], true) || in_array($verbWord, $nameRuleDict['question'], true) || in_array($verbWord, $nameRuleDict['domain'], true) ){
		return '';
	}
	return (strtolower($returnedType)=='bool') ? 'returns a Bool: "'.$verbWord.'" is neither a question verb nor an action verb' : '"'.$verbWord.'" is not a verb';
}

/**
 * Checks a name holding a Bool: it starts with a question verb.
 *
 * @param String $fullNameText The name, without its '$'.
 * @param Array  $nameRuleDict The lists of the naming rules (see readNamingDict).
 * @return String What is wrong, empty when nothing is.
 */
function checkBoolNames( String $fullNameText, Array $nameRuleDict ): String {
	$verbWord = strtolower(splitWordsList($fullNameText, $nameRuleDict)[0] ?? '');
	return in_array($verbWord, $nameRuleDict['question'], true) ? '' : 'a Bool: "'.$verbWord.'" is not a question verb';
}

/**
 * Checks the last word of a name: a kind term. Any other last word is a
 * noun to look at: it is reported, and never fails the audit.
 *
 * @param String $fullNameText The name, without its '$'.
 * @param Array  $nameRuleDict The lists of the naming rules (see readNamingDict).
 * @return String The last word when it is not a kind term, empty when it is one.
 */
function checkFinalKind( String $fullNameText, Array $nameRuleDict ): String {
	$wordList = splitWordsList($fullNameText, $nameRuleDict);
	$lastWord = ucfirst(strtolower(strval(end($wordList))));
	return in_array($lastWord, $nameRuleDict['kind'], true) ? '' : 'ends with "'.$lastWord.'", not a kind term';
}
