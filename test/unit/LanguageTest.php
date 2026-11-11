<?php

// Language : the tag a text is written in for one person, the chain of
// languages it is looked for along, and the file found in that chain.

require __DIR__.'/../lib/harness.php';

use nyu\Tools\Language;

// Tags
checkIdentical('tags in lower case', array_map(Array(Language::class, 'isValidLangTag'), Array('en', 'fr-ch', 'zh-hant', 'gsw', 'es-419')), Array(true, true, true, true, true));
checkIdentical('what is not a tag', array_map(Array(Language::class, 'isValidLangTag'), Array('', 'FR', 'fr_ch', 'f', 'french', '*', 'fr-', 'fr-c', '../en', 'en-abcdefghij')), Array(false, false, false, false, false, false, false, false, false, false));

// The default : given, read off DEF_LANG, or en
$frLangs = new Language('fr_CH');
checkIdentical('the default given, read in lower case', $frLangs->chooseLanguage(null, null), 'fr-ch');
checkIdentical('a default that is not a tag is en', (new Language('french'))->chooseLanguage(null, null), 'en');
checkIdentical('without config, the default is en', (new Language())->chooseLanguage(null, null), 'en');

// The choice : the stored one, then the request's, then the default
$enLangs = new Language('en');
checkIdentical('the stored language comes first', $enLangs->chooseLanguage('de', 'fr-ch'), 'de');
checkIdentical('then the language of the request', $enLangs->chooseLanguage(null, 'fr-ch'), 'fr-ch');
checkIdentical('an empty or broken one is passed over', Array($enLangs->chooseLanguage('', 'it'), $enLangs->chooseLanguage('*', '')), Array('it', 'en'));
checkIdentical('the case and the underscore are not kept', $enLangs->chooseLanguage('PT_br', null), 'pt-br');

// The chain
checkIdentical('a regional tag, cut part by part, then the default', $enLangs->langChainArray('fr-ch'), Array('fr-ch', 'fr', 'en'));
checkIdentical('the default is not given twice', $enLangs->langChainArray('en'), Array('en'));
checkIdentical('a regional default is cut as well', $frLangs->langChainArray('de-at'), Array('de-at', 'de', 'fr-ch', 'fr'));
checkIdentical('a broken tag leaves the default alone', $enLangs->langChainArray('../x'), Array('en'));

// The file, in the first language of the chain that has it
$baseDir = inTemporaryDir('langs');
foreach( Array('fr', 'en') as $oneTag ){
	if( !is_dir($baseDir.DS.$oneTag) ){
		mkdir($baseDir.DS.$oneTag, 0777, true);
	}
}
file_put_contents($baseDir.DS.'fr'.DS.'hello.txt', 'Bonjour');
file_put_contents($baseDir.DS.'en'.DS.'hello.txt', 'Hello');
file_put_contents($baseDir.DS.'en'.DS.'bye.txt', 'Bye');
checkIdentical('the most precise language that has the file', $enLangs->locateTemplate($baseDir, 'hello.txt', $enLangs->langChainArray('fr-ch')), $baseDir.DS.'fr'.DS.'hello.txt');
checkIdentical('the default when the language lacks it', $enLangs->locateTemplate($baseDir, 'bye.txt', $enLangs->langChainArray('fr-ch')), $baseDir.DS.'en'.DS.'bye.txt');
checkIdentical('none when no language has it', $enLangs->locateTemplate($baseDir, 'none.txt', $enLangs->langChainArray('fr')), null);
checkIdentical('a name never leaves its folder', Array($enLangs->locateTemplate($baseDir, '../en/hello.txt', Array('fr')), $enLangs->locateTemplate($baseDir, 'hello.txt', Array('..'))), Array(null, null));

finishChecking();
