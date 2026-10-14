<?php

// Language : the tag a text is written in for one person, the chain of
// languages it is looked for along, and the file found in that chain.

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Language;

// Tags
checkSame('tags in lower case', array_map(Array(Language::class, 'checksTag'), Array('en', 'fr-ch', 'zh-hant', 'gsw', 'es-419')), Array(true, true, true, true, true));
checkSame('what is not a tag', array_map(Array(Language::class, 'checksTag'), Array('', 'FR', 'fr_ch', 'f', 'french', '*', 'fr-', 'fr-c', '../en', 'en-abcdefghij')), Array(false, false, false, false, false, false, false, false, false, false));

// The default : given, read off LNG_DFLT, or en
$frLangs = new Language('fr_CH');
checkSame('the default given, read in lower case', $frLangs->chooseLng(null, null), 'fr-ch');
checkSame('a default that is not a tag is en', (new Language('french'))->chooseLng(null, null), 'en');
checkSame('without config, the default is en', (new Language())->chooseLng(null, null), 'en');

// The choice : the stored one, then the request's, then the default
$enLangs = new Language('en');
checkSame('the stored language comes first', $enLangs->chooseLng('de', 'fr-ch'), 'de');
checkSame('then the language of the request', $enLangs->chooseLng(null, 'fr-ch'), 'fr-ch');
checkSame('an empty or broken one is passed over', Array($enLangs->chooseLng('', 'it'), $enLangs->chooseLng('*', '')), Array('it', 'en'));
checkSame('the case and the underscore are not kept', $enLangs->chooseLng('PT_br', null), 'pt-br');

// The chain
checkSame('a regional tag, cut part by part, then the default', $enLangs->langChain('fr-ch'), Array('fr-ch', 'fr', 'en'));
checkSame('the default is not given twice', $enLangs->langChain('en'), Array('en'));
checkSame('a regional default is cut as well', $frLangs->langChain('de-at'), Array('de-at', 'de', 'fr-ch', 'fr'));
checkSame('a broken tag leaves the default alone', $enLangs->langChain('../x'), Array('en'));

// The file, in the first language of the chain that has it
$baseDir = inTempDir('langs');
foreach( Array('fr', 'en') as $oneTag ){
	if( !is_dir($baseDir.DS.$oneTag) ){
		mkdir($baseDir.DS.$oneTag, 0777, true);
	}
}
file_put_contents($baseDir.DS.'fr'.DS.'hello.txt', 'Bonjour');
file_put_contents($baseDir.DS.'en'.DS.'hello.txt', 'Hello');
file_put_contents($baseDir.DS.'en'.DS.'bye.txt', 'Bye');
checkSame('the most precise language that has the file', $enLangs->findTmplt($baseDir, 'hello.txt', $enLangs->langChain('fr-ch')), $baseDir.DS.'fr'.DS.'hello.txt');
checkSame('the default when the language lacks it', $enLangs->findTmplt($baseDir, 'bye.txt', $enLangs->langChain('fr-ch')), $baseDir.DS.'en'.DS.'bye.txt');
checkSame('none when no language has it', $enLangs->findTmplt($baseDir, 'none.txt', $enLangs->langChain('fr')), null);
checkSame('a name never leaves its folder', Array($enLangs->findTmplt($baseDir, '../en/hello.txt', Array('fr')), $enLangs->findTmplt($baseDir, 'hello.txt', Array('..'))), Array(null, null));

endChecks();
