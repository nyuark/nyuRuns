<?php

namespace nyu\Data\Writers;

use nyu\Data\Archv;
use nyu\Data\Shape;

/**
 * Word documents (docx) written from text blocks: headings with the
 * styles Word finds its way by, paragraphs, lists that are lists for
 * Word, tables, quotes, code, notes, and what stands at the top and the
 * bottom of the pages. Sheets are written as tables. What is text stays
 * text: the document holds no macro, no object, and points to nothing
 * it would fetch. Its pictures are those of a folder it is given, and
 * no other: none is ever taken from an address.
 */
class Docx extends Page {

	// The size of the headings, by their level, in points
	const HEAD_SIZE = Array(1=>20, 2=>16, 3=>14, 4=>12, 5=>11, 6=>11);
	// How a paragraph is aligned, by the code that asks for it
	const ALGN_LIST = Array('alignLft'=>'left', 'alignRgt'=>'right', 'alignCtr'=>'center', 'alignJst'=>'both');
	// The deepest a list goes into another
	const LIST_DEEP = 8;
	// The properties Word keeps a place for, by their name in the content: the element they are written in
	const PROP_LIST = Array('Title'=>'dc:title', 'Author'=>'dc:creator', 'Subject'=>'dc:subject', 'Keywords'=>'cp:keywords', 'Comments'=>'dc:description', 'Category'=>'cp:category', 'LastSavedBy'=>'cp:lastModifiedBy');
	// What a field asks Word for, by the code of the field
	const FLDS_LIST = Array('pageNumb'=>'PAGE', 'pageTotl'=>'NUMPAGES', 'dateTime'=>'DATE', 'props:Title'=>'TITLE', 'props:Author'=>'AUTHOR', 'props:Subject'=>'SUBJECT', 'props:Keywords'=>'KEYWORDS', 'props:Comments'=>'COMMENTS', 'props:LastSavedBy'=>'LASTSAVEDBY');
	// The addresses of the format, and of what ties its parts together
	const MAIN_NAME = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const RELS_NAME = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const PACK_NAME = 'http://schemas.openxmlformats.org/package/2006';

	// The addresses the part being written points to, each with the id of its relation
	protected $knownLinks = Array();
	// The lists of the document, in the order they are met: true for a numbered one
	protected $numbersLst = Array();
	// The names given to places of the document so far
	protected $anchorList = Array();
	// The notes of the document, by their kind then their name: the block of each
	protected $remarkList = Array();
	// The notes the text has referred to so far, by their kind then their name: the number Word knows each by
	protected $citedNotes = Array();
	// Whether the part being written may not refer to a note: only the text of the document may
	protected $isNoteless = false;
	// The styles of the content written as styles of the document: name in small letters => id
	protected $styleTable = Array();
	// The pictures the part being written shows, each with the id of its relation: name in the document => id
	protected $pictureLst = Array();
	// The pictures of the document, each written once: what tells its content apart => its name in the document, its bytes, its width and its height in pixels
	protected $mediaFiles = Array();
	// The room a picture has on a page: its greatest width and height, in the unit Word measures pictures in
	protected $roomLength = Array(0, 0);

	/**
	 * Writes text blocks, or sheets, as a document.
	 *
	 * Options:
	 *   'paper' => the size of the pages: 'isoPgeA3', 'isoPgeA4', 'isoPgeA5',
	 *              'usLetter' or 'legalUsa'; 'isoPgeA4' when left out;
	 *   'lands' => lays the pages out on their long side;
	 *   'media' => the folder the pictures of the document are taken
	 *              from: an image whose file is in it is written into
	 *              the document, any other is written as the text that
	 *              describes it. No picture is ever fetched.
	 *
	 * What is told of the document is written with it: its title under
	 * 'title', its author under 'authr', its other properties under
	 * 'props', what stands at the top and at the bottom of its pages
	 * under 'phead' and 'pfoot', and the styles its paragraphs name under
	 * 'frmat'.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'paper', 'lands', 'media'.
	 * @return String The bytes of the document.
	 *
	 * @throws WriteError When the shape is neither 'block' nor 'sheet', the size of paper is not a known one, or the folder of the pictures cannot be read.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$shapeNme = $this->checkShapeText($dataEnvelope, Array('block', 'sheet'), 'a document');
		$sizeDict = $this->getPageSizeDct($writeOptions);
		// A picture is no wider nor higher than the text of a page: a twentieth of a point is 635 of Word's units
		$this->roomLength = Array(($sizeDict[0]-2880)*635, ($sizeDict[1]-2880-1440)*635);
		$this->readPictureDir($writeOptions);
		$this->pictureLst = Array();
		$this->mediaFiles = Array();
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$blockLst = ($shapeNme=='sheet') ? $this->getSheetBlocks((array)($dataEnvelope['items'] ?? Array())) : (array)($dataEnvelope['items'] ?? Array());
		$this->knownLinks = Array();
		$this->numbersLst = Array();
		$this->anchorList = Array();
		$this->citedNotes = Array('fnote'=>Array(), 'enote'=>Array());
		// The notes are taken out of the text: each is written where the text refers to it
		$this->remarkList = $this->splitNotesDict($blockLst);
		$styleTxt = $this->writeStylePart((array)($infoList['frmat'] ?? Array()), $blockLst);
		$this->isNoteless = false;
		$bodyText = $this->writeAllBlocks($blockLst, 0);
		// Only the text of the document refers to notes: what follows writes a reference as the text it is
		$this->isNoteless = true;
		$linkList = Array($this->knownLinks, $this->pictureLst);
		$headBrut = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
		$rootText = ' xmlns:w="'.self::MAIN_NAME.'" xmlns:r="'.self::RELS_NAME.'" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
		$moreList = Array();
		$typeText = '';
		$rltnText = '<Relationship Id="rId1" Type="'.self::RELS_NAME.'/styles" Target="styles.xml"/><Relationship Id="rId2" Type="'.self::RELS_NAME.'/numbering" Target="numbering.xml"/>';
		$sctnText = '';
		// What stands at the top and at the bottom of the pages: a part each, with the addresses it points to
		foreach(Array('phead'=>Array('header', 'w:hdr', 'rId3'), 'pfoot'=>Array('footer', 'w:ftr', 'rId4')) as $infKey=>$pgeSet){
			if( empty($infoList[$infKey]) || !is_array($infoList[$infKey]) ){
				continue;
			}
			$this->knownLinks = Array();
			$this->pictureLst = Array();
			$pageText = $this->writeAllBlocks($infoList[$infKey], 0);
			$moreList['word/'.$pgeSet[0].'1.xml'] = $headBrut.'<'.$pgeSet[1].$rootText.'>'.(($pageText==='' || substr($pageText, -8)=='</w:tbl>') ? $pageText.'<w:p/>' : $pageText).'</'.$pgeSet[1].'>';
			$moreList['word/_rels/'.$pgeSet[0].'1.xml.rels'] = $headBrut.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$this->writeRelations().'</Relationships>';
			$typeText .= '<Override PartName="/word/'.$pgeSet[0].'1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.'.$pgeSet[0].'+xml"/>';
			$rltnText .= '<Relationship Id="'.$pgeSet[2].'" Type="'.self::RELS_NAME.'/'.$pgeSet[0].'" Target="'.$pgeSet[0].'1.xml"/>';
			$sctnText .= '<w:'.$pgeSet[0].'Reference w:type="default" r:id="'.$pgeSet[2].'"/>';
		}
		// The notes the text referred to: those of the pages, those of the end
		$lostList = Array();
		foreach(Array('fnote'=>Array('footnote', 'rId5'), 'enote'=>Array('endnote', 'rId6')) as $nteKnd=>$nteSet){
			$lostList = array_merge($lostList, array_values(array_diff_key($this->remarkList[$nteKnd], $this->citedNotes[$nteKnd])));
			if( empty($this->citedNotes[$nteKnd]) ){
				continue;
			}
			$this->knownLinks = Array();
			$this->pictureLst = Array();
			$moreList['word/'.$nteSet[0].'s.xml'] = $headBrut.'<w:'.$nteSet[0].'s'.$rootText.'>'.$this->writeNotesPart($nteKnd, $nteSet[0]).'</w:'.$nteSet[0].'s>';
			$moreList['word/_rels/'.$nteSet[0].'s.xml.rels'] = $headBrut.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$this->writeRelations().'</Relationships>';
			$typeText .= '<Override PartName="/word/'.$nteSet[0].'s.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.'.$nteSet[0].'s+xml"/>';
			$rltnText .= '<Relationship Id="'.$nteSet[1].'" Type="'.self::RELS_NAME.'/'.$nteSet[0].'s" Target="'.$nteSet[0].'s.xml"/>';
		}
		// A note the text never referred to is not lost: it ends the document, as text
		$this->knownLinks = $linkList[0];
		$this->pictureLst = $linkList[1];
		foreach($lostList as $blkArr){
			$bodyText .= $this->writeBlockPart(Array('block'=>'paragrph') + $blkArr, 0);
		}
		$rltnText .= $this->writeRelations();
		// The properties: those Word keeps a place for, then the others
		$propList = (array)($infoList['props'] ?? Array());
		foreach(Array('title'=>'Title', 'authr'=>'Author') as $infKey=>$prpNme){
			if( is_scalar($infoList[$infKey] ?? null) && strval($infoList[$infKey])!=='' ){
				$propList[$prpNme] = strval($infoList[$infKey]);
			}
		}
		$coreText = '';
		foreach(self::PROP_LIST as $prpNme=>$xmlTag){
			$coreText .= (is_scalar($propList[$prpNme] ?? null) && strval($propList[$prpNme])!=='') ? '<'.$xmlTag.'>'.$this->escapeXmlValue(strval($propList[$prpNme])).'</'.$xmlTag.'>' : '';
		}
		$custText = $this->writeExtraProp(array_diff_key($propList, self::PROP_LIST));
		// The pictures, each written once, and the kinds of file they are
		foreach($this->mediaFiles as $medSet){
			$moreList['word/'.$medSet[0]] = $medSet[1];
			$fileExtn = substr($medSet[0], intval(strrpos($medSet[0], '.'))+1);
			if( strpos($typeText, 'Extension="'.$fileExtn.'"')===false ){
				$typeText = '<Default Extension="'.$fileExtn.'" ContentType="'.self::PICT_LIST[($fileExtn=='jpeg') ? 'jpg' : $fileExtn].'"/>'.$typeText;
			}
		}
		$fileList = Array(
			'[Content_Types].xml'=>$headBrut.'<Types xmlns="'.self::PACK_NAME.'/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.$typeText
				.'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
				.'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
				.'<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
				.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
				.(($custText==='') ? '' : '<Override PartName="/docProps/custom.xml" ContentType="application/vnd.openxmlformats-officedocument.custom-properties+xml"/>').'</Types>',
			'_rels/.rels'=>$headBrut.'<Relationships xmlns="'.self::PACK_NAME.'/relationships"><Relationship Id="rId1" Type="'.self::RELS_NAME.'/officeDocument" Target="word/document.xml"/>'
				.'<Relationship Id="rId2" Type="'.self::PACK_NAME.'/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
				.(($custText==='') ? '' : '<Relationship Id="rId3" Type="'.self::RELS_NAME.'/custom-properties" Target="docProps/custom.xml"/>').'</Relationships>',
			'docProps/core.xml'=>$headBrut.'<cp:coreProperties xmlns:cp="'.self::PACK_NAME.'/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				.$coreText.'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>'
		) + (($custText==='') ? Array() : Array('docProps/custom.xml'=>$headBrut.$custText)) + Array(
			'word/document.xml'=>$headBrut.'<w:document'.$rootText.'><w:body>'.$bodyText.((substr($bodyText, -8)=='</w:tbl>') ? '<w:p/>' : '')
				.'<w:sectPr>'.$sctnText.'<w:pgSz w:w="'.$sizeDict[0].'" w:h="'.$sizeDict[1].'"'.(empty($writeOptions['lands']) ? '' : ' w:orient="landscape"').'/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>',
			'word/_rels/document.xml.rels'=>$headBrut.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$rltnText.'</Relationships>',
			'word/styles.xml'=>$headBrut.$styleTxt,
			'word/numbering.xml'=>$headBrut.$this->writeListsPart()
		) + $moreList;
		try{
			return Archv::makeZipFromLst($fileList);
		}catch( \RuntimeException $e ){
			throw new WriteError('Document that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Gives the relations of the addresses the part just written points
	 * to, and of the pictures it shows. What a document points to is told
	 * apart from its text: an address is never part of it.
	 *
	 * @return String The relations, as written.
	 */
	protected function writeRelations(): String {
		$rltnText = '';
		foreach($this->knownLinks as $lnkAdr=>$linkId){
			$rltnText .= '<Relationship Id="'.$linkId.'" Type="'.self::RELS_NAME.'/hyperlink" Target="'.$this->escapeXmlValue($lnkAdr).'" TargetMode="External"/>';
		}
		foreach($this->pictureLst as $picKey=>$linkId){
			$rltnText .= '<Relationship Id="'.$linkId.'" Type="'.self::RELS_NAME.'/image" Target="'.$picKey.'"/>';
		}
		return $rltnText;
	}

	/**
	 * Writes the properties of the document Word keeps no place for,
	 * each with the kind of its value.
	 *
	 * @param Array $propertyList The properties, name => value.
	 * @return String The part holding them, '' when there is none.
	 */
	protected function writeExtraProp(Array $propertyList): String {
		$propText = '';
		$propNumb = 1;
		foreach($propertyList as $prpNme=>$prpVal){
			$nameText = trim(strval($prpNme));
			if( $nameText==='' || !is_scalar($prpVal) || (is_float($prpVal) && !is_finite($prpVal)) ){
				continue;
			}
			if( is_bool($prpVal) ){
				$valueTxt = '<vt:bool>'.($prpVal ? 'true' : 'false').'</vt:bool>';
			}elseif( is_int($prpVal) ){
				$valueTxt = (abs($prpVal)<2147483648) ? '<vt:i4>'.$prpVal.'</vt:i4>' : '<vt:i8>'.$prpVal.'</vt:i8>';
			}elseif( is_float($prpVal) ){
				$valueTxt = '<vt:r8>'.$this->writeNumberTxt($prpVal).'</vt:r8>';
			}else{
				$valueTxt = '<vt:lpwstr>'.$this->escapeXmlValue($prpVal).'</vt:lpwstr>';
			}
			$propNumb++;
			$propText .= '<property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="'.$propNumb.'" name="'.$this->escapeXmlValue($nameText).'">'.$valueTxt.'</property>';
		}
		return ($propText==='') ? '' : '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'.$propText.'</Properties>';
	}

	/**
	 * Writes the styles of the document: its text, its headings, the
	 * entries of its lists, its quotes, its code, its links and its
	 * notes, under the names Word gives them in every language; then the
	 * styles its paragraphs name, with what the content tells of each.
	 *
	 * @param Array $styleFormats The styles the content tells of, name => its font under 'fonts', its size in points under 'sizes', its colour under 'color', its formatting under 'marks' and its alignment under 'align'.
	 * @param Array $bookBlockSet The blocks of the document, for the styles their paragraphs name.
	 * @return String The part holding the styles.
	 */
	protected function writeStylePart(Array $styleFormats, Array $bookBlockSet): String {
		$styleTxt = '<w:styles xmlns:w="'.self::MAIN_NAME.'"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
			.'<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
			.'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			.'<w:style w:type="character" w:default="1" w:styleId="DefaultParagraphFont"><w:name w:val="Default Paragraph Font"/><w:uiPriority w:val="1"/><w:semiHidden/></w:style>';
		foreach(self::HEAD_SIZE as $hdrLvl=>$sizeNb){
			$styleTxt .= '<w:style w:type="paragraph" w:styleId="Heading'.$hdrLvl.'"><w:name w:val="heading '.$hdrLvl.'"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
				.'<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="'.(($hdrLvl==1) ? 360 : 200).'" w:after="80"/><w:outlineLvl w:val="'.($hdrLvl-1).'"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="'.($sizeNb*2).'"/><w:szCs w:val="'.($sizeNb*2).'"/></w:rPr></w:style>';
		}
		$styleTxt .= '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="34"/><w:qFormat/><w:pPr><w:contextualSpacing/><w:ind w:left="720"/></w:pPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="29"/><w:qFormat/><w:pPr><w:spacing w:before="160"/><w:ind w:left="720" w:right="720"/></w:pPr><w:rPr><w:i/><w:iCs/><w:color w:val="404040"/></w:rPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="HTMLPreformatted"><w:name w:val="HTML Preformatted"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="TableHeading"><w:name w:val="Table Heading"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:spacing w:after="0"/></w:pPr><w:rPr><w:b/><w:bCs/></w:rPr></w:style>'
			.'<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:basedOn w:val="DefaultParagraphFont"/><w:uiPriority w:val="99"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style>';
		foreach(Array('Footnote'=>'footnote', 'Endnote'=>'endnote') as $noteId=>$nteNme){
			$styleTxt .= '<w:style w:type="paragraph" w:styleId="'.$noteId.'Text"><w:name w:val="'.$nteNme.' text"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
				.'<w:style w:type="character" w:styleId="'.$noteId.'Reference"><w:name w:val="'.$nteNme.' reference"/><w:basedOn w:val="DefaultParagraphFont"/><w:uiPriority w:val="99"/><w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>';
		}
		// The styles the paragraphs name: those the content tells of, and those it only names
		$this->styleTable = Array();
		$nameList = array_map('strval', array_keys($styleFormats));
		$walkFunc = function(Array $blocksToWalk) use (&$walkFunc, &$nameList){
			foreach($blocksToWalk as $blkArr){
				if( is_array($blkArr) ){
					$nameList[] = (strval($blkArr['block'] ?? '')=='paragrph' && is_scalar($blkArr['attrs']['style'] ?? null)) ? strval($blkArr['attrs']['style']) : '';
					$walkFunc((array)($blkArr['items'] ?? Array()));
				}
			}
		};
		$walkFunc($bookBlockSet);
		foreach($nameList as $oneNme){
			$nameClef = strtolower(trim($oneNme));
			if( $nameClef==='' || in_array($nameClef, self::OWNS_STYL) || isset($this->styleTable[$nameClef]) ){
				continue;
			}
			$styleDct = (array)($styleFormats[$oneNme] ?? Array());
			$stylIdnt = 'nyu'.(count($this->styleTable)+1).substr(strval(preg_replace('/[^A-Za-z0-9]+/', '', $oneNme)), 0, 20);
			$this->styleTable[$nameClef] = $stylIdnt;
			$markList = (array)($styleDct['marks'] ?? Array());
			$fontText = is_scalar($styleDct['fonts'] ?? '') ? $this->escapeXmlValue(substr(trim(strval($styleDct['fonts'] ?? '')), 0, 60)) : '';
			$sizeHalf = is_numeric($styleDct['sizes'] ?? null) ? intval(round(max(1, min(400, floatval($styleDct['sizes'])))*2)) : 0;
			$colrMatx = Array();
			$runsText = (($fontText==='') ? '' : '<w:rFonts w:ascii="'.$fontText.'" w:hAnsi="'.$fontText.'" w:cs="'.$fontText.'"/>')
				.(in_array('boldFace', $markList) ? '<w:b/><w:bCs/>' : '').(in_array('emphasis', $markList) ? '<w:i/><w:iCs/>' : '').(in_array('crossOut', $markList) ? '<w:strike/>' : '')
				.(preg_match('/^#?([0-9A-Fa-f]{6})$/D', is_scalar($styleDct['color'] ?? '') ? strval($styleDct['color'] ?? '') : '', $colrMatx) ? '<w:color w:val="'.strtoupper($colrMatx[1]).'"/>' : '')
				.(($sizeHalf>0) ? '<w:sz w:val="'.$sizeHalf.'"/><w:szCs w:val="'.$sizeHalf.'"/>' : '').(in_array('undrLine', $markList) ? '<w:u w:val="single"/>' : '');
			$alignKey = is_scalar($styleDct['align'] ?? '') ? strval($styleDct['align'] ?? '') : '';
			$styleTxt .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="'.$stylIdnt.'"><w:name w:val="'.$this->escapeXmlValue(substr(trim($oneNme), 0, 100)).'"/><w:basedOn w:val="Normal"/><w:qFormat/>'
				.(isset(self::ALGN_LIST[$alignKey]) ? '<w:pPr><w:jc w:val="'.self::ALGN_LIST[$alignKey].'"/></w:pPr>' : '').(($runsText==='') ? '' : '<w:rPr>'.$runsText.'</w:rPr>').'</w:style>';
		}
		return $styleTxt.'</w:styles>';
	}

	/**
	 * Writes the lists of the document: what a marked one and a numbered
	 * one look like at each level, then every list met, which starts its
	 * numbers again.
	 *
	 * @return String The part holding the lists.
	 */
	protected function writeListsPart(): String {
		$numbText = '<w:numbering xmlns:w="'.self::MAIN_NAME.'">';
		foreach(Array(0=>Array('bullet', Array("\u{2022}", "\u{25E6}", "\u{25AA}")), 1=>Array('', Array('decimal', 'lowerLetter', 'lowerRoman'))) as $listId=>$absSet){
			$numbText .= '<w:abstractNum w:abstractNumId="'.$listId.'"><w:multiLevelType w:val="hybridMultilevel"/>';
			for($lvlIdx=0; $lvlIdx<=self::LIST_DEEP; $lvlIdx++){
				$numbText .= '<w:lvl w:ilvl="'.$lvlIdx.'"><w:start w:val="1"/><w:numFmt w:val="'.(($listId==0) ? 'bullet' : $absSet[1][$lvlIdx%3]).'"/><w:lvlText w:val="'.(($listId==0) ? $absSet[1][$lvlIdx%3] : '%'.($lvlIdx+1).'.').'"/><w:lvlJc w:val="left"/>'
					.'<w:pPr><w:ind w:left="'.(720*($lvlIdx+1)).'" w:hanging="360"/></w:pPr></w:lvl>';
			}
			$numbText .= '</w:abstractNum>';
		}
		foreach($this->numbersLst as $numIdx=>$isOrdr){
			$numbText .= '<w:num w:numId="'.($numIdx+1).'"><w:abstractNumId w:val="'.($isOrdr ? 1 : 0).'"/>';
			for($lvlIdx=0; $isOrdr && $lvlIdx<=self::LIST_DEEP; $lvlIdx++){
				$numbText .= '<w:lvlOverride w:ilvl="'.$lvlIdx.'"><w:startOverride w:val="1"/></w:lvlOverride>';
			}
			$numbText .= '</w:num>';
		}
		return $numbText.'</w:numbering>';
	}

	/**
	 * Writes the notes the text referred to, in the order it did. Word
	 * asks for two notes of its own before them: the lines it draws
	 * between the text and the notes.
	 *
	 * @param String $noteTypeCode The kind of notes: 'fnote' for those of the pages, 'enote' for those of the end.
	 * @param String $wordNoteName The name Word gives that kind: 'footnote' or 'endnote'.
	 * @return String The notes, as written.
	 */
	protected function writeNotesPart(String $noteTypeCode, String $wordNoteName): String {
		$noteText = '<w:'.$wordNoteName.' w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:'.$wordNoteName.'><w:'.$wordNoteName.' w:type="continuationSeparator" w:id="0"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:'.$wordNoteName.'>';
		$stylIdnt = ucfirst($wordNoteName);
		foreach($this->citedNotes[$noteTypeCode] as $noteId=>$noteNb){
			$noteList = $this->remarkList[$noteTypeCode][$noteId];
			$spanList = array_values((array)($noteList['spans'] ?? Array()));
			// The number of the note, a space, then its text: the space is Word's, not the note's
			if( isset($spanList[0]['texts']) ){
				$spanList[0]['texts'] = ltrim(strval($spanList[0]['texts']));
			}
			$noteText .= '<w:'.$wordNoteName.' w:id="'.$noteNb.'"><w:p><w:pPr><w:pStyle w:val="'.$stylIdnt.'Text"/></w:pPr><w:r><w:rPr><w:rStyle w:val="'.$stylIdnt.'Reference"/></w:rPr><w:'.$wordNoteName.'Ref/></w:r><w:r><w:t xml:space="preserve"> </w:t></w:r>'
				.$this->writeSpansPart($spanList).'</w:p>'.$this->writeAllBlocks((array)($noteList['items'] ?? Array()), 0);
			// A note ends with a paragraph, as a cell does
			$noteText .= ((substr($noteText, -8)=='</w:tbl>') ? '<w:p/>' : '').'</w:'.$wordNoteName.'>';
		}
		return $noteText;
	}

	/**
	 * Writes blocks, one after the other.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @param Int   $nestingDepth How far in they stand: 0 for the text of the document, one more for what an entry of a list holds.
	 * @return String The paragraphs they make.
	 */
	protected function writeAllBlocks(Array $bookBlockSet, Int $nestingDepth): String {
		$bodyText = '';
		foreach($bookBlockSet as $blkArr){
			if( is_array($blkArr) && isset($blkArr['block']) ){
				$partText = $this->writeBlockPart($blkArr, $nestingDepth);
				// A paragraph stands between two tables, without which they would become one
				$bodyText .= ((substr($bodyText, -8)=='</w:tbl>' && substr($partText, 0, 7)=='<w:tbl>') ? '<w:p/>' : '').$partText;
			}
		}
		return $bodyText;
	}

	/**
	 * Writes a block as the Word paragraphs of its kind: heading, list, table, quote, code, rule, picture or paragraph.
	 *
	 * @param Array $contentBlock The block.
	 * @param Int   $nestingDepth How far in it stands.
	 * @return String The paragraphs it makes.
	 */
	protected function writeBlockPart(Array $contentBlock, Int $nestingDepth): String {
		$blockCod = strval($contentBlock['block']);
		$attrDict = (array)($contentBlock['attrs'] ?? Array());
		$spanList = (array)($contentBlock['spans'] ?? Array());
		$itemList = (array)($contentBlock['items'] ?? Array());
		$indtTags = ($nestingDepth>0) ? '<w:ind w:left="'.(720*$nestingDepth).'"/>' : '';
		if( $blockCod=='headline' ){
			return $this->writeParagraph(Array('style'=>'Heading'.max(1, min(6, intval($attrDict['level'] ?? 1))), 'indnt'=>$indtTags), $spanList, $attrDict);
		}
		if( $blockCod=='itemList' ){
			return $this->writeListPiece($contentBlock, $nestingDepth);
		}
		if( $blockCod=='tableSet' ){
			return $this->writeTablePart($contentBlock, $nestingDepth);
		}
		if( $blockCod=='citation' ){
			$quoteTxt = $this->writeParagraph(Array('style'=>'Quote'), $spanList, $attrDict).$this->writeAllBlocks($itemList, $nestingDepth+1);
			// Who is quoted follows the quote
			$citeText = is_scalar($attrDict['cites'] ?? '') ? trim(strval($attrDict['cites'] ?? '')) : '';
			return $quoteTxt.(($citeText==='') ? '' : $this->writeParagraph(Array('style'=>'Quote'), Array(Shape::makeSpanOfText("\u{2014} ".$citeText)), Array('align'=>'alignRgt')));
		}
		if( $blockCod=='codeBlck' ){
			// A paragraph for each line of code, their spaces kept
			$codeText = '';
			foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", implode('', array_column($spanList, 'texts')))) as $oneLin){
				$codeText .= $this->writeParagraph(Array('style'=>'HTMLPreformatted', 'indnt'=>$indtTags), Array(Shape::makeSpanOfText($oneLin)), Array());
			}
			return $codeText;
		}
		if( $blockCod=='ruleLine' ){
			// A page ends here, or a line is drawn across it
			if( !empty($attrDict['break']) ){
				return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
			}
			return $this->writeParagraph(Array('bordr'=>'<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr>'), Array(), Array());
		}
		if( $blockCod=='imageBox' ){
			$pictText = $this->writeImagePart($contentBlock, $indtTags);
			if( $pictText!=='' ){
				return $pictText;
			}
		}
		if( $blockCod=='tableTop' || $blockCod=='tableRow' ){
			// A row met outside a table is a table of that row
			return $this->writeTablePart(Array('items'=>Array($contentBlock)), $nestingDepth);
		}
		// A paragraph, with the style it names; and what stands for a picture that is not written: the text that describes it
		$stylIdnt = ($blockCod=='paragrph' && is_scalar($attrDict['style'] ?? null)) ? strval($this->styleTable[strtolower(trim(strval($attrDict['style'])))] ?? '') : '';
		return $this->writeParagraph(Array('indnt'=>$indtTags) + (($stylIdnt==='') ? Array() : Array('style'=>$stylIdnt)), $spanList, $attrDict).$this->writeAllBlocks($itemList, $nestingDepth);
	}

	/**
	 * Writes a picture into the document, when its file is in the folder
	 * the document was given.
	 *
	 * @param Array  $contentBlock The image: the name of its file under 'sourc', and the text that describes it.
	 * @param String $indentString How far in it stands, as Word is told.
	 * @return String The paragraph showing the picture; '' when the picture is not written.
	 */
	protected function writeImagePart(Array $contentBlock, String $indentString): String {
		$pictDict = $this->readPictureDct(is_scalar($contentBlock['attrs']['sourc'] ?? '') ? strval($contentBlock['attrs']['sourc'] ?? '') : '');
		if( $pictDict===null ){
			return '';
		}
		$pictText = $pictDict['bytes'];
		$givenCod = $pictDict['kinds'];
		$widePixl = $pictDict['width'];
		$highPixl = $pictDict['heght'];
		// The same picture is written once, however often it is shown
		$pictClef = sha1($pictText);
		if( !isset($this->mediaFiles[$pictClef]) ){
			if( array_sum(array_map(function(Array $mediaItemLst){ return strlen($mediaItemLst[1]); }, $this->mediaFiles))+strlen($pictText)>self::MEDI_MAXI ){
				return '';
			}
			$this->mediaFiles[$pictClef] = Array('media/image'.(count($this->mediaFiles)+1).'.'.(($givenCod=='jpg') ? 'jpeg' : $givenCod), $pictText);
		}
		$pictName = $this->mediaFiles[$pictClef][0];
		if( !isset($this->pictureLst[$pictName]) ){
			$this->pictureLst[$pictName] = 'rIm'.(count($this->pictureLst)+1);
		}
		// A pixel is 9525 of Word's units; a picture larger than the text of a page is brought down to it, its proportions kept
		$sizeRato = min(1, $this->roomLength[0]/($widePixl*9525), $this->roomLength[1]/($highPixl*9525));
		$widthEmu = max(1, intval(round($widePixl*9525*$sizeRato)));
		$heigtEmu = max(1, intval(round($highPixl*9525*$sizeRato)));
		$this->anchorList[] = '';
		$drwnIdnt = count($this->anchorList);
		$infoText = $this->escapeXmlValue(trim(implode('', array_column((array)($contentBlock['spans'] ?? Array()), 'texts'))));
		$alignKey = is_scalar($contentBlock['attrs']['align'] ?? '') ? strval($contentBlock['attrs']['align'] ?? '') : '';
		return '<w:p>'.(($indentString==='' && !isset(self::ALGN_LIST[$alignKey])) ? '' : '<w:pPr>'.$indentString.(isset(self::ALGN_LIST[$alignKey]) ? '<w:jc w:val="'.self::ALGN_LIST[$alignKey].'"/>' : '').'</w:pPr>')
			.'<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$widthEmu.'" cy="'.$heigtEmu.'"/><wp:docPr id="'.$drwnIdnt.'" name="Picture '.$drwnIdnt.'" descr="'.$infoText.'"/>'
			.'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			.'<pic:pic><pic:nvPicPr><pic:cNvPr id="'.$drwnIdnt.'" name="Picture '.$drwnIdnt.'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$this->pictureLst[$pictName].'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
			.'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$widthEmu.'" cy="'.$heigtEmu.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
			.'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
	}

	/**
	 * Writes a list: a paragraph for each of its entries, which Word
	 * marks or numbers, and further in what each entry holds.
	 *
	 * @param Array $contentBlock The list.
	 * @param Int   $levelValNumb How far in it stands.
	 * @return String The paragraphs it makes.
	 */
	protected function writeListPiece(Array $contentBlock, Int $levelValNumb): String {
		$this->numbersLst[] = !empty($contentBlock['attrs']['order']);
		$numbIdnt = count($this->numbersLst);
		$levelCnt = min(self::LIST_DEEP, $levelValNumb);
		$listText = '';
		foreach((array)($contentBlock['items'] ?? Array()) as $entArr){
			$spanList = (array)($entArr['spans'] ?? Array());
			// A box to tick is written as the sign of one, ticked or not
			if( isset($entArr['attrs']['check']) ){
				array_unshift($spanList, Shape::makeSpanOfText(empty($entArr['attrs']['check']) ? "\u{2610} " : "\u{2611} "));
			}
			$listText .= $this->writeParagraph(Array('style'=>'ListParagraph', 'numbr'=>'<w:numPr><w:ilvl w:val="'.$levelCnt.'"/><w:numId w:val="'.$numbIdnt.'"/></w:numPr>'), $spanList, array_intersect_key((array)($entArr['attrs'] ?? Array()), Array('tagid'=>1)));
			foreach((array)($entArr['items'] ?? Array()) as $subBlk){
				$listText .= (strval($subBlk['block'] ?? '')=='itemList') ? $this->writeListPiece((array)$subBlk, $levelValNumb+1) : $this->writeBlockPart((array)$subBlk, $levelValNumb+1);
			}
		}
		return $listText;
	}

	/**
	 * Writes a table. A cell covering several columns says so; a cell
	 * covering several rows starts an area the cells under it go on with.
	 * The header rows are repeated at the top of each page, and no row is
	 * cut by the end of a page.
	 *
	 * @param Array $contentBlock The table.
	 * @param Int   $nestingDepth How far in it stands.
	 * @return String The table; '' when it holds no row.
	 */
	protected function writeTablePart(Array $contentBlock, Int $nestingDepth): String {
		$rowsText = '';
		$clmnsMax = 0;
		// The areas still running down their columns: column => the rows left to cover and the columns covered
		$openList = Array();
		// The columns of the table, for the room each cell leaves a picture
		$clmnsAll = 1;
		foreach((array)($contentBlock['items'] ?? Array()) as $oneRow){
			$rowClmns = 0;
			foreach((array)($oneRow['items'] ?? Array()) as $celBlk){
				$rowClmns += max(1, min(63, intval($celBlk['attrs']['colsp'] ?? 1)));
			}
			$clmnsAll = max($clmnsAll, $rowClmns);
		}
		$prevRoom = $this->roomLength;
		foreach((array)($contentBlock['items'] ?? Array()) as $oneRow){
			if( !is_array($oneRow) || !in_array(strval($oneRow['block'] ?? ''), Array('tableTop', 'tableRow')) ){
				continue;
			}
			$isTopRow = ($oneRow['block']=='tableTop');
			$cellText = '';
			$clmnIndx = 0;
			$cellList = array_values((array)($oneRow['items'] ?? Array()));
			// An end is added to the cells, so that the cells covered from above ending the row are written like the others
			$cellList[] = null;
			foreach($cellList as $celBlk){
				while( isset($openList[$clmnIndx]) ){
					$cellText .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'.(($openList[$clmnIndx][1]>1) ? '<w:gridSpan w:val="'.$openList[$clmnIndx][1].'"/>' : '').'<w:vMerge/></w:tcPr><w:p/></w:tc>';
					$spanNumb = $openList[$clmnIndx][1];
					if( --$openList[$clmnIndx][0]<1 ){
						unset($openList[$clmnIndx]);
					}
					$clmnIndx += $spanNumb;
				}
				if( !is_array($celBlk) ){
					continue;
				}
				$attrDict = (array)($celBlk['attrs'] ?? Array());
				$clmnSpan = max(1, min(63, intval($attrDict['colsp'] ?? 1)));
				$rowRange = max(1, intval($attrDict['rowsp'] ?? 1));
				if( $rowRange>1 ){
					$openList[$clmnIndx] = Array($rowRange-1, $clmnSpan);
				}
				$isHeader = ($isTopRow || !empty($attrDict['headr']));
				// A picture in a cell is no wider than the cell, nor higher than half a page: a row is never cut by the end of a page
				$this->roomLength = Array(max(9525, intdiv($prevRoom[0]*min($clmnSpan, $clmnsAll), $clmnsAll)-144000), intdiv($prevRoom[1], 2));
				// What the cell holds: its own text, then its other blocks. A cell ends with a paragraph, whatever it holds
				$holdText = (empty($celBlk['spans']) && !empty($celBlk['items'])) ? '' : $this->writeParagraph($isHeader ? Array('style'=>'TableHeading') : Array(), (array)($celBlk['spans'] ?? Array()), $attrDict);
				$holdText .= $this->writeAllBlocks((array)($celBlk['items'] ?? Array()), 0);
				if( substr($holdText, -6)!=='</w:p>' ){
					$holdText .= '<w:p/>';
				}
				$cellText .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'.(($clmnSpan>1) ? '<w:gridSpan w:val="'.$clmnSpan.'"/>' : '').(($rowRange>1) ? '<w:vMerge w:val="restart"/>' : '').($isHeader ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>' : '').'</w:tcPr>'.$holdText.'</w:tc>';
				$this->roomLength = $prevRoom;
				$clmnIndx += $clmnSpan;
			}
			if( $cellText!=='' ){
				$rowsText .= '<w:tr><w:trPr><w:cantSplit/>'.($isTopRow ? '<w:tblHeader/>' : '').'</w:trPr>'.$cellText.'</w:tr>';
				$clmnsMax = max($clmnsMax, $clmnIndx);
			}
		}
		if( $rowsText==='' ){
			return '';
		}
		$brdrText = '';
		foreach(Array('top', 'left', 'bottom', 'right', 'insideH', 'insideV') as $border){
			$brdrText .= '<w:'.$border.' w:val="single" w:sz="4" w:space="0" w:color="808080"/>';
		}
		// The table takes the width of the page
		return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'.(($nestingDepth>0) ? '<w:tblInd w:w="'.(720*$nestingDepth).'" w:type="dxa"/>' : '').'<w:tblBorders>'.$brdrText.'</w:tblBorders>'
			.'<w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
			.'<w:tblGrid>'.str_repeat('<w:gridCol/>', max(1, $clmnsMax)).'</w:tblGrid>'.$rowsText.'</w:tbl>';
	}

	/**
	 * Writes a Word paragraph: its properties, its bookmark when its place is named, then its text.
	 *
	 * @param Array $propertyList What Word is told of it, as written: its style under 'style', its list under 'numbr', its borders under 'bordr', how far in it stands under 'indnt'.
	 * @param Array $textSpanList Its text, as spans.
	 * @param Array $attributeSet What the block tells of it: how it is aligned under 'align', the name of its place under 'tagid'.
	 * @return String The paragraph.
	 */
	protected function writeParagraph(Array $propertyList, Array $textSpanList, Array $attributeSet): String {
		// Word reads what it is told of a paragraph in one order only
		$propText = (isset($propertyList['style']) ? '<w:pStyle w:val="'.$propertyList['style'].'"/>' : '').strval($propertyList['numbr'] ?? '').strval($propertyList['bordr'] ?? '').strval($propertyList['indnt'] ?? '');
		$alignKey = is_scalar($attributeSet['align'] ?? '') ? strval($attributeSet['align'] ?? '') : '';
		if( isset(self::ALGN_LIST[$alignKey]) ){
			$propText .= '<w:jc w:val="'.self::ALGN_LIST[$alignKey].'"/>';
		}
		// A place that is named can be pointed to from the rest of the document; the names of the notes are Word's to give
		$markText = '';
		$markName = $this->wipePlaceTitle(is_scalar($attributeSet['tagid'] ?? '') ? strval($attributeSet['tagid'] ?? '') : '');
		if( $markName!=='' && !in_array($markName, $this->anchorList, true) && !preg_match('/^(fnote|enote)\d+$/D', $markName) ){
			$this->anchorList[] = $markName;
			$markText = '<w:bookmarkStart w:id="'.count($this->anchorList).'" w:name="'.$markName.'"/><w:bookmarkEnd w:id="'.count($this->anchorList).'"/>';
		}
		return '<w:p>'.(($propText==='') ? '' : '<w:pPr>'.$propText.'</w:pPr>').$markText.$this->writeSpansPart($textSpanList).'</w:p>';
	}

	/**
	 * Writes the text of a paragraph: a run for each span, in a link when
	 * the span points to an address, as a field when Word works its text
	 * out, as the reference to a note when it points to one.
	 *
	 * @param Array $textSpanList The spans.
	 * @return String The runs.
	 */
	protected function writeSpansPart(Array $textSpanList): String {
		$runsText = '';
		foreach($textSpanList as $oneSpn){
			if( !is_array($oneSpn) ){
				continue;
			}
			$spanText = strval($oneSpn['texts'] ?? '');
			$markList = (array)($oneSpn['marks'] ?? Array());
			$linkAddr = Shape::wipeUnsafeAddr(is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
			$fieldCod = is_scalar($oneSpn['field'] ?? '') ? strval($oneSpn['field'] ?? '') : '';
			$matxRows = Array();
			if( preg_match('/^#((fnote|enote)\d+)$/D', $linkAddr, $matxRows) && isset($this->remarkList[$matxRows[2]][$matxRows[1]]) ){
				// A note is referred to once, from the text of the document: Word numbers it, and writes its number
				if( !$this->isNoteless && !isset($this->citedNotes[$matxRows[2]][$matxRows[1]]) ){
					$this->citedNotes[$matxRows[2]][$matxRows[1]] = count($this->citedNotes[$matxRows[2]])+1;
					$wordName = ($matxRows[2]=='fnote') ? 'footnote' : 'endnote';
					$runsText .= '<w:r><w:rPr><w:rStyle w:val="'.ucfirst($wordName).'Reference"/></w:rPr><w:'.$wordName.'Reference w:id="'.$this->citedNotes[$matxRows[2]][$matxRows[1]].'"/></w:r>';
					continue;
				}
				// Any other reference to it is the text it shows
				$linkAddr = '';
			}
			// A field: what it asks Word for, around the text it last showed
			$codeText = strval(self::FLDS_LIST[$fieldCod] ?? '');
			$propName = (substr($fieldCod, 0, 6)=='props:') ? trim(strval(preg_replace('/["\\\\\x00-\x1F]+/', '', substr($fieldCod, 6)))) : '';
			if( $codeText==='' && $propName!=='' ){
				$codeText = 'DOCPROPERTY "'.$propName.'"';
			}
			if( $spanText==='' && $codeText==='' ){
				continue;
			}
			$runStrng = $this->writeRunOfText(($spanText==='') ? '1' : $spanText, $markList, ($linkAddr!==''));
			if( $codeText!=='' ){
				$runStrng = '<w:fldSimple w:instr=" '.$this->escapeXmlValue($codeText).' ">'.$runStrng.'</w:fldSimple>';
			}
			if( $linkAddr==='' ){
				$runsText .= $runStrng;
				continue;
			}
			// A link to a place of the document names that place; any other is kept among the relations of the part
			if( $linkAddr[0]=='#' ){
				$markName = $this->wipePlaceTitle(substr($linkAddr, 1));
				$runsText .= ($markName==='') ? $runStrng : '<w:hyperlink w:anchor="'.$markName.'" w:history="1">'.$runStrng.'</w:hyperlink>';
				continue;
			}
			if( !isset($this->knownLinks[$linkAddr]) ){
				$this->knownLinks[$linkAddr] = 'rLk'.(count($this->knownLinks)+1);
			}
			$runsText .= '<w:hyperlink r:id="'.$this->knownLinks[$linkAddr].'" w:history="1">'.$runStrng.'</w:hyperlink>';
		}
		return $runsText;
	}

	/**
	 * Writes a run of text, with its formatting. Its line ends and its
	 * tabs are written as what they are, its spaces are all kept.
	 *
	 * @param String $sourceString The text.
	 * @param Array  $runFormatSet Its formatting, among boldFace, emphasis, undrLine, crossOut and codeSpan.
	 * @param Bool   $isInsideLink Whether the run stands in a link, and is shown as one.
	 * @return String The run.
	 */
	protected function writeRunOfText(String $sourceString, Array $runFormatSet, Bool $isInsideLink): String {
		// Word reads what it is told of a run in one order only
		$propText = ($isInsideLink? '<w:rStyle w:val="Hyperlink"/>' : '')
			.(in_array('codeSpan', $runFormatSet) ? '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/>' : '')
			.(in_array('boldFace', $runFormatSet) ? '<w:b/><w:bCs/>' : '').(in_array('emphasis', $runFormatSet) ? '<w:i/><w:iCs/>' : '')
			.(in_array('crossOut', $runFormatSet) ? '<w:strike/>' : '').(in_array('undrLine', $runFormatSet) ? '<w:u w:val="single"/>' : '');
		$textText = '';
		foreach(preg_split('/(\n|\t)/', str_replace(Array("\r\n", "\r"), "\n", $sourceString), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			$textText .= ($onePrt==="\n") ? '<w:br/>' : (($onePrt==="\t") ? '<w:tab/>' : '<w:t xml:space="preserve">'.$this->escapeXmlValue($onePrt).'</w:t>');
		}
		return '<w:r>'.(($propText==='') ? '' : '<w:rPr>'.$propText.'</w:rPr>').$textText.'</w:r>';
	}

}
