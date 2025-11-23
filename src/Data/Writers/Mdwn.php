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
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'front'.
	 * @return String The Markdown.
	 *
	 * @throws WriteError When the shape is not 'block', or a block is of an unknown kind.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('block'), 'Markdown');
		$bodyTxt = implode("\n\n", $this->blocksOut((array)($shapedData['items'] ?? Array())));
		$bodyTxt .= ($bodyTxt==='') ? '' : "\n";
		if( empty($writeOptns['front']) ){
			return $bodyTxt;
		}
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$frntArr = (is_array($infoArr['front'] ?? null) && !empty($infoArr['front'])) ? $infoArr['front'] : array_filter(Array('title'=>strval($infoArr['title'] ?? ''), 'author'=>strval($infoArr['authr'] ?? ''), 'lang'=>strval($infoArr['langg'] ?? '')), 'strlen');
		if( empty($frntArr) ){
			return $bodyTxt;
		}
		return "---\n".(new Yaml())->writeData(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>$frntArr))."---\n".(($bodyTxt==='') ? '' : "\n".$bodyTxt);
	}

	/**
	 * Writes a text so that Markdown reads it as text: the characters it
	 * gives a meaning to stand after a backslash.
	 *
	 * @param String $textString The text.
	 * @return String The text, safe to write.
	 */
	protected function escapeTxt(String $textString): String {
		$safeTxt = preg_replace('/[\\\\`*\[\]]/', '\\\\$0', $textString);
		// An underscore marks only against a word; a tilde only doubled; < and & only where an address or a character code could be read
		$safeTxt = preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $safeTxt);
		return preg_replace(Array('/~(?=~)/', '/<(?=[A-Za-z\/!?])/', '/&(?=#?\w+;)/'), Array('\\\\~', '\\\\<', '\\\\&'), $safeTxt);
	}

	/**
	 * Writes the start of the lines that would be read as something else
	 * than text after a backslash: a heading, a quote, an entry of a
	 * list, a rule, a line that makes a heading of the one above it.
	 *
	 * @param String $textString The text of a paragraph, its lines as they will be written.
	 * @return String The text, its lines safe to start a paragraph.
	 */
	protected function guardLine(String $textString): String {
		$safeTxt = preg_replace('/^( {0,3})(#{1,6}(?:[ \t]|$)|>|[\-+](?:[ \t]|$)|=+[ \t]*$|-{2,}[ \t]*$|~{3})/m', '$1\\\\$2', $textString);
		return preg_replace('/^( {0,3}\d{1,9})([.)](?:[ \t]|$))/m', '$1\\\\$2', $safeTxt);
	}

	/**
	 * Gives an address as it stands between the brackets of a link or of
	 * an image.
	 *
	 * @param String $linkTarget The address.
	 * @return String The address, between angle brackets when it holds a space or a bracket; '' when it is not safe to follow.
	 */
	protected function destOfLnk(String $linkTarget): String {
		$linkAdr = Shape::cleanLink($linkTarget);
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
	 * @param Array $spansArray The spans.
	 * @param Bool  $oneLineOut Whether the line breaks of the text are written as spaces, where the text must hold on a line.
	 * @return String The Markdown.
	 */
	protected function inlineOut(Array $spansArray, Bool $oneLineOut=false): String {
		$mdwText = '';
		foreach($spansArray as $oneSpn){
			$spanTxt = strval($oneSpn['texts'] ?? '');
			$markLst = (array)($oneSpn['marks'] ?? Array());
			$linkAdr = strval($oneSpn['links'] ?? '');
			$notePrt = Array();
			// The reference to a note is written as Markdown writes them, whatever number it showed
			if( preg_match('/^#([fe])note(\d+)$/D', $linkAdr, $notePrt) ){
				$mdwText .= '[^'.(($notePrt[1]=='e') ? 'e' : '').$notePrt[2].']';
				continue;
			}
			$edgePrt = Array();
			preg_match('/^(\s*)(.*?)(\s*)$/Ds', $spanTxt, $edgePrt);
			$coreTxt = $edgePrt[2];
			if( $coreTxt==='' ){
				$mdwText .= $oneLineOut ? preg_replace('/\s+/', ' ', $spanTxt) : $spanTxt;
				continue;
			}
			if( in_array('code', $markLst) ){
				// Code is written as it is, between more backticks than it holds in a row
				preg_match_all('/`+/', $coreTxt, $tickPrt);
				$tickTxt = str_repeat('`', empty($tickPrt[0]) ? 1 : max(array_map('strlen', $tickPrt[0]))+1);
				$coreTxt = $tickTxt.(($coreTxt[0]=='`' || substr($coreTxt, -1)=='`') ? ' '.$coreTxt.' ' : $coreTxt).$tickTxt;
				$coreTxt = $oneLineOut ? str_replace("\n", ' ', $coreTxt) : $coreTxt;
			}else{
				$coreTxt = str_replace("\n", $oneLineOut ? ' ' : "\\\n", $this->escapeTxt($coreTxt));
			}
			if( in_array('strk', $markLst) ){
				$coreTxt = '~~'.$coreTxt.'~~';
			}
			$starNbr = (in_array('bold', $markLst) ? 2 : 0)+(in_array('ital', $markLst) ? 1 : 0);
			if( $starNbr>0 ){
				// Stars standing against the stars of the span before would be read as one mark: underscores are written then
				$markChr = (substr($mdwText, -1)=='*' && $edgePrt[1]==='') ? '_' : '*';
				$coreTxt = str_repeat($markChr, $starNbr).$coreTxt.str_repeat($markChr, $starNbr);
			}
			$linkDst = $this->destOfLnk($linkAdr);
			if( $linkDst!=='' ){
				$coreTxt = '['.$coreTxt.']('.$linkDst.')';
			}
			$mdwText .= ($oneLineOut ? preg_replace('/\s+/', ' ', $edgePrt[1]) : $edgePrt[1]).$coreTxt.($oneLineOut ? preg_replace('/\s+/', ' ', $edgePrt[3]) : $edgePrt[3]);
		}
		return $oneLineOut ? trim($mdwText) : $mdwText;
	}

	/**
	 * Starts every line of a text with the same spaces, empty lines left
	 * empty.
	 *
	 * @param String $textString The text.
	 * @param String $indentText The spaces.
	 * @return String The text, further in.
	 */
	protected function indentTxt(String $textString, String $indentText): String {
		return preg_replace('/^(?=.)/m', $indentText, $textString);
	}

	/**
	 * Writes what an entry, a quote or a note holds: its own text, then
	 * the blocks it contains, a list right under it and anything else
	 * after an empty line.
	 *
	 * @param Array $blockArray The block.
	 * @return String The Markdown.
	 */
	protected function insideOut(Array $blockArray): String {
		$mdwText = $this->guardLine($this->inlineOut((array)$blockArray['spans']));
		foreach((array)$blockArray['items'] as $oneBlk){
			foreach($this->blocksOut(Array($oneBlk)) as $blkTxt){
				$mdwText .= (($mdwText==='') ? '' : ((($oneBlk['block'] ?? '')=='lists') ? "\n" : "\n\n")).$blkTxt;
			}
		}
		return $mdwText;
	}

	/**
	 * Writes a table: a line for the names of its columns, a line of
	 * dashes under it, then a line for each row. A table without header
	 * row has an empty one, which Markdown asks for.
	 *
	 * @param Array $tableBlock The table.
	 * @return String The Markdown, '' for a table without cell.
	 */
	protected function tablesOut(Array $tableBlock): String {
		$headRow = null;
		$bodyRws = Array();
		$colsNbr = 0;
		foreach((array)$tableBlock['items'] as $rowBlk){
			$cellTxt = Array();
			foreach((array)$rowBlk['items'] as $celBlk){
				// A cell holds on one line: what it holds besides its text follows it there
				$celText = $this->inlineOut((array)$celBlk['spans'], true);
				foreach((array)$celBlk['items'] as $oneBlk){
					$celText = trim($celText.' '.$this->escapeTxt(preg_replace('/\s+/', ' ', Shape::plainText($oneBlk))));
				}
				$cellTxt[] = str_replace('|', '\\|', $celText);
			}
			$colsNbr = max($colsNbr, count($cellTxt));
			if( ($rowBlk['block'] ?? '')=='thead' && $headRow===null && empty($bodyRws) ){
				$headRow = $cellTxt;
			}else{
				$bodyRws[] = $cellTxt;
			}
		}
		if( $colsNbr==0 ){
			return '';
		}
		$lineLst = Array('| '.implode(' | ', array_pad($headRow ?? Array(), $colsNbr, '')).' |', '|'.str_repeat(' --- |', $colsNbr));
		foreach($bodyRws as $rowTxt){
			$lineLst[] = '| '.implode(' | ', array_pad($rowTxt, $colsNbr, '')).' |';
		}
		return implode("\n", $lineLst);
	}

	/**
	 * Writes blocks, each as its text.
	 *
	 * @param Array $blocksList The blocks.
	 * @return Array The Markdown of each block that gives some, to be separated by an empty line.
	 *
	 * @throws WriteError When a block is of an unknown kind.
	 */
	protected function blocksOut(Array $blocksList): Array {
		$textLst = Array();
		foreach($blocksList as $oneBlk){
			$blckKnd = strval($oneBlk['block'] ?? '');
			$blckTxt = '';
			if( $blckKnd=='headr' ){
				$headTxt = preg_replace('/(?<=\s)#+$/D', '\\\\$0', $this->inlineOut((array)$oneBlk['spans'], true));
				$tagIdnt = strval($oneBlk['attrs']['tagid'] ?? '');
				$blckTxt = ($headTxt==='') ? '' : str_repeat('#', min(6, max(1, intval($oneBlk['attrs']['level'] ?? 1)))).' '.$headTxt.(preg_match('/^[\w\-:.]+$/D', $tagIdnt) ? ' {#'.$tagIdnt.'}' : '');
			}elseif( $blckKnd=='parag' ){
				$blckTxt = $this->guardLine(trim($this->inlineOut((array)$oneBlk['spans'])));
			}elseif( $blckKnd=='lists' ){
				$entrTxt = Array();
				foreach((array)$oneBlk['items'] as $entBlk){
					$markTxt = empty($oneBlk['attrs']['order']) ? '- ' : (count($entrTxt)+1).'. ';
					$taskTxt = isset($entBlk['attrs']['check']) ? (empty($entBlk['attrs']['check']) ? '[ ] ' : '[x] ') : '';
					// What an entry holds stands under its text, as far in as that text starts
					$entrTxt[] = rtrim($markTxt.ltrim($this->indentTxt($taskTxt.$this->insideOut($entBlk), str_repeat(' ', strlen($markTxt)))));
				}
				$blckTxt = implode("\n", $entrTxt);
			}elseif( $blckKnd=='table' ){
				$blckTxt = $this->tablesOut($oneBlk);
			}elseif( $blckKnd=='quote' ){
				$qteText = $this->insideOut($oneBlk);
				$citeTxt = strval($oneBlk['attrs']['cites'] ?? '');
				$qteText .= ($citeTxt==='') ? '' : (($qteText==='') ? '' : "\n\n").'— '.$this->escapeTxt($citeTxt);
				$blckTxt = ($qteText==='') ? '' : preg_replace(Array('/^(?=.)/m', '/^$/m'), Array('> ', '>'), $qteText);
			}elseif( $blckKnd=='codes' ){
				// The lines of marks around code are longer than any the code holds
				$codeTxt = strval($oneBlk['plain'] ?? '');
				preg_match_all('/^ {0,3}(`{3,})/m', $codeTxt, $tickPrt);
				$tickTxt = str_repeat('`', empty($tickPrt[1]) ? 3 : max(array_map('strlen', $tickPrt[1]))+1);
				$codeLng = strval($oneBlk['attrs']['langg'] ?? '');
				$blckTxt = $tickTxt.(preg_match('/^[\w+#.\-]+$/D', $codeLng) ? $codeLng : '')."\n".$codeTxt."\n".$tickTxt;
			}elseif( $blckKnd=='image' ){
				$imgDest = $this->destOfLnk(strval($oneBlk['attrs']['sourc'] ?? ''));
				$blckTxt = ($imgDest==='') ? '' : '!['.$this->escapeTxt(str_replace("\n", ' ', strval($oneBlk['plain'] ?? ''))).']('.$imgDest.')';
			}elseif( $blckKnd=='rules' ){
				$blckTxt = '---';
			}elseif( $blckKnd=='fnote' ){
				$noteMrk = preg_match('/^([fe])note(\d+)$/D', strval($oneBlk['attrs']['tagid'] ?? ''), $notePrt) ? (($notePrt[1]=='e') ? 'e' : '').$notePrt[2] : strval(intval($oneBlk['attrs']['numbr'] ?? 0));
				$blckTxt = '[^'.$noteMrk.']: '.ltrim($this->indentTxt($this->insideOut($oneBlk), '    '));
			}elseif( $blckKnd=='entry' || $blckKnd=='tcell' ){
				// An entry or a cell met outside its list or its table: what it holds
				$blckTxt = $this->insideOut($oneBlk);
			}elseif( $blckKnd=='thead' || $blckKnd=='tbrow' ){
				$blckTxt = $this->tablesOut(Array('items'=>Array($oneBlk)));
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
