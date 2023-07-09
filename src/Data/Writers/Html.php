<?php

namespace nyu\Data\Writers;

use nyu\Data\Shape;

// HTML: text blocks as headings, paragraphs, lists and tables, sheets as
// tables, a tree of values as indented text. Every piece of text is
// escaped, and an address is written only when it is safe to follow.
class Html implements Writer {

	// HTML element of each formatting, from the outermost to the innermost
	const MARKTAGS = Array('bold'=>'strong', 'ital'=>'em', 'undr'=>'u', 'strk'=>'s', 'code'=>'code');

	/**
	 * Writes a content as HTML.
	 *
	 * Options:
	 *   'whole' => writes a whole page, with its head and the title given
	 *              by 'infos', instead of the content alone;
	 *   'heads' => for sheets, writes the first row of each as its header.
	 *
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'whole', 'heads'.
	 * @return String The HTML.
	 *
	 * @throws \RuntimeException When the shape is not 'block', 'sheet' or 'nodes'.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$shapeAs = strval($shapedData['shape'] ?? '');
		$itemLst = $shapedData['items'] ?? Array();
		if( $shapeAs=='block' ){
			$htmBody = $this->blocksOut((array)$itemLst);
		}elseif( $shapeAs=='sheet' ){
			$htmBody = $this->sheetsOut((array)$itemLst, !empty($writeOptns['heads']));
		}elseif( $shapeAs=='nodes' ){
			$htmBody = '<pre>'.$this->escapeTxt(strval(json_encode($itemLst, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))).'</pre>'."\n";
		}else{
			throw new \RuntimeException('Shape that cannot be written as HTML: '.$shapeAs);
		}
		if( empty($writeOptns['whole']) ){
			return $htmBody;
		}
		return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<title>".$this->escapeTxt(strval($shapedData['infos']['title'] ?? ''))."</title>\n</head>\n<body>\n".$htmBody."</body>\n</html>\n";
	}

	/**
	 * Escapes a text for HTML, in content or in an attribute.
	 *
	 * @param String $textString The text.
	 * @return String The text, safe to write.
	 */
	protected function escapeTxt(String $textString): String {
		return htmlspecialchars($textString, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Writes the id of a block as an attribute, when it has a usable one.
	 *
	 * @param Array $blockArray The block.
	 * @return String The attribute, with its leading space, or ''.
	 */
	protected function identAttr(Array $blockArray): String {
		$tagIdnt = strval($blockArray['attrs']['tagid'] ?? '');
		return preg_match('/^[\w\-:.]+$/D', $tagIdnt) ? ' id="'.$this->escapeTxt($tagIdnt).'"' : '';
	}

	/**
	 * Writes spans: each text escaped, inside the elements of its
	 * formatting and of its link, its line breaks as br elements.
	 *
	 * @param Array $spansArray The spans.
	 * @return String The HTML.
	 */
	protected function inlineOut(Array $spansArray): String {
		$htmText = '';
		foreach($spansArray as $oneSpn){
			$spanHtm = str_replace("\n", "<br>\n", $this->escapeTxt(strval($oneSpn['texts'] ?? '')));
			foreach(array_reverse(self::MARKTAGS) as $mrkKey=>$mrkTag){
				if( in_array($mrkKey, (array)($oneSpn['marks'] ?? Array())) ){
					$spanHtm = '<'.$mrkTag.'>'.$spanHtm.'</'.$mrkTag.'>';
				}
			}
			$linkAdr = Shape::cleanLink(strval($oneSpn['links'] ?? ''));
			if( $linkAdr!=='' ){
				$spanHtm = '<a href="'.$this->escapeTxt($linkAdr).'">'.$spanHtm.'</a>';
			}
			$htmText .= $spanHtm;
		}
		return $htmText;
	}

	/**
	 * Writes what an entry, a cell or a quote holds: its own text, then
	 * the blocks it contains.
	 *
	 * @param Array $blockArray The block.
	 * @return String The HTML.
	 */
	protected function insideOut(Array $blockArray): String {
		$ownHtml = $this->inlineOut($blockArray['spans']);
		if( empty($blockArray['items']) ){
			return $ownHtml;
		}
		return $ownHtml."\n".$this->blocksOut($blockArray['items']);
	}

	/**
	 * Writes a list of blocks, one after the other.
	 *
	 * @param Array $blocksList The blocks.
	 * @return String The HTML.
	 *
	 * @throws \RuntimeException When a block is of an unknown kind.
	 */
	protected function blocksOut(Array $blocksList): String {
		$htmText = '';
		foreach($blocksList as $oneBlk){
			$blckKnd = strval($oneBlk['block'] ?? '');
			$idntAtr = $this->identAttr($oneBlk);
			if( $blckKnd=='headr' ){
				$hdrLevl = min(6, max(1, intval($oneBlk['attrs']['level'] ?? 1)));
				$htmText .= '<h'.$hdrLevl.$idntAtr.'>'.$this->inlineOut($oneBlk['spans']).'</h'.$hdrLevl.">\n";
			}elseif( $blckKnd=='parag' ){
				$htmText .= '<p'.$idntAtr.'>'.$this->inlineOut($oneBlk['spans'])."</p>\n";
			}elseif( $blckKnd=='lists' ){
				$listTag = empty($oneBlk['attrs']['order']) ? 'ul' : 'ol';
				$htmText .= '<'.$listTag.$idntAtr.">\n";
				foreach($oneBlk['items'] as $entBlk){
					$htmText .= '<li'.$this->identAttr($entBlk).'>'.$this->insideOut($entBlk)."</li>\n";
				}
				$htmText .= '</'.$listTag.">\n";
			}elseif( $blckKnd=='table' ){
				$htmText .= '<table'.$idntAtr.">\n".$this->tblRowOut($oneBlk['items'])."</table>\n";
			}elseif( $blckKnd=='quote' ){
				$htmText .= '<blockquote'.$idntAtr.'>'.$this->insideOut($oneBlk)."</blockquote>\n";
			}elseif( $blckKnd=='codes' ){
				$codeLng = strval($oneBlk['attrs']['langg'] ?? '');
				$htmText .= '<pre'.$idntAtr.'><code'.(preg_match('/^[\w+#.\-]+$/D', $codeLng) ? ' class="language-'.$this->escapeTxt($codeLng).'"' : '').'>'.$this->escapeTxt($oneBlk['plain'])."</code></pre>\n";
			}elseif( $blckKnd=='image' ){
				$imgSrce = Shape::cleanLink(strval($oneBlk['attrs']['sourc'] ?? ''));
				if( $imgSrce!=='' ){
					$htmText .= '<img'.$idntAtr.' src="'.$this->escapeTxt($imgSrce).'" alt="'.$this->escapeTxt($oneBlk['plain'])."\">\n";
				}
			}elseif( $blckKnd=='rules' ){
				$htmText .= '<hr'.$idntAtr.">\n";
			}elseif( in_array($blckKnd, Array('entry', 'tcell')) ){
				// An entry or a cell met outside its list or its table: what it holds
				$htmText .= $this->insideOut($oneBlk)."\n";
			}elseif( in_array($blckKnd, Array('thead', 'tbrow')) ){
				$htmText .= "<table>\n".$this->tblRowOut(Array($oneBlk))."</table>\n";
			}else{
				throw new \RuntimeException('Unknown kind of block: '.$blckKnd);
			}
		}
		return $htmText;
	}

	/**
	 * Writes the rows of a table: the header rows in a thead element,
	 * the others in a tbody.
	 *
	 * @param Array $rowsBlocks The rows, as 'thead' and 'tbrow' blocks.
	 * @return String The HTML.
	 */
	protected function tblRowOut(Array $rowsBlocks): String {
		$partHtm = Array('thead'=>'', 'tbody'=>'');
		foreach($rowsBlocks as $rowBlk){
			$isHeadr = (strval($rowBlk['block'] ?? '')=='thead');
			$rowHtml = '<tr>';
			foreach($rowBlk['items'] as $celBlk){
				$cellTag = ($isHeadr || !empty($celBlk['attrs']['headr'])) ? 'th' : 'td';
				$spanAtr = '';
				foreach(Array('colsp'=>'colspan', 'rowsp'=>'rowspan') as $atrKey=>$atrNme){
					if( intval($celBlk['attrs'][$atrKey] ?? 1)>1 ){
						$spanAtr .= ' '.$atrNme.'="'.intval($celBlk['attrs'][$atrKey]).'"';
					}
				}
				$rowHtml .= '<'.$cellTag.$this->identAttr($celBlk).$spanAtr.'>'.$this->insideOut($celBlk).'</'.$cellTag.'>';
			}
			$partHtm[$isHeadr ? 'thead' : 'tbody'] .= $rowHtml."</tr>\n";
		}
		return (($partHtm['thead']!=='') ? "<thead>\n".$partHtm['thead']."</thead>\n" : '').(($partHtm['tbody']!=='') ? "<tbody>\n".$partHtm['tbody']."</tbody>\n" : '');
	}

	/**
	 * Writes sheets, each as a table with its title as caption.
	 *
	 * @param Array $sheetsList The sheets, each with its 'title' and its 'cells'.
	 * @param Bool  $firstHeads Whether the first row of each sheet is its header.
	 * @return String The HTML.
	 */
	protected function sheetsOut(Array $sheetsList, Bool $firstHeads): String {
		$htmText = '';
		foreach($sheetsList as $oneSht){
			$htmText .= "<table>\n";
			if( strval($oneSht['title'] ?? '')!=='' ){
				$htmText .= '<caption>'.$this->escapeTxt(strval($oneSht['title']))."</caption>\n";
			}
			$rowsLst = array_values((array)($oneSht['cells'] ?? Array()));
			foreach($rowsLst as $rowIdx=>$oneRow){
				$isHeadr = ($firstHeads && $rowIdx==0);
				$rowHtml = '';
				foreach((array)$oneRow as $celVal){
					$celText = is_bool($celVal) ? ($celVal ? 'true' : 'false') : (is_scalar($celVal) ? strval($celVal) : '');
					$rowHtml .= ($isHeadr ? '<th>' : '<td>').$this->escapeTxt($celText).($isHeadr ? '</th>' : '</td>');
				}
				if( $isHeadr ){
					$htmText .= "<thead>\n<tr>".$rowHtml."</tr>\n</thead>\n";
					continue;
				}
				$htmText .= (($rowIdx==($firstHeads ? 1 : 0)) ? "<tbody>\n" : '').'<tr>'.$rowHtml."</tr>\n";
			}
			if( count($rowsLst)>($firstHeads ? 1 : 0) ){
				$htmText .= "</tbody>\n";
			}
			$htmText .= "</table>\n";
		}
		return $htmText;
	}

}
