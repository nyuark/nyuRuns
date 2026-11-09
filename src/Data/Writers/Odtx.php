<?php

namespace nyu\Data\Writers;

use nyu\Data\Archv;
use nyu\Data\Shape;

/**
 * OpenDocument texts (odt) written from text blocks: headings a reader
 * finds its way by, paragraphs, lists, tables, quotes, code, notes, and
 * what stands at the top and the bottom of the pages. Sheets are written
 * as tables. What is text stays text: the document holds no macro, no
 * object, and points to nothing it would fetch. Its pictures are those
 * of a folder it is given, and no other.
 */
class Odtx extends Page {

	// The kind of file, as its first entry tells it
	const FILE_KIND = 'application/vnd.oasis.opendocument.text';
	// The size of the headings, by their level, in points
	const HEAD_SIZE = Array(1=>20, 2=>16, 3=>14, 4=>12, 5=>11, 6=>11);
	// How a paragraph is aligned, by the code that asks for it
	const ALGN_LIST = Array('alignLft'=>'start', 'alignRgt'=>'end', 'alignCtr'=>'center', 'alignJst'=>'justify');
	// The deepest a list goes into another
	const LIST_DEEP = 10;
	// What a field stands for, by its code: the element that asks the document for it
	const FLDS_LIST = Array('pageNumb'=>'text:page-number', 'pageTotl'=>'text:page-count', 'dateTime'=>'text:date', 'props:Title'=>'text:title', 'props:Subject'=>'text:subject', 'props:Keywords'=>'text:keywords', 'props:Comments'=>'text:description', 'props:Author'=>'text:initial-creator', 'props:LastSavedBy'=>'text:creator');
	// The addresses of the format, as every part declares them
	const NAME_LIST = ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" office:version="1.2"';

	// The styles made for the paragraphs formatted by hand: what tells one apart => its name and what it gives, as written
	protected $autoPrgrph = Array();
	// The styles made for the text formatted by hand: its formatting => the name of its style
	protected $autoStyles = Array();
	// The names given to places of the document so far
	protected $anchorList = Array();
	// The notes of the document, by their kind then their name: the block of each
	protected $remarkList = Array();
	// The notes the text has referred to so far, by their kind then their name
	protected $noteRfrncs = Array();
	// Whether what is being written may not refer to a note: a note may not hold another
	protected $notesMuted = false;
	// The styles of the content written as styles of the document: name in small letters => name in the document
	protected $styleTable = Array();
	// Whether the next paragraph starts a new page
	protected $breakAhead = false;
	// The number of tables written so far
	protected $tableCount = 0;
	// What the names of the styles made by hand start with: nothing in the text, a letter in what stands around the pages, whose styles are kept apart
	protected $autoPrefix = '';
	// The pictures of the document, each written once: what tells its content apart => its name in the document, its bytes
	protected $mediaFiles = Array();
	// The room a picture has on a page: its greatest width and height, in twentieths of a point
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
		$this->readPictureDir($writeOptions);
		// A picture is no wider nor higher than the text of a page
		$this->roomLength = Array($sizeSet[0]-2880, $sizeSet[1]-2880-1440);
		$this->mediaFiles = Array();
		$this->autoPrefix = '';
		$infoArr = (array)($dataEnvelope['infos'] ?? Array());
		$blckLst = ($shapeAs=='sheet') ? $this->writeAllSheets((array)($dataEnvelope['items'] ?? Array())) : (array)($dataEnvelope['items'] ?? Array());
		$this->autoPrgrph = Array();
		$this->autoStyles = Array();
		$this->anchorList = Array();
		$this->noteRfrncs = Array('fnote'=>Array(), 'enote'=>Array());
		$this->notesMuted = false;
		$this->breakAhead = false;
		$this->tableCount = 0;
		// The notes are taken out of the text: each is written where the text refers to it
		$this->remarkList = $this->takeNotesArray($blckLst);
		$stylTxt = $this->stylePieceText((array)($infoArr['frmat'] ?? Array()), $blckLst);
		$bodyTxt = $this->writeAllBlocks($blckLst);
		// A note the text never referred to is not lost: it ends the document, as text
		$this->notesMuted = true;
		foreach(Array('fnote', 'enote') as $nteKnd){
			foreach(array_diff_key($this->remarkList[$nteKnd], $this->noteRfrncs[$nteKnd]) as $blkArr){
				$bodyTxt .= $this->blckPartString(Array('block'=>'paragrph') + $blkArr);
			}
		}
		// A page that was to start and has nothing to start with still starts
		if( $this->breakAhead ){
			$bodyTxt .= $this->paragraphPiece('Standard', Array(), Array());
		}
		$autoTxt = $this->autosPieceText($sizeSet);
		// What stands at the top and at the bottom of the pages. Its styles are kept apart from those of the text,
		// under names of their own; it refers to no note, and starts no page
		$this->autoPrgrph = Array();
		$this->autoStyles = Array();
		$this->autoPrefix = 'M';
		$this->breakAhead = false;
		$mastTxt = '';
		$roomTxt = '';
		foreach(Array('phead'=>'header', 'pfoot'=>'footer') as $infKey=>$pgeNme){
			$pgeBlck = (!empty($infoArr[$infKey]) && is_array($infoArr[$infKey])) ? $this->writeAllBlocks($infoArr[$infKey]) : '';
			if( $pgeBlck!=='' ){
				$mastTxt .= '<style:'.$pgeNme.'>'.$pgeBlck.'</style:'.$pgeNme.'>';
				$roomTxt .= '<style:'.$pgeNme.'-style><style:header-footer-properties fo:min-height="0.6cm" fo:margin-'.(($pgeNme=='header') ? 'bottom' : 'top').'="0.5cm"/></style:'.$pgeNme.'-style>';
			}
		}
		$mastAut = ($mastTxt==='') ? '' : $this->autosPieceText($sizeSet);
		// The size of the pages and their margins, and the room kept for what stands at their top and their bottom
		$measure = function(Int $twipsValNumb){ return sprintf('%.3F', $twipsValNumb*2.54/1440).'cm'; };
		$pageTxt = '<style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="'.$measure($sizeSet[0]).'" fo:page-height="'.$measure($sizeSet[1]).'" style:print-orientation="'.(empty($writeOptions['lands']) ? 'portrait' : 'landscape').'" fo:margin-top="2.54cm" fo:margin-bottom="2.54cm" fo:margin-left="2.54cm" fo:margin-right="2.54cm"/>'.$roomTxt.'</style:page-layout>';
		// The pictures, each written once, and told for what it is
		$pctrLst = Array();
		$pctrTxt = '';
		foreach($this->mediaFiles as $medSet){
			$pctrLst[$medSet[0]] = $medSet[1];
			$pctrTxt .= '<manifest:file-entry manifest:full-path="'.$medSet[0].'" manifest:media-type="'.self::PICT_LIST[substr($medSet[0], intval(strrpos($medSet[0], '.'))+1)].'"/>';
		}
		$headXml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$fileLst = Array(
			'mimetype'=>self::FILE_KIND,
			'META-INF/manifest.xml'=>$headXml.'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="'.self::FILE_KIND.'"/>'
				.'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
				.'<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'.$pctrTxt.'</manifest:manifest>',
			'content.xml'=>$headXml.'<office:document-content'.self::NAME_LIST.'><office:automatic-styles>'.$autoTxt.'</office:automatic-styles><office:body><office:text>'.$bodyTxt.'</office:text></office:body></office:document-content>',
			'styles.xml'=>$headXml.'<office:document-styles'.self::NAME_LIST.'>'.$stylTxt.'<office:automatic-styles>'.$pageTxt.$mastAut.'</office:automatic-styles><office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1">'.$mastTxt.'</style:master-page></office:master-styles></office:document-styles>',
			'meta.xml'=>$headXml.$this->metasPieceText($infoArr)
		) + $pctrLst;
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::newBytesString($fileLst, Array('mimetype'));
		}catch( \RuntimeException $e ){
			throw new WriteError('Document that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Writes the properties of the document: those every document has a
	 * place for, then the others, each with the kind of its value.
	 *
	 * @param Array $mediaDetails What is told of the document: its title under 'title', its author under 'authr', its other properties under 'props'.
	 * @return String The part holding the properties.
	 */
	protected function metasPieceText(Array $mediaDetails): String {
		$propLst = (array)($mediaDetails['props'] ?? Array());
		foreach(Array('title'=>'Title', 'authr'=>'Author') as $infKey=>$prpNme){
			if( is_scalar($mediaDetails[$infKey] ?? null) && strval($mediaDetails[$infKey])!=='' ){
				$propLst[$prpNme] = strval($mediaDetails[$infKey]);
			}
		}
		$metaTxt = '';
		foreach(Array('Title'=>'dc:title', 'Author'=>'meta:initial-creator', 'Subject'=>'dc:subject', 'Comments'=>'dc:description', 'LastSavedBy'=>'dc:creator') as $prpNme=>$xmlTag){
			$metaTxt .= (is_scalar($propLst[$prpNme] ?? null) && strval($propLst[$prpNme])!=='') ? '<'.$xmlTag.'>'.$this->escapeXmlValue(strval($propLst[$prpNme])).'</'.$xmlTag.'>' : '';
		}
		// The key words are written one by one
		foreach(is_scalar($propLst['Keywords'] ?? null) ? array_filter(array_map('trim', explode(',', strval($propLst['Keywords']))), 'strlen') : Array() as $keyWrd){
			$metaTxt .= '<meta:keyword>'.$this->escapeXmlValue($keyWrd).'</meta:keyword>';
		}
		$metaTxt .= '<meta:creation-date>'.gmdate('Y-m-d\TH:i:s').'</meta:creation-date>';
		foreach(array_diff_key($propLst, Array('Title'=>1, 'Author'=>1, 'Subject'=>1, 'Comments'=>1, 'LastSavedBy'=>1, 'Keywords'=>1)) as $prpNme=>$prpVal){
			$nameTxt = trim(strval($prpNme));
			if( $nameTxt==='' || !is_scalar($prpVal) || (is_float($prpVal) && !is_finite($prpVal)) ){
				continue;
			}
			$metaTxt .= '<meta:user-defined meta:name="'.$this->escapeXmlValue($nameTxt).'" meta:value-type="'.(is_bool($prpVal) ? 'boolean' : ((is_int($prpVal) || is_float($prpVal)) ? 'float' : 'string')).'">'
				.(is_bool($prpVal) ? ($prpVal ? 'true' : 'false') : ((is_int($prpVal) || is_float($prpVal)) ? $this->writeNumberTxt($prpVal) : $this->escapeXmlValue($prpVal))).'</meta:user-defined>';
		}
		return '<office:document-meta'.self::NAME_LIST.'><office:meta>'.$metaTxt.'</office:meta></office:document-meta>';
	}

	/**
	 * Gives a style the name it has in the document: what is neither a
	 * letter nor a digit is written as its code between two underscores,
	 * so that 'Text body' is Text_20_body.
	 *
	 * @param String $displayLabel The name of the style, as shown.
	 * @return String Its name in the document.
	 */
	protected function escapeStyleNme(String $displayLabel): String {
		$nameTxt = strval(preg_replace_callback('/[^A-Za-z0-9]/', function(Array $locatedParts){ return '_'.bin2hex($locatedParts[0]).'_'; }, substr($displayLabel, 0, 100)));
		return preg_match('/^[A-Za-z]/', $nameTxt) ? $nameTxt : 'S'.$nameTxt;
	}

	/**
	 * Writes the styles of the document: its text, its headings, its
	 * quotes, its code, the headings of its tables and its notes, under
	 * the names LibreOffice gives them; then the styles its paragraphs
	 * name, with what the content tells of each.
	 *
	 * @param Array $styleFormats The styles the content tells of, name => its font under 'fonts', its size in points under 'sizes', its colour under 'color', its formatting under 'marks' and its alignment under 'align'.
	 * @param Array $bookBlockSet The blocks of the document, for the styles their paragraphs name.
	 * @return String The styles the document names.
	 */
	protected function stylePieceText(Array $styleFormats, Array $bookBlockSet): String {
		$stylTxt = '<office:styles>'
			.'<style:default-style style:family="paragraph"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.282cm" fo:line-height="108%"/><style:text-properties fo:font-family="Calibri" style:font-family-generic="swiss" fo:font-size="11pt" style:font-size-asian="11pt" style:font-size-complex="11pt"/></style:default-style>'
			.'<style:style style:name="Standard" style:family="paragraph" style:class="text"/>';
		foreach(self::HEAD_SIZE as $hdrLvl=>$sizeNb){
			$stylTxt .= '<style:style style:name="Heading_20_'.$hdrLvl.'" style:display-name="Heading '.$hdrLvl.'" style:family="paragraph" style:parent-style-name="Standard" style:next-style-name="Standard" style:default-outline-level="'.$hdrLvl.'" style:class="text">'
				.'<style:paragraph-properties fo:margin-top="'.(($hdrLvl==1) ? '0.635' : '0.353').'cm" fo:margin-bottom="0.141cm" fo:keep-with-next="always"/><style:text-properties fo:font-size="'.$sizeNb.'pt" style:font-size-asian="'.$sizeNb.'pt" style:font-size-complex="'.$sizeNb.'pt" fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>';
		}
		$stylTxt .= '<style:style style:name="Quotations" style:family="paragraph" style:parent-style-name="Standard" style:class="html"><style:paragraph-properties fo:margin-left="1.27cm" fo:margin-right="1.27cm" fo:margin-top="0.282cm"/><style:text-properties fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic" fo:color="#404040"/></style:style>'
			.'<style:style style:name="Preformatted_20_Text" style:display-name="Preformatted Text" style:family="paragraph" style:parent-style-name="Standard" style:class="html"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%" fo:background-color="#f2f2f2"/><style:text-properties fo:font-family="\'Courier New\'" style:font-family-generic="modern" style:font-pitch="fixed" fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Table_20_Heading" style:display-name="Table Heading" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm"/><style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>'
			.'<style:style style:name="Footnote" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%"/><style:text-properties fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Endnote" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%"/><style:text-properties fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Graphics" style:family="graphic"><style:graphic-properties text:anchor-type="as-char" style:wrap="none" style:vertical-pos="top" style:vertical-rel="baseline"/></style:style>'
			.'<style:style style:name="Internet_20_link" style:display-name="Internet Link" style:family="text"><style:text-properties fo:color="#0563c1" style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"/></style:style>';
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
			$styleId = 'nyu'.(count($this->styleTable)+1).'_'.$this->escapeStyleNme(trim($oneNme));
			$this->styleTable[$nameKey] = $styleId;
			$markLst = (array)($stylSet['marks'] ?? Array());
			$fontTxt = is_scalar($stylSet['fonts'] ?? '') ? $this->escapeXmlValue(str_replace(Array("'", '"'), '', substr(trim(strval($stylSet['fonts'] ?? '')), 0, 60))) : '';
			$sizeNum = is_numeric($stylSet['sizes'] ?? null) ? $this->writeNumberTxt(round(max(1, min(400, floatval($stylSet['sizes']))), 1)) : '';
			$hexCode = Array();
			$textTxt = (($fontTxt==='') ? '' : ' fo:font-family="\''.$fontTxt.'\'"')
				.(($sizeNum==='') ? '' : ' fo:font-size="'.$sizeNum.'pt" style:font-size-asian="'.$sizeNum.'pt" style:font-size-complex="'.$sizeNum.'pt"')
				.(preg_match('/^#?([0-9A-Fa-f]{6})$/D', is_scalar($stylSet['color'] ?? '') ? strval($stylSet['color'] ?? '') : '', $hexCode) ? ' fo:color="#'.strtolower($hexCode[1]).'"' : '')
				.(in_array('boldFace', $markLst) ? ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '')
				.(in_array('emphasis', $markLst) ? ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '')
				.(in_array('undrLine', $markLst) ? ' style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"' : '')
				.(in_array('crossOut', $markLst) ? ' style:text-line-through-style="solid" style:text-line-through-type="single"' : '');
			$algnKey = is_scalar($stylSet['align'] ?? '') ? strval($stylSet['align'] ?? '') : '';
			$stylTxt .= '<style:style style:name="'.$styleId.'" style:display-name="'.$this->escapeXmlValue(substr(trim($oneNme), 0, 100)).'" style:family="paragraph" style:parent-style-name="Standard" style:class="text">'
				.(isset(self::ALGN_LIST[$algnKey]) ? '<style:paragraph-properties fo:text-align="'.self::ALGN_LIST[$algnKey].'"/>' : '').(($textTxt==='') ? '' : '<style:text-properties'.$textTxt.'/>').'</style:style>';
		}
		return $stylTxt.'</office:styles>';
	}

	/**
	 * Writes the styles made for what was formatted by hand in the part
	 * just written: those of its paragraphs and of its text, then those
	 * every part needs for its lists, its tables and its pictures.
	 *
	 * @param Array $pageMeasures The width and the height of a page, in twentieths of a point.
	 * @return String The styles.
	 */
	protected function autosPieceText(Array $pageMeasures): String {
		$autoTxt = '';
		foreach($this->autoPrgrph as $stlSet){
			$autoTxt .= '<style:style style:name="'.$stlSet[0].'" style:family="paragraph" style:parent-style-name="'.$stlSet[1].'"><style:paragraph-properties'.$stlSet[2].'/></style:style>';
		}
		foreach($this->autoStyles as $mrkKey=>$oneNme){
			$markLst = explode(',', $mrkKey);
			$autoTxt .= '<style:style style:name="'.$oneNme.'" style:family="text"><style:text-properties'
				.(in_array('boldFace', $markLst) ? ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '')
				.(in_array('emphasis', $markLst) ? ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '')
				.(in_array('undrLine', $markLst) ? ' style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"' : '')
				.(in_array('crossOut', $markLst) ? ' style:text-line-through-style="solid" style:text-line-through-type="single"' : '')
				.(in_array('codeSpan', $markLst) ? ' fo:font-family="\'Courier New\'" style:font-family-generic="modern" style:font-pitch="fixed"' : '').'/></style:style>';
		}
		// The lists: a marked one and a numbered one, each telling what it looks like at every level
		foreach(Array('LB'=>Array("\u{2022}", "\u{25E6}", "\u{25AA}"), 'LN'=>Array('1', 'a', 'i')) as $lstNme=>$lvlSet){
			$autoTxt .= '<text:list-style style:name="'.$lstNme.'">';
			for($lvlIdx=1; $lvlIdx<=self::LIST_DEEP; $lvlIdx++){
				$lvlText = $lvlSet[($lvlIdx-1)%3];
				$autoTxt .= (($lstNme=='LB') ? '<text:list-level-style-bullet text:level="'.$lvlIdx.'" text:bullet-char="'.$lvlText.'">' : '<text:list-level-style-number text:level="'.$lvlIdx.'" style:num-suffix="." style:num-format="'.$lvlText.'">')
					.'<style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" text:list-tab-stop-position="'.sprintf('%.3F', 0.635*($lvlIdx+1)).'cm" fo:text-indent="-0.635cm" fo:margin-left="'.sprintf('%.3F', 0.635*($lvlIdx+1)).'cm"/></style:list-level-properties>'
					.(($lstNme=='LB') ? '</text:list-level-style-bullet>' : '</text:list-level-style-number>');
			}
			$autoTxt .= '</text:list-style>';
		}
		// The tables: as wide as the page, their cells lined, the cells of their headings shaded. The pictures: standing in the line, as a letter does
		return $autoTxt.'<style:style style:name="Tbl" style:family="table"><style:table-properties style:width="'.sprintf('%.3F', ($pageMeasures[0]-2880)*2.54/1440).'cm" table:align="margins"/></style:style>'
			.'<style:style style:name="Cel" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="0.5pt solid #808080"/></style:style>'
			.'<style:style style:name="CelH" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="0.5pt solid #808080" fo:background-color="#f2f2f2"/></style:style>'
			.'<style:style style:name="fr1" style:family="graphic" style:parent-style-name="Graphics"><style:graphic-properties style:vertical-pos="top" style:vertical-rel="baseline" fo:padding="0cm" fo:border="none" style:mirror="none" fo:clip="rect(0cm, 0cm, 0cm, 0cm)"/></style:style>';
	}

	/**
	 * Writes a picture into the document, when its file is in the folder
	 * the document was given.
	 *
	 * @param Array $contentBlock The image: the name of its file under 'sourc', and the text that describes it.
	 * @return String The paragraph showing the picture; '' when the picture is not written.
	 */
	protected function picturePortion(Array $contentBlock): String {
		$pctrSet = $this->pictureReadArr(is_scalar($contentBlock['attrs']['sourc'] ?? '') ? strval($contentBlock['attrs']['sourc'] ?? '') : '');
		if( $pctrSet===null ){
			return '';
		}
		// The same picture is written once, however often it is shown
		$pctrKey = sha1($pctrSet['bytes']);
		if( !isset($this->mediaFiles[$pctrKey]) ){
			if( array_sum(array_map(function(Array $mediaItemArr){ return strlen($mediaItemArr[1]); }, $this->mediaFiles))+strlen($pctrSet['bytes'])>self::MEDI_MAXI ){
				return '';
			}
			$this->mediaFiles[$pctrKey] = Array('Pictures/image'.(count($this->mediaFiles)+1).'.'.$pctrSet['kinds'], $pctrSet['bytes']);
		}
		// A pixel is 15 twentieths of a point; a picture larger than its room is brought down to it, its proportions kept
		$sizRati = min(1, $this->roomLength[0]/($pctrSet['width']*15), $this->roomLength[1]/($pctrSet['heght']*15));
		$wideTxt = sprintf('%.3F', max(0.01, $pctrSet['width']*15*$sizRati*2.54/1440));
		$highTxt = sprintf('%.3F', max(0.01, $pctrSet['heght']*15*$sizRati*2.54/1440));
		$this->anchorList[] = '';
		$drwIdnt = count($this->anchorList);
		$infoTxt = $this->escapeXmlValue(trim(implode('', array_column((array)($contentBlock['spans'] ?? Array()), 'texts'))));
		$paraLst = explode('></text:p>', $this->paragraphPiece('Standard', Array(), array_intersect_key((array)($contentBlock['attrs'] ?? Array()), Array('align'=>1))), 2);
		return $paraLst[0].'><draw:frame draw:style-name="fr1" draw:name="Picture '.$drwIdnt.'" text:anchor-type="as-char" svg:width="'.$wideTxt.'cm" svg:height="'.$highTxt.'cm" draw:z-index="0">'
			.'<draw:image xlink:href="'.$this->mediaFiles[$pctrKey][0].'" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>'.(($infoTxt==='') ? '' : '<svg:desc>'.$infoTxt.'</svg:desc>').'</draw:frame></text:p>';
	}

	/**
	 * Writes blocks, one after the other.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return String What they make.
	 */
	protected function writeAllBlocks(Array $bookBlockSet): String {
		$bodyTxt = '';
		foreach($bookBlockSet as $blkArr){
			if( is_array($blkArr) && isset($blkArr['block']) ){
				$bodyTxt .= $this->blckPartString($blkArr);
			}
		}
		return $bodyTxt;
	}

	/**
	 * Writes a block as the OpenDocument content of its kind: heading, list, table, quote, code, rule, picture or paragraph.
	 *
	 * @param Array $contentBlock The block.
	 * @return String What it makes.
	 */
	protected function blckPartString(Array $contentBlock): String {
		$blckKnd = strval($contentBlock['block']);
		$attrSet = (array)($contentBlock['attrs'] ?? Array());
		$spanLst = (array)($contentBlock['spans'] ?? Array());
		$itemLst = (array)($contentBlock['items'] ?? Array());
		if( $blckKnd=='headline' ){
			$headLvl = max(1, min(6, intval($attrSet['level'] ?? 1)));
			return $this->paragraphPiece('Heading_20_'.$headLvl, $spanLst, $attrSet, $headLvl);
		}
		if( $blckKnd=='itemList' ){
			return $this->listsPieceText($contentBlock);
		}
		if( $blckKnd=='tableSet' ){
			return $this->tblePartString($contentBlock);
		}
		if( $blckKnd=='tableTop' || $blckKnd=='tableRow' ){
			// A row met outside a table is a table of that row
			return $this->tblePartString(Array('items'=>Array($contentBlock)));
		}
		if( $blckKnd=='citation' ){
			$qutText = $this->paragraphPiece('Quotations', $spanLst, $attrSet).$this->writeAllBlocks($itemLst);
			// Who is quoted follows the quote
			$citeTxt = is_scalar($attrSet['cites'] ?? '') ? trim(strval($attrSet['cites'] ?? '')) : '';
			return $qutText.(($citeTxt==='') ? '' : $this->paragraphPiece('Quotations', Array(Shape::makeASpanArray("\u{2014} ".$citeTxt)), Array('align'=>'alignRgt')));
		}
		if( $blckKnd=='codeBlck' ){
			// A paragraph for each line of code, their spaces kept
			$codeTxt = '';
			foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", implode('', array_column($spanLst, 'texts')))) as $oneLin){
				$codeTxt .= $this->paragraphPiece('Preformatted_20_Text', Array(Shape::makeASpanArray($oneLin)), Array());
			}
			return $codeTxt;
		}
		if( $blckKnd=='ruleLine' ){
			// A page ends here: the paragraph that follows starts the next one. Or a line is drawn across the page
			if( !empty($attrSet['break']) ){
				$this->breakAhead = true;
				return '';
			}
			return $this->paragraphPiece('Standard', Array(), Array('lined'=>true));
		}
		if( $blckKnd=='imageBox' ){
			$pctrTxt = $this->picturePortion($contentBlock);
			if( $pctrTxt!=='' ){
				return $pctrTxt;
			}
		}
		// A paragraph, with the style it names; and what stands for a picture that is not written: the text that describes it
		$styleId = ($blckKnd=='paragrph' && is_scalar($attrSet['style'] ?? null)) ? strval($this->styleTable[strtolower(trim(strval($attrSet['style'])))] ?? '') : '';
		return $this->paragraphPiece(($styleId==='') ? 'Standard' : $styleId, $spanLst, $attrSet).$this->writeAllBlocks($itemLst);
	}

	/**
	 * Writes a list: its entries, each holding its text, then what else
	 * it holds, a list among it being a list inside this one.
	 *
	 * @param Array $contentBlock The list.
	 * @return String The list.
	 */
	protected function listsPieceText(Array $contentBlock): String {
		$listTxt = '';
		foreach((array)($contentBlock['items'] ?? Array()) as $entArr){
			if( !is_array($entArr) ){
				continue;
			}
			$spanLst = (array)($entArr['spans'] ?? Array());
			// A box to tick is written as the sign of one, ticked or not
			if( isset($entArr['attrs']['check']) ){
				array_unshift($spanLst, Shape::makeASpanArray(empty($entArr['attrs']['check']) ? "\u{2610} " : "\u{2611} "));
			}
			$listTxt .= '<text:list-item>'.$this->paragraphPiece('Standard', $spanLst, array_intersect_key((array)($entArr['attrs'] ?? Array()), Array('tagid'=>1, 'align'=>1))).$this->writeAllBlocks((array)($entArr['items'] ?? Array())).'</text:list-item>';
		}
		return ($listTxt==='') ? '' : '<text:list text:style-name="'.(empty($contentBlock['attrs']['order']) ? 'LB' : 'LN').'">'.$listTxt.'</text:list>';
	}

	/**
	 * Writes a table. A cell covering several columns or rows says so,
	 * and the cells it covers are written as covered. The header rows
	 * starting the table are repeated at the top of each page.
	 *
	 * @param Array $contentBlock The table.
	 * @return String The table; '' when it holds no row.
	 */
	protected function tblePartString(Array $contentBlock): String {
		$headTxt = '';
		$rowsTxt = '';
		$colsMax = 0;
		// The areas still running down their columns: column => the rows left to cover and the columns covered
		$openLst = Array();
		// A table starts no page by itself: a paragraph before it does
		$prevTxt = $this->breakAhead ? $this->paragraphPiece('Standard', Array(), Array()) : '';
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
					$cellTxt .= str_repeat('<table:covered-table-cell/>', $openLst[$clmnIdx][1]);
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
				// A picture in a cell is no wider than the cell, nor higher than half a page
				$this->roomLength = Array(max(15, intdiv($roomWas[0]*min($clmnSpn, $colsAll), $colsAll)-227), intdiv($roomWas[1], 2));
				// What the cell holds: its own text, then its other blocks; a cell holds a paragraph at least
				$holdTxt = (empty($celBlk['spans']) && !empty($celBlk['items'])) ? '' : $this->paragraphPiece($asHeadr ? 'Table_20_Heading' : 'Standard', (array)($celBlk['spans'] ?? Array()), $attrSet);
				$holdTxt .= $this->writeAllBlocks((array)($celBlk['items'] ?? Array()));
				$cellTxt .= '<table:table-cell table:style-name="'.($asHeadr ? 'CelH' : 'Cel').'" office:value-type="string"'.(($clmnSpn>1) ? ' table:number-columns-spanned="'.$clmnSpn.'"' : '').(($rowSpan>1) ? ' table:number-rows-spanned="'.$rowSpan.'"' : '').'>'
					.(($holdTxt==='') ? '<text:p text:style-name="Standard"/>' : $holdTxt).'</table:table-cell>'.str_repeat('<table:covered-table-cell/>', $clmnSpn-1);
				$this->roomLength = $roomWas;
				$clmnIdx += $clmnSpn;
			}
			if( $cellTxt==='' ){
				continue;
			}
			// Only the header rows that start the table are repeated: one met further down is a row like the others
			if( $isHeadr && $rowsTxt==='' ){
				$headTxt .= '<table:table-row>'.$cellTxt.'</table:table-row>';
			}else{
				$rowsTxt .= '<table:table-row>'.$cellTxt.'</table:table-row>';
			}
			$colsMax = max($colsMax, $clmnIdx);
		}
		if( $headTxt==='' && $rowsTxt==='' ){
			return $prevTxt;
		}
		$this->tableCount++;
		return $prevTxt.'<table:table table:name="Table'.$this->tableCount.'" table:style-name="Tbl"><table:table-column table:number-columns-repeated="'.max(1, $colsMax).'"/>'
			.(($headTxt==='') ? '' : '<table:table-header-rows>'.$headTxt.'</table:table-header-rows>').$rowsTxt.'</table:table>';
	}

	/**
	 * Writes a paragraph, or a heading.
	 *
	 * @param String $styleDocName The name of its style in the document.
	 * @param Array  $textSpanList Its text, as spans.
	 * @param Array  $attributeSet What the block tells of it: how it is aligned under 'align', the name of its place under 'tagid', and whether a line is drawn under it under 'lined'.
	 * @param Int    $headsLevelNb Its level when it is a heading, 0 for a paragraph.
	 * @return String The paragraph.
	 */
	protected function paragraphPiece(String $styleDocName, Array $textSpanList, Array $attributeSet, Int $headsLevelNb=0): String {
		// What a paragraph adds to its style makes a style of its own, based on it: how it is aligned, the page it starts, the line under it
		$algnKey = is_scalar($attributeSet['align'] ?? '') ? strval($attributeSet['align'] ?? '') : '';
		$handTxt = (isset(self::ALGN_LIST[$algnKey]) ? ' fo:text-align="'.self::ALGN_LIST[$algnKey].'"' : '').($this->breakAhead ? ' fo:break-before="page"' : '').(empty($attributeSet['lined']) ? '' : ' fo:border-bottom="0.5pt solid #000000" fo:padding-bottom="0.035cm"');
		$this->breakAhead = false;
		$stylNme = $styleDocName;
		if( $handTxt!=='' ){
			$handKey = $styleDocName.'|'.$handTxt;
			if( !isset($this->autoPrgrph[$handKey]) ){
				$this->autoPrgrph[$handKey] = Array($this->autoPrefix.'P'.(count($this->autoPrgrph)+1), $styleDocName, $handTxt);
			}
			$stylNme = $this->autoPrgrph[$handKey][0];
		}
		// A place that is named can be pointed to from the rest of the document; the names of the notes are the document's to give
		$markTxt = '';
		$markNme = $this->cleanPlaceName(is_scalar($attributeSet['tagid'] ?? '') ? strval($attributeSet['tagid'] ?? '') : '');
		if( $markNme!=='' && !in_array($markNme, $this->anchorList, true) && !preg_match('/^(fnote|enote)\d+$/D', $markNme) ){
			$this->anchorList[] = $markNme;
			$markTxt = '<text:bookmark text:name="'.$markNme.'"/>';
		}
		$tagName = ($headsLevelNb>0) ? 'text:h' : 'text:p';
		return '<'.$tagName.' text:style-name="'.$stylNme.'"'.(($headsLevelNb>0) ? ' text:outline-level="'.$headsLevelNb.'"' : '').'>'.$markTxt.$this->spansPieceText($textSpanList).'</'.$tagName.'>';
	}

	/**
	 * Writes the text of a paragraph: each span with its formatting, in
	 * a link when it points to an address, as a field when the document
	 * works its text out, as a note where the text first refers to one.
	 *
	 * @param Array $textSpanList The spans.
	 * @return String The text, as written.
	 */
	protected function spansPieceText(Array $textSpanList): String {
		$fullTxt = '';
		foreach($textSpanList as $oneSpn){
			if( !is_array($oneSpn) ){
				continue;
			}
			$spanTxt = strval($oneSpn['texts'] ?? '');
			$markLst = array_values(array_intersect(Shape::MARK_LIST, (array)($oneSpn['marks'] ?? Array())));
			$linkAdr = Shape::tidyLinkString(is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
			$matches = Array();
			if( preg_match('/^#((fnote|enote)\d+)$/D', $linkAdr, $matches) && isset($this->remarkList[$matches[2]][$matches[1]]) ){
				// A note is written where the text first refers to it: the document numbers it
				if( !$this->notesMuted && !isset($this->noteRfrncs[$matches[2]][$matches[1]]) ){
					$this->noteRfrncs[$matches[2]][$matches[1]] = count($this->noteRfrncs[$matches[2]])+1;
					$fullTxt .= $this->notesPieceText($matches[2], $this->noteRfrncs[$matches[2]][$matches[1]], $this->remarkList[$matches[2]][$matches[1]]);
					continue;
				}
				// Any other reference to it is the text it shows
				$linkAdr = '';
			}
			// A field: what it asks the document for, around the text it last showed
			$fldCode = is_scalar($oneSpn['field'] ?? '') ? strval($oneSpn['field'] ?? '') : '';
			$fldName = strval(self::FLDS_LIST[$fldCode] ?? '');
			$propNme = (substr($fldCode, 0, 6)=='props:') ? trim(strval(preg_replace('/[\x00-\x1F]+/', '', substr($fldCode, 6)))) : '';
			if( $spanTxt==='' && $fldName==='' && $propNme==='' ){
				continue;
			}
			$partTxt = $this->textsPieceText(($spanTxt==='') ? '1' : $spanTxt);
			if( $fldName!=='' ){
				$partTxt = '<'.$fldName.(($fldCode=='pageNumb') ? ' text:select-page="current"' : '').'>'.$partTxt.'</'.$fldName.'>';
			}elseif( $propNme!=='' ){
				$partTxt = '<text:user-defined text:name="'.$this->escapeXmlValue($propNme).'">'.$partTxt.'</text:user-defined>';
			}
			if( !empty($markLst) ){
				$markKey = implode(',', $markLst);
				if( !isset($this->autoStyles[$markKey]) ){
					$this->autoStyles[$markKey] = $this->autoPrefix.'T'.(count($this->autoStyles)+1);
				}
				$partTxt = '<text:span text:style-name="'.$this->autoStyles[$markKey].'">'.$partTxt.'</text:span>';
			}
			// A link to a place of the document names that place
			if( $linkAdr!=='' && $linkAdr[0]=='#' ){
				$linkAdr = ($this->cleanPlaceName(substr($linkAdr, 1))==='') ? '' : '#'.$this->cleanPlaceName(substr($linkAdr, 1));
			}
			$fullTxt .= ($linkAdr==='') ? $partTxt : '<text:a xlink:type="simple" xlink:href="'.$this->escapeXmlValue($linkAdr).'" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link">'.$partTxt.'</text:a>';
		}
		return $fullTxt;
	}

	/**
	 * Writes a note, where the text refers to it: its number, then what
	 * it holds. A note holds no other note.
	 *
	 * @param String $noteTypeCode Its kind: 'fnote' for a note of the pages, 'enote' for one of the end.
	 * @param Int    $noteIdNumber The number the text knows it by.
	 * @param Array  $contentBlock The note, as a block.
	 * @return String The note.
	 */
	protected function notesPieceText(String $noteTypeCode, Int $noteIdNumber, Array $contentBlock): String {
		$keptOff = $this->notesMuted;
		$keptBrk = $this->breakAhead;
		$this->notesMuted = true;
		$this->breakAhead = false;
		$bodyTxt = $this->paragraphPiece(($noteTypeCode=='fnote') ? 'Footnote' : 'Endnote', (array)($contentBlock['spans'] ?? Array()), Array()).$this->writeAllBlocks((array)($contentBlock['items'] ?? Array()));
		$this->notesMuted = $keptOff;
		$this->breakAhead = $keptBrk;
		return '<text:note text:id="'.(($noteTypeCode=='fnote') ? 'ftn' : 'edn').$noteIdNumber.'" text:note-class="'.(($noteTypeCode=='fnote') ? 'footnote' : 'endnote').'"><text:note-citation>'.$noteIdNumber.'</text:note-citation><text:note-body>'.$bodyTxt.'</text:note-body></text:note>';
	}

	/**
	 * Writes a piece of text. A document of this kind takes several
	 * spaces for one, and those at the ends of a line for none: they are
	 * written as what they are, and so are the tabs and the line ends.
	 *
	 * @param String $sourceString The text.
	 * @return String The text, ready to stand in a paragraph.
	 */
	protected function textsPieceText(String $sourceString): String {
		$lineTxt = '';
		foreach(preg_split('/(\n|\t|^ +| +$| {2,})/', str_replace(Array("\r\n", "\r"), "\n", $sourceString), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			if( $onePrt==="\n" ){
				$lineTxt .= '<text:line-break/>';
			}elseif( $onePrt==="\t" ){
				$lineTxt .= '<text:tab/>';
			}elseif( trim($onePrt, ' ')==='' ){
				$lineTxt .= '<text:s'.((strlen($onePrt)>1) ? ' text:c="'.strlen($onePrt).'"' : '').'/>';
			}else{
				$lineTxt .= $this->escapeXmlValue($onePrt);
			}
		}
		return $lineTxt;
	}

}
