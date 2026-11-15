<?php

namespace nyu\Data\Writers;

use nyu\Data\Fluxd;

/**
 * XML written from a tree of values, in the three ways such a tree is
 * given:
 *
 *   - the tree of elements the XML parser reads, each with its five
 *     keys ('tagnm', 'tagid', 'attrs', 'value', 'nodes'): it is written
 *     back as the document it was read from;
 *   - its compact form: the name of the root => its content, the
 *     attributes under '@name', the text of an element under '#text',
 *     and the elements of one name as a list;
 *   - any other values, as the JSON and YAML writers take them: a key
 *     is an element, a list the same element as many times.
 *
 * Every text is escaped, and every name is checked: one that XML would
 * refuse is never written. A document may tell its kind (DOCTYPE) and
 * where the rules of that kind are told; it never declares anything of
 * its own there, entities least of all.
 */
class Xmlx extends Base {

	// The deepest an element stands in others
	const DEEP_MAXI = 256;
	// What the name of an element or of an attribute is made of
	const NAME_PATT = '/^[\p{L}_][\p{L}\p{N}_.\-]*(:[\p{L}_][\p{L}\p{N}_.\-]*)?$/Du';
	// The name given to an element whose key is not one XML takes, and to the values of a list without a name
	const ITEM_NAME = 'item';

	// Whether the document is written over several lines, each element further in than the one holding it
	protected $isIndented = false;

	/**
	 * Writes a tree of values as XML.
	 *
	 * Options:
	 *   'roots' => the name of the element holding everything, for values
	 *              that do not name it themselves; 'root' when left out;
	 *   'prety' => writes the document over several lines, each element
	 *              further in than the one holding it; an element mixing
	 *              text and elements is left on one line, since a space
	 *              added to it would be part of its text;
	 *   'heads' => starts the document with the line telling it is XML,
	 *              and written in UTF-8; on unless told otherwise;
	 *   'dtype' => the kind of the document (DOCTYPE): where its rules
	 *              are told as a text, or the name of the kind under
	 *              'names', its public name under 'publc' and where its
	 *              rules are told under 'systm'; the one the content was
	 *              read with, under 'dtype' in its 'infos', when left
	 *              out; false to write none.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'roots', 'prety', 'heads', 'dtype'.
	 * @return String The XML.
	 *
	 * @throws WriteError When the shape is not 'nodes', a name is not one XML takes, the kind of the document cannot be written, or the tree goes deeper than accepted.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('nodes'), 'XML');
		$this->isIndented = !empty($writeOptions['prety']);
		$itemVals = Fluxd::dropAllSources($dataEnvelope['items'] ?? null);
		$rootName = strval($writeOptions['roots'] ?? '');
		if( $rootName!=='' ){
			$this->checkNameChars($rootName);
		}
		if( is_array($itemVals) && isset($itemVals['tagnm']) && array_key_exists('nodes', $itemVals) && $rootName==='' ){
			// The tree of elements, as the parser reads it
			$rootName = strval($itemVals['tagnm']);
			$bodyText = $this->writeTreeNodes($itemVals, 0);
		}elseif( is_array($itemVals) && $rootName==='' && count($itemVals)==1 && is_string(array_key_first($itemVals)) && preg_match(self::NAME_PATT, strval(array_key_first($itemVals))) && !(is_array(current($itemVals)) && !empty(current($itemVals)) && $this->isSequenceList(current($itemVals))) ){
			// The compact form: the one key of the values names the root
			$rootName = strval(array_key_first($itemVals));
			$bodyText = $this->writeValueNode($rootName, current($itemVals), 0);
		}else{
			$rootName = ($rootName==='') ? 'root' : $rootName;
			$bodyText = $this->writeValueNode($rootName, $itemVals, 0);
		}
		$typeText = $this->makeDoctypeTxt(array_key_exists('dtype', $writeOptions) ? $writeOptions['dtype'] : (((array)($dataEnvelope['infos'] ?? Array()))['dtype'] ?? false), $rootName);
		$withHdrs = !isset($writeOptions['heads']) || !empty($writeOptions['heads']);
		return ($withHdrs ? '<?xml version="1.0" encoding="UTF-8"?>'."\n" : '').(($typeText==='') ? '' : $typeText."\n").$bodyText.(($this->isIndented || $withHdrs) ? "\n" : '');
	}

	/**
	 * Checks the name of an element or of an attribute.
	 *
	 * @param String $elementLabel The name.
	 * @return String The name, as given.
	 *
	 * @throws WriteError When the name is not one XML takes.
	 */
	protected function checkNameChars(String $elementLabel): String {
		if( !preg_match(self::NAME_PATT, $elementLabel) ){
			throw new WriteError('Name that XML does not take: '.substr(strval(preg_replace('/[^\x20-\x7E]+/', '?', $elementLabel)), 0, 60));
		}
		return $elementLabel;
	}

	/**
	 * Writes the kind of the document, and where the rules of that kind
	 * are told. Nothing is ever declared there by the document itself.
	 *
	 * @param mixed  $doctypeValue What is told of the kind: where its rules are told, as a text; its name, its public name and where its rules are told, under 'names', 'publc' and 'systm'; false or nothing for none.
	 * @param String $rootTagLabel The name of the root of the document, which is the name of its kind when none is told.
	 * @return String The line telling the kind, '' when there is none.
	 *
	 * @throws WriteError When what is told of the kind cannot be written.
	 */
	protected function makeDoctypeTxt(Mixed $doctypeValue, String $rootTagLabel): String {
		if( $doctypeValue===false || $doctypeValue===null || $doctypeValue==='' || $doctypeValue===Array() ){
			return '';
		}
		$typeDict = is_array($doctypeValue) ? $doctypeValue : Array('systm'=>strval($doctypeValue));
		$typeName = $this->checkNameChars((strval($typeDict['names'] ?? '')==='') ? $rootTagLabel : strval($typeDict['names']));
		$publText = strval($typeDict['publc'] ?? '');
		$dtdTrail = strval($typeDict['systm'] ?? '');
		// A public name is made of few signs; where the rules are told is an address, written between quotes it does not hold
		if( !preg_match('/^[A-Za-z0-9 \-\'()+,.\/:=?;!*#@$_%]*$/D', $publText) || preg_match('/["\x00-\x1F<>]/', $dtdTrail) || strlen($publText)>500 || strlen($dtdTrail)>2000 ){
			throw new WriteError('Kind of document that cannot be written: its names hold what such a line does not take');
		}
		if( $publText!=='' ){
			return '<!DOCTYPE '.$typeName.' PUBLIC "'.$publText.'" "'.$dtdTrail.'">';
		}
		return '<!DOCTYPE '.$typeName.(($dtdTrail==='') ? '' : ' SYSTEM "'.$dtdTrail.'"').'>';
	}

	/**
	 * Writes the attributes of an element.
	 *
	 * @param Array $attributeSet The attributes, name => value.
	 * @return String The attributes, each after a space.
	 *
	 * @throws WriteError When a name is not one XML takes.
	 */
	protected function writeAttrbText(Array $attributeSet): String {
		$xmlAttrb = '';
		foreach($attributeSet as $atrNme=>$atrVal){
			if( $atrVal===null || is_array($atrVal) || is_object($atrVal) ){
				continue;
			}
			$xmlAttrb .= ' '.$this->checkNameChars(strval($atrNme)).'="'.str_replace(Array("\t", "\n", "\r"), Array('&#9;', '&#10;', '&#13;'), $this->escapeXmlValue($this->writeScalarTxt($atrVal))).'"';
		}
		return $xmlAttrb;
	}

	/**
	 * Gives a single value as the text XML holds for it.
	 *
	 * @param mixed $valueAsGiven The value.
	 * @return String Its text: true or false for a boolean, a number with the digits it needs and no more.
	 */
	protected function writeScalarTxt(Mixed $valueAsGiven): String {
		if( is_bool($valueAsGiven) ){
			return $valueAsGiven ? 'true' : 'false';
		}
		if( is_int($valueAsGiven) || is_float($valueAsGiven) ){
			return is_finite(floatval($valueAsGiven)) ? $this->writeNumberTxt($valueAsGiven) : '';
		}
		return is_scalar($valueAsGiven) ? strval($valueAsGiven) : '';
	}

	/**
	 * Gives what starts a line of an element, when the document is
	 * written over several lines.
	 *
	 * @param Int $depthLvlNumb How far in the element stands.
	 * @return String The end of the line before it and what sets it in; '' when the document is written on one line.
	 */
	protected function getIndentOfLvl(Int $depthLvlNumb): String {
		return $this->isIndented ? "\n".str_repeat('  ', $depthLvlNumb) : '';
	}

	/**
	 * Writes an element of the tree the parser reads, with what it holds.
	 *
	 * @param Array $elementArray The element: 'tagnm', 'attrs', 'value', 'nodes'.
	 * @param Int   $depthLvlNumb How far in it stands.
	 * @return String The element.
	 *
	 * @throws WriteError When a name is not one XML takes, or the tree goes deeper than accepted.
	 */
	protected function writeTreeNodes(Array $elementArray, Int $depthLvlNumb): String {
		if( $depthLvlNumb>self::DEEP_MAXI ){
			throw new WriteError('Tree nested deeper than the '.self::DEEP_MAXI.' levels allowed');
		}
		$tagTitle = $this->checkNameChars(strval($elementArray['tagnm'] ?? ''));
		$openText = '<'.$tagTitle.$this->writeAttrbText((array)($elementArray['attrs'] ?? Array()));
		$childLst = array_values(array_filter((array)($elementArray['nodes'] ?? Array()), 'is_array'));
		if( empty($childLst) ){
			$textText = $this->escapeXmlValue(is_scalar($elementArray['value'] ?? '') ? strval($elementArray['value'] ?? '') : '');
			return ($textText==='') ? $openText.'/>' : $openText.'>'.$textText.'</'.$tagTitle.'>';
		}
		// An element mixing text and elements is written as it is: a space added to it would be part of its text
		$hasMixed = in_array('#text', array_column($childLst, 'tagnm'), true);
		$wasMulti = $this->isIndented;
		$this->isIndented = ($wasMulti && !$hasMixed);
		$holdText = '';
		foreach($childLst as $chlArr){
			$holdText .= (strval($chlArr['tagnm'] ?? '')==='#text') ? $this->escapeXmlValue(is_scalar($chlArr['value'] ?? '') ? strval($chlArr['value'] ?? '') : '') : $this->getIndentOfLvl($depthLvlNumb+1).$this->writeTreeNodes($chlArr, $depthLvlNumb+1);
		}
		$holdText .= $this->getIndentOfLvl($depthLvlNumb);
		$this->isIndented = $wasMulti;
		return $openText.'>'.$holdText.'</'.$tagTitle.'>';
	}

	/**
	 * Writes a value as an element, with what it holds: a single value
	 * is its text; in a list of named values, a key starting with '@' is
	 * one of its attributes, '#text' its own text, any other key an
	 * element it holds, written as many times as the list it is given.
	 *
	 * @param String $elementLabel The name of the element; one XML does not take is written as an element named 'item', telling the name under 'key'.
	 * @param mixed  $valueAsGiven The value.
	 * @param Int    $depthLvlNumb How far in the element stands.
	 * @return String The element.
	 *
	 * @throws WriteError When the name of an attribute is not one XML takes, or the values go deeper than accepted.
	 */
	protected function writeValueNode(String $elementLabel, Mixed $valueAsGiven, Int $depthLvlNumb): String {
		if( $depthLvlNumb>self::DEEP_MAXI ){
			throw new WriteError('Tree nested deeper than the '.self::DEEP_MAXI.' levels allowed');
		}
		$tagTitle = $elementLabel;
		$attrDict = Array();
		if( !preg_match(self::NAME_PATT, $elementLabel) ){
			$tagTitle = self::ITEM_NAME;
			$attrDict['key'] = $elementLabel;
		}
		if( is_object($valueAsGiven) ){
			$valueAsGiven = (array)$valueAsGiven;
		}
		if( !is_array($valueAsGiven) ){
			$textText = $this->escapeXmlValue($this->writeScalarTxt($valueAsGiven));
			return '<'.$tagTitle.$this->writeAttrbText($attrDict).(($textText==='') ? '/>' : '>'.$textText.'</'.$tagTitle.'>');
		}
		$holdText = '';
		$textText = '';
		foreach($valueAsGiven as $subKey=>$subVal){
			$keyStrng = strval($subKey);
			if( is_string($subKey) && $keyStrng!=='' && $keyStrng[0]=='@' && !is_array($subVal) && !is_object($subVal) ){
				$attrDict[substr($keyStrng, 1)] = $subVal;
			}elseif( $keyStrng==='#text' && !is_array($subVal) && !is_object($subVal) ){
				$textText .= $this->escapeXmlValue($this->writeScalarTxt($subVal));
			}elseif( is_int($subKey) ){
				// A value of a list without a name
				$holdText .= $this->getIndentOfLvl($depthLvlNumb+1).$this->writeValueNode(self::ITEM_NAME, $subVal, $depthLvlNumb+1);
			}elseif( is_array($subVal) && !empty($subVal) && $this->isSequenceList($subVal) ){
				// A list under a name: the element of that name, as many times
				foreach($subVal as $oneVal){
					$holdText .= $this->getIndentOfLvl($depthLvlNumb+1).$this->writeValueNode($keyStrng, $oneVal, $depthLvlNumb+1);
				}
			}else{
				$holdText .= $this->getIndentOfLvl($depthLvlNumb+1).$this->writeValueNode($keyStrng, $subVal, $depthLvlNumb+1);
			}
		}
		$openText = '<'.$tagTitle.$this->writeAttrbText($attrDict);
		if( $holdText==='' ){
			return ($textText==='') ? $openText.'/>' : $openText.'>'.$textText.'</'.$tagTitle.'>';
		}
		return $openText.'>'.$textText.$holdText.$this->getIndentOfLvl($depthLvlNumb).'</'.$tagTitle.'>';
	}

}
