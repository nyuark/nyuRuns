<?php

// Coding conventions: naming lengths, docblocks and type
// declarations across src/ and runs/, checked with the tokenizer audit in
// test/lib/auditor.php.
//
// Accepted exceptions, agreed for this project:
//   - action methods in runs/*/Actions.php keep URL-friendly names (index, view,
//     login...), because in autopath mode the method name is the URL segment;
//   - the PSR-3 level methods of Tools\Logger (error, warning, notice, info, debug);
//   - the catch variable $e.
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
checkSame('loop variables are 6- or 3-character camelCase', violating($finding['loop']), Array());
checkSame('locals are 7-character camelCase',           violating($finding['local'], $okLocal), Array());
checkSame('public methods have a docblock',             violating($finding['nodoc']), Array());
checkSame('parameters and return values are typed',     violating($finding['notype']), Array());

endChecks();
