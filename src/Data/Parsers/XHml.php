<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

// XML and HTML documents.
//
// Read as a tree (shape 'nodes'), every element has the same five keys,
// in the order of the document:
//
//   'tagnm' => the name of the element, with its prefix when it has one;
//   'tagid' => its id (the 'id' attribute, or 'xml:id'), '' when it has none;
//   'attrs' => its attributes, name => value; in XML, the addresses it
//              gives to the prefixes of names (xmlns, xmlns:x) among them;
//   'value' => its text, when it holds text only;
//   'nodes' => its child elements.
//
// An element mixing text and elements keeps their order: its pieces of
// text stand among its 'nodes' under the name '#text', and its 'value'
// is empty. Comments and processing instructions are left out.
//
// Read as text blocks (shape 'block'), the document gives what it says,
// as the blocks of nyu\Data\Shape: the elements that only group others
// (div, section, ...) disappear, scripts, styles and forms are left out.
//
// An XML document is read as a tree and an HTML one as text blocks,
// unless the 'shape' option asks otherwise.
class XHml extends Base {

	// HTML elements whose content is not part of the text
	const SKIPLIST = Array('script', 'style', 'head', 'title', 'meta', 'link', 'noscript', 'template', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'canvas', 'audio', 'video', 'map');
	// HTML elements that start a block, or hold blocks
	const BLCKLIST = Array(
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'blockquote', 'pre', 'hr', 'img',
		'html', 'body', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'details', 'summary', 'address', 'fieldset', 'center'
	);
	// Formatting given by the inline HTML elements
	const MARKTAGS = Array('b'=>'bold', 'strong'=>'bold', 'i'=>'ital', 'em'=>'ital', 'u'=>'undr', 'ins'=>'undr', 's'=>'strk', 'strike'=>'strk', 'del'=>'strk', 'code'=>'code', 'kbd'=>'code', 'samp'=>'code', 'tt'=>'code');
	// Stands for a line break while the spacing of a text is being tidied
	const LINEMARK = "\x1E";

	/**
	 * Parses an XML or HTML document. An empty content gives an empty
	 * result. The text is always given in UTF-8: an encoding the document
	 * declares is followed, and a document that declares none and is not
	 * UTF-8 is read as Windows-1252.
	 *
	 * Options:
	 *   'reads' => 'xml' or 'html': what the content is; told from its
	 *              first bytes when left out, XML being the default;
	 *   'shape' => 'nodes' for the tree of elements, 'block' for the text
	 *              blocks; XML gives the tree and HTML the blocks when
	 *              left out;
	 *   'short' => for a tree, gives the compact form instead: root name
	 *              => content, attributes under '@name', an element's own
	 *              text under '#text', and the elements of one name
	 *              always as a list;
	 *   'depth' => deepest nesting accepted, 256 by default;
	 *   'space' => for a tree, keeps the spacing written between two
	 *              elements as '#text' nodes, for the documents where a
	 *              space between two pieces of text counts.
	 *
	 * @param String $rawContent The document.
	 * @param Array  $parseOptns Options: 'reads', 'shape', 'short', 'depth', 'space'.
	 * @return Array The envelope, of shape 'nodes' or 'block'; 'infos' holds what the content was read as under 'reads', the encoding it declared under 'encod', for HTML the title of the page under 'title', and for an XML document that tells its kind, the name of that kind, its public name and where its rules are told under 'dtype' ('names', 'publc', 'systm').
	 *
	 * @throws ParseError When an XML content is not well-formed or declares entities, or the document is nested too deep.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		$readsAs = strtolower(strval($parseOptns['reads'] ?? ''));
		if( $readsAs!='html' && $readsAs!='xml' ){
			$readsAs = (Bina::signature(substr($rawContent, 0, 512))=='html') ? 'html' : 'xml';
		}
		$shapeAs = strtolower(strval($parseOptns['shape'] ?? ''));
		if( $shapeAs!='nodes' && $shapeAs!='block' ){
			$shapeAs = ($readsAs=='html') ? 'block' : 'nodes';
		}
		$infoArr = Array('reads'=>$readsAs, 'encod'=>'UTF-8');
		if( $readsAs=='html' ){
			$infoArr['title'] = '';
		}
		if( trim($rawContent)==='' ){
			return $this->makeShape($shapeAs, Array(), $infoArr);
		}
		$declEnc = 'UTF-8';
		$domDocu = ($readsAs=='html') ? $this->loadAHtml($rawContent, $declEnc) : $this->loadAsXml($rawContent, $declEnc);
		$infoArr['encod'] = $declEnc;
		// The kind of document an XML content says it is, and where the rules of that kind are told
		if( $readsAs=='xml' && $domDocu->doctype!==null ){
			$infoArr['dtype'] = Array('names'=>strval($domDocu->doctype->name), 'publc'=>strval($domDocu->doctype->publicId), 'systm'=>strval($domDocu->doctype->systemId));
		}
		if( $domDocu->documentElement===null ){
			return $this->makeShape($shapeAs, Array(), $infoArr);
		}
		$rootArr = $this->nodeArray($domDocu->documentElement, max(1, intval($parseOptns['depth'] ?? 256)), $readsAs=='html', !empty($parseOptns['space']));
		if( $readsAs=='html' ){
			$infoArr['title'] = $this->findTitle($rootArr);
		}
		if( $shapeAs=='block' ){
			return $this->makeShape('block', $this->tagBlocks($rootArr), $infoArr);
		}
		if( !empty($parseOptns['short']) ){
			$rootArr = Array($rootArr['tagnm']=>$this->shortNode($rootArr));
		}
		return $this->makeShape('nodes', $rootArr, $infoArr);
	}

	/**
	 * Loads an XML document.
	 *
	 * @param String $rawContent The document.
	 * @param String $declaredEn Receives the encoding the document declared, in upper case; 'UTF-8' when it declared none.
	 * @return \DOMDocument The loaded document.
	 *
	 * @throws ParseError When the content is not well-formed XML, or declares entities or an unknown encoding.
	 */
	protected function loadAsXml(String $rawContent, String &$declaredEn): \DOMDocument {
		// Declared entities are how an XML document reads local files or grows without end
		if( strpos($rawContent, '<!ENTITY')!==false ){
			throw new ParseError('Invalid XML content: entity declarations are not accepted');
		}
		// The text is brought to UTF-8 here, from the encoding the document declares when it
		// declares one, so that the result does not depend on what the XML reader supports
		$declPtn = '/^(\s*<\?xml[^>]*?\sencoding\s*=\s*["\'])([\w.:\-]+)(["\'])/i';
		$declPrt = Array();
		$utf16On = in_array(substr($rawContent, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true);
		if( !$utf16On && preg_match($declPtn, substr($rawContent, 0, 200), $declPrt) && !in_array(strtoupper($declPrt[2]), Array('UTF-8', 'UTF8')) ){
			// A declared encoding is followed, whatever it is
			$xmlText = @iconv(strtoupper($declPrt[2]), 'UTF-8//IGNORE', $rawContent);
			if( $xmlText===false ){
				throw new ParseError('Invalid XML content: unknown encoding '.strtoupper($declPrt[2]));
			}
		}else{
			// A marker at the start, UTF-8, or nothing declared: the common rules apply
			$xmlText = self::cleanText($rawContent);
		}
		if( preg_match($declPtn, substr($xmlText, 0, 200), $declPrt) ){
			$declaredEn = strtoupper($declPrt[2]);
			$xmlText = preg_replace($declPtn, '${1}UTF-8${3}', $xmlText, 1);
		}
		$domDocu = new \DOMDocument();
		$oldMode = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$loadOky = $domDocu->loadXML($xmlText, LIBXML_NONET | LIBXML_COMPACT);
		$xmlErrs = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($oldMode);
		if( !$loadOky || $domDocu->documentElement===null ){
			$frstErr = $xmlErrs[0] ?? null;
			throw new ParseError('Invalid XML content: '.($frstErr ? trim($frstErr->message).' on line '.$frstErr->line : 'not well-formed'));
		}
		return $domDocu;
	}

	/**
	 * Loads an HTML document. HTML is read as browsers read it: what is
	 * not well-formed is repaired, never refused.
	 *
	 * @param String $rawContent The document.
	 * @param String $declaredEn Receives the encoding the page declared, in upper case; 'UTF-8' when it declared none.
	 * @return \DOMDocument The loaded document.
	 */
	protected function loadAHtml(String $rawContent, String &$declaredEn): \DOMDocument {
		$metaPtn = '/(<meta\b[^>]*?\bcharset\s*=\s*["\']?)([\w.:\-]+)/i';
		$metaPrt = Array();
		$htmText = self::cleanText($rawContent);
		if( preg_match($metaPtn, substr($rawContent, 0, 2048), $metaPrt) ){
			$declaredEn = strtoupper($metaPrt[2]);
			// A declared encoding is followed when the bytes are not already UTF-8
			if( !preg_match('//u', $rawContent) && !in_array($declaredEn, Array('UTF-8', 'UTF8')) ){
				$convTxt = @iconv($declaredEn, 'UTF-8//IGNORE', $rawContent);
				$htmText = ($convTxt===false) ? $htmText : $convTxt;
			}
			// The text is UTF-8 from here on: the page must not tell the reader otherwise
			$htmText = preg_replace($metaPtn, '${1}UTF-8', $htmText);
		}
		$domDocu = new \DOMDocument();
		$oldMode = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$domDocu->loadHTML('<?xml encoding="UTF-8">'.$htmText, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($oldMode);
		return $domDocu;
	}

	/**
	 * Reads an element and what it holds into its five keys.
	 *
	 * @param \DOMElement $domElement The element.
	 * @param Int         $depthsLeft Levels of nesting still accepted.
	 * @param Bool        $readAsHtml Whether the element is one of an HTML page, where a space between two pieces of text counts.
	 * @param Bool        $keepsSpace Whether the spacing between two elements is kept, whatever they are.
	 * @return Array The element: 'tagnm', 'tagid', 'attrs', 'value', 'nodes'.
	 *
	 * @throws ParseError When the nesting goes deeper than accepted.
	 */
	protected function nodeArray(\DOMElement $domElement, Int $depthsLeft, Bool $readAsHtml=false, Bool $keepsSpace=false): Array {
		if( $depthsLeft<1 ){
			throw new ParseError('Invalid '.($readAsHtml ? 'HTML' : 'XML').' content: nested deeper than allowed');
		}
		$attrLst = Array();
		// The addresses an XML element gives to the prefixes of its names are attributes of it, though not listed as such
		if( !$readAsHtml && function_exists('simplexml_import_dom') ){
			foreach((array)simplexml_import_dom($domElement)->getDocNamespaces(false, false) as $nmsKey=>$nmsAdr){
				$attrLst[($nmsKey==='') ? 'xmlns' : 'xmlns:'.$nmsKey] = strval($nmsAdr);
			}
		}
		foreach($domElement->attributes as $atrNod){
			$attrLst[$atrNod->nodeName] = $atrNod->nodeValue;
		}
		$chldLst = Array();
		$textLst = Array();
		$hasElmt = false;
		$hasInln = false;
		foreach($domElement->childNodes as $chlNod){
			if( $chlNod instanceof \DOMElement ){
				$hasElmt = true;
				$hasInln = ($hasInln || ($readAsHtml && !in_array($chlNod->nodeName, self::BLCKLIST) && !in_array($chlNod->nodeName, self::SKIPLIST)));
				$chldLst[] = $this->nodeArray($chlNod, $depthsLeft-1, $readAsHtml, $keepsSpace);
			}elseif( $chlNod instanceof \DOMText ){
				// Text and CDATA; what is only spacing between elements is left out below
				$textLst[] = $chlNod->nodeValue;
				$chldLst[] = Array('tagnm'=>'#text', 'tagid'=>'', 'attrs'=>Array(), 'value'=>$chlNod->nodeValue, 'nodes'=>Array());
			}
		}
		$ownText = implode('', $textLst);
		$nodeArr = Array('tagnm'=>$domElement->nodeName, 'tagid'=>strval($attrLst['id'] ?? $attrLst['xml:id'] ?? ''), 'attrs'=>$attrLst, 'value'=>'', 'nodes'=>Array());
		if( !$hasElmt ){
			// Text only: it is the value of the element
			$nodeArr['value'] = $ownText;
			return $nodeArr;
		}
		if( trim($ownText)==='' && !$hasInln && !$keepsSpace ){
			// Elements only: the spacing around them is not content, unless it
			// stands between two pieces of text of an HTML page
			$chldLst = array_values(array_filter($chldLst, function($childArray){ return $childArray['tagnm']!=='#text'; }));
		}
		$nodeArr['nodes'] = $chldLst;
		return $nodeArr;
	}

	/**
	 * Gives the compact form of an element: its text alone when it has
	 * nothing else, otherwise its attributes under '@name', its own text
	 * under '#text', and its child elements grouped by name, each name
	 * always holding a list.
	 *
	 * @param Array $nodeValues The element, as nodeArray() gives it.
	 * @return mixed The text of the element, or its compact array.
	 */
	protected function shortNode(Array $nodeValues): Mixed {
		if( empty($nodeValues['attrs']) && empty($nodeValues['nodes']) ){
			return $nodeValues['value'];
		}
		$shrtArr = Array();
		foreach($nodeValues['attrs'] as $atrNme=>$atrVal){
			$shrtArr['@'.$atrNme] = $atrVal;
		}
		$ownText = $nodeValues['value'];
		foreach($nodeValues['nodes'] as $chlArr){
			if( $chlArr['tagnm']==='#text' ){
				$ownText .= $chlArr['value'];
			}else{
				$shrtArr[$chlArr['tagnm']][] = $this->shortNode($chlArr);
			}
		}
		if( trim($ownText)!=='' ){
			$shrtArr['#text'] = $ownText;
		}
		return $shrtArr;
	}

	/**
	 * Finds the title of an HTML page: the first title element of its tree.
	 *
	 * @param Array $nodeValues An element of the tree.
	 * @return String The title, its spacing tidied, '' when the page has none.
	 */
	protected function findTitle(Array $nodeValues): String {
		if( $nodeValues['tagnm']=='title' ){
			return trim(preg_replace('/\s+/', ' ', $nodeValues['value']));
		}
		foreach($nodeValues['nodes'] as $chlArr){
			// The title of a page is in its head: the body, and a drawing, may hold others
			if( in_array($chlArr['tagnm'], Array('body', 'svg', '#text')) ){
				continue;
			}
			$foundTl = $this->findTitle($chlArr);
			if( $foundTl!=='' ){
				return $foundTl;
			}
		}
		return '';
	}

	/**
	 * Gives what an element holds as a list of nodes, its own text being
	 * one of them.
	 *
	 * @param Array $nodeValues The element.
	 * @return Array Its child nodes, in order.
	 */
	protected function kidsOfTag(Array $nodeValues): Array {
		if( empty($nodeValues['nodes']) && $nodeValues['value']!=='' ){
			return Array(Array('tagnm'=>'#text', 'tagid'=>'', 'attrs'=>Array(), 'value'=>$nodeValues['value'], 'nodes'=>Array()));
		}
		return $nodeValues['nodes'];
	}

	/**
	 * Tells whether an element holds a block, at any depth.
	 *
	 * @param Array $nodeValues The element.
	 * @return Bool True when one of the elements inside it starts a block.
	 */
	protected function holdsBlck(Array $nodeValues): Bool {
		foreach($nodeValues['nodes'] as $chlArr){
			if( in_array($chlArr['tagnm'], self::BLCKLIST) || $this->holdsBlck($chlArr) ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a run of nodes into blocks: each block element gives its
	 * blocks, and the text and inline elements between two of them
	 * become a paragraph.
	 *
	 * @param Array $childNodes The nodes, in order.
	 * @return Array The blocks.
	 */
	protected function readFlows(Array $childNodes): Array {
		$blckLst = Array();
		$spnsBuf = Array();
		foreach($childNodes as $chlArr){
			$tagName = $chlArr['tagnm'];
			if( in_array($tagName, self::SKIPLIST) ){
				continue;
			}
			if( !in_array($tagName, self::BLCKLIST) && !$this->holdsBlck($chlArr) ){
				$spnsBuf = array_merge($spnsBuf, $this->inlnSpans($chlArr, Array(), ''));
				continue;
			}
			// A block starts: the text gathered so far is a paragraph of its own
			$tidySpn = $this->tidySpans($spnsBuf);
			$spnsBuf = Array();
			if( !empty($tidySpn) ){
				$blckLst[] = Shape::makeBlock('parag', $tidySpn);
			}
			$blckLst = array_merge($blckLst, in_array($tagName, self::BLCKLIST) ? $this->tagBlocks($chlArr) : $this->readFlows($this->kidsOfTag($chlArr)));
		}
		$tidySpn = $this->tidySpans($spnsBuf);
		if( !empty($tidySpn) ){
			$blckLst[] = Shape::makeBlock('parag', $tidySpn);
		}
		return $blckLst;
	}

	/**
	 * Reads an inline node into spans, each carrying the formatting and
	 * the link of the elements around it.
	 *
	 * @param Array  $nodeValues The node.
	 * @param Array  $marksArray Formatting given by the elements around it.
	 * @param String $linkTarget Address given by a link around it, '' when there is none.
	 * @return Array The spans, their spacing as written.
	 */
	protected function inlnSpans(Array $nodeValues, Array $marksArray, String $linkTarget): Array {
		$tagName = $nodeValues['tagnm'];
		if( $tagName=='#text' ){
			return Array(Shape::makeASpan($nodeValues['value'], $marksArray, $linkTarget));
		}
		if( $tagName=='br' ){
			return Array(Shape::makeASpan(self::LINEMARK, $marksArray, $linkTarget));
		}
		if( in_array($tagName, self::SKIPLIST) ){
			return Array();
		}
		if( isset(self::MARKTAGS[$tagName]) ){
			$marksArray[] = self::MARKTAGS[$tagName];
		}
		if( $tagName=='a' && isset($nodeValues['attrs']['href']) ){
			$linkTarget = Shape::cleanLink($nodeValues['attrs']['href']);
		}
		$spnsLst = Array();
		foreach($this->kidsOfTag($nodeValues) as $chlArr){
			$spnsLst = array_merge($spnsLst, $this->inlnSpans($chlArr, $marksArray, $linkTarget));
		}
		return $spnsLst;
	}

	/**
	 * Tidies the spans of a block the way a browser shows them: any run
	 * of spaces and line ends is one space, none is left at the start or
	 * at the end, a line break stays a line break, and two neighbours of
	 * the same formatting are one span.
	 *
	 * @param Array $spansArray The spans, their spacing as written.
	 * @return Array The tidied spans; none when there is no text.
	 */
	protected function tidySpans(Array $spansArray): Array {
		$tidyLst = Array();
		foreach($spansArray as $oneSpn){
			$spanTxt = preg_replace('/\s+/', ' ', $oneSpn['texts']);
			$lastIdx = count($tidyLst)-1;
			$prevTxt = ($lastIdx>=0) ? $tidyLst[$lastIdx]['texts'] : '';
			// No space at the start of the block, after a space, or after a line break
			if( $prevTxt==='' || in_array(substr($prevTxt, -1), Array(' ', self::LINEMARK)) ){
				$spanTxt = ltrim($spanTxt, ' ');
			}
			if( $spanTxt==='' ){
				continue;
			}
			if( $lastIdx>=0 && $tidyLst[$lastIdx]['marks']===$oneSpn['marks'] && $tidyLst[$lastIdx]['links']===$oneSpn['links'] ){
				$tidyLst[$lastIdx]['texts'] .= $spanTxt;
			}else{
				$tidyLst[] = Array('texts'=>$spanTxt, 'marks'=>$oneSpn['marks'], 'links'=>$oneSpn['links'], 'field'=>$oneSpn['field']);
			}
		}
		// Nothing is left at the end either, and the line breaks take their real form
		while( !empty($tidyLst) ){
			$lastIdx = count($tidyLst)-1;
			$tidyLst[$lastIdx]['texts'] = rtrim($tidyLst[$lastIdx]['texts'], ' '.self::LINEMARK);
			if( $tidyLst[$lastIdx]['texts']!=='' ){
				break;
			}
			array_pop($tidyLst);
		}
		foreach($tidyLst as $spnIdx=>$oneSpn){
			$tidyLst[$spnIdx]['texts'] = str_replace(Array(' '.self::LINEMARK, self::LINEMARK), "\n", $oneSpn['texts']);
		}
		return $tidyLst;
	}

	/**
	 * Splits what an entry, a cell or a quote holds between its own text
	 * and the blocks it contains: a first paragraph is its text.
	 *
	 * @param Array $blocksList The blocks read from it.
	 * @return Array Its spans first, then the blocks it contains.
	 */
	protected function splitHold(Array $blocksList): Array {
		if( !empty($blocksList) && $blocksList[0]['block']=='parag' ){
			return Array($blocksList[0]['spans'], array_slice($blocksList, 1));
		}
		return Array(Array(), $blocksList);
	}

	/**
	 * Reads the rows of an HTML table.
	 *
	 * @param Array $nodeValues The table element, or one of its row groups.
	 * @param Bool  $insideHead Whether the rows are those of a thead element.
	 * @return Array The rows, as 'thead' and 'tbrow' blocks.
	 */
	protected function rowsOfTbl(Array $nodeValues, Bool $insideHead=false): Array {
		$rowsLst = Array();
		foreach($nodeValues['nodes'] as $chlArr){
			if( in_array($chlArr['tagnm'], Array('thead', 'tbody', 'tfoot')) ){
				$rowsLst = array_merge($rowsLst, $this->rowsOfTbl($chlArr, $chlArr['tagnm']=='thead'));
				continue;
			}
			if( $chlArr['tagnm']!='tr' ){
				continue;
			}
			$cellLst = Array();
			$headNbr = 0;
			foreach($chlArr['nodes'] as $celArr){
				if( $celArr['tagnm']!='td' && $celArr['tagnm']!='th' ){
					continue;
				}
				$celAttr = Array();
				if( $celArr['tagnm']=='th' ){
					$headNbr++;
					$celAttr['headr'] = true;
				}
				foreach(Array('colspan'=>'colsp', 'rowspan'=>'rowsp') as $atrNme=>$atrKey){
					if( intval($celArr['attrs'][$atrNme] ?? 1)>1 ){
						$celAttr[$atrKey] = intval($celArr['attrs'][$atrNme]);
					}
				}
				$celPart = $this->splitHold($this->readFlows($this->kidsOfTag($celArr)));
				$cellLst[] = Array($celPart[0], $celPart[1], $celAttr);
			}
			// A row of the head, or a row made of header cells only, is a header row
			$headRow = ($insideHead || ($headNbr>0 && $headNbr==count($cellLst)));
			$cellBlk = Array();
			foreach($cellLst as $celSet){
				if( $headRow ){
					unset($celSet[2]['headr']);
				}
				$cellBlk[] = Shape::makeBlock('tcell', $celSet[0], $celSet[1], $celSet[2]);
			}
			$rowsLst[] = Shape::makeBlock($headRow ? 'thead' : 'tbrow', Array(), $cellBlk);
		}
		return $rowsLst;
	}

	/**
	 * Gives the text of an element as it is written, spacing included.
	 *
	 * @param Array $nodeValues The element.
	 * @return String Its text, with the text of what it holds.
	 */
	protected function rawTextOf(Array $nodeValues): String {
		if( $nodeValues['tagnm']=='br' ){
			return "\n";
		}
		$rawText = $nodeValues['value'];
		foreach($nodeValues['nodes'] as $chlArr){
			$rawText .= $this->rawTextOf($chlArr);
		}
		return $rawText;
	}

	/**
	 * Reads a block element into the blocks it gives: one for a heading
	 * or a list, several for an element that only groups others, none
	 * when it holds nothing.
	 *
	 * @param Array $nodeValues The element.
	 * @return Array The blocks.
	 */
	protected function tagBlocks(Array $nodeValues): Array {
		$tagName = $nodeValues['tagnm'];
		$idntAtr = ($nodeValues['tagid']!=='') ? Array('tagid'=>$nodeValues['tagid']) : Array();
		$blckLst = Array();
		if( preg_match('/^h([1-6])$/D', $tagName, $hdrPart) ){
			$hdrSpns = $this->tidySpans($this->inlnSpans(Array('tagnm'=>'span')+$nodeValues, Array(), ''));
			if( !empty($hdrSpns) ){
				$blckLst[] = Shape::makeBlock('headr', $hdrSpns, Array(), Array('level'=>intval($hdrPart[1])) + $idntAtr);
			}
		}elseif( $tagName=='ul' || $tagName=='ol' ){
			$entrLst = Array();
			foreach($nodeValues['nodes'] as $chlArr){
				if( $chlArr['tagnm']!='li' ){
					continue;
				}
				$entPart = $this->splitHold($this->readFlows($this->kidsOfTag($chlArr)));
				$entrLst[] = Shape::makeBlock('entry', $entPart[0], $entPart[1], ($chlArr['tagid']!=='') ? Array('tagid'=>$chlArr['tagid']) : Array());
			}
			if( !empty($entrLst) ){
				$blckLst[] = Shape::makeBlock('lists', Array(), $entrLst, Array('order'=>($tagName=='ol')) + $idntAtr);
			}
		}elseif( $tagName=='table' ){
			$rowsLst = $this->rowsOfTbl($nodeValues);
			if( !empty($rowsLst) ){
				$blckLst[] = Shape::makeBlock('table', Array(), $rowsLst, $idntAtr);
			}
		}elseif( $tagName=='blockquote' ){
			$qtePart = $this->splitHold($this->readFlows($this->kidsOfTag($nodeValues)));
			if( !empty($qtePart[0]) || !empty($qtePart[1]) ){
				$blckLst[] = Shape::makeBlock('quote', $qtePart[0], $qtePart[1], $idntAtr);
			}
		}elseif( $tagName=='pre' ){
			$codeTxt = trim($this->rawTextOf($nodeValues), "\r\n");
			$langPrt = Array();
			$codeAtr = $idntAtr;
			if( preg_match('/\blang(?:uage)?-([\w+#.\-]+)/', strval($nodeValues['nodes'][0]['attrs']['class'] ?? $nodeValues['attrs']['class'] ?? ''), $langPrt) ){
				$codeAtr = Array('langg'=>$langPrt[1]) + $codeAtr;
			}
			if( $codeTxt!=='' ){
				$blckLst[] = Shape::makeBlock('codes', Array(Shape::makeASpan($codeTxt)), Array(), $codeAtr);
			}
		}elseif( $tagName=='hr' ){
			$blckLst[] = Shape::makeBlock('rules', Array(), Array(), $idntAtr);
		}elseif( $tagName=='img' ){
			$imgSrce = Shape::cleanLink(strval($nodeValues['attrs']['src'] ?? ''));
			$imgText = trim(strval($nodeValues['attrs']['alt'] ?? ''));
			if( $imgSrce!=='' ){
				$blckLst[] = Shape::makeBlock('image', ($imgText!=='') ? Array(Shape::makeASpan($imgText)) : Array(), Array(), Array('sourc'=>$imgSrce) + $idntAtr);
			}
		}else{
			// A paragraph, or an element that only groups others: what it holds, in order
			$blckLst = $this->readFlows($this->kidsOfTag($nodeValues));
			if( !empty($blckLst) && !empty($idntAtr) && !isset($blckLst[0]['attrs']['tagid']) && $tagName=='p' ){
				$blckLst[0]['attrs'] = $blckLst[0]['attrs'] + $idntAtr;
			}
		}
		return $blckLst;
	}

}
