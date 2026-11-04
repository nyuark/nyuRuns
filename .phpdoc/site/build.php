<?php

// Builds the documentation site into docs/site: the README as its home page, the documents of docs,
// HOSTING.md and CONTRIBUTING.md, each read by nyuRuns' own Markdown parser and written by its HTML
// writer, in the layout of layout.html and the style of the drafts (site.css). The code reference is
// made beside them, in docs/site/reference, by phpDocumentor.
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
			}elseif( $projPth==='docs/site/reference' || strpos($projPth, 'docs/site/reference/')===0 ){
				$siteAdr = 'reference/'.( $projPth==='docs/site/reference' ? 'index.html' : substr($projPth, strlen('docs/site/reference/')) );
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

$siteDir = TOP_DIR.DS.'docs'.DS.'site';
// The pages of the site: the source of each, its page and its title in the menu
$pageLst = Array(
	'README.md'=>Array('index.html', 'Overview'),
	'docs/userguide.md'=>Array('userguide.html', 'User Guide'),
	'docs/funcspecs.md'=>Array('funcspecs.html', 'Functional Specifications'),
	'docs/techspecs.md'=>Array('techspecs.html', 'Technical Specifications'),
	'docs/requirements.md'=>Array('requirements.html', 'Requirements'),
	'HOSTING.md'=>Array('hosting.html', 'Hosting'),
	'CONTRIBUTING.md'=>Array('contributing.html', 'Contributing')
);
// The files copied as they are: their source, and their place in the site
$fileLst = Array(
	'LICENSE'=>'license.txt',
	'NOTICE'=>'notice.txt',
	'.phpdoc/site/site.css'=>'css/site.css',
	'.phpdoc/template/images/logo.png'=>'images/logo.png',
	'.phpdoc/template/fonts/Cabin.ttf'=>'fonts/Cabin.ttf',
	'.phpdoc/template/fonts/Cabin-Italic.ttf'=>'fonts/Cabin-Italic.ttf',
	'.phpdoc/template/fonts/OFL.txt'=>'fonts/OFL.txt'
);
$linkLst = array_map(function( Array $pageValues ): String { return $pageValues[0]; }, $pageLst) + $fileLst;

foreach( $fileLst as $srcPth => $dstPth ){
	$dstFile = $siteDir.DS.str_replace('/', DS, $dstPth);
	if( !is_dir(dirname($dstFile)) ){
		mkdir(dirname($dstFile), 0777, true);
	}
	copy(TOP_DIR.DS.str_replace('/', DS, $srcPth), $dstFile);
}

$lytText = strval(file_get_contents(__DIR__.DS.'layout.html'));
$bodyLst = Array();
$parsObj = new Mdwn();
$wrtrObj = new Html();
foreach( $pageLst as $srcPth => $pagVal ){
	$envlope = $parsObj->parseData( strval(file_get_contents(TOP_DIR.DS.str_replace('/', DS, $srcPth))) );
	$envlope['items'] = pageLinks($envlope['items'], $srcPth, $linkLst);
	$bodyHtm = $wrtrObj->writeData($envlope, Array('anchr'=>true, 'class'=>false));
	$bodyLst[$pagVal[0]] = $bodyHtm;
	$menuHtm = "<ul>\n";
	foreach( $pageLst as $lnkPth => $lnkVal ){
		$curAttr = ($lnkPth===$srcPth) ? ' aria-current="page"' : '';
		$menuHtm .= '<li><a href="'.$lnkVal[0].'"'.$curAttr.'>'.htmlspecialchars($lnkVal[1]).'</a></li>'."\n";
	}
	$menuHtm .= '<li class="menu__apart"><a href="reference/index.html">Code reference</a></li>'."\n</ul>";
	file_put_contents( $siteDir.DS.$pagVal[0], putLayout($lytText, Array('title'=>htmlspecialchars($pagVal[1]), 'menu'=>$menuHtm, 'body'=>$bodyHtm)) );
	echo $pagVal[0], "\n";
}
echo count($pageLst), ' pages written in docs/site', "\n";

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
		$fileNme = ($partLst[0]==='') ? $pagNme : $partLst[0];
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
