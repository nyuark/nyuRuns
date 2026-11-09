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
 * 'pgnum' the number of the page, 'pgtot' the number of pages, 'dates'
 * a date, 'props:Name' a property of the document; '' for plain text).
 * The text of a field is the value its document last showed for it.
 *
 * A workbook is a list of sheets, each with three keys: 'title' (its
 * name), 'attrs' (what is particular to it) and 'cells' (its rows).
 */
class Shape {

	// Kinds of block that carry text of their own
	const TEXT_LIST = Array('headr', 'parag', 'entry', 'tcell', 'quote', 'codes', 'image', 'fnote');
	// Kinds of block that only hold other blocks, or nothing
	const HOLD_LIST = Array('lists', 'table', 'thead', 'tbrow', 'rules');
	// Formatting a span can carry
	const MARK_LIST = Array('bold', 'ital', 'undr', 'strk', 'code');
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
	public static function tidyLinkString(String $linkGoalText): String {
		$trmLink = trim($linkGoalText);
		// Spaces and control characters inside an address are how a forbidden kind is hidden
		$bareLnk = preg_replace('/[\x00-\x20]+/', '', $trmLink);
		$kndPart = Array();
		if( preg_match('/^([a-z][a-z0-9+.\-]*):/i', $bareLnk, $kndPart) ){
			return in_array(strtolower($kndPart[1]), self::LINK_LIST) ? $trmLink : '';
		}
		return $trmLink;
	}

	/**
	 * Builds a piece of text.
	 *
	 * @param String $templateText The text.
	 * @param Array  $runFormatSet Its formatting, among bold, ital, undr, strk and code; anything else is left out.
	 * @param String $linkGoalText The address it points to, '' when it is not a link.
	 * @param String $fldCodesText What the text stands for when its document works it out, '' for plain text.
	 * @return Array The span: 'texts', 'marks', 'links', 'field'.
	 */
	public static function makeASpanArray(String $templateText, Array $runFormatSet=Array(), String $linkGoalText='', String $fldCodesText=''): Array {
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
		$joinLst = Array();
		foreach($textSpanList as $oneSpn){
			if( $oneSpn['texts']==='' ){
				continue;
			}
			$lastIdx = count($joinLst)-1;
			if( $lastIdx>=0 && $joinLst[$lastIdx]['marks']===$oneSpn['marks'] && $joinLst[$lastIdx]['links']===$oneSpn['links'] && $oneSpn['field']==='' && $joinLst[$lastIdx]['field']==='' ){
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
	 * @param String $blckLabelTxt Kind of the block: headr, parag, lists, entry, table, thead, tbrow, tcell, quote, codes, image, rules or fnote.
	 * @param Array  $textSpanList Its own text, as spans.
	 * @param Array  $paragraphSet The blocks it contains.
	 * @param Array  $attributeSet What is particular to it.
	 * @return Array The block: 'block', 'attrs', 'spans', 'items', 'plain'.
	 *
	 * @throws \RuntimeException When the kind is not a known one.
	 */
	public static function makeBlockArray(String $blckLabelTxt, Array $textSpanList=Array(), Array $paragraphSet=Array(), Array $attributeSet=Array()): Array {
		if( !in_array($blckLabelTxt, self::TEXT_LIST) && !in_array($blckLabelTxt, self::HOLD_LIST) ){
			throw new \RuntimeException('Unknown kind of block: '.$blckLabelTxt);
		}
		$paragraphSet = array_values($paragraphSet);
		$plainTx = implode('', array_column($textSpanList, 'texts'));
		if( $blckLabelTxt=='thead' || $blckLabelTxt=='tbrow' ){
			$plainTx = implode("\t", array_column($paragraphSet, 'plain'));
		}elseif( $blckLabelTxt=='table' ){
			$headLst = Array();
			$colsMax = 0;
			$lineNum = 0;
			foreach($paragraphSet as $oneRow){
				$colsMax = max($colsMax, count($oneRow['items']));
				if( $oneRow['block']=='thead' ){
					$headLst = empty($headLst) ? array_column($oneRow['items'], 'plain') : $headLst;
				}else{
					$lineNum++;
				}
			}
			$plainTx = '';
			$attributeSet = Array('heads'=>$headLst, 'colms'=>$colsMax, 'lines'=>$lineNum, 'bytes'=>self::textBytesCount($paragraphSet)) + $attributeSet;
		}elseif( $blckLabelTxt=='lists' ){
			$plainTx = '';
			$attributeSet = Array('lines'=>count($paragraphSet), 'bytes'=>self::textBytesCount($paragraphSet)) + $attributeSet;
		}
		return Array('block'=>$blckLabelTxt, 'attrs'=>$attributeSet, 'spans'=>array_values($textSpanList), 'items'=>$paragraphSet, 'plain'=>$plainTx);
	}

	/**
	 * Counts the bytes of text some blocks hold, at any depth: the text
	 * alone, without what separates or formats it.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Int The number of bytes.
	 */
	public static function textBytesCount(Array $bookBlockSet): Int {
		$byteNum = 0;
		foreach($bookBlockSet as $oneBlk){
			foreach($oneBlk['spans'] as $oneSpn){
				$byteNum += strlen($oneSpn['texts']);
			}
			$byteNum += self::textBytesCount($oneBlk['items']);
		}
		return $byteNum;
	}

	/**
	 * Gives the whole text of a block, with what it contains: the entries
	 * of a list and the rows of a table one per line, the cells of a row
	 * separated by tabs.
	 *
	 * @param Array $contentBlock The block.
	 * @return String Its text, without formatting.
	 */
	public static function blockPlainText(Array $contentBlock): String {
		$ownText = implode('', array_column($contentBlock['spans'], 'texts'));
		if( empty($contentBlock['items']) ){
			return $ownText;
		}
		$chldTxt = Array();
		foreach($contentBlock['items'] as $oneBlk){
			$chldTxt[] = self::blockPlainText($oneBlk);
		}
		$joinTxt = implode(in_array($contentBlock['block'], Array('thead', 'tbrow')) ? "\t" : "\n", $chldTxt);
		return ($ownText==='') ? $joinTxt : $ownText."\n".$joinTxt;
	}

	/**
	 * Gives the whole text of a document: its blocks, one after the other.
	 *
	 * @param Array $bookBlockSet The blocks of the document.
	 * @return String The text, a blank line between two blocks.
	 */
	public static function documentAsText(Array $bookBlockSet): String {
		$blckTxt = Array();
		foreach($bookBlockSet as $oneBlk){
			$blckTxt[] = self::blockPlainText($oneBlk);
		}
		return implode("\n\n", $blckTxt);
	}

	/**
	 * Gives a table as a plain grid: its rows, each a list of the texts of
	 * its cells. The header rows come first, like the others.
	 *
	 * @param Array $tableBlckArr A block of kind 'table'.
	 * @return Array The rows, each a list of texts.
	 */
	public static function tableGridArray(Array $tableBlckArr): Array {
		$gridRws = Array();
		foreach($tableBlckArr['items'] as $oneRow){
			$cellTxt = Array();
			foreach($oneRow['items'] as $celBlk){
				$cellTxt[] = self::blockPlainText($celBlk);
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
	 * @param Array $bookBlockSet The blocks of the document, in order.
	 * @return Array The sections, each with its 'title', its 'level', the 'attrs' of its heading, its own 'items' and its sub-sections under 'nodes'.
	 */
	public static function bySectionArray(Array $bookBlockSet): Array {
		$rotSctn = Array('title'=>'', 'level'=>0, 'attrs'=>Array(), 'items'=>Array(), 'nodes'=>Array());
		// The sections being filled, from the outermost to the innermost
		$opnSctn = Array(&$rotSctn);
		foreach($bookBlockSet as $oneBlk){
			if( $oneBlk['block']!=='headr' ){
				$opnSctn[count($opnSctn)-1]['items'][] = $oneBlk;
				continue;
			}
			$headLvl = max(1, intval($oneBlk['attrs']['level'] ?? 1));
			while( count($opnSctn)>1 && $opnSctn[count($opnSctn)-1]['level']>=$headLvl ){
				array_pop($opnSctn);
			}
			$newSctn = Array('title'=>$oneBlk['plain'], 'level'=>$headLvl, 'attrs'=>$oneBlk['attrs'], 'items'=>Array(), 'nodes'=>Array());
			$ownerAt = count($opnSctn)-1;
			$opnSctn[$ownerAt]['nodes'][] = $newSctn;
			$opnSctn[] = &$opnSctn[$ownerAt]['nodes'][count($opnSctn[$ownerAt]['nodes'])-1];
		}
		// A document that starts with a heading has no untitled section
		if( empty($rotSctn['items']) ){
			return $rotSctn['nodes'];
		}
		$ledSctn = $rotSctn;
		$ledSctn['nodes'] = Array();
		return array_merge(Array($ledSctn), $rotSctn['nodes']);
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
		$rowsLst = Array();
		foreach($sheetRowList as $oneRow){
			$rowsLst[] = array_values((array)$oneRow);
		}
		return Array('title'=>$sheetHeading, 'attrs'=>$attributeSet + Array('shown'=>true, 'merge'=>Array()), 'cells'=>$rowsLst);
	}

}
