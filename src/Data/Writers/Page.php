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
	protected function paperSizeArray(Array $writeOptions): Array {
		$sizeKey = strval($writeOptions['paper'] ?? 'isoPgeA4');
		// The size whatever its case, as SIZE_LIST writes it
		$sizeKey = strval( array_values(preg_grep('/^'.preg_quote($sizeKey, '/').'$/iD', array_keys(self::SIZE_LIST)))[0] ?? $sizeKey );
		if( !isset(self::SIZE_LIST[$sizeKey]) ){
			throw new WriteError('Invalid document option: unknown paper '.$sizeKey);
		}
		return empty($writeOptions['lands']) ? self::SIZE_LIST[$sizeKey] : array_reverse(self::SIZE_LIST[$sizeKey]);
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
			$realDir = realpath(strval($writeOptions['media']));
			if( $realDir===false || !is_dir($realDir) || !is_readable($realDir) ){
				throw new WriteError('Invalid document option: the folder of the pictures cannot be read');
			}
			$this->pictureDir = $realDir;
		}
	}

	/**
	 * Takes the notes out of the blocks of a document: each is written
	 * where the text refers to it, not where it stands among the blocks.
	 *
	 * @param Array $bookBlockSet The blocks; the notes are taken out of them.
	 * @return Array The notes of the pages under 'fnote' and those of the end under 'enote', each list by the name of the note: its block.
	 */
	protected function takeNotesArray(Array &$bookBlockSet): Array {
		$noteLst = Array('fnote'=>Array(), 'enote'=>Array());
		$matches = Array();
		foreach($bookBlockSet as $blkIdx=>$blkArr){
			if( is_array($blkArr) && strval($blkArr['block'] ?? '')=='footnote' && preg_match('/^(fnote|enote)(\d+)$/D', strval($blkArr['attrs']['tagid'] ?? ''), $matches) ){
				$noteLst[$matches[1]][$matches[0]] = $blkArr;
				unset($bookBlockSet[$blkIdx]);
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
	 * @param String $pageFileName The name of its file, in the folder: never an address, never a file of another folder.
	 * @return ?Array Its bytes under 'bytes', its kind under 'kinds' (png, jpg or gif), its width and its height in pixels under 'width' and 'heght'; null when it is not a picture of the folder.
	 */
	protected function pictureReadArr(String $pageFileName): ?Array {
		if( $this->pictureDir==='' || $pageFileName==='' || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $pageFileName) || strpos($pageFileName, "\0")!==false ){
			return null;
		}
		$filPath = realpath($this->pictureDir.DIRECTORY_SEPARATOR.$pageFileName);
		if( $filPath===false || !is_file($filPath) || strpos($filPath, rtrim($this->pictureDir, '/\\').DIRECTORY_SEPARATOR)!==0 ){
			return null;
		}
		try{
			$pctrObj = Image::openFluxdArchv(Fluxd::openLocalFluxd($filPath, $this->pictureDir));
			$kindKey = strval($pctrObj->imageInfoArray()['kinds']);
			$givKind = isset(self::PICT_LIST[$kindKey]) ? $kindKey : 'png';
			try{
				$pctrObj = $pctrObj->fixOrientation();
				$pctrTxt = $pctrObj->grantBytesText($givKind);
			}catch( \RuntimeException $e ){
				// Without what writes pictures anew, one of a kind a document holds is given as it is
				if( !isset(self::PICT_LIST[$kindKey]) ){
					return null;
				}
				$pctrTxt = strval(file_get_contents($filPath));
			}
			$infoSet = $pctrObj->imageInfoArray();
		}catch( \RuntimeException $e ){
			return null;
		}
		return Array('bytes'=>$pctrTxt, 'kinds'=>$givKind, 'width'=>max(1, intval($infoSet['width'])), 'heght'=>max(1, intval($infoSet['heght'])));
	}

	/**
	 * Gives sheets as text blocks: each sheet that is shown is a table,
	 * under a heading bearing its name. The names of its columns make the
	 * first row of the table, repeated at the top of each page.
	 *
	 * @param Array $sheetEntries The sheets.
	 * @return Array The blocks.
	 */
	protected function writeAllSheets(Array $sheetEntries): Array {
		$blckLst = Array();
		foreach($sheetEntries as $shtArr){
			if( !is_array($shtArr) || (isset($shtArr['attrs']['shown']) && empty($shtArr['attrs']['shown'])) ){
				continue;
			}
			$rowsLst = array_values((array)($shtArr['cells'] ?? Array()));
			$headLst = Array();
			foreach($rowsLst as $oneRow){
				foreach($this->validateALstOf((array)$oneRow) ? Array() : array_keys((array)$oneRow) as $column){
					if( !in_array(strval($column), $headLst, true) ){
						$headLst[] = strval($column);
					}
				}
			}
			// The cells an area covers are not cells of their own; the one it starts at tells what it covers
			$spanLst = Array();
			$cvrList = Array();
			$headGap = empty($headLst) ? 0 : 1;
			foreach((array)($shtArr['attrs']['merge'] ?? Array()) as $mrgArr){
				$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
				if( $mrgArr['atrow']<$headGap || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || $mrgArr['rowsp']*$mrgArr['colsp']>10000 || isset($cvrList[$mrgArr['atrow'].':'.$mrgArr['atcol']]) ){
					continue;
				}
				for($rowIdx=$mrgArr['atrow']; $rowIdx<$mrgArr['atrow']+$mrgArr['rowsp']; $rowIdx++){
					for($colInc=$mrgArr['atcol']; $colInc<$mrgArr['atcol']+$mrgArr['colsp']; $colInc++){
						$cvrList[$rowIdx.':'.$colInc] = true;
					}
				}
				unset($cvrList[$mrgArr['atrow'].':'.$mrgArr['atcol']]);
				$spanLst[$mrgArr['atrow'].':'.$mrgArr['atcol']] = array_filter(Array('rowsp'=>$mrgArr['rowsp'], 'colsp'=>$mrgArr['colsp']), function(Int $colSpanCount){ return $colSpanCount>1; });
			}
			$tblRows = empty($headLst) ? Array() : Array(Shape::makeBlockArray('tableTop', Array(), array_map(function(String $columnHeader){ return Shape::makeBlockArray('cellText', Array(Shape::makeASpanArray($columnHeader))); }, $headLst)));
			$colsNum = count($headLst);
			foreach($rowsLst as $oneRow){
				$colsNum = max($colsNum, count((array)$oneRow));
			}
			foreach($rowsLst as $rowIdx=>$oneRow){
				$cellLst = Array();
				$vlsList = empty($headLst) ? array_values((array)$oneRow) : array_map(function(String $columnHeader) use ($oneRow){ return ((array)$oneRow)[$columnHeader] ?? null; }, $headLst);
				for($colInc=0; $colInc<$colsNum; $colInc++){
					$cellKey = ($rowIdx+$headGap).':'.$colInc;
					if( isset($cvrList[$cellKey]) ){
						continue;
					}
					$cellVal = $vlsList[$colInc] ?? null;
					$cellTxt = '';
					if( is_bool($cellVal) ){
						$cellTxt = $cellVal ? 'TRUE' : 'FALSE';
					}elseif( is_string($cellVal) ){
						$cellTxt = $cellVal;
					}elseif( is_int($cellVal) || (is_float($cellVal) && is_finite($cellVal)) ){
						$cellTxt = $this->writeNumberTxt($cellVal);
					}elseif( is_array($cellVal) ){
						$cellTxt = strval(json_encode(Fluxd::nullifySources($cellVal), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
					}
					// Numbers stand on the right of their column, as in a spreadsheet
					$cellLst[] = Shape::makeBlockArray('cellText', ($cellTxt==='') ? Array() : Array(Shape::makeASpanArray($cellTxt)), Array(), ($spanLst[$cellKey] ?? Array()) + ((is_int($cellVal) || is_float($cellVal)) ? Array('align'=>'alignRgt') : Array()));
				}
				$tblRows[] = Shape::makeBlockArray('tableRow', Array(), $cellLst);
			}
			$shtName = is_scalar($shtArr['title'] ?? '') ? trim(strval($shtArr['title'] ?? '')) : '';
			if( $shtName!=='' ){
				$blckLst[] = Shape::makeBlockArray('headline', Array(Shape::makeASpanArray($shtName)), Array(), Array('level'=>2));
			}
			if( !empty($tblRows) ){
				$blckLst[] = Shape::makeBlockArray('tableSet', Array(), $tblRows);
			}
		}
		return $blckLst;
	}

	/**
	 * Gives a place of the document a name every document accepts: letters, digits
	 * and underscores, starting with a letter, 40 characters at most.
	 *
	 * @param String $locationText The name asked for.
	 * @return String The name, '' when nothing of it can be kept.
	 */
	protected function cleanPlaceName(String $locationText): String {
		$nameTxt = trim(strval(preg_replace('/[^A-Za-z0-9_]+/', '_', $locationText)), '_');
		if( $nameTxt==='' ){
			return '';
		}
		return substr(preg_match('/^[A-Za-z]/', $nameTxt) ? $nameTxt : 'p_'.$nameTxt, 0, 40);
	}

}
