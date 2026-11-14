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
	protected $notesMuted = false;
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
		$shapeAs = $this->checkShapeText($dataEnvelope, Array('block', 'sheet'), 'a document');
		$sizeSet = $this->paperSizeArray($writeOptions);
		// A picture is no wider nor higher than the text of a page: a twentieth of a point is 635 of Word's units
		$this->roomLength = Array(($sizeSet[0]-2880)*635, ($sizeSet[1]-2880-1440)*635);
		$this->readPictureDir($writeOptions);
		$this->pictureLst = Array();
		$this->mediaFiles = Array();
		$infoArr = (array)($dataEnvelope['infos'] ?? Array());
		$blckLst = ($shapeAs=='sheet') ? $this->sheetsAsBlocks((array)($dataEnvelope['items'] ?? Array())) : (array)($dataEnvelope['items'] ?? Array());
		$this->knownLinks = Array();
		$this->numbersLst = Array();
		$this->anchorList = Array();
		$this->citedNotes = Array('fnote'=>Array(), 'enote'=>Array());
		// The notes are taken out of the text: each is written where the text refers to it
		$this->remarkList = $this->takeNotesArray($blckLst);
		$stylTxt = $this->stylePieceText((array)($infoArr['frmat'] ?? Array()), $blckLst);
		$this->notesMuted = false;
		$bodyTxt = $this->writeAllBlocks($blckLst, 0);
		// Only the text of the document refers to notes: what follows writes a reference as the text it is
		$this->notesMuted = true;
		$linkLst = Array($this->knownLinks, $this->pictureLst);
		$headXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
		$rootTxt = ' xmlns:w="'.self::MAIN_NAME.'" xmlns:r="'.self::RELS_NAME.'" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
		$moreLst = Array();
		$typeTxt = '';
		$relsTxt = '<Relationship Id="rId1" Type="'.self::RELS_NAME.'/styles" Target="styles.xml"/><Relationship Id="rId2" Type="'.self::RELS_NAME.'/numbering" Target="numbering.xml"/>';
		$sctnTxt = '';
		// What stands at the top and at the bottom of the pages: a part each, with the addresses it points to
		foreach(Array('phead'=>Array('header', 'w:hdr', 'rId3'), 'pfoot'=>Array('footer', 'w:ftr', 'rId4')) as $infKey=>$pgeSet){
			if( empty($infoArr[$infKey]) || !is_array($infoArr[$infKey]) ){
				continue;
			}
			$this->knownLinks = Array();
			$this->pictureLst = Array();
			$pageTxt = $this->writeAllBlocks($infoArr[$infKey], 0);
			$moreLst['word/'.$pgeSet[0].'1.xml'] = $headXml.'<'.$pgeSet[1].$rootTxt.'>'.(($pageTxt==='' || substr($pageTxt, -8)=='</w:tbl>') ? $pageTxt.'<w:p/>' : $pageTxt).'</'.$pgeSet[1].'>';
			$moreLst['word/_rels/'.$pgeSet[0].'1.xml.rels'] = $headXml.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$this->writeRelations().'</Relationships>';
			$typeTxt .= '<Override PartName="/word/'.$pgeSet[0].'1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.'.$pgeSet[0].'+xml"/>';
			$relsTxt .= '<Relationship Id="'.$pgeSet[2].'" Type="'.self::RELS_NAME.'/'.$pgeSet[0].'" Target="'.$pgeSet[0].'1.xml"/>';
			$sctnTxt .= '<w:'.$pgeSet[0].'Reference w:type="default" r:id="'.$pgeSet[2].'"/>';
		}
		// The notes the text referred to: those of the pages, those of the end
		$lostLst = Array();
		foreach(Array('fnote'=>Array('footnote', 'rId5'), 'enote'=>Array('endnote', 'rId6')) as $nteKnd=>$nteSet){
			$lostLst = array_merge($lostLst, array_values(array_diff_key($this->remarkList[$nteKnd], $this->citedNotes[$nteKnd])));
			if( empty($this->citedNotes[$nteKnd]) ){
				continue;
			}
			$this->knownLinks = Array();
			$this->pictureLst = Array();
			$moreLst['word/'.$nteSet[0].'s.xml'] = $headXml.'<w:'.$nteSet[0].'s'.$rootTxt.'>'.$this->notesPieceText($nteKnd, $nteSet[0]).'</w:'.$nteSet[0].'s>';
			$moreLst['word/_rels/'.$nteSet[0].'s.xml.rels'] = $headXml.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$this->writeRelations().'</Relationships>';
			$typeTxt .= '<Override PartName="/word/'.$nteSet[0].'s.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.'.$nteSet[0].'s+xml"/>';
			$relsTxt .= '<Relationship Id="'.$nteSet[1].'" Type="'.self::RELS_NAME.'/'.$nteSet[0].'s" Target="'.$nteSet[0].'s.xml"/>';
		}
		// A note the text never referred to is not lost: it ends the document, as text
		$this->knownLinks = $linkLst[0];
		$this->pictureLst = $linkLst[1];
		foreach($lostLst as $blkArr){
			$bodyTxt .= $this->blckPartString(Array('block'=>'paragrph') + $blkArr, 0);
		}
		$relsTxt .= $this->writeRelations();
		// The properties: those Word keeps a place for, then the others
		$propLst = (array)($infoArr['props'] ?? Array());
		foreach(Array('title'=>'Title', 'authr'=>'Author') as $infKey=>$prpNme){
			if( is_scalar($infoArr[$infKey] ?? null) && strval($infoArr[$infKey])!=='' ){
				$propLst[$prpNme] = strval($infoArr[$infKey]);
			}
		}
		$coreTxt = '';
		foreach(self::PROP_LIST as $prpNme=>$xmlTag){
			$coreTxt .= (is_scalar($propLst[$prpNme] ?? null) && strval($propLst[$prpNme])!=='') ? '<'.$xmlTag.'>'.$this->escapeXmlValue(strval($propLst[$prpNme])).'</'.$xmlTag.'>' : '';
		}
		$cstmTxt = $this->propsPieceText(array_diff_key($propLst, self::PROP_LIST));
		// The pictures, each written once, and the kinds of file they are
		foreach($this->mediaFiles as $medSet){
			$moreLst['word/'.$medSet[0]] = $medSet[1];
			$fileExt = substr($medSet[0], intval(strrpos($medSet[0], '.'))+1);
			if( strpos($typeTxt, 'Extension="'.$fileExt.'"')===false ){
				$typeTxt = '<Default Extension="'.$fileExt.'" ContentType="'.self::PICT_LIST[($fileExt=='jpeg') ? 'jpg' : $fileExt].'"/>'.$typeTxt;
			}
		}
		$fileLst = Array(
			'[Content_Types].xml'=>$headXml.'<Types xmlns="'.self::PACK_NAME.'/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.$typeTxt
				.'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
				.'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
				.'<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
				.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
				.(($cstmTxt==='') ? '' : '<Override PartName="/docProps/custom.xml" ContentType="application/vnd.openxmlformats-officedocument.custom-properties+xml"/>').'</Types>',
			'_rels/.rels'=>$headXml.'<Relationships xmlns="'.self::PACK_NAME.'/relationships"><Relationship Id="rId1" Type="'.self::RELS_NAME.'/officeDocument" Target="word/document.xml"/>'
				.'<Relationship Id="rId2" Type="'.self::PACK_NAME.'/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
				.(($cstmTxt==='') ? '' : '<Relationship Id="rId3" Type="'.self::RELS_NAME.'/custom-properties" Target="docProps/custom.xml"/>').'</Relationships>',
			'docProps/core.xml'=>$headXml.'<cp:coreProperties xmlns:cp="'.self::PACK_NAME.'/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				.$coreTxt.'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>'
		) + (($cstmTxt==='') ? Array() : Array('docProps/custom.xml'=>$headXml.$cstmTxt)) + Array(
			'word/document.xml'=>$headXml.'<w:document'.$rootTxt.'><w:body>'.$bodyTxt.((substr($bodyTxt, -8)=='</w:tbl>') ? '<w:p/>' : '')
				.'<w:sectPr>'.$sctnTxt.'<w:pgSz w:w="'.$sizeSet[0].'" w:h="'.$sizeSet[1].'"'.(empty($writeOptions['lands']) ? '' : ' w:orient="landscape"').'/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>',
			'word/_rels/document.xml.rels'=>$headXml.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$relsTxt.'</Relationships>',
			'word/styles.xml'=>$headXml.$stylTxt,
			'word/numbering.xml'=>$headXml.$this->numberFragment()
		) + $moreLst;
		try{
			return Archv::newBytesString($fileLst);
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
		$relsTxt = '';
		foreach($this->knownLinks as $lnkAdr=>$linkId){
			$relsTxt .= '<Relationship Id="'.$linkId.'" Type="'.self::RELS_NAME.'/hyperlink" Target="'.$this->escapeXmlValue($lnkAdr).'" TargetMode="External"/>';
		}
		foreach($this->pictureLst as $picKey=>$linkId){
			$relsTxt .= '<Relationship Id="'.$linkId.'" Type="'.self::RELS_NAME.'/image" Target="'.$picKey.'"/>';
		}
		return $relsTxt;
	}

	/**
	 * Writes the properties of the document Word keeps no place for,
	 * each with the kind of its value.
	 *
	 * @param Array $propertyList The properties, name => value.
	 * @return String The part holding them, '' when there is none.
	 */
	protected function propsPieceText(Array $propertyList): String {
		$propTxt = '';
		$propNum = 1;
		foreach($propertyList as $prpNme=>$prpVal){
			$nameTxt = trim(strval($prpNme));
			if( $nameTxt==='' || !is_scalar($prpVal) || (is_float($prpVal) && !is_finite($prpVal)) ){
				continue;
			}
			if( is_bool($prpVal) ){
				$valText = '<vt:bool>'.($prpVal ? 'true' : 'false').'</vt:bool>';
			}elseif( is_int($prpVal) ){
				$valText = (abs($prpVal)<2147483648) ? '<vt:i4>'.$prpVal.'</vt:i4>' : '<vt:i8>'.$prpVal.'</vt:i8>';
			}elseif( is_float($prpVal) ){
				$valText = '<vt:r8>'.$this->writeNumberTxt($prpVal).'</vt:r8>';
			}else{
				$valText = '<vt:lpwstr>'.$this->escapeXmlValue($prpVal).'</vt:lpwstr>';
			}
			$propNum++;
			$propTxt .= '<property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="'.$propNum.'" name="'.$this->escapeXmlValue($nameTxt).'">'.$valText.'</property>';
		}
		return ($propTxt==='') ? '' : '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'.$propTxt.'</Properties>';
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
	protected function stylePieceText(Array $styleFormats, Array $bookBlockSet): String {
		$stylTxt = '<w:styles xmlns:w="'.self::MAIN_NAME.'"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
			.'<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
			.'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			.'<w:style w:type="character" w:default="1" w:styleId="DefaultParagraphFont"><w:name w:val="Default Paragraph Font"/><w:uiPriority w:val="1"/><w:semiHidden/></w:style>';
		foreach(self::HEAD_SIZE as $hdrLvl=>$sizeNb){
			$stylTxt .= '<w:style w:type="paragraph" w:styleId="Heading'.$hdrLvl.'"><w:name w:val="heading '.$hdrLvl.'"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
				.'<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="'.(($hdrLvl==1) ? 360 : 200).'" w:after="80"/><w:outlineLvl w:val="'.($hdrLvl-1).'"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="'.($sizeNb*2).'"/><w:szCs w:val="'.($sizeNb*2).'"/></w:rPr></w:style>';
		}
		$stylTxt .= '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="34"/><w:qFormat/><w:pPr><w:contextualSpacing/><w:ind w:left="720"/></w:pPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="29"/><w:qFormat/><w:pPr><w:spacing w:before="160"/><w:ind w:left="720" w:right="720"/></w:pPr><w:rPr><w:i/><w:iCs/><w:color w:val="404040"/></w:rPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="HTMLPreformatted"><w:name w:val="HTML Preformatted"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="TableHeading"><w:name w:val="Table Heading"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:spacing w:after="0"/></w:pPr><w:rPr><w:b/><w:bCs/></w:rPr></w:style>'
			.'<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:basedOn w:val="DefaultParagraphFont"/><w:uiPriority w:val="99"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style>';
		foreach(Array('Footnote'=>'footnote', 'Endnote'=>'endnote') as $noteId=>$nteNme){
			$stylTxt .= '<w:style w:type="paragraph" w:styleId="'.$noteId.'Text"><w:name w:val="'.$nteNme.' text"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
				.'<w:style w:type="character" w:styleId="'.$noteId.'Reference"><w:name w:val="'.$nteNme.' reference"/><w:basedOn w:val="DefaultParagraphFont"/><w:uiPriority w:val="99"/><w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>';
		}
		// The styles the paragraphs name: those the content tells of, and those it only names
		$this->styleTable = Array();
		$nameLst = array_map('strval', array_keys($styleFormats));
		$visitor = function(Array $blocksToWalk) use (&$visitor, &$nameLst){
			foreach($blocksToWalk as $blkArr){
				if( is_array($blkArr) ){
					$nameLst[] = (strval($blkArr['block'] ?? '')=='paragrph' && is_scalar($blkArr['attrs']['style'] ?? null)) ? strval($blkArr['attrs']['style']) : '';
					$visitor((array)($blkArr['items'] ?? Array()));
				}
			}
		};
		$visitor($bookBlockSet);
		foreach($nameLst as $oneNme){
			$nameKey = strtolower(trim($oneNme));
			if( $nameKey==='' || in_array($nameKey, self::OWNS_STYL) || isset($this->styleTable[$nameKey]) ){
				continue;
			}
			$stylSet = (array)($styleFormats[$oneNme] ?? Array());
			$styleId = 'nyu'.(count($this->styleTable)+1).substr(strval(preg_replace('/[^A-Za-z0-9]+/', '', $oneNme)), 0, 20);
			$this->styleTable[$nameKey] = $styleId;
			$markLst = (array)($stylSet['marks'] ?? Array());
			$fontTxt = is_scalar($stylSet['fonts'] ?? '') ? $this->escapeXmlValue(substr(trim(strval($stylSet['fonts'] ?? '')), 0, 60)) : '';
			$sizHalf = is_numeric($stylSet['sizes'] ?? null) ? intval(round(max(1, min(400, floatval($stylSet['sizes'])))*2)) : 0;
			$hexCode = Array();
			$runsTxt = (($fontTxt==='') ? '' : '<w:rFonts w:ascii="'.$fontTxt.'" w:hAnsi="'.$fontTxt.'" w:cs="'.$fontTxt.'"/>')
				.(in_array('boldFace', $markLst) ? '<w:b/><w:bCs/>' : '').(in_array('emphasis', $markLst) ? '<w:i/><w:iCs/>' : '').(in_array('crossOut', $markLst) ? '<w:strike/>' : '')
				.(preg_match('/^#?([0-9A-Fa-f]{6})$/D', is_scalar($stylSet['color'] ?? '') ? strval($stylSet['color'] ?? '') : '', $hexCode) ? '<w:color w:val="'.strtoupper($hexCode[1]).'"/>' : '')
				.(($sizHalf>0) ? '<w:sz w:val="'.$sizHalf.'"/><w:szCs w:val="'.$sizHalf.'"/>' : '').(in_array('undrLine', $markLst) ? '<w:u w:val="single"/>' : '');
			$algnKey = is_scalar($stylSet['align'] ?? '') ? strval($stylSet['align'] ?? '') : '';
			$stylTxt .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="'.$styleId.'"><w:name w:val="'.$this->escapeXmlValue(substr(trim($oneNme), 0, 100)).'"/><w:basedOn w:val="Normal"/><w:qFormat/>'
				.(isset(self::ALGN_LIST[$algnKey]) ? '<w:pPr><w:jc w:val="'.self::ALGN_LIST[$algnKey].'"/></w:pPr>' : '').(($runsTxt==='') ? '' : '<w:rPr>'.$runsTxt.'</w:rPr>').'</w:style>';
		}
		return $stylTxt.'</w:styles>';
	}

	/**
	 * Writes the lists of the document: what a marked one and a numbered
	 * one look like at each level, then every list met, which starts its
	 * numbers again.
	 *
	 * @return String The part holding the lists.
	 */
	protected function numberFragment(): String {
		$numbTxt = '<w:numbering xmlns:w="'.self::MAIN_NAME.'">';
		foreach(Array(0=>Array('bullet', Array("\u{2022}", "\u{25E6}", "\u{25AA}")), 1=>Array('', Array('decimal', 'lowerLetter', 'lowerRoman'))) as $listId=>$absSet){
			$numbTxt .= '<w:abstractNum w:abstractNumId="'.$listId.'"><w:multiLevelType w:val="hybridMultilevel"/>';
			for($lvlIdx=0; $lvlIdx<=self::LIST_DEEP; $lvlIdx++){
				$numbTxt .= '<w:lvl w:ilvl="'.$lvlIdx.'"><w:start w:val="1"/><w:numFmt w:val="'.(($listId==0) ? 'bullet' : $absSet[1][$lvlIdx%3]).'"/><w:lvlText w:val="'.(($listId==0) ? $absSet[1][$lvlIdx%3] : '%'.($lvlIdx+1).'.').'"/><w:lvlJc w:val="left"/>'
					.'<w:pPr><w:ind w:left="'.(720*($lvlIdx+1)).'" w:hanging="360"/></w:pPr></w:lvl>';
			}
			$numbTxt .= '</w:abstractNum>';
		}
		foreach($this->numbersLst as $numIdx=>$isOrdr){
			$numbTxt .= '<w:num w:numId="'.($numIdx+1).'"><w:abstractNumId w:val="'.($isOrdr ? 1 : 0).'"/>';
			for($lvlIdx=0; $isOrdr && $lvlIdx<=self::LIST_DEEP; $lvlIdx++){
				$numbTxt .= '<w:lvlOverride w:ilvl="'.$lvlIdx.'"><w:startOverride w:val="1"/></w:lvlOverride>';
			}
			$numbTxt .= '</w:num>';
		}
		return $numbTxt.'</w:numbering>';
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
	protected function notesPieceText(String $noteTypeCode, String $wordNoteName): String {
		$noteTxt = '<w:'.$wordNoteName.' w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:'.$wordNoteName.'><w:'.$wordNoteName.' w:type="continuationSeparator" w:id="0"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:'.$wordNoteName.'>';
		$styleId = ucfirst($wordNoteName);
		foreach($this->citedNotes[$noteTypeCode] as $noteId=>$noteNb){
			$noteArr = $this->remarkList[$noteTypeCode][$noteId];
			$spanLst = array_values((array)($noteArr['spans'] ?? Array()));
			// The number of the note, a space, then its text: the space is Word's, not the note's
			if( isset($spanLst[0]['texts']) ){
				$spanLst[0]['texts'] = ltrim(strval($spanLst[0]['texts']));
			}
			$noteTxt .= '<w:'.$wordNoteName.' w:id="'.$noteNb.'"><w:p><w:pPr><w:pStyle w:val="'.$styleId.'Text"/></w:pPr><w:r><w:rPr><w:rStyle w:val="'.$styleId.'Reference"/></w:rPr><w:'.$wordNoteName.'Ref/></w:r><w:r><w:t xml:space="preserve"> </w:t></w:r>'
				.$this->spansPieceText($spanLst).'</w:p>'.$this->writeAllBlocks((array)($noteArr['items'] ?? Array()), 0);
			// A note ends with a paragraph, as a cell does
			$noteTxt .= ((substr($noteTxt, -8)=='</w:tbl>') ? '<w:p/>' : '').'</w:'.$wordNoteName.'>';
		}
		return $noteTxt;
	}

	/**
	 * Writes blocks, one after the other.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @param Int   $indentStepNb How far in they stand: 0 for the text of the document, one more for what an entry of a list holds.
	 * @return String The paragraphs they make.
	 */
	protected function writeAllBlocks(Array $bookBlockSet, Int $indentStepNb): String {
		$bodyTxt = '';
		foreach($bookBlockSet as $blkArr){
			if( is_array($blkArr) && isset($blkArr['block']) ){
				$partTxt = $this->blckPartString($blkArr, $indentStepNb);
				// A paragraph stands between two tables, without which they would become one
				$bodyTxt .= ((substr($bodyTxt, -8)=='</w:tbl>' && substr($partTxt, 0, 7)=='<w:tbl>') ? '<w:p/>' : '').$partTxt;
			}
		}
		return $bodyTxt;
	}

	/**
	 * Writes a block as the Word paragraphs of its kind: heading, list, table, quote, code, rule, picture or paragraph.
	 *
	 * @param Array $contentBlock The block.
	 * @param Int   $indentStepNb How far in it stands.
	 * @return String The paragraphs it makes.
	 */
	protected function blckPartString(Array $contentBlock, Int $indentStepNb): String {
		$blckKnd = strval($contentBlock['block']);
		$attrSet = (array)($contentBlock['attrs'] ?? Array());
		$spanLst = (array)($contentBlock['spans'] ?? Array());
		$itemLst = (array)($contentBlock['items'] ?? Array());
		$indents = ($indentStepNb>0) ? '<w:ind w:left="'.(720*$indentStepNb).'"/>' : '';
		if( $blckKnd=='headline' ){
			return $this->paragraphPiece(Array('style'=>'Heading'.max(1, min(6, intval($attrSet['level'] ?? 1))), 'indnt'=>$indents), $spanLst, $attrSet);
		}
		if( $blckKnd=='itemList' ){
			return $this->listsPieceText($contentBlock, $indentStepNb);
		}
		if( $blckKnd=='tableSet' ){
			return $this->tblePartString($contentBlock, $indentStepNb);
		}
		if( $blckKnd=='citation' ){
			$qutText = $this->paragraphPiece(Array('style'=>'Quote'), $spanLst, $attrSet).$this->writeAllBlocks($itemLst, $indentStepNb+1);
			// Who is quoted follows the quote
			$citeTxt = is_scalar($attrSet['cites'] ?? '') ? trim(strval($attrSet['cites'] ?? '')) : '';
			return $qutText.(($citeTxt==='') ? '' : $this->paragraphPiece(Array('style'=>'Quote'), Array(Shape::makeASpanArray("\u{2014} ".$citeTxt)), Array('align'=>'alignRgt')));
		}
		if( $blckKnd=='codeBlck' ){
			// A paragraph for each line of code, their spaces kept
			$codeTxt = '';
			foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", implode('', array_column($spanLst, 'texts')))) as $oneLin){
				$codeTxt .= $this->paragraphPiece(Array('style'=>'HTMLPreformatted', 'indnt'=>$indents), Array(Shape::makeASpanArray($oneLin)), Array());
			}
			return $codeTxt;
		}
		if( $blckKnd=='ruleLine' ){
			// A page ends here, or a line is drawn across it
			if( !empty($attrSet['break']) ){
				return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
			}
			return $this->paragraphPiece(Array('bordr'=>'<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr>'), Array(), Array());
		}
		if( $blckKnd=='imageBox' ){
			$pctrTxt = $this->picturePortion($contentBlock, $indents);
			if( $pctrTxt!=='' ){
				return $pctrTxt;
			}
		}
		if( $blckKnd=='tableTop' || $blckKnd=='tableRow' ){
			// A row met outside a table is a table of that row
			return $this->tblePartString(Array('items'=>Array($contentBlock)), $indentStepNb);
		}
		// A paragraph, with the style it names; and what stands for a picture that is not written: the text that describes it
		$styleId = ($blckKnd=='paragrph' && is_scalar($attrSet['style'] ?? null)) ? strval($this->styleTable[strtolower(trim(strval($attrSet['style'])))] ?? '') : '';
		return $this->paragraphPiece(Array('indnt'=>$indents) + (($styleId==='') ? Array() : Array('style'=>$styleId)), $spanLst, $attrSet).$this->writeAllBlocks($itemLst, $indentStepNb);
	}

	/**
	 * Writes a picture into the document, when its file is in the folder
	 * the document was given.
	 *
	 * @param Array  $contentBlock The image: the name of its file under 'sourc', and the text that describes it.
	 * @param String $indentString How far in it stands, as Word is told.
	 * @return String The paragraph showing the picture; '' when the picture is not written.
	 */
	protected function picturePortion(Array $contentBlock, String $indentString): String {
		$pctrSet = $this->pictureReadArr(is_scalar($contentBlock['attrs']['sourc'] ?? '') ? strval($contentBlock['attrs']['sourc'] ?? '') : '');
		if( $pctrSet===null ){
			return '';
		}
		$pctrTxt = $pctrSet['bytes'];
		$givKind = $pctrSet['kinds'];
		$widePxl = $pctrSet['width'];
		$highPxl = $pctrSet['heght'];
		// The same picture is written once, however often it is shown
		$pctrKey = sha1($pctrTxt);
		if( !isset($this->mediaFiles[$pctrKey]) ){
			if( array_sum(array_map(function(Array $mediaItemArr){ return strlen($mediaItemArr[1]); }, $this->mediaFiles))+strlen($pctrTxt)>self::MEDI_MAXI ){
				return '';
			}
			$this->mediaFiles[$pctrKey] = Array('media/image'.(count($this->mediaFiles)+1).'.'.(($givKind=='jpg') ? 'jpeg' : $givKind), $pctrTxt);
		}
		$pctrNme = $this->mediaFiles[$pctrKey][0];
		if( !isset($this->pictureLst[$pctrNme]) ){
			$this->pictureLst[$pctrNme] = 'rIm'.(count($this->pictureLst)+1);
		}
		// A pixel is 9525 of Word's units; a picture larger than the text of a page is brought down to it, its proportions kept
		$sizRati = min(1, $this->roomLength[0]/($widePxl*9525), $this->roomLength[1]/($highPxl*9525));
		$wideEmu = max(1, intval(round($widePxl*9525*$sizRati)));
		$highEmu = max(1, intval(round($highPxl*9525*$sizRati)));
		$this->anchorList[] = '';
		$drwIdnt = count($this->anchorList);
		$infoTxt = $this->escapeXmlValue(trim(implode('', array_column((array)($contentBlock['spans'] ?? Array()), 'texts'))));
		$algnKey = is_scalar($contentBlock['attrs']['align'] ?? '') ? strval($contentBlock['attrs']['align'] ?? '') : '';
		return '<w:p>'.(($indentString==='' && !isset(self::ALGN_LIST[$algnKey])) ? '' : '<w:pPr>'.$indentString.(isset(self::ALGN_LIST[$algnKey]) ? '<w:jc w:val="'.self::ALGN_LIST[$algnKey].'"/>' : '').'</w:pPr>')
			.'<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$wideEmu.'" cy="'.$highEmu.'"/><wp:docPr id="'.$drwIdnt.'" name="Picture '.$drwIdnt.'" descr="'.$infoTxt.'"/>'
			.'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			.'<pic:pic><pic:nvPicPr><pic:cNvPr id="'.$drwIdnt.'" name="Picture '.$drwIdnt.'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$this->pictureLst[$pctrNme].'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
			.'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$wideEmu.'" cy="'.$highEmu.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
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
	protected function listsPieceText(Array $contentBlock, Int $levelValNumb): String {
		$this->numbersLst[] = !empty($contentBlock['attrs']['order']);
		$numIdnt = count($this->numbersLst);
		$levelNb = min(self::LIST_DEEP, $levelValNumb);
		$listTxt = '';
		foreach((array)($contentBlock['items'] ?? Array()) as $entArr){
			$spanLst = (array)($entArr['spans'] ?? Array());
			// A box to tick is written as the sign of one, ticked or not
			if( isset($entArr['attrs']['check']) ){
				array_unshift($spanLst, Shape::makeASpanArray(empty($entArr['attrs']['check']) ? "\u{2610} " : "\u{2611} "));
			}
			$listTxt .= $this->paragraphPiece(Array('style'=>'ListParagraph', 'numbr'=>'<w:numPr><w:ilvl w:val="'.$levelNb.'"/><w:numId w:val="'.$numIdnt.'"/></w:numPr>'), $spanLst, array_intersect_key((array)($entArr['attrs'] ?? Array()), Array('tagid'=>1)));
			foreach((array)($entArr['items'] ?? Array()) as $subBlk){
				$listTxt .= (strval($subBlk['block'] ?? '')=='itemList') ? $this->listsPieceText((array)$subBlk, $levelValNumb+1) : $this->blckPartString((array)$subBlk, $levelValNumb+1);
			}
		}
		return $listTxt;
	}

	/**
	 * Writes a table. A cell covering several columns says so; a cell
	 * covering several rows starts an area the cells under it go on with.
	 * The header rows are repeated at the top of each page, and no row is
	 * cut by the end of a page.
	 *
	 * @param Array $contentBlock The table.
	 * @param Int   $indentStepNb How far in it stands.
	 * @return String The table; '' when it holds no row.
	 */
	protected function tblePartString(Array $contentBlock, Int $indentStepNb): String {
		$rowsTxt = '';
		$colsMax = 0;
		// The areas still running down their columns: column => the rows left to cover and the columns covered
		$openLst = Array();
		// The columns of the table, for the room each cell leaves a picture
		$colsAll = 1;
		foreach((array)($contentBlock['items'] ?? Array()) as $oneRow){
			$rowCols = 0;
			foreach((array)($oneRow['items'] ?? Array()) as $celBlk){
				$rowCols += max(1, min(63, intval($celBlk['attrs']['colsp'] ?? 1)));
			}
			$colsAll = max($colsAll, $rowCols);
		}
		$roomWas = $this->roomLength;
		foreach((array)($contentBlock['items'] ?? Array()) as $oneRow){
			if( !is_array($oneRow) || !in_array(strval($oneRow['block'] ?? ''), Array('tableTop', 'tableRow')) ){
				continue;
			}
			$isHeadr = ($oneRow['block']=='tableTop');
			$cellTxt = '';
			$clmnIdx = 0;
			$cellLst = array_values((array)($oneRow['items'] ?? Array()));
			// An end is added to the cells, so that the cells covered from above ending the row are written like the others
			$cellLst[] = null;
			foreach($cellLst as $celBlk){
				while( isset($openLst[$clmnIdx]) ){
					$cellTxt .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'.(($openLst[$clmnIdx][1]>1) ? '<w:gridSpan w:val="'.$openLst[$clmnIdx][1].'"/>' : '').'<w:vMerge/></w:tcPr><w:p/></w:tc>';
					$spanNum = $openLst[$clmnIdx][1];
					if( --$openLst[$clmnIdx][0]<1 ){
						unset($openLst[$clmnIdx]);
					}
					$clmnIdx += $spanNum;
				}
				if( !is_array($celBlk) ){
					continue;
				}
				$attrSet = (array)($celBlk['attrs'] ?? Array());
				$clmnSpn = max(1, min(63, intval($attrSet['colsp'] ?? 1)));
				$rowSpan = max(1, intval($attrSet['rowsp'] ?? 1));
				if( $rowSpan>1 ){
					$openLst[$clmnIdx] = Array($rowSpan-1, $clmnSpn);
				}
				$asHeadr = ($isHeadr || !empty($attrSet['headr']));
				// A picture in a cell is no wider than the cell, nor higher than half a page: a row is never cut by the end of a page
				$this->roomLength = Array(max(9525, intdiv($roomWas[0]*min($clmnSpn, $colsAll), $colsAll)-144000), intdiv($roomWas[1], 2));
				// What the cell holds: its own text, then its other blocks. A cell ends with a paragraph, whatever it holds
				$holdTxt = (empty($celBlk['spans']) && !empty($celBlk['items'])) ? '' : $this->paragraphPiece($asHeadr ? Array('style'=>'TableHeading') : Array(), (array)($celBlk['spans'] ?? Array()), $attrSet);
				$holdTxt .= $this->writeAllBlocks((array)($celBlk['items'] ?? Array()), 0);
				if( substr($holdTxt, -6)!=='</w:p>' ){
					$holdTxt .= '<w:p/>';
				}
				$cellTxt .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'.(($clmnSpn>1) ? '<w:gridSpan w:val="'.$clmnSpn.'"/>' : '').(($rowSpan>1) ? '<w:vMerge w:val="restart"/>' : '').($asHeadr ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>' : '').'</w:tcPr>'.$holdTxt.'</w:tc>';
				$this->roomLength = $roomWas;
				$clmnIdx += $clmnSpn;
			}
			if( $cellTxt!=='' ){
				$rowsTxt .= '<w:tr><w:trPr><w:cantSplit/>'.($isHeadr ? '<w:tblHeader/>' : '').'</w:trPr>'.$cellTxt.'</w:tr>';
				$colsMax = max($colsMax, $clmnIdx);
			}
		}
		if( $rowsTxt==='' ){
			return '';
		}
		$brdrTxt = '';
		foreach(Array('top', 'left', 'bottom', 'right', 'insideH', 'insideV') as $border){
			$brdrTxt .= '<w:'.$border.' w:val="single" w:sz="4" w:space="0" w:color="808080"/>';
		}
		// The table takes the width of the page
		return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'.(($indentStepNb>0) ? '<w:tblInd w:w="'.(720*$indentStepNb).'" w:type="dxa"/>' : '').'<w:tblBorders>'.$brdrTxt.'</w:tblBorders>'
			.'<w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
			.'<w:tblGrid>'.str_repeat('<w:gridCol/>', max(1, $colsMax)).'</w:tblGrid>'.$rowsTxt.'</w:tbl>';
	}

	/**
	 * Writes a Word paragraph: its properties, its bookmark when its place is named, then its text.
	 *
	 * @param Array $propertyList What Word is told of it, as written: its style under 'style', its list under 'numbr', its borders under 'bordr', how far in it stands under 'indnt'.
	 * @param Array $textSpanList Its text, as spans.
	 * @param Array $attributeSet What the block tells of it: how it is aligned under 'align', the name of its place under 'tagid'.
	 * @return String The paragraph.
	 */
	protected function paragraphPiece(Array $propertyList, Array $textSpanList, Array $attributeSet): String {
		// Word reads what it is told of a paragraph in one order only
		$propTxt = (isset($propertyList['style']) ? '<w:pStyle w:val="'.$propertyList['style'].'"/>' : '').strval($propertyList['numbr'] ?? '').strval($propertyList['bordr'] ?? '').strval($propertyList['indnt'] ?? '');
		$algnKey = is_scalar($attributeSet['align'] ?? '') ? strval($attributeSet['align'] ?? '') : '';
		if( isset(self::ALGN_LIST[$algnKey]) ){
			$propTxt .= '<w:jc w:val="'.self::ALGN_LIST[$algnKey].'"/>';
		}
		// A place that is named can be pointed to from the rest of the document; the names of the notes are Word's to give
		$markTxt = '';
		$markNme = $this->cleanPlaceName(is_scalar($attributeSet['tagid'] ?? '') ? strval($attributeSet['tagid'] ?? '') : '');
		if( $markNme!=='' && !in_array($markNme, $this->anchorList, true) && !preg_match('/^(fnote|enote)\d+$/D', $markNme) ){
			$this->anchorList[] = $markNme;
			$markTxt = '<w:bookmarkStart w:id="'.count($this->anchorList).'" w:name="'.$markNme.'"/><w:bookmarkEnd w:id="'.count($this->anchorList).'"/>';
		}
		return '<w:p>'.(($propTxt==='') ? '' : '<w:pPr>'.$propTxt.'</w:pPr>').$markTxt.$this->spansPieceText($textSpanList).'</w:p>';
	}

	/**
	 * Writes the text of a paragraph: a run for each span, in a link when
	 * the span points to an address, as a field when Word works its text
	 * out, as the reference to a note when it points to one.
	 *
	 * @param Array $textSpanList The spans.
	 * @return String The runs.
	 */
	protected function spansPieceText(Array $textSpanList): String {
		$runsTxt = '';
		foreach($textSpanList as $oneSpn){
			if( !is_array($oneSpn) ){
				continue;
			}
			$spanTxt = strval($oneSpn['texts'] ?? '');
			$markLst = (array)($oneSpn['marks'] ?? Array());
			$linkAdr = Shape::tidyLinkString(is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
			$fldCode = is_scalar($oneSpn['field'] ?? '') ? strval($oneSpn['field'] ?? '') : '';
			$matches = Array();
			if( preg_match('/^#((fnote|enote)\d+)$/D', $linkAdr, $matches) && isset($this->remarkList[$matches[2]][$matches[1]]) ){
				// A note is referred to once, from the text of the document: Word numbers it, and writes its number
				if( !$this->notesMuted && !isset($this->citedNotes[$matches[2]][$matches[1]]) ){
					$this->citedNotes[$matches[2]][$matches[1]] = count($this->citedNotes[$matches[2]])+1;
					$wordNme = ($matches[2]=='fnote') ? 'footnote' : 'endnote';
					$runsTxt .= '<w:r><w:rPr><w:rStyle w:val="'.ucfirst($wordNme).'Reference"/></w:rPr><w:'.$wordNme.'Reference w:id="'.$this->citedNotes[$matches[2]][$matches[1]].'"/></w:r>';
					continue;
				}
				// Any other reference to it is the text it shows
				$linkAdr = '';
			}
			// A field: what it asks Word for, around the text it last showed
			$codeTxt = strval(self::FLDS_LIST[$fldCode] ?? '');
			$propNme = (substr($fldCode, 0, 6)=='props:') ? trim(strval(preg_replace('/["\\\\\x00-\x1F]+/', '', substr($fldCode, 6)))) : '';
			if( $codeTxt==='' && $propNme!=='' ){
				$codeTxt = 'DOCPROPERTY "'.$propNme.'"';
			}
			if( $spanTxt==='' && $codeTxt==='' ){
				continue;
			}
			$runText = $this->writeRunOfText(($spanTxt==='') ? '1' : $spanTxt, $markLst, ($linkAdr!==''));
			if( $codeTxt!=='' ){
				$runText = '<w:fldSimple w:instr=" '.$this->escapeXmlValue($codeTxt).' ">'.$runText.'</w:fldSimple>';
			}
			if( $linkAdr==='' ){
				$runsTxt .= $runText;
				continue;
			}
			// A link to a place of the document names that place; any other is kept among the relations of the part
			if( $linkAdr[0]=='#' ){
				$markNme = $this->cleanPlaceName(substr($linkAdr, 1));
				$runsTxt .= ($markNme==='') ? $runText : '<w:hyperlink w:anchor="'.$markNme.'" w:history="1">'.$runText.'</w:hyperlink>';
				continue;
			}
			if( !isset($this->knownLinks[$linkAdr]) ){
				$this->knownLinks[$linkAdr] = 'rLk'.(count($this->knownLinks)+1);
			}
			$runsTxt .= '<w:hyperlink r:id="'.$this->knownLinks[$linkAdr].'" w:history="1">'.$runText.'</w:hyperlink>';
		}
		return $runsTxt;
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
		$propTxt = ($isInsideLink? '<w:rStyle w:val="Hyperlink"/>' : '')
			.(in_array('codeSpan', $runFormatSet) ? '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/>' : '')
			.(in_array('boldFace', $runFormatSet) ? '<w:b/><w:bCs/>' : '').(in_array('emphasis', $runFormatSet) ? '<w:i/><w:iCs/>' : '')
			.(in_array('crossOut', $runFormatSet) ? '<w:strike/>' : '').(in_array('undrLine', $runFormatSet) ? '<w:u w:val="single"/>' : '');
		$textTxt = '';
		foreach(preg_split('/(\n|\t)/', str_replace(Array("\r\n", "\r"), "\n", $sourceString), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			$textTxt .= ($onePrt==="\n") ? '<w:br/>' : (($onePrt==="\t") ? '<w:tab/>' : '<w:t xml:space="preserve">'.$this->escapeXmlValue($onePrt).'</w:t>');
		}
		return '<w:r>'.(($propTxt==='') ? '' : '<w:rPr>'.$propTxt.'</w:rPr>').$textTxt.'</w:r>';
	}

}
