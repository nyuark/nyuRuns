<?php

namespace nyu\Data;

/**
 * The shapes the parsers give and the writers take, and what can be done
 * with them whatever format they came from.
 *
 * A text document is a flat list of blocks, in reading order. Every block
 * has the same five keys:
 *
 * - 'block' => its kind;
 * - 'attrs' => what is particular to it (the level of a heading, ...);
 * - 'spans' => its own text, as pieces carrying their formatting;
 * - 'items' => the blocks it contains (the entries of a list, the rows of
 *              a table, what an entry or a cell holds besides its text);
 * - 'plain' => its own text without formatting.
 *
 * A piece of text, or span, has four keys: 'texts' (the text), 'marks'
 * (its formatting), 'links' (the address it points to, or '') and
 * 'field' (what the text stands for when its document works it out:
 * 'pageNumb' the number of the page, 'pageTotl' the number of pages, 'dateTime'
 * a date, 'props:Name' a property of the document; '' for plain text).
 * The text of a field is the value its document last showed for it.
 *
 * A workbook is a list of sheets, each with three keys: 'title' (its
 * name), 'attrs' (what is particular to it) and 'cells' (its rows).
 */
class Shape {

	// Kinds of block that carry text of their own
	const TEXT_LIST = Array('headline', 'paragrph', 'listItem', 'cellText', 'citation', 'codeBlck', 'imageBox', 'footnote');
	// Kinds of block that only hold other blocks, or nothing
	const HOLD_LIST = Array('itemList', 'tableSet', 'tableTop', 'tableRow', 'ruleLine');
	// Formatting a span can carry
	const MARK_LIST = Array('boldFace', 'emphasis', 'undrLine', 'crossOut', 'codeSpan');
	// Kinds of address a link or an image may point to, besides a relative one
	const LINK_LIST = Array('http', 'https', 'mailto', 'tel');

	/**
	 * Keeps an address only when it is safe to follow: a relative one, or
	 * one of the kinds of LINKLIST. An address that would run a script
	 * (javascript:, data:, ...) is dropped.
	 *
	 * @param String $linkGoalText The address, as written in a document.
	 * @return String The address, '' when it is not accepted.
	 */
	public static function wipeUnsafeAddr(String $linkGoalText): String {
		$trimLink = trim($linkGoalText);
		// Spaces and control characters inside an address are how a forbidden kind is hidden
		$bareLink = preg_replace('/[\x00-\x20]+/', '', $trimLink);
		$kindPart = Array();
		if( preg_match('/^([a-z][a-z0-9+.\-]*):/i', $bareLink, $kindPart) ){
			return in_array(strtolower($kindPart[1]), self::LINK_LIST) ? $trimLink : '';
		}
		return $trimLink;
	}

	/**
	 * Builds a piece of text.
	 *
	 * @param String $templateText The text.
	 * @param Array  $runFormatSet Its formatting, among boldFace, emphasis, undrLine, crossOut and codeSpan; anything else is left out.
	 * @param String $linkGoalText The address it points to, '' when it is not a link.
	 * @param String $fldCodesText What the text stands for when its document works it out, '' for plain text.
	 * @return Array The span: 'texts', 'marks', 'links', 'field'.
	 */
	public static function makeSpanOfText(String $templateText, Array $runFormatSet=Array(), String $linkGoalText='', String $fldCodesText=''): Array {
		return Array('texts'=>$templateText, 'marks'=>array_values(array_intersect(self::MARK_LIST, $runFormatSet)), 'links'=>$linkGoalText, 'field'=>$fldCodesText);
	}

	/**
	 * Tidies a run of spans: those without text are left out, and two
	 * neighbours of the same formatting and the same address become one.
	 * A field is never joined with what stands beside it.
	 *
	 * @param Array $textSpanList The spans.
	 * @return Array The tidied spans.
	 */
	public static function joinSpansArray(Array $textSpanList): Array {
		$joinList = Array();
		foreach($textSpanList as $oneSpn){
			if( $oneSpn['texts']==='' ){
				continue;
			}
			$lastIndx = count($joinList)-1;
			if( $lastIndx>=0 && $joinList[$lastIndx]['marks']===$oneSpn['marks'] && $joinList[$lastIndx]['links']===$oneSpn['links'] && $oneSpn['field']==='' && $joinList[$lastIndx]['field']==='' ){
				$joinList[$lastIndx]['texts'] .= $oneSpn['texts'];
			}else{
				$joinList[] = $oneSpn;
			}
		}
		return $joinList;
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
	 * @param String $blockLblText Kind of the block: headline, paragrph, itemList, listItem, tableSet, tableTop, tableRow, cellText, citation, codeBlck, imageBox, ruleLine or footnote.
	 * @param Array  $textSpanList Its own text, as spans.
	 * @param Array  $paragraphSet The blocks it contains.
	 * @param Array  $attributeSet What is particular to it.
	 * @return Array The block: 'block', 'attrs', 'spans', 'items', 'plain'.
	 *
	 * @throws \RuntimeException When the kind is not a known one.
	 */
	public static function makeBlockArray(String $blockLblText, Array $textSpanList=Array(), Array $paragraphSet=Array(), Array $attributeSet=Array()): Array {
		if( !in_array($blockLblText, self::TEXT_LIST) && !in_array($blockLblText, self::HOLD_LIST) ){
			throw new \RuntimeException('Unknown kind of block: '.$blockLblText);
		}
		$paragraphSet = array_values($paragraphSet);
		$plainTxt = implode('', array_column($textSpanList, 'texts'));
		if( $blockLblText=='tableTop' || $blockLblText=='tableRow' ){
			$plainTxt = implode("\t", array_column($paragraphSet, 'plain'));
		}elseif( $blockLblText=='tableSet' ){
			$headList = Array();
			$clmnsMax = 0;
			$lineNumb = 0;
			foreach($paragraphSet as $oneRow){
				$clmnsMax = max($clmnsMax, count($oneRow['items']));
				if( $oneRow['block']=='tableTop' ){
					$headList = empty($headList) ? array_column($oneRow['items'], 'plain') : $headList;
				}else{
					$lineNumb++;
				}
			}
			$plainTxt = '';
			$attributeSet = Array('heads'=>$headList, 'colms'=>$clmnsMax, 'lines'=>$lineNumb, 'bytes'=>self::countTextBytes($paragraphSet)) + $attributeSet;
		}elseif( $blockLblText=='itemList' ){
			$plainTxt = '';
			$attributeSet = Array('lines'=>count($paragraphSet), 'bytes'=>self::countTextBytes($paragraphSet)) + $attributeSet;
		}
		return Array('block'=>$blockLblText, 'attrs'=>$attributeSet, 'spans'=>array_values($textSpanList), 'items'=>$paragraphSet, 'plain'=>$plainTxt);
	}

	/**
	 * Counts the bytes of text some blocks hold, at any depth: the text
	 * alone, without what separates or formats it.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Int The number of bytes.
	 */
	public static function countTextBytes(Array $bookBlockSet): Int {
		$byteNumb = 0;
		foreach($bookBlockSet as $oneBlk){
			foreach($oneBlk['spans'] as $oneSpn){
				$byteNumb += strlen($oneSpn['texts']);
			}
			$byteNumb += self::countTextBytes($oneBlk['items']);
		}
		return $byteNumb;
	}

	/**
	 * Gives the whole text of a block, with what it contains: the entries
	 * of a list and the rows of a table one per line, the cells of a row
	 * separated by tabs.
	 *
	 * @param Array $contentBlock The block.
	 * @return String Its text, without formatting.
	 */
	public static function getTextOfBlock(Array $contentBlock): String {
		$ownStrng = implode('', array_column($contentBlock['spans'], 'texts'));
		if( empty($contentBlock['items']) ){
			return $ownStrng;
		}
		$childTxt = Array();
		foreach($contentBlock['items'] as $oneBlk){
			$childTxt[] = self::getTextOfBlock($oneBlk);
		}
		$joinText = implode(in_array($contentBlock['block'], Array('tableTop', 'tableRow')) ? "\t" : "\n", $childTxt);
		return ($ownStrng==='') ? $joinText : $ownStrng."\n".$joinText;
	}

	/**
	 * Gives the whole text of a document: its blocks, one after the other.
	 *
	 * @param Array $bookBlockSet The blocks of the document.
	 * @return String The text, a blank line between two blocks.
	 */
	public static function getTextOfDcmnt(Array $bookBlockSet): String {
		$blockTxt = Array();
		foreach($bookBlockSet as $oneBlk){
			$blockTxt[] = self::getTextOfBlock($oneBlk);
		}
		return implode("\n\n", $blockTxt);
	}

	/**
	 * Gives a table as a plain grid: its rows, each a list of the texts of
	 * its cells. The header rows come first, like the others.
	 *
	 * @param Array $tableBlcList A block of kind 'tableSet'.
	 * @return Array The rows, each a list of texts.
	 */
	public static function getGridOfTable(Array $tableBlcList): Array {
		$gridRows = Array();
		foreach($tableBlcList['items'] as $oneRow){
			$cellText = Array();
			foreach($oneRow['items'] as $oneCel){
				$cellText[] = self::getTextOfBlock($oneCel);
			}
			$gridRows[] = $cellText;
		}
		return $gridRows;
	}

	/**
	 * Groups the blocks of a document under their headings, as a tree of
	 * sections. What comes before the first heading is a section without
	 * title, of level 0. A section holds the blocks that follow its
	 * heading, and the sections of a deeper level that follow them.
	 *
	 * @param Array $bookBlockSet The blocks of the document, in order.
	 * @return Array The sections, each with its 'title', its 'level', the 'attrs' of its heading, its own 'items' and its sub-sections under 'nodes'.
	 */
	public static function getSectionTree(Array $bookBlockSet): Array {
		$rootSctn = Array('title'=>'', 'level'=>0, 'attrs'=>Array(), 'items'=>Array(), 'nodes'=>Array());
		// The sections being filled, from the outermost to the innermost
		$openSctn = Array(&$rootSctn);
		foreach($bookBlockSet as $oneBlk){
			if( $oneBlk['block']!=='headline' ){
				$openSctn[count($openSctn)-1]['items'][] = $oneBlk;
				continue;
			}
			$headLevl = max(1, intval($oneBlk['attrs']['level'] ?? 1));
			while( count($openSctn)>1 && $openSctn[count($openSctn)-1]['level']>=$headLevl ){
				array_pop($openSctn);
			}
			$newSectn = Array('title'=>$oneBlk['plain'], 'level'=>$headLevl, 'attrs'=>$oneBlk['attrs'], 'items'=>Array(), 'nodes'=>Array());
			$ownerIdx = count($openSctn)-1;
			$openSctn[$ownerIdx]['nodes'][] = $newSectn;
			$openSctn[] = &$openSctn[$ownerIdx]['nodes'][count($openSctn[$ownerIdx]['nodes'])-1];
		}
		// A document that starts with a heading has no untitled section
		if( empty($rootSctn['items']) ){
			return $rootSctn['nodes'];
		}
		$leadSctn = $rootSctn;
		$leadSctn['nodes'] = Array();
		return array_merge(Array($leadSctn), $rootSctn['nodes']);
	}

	/**
	 * Builds a sheet: a named table of values. What is particular to it
	 * stands in its 'attrs':
	 *   'shown' => false for a sheet its workbook hides;
	 *   'merge' => the areas of merged cells, each with the row and the
	 *              column of its first cell under 'atrow' and 'atcol',
	 *              counted from 0, and the rows and columns it covers
	 *              under 'rowsp' and 'colsp'.
	 *
	 * @param String $sheetHeading Name of the sheet.
	 * @param Array  $sheetRowList Its rows, each a list of values.
	 * @param Array  $attributeSet What is particular to it; a shown sheet without merged cells when left out.
	 * @return Array The sheet: 'title', 'attrs', 'cells'.
	 */
	public static function makeSheetArray(String $sheetHeading, Array $sheetRowList, Array $attributeSet=Array()): Array {
		$rowsList = Array();
		foreach($sheetRowList as $oneRow){
			$rowsList[] = array_values((array)$oneRow);
		}
		return Array('title'=>$sheetHeading, 'attrs'=>$attributeSet + Array('shown'=>true, 'merge'=>Array()), 'cells'=>$rowsList);
	}

}
