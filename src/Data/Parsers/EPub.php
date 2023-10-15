<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

// Books (epub), read into text blocks: the chapters of the book in the
// order it gives them, each read as the HTML page it is, a page break
// standing between two of them.
//
// The addresses of the images are their place in the archive. The links
// from a chapter to another stay as the book writes them.
class EPub extends Pack {

	// Details of a book, by the element holding them: the name Word gives the same property of a document, or their own
	const PROPLIST = Array('dc:title'=>'Title', 'dc:creator'=>'Author', 'dc:subject'=>'Keywords', 'dc:description'=>'Comments', 'dc:publisher'=>'Publisher', 'dc:language'=>'Language', 'dc:identifier'=>'Identifier', 'dc:date'=>'Published', 'dc:rights'=>'Rights');

	/**
	 * Reads the book of an opened archive.
	 *
	 * @param Array $parseOptns Options: none.
	 * @return Array The envelope, of shape 'block'; 'infos' holds the title of the book under 'title', its author under 'authr', its language under 'langg', the number of its chapters under 'chaps', the place of its cover in the archive under 'cover', and its details under 'props'.
	 *
	 * @throws ParseError When the archive is not a valid book, or the book is protected against copying.
	 */
	protected function readArchv(Array $parseOptns): Array {
		// The archive says where the description of the book is
		$rootArr = $this->deepNamed($this->partNodes('META-INF/container.xml') ?? Array('nodes'=>Array()), Array('rootfile'));
		$packPth = strval($rootArr['attrs']['full-path'] ?? '');
		if( $packPth==='' ){
			throw new ParseError('Invalid archive: the book does not tell where its description is');
		}
		$packArr = $this->partNodes($packPth) ?? Array('nodes'=>Array());
		$packDir = (strrpos($packPth, '/')===false) ? '' : substr($packPth, 0, strrpos($packPth, '/')+1);
		// Its details: a detail given several times, as the authors or the subjects are, is given as one text
		$propLst = Array();
		$metaArr = $this->findChild($packArr, Array('metadata')) ?? Array('nodes'=>Array());
		foreach($metaArr['nodes'] as $prpArr){
			$propNme = strval(self::PROPLIST[$prpArr['tagnm']] ?? '');
			$propVal = trim(preg_replace('/\s+/', ' ', $this->textOfTag($prpArr)));
			if( $propNme!=='' && $propVal!=='' ){
				$propLst[$propNme] = isset($propLst[$propNme]) ? $propLst[$propNme].', '.$propVal : $propVal;
			}
		}
		// Its parts, by their id: their place in the archive, their kind, and what is particular to them
		$itemLst = Array();
		$covrPth = '';
		foreach($this->kidsNamed($this->findChild($packArr, Array('manifest')) ?? Array('nodes'=>Array()), 'item') as $itmArr){
			$itemPth = $this->joinPaths($packDir, strval($itmArr['attrs']['href'] ?? ''));
			$itemLst[strval($itmArr['attrs']['id'] ?? '')] = Array('paths'=>$itemPth, 'types'=>strtolower(strval($itmArr['attrs']['media-type'] ?? '')));
			if( in_array('cover-image', explode(' ', strval($itmArr['attrs']['properties'] ?? ''))) ){
				$covrPth = $itemPth;
			}
		}
		// Older books name their cover among their details
		foreach($this->kidsNamed($metaArr, 'meta') as $mtaArr){
			if( $covrPth==='' && strval($mtaArr['attrs']['name'] ?? '')=='cover' ){
				$covrPth = strval($itemLst[strval($mtaArr['attrs']['content'] ?? '')]['paths'] ?? '');
			}
		}
		// The parts a seller locked cannot be read: a book whose text is locked is refused, one whose fonts only are is not
		$lockTxt = $this->openArch->findEntry('META-INF/encryption.xml') ? $this->openArch->readEntry('META-INF/encryption.xml', $this->sizeLimt) : '';
		$blckLst = Array();
		$chapNbr = 0;
		foreach($this->kidsNamed($this->findChild($packArr, Array('spine')) ?? Array('nodes'=>Array()), 'itemref') as $refArr){
			$itemSet = $itemLst[strval($refArr['attrs']['idref'] ?? '')] ?? null;
			// The reading order may name what is not a page of text, such as a drawing
			if( $itemSet===null || !preg_match('/html|^$/', $itemSet['types']) || ($itemSet['types']==='' && !preg_match('/\.x?html?$/Di', $itemSet['paths'])) ){
				continue;
			}
			if( !$this->openArch->findEntry($itemSet['paths']) ){
				throw new ParseError('Invalid archive: part '.$itemSet['paths'].' is missing');
			}
			if( $lockTxt!=='' && (strpos($lockTxt, '"'.$itemSet['paths'].'"')!==false || strpos($lockTxt, '"'.rawurlencode($itemSet['paths']).'"')!==false || strpos($lockTxt, '"'.str_replace('%2F', '/', rawurlencode($itemSet['paths'])).'"')!==false) ){
				throw new ParseError('Invalid archive: the book is protected against copying');
			}
			try{
				$chapBlk = (new XHml())->parseData($this->openArch->readEntry($itemSet['paths'], $this->sizeLimt), Array('reads'=>'html', 'shape'=>'block'))['items'];
			}catch( ParseError $e ){
				throw new ParseError('Invalid archive: part '.$itemSet['paths'].': '.$e->getMessage(), 0, $e);
			}
			$chapNbr++;
			if( empty($chapBlk) ){
				continue;
			}
			$slshPos = strrpos($itemSet['paths'], '/');
			$chapBlk = $this->placeImgs($chapBlk, ($slshPos===false) ? '' : substr($itemSet['paths'], 0, $slshPos+1));
			// A chapter starts on a new page
			if( !empty($blckLst) ){
				$blckLst[] = Shape::makeBlock('rules', Array(), Array(), Array('break'=>true));
			}
			$blckLst = array_merge($blckLst, $chapBlk);
		}
		return $this->makeShape('block', $blckLst, Array(
			'title'=>strval($propLst['Title'] ?? ''), 'authr'=>strval($propLst['Author'] ?? ''), 'langg'=>strval($propLst['Language'] ?? ''),
			'chaps'=>$chapNbr, 'cover'=>$covrPth, 'props'=>$propLst
		));
	}

	/**
	 * Gives the place in the archive of what a part points to.
	 *
	 * @param String $baseFolder Folder of the part, ending with a slash, '' for the root of the archive.
	 * @param String $relatvPath The address, as the part writes it: from its own folder, with or without a place in the page after #.
	 * @return String The place in the archive.
	 */
	protected function joinPaths(String $baseFolder, String $relatvPath): String {
		$pathPrt = Array();
		foreach(explode('/', $baseFolder.rawurldecode(preg_replace('/[#?].*$/s', '', $relatvPath))) as $pthSeg){
			if( $pthSeg=='..' ){
				array_pop($pathPrt);
			}elseif( $pthSeg!=='' && $pthSeg!='.' ){
				$pathPrt[] = $pthSeg;
			}
		}
		return implode('/', $pathPrt);
	}

	/**
	 * Gives the images of some blocks their place in the archive, in the
	 * stead of the address their chapter writes from its own folder. An
	 * image kept outside the book keeps its address.
	 *
	 * @param Array  $blocksList The blocks, at any depth.
	 * @param String $baseFolder Folder of the chapter, ending with a slash.
	 * @return Array The blocks.
	 */
	protected function placeImgs(Array $blocksList, String $baseFolder): Array {
		foreach($blocksList as $blkIdx=>$oneBlk){
			if( $oneBlk['block']=='image' && !preg_match('/^(?:[a-z][a-z0-9+.\-]*:|\/|#)/i', strval($oneBlk['attrs']['sourc'] ?? '')) ){
				$blocksList[$blkIdx]['attrs']['sourc'] = $this->joinPaths($baseFolder, strval($oneBlk['attrs']['sourc'] ?? ''));
			}
			if( !empty($oneBlk['items']) ){
				$blocksList[$blkIdx]['items'] = $this->placeImgs($oneBlk['items'], $baseFolder);
			}
		}
		return $blocksList;
	}

}
