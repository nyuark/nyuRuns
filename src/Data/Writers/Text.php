<?php

namespace nyu\Data\Writers;

use nyu\Data\Shape;

/**
 * Text: text blocks written as plain text, with the tags of forums, or
 * with the marks of messaging.
 *
 *   'plain' => the text alone: lists after '- ' and '1. ', the rows of a
 *              table one per line with tabulations between their cells;
 *   'bbcod' => [b] [i] [u] [s], [url=...], [img], [code], [quote], [list];
 *   'chats' => *bold* _italic_ __underlined__ ~strike~, and lists.
 *
 * What a way of writing has no mark for is written as its text.
 */
class Text extends Base {

	// The marks around a formatting, for each way of writing: what opens it, what closes it
	const MARK_LIST = Array(
		'plain'=>Array(),
		'bbcod'=>Array('bold'=>Array('[b]', '[/b]'), 'ital'=>Array('[i]', '[/i]'), 'undr'=>Array('[u]', '[/u]'), 'strk'=>Array('[s]', '[/s]')),
		'chats'=>Array('bold'=>Array('*', '*'), 'ital'=>Array('_', '_'), 'undr'=>Array('__', '__'), 'strk'=>Array('~', '~'))
	);

	// The way of writing asked for
	protected $outputKind = 'plain';

	/**
	 * Writes text blocks as text.
	 *
	 * Options:
	 *   'gives' => 'plain', 'bbcod' or 'chats': what the text carries;
	 *              'plain' when left out.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'gives'.
	 * @return String The text.
	 *
	 * @throws WriteError When the shape is not 'block', the 'gives' option is not a known one, or a block is of an unknown kind.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('block'), 'text');
		$this->outputKind = strtolower(strval($writeOptions['gives'] ?? 'plain'));
		if( !isset(self::MARK_LIST[$this->outputKind]) ){
			throw new WriteError('Invalid text option: unknown writing '.$this->outputKind);
		}
		$bodyTxt = implode("\n\n", $this->writeAllBlocks((array)($dataEnvelope['items'] ?? Array())));
		return ($bodyTxt==='') ? '' : $bodyTxt."\n";
	}

	/**
	 * Writes spans: each text with the marks of its formatting around
	 * it, the spaces at its ends left outside them, and its link.
	 *
	 * @param Array $textSpanList The spans.
	 * @return String The text.
	 */
	protected function writeInlineTxt(Array $textSpanList): String {
		$outText = '';
		foreach($textSpanList as $oneSpn){
			$edgPart = Array();
			preg_match('/^(\s*)(.*?)(\s*)$/Ds', strval($oneSpn['texts'] ?? ''), $edgPart);
			$coreTxt = $edgPart[2];
			$linkAdr = Shape::tidyLinkString(strval($oneSpn['links'] ?? ''));
			$matches = Array();
			if( $coreTxt!=='' && preg_match('/^#[fe]note(\d+)$/D', $linkAdr, $matches) ){
				// The reference to a note stands between square brackets
				$coreTxt = '['.$coreTxt.']';
				$linkAdr = '';
			}elseif( $coreTxt!=='' ){
				foreach(array_reverse(self::MARK_LIST[$this->outputKind]) as $mrkKey=>$mrkSet){
					if( in_array($mrkKey, (array)($oneSpn['marks'] ?? Array())) ){
						$coreTxt = $mrkSet[0].$coreTxt.$mrkSet[1];
					}
				}
			}
			if( $coreTxt!=='' && $linkAdr!=='' ){
				// A link: a tag where there is one, the address after the text otherwise, once when the text is the address
				$coreTxt = ($this->outputKind=='bbcod') ? '[url='.str_replace(Array('[', ']'), Array('%5B', '%5D'), $linkAdr).']'.$coreTxt.'[/url]' : (($edgPart[2]===$linkAdr) ? $coreTxt : $coreTxt.' ('.$linkAdr.')');
			}
			$outText .= $edgPart[1].$coreTxt.$edgPart[3];
		}
		return $outText;
	}

	/**
	 * Writes what an entry, a quote or a note holds: its own text, then
	 * the blocks it contains, a list right under it and anything else
	 * after an empty line.
	 *
	 * @param Array $contentBlock The block.
	 * @return String The text.
	 */
	protected function innerOutString(Array $contentBlock): String {
		$outText = trim($this->writeInlineTxt((array)$contentBlock['spans']));
		foreach((array)$contentBlock['items'] as $oneBlk){
			foreach($this->writeAllBlocks(Array($oneBlk)) as $blkTxt){
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
	 * @param Array $listsBlckArr The list.
	 * @return String The text.
	 */
	protected function writeListItems(Array $listsBlckArr): String {
		$isOrdrd = !empty($listsBlckArr['attrs']['order']);
		$itemTxt = Array();
		foreach((array)$listsBlckArr['items'] as $entBlk){
			$insdTxt = $this->innerOutString($entBlk);
			if( $this->outputKind=='bbcod' ){
				$itemTxt[] = '[*]'.$insdTxt;
				continue;
			}
			$markTxt = $isOrdrd ? (count($itemTxt)+1).'. ' : '- ';
			$itemTxt[] = rtrim($markTxt.ltrim(preg_replace('/^(?=.)/m', str_repeat(' ', strlen($markTxt)), $insdTxt)));
		}
		if( $this->outputKind=='bbcod' ){
			return empty($itemTxt) ? '' : ($isOrdrd ? '[list=1]' : '[list]')."\n".implode("\n", $itemTxt)."\n[/list]";
		}
		return implode("\n", $itemTxt);
	}

	/**
	 * Writes blocks, each as its text.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Array The text of each block that gives some, to be separated by an empty line.
	 *
	 * @throws WriteError When a block is of an unknown kind.
	 */
	protected function writeAllBlocks(Array $bookBlockSet): Array {
		$textLst = Array();
		$boldSet = self::MARK_LIST[$this->outputKind]['bold'] ?? Array('', '');
		foreach($bookBlockSet as $oneBlk){
			$blckKnd = strval($oneBlk['block'] ?? '');
			$blckTxt = '';
			if( $blckKnd=='headr' ){
				// A heading has no mark of its own: it is bold where bold can be written
				$headTxt = trim($this->writeInlineTxt((array)$oneBlk['spans']));
				$blckTxt = ($headTxt==='' || in_array('bold', array_merge(...array_column((array)$oneBlk['spans'], 'marks'), ...Array(Array())))) ? $headTxt : $boldSet[0].$headTxt.$boldSet[1];
			}elseif( $blckKnd=='parag' ){
				$blckTxt = trim($this->writeInlineTxt((array)$oneBlk['spans']));
			}elseif( $blckKnd=='lists' ){
				$blckTxt = $this->writeListItems($oneBlk);
			}elseif( $blckKnd=='table' || $blckKnd=='thead' || $blckKnd=='tbrow' ){
				// The rows one per line, tabulations between their cells
				$rowsTxt = Array();
				foreach(($blckKnd=='table') ? (array)$oneBlk['items'] : Array($oneBlk) as $oneRow){
					$cellTxt = Array();
					foreach((array)$oneRow['items'] as $celBlk){
						$cellTxt[] = preg_replace('/\s+/', ' ', $this->innerOutString($celBlk));
					}
					$rowsTxt[] = implode("\t", $cellTxt);
				}
				$blckTxt = implode("\n", $rowsTxt);
			}elseif( $blckKnd=='quote' ){
				$qutText = $this->innerOutString($oneBlk);
				$citeTxt = str_replace(Array('[', ']', "\n"), Array('(', ')', ' '), strval($oneBlk['attrs']['cites'] ?? ''));
				if( $qutText!=='' && $this->outputKind=='bbcod' ){
					$blckTxt = '[quote'.(($citeTxt!=='') ? '='.$citeTxt : '').']'.$qutText.'[/quote]';
				}elseif( $qutText!=='' ){
					$blckTxt = preg_replace(Array('/^(?=.)/m', '/^$/m'), Array('> ', '>'), $qutText.(($citeTxt!=='') ? "\n— ".$citeTxt : ''));
				}
			}elseif( $blckKnd=='codes' ){
				$codeLng = strval($oneBlk['attrs']['langg'] ?? '');
				$blckTxt = ($this->outputKind=='bbcod') ? '[code'.(preg_match('/^[\w+#.\-]+$/D', $codeLng) ? '='.$codeLng : '')."]\n".strval($oneBlk['plain'] ?? '')."\n[/code]" : strval($oneBlk['plain'] ?? '');
			}elseif( $blckKnd=='image' ){
				// An image is a tag where there is one, and its description otherwise
				$imgLink = Shape::tidyLinkString(strval($oneBlk['attrs']['sourc'] ?? ''));
				$blckTxt = ($this->outputKind=='bbcod' && $imgLink!=='') ? '[img]'.$imgLink.'[/img]' : trim(strval($oneBlk['plain'] ?? ''));
			}elseif( $blckKnd=='rules' ){
				$blckTxt = '----------';
			}elseif( $blckKnd=='fnote' ){
				$blckTxt = '['.intval($oneBlk['attrs']['numbr'] ?? 0).'] '.$this->innerOutString($oneBlk);
			}elseif( $blckKnd=='entry' || $blckKnd=='tcell' ){
				// An entry or a cell met outside its list or its table: what it holds
				$blckTxt = $this->innerOutString($oneBlk);
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
