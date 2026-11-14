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
		$bodyTxt = implode("\n\n", $this->writeAllBlocks((array)($dataEnvelope['items'] ?? Array())));
		$bodyTxt .= ($bodyTxt==='') ? '' : "\n";
		if( empty($writeOptions['front']) ){
			return $bodyTxt;
		}
		$infoArr = (array)($dataEnvelope['infos'] ?? Array());
		$frntArr = (is_array($infoArr['front'] ?? null) && !empty($infoArr['front'])) ? $infoArr['front'] : array_filter(Array('title'=>strval($infoArr['title'] ?? ''), 'author'=>strval($infoArr['authr'] ?? ''), 'lang'=>strval($infoArr['langg'] ?? '')), 'strlen');
		if( empty($frntArr) ){
			return $bodyTxt;
		}
		return "---\n".(new Yaml())->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>$frntArr))."---\n".(($bodyTxt==='') ? '' : "\n".$bodyTxt);
	}

	/**
	 * Writes a text so that Markdown reads it as text: the characters it
	 * gives a meaning to stand after a backslash.
	 *
	 * @param String $templateText The text.
	 * @return String The text, safe to write.
	 */
	protected function escapeMdwnText(String $templateText): String {
		$safeTxt = preg_replace('/[\\\\`*\[\]]/', '\\\\$0', $templateText);
		// An underscore marks only against a word; a tilde only doubled; < and & only where an address or a character code could be read
		$safeTxt = preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $safeTxt);
		return preg_replace(Array('/~(?=~)/', '/<(?=[A-Za-z\/!?])/', '/&(?=#?\w+;)/'), Array('\\\\~', '\\\\<', '\\\\&'), $safeTxt);
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
		$safeTxt = preg_replace('/^( {0,3})(#{1,6}(?:[ \t]|$)|>|[\-+](?:[ \t]|$)|=+[ \t]*$|-{2,}[ \t]*$|~{3})/m', '$1\\\\$2', $templateText);
		return preg_replace('/^( {0,3}\d{1,9})([.)](?:[ \t]|$))/m', '$1\\\\$2', $safeTxt);
	}

	/**
	 * Gives an address as it stands between the brackets of a link or of
	 * an image.
	 *
	 * @param String $linkGoalText The address.
	 * @return String The address, between angle brackets when it holds a space or a bracket; '' when it is not safe to follow.
	 */
	protected function linkTargetText(String $linkGoalText): String {
		$linkAdr = Shape::tidyLinkString($linkGoalText);
		if( $linkAdr!=='' && preg_match('/[\s()<>]/', $linkAdr) ){
			return '<'.str_replace(Array('<', '>', "\r", "\n"), Array('%3C', '%3E', '', ''), $linkAdr).'>';
		}
		return $linkAdr;
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
		$outText = '';
		foreach($textSpanList as $oneSpn){
			$spanTxt = strval($oneSpn['texts'] ?? '');
			$markLst = (array)($oneSpn['marks'] ?? Array());
			$linkAdr = strval($oneSpn['links'] ?? '');
			$matches = Array();
			// The reference to a note is written as Markdown writes them, whatever number it showed
			if( preg_match('/^#([fe])note(\d+)$/D', $linkAdr, $matches) ){
				$outText .= '[^'.(($matches[1]=='e') ? 'e' : '').$matches[2].']';
				continue;
			}
			$edgPart = Array();
			preg_match('/^(\s*)(.*?)(\s*)$/Ds', $spanTxt, $edgPart);
			$coreTxt = $edgPart[2];
			if( $coreTxt==='' ){
				$outText .= $flattenLines ? preg_replace('/\s+/', ' ', $spanTxt) : $spanTxt;
				continue;
			}
			if( in_array('codeSpan', $markLst) ){
				// Code is written as it is, between more backticks than it holds in a row
				preg_match_all('/`+/', $coreTxt, $tckPart);
				$tickTxt = str_repeat('`', empty($tckPart[0]) ? 1 : max(array_map('strlen', $tckPart[0]))+1);
				$coreTxt = $tickTxt.(($coreTxt[0]=='`' || substr($coreTxt, -1)=='`') ? ' '.$coreTxt.' ' : $coreTxt).$tickTxt;
				$coreTxt = $flattenLines ? str_replace("\n", ' ', $coreTxt) : $coreTxt;
			}else{
				$coreTxt = str_replace("\n", $flattenLines ? ' ' : "\\\n", $this->escapeMdwnText($coreTxt));
			}
			if( in_array('crossOut', $markLst) ){
				$coreTxt = '~~'.$coreTxt.'~~';
			}
			$starNum = (in_array('boldFace', $markLst) ? 2 : 0)+(in_array('emphasis', $markLst) ? 1 : 0);
			if( $starNum>0 ){
				// Stars standing against the stars of the span before would be read as one mark: underscores are written then
				$markChr = (substr($outText, -1)=='*' && $edgPart[1]==='') ? '_' : '*';
				$coreTxt = str_repeat($markChr, $starNum).$coreTxt.str_repeat($markChr, $starNum);
			}
			$linkUrl = $this->linkTargetText($linkAdr);
			if( $linkUrl!=='' ){
				$coreTxt = '['.$coreTxt.']('.$linkUrl.')';
			}
			$outText .= ($flattenLines ? preg_replace('/\s+/', ' ', $edgPart[1]) : $edgPart[1]).$coreTxt.($flattenLines ? preg_replace('/\s+/', ' ', $edgPart[3]) : $edgPart[3]);
		}
		return $flattenLines ? trim($outText) : $outText;
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
	protected function innerOutString(Array $contentBlock): String {
		$outText = $this->protectLineTxt($this->writeInlineTxt((array)$contentBlock['spans']));
		foreach((array)$contentBlock['items'] as $oneBlk){
			foreach($this->writeAllBlocks(Array($oneBlk)) as $blkTxt){
				$outText .= (($outText==='') ? '' : ((($oneBlk['block'] ?? '')=='itemList') ? "\n" : "\n\n")).$blkTxt;
			}
		}
		return $outText;
	}

	/**
	 * Writes a table: a line for the names of its columns, a line of
	 * dashes under it, then a line for each row. A table without header
	 * row has an empty one, which Markdown asks for.
	 *
	 * @param Array $tableBlckArr The table.
	 * @return String The Markdown, '' for a table without cell.
	 */
	protected function writeTableText(Array $tableBlckArr): String {
		$headRow = null;
		$bodyRws = Array();
		$colsNum = 0;
		foreach((array)$tableBlckArr['items'] as $oneRow){
			$cellTxt = Array();
			foreach((array)$oneRow['items'] as $celBlk){
				// A cell holds on one line: what it holds besides its text follows it there
				$rawCell = $this->writeInlineTxt((array)$celBlk['spans'], true);
				foreach((array)$celBlk['items'] as $oneBlk){
					$rawCell = trim($rawCell.' '.$this->escapeMdwnText(preg_replace('/\s+/', ' ', Shape::blockPlainText($oneBlk))));
				}
				$cellTxt[] = str_replace('|', '\\|', $rawCell);
			}
			$colsNum = max($colsNum, count($cellTxt));
			if( ($oneRow['block'] ?? '')=='tableTop' && $headRow===null && empty($bodyRws) ){
				$headRow = $cellTxt;
			}else{
				$bodyRws[] = $cellTxt;
			}
		}
		if( $colsNum==0 ){
			return '';
		}
		$lineLst = Array('| '.implode(' | ', array_pad($headRow ?? Array(), $colsNum, '')).' |', '|'.str_repeat(' --- |', $colsNum));
		foreach($bodyRws as $rowTxt){
			$lineLst[] = '| '.implode(' | ', array_pad($rowTxt, $colsNum, '')).' |';
		}
		return implode("\n", $lineLst);
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
		$textLst = Array();
		foreach($bookBlockSet as $oneBlk){
			$blckKnd = strval($oneBlk['block'] ?? '');
			$blckTxt = '';
			if( $blckKnd=='headline' ){
				$headTxt = preg_replace('/(?<=\s)#+$/D', '\\\\$0', $this->writeInlineTxt((array)$oneBlk['spans'], true));
				$tagIdnt = strval($oneBlk['attrs']['tagid'] ?? '');
				$blckTxt = ($headTxt==='') ? '' : str_repeat('#', min(6, max(1, intval($oneBlk['attrs']['level'] ?? 1)))).' '.$headTxt.(preg_match('/^[\w\-:.]+$/D', $tagIdnt) ? ' {#'.$tagIdnt.'}' : '');
			}elseif( $blckKnd=='paragrph' ){
				$blckTxt = $this->protectLineTxt(trim($this->writeInlineTxt((array)$oneBlk['spans'])));
			}elseif( $blckKnd=='itemList' ){
				$itemTxt = Array();
				foreach((array)$oneBlk['items'] as $entBlk){
					$markTxt = empty($oneBlk['attrs']['order']) ? '- ' : (count($itemTxt)+1).'. ';
					$taskTxt = isset($entBlk['attrs']['check']) ? (empty($entBlk['attrs']['check']) ? '[ ] ' : '[x] ') : '';
					// What an entry holds stands under its text, as far in as that text starts
					$itemTxt[] = rtrim($markTxt.ltrim($this->indentAllLines($taskTxt.$this->innerOutString($entBlk), str_repeat(' ', strlen($markTxt)))));
				}
				$blckTxt = implode("\n", $itemTxt);
			}elseif( $blckKnd=='tableSet' ){
				$blckTxt = $this->writeTableText($oneBlk);
			}elseif( $blckKnd=='citation' ){
				$qutText = $this->innerOutString($oneBlk);
				$citeTxt = strval($oneBlk['attrs']['cites'] ?? '');
				$qutText .= ($citeTxt==='') ? '' : (($qutText==='') ? '' : "\n\n").'— '.$this->escapeMdwnText($citeTxt);
				$blckTxt = ($qutText==='') ? '' : preg_replace(Array('/^(?=.)/m', '/^$/m'), Array('> ', '>'), $qutText);
			}elseif( $blckKnd=='codeBlck' ){
				// The lines of marks around code are longer than any the code holds
				$codeTxt = strval($oneBlk['plain'] ?? '');
				preg_match_all('/^ {0,3}(`{3,})/m', $codeTxt, $tckPart);
				$tickTxt = str_repeat('`', empty($tckPart[1]) ? 3 : max(array_map('strlen', $tckPart[1]))+1);
				$codeLng = strval($oneBlk['attrs']['langg'] ?? '');
				$blckTxt = $tickTxt.(preg_match('/^[\w+#.\-]+$/D', $codeLng) ? $codeLng : '')."\n".$codeTxt."\n".$tickTxt;
			}elseif( $blckKnd=='imageBox' ){
				$imgLink = $this->linkTargetText(strval($oneBlk['attrs']['sourc'] ?? ''));
				$blckTxt = ($imgLink==='') ? '' : '!['.$this->escapeMdwnText(str_replace("\n", ' ', strval($oneBlk['plain'] ?? ''))).']('.$imgLink.')';
			}elseif( $blckKnd=='ruleLine' ){
				$blckTxt = '---';
			}elseif( $blckKnd=='footnote' ){
				$noteMrk = preg_match('/^([fe])note(\d+)$/D', strval($oneBlk['attrs']['tagid'] ?? ''), $matches) ? (($matches[1]=='e') ? 'e' : '').$matches[2] : strval(intval($oneBlk['attrs']['numbr'] ?? 0));
				$blckTxt = '[^'.$noteMrk.']: '.ltrim($this->indentAllLines($this->innerOutString($oneBlk), '    '));
			}elseif( $blckKnd=='listItem' || $blckKnd=='cellText' ){
				// An entry or a cell met outside its list or its table: what it holds
				$blckTxt = $this->innerOutString($oneBlk);
			}elseif( $blckKnd=='tableTop' || $blckKnd=='tableRow' ){
				$blckTxt = $this->writeTableText(Array('items'=>Array($oneBlk)));
			}else{
				throw new WriteError('Unknown kind of block: '.$blckKnd);
			}
			if( $blckTxt!=='' ){
				$textLst[] = $blckTxt;
			}
		}
		return $textLst;
	}

}
