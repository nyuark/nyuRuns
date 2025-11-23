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
	const DEEPMAXI = 256;
	// What the name of an element or of an attribute is made of
	const NAMEPATT = '/^[\p{L}_][\p{L}\p{N}_.\-]*(:[\p{L}_][\p{L}\p{N}_.\-]*)?$/Du';
	// The name given to an element whose key is not one XML takes, and to the values of a list without a name
	const ITEMNAME = 'item';

	// Whether the document is written over several lines, each element further in than the one holding it
	protected $prettyUp = false;

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
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'roots', 'prety', 'heads', 'dtype'.
	 * @return String The XML.
	 *
	 * @throws WriteError When the shape is not 'nodes', a name is not one XML takes, the kind of the document cannot be written, or the tree goes deeper than accepted.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('nodes'), 'XML');
		$this->prettyUp = !empty($writeOptns['prety']);
		$itemVal = Fluxd::dropFluxd($shapedData['items'] ?? null);
		$rootNme = strval($writeOptns['roots'] ?? '');
		if( $rootNme!=='' ){
			$this->checkName($rootNme);
		}
		if( is_array($itemVal) && isset($itemVal['tagnm']) && array_key_exists('nodes', $itemVal) && $rootNme==='' ){
			// The tree of elements, as the parser reads it
			$rootNme = strval($itemVal['tagnm']);
			$bodyTxt = $this->treesPart($itemVal, 0);
		}elseif( is_array($itemVal) && $rootNme==='' && count($itemVal)==1 && is_string(array_key_first($itemVal)) && preg_match(self::NAMEPATT, strval(array_key_first($itemVal))) && !(is_array(current($itemVal)) && !empty(current($itemVal)) && $this->isAListOf(current($itemVal))) ){
			// The compact form: the one key of the values names the root
			$rootNme = strval(array_key_first($itemVal));
			$bodyTxt = $this->valuePart($rootNme, current($itemVal), 0);
		}else{
			$rootNme = ($rootNme==='') ? 'root' : $rootNme;
			$bodyTxt = $this->valuePart($rootNme, $itemVal, 0);
		}
		$typeTxt = $this->dtypePart(array_key_exists('dtype', $writeOptns) ? $writeOptns['dtype'] : (((array)($shapedData['infos'] ?? Array()))['dtype'] ?? false), $rootNme);
		$withHds = !isset($writeOptns['heads']) || !empty($writeOptns['heads']);
		return ($withHds ? '<?xml version="1.0" encoding="UTF-8"?>'."\n" : '').(($typeTxt==='') ? '' : $typeTxt."\n").$bodyTxt.(($this->prettyUp || $withHds) ? "\n" : '');
	}

	/**
	 * Checks the name of an element or of an attribute.
	 *
	 * @param String $givenLabel The name.
	 * @return String The name, as given.
	 *
	 * @throws WriteError When the name is not one XML takes.
	 */
	protected function checkName(String $givenLabel): String {
		if( !preg_match(self::NAMEPATT, $givenLabel) ){
			throw new WriteError('Name that XML does not take: '.substr(strval(preg_replace('/[^\x20-\x7E]+/', '?', $givenLabel)), 0, 60));
		}
		return $givenLabel;
	}

	/**
	 * Writes the kind of the document, and where the rules of that kind
	 * are told. Nothing is ever declared there by the document itself.
	 *
	 * @param mixed  $dtypeValue What is told of the kind: where its rules are told, as a text; its name, its public name and where its rules are told, under 'names', 'publc' and 'systm'; false or nothing for none.
	 * @param String $rootsLabel The name of the root of the document, which is the name of its kind when none is told.
	 * @return String The line telling the kind, '' when there is none.
	 *
	 * @throws WriteError When what is told of the kind cannot be written.
	 */
	protected function dtypePart(Mixed $dtypeValue, String $rootsLabel): String {
		if( $dtypeValue===false || $dtypeValue===null || $dtypeValue==='' || $dtypeValue===Array() ){
			return '';
		}
		$typeSet = is_array($dtypeValue) ? $dtypeValue : Array('systm'=>strval($dtypeValue));
		$typeNme = $this->checkName((strval($typeSet['names'] ?? '')==='') ? $rootsLabel : strval($typeSet['names']));
		$publTxt = strval($typeSet['publc'] ?? '');
		$systTxt = strval($typeSet['systm'] ?? '');
		// A public name is made of few signs; where the rules are told is an address, written between quotes it does not hold
		if( !preg_match('/^[A-Za-z0-9 \-\'()+,.\/:=?;!*#@$_%]*$/D', $publTxt) || preg_match('/["\x00-\x1F<>]/', $systTxt) || strlen($publTxt)>500 || strlen($systTxt)>2000 ){
			throw new WriteError('Kind of document that cannot be written: its names hold what such a line does not take');
		}
		if( $publTxt!=='' ){
			return '<!DOCTYPE '.$typeNme.' PUBLIC "'.$publTxt.'" "'.$systTxt.'">';
		}
		return '<!DOCTYPE '.$typeNme.(($systTxt==='') ? '' : ' SYSTEM "'.$systTxt.'"').'>';
	}

	/**
	 * Writes the attributes of an element.
	 *
	 * @param Array $attrsArray The attributes, name => value.
	 * @return String The attributes, each after a space.
	 *
	 * @throws WriteError When a name is not one XML takes.
	 */
	protected function attrsPart(Array $attrsArray): String {
		$attrTxt = '';
		foreach($attrsArray as $atrNme=>$atrVal){
			if( $atrVal===null || is_array($atrVal) || is_object($atrVal) ){
				continue;
			}
			$attrTxt .= ' '.$this->checkName(strval($atrNme)).'="'.str_replace(Array("\t", "\n", "\r"), Array('&#9;', '&#10;', '&#13;'), $this->escapeTxt($this->scalrText($atrVal))).'"';
		}
		return $attrTxt;
	}

	/**
	 * Gives a single value as the text XML holds for it.
	 *
	 * @param mixed $givenValue The value.
	 * @return String Its text: true or false for a boolean, a number with the digits it needs and no more.
	 */
	protected function scalrText(Mixed $givenValue): String {
		if( is_bool($givenValue) ){
			return $givenValue ? 'true' : 'false';
		}
		if( is_int($givenValue) || is_float($givenValue) ){
			return is_finite(floatval($givenValue)) ? $this->numbrText($givenValue) : '';
		}
		return is_scalar($givenValue) ? strval($givenValue) : '';
	}

	/**
	 * Gives what starts a line of an element, when the document is
	 * written over several lines.
	 *
	 * @param Int $depthLevel How far in the element stands.
	 * @return String The end of the line before it and what sets it in; '' when the document is written on one line.
	 */
	protected function linesHead(Int $depthLevel): String {
		return $this->prettyUp ? "\n".str_repeat('  ', $depthLevel) : '';
	}

	/**
	 * Writes an element of the tree the parser reads, with what it holds.
	 *
	 * @param Array $nodeValues The element: 'tagnm', 'attrs', 'value', 'nodes'.
	 * @param Int   $depthLevel How far in it stands.
	 * @return String The element.
	 *
	 * @throws WriteError When a name is not one XML takes, or the tree goes deeper than accepted.
	 */
	protected function treesPart(Array $nodeValues, Int $depthLevel): String {
		if( $depthLevel>self::DEEPMAXI ){
			throw new WriteError('Tree nested deeper than the '.self::DEEPMAXI.' levels allowed');
		}
		$tagName = $this->checkName(strval($nodeValues['tagnm'] ?? ''));
		$openTxt = '<'.$tagName.$this->attrsPart((array)($nodeValues['attrs'] ?? Array()));
		$chldLst = array_values(array_filter((array)($nodeValues['nodes'] ?? Array()), 'is_array'));
		if( empty($chldLst) ){
			$textTxt = $this->escapeTxt(is_scalar($nodeValues['value'] ?? '') ? strval($nodeValues['value'] ?? '') : '');
			return ($textTxt==='') ? $openTxt.'/>' : $openTxt.'>'.$textTxt.'</'.$tagName.'>';
		}
		// An element mixing text and elements is written as it is: a space added to it would be part of its text
		$isMixed = in_array('#text', array_column($chldLst, 'tagnm'), true);
		$keptPty = $this->prettyUp;
		$this->prettyUp = ($keptPty && !$isMixed);
		$holdTxt = '';
		foreach($chldLst as $chlArr){
			$holdTxt .= (strval($chlArr['tagnm'] ?? '')==='#text') ? $this->escapeTxt(is_scalar($chlArr['value'] ?? '') ? strval($chlArr['value'] ?? '') : '') : $this->linesHead($depthLevel+1).$this->treesPart($chlArr, $depthLevel+1);
		}
		$holdTxt .= $this->linesHead($depthLevel);
		$this->prettyUp = $keptPty;
		return $openTxt.'>'.$holdTxt.'</'.$tagName.'>';
	}

	/**
	 * Writes a value as an element, with what it holds: a single value
	 * is its text; in a list of named values, a key starting with '@' is
	 * one of its attributes, '#text' its own text, any other key an
	 * element it holds, written as many times as the list it is given.
	 *
	 * @param String $givenLabel The name of the element; one XML does not take is written as an element named 'item', telling the name under 'key'.
	 * @param mixed  $givenValue The value.
	 * @param Int    $depthLevel How far in the element stands.
	 * @return String The element.
	 *
	 * @throws WriteError When the name of an attribute is not one XML takes, or the values go deeper than accepted.
	 */
	protected function valuePart(String $givenLabel, Mixed $givenValue, Int $depthLevel): String {
		if( $depthLevel>self::DEEPMAXI ){
			throw new WriteError('Tree nested deeper than the '.self::DEEPMAXI.' levels allowed');
		}
		$tagName = $givenLabel;
		$attrLst = Array();
		if( !preg_match(self::NAMEPATT, $givenLabel) ){
			$tagName = self::ITEMNAME;
			$attrLst['key'] = $givenLabel;
		}
		if( is_object($givenValue) ){
			$givenValue = (array)$givenValue;
		}
		if( !is_array($givenValue) ){
			$textTxt = $this->escapeTxt($this->scalrText($givenValue));
			return '<'.$tagName.$this->attrsPart($attrLst).(($textTxt==='') ? '/>' : '>'.$textTxt.'</'.$tagName.'>');
		}
		$holdTxt = '';
		$textTxt = '';
		foreach($givenValue as $subKey=>$subVal){
			$keyText = strval($subKey);
			if( is_string($subKey) && $keyText!=='' && $keyText[0]=='@' && !is_array($subVal) && !is_object($subVal) ){
				$attrLst[substr($keyText, 1)] = $subVal;
			}elseif( $keyText==='#text' && !is_array($subVal) && !is_object($subVal) ){
				$textTxt .= $this->escapeTxt($this->scalrText($subVal));
			}elseif( is_int($subKey) ){
				// A value of a list without a name
				$holdTxt .= $this->linesHead($depthLevel+1).$this->valuePart(self::ITEMNAME, $subVal, $depthLevel+1);
			}elseif( is_array($subVal) && !empty($subVal) && $this->isAListOf($subVal) ){
				// A list under a name: the element of that name, as many times
				foreach($subVal as $oneVal){
					$holdTxt .= $this->linesHead($depthLevel+1).$this->valuePart($keyText, $oneVal, $depthLevel+1);
				}
			}else{
				$holdTxt .= $this->linesHead($depthLevel+1).$this->valuePart($keyText, $subVal, $depthLevel+1);
			}
		}
		$openTxt = '<'.$tagName.$this->attrsPart($attrLst);
		if( $holdTxt==='' ){
			return ($textTxt==='') ? $openTxt.'/>' : $openTxt.'>'.$textTxt.'</'.$tagName.'>';
		}
		return $openTxt.'>'.$textTxt.$holdTxt.$this->linesHead($depthLevel).'</'.$tagName.'>';
	}

}
