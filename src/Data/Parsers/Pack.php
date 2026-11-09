<?php

namespace nyu\Data\Parsers;

use nyu\Data\Archv;
use nyu\Data\Fluxd;
use nyu\Data\Image;
use nyu\Data\Shape;

/**
 * What the parsers of zipped documents share: opening the archive,
 * reading its XML parts into trees, and finding their way in those trees.
 */
abstract class Pack extends Base {

	// Shape of what the parser gives: 'sheet' or 'block'
	const SHPE_OUTS = 'block';
	// Greatest number of cells filled with the value of the merged area they belong to
	const FILL_MAXI = 1000000;
	// Greatest number of pictures taken out of a document
	const MEDI_MAXI = 1000;

	// The archive being read, and the greatest size accepted for one of its entries
	protected $opnArchive;
	protected $maxSizeVal = self::DFLT_SIZE;

	/**
	 * Tells the kinds of content the parser reads: zip archives.
	 *
	 * @return Array The kinds.
	 */
	public function signsReadArray(): Array {
		return Bina::PACK_LIST;
	}
	
	/**
	 * Reads the document held by an opened archive.
	 *
	 * @param Array $parseOptions Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the archive is not a valid document of the kind.
	 */
	abstract protected function readArchvArray(Array $parseOptions): Array;

	/**
	 * Parses a zipped document given as bytes. An empty content gives an
	 * empty result.
	 *
	 * @param String $nakedContent The bytes of the document.
	 * @param Array  $parseOptions Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is not a valid document of the kind.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		if( $nakedContent==='' ){
			return $this->makeShapeArray(static::SHPE_OUTS, Array());
		}
		$bytFlux = Fluxd::openBytesFluxd($nakedContent);
		try{
			return $this->parseFluxArray($bytFlux, $parseOptions);
		}finally{
			$bytFlux->closeTheSource();
		}
	}

	/**
	 * Parses a zipped document from a source.
	 *
	 * Options, besides those of each parser:
	 *   'limit' => the greatest size accepted for each entry of the
	 *              archive once unpacked, in bytes;
	 *   'media' => a folder of the server: the pictures the document
	 *              holds are saved there, and each image block then
	 *              gives the name of its file in the stead of its place
	 *              in the archive. What was saved is listed in the
	 *              details, under 'media'.
	 *
	 * @param Fluxd $sourceStream The source.
	 * @param Array $parseOptions Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the source is not a zip archive, not a valid document of the kind, or the folder for its pictures cannot be written to.
	 */
	public function parseFluxArray(Fluxd $sourceStream, Array $parseOptions=Array()): Array {
		$this->maxSizeVal = intval($parseOptions['limit'] ?? self::DFLT_SIZE);
		try{
			$this->opnArchive = Archv::openFluxdArchv($sourceStream);
			$envlope = $this->readArchvArray($parseOptions);
			// The pictures are taken out while the archive is still open
			return (strval($parseOptions['media'] ?? '')!=='' && $envlope['shape']=='block') ? $this->saveMediaArray($envlope, strval($parseOptions['media'])) : $envlope;
		}catch( ParseError $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			// An Office document protected by a password is no longer a zip archive, but a container of the older Office formats
			if( $e->getMessage()=='Not a zip archive' && Bina::detectFileKind($sourceStream->getRangeString(0, 511))=='ole' ){
				throw new ParseError('Invalid archive: the document is protected by a password, or saved in an older Office format', 0, $e);
			}
			throw new ParseError('Invalid archive: '.$e->getMessage(), 0, $e);
		}finally{
			if( $this->opnArchive!==null ){
				$this->opnArchive->releaseArchive();
			}
			$this->opnArchive = null;
		}
	}

	/**
	 * Reads an XML part of the archive into its tree of elements.
	 *
	 * @param String $fullPathName Name of the entry, with its folders.
	 * @param Bool   $partRequired Whether the document cannot do without it.
	 * @param Bool   $keepsSpacing Whether the spacing written between two elements is kept, as the parts holding running text need.
	 * @return ?Array The root element, as XHml gives it; null when the part is missing and not required.
	 *
	 * @throws ParseError When the part is required and missing, or is not valid XML.
	 */
	protected function partNodesArray(String $fullPathName, Bool $partRequired=true, Bool $keepsSpacing=false): ?Array {
		if( !$this->opnArchive->locateEntryFlg($fullPathName) ){
			if( $partRequired ){
				throw new ParseError('Invalid archive: part '.$fullPathName.' is missing');
			}
			return null;
		}
		try{
			$partDoc = (new XHml())->parseDataArray($this->opnArchive->getEntryString($fullPathName, $this->maxSizeVal), Array('reads'=>'xml', 'shape'=>'nodes', 'space'=>$keepsSpacing));
		}catch( ParseError $e ){
			throw new ParseError('Invalid archive: part '.$fullPathName.': '.$e->getMessage(), 0, $e);
		}
		return empty($partDoc['items']) ? null : $partDoc['items'];
	}

	/**
	 * Gives the child elements of an element that carry a name.
	 *
	 * @param Array  $elementArray The element.
	 * @param String $tagNmeString Name looked for. Given with its prefix, it is matched whole; given without, it matches whatever prefix the document uses.
	 * @return Array The children of that name, in order.
	 */
	protected function kidsNamedArray(Array $elementArray, String $tagNmeString): Array {
		$kidsLst = Array();
		$anyPrfx = (strpos($tagNmeString, ':')===false);
		foreach($elementArray['nodes'] as $chlArr){
			$chldNme = $chlArr['tagnm'];
			if( $chldNme===$tagNmeString || ($anyPrfx && substr($chldNme, intval(strrpos($chldNme, ':'))+1)===$tagNmeString && strpos($chldNme, ':')!==false) ){
				$kidsLst[] = $chlArr;
			}
		}
		return $kidsLst;
	}

	/**
	 * Follows a path of names down an element, taking the first child of
	 * each name.
	 *
	 * @param Array $elementArray The element.
	 * @param Array $tagPathSteps Names to follow, one per level.
	 * @return ?Array The element reached, null when the path breaks.
	 */
	protected function findChildArray(Array $elementArray, Array $tagPathSteps): ?Array {
		foreach($tagPathSteps as $tagNme){
			$kidsLst = $this->kidsNamedArray($elementArray, $tagNme);
			if( empty($kidsLst) ){
				return null;
			}
			$elementArray = $kidsLst[0];
		}
		return $elementArray;
	}

	/**
	 * Gives the text an element holds, at any depth.
	 *
	 * @param Array $elementArray The element.
	 * @return String Its text, with the text of what it holds, in order.
	 */
	protected function collectAllText(Array $elementArray): String {
		$fullTxt = $elementArray['value'];
		foreach($elementArray['nodes'] as $chlArr){
			$fullTxt .= $this->collectAllText($chlArr);
		}
		return $fullTxt;
	}
	
	/**
	 * Chooses the sheets of a workbook to read: the one the 'sheet'
	 * option asks for, by its name first and by its position otherwise,
	 * or all of them, the hidden ones left out with the 'shown' option.
	 *
	 * @param Array $sheetNameLst The names of the sheets, in order.
	 * @param Array $visibleFlags Whether each of them is shown.
	 * @param Array $parseOptions Options of the parser.
	 * @return Array The positions of the sheets to read, counted from 0.
	 *
	 * @throws ParseError When the sheet asked for is not in the workbook.
	 */
	protected function pickSheetArray(Array $sheetNameLst, Array $visibleFlags, Array $parseOptions): Array {
		if( isset($parseOptions['sheet']) && $parseOptions['sheet']!=='' ){
			$askdSht = strval($parseOptions['sheet']);
			$foundAt = array_search($askdSht, $sheetNameLst, true);
			if( $foundAt===false && preg_match('/^[1-9]\d*$/D', $askdSht) && isset($sheetNameLst[intval($askdSht)-1]) ){
				$foundAt = intval($askdSht)-1;
			}
			if( $foundAt===false ){
				throw new ParseError('Invalid workbook option: no sheet '.$askdSht);
			}
			return Array($foundAt);
		}
		$keptIdx = Array();
		foreach(array_keys($sheetNameLst) as $shtIdx){
			if( empty($parseOptions['shown']) || !empty($visibleFlags[$shtIdx]) ){
				$keptIdx[] = $shtIdx;
			}
		}
		return $keptIdx;
	}
	
	/**
	 * Finishes a sheet once its rows are read: the 'merge' option fills
	 * its merged cells, the 'heads' option keys its rows by the names of
	 * its columns.
	 *
	 * @param String $sheetHeading Name of the sheet.
	 * @param Array  $cellRowsList Its rows, each a list of values.
	 * @param Array  $attributeSet What is particular to it: 'shown', 'merge'.
	 * @param Array  $parseOptions Options of the parser.
	 * @param Array  $columnTitles Receives the names of its columns, with the 'heads' option.
	 * @return Array The sheet: 'title', 'attrs', 'cells'.
	 *
	 * @throws ParseError When the 'merge' option is not a known one, or the merged cells are too many to be filled.
	 */
	protected function doneSheetArray(String $sheetHeading, Array $cellRowsList, Array $attributeSet, Array $parseOptions, Array &$columnTitles): Array {
		$mergeAs = strtolower(strval($parseOptions['merge'] ?? 'keeps'));
		if( $mergeAs!='keeps' && $mergeAs!='fills' ){
			throw new ParseError('Invalid workbook option: unknown merge '.$mergeAs);
		}
		if( $mergeAs=='fills' ){
			$cellRowsList = $this->fillMergeArray($cellRowsList, (array)($attributeSet['merge'] ?? Array()));
		}
		$columnTitles = Array();
		if( !empty($parseOptions['heads']) ){
			$cellRowsList = $this->keyedRowsArray($cellRowsList, $columnTitles);
		}
		$shetArr = Shape::makeSheetArray($sheetHeading, Array(), $attributeSet);
		$shetArr['cells'] = $cellRowsList;
		return $shetArr;
	}
	
	/**
	 * Copies the value of each merged area into every cell it covers. An
	 * area whose first cell is empty is left as it is.
	 *
	 * @param Array $cellRowsList The rows, each a list of values.
	 * @param Array $mergedRanges The areas, each with its 'atrow', 'atcol', 'rowsp' and 'colsp'.
	 * @return Array The rows, filled.
	 *
	 * @throws ParseError When the cells to fill are too many.
	 */
	protected function fillMergeArray(Array $cellRowsList, Array $mergedRanges): Array {
		$fillNum = 0;
		foreach($mergedRanges as $oneAre){
			$areaVal = $cellRowsList[$oneAre['atrow']][$oneAre['atcol']] ?? null;
			if( $areaVal===null ){
				continue;
			}
			$fillNum += $oneAre['rowsp']*$oneAre['colsp'];
			if( $fillNum>self::FILL_MAXI ){
				throw new ParseError('Invalid workbook: merged cells too many to be filled');
			}
			for($rowIdx=$oneAre['atrow']; $rowIdx<$oneAre['atrow']+$oneAre['rowsp']; $rowIdx++){
				while( count($cellRowsList)<=$rowIdx ){
					$cellRowsList[] = Array();
				}
				$cellRowsList[$rowIdx] = array_pad($cellRowsList[$rowIdx], $oneAre['atcol']+$oneAre['colsp'], null);
				for($colInc=$oneAre['atcol']; $colInc<$oneAre['atcol']+$oneAre['colsp']; $colInc++){
					$cellRowsList[$rowIdx][$colInc] = $areaVal;
				}
			}
		}
		return $cellRowsList;
	}
	
	/**
	 * Refuses an OpenDocument file protected by a password: its archive
	 * opens, but its parts are encrypted.
	 *
	 * @throws ParseError When the document is protected by a password.
	 */
	protected function refuseIfLocked(): Void {
		if( $this->opnArchive->locateEntryFlg('META-INF/manifest.xml') && strpos($this->opnArchive->getEntryString('META-INF/manifest.xml', $this->maxSizeVal), 'encryption-data')!==false ){
			throw new ParseError('Invalid archive: the document is protected by a password');
		}
	}
	
	/**
	 * Gives the text of an OpenDocument paragraph, or of a piece of one:
	 * its spaces, tabulations and line breaks as their characters, and
	 * without the notes attached to it.
	 *
	 * @param Array $elementArray The element.
	 * @return String Its text.
	 */
	protected function odfPieceAsText(Array $elementArray): String {
		$fullTxt = $elementArray['value'];
		foreach($elementArray['nodes'] as $chlArr){
			$chldNme = $chlArr['tagnm'];
			if( $chldNme=='text:s' ){
				$fullTxt .= str_repeat(' ', max(1, min(10000, intval($chlArr['attrs']['text:c'] ?? 1))));
			}elseif( $chldNme=='text:tab' ){
				$fullTxt .= "\t";
			}elseif( $chldNme=='text:line-break' ){
				$fullTxt .= "\n";
			}elseif( !in_array($chldNme, Array('office:annotation', 'office:annotation-end', 'text:note', 'text:tracked-changes')) ){
				$fullTxt .= $this->odfPieceAsText($chlArr);
			}
		}
		return $fullTxt;
	}
	
	/**
	 * Reads the relations of a part of an Office document: the other
	 * parts, and the addresses, it points to.
	 *
	 * @param String $fullPathName Name of the part, '' for the archive itself.
	 * @return Array The relations, by their id, each with its kind under 'types', the name of the part it points to under 'paths', what it points to as written under 'links', and whether that is outside the archive under 'outer'.
	 */
	protected function relsOfFragment(String $fullPathName): Array {
		$slashAt = strrpos($fullPathName, '/');
		$partDir = ($slashAt===false) ? '' : substr($fullPathName, 0, $slashAt+1);
		$relsArr = $this->partNodesArray($partDir.'_rels/'.substr($fullPathName, strlen($partDir)).'.rels', false);
		$relsLst = Array();
		foreach($this->kidsNamedArray($relsArr ?? Array('nodes'=>Array()), 'Relationship') as $relArr){
			$relTrgt = strval($relArr['attrs']['Target'] ?? '');
			// A target is written from the folder of the part, or from the root of the archive when it starts with /
			$relPath = (substr($relTrgt, 0, 1)=='/') ? substr($relTrgt, 1) : $partDir.$relTrgt;
			$pthPart = Array();
			foreach(explode('/', $relPath) as $oneDir){
				if( $oneDir=='..' ){
					array_pop($pthPart);
				}elseif( $oneDir!=='' && $oneDir!='.' ){
					$pthPart[] = $oneDir;
				}
			}
			$relsLst[strval($relArr['attrs']['Id'] ?? '')] = Array('types'=>strval($relArr['attrs']['Type'] ?? ''), 'paths'=>implode('/', $pthPart), 'links'=>$relTrgt, 'outer'=>(strtolower(strval($relArr['attrs']['TargetMode'] ?? ''))=='external'));
		}
		return $relsLst;
	}
	
	/**
	 * Finds the first element carrying one of some names inside an
	 * element, at any depth.
	 *
	 * @param Array $elementArray The element.
	 * @param Array $namesToMatch The names looked for, with their prefix.
	 * @return ?Array The element found, null when there is none.
	 */
	protected function deepNamedArray(Array $elementArray, Array $namesToMatch): ?Array {
		foreach($elementArray['nodes'] as $chlArr){
			if( in_array($chlArr['tagnm'], $namesToMatch, true) ){
				return $chlArr;
			}
			$deepArr = $this->deepNamedArray($chlArr, $namesToMatch);
			if( $deepArr!==null ){
				return $deepArr;
			}
		}
		return null;
	}
	
	/**
	 * Sorts the pieces of a paragraph: a run of text makes a paragraph,
	 * an image or a page break stands between two of them.
	 *
	 * @param Array $outputPieces The pieces, in order: spans, and images and page breaks as blocks.
	 * @param Bool  $keepEmptyFlg Whether a paragraph holding nothing is given as a block without text.
	 * @return Array The whole text as spans under 'spans' and as written under 'plain', the images and the page breaks under 'holds', and all of it in order, as blocks, under 'flows'.
	 */
	protected function flowPartsArray(Array $outputPieces, Bool $keepEmptyFlg): Array {
		$spnsAll = Array();
		$holdLst = Array();
		$flowLst = Array();
		$spanBuf = Array();
		$outputPieces[] = Array('block'=>'#end');
		foreach($outputPieces as $onePic){
			if( !isset($onePic['block']) ){
				$spanBuf[] = $onePic;
				$spnsAll[] = $onePic;
				continue;
			}
			$tidySpn = Shape::joinSpansArray($spanBuf);
			$spanBuf = Array();
			if( trim(implode('', array_column($tidySpn, 'texts')))!=='' ){
				$flowLst[] = Shape::makeBlockArray('parag', $tidySpn);
			}
			if( $onePic['block']!='#end' ){
				$flowLst[] = $onePic;
				$holdLst[] = $onePic;
			}
		}
		$spnsAll = Shape::joinSpansArray($spnsAll);
		$fullTxt = implode('', array_column($spnsAll, 'texts'));
		if( trim($fullTxt)==='' ){
			$spnsAll = Array();
		}
		// A paragraph holding nothing is a block without text, when those are kept
		if( empty($flowLst) && $keepEmptyFlg ){
			$flowLst[] = Shape::makeBlockArray('parag');
		}
		return Array('spans'=>$spnsAll, 'plain'=>$fullTxt, 'holds'=>$holdLst, 'flows'=>$flowLst);
	}
	
	/**
	 * Gives the blocks of a paragraph once it is read: a heading, a
	 * quote, or its text with the images it holds, and a page break
	 * before or after it when it asks for one.
	 *
	 * @param Array $prgrphValues The paragraph: its 'kinds' (headr, quote or parag), 'level', 'attrs', 'spans', 'holds', 'flows', and whether a page break stands before and after it under 'prior' and 'after'.
	 * @return Array The blocks.
	 */
	protected function paragrphBlocks(Array $prgrphValues): Array {
		$paraKnd = $prgrphValues['kinds'];
		$newPara = $prgrphValues['flows'];
		if( $paraKnd=='headr' || $paraKnd=='quote' ){
			// A heading and a quote hold their text only; what else their paragraph holds follows them
			$newPara = array_merge(
				Array(Shape::makeBlockArray($paraKnd, $prgrphValues['spans'], Array(), ($paraKnd=='headr') ? Array('level'=>$prgrphValues['level']) : Array())),
				($paraKnd=='headr') ? array_values(array_filter($prgrphValues['holds'], function($contentBlock){ return $contentBlock['block']=='rules'; })) : $prgrphValues['holds']
			);
		}
		// The style, the alignment and the id are those of the text, not of an image or a page break
		if( !empty($newPara) && !in_array($newPara[0]['block'], Array('rules', 'image')) ){
			$newPara[0]['attrs'] = $newPara[0]['attrs'] + $prgrphValues['attrs'];
		}
		$brkBlck = Shape::makeBlockArray('rules', Array(), Array(), Array('break'=>true));
		return array_merge($prgrphValues['prior'] ? Array($brkBlck) : Array(), $newPara, $prgrphValues['after'] ? Array($brkBlck) : Array());
	}
	
	/**
	 * Makes one block of code of the paragraphs of code that follow one
	 * another, each being one of its lines.
	 *
	 * @param Array $prgrphsArray The paragraphs, each with its text as written under 'plain' and what is particular to it under 'attrs'.
	 * @return Array The block, in a list; an empty list when the lines hold nothing.
	 */
	protected function codeBlockArray(Array $prgrphsArray): Array {
		$codeTxt = trim(implode("\n", array_column($prgrphsArray, 'plain')), "\n");
		if( trim($codeTxt)==='' ){
			return Array();
		}
		return Array(Shape::makeBlockArray('codes', Array(Shape::makeASpanArray($codeTxt)), Array(), $prgrphsArray[0]['attrs'] ?? Array()));
	}
	
	/**
	 * Gives what a style gives, as the details of a document list it.
	 *
	 * @param Array $styleDetails The style: its name under 'names', then 'fonts', 'sizes', 'color', 'marks' and 'align'.
	 * @return Array A name fit for a style sheet under 'class', then what the style tells among 'fonts', 'sizes', 'color', 'marks' and 'align'.
	 */
	protected function frmatOfItArray(Array $styleDetails): Array {
		$clssNme = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strval($styleDetails['names'] ?? ''))), '-');
		$frmtSet = Array('class'=>preg_match('/^[a-z]/', $clssNme) ? $clssNme : 's-'.$clssNme);
		foreach(Array('fonts', 'sizes', 'color', 'marks', 'align') as $prpKey){
			if( !empty($styleDetails[$prpKey]) ){
				$frmtSet[$prpKey] = $styleDetails[$prpKey];
			}
		}
		// A size is a whole number of points more often than not
		if( isset($frmtSet['sizes']) && floor($frmtSet['sizes'])==$frmtSet['sizes'] ){
			$frmtSet['sizes'] = intval($frmtSet['sizes']);
		}
		return $frmtSet;
	}
	
	/**
	 * Saves the pictures a document holds to a folder, and gives each
	 * image block the name of its file.
	 *
	 * @param Array  $envelopeData The envelope of the document, of shape 'block'.
	 * @param String $imagesFolder The folder.
	 * @return Array The envelope, its image blocks pointing to the files saved, and those files listed in its details under 'media': the name of each file => its place in the archive.
	 *
	 * @throws ParseError When the folder is not one that can be written to.
	 */
	protected function saveMediaArray(Array $envelopeData, String $imagesFolder): Array {
		$realDir = realpath($imagesFolder);
		if( $realDir===false || !is_dir($realDir) || !is_writable($realDir) ){
			throw new ParseError('Invalid option: the folder for the pictures cannot be written to');
		}
		$saveLst = Array();
		$envelopeData['items'] = $this->mediaWalkArray((array)$envelopeData['items'], $realDir, $saveLst);
		// The headers and the footers of the pages hold pictures too
		foreach(Array('phead', 'pfoot') as $infKey){
			if( is_array($envelopeData['infos'][$infKey] ?? null) ){
				$envelopeData['infos'][$infKey] = $this->mediaWalkArray($envelopeData['infos'][$infKey], $realDir, $saveLst);
			}
		}
		$envelopeData['infos']['media'] = array_flip(array_filter($saveLst, 'strlen'));
		return $envelopeData;
	}
	
	/**
	 * Saves the pictures of some blocks, at any depth. A picture is
	 * saved once, under a name made from its content, whatever number of
	 * blocks show it; it is written anew when this server can, standing
	 * the right way up and without what a camera recorded in it, and
	 * copied as it is otherwise. What is not a picture this server reads
	 * is left where it is.
	 *
	 * @param Array  $bookBlockSet The blocks.
	 * @param String $imagesFolder The folder, as the system names it.
	 * @param Array  $picturesSeen The pictures met so far: their place in the archive => the name of their file, '' for what could not be saved.
	 * @return Array The blocks.
	 */
	protected function mediaWalkArray(Array $bookBlockSet, String $imagesFolder, Array &$picturesSeen): Array {
		foreach($bookBlockSet as $blkIdx=>$oneBlk){
			if( !empty($oneBlk['items']) ){
				$bookBlockSet[$blkIdx]['items'] = $this->mediaWalkArray($oneBlk['items'], $imagesFolder, $picturesSeen);
			}
			$innrPth = strval($oneBlk['attrs']['sourc'] ?? '');
			if( ($oneBlk['block'] ?? '')!='image' || !$this->opnArchive->locateEntryFlg($innrPth) ){
				continue;
			}
			if( !isset($picturesSeen[$innrPth]) && count($picturesSeen)<self::MEDI_MAXI ){
				$picturesSeen[$innrPth] = '';
				try{
					$rawText = $this->opnArchive->getEntryString($innrPth, $this->maxSizeVal);
					$picture = Image::openFluxdArchv(Fluxd::openBytesFluxd($rawText));
					// A drawing is saved cleaned of what runs; any other picture written anew, or copied when this server cannot
					if( $picture->imageInfoArray()['kinds']=='svg' ){
						$outText = $picture->dropRiskyParts();
					}else{
						$outText = function_exists('imagecreatefromstring') ? $picture->fixOrientation()->grantBytesText('', 90) : $rawText;
					}
					$fileNme = substr(sha1($outText), 0, 20).'.'.$picture->imageInfoArray()['kinds'];
					if( @file_put_contents($imagesFolder.DIRECTORY_SEPARATOR.$fileNme, $outText)!==false ){
						$picturesSeen[$innrPth] = $fileNme;
					}
				}catch( \RuntimeException $e ){
					// Not a picture this server reads, such as a drawing of an office suite: the block keeps its place in the archive
				}
			}
			if( strval($picturesSeen[$innrPth] ?? '')!=='' ){
				$bookBlockSet[$blkIdx]['attrs']['sourc'] = $picturesSeen[$innrPth];
			}
		}
		return $bookBlockSet;
	}
	
}
