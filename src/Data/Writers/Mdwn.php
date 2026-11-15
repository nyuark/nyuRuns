<?php

namespace nyu\Data\Writers;

use nyu\Data\Shape;

/**
 * Markdown: text blocks written the way the Markdown parser reads them,
 * so that a text read then written reads back the same.
 *
 * The characters Markdown gives a meaning to are written after a
 * backslash where they are only text. What Markdown has no way to say is
 * left out: underlined text is written plain, a cell over several
 * columns as one cell, a page break as a rule.
 */
class Mdwn extends Base {

	/**
	 * Writes text blocks as Markdown.
	 *
	 * Options:
	 *   'front' => starts the text with a block of settings between two
	 *              lines of ---: the ones the content was read with, or
	 *              else its title, its author and its language.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'front'.
	 * @return String The Markdown.
	 *
	 * @throws WriteError When the shape is not 'block', or a block is of an unknown kind.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('block'), 'Markdown');
		$bodyText = implode("\n\n", $this->writeAllBlocks((array)($dataEnvelope['items'] ?? Array())));
		$bodyText .= ($bodyText==='') ? '' : "\n";
		if( empty($writeOptions['front']) ){
			return $bodyText;
		}
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$frontLst = (is_array($infoList['front'] ?? null) && !empty($infoList['front'])) ? $infoList['front'] : array_filter(Array('title'=>strval($infoList['title'] ?? ''), 'author'=>strval($infoList['authr'] ?? ''), 'lang'=>strval($infoList['langg'] ?? '')), 'strlen');
		if( empty($frontLst) ){
			return $bodyText;
		}
		return "---\n".(new Yaml())->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>$frontLst))."---\n".(($bodyText==='') ? '' : "\n".$bodyText);
	}

	/**
	 * Writes a text so that Markdown reads it as text: the characters it
	 * gives a meaning to stand after a backslash.
	 *
	 * @param String $templateText The text.
	 * @return String The text, safe to write.
	 */
	protected function escapeMdwnText(String $templateText): String {
		$safeText = preg_replace('/[\\\\`*\[\]]/', '\\\\$0', $templateText);
		// An underscore marks only against a word; a tilde only doubled; < and & only where an address or a character code could be read
		$safeText = preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $safeText);
		return preg_replace(Array('/~(?=~)/', '/<(?=[A-Za-z\/!?])/', '/&(?=#?\w+;)/'), Array('\\\\~', '\\\\<', '\\\\&'), $safeText);
	}

	/**
	 * Writes the start of the lines that would be read as something else
	 * than text after a backslash: a heading, a quote, an entry of a
	 * list, a rule, a line that makes a heading of the one above it.
	 *
	 * @param String $templateText The text of a paragraph, its lines as they will be written.
	 * @return String The text, its lines safe to start a paragraph.
	 */
	protected function protectLineTxt(String $templateText): String {
		$safeText = preg_replace('/^( {0,3})(#{1,6}(?:[ \t]|$)|>|[\-+](?:[ \t]|$)|=+[ \t]*$|-{2,}[ \t]*$|~{3})/m', '$1\\\\$2', $templateText);
		return preg_replace('/^( {0,3}\d{1,9})([.)](?:[ \t]|$))/m', '$1\\\\$2', $safeText);
	}

	/**
	 * Gives an address as it stands between the brackets of a link or of
	 * an image.
	 *
	 * @param String $linkGoalText The address.
	 * @return String The address, between angle brackets when it holds a space or a bracket; '' when it is not safe to follow.
	 */
	protected function linkTargetText(String $linkGoalText): String {
		$linkAddr = Shape::wipeUnsafeAddr($linkGoalText);
		if( $linkAddr!=='' && preg_match('/[\s()<>]/', $linkAddr) ){
			return '<'.str_replace(Array('<', '>', "\r", "\n"), Array('%3C', '%3E', '', ''), $linkAddr).'>';
		}
		return $linkAddr;
	}

	/**
	 * Writes spans: each text with the marks of its formatting around
	 * it, inside the brackets of its link. The marks stand against the
	 * words: the spaces at the ends of a span stay outside them.
	 *
	 * @param Array $textSpanList The spans.
	 * @param Bool  $flattenLines Whether the line breaks of the text are written as spaces, where the text must hold on a line.
	 * @return String The Markdown.
	 */
	protected function writeInlineTxt(Array $textSpanList, Bool $flattenLines=false): String {
		$outStrng = '';
		foreach($textSpanList as $oneSpn){
			$spanText = strval($oneSpn['texts'] ?? '');
			$markList = (array)($oneSpn['marks'] ?? Array());
			$linkAddr = strval($oneSpn['links'] ?? '');
			$matxRows = Array();
			// The reference to a note is written as Markdown writes them, whatever number it showed
			if( preg_match('/^#([fe])note(\d+)$/D', $linkAddr, $matxRows) ){
				$outStrng .= '[^'.(($matxRows[1]=='e') ? 'e' : '').$matxRows[2].']';
				continue;
			}
			$edgePart = Array();
			preg_match('/^(\s*)(.*?)(\s*)$/Ds', $spanText, $edgePart);
			$coreText = $edgePart[2];
			if( $coreText==='' ){
				$outStrng .= $flattenLines ? preg_replace('/\s+/', ' ', $spanText) : $spanText;
				continue;
			}
			if( in_array('codeSpan', $markList) ){
				// Code is written as it is, between more backticks than it holds in a row
				preg_match_all('/`+/', $coreText, $tickPart);
				$tickText = str_repeat('`', empty($tickPart[0]) ? 1 : max(array_map('strlen', $tickPart[0]))+1);
				$coreText = $tickText.(($coreText[0]=='`' || substr($coreText, -1)=='`') ? ' '.$coreText.' ' : $coreText).$tickText;
				$coreText = $flattenLines ? str_replace("\n", ' ', $coreText) : $coreText;
			}else{
				$coreText = str_replace("\n", $flattenLines ? ' ' : "\\\n", $this->escapeMdwnText($coreText));
			}
			if( in_array('crossOut', $markList) ){
				$coreText = '~~'.$coreText.'~~';
			}
			$jokerCnt = (in_array('boldFace', $markList) ? 2 : 0)+(in_array('emphasis', $markList) ? 1 : 0);
			if( $jokerCnt>0 ){
				// Stars standing against the stars of the span before would be read as one mark: underscores are written then
				$markChar = (substr($outStrng, -1)=='*' && $edgePart[1]==='') ? '_' : '*';
				$coreText = str_repeat($markChar, $jokerCnt).$coreText.str_repeat($markChar, $jokerCnt);
			}
			$nlaceUrl = $this->linkTargetText($linkAddr);
			if( $nlaceUrl!=='' ){
				$coreText = '['.$coreText.']('.$nlaceUrl.')';
			}
			$outStrng .= ($flattenLines ? preg_replace('/\s+/', ' ', $edgePart[1]) : $edgePart[1]).$coreText.($flattenLines ? preg_replace('/\s+/', ' ', $edgePart[3]) : $edgePart[3]);
		}
		return $flattenLines ? trim($outStrng) : $outStrng;
	}

	/**
	 * Starts every line of a text with the same spaces, empty lines left
	 * empty.
	 *
	 * @param String $templateText The text.
	 * @param String $indentString The spaces.
	 * @return String The text, further in.
	 */
	protected function indentAllLines(String $templateText, String $indentString): String {
		return preg_replace('/^(?=.)/m', $indentString, $templateText);
	}

	/**
	 * Writes what an entry, a quote or a note holds: its own text, then
	 * the blocks it contains, a list right under it and anything else
	 * after an empty line.
	 *
	 * @param Array $contentBlock The block.
	 * @return String The Markdown.
	 */
	protected function writeInnerPart(Array $contentBlock): String {
		$outStrng = $this->protectLineTxt($this->writeInlineTxt((array)$contentBlock['spans']));
		foreach((array)$contentBlock['items'] as $oneBlk){
			foreach($this->writeAllBlocks(Array($oneBlk)) as $oneTxt){
				$outStrng .= (($outStrng==='') ? '' : ((($oneBlk['block'] ?? '')=='itemList') ? "\n" : "\n\n")).$oneTxt;
			}
		}
		return $outStrng;
	}

	/**
	 * Writes a table: a line for the names of its columns, a line of
	 * dashes under it, then a line for each row. A table without header
	 * row has an empty one, which Markdown asks for.
	 *
	 * @param Array $tableBlcList The table.
	 * @return String The Markdown, '' for a table without cell.
	 */
	protected function writeTableText(Array $tableBlcList): String {
		$headNtry = null;
		$bodyRows = Array();
		$colsNumb = 0;
		foreach((array)$tableBlcList['items'] as $oneRow){
			$cellText = Array();
			foreach((array)$oneRow['items'] as $oneCel){
				// A cell holds on one line: what it holds besides its text follows it there
				$rawCelda = $this->writeInlineTxt((array)$oneCel['spans'], true);
				foreach((array)$oneCel['items'] as $oneBlk){
					$rawCelda = trim($rawCelda.' '.$this->escapeMdwnText(preg_replace('/\s+/', ' ', Shape::getTextOfBlock($oneBlk))));
				}
				$cellText[] = str_replace('|', '\\|', $rawCelda);
			}
			$colsNumb = max($colsNumb, count($cellText));
			if( ($oneRow['block'] ?? '')=='tableTop' && $headNtry===null && empty($bodyRows) ){
				$headNtry = $cellText;
			}else{
				$bodyRows[] = $cellText;
			}
		}
		if( $colsNumb==0 ){
			return '';
		}
		$lineList = Array('| '.implode(' | ', array_pad($headNtry ?? Array(), $colsNumb, '')).' |', '|'.str_repeat(' --- |', $colsNumb));
		foreach($bodyRows as $oneTxt){
			$lineList[] = '| '.implode(' | ', array_pad($oneTxt, $colsNumb, '')).' |';
		}
		return implode("\n", $lineList);
	}

	/**
	 * Writes blocks, each as its text.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Array The Markdown of each block that gives some, to be separated by an empty line.
	 *
	 * @throws WriteError When a block is of an unknown kind.
	 */
	protected function writeAllBlocks(Array $bookBlockSet): Array {
		$textList = Array();
		foreach($bookBlockSet as $oneBlk){
			$blockCod = strval($oneBlk['block'] ?? '');
			$blockTxt = '';
			if( $blockCod=='headline' ){
				$headText = preg_replace('/(?<=\s)#+$/D', '\\\\$0', $this->writeInlineTxt((array)$oneBlk['spans'], true));
				$tagIdent = strval($oneBlk['attrs']['tagid'] ?? '');
				$blockTxt = ($headText==='') ? '' : str_repeat('#', min(6, max(1, intval($oneBlk['attrs']['level'] ?? 1)))).' '.$headText.(preg_match('/^[\w\-:.]+$/D', $tagIdent) ? ' {#'.$tagIdent.'}' : '');
			}elseif( $blockCod=='paragrph' ){
				$blockTxt = $this->protectLineTxt(trim($this->writeInlineTxt((array)$oneBlk['spans'])));
			}elseif( $blockCod=='itemList' ){
				$itemText = Array();
				foreach((array)$oneBlk['items'] as $oneNtr){
					$markText = empty($oneBlk['attrs']['order']) ? '- ' : (count($itemText)+1).'. ';
					$taskText = isset($oneNtr['attrs']['check']) ? (empty($oneNtr['attrs']['check']) ? '[ ] ' : '[x] ') : '';
					// What an entry holds stands under its text, as far in as that text starts
					$itemText[] = rtrim($markText.ltrim($this->indentAllLines($taskText.$this->writeInnerPart($oneNtr), str_repeat(' ', strlen($markText)))));
				}
				$blockTxt = implode("\n", $itemText);
			}elseif( $blockCod=='tableSet' ){
				$blockTxt = $this->writeTableText($oneBlk);
			}elseif( $blockCod=='citation' ){
				$quoteTxt = $this->writeInnerPart($oneBlk);
				$citeText = strval($oneBlk['attrs']['cites'] ?? '');
				$quoteTxt .= ($citeText==='') ? '' : (($quoteTxt==='') ? '' : "\n\n").'— '.$this->escapeMdwnText($citeText);
				$blockTxt = ($quoteTxt==='') ? '' : preg_replace(Array('/^(?=.)/m', '/^$/m'), Array('> ', '>'), $quoteTxt);
			}elseif( $blockCod=='codeBlck' ){
				// The lines of marks around code are longer than any the code holds
				$codeText = strval($oneBlk['plain'] ?? '');
				preg_match_all('/^ {0,3}(`{3,})/m', $codeText, $tickPart);
				$tickText = str_repeat('`', empty($tickPart[1]) ? 3 : max(array_map('strlen', $tickPart[1]))+1);
				$codeLang = strval($oneBlk['attrs']['langg'] ?? '');
				$blockTxt = $tickText.(preg_match('/^[\w+#.\-]+$/D', $codeLang) ? $codeLang : '')."\n".$codeText."\n".$tickText;
			}elseif( $blockCod=='imageBox' ){
				$imageLnk = $this->linkTargetText(strval($oneBlk['attrs']['sourc'] ?? ''));
				$blockTxt = ($imageLnk==='') ? '' : '!['.$this->escapeMdwnText(str_replace("\n", ' ', strval($oneBlk['plain'] ?? ''))).']('.$imageLnk.')';
			}elseif( $blockCod=='ruleLine' ){
				$blockTxt = '---';
			}elseif( $blockCod=='footnote' ){
				$noteMark = preg_match('/^([fe])note(\d+)$/D', strval($oneBlk['attrs']['tagid'] ?? ''), $matxRows) ? (($matxRows[1]=='e') ? 'e' : '').$matxRows[2] : strval(intval($oneBlk['attrs']['numbr'] ?? 0));
				$blockTxt = '[^'.$noteMark.']: '.ltrim($this->indentAllLines($this->writeInnerPart($oneBlk), '    '));
			}elseif( $blockCod=='listItem' || $blockCod=='cellText' ){
				// An entry or a cell met outside its list or its table: what it holds
				$blockTxt = $this->writeInnerPart($oneBlk);
			}elseif( $blockCod=='tableTop' || $blockCod=='tableRow' ){
				$blockTxt = $this->writeTableText(Array('items'=>Array($oneBlk)));
			}else{
				throw new WriteError('Unknown kind of block: '.$blockCod);
			}
			if( $blockTxt!=='' ){
				$textList[] = $blockTxt;
			}
		}
		return $textList;
	}

}
