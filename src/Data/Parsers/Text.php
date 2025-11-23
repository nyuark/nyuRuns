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
	const BBCDMARK = Array('b'=>'bold', 'i'=>'ital', 'u'=>'undr', 's'=>'strk');
	// Formatting given by the marks of messaging
	const CHATMARK = Array('*'=>'bold', '_'=>'ital', '__'=>'undr', '~'=>'strk');

	/**
	 * Parses a text into blocks. An empty content gives no block.
	 *
	 * Options:
	 *   'reads' => 'plain', 'bbcod' or 'chats': what the text carries;
	 *              'plain' when left out.
	 *
	 * @param String $rawContent The text.
	 * @param Array  $parseOptns Options: 'reads'.
	 * @return Array The envelope, of shape 'block'; 'infos' holds what the text was read as under 'reads'.
	 *
	 * @throws ParseError When the 'reads' option is not a known one.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		$readsAs = strtolower(strval($parseOptns['reads'] ?? 'plain'));
		if( !in_array($readsAs, Array('plain', 'bbcod', 'chats')) ){
			throw new ParseError('Invalid text option: unknown reading '.$readsAs);
		}
		$fullTxt = str_replace(Array("\r\n", "\r"), "\n", self::cleanText($rawContent));
		if( $readsAs=='bbcod' ){
			$blckLst = $this->bbcodBlks($fullTxt);
		}elseif( $readsAs=='chats' ){
			$blckLst = $this->chatsBlks($fullTxt);
		}else{
			$blckLst = Array();
			foreach($this->splitPara($fullTxt) as $parTxt){
				$blckLst[] = Shape::makeBlock('parag', Array(Shape::makeASpan($parTxt)));
			}
		}
		return $this->makeShape('block', $blckLst, Array('reads'=>$readsAs));
	}

	/**
	 * Cuts a text into its paragraphs: a blank line stands between two of
	 * them. The lines of a paragraph lose the spaces at their end, and
	 * the paragraph those at its start.
	 *
	 * @param String $textString The text, its lines ending with \n.
	 * @return Array The paragraphs, none of them empty.
	 */
	protected function splitPara(String $textString): Array {
		$paraLst = Array();
		foreach(preg_split('/\n[ \t]*(?:\n[ \t]*)+/', preg_replace('/[ \t]+$/m', '', $textString)) as $parTxt){
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
	 * @param String $textString The text.
	 * @param String $tagNameStr Name of the tag, in lower case.
	 * @param Int    $startsFrom Position following the opening tag.
	 * @param Bool   $tagsNested Whether the tag may hold others of its name, each closed in turn.
	 * @return ?Array The position of the closing tag and its length, null when the tag is never closed.
	 */
	protected function findClose(String $textString, String $tagNameStr, Int $startsFrom, Bool $tagsNested): ?Array {
		$tagPrts = Array();
		$openNbr = 1;
		$curPosn = $startsFrom;
		while( preg_match('/\[(\/?)'.preg_quote($tagNameStr, '/').'(?:=[^\]\n]*)?\]/i', $textString, $tagPrts, PREG_OFFSET_CAPTURE, $curPosn) ){
			$curPosn = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			if( $tagPrts[1][0]==='' ){
				$openNbr += $tagsNested ? 1 : 0;
				continue;
			}
			$openNbr--;
			if( $openNbr==0 ){
				return Array($tagPrts[0][1], strlen($tagPrts[0][0]));
			}
		}
		return null;
	}

	/**
	 * Reads a BBCode text into blocks.
	 *
	 * @param String $textString The text.
	 * @return Array The blocks.
	 */
	protected function bbcodBlks(String $textString): Array {
		$blckLst = Array();
		$textBuf = '';
		$curPosn = 0;
		$tagPrts = Array();
		while( preg_match('/\[(code|quote|list|img)(?:=([^\]\n]*))?\]/i', $textString, $tagPrts, PREG_OFFSET_CAPTURE, $curPosn) ){
			$tagName = strtolower($tagPrts[1][0]);
			$tagArgs = trim(strval($tagPrts[2][0] ?? ''), " \t\"'");
			$bodyPos = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			$closeAt = $this->findClose($textString, $tagName, $bodyPos, in_array($tagName, Array('quote', 'list')));
			if( $closeAt===null ){
				// Never closed: the tag is left out, what follows it is kept
				$textBuf .= substr($textString, $curPosn, $tagPrts[0][1]-$curPosn);
				$curPosn = $bodyPos;
				continue;
			}
			$blckLst = array_merge($blckLst, $this->bbcdParas($textBuf.substr($textString, $curPosn, $tagPrts[0][1]-$curPosn)));
			$textBuf = '';
			$bodyTxt = substr($textString, $bodyPos, $closeAt[0]-$bodyPos);
			$curPosn = $closeAt[0]+$closeAt[1];
			if( $tagName=='code' ){
				$codeTxt = trim($bodyTxt, "\n");
				if( trim($codeTxt)!=='' ){
					$blckLst[] = Shape::makeBlock('codes', Array(Shape::makeASpan($codeTxt)), Array(), preg_match('/^[\w+#.\-]+$/D', $tagArgs) ? Array('langg'=>$tagArgs) : Array());
				}
			}elseif( $tagName=='img' ){
				$imgSrce = Shape::cleanLink($bodyTxt);
				if( $imgSrce!=='' ){
					$blckLst[] = Shape::makeBlock('image', Array(), Array(), Array('sourc'=>$imgSrce));
				}
			}elseif( $tagName=='quote' ){
				$heldLst = $this->bbcodBlks($bodyTxt);
				if( !empty($heldLst) ){
					$blckLst[] = $this->holdBlock('quote', $heldLst, ($tagArgs!=='') ? Array('cites'=>$tagArgs) : Array());
				}
			}else{
				$entrLst = Array();
				foreach($this->bbcdEntry($bodyTxt) as $entTxt){
					$entrLst[] = $this->holdBlock('entry', $this->bbcodBlks($entTxt));
				}
				if( !empty($entrLst) ){
					$blckLst[] = Shape::makeBlock('lists', Array(), $entrLst, Array('order'=>($tagArgs!=='')));
				}
			}
		}
		return array_merge($blckLst, $this->bbcdParas($textBuf.substr($textString, $curPosn)));
	}

	/**
	 * Cuts what a BBCode list holds into its entries, at the [*] tags that
	 * are its own: those of a list inside it are left to that list.
	 *
	 * @param String $textString What stands between the tags of the list.
	 * @return Array The text of each entry, none of them empty.
	 */
	protected function bbcdEntry(String $textString): Array {
		$entrLst = Array();
		$tagPrts = Array();
		$deepNbr = 0;
		$fromPos = 0;
		$curPosn = 0;
		while( preg_match('/\[(list(?:=[^\]\n]*)?|\/list|\*)\]/i', $textString, $tagPrts, PREG_OFFSET_CAPTURE, $curPosn) ){
			$curPosn = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			if( $tagPrts[1][0]!=='*' ){
				$deepNbr += ($tagPrts[1][0][0]=='/') ? -1 : 1;
			}elseif( $deepNbr<=0 ){
				$entrLst[] = substr($textString, $fromPos, $tagPrts[0][1]-$fromPos);
				$fromPos = $curPosn;
			}
		}
		$entrLst[] = substr($textString, $fromPos);
		return array_values(array_filter(array_map('trim', $entrLst), function($entryValue){ return $entryValue!==''; }));
	}

	/**
	 * Reads a BBCode text holding no block into its paragraphs.
	 *
	 * @param String $textString The text.
	 * @return Array The paragraphs, as blocks.
	 */
	protected function bbcdParas(String $textString): Array {
		$blckLst = Array();
		foreach($this->splitPara($textString) as $parTxt){
			$spnsLst = Shape::joinSpans($this->bbcdSpans($parTxt, Array(), ''));
			if( !empty($spnsLst) ){
				$blckLst[] = Shape::makeBlock('parag', $spnsLst);
			}
		}
		return $blckLst;
	}

	/**
	 * Reads the BBCode tags of a paragraph into spans.
	 *
	 * @param String $textString The text.
	 * @param Array  $marksArray Formatting given by the tags around it.
	 * @param String $linkTarget Address given by a link around it, '' when there is none.
	 * @return Array The spans.
	 */
	protected function bbcdSpans(String $textString, Array $marksArray, String $linkTarget): Array {
		$spnsLst = Array();
		$textBuf = '';
		$curPosn = 0;
		$tagPrts = Array();
		while( preg_match('/\[(b|i|u|s|url)(?:=([^\]\n]*))?\]/i', $textString, $tagPrts, PREG_OFFSET_CAPTURE, $curPosn) ){
			$tagName = strtolower($tagPrts[1][0]);
			$bodyPos = $tagPrts[0][1]+strlen($tagPrts[0][0]);
			$closeAt = $this->findClose($textString, $tagName, $bodyPos, true);
			if( $closeAt===null ){
				// Never closed: the tag is left out, what follows it is kept
				$textBuf .= substr($textString, $curPosn, $tagPrts[0][1]-$curPosn);
				$curPosn = $bodyPos;
				continue;
			}
			$spnsLst[] = Shape::makeASpan($this->dropsTags($textBuf.substr($textString, $curPosn, $tagPrts[0][1]-$curPosn)), $marksArray, $linkTarget);
			$textBuf = '';
			$bodyTxt = substr($textString, $bodyPos, $closeAt[0]-$bodyPos);
			$curPosn = $closeAt[0]+$closeAt[1];
			if( $tagName=='url' ){
				// The address is what follows the '=', or else the text of the link itself
				$tagArgs = trim(strval($tagPrts[2][0] ?? ''), " \t\"'");
				$linkAdr = Shape::cleanLink(($tagArgs!=='') ? $tagArgs : $bodyTxt);
				$spnsLst = array_merge($spnsLst, $this->bbcdSpans($bodyTxt, $marksArray, ($linkAdr!=='') ? $linkAdr : $linkTarget));
			}else{
				$spnsLst = array_merge($spnsLst, $this->bbcdSpans($bodyTxt, array_merge($marksArray, Array(self::BBCDMARK[$tagName])), $linkTarget));
			}
		}
		$spnsLst[] = Shape::makeASpan($this->dropsTags($textBuf.substr($textString, $curPosn)), $marksArray, $linkTarget);
		return $spnsLst;
	}

	/**
	 * Takes out of a text the BBCode tags left in it once those that do
	 * something were read: closed and never opened, or not known. A tag
	 * is a name made of letters and digits between square brackets, with
	 * or without a value after '='; [1] or [ ] are not tags.
	 *
	 * @param String $textString The text.
	 * @return String The text without those tags.
	 */
	protected function dropsTags(String $textString): String {
		return preg_replace('/\[\/?[a-z][a-z0-9]*(?:=[^\]\n]*)?\]|\[\*\]/i', '', $textString);
	}

	/**
	 * Reads a text written with the marks of messaging into blocks.
	 *
	 * @param String $textString The text, its lines ending with \n.
	 * @return Array The blocks.
	 */
	protected function chatsBlks(String $textString): Array {
		$blckLst = Array();
		// What is being gathered: its kind ('parag' or 'lists'), its lines, and for a list whether it is numbered
		$openKnd = '';
		$openLns = Array();
		$openOrd = false;
		$lineLst = explode("\n", $textString);
		$lineLst[] = '';
		$lineNbr = count($lineLst);
		for($lneIdx=0; $lneIdx<$lineNbr; $lneIdx++){
			$lineTxt = rtrim($lineLst[$lneIdx]);
			$linePrt = Array();
			$lineKnd = 'parag';
			$lineOrd = false;
			if( $lineTxt==='' ){
				$lineKnd = '';
			}elseif( preg_match('/^[\-*] +(\S.*)$/', $lineTxt, $linePrt) ){
				$lineKnd = 'lists';
				$lineTxt = $linePrt[1];
			}elseif( preg_match('/^\d{1,9}[.)] +(\S.*)$/', $lineTxt, $linePrt) ){
				$lineKnd = 'lists';
				$lineOrd = true;
				$lineTxt = $linePrt[1];
			}
			// What was being gathered ends when the kind of line changes
			if( $openKnd!=='' && ($lineKnd!==$openKnd || ($lineKnd=='lists' && $lineOrd!==$openOrd)) ){
				if( $openKnd=='lists' ){
					$entrLst = Array();
					foreach($openLns as $entTxt){
						$entrLst[] = Shape::makeBlock('entry', Shape::joinSpans($this->chatSpans($entTxt, Array())));
					}
					$blckLst[] = Shape::makeBlock('lists', Array(), $entrLst, Array('order'=>$openOrd));
				}else{
					$spnsLst = Shape::joinSpans($this->chatSpans(trim(implode("\n", $openLns), "\n"), Array()));
					if( !empty($spnsLst) ){
						$blckLst[] = Shape::makeBlock('parag', $spnsLst);
					}
				}
				$openKnd = '';
				$openLns = Array();
			}
			if( $lineKnd!=='' ){
				$openKnd = $lineKnd;
				$openOrd = $lineOrd;
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
	 * @param String $textString The text.
	 * @param Array  $marksArray Formatting given by the marks around it.
	 * @return Array The spans.
	 */
	protected function chatSpans(String $textString, Array $marksArray): Array {
		// An underscore standing against another is never a mark of its own: __this__ is one mark, not two
		$markPtn = '/(?<url>https?:\/\/[^\s<>"]+)'
			.'|(?<![\p{L}\p{N}_])(?<und>__|_)(?=[^\s_])(?<utx>.+?)(?<=[^\s_])\k<und>(?![\p{L}\p{N}_])'
			.'|(?<![\p{L}\p{N}])(?<mrk>[*~])(?=\S)(?<txt>.+?)(?<=\S)\k<mrk>(?![\p{L}\p{N}])/us';
		$spnsLst = Array();
		$curPosn = 0;
		$fndPrts = Array();
		while( preg_match($markPtn, $textString, $fndPrts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $curPosn) ){
			$spnsLst[] = Shape::makeASpan(substr($textString, $curPosn, $fndPrts[0][1]-$curPosn), $marksArray);
			if( $fndPrts['url'][0]!==null ){
				$spnsLst[] = Shape::makeASpan($fndPrts[0][0], $marksArray);
			}else{
				$spnsLst = array_merge($spnsLst, $this->chatSpans(strval($fndPrts['utx'][0] ?? $fndPrts['txt'][0]), array_merge($marksArray, Array(self::CHATMARK[$fndPrts['und'][0] ?? $fndPrts['mrk'][0]]))));
			}
			$curPosn = $fndPrts[0][1]+strlen($fndPrts[0][0]);
		}
		$spnsLst[] = Shape::makeASpan(substr($textString, $curPosn), $marksArray);
		return $spnsLst;
	}

}
