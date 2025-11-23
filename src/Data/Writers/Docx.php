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
	const HEADSIZE = Array(1=>20, 2=>16, 3=>14, 4=>12, 5=>11, 6=>11);
	// How a paragraph is aligned, by the code that asks for it
	const ALGNLIST = Array('lefts'=>'left', 'right'=>'right', 'centr'=>'center', 'justf'=>'both');
	// The deepest a list goes into another
	const LISTDEEP = 8;
	// The properties Word keeps a place for, by their name in the content: the element they are written in
	const PROPLIST = Array('Title'=>'dc:title', 'Author'=>'dc:creator', 'Subject'=>'dc:subject', 'Keywords'=>'cp:keywords', 'Comments'=>'dc:description', 'Category'=>'cp:category', 'LastSavedBy'=>'cp:lastModifiedBy');
	// What a field asks Word for, by the code of the field
	const FLDSLIST = Array('pgnum'=>'PAGE', 'pgtot'=>'NUMPAGES', 'dates'=>'DATE', 'props:Title'=>'TITLE', 'props:Author'=>'AUTHOR', 'props:Subject'=>'SUBJECT', 'props:Keywords'=>'KEYWORDS', 'props:Comments'=>'COMMENTS', 'props:LastSavedBy'=>'LASTSAVEDBY');
	// The addresses of the format, and of what ties its parts together
	const MAINNAME = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const RELSNAME = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const PACKNAME = 'http://schemas.openxmlformats.org/package/2006';

	// The addresses the part being written points to, each with the id of its relation
	protected $linkList = Array();
	// The lists of the document, in the order they are met: true for a numbered one
	protected $numsList = Array();
	// The names given to places of the document so far
	protected $markList = Array();
	// The notes of the document, by their kind then their name: the block of each
	protected $noteList = Array();
	// The notes the text has referred to so far, by their kind then their name: the number Word knows each by
	protected $noteRefs = Array();
	// Whether the part being written may not refer to a note: only the text of the document may
	protected $notesOff = false;
	// The styles of the content written as styles of the document: name in small letters => id
	protected $stylList = Array();
	// The pictures the part being written shows, each with the id of its relation: name in the document => id
	protected $pictList = Array();
	// The pictures of the document, each written once: what tells its content apart => its name in the document, its bytes, its width and its height in pixels
	protected $mediaSet = Array();
	// The room a picture has on a page: its greatest width and height, in the unit Word measures pictures in
	protected $roomSize = Array(0, 0);

	/**
	 * Writes text blocks, or sheets, as a document.
	 *
	 * Options:
	 *   'paper' => the size of the pages: 'isoa3', 'isoa4', 'isoa5',
	 *              'lettr' or 'legal'; 'isoa4' when left out;
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
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'paper', 'lands', 'media'.
	 * @return String The bytes of the document.
	 *
	 * @throws WriteError When the shape is neither 'block' nor 'sheet', the size of paper is not a known one, or the folder of the pictures cannot be read.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$shapeAs = $this->checkShpe($shapedData, Array('block', 'sheet'), 'a document');
		$sizeSet = $this->paperSize($writeOptns);
		// A picture is no wider nor higher than the text of a page: a twentieth of a point is 635 of Word's units
		$this->roomSize = Array(($sizeSet[0]-2880)*635, ($sizeSet[1]-2880-1440)*635);
		$this->mediaFrom($writeOptns);
		$this->pictList = Array();
		$this->mediaSet = Array();
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$blckLst = ($shapeAs=='sheet') ? $this->sheetsOut((array)($shapedData['items'] ?? Array())) : (array)($shapedData['items'] ?? Array());
		$this->linkList = Array();
		$this->numsList = Array();
		$this->markList = Array();
		$this->noteRefs = Array('fnote'=>Array(), 'enote'=>Array());
		// The notes are taken out of the text: each is written where the text refers to it
		$this->noteList = $this->takeNotes($blckLst);
		$stylTxt = $this->stylePart((array)($infoArr['frmat'] ?? Array()), $blckLst);
		$this->notesOff = false;
		$bodyTxt = $this->blocksOut($blckLst, 0);
		// Only the text of the document refers to notes: what follows writes a reference as the text it is
		$this->notesOff = true;
		$docLnks = Array($this->linkList, $this->pictList);
		$headXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
		$rootTxt = ' xmlns:w="'.self::MAINNAME.'" xmlns:r="'.self::RELSNAME.'" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
		$moreLst = Array();
		$typeTxt = '';
		$relsTxt = '<Relationship Id="rId1" Type="'.self::RELSNAME.'/styles" Target="styles.xml"/><Relationship Id="rId2" Type="'.self::RELSNAME.'/numbering" Target="numbering.xml"/>';
		$sectTxt = '';
		// What stands at the top and at the bottom of the pages: a part each, with the addresses it points to
		foreach(Array('phead'=>Array('header', 'w:hdr', 'rId3'), 'pfoot'=>Array('footer', 'w:ftr', 'rId4')) as $infKey=>$pagSet){
			if( empty($infoArr[$infKey]) || !is_array($infoArr[$infKey]) ){
				continue;
			}
			$this->linkList = Array();
			$this->pictList = Array();
			$pageTxt = $this->blocksOut($infoArr[$infKey], 0);
			$moreLst['word/'.$pagSet[0].'1.xml'] = $headXml.'<'.$pagSet[1].$rootTxt.'>'.(($pageTxt==='' || substr($pageTxt, -8)=='</w:tbl>') ? $pageTxt.'<w:p/>' : $pageTxt).'</'.$pagSet[1].'>';
			$moreLst['word/_rels/'.$pagSet[0].'1.xml.rels'] = $headXml.'<Relationships xmlns="'.self::PACKNAME.'/relationships">'.$this->linksRels().'</Relationships>';
			$typeTxt .= '<Override PartName="/word/'.$pagSet[0].'1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.'.$pagSet[0].'+xml"/>';
			$relsTxt .= '<Relationship Id="'.$pagSet[2].'" Type="'.self::RELSNAME.'/'.$pagSet[0].'" Target="'.$pagSet[0].'1.xml"/>';
			$sectTxt .= '<w:'.$pagSet[0].'Reference w:type="default" r:id="'.$pagSet[2].'"/>';
		}
		// The notes the text referred to: those of the pages, those of the end
		$lostLst = Array();
		foreach(Array('fnote'=>Array('footnote', 'rId5'), 'enote'=>Array('endnote', 'rId6')) as $nteKnd=>$nteSet){
			$lostLst = array_merge($lostLst, array_values(array_diff_key($this->noteList[$nteKnd], $this->noteRefs[$nteKnd])));
			if( empty($this->noteRefs[$nteKnd]) ){
				continue;
			}
			$this->linkList = Array();
			$this->pictList = Array();
			$moreLst['word/'.$nteSet[0].'s.xml'] = $headXml.'<w:'.$nteSet[0].'s'.$rootTxt.'>'.$this->notesPart($nteKnd, $nteSet[0]).'</w:'.$nteSet[0].'s>';
			$moreLst['word/_rels/'.$nteSet[0].'s.xml.rels'] = $headXml.'<Relationships xmlns="'.self::PACKNAME.'/relationships">'.$this->linksRels().'</Relationships>';
			$typeTxt .= '<Override PartName="/word/'.$nteSet[0].'s.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.'.$nteSet[0].'s+xml"/>';
			$relsTxt .= '<Relationship Id="'.$nteSet[1].'" Type="'.self::RELSNAME.'/'.$nteSet[0].'s" Target="'.$nteSet[0].'s.xml"/>';
		}
		// A note the text never referred to is not lost: it ends the document, as text
		$this->linkList = $docLnks[0];
		$this->pictList = $docLnks[1];
		foreach($lostLst as $blkArr){
			$bodyTxt .= $this->blockPart(Array('block'=>'parag') + $blkArr, 0);
		}
		$relsTxt .= $this->linksRels();
		// The properties: those Word keeps a place for, then the others
		$propLst = (array)($infoArr['props'] ?? Array());
		foreach(Array('title'=>'Title', 'authr'=>'Author') as $infKey=>$prpNme){
			if( is_scalar($infoArr[$infKey] ?? null) && strval($infoArr[$infKey])!=='' ){
				$propLst[$prpNme] = strval($infoArr[$infKey]);
			}
		}
		$coreTxt = '';
		foreach(self::PROPLIST as $prpNme=>$prpTag){
			$coreTxt .= (is_scalar($propLst[$prpNme] ?? null) && strval($propLst[$prpNme])!=='') ? '<'.$prpTag.'>'.$this->escapeTxt(strval($propLst[$prpNme])).'</'.$prpTag.'>' : '';
		}
		$custTxt = $this->propsPart(array_diff_key($propLst, self::PROPLIST));
		// The pictures, each written once, and the kinds of file they are
		foreach($this->mediaSet as $medSet){
			$moreLst['word/'.$medSet[0]] = $medSet[1];
			$medExtn = substr($medSet[0], intval(strrpos($medSet[0], '.'))+1);
			if( strpos($typeTxt, 'Extension="'.$medExtn.'"')===false ){
				$typeTxt = '<Default Extension="'.$medExtn.'" ContentType="'.self::PICTLIST[($medExtn=='jpeg') ? 'jpg' : $medExtn].'"/>'.$typeTxt;
			}
		}
		$fileLst = Array(
			'[Content_Types].xml'=>$headXml.'<Types xmlns="'.self::PACKNAME.'/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.$typeTxt
				.'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
				.'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
				.'<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
				.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
				.(($custTxt==='') ? '' : '<Override PartName="/docProps/custom.xml" ContentType="application/vnd.openxmlformats-officedocument.custom-properties+xml"/>').'</Types>',
			'_rels/.rels'=>$headXml.'<Relationships xmlns="'.self::PACKNAME.'/relationships"><Relationship Id="rId1" Type="'.self::RELSNAME.'/officeDocument" Target="word/document.xml"/>'
				.'<Relationship Id="rId2" Type="'.self::PACKNAME.'/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
				.(($custTxt==='') ? '' : '<Relationship Id="rId3" Type="'.self::RELSNAME.'/custom-properties" Target="docProps/custom.xml"/>').'</Relationships>',
			'docProps/core.xml'=>$headXml.'<cp:coreProperties xmlns:cp="'.self::PACKNAME.'/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				.$coreTxt.'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>'
		) + (($custTxt==='') ? Array() : Array('docProps/custom.xml'=>$headXml.$custTxt)) + Array(
			'word/document.xml'=>$headXml.'<w:document'.$rootTxt.'><w:body>'.$bodyTxt.((substr($bodyTxt, -8)=='</w:tbl>') ? '<w:p/>' : '')
				.'<w:sectPr>'.$sectTxt.'<w:pgSz w:w="'.$sizeSet[0].'" w:h="'.$sizeSet[1].'"'.(empty($writeOptns['lands']) ? '' : ' w:orient="landscape"').'/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>',
			'word/_rels/document.xml.rels'=>$headXml.'<Relationships xmlns="'.self::PACKNAME.'/relationships">'.$relsTxt.'</Relationships>',
			'word/styles.xml'=>$headXml.$stylTxt,
			'word/numbering.xml'=>$headXml.$this->numbrPart()
		) + $moreLst;
		try{
			return Archv::makeBytes($fileLst);
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
	protected function linksRels(): String {
		$relsTxt = '';
		foreach($this->linkList as $lnkAdr=>$relIdn){
			$relsTxt .= '<Relationship Id="'.$relIdn.'" Type="'.self::RELSNAME.'/hyperlink" Target="'.$this->escapeTxt($lnkAdr).'" TargetMode="External"/>';
		}
		foreach($this->pictList as $picNme=>$relIdn){
			$relsTxt .= '<Relationship Id="'.$relIdn.'" Type="'.self::RELSNAME.'/image" Target="'.$picNme.'"/>';
		}
		return $relsTxt;
	}

	/**
	 * Writes the properties of the document Word keeps no place for,
	 * each with the kind of its value.
	 *
	 * @param Array $propsArray The properties, name => value.
	 * @return String The part holding them, '' when there is none.
	 */
	protected function propsPart(Array $propsArray): String {
		$propTxt = '';
		$propNbr = 1;
		foreach($propsArray as $prpNme=>$prpVal){
			$nameTxt = trim(strval($prpNme));
			if( $nameTxt==='' || !is_scalar($prpVal) || (is_float($prpVal) && !is_finite($prpVal)) ){
				continue;
			}
			if( is_bool($prpVal) ){
				$valuTxt = '<vt:bool>'.($prpVal ? 'true' : 'false').'</vt:bool>';
			}elseif( is_int($prpVal) ){
				$valuTxt = (abs($prpVal)<2147483648) ? '<vt:i4>'.$prpVal.'</vt:i4>' : '<vt:i8>'.$prpVal.'</vt:i8>';
			}elseif( is_float($prpVal) ){
				$valuTxt = '<vt:r8>'.$this->numbrText($prpVal).'</vt:r8>';
			}else{
				$valuTxt = '<vt:lpwstr>'.$this->escapeTxt($prpVal).'</vt:lpwstr>';
			}
			$propNbr++;
			$propTxt .= '<property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="'.$propNbr.'" name="'.$this->escapeTxt($nameTxt).'">'.$valuTxt.'</property>';
		}
		return ($propTxt==='') ? '' : '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'.$propTxt.'</Properties>';
	}

	/**
	 * Writes the styles of the document: its text, its headings, the
	 * entries of its lists, its quotes, its code, its links and its
	 * notes, under the names Word gives them in every language; then the
	 * styles its paragraphs name, with what the content tells of each.
	 *
	 * @param Array $formatList The styles the content tells of, name => its font under 'fonts', its size in points under 'sizes', its colour under 'color', its formatting under 'marks' and its alignment under 'align'.
	 * @param Array $blocksList The blocks of the document, for the styles their paragraphs name.
	 * @return String The part holding the styles.
	 */
	protected function stylePart(Array $formatList, Array $blocksList): String {
		$stylTxt = '<w:styles xmlns:w="'.self::MAINNAME.'"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
			.'<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
			.'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			.'<w:style w:type="character" w:default="1" w:styleId="DefaultParagraphFont"><w:name w:val="Default Paragraph Font"/><w:uiPriority w:val="1"/><w:semiHidden/></w:style>';
		foreach(self::HEADSIZE as $hdrLvl=>$sizNbr){
			$stylTxt .= '<w:style w:type="paragraph" w:styleId="Heading'.$hdrLvl.'"><w:name w:val="heading '.$hdrLvl.'"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="9"/><w:qFormat/>'
				.'<w:pPr><w:keepNext/><w:keepLines/><w:spacing w:before="'.(($hdrLvl==1) ? 360 : 200).'" w:after="80"/><w:outlineLvl w:val="'.($hdrLvl-1).'"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="'.($sizNbr*2).'"/><w:szCs w:val="'.($sizNbr*2).'"/></w:rPr></w:style>';
		}
		$stylTxt .= '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="34"/><w:qFormat/><w:pPr><w:contextualSpacing/><w:ind w:left="720"/></w:pPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:uiPriority w:val="29"/><w:qFormat/><w:pPr><w:spacing w:before="160"/><w:ind w:left="720" w:right="720"/></w:pPr><w:rPr><w:i/><w:iCs/><w:color w:val="404040"/></w:rPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="HTMLPreformatted"><w:name w:val="HTML Preformatted"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="TableHeading"><w:name w:val="Table Heading"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:spacing w:after="0"/></w:pPr><w:rPr><w:b/><w:bCs/></w:rPr></w:style>'
			.'<w:style w:type="character" w:styleId="Hyperlink"><w:name w:val="Hyperlink"/><w:basedOn w:val="DefaultParagraphFont"/><w:uiPriority w:val="99"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr></w:style>';
		foreach(Array('Footnote'=>'footnote', 'Endnote'=>'endnote') as $nteIdn=>$nteNme){
			$stylTxt .= '<w:style w:type="paragraph" w:styleId="'.$nteIdn.'Text"><w:name w:val="'.$nteNme.' text"/><w:basedOn w:val="Normal"/><w:uiPriority w:val="99"/><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr></w:style>'
				.'<w:style w:type="character" w:styleId="'.$nteIdn.'Reference"><w:name w:val="'.$nteNme.' reference"/><w:basedOn w:val="DefaultParagraphFont"/><w:uiPriority w:val="99"/><w:rPr><w:vertAlign w:val="superscript"/></w:rPr></w:style>';
		}
		// The styles the paragraphs name: those the content tells of, and those it only names
		$this->stylList = Array();
		$nameLst = array_map('strval', array_keys($formatList));
		$walkFnc = function(Array $blocksSeen) use (&$walkFnc, &$nameLst){
			foreach($blocksSeen as $blkArr){
				if( is_array($blkArr) ){
					$nameLst[] = (strval($blkArr['block'] ?? '')=='parag' && is_scalar($blkArr['attrs']['style'] ?? null)) ? strval($blkArr['attrs']['style']) : '';
					$walkFnc((array)($blkArr['items'] ?? Array()));
				}
			}
		};
		$walkFnc($blocksList);
		foreach($nameLst as $stlNme){
			$nameKey = strtolower(trim($stlNme));
			if( $nameKey==='' || in_array($nameKey, self::OWNSTYLS) || isset($this->stylList[$nameKey]) ){
				continue;
			}
			$stylSet = (array)($formatList[$stlNme] ?? Array());
			$stylIdn = 'nyu'.(count($this->stylList)+1).substr(strval(preg_replace('/[^A-Za-z0-9]+/', '', $stlNme)), 0, 20);
			$this->stylList[$nameKey] = $stylIdn;
			$markLst = (array)($stylSet['marks'] ?? Array());
			$fontTxt = is_scalar($stylSet['fonts'] ?? '') ? $this->escapeTxt(substr(trim(strval($stylSet['fonts'] ?? '')), 0, 60)) : '';
			$sizeHlf = is_numeric($stylSet['sizes'] ?? null) ? intval(round(max(1, min(400, floatval($stylSet['sizes'])))*2)) : 0;
			$colrPrt = Array();
			$runsTxt = (($fontTxt==='') ? '' : '<w:rFonts w:ascii="'.$fontTxt.'" w:hAnsi="'.$fontTxt.'" w:cs="'.$fontTxt.'"/>')
				.(in_array('bold', $markLst) ? '<w:b/><w:bCs/>' : '').(in_array('ital', $markLst) ? '<w:i/><w:iCs/>' : '').(in_array('strk', $markLst) ? '<w:strike/>' : '')
				.(preg_match('/^#?([0-9A-Fa-f]{6})$/D', is_scalar($stylSet['color'] ?? '') ? strval($stylSet['color'] ?? '') : '', $colrPrt) ? '<w:color w:val="'.strtoupper($colrPrt[1]).'"/>' : '')
				.(($sizeHlf>0) ? '<w:sz w:val="'.$sizeHlf.'"/><w:szCs w:val="'.$sizeHlf.'"/>' : '').(in_array('undr', $markLst) ? '<w:u w:val="single"/>' : '');
			$algnKey = is_scalar($stylSet['align'] ?? '') ? strval($stylSet['align'] ?? '') : '';
			$stylTxt .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="'.$stylIdn.'"><w:name w:val="'.$this->escapeTxt(substr(trim($stlNme), 0, 100)).'"/><w:basedOn w:val="Normal"/><w:qFormat/>'
				.(isset(self::ALGNLIST[$algnKey]) ? '<w:pPr><w:jc w:val="'.self::ALGNLIST[$algnKey].'"/></w:pPr>' : '').(($runsTxt==='') ? '' : '<w:rPr>'.$runsTxt.'</w:rPr>').'</w:style>';
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
	protected function numbrPart(): String {
		$numbTxt = '<w:numbering xmlns:w="'.self::MAINNAME.'">';
		foreach(Array(0=>Array('bullet', Array("\u{2022}", "\u{25E6}", "\u{25AA}")), 1=>Array('', Array('decimal', 'lowerLetter', 'lowerRoman'))) as $absIdx=>$absSet){
			$numbTxt .= '<w:abstractNum w:abstractNumId="'.$absIdx.'"><w:multiLevelType w:val="hybridMultilevel"/>';
			for($lvlIdx=0; $lvlIdx<=self::LISTDEEP; $lvlIdx++){
				$numbTxt .= '<w:lvl w:ilvl="'.$lvlIdx.'"><w:start w:val="1"/><w:numFmt w:val="'.(($absIdx==0) ? 'bullet' : $absSet[1][$lvlIdx%3]).'"/><w:lvlText w:val="'.(($absIdx==0) ? $absSet[1][$lvlIdx%3] : '%'.($lvlIdx+1).'.').'"/><w:lvlJc w:val="left"/>'
					.'<w:pPr><w:ind w:left="'.(720*($lvlIdx+1)).'" w:hanging="360"/></w:pPr></w:lvl>';
			}
			$numbTxt .= '</w:abstractNum>';
		}
		foreach($this->numsList as $numIdx=>$isOrdr){
			$numbTxt .= '<w:num w:numId="'.($numIdx+1).'"><w:abstractNumId w:val="'.($isOrdr ? 1 : 0).'"/>';
			for($lvlIdx=0; $isOrdr && $lvlIdx<=self::LISTDEEP; $lvlIdx++){
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
	 * @param String $notesKinds The kind of notes: 'fnote' for those of the pages, 'enote' for those of the end.
	 * @param String $wordsNames The name Word gives that kind: 'footnote' or 'endnote'.
	 * @return String The notes, as written.
	 */
	protected function notesPart(String $notesKinds, String $wordsNames): String {
		$noteTxt = '<w:'.$wordsNames.' w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:'.$wordsNames.'><w:'.$wordsNames.' w:type="continuationSeparator" w:id="0"><w:p><w:r><w:continuationSeparator/></w:r></w:p></w:'.$wordsNames.'>';
		$stylIdn = ucfirst($wordsNames);
		foreach($this->noteRefs[$notesKinds] as $nteKey=>$nteNum){
			$noteArr = $this->noteList[$notesKinds][$nteKey];
			$spanLst = array_values((array)($noteArr['spans'] ?? Array()));
			// The number of the note, a space, then its text: the space is Word's, not the note's
			if( isset($spanLst[0]['texts']) ){
				$spanLst[0]['texts'] = ltrim(strval($spanLst[0]['texts']));
			}
			$noteTxt .= '<w:'.$wordsNames.' w:id="'.$nteNum.'"><w:p><w:pPr><w:pStyle w:val="'.$stylIdn.'Text"/></w:pPr><w:r><w:rPr><w:rStyle w:val="'.$stylIdn.'Reference"/></w:rPr><w:'.$wordsNames.'Ref/></w:r><w:r><w:t xml:space="preserve"> </w:t></w:r>'
				.$this->spansPart($spanLst).'</w:p>'.$this->blocksOut((array)($noteArr['items'] ?? Array()), 0);
			// A note ends with a paragraph, as a cell does
			$noteTxt .= ((substr($noteTxt, -8)=='</w:tbl>') ? '<w:p/>' : '').'</w:'.$wordsNames.'>';
		}
		return $noteTxt;
	}

	/**
	 * Writes blocks, one after the other.
	 *
	 * @param Array $blocksList The blocks.
	 * @param Int   $indentStep How far in they stand: 0 for the text of the document, one more for what an entry of a list holds.
	 * @return String The paragraphs they make.
	 */
	protected function blocksOut(Array $blocksList, Int $indentStep): String {
		$bodyTxt = '';
		foreach($blocksList as $blkArr){
			if( is_array($blkArr) && isset($blkArr['block']) ){
				$partTxt = $this->blockPart($blkArr, $indentStep);
				// A paragraph stands between two tables, without which they would become one
				$bodyTxt .= ((substr($bodyTxt, -8)=='</w:tbl>' && substr($partTxt, 0, 7)=='<w:tbl>') ? '<w:p/>' : '').$partTxt;
			}
		}
		return $bodyTxt;
	}

	/**
	 * Writes a block as the Word paragraphs of its kind: heading, list, table, quote, code, rule, picture or paragraph.
	 *
	 * @param Array $blockArray The block.
	 * @param Int   $indentStep How far in it stands.
	 * @return String The paragraphs it makes.
	 */
	protected function blockPart(Array $blockArray, Int $indentStep): String {
		$blckKnd = strval($blockArray['block']);
		$attrLst = (array)($blockArray['attrs'] ?? Array());
		$spanLst = (array)($blockArray['spans'] ?? Array());
		$itemLst = (array)($blockArray['items'] ?? Array());
		$indtTxt = ($indentStep>0) ? '<w:ind w:left="'.(720*$indentStep).'"/>' : '';
		if( $blckKnd=='headr' ){
			return $this->paragPart(Array('style'=>'Heading'.max(1, min(6, intval($attrLst['level'] ?? 1))), 'indnt'=>$indtTxt), $spanLst, $attrLst);
		}
		if( $blckKnd=='lists' ){
			return $this->listsPart($blockArray, $indentStep);
		}
		if( $blckKnd=='table' ){
			return $this->tablePart($blockArray, $indentStep);
		}
		if( $blckKnd=='quote' ){
			$quotTxt = $this->paragPart(Array('style'=>'Quote'), $spanLst, $attrLst).$this->blocksOut($itemLst, $indentStep+1);
			// Who is quoted follows the quote
			$citeTxt = is_scalar($attrLst['cites'] ?? '') ? trim(strval($attrLst['cites'] ?? '')) : '';
			return $quotTxt.(($citeTxt==='') ? '' : $this->paragPart(Array('style'=>'Quote'), Array(Shape::makeASpan("\u{2014} ".$citeTxt)), Array('align'=>'right')));
		}
		if( $blckKnd=='codes' ){
			// A paragraph for each line of code, their spaces kept
			$codeTxt = '';
			foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", implode('', array_column($spanLst, 'texts')))) as $oneLne){
				$codeTxt .= $this->paragPart(Array('style'=>'HTMLPreformatted', 'indnt'=>$indtTxt), Array(Shape::makeASpan($oneLne)), Array());
			}
			return $codeTxt;
		}
		if( $blckKnd=='rules' ){
			// A page ends here, or a line is drawn across it
			if( !empty($attrLst['break']) ){
				return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
			}
			return $this->paragPart(Array('bordr'=>'<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr>'), Array(), Array());
		}
		if( $blckKnd=='image' ){
			$pictTxt = $this->pictrPart($blockArray, $indtTxt);
			if( $pictTxt!=='' ){
				return $pictTxt;
			}
		}
		if( $blckKnd=='thead' || $blckKnd=='tbrow' ){
			// A row met outside a table is a table of that row
			return $this->tablePart(Array('items'=>Array($blockArray)), $indentStep);
		}
		// A paragraph, with the style it names; and what stands for a picture that is not written: the text that describes it
		$stylIdn = ($blckKnd=='parag' && is_scalar($attrLst['style'] ?? null)) ? strval($this->stylList[strtolower(trim(strval($attrLst['style'])))] ?? '') : '';
		return $this->paragPart(Array('indnt'=>$indtTxt) + (($stylIdn==='') ? Array() : Array('style'=>$stylIdn)), $spanLst, $attrLst).$this->blocksOut($itemLst, $indentStep);
	}

	/**
	 * Writes a picture into the document, when its file is in the folder
	 * the document was given.
	 *
	 * @param Array  $blockArray The image: the name of its file under 'sourc', and the text that describes it.
	 * @param String $indentText How far in it stands, as Word is told.
	 * @return String The paragraph showing the picture; '' when the picture is not written.
	 */
	protected function pictrPart(Array $blockArray, String $indentText): String {
		$pictSet = $this->pictrRead(is_scalar($blockArray['attrs']['sourc'] ?? '') ? strval($blockArray['attrs']['sourc'] ?? '') : '');
		if( $pictSet===null ){
			return '';
		}
		$pictTxt = $pictSet['bytes'];
		$giveKnd = $pictSet['kinds'];
		$widePix = $pictSet['width'];
		$highPix = $pictSet['heght'];
		// The same picture is written once, however often it is shown
		$pictKey = sha1($pictTxt);
		if( !isset($this->mediaSet[$pictKey]) ){
			if( array_sum(array_map(function(Array $mediaEntry){ return strlen($mediaEntry[1]); }, $this->mediaSet))+strlen($pictTxt)>self::MEDIAMAX ){
				return '';
			}
			$this->mediaSet[$pictKey] = Array('media/image'.(count($this->mediaSet)+1).'.'.(($giveKnd=='jpg') ? 'jpeg' : $giveKnd), $pictTxt);
		}
		$pictNme = $this->mediaSet[$pictKey][0];
		if( !isset($this->pictList[$pictNme]) ){
			$this->pictList[$pictNme] = 'rIm'.(count($this->pictList)+1);
		}
		// A pixel is 9525 of Word's units; a picture larger than the text of a page is brought down to it, its proportions kept
		$sizeRto = min(1, $this->roomSize[0]/($widePix*9525), $this->roomSize[1]/($highPix*9525));
		$wideEmu = max(1, intval(round($widePix*9525*$sizeRto)));
		$highEmu = max(1, intval(round($highPix*9525*$sizeRto)));
		$this->markList[] = '';
		$drawIdn = count($this->markList);
		$descTxt = $this->escapeTxt(trim(implode('', array_column((array)($blockArray['spans'] ?? Array()), 'texts'))));
		$algnKey = is_scalar($blockArray['attrs']['align'] ?? '') ? strval($blockArray['attrs']['align'] ?? '') : '';
		return '<w:p>'.(($indentText==='' && !isset(self::ALGNLIST[$algnKey])) ? '' : '<w:pPr>'.$indentText.(isset(self::ALGNLIST[$algnKey]) ? '<w:jc w:val="'.self::ALGNLIST[$algnKey].'"/>' : '').'</w:pPr>')
			.'<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$wideEmu.'" cy="'.$highEmu.'"/><wp:docPr id="'.$drawIdn.'" name="Picture '.$drawIdn.'" descr="'.$descTxt.'"/>'
			.'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			.'<pic:pic><pic:nvPicPr><pic:cNvPr id="'.$drawIdn.'" name="Picture '.$drawIdn.'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$this->pictList[$pictNme].'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
			.'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$wideEmu.'" cy="'.$highEmu.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
			.'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
	}

	/**
	 * Writes a list: a paragraph for each of its entries, which Word
	 * marks or numbers, and further in what each entry holds.
	 *
	 * @param Array $blockArray The list.
	 * @param Int   $levelValue How far in it stands.
	 * @return String The paragraphs it makes.
	 */
	protected function listsPart(Array $blockArray, Int $levelValue): String {
		$this->numsList[] = !empty($blockArray['attrs']['order']);
		$numIdnt = count($this->numsList);
		$lvlNmbr = min(self::LISTDEEP, $levelValue);
		$listTxt = '';
		foreach((array)($blockArray['items'] ?? Array()) as $entArr){
			$spanLst = (array)($entArr['spans'] ?? Array());
			// A box to tick is written as the sign of one, ticked or not
			if( isset($entArr['attrs']['check']) ){
				array_unshift($spanLst, Shape::makeASpan(empty($entArr['attrs']['check']) ? "\u{2610} " : "\u{2611} "));
			}
			$listTxt .= $this->paragPart(Array('style'=>'ListParagraph', 'numbr'=>'<w:numPr><w:ilvl w:val="'.$lvlNmbr.'"/><w:numId w:val="'.$numIdnt.'"/></w:numPr>'), $spanLst, array_intersect_key((array)($entArr['attrs'] ?? Array()), Array('tagid'=>1)));
			foreach((array)($entArr['items'] ?? Array()) as $subBlk){
				$listTxt .= (strval($subBlk['block'] ?? '')=='lists') ? $this->listsPart((array)$subBlk, $levelValue+1) : $this->blockPart((array)$subBlk, $levelValue+1);
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
	 * @param Array $blockArray The table.
	 * @param Int   $indentStep How far in it stands.
	 * @return String The table; '' when it holds no row.
	 */
	protected function tablePart(Array $blockArray, Int $indentStep): String {
		$rowsTxt = '';
		$colsMax = 0;
		// The areas still running down their columns: column => the rows left to cover and the columns covered
		$openLst = Array();
		// The columns of the table, for the room each cell leaves a picture
		$colsAll = 1;
		foreach((array)($blockArray['items'] ?? Array()) as $rowBlk){
			$rowCols = 0;
			foreach((array)($rowBlk['items'] ?? Array()) as $celBlk){
				$rowCols += max(1, min(63, intval($celBlk['attrs']['colsp'] ?? 1)));
			}
			$colsAll = max($colsAll, $rowCols);
		}
		$roomWas = $this->roomSize;
		foreach((array)($blockArray['items'] ?? Array()) as $rowBlk){
			if( !is_array($rowBlk) || !in_array(strval($rowBlk['block'] ?? ''), Array('thead', 'tbrow')) ){
				continue;
			}
			$isHeadr = ($rowBlk['block']=='thead');
			$cellTxt = '';
			$colIndx = 0;
			$cellLst = array_values((array)($rowBlk['items'] ?? Array()));
			// An end is added to the cells, so that the cells covered from above ending the row are written like the others
			$cellLst[] = null;
			foreach($cellLst as $celBlk){
				while( isset($openLst[$colIndx]) ){
					$cellTxt .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'.(($openLst[$colIndx][1]>1) ? '<w:gridSpan w:val="'.$openLst[$colIndx][1].'"/>' : '').'<w:vMerge/></w:tcPr><w:p/></w:tc>';
					$spanNbr = $openLst[$colIndx][1];
					if( --$openLst[$colIndx][0]<1 ){
						unset($openLst[$colIndx]);
					}
					$colIndx += $spanNbr;
				}
				if( !is_array($celBlk) ){
					continue;
				}
				$attrLst = (array)($celBlk['attrs'] ?? Array());
				$colSpan = max(1, min(63, intval($attrLst['colsp'] ?? 1)));
				$rowSpan = max(1, intval($attrLst['rowsp'] ?? 1));
				if( $rowSpan>1 ){
					$openLst[$colIndx] = Array($rowSpan-1, $colSpan);
				}
				$asHeadr = ($isHeadr || !empty($attrLst['headr']));
				// A picture in a cell is no wider than the cell, nor higher than half a page: a row is never cut by the end of a page
				$this->roomSize = Array(max(9525, intdiv($roomWas[0]*min($colSpan, $colsAll), $colsAll)-144000), intdiv($roomWas[1], 2));
				// What the cell holds: its own text, then its other blocks. A cell ends with a paragraph, whatever it holds
				$holdTxt = (empty($celBlk['spans']) && !empty($celBlk['items'])) ? '' : $this->paragPart($asHeadr ? Array('style'=>'TableHeading') : Array(), (array)($celBlk['spans'] ?? Array()), $attrLst);
				$holdTxt .= $this->blocksOut((array)($celBlk['items'] ?? Array()), 0);
				if( substr($holdTxt, -6)!=='</w:p>' ){
					$holdTxt .= '<w:p/>';
				}
				$cellTxt .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'.(($colSpan>1) ? '<w:gridSpan w:val="'.$colSpan.'"/>' : '').(($rowSpan>1) ? '<w:vMerge w:val="restart"/>' : '').($asHeadr ? '<w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>' : '').'</w:tcPr>'.$holdTxt.'</w:tc>';
				$this->roomSize = $roomWas;
				$colIndx += $colSpan;
			}
			if( $cellTxt!=='' ){
				$rowsTxt .= '<w:tr><w:trPr><w:cantSplit/>'.($isHeadr ? '<w:tblHeader/>' : '').'</w:trPr>'.$cellTxt.'</w:tr>';
				$colsMax = max($colsMax, $colIndx);
			}
		}
		if( $rowsTxt==='' ){
			return '';
		}
		$bordTxt = '';
		foreach(Array('top', 'left', 'bottom', 'right', 'insideH', 'insideV') as $sideNm){
			$bordTxt .= '<w:'.$sideNm.' w:val="single" w:sz="4" w:space="0" w:color="808080"/>';
		}
		// The table takes the width of the page
		return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'.(($indentStep>0) ? '<w:tblInd w:w="'.(720*$indentStep).'" w:type="dxa"/>' : '').'<w:tblBorders>'.$bordTxt.'</w:tblBorders>'
			.'<w:tblCellMar><w:top w:w="40" w:type="dxa"/><w:left w:w="100" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr>'
			.'<w:tblGrid>'.str_repeat('<w:gridCol/>', max(1, $colsMax)).'</w:tblGrid>'.$rowsTxt.'</w:tbl>';
	}

	/**
	 * Writes a Word paragraph: its properties, its bookmark when its place is named, then its text.
	 *
	 * @param Array $propsArray What Word is told of it, as written: its style under 'style', its list under 'numbr', its borders under 'bordr', how far in it stands under 'indnt'.
	 * @param Array $spansArray Its text, as spans.
	 * @param Array $attrsArray What the block tells of it: how it is aligned under 'align', the name of its place under 'tagid'.
	 * @return String The paragraph.
	 */
	protected function paragPart(Array $propsArray, Array $spansArray, Array $attrsArray): String {
		// Word reads what it is told of a paragraph in one order only
		$propTxt = (isset($propsArray['style']) ? '<w:pStyle w:val="'.$propsArray['style'].'"/>' : '').strval($propsArray['numbr'] ?? '').strval($propsArray['bordr'] ?? '').strval($propsArray['indnt'] ?? '');
		$algnKey = is_scalar($attrsArray['align'] ?? '') ? strval($attrsArray['align'] ?? '') : '';
		if( isset(self::ALGNLIST[$algnKey]) ){
			$propTxt .= '<w:jc w:val="'.self::ALGNLIST[$algnKey].'"/>';
		}
		// A place that is named can be pointed to from the rest of the document; the names of the notes are Word's to give
		$markTxt = '';
		$markNme = $this->placeName(is_scalar($attrsArray['tagid'] ?? '') ? strval($attrsArray['tagid'] ?? '') : '');
		if( $markNme!=='' && !in_array($markNme, $this->markList, true) && !preg_match('/^(fnote|enote)\d+$/D', $markNme) ){
			$this->markList[] = $markNme;
			$markTxt = '<w:bookmarkStart w:id="'.count($this->markList).'" w:name="'.$markNme.'"/><w:bookmarkEnd w:id="'.count($this->markList).'"/>';
		}
		return '<w:p>'.(($propTxt==='') ? '' : '<w:pPr>'.$propTxt.'</w:pPr>').$markTxt.$this->spansPart($spansArray).'</w:p>';
	}

	/**
	 * Writes the text of a paragraph: a run for each span, in a link when
	 * the span points to an address, as a field when Word works its text
	 * out, as the reference to a note when it points to one.
	 *
	 * @param Array $spansArray The spans.
	 * @return String The runs.
	 */
	protected function spansPart(Array $spansArray): String {
		$runsTxt = '';
		foreach($spansArray as $oneSpn){
			if( !is_array($oneSpn) ){
				continue;
			}
			$spanTxt = strval($oneSpn['texts'] ?? '');
			$markLst = (array)($oneSpn['marks'] ?? Array());
			$linkAdr = Shape::cleanLink(is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
			$fldCode = is_scalar($oneSpn['field'] ?? '') ? strval($oneSpn['field'] ?? '') : '';
			$notePrt = Array();
			if( preg_match('/^#((fnote|enote)\d+)$/D', $linkAdr, $notePrt) && isset($this->noteList[$notePrt[2]][$notePrt[1]]) ){
				// A note is referred to once, from the text of the document: Word numbers it, and writes its number
				if( !$this->notesOff && !isset($this->noteRefs[$notePrt[2]][$notePrt[1]]) ){
					$this->noteRefs[$notePrt[2]][$notePrt[1]] = count($this->noteRefs[$notePrt[2]])+1;
					$wordNme = ($notePrt[2]=='fnote') ? 'footnote' : 'endnote';
					$runsTxt .= '<w:r><w:rPr><w:rStyle w:val="'.ucfirst($wordNme).'Reference"/></w:rPr><w:'.$wordNme.'Reference w:id="'.$this->noteRefs[$notePrt[2]][$notePrt[1]].'"/></w:r>';
					continue;
				}
				// Any other reference to it is the text it shows
				$linkAdr = '';
			}
			// A field: what it asks Word for, around the text it last showed
			$instTxt = strval(self::FLDSLIST[$fldCode] ?? '');
			$propNme = (substr($fldCode, 0, 6)=='props:') ? trim(strval(preg_replace('/["\\\\\x00-\x1F]+/', '', substr($fldCode, 6)))) : '';
			if( $instTxt==='' && $propNme!=='' ){
				$instTxt = 'DOCPROPERTY "'.$propNme.'"';
			}
			if( $spanTxt==='' && $instTxt==='' ){
				continue;
			}
			$runText = $this->runOfText(($spanTxt==='') ? '1' : $spanTxt, $markLst, ($linkAdr!==''));
			if( $instTxt!=='' ){
				$runText = '<w:fldSimple w:instr=" '.$this->escapeTxt($instTxt).' ">'.$runText.'</w:fldSimple>';
			}
			if( $linkAdr==='' ){
				$runsTxt .= $runText;
				continue;
			}
			// A link to a place of the document names that place; any other is kept among the relations of the part
			if( $linkAdr[0]=='#' ){
				$markNme = $this->placeName(substr($linkAdr, 1));
				$runsTxt .= ($markNme==='') ? $runText : '<w:hyperlink w:anchor="'.$markNme.'" w:history="1">'.$runText.'</w:hyperlink>';
				continue;
			}
			if( !isset($this->linkList[$linkAdr]) ){
				$this->linkList[$linkAdr] = 'rLk'.(count($this->linkList)+1);
			}
			$runsTxt .= '<w:hyperlink r:id="'.$this->linkList[$linkAdr].'" w:history="1">'.$runText.'</w:hyperlink>';
		}
		return $runsTxt;
	}

	/**
	 * Writes a run of text, with its formatting. Its line ends and its
	 * tabs are written as what they are, its spaces are all kept.
	 *
	 * @param String $plainTexts The text.
	 * @param Array  $marksArray Its formatting, among bold, ital, undr, strk and code.
	 * @param Bool   $shownAsLnk Whether the run stands in a link, and is shown as one.
	 * @return String The run.
	 */
	protected function runOfText(String $plainTexts, Array $marksArray, Bool $shownAsLnk): String {
		// Word reads what it is told of a run in one order only
		$propTxt = ($shownAsLnk? '<w:rStyle w:val="Hyperlink"/>' : '')
			.(in_array('code', $marksArray) ? '<w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/>' : '')
			.(in_array('bold', $marksArray) ? '<w:b/><w:bCs/>' : '').(in_array('ital', $marksArray) ? '<w:i/><w:iCs/>' : '')
			.(in_array('strk', $marksArray) ? '<w:strike/>' : '').(in_array('undr', $marksArray) ? '<w:u w:val="single"/>' : '');
		$textTxt = '';
		foreach(preg_split('/(\n|\t)/', str_replace(Array("\r\n", "\r"), "\n", $plainTexts), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			$textTxt .= ($onePrt==="\n") ? '<w:br/>' : (($onePrt==="\t") ? '<w:tab/>' : '<w:t xml:space="preserve">'.$this->escapeTxt($onePrt).'</w:t>');
		}
		return '<w:r>'.(($propTxt==='') ? '' : '<w:rPr>'.$propTxt.'</w:rPr>').$textTxt.'</w:r>';
	}

}
