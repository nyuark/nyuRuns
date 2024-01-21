<?php

namespace nyu\Data\Writers;

use nyu\Data\Shape;

// Text: text blocks written as plain text, with the tags of forums, or
// with the marks of messaging.
//
//   'plain' => the text alone: lists after '- ' and '1. ', the rows of a
//              table one per line with tabulations between their cells;
//   'bbcod' => [b] [i] [u] [s], [url=...], [img], [code], [quote], [list];
//   'chats' => *bold* _italic_ __underlined__ ~strike~, and lists.
//
// What a way of writing has no mark for is written as its text.
class Text extends Base {

	// The marks around a formatting, for each way of writing: what opens it, what closes it
	const MARKLIST = Array(
		'plain'=>Array(),
		'bbcod'=>Array('bold'=>Array('[b]', '[/b]'), 'ital'=>Array('[i]', '[/i]'), 'undr'=>Array('[u]', '[/u]'), 'strk'=>Array('[s]', '[/s]')),
		'chats'=>Array('bold'=>Array('*', '*'), 'ital'=>Array('_', '_'), 'undr'=>Array('__', '__'), 'strk'=>Array('~', '~'))
	);

	// The way of writing asked for
	protected $givesKnd = 'plain';

	/**
	 * Writes text blocks as text.
	 *
	 * Options:
	 *   'gives' => 'plain', 'bbcod' or 'chats': what the text carries;
	 *              'plain' when left out.
	 *
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'gives'.
	 * @return String The text.
	 *
	 * @throws WriteError When the shape is not 'block', the 'gives' option is not a known one, or a block is of an unknown kind.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('block'), 'text');
		$this->givesKnd = strtolower(strval($writeOptns['gives'] ?? 'plain'));
		if( !isset(self::MARKLIST[$this->givesKnd]) ){
			throw new WriteError('Invalid text option: unknown writing '.$this->givesKnd);
		}
		$bodyTxt = implode("\n\n", $this->blocksOut((array)($shapedData['items'] ?? Array())));
		return ($bodyTxt==='') ? '' : $bodyTxt."\n";
	}

	/**
	 * Writes spans: each text with the marks of its formatting around
	 * it, the spaces at its ends left outside them, and its link.
	 *
	 * @param Array $spansArray The spans.
	 * @return String The text.
	 */
	protected function inlineOut(Array $spansArray): String {
		$outText = '';
		foreach($spansArray as $oneSpn){
			$edgePrt = Array();
			preg_match('/^(\s*)(.*?)(\s*)$/Ds', strval($oneSpn['texts'] ?? ''), $edgePrt);
			$coreTxt = $edgePrt[2];
			$linkAdr = Shape::cleanLink(strval($oneSpn['links'] ?? ''));
			$notePrt = Array();
			if( $coreTxt!=='' && preg_match('/^#[fe]note(\d+)$/D', $linkAdr, $notePrt) ){
				// The reference to a note stands between square brackets
				$coreTxt = '['.$coreTxt.']';
				$linkAdr = '';
			}elseif( $coreTxt!=='' ){
				foreach(array_reverse(self::MARKLIST[$this->givesKnd]) as $mrkKey=>$mrkSet){
					if( in_array($mrkKey, (array)($oneSpn['marks'] ?? Array())) ){
						$coreTxt = $mrkSet[0].$coreTxt.$mrkSet[1];
					}
				}
			}
			if( $coreTxt!=='' && $linkAdr!=='' ){
				// A link: a tag where there is one, the address after the text otherwise, once when the text is the address
				$coreTxt = ($this->givesKnd=='bbcod') ? '[url='.str_replace(Array('[', ']'), Array('%5B', '%5D'), $linkAdr).']'.$coreTxt.'[/url]' : (($edgePrt[2]===$linkAdr) ? $coreTxt : $coreTxt.' ('.$linkAdr.')');
			}
			$outText .= $edgePrt[1].$coreTxt.$edgePrt[3];
		}
		return $outText;
	}

	/**
	 * Writes what an entry, a quote or a note holds: its own text, then
	 * the blocks it contains, a list right under it and anything else
	 * after an empty line.
	 *
	 * @param Array $blockArray The block.
	 * @return String The text.
	 */
	protected function insideOut(Array $blockArray): String {
		$outText = trim($this->inlineOut((array)$blockArray['spans']));
		foreach((array)$blockArray['items'] as $oneBlk){
			foreach($this->blocksOut(Array($oneBlk)) as $blkTxt){
				$outText .= (($outText==='') ? '' : ((($oneBlk['block'] ?? '')=='lists') ? "\n" : "\n\n")).$blkTxt;
			}
		}
		return $outText;
	}

	/**
	 * Writes a list: its entries one per line after a mark or a number,
	 * what an entry holds further in; with the tags of forums, between
	 * the tags of the list.
	 *
	 * @param Array $listsBlock The list.
	 * @return String The text.
	 */
	protected function listedOut(Array $listsBlock): String {
		$isOrdrd = !empty($listsBlock['attrs']['order']);
		$entrTxt = Array();
		foreach((array)$listsBlock['items'] as $entBlk){
			$insdTxt = $this->insideOut($entBlk);
			if( $this->givesKnd=='bbcod' ){
				$entrTxt[] = '[*]'.$insdTxt;
				continue;
			}
			$markTxt = $isOrdrd ? (count($entrTxt)+1).'. ' : '- ';
			$entrTxt[] = rtrim($markTxt.ltrim(preg_replace('/^(?=.)/m', str_repeat(' ', strlen($markTxt)), $insdTxt)));
		}
		if( $this->givesKnd=='bbcod' ){
			return empty($entrTxt) ? '' : ($isOrdrd ? '[list=1]' : '[list]')."\n".implode("\n", $entrTxt)."\n[/list]";
		}
		return implode("\n", $entrTxt);
	}

	/**
	 * Writes blocks, each as its text.
	 *
	 * @param Array $blocksList The blocks.
	 * @return Array The text of each block that gives some, to be separated by an empty line.
	 *
	 * @throws WriteError When a block is of an unknown kind.
	 */
	protected function blocksOut(Array $blocksList): Array {
		$textLst = Array();
		$boldSet = self::MARKLIST[$this->givesKnd]['bold'] ?? Array('', '');
		foreach($blocksList as $oneBlk){
			$blckKnd = strval($oneBlk['block'] ?? '');
			$blckTxt = '';
			if( $blckKnd=='headr' ){
				// A heading has no mark of its own: it is bold where bold can be written
				$headTxt = trim($this->inlineOut((array)$oneBlk['spans']));
				$blckTxt = ($headTxt==='' || in_array('bold', array_merge(...array_column((array)$oneBlk['spans'], 'marks'), ...Array(Array())))) ? $headTxt : $boldSet[0].$headTxt.$boldSet[1];
			}elseif( $blckKnd=='parag' ){
				$blckTxt = trim($this->inlineOut((array)$oneBlk['spans']));
			}elseif( $blckKnd=='lists' ){
				$blckTxt = $this->listedOut($oneBlk);
			}elseif( $blckKnd=='table' || $blckKnd=='thead' || $blckKnd=='tbrow' ){
				// The rows one per line, tabulations between their cells
				$rowsTxt = Array();
				foreach(($blckKnd=='table') ? (array)$oneBlk['items'] : Array($oneBlk) as $rowBlk){
					$cellTxt = Array();
					foreach((array)$rowBlk['items'] as $celBlk){
						$cellTxt[] = preg_replace('/\s+/', ' ', $this->insideOut($celBlk));
					}
					$rowsTxt[] = implode("\t", $cellTxt);
				}
				$blckTxt = implode("\n", $rowsTxt);
			}elseif( $blckKnd=='quote' ){
				$qteText = $this->insideOut($oneBlk);
				$citeTxt = str_replace(Array('[', ']', "\n"), Array('(', ')', ' '), strval($oneBlk['attrs']['cites'] ?? ''));
				if( $qteText!=='' && $this->givesKnd=='bbcod' ){
					$blckTxt = '[quote'.(($citeTxt!=='') ? '='.$citeTxt : '').']'.$qteText.'[/quote]';
				}elseif( $qteText!=='' ){
					$blckTxt = preg_replace(Array('/^(?=.)/m', '/^$/m'), Array('> ', '>'), $qteText.(($citeTxt!=='') ? "\n— ".$citeTxt : ''));
				}
			}elseif( $blckKnd=='codes' ){
				$codeLng = strval($oneBlk['attrs']['langg'] ?? '');
				$blckTxt = ($this->givesKnd=='bbcod') ? '[code'.(preg_match('/^[\w+#.\-]+$/D', $codeLng) ? '='.$codeLng : '')."]\n".strval($oneBlk['plain'] ?? '')."\n[/code]" : strval($oneBlk['plain'] ?? '');
			}elseif( $blckKnd=='image' ){
				// An image is a tag where there is one, and its description otherwise
				$imgSrce = Shape::cleanLink(strval($oneBlk['attrs']['sourc'] ?? ''));
				$blckTxt = ($this->givesKnd=='bbcod' && $imgSrce!=='') ? '[img]'.$imgSrce.'[/img]' : trim(strval($oneBlk['plain'] ?? ''));
			}elseif( $blckKnd=='rules' ){
				$blckTxt = '----------';
			}elseif( $blckKnd=='fnote' ){
				$blckTxt = '['.intval($oneBlk['attrs']['numbr'] ?? 0).'] '.$this->insideOut($oneBlk);
			}elseif( $blckKnd=='entry' || $blckKnd=='tcell' ){
				// An entry or a cell met outside its list or its table: what it holds
				$blckTxt = $this->insideOut($oneBlk);
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
