<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * Text, as it is or carrying light formatting:
 *
 *   'plain' => the text as written, a blank line between two paragraphs;
 *   'bbcod' => the tags of forums and web editors: [b] [i] [u] [s],
 *              [url] and [url=...], [img], [code], [quote], [list] and [*];
 *   'chats' => the marks of messaging: *bold* _italic_ __underlined__
 *              ~strike~, and lists after '- ', '* ', '1. ' or '1) '.
 *
 * In BBCode, a tag that does nothing is left out and what it surrounds is
 * kept: one opened and never closed, one closed and never opened, one
 * that is not known. In messaging, a mark that is not closed is text.
 */
class Text extends Base {

	// Formatting given by the BBCode tags
	const BBCD_MARK = Array('b'=>'boldFace', 'i'=>'emphasis', 'u'=>'undrLine', 's'=>'crossOut');
	// Formatting given by the marks of messaging
	const CHAT_MARK = Array('*'=>'boldFace', '_'=>'emphasis', '__'=>'undrLine', '~'=>'crossOut');

	/**
	 * Parses a text into blocks. An empty content gives no block.
	 *
	 * Options:
	 *   'reads' => 'plain', 'bbcod' or 'chats': what the text carries;
	 *              'plain' when left out.
	 *
	 * @param String $nakedContent The text.
	 * @param Array  $parseOptions Options: 'reads'.
	 * @return Array The envelope, of shape 'block'; 'infos' holds what the text was read as under 'reads'.
	 *
	 * @throws ParseError When the 'reads' option is not a known one.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$readCode = strtolower(strval($parseOptions['reads'] ?? 'plain'));
		if( !in_array($readCode, Array('plain', 'bbcod', 'chats')) ){
			throw new ParseError('Invalid text option: unknown reading '.$readCode);
		}
		$fullText = str_replace(Array("\r\n", "\r"), "\n", self::castTextToUtf8($nakedContent));
		if( $readCode=='bbcod' ){
			$blockLst = $this->parseBbcodeTxt($fullText);
		}elseif( $readCode=='chats' ){
			$blockLst = $this->parseChatsText($fullText);
		}else{
			$blockLst = Array();
			foreach($this->splitParagraph($fullText) as $oneTxt){
				$blockLst[] = Shape::makeBlockArray('paragrph', Array(Shape::makeSpanOfText($oneTxt)));
			}
		}
		return $this->makeShapeArray('block', $blockLst, Array('reads'=>$readCode));
	}

	/**
	 * Cuts a text into its paragraphs: a blank line stands between two of
	 * them. The lines of a paragraph lose the spaces at their end, and
	 * the paragraph those at its start.
	 *
	 * @param String $templateText The text, its lines ending with \n.
	 * @return Array The paragraphs, none of them empty.
	 */
	protected function splitParagraph(String $templateText): Array {
		$prgrList = Array();
		foreach(preg_split('/\n[ \t]*(?:\n[ \t]*)+/', preg_replace('/[ \t]+$/m', '', $templateText)) as $oneTxt){
			$oneTxt = trim($oneTxt);
			if( trim($oneTxt)!=='' ){
				$prgrList[] = $oneTxt;
			}
		}
		return $prgrList;
	}

	/**
	 * Finds where a BBCode tag is closed.
	 *
	 * @param String $templateText The text.
	 * @param String $tagNmeString Name of the tag, in lower case.
	 * @param Int    $fromPosition Position following the opening tag.
	 * @param Bool   $allowsNested Whether the tag may hold others of its name, each closed in turn.
	 * @return ?Array The position of the closing tag and its length, null when the tag is never closed.
	 */
	protected function findCloseArray(String $templateText, String $tagNmeString, Int $fromPosition, Bool $allowsNested): ?Array {
		$tagParte = Array();
		$openNumb = 1;
		$readIndx = $fromPosition;
		while( preg_match('/\[(\/?)'.preg_quote($tagNmeString, '/').'(?:=[^\]\n]*)?\]/i', $templateText, $tagParte, PREG_OFFSET_CAPTURE, $readIndx) ){
			$readIndx = $tagParte[0][1]+strlen($tagParte[0][0]);
			if( $tagParte[1][0]==='' ){
				$openNumb += $allowsNested ? 1 : 0;
				continue;
			}
			$openNumb--;
			if( $openNumb==0 ){
				return Array($tagParte[0][1], strlen($tagParte[0][0]));
			}
		}
		return null;
	}

	/**
	 * Reads a BBCode text into blocks.
	 *
	 * @param String $templateText The text.
	 * @return Array The blocks.
	 */
	protected function parseBbcodeTxt(String $templateText): Array {
		$blockLst = Array();
		$textBuff = '';
		$readIndx = 0;
		$tagParte = Array();
		while( preg_match('/\[(code|quote|list|img)(?:=([^\]\n]*))?\]/i', $templateText, $tagParte, PREG_OFFSET_CAPTURE, $readIndx) ){
			$tagTitle = strtolower($tagParte[1][0]);
			$tagArgmn = trim(strval($tagParte[2][0] ?? ''), " \t\"'");
			$innerPos = $tagParte[0][1]+strlen($tagParte[0][0]);
			$closeIdx = $this->findCloseArray($templateText, $tagTitle, $innerPos, in_array($tagTitle, Array('quote', 'list')));
			if( $closeIdx===null ){
				// Never closed: the tag is left out, what follows it is kept
				$textBuff .= substr($templateText, $readIndx, $tagParte[0][1]-$readIndx);
				$readIndx = $innerPos;
				continue;
			}
			$blockLst = array_merge($blockLst, $this->readBbcodePrgr($textBuff.substr($templateText, $readIndx, $tagParte[0][1]-$readIndx)));
			$textBuff = '';
			$bodyText = substr($templateText, $innerPos, $closeIdx[0]-$innerPos);
			$readIndx = $closeIdx[0]+$closeIdx[1];
			if( $tagTitle=='code' ){
				$codeText = trim($bodyText, "\n");
				if( trim($codeText)!=='' ){
					$blockLst[] = Shape::makeBlockArray('codeBlck', Array(Shape::makeSpanOfText($codeText)), Array(), preg_match('/^[\w+#.\-]+$/D', $tagArgmn) ? Array('langg'=>$tagArgmn) : Array());
				}
			}elseif( $tagTitle=='img' ){
				$imageLnk = Shape::wipeUnsafeAddr($bodyText);
				if( $imageLnk!=='' ){
					$blockLst[] = Shape::makeBlockArray('imageBox', Array(), Array(), Array('sourc'=>$imageLnk));
				}
			}elseif( $tagTitle=='quote' ){
				$heldList = $this->parseBbcodeTxt($bodyText);
				if( !empty($heldList) ){
					$blockLst[] = $this->makeOuterBlock('citation', $heldList, ($tagArgmn!=='') ? Array('cites'=>$tagArgmn) : Array());
				}
			}else{
				$itemList = Array();
				foreach($this->splitBbcodeLst($bodyText) as $oneTxt){
					$itemList[] = $this->makeOuterBlock('listItem', $this->parseBbcodeTxt($oneTxt));
				}
				if( !empty($itemList) ){
					$blockLst[] = Shape::makeBlockArray('itemList', Array(), $itemList, Array('order'=>($tagArgmn!=='')));
				}
			}
		}
		return array_merge($blockLst, $this->readBbcodePrgr($textBuff.substr($templateText, $readIndx)));
	}

	/**
	 * Cuts what a BBCode list holds into its entries, at the [*] tags that
	 * are its own: those of a list inside it are left to that list.
	 *
	 * @param String $templateText What stands between the tags of the list.
	 * @return Array The text of each entry, none of them empty.
	 */
	protected function splitBbcodeLst(String $templateText): Array {
		$itemList = Array();
		$tagParte = Array();
		$deepNumb = 0;
		$startIdx = 0;
		$readIndx = 0;
		while( preg_match('/\[(list(?:=[^\]\n]*)?|\/list|\*)\]/i', $templateText, $tagParte, PREG_OFFSET_CAPTURE, $readIndx) ){
			$readIndx = $tagParte[0][1]+strlen($tagParte[0][0]);
			if( $tagParte[1][0]!=='*' ){
				$deepNumb += ($tagParte[1][0][0]=='/') ? -1 : 1;
			}elseif( $deepNumb<=0 ){
				$itemList[] = substr($templateText, $startIdx, $tagParte[0][1]-$startIdx);
				$startIdx = $readIndx;
			}
		}
		$itemList[] = substr($templateText, $startIdx);
		return array_values(array_filter(array_map('trim', $itemList), function($listEntryTxt){ return $listEntryTxt!==''; }));
	}

	/**
	 * Reads a BBCode text holding no block into its paragraphs.
	 *
	 * @param String $templateText The text.
	 * @return Array The paragraphs, as blocks.
	 */
	protected function readBbcodePrgr(String $templateText): Array {
		$blockLst = Array();
		foreach($this->splitParagraph($templateText) as $oneTxt){
			$spansLst = Shape::joinSpansArray($this->readBbcodeSpns($oneTxt, Array(), ''));
			if( !empty($spansLst) ){
				$blockLst[] = Shape::makeBlockArray('paragrph', $spansLst);
			}
		}
		return $blockLst;
	}

	/**
	 * Reads the BBCode tags of a paragraph into spans.
	 *
	 * @param String $templateText The text.
	 * @param Array  $runFormatSet Formatting given by the tags around it.
	 * @param String $linkGoalText Address given by a link around it, '' when there is none.
	 * @return Array The spans.
	 */
	protected function readBbcodeSpns(String $templateText, Array $runFormatSet, String $linkGoalText): Array {
		$spansLst = Array();
		$textBuff = '';
		$readIndx = 0;
		$tagParte = Array();
		while( preg_match('/\[(b|i|u|s|url)(?:=([^\]\n]*))?\]/i', $templateText, $tagParte, PREG_OFFSET_CAPTURE, $readIndx) ){
			$tagTitle = strtolower($tagParte[1][0]);
			$innerPos = $tagParte[0][1]+strlen($tagParte[0][0]);
			$closeIdx = $this->findCloseArray($templateText, $tagTitle, $innerPos, true);
			if( $closeIdx===null ){
				// Never closed: the tag is left out, what follows it is kept
				$textBuff .= substr($templateText, $readIndx, $tagParte[0][1]-$readIndx);
				$readIndx = $innerPos;
				continue;
			}
			$spansLst[] = Shape::makeSpanOfText($this->dropUnusedTags($textBuff.substr($templateText, $readIndx, $tagParte[0][1]-$readIndx)), $runFormatSet, $linkGoalText);
			$textBuff = '';
			$bodyText = substr($templateText, $innerPos, $closeIdx[0]-$innerPos);
			$readIndx = $closeIdx[0]+$closeIdx[1];
			if( $tagTitle=='url' ){
				// The address is what follows the '=', or else the text of the link itself
				$tagArgmn = trim(strval($tagParte[2][0] ?? ''), " \t\"'");
				$linkAddr = Shape::wipeUnsafeAddr(($tagArgmn!=='') ? $tagArgmn : $bodyText);
				$spansLst = array_merge($spansLst, $this->readBbcodeSpns($bodyText, $runFormatSet, ($linkAddr!=='') ? $linkAddr : $linkGoalText));
			}else{
				$spansLst = array_merge($spansLst, $this->readBbcodeSpns($bodyText, array_merge($runFormatSet, Array(self::BBCD_MARK[$tagTitle])), $linkGoalText));
			}
		}
		$spansLst[] = Shape::makeSpanOfText($this->dropUnusedTags($textBuff.substr($templateText, $readIndx)), $runFormatSet, $linkGoalText);
		return $spansLst;
	}

	/**
	 * Takes out of a text the BBCode tags left in it once those that do
	 * something were read: closed and never opened, or not known. A tag
	 * is a name made of letters and digits between square brackets, with
	 * or without a value after '='; [1] or [ ] are not tags.
	 *
	 * @param String $templateText The text.
	 * @return String The text without those tags.
	 */
	protected function dropUnusedTags(String $templateText): String {
		return preg_replace('/\[\/?[a-z][a-z0-9]*(?:=[^\]\n]*)?\]|\[\*\]/i', '', $templateText);
	}

	/**
	 * Reads a text written with the marks of messaging into blocks.
	 *
	 * @param String $templateText The text, its lines ending with \n.
	 * @return Array The blocks.
	 */
	protected function parseChatsText(String $templateText): Array {
		$blockLst = Array();
		// What is being gathered: its kind ('paragrph' or 'itemList'), its lines, and for a list whether it is numbered
		$openCode = '';
		$openLnes = Array();
		$openOrdr = false;
		$lineList = explode("\n", $templateText);
		$lineList[] = '';
		$lineNumb = count($lineList);
		for($nthLne=0; $nthLne<$lineNumb; $nthLne++){
			$lineText = rtrim($lineList[$nthLne]);
			$linePart = Array();
			$lineCode = 'paragrph';
			$lineOrdr = false;
			if( $lineText==='' ){
				$lineCode = '';
			}elseif( preg_match('/^[\-*] +(\S.*)$/', $lineText, $linePart) ){
				$lineCode = 'itemList';
				$lineText = $linePart[1];
			}elseif( preg_match('/^\d{1,9}[.)] +(\S.*)$/', $lineText, $linePart) ){
				$lineCode = 'itemList';
				$lineOrdr = true;
				$lineText = $linePart[1];
			}
			// What was being gathered ends when the kind of line changes
			if( $openCode!=='' && ($lineCode!==$openCode || ($lineCode=='itemList' && $lineOrdr!==$openOrdr)) ){
				if( $openCode=='itemList' ){
					$itemList = Array();
					foreach($openLnes as $oneTxt){
						$itemList[] = Shape::makeBlockArray('listItem', Shape::joinSpansArray($this->readChatsSpans($oneTxt, Array())));
					}
					$blockLst[] = Shape::makeBlockArray('itemList', Array(), $itemList, Array('order'=>$openOrdr));
				}else{
					$spansLst = Shape::joinSpansArray($this->readChatsSpans(trim(implode("\n", $openLnes), "\n"), Array()));
					if( !empty($spansLst) ){
						$blockLst[] = Shape::makeBlockArray('paragrph', $spansLst);
					}
				}
				$openCode = '';
				$openLnes = Array();
			}
			if( $lineCode!=='' ){
				$openCode = $lineCode;
				$openOrdr = $lineOrdr;
				$openLnes[] = $lineText;
			}
		}
		return $blockLst;
	}

	/**
	 * Reads the marks of messaging of a text into spans. A mark counts
	 * when it opens against a word and closes against one, as *this* or
	 * *these words* and not as 2 * 3, over several lines of a paragraph
	 * when it has to. An address starting
	 * with http:// or https:// is given as written: the marks inside it
	 * are part of it.
	 *
	 * @param String $templateText The text.
	 * @param Array  $runFormatSet Formatting given by the marks around it.
	 * @return Array The spans.
	 */
	protected function readChatsSpans(String $templateText, Array $runFormatSet): Array {
		// An underscore standing against another is never a mark of its own: __this__ is one mark, not two
		$markRgxp = '/(?<url>https?:\/\/[^\s<>"]+)'
			.'|(?<![\p{L}\p{N}_])(?<und>__|_)(?=[^\s_])(?<utx>.+?)(?<=[^\s_])\k<und>(?![\p{L}\p{N}_])'
			.'|(?<![\p{L}\p{N}])(?<mrk>[*~])(?=\S)(?<txt>.+?)(?<=\S)\k<mrk>(?![\p{L}\p{N}])/us';
		$spansLst = Array();
		$readIndx = 0;
		$matxRows = Array();
		while( preg_match($markRgxp, $templateText, $matxRows, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $readIndx) ){
			$spansLst[] = Shape::makeSpanOfText(substr($templateText, $readIndx, $matxRows[0][1]-$readIndx), $runFormatSet);
			if( $matxRows['url'][0]!==null ){
				$spansLst[] = Shape::makeSpanOfText($matxRows[0][0], $runFormatSet);
			}else{
				$spansLst = array_merge($spansLst, $this->readChatsSpans(strval($matxRows['utx'][0] ?? $matxRows['txt'][0]), array_merge($runFormatSet, Array(self::CHAT_MARK[$matxRows['und'][0] ?? $matxRows['mrk'][0]]))));
			}
			$readIndx = $matxRows[0][1]+strlen($matxRows[0][0]);
		}
		$spansLst[] = Shape::makeSpanOfText(substr($templateText, $readIndx), $runFormatSet);
		return $spansLst;
	}

}
