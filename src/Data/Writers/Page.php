<?php

namespace nyu\Data\Writers;

use nyu\Data\Fluxd;
use nyu\Data\Image;
use nyu\Data\Shape;

// What the writers of text documents share: the paper their pages are
// laid out on, the notes taken out of the text to be written where it
// refers to them, sheets given as tables, the names of the places of a
// document, and the pictures, which are those of a folder the writer is
// given and no other: none is ever taken from an address.
abstract class Page extends Base {

	// The sizes of paper, by the code that asks for them: width and height in twentieths of a point
	const SIZELIST = Array('isoa3'=>Array(16838, 23811), 'isoa4'=>Array(11906, 16838), 'isoa5'=>Array(8391, 11906), 'lettr'=>Array(12240, 15840), 'legal'=>Array(12240, 20160));
	// Greatest size of all the pictures of a document, in bytes: 64 MB
	const MEDIAMAX = 67108864;
	// The kinds of picture written as they are, with the type each is told for; any other is written as PNG
	const PICTLIST = Array('png'=>'image/png', 'jpg'=>'image/jpeg', 'gif'=>'image/gif');
	// The styles the documents define themselves, by the names Word and LibreOffice give them, in small letters: a style of the content bearing one of these names is not defined again
	const OWNSTYLS = Array(
		'normal', 'standard', 'default paragraph font', 'text body', 'heading 1', 'heading 2', 'heading 3', 'heading 4', 'heading 5', 'heading 6', 'title', 'list paragraph',
		'quote', 'quotations', 'html preformatted', 'preformatted text', 'hyperlink', 'internet link', 'table heading',
		'footnote text', 'footnote reference', 'endnote text', 'endnote reference', 'footnote', 'endnote'
	);

	// The folder the pictures are taken from, '' when the document is given none
	protected $mediaDir = '';

	/**
	 * Gives the size of the pages the options ask for.
	 *
	 * @param Array $writeOptns Options of the writer: the size of paper under 'paper', 'isoa4' when left out, and whether the pages lie on their long side under 'lands'.
	 * @return Array The width and the height of a page, in twentieths of a point.
	 *
	 * @throws WriteError When the size of paper is not a known one.
	 */
	protected function paperSize(Array $writeOptns): Array {
		$sizeKey = strtolower(strval($writeOptns['paper'] ?? 'isoa4'));
		if( !isset(self::SIZELIST[$sizeKey]) ){
			throw new WriteError('Invalid document option: unknown paper '.$sizeKey);
		}
		return empty($writeOptns['lands']) ? self::SIZELIST[$sizeKey] : array_reverse(self::SIZELIST[$sizeKey]);
	}

	/**
	 * Takes the folder of the pictures from the options.
	 *
	 * @param Array $writeOptns Options of the writer: the folder under 'media', none when left out.
	 * @return Void
	 *
	 * @throws WriteError When the folder cannot be read.
	 */
	protected function mediaFrom(Array $writeOptns): Void {
		$this->mediaDir = '';
		if( strval($writeOptns['media'] ?? '')!=='' ){
			$realDir = realpath(strval($writeOptns['media']));
			if( $realDir===false || !is_dir($realDir) || !is_readable($realDir) ){
				throw new WriteError('Invalid document option: the folder of the pictures cannot be read');
			}
			$this->mediaDir = $realDir;
		}
	}

	/**
	 * Takes the notes out of the blocks of a document: each is written
	 * where the text refers to it, not where it stands among the blocks.
	 *
	 * @param Array $blocksList The blocks; the notes are taken out of them.
	 * @return Array The notes of the pages under 'fnote' and those of the end under 'enote', each list by the name of the note: its block.
	 */
	protected function takeNotes(Array &$blocksList): Array {
		$noteLst = Array('fnote'=>Array(), 'enote'=>Array());
		$notePrt = Array();
		foreach($blocksList as $blkIdx=>$blkArr){
			if( is_array($blkArr) && strval($blkArr['block'] ?? '')=='fnote' && preg_match('/^(fnote|enote)(\d+)$/D', strval($blkArr['attrs']['tagid'] ?? ''), $notePrt) ){
				$noteLst[$notePrt[1]][$notePrt[0]] = $blkArr;
				unset($blocksList[$blkIdx]);
			}
		}
		return $noteLst;
	}

	/**
	 * Reads a picture of the folder the writer was given. The picture is
	 * read for what it is, set upright, and written anew: nothing it
	 * carried comes with it. A picture the server cannot write anew is
	 * given as it is when its kind is one a document holds.
	 *
	 * @param String $sourceName The name of its file, in the folder: never an address, never a file of another folder.
	 * @return ?Array Its bytes under 'bytes', its kind under 'kinds' (png, jpg or gif), its width and its height in pixels under 'width' and 'heght'; null when it is not a picture of the folder.
	 */
	protected function pictrRead(String $sourceName): ?Array {
		if( $this->mediaDir==='' || $sourceName==='' || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $sourceName) || strpos($sourceName, "\0")!==false ){
			return null;
		}
		$filePth = realpath($this->mediaDir.DIRECTORY_SEPARATOR.$sourceName);
		if( $filePth===false || !is_file($filePth) || strpos($filePth, rtrim($this->mediaDir, '/\\').DIRECTORY_SEPARATOR)!==0 ){
			return null;
		}
		try{
			$pictObj = Image::openFluxd(Fluxd::openLocal($filePth, $this->mediaDir));
			$kindKey = strval($pictObj->imageInfo()['kinds']);
			$giveKnd = isset(self::PICTLIST[$kindKey]) ? $kindKey : 'png';
			try{
				$pictObj = $pictObj->setUprght();
				$pictTxt = $pictObj->giveBytes($giveKnd);
			}catch( \RuntimeException $e ){
				// Without what writes pictures anew, one of a kind a document holds is given as it is
				if( !isset(self::PICTLIST[$kindKey]) ){
					return null;
				}
				$pictTxt = strval(file_get_contents($filePth));
			}
			$infoSet = $pictObj->imageInfo();
		}catch( \RuntimeException $e ){
			return null;
		}
		return Array('bytes'=>$pictTxt, 'kinds'=>$giveKnd, 'width'=>max(1, intval($infoSet['width'])), 'heght'=>max(1, intval($infoSet['heght'])));
	}

	/**
	 * Gives sheets as text blocks: each sheet that is shown is a table,
	 * under a heading bearing its name. The names of its columns make the
	 * first row of the table, repeated at the top of each page.
	 *
	 * @param Array $sheetsList The sheets.
	 * @return Array The blocks.
	 */
	protected function sheetsOut(Array $sheetsList): Array {
		$blckLst = Array();
		foreach($sheetsList as $shtArr){
			if( !is_array($shtArr) || (isset($shtArr['attrs']['shown']) && empty($shtArr['attrs']['shown'])) ){
				continue;
			}
			$rowsLst = array_values((array)($shtArr['cells'] ?? Array()));
			$headLst = Array();
			foreach($rowsLst as $oneRow){
				foreach($this->isAListOf((array)$oneRow) ? Array() : array_keys((array)$oneRow) as $colKey){
					if( !in_array(strval($colKey), $headLst, true) ){
						$headLst[] = strval($colKey);
					}
				}
			}
			// The cells an area covers are not cells of their own; the one it starts at tells what it covers
			$spanLst = Array();
			$covrLst = Array();
			$headGap = empty($headLst) ? 0 : 1;
			foreach((array)($shtArr['attrs']['merge'] ?? Array()) as $mrgArr){
				$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
				if( $mrgArr['atrow']<$headGap || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || $mrgArr['rowsp']*$mrgArr['colsp']>10000 || isset($covrLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]) ){
					continue;
				}
				for($rowIdx=$mrgArr['atrow']; $rowIdx<$mrgArr['atrow']+$mrgArr['rowsp']; $rowIdx++){
					for($colIdx=$mrgArr['atcol']; $colIdx<$mrgArr['atcol']+$mrgArr['colsp']; $colIdx++){
						$covrLst[$rowIdx.':'.$colIdx] = true;
					}
				}
				unset($covrLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]);
				$spanLst[$mrgArr['atrow'].':'.$mrgArr['atcol']] = array_filter(Array('rowsp'=>$mrgArr['rowsp'], 'colsp'=>$mrgArr['colsp']), function(Int $spanNumber){ return $spanNumber>1; });
			}
			$tblRows = empty($headLst) ? Array() : Array(Shape::makeBlock('thead', Array(), array_map(function(String $columnName){ return Shape::makeBlock('tcell', Array(Shape::makeASpan($columnName))); }, $headLst)));
			$colsNbr = count($headLst);
			foreach($rowsLst as $oneRow){
				$colsNbr = max($colsNbr, count((array)$oneRow));
			}
			foreach($rowsLst as $rowIdx=>$oneRow){
				$cellLst = Array();
				$valsLst = empty($headLst) ? array_values((array)$oneRow) : array_map(function(String $columnName) use ($oneRow){ return ((array)$oneRow)[$columnName] ?? null; }, $headLst);
				for($colIdx=0; $colIdx<$colsNbr; $colIdx++){
					$placeKy = ($rowIdx+$headGap).':'.$colIdx;
					if( isset($covrLst[$placeKy]) ){
						continue;
					}
					$cellVal = $valsLst[$colIdx] ?? null;
					$cellTxt = '';
					if( is_bool($cellVal) ){
						$cellTxt = $cellVal ? 'TRUE' : 'FALSE';
					}elseif( is_string($cellVal) ){
						$cellTxt = $cellVal;
					}elseif( is_int($cellVal) || (is_float($cellVal) && is_finite($cellVal)) ){
						$cellTxt = $this->numbrText($cellVal);
					}elseif( is_array($cellVal) ){
						$cellTxt = strval(json_encode(Fluxd::dropFluxd($cellVal), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
					}
					// Numbers stand on the right of their column, as in a spreadsheet
					$cellLst[] = Shape::makeBlock('tcell', ($cellTxt==='') ? Array() : Array(Shape::makeASpan($cellTxt)), Array(), ($spanLst[$placeKy] ?? Array()) + ((is_int($cellVal) || is_float($cellVal)) ? Array('align'=>'right') : Array()));
				}
				$tblRows[] = Shape::makeBlock('tbrow', Array(), $cellLst);
			}
			$shtName = is_scalar($shtArr['title'] ?? '') ? trim(strval($shtArr['title'] ?? '')) : '';
			if( $shtName!=='' ){
				$blckLst[] = Shape::makeBlock('headr', Array(Shape::makeASpan($shtName)), Array(), Array('level'=>2));
			}
			if( !empty($tblRows) ){
				$blckLst[] = Shape::makeBlock('table', Array(), $tblRows);
			}
		}
		return $blckLst;
	}

	/**
	 * Gives a place of the document a name every document accepts: letters, digits
	 * and underscores, starting with a letter, 40 characters at most.
	 *
	 * @param String $placeLabel The name asked for.
	 * @return String The name, '' when nothing of it can be kept.
	 */
	protected function placeName(String $placeLabel): String {
		$nameTxt = trim(strval(preg_replace('/[^A-Za-z0-9_]+/', '_', $placeLabel)), '_');
		if( $nameTxt==='' ){
			return '';
		}
		return substr(preg_match('/^[A-Za-z]/', $nameTxt) ? $nameTxt : 'p_'.$nameTxt, 0, 40);
	}

}
