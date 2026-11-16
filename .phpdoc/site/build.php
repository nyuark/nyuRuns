<?php

// Builds the documentation as HTML into docs/online, to be put online as it is: the documents of
// docs/<language>/ (en, fr, es), each read by nyuRuns' own Markdown parser and written by its HTML
// writer, in the layout of layout.html and the style of the drafts (site.css), one folder per
// language; and docs/online/index.html, which sends a visitor to the language of their browser,
// English otherwise. The code reference is made beside them, in docs/online/reference, by
// phpDocumentor, in English for every language. The Markdown files of the repository stay the source.
// Run from the project root: php .phpdoc/site/build.php, or pwsh .phpdoc/build.ps1 for the whole site.

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', dirname(__DIR__, 2));
require TOP_DIR.DS.'vendor'.DS.'autoload.php';

use nyu\Data\Parsers\Mdwn;
use nyu\Data\Writers\Html;

/**
 * Points the links of a document at the pages of the site: a document of
 * the site at its page, the anchor kept; LICENSE and NOTICE at their copy;
 * the code reference at its folder. A link to a file of the project the site
 * does not hold keeps its text, without the link; a link to a file that does
 * not exist is left as written, for the check of the links to report it; an
 * address of another site, or an anchor of the page, is left as it is.
 *
 * @param Array  $blocksList The blocks of the document, as the parser gives them.
 * @param String $sourcePath Path of the document, from the project root, with forward slashes.
 * @param Array  $pagesTable What the site holds: the path of each file, from the project root, and its place in the site.
 * @return Array The blocks, their links pointed at the site.
 */
function pageLinks( Array $blocksList, String $sourcePath, Array $pagesTable ): Array {
	$srcFldr = dirname($sourcePath);
	foreach( $blocksList as $blkKey => $oneBlk ){
		foreach( (array)($oneBlk['spans'] ?? Array()) as $spnKey => $oneSpn ){
			$linkAdr = strval($oneSpn['links'] ?? '');
			if( $linkAdr==='' || preg_match('#^([a-z][a-z0-9+.\-]*:|\#)#i', $linkAdr) ){
				continue;
			}
			$partLst = explode('#', $linkAdr, 2);
			$pathLst = Array();
			foreach( explode('/', str_replace('\\', '/', ($srcFldr!=='.' ? $srcFldr.'/' : '').$partLst[0])) as $segVal ){
				if( $segVal==='..' ){
					array_pop($pathLst);
				}elseif( $segVal!=='' && $segVal!=='.' ){
					$pathLst[] = $segVal;
				}
			}
			$projPth = implode('/', $pathLst);
			$anchTxt = isset($partLst[1]) ? '#'.$partLst[1] : '';
			if( isset($pagesTable[$projPth]) ){
				$siteAdr = $pagesTable[$projPth].$anchTxt;
			}elseif( $projPth==='docs/online/reference' || strpos($projPth, 'docs/online/reference/')===0 ){
				$siteAdr = '../reference/'.( $projPth==='docs/online/reference' ? 'index.html' : substr($projPth, strlen('docs/online/reference/')) );
			}elseif( file_exists(TOP_DIR.DS.str_replace('/', DS, $projPth)) ){
				$siteAdr = '';
			}else{
				$siteAdr = $linkAdr;
			}
			$blocksList[$blkKey]['spans'][$spnKey]['links'] = $siteAdr;
		}
		if( !empty($oneBlk['items']) ){
			$blocksList[$blkKey]['items'] = pageLinks($oneBlk['items'], $sourcePath, $pagesTable);
		}
	}
	return $blocksList;
}

/**
 * Writes a page in the layout of the site: each %name% of the layout
 * replaced by its value, as it is. The values are HTML already: the
 * title is encoded by the caller.
 *
 * @param String $layoutText The layout, layout.html.
 * @param Array  $pageValues The values, by the name of their placeholder: 'title', 'menu', 'body'.
 * @return String The page.
 */
function putLayout( String $layoutText, Array $pageValues ): String {
	$placLst = Array();
	foreach( $pageValues as $valNme => $valTxt ){
		$placLst['%'.$valNme.'%'] = strval($valTxt);
	}
	return strtr($layoutText, $placLst);
}

$siteDir = TOP_DIR.DS.'docs'.DS.'online';
// The documents, in the order of the menu: the name of their file, in docs/<language>/ and in the site.
// The English hosting notes are HOSTING.md, at the root of the project; their translations are docs/<language>/hosting.md
$docsList = Array('userguide', 'funcspecs', 'techspecs', 'requirements', 'hosting');
// The languages: the titles of the menu, the link to the code reference (in English for all), the name of the language
$langList = Array(
	'en'=>Array('menu'=>Array('User Guide', 'Functional Specifications', 'Technical Specifications', 'Requirements', 'Hosting'), 'refr'=>'Code reference', 'name'=>'English'),
	'fr'=>Array('menu'=>Array('Guide de l’utilisateur', 'Spécifications fonctionnelles', 'Spécifications techniques', 'Exigences', 'Hébergement'), 'refr'=>'Référence du code (en anglais)', 'name'=>'Français'),
	'es'=>Array('menu'=>Array('Guía del usuario', 'Especificaciones funcionales', 'Especificaciones técnicas', 'Requisitos', 'Alojamiento'), 'refr'=>'Referencia del código (en inglés)', 'name'=>'Español')
);
// The files copied as they are: their source, and their place in the site, shared by the languages
$fileLst = Array(
	'LICENSE'=>'license.txt',
	'NOTICE'=>'notice.txt',
	'.phpdoc/site/site.css'=>'css/site.css',
	'.phpdoc/template/images/logo.png'=>'images/logo.png',
	'.phpdoc/template/fonts/Cabin.ttf'=>'fonts/Cabin.ttf',
	'.phpdoc/template/fonts/Cabin-Italic.ttf'=>'fonts/Cabin-Italic.ttf',
	'.phpdoc/template/fonts/OFL.txt'=>'fonts/OFL.txt'
);

foreach( $fileLst as $srcPth => $dstPth ){
	$dstFile = $siteDir.DS.str_replace('/', DS, $dstPth);
	if( !is_dir(dirname($dstFile)) ){
		mkdir(dirname($dstFile), 0777, true);
	}
	copy(TOP_DIR.DS.str_replace('/', DS, $srcPth), $dstFile);
}

// The pages each language has: a document is written in a language once its file is there
$langPgs = Array();
foreach( $langList as $langCode => $langVal ){
	foreach( $docsList as $nthDoc => $docNme ){
		$srcPth = ($langCode==='en' && $docNme==='hosting') ? 'HOSTING.md' : 'docs/'.$langCode.'/'.$docNme.'.md';
		if( is_file(TOP_DIR.DS.str_replace('/', DS, $srcPth)) ){
			$langPgs[$langCode][$docNme] = $langVal['menu'][$nthDoc];
		}
	}
	// A language goes online once all its documents are there: a half-translated set would link to pages it lacks
	if( isset($langPgs[$langCode]) && count($langPgs[$langCode])<count($docsList) ){
		echo $langCode, ': ', count($langPgs[$langCode]), ' of ', count($docsList), ' documents, left out until all are there', "\n";
		unset($langPgs[$langCode]);
	}
}

$lytText = strval(file_get_contents(__DIR__.DS.'layout.html'));
$bodyLst = Array();
$parsObj = new Mdwn();
$wrtrObj = new Html();
foreach( $langPgs as $langCode => $pageLst ){
	// The links of a document: the documents of its language beside it, the shared files one folder up
	$linkLst = Array();
	foreach( $pageLst as $docNme => $docTtl ){
		$linkLst[($langCode==='en' && $docNme==='hosting') ? 'HOSTING.md' : 'docs/'.$langCode.'/'.$docNme.'.md'] = $docNme.'.html';
	}
	foreach( $fileLst as $srcPth => $dstPth ){
		$linkLst[$srcPth] = '../'.$dstPth;
	}
	if( !is_dir($siteDir.DS.$langCode) ){
		mkdir($siteDir.DS.$langCode, 0777, true);
	}
	foreach( $pageLst as $docNme => $docTtl ){
		$srcPth = ($langCode==='en' && $docNme==='hosting') ? 'HOSTING.md' : 'docs/'.$langCode.'/'.$docNme.'.md';
		$envlope = $parsObj->parseDataArray( strval(file_get_contents(TOP_DIR.DS.str_replace('/', DS, $srcPth))) );
		$envlope['items'] = pageLinks($envlope['items'], $srcPth, $linkLst);
		$bodyHtm = $wrtrObj->renderDocument($envlope, Array('anchr'=>true, 'class'=>false));
		$bodyLst[$langCode.'/'.$docNme.'.html'] = $bodyHtm;
		$menuHtm = "<ul>\n";
		foreach( $pageLst as $lnkNme => $lnkTtl ){
			$curAttr = ($lnkNme===$docNme) ? ' aria-current="page"' : '';
			$menuHtm .= '<li><a href="'.$lnkNme.'.html"'.$curAttr.'>'.htmlspecialchars($lnkTtl).'</a></li>'."\n";
		}
		$menuHtm .= '<li class="menu__apart"><a href="../reference/index.html">'.htmlspecialchars($langList[$langCode]['refr']).'</a></li>'."\n</ul>";
		// The language switch: the same document in the other languages that have it
		$swtchHtm = '';
		foreach( $langPgs as $othCode => $othPage ){
			if( $othCode!==$langCode && isset($othPage[$docNme]) ){
				$swtchHtm .= '<li><a href="../'.$othCode.'/'.$docNme.'.html" hreflang="'.$othCode.'" lang="'.$othCode.'">'.htmlspecialchars($langList[$othCode]['name']).'</a></li>'."\n";
			}
		}
		$swtchHtm = ($swtchHtm==='') ? '' : '<ul class="menu__langs">'."\n".$swtchHtm."</ul>";
		file_put_contents( $siteDir.DS.$langCode.DS.$docNme.'.html', putLayout($lytText, Array('title'=>htmlspecialchars($docTtl), 'menu'=>$menuHtm, 'langMenu'=>$swtchHtm, 'langCode'=>$langCode, 'body'=>$bodyHtm)) );
		echo $langCode, '/', $docNme, '.html', "\n";
	}
}

// The entry of the site: the first language of the browser the site has, English otherwise
$langKeys = array_keys($langPgs);
$fwrdHtm = '<!doctype html>'."\n".'<html lang="en">'."\n".'<head>'."\n".'<meta charset="utf-8">'."\n".'<title>nyuRuns – Documentation</title>'."\n"
	.'<script>'."\n".'(function(){var langs='.json_encode($langKeys).';var asked=navigator.languages||[navigator.language||"en"];var found="en";'
	.'for(var i=0;i<asked.length;i++){var code=String(asked[i]).slice(0,2).toLowerCase();if(langs.indexOf(code)>=0){found=code;break;}}'
	.'location.replace(found+"/userguide.html");})();'."\n".'</script>'."\n".'</head>'."\n".'<body>'."\n".'<ul>'."\n";
foreach( $langKeys as $langCode ){
	$fwrdHtm .= '<li><a href="'.$langCode.'/userguide.html" hreflang="'.$langCode.'" lang="'.$langCode.'">'.htmlspecialchars($langList[$langCode]['name']).'</a></li>'."\n";
}
$fwrdHtm .= '</ul>'."\n".'</body>'."\n".'</html>'."\n";
file_put_contents($siteDir.DS.'index.html', $fwrdHtm);
echo count($bodyLst), ' pages written in docs/online, in ', implode(', ', $langKeys), "\n";

// Every link of the pages leads somewhere: a page of the site and its anchor, or a file the site holds.
// The links into the code reference are followed once it is made (build.ps1 makes it first).
$refrDir = is_dir($siteDir.DS.'reference');
$brokLst = Array();
$linkNbr = 0;
foreach( $bodyLst as $pagNme => $bodyTx ){
	preg_match_all('/ href="([^"]*)"/', $bodyTx, $hrefLst);
	foreach( $hrefLst[1] as $hrefTx ){
		$linkAdr = html_entity_decode($hrefTx, ENT_QUOTES | ENT_HTML5);
		if( preg_match('#^[a-z][a-z0-9+.\-]*:#i', $linkAdr) ){
			continue;
		}
		$partLst = explode('#', $linkAdr, 2);
		// The address of the link, from the root of the site
		$pathLst = Array();
		foreach( explode('/', ($partLst[0]==='') ? $pagNme : dirname($pagNme).'/'.$partLst[0]) as $segVal ){
			if( $segVal==='..' ){
				array_pop($pathLst);
			}elseif( $segVal!=='' && $segVal!=='.' ){
				$pathLst[] = $segVal;
			}
		}
		$fileNme = implode('/', $pathLst);
		if( strpos($fileNme, 'reference/')===0 && !$refrDir ){
			continue;
		}
		$linkNbr++;
		if( isset($bodyLst[$fileNme]) ){
			if( isset($partLst[1]) && strpos($bodyLst[$fileNme], ' id="'.rawurldecode($partLst[1]).'"')===false ){
				$brokLst[] = $pagNme.' -> '.$linkAdr.': no such anchor';
			}
		}elseif( !is_file($siteDir.DS.str_replace('/', DS, $fileNme)) ){
			$brokLst[] = $pagNme.' -> '.$linkAdr.': no such file';
		}
	}
}
if( !empty($brokLst) ){
	fwrite(STDERR, count($brokLst).' broken links out of '.$linkNbr.":\n  ".implode("\n  ", $brokLst)."\n");
	exit(1);
}
echo $linkNbr, ' links followed, none broken', "\n";
