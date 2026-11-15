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
		'bbcod'=>Array('boldFace'=>Array('[b]', '[/b]'), 'emphasis'=>Array('[i]', '[/i]'), 'undrLine'=>Array('[u]', '[/u]'), 'crossOut'=>Array('[s]', '[/s]')),
		'chats'=>Array('boldFace'=>Array('*', '*'), 'emphasis'=>Array('_', '_'), 'undrLine'=>Array('__', '__'), 'crossOut'=>Array('~', '~'))
	);

	// The way of writing asked for
	protected $outputType = 'plain';

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
		$this->outputType = strtolower(strval($writeOptions['gives'] ?? 'plain'));
		if( !isset(self::MARK_LIST[$this->outputType]) ){
			throw new WriteError('Invalid text option: unknown writing '.$this->outputType);
		}
		$bodyText = implode("\n\n", $this->writeAllBlocks((array)($dataEnvelope['items'] ?? Array())));
		return ($bodyText==='') ? '' : $bodyText."\n";
	}

	/**
	 * Writes spans: each text with the marks of its formatting around
	 * it, the spaces at its ends left outside them, and its link.
	 *
	 * @param Array $textSpanList The spans.
	 * @return String The text.
	 */
	protected function writeInlineTxt(Array $textSpanList): String {
		$outStrng = '';
		foreach($textSpanList as $oneSpn){
			$edgePart = Array();
			preg_match('/^(\s*)(.*?)(\s*)$/Ds', strval($oneSpn['texts'] ?? ''), $edgePart);
			$coreText = $edgePart[2];
			$linkAddr = Shape::wipeUnsafeAddr(strval($oneSpn['links'] ?? ''));
			$matxRows = Array();
			if( $coreText!=='' && preg_match('/^#[fe]note(\d+)$/D', $linkAddr, $matxRows) ){
				// The reference to a note stands between square brackets
				$coreText = '['.$coreText.']';
				$linkAddr = '';
			}elseif( $coreText!=='' ){
				foreach(array_reverse(self::MARK_LIST[$this->outputType]) as $oneKey=>$oneSet){
					if( in_array($oneKey, (array)($oneSpn['marks'] ?? Array())) ){
						$coreText = $oneSet[0].$coreText.$oneSet[1];
					}
				}
			}
			if( $coreText!=='' && $linkAddr!=='' ){
				// A link: a tag where there is one, the address after the text otherwise, once when the text is the address
				$coreText = ($this->outputType=='bbcod') ? '[url='.str_replace(Array('[', ']'), Array('%5B', '%5D'), $linkAddr).']'.$coreText.'[/url]' : (($edgePart[2]===$linkAddr) ? $coreText : $coreText.' ('.$linkAddr.')');
			}
			$outStrng .= $edgePart[1].$coreText.$edgePart[3];
		}
		return $outStrng;
	}

	/**
	 * Writes what an entry, a quote or a note holds: its own text, then
	 * the blocks it contains, a list right under it and anything else
	 * after an empty line.
	 *
	 * @param Array $contentBlock The block.
	 * @return String The text.
	 */
	protected function writeInnerPart(Array $contentBlock): String {
		$outStrng = trim($this->writeInlineTxt((array)$contentBlock['spans']));
		foreach((array)$contentBlock['items'] as $oneBlk){
			foreach($this->writeAllBlocks(Array($oneBlk)) as $oneTxt){
				$outStrng .= (($outStrng==='') ? '' : ((($oneBlk['block'] ?? '')=='itemList') ? "\n" : "\n\n")).$oneTxt;
			}
		}
		return $outStrng;
	}

	/**
	 * Writes a list: its entries one per line after a mark or a number,
	 * what an entry holds further in; with the tags of forums, between
	 * the tags of the list.
	 *
	 * @param Array $listsBlcList The list.
	 * @return String The text.
	 */
	protected function writeListItems(Array $listsBlcList): String {
		$hasOrder = !empty($listsBlcList['attrs']['order']);
		$itemText = Array();
		foreach((array)$listsBlcList['items'] as $oneNtr){
			$insdText = $this->writeInnerPart($oneNtr);
			if( $this->outputType=='bbcod' ){
				$itemText[] = '[*]'.$insdText;
				continue;
			}
			$markText = $hasOrder ? (count($itemText)+1).'. ' : '- ';
			$itemText[] = rtrim($markText.ltrim(preg_replace('/^(?=.)/m', str_repeat(' ', strlen($markText)), $insdText)));
		}
		if( $this->outputType=='bbcod' ){
			return empty($itemText) ? '' : ($hasOrder ? '[list=1]' : '[list]')."\n".implode("\n", $itemText)."\n[/list]";
		}
		return implode("\n", $itemText);
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
		$textList = Array();
		$boldDict = self::MARK_LIST[$this->outputType]['boldFace'] ?? Array('', '');
		foreach($bookBlockSet as $oneBlk){
			$blockCod = strval($oneBlk['block'] ?? '');
			$blockTxt = '';
			if( $blockCod=='headline' ){
				// A heading has no mark of its own: it is bold where bold can be written
				$headText = trim($this->writeInlineTxt((array)$oneBlk['spans']));
				$blockTxt = ($headText==='' || in_array('boldFace', array_merge(...array_column((array)$oneBlk['spans'], 'marks'), ...Array(Array())))) ? $headText : $boldDict[0].$headText.$boldDict[1];
			}elseif( $blockCod=='paragrph' ){
				$blockTxt = trim($this->writeInlineTxt((array)$oneBlk['spans']));
			}elseif( $blockCod=='itemList' ){
				$blockTxt = $this->writeListItems($oneBlk);
			}elseif( $blockCod=='tableSet' || $blockCod=='tableTop' || $blockCod=='tableRow' ){
				// The rows one per line, tabulations between their cells
				$rowsText = Array();
				foreach(($blockCod=='tableSet') ? (array)$oneBlk['items'] : Array($oneBlk) as $oneRow){
					$cellText = Array();
					foreach((array)$oneRow['items'] as $oneCel){
						$cellText[] = preg_replace('/\s+/', ' ', $this->writeInnerPart($oneCel));
					}
					$rowsText[] = implode("\t", $cellText);
				}
				$blockTxt = implode("\n", $rowsText);
			}elseif( $blockCod=='citation' ){
				$quoteTxt = $this->writeInnerPart($oneBlk);
				$citeText = str_replace(Array('[', ']', "\n"), Array('(', ')', ' '), strval($oneBlk['attrs']['cites'] ?? ''));
				if( $quoteTxt!=='' && $this->outputType=='bbcod' ){
					$blockTxt = '[quote'.(($citeText!=='') ? '='.$citeText : '').']'.$quoteTxt.'[/quote]';
				}elseif( $quoteTxt!=='' ){
					$blockTxt = preg_replace(Array('/^(?=.)/m', '/^$/m'), Array('> ', '>'), $quoteTxt.(($citeText!=='') ? "\n— ".$citeText : ''));
				}
			}elseif( $blockCod=='codeBlck' ){
				$codeLang = strval($oneBlk['attrs']['langg'] ?? '');
				$blockTxt = ($this->outputType=='bbcod') ? '[code'.(preg_match('/^[\w+#.\-]+$/D', $codeLang) ? '='.$codeLang : '')."]\n".strval($oneBlk['plain'] ?? '')."\n[/code]" : strval($oneBlk['plain'] ?? '');
			}elseif( $blockCod=='imageBox' ){
				// An image is a tag where there is one, and its description otherwise
				$imageLnk = Shape::wipeUnsafeAddr(strval($oneBlk['attrs']['sourc'] ?? ''));
				$blockTxt = ($this->outputType=='bbcod' && $imageLnk!=='') ? '[img]'.$imageLnk.'[/img]' : trim(strval($oneBlk['plain'] ?? ''));
			}elseif( $blockCod=='ruleLine' ){
				$blockTxt = '----------';
			}elseif( $blockCod=='footnote' ){
				$blockTxt = '['.intval($oneBlk['attrs']['numbr'] ?? 0).'] '.$this->writeInnerPart($oneBlk);
			}elseif( $blockCod=='listItem' || $blockCod=='cellText' ){
				// An entry or a cell met outside its list or its table: what it holds
				$blockTxt = $this->writeInnerPart($oneBlk);
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
