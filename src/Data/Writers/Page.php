<?php

namespace nyu\Data\Writers;

use nyu\Data\Fluxd;
use nyu\Data\Image;
use nyu\Data\Shape;

/**
 * What the writers of text documents share: the paper their pages are
 * laid out on, the notes taken out of the text to be written where it
 * refers to them, sheets given as tables, the names of the places of a
 * document, and the pictures, which are those of a folder the writer is
 * given and no other: none is ever taken from an address.
 */
abstract class Page extends Base {

	// The sizes of paper, by the code that asks for them: width and height in twentieths of a point
	const SIZE_LIST = Array('isoPgeA3'=>Array(16838, 23811), 'isoPgeA4'=>Array(11906, 16838), 'isoPgeA5'=>Array(8391, 11906), 'usLetter'=>Array(12240, 15840), 'legalUsa'=>Array(12240, 20160));
	// Greatest size of all the pictures of a document, in bytes: 64 MB
	const MEDI_MAXI = 67108864;
	// The kinds of picture written as they are, with the type each is told for; any other is written as PNG
	const PICT_LIST = Array('png'=>'image/png', 'jpg'=>'image/jpeg', 'gif'=>'image/gif');
	// The styles the documents define themselves, by the names Word and LibreOffice give them, in small letters: a style of the content bearing one of these names is not defined again
	const OWNS_STYL = Array(
		'normal', 'standard', 'default paragraph font', 'text body', 'heading 1', 'heading 2', 'heading 3', 'heading 4', 'heading 5', 'heading 6', 'title', 'list paragraph',
		'quote', 'quotations', 'html preformatted', 'preformatted text', 'hyperlink', 'internet link', 'table heading',
		'footnote text', 'footnote reference', 'endnote text', 'endnote reference', 'footnote', 'endnote'
	);

	// The folder the pictures are taken from, '' when the document is given none
	protected $pictureDir = '';

	/**
	 * Gives the size of the pages the options ask for.
	 *
	 * @param Array $writeOptions Options of the writer: the size of paper under 'paper', 'isoPgeA4' when left out, and whether the pages lie on their long side under 'lands'.
	 * @return Array The width and the height of a page, in twentieths of a point.
	 *
	 * @throws WriteError When the size of paper is not a known one.
	 */
	protected function getPageSizeDct(Array $writeOptions): Array {
		$sizeClef = strval($writeOptions['paper'] ?? 'isoPgeA4');
		// The size whatever its case, as SIZE_LIST writes it
		$sizeClef = strval( array_values(preg_grep('/^'.preg_quote($sizeClef, '/').'$/iD', array_keys(self::SIZE_LIST)))[0] ?? $sizeClef );
		if( !isset(self::SIZE_LIST[$sizeClef]) ){
			throw new WriteError('Invalid document option: unknown paper '.$sizeClef);
		}
		return empty($writeOptions['lands']) ? self::SIZE_LIST[$sizeClef] : array_reverse(self::SIZE_LIST[$sizeClef]);
	}

	/**
	 * Takes the folder of the pictures from the options.
	 *
	 * @param Array $writeOptions Options of the writer: the folder under 'media', none when left out.
	 * @return Void
	 *
	 * @throws WriteError When the folder cannot be read.
	 */
	protected function readPictureDir(Array $writeOptions): Void {
		$this->pictureDir = '';
		if( strval($writeOptions['media'] ?? '')!=='' ){
			$realPath = realpath(strval($writeOptions['media']));
			if( $realPath===false || !is_dir($realPath) || !is_readable($realPath) ){
				throw new WriteError('Invalid document option: the folder of the pictures cannot be read');
			}
			$this->pictureDir = $realPath;
		}
	}

	/**
	 * Takes the notes out of the blocks of a document: each is written
	 * where the text refers to it, not where it stands among the blocks.
	 *
	 * @param Array $bookBlockSet The blocks; the notes are taken out of them.
	 * @return Array The notes of the pages under 'fnote' and those of the end under 'enote', each list by the name of the note: its block.
	 */
	protected function splitNotesDict(Array &$bookBlockSet): Array {
		$noteList = Array('fnote'=>Array(), 'enote'=>Array());
		$matxRows = Array();
		foreach($bookBlockSet as $blkIdx=>$blkArr){
			if( is_array($blkArr) && strval($blkArr['block'] ?? '')=='footnote' && preg_match('/^(fnote|enote)(\d+)$/D', strval($blkArr['attrs']['tagid'] ?? ''), $matxRows) ){
				$noteList[$matxRows[1]][$matxRows[0]] = $blkArr;
				unset($bookBlockSet[$blkIdx]);
			}
		}
		return $noteList;
	}

	/**
	 * Reads a picture of the folder the writer was given. The picture is
	 * read for what it is, set upright, and written anew: nothing it
	 * carried comes with it. A picture the server cannot write anew is
	 * given as it is when its kind is one a document holds.
	 *
	 * @param String $pageFileName The name of its file, in the folder: never an address, never a file of another folder.
	 * @return ?Array Its bytes under 'bytes', its kind under 'kinds' (png, jpg or gif), its width and its height in pixels under 'width' and 'heght'; null when it is not a picture of the folder.
	 */
	protected function readPictureDct(String $pageFileName): ?Array {
		if( $this->pictureDir==='' || $pageFileName==='' || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $pageFileName) || strpos($pageFileName, "\0")!==false ){
			return null;
		}
		$filePath = realpath($this->pictureDir.DIRECTORY_SEPARATOR.$pageFileName);
		if( $filePath===false || !is_file($filePath) || strpos($filePath, rtrim($this->pictureDir, '/\\').DIRECTORY_SEPARATOR)!==0 ){
			return null;
		}
		try{
			$pictInst = Image::openFluxdImage(Fluxd::openLocalFluxd($filePath, $this->pictureDir));
			$kindClef = strval($pictInst->getInfoOfImage()['kinds']);
			$givenCod = isset(self::PICT_LIST[$kindClef]) ? $kindClef : 'png';
			try{
				$pictInst = $pictInst->turnUprightImg();
				$pictText = $pictInst->grantBytesText($givenCod);
			}catch( \RuntimeException $e ){
				// Without what writes pictures anew, one of a kind a document holds is given as it is
				if( !isset(self::PICT_LIST[$kindClef]) ){
					return null;
				}
				$pictText = strval(file_get_contents($filePath));
			}
			$infoDict = $pictInst->getInfoOfImage();
		}catch( \RuntimeException $e ){
			return null;
		}
		return Array('bytes'=>$pictText, 'kinds'=>$givenCod, 'width'=>max(1, intval($infoDict['width'])), 'heght'=>max(1, intval($infoDict['heght'])));
	}

	/**
	 * Gives sheets as text blocks: each sheet that is shown is a table,
	 * under a heading bearing its name. The names of its columns make the
	 * first row of the table, repeated at the top of each page.
	 *
	 * @param Array $sheetEntries The sheets.
	 * @return Array The blocks.
	 */
	protected function getSheetBlocks(Array $sheetEntries): Array {
		$blockLst = Array();
		foreach($sheetEntries as $shtArr){
			if( !is_array($shtArr) || (isset($shtArr['attrs']['shown']) && empty($shtArr['attrs']['shown'])) ){
				continue;
			}
			$rowsList = array_values((array)($shtArr['cells'] ?? Array()));
			$headList = Array();
			foreach($rowsList as $oneRow){
				foreach($this->isSequenceList((array)$oneRow) ? Array() : array_keys((array)$oneRow) as $column){
					if( !in_array(strval($column), $headList, true) ){
						$headList[] = strval($column);
					}
				}
			}
			// The cells an area covers are not cells of their own; the one it starts at tells what it covers
			$spanList = Array();
			$coverLst = Array();
			$headSpan = empty($headList) ? 0 : 1;
			foreach((array)($shtArr['attrs']['merge'] ?? Array()) as $mrgArr){
				$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
				if( $mrgArr['atrow']<$headSpan || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || $mrgArr['rowsp']*$mrgArr['colsp']>10000 || isset($coverLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]) ){
					continue;
				}
				for($rowIdx=$mrgArr['atrow']; $rowIdx<$mrgArr['atrow']+$mrgArr['rowsp']; $rowIdx++){
					for($colInc=$mrgArr['atcol']; $colInc<$mrgArr['atcol']+$mrgArr['colsp']; $colInc++){
						$coverLst[$rowIdx.':'.$colInc] = true;
					}
				}
				unset($coverLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]);
				$spanList[$mrgArr['atrow'].':'.$mrgArr['atcol']] = array_filter(Array('rowsp'=>$mrgArr['rowsp'], 'colsp'=>$mrgArr['colsp']), function(Int $colSpanCount){ return $colSpanCount>1; });
			}
			$tableRow = empty($headList) ? Array() : Array(Shape::makeBlockArray('tableTop', Array(), array_map(function(String $columnHeader){ return Shape::makeBlockArray('cellText', Array(Shape::makeSpanOfText($columnHeader))); }, $headList)));
			$colsNumb = count($headList);
			foreach($rowsList as $oneRow){
				$colsNumb = max($colsNumb, count((array)$oneRow));
			}
			foreach($rowsList as $rowIdx=>$oneRow){
				$cellList = Array();
				$valsList = empty($headList) ? array_values((array)$oneRow) : array_map(function(String $columnHeader) use ($oneRow){ return ((array)$oneRow)[$columnHeader] ?? null; }, $headList);
				for($colInc=0; $colInc<$colsNumb; $colInc++){
					$cellClef = ($rowIdx+$headSpan).':'.$colInc;
					if( isset($coverLst[$cellClef]) ){
						continue;
					}
					$cellVals = $valsList[$colInc] ?? null;
					$cellText = '';
					if( is_bool($cellVals) ){
						$cellText = $cellVals ? 'TRUE' : 'FALSE';
					}elseif( is_string($cellVals) ){
						$cellText = $cellVals;
					}elseif( is_int($cellVals) || (is_float($cellVals) && is_finite($cellVals)) ){
						$cellText = $this->writeNumberTxt($cellVals);
					}elseif( is_array($cellVals) ){
						$cellText = strval(json_encode(Fluxd::dropAllSources($cellVals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
					}
					// Numbers stand on the right of their column, as in a spreadsheet
					$cellList[] = Shape::makeBlockArray('cellText', ($cellText==='') ? Array() : Array(Shape::makeSpanOfText($cellText)), Array(), ($spanList[$cellClef] ?? Array()) + ((is_int($cellVals) || is_float($cellVals)) ? Array('align'=>'alignRgt') : Array()));
				}
				$tableRow[] = Shape::makeBlockArray('tableRow', Array(), $cellList);
			}
			$sheetNme = is_scalar($shtArr['title'] ?? '') ? trim(strval($shtArr['title'] ?? '')) : '';
			if( $sheetNme!=='' ){
				$blockLst[] = Shape::makeBlockArray('headline', Array(Shape::makeSpanOfText($sheetNme)), Array(), Array('level'=>2));
			}
			if( !empty($tableRow) ){
				$blockLst[] = Shape::makeBlockArray('tableSet', Array(), $tableRow);
			}
		}
		return $blockLst;
	}

	/**
	 * Gives a place of the document a name every document accepts: letters, digits
	 * and underscores, starting with a letter, 40 characters at most.
	 *
	 * @param String $locationText The name asked for.
	 * @return String The name, '' when nothing of it can be kept.
	 */
	protected function wipePlaceTitle(String $locationText): String {
		$nameText = trim(strval(preg_replace('/[^A-Za-z0-9_]+/', '_', $locationText)), '_');
		if( $nameText==='' ){
			return '';
		}
		return substr(preg_match('/^[A-Za-z]/', $nameText) ? $nameText : 'p_'.$nameText, 0, 40);
	}

}
