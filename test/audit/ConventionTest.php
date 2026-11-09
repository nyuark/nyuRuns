<?php

// Coding conventions (see CONTRIBUTING.md) : naming lengths, docblocks and type
// declarations across src/ and runs/, checked with the tokenizer audit in
// test/lib/auditor.php.
//
// Accepted exceptions, agreed for this project:
//   - action methods in runs/*/Actions.php keep URL-friendly names (index, view,
//     login...), because in autopath mode the method name is the URL segment;
//   - the PSR-3 level methods of Tools\Logger (error, warning, notice, info, debug);
//   - the catch variable $e;
//   - the loop variables of 3 letters listed in test/audit/looplabels.txt, kept for now (the others are 6).
//
//   php test/audit/ConventionTest.php --list     prints every finding, accepted or not

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/auditor.php';

$folders = Array('src', 'runs');
$finding = auditCode(TOP_DIR, $folders);

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
	return $oneFinding['file']=='src/Tools/Logger.php' && in_array($oneFinding['name'], Array('error', 'warning', 'notice', 'info', 'debug'), true);
};
$okLocal = function( Array $oneFinding ): Bool {
	return $oneFinding['name']=='e';
};
// A loop variable of 3 letters is accepted only when test/audit/looplabels.txt lists it (exception of the naming scheme)
$loopTxt = (array)@file(__DIR__.DS.'looplabels.txt', FILE_IGNORE_NEW_LINES);
$loopLst = Array();
foreach( $loopTxt as $oneLin ){
	if( preg_match('/^([a-z][a-zA-Z0-9]{2})\s/', strval($oneLin), $lblMtch) ){
		$loopLst[] = $lblMtch[1];
	}
}
$okLoops = function( Array $oneFinding ) use ( $loopLst ): Bool {
	return strlen($oneFinding['name'])==3 && in_array($oneFinding['name'], $loopLst, true);
};

if( in_array('--list', $argv, true) ){
	foreach( Array('class', 'method', 'param', 'prop', 'loop', 'local', 'nodoc', 'notype', 'parse') as $kndNme ){
		echo '  '.$kndNme.': '.count($finding[$kndNme]).(isset($finding['count'][$kndNme]) ? ' of '.$finding['count'][$kndNme] : '')."\n";
		foreach( violating($finding[$kndNme]) as $oneLin ){
			echo '      '.$oneLin."\n";
		}
	}
}

checkTrue('the audit found code to check', $finding['count']['method']>100 && $finding['count']['class']>30);
checkSame('files that do not parse',                    violating($finding['parse']), Array());
checkSame('classes are PascalCase',                     violating($finding['class']), Array());
checkSame('methods are 9-character camelCase',          violating($finding['method'], $okMethd), Array());
checkSame('parameters are 10-character camelCase',      violating($finding['param']), Array());
checkSame('properties are 8-character camelCase',       violating($finding['prop']), Array());
checkSame('loop variables are 6-character camelCase, or 3 when looplabels.txt lists them', violating($finding['loop'], $okLoops), Array());
checkTrue('looplabels.txt lists the loop variables of 3 letters', count($loopLst)>0);
checkSame('locals are 7-character camelCase',           violating($finding['local'], $okLocal), Array());
checkSame('public methods have a docblock',             violating($finding['nodoc']), Array());
checkSame('parameters and return values are typed',     violating($finding['notype']), Array());

endChecks();
