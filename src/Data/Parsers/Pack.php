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
	protected $archiveObj;
	protected $maxSizeVal = self::DFLT_SIZE;

	/**
	 * Tells the kinds of content the parser reads: zip archives.
	 *
	 * @return Array The kinds.
	 */
	public function listContentCod(): Array {
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
		$bytesFlx = Fluxd::openBytesFluxd($nakedContent);
		try{
			return $this->parseFluxArray($bytesFlx, $parseOptions);
		}finally{
			$bytesFlx->shutFluxSource();
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
			$this->archiveObj = Archv::openFluxdArchv($sourceStream);
			$envelope = $this->readArchvArray($parseOptions);
			// The pictures are taken out while the archive is still open
			return (strval($parseOptions['media'] ?? '')!=='' && $envelope['shape']=='block') ? $this->saveMediaArray($envelope, strval($parseOptions['media'])) : $envelope;
		}catch( ParseError $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			// An Office document protected by a password is no longer a zip archive, but a container of the older Office formats
			if( $e->getMessage()=='Not a zip archive' && Bina::findContentCod($sourceStream->getRangeString(0, 511))=='ole' ){
				throw new ParseError('Invalid archive: the document is protected by a password, or saved in an older Office format', 0, $e);
			}
			throw new ParseError('Invalid archive: '.$e->getMessage(), 0, $e);
		}finally{
			if( $this->archiveObj!==null ){
				$this->archiveObj->shutZipArchive();
			}
			$this->archiveObj = null;
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
	protected function readPartAsTree(String $fullPathName, Bool $partRequired=true, Bool $keepsSpacing=false): ?Array {
		if( !$this->archiveObj->isEntryInArchv($fullPathName) ){
			if( $partRequired ){
				throw new ParseError('Invalid archive: part '.$fullPathName.' is missing');
			}
			return null;
		}
		try{
			$partDocu = (new XHml())->parseDataArray($this->archiveObj->getEntryString($fullPathName, $this->maxSizeVal), Array('reads'=>'xml', 'shape'=>'nodes', 'space'=>$keepsSpacing));
		}catch( ParseError $e ){
			throw new ParseError('Invalid archive: part '.$fullPathName.': '.$e->getMessage(), 0, $e);
		}
		return empty($partDocu['items']) ? null : $partDocu['items'];
	}

	/**
	 * Gives the child elements of an element that carry a name.
	 *
	 * @param Array  $elementArray The element.
	 * @param String $tagNmeString Name looked for. Given with its prefix, it is matched whole; given without, it matches whatever prefix the document uses.
	 * @return Array The children of that name, in order.
	 */
	protected function listNamedNodes(Array $elementArray, String $tagNmeString): Array {
		$kidsList = Array();
		$hasPrefx = (strpos($tagNmeString, ':')!==false);
		foreach($elementArray['nodes'] as $oneChl){
			$childNme = $oneChl['tagnm'];
			if( $childNme===$tagNmeString || (!$hasPrefx && substr($childNme, intval(strrpos($childNme, ':'))+1)===$tagNmeString && strpos($childNme, ':')!==false) ){
				$kidsList[] = $oneChl;
			}
		}
		return $kidsList;
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
		foreach($tagPathSteps as $oneTag){
			$kidsList = $this->listNamedNodes($elementArray, $oneTag);
			if( empty($kidsList) ){
				return null;
			}
			$elementArray = $kidsList[0];
		}
		return $elementArray;
	}

	/**
	 * Gives the text an element holds, at any depth.
	 *
	 * @param Array $elementArray The element.
	 * @return String Its text, with the text of what it holds, in order.
	 */
	protected function readNestedText(Array $elementArray): String {
		$fullText = $elementArray['value'];
		foreach($elementArray['nodes'] as $oneChl){
			$fullText .= $this->readNestedText($oneChl);
		}
		return $fullText;
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
	protected function findSheetArray(Array $sheetNameLst, Array $visibleFlags, Array $parseOptions): Array {
		if( isset($parseOptions['sheet']) && $parseOptions['sheet']!=='' ){
			$askdTabs = strval($parseOptions['sheet']);
			$foundIdx = array_search($askdTabs, $sheetNameLst, true);
			if( $foundIdx===false && preg_match('/^[1-9]\d*$/D', $askdTabs) && isset($sheetNameLst[intval($askdTabs)-1]) ){
				$foundIdx = intval($askdTabs)-1;
			}
			if( $foundIdx===false ){
				throw new ParseError('Invalid workbook option: no sheet '.$askdTabs);
			}
			return Array($foundIdx);
		}
		$keptIndx = Array();
		foreach(array_keys($sheetNameLst) as $nthTbl){
			if( empty($parseOptions['shown']) || !empty($visibleFlags[$nthTbl]) ){
				$keptIndx[] = $nthTbl;
			}
		}
		return $keptIndx;
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
	protected function makeFinalSheet(String $sheetHeading, Array $cellRowsList, Array $attributeSet, Array $parseOptions, Array &$columnTitles): Array {
		$mergCode = strtolower(strval($parseOptions['merge'] ?? 'keeps'));
		if( $mergCode!='keeps' && $mergCode!='fills' ){
			throw new ParseError('Invalid workbook option: unknown merge '.$mergCode);
		}
		if( $mergCode=='fills' ){
			$cellRowsList = $this->fillMergeArray($cellRowsList, (array)($attributeSet['merge'] ?? Array()));
		}
		$columnTitles = Array();
		if( !empty($parseOptions['heads']) ){
			$cellRowsList = $this->castRowsToDict($cellRowsList, $columnTitles);
		}
		$sheetLst = Shape::makeSheetArray($sheetHeading, Array(), $attributeSet);
		$sheetLst['cells'] = $cellRowsList;
		return $sheetLst;
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
		$fillNumb = 0;
		foreach($mergedRanges as $oneRng){
			$areaVals = $cellRowsList[$oneRng['atrow']][$oneRng['atcol']] ?? null;
			if( $areaVals===null ){
				continue;
			}
			$fillNumb += $oneRng['rowsp']*$oneRng['colsp'];
			if( $fillNumb>self::FILL_MAXI ){
				throw new ParseError('Invalid workbook: merged cells too many to be filled');
			}
			for($nthRow=$oneRng['atrow']; $nthRow<$oneRng['atrow']+$oneRng['rowsp']; $nthRow++){
				while( count($cellRowsList)<=$nthRow ){
					$cellRowsList[] = Array();
				}
				$cellRowsList[$nthRow] = array_pad($cellRowsList[$nthRow], $oneRng['atcol']+$oneRng['colsp'], null);
				for($nthCol=$oneRng['atcol']; $nthCol<$oneRng['atcol']+$oneRng['colsp']; $nthCol++){
					$cellRowsList[$nthRow][$nthCol] = $areaVals;
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
	protected function denyLockedFile(): Void {
		if( $this->archiveObj->isEntryInArchv('META-INF/manifest.xml') && strpos($this->archiveObj->getEntryString('META-INF/manifest.xml', $this->maxSizeVal), 'encryption-data')!==false ){
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
	protected function getOdfPieceTxt(Array $elementArray): String {
		$fullText = $elementArray['value'];
		foreach($elementArray['nodes'] as $oneChl){
			$childNme = $oneChl['tagnm'];
			if( $childNme=='text:s' ){
				$fullText .= str_repeat(' ', max(1, min(10000, intval($oneChl['attrs']['text:c'] ?? 1))));
			}elseif( $childNme=='text:tab' ){
				$fullText .= "\t";
			}elseif( $childNme=='text:line-break' ){
				$fullText .= "\n";
			}elseif( !in_array($childNme, Array('office:annotation', 'office:annotation-end', 'text:note', 'text:tracked-changes')) ){
				$fullText .= $this->getOdfPieceTxt($oneChl);
			}
		}
		return $fullText;
	}
	
	/**
	 * Reads the relations of a part of an Office document: the other
	 * parts, and the addresses, it points to.
	 *
	 * @param String $fullPathName Name of the part, '' for the archive itself.
	 * @return Array The relations, by their id, each with its kind under 'types', the name of the part it points to under 'paths', what it points to as written under 'links', and whether that is outside the archive under 'outer'.
	 */
	protected function readRltnOfPart(String $fullPathName): Array {
		$slashIdx = strrpos($fullPathName, '/');
		$partPath = ($slashIdx===false) ? '' : substr($fullPathName, 0, $slashIdx+1);
		$rltnNods = $this->readPartAsTree($partPath.'_rels/'.substr($fullPathName, strlen($partPath)).'.rels', false);
		$rltnList = Array();
		foreach($this->listNamedNodes($rltnNods ?? Array('nodes'=>Array()), 'Relationship') as $oneRel){
			$rltnTrgt = strval($oneRel['attrs']['Target'] ?? '');
			// A target is written from the folder of the part, or from the root of the archive when it starts with /
			$rltnPath = (substr($rltnTrgt, 0, 1)=='/') ? substr($rltnTrgt, 1) : $partPath.$rltnTrgt;
			$pathPart = Array();
			foreach(explode('/', $rltnPath) as $onePth){
				if( $onePth=='..' ){
					array_pop($pathPart);
				}elseif( $onePth!=='' && $onePth!='.' ){
					$pathPart[] = $onePth;
				}
			}
			$rltnList[strval($oneRel['attrs']['Id'] ?? '')] = Array('types'=>strval($oneRel['attrs']['Type'] ?? ''), 'paths'=>implode('/', $pathPart), 'links'=>$rltnTrgt, 'outer'=>(strtolower(strval($oneRel['attrs']['TargetMode'] ?? ''))=='external'));
		}
		return $rltnList;
	}
	
	/**
	 * Finds the first element carrying one of some names inside an
	 * element, at any depth.
	 *
	 * @param Array $elementArray The element.
	 * @param Array $namesToMatch The names looked for, with their prefix.
	 * @return ?Array The element found, null when there is none.
	 */
	protected function findNestedNode(Array $elementArray, Array $namesToMatch): ?Array {
		foreach($elementArray['nodes'] as $oneChl){
			if( in_array($oneChl['tagnm'], $namesToMatch, true) ){
				return $oneChl;
			}
			$deepList = $this->findNestedNode($oneChl, $namesToMatch);
			if( $deepList!==null ){
				return $deepList;
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
	protected function splitFlowParts(Array $outputPieces, Bool $keepEmptyFlg): Array {
		$spansAll = Array();
		$holdList = Array();
		$flowList = Array();
		$spanBuff = Array();
		$outputPieces[] = Array('block'=>'#end');
		foreach($outputPieces as $onePic){
			if( !isset($onePic['block']) ){
				$spanBuff[] = $onePic;
				$spansAll[] = $onePic;
				continue;
			}
			$tidySpan = Shape::joinSpansArray($spanBuff);
			$spanBuff = Array();
			if( trim(implode('', array_column($tidySpan, 'texts')))!=='' ){
				$flowList[] = Shape::makeBlockArray('paragrph', $tidySpan);
			}
			if( $onePic['block']!='#end' ){
				$flowList[] = $onePic;
				$holdList[] = $onePic;
			}
		}
		$spansAll = Shape::joinSpansArray($spansAll);
		$fullText = implode('', array_column($spansAll, 'texts'));
		if( trim($fullText)==='' ){
			$spansAll = Array();
		}
		// A paragraph holding nothing is a block without text, when those are kept
		if( empty($flowList) && $keepEmptyFlg ){
			$flowList[] = Shape::makeBlockArray('paragrph');
		}
		return Array('spans'=>$spansAll, 'plain'=>$fullText, 'holds'=>$holdList, 'flows'=>$flowList);
	}
	
	/**
	 * Gives the blocks of a paragraph once it is read: a heading, a
	 * quote, or its text with the images it holds, and a page break
	 * before or after it when it asks for one.
	 *
	 * @param Array $paragraphVal The paragraph: its 'kinds' (headline, citation or paragrph), 'level', 'attrs', 'spans', 'holds', 'flows', and whether a page break stands before and after it under 'prior' and 'after'.
	 * @return Array The blocks.
	 */
	protected function getBlocksOfPrg(Array $paragraphVal): Array {
		$prgrCode = $paragraphVal['kinds'];
		$newPargr = $paragraphVal['flows'];
		if( $prgrCode=='headline' || $prgrCode=='citation' ){
			// A heading and a quote hold their text only; what else their paragraph holds follows them
			$newPargr = array_merge(
				Array(Shape::makeBlockArray($prgrCode, $paragraphVal['spans'], Array(), ($prgrCode=='headline') ? Array('level'=>$paragraphVal['level']) : Array())),
				($prgrCode=='headline') ? array_values(array_filter($paragraphVal['holds'], function($contentBlock){ return $contentBlock['block']=='ruleLine'; })) : $paragraphVal['holds']
			);
		}
		// The style, the alignment and the id are those of the text, not of an image or a page break
		if( !empty($newPargr) && !in_array($newPargr[0]['block'], Array('ruleLine', 'imageBox')) ){
			$newPargr[0]['attrs'] = $newPargr[0]['attrs'] + $paragraphVal['attrs'];
		}
		$breakBlc = Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true));
		return array_merge($paragraphVal['prior'] ? Array($breakBlc) : Array(), $newPargr, $paragraphVal['after'] ? Array($breakBlc) : Array());
	}
	
	/**
	 * Makes one block of code of the paragraphs of code that follow one
	 * another, each being one of its lines.
	 *
	 * @param Array $paragraphLst The paragraphs, each with its text as written under 'plain' and what is particular to it under 'attrs'.
	 * @return Array The block, in a list; an empty list when the lines hold nothing.
	 */
	protected function joinCodeAsBloc(Array $paragraphLst): Array {
		$codeText = trim(implode("\n", array_column($paragraphLst, 'plain')), "\n");
		if( trim($codeText)==='' ){
			return Array();
		}
		return Array(Shape::makeBlockArray('codeBlck', Array(Shape::makeSpanOfText($codeText)), Array(), $paragraphLst[0]['attrs'] ?? Array()));
	}
	
	/**
	 * Gives what a style gives, as the details of a document list it.
	 *
	 * @param Array $styleDetails The style: its name under 'names', then 'fonts', 'sizes', 'color', 'marks' and 'align'.
	 * @return Array A name fit for a style sheet under 'class', then what the style tells among 'fonts', 'sizes', 'color', 'marks' and 'align'.
	 */
	protected function getStyleFormat(Array $styleDetails): Array {
		$classNme = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strval($styleDetails['names'] ?? ''))), '-');
		$frmtDict = Array('class'=>preg_match('/^[a-z]/', $classNme) ? $classNme : 's-'.$classNme);
		foreach(Array('fonts', 'sizes', 'color', 'marks', 'align') as $oneKey){
			if( !empty($styleDetails[$oneKey]) ){
				$frmtDict[$oneKey] = $styleDetails[$oneKey];
			}
		}
		// A size is a whole number of points more often than not
		if( isset($frmtDict['sizes']) && floor($frmtDict['sizes'])==$frmtDict['sizes'] ){
			$frmtDict['sizes'] = intval($frmtDict['sizes']);
		}
		return $frmtDict;
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
		$realPath = realpath($imagesFolder);
		if( $realPath===false || !is_dir($realPath) || !is_writable($realPath) ){
			throw new ParseError('Invalid option: the folder for the pictures cannot be written to');
		}
		$saveList = Array();
		$envelopeData['items'] = $this->saveDeepImages((array)$envelopeData['items'], $realPath, $saveList);
		// The headers and the footers of the pages hold pictures too
		foreach(Array('phead', 'pfoot') as $oneKey){
			if( is_array($envelopeData['infos'][$oneKey] ?? null) ){
				$envelopeData['infos'][$oneKey] = $this->saveDeepImages($envelopeData['infos'][$oneKey], $realPath, $saveList);
			}
		}
		$envelopeData['infos']['media'] = array_flip(array_filter($saveList, 'strlen'));
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
	protected function saveDeepImages(Array $bookBlockSet, String $imagesFolder, Array &$picturesSeen): Array {
		foreach($bookBlockSet as $nthBlk=>$oneBlk){
			if( !empty($oneBlk['items']) ){
				$bookBlockSet[$nthBlk]['items'] = $this->saveDeepImages($oneBlk['items'], $imagesFolder, $picturesSeen);
			}
			$innerPth = strval($oneBlk['attrs']['sourc'] ?? '');
			if( ($oneBlk['block'] ?? '')!='imageBox' || !$this->archiveObj->isEntryInArchv($innerPth) ){
				continue;
			}
			if( !isset($picturesSeen[$innerPth]) && count($picturesSeen)<self::MEDI_MAXI ){
				$picturesSeen[$innerPth] = '';
				try{
					$rawStrng = $this->archiveObj->getEntryString($innerPth, $this->maxSizeVal);
					$pictInst = Image::openFluxdImage(Fluxd::openBytesFluxd($rawStrng));
					// A drawing is saved cleaned of what runs; any other picture written anew, or copied when this server cannot
					if( $pictInst->getInfoOfImage()['kinds']=='svg' ){
						$outStrng = $pictInst->dropRiskyParts();
					}else{
						$outStrng = function_exists('imagecreatefromstring') ? $pictInst->turnUprightImg()->grantBytesText('', 90) : $rawStrng;
					}
					$fileName = substr(sha1($outStrng), 0, 20).'.'.$pictInst->getInfoOfImage()['kinds'];
					if( @file_put_contents($imagesFolder.DIRECTORY_SEPARATOR.$fileName, $outStrng)!==false ){
						$picturesSeen[$innerPth] = $fileName;
					}
				}catch( \RuntimeException $e ){
					// Not a picture this server reads, such as a drawing of an office suite: the block keeps its place in the archive
				}
			}
			if( strval($picturesSeen[$innerPth] ?? '')!=='' ){
				$bookBlockSet[$nthBlk]['attrs']['sourc'] = $picturesSeen[$innerPth];
			}
		}
		return $bookBlockSet;
	}
	
}
