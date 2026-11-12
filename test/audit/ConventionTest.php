<?php

// Coding conventions (see CONVENTIONS.md and NAMERULES.md) : naming lengths, docblocks
// and type declarations across src/ and runs/, checked with the tokenizer audit in
// test/lib/auditor.php, then the grammar of the names, with test/lib/namerules.php.
//
// Accepted exceptions, agreed for this project:
//   - action methods in runs/*/Actions.php keep URL-friendly names (index, view,
//     login...), because in autopath mode the method name is the URL segment;
//   - the PSR-3 level methods of Tools\Logger (error, warning, notice, info, debug);
//   - the catch variable $e;
//   - the loop variables of 3 letters listed in test/audit/looplabels.txt, kept until they are renamed;
//   - the methods a PHP interface imposes, and those of a stream wrapper;
//   - the PSR-3 level constants of Tools\Logger (LEVL_ERROR...), the others being XXXX_YYYY.
//
// The grammar of NAMERULES.md is checked area by area (Tools, Access, Data, Shell, App
// with Output and the Runner, Setup, Error, runs), its lists read from that file on each run:
//   local   a local is 8 characters, one word or two, no piece under 3 letters
//   loop    a loop variable is one or nth + a whole word of 3 letters
//   piece   every piece of a name is a whole word, a short form of the glossary, a 3-letter
//           exception or a standard abbreviation (whole words from test/audit/words.txt; until
//           that file exists, a piece of 4 letters or more holding a vowel is taken for one)
//   verb    a method starts with a verb, a question verb or a domain verb
//   bool    a Bool parameter, property or local starts with a question verb
//   kind    the last word is a kind term; any other is reported, and never fails
//
// An area not yet renamed has its findings counted, and none fails. An area listed in
// test/audit/strictareas.txt is strict: any finding fails, those of 'kind' left aside.
//
//   php test/audit/ConventionTest.php --list     prints every finding, by area and by check

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/auditor.php';
require __DIR__.'/../lib/namerules.php';

$folders = Array('src', 'runs');
$finding = auditCodeFiles(TOP_DIR, $folders);

/**
 * Renders findings as 'file:line scope name' strings, leaving out the
 * accepted ones.
 *
 * @param Array     $findngList Findings of one kind.
 * @param ?callable $acceptedFn Tells whether a finding is an accepted exception.
 * @return Array The findings that are not accepted.
 */
function violating( Array $findngList, ?callable $acceptedFn=null ): Array {
	$badList = Array();
	foreach( $findngList as $oneFnd ){
		if( $acceptedFn!==null && $acceptedFn($oneFnd) ){
			continue;
		}
		$badList[] = $oneFnd['file'].':'.$oneFnd['line'].' '.trim($oneFnd['scope'].' '.$oneFnd['name']);
	}
	return $badList;
}

$okMethd = function( Array $oneFinding ): Bool {
	if( preg_match('#^runs/[^/]+/Actions\.php$#', $oneFinding['file']) ){
		return true;
	}
	// The methods a PHP interface imposes, and those of a stream wrapper
	if( in_array($oneFinding['name'], Array('jsonSerialize', 'getIterator', 'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset', 'count', 'current', 'key', 'next', 'rewind', 'valid'), true) || str_starts_with($oneFinding['name'], 'stream_') ){
		return true;
	}
	return $oneFinding['file']=='src/Tools/Logger.php' && in_array($oneFinding['name'], Array('error', 'warning', 'notice', 'info', 'debug'), true);
};
// Class constants: XXXX_YYYY, the PSR-3 level constants of Tools\Logger kept as they are
$cnstBad = Array();
foreach( listPhpFileArr(TOP_DIR, $folders) as $filNme ){
	$tknList = array_values(array_filter(PhpToken::tokenize(strval(file_get_contents($filNme))), function( PhpToken $oneToken ): Bool { return !$oneToken->is(Array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT)); }));
	foreach( $tknList as $tknIdx => $oneToken ){
		if( $oneToken->id!==T_CONST ){
			continue;
		}
		for( $nxtIdx=$tknIdx+1; isset($tknList[$nxtIdx]) && $tknList[$nxtIdx]->text!==';'; $nxtIdx++ ){
			$cnstNme = $tknList[$nxtIdx]->text;
			if( $tknList[$nxtIdx]->id===T_STRING && ($tknList[$nxtIdx+1]->text ?? '')==='=' && !preg_match('/^[A-Z0-9]{4}_[A-Z0-9]{4}$/', $cnstNme) && !str_starts_with($cnstNme, 'LEVL_') ){
				$cnstBad[] = str_replace('\\', '/', substr($filNme, strlen(TOP_DIR)+1)).':'.$tknList[$nxtIdx]->line.' '.$cnstNme;
			}
		}
	}
}
// A loop variable of 3 letters is accepted only when test/audit/looplabels.txt lists it (exception of the naming scheme)
$loopTxt = (array)@file(__DIR__.DS.'looplabels.txt', FILE_IGNORE_NEW_LINES);
$loopLst = Array();
foreach( $loopTxt as $oneLin ){
	if( preg_match('/^([a-z][a-zA-Z0-9]{2})\s/', strval($oneLin), $lblMtch) ){
		$loopLst[] = $lblMtch[1];
	}
}

checkTrueValue('the audit found code to check', $finding['count']['method']>100 && $finding['count']['class']>30);
checkIdentical('files that do not parse',                    violating($finding['parse']), Array());
checkIdentical('classes are PascalCase',                     violating($finding['class']), Array());
checkIdentical('methods are 14-character camelCase',         violating($finding['method'], $okMethd), Array());
checkIdentical('parameters are 12-character camelCase',      violating($finding['param']), Array());
checkIdentical('properties are 10-character camelCase',      violating($finding['prop']), Array());
checkIdentical('constants are XXXX_YYYY',                    $cnstBad, Array());
checkTrueValue('looplabels.txt lists the loop variables of 3 letters', count($loopLst)>0);
checkIdentical('public methods have a docblock',             violating($finding['nodoc']), Array());
checkIdentical('parameters and return values are typed',     violating($finding['notype']), Array());

// The grammar of NAMERULES.md, by area. A list that cannot be read fails the audit, and nothing is checked against an empty one
$areaList = Array('Tools', 'Access', 'Data', 'Shell', 'App', 'Setup', 'Error', 'runs');
$testList = Array('local', 'loop', 'piece', 'verb', 'bool', 'kind');
$nameDict = runCatchThrown(function(): Array { return readNamingDict(TOP_DIR.DS.'NAMERULES.md'); });
checkTrueValue('NAMERULES.md gives every list of the audit'.(is_string($nameDict) ? ' ('.$nameDict.')' : ''), is_array($nameDict));

// The whole words, one per line, once the file exists
$wordDict = Array();
foreach( is_file(__DIR__.DS.'words.txt') ? (array)file(__DIR__.DS.'words.txt', FILE_IGNORE_NEW_LINES) : Array() as $oneRow ){
	if( trim(strval($oneRow))!=='' && !str_starts_with(trim(strval($oneRow)), '#') ){
		$wordDict[strtolower(trim(strval($oneRow)))] = true;
	}
}
// The areas already renamed, one per line
$hardList = Array();
foreach( (array)@file(__DIR__.DS.'strictareas.txt', FILE_IGNORE_NEW_LINES) as $oneRow ){
	if( trim(strval($oneRow))!=='' && !str_starts_with(trim(strval($oneRow)), '#') ){
		$hardList[] = trim(strval($oneRow));
	}
}
checkIdentical('strictareas.txt names areas of the audit only', array_values(array_diff($hardList, $areaList)), Array());

$foundSet = Array();
$lostList = Array();
foreach( $areaList as $oneKey ){
	$foundSet[$oneKey] = array_fill_keys($testList, Array());
}
foreach( is_array($nameDict) ? $finding['names'] : Array() as $oneHit ){
	$areaName = findAreaOfFile($oneHit['file']);
	if( $areaName==='' ){
		$lostList[$oneHit['file']] = true;
		continue;
	}
	$fullName = $oneHit['name'];
	$isOfBool = strtolower($oneHit['type'])=='bool';
	// What is wrong with the name, by check
	$flawDict = Array();
	if( $oneHit['kind']=='loop' ){
		if( !(strlen($fullName)==3 && in_array($fullName, $loopLst, true)) ){
			$flawDict['loop'] = checkLoopLabel($fullName, $nameDict, $wordDict);
		}
	}elseif( $oneHit['kind']=='method' ){
		if( !$okMethd($oneHit) ){
			$flawDict['verb'] = checkFirstVerb($fullName, $oneHit['type'], $nameDict);
			$flawDict['piece'] = checkNameWords($fullName, $nameDict, $wordDict);
			// The verb says the kind of a count and of a question
			$verbWord = strtolower(splitWordsList($fullName)[0] ?? '');
			if( $verbWord!='count' && !in_array($verbWord, $nameDict['question'], true) ){
				$flawDict['kind'] = checkFinalKind($fullName, $nameDict);
			}
		}
	}elseif( !($oneHit['kind']=='local' && $fullName=='e') ){
		if( $oneHit['kind']=='local' ){
			$flawDict['local'] = checkLocalName($fullName, $nameDict);
		}
		$flawDict['piece'] = checkNameWords($fullName, $nameDict, $wordDict);
		if( $isOfBool ){
			$flawDict['bool'] = checkBoolNames($fullName, $nameDict);
		}else{
			$flawDict['kind'] = checkFinalKind($fullName, $nameDict);
		}
	}
	foreach( array_filter($flawDict) as $oneKey => $oneWhy ){
		$foundSet[$areaName][$oneKey][] = $oneHit['file'].':'.$oneHit['line'].' '.trim($oneHit['scope'].' '.$fullName).': '.$oneWhy;
	}
}
checkIdentical('every file audited belongs to an area', array_keys($lostList), Array());

// The counts by area; with --list, every finding
foreach( $foundSet as $areaName => $areaSet ){
	$sizeList = Array();
	foreach( $areaSet as $oneKey => $oneSet ){
		$sizeList[] = $oneKey.' '.count($oneSet);
	}
	echo '  '.str_pad($areaName, 7).(in_array($areaName, $hardList, true) ? 'strict  ' : 'report  ').implode(', ', $sizeList)."\n";
	foreach( in_array('--list', $argv, true) ? $areaSet : Array() as $oneKey => $oneSet ){
		echo '    '.$oneKey.': '.count($oneSet)."\n";
		foreach( $oneSet as $oneRow ){
			echo '      '.$oneRow."\n";
		}
	}
}
if( in_array('--list', $argv, true) ){
	foreach( Array('class', 'method', 'param', 'prop', 'nodoc', 'notype', 'parse') as $oneKey ){
		echo '  '.$oneKey.': '.count($finding[$oneKey]).(isset($finding['count'][$oneKey]) ? ' of '.$finding['count'][$oneKey] : '')."\n";
		foreach( violating($finding[$oneKey]) as $oneRow ){
			echo '      '.$oneRow."\n";
		}
	}
}
// A strict area fails on any finding, the last words left aside: they are reported only
foreach( $hardList as $areaName ){
	checkIdentical('the names of '.$areaName.' follow NAMERULES.md', array_merge(...array_values(array_diff_key($foundSet[$areaName] ?? Array(), Array('kind'=>true)))), Array());
}

finishChecking();
