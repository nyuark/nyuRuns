<?php

namespace nyu\Data\Parsers;

// XML documents, read into a tree where every element has the same four
// keys, in the order of the document:
//
//   'tagnm' => the name of the element, with its prefix when it has one;
//   'attrs' => its attributes, name => value;
//   'value' => its text, when it holds text only;
//   'nodes' => its child elements.
//
// An element mixing text and elements keeps their order: its pieces of
// text stand among its 'nodes' under the name '#text', and its 'value'
// is empty. Comments and processing instructions are left out.
class XHml extends Base {

	/**
	 * Parses XML content into a tree of elements. An empty content gives
	 * an empty tree. The text is always given in UTF-8: an encoding the
	 * document declares is followed, and a document that declares none
	 * and is not UTF-8 is read as Windows-1252.
	 *
	 * Options:
	 *   'short' => gives the compact form instead: root name => content,
	 *              attributes under '@name', an element's own text under
	 *              '#text', and the elements of one name always as a list;
	 *   'depth' => deepest nesting accepted, 256 by default.
	 *
	 * @param String $rawContent The XML content.
	 * @param Array  $parseOptns Options: 'short', 'depth'.
	 * @return Array The envelope, of shape 'nodes'; 'infos' holds the encoding the document declared under 'encod'.
	 *
	 * @throws ParseError When the content is not well-formed XML, declares entities, or is nested too deep.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		if( trim($rawContent)==='' ){
			return $this->makeShape('nodes', Array());
		}
		// Declared entities are how an XML document reads local files or grows without end
		if( strpos($rawContent, '<!ENTITY')!==false ){
			throw new ParseError('Invalid XML content: entity declarations are not accepted');
		}
		// The text is brought to UTF-8 here, from the encoding the document declares when it
		// declares one, so that the result does not depend on what the XML reader supports
		$declEnc = 'UTF-8';
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
			$declEnc = strtoupper($declPrt[2]);
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
		$maxDpth = max(1, intval($parseOptns['depth'] ?? 256));
		$rootArr = $this->nodeArray($domDocu->documentElement, $maxDpth);
		if( !empty($parseOptns['short']) ){
			$rootArr = Array($rootArr['tagnm']=>$this->shortNode($rootArr));
		}
		return $this->makeShape('nodes', $rootArr, Array('encod'=>$declEnc));
	}

	/**
	 * Reads an element and what it holds into its four keys.
	 *
	 * @param \DOMElement $domElement The element.
	 * @param Int         $depthsLeft Levels of nesting still accepted.
	 * @return Array The element: 'tagnm', 'attrs', 'value', 'nodes'.
	 *
	 * @throws ParseError When the nesting goes deeper than accepted.
	 */
	protected function nodeArray(\DOMElement $domElement, Int $depthsLeft): Array {
		if( $depthsLeft<1 ){
			throw new ParseError('Invalid XML content: nested deeper than allowed');
		}
		$attrLst = Array();
		foreach($domElement->attributes as $atrNod){
			$attrLst[$atrNod->nodeName] = $atrNod->nodeValue;
		}
		$chldLst = Array();
		$textLst = Array();
		$hasElmt = false;
		foreach($domElement->childNodes as $chlNod){
			if( $chlNod instanceof \DOMElement ){
				$hasElmt = true;
				$chldLst[] = $this->nodeArray($chlNod, $depthsLeft-1);
			}elseif( $chlNod instanceof \DOMText ){
				// Text and CDATA; what is only spacing between elements is left out below
				$textLst[] = $chlNod->nodeValue;
				$chldLst[] = Array('tagnm'=>'#text', 'attrs'=>Array(), 'value'=>$chlNod->nodeValue, 'nodes'=>Array());
			}
		}
		$ownText = implode('', $textLst);
		if( !$hasElmt ){
			// Text only: it is the value of the element
			return Array('tagnm'=>$domElement->nodeName, 'attrs'=>$attrLst, 'value'=>$ownText, 'nodes'=>Array());
		}
		if( trim($ownText)==='' ){
			// Elements only: the spacing around them is not content
			$chldLst = array_values(array_filter($chldLst, function($childArray){ return $childArray['tagnm']!=='#text'; }));
		}
		return Array('tagnm'=>$domElement->nodeName, 'attrs'=>$attrLst, 'value'=>'', 'nodes'=>$chldLst);
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

}
