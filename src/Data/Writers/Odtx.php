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
	protected $handStyles = Array();
	// The styles made for the text formatted by hand: its formatting => the name of its style
	protected $autoStyles = Array();
	// The names given to places of the document so far
	protected $anchorList = Array();
	// The notes of the document, by their kind then their name: the block of each
	protected $remarkList = Array();
	// The notes the text has referred to so far, by their kind then their name
	protected $citedNotes = Array();
	// Whether what is being written may not refer to a note: a note may not hold another
	protected $isNoteless = false;
	// The styles of the content written as styles of the document: name in small letters => name in the document
	protected $styleTable = Array();
	// Whether the next paragraph starts a new page
	protected $needsBreak = false;
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
		$shapeNme = $this->checkShapeText($dataEnvelope, Array('block', 'sheet'), 'a document');
		$sizeDict = $this->getPageSizeDct($writeOptions);
		$this->readPictureDir($writeOptions);
		// A picture is no wider nor higher than the text of a page
		$this->roomLength = Array($sizeDict[0]-2880, $sizeDict[1]-2880-1440);
		$this->mediaFiles = Array();
		$this->autoPrefix = '';
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$blockLst = ($shapeNme=='sheet') ? $this->getSheetBlocks((array)($dataEnvelope['items'] ?? Array())) : (array)($dataEnvelope['items'] ?? Array());
		$this->handStyles = Array();
		$this->autoStyles = Array();
		$this->anchorList = Array();
		$this->citedNotes = Array('fnote'=>Array(), 'enote'=>Array());
		$this->isNoteless = false;
		$this->needsBreak = false;
		$this->tableCount = 0;
		// The notes are taken out of the text: each is written where the text refers to it
		$this->remarkList = $this->splitNotesDict($blockLst);
		$styleTxt = $this->writeStylePart((array)($infoList['frmat'] ?? Array()), $blockLst);
		$bodyText = $this->writeAllBlocks($blockLst);
		// A note the text never referred to is not lost: it ends the document, as text
		$this->isNoteless = true;
		foreach(Array('fnote', 'enote') as $oneCod){
			foreach(array_diff_key($this->remarkList[$oneCod], $this->citedNotes[$oneCod]) as $oneBlk){
				$bodyText .= $this->writeBlockPart(Array('block'=>'paragrph') + $oneBlk);
			}
		}
		// A page that was to start and has nothing to start with still starts
		if( $this->needsBreak ){
			$bodyText .= $this->writeParagraph('Standard', Array(), Array());
		}
		$autoText = $this->writeAutoStyle($sizeDict);
		// What stands at the top and at the bottom of the pages. Its styles are kept apart from those of the text,
		// under names of their own; it refers to no note, and starts no page
		$this->handStyles = Array();
		$this->autoStyles = Array();
		$this->autoPrefix = 'M';
		$this->needsBreak = false;
		$mstrText = '';
		$roomText = '';
		foreach(Array('phead'=>'header', 'pfoot'=>'footer') as $oneKey=>$oneNme){
			$pageBloc = (!empty($infoList[$oneKey]) && is_array($infoList[$oneKey])) ? $this->writeAllBlocks($infoList[$oneKey]) : '';
			if( $pageBloc!=='' ){
				$mstrText .= '<style:'.$oneNme.'>'.$pageBloc.'</style:'.$oneNme.'>';
				$roomText .= '<style:'.$oneNme.'-style><style:header-footer-properties fo:min-height="0.6cm" fo:margin-'.(($oneNme=='header') ? 'bottom' : 'top').'="0.5cm"/></style:'.$oneNme.'-style>';
			}
		}
		$mstrAuto = ($mstrText==='') ? '' : $this->writeAutoStyle($sizeDict);
		// The size of the pages and their margins, and the room kept for what stands at their top and their bottom
		$twipFunc = function(Int $twipsValNumb){ return sprintf('%.3F', $twipsValNumb*2.54/1440).'cm'; };
		$pageText = '<style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="'.$twipFunc($sizeDict[0]).'" fo:page-height="'.$twipFunc($sizeDict[1]).'" style:print-orientation="'.(empty($writeOptions['lands']) ? 'portrait' : 'landscape').'" fo:margin-top="2.54cm" fo:margin-bottom="2.54cm" fo:margin-left="2.54cm" fo:margin-right="2.54cm"/>'.$roomText.'</style:page-layout>';
		// The pictures, each written once, and told for what it is
		$pictList = Array();
		$pictText = '';
		foreach($this->mediaFiles as $oneSet){
			$pictList[$oneSet[0]] = $oneSet[1];
			$pictText .= '<manifest:file-entry manifest:full-path="'.$oneSet[0].'" manifest:media-type="'.self::PICT_LIST[substr($oneSet[0], intval(strrpos($oneSet[0], '.'))+1)].'"/>';
		}
		$headBrut = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$fileList = Array(
			'mimetype'=>self::FILE_KIND,
			'META-INF/manifest.xml'=>$headBrut.'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="'.self::FILE_KIND.'"/>'
				.'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
				.'<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'.$pictText.'</manifest:manifest>',
			'content.xml'=>$headBrut.'<office:document-content'.self::NAME_LIST.'><office:automatic-styles>'.$autoText.'</office:automatic-styles><office:body><office:text>'.$bodyText.'</office:text></office:body></office:document-content>',
			'styles.xml'=>$headBrut.'<office:document-styles'.self::NAME_LIST.'>'.$styleTxt.'<office:automatic-styles>'.$pageText.$mstrAuto.'</office:automatic-styles><office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1">'.$mstrText.'</style:master-page></office:master-styles></office:document-styles>',
			'meta.xml'=>$headBrut.$this->writeMetaPiece($infoList)
		) + $pictList;
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::makeZipFromLst($fileList, Array('mimetype'));
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
	protected function writeMetaPiece(Array $mediaDetails): String {
		$propList = (array)($mediaDetails['props'] ?? Array());
		foreach(Array('title'=>'Title', 'authr'=>'Author') as $oneKey=>$oneNme){
			if( is_scalar($mediaDetails[$oneKey] ?? null) && strval($mediaDetails[$oneKey])!=='' ){
				$propList[$oneNme] = strval($mediaDetails[$oneKey]);
			}
		}
		$metaText = '';
		foreach(Array('Title'=>'dc:title', 'Author'=>'meta:initial-creator', 'Subject'=>'dc:subject', 'Comments'=>'dc:description', 'LastSavedBy'=>'dc:creator') as $oneNme=>$oneTag){
			$metaText .= (is_scalar($propList[$oneNme] ?? null) && strval($propList[$oneNme])!=='') ? '<'.$oneTag.'>'.$this->escapeXmlValue(strval($propList[$oneNme])).'</'.$oneTag.'>' : '';
		}
		// The key words are written one by one
		foreach(is_scalar($propList['Keywords'] ?? null) ? array_filter(array_map('trim', explode(',', strval($propList['Keywords']))), 'strlen') : Array() as $oneWrd){
			$metaText .= '<meta:keyword>'.$this->escapeXmlValue($oneWrd).'</meta:keyword>';
		}
		$metaText .= '<meta:creation-date>'.gmdate('Y-m-d\TH:i:s').'</meta:creation-date>';
		foreach(array_diff_key($propList, Array('Title'=>1, 'Author'=>1, 'Subject'=>1, 'Comments'=>1, 'LastSavedBy'=>1, 'Keywords'=>1)) as $oneNme=>$onePrp){
			$nameText = trim(strval($oneNme));
			if( $nameText==='' || !is_scalar($onePrp) || (is_float($onePrp) && !is_finite($onePrp)) ){
				continue;
			}
			$metaText .= '<meta:user-defined meta:name="'.$this->escapeXmlValue($nameText).'" meta:value-type="'.(is_bool($onePrp) ? 'boolean' : ((is_int($onePrp) || is_float($onePrp)) ? 'float' : 'string')).'">'
				.(is_bool($onePrp) ? ($onePrp ? 'true' : 'false') : ((is_int($onePrp) || is_float($onePrp)) ? $this->writeNumberTxt($onePrp) : $this->escapeXmlValue($onePrp))).'</meta:user-defined>';
		}
		return '<office:document-meta'.self::NAME_LIST.'><office:meta>'.$metaText.'</office:meta></office:document-meta>';
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
		$nameText = strval(preg_replace_callback('/[^A-Za-z0-9]/', function(Array $locatedParts){ return '_'.bin2hex($locatedParts[0]).'_'; }, substr($displayLabel, 0, 100)));
		return preg_match('/^[A-Za-z]/', $nameText) ? $nameText : 'S'.$nameText;
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
	protected function writeStylePart(Array $styleFormats, Array $bookBlockSet): String {
		$styleTxt = '<office:styles>'
			.'<style:default-style style:family="paragraph"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.282cm" fo:line-height="108%"/><style:text-properties fo:font-family="Calibri" style:font-family-generic="swiss" fo:font-size="11pt" style:font-size-asian="11pt" style:font-size-complex="11pt"/></style:default-style>'
			.'<style:style style:name="Standard" style:family="paragraph" style:class="text"/>';
		foreach(self::HEAD_SIZE as $oneLvl=>$nthSze){
			$styleTxt .= '<style:style style:name="Heading_20_'.$oneLvl.'" style:display-name="Heading '.$oneLvl.'" style:family="paragraph" style:parent-style-name="Standard" style:next-style-name="Standard" style:default-outline-level="'.$oneLvl.'" style:class="text">'
				.'<style:paragraph-properties fo:margin-top="'.(($oneLvl==1) ? '0.635' : '0.353').'cm" fo:margin-bottom="0.141cm" fo:keep-with-next="always"/><style:text-properties fo:font-size="'.$nthSze.'pt" style:font-size-asian="'.$nthSze.'pt" style:font-size-complex="'.$nthSze.'pt" fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>';
		}
		$styleTxt .= '<style:style style:name="Quotations" style:family="paragraph" style:parent-style-name="Standard" style:class="html"><style:paragraph-properties fo:margin-left="1.27cm" fo:margin-right="1.27cm" fo:margin-top="0.282cm"/><style:text-properties fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic" fo:color="#404040"/></style:style>'
			.'<style:style style:name="Preformatted_20_Text" style:display-name="Preformatted Text" style:family="paragraph" style:parent-style-name="Standard" style:class="html"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%" fo:background-color="#f2f2f2"/><style:text-properties fo:font-family="\'Courier New\'" style:font-family-generic="modern" style:font-pitch="fixed" fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Table_20_Heading" style:display-name="Table Heading" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm"/><style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>'
			.'<style:style style:name="Footnote" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%"/><style:text-properties fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Endnote" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%"/><style:text-properties fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Graphics" style:family="graphic"><style:graphic-properties text:anchor-type="as-char" style:wrap="none" style:vertical-pos="top" style:vertical-rel="baseline"/></style:style>'
			.'<style:style style:name="Internet_20_link" style:display-name="Internet Link" style:family="text"><style:text-properties fo:color="#0563c1" style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"/></style:style>';
		// The styles the paragraphs name: those the content tells of, and those it only names
		$this->styleTable = Array();
		$nameList = array_map('strval', array_keys($styleFormats));
		$walkFunc = function(Array $blocksToWalk) use (&$walkFunc, &$nameList){
			foreach($blocksToWalk as $oneBlk){
				if( is_array($oneBlk) ){
					$nameList[] = (strval($oneBlk['block'] ?? '')=='paragrph' && is_scalar($oneBlk['attrs']['style'] ?? null)) ? strval($oneBlk['attrs']['style']) : '';
					$walkFunc((array)($oneBlk['items'] ?? Array()));
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
			$stylIdnt = 'nyu'.(count($this->styleTable)+1).'_'.$this->escapeStyleNme(trim($oneNme));
			$this->styleTable[$nameClef] = $stylIdnt;
			$markList = (array)($styleDct['marks'] ?? Array());
			$fontText = is_scalar($styleDct['fonts'] ?? '') ? $this->escapeXmlValue(str_replace(Array("'", '"'), '', substr(trim(strval($styleDct['fonts'] ?? '')), 0, 60))) : '';
			$sizeNumb = is_numeric($styleDct['sizes'] ?? null) ? $this->writeNumberTxt(round(max(1, min(400, floatval($styleDct['sizes']))), 1)) : '';
			$colrMatx = Array();
			$textText = (($fontText==='') ? '' : ' fo:font-family="\''.$fontText.'\'"')
				.(($sizeNumb==='') ? '' : ' fo:font-size="'.$sizeNumb.'pt" style:font-size-asian="'.$sizeNumb.'pt" style:font-size-complex="'.$sizeNumb.'pt"')
				.(preg_match('/^#?([0-9A-Fa-f]{6})$/D', is_scalar($styleDct['color'] ?? '') ? strval($styleDct['color'] ?? '') : '', $colrMatx) ? ' fo:color="#'.strtolower($colrMatx[1]).'"' : '')
				.(in_array('boldFace', $markList) ? ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '')
				.(in_array('emphasis', $markList) ? ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '')
				.(in_array('undrLine', $markList) ? ' style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"' : '')
				.(in_array('crossOut', $markList) ? ' style:text-line-through-style="solid" style:text-line-through-type="single"' : '');
			$alignKey = is_scalar($styleDct['align'] ?? '') ? strval($styleDct['align'] ?? '') : '';
			$styleTxt .= '<style:style style:name="'.$stylIdnt.'" style:display-name="'.$this->escapeXmlValue(substr(trim($oneNme), 0, 100)).'" style:family="paragraph" style:parent-style-name="Standard" style:class="text">'
				.(isset(self::ALGN_LIST[$alignKey]) ? '<style:paragraph-properties fo:text-align="'.self::ALGN_LIST[$alignKey].'"/>' : '').(($textText==='') ? '' : '<style:text-properties'.$textText.'/>').'</style:style>';
		}
		return $styleTxt.'</office:styles>';
	}

	/**
	 * Writes the styles made for what was formatted by hand in the part
	 * just written: those of its paragraphs and of its text, then those
	 * every part needs for its lists, its tables and its pictures.
	 *
	 * @param Array $pageMeasures The width and the height of a page, in twentieths of a point.
	 * @return String The styles.
	 */
	protected function writeAutoStyle(Array $pageMeasures): String {
		$autoText = '';
		foreach($this->handStyles as $oneSet){
			$autoText .= '<style:style style:name="'.$oneSet[0].'" style:family="paragraph" style:parent-style-name="'.$oneSet[1].'"><style:paragraph-properties'.$oneSet[2].'/></style:style>';
		}
		foreach($this->autoStyles as $oneKey=>$oneNme){
			$markList = explode(',', $oneKey);
			$autoText .= '<style:style style:name="'.$oneNme.'" style:family="text"><style:text-properties'
				.(in_array('boldFace', $markList) ? ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '')
				.(in_array('emphasis', $markList) ? ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '')
				.(in_array('undrLine', $markList) ? ' style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"' : '')
				.(in_array('crossOut', $markList) ? ' style:text-line-through-style="solid" style:text-line-through-type="single"' : '')
				.(in_array('codeSpan', $markList) ? ' fo:font-family="\'Courier New\'" style:font-family-generic="modern" style:font-pitch="fixed"' : '').'/></style:style>';
		}
		// The lists: a marked one and a numbered one, each telling what it looks like at every level
		foreach(Array('LB'=>Array("\u{2022}", "\u{25E6}", "\u{25AA}"), 'LN'=>Array('1', 'a', 'i')) as $oneNme=>$oneSet){
			$autoText .= '<text:list-style style:name="'.$oneNme.'">';
			for($nthLvl=1; $nthLvl<=self::LIST_DEEP; $nthLvl++){
				$levelTxt = $oneSet[($nthLvl-1)%3];
				$autoText .= (($oneNme=='LB') ? '<text:list-level-style-bullet text:level="'.$nthLvl.'" text:bullet-char="'.$levelTxt.'">' : '<text:list-level-style-number text:level="'.$nthLvl.'" style:num-suffix="." style:num-format="'.$levelTxt.'">')
					.'<style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" text:list-tab-stop-position="'.sprintf('%.3F', 0.635*($nthLvl+1)).'cm" fo:text-indent="-0.635cm" fo:margin-left="'.sprintf('%.3F', 0.635*($nthLvl+1)).'cm"/></style:list-level-properties>'
					.(($oneNme=='LB') ? '</text:list-level-style-bullet>' : '</text:list-level-style-number>');
			}
			$autoText .= '</text:list-style>';
		}
		// The tables: as wide as the page, their cells lined, the cells of their headings shaded. The pictures: standing in the line, as a letter does
		return $autoText.'<style:style style:name="Tbl" style:family="table"><style:table-properties style:width="'.sprintf('%.3F', ($pageMeasures[0]-2880)*2.54/1440).'cm" table:align="margins"/></style:style>'
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
	protected function writeImagePart(Array $contentBlock): String {
		$pictDict = $this->readPictureDct(is_scalar($contentBlock['attrs']['sourc'] ?? '') ? strval($contentBlock['attrs']['sourc'] ?? '') : '');
		if( $pictDict===null ){
			return '';
		}
		// The same picture is written once, however often it is shown
		$pictClef = sha1($pictDict['bytes']);
		if( !isset($this->mediaFiles[$pictClef]) ){
			if( array_sum(array_map(function(Array $mediaItemLst){ return strlen($mediaItemLst[1]); }, $this->mediaFiles))+strlen($pictDict['bytes'])>self::MEDI_MAXI ){
				return '';
			}
			$this->mediaFiles[$pictClef] = Array('Pictures/image'.(count($this->mediaFiles)+1).'.'.$pictDict['kinds'], $pictDict['bytes']);
		}
		// A pixel is 15 twentieths of a point; a picture larger than its room is brought down to it, its proportions kept
		$sizeRato = min(1, $this->roomLength[0]/($pictDict['width']*15), $this->roomLength[1]/($pictDict['heght']*15));
		$wideText = sprintf('%.3F', max(0.01, $pictDict['width']*15*$sizeRato*2.54/1440));
		$highText = sprintf('%.3F', max(0.01, $pictDict['heght']*15*$sizeRato*2.54/1440));
		$this->anchorList[] = '';
		$drwnIdnt = count($this->anchorList);
		$infoText = $this->escapeXmlValue(trim(implode('', array_column((array)($contentBlock['spans'] ?? Array()), 'texts'))));
		$prgrList = explode('></text:p>', $this->writeParagraph('Standard', Array(), array_intersect_key((array)($contentBlock['attrs'] ?? Array()), Array('align'=>1))), 2);
		return $prgrList[0].'><draw:frame draw:style-name="fr1" draw:name="Picture '.$drwnIdnt.'" text:anchor-type="as-char" svg:width="'.$wideText.'cm" svg:height="'.$highText.'cm" draw:z-index="0">'
			.'<draw:image xlink:href="'.$this->mediaFiles[$pictClef][0].'" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>'.(($infoText==='') ? '' : '<svg:desc>'.$infoText.'</svg:desc>').'</draw:frame></text:p>';
	}

	/**
	 * Writes blocks, one after the other.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return String What they make.
	 */
	protected function writeAllBlocks(Array $bookBlockSet): String {
		$bodyText = '';
		foreach($bookBlockSet as $oneBlk){
			if( is_array($oneBlk) && isset($oneBlk['block']) ){
				$bodyText .= $this->writeBlockPart($oneBlk);
			}
		}
		return $bodyText;
	}

	/**
	 * Writes a block as the OpenDocument content of its kind: heading, list, table, quote, code, rule, picture or paragraph.
	 *
	 * @param Array $contentBlock The block.
	 * @return String What it makes.
	 */
	protected function writeBlockPart(Array $contentBlock): String {
		$blockCod = strval($contentBlock['block']);
		$attrDict = (array)($contentBlock['attrs'] ?? Array());
		$spanList = (array)($contentBlock['spans'] ?? Array());
		$itemList = (array)($contentBlock['items'] ?? Array());
		if( $blockCod=='headline' ){
			$headLevl = max(1, min(6, intval($attrDict['level'] ?? 1)));
			return $this->writeParagraph('Heading_20_'.$headLevl, $spanList, $attrDict, $headLevl);
		}
		if( $blockCod=='itemList' ){
			return $this->writeListPiece($contentBlock);
		}
		if( $blockCod=='tableSet' ){
			return $this->writeTablePart($contentBlock);
		}
		if( $blockCod=='tableTop' || $blockCod=='tableRow' ){
			// A row met outside a table is a table of that row
			return $this->writeTablePart(Array('items'=>Array($contentBlock)));
		}
		if( $blockCod=='citation' ){
			$quoteTxt = $this->writeParagraph('Quotations', $spanList, $attrDict).$this->writeAllBlocks($itemList);
			// Who is quoted follows the quote
			$citeText = is_scalar($attrDict['cites'] ?? '') ? trim(strval($attrDict['cites'] ?? '')) : '';
			return $quoteTxt.(($citeText==='') ? '' : $this->writeParagraph('Quotations', Array(Shape::makeSpanOfText("\u{2014} ".$citeText)), Array('align'=>'alignRgt')));
		}
		if( $blockCod=='codeBlck' ){
			// A paragraph for each line of code, their spaces kept
			$codeText = '';
			foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", implode('', array_column($spanList, 'texts')))) as $oneLne){
				$codeText .= $this->writeParagraph('Preformatted_20_Text', Array(Shape::makeSpanOfText($oneLne)), Array());
			}
			return $codeText;
		}
		if( $blockCod=='ruleLine' ){
			// A page ends here: the paragraph that follows starts the next one. Or a line is drawn across the page
			if( !empty($attrDict['break']) ){
				$this->needsBreak = true;
				return '';
			}
			return $this->writeParagraph('Standard', Array(), Array('lined'=>true));
		}
		if( $blockCod=='imageBox' ){
			$pictText = $this->writeImagePart($contentBlock);
			if( $pictText!=='' ){
				return $pictText;
			}
		}
		// A paragraph, with the style it names; and what stands for a picture that is not written: the text that describes it
		$stylIdnt = ($blockCod=='paragrph' && is_scalar($attrDict['style'] ?? null)) ? strval($this->styleTable[strtolower(trim(strval($attrDict['style'])))] ?? '') : '';
		return $this->writeParagraph(($stylIdnt==='') ? 'Standard' : $stylIdnt, $spanList, $attrDict).$this->writeAllBlocks($itemList);
	}

	/**
	 * Writes a list: its entries, each holding its text, then what else
	 * it holds, a list among it being a list inside this one.
	 *
	 * @param Array $contentBlock The list.
	 * @return String The list.
	 */
	protected function writeListPiece(Array $contentBlock): String {
		$listText = '';
		foreach((array)($contentBlock['items'] ?? Array()) as $oneNtr){
			if( !is_array($oneNtr) ){
				continue;
			}
			$spanList = (array)($oneNtr['spans'] ?? Array());
			// A box to tick is written as the sign of one, ticked or not
			if( isset($oneNtr['attrs']['check']) ){
				array_unshift($spanList, Shape::makeSpanOfText(empty($oneNtr['attrs']['check']) ? "\u{2610} " : "\u{2611} "));
			}
			$listText .= '<text:list-item>'.$this->writeParagraph('Standard', $spanList, array_intersect_key((array)($oneNtr['attrs'] ?? Array()), Array('tagid'=>1, 'align'=>1))).$this->writeAllBlocks((array)($oneNtr['items'] ?? Array())).'</text:list-item>';
		}
		return ($listText==='') ? '' : '<text:list text:style-name="'.(empty($contentBlock['attrs']['order']) ? 'LB' : 'LN').'">'.$listText.'</text:list>';
	}

	/**
	 * Writes a table. A cell covering several columns or rows says so,
	 * and the cells it covers are written as covered. The header rows
	 * starting the table are repeated at the top of each page.
	 *
	 * @param Array $contentBlock The table.
	 * @return String The table; '' when it holds no row.
	 */
	protected function writeTablePart(Array $contentBlock): String {
		$headText = '';
		$rowsText = '';
		$clmnsMax = 0;
		// The areas still running down their columns: column => the rows left to cover and the columns covered
		$openList = Array();
		// A table starts no page by itself: a paragraph before it does
		$prevText = $this->needsBreak ? $this->writeParagraph('Standard', Array(), Array()) : '';
		// The columns of the table, for the room each cell leaves a picture
		$clmnsAll = 1;
		foreach((array)($contentBlock['items'] ?? Array()) as $oneRow){
			$rowClmns = 0;
			foreach((array)($oneRow['items'] ?? Array()) as $oneCel){
				$rowClmns += max(1, min(63, intval($oneCel['attrs']['colsp'] ?? 1)));
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
			foreach($cellList as $oneCel){
				while( isset($openList[$clmnIndx]) ){
					$cellText .= str_repeat('<table:covered-table-cell/>', $openList[$clmnIndx][1]);
					$spanNumb = $openList[$clmnIndx][1];
					if( --$openList[$clmnIndx][0]<1 ){
						unset($openList[$clmnIndx]);
					}
					$clmnIndx += $spanNumb;
				}
				if( !is_array($oneCel) ){
					continue;
				}
				$attrDict = (array)($oneCel['attrs'] ?? Array());
				$clmnSpan = max(1, min(63, intval($attrDict['colsp'] ?? 1)));
				$rowRange = max(1, intval($attrDict['rowsp'] ?? 1));
				if( $rowRange>1 ){
					$openList[$clmnIndx] = Array($rowRange-1, $clmnSpan);
				}
				$isHeader = ($isTopRow || !empty($attrDict['headr']));
				// A picture in a cell is no wider than the cell, nor higher than half a page
				$this->roomLength = Array(max(15, intdiv($prevRoom[0]*min($clmnSpan, $clmnsAll), $clmnsAll)-227), intdiv($prevRoom[1], 2));
				// What the cell holds: its own text, then its other blocks; a cell holds a paragraph at least
				$holdText = (empty($oneCel['spans']) && !empty($oneCel['items'])) ? '' : $this->writeParagraph($isHeader ? 'Table_20_Heading' : 'Standard', (array)($oneCel['spans'] ?? Array()), $attrDict);
				$holdText .= $this->writeAllBlocks((array)($oneCel['items'] ?? Array()));
				$cellText .= '<table:table-cell table:style-name="'.($isHeader ? 'CelH' : 'Cel').'" office:value-type="string"'.(($clmnSpan>1) ? ' table:number-columns-spanned="'.$clmnSpan.'"' : '').(($rowRange>1) ? ' table:number-rows-spanned="'.$rowRange.'"' : '').'>'
					.(($holdText==='') ? '<text:p text:style-name="Standard"/>' : $holdText).'</table:table-cell>'.str_repeat('<table:covered-table-cell/>', $clmnSpan-1);
				$this->roomLength = $prevRoom;
				$clmnIndx += $clmnSpan;
			}
			if( $cellText==='' ){
				continue;
			}
			// Only the header rows that start the table are repeated: one met further down is a row like the others
			if( $isTopRow && $rowsText==='' ){
				$headText .= '<table:table-row>'.$cellText.'</table:table-row>';
			}else{
				$rowsText .= '<table:table-row>'.$cellText.'</table:table-row>';
			}
			$clmnsMax = max($clmnsMax, $clmnIndx);
		}
		if( $headText==='' && $rowsText==='' ){
			return $prevText;
		}
		$this->tableCount++;
		return $prevText.'<table:table table:name="Table'.$this->tableCount.'" table:style-name="Tbl"><table:table-column table:number-columns-repeated="'.max(1, $clmnsMax).'"/>'
			.(($headText==='') ? '' : '<table:table-header-rows>'.$headText.'</table:table-header-rows>').$rowsText.'</table:table>';
	}

	/**
	 * Writes a paragraph, or a heading.
	 *
	 * @param String $styleDocName The name of its style in the document.
	 * @param Array  $textSpanList Its text, as spans.
	 * @param Array  $attributeSet What the block tells of it: how it is aligned under 'align', the name of its place under 'tagid', and whether a line is drawn under it under 'lined'.
	 * @param Int    $headsLvlNumb Its level when it is a heading, 0 for a paragraph.
	 * @return String The paragraph.
	 */
	protected function writeParagraph(String $styleDocName, Array $textSpanList, Array $attributeSet, Int $headsLvlNumb=0): String {
		// What a paragraph adds to its style makes a style of its own, based on it: how it is aligned, the page it starts, the line under it
		$alignKey = is_scalar($attributeSet['align'] ?? '') ? strval($attributeSet['align'] ?? '') : '';
		$handText = (isset(self::ALGN_LIST[$alignKey]) ? ' fo:text-align="'.self::ALGN_LIST[$alignKey].'"' : '').($this->needsBreak ? ' fo:break-before="page"' : '').(empty($attributeSet['lined']) ? '' : ' fo:border-bottom="0.5pt solid #000000" fo:padding-bottom="0.035cm"');
		$this->needsBreak = false;
		$styleNme = $styleDocName;
		if( $handText!=='' ){
			$handClef = $styleDocName.'|'.$handText;
			if( !isset($this->handStyles[$handClef]) ){
				$this->handStyles[$handClef] = Array($this->autoPrefix.'P'.(count($this->handStyles)+1), $styleDocName, $handText);
			}
			$styleNme = $this->handStyles[$handClef][0];
		}
		// A place that is named can be pointed to from the rest of the document; the names of the notes are the document's to give
		$markText = '';
		$markName = $this->wipePlaceTitle(is_scalar($attributeSet['tagid'] ?? '') ? strval($attributeSet['tagid'] ?? '') : '');
		if( $markName!=='' && !in_array($markName, $this->anchorList, true) && !preg_match('/^(fnote|enote)\d+$/D', $markName) ){
			$this->anchorList[] = $markName;
			$markText = '<text:bookmark text:name="'.$markName.'"/>';
		}
		$tagTitle = ($headsLvlNumb>0) ? 'text:h' : 'text:p';
		return '<'.$tagTitle.' text:style-name="'.$styleNme.'"'.(($headsLvlNumb>0) ? ' text:outline-level="'.$headsLvlNumb.'"' : '').'>'.$markText.$this->writeSpansPart($textSpanList).'</'.$tagTitle.'>';
	}

	/**
	 * Writes the text of a paragraph: each span with its formatting, in
	 * a link when it points to an address, as a field when the document
	 * works its text out, as a note where the text first refers to one.
	 *
	 * @param Array $textSpanList The spans.
	 * @return String The text, as written.
	 */
	protected function writeSpansPart(Array $textSpanList): String {
		$fullText = '';
		foreach($textSpanList as $oneSpn){
			if( !is_array($oneSpn) ){
				continue;
			}
			$spanText = strval($oneSpn['texts'] ?? '');
			$markList = array_values(array_intersect(Shape::MARK_LIST, (array)($oneSpn['marks'] ?? Array())));
			$linkAddr = Shape::wipeUnsafeAddr(is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
			$matxRows = Array();
			if( preg_match('/^#((fnote|enote)\d+)$/D', $linkAddr, $matxRows) && isset($this->remarkList[$matxRows[2]][$matxRows[1]]) ){
				// A note is written where the text first refers to it: the document numbers it
				if( !$this->isNoteless && !isset($this->citedNotes[$matxRows[2]][$matxRows[1]]) ){
					$this->citedNotes[$matxRows[2]][$matxRows[1]] = count($this->citedNotes[$matxRows[2]])+1;
					$fullText .= $this->writeNotesPart($matxRows[2], $this->citedNotes[$matxRows[2]][$matxRows[1]], $this->remarkList[$matxRows[2]][$matxRows[1]]);
					continue;
				}
				// Any other reference to it is the text it shows
				$linkAddr = '';
			}
			// A field: what it asks the document for, around the text it last showed
			$fieldCod = is_scalar($oneSpn['field'] ?? '') ? strval($oneSpn['field'] ?? '') : '';
			$fieldNme = strval(self::FLDS_LIST[$fieldCod] ?? '');
			$propName = (substr($fieldCod, 0, 6)=='props:') ? trim(strval(preg_replace('/[\x00-\x1F]+/', '', substr($fieldCod, 6)))) : '';
			if( $spanText==='' && $fieldNme==='' && $propName==='' ){
				continue;
			}
			$partText = $this->castTextForOdf(($spanText==='') ? '1' : $spanText);
			if( $fieldNme!=='' ){
				$partText = '<'.$fieldNme.(($fieldCod=='pageNumb') ? ' text:select-page="current"' : '').'>'.$partText.'</'.$fieldNme.'>';
			}elseif( $propName!=='' ){
				$partText = '<text:user-defined text:name="'.$this->escapeXmlValue($propName).'">'.$partText.'</text:user-defined>';
			}
			if( !empty($markList) ){
				$markClef = implode(',', $markList);
				if( !isset($this->autoStyles[$markClef]) ){
					$this->autoStyles[$markClef] = $this->autoPrefix.'T'.(count($this->autoStyles)+1);
				}
				$partText = '<text:span text:style-name="'.$this->autoStyles[$markClef].'">'.$partText.'</text:span>';
			}
			// A link to a place of the document names that place
			if( $linkAddr!=='' && $linkAddr[0]=='#' ){
				$linkAddr = ($this->wipePlaceTitle(substr($linkAddr, 1))==='') ? '' : '#'.$this->wipePlaceTitle(substr($linkAddr, 1));
			}
			$fullText .= ($linkAddr==='') ? $partText : '<text:a xlink:type="simple" xlink:href="'.$this->escapeXmlValue($linkAddr).'" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link">'.$partText.'</text:a>';
		}
		return $fullText;
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
	protected function writeNotesPart(String $noteTypeCode, Int $noteIdNumber, Array $contentBlock): String {
		$isMuting = $this->isNoteless;
		$keptBrek = $this->needsBreak;
		$this->isNoteless = true;
		$this->needsBreak = false;
		$bodyText = $this->writeParagraph(($noteTypeCode=='fnote') ? 'Footnote' : 'Endnote', (array)($contentBlock['spans'] ?? Array()), Array()).$this->writeAllBlocks((array)($contentBlock['items'] ?? Array()));
		$this->isNoteless = $isMuting;
		$this->needsBreak = $keptBrek;
		return '<text:note text:id="'.(($noteTypeCode=='fnote') ? 'ftn' : 'edn').$noteIdNumber.'" text:note-class="'.(($noteTypeCode=='fnote') ? 'footnote' : 'endnote').'"><text:note-citation>'.$noteIdNumber.'</text:note-citation><text:note-body>'.$bodyText.'</text:note-body></text:note>';
	}

	/**
	 * Writes a piece of text. A document of this kind takes several
	 * spaces for one, and those at the ends of a line for none: they are
	 * written as what they are, and so are the tabs and the line ends.
	 *
	 * @param String $sourceString The text.
	 * @return String The text, ready to stand in a paragraph.
	 */
	protected function castTextForOdf(String $sourceString): String {
		$lineText = '';
		foreach(preg_split('/(\n|\t|^ +| +$| {2,})/', str_replace(Array("\r\n", "\r"), "\n", $sourceString), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			if( $onePrt==="\n" ){
				$lineText .= '<text:line-break/>';
			}elseif( $onePrt==="\t" ){
				$lineText .= '<text:tab/>';
			}elseif( trim($onePrt, ' ')==='' ){
				$lineText .= '<text:s'.((strlen($onePrt)>1) ? ' text:c="'.strlen($onePrt).'"' : '').'/>';
			}else{
				$lineText .= $this->escapeXmlValue($onePrt);
			}
		}
		return $lineText;
	}

}
