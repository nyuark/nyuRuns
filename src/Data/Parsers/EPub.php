<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * Books (epub), read into text blocks: the chapters of the book in the
 * order it gives them, each read as the HTML page it is, a page break
 * standing between two of them.
 *
 * The addresses of the images are their place in the archive. The links
 * from a chapter to another stay as the book writes them.
 */
class EPub extends Pack {

	// Details of a book, by the element holding them: the name Word gives the same property of a document, or their own
	const PROP_LIST = Array('dc:title'=>'Title', 'dc:creator'=>'Author', 'dc:subject'=>'Keywords', 'dc:description'=>'Comments', 'dc:publisher'=>'Publisher', 'dc:language'=>'Language', 'dc:identifier'=>'Identifier', 'dc:date'=>'Published', 'dc:rights'=>'Rights');

	/**
	 * Reads the book of an opened archive.
	 *
	 * @param Array $parseOptions Options: none.
	 * @return Array The envelope, of shape 'block'; 'infos' holds the title of the book under 'title', its author under 'authr', its language under 'langg', the number of its chapters under 'chaps', the place of its cover in the archive under 'cover', and its details under 'props'.
	 *
	 * @throws ParseError When the archive is not a valid book, or the book is protected against copying.
	 */
	protected function readArchvArray(Array $parseOptions): Array {
		// The archive says where the description of the book is
		$rootArr = $this->deepNamedArray($this->partNodesArray('META-INF/container.xml') ?? Array('nodes'=>Array()), Array('rootfile'));
		$pckPath = strval($rootArr['attrs']['full-path'] ?? '');
		if( $pckPath==='' ){
			throw new ParseError('Invalid archive: the book does not tell where its description is');
		}
		$packArr = $this->partNodesArray($pckPath) ?? Array('nodes'=>Array());
		$packDir = (strrpos($pckPath, '/')===false) ? '' : substr($pckPath, 0, strrpos($pckPath, '/')+1);
		// Its details: a detail given several times, as the authors or the subjects are, is given as one text
		$propLst = Array();
		$metaArr = $this->findChildArray($packArr, Array('metadata')) ?? Array('nodes'=>Array());
		foreach($metaArr['nodes'] as $prpArr){
			$propNme = strval(self::PROP_LIST[$prpArr['tagnm']] ?? '');
			$propVal = trim(preg_replace('/\s+/', ' ', $this->collectAllText($prpArr)));
			if( $propNme!=='' && $propVal!=='' ){
				$propLst[$propNme] = isset($propLst[$propNme]) ? $propLst[$propNme].', '.$propVal : $propVal;
			}
		}
		// Its parts, by their id: their place in the archive, their kind, and what is particular to them
		$itemLst = Array();
		$cvrPath = '';
		foreach($this->kidsNamedArray($this->findChildArray($packArr, Array('manifest')) ?? Array('nodes'=>Array()), 'item') as $itmArr){
			$itmPath = $this->mergePathsText($packDir, strval($itmArr['attrs']['href'] ?? ''));
			$itemLst[strval($itmArr['attrs']['id'] ?? '')] = Array('paths'=>$itmPath, 'types'=>strtolower(strval($itmArr['attrs']['media-type'] ?? '')));
			if( in_array('cover-image', explode(' ', strval($itmArr['attrs']['properties'] ?? ''))) ){
				$cvrPath = $itmPath;
			}
		}
		// Older books name their cover among their details
		foreach($this->kidsNamedArray($metaArr, 'meta') as $mtaArr){
			if( $cvrPath==='' && strval($mtaArr['attrs']['name'] ?? '')=='cover' ){
				$cvrPath = strval($itemLst[strval($mtaArr['attrs']['content'] ?? '')]['paths'] ?? '');
			}
		}
		// The parts a seller locked cannot be read: a book whose text is locked is refused, one whose fonts only are is not
		$lockTxt = $this->opnArchive->locateEntryFlg('META-INF/encryption.xml') ? $this->opnArchive->getEntryString('META-INF/encryption.xml', $this->maxSizeVal) : '';
		$blckLst = Array();
		$chptrNb = 0;
		foreach($this->kidsNamedArray($this->findChildArray($packArr, Array('spine')) ?? Array('nodes'=>Array()), 'itemref') as $oneRef){
			$itemSet = $itemLst[strval($oneRef['attrs']['idref'] ?? '')] ?? null;
			// The reading order may name what is not a page of text, such as a drawing
			if( $itemSet===null || !preg_match('/html|^$/', $itemSet['types']) || ($itemSet['types']==='' && !preg_match('/\.x?html?$/Di', $itemSet['paths'])) ){
				continue;
			}
			if( !$this->opnArchive->locateEntryFlg($itemSet['paths']) ){
				throw new ParseError('Invalid archive: part '.$itemSet['paths'].' is missing');
			}
			if( $lockTxt!=='' && (strpos($lockTxt, '"'.$itemSet['paths'].'"')!==false || strpos($lockTxt, '"'.rawurlencode($itemSet['paths']).'"')!==false || strpos($lockTxt, '"'.str_replace('%2F', '/', rawurlencode($itemSet['paths'])).'"')!==false) ){
				throw new ParseError('Invalid archive: the book is protected against copying');
			}
			try{
				$chapter = (new XHml())->parseDataArray($this->opnArchive->getEntryString($itemSet['paths'], $this->maxSizeVal), Array('reads'=>'html', 'shape'=>'block'))['items'];
			}catch( ParseError $e ){
				throw new ParseError('Invalid archive: part '.$itemSet['paths'].': '.$e->getMessage(), 0, $e);
			}
			$chptrNb++;
			if( empty($chapter) ){
				continue;
			}
			$slashAt = strrpos($itemSet['paths'], '/');
			$chapter = $this->positionImages($chapter, ($slashAt===false) ? '' : substr($itemSet['paths'], 0, $slashAt+1));
			// A chapter starts on a new page
			if( !empty($blckLst) ){
				$blckLst[] = Shape::makeBlockArray('rules', Array(), Array(), Array('break'=>true));
			}
			$blckLst = array_merge($blckLst, $chapter);
		}
		return $this->makeShapeArray('block', $blckLst, Array(
			'title'=>strval($propLst['Title'] ?? ''), 'authr'=>strval($propLst['Author'] ?? ''), 'langg'=>strval($propLst['Language'] ?? ''),
			'chaps'=>$chptrNb, 'cover'=>$cvrPath, 'props'=>$propLst
		));
	}

	/**
	 * Gives the place in the archive of what a part points to.
	 *
	 * @param String $baseFoldText Folder of the part, ending with a slash, '' for the root of the archive.
	 * @param String $relativePath The address, as the part writes it: from its own folder, with or without a place in the page after #.
	 * @return String The place in the archive.
	 */
	protected function mergePathsText(String $baseFoldText, String $relativePath): String {
		$pthPart = Array();
		foreach(explode('/', $baseFoldText.rawurldecode(preg_replace('/[#?].*$/s', '', $relativePath))) as $oneDir){
			if( $oneDir=='..' ){
				array_pop($pthPart);
			}elseif( $oneDir!=='' && $oneDir!='.' ){
				$pthPart[] = $oneDir;
			}
		}
		return implode('/', $pthPart);
	}

	/**
	 * Gives the images of some blocks their place in the archive, in the
	 * stead of the address their chapter writes from its own folder. An
	 * image kept outside the book keeps its address.
	 *
	 * @param Array  $bookBlockSet The blocks, at any depth.
	 * @param String $baseFoldText Folder of the chapter, ending with a slash.
	 * @return Array The blocks.
	 */
	protected function positionImages(Array $bookBlockSet, String $baseFoldText): Array {
		foreach($bookBlockSet as $blkIdx=>$oneBlk){
			if( $oneBlk['block']=='image' && !preg_match('/^(?:[a-z][a-z0-9+.\-]*:|\/|#)/i', strval($oneBlk['attrs']['sourc'] ?? '')) ){
				$bookBlockSet[$blkIdx]['attrs']['sourc'] = $this->mergePathsText($baseFoldText, strval($oneBlk['attrs']['sourc'] ?? ''));
			}
			if( !empty($oneBlk['items']) ){
				$bookBlockSet[$blkIdx]['items'] = $this->positionImages($oneBlk['items'], $baseFoldText);
			}
		}
		return $bookBlockSet;
	}

}
