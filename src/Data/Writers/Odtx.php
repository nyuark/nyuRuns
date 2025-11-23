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
	const FILEKIND = 'application/vnd.oasis.opendocument.text';
	// The size of the headings, by their level, in points
	const HEADSIZE = Array(1=>20, 2=>16, 3=>14, 4=>12, 5=>11, 6=>11);
	// How a paragraph is aligned, by the code that asks for it
	const ALGNLIST = Array('lefts'=>'start', 'right'=>'end', 'centr'=>'center', 'justf'=>'justify');
	// The deepest a list goes into another
	const LISTDEEP = 10;
	// What a field stands for, by its code: the element that asks the document for it
	const FLDSLIST = Array('pgnum'=>'text:page-number', 'pgtot'=>'text:page-count', 'dates'=>'text:date', 'props:Title'=>'text:title', 'props:Subject'=>'text:subject', 'props:Keywords'=>'text:keywords', 'props:Comments'=>'text:description', 'props:Author'=>'text:initial-creator', 'props:LastSavedBy'=>'text:creator');
	// The addresses of the format, as every part declares them
	const NAMELIST = ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" office:version="1.2"';

	// The styles made for the paragraphs formatted by hand: what tells one apart => its name and what it gives, as written
	protected $autoPara = Array();
	// The styles made for the text formatted by hand: its formatting => the name of its style
	protected $autoText = Array();
	// The names given to places of the document so far
	protected $markList = Array();
	// The notes of the document, by their kind then their name: the block of each
	protected $noteList = Array();
	// The notes the text has referred to so far, by their kind then their name
	protected $noteRefs = Array();
	// Whether what is being written may not refer to a note: a note may not hold another
	protected $notesOff = false;
	// The styles of the content written as styles of the document: name in small letters => name in the document
	protected $stylList = Array();
	// Whether the next paragraph starts a new page
	protected $breakNxt = false;
	// The number of tables written so far
	protected $tblCount = 0;
	// What the names of the styles made by hand start with: nothing in the text, a letter in what stands around the pages, whose styles are kept apart
	protected $autoPref = '';
	// The pictures of the document, each written once: what tells its content apart => its name in the document, its bytes
	protected $mediaSet = Array();
	// The room a picture has on a page: its greatest width and height, in twentieths of a point
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
		$this->mediaFrom($writeOptns);
		// A picture is no wider nor higher than the text of a page
		$this->roomSize = Array($sizeSet[0]-2880, $sizeSet[1]-2880-1440);
		$this->mediaSet = Array();
		$this->autoPref = '';
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$blckLst = ($shapeAs=='sheet') ? $this->sheetsOut((array)($shapedData['items'] ?? Array())) : (array)($shapedData['items'] ?? Array());
		$this->autoPara = Array();
		$this->autoText = Array();
		$this->markList = Array();
		$this->noteRefs = Array('fnote'=>Array(), 'enote'=>Array());
		$this->notesOff = false;
		$this->breakNxt = false;
		$this->tblCount = 0;
		// The notes are taken out of the text: each is written where the text refers to it
		$this->noteList = $this->takeNotes($blckLst);
		$stylTxt = $this->stylePart((array)($infoArr['frmat'] ?? Array()), $blckLst);
		$bodyTxt = $this->blocksOut($blckLst);
		// A note the text never referred to is not lost: it ends the document, as text
		$this->notesOff = true;
		foreach(Array('fnote', 'enote') as $nteKnd){
			foreach(array_diff_key($this->noteList[$nteKnd], $this->noteRefs[$nteKnd]) as $blkArr){
				$bodyTxt .= $this->blockPart(Array('block'=>'parag') + $blkArr);
			}
		}
		// A page that was to start and has nothing to start with still starts
		if( $this->breakNxt ){
			$bodyTxt .= $this->paragPart('Standard', Array(), Array());
		}
		$autoTxt = $this->autosPart($sizeSet);
		// What stands at the top and at the bottom of the pages. Its styles are kept apart from those of the text,
		// under names of their own; it refers to no note, and starts no page
		$this->autoPara = Array();
		$this->autoText = Array();
		$this->autoPref = 'M';
		$this->breakNxt = false;
		$mastTxt = '';
		$roomTxt = '';
		foreach(Array('phead'=>'header', 'pfoot'=>'footer') as $infKey=>$pagNme){
			$pageBlk = (!empty($infoArr[$infKey]) && is_array($infoArr[$infKey])) ? $this->blocksOut($infoArr[$infKey]) : '';
			if( $pageBlk!=='' ){
				$mastTxt .= '<style:'.$pagNme.'>'.$pageBlk.'</style:'.$pagNme.'>';
				$roomTxt .= '<style:'.$pagNme.'-style><style:header-footer-properties fo:min-height="0.6cm" fo:margin-'.(($pagNme=='header') ? 'bottom' : 'top').'="0.5cm"/></style:'.$pagNme.'-style>';
			}
		}
		$mastAut = ($mastTxt==='') ? '' : $this->autosPart($sizeSet);
		// The size of the pages and their margins, and the room kept for what stands at their top and their bottom
		$sizeFnc = function(Int $twipsValue){ return sprintf('%.3F', $twipsValue*2.54/1440).'cm'; };
		$pageTxt = '<style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="'.$sizeFnc($sizeSet[0]).'" fo:page-height="'.$sizeFnc($sizeSet[1]).'" style:print-orientation="'.(empty($writeOptns['lands']) ? 'portrait' : 'landscape').'" fo:margin-top="2.54cm" fo:margin-bottom="2.54cm" fo:margin-left="2.54cm" fo:margin-right="2.54cm"/>'.$roomTxt.'</style:page-layout>';
		// The pictures, each written once, and told for what it is
		$pictLst = Array();
		$pictTxt = '';
		foreach($this->mediaSet as $medSet){
			$pictLst[$medSet[0]] = $medSet[1];
			$pictTxt .= '<manifest:file-entry manifest:full-path="'.$medSet[0].'" manifest:media-type="'.self::PICTLIST[substr($medSet[0], intval(strrpos($medSet[0], '.'))+1)].'"/>';
		}
		$headXml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$fileLst = Array(
			'mimetype'=>self::FILEKIND,
			'META-INF/manifest.xml'=>$headXml.'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="'.self::FILEKIND.'"/>'
				.'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
				.'<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'.$pictTxt.'</manifest:manifest>',
			'content.xml'=>$headXml.'<office:document-content'.self::NAMELIST.'><office:automatic-styles>'.$autoTxt.'</office:automatic-styles><office:body><office:text>'.$bodyTxt.'</office:text></office:body></office:document-content>',
			'styles.xml'=>$headXml.'<office:document-styles'.self::NAMELIST.'>'.$stylTxt.'<office:automatic-styles>'.$pageTxt.$mastAut.'</office:automatic-styles><office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1">'.$mastTxt.'</style:master-page></office:master-styles></office:document-styles>',
			'meta.xml'=>$headXml.$this->metasPart($infoArr)
		) + $pictLst;
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::makeBytes($fileLst, Array('mimetype'));
		}catch( \RuntimeException $e ){
			throw new WriteError('Document that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Writes the properties of the document: those every document has a
	 * place for, then the others, each with the kind of its value.
	 *
	 * @param Array $infosArray What is told of the document: its title under 'title', its author under 'authr', its other properties under 'props'.
	 * @return String The part holding the properties.
	 */
	protected function metasPart(Array $infosArray): String {
		$propLst = (array)($infosArray['props'] ?? Array());
		foreach(Array('title'=>'Title', 'authr'=>'Author') as $infKey=>$prpNme){
			if( is_scalar($infosArray[$infKey] ?? null) && strval($infosArray[$infKey])!=='' ){
				$propLst[$prpNme] = strval($infosArray[$infKey]);
			}
		}
		$metaTxt = '';
		foreach(Array('Title'=>'dc:title', 'Author'=>'meta:initial-creator', 'Subject'=>'dc:subject', 'Comments'=>'dc:description', 'LastSavedBy'=>'dc:creator') as $prpNme=>$prpTag){
			$metaTxt .= (is_scalar($propLst[$prpNme] ?? null) && strval($propLst[$prpNme])!=='') ? '<'.$prpTag.'>'.$this->escapeTxt(strval($propLst[$prpNme])).'</'.$prpTag.'>' : '';
		}
		// The key words are written one by one
		foreach(is_scalar($propLst['Keywords'] ?? null) ? array_filter(array_map('trim', explode(',', strval($propLst['Keywords']))), 'strlen') : Array() as $keyWrd){
			$metaTxt .= '<meta:keyword>'.$this->escapeTxt($keyWrd).'</meta:keyword>';
		}
		$metaTxt .= '<meta:creation-date>'.gmdate('Y-m-d\TH:i:s').'</meta:creation-date>';
		foreach(array_diff_key($propLst, Array('Title'=>1, 'Author'=>1, 'Subject'=>1, 'Comments'=>1, 'LastSavedBy'=>1, 'Keywords'=>1)) as $prpNme=>$prpVal){
			$nameTxt = trim(strval($prpNme));
			if( $nameTxt==='' || !is_scalar($prpVal) || (is_float($prpVal) && !is_finite($prpVal)) ){
				continue;
			}
			$metaTxt .= '<meta:user-defined meta:name="'.$this->escapeTxt($nameTxt).'" meta:value-type="'.(is_bool($prpVal) ? 'boolean' : ((is_int($prpVal) || is_float($prpVal)) ? 'float' : 'string')).'">'
				.(is_bool($prpVal) ? ($prpVal ? 'true' : 'false') : ((is_int($prpVal) || is_float($prpVal)) ? $this->numbrText($prpVal) : $this->escapeTxt($prpVal))).'</meta:user-defined>';
		}
		return '<office:document-meta'.self::NAMELIST.'><office:meta>'.$metaTxt.'</office:meta></office:document-meta>';
	}

	/**
	 * Gives a style the name it has in the document: what is neither a
	 * letter nor a digit is written as its code between two underscores,
	 * so that 'Text body' is Text_20_body.
	 *
	 * @param String $shownLabel The name of the style, as shown.
	 * @return String Its name in the document.
	 */
	protected function styleName(String $shownLabel): String {
		$nameTxt = strval(preg_replace_callback('/[^A-Za-z0-9]/', function(Array $foundParts){ return '_'.bin2hex($foundParts[0]).'_'; }, substr($shownLabel, 0, 100)));
		return preg_match('/^[A-Za-z]/', $nameTxt) ? $nameTxt : 'S'.$nameTxt;
	}

	/**
	 * Writes the styles of the document: its text, its headings, its
	 * quotes, its code, the headings of its tables and its notes, under
	 * the names LibreOffice gives them; then the styles its paragraphs
	 * name, with what the content tells of each.
	 *
	 * @param Array $formatList The styles the content tells of, name => its font under 'fonts', its size in points under 'sizes', its colour under 'color', its formatting under 'marks' and its alignment under 'align'.
	 * @param Array $blocksList The blocks of the document, for the styles their paragraphs name.
	 * @return String The styles the document names.
	 */
	protected function stylePart(Array $formatList, Array $blocksList): String {
		$stylTxt = '<office:styles>'
			.'<style:default-style style:family="paragraph"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.282cm" fo:line-height="108%"/><style:text-properties fo:font-family="Calibri" style:font-family-generic="swiss" fo:font-size="11pt" style:font-size-asian="11pt" style:font-size-complex="11pt"/></style:default-style>'
			.'<style:style style:name="Standard" style:family="paragraph" style:class="text"/>';
		foreach(self::HEADSIZE as $hdrLvl=>$sizNbr){
			$stylTxt .= '<style:style style:name="Heading_20_'.$hdrLvl.'" style:display-name="Heading '.$hdrLvl.'" style:family="paragraph" style:parent-style-name="Standard" style:next-style-name="Standard" style:default-outline-level="'.$hdrLvl.'" style:class="text">'
				.'<style:paragraph-properties fo:margin-top="'.(($hdrLvl==1) ? '0.635' : '0.353').'cm" fo:margin-bottom="0.141cm" fo:keep-with-next="always"/><style:text-properties fo:font-size="'.$sizNbr.'pt" style:font-size-asian="'.$sizNbr.'pt" style:font-size-complex="'.$sizNbr.'pt" fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>';
		}
		$stylTxt .= '<style:style style:name="Quotations" style:family="paragraph" style:parent-style-name="Standard" style:class="html"><style:paragraph-properties fo:margin-left="1.27cm" fo:margin-right="1.27cm" fo:margin-top="0.282cm"/><style:text-properties fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic" fo:color="#404040"/></style:style>'
			.'<style:style style:name="Preformatted_20_Text" style:display-name="Preformatted Text" style:family="paragraph" style:parent-style-name="Standard" style:class="html"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%" fo:background-color="#f2f2f2"/><style:text-properties fo:font-family="\'Courier New\'" style:font-family-generic="modern" style:font-pitch="fixed" fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Table_20_Heading" style:display-name="Table Heading" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm"/><style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>'
			.'<style:style style:name="Footnote" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%"/><style:text-properties fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Endnote" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"><style:paragraph-properties fo:margin-bottom="0cm" fo:line-height="100%"/><style:text-properties fo:font-size="10pt" style:font-size-asian="10pt" style:font-size-complex="10pt"/></style:style>'
			.'<style:style style:name="Graphics" style:family="graphic"><style:graphic-properties text:anchor-type="as-char" style:wrap="none" style:vertical-pos="top" style:vertical-rel="baseline"/></style:style>'
			.'<style:style style:name="Internet_20_link" style:display-name="Internet Link" style:family="text"><style:text-properties fo:color="#0563c1" style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"/></style:style>';
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
			$stylIdn = 'nyu'.(count($this->stylList)+1).'_'.$this->styleName(trim($stlNme));
			$this->stylList[$nameKey] = $stylIdn;
			$markLst = (array)($stylSet['marks'] ?? Array());
			$fontTxt = is_scalar($stylSet['fonts'] ?? '') ? $this->escapeTxt(str_replace(Array("'", '"'), '', substr(trim(strval($stylSet['fonts'] ?? '')), 0, 60))) : '';
			$sizeNbr = is_numeric($stylSet['sizes'] ?? null) ? $this->numbrText(round(max(1, min(400, floatval($stylSet['sizes']))), 1)) : '';
			$colrPrt = Array();
			$textTxt = (($fontTxt==='') ? '' : ' fo:font-family="\''.$fontTxt.'\'"')
				.(($sizeNbr==='') ? '' : ' fo:font-size="'.$sizeNbr.'pt" style:font-size-asian="'.$sizeNbr.'pt" style:font-size-complex="'.$sizeNbr.'pt"')
				.(preg_match('/^#?([0-9A-Fa-f]{6})$/D', is_scalar($stylSet['color'] ?? '') ? strval($stylSet['color'] ?? '') : '', $colrPrt) ? ' fo:color="#'.strtolower($colrPrt[1]).'"' : '')
				.(in_array('bold', $markLst) ? ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '')
				.(in_array('ital', $markLst) ? ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '')
				.(in_array('undr', $markLst) ? ' style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"' : '')
				.(in_array('strk', $markLst) ? ' style:text-line-through-style="solid" style:text-line-through-type="single"' : '');
			$algnKey = is_scalar($stylSet['align'] ?? '') ? strval($stylSet['align'] ?? '') : '';
			$stylTxt .= '<style:style style:name="'.$stylIdn.'" style:display-name="'.$this->escapeTxt(substr(trim($stlNme), 0, 100)).'" style:family="paragraph" style:parent-style-name="Standard" style:class="text">'
				.(isset(self::ALGNLIST[$algnKey]) ? '<style:paragraph-properties fo:text-align="'.self::ALGNLIST[$algnKey].'"/>' : '').(($textTxt==='') ? '' : '<style:text-properties'.$textTxt.'/>').'</style:style>';
		}
		return $stylTxt.'</office:styles>';
	}

	/**
	 * Writes the styles made for what was formatted by hand in the part
	 * just written: those of its paragraphs and of its text, then those
	 * every part needs for its lists, its tables and its pictures.
	 *
	 * @param Array $paperSizes The width and the height of a page, in twentieths of a point.
	 * @return String The styles.
	 */
	protected function autosPart(Array $paperSizes): String {
		$autoTxt = '';
		foreach($this->autoPara as $stlSet){
			$autoTxt .= '<style:style style:name="'.$stlSet[0].'" style:family="paragraph" style:parent-style-name="'.$stlSet[1].'"><style:paragraph-properties'.$stlSet[2].'/></style:style>';
		}
		foreach($this->autoText as $mrkKey=>$stlNme){
			$markLst = explode(',', $mrkKey);
			$autoTxt .= '<style:style style:name="'.$stlNme.'" style:family="text"><style:text-properties'
				.(in_array('bold', $markLst) ? ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '')
				.(in_array('ital', $markLst) ? ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '')
				.(in_array('undr', $markLst) ? ' style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"' : '')
				.(in_array('strk', $markLst) ? ' style:text-line-through-style="solid" style:text-line-through-type="single"' : '')
				.(in_array('code', $markLst) ? ' fo:font-family="\'Courier New\'" style:font-family-generic="modern" style:font-pitch="fixed"' : '').'/></style:style>';
		}
		// The lists: a marked one and a numbered one, each telling what it looks like at every level
		foreach(Array('LB'=>Array("\u{2022}", "\u{25E6}", "\u{25AA}"), 'LN'=>Array('1', 'a', 'i')) as $lstNme=>$lvlSet){
			$autoTxt .= '<text:list-style style:name="'.$lstNme.'">';
			for($lvlIdx=1; $lvlIdx<=self::LISTDEEP; $lvlIdx++){
				$lvlText = $lvlSet[($lvlIdx-1)%3];
				$autoTxt .= (($lstNme=='LB') ? '<text:list-level-style-bullet text:level="'.$lvlIdx.'" text:bullet-char="'.$lvlText.'">' : '<text:list-level-style-number text:level="'.$lvlIdx.'" style:num-suffix="." style:num-format="'.$lvlText.'">')
					.'<style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" text:list-tab-stop-position="'.sprintf('%.3F', 0.635*($lvlIdx+1)).'cm" fo:text-indent="-0.635cm" fo:margin-left="'.sprintf('%.3F', 0.635*($lvlIdx+1)).'cm"/></style:list-level-properties>'
					.(($lstNme=='LB') ? '</text:list-level-style-bullet>' : '</text:list-level-style-number>');
			}
			$autoTxt .= '</text:list-style>';
		}
		// The tables: as wide as the page, their cells lined, the cells of their headings shaded. The pictures: standing in the line, as a letter does
		return $autoTxt.'<style:style style:name="Tbl" style:family="table"><style:table-properties style:width="'.sprintf('%.3F', ($paperSizes[0]-2880)*2.54/1440).'cm" table:align="margins"/></style:style>'
			.'<style:style style:name="Cel" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="0.5pt solid #808080"/></style:style>'
			.'<style:style style:name="CelH" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="0.5pt solid #808080" fo:background-color="#f2f2f2"/></style:style>'
			.'<style:style style:name="fr1" style:family="graphic" style:parent-style-name="Graphics"><style:graphic-properties style:vertical-pos="top" style:vertical-rel="baseline" fo:padding="0cm" fo:border="none" style:mirror="none" fo:clip="rect(0cm, 0cm, 0cm, 0cm)"/></style:style>';
	}

	/**
	 * Writes a picture into the document, when its file is in the folder
	 * the document was given.
	 *
	 * @param Array $blockArray The image: the name of its file under 'sourc', and the text that describes it.
	 * @return String The paragraph showing the picture; '' when the picture is not written.
	 */
	protected function pictrPart(Array $blockArray): String {
		$pictSet = $this->pictrRead(is_scalar($blockArray['attrs']['sourc'] ?? '') ? strval($blockArray['attrs']['sourc'] ?? '') : '');
		if( $pictSet===null ){
			return '';
		}
		// The same picture is written once, however often it is shown
		$pictKey = sha1($pictSet['bytes']);
		if( !isset($this->mediaSet[$pictKey]) ){
			if( array_sum(array_map(function(Array $mediaEntry){ return strlen($mediaEntry[1]); }, $this->mediaSet))+strlen($pictSet['bytes'])>self::MEDIAMAX ){
				return '';
			}
			$this->mediaSet[$pictKey] = Array('Pictures/image'.(count($this->mediaSet)+1).'.'.$pictSet['kinds'], $pictSet['bytes']);
		}
		// A pixel is 15 twentieths of a point; a picture larger than its room is brought down to it, its proportions kept
		$sizeRto = min(1, $this->roomSize[0]/($pictSet['width']*15), $this->roomSize[1]/($pictSet['heght']*15));
		$wideTxt = sprintf('%.3F', max(0.01, $pictSet['width']*15*$sizeRto*2.54/1440));
		$highTxt = sprintf('%.3F', max(0.01, $pictSet['heght']*15*$sizeRto*2.54/1440));
		$this->markList[] = '';
		$drawIdn = count($this->markList);
		$descTxt = $this->escapeTxt(trim(implode('', array_column((array)($blockArray['spans'] ?? Array()), 'texts'))));
		$paraSet = explode('></text:p>', $this->paragPart('Standard', Array(), array_intersect_key((array)($blockArray['attrs'] ?? Array()), Array('align'=>1))), 2);
		return $paraSet[0].'><draw:frame draw:style-name="fr1" draw:name="Picture '.$drawIdn.'" text:anchor-type="as-char" svg:width="'.$wideTxt.'cm" svg:height="'.$highTxt.'cm" draw:z-index="0">'
			.'<draw:image xlink:href="'.$this->mediaSet[$pictKey][0].'" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/>'.(($descTxt==='') ? '' : '<svg:desc>'.$descTxt.'</svg:desc>').'</draw:frame></text:p>';
	}

	/**
	 * Writes blocks, one after the other.
	 *
	 * @param Array $blocksList The blocks.
	 * @return String What they make.
	 */
	protected function blocksOut(Array $blocksList): String {
		$bodyTxt = '';
		foreach($blocksList as $blkArr){
			if( is_array($blkArr) && isset($blkArr['block']) ){
				$bodyTxt .= $this->blockPart($blkArr);
			}
		}
		return $bodyTxt;
	}

	/**
	 * Writes a block as the OpenDocument content of its kind: heading, list, table, quote, code, rule, picture or paragraph.
	 *
	 * @param Array $blockArray The block.
	 * @return String What it makes.
	 */
	protected function blockPart(Array $blockArray): String {
		$blckKnd = strval($blockArray['block']);
		$attrLst = (array)($blockArray['attrs'] ?? Array());
		$spanLst = (array)($blockArray['spans'] ?? Array());
		$itemLst = (array)($blockArray['items'] ?? Array());
		if( $blckKnd=='headr' ){
			$hdrLevl = max(1, min(6, intval($attrLst['level'] ?? 1)));
			return $this->paragPart('Heading_20_'.$hdrLevl, $spanLst, $attrLst, $hdrLevl);
		}
		if( $blckKnd=='lists' ){
			return $this->listsPart($blockArray);
		}
		if( $blckKnd=='table' ){
			return $this->tablePart($blockArray);
		}
		if( $blckKnd=='thead' || $blckKnd=='tbrow' ){
			// A row met outside a table is a table of that row
			return $this->tablePart(Array('items'=>Array($blockArray)));
		}
		if( $blckKnd=='quote' ){
			$quotTxt = $this->paragPart('Quotations', $spanLst, $attrLst).$this->blocksOut($itemLst);
			// Who is quoted follows the quote
			$citeTxt = is_scalar($attrLst['cites'] ?? '') ? trim(strval($attrLst['cites'] ?? '')) : '';
			return $quotTxt.(($citeTxt==='') ? '' : $this->paragPart('Quotations', Array(Shape::makeASpan("\u{2014} ".$citeTxt)), Array('align'=>'right')));
		}
		if( $blckKnd=='codes' ){
			// A paragraph for each line of code, their spaces kept
			$codeTxt = '';
			foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", implode('', array_column($spanLst, 'texts')))) as $oneLne){
				$codeTxt .= $this->paragPart('Preformatted_20_Text', Array(Shape::makeASpan($oneLne)), Array());
			}
			return $codeTxt;
		}
		if( $blckKnd=='rules' ){
			// A page ends here: the paragraph that follows starts the next one. Or a line is drawn across the page
			if( !empty($attrLst['break']) ){
				$this->breakNxt = true;
				return '';
			}
			return $this->paragPart('Standard', Array(), Array('lined'=>true));
		}
		if( $blckKnd=='image' ){
			$pictTxt = $this->pictrPart($blockArray);
			if( $pictTxt!=='' ){
				return $pictTxt;
			}
		}
		// A paragraph, with the style it names; and what stands for a picture that is not written: the text that describes it
		$stylIdn = ($blckKnd=='parag' && is_scalar($attrLst['style'] ?? null)) ? strval($this->stylList[strtolower(trim(strval($attrLst['style'])))] ?? '') : '';
		return $this->paragPart(($stylIdn==='') ? 'Standard' : $stylIdn, $spanLst, $attrLst).$this->blocksOut($itemLst);
	}

	/**
	 * Writes a list: its entries, each holding its text, then what else
	 * it holds, a list among it being a list inside this one.
	 *
	 * @param Array $blockArray The list.
	 * @return String The list.
	 */
	protected function listsPart(Array $blockArray): String {
		$listTxt = '';
		foreach((array)($blockArray['items'] ?? Array()) as $entArr){
			if( !is_array($entArr) ){
				continue;
			}
			$spanLst = (array)($entArr['spans'] ?? Array());
			// A box to tick is written as the sign of one, ticked or not
			if( isset($entArr['attrs']['check']) ){
				array_unshift($spanLst, Shape::makeASpan(empty($entArr['attrs']['check']) ? "\u{2610} " : "\u{2611} "));
			}
			$listTxt .= '<text:list-item>'.$this->paragPart('Standard', $spanLst, array_intersect_key((array)($entArr['attrs'] ?? Array()), Array('tagid'=>1, 'align'=>1))).$this->blocksOut((array)($entArr['items'] ?? Array())).'</text:list-item>';
		}
		return ($listTxt==='') ? '' : '<text:list text:style-name="'.(empty($blockArray['attrs']['order']) ? 'LB' : 'LN').'">'.$listTxt.'</text:list>';
	}

	/**
	 * Writes a table. A cell covering several columns or rows says so,
	 * and the cells it covers are written as covered. The header rows
	 * starting the table are repeated at the top of each page.
	 *
	 * @param Array $blockArray The table.
	 * @return String The table; '' when it holds no row.
	 */
	protected function tablePart(Array $blockArray): String {
		$headTxt = '';
		$rowsTxt = '';
		$colsMax = 0;
		// The areas still running down their columns: column => the rows left to cover and the columns covered
		$openLst = Array();
		// A table starts no page by itself: a paragraph before it does
		$prevTxt = $this->breakNxt ? $this->paragPart('Standard', Array(), Array()) : '';
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
					$cellTxt .= str_repeat('<table:covered-table-cell/>', $openLst[$colIndx][1]);
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
				// A picture in a cell is no wider than the cell, nor higher than half a page
				$this->roomSize = Array(max(15, intdiv($roomWas[0]*min($colSpan, $colsAll), $colsAll)-227), intdiv($roomWas[1], 2));
				// What the cell holds: its own text, then its other blocks; a cell holds a paragraph at least
				$holdTxt = (empty($celBlk['spans']) && !empty($celBlk['items'])) ? '' : $this->paragPart($asHeadr ? 'Table_20_Heading' : 'Standard', (array)($celBlk['spans'] ?? Array()), $attrLst);
				$holdTxt .= $this->blocksOut((array)($celBlk['items'] ?? Array()));
				$cellTxt .= '<table:table-cell table:style-name="'.($asHeadr ? 'CelH' : 'Cel').'" office:value-type="string"'.(($colSpan>1) ? ' table:number-columns-spanned="'.$colSpan.'"' : '').(($rowSpan>1) ? ' table:number-rows-spanned="'.$rowSpan.'"' : '').'>'
					.(($holdTxt==='') ? '<text:p text:style-name="Standard"/>' : $holdTxt).'</table:table-cell>'.str_repeat('<table:covered-table-cell/>', $colSpan-1);
				$this->roomSize = $roomWas;
				$colIndx += $colSpan;
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
			$colsMax = max($colsMax, $colIndx);
		}
		if( $headTxt==='' && $rowsTxt==='' ){
			return $prevTxt;
		}
		$this->tblCount++;
		return $prevTxt.'<table:table table:name="Table'.$this->tblCount.'" table:style-name="Tbl"><table:table-column table:number-columns-repeated="'.max(1, $colsMax).'"/>'
			.(($headTxt==='') ? '' : '<table:table-header-rows>'.$headTxt.'</table:table-header-rows>').$rowsTxt.'</table:table>';
	}

	/**
	 * Writes a paragraph, or a heading.
	 *
	 * @param String $styleLabel The name of its style in the document.
	 * @param Array  $spansArray Its text, as spans.
	 * @param Array  $attrsArray What the block tells of it: how it is aligned under 'align', the name of its place under 'tagid', and whether a line is drawn under it under 'lined'.
	 * @param Int    $headsLevel Its level when it is a heading, 0 for a paragraph.
	 * @return String The paragraph.
	 */
	protected function paragPart(String $styleLabel, Array $spansArray, Array $attrsArray, Int $headsLevel=0): String {
		// What a paragraph adds to its style makes a style of its own, based on it: how it is aligned, the page it starts, the line under it
		$algnKey = is_scalar($attrsArray['align'] ?? '') ? strval($attrsArray['align'] ?? '') : '';
		$handTxt = (isset(self::ALGNLIST[$algnKey]) ? ' fo:text-align="'.self::ALGNLIST[$algnKey].'"' : '').($this->breakNxt ? ' fo:break-before="page"' : '').(empty($attrsArray['lined']) ? '' : ' fo:border-bottom="0.5pt solid #000000" fo:padding-bottom="0.035cm"');
		$this->breakNxt = false;
		$stylNme = $styleLabel;
		if( $handTxt!=='' ){
			$handKey = $styleLabel.'|'.$handTxt;
			if( !isset($this->autoPara[$handKey]) ){
				$this->autoPara[$handKey] = Array($this->autoPref.'P'.(count($this->autoPara)+1), $styleLabel, $handTxt);
			}
			$stylNme = $this->autoPara[$handKey][0];
		}
		// A place that is named can be pointed to from the rest of the document; the names of the notes are the document's to give
		$markTxt = '';
		$markNme = $this->placeName(is_scalar($attrsArray['tagid'] ?? '') ? strval($attrsArray['tagid'] ?? '') : '');
		if( $markNme!=='' && !in_array($markNme, $this->markList, true) && !preg_match('/^(fnote|enote)\d+$/D', $markNme) ){
			$this->markList[] = $markNme;
			$markTxt = '<text:bookmark text:name="'.$markNme.'"/>';
		}
		$tagName = ($headsLevel>0) ? 'text:h' : 'text:p';
		return '<'.$tagName.' text:style-name="'.$stylNme.'"'.(($headsLevel>0) ? ' text:outline-level="'.$headsLevel.'"' : '').'>'.$markTxt.$this->spansPart($spansArray).'</'.$tagName.'>';
	}

	/**
	 * Writes the text of a paragraph: each span with its formatting, in
	 * a link when it points to an address, as a field when the document
	 * works its text out, as a note where the text first refers to one.
	 *
	 * @param Array $spansArray The spans.
	 * @return String The text, as written.
	 */
	protected function spansPart(Array $spansArray): String {
		$fullTxt = '';
		foreach($spansArray as $oneSpn){
			if( !is_array($oneSpn) ){
				continue;
			}
			$spanTxt = strval($oneSpn['texts'] ?? '');
			$markLst = array_values(array_intersect(Shape::MARKLIST, (array)($oneSpn['marks'] ?? Array())));
			$linkAdr = Shape::cleanLink(is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
			$notePrt = Array();
			if( preg_match('/^#((fnote|enote)\d+)$/D', $linkAdr, $notePrt) && isset($this->noteList[$notePrt[2]][$notePrt[1]]) ){
				// A note is written where the text first refers to it: the document numbers it
				if( !$this->notesOff && !isset($this->noteRefs[$notePrt[2]][$notePrt[1]]) ){
					$this->noteRefs[$notePrt[2]][$notePrt[1]] = count($this->noteRefs[$notePrt[2]])+1;
					$fullTxt .= $this->notesPart($notePrt[2], $this->noteRefs[$notePrt[2]][$notePrt[1]], $this->noteList[$notePrt[2]][$notePrt[1]]);
					continue;
				}
				// Any other reference to it is the text it shows
				$linkAdr = '';
			}
			// A field: what it asks the document for, around the text it last showed
			$fldCode = is_scalar($oneSpn['field'] ?? '') ? strval($oneSpn['field'] ?? '') : '';
			$fldName = strval(self::FLDSLIST[$fldCode] ?? '');
			$propNme = (substr($fldCode, 0, 6)=='props:') ? trim(strval(preg_replace('/[\x00-\x1F]+/', '', substr($fldCode, 6)))) : '';
			if( $spanTxt==='' && $fldName==='' && $propNme==='' ){
				continue;
			}
			$partTxt = $this->textsPart(($spanTxt==='') ? '1' : $spanTxt);
			if( $fldName!=='' ){
				$partTxt = '<'.$fldName.(($fldCode=='pgnum') ? ' text:select-page="current"' : '').'>'.$partTxt.'</'.$fldName.'>';
			}elseif( $propNme!=='' ){
				$partTxt = '<text:user-defined text:name="'.$this->escapeTxt($propNme).'">'.$partTxt.'</text:user-defined>';
			}
			if( !empty($markLst) ){
				$markKey = implode(',', $markLst);
				if( !isset($this->autoText[$markKey]) ){
					$this->autoText[$markKey] = $this->autoPref.'T'.(count($this->autoText)+1);
				}
				$partTxt = '<text:span text:style-name="'.$this->autoText[$markKey].'">'.$partTxt.'</text:span>';
			}
			// A link to a place of the document names that place
			if( $linkAdr!=='' && $linkAdr[0]=='#' ){
				$linkAdr = ($this->placeName(substr($linkAdr, 1))==='') ? '' : '#'.$this->placeName(substr($linkAdr, 1));
			}
			$fullTxt .= ($linkAdr==='') ? $partTxt : '<text:a xlink:type="simple" xlink:href="'.$this->escapeTxt($linkAdr).'" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link">'.$partTxt.'</text:a>';
		}
		return $fullTxt;
	}

	/**
	 * Writes a note, where the text refers to it: its number, then what
	 * it holds. A note holds no other note.
	 *
	 * @param String $notesKinds Its kind: 'fnote' for a note of the pages, 'enote' for one of the end.
	 * @param Int    $notesNmber The number the text knows it by.
	 * @param Array  $blockArray The note, as a block.
	 * @return String The note.
	 */
	protected function notesPart(String $notesKinds, Int $notesNmber, Array $blockArray): String {
		$keptOff = $this->notesOff;
		$keptBrk = $this->breakNxt;
		$this->notesOff = true;
		$this->breakNxt = false;
		$bodyTxt = $this->paragPart(($notesKinds=='fnote') ? 'Footnote' : 'Endnote', (array)($blockArray['spans'] ?? Array()), Array()).$this->blocksOut((array)($blockArray['items'] ?? Array()));
		$this->notesOff = $keptOff;
		$this->breakNxt = $keptBrk;
		return '<text:note text:id="'.(($notesKinds=='fnote') ? 'ftn' : 'edn').$notesNmber.'" text:note-class="'.(($notesKinds=='fnote') ? 'footnote' : 'endnote').'"><text:note-citation>'.$notesNmber.'</text:note-citation><text:note-body>'.$bodyTxt.'</text:note-body></text:note>';
	}

	/**
	 * Writes a piece of text. A document of this kind takes several
	 * spaces for one, and those at the ends of a line for none: they are
	 * written as what they are, and so are the tabs and the line ends.
	 *
	 * @param String $plainTexts The text.
	 * @return String The text, ready to stand in a paragraph.
	 */
	protected function textsPart(String $plainTexts): String {
		$lineTxt = '';
		foreach(preg_split('/(\n|\t|^ +| +$| {2,})/', str_replace(Array("\r\n", "\r"), "\n", $plainTexts), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			if( $onePrt==="\n" ){
				$lineTxt .= '<text:line-break/>';
			}elseif( $onePrt==="\t" ){
				$lineTxt .= '<text:tab/>';
			}elseif( trim($onePrt, ' ')==='' ){
				$lineTxt .= '<text:s'.((strlen($onePrt)>1) ? ' text:c="'.strlen($onePrt).'"' : '').'/>';
			}else{
				$lineTxt .= $this->escapeTxt($onePrt);
			}
		}
		return $lineTxt;
	}

}
