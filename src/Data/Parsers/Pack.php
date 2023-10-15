<?php

namespace nyu\Data\Parsers;

use nyu\Data\Archv;
use nyu\Data\Fluxd;
use nyu\Data\Shape;

// What the parsers of zipped documents share: opening the archive,
// reading its XML parts into trees, and finding their way in those trees.
abstract class Pack extends Base {

	// Shape of what the parser gives: 'sheet' or 'block'
	const SHAPEOUT = 'block';
	// Greatest number of cells filled with the value of the merged area they belong to
	const FILLSMAX = 1000000;

	// The archive being read, and the greatest size accepted for one of its entries
	protected $openArch;
	protected $sizeLimt = self::DFLTSIZE;

	/**
	 * Reads the document held by an opened archive.
	 *
	 * @param Array $parseOptns Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the archive is not a valid document of the kind.
	 */
	abstract protected function readArchv(Array $parseOptns): Array;

	/**
	 * Parses a zipped document given as bytes. An empty content gives an
	 * empty result.
	 *
	 * @param String $rawContent The bytes of the document.
	 * @param Array  $parseOptns Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is not a valid document of the kind.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		if( $rawContent==='' ){
			return $this->makeShape(static::SHAPEOUT, Array());
		}
		$byteFlx = Fluxd::openBytes($rawContent);
		try{
			return $this->parseFlux($byteFlx, $parseOptns);
		}finally{
			$byteFlx->closeFlux();
		}
	}

	/**
	 * Parses a zipped document from a source. The 'limit' option is the
	 * greatest size accepted for each entry of the archive once unpacked,
	 * in bytes.
	 *
	 * @param Fluxd $sourceFlux The source.
	 * @param Array $parseOptns Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the source is not a zip archive, or not a valid document of the kind.
	 */
	public function parseFlux(Fluxd $sourceFlux, Array $parseOptns=Array()): Array {
		$this->sizeLimt = intval($parseOptns['limit'] ?? self::DFLTSIZE);
		try{
			$this->openArch = Archv::openFluxd($sourceFlux);
			return $this->readArchv($parseOptns);
		}catch( ParseError $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			// An Office document protected by a password is no longer a zip archive, but a container of the older Office formats
			if( $e->getMessage()=='Not a zip archive' && Bina::signature($sourceFlux->readRange(0, 511))=='ole' ){
				throw new ParseError('Invalid archive: the document is protected by a password, or saved in an older Office format', 0, $e);
			}
			throw new ParseError('Invalid archive: '.$e->getMessage(), 0, $e);
		}finally{
			if( $this->openArch!==null ){
				$this->openArch->closeArch();
			}
			$this->openArch = null;
		}
	}

	/**
	 * Reads an XML part of the archive into its tree of elements.
	 *
	 * @param String $insideName Name of the entry, with its folders.
	 * @param Bool   $isRequired Whether the document cannot do without it.
	 * @param Bool   $keepsSpace Whether the spacing written between two elements is kept, as the parts holding running text need.
	 * @return ?Array The root element, as XHml gives it; null when the part is missing and not required.
	 *
	 * @throws ParseError When the part is required and missing, or is not valid XML.
	 */
	protected function partNodes(String $insideName, Bool $isRequired=true, Bool $keepsSpace=false): ?Array {
		if( !$this->openArch->findEntry($insideName) ){
			if( $isRequired ){
				throw new ParseError('Invalid archive: part '.$insideName.' is missing');
			}
			return null;
		}
		try{
			$partEnv = (new XHml())->parseData($this->openArch->readEntry($insideName, $this->sizeLimt), Array('reads'=>'xml', 'shape'=>'nodes', 'space'=>$keepsSpace));
		}catch( ParseError $e ){
			throw new ParseError('Invalid archive: part '.$insideName.': '.$e->getMessage(), 0, $e);
		}
		return empty($partEnv['items']) ? null : $partEnv['items'];
	}

	/**
	 * Gives the child elements of an element that carry a name.
	 *
	 * @param Array  $nodeValues The element.
	 * @param String $tagNameStr Name looked for. Given with its prefix, it is matched whole; given without, it matches whatever prefix the document uses.
	 * @return Array The children of that name, in order.
	 */
	protected function kidsNamed(Array $nodeValues, String $tagNameStr): Array {
		$kidsLst = Array();
		$anyPrfx = (strpos($tagNameStr, ':')===false);
		foreach($nodeValues['nodes'] as $chlArr){
			$chldNme = $chlArr['tagnm'];
			if( $chldNme===$tagNameStr || ($anyPrfx && substr($chldNme, intval(strrpos($chldNme, ':'))+1)===$tagNameStr && strpos($chldNme, ':')!==false) ){
				$kidsLst[] = $chlArr;
			}
		}
		return $kidsLst;
	}

	/**
	 * Follows a path of names down an element, taking the first child of
	 * each name.
	 *
	 * @param Array $nodeValues The element.
	 * @param Array $tagsToWalk Names to follow, one per level.
	 * @return ?Array The element reached, null when the path breaks.
	 */
	protected function findChild(Array $nodeValues, Array $tagsToWalk): ?Array {
		foreach($tagsToWalk as $tagNme){
			$kidsLst = $this->kidsNamed($nodeValues, $tagNme);
			if( empty($kidsLst) ){
				return null;
			}
			$nodeValues = $kidsLst[0];
		}
		return $nodeValues;
	}

	/**
	 * Gives the text an element holds, at any depth.
	 *
	 * @param Array $nodeValues The element.
	 * @return String Its text, with the text of what it holds, in order.
	 */
	protected function textOfTag(Array $nodeValues): String {
		$fullTxt = $nodeValues['value'];
		foreach($nodeValues['nodes'] as $chlArr){
			$fullTxt .= $this->textOfTag($chlArr);
		}
		return $fullTxt;
	}
	
	/**
	 * Chooses the sheets of a workbook to read: the one the 'sheet'
	 * option asks for, by its name first and by its position otherwise,
	 * or all of them, the hidden ones left out with the 'shown' option.
	 *
	 * @param Array $namesArray The names of the sheets, in order.
	 * @param Array $shownArray Whether each of them is shown.
	 * @param Array $parseOptns Options of the parser.
	 * @return Array The positions of the sheets to read, counted from 0.
	 *
	 * @throws ParseError When the sheet asked for is not in the workbook.
	 */
	protected function pickSheet(Array $namesArray, Array $shownArray, Array $parseOptns): Array {
		if( isset($parseOptns['sheet']) && $parseOptns['sheet']!=='' ){
			$askdSht = strval($parseOptns['sheet']);
			$fndIndx = array_search($askdSht, $namesArray, true);
			if( $fndIndx===false && preg_match('/^[1-9]\d*$/D', $askdSht) && isset($namesArray[intval($askdSht)-1]) ){
				$fndIndx = intval($askdSht)-1;
			}
			if( $fndIndx===false ){
				throw new ParseError('Invalid workbook option: no sheet '.$askdSht);
			}
			return Array($fndIndx);
		}
		$keptIdx = Array();
		foreach(array_keys($namesArray) as $shtIdx){
			if( empty($parseOptns['shown']) || !empty($shownArray[$shtIdx]) ){
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
	 * @param String $sheetTitle Name of the sheet.
	 * @param Array  $rowsOfGrid Its rows, each a list of values.
	 * @param Array  $attrsArray What is particular to it: 'shown', 'merge'.
	 * @param Array  $parseOptns Options of the parser.
	 * @param Array  $headsFound Receives the names of its columns, with the 'heads' option.
	 * @return Array The sheet: 'title', 'attrs', 'cells'.
	 *
	 * @throws ParseError When the 'merge' option is not a known one, or the merged cells are too many to be filled.
	 */
	protected function doneSheet(String $sheetTitle, Array $rowsOfGrid, Array $attrsArray, Array $parseOptns, Array &$headsFound): Array {
		$mergeAs = strtolower(strval($parseOptns['merge'] ?? 'keeps'));
		if( $mergeAs!='keeps' && $mergeAs!='fills' ){
			throw new ParseError('Invalid workbook option: unknown merge '.$mergeAs);
		}
		if( $mergeAs=='fills' ){
			$rowsOfGrid = $this->fillMerge($rowsOfGrid, (array)($attrsArray['merge'] ?? Array()));
		}
		$headsFound = Array();
		if( !empty($parseOptns['heads']) ){
			$rowsOfGrid = $this->keyedRows($rowsOfGrid, $headsFound);
		}
		$shetArr = Shape::makeSheet($sheetTitle, Array(), $attrsArray);
		$shetArr['cells'] = $rowsOfGrid;
		return $shetArr;
	}
	
	/**
	 * Copies the value of each merged area into every cell it covers. An
	 * area whose first cell is empty is left as it is.
	 *
	 * @param Array $rowsOfGrid The rows, each a list of values.
	 * @param Array $mergeAreas The areas, each with its 'atrow', 'atcol', 'rowsp' and 'colsp'.
	 * @return Array The rows, filled.
	 *
	 * @throws ParseError When the cells to fill are too many.
	 */
	protected function fillMerge(Array $rowsOfGrid, Array $mergeAreas): Array {
		$fillNbr = 0;
		foreach($mergeAreas as $oneAre){
			$areaVal = $rowsOfGrid[$oneAre['atrow']][$oneAre['atcol']] ?? null;
			if( $areaVal===null ){
				continue;
			}
			$fillNbr += $oneAre['rowsp']*$oneAre['colsp'];
			if( $fillNbr>self::FILLSMAX ){
				throw new ParseError('Invalid workbook: merged cells too many to be filled');
			}
			for($rowIdx=$oneAre['atrow']; $rowIdx<$oneAre['atrow']+$oneAre['rowsp']; $rowIdx++){
				while( count($rowsOfGrid)<=$rowIdx ){
					$rowsOfGrid[] = Array();
				}
				$rowsOfGrid[$rowIdx] = array_pad($rowsOfGrid[$rowIdx], $oneAre['atcol']+$oneAre['colsp'], null);
				for($colIdx=$oneAre['atcol']; $colIdx<$oneAre['atcol']+$oneAre['colsp']; $colIdx++){
					$rowsOfGrid[$rowIdx][$colIdx] = $areaVal;
				}
			}
		}
		return $rowsOfGrid;
	}
	
	/**
	 * Refuses an OpenDocument file protected by a password: its archive
	 * opens, but its parts are encrypted.
	 *
	 * @throws ParseError When the document is protected by a password.
	 */
	protected function checkLock(): Void {
		if( $this->openArch->findEntry('META-INF/manifest.xml') && strpos($this->openArch->readEntry('META-INF/manifest.xml', $this->sizeLimt), 'encryption-data')!==false ){
			throw new ParseError('Invalid archive: the document is protected by a password');
		}
	}
	
	/**
	 * Gives the text of an OpenDocument paragraph, or of a piece of one:
	 * its spaces, tabulations and line breaks as their characters, and
	 * without the notes attached to it.
	 *
	 * @param Array $nodeValues The element.
	 * @return String Its text.
	 */
	protected function odfOfText(Array $nodeValues): String {
		$fullTxt = $nodeValues['value'];
		foreach($nodeValues['nodes'] as $chlArr){
			$chldNme = $chlArr['tagnm'];
			if( $chldNme=='text:s' ){
				$fullTxt .= str_repeat(' ', max(1, min(10000, intval($chlArr['attrs']['text:c'] ?? 1))));
			}elseif( $chldNme=='text:tab' ){
				$fullTxt .= "\t";
			}elseif( $chldNme=='text:line-break' ){
				$fullTxt .= "\n";
			}elseif( !in_array($chldNme, Array('office:annotation', 'office:annotation-end', 'text:note', 'text:tracked-changes')) ){
				$fullTxt .= $this->odfOfText($chlArr);
			}
		}
		return $fullTxt;
	}
	
	/**
	 * Reads the relations of a part of an Office document: the other
	 * parts, and the addresses, it points to.
	 *
	 * @param String $insideName Name of the part, '' for the archive itself.
	 * @return Array The relations, by their id, each with its kind under 'types', the name of the part it points to under 'paths', what it points to as written under 'links', and whether that is outside the archive under 'outer'.
	 */
	protected function relsOfPrt(String $insideName): Array {
		$slshPos = strrpos($insideName, '/');
		$partDir = ($slshPos===false) ? '' : substr($insideName, 0, $slshPos+1);
		$relsArr = $this->partNodes($partDir.'_rels/'.substr($insideName, strlen($partDir)).'.rels', false);
		$relsLst = Array();
		foreach($this->kidsNamed($relsArr ?? Array('nodes'=>Array()), 'Relationship') as $relArr){
			$relTrgt = strval($relArr['attrs']['Target'] ?? '');
			// A target is written from the folder of the part, or from the root of the archive when it starts with /
			$relPath = (substr($relTrgt, 0, 1)=='/') ? substr($relTrgt, 1) : $partDir.$relTrgt;
			$pathPrt = Array();
			foreach(explode('/', $relPath) as $pthSeg){
				if( $pthSeg=='..' ){
					array_pop($pathPrt);
				}elseif( $pthSeg!=='' && $pthSeg!='.' ){
					$pathPrt[] = $pthSeg;
				}
			}
			$relsLst[strval($relArr['attrs']['Id'] ?? '')] = Array('types'=>strval($relArr['attrs']['Type'] ?? ''), 'paths'=>implode('/', $pathPrt), 'links'=>$relTrgt, 'outer'=>(strtolower(strval($relArr['attrs']['TargetMode'] ?? ''))=='external'));
		}
		return $relsLst;
	}
	
	/**
	 * Finds the first element carrying one of some names inside an
	 * element, at any depth.
	 *
	 * @param Array $nodeValues The element.
	 * @param Array $tagsToFind The names looked for, with their prefix.
	 * @return ?Array The element found, null when there is none.
	 */
	protected function deepNamed(Array $nodeValues, Array $tagsToFind): ?Array {
		foreach($nodeValues['nodes'] as $chlArr){
			if( in_array($chlArr['tagnm'], $tagsToFind, true) ){
				return $chlArr;
			}
			$deepArr = $this->deepNamed($chlArr, $tagsToFind);
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
	 * @param Array $piecesList The pieces, in order: spans, and images and page breaks as blocks.
	 * @param Bool  $keepsEmpty Whether a paragraph holding nothing is given as a block without text.
	 * @return Array The whole text as spans under 'spans' and as written under 'plain', the images and the page breaks under 'holds', and all of it in order, as blocks, under 'flows'.
	 */
	protected function flowParts(Array $piecesList, Bool $keepsEmpty): Array {
		$spnsAll = Array();
		$holdLst = Array();
		$flowLst = Array();
		$spnsBuf = Array();
		$piecesList[] = Array('block'=>'#end');
		foreach($piecesList as $onePce){
			if( !isset($onePce['block']) ){
				$spnsBuf[] = $onePce;
				$spnsAll[] = $onePce;
				continue;
			}
			$tidySpn = Shape::joinSpans($spnsBuf);
			$spnsBuf = Array();
			if( trim(implode('', array_column($tidySpn, 'texts')))!=='' ){
				$flowLst[] = Shape::makeBlock('parag', $tidySpn);
			}
			if( $onePce['block']!='#end' ){
				$flowLst[] = $onePce;
				$holdLst[] = $onePce;
			}
		}
		$spnsAll = Shape::joinSpans($spnsAll);
		$fullTxt = implode('', array_column($spnsAll, 'texts'));
		if( trim($fullTxt)==='' ){
			$spnsAll = Array();
		}
		// A paragraph holding nothing is a block without text, when those are kept
		if( empty($flowLst) && $keepsEmpty ){
			$flowLst[] = Shape::makeBlock('parag');
		}
		return Array('spans'=>$spnsAll, 'plain'=>$fullTxt, 'holds'=>$holdLst, 'flows'=>$flowLst);
	}
	
	/**
	 * Gives the blocks of a paragraph once it is read: a heading, a
	 * quote, or its text with the images it holds, and a page break
	 * before or after it when it asks for one.
	 *
	 * @param Array $paraValues The paragraph: its 'kinds' (headr, quote or parag), 'level', 'attrs', 'spans', 'holds', 'flows', and whether a page break stands before and after it under 'prior' and 'after'.
	 * @return Array The blocks.
	 */
	protected function paraBlcks(Array $paraValues): Array {
		$paraKnd = $paraValues['kinds'];
		$paraBlk = $paraValues['flows'];
		if( $paraKnd=='headr' || $paraKnd=='quote' ){
			// A heading and a quote hold their text only; what else their paragraph holds follows them
			$paraBlk = array_merge(
				Array(Shape::makeBlock($paraKnd, $paraValues['spans'], Array(), ($paraKnd=='headr') ? Array('level'=>$paraValues['level']) : Array())),
				($paraKnd=='headr') ? array_values(array_filter($paraValues['holds'], function($blockArray){ return $blockArray['block']=='rules'; })) : $paraValues['holds']
			);
		}
		// The style, the alignment and the id are those of the text, not of an image or a page break
		if( !empty($paraBlk) && !in_array($paraBlk[0]['block'], Array('rules', 'image')) ){
			$paraBlk[0]['attrs'] = $paraBlk[0]['attrs'] + $paraValues['attrs'];
		}
		$brkBlck = Shape::makeBlock('rules', Array(), Array(), Array('break'=>true));
		return array_merge($paraValues['prior'] ? Array($brkBlck) : Array(), $paraBlk, $paraValues['after'] ? Array($brkBlck) : Array());
	}
	
	/**
	 * Makes one block of code of the paragraphs of code that follow one
	 * another, each being one of its lines.
	 *
	 * @param Array $parasArray The paragraphs, each with its text as written under 'plain' and what is particular to it under 'attrs'.
	 * @return Array The block, in a list; an empty list when the lines hold nothing.
	 */
	protected function codeBlock(Array $parasArray): Array {
		$codeTxt = trim(implode("\n", array_column($parasArray, 'plain')), "\n");
		if( trim($codeTxt)==='' ){
			return Array();
		}
		return Array(Shape::makeBlock('codes', Array(Shape::makeASpan($codeTxt)), Array(), $parasArray[0]['attrs'] ?? Array()));
	}
	
	/**
	 * Gives what a style gives, as the details of a document list it.
	 *
	 * @param Array $styleArray The style: its name under 'names', then 'fonts', 'sizes', 'color', 'marks' and 'align'.
	 * @return Array A name fit for a style sheet under 'class', then what the style tells among 'fonts', 'sizes', 'color', 'marks' and 'align'.
	 */
	protected function frmatOfIt(Array $styleArray): Array {
		$clssNme = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strval($styleArray['names'] ?? ''))), '-');
		$frmtSet = Array('class'=>preg_match('/^[a-z]/', $clssNme) ? $clssNme : 's-'.$clssNme);
		foreach(Array('fonts', 'sizes', 'color', 'marks', 'align') as $prpKey){
			if( !empty($styleArray[$prpKey]) ){
				$frmtSet[$prpKey] = $styleArray[$prpKey];
			}
		}
		// A size is a whole number of points more often than not
		if( isset($frmtSet['sizes']) && floor($frmtSet['sizes'])==$frmtSet['sizes'] ){
			$frmtSet['sizes'] = intval($frmtSet['sizes']);
		}
		return $frmtSet;
	}
	
}
