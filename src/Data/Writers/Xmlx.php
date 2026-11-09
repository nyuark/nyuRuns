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
	const NAME_PTRN = '/^[\p{L}_][\p{L}\p{N}_.\-]*(:[\p{L}_][\p{L}\p{N}_.\-]*)?$/Du';
	// The name given to an element whose key is not one XML takes, and to the values of a list without a name
	const ITEM_NAME = 'item';

	// Whether the document is written over several lines, each element further in than the one holding it
	protected $multiLines = false;

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
		$this->multiLines = !empty($writeOptions['prety']);
		$itemVal = Fluxd::nullifySources($dataEnvelope['items'] ?? null);
		$rootNme = strval($writeOptions['roots'] ?? '');
		if( $rootNme!=='' ){
			$this->checkNameChars($rootNme);
		}
		if( is_array($itemVal) && isset($itemVal['tagnm']) && array_key_exists('nodes', $itemVal) && $rootNme==='' ){
			// The tree of elements, as the parser reads it
			$rootNme = strval($itemVal['tagnm']);
			$bodyTxt = $this->treesPieceText($itemVal, 0);
		}elseif( is_array($itemVal) && $rootNme==='' && count($itemVal)==1 && is_string(array_key_first($itemVal)) && preg_match(self::NAME_PTRN, strval(array_key_first($itemVal))) && !(is_array(current($itemVal)) && !empty(current($itemVal)) && $this->validateALstOf(current($itemVal))) ){
			// The compact form: the one key of the values names the root
			$rootNme = strval(array_key_first($itemVal));
			$bodyTxt = $this->valuePieceText($rootNme, current($itemVal), 0);
		}else{
			$rootNme = ($rootNme==='') ? 'root' : $rootNme;
			$bodyTxt = $this->valuePieceText($rootNme, $itemVal, 0);
		}
		$typeTxt = $this->dtypePieceText(array_key_exists('dtype', $writeOptions) ? $writeOptions['dtype'] : (((array)($dataEnvelope['infos'] ?? Array()))['dtype'] ?? false), $rootNme);
		$withHds = !isset($writeOptions['heads']) || !empty($writeOptions['heads']);
		return ($withHds ? '<?xml version="1.0" encoding="UTF-8"?>'."\n" : '').(($typeTxt==='') ? '' : $typeTxt."\n").$bodyTxt.(($this->multiLines || $withHds) ? "\n" : '');
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
		if( !preg_match(self::NAME_PTRN, $elementLabel) ){
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
	protected function dtypePieceText(Mixed $doctypeValue, String $rootTagLabel): String {
		if( $doctypeValue===false || $doctypeValue===null || $doctypeValue==='' || $doctypeValue===Array() ){
			return '';
		}
		$typeSet = is_array($doctypeValue) ? $doctypeValue : Array('systm'=>strval($doctypeValue));
		$typeNme = $this->checkNameChars((strval($typeSet['names'] ?? '')==='') ? $rootTagLabel : strval($typeSet['names']));
		$publTxt = strval($typeSet['publc'] ?? '');
		$dtdPath = strval($typeSet['systm'] ?? '');
		// A public name is made of few signs; where the rules are told is an address, written between quotes it does not hold
		if( !preg_match('/^[A-Za-z0-9 \-\'()+,.\/:=?;!*#@$_%]*$/D', $publTxt) || preg_match('/["\x00-\x1F<>]/', $dtdPath) || strlen($publTxt)>500 || strlen($dtdPath)>2000 ){
			throw new WriteError('Kind of document that cannot be written: its names hold what such a line does not take');
		}
		if( $publTxt!=='' ){
			return '<!DOCTYPE '.$typeNme.' PUBLIC "'.$publTxt.'" "'.$dtdPath.'">';
		}
		return '<!DOCTYPE '.$typeNme.(($dtdPath==='') ? '' : ' SYSTEM "'.$dtdPath.'"').'>';
	}

	/**
	 * Writes the attributes of an element.
	 *
	 * @param Array $attributeSet The attributes, name => value.
	 * @return String The attributes, each after a space.
	 *
	 * @throws WriteError When a name is not one XML takes.
	 */
	protected function attrsPieceText(Array $attributeSet): String {
		$xmlAttr = '';
		foreach($attributeSet as $atrNme=>$atrVal){
			if( $atrVal===null || is_array($atrVal) || is_object($atrVal) ){
				continue;
			}
			$xmlAttr .= ' '.$this->checkNameChars(strval($atrNme)).'="'.str_replace(Array("\t", "\n", "\r"), Array('&#9;', '&#10;', '&#13;'), $this->escapeXmlValue($this->writeScalarTxt($atrVal))).'"';
		}
		return $xmlAttr;
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
	 * @param Int $depthLevelNb How far in the element stands.
	 * @return String The end of the line before it and what sets it in; '' when the document is written on one line.
	 */
	protected function giveLineIndent(Int $depthLevelNb): String {
		return $this->multiLines ? "\n".str_repeat('  ', $depthLevelNb) : '';
	}

	/**
	 * Writes an element of the tree the parser reads, with what it holds.
	 *
	 * @param Array $elementArray The element: 'tagnm', 'attrs', 'value', 'nodes'.
	 * @param Int   $depthLevelNb How far in it stands.
	 * @return String The element.
	 *
	 * @throws WriteError When a name is not one XML takes, or the tree goes deeper than accepted.
	 */
	protected function treesPieceText(Array $elementArray, Int $depthLevelNb): String {
		if( $depthLevelNb>self::DEEP_MAXI ){
			throw new WriteError('Tree nested deeper than the '.self::DEEP_MAXI.' levels allowed');
		}
		$tagName = $this->checkNameChars(strval($elementArray['tagnm'] ?? ''));
		$openTxt = '<'.$tagName.$this->attrsPieceText((array)($elementArray['attrs'] ?? Array()));
		$chldLst = array_values(array_filter((array)($elementArray['nodes'] ?? Array()), 'is_array'));
		if( empty($chldLst) ){
			$textTxt = $this->escapeXmlValue(is_scalar($elementArray['value'] ?? '') ? strval($elementArray['value'] ?? '') : '');
			return ($textTxt==='') ? $openTxt.'/>' : $openTxt.'>'.$textTxt.'</'.$tagName.'>';
		}
		// An element mixing text and elements is written as it is: a space added to it would be part of its text
		$isMixed = in_array('#text', array_column($chldLst, 'tagnm'), true);
		$keptPty = $this->multiLines;
		$this->multiLines = ($keptPty && !$isMixed);
		$holdTxt = '';
		foreach($chldLst as $chlArr){
			$holdTxt .= (strval($chlArr['tagnm'] ?? '')==='#text') ? $this->escapeXmlValue(is_scalar($chlArr['value'] ?? '') ? strval($chlArr['value'] ?? '') : '') : $this->giveLineIndent($depthLevelNb+1).$this->treesPieceText($chlArr, $depthLevelNb+1);
		}
		$holdTxt .= $this->giveLineIndent($depthLevelNb);
		$this->multiLines = $keptPty;
		return $openTxt.'>'.$holdTxt.'</'.$tagName.'>';
	}

	/**
	 * Writes a value as an element, with what it holds: a single value
	 * is its text; in a list of named values, a key starting with '@' is
	 * one of its attributes, '#text' its own text, any other key an
	 * element it holds, written as many times as the list it is given.
	 *
	 * @param String $elementLabel The name of the element; one XML does not take is written as an element named 'item', telling the name under 'key'.
	 * @param mixed  $valueAsGiven The value.
	 * @param Int    $depthLevelNb How far in the element stands.
	 * @return String The element.
	 *
	 * @throws WriteError When the name of an attribute is not one XML takes, or the values go deeper than accepted.
	 */
	protected function valuePieceText(String $elementLabel, Mixed $valueAsGiven, Int $depthLevelNb): String {
		if( $depthLevelNb>self::DEEP_MAXI ){
			throw new WriteError('Tree nested deeper than the '.self::DEEP_MAXI.' levels allowed');
		}
		$tagName = $elementLabel;
		$attrSet = Array();
		if( !preg_match(self::NAME_PTRN, $elementLabel) ){
			$tagName = self::ITEM_NAME;
			$attrSet['key'] = $elementLabel;
		}
		if( is_object($valueAsGiven) ){
			$valueAsGiven = (array)$valueAsGiven;
		}
		if( !is_array($valueAsGiven) ){
			$textTxt = $this->escapeXmlValue($this->writeScalarTxt($valueAsGiven));
			return '<'.$tagName.$this->attrsPieceText($attrSet).(($textTxt==='') ? '/>' : '>'.$textTxt.'</'.$tagName.'>');
		}
		$holdTxt = '';
		$textTxt = '';
		foreach($valueAsGiven as $subKey=>$subVal){
			$keyText = strval($subKey);
			if( is_string($subKey) && $keyText!=='' && $keyText[0]=='@' && !is_array($subVal) && !is_object($subVal) ){
				$attrSet[substr($keyText, 1)] = $subVal;
			}elseif( $keyText==='#text' && !is_array($subVal) && !is_object($subVal) ){
				$textTxt .= $this->escapeXmlValue($this->writeScalarTxt($subVal));
			}elseif( is_int($subKey) ){
				// A value of a list without a name
				$holdTxt .= $this->giveLineIndent($depthLevelNb+1).$this->valuePieceText(self::ITEM_NAME, $subVal, $depthLevelNb+1);
			}elseif( is_array($subVal) && !empty($subVal) && $this->validateALstOf($subVal) ){
				// A list under a name: the element of that name, as many times
				foreach($subVal as $oneVal){
					$holdTxt .= $this->giveLineIndent($depthLevelNb+1).$this->valuePieceText($keyText, $oneVal, $depthLevelNb+1);
				}
			}else{
				$holdTxt .= $this->giveLineIndent($depthLevelNb+1).$this->valuePieceText($keyText, $subVal, $depthLevelNb+1);
			}
		}
		$openTxt = '<'.$tagName.$this->attrsPieceText($attrSet);
		if( $holdTxt==='' ){
			return ($textTxt==='') ? $openTxt.'/>' : $openTxt.'>'.$textTxt.'</'.$tagName.'>';
		}
		return $openTxt.'>'.$textTxt.$holdTxt.$this->giveLineIndent($depthLevelNb).'</'.$tagName.'>';
	}

}
