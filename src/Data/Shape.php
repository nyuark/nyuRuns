<?php

namespace nyu\Data;

// The shapes the parsers give and the writers take, and what can be done
// with them whatever format they came from.
//
// A text document is a flat list of blocks, in reading order. Every block
// has the same five keys:
//
//   'block' => its kind;
//   'attrs' => what is particular to it (the level of a heading, ...);
//   'spans' => its own text, as pieces carrying their formatting;
//   'items' => the blocks it contains (the entries of a list, the rows of
//              a table, what an entry or a cell holds besides its text);
//   'plain' => its own text without formatting.
//
// A piece of text, or span, has three keys: 'texts' (the text), 'marks'
// (its formatting) and 'links' (the address it points to, or '').
class Shape {

	// Kinds of block that carry text of their own
	const TEXTLIST = Array('headr', 'parag', 'entry', 'tcell', 'quote', 'codes', 'image');
	// Kinds of block that only hold other blocks, or nothing
	const HOLDLIST = Array('lists', 'table', 'thead', 'tbrow', 'rules');
	// Formatting a span can carry
	const MARKLIST = Array('bold', 'ital', 'undr', 'strk', 'code');
	// Kinds of address a link or an image may point to, besides a relative one
	const LINKLIST = Array('http', 'https', 'mailto', 'tel');

	/**
	 * Keeps an address only when it is safe to follow: a relative one, or
	 * one of the kinds of LINKLIST. An address that would run a script
	 * (javascript:, data:, ...) is dropped.
	 *
	 * @param String $linkTarget The address, as written in a document.
	 * @return String The address, '' when it is not accepted.
	 */
	public static function cleanLink(String $linkTarget): String {
		$trimLnk = trim($linkTarget);
		// Spaces and control characters inside an address are how a forbidden kind is hidden
		$bareLnk = preg_replace('/[\x00-\x20]+/', '', $trimLnk);
		$kindPrt = Array();
		if( preg_match('/^([a-z][a-z0-9+.\-]*):/i', $bareLnk, $kindPrt) ){
			return in_array(strtolower($kindPrt[1]), self::LINKLIST) ? $trimLnk : '';
		}
		return $trimLnk;
	}

	/**
	 * Builds a piece of text.
	 *
	 * @param String $textString The text.
	 * @param Array  $marksArray Its formatting, among bold, ital, undr, strk and code; anything else is left out.
	 * @param String $linkTarget The address it points to, '' when it is not a link.
	 * @return Array The span: 'texts', 'marks', 'links'.
	 */
	public static function makeASpan(String $textString, Array $marksArray=Array(), String $linkTarget=''): Array {
		return Array('texts'=>$textString, 'marks'=>array_values(array_intersect(self::MARKLIST, $marksArray)), 'links'=>$linkTarget);
	}

	/**
	 * Tidies a run of spans: those without text are left out, and two
	 * neighbours of the same formatting and the same address become one.
	 *
	 * @param Array $spansArray The spans.
	 * @return Array The tidied spans.
	 */
	public static function joinSpans(Array $spansArray): Array {
		$joinLst = Array();
		foreach($spansArray as $oneSpn){
			if( $oneSpn['texts']==='' ){
				continue;
			}
			$lastIdx = count($joinLst)-1;
			if( $lastIdx>=0 && $joinLst[$lastIdx]['marks']===$oneSpn['marks'] && $joinLst[$lastIdx]['links']===$oneSpn['links'] ){
				$joinLst[$lastIdx]['texts'] .= $oneSpn['texts'];
			}else{
				$joinLst[] = $oneSpn;
			}
		}
		return $joinLst;
	}

	/**
	 * Builds a block, and works out what describes it:
	 *   - a block with text of its own has that text in 'plain';
	 *   - a row has its cells in 'plain', separated by tabs;
	 *   - a table has no 'plain': its 'attrs' give its header texts
	 *     ('heads'), its number of columns ('colms'), of body rows
	 *     ('lines'), and the size of its text in bytes ('bytes');
	 *   - a list has no 'plain': its 'attrs' give its number of entries
	 *     ('lines') and the size of its text in bytes ('bytes').
	 *
	 * @param String $blockLabel Kind of the block: headr, parag, lists, entry, table, thead, tbrow, tcell, quote, codes, image or rules.
	 * @param Array  $spansArray Its own text, as spans.
	 * @param Array  $itemsArray The blocks it contains.
	 * @param Array  $attrsArray What is particular to it.
	 * @return Array The block: 'block', 'attrs', 'spans', 'items', 'plain'.
	 *
	 * @throws \RuntimeException When the kind is not a known one.
	 */
	public static function makeBlock(String $blockLabel, Array $spansArray=Array(), Array $itemsArray=Array(), Array $attrsArray=Array()): Array {
		if( !in_array($blockLabel, self::TEXTLIST) && !in_array($blockLabel, self::HOLDLIST) ){
			throw new \RuntimeException('Unknown kind of block: '.$blockLabel);
		}
		$itemsArray = array_values($itemsArray);
		$plainTx = implode('', array_column($spansArray, 'texts'));
		if( $blockLabel=='thead' || $blockLabel=='tbrow' ){
			$plainTx = implode("\t", array_column($itemsArray, 'plain'));
		}elseif( $blockLabel=='table' ){
			$headLst = Array();
			$colsMax = 0;
			$lineNbr = 0;
			foreach($itemsArray as $rowBlk){
				$colsMax = max($colsMax, count($rowBlk['items']));
				if( $rowBlk['block']=='thead' ){
					$headLst = empty($headLst) ? array_column($rowBlk['items'], 'plain') : $headLst;
				}else{
					$lineNbr++;
				}
			}
			$plainTx = '';
			$attrsArray = Array('heads'=>$headLst, 'colms'=>$colsMax, 'lines'=>$lineNbr, 'bytes'=>self::textBytes($itemsArray)) + $attrsArray;
		}elseif( $blockLabel=='lists' ){
			$plainTx = '';
			$attrsArray = Array('lines'=>count($itemsArray), 'bytes'=>self::textBytes($itemsArray)) + $attrsArray;
		}
		return Array('block'=>$blockLabel, 'attrs'=>$attrsArray, 'spans'=>array_values($spansArray), 'items'=>$itemsArray, 'plain'=>$plainTx);
	}

	/**
	 * Counts the bytes of text some blocks hold, at any depth: the text
	 * alone, without what separates or formats it.
	 *
	 * @param Array $blocksList The blocks.
	 * @return Int The number of bytes.
	 */
	public static function textBytes(Array $blocksList): Int {
		$byteNbr = 0;
		foreach($blocksList as $oneBlk){
			foreach($oneBlk['spans'] as $oneSpn){
				$byteNbr += strlen($oneSpn['texts']);
			}
			$byteNbr += self::textBytes($oneBlk['items']);
		}
		return $byteNbr;
	}

	/**
	 * Gives the whole text of a block, with what it contains: the entries
	 * of a list and the rows of a table one per line, the cells of a row
	 * separated by tabs.
	 *
	 * @param Array $blockArray The block.
	 * @return String Its text, without formatting.
	 */
	public static function plainText(Array $blockArray): String {
		$ownText = implode('', array_column($blockArray['spans'], 'texts'));
		if( empty($blockArray['items']) ){
			return $ownText;
		}
		$chldTxt = Array();
		foreach($blockArray['items'] as $oneBlk){
			$chldTxt[] = self::plainText($oneBlk);
		}
		$joinTxt = implode(in_array($blockArray['block'], Array('thead', 'tbrow')) ? "\t" : "\n", $chldTxt);
		return ($ownText==='') ? $joinTxt : $ownText."\n".$joinTxt;
	}

	/**
	 * Gives the whole text of a document: its blocks, one after the other.
	 *
	 * @param Array $blocksList The blocks of the document.
	 * @return String The text, a blank line between two blocks.
	 */
	public static function wholeText(Array $blocksList): String {
		$blckTxt = Array();
		foreach($blocksList as $oneBlk){
			$blckTxt[] = self::plainText($oneBlk);
		}
		return implode("\n\n", $blckTxt);
	}

	/**
	 * Gives a table as a plain grid: its rows, each a list of the texts of
	 * its cells. The header rows come first, like the others.
	 *
	 * @param Array $tableBlock A block of kind 'table'.
	 * @return Array The rows, each a list of texts.
	 */
	public static function tableGrid(Array $tableBlock): Array {
		$gridRws = Array();
		foreach($tableBlock['items'] as $rowBlk){
			$cellTxt = Array();
			foreach($rowBlk['items'] as $celBlk){
				$cellTxt[] = self::plainText($celBlk);
			}
			$gridRws[] = $cellTxt;
		}
		return $gridRws;
	}

	/**
	 * Groups the blocks of a document under their headings, as a tree of
	 * sections. What comes before the first heading is a section without
	 * title, of level 0. A section holds the blocks that follow its
	 * heading, and the sections of a deeper level that follow them.
	 *
	 * @param Array $blocksList The blocks of the document, in order.
	 * @return Array The sections, each with its 'title', its 'level', the 'attrs' of its heading, its own 'items' and its sub-sections under 'nodes'.
	 */
	public static function bySection(Array $blocksList): Array {
		$rootSct = Array('title'=>'', 'level'=>0, 'attrs'=>Array(), 'items'=>Array(), 'nodes'=>Array());
		// The sections being filled, from the outermost to the innermost
		$openSct = Array(&$rootSct);
		foreach($blocksList as $oneBlk){
			if( $oneBlk['block']!=='headr' ){
				$openSct[count($openSct)-1]['items'][] = $oneBlk;
				continue;
			}
			$hdrLevl = max(1, intval($oneBlk['attrs']['level'] ?? 1));
			while( count($openSct)>1 && $openSct[count($openSct)-1]['level']>=$hdrLevl ){
				array_pop($openSct);
			}
			$newSect = Array('title'=>$oneBlk['plain'], 'level'=>$hdrLevl, 'attrs'=>$oneBlk['attrs'], 'items'=>Array(), 'nodes'=>Array());
			$prntIdx = count($openSct)-1;
			$openSct[$prntIdx]['nodes'][] = $newSect;
			$openSct[] = &$openSct[$prntIdx]['nodes'][count($openSct[$prntIdx]['nodes'])-1];
		}
		// A document that starts with a heading has no untitled section
		if( empty($rootSct['items']) ){
			return $rootSct['nodes'];
		}
		$leadSct = $rootSct;
		$leadSct['nodes'] = Array();
		return array_merge(Array($leadSct), $rootSct['nodes']);
	}

	/**
	 * Builds a sheet: a named table of values.
	 *
	 * @param String $sheetTitle Name of the sheet.
	 * @param Array  $cellsArray Its rows, each a list of values.
	 * @return Array The sheet: 'title', 'cells'.
	 */
	public static function makeSheet(String $sheetTitle, Array $cellsArray): Array {
		$rowsLst = Array();
		foreach($cellsArray as $oneRow){
			$rowsLst[] = array_values((array)$oneRow);
		}
		return Array('title'=>$sheetTitle, 'cells'=>$rowsLst);
	}

}
