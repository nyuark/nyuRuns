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
		$rootList = $this->findNestedNode($this->readPartAsTree('META-INF/container.xml') ?? Array('nodes'=>Array()), Array('rootfile'));
		$packFile = strval($rootList['attrs']['full-path'] ?? '');
		if( $packFile==='' ){
			throw new ParseError('Invalid archive: the book does not tell where its description is');
		}
		$packList = $this->readPartAsTree($packFile) ?? Array('nodes'=>Array());
		$packPath = (strrpos($packFile, '/')===false) ? '' : substr($packFile, 0, strrpos($packFile, '/')+1);
		// Its details: a detail given several times, as the authors or the subjects are, is given as one text
		$propList = Array();
		$metaList = $this->findChildArray($packList, Array('metadata')) ?? Array('nodes'=>Array());
		foreach($metaList['nodes'] as $onePrp){
			$propName = strval(self::PROP_LIST[$onePrp['tagnm']] ?? '');
			$propVals = trim(preg_replace('/\s+/', ' ', $this->readNestedText($onePrp)));
			if( $propName!=='' && $propVals!=='' ){
				$propList[$propName] = isset($propList[$propName]) ? $propList[$propName].', '.$propVals : $propVals;
			}
		}
		// Its parts, by their id: their place in the archive, their kind, and what is particular to them
		$itemList = Array();
		$coverPth = '';
		foreach($this->listNamedNodes($this->findChildArray($packList, Array('manifest')) ?? Array('nodes'=>Array()), 'item') as $oneItm){
			$itemPath = $this->getPathInArchv($packPath, strval($oneItm['attrs']['href'] ?? ''));
			$itemList[strval($oneItm['attrs']['id'] ?? '')] = Array('paths'=>$itemPath, 'types'=>strtolower(strval($oneItm['attrs']['media-type'] ?? '')));
			if( in_array('cover-image', explode(' ', strval($oneItm['attrs']['properties'] ?? ''))) ){
				$coverPth = $itemPath;
			}
		}
		// Older books name their cover among their details
		foreach($this->listNamedNodes($metaList, 'meta') as $oneMta){
			if( $coverPth==='' && strval($oneMta['attrs']['name'] ?? '')=='cover' ){
				$coverPth = strval($itemList[strval($oneMta['attrs']['content'] ?? '')]['paths'] ?? '');
			}
		}
		// The parts a seller locked cannot be read: a book whose text is locked is refused, one whose fonts only are is not
		$lockText = $this->archiveObj->isEntryInArchv('META-INF/encryption.xml') ? $this->archiveObj->getEntryString('META-INF/encryption.xml', $this->maxSizeVal) : '';
		$blockLst = Array();
		$chptNumb = 0;
		foreach($this->listNamedNodes($this->findChildArray($packList, Array('spine')) ?? Array('nodes'=>Array()), 'itemref') as $oneRef){
			$itemDict = $itemList[strval($oneRef['attrs']['idref'] ?? '')] ?? null;
			// The reading order may name what is not a page of text, such as a drawing
			if( $itemDict===null || !preg_match('/html|^$/', $itemDict['types']) || ($itemDict['types']==='' && !preg_match('/\.x?html?$/Di', $itemDict['paths'])) ){
				continue;
			}
			if( !$this->archiveObj->isEntryInArchv($itemDict['paths']) ){
				throw new ParseError('Invalid archive: part '.$itemDict['paths'].' is missing');
			}
			if( $lockText!=='' && (strpos($lockText, '"'.$itemDict['paths'].'"')!==false || strpos($lockText, '"'.rawurlencode($itemDict['paths']).'"')!==false || strpos($lockText, '"'.str_replace('%2F', '/', rawurlencode($itemDict['paths'])).'"')!==false) ){
				throw new ParseError('Invalid archive: the book is protected against copying');
			}
			try{
				$chptDict = (new XHml())->parseDataArray($this->archiveObj->getEntryString($itemDict['paths'], $this->maxSizeVal), Array('reads'=>'html', 'shape'=>'block'))['items'];
			}catch( ParseError $e ){
				throw new ParseError('Invalid archive: part '.$itemDict['paths'].': '.$e->getMessage(), 0, $e);
			}
			$chptNumb++;
			if( empty($chptDict) ){
				continue;
			}
			$slashIdx = strrpos($itemDict['paths'], '/');
			$chptDict = $this->alterImageAddr($chptDict, ($slashIdx===false) ? '' : substr($itemDict['paths'], 0, $slashIdx+1));
			// A chapter starts on a new page
			if( !empty($blockLst) ){
				$blockLst[] = Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true));
			}
			$blockLst = array_merge($blockLst, $chptDict);
		}
		return $this->makeShapeArray('block', $blockLst, Array(
			'title'=>strval($propList['Title'] ?? ''), 'authr'=>strval($propList['Author'] ?? ''), 'langg'=>strval($propList['Language'] ?? ''),
			'chaps'=>$chptNumb, 'cover'=>$coverPth, 'props'=>$propList
		));
	}

	/**
	 * Gives the place in the archive of what a part points to.
	 *
	 * @param String $baseFldrText Folder of the part, ending with a slash, '' for the root of the archive.
	 * @param String $relativePath The address, as the part writes it: from its own folder, with or without a place in the page after #.
	 * @return String The place in the archive.
	 */
	protected function getPathInArchv(String $baseFldrText, String $relativePath): String {
		$pathPart = Array();
		foreach(explode('/', $baseFldrText.rawurldecode(preg_replace('/[#?].*$/s', '', $relativePath))) as $onePth){
			if( $onePth=='..' ){
				array_pop($pathPart);
			}elseif( $onePth!=='' && $onePth!='.' ){
				$pathPart[] = $onePth;
			}
		}
		return implode('/', $pathPart);
	}

	/**
	 * Gives the images of some blocks their place in the archive, in the
	 * stead of the address their chapter writes from its own folder. An
	 * image kept outside the book keeps its address.
	 *
	 * @param Array  $bookBlockSet The blocks, at any depth.
	 * @param String $baseFldrText Folder of the chapter, ending with a slash.
	 * @return Array The blocks.
	 */
	protected function alterImageAddr(Array $bookBlockSet, String $baseFldrText): Array {
		foreach($bookBlockSet as $nthBlk=>$oneBlk){
			if( $oneBlk['block']=='imageBox' && !preg_match('/^(?:[a-z][a-z0-9+.\-]*:|\/|#)/i', strval($oneBlk['attrs']['sourc'] ?? '')) ){
				$bookBlockSet[$nthBlk]['attrs']['sourc'] = $this->getPathInArchv($baseFldrText, strval($oneBlk['attrs']['sourc'] ?? ''));
			}
			if( !empty($oneBlk['items']) ){
				$bookBlockSet[$nthBlk]['items'] = $this->alterImageAddr($oneBlk['items'], $baseFldrText);
			}
		}
		return $bookBlockSet;
	}

}
