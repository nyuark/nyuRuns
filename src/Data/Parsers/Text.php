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
	const BBCD_MARK = Array('b'=>'bold', 'i'=>'ital', 'u'=>'undr', 's'=>'strk');
	// Formatting given by the marks of messaging
	const CHAT_MARK = Array('*'=>'bold', '_'=>'ital', '__'=>'undr', '~'=>'strk');

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
		$readsAs = strtolower(strval($parseOptions['reads'] ?? 'plain'));
		if( !in_array($readsAs, Array('plain', 'bbcod', 'chats')) ){
			throw new ParseError('Invalid text option: unknown reading '.$readsAs);
		}
		$fullTxt = str_replace(Array("\r\n", "\r"), "\n", self::recodeTextUtf8($nakedContent));
		if( $readsAs=='bbcod' ){
			$blckLst = $this->bbcodeToBlocks($fullTxt);
		}elseif( $readsAs=='chats' ){
			$blckLst = $this->chatsBlocksArr($fullTxt);
		}else{
			$blckLst = Array();
			foreach($this->splitParagraph($fullTxt) as $parTxt){
				$blckLst[] = Shape::makeBlockArray('parag', Array(Shape::makeASpanArray($parTxt)));
			}
		}
		return $this->makeShapeArray('block', $blckLst, Array('reads'=>$readsAs));
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
		$paraLst = Array();
		foreach(preg_split('/\n[ \t]*(?:\n[ \t]*)+/', preg_replace('/[ \t]+$/m', '', $templateText)) as $parTxt){
			$parTxt = trim($parTxt);
			if( trim($parTxt)!=='' ){
				$paraLst[] = $parTxt;
			}
		}
		return $paraLst;
	}

	/**
	 * Finds where a BBCode tag is closed.
	 *
	 * @param String $templateText The text.
	 * @param String $tagNmeString Name of the tag, in lower case.
	 * @param Int    $startsFromNb Position following the opening tag.
	 * @param Bool   $allowsNested Whether the tag may hold others of its name, each closed in turn.
	 * @return ?Array The position of the closing tag and its length, null when the tag is never closed.
	 */
	protected function findCloseArray(String $templateText, String $tagNmeString, Int $startsFromNb, Bool $allowsNested): ?Array {
		$tagPrts = Array();
		$openNum = 1;
		$readPos = $startsFromNb;
		while( preg_match('/\[(\/?)'.preg_quote($tagNmeString, '/').'(?:=[^\]\n]*)?\]/i', $templateText, $tagPrts, PREG_OFFSET_CAPTURE, $readPos) ){
			$readPos = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			if( $tagPrts[1][0]==='' ){
				$openNum += $allowsNested ? 1 : 0;
				continue;
			}
			$openNum--;
			if( $openNum==0 ){
				return Array($tagPrts[0][1], strlen($tagPrts[0][0]));
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
	protected function bbcodeToBlocks(String $templateText): Array {
		$blckLst = Array();
		$txtBffr = '';
		$readPos = 0;
		$tagPrts = Array();
		while( preg_match('/\[(code|quote|list|img)(?:=([^\]\n]*))?\]/i', $templateText, $tagPrts, PREG_OFFSET_CAPTURE, $readPos) ){
			$tagName = strtolower($tagPrts[1][0]);
			$tagArgs = trim(strval($tagPrts[2][0] ?? ''), " \t\"'");
			$bdyPstn = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			$closeAt = $this->findCloseArray($templateText, $tagName, $bdyPstn, in_array($tagName, Array('quote', 'list')));
			if( $closeAt===null ){
				// Never closed: the tag is left out, what follows it is kept
				$txtBffr .= substr($templateText, $readPos, $tagPrts[0][1]-$readPos);
				$readPos = $bdyPstn;
				continue;
			}
			$blckLst = array_merge($blckLst, $this->bbcodeToPrgrph($txtBffr.substr($templateText, $readPos, $tagPrts[0][1]-$readPos)));
			$txtBffr = '';
			$bodyTxt = substr($templateText, $bdyPstn, $closeAt[0]-$bdyPstn);
			$readPos = $closeAt[0]+$closeAt[1];
			if( $tagName=='code' ){
				$codeTxt = trim($bodyTxt, "\n");
				if( trim($codeTxt)!=='' ){
					$blckLst[] = Shape::makeBlockArray('codes', Array(Shape::makeASpanArray($codeTxt)), Array(), preg_match('/^[\w+#.\-]+$/D', $tagArgs) ? Array('langg'=>$tagArgs) : Array());
				}
			}elseif( $tagName=='img' ){
				$imgLink = Shape::tidyLinkString($bodyTxt);
				if( $imgLink!=='' ){
					$blckLst[] = Shape::makeBlockArray('image', Array(), Array(), Array('sourc'=>$imgLink));
				}
			}elseif( $tagName=='quote' ){
				$heldLst = $this->bbcodeToBlocks($bodyTxt);
				if( !empty($heldLst) ){
					$blckLst[] = $this->holdBlockArray('quote', $heldLst, ($tagArgs!=='') ? Array('cites'=>$tagArgs) : Array());
				}
			}else{
				$itemLst = Array();
				foreach($this->bbcodeEntryArr($bodyTxt) as $entTxt){
					$itemLst[] = $this->holdBlockArray('entry', $this->bbcodeToBlocks($entTxt));
				}
				if( !empty($itemLst) ){
					$blckLst[] = Shape::makeBlockArray('lists', Array(), $itemLst, Array('order'=>($tagArgs!=='')));
				}
			}
		}
		return array_merge($blckLst, $this->bbcodeToPrgrph($txtBffr.substr($templateText, $readPos)));
	}

	/**
	 * Cuts what a BBCode list holds into its entries, at the [*] tags that
	 * are its own: those of a list inside it are left to that list.
	 *
	 * @param String $templateText What stands between the tags of the list.
	 * @return Array The text of each entry, none of them empty.
	 */
	protected function bbcodeEntryArr(String $templateText): Array {
		$itemLst = Array();
		$tagPrts = Array();
		$deepNum = 0;
		$startAt = 0;
		$readPos = 0;
		while( preg_match('/\[(list(?:=[^\]\n]*)?|\/list|\*)\]/i', $templateText, $tagPrts, PREG_OFFSET_CAPTURE, $readPos) ){
			$readPos = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			if( $tagPrts[1][0]!=='*' ){
				$deepNum += ($tagPrts[1][0][0]=='/') ? -1 : 1;
			}elseif( $deepNum<=0 ){
				$itemLst[] = substr($templateText, $startAt, $tagPrts[0][1]-$startAt);
				$startAt = $readPos;
			}
		}
		$itemLst[] = substr($templateText, $startAt);
		return array_values(array_filter(array_map('trim', $itemLst), function($listEntryTxt){ return $listEntryTxt!==''; }));
	}

	/**
	 * Reads a BBCode text holding no block into its paragraphs.
	 *
	 * @param String $templateText The text.
	 * @return Array The paragraphs, as blocks.
	 */
	protected function bbcodeToPrgrph(String $templateText): Array {
		$blckLst = Array();
		foreach($this->splitParagraph($templateText) as $parTxt){
			$spnsLst = Shape::joinSpansArray($this->bbcodeSpansArr($parTxt, Array(), ''));
			if( !empty($spnsLst) ){
				$blckLst[] = Shape::makeBlockArray('parag', $spnsLst);
			}
		}
		return $blckLst;
	}

	/**
	 * Reads the BBCode tags of a paragraph into spans.
	 *
	 * @param String $templateText The text.
	 * @param Array  $runFormatSet Formatting given by the tags around it.
	 * @param String $linkGoalText Address given by a link around it, '' when there is none.
	 * @return Array The spans.
	 */
	protected function bbcodeSpansArr(String $templateText, Array $runFormatSet, String $linkGoalText): Array {
		$spnsLst = Array();
		$txtBffr = '';
		$readPos = 0;
		$tagPrts = Array();
		while( preg_match('/\[(b|i|u|s|url)(?:=([^\]\n]*))?\]/i', $templateText, $tagPrts, PREG_OFFSET_CAPTURE, $readPos) ){
			$tagName = strtolower($tagPrts[1][0]);
			$bdyPstn = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			$closeAt = $this->findCloseArray($templateText, $tagName, $bdyPstn, true);
			if( $closeAt===null ){
				// Never closed: the tag is left out, what follows it is kept
				$txtBffr .= substr($templateText, $readPos, $tagPrts[0][1]-$readPos);
				$readPos = $bdyPstn;
				continue;
			}
			$spnsLst[] = Shape::makeASpanArray($this->dropUnusedTags($txtBffr.substr($templateText, $readPos, $tagPrts[0][1]-$readPos)), $runFormatSet, $linkGoalText);
			$txtBffr = '';
			$bodyTxt = substr($templateText, $bdyPstn, $closeAt[0]-$bdyPstn);
			$readPos = $closeAt[0]+$closeAt[1];
			if( $tagName=='url' ){
				// The address is what follows the '=', or else the text of the link itself
				$tagArgs = trim(strval($tagPrts[2][0] ?? ''), " \t\"'");
				$linkAdr = Shape::tidyLinkString(($tagArgs!=='') ? $tagArgs : $bodyTxt);
				$spnsLst = array_merge($spnsLst, $this->bbcodeSpansArr($bodyTxt, $runFormatSet, ($linkAdr!=='') ? $linkAdr : $linkGoalText));
			}else{
				$spnsLst = array_merge($spnsLst, $this->bbcodeSpansArr($bodyTxt, array_merge($runFormatSet, Array(self::BBCD_MARK[$tagName])), $linkGoalText));
			}
		}
		$spnsLst[] = Shape::makeASpanArray($this->dropUnusedTags($txtBffr.substr($templateText, $readPos)), $runFormatSet, $linkGoalText);
		return $spnsLst;
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
	protected function chatsBlocksArr(String $templateText): Array {
		$blckLst = Array();
		// What is being gathered: its kind ('parag' or 'lists'), its lines, and for a list whether it is numbered
		$opnKind = '';
		$openLns = Array();
		$opnOrdr = false;
		$lineLst = explode("\n", $templateText);
		$lineLst[] = '';
		$lineNum = count($lineLst);
		for($linIdx=0; $linIdx<$lineNum; $linIdx++){
			$lineTxt = rtrim($lineLst[$linIdx]);
			$linPart = Array();
			$linKind = 'parag';
			$linOrdr = false;
			if( $lineTxt==='' ){
				$linKind = '';
			}elseif( preg_match('/^[\-*] +(\S.*)$/', $lineTxt, $linPart) ){
				$linKind = 'lists';
				$lineTxt = $linPart[1];
			}elseif( preg_match('/^\d{1,9}[.)] +(\S.*)$/', $lineTxt, $linPart) ){
				$linKind = 'lists';
				$linOrdr = true;
				$lineTxt = $linPart[1];
			}
			// What was being gathered ends when the kind of line changes
			if( $opnKind!=='' && ($linKind!==$opnKind || ($linKind=='lists' && $linOrdr!==$opnOrdr)) ){
				if( $opnKind=='lists' ){
					$itemLst = Array();
					foreach($openLns as $entTxt){
						$itemLst[] = Shape::makeBlockArray('entry', Shape::joinSpansArray($this->chatSpansArray($entTxt, Array())));
					}
					$blckLst[] = Shape::makeBlockArray('lists', Array(), $itemLst, Array('order'=>$opnOrdr));
				}else{
					$spnsLst = Shape::joinSpansArray($this->chatSpansArray(trim(implode("\n", $openLns), "\n"), Array()));
					if( !empty($spnsLst) ){
						$blckLst[] = Shape::makeBlockArray('parag', $spnsLst);
					}
				}
				$opnKind = '';
				$openLns = Array();
			}
			if( $linKind!=='' ){
				$opnKind = $linKind;
				$opnOrdr = $linOrdr;
				$openLns[] = $lineTxt;
			}
		}
		return $blckLst;
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
	protected function chatSpansArray(String $templateText, Array $runFormatSet): Array {
		// An underscore standing against another is never a mark of its own: __this__ is one mark, not two
		$markRgx = '/(?<url>https?:\/\/[^\s<>"]+)'
			.'|(?<![\p{L}\p{N}_])(?<und>__|_)(?=[^\s_])(?<utx>.+?)(?<=[^\s_])\k<und>(?![\p{L}\p{N}_])'
			.'|(?<![\p{L}\p{N}])(?<mrk>[*~])(?=\S)(?<txt>.+?)(?<=\S)\k<mrk>(?![\p{L}\p{N}])/us';
		$spnsLst = Array();
		$readPos = 0;
		$matches = Array();
		while( preg_match($markRgx, $templateText, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $readPos) ){
			$spnsLst[] = Shape::makeASpanArray(substr($templateText, $readPos, $matches[0][1]-$readPos), $runFormatSet);
			if( $matches['url'][0]!==null ){
				$spnsLst[] = Shape::makeASpanArray($matches[0][0], $runFormatSet);
			}else{
				$spnsLst = array_merge($spnsLst, $this->chatSpansArray(strval($matches['utx'][0] ?? $matches['txt'][0]), array_merge($runFormatSet, Array(self::CHAT_MARK[$matches['und'][0] ?? $matches['mrk'][0]]))));
			}
			$readPos = $matches[0][1]+strlen($matches[0][0]);
		}
		$spnsLst[] = Shape::makeASpanArray(substr($templateText, $readPos), $runFormatSet);
		return $spnsLst;
	}

}
