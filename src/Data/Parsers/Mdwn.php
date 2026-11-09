<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * Markdown, read into text blocks: headings, paragraphs, lists, quotes,
 * code, tables and rules, with the formatting, the links and the images
 * of their text.
 *
 * HTML written inside the Markdown is not interpreted: it stays text.
 * Links by reference and footnotes stay as written.
 */
class Mdwn extends Base {

	// Formatting given by each mark
	const MARK_LIST = Array(1=>Array('ital'), 2=>Array('bold'), 3=>Array('bold', 'ital'));
	// A line that is a rule
	const RULE_PTRN = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/D';
	// The line under the names of the columns of a table
	const TBLE_PTRN = '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/D';
	// An address between the brackets of a link or of an image, and the title that may follow it
	const ADDR_PTRN = '(?:<[^>\n]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)';
	const TITL_PTRN = '(?:[ \t]+(?:"[^"]*"|\'[^\']*\'))?[ \t]*';
	// An image, alone or inside a link
	const IMGE_PTRN = '/\[!\[(?<la>[^\]]*)\]\([ \t]*(?<ls>'.self::ADDR_PTRN.')'.self::TITL_PTRN.'\)\]\([ \t]*'.self::ADDR_PTRN.self::TITL_PTRN.'\)|!\[(?<ia>[^\]]*)\]\([ \t]*(?<is>'.self::ADDR_PTRN.')'.self::TITL_PTRN.'\)/';

	/**
	 * Parses a Markdown text into blocks. An empty content gives no block.
	 *
	 * @param String $nakedContent The Markdown text.
	 * @param Array  $parseOptions Options: none.
	 * @return Array The envelope, of shape 'block'; 'infos' holds the first heading of level 1 under 'title' and, when the text starts with a block of settings between two lines of ---, the values of that block, read as YAML, under 'front'.
	 *
	 * @throws ParseError When that block of settings is not valid.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$fullTxt = str_replace(Array("\r\n", "\r"), "\n", self::recodeTextUtf8($nakedContent));
		$infoArr = Array('title'=>'', 'front'=>Array());
		$frntPrt = Array();
		if( preg_match('/^---[ \t]*\n((?=[\w\-]+[ \t]*:).*?)\n(?:---|\.\.\.)[ \t]*(?:\n|$)/s', $fullTxt, $frntPrt) ){
			// The settings are YAML; their lines are numbered as in the text, after the line of ---
			try{
				$frntVal = (new Yaml())->parseDataArray("\n".$frntPrt[1])['items'];
			}catch( ParseError $e ){
				throw new ParseError(str_replace('Invalid YAML content', 'Invalid Markdown settings', $e->getMessage()), 0, $e);
			}
			$infoArr['front'] = is_array($frntVal) ? $frntVal : Array();
			$fullTxt = substr($fullTxt, strlen($frntPrt[0]));
		}
		// A tabulation starting a line stands for four spaces
		$fullTxt = preg_replace_callback('/^[ \t]*\t[ \t]*/m', function($locatedParts){ return str_replace("\t", '    ', $locatedParts[0]); }, $fullTxt);
		$blckLst = $this->retrieveBlocks(explode("\n", $fullTxt));
		foreach($blckLst as $oneBlk){
			if( $oneBlk['block']=='headr' && $oneBlk['attrs']['level']==1 ){
				$infoArr['title'] = $oneBlk['plain'];
				break;
			}
		}
		return $this->makeShapeArray('block', $blckLst, $infoArr);
	}

	/**
	 * Tells whether a line starts a block of its own: code between marks,
	 * a heading, a rule, a quote or a list.
	 *
	 * @param String $markdownLine The line.
	 * @param Bool   $insidePrgrph Whether the line follows the lines of a paragraph, which only a list that clearly is one may cut.
	 * @return Bool True when it does.
	 */
	protected function startsBlockFlg(String $markdownLine, Bool $insidePrgrph): Bool {
		if( preg_match('/^ {0,3}(?:`{3,}[^`]*$|~{3,}|#{1,6}(?:[ \t]|$)|>)/D', $markdownLine) || preg_match(self::RULE_PTRN, $markdownLine) ){
			return true;
		}
		$lstMark = $this->readListMarker($markdownLine);
		if( $lstMark===null ){
			return false;
		}
		return (!$insidePrgrph || ($lstMark['texts']!=='' && in_array($lstMark['numbr'], Array('', '1'), true)));
	}

	/**
	 * Reads the mark of a list at the start of a line.
	 *
	 * @param String $markdownLine The line.
	 * @return ?Array The mark: its kind under 'kinds' (-, *, +, . or )), its number under 'numbr' ('' for a list without numbers), the width its entry is indented by under 'indnt', and the text following it under 'texts'. Null when the line does not start an entry.
	 */
	protected function readListMarker(String $markdownLine): ?Array {
		$mrkPart = Array();
		if( !preg_match('/^( {0,3})(?:([-*+])|(\d{1,9})([.)]))(?:([ \t]+)(.*))?$/D', $markdownLine, $mrkPart) ){
			return null;
		}
		$markLen = strlen($mrkPart[1])+strlen($mrkPart[2] ?? '')+strlen($mrkPart[3] ?? '')+strlen($mrkPart[4] ?? '');
		$gapSize = strlen(str_replace("\t", ' ', strval($mrkPart[5] ?? '')));
		$content = strval($mrkPart[6] ?? '');
		// More than four spaces after the mark: the entry starts one space in, and holds indented code
		if( $gapSize>4 ){
			$content = str_repeat(' ', $gapSize-1).$content;
			$gapSize = 1;
		}
		return Array('kinds'=>($mrkPart[2]!=='') ? $mrkPart[2] : $mrkPart[4], 'numbr'=>strval($mrkPart[3] ?? ''), 'indnt'=>$markLen+(($content==='') ? 1 : $gapSize), 'texts'=>$content);
	}

	/**
	 * Reads lines into blocks.
	 *
	 * @param Array $linesToParse The lines, without their line ends.
	 * @return Array The blocks.
	 */
	protected function retrieveBlocks(Array $linesToParse): Array {
		$blckLst = Array();
		$paraLns = Array();
		$linPart = Array();
		$lineNum = count($linesToParse);
		$lineIdx = 0;
		while( $lineIdx<$lineNum ){
			$lineTxt = $linesToParse[$lineIdx];
			// A line of = or - under a paragraph makes a heading of it
			if( !empty($paraLns) && preg_match('/^ {0,3}(=+|-+)[ \t]*$/D', $lineTxt, $linPart) ){
				$blckLst[] = $this->headBlockArray($this->mergeLinesText($paraLns), ($linPart[1][0]=='=') ? 1 : 2);
				$paraLns = Array();
				$lineIdx++;
				continue;
			}
			$tblStrt = (strpos($lineTxt, '|')!==false && isset($linesToParse[$lineIdx+1]) && preg_match(self::TBLE_PTRN, $linesToParse[$lineIdx+1]) && count($this->splitCellArray($lineTxt))==count($this->splitCellArray($linesToParse[$lineIdx+1])));
			$codeInd = (empty($paraLns) && substr($lineTxt, 0, 4)==='    ');
			if( trim($lineTxt)!=='' && !$tblStrt && !$codeInd && !$this->startsBlockFlg($lineTxt, !empty($paraLns)) ){
				$paraLns[] = $lineTxt;
				$lineIdx++;
				continue;
			}
			// Anything else ends the paragraph being gathered
			$blckLst = array_merge($blckLst, $this->flowBlocksList($paraLns));
			$paraLns = Array();
			if( trim($lineTxt)==='' ){
				$lineIdx++;
			}elseif( $tblStrt ){
				$blckLst[] = $this->readTableArray($linesToParse, $lineIdx);
			}elseif( $codeInd ){
				$codeLns = Array();
				while( $lineIdx<$lineNum && (substr($linesToParse[$lineIdx], 0, 4)==='    ' || trim($linesToParse[$lineIdx])==='') ){
					$codeLns[] = strval(substr($linesToParse[$lineIdx], 4));
					$lineIdx++;
				}
				$blckLst[] = Shape::makeBlockArray('codes', Array(Shape::makeASpanArray(rtrim(implode("\n", $codeLns)))));
			}elseif( preg_match('/^( {0,3})(`{3,}|~{3,})[ \t]*([^\s`]*)/', $lineTxt, $linPart) ){
				$codeLns = Array();
				$lineIdx++;
				// The code runs to a line of the same marks, or to the end of the text
				while( $lineIdx<$lineNum && !preg_match('/^ {0,3}'.preg_quote($linPart[2][0], '/').'{'.strlen($linPart[2]).',}[ \t]*$/D', $linesToParse[$lineIdx]) ){
					$codeLns[] = preg_replace('/^ {0,'.strlen($linPart[1]).'}/', '', $linesToParse[$lineIdx]);
					$lineIdx++;
				}
				$lineIdx++;
				$blckLst[] = Shape::makeBlockArray('codes', Array(Shape::makeASpanArray(implode("\n", $codeLns))), Array(), preg_match('/^[\w+#.\-]+$/D', $linPart[3]) ? Array('langg'=>$linPart[3]) : Array());
			}elseif( preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/D', $lineTxt, $linPart) ){
				$blckLst[] = $this->headBlockArray(strval($linPart[2] ?? ''), strlen($linPart[1]));
				$lineIdx++;
			}elseif( preg_match(self::RULE_PTRN, $lineTxt) ){
				$blckLst[] = Shape::makeBlockArray('rules');
				$lineIdx++;
			}elseif( preg_match('/^ {0,3}>/', $lineTxt) ){
				$heldLns = Array();
				// The quote runs over the lines starting with >, and the lines that carry on its last paragraph
				while( $lineIdx<$lineNum && (preg_match('/^ {0,3}>/', $linesToParse[$lineIdx]) || (trim($linesToParse[$lineIdx])!=='' && trim(strval(end($heldLns)))!=='' && !$this->startsBlockFlg($linesToParse[$lineIdx], true))) ){
					$heldLns[] = preg_replace('/^ {0,3}> ?/', '', $linesToParse[$lineIdx]);
					$lineIdx++;
				}
				$blckLst[] = $this->holdBlockArray('quote', $this->retrieveBlocks($heldLns));
			}else{
				$blckLst[] = $this->readListsArray($linesToParse, $lineIdx);
			}
		}
		return array_merge($blckLst, $this->flowBlocksList($paraLns));
	}

	/**
	 * Builds a heading. An id written after its text as {#name} is the
	 * id of the block.
	 *
	 * @param String $templateText The text of the heading.
	 * @param Int    $levelValNumb Its level, from 1 to 6.
	 * @return Array The block.
	 */
	protected function headBlockArray(String $templateText, Int $levelValNumb): Array {
		$attrSet = Array('level'=>$levelValNumb);
		$idMatch = Array();
		if( preg_match('/^(.*?)[ \t]*\{#([\w\-:.]+)\}$/Ds', trim($templateText), $idMatch) ){
			$templateText = $idMatch[1];
			$attrSet['tagid'] = $idMatch[2];
		}
		return Shape::makeBlockArray('headr', $this->textSpansArray($templateText), Array(), $attrSet);
	}

	/**
	 * Reads a list, from the line of its first entry.
	 *
	 * @param Array $linesToParse The lines being read.
	 * @param Int   $linesIndxNum The line of the first entry; receives the line following the list.
	 * @return Array The list, as a block.
	 */
	protected function readListsArray(Array $linesToParse, Int &$linesIndxNum): Array {
		$lineNum = count($linesToParse);
		$frstMrk = $this->readListMarker($linesToParse[$linesIndxNum]);
		$itemLst = Array();
		while( $linesIndxNum<$lineNum ){
			$itmMark = $this->readListMarker($linesToParse[$linesIndxNum]);
			$itemLns = Array($itmMark['texts']);
			$linesIndxNum++;
			while( $linesIndxNum<$lineNum ){
				$lineTxt = $linesToParse[$linesIndxNum];
				$nextIdx = $linesIndxNum;
				while( $nextIdx<$lineNum && trim($linesToParse[$nextIdx])==='' ){
					$nextIdx++;
				}
				if( $nextIdx>$linesIndxNum ){
					// Blank lines belong to the entry when what follows them is indented as it is
					if( $nextIdx>=$lineNum || strspn($linesToParse[$nextIdx], ' ')<$itmMark['indnt'] ){
						break;
					}
					$itemLns = array_merge($itemLns, array_fill(0, $nextIdx-$linesIndxNum, ''));
					$linesIndxNum = $nextIdx;
					continue;
				}
				if( strspn($lineTxt, ' ')>=$itmMark['indnt'] ){
					$itemLns[] = substr($lineTxt, $itmMark['indnt']);
				}elseif( trim(strval(end($itemLns)))!=='' && !$this->startsBlockFlg($lineTxt, false) ){
					// A line that is not indented carries on the paragraph of the entry
					$itemLns[] = ltrim($lineTxt);
				}else{
					break;
				}
				$linesIndxNum++;
			}
			// A box at the start of an entry makes it a task, done or not
			$attrSet = Array();
			$tskPart = Array();
			if( preg_match('/^\[([ xX])\][ \t]+(.*)$/Ds', $itemLns[0], $tskPart) ){
				$attrSet['check'] = ($tskPart[1]!==' ');
				$itemLns[0] = $tskPart[2];
			}
			$itemLst[] = $this->holdBlockArray('entry', $this->retrieveBlocks($itemLns), $attrSet);
			// The list carries on when its next entry follows, blank lines apart
			$nextIdx = $linesIndxNum;
			while( $nextIdx<$lineNum && trim($linesToParse[$nextIdx])==='' ){
				$nextIdx++;
			}
			$nxtMark = ($nextIdx<$lineNum && !preg_match(self::RULE_PTRN, $linesToParse[$nextIdx])) ? $this->readListMarker($linesToParse[$nextIdx]) : null;
			if( $nxtMark===null || $nxtMark['kinds']!==$frstMrk['kinds'] ){
				break;
			}
			$linesIndxNum = $nextIdx;
		}
		return Shape::makeBlockArray('lists', Array(), $itemLst, Array('order'=>($frstMrk['numbr']!=='')));
	}

	/**
	 * Cuts a line of a table into its cells.
	 *
	 * @param String $markdownLine The line.
	 * @return Array The text of each cell.
	 */
	protected function splitCellArray(String $markdownLine): Array {
		$lineTxt = preg_replace('/^\||(?<!\\\\)\|$/', '', trim($markdownLine));
		$cellLst = Array();
		foreach(preg_split('/(?<!\\\\)\|/', $lineTxt) as $fldTxt){
			$cellLst[] = trim(str_replace('\\|', '|', $fldTxt));
		}
		return $cellLst;
	}

	/**
	 * Reads a table, from the line naming its columns.
	 *
	 * @param Array $linesToParse The lines being read.
	 * @param Int   $linesIndxNum The line naming the columns; receives the line following the table.
	 * @return Array The table, as a block.
	 */
	protected function readTableArray(Array $linesToParse, Int &$linesIndxNum): Array {
		$headTxt = $this->splitCellArray($linesToParse[$linesIndxNum]);
		$rowsLst = Array();
		$linesIndxNum += 2;
		$cellTxt = $headTxt;
		while( true ){
			$cellBlk = Array();
			// Every row has the cells of the header: no more, no less
			foreach(array_keys($headTxt) as $colInc){
				$cellBlk[] = Shape::makeBlockArray('tcell', $this->textSpansArray(strval($cellTxt[$colInc] ?? '')));
			}
			$rowsLst[] = Shape::makeBlockArray(empty($rowsLst) ? 'thead' : 'tbrow', Array(), $cellBlk);
			if( !isset($linesToParse[$linesIndxNum]) || trim($linesToParse[$linesIndxNum])==='' || $this->startsBlockFlg($linesToParse[$linesIndxNum], true) ){
				break;
			}
			$cellTxt = $this->splitCellArray($linesToParse[$linesIndxNum]);
			$linesIndxNum++;
		}
		return Shape::makeBlockArray('table', Array(), $rowsLst);
	}

	/**
	 * Joins the lines of a paragraph: a line end is a space, unless the
	 * line ends with two spaces or a backslash, which ask for a line break.
	 *
	 * @param Array $linesToParse The lines.
	 * @return String The text.
	 */
	protected function mergeLinesText(Array $linesToParse): String {
		$fullTxt = '';
		$lastIdx = count($linesToParse)-1;
		foreach(array_values($linesToParse) as $linIdx=>$linTxt){
			$brkLine = ($linIdx<$lastIdx && (substr($linTxt, -2)==='  ' || (substr($linTxt, -1)==='\\' && substr($linTxt, -2)!=='\\\\')));
			$fullTxt .= trim(($brkLine && substr($linTxt, -1)==='\\') ? substr($linTxt, 0, -1) : $linTxt).(($linIdx<$lastIdx) ? ($brkLine ? "\n" : ' ') : '');
		}
		return $fullTxt;
	}

	/**
	 * Reads the lines of a paragraph into blocks: the paragraph, cut in
	 * two around each image it holds.
	 *
	 * @param Array $linesToParse The lines.
	 * @return Array The blocks; none when there is no line.
	 */
	protected function flowBlocksList(Array $linesToParse): Array {
		$blckLst = Array();
		$fullTxt = $this->mergeLinesText($linesToParse);
		$imgPrts = Array();
		$readPos = 0;
		while( preg_match(self::IMGE_PTRN, $fullTxt, $imgPrts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $readPos) ){
			$spnsLst = $this->textSpansArray(substr($fullTxt, $readPos, $imgPrts[0][1]-$readPos));
			if( !empty($spnsLst) ){
				$blckLst[] = Shape::makeBlockArray('parag', $spnsLst);
			}
			$imgLink = Shape::tidyLinkString(trim(strval($imgPrts['ls'][0] ?? $imgPrts['is'][0]), '<>'));
			$imgText = trim(strval($imgPrts['la'][0] ?? $imgPrts['ia'][0]));
			if( $imgLink!=='' ){
				$blckLst[] = Shape::makeBlockArray('image', ($imgText!=='') ? Array(Shape::makeASpanArray($imgText)) : Array(), Array(), Array('sourc'=>$imgLink));
			}
			$readPos = $imgPrts[0][1]+strlen($imgPrts[0][0]);
		}
		$spnsLst = $this->textSpansArray(strval(substr($fullTxt, $readPos)));
		if( !empty($spnsLst) ){
			$blckLst[] = Shape::makeBlockArray('parag', $spnsLst);
		}
		return $blckLst;
	}

	/**
	 * Reads a text into tidied spans.
	 *
	 * @param String $templateText The text.
	 * @return Array The spans; none when there is no text.
	 */
	protected function textSpansArray(String $templateText): Array {
		return Shape::joinSpansArray($this->inlineSpansArr(trim($templateText), Array(), ''));
	}

	/**
	 * Reads the marks of a text into spans: code between backticks,
	 * links, and what is bold, italic or struck. A mark after a backslash
	 * is text, and so is a mark that is never closed.
	 *
	 * @param String $templateText The text.
	 * @param Array  $runFormatSet Formatting given by the marks around it.
	 * @param String $linkGoalText Address given by a link around it, '' when there is none.
	 * @return Array The spans.
	 */
	protected function inlineSpansArr(String $templateText, Array $runFormatSet, String $linkGoalText): Array {
		$pattern = '/\\\\(?<esc>[!-\/:-@\[-`{-~])'
			.'|(?<!`)(?<tck>`++)(?<cod>.+?)(?<!`)\k<tck>(?!`)'
			.'|<(?<aut>https?:\/\/[^\s<>]+|mailto:[^\s<>]+|[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+)>'
			.'|!\[(?<alt>[^\]]*)\]\([ \t]*'.self::ADDR_PTRN.self::TITL_PTRN.'\)'
			.'|\[(?<ltx>(?:[^\[\]\\\\]|\\\\.|\[[^\[\]]*\])*)\]\([ \t]*(?<lnk>'.self::ADDR_PTRN.')'.self::TITL_PTRN.'\)'
			.'|(?<!\*)(?<stm>\*{1,3})(?![\s*])(?<stx>.+?)(?<![\s*])\k<stm>(?!\*)'
			.'|(?<![\p{L}\p{N}_])(?<unm>_{1,3})(?![\s_])(?<unx>.+?)(?<![\s_])\k<unm>(?![\p{L}\p{N}_])'
			.'|(?<!~)~~(?![\s~])(?<tld>.+?)(?<![\s~])~~(?!~)/us';
		$spnsLst = Array();
		$readPos = 0;
		$matches = Array();
		while( preg_match($pattern, $templateText, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $readPos) ){
			$spnsLst[] = Shape::makeASpanArray(html_entity_decode(substr($templateText, $readPos, $matches[0][1]-$readPos), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $runFormatSet, $linkGoalText);
			$readPos = $matches[0][1]+strlen($matches[0][0]);
			if( $matches['esc'][0]!==null ){
				$spnsLst[] = Shape::makeASpanArray($matches['esc'][0], $runFormatSet, $linkGoalText);
			}elseif( $matches['cod'][0]!==null ){
				// Code is given as written; one space on each side of it is only there to hold a backtick
				$codeTxt = $matches['cod'][0];
				if( strlen($codeTxt)>2 && $codeTxt[0]==' ' && substr($codeTxt, -1)==' ' && trim($codeTxt)!=='' ){
					$codeTxt = substr($codeTxt, 1, -1);
				}
				$spnsLst[] = Shape::makeASpanArray($codeTxt, array_merge($runFormatSet, Array('code')), $linkGoalText);
			}elseif( $matches['aut'][0]!==null ){
				$autoAdr = $matches['aut'][0];
				$spnsLst[] = Shape::makeASpanArray($autoAdr, $runFormatSet, Shape::tidyLinkString((strpos($autoAdr, ':')===false) ? 'mailto:'.$autoAdr : $autoAdr));
			}elseif( $matches['alt'][0]!==null ){
				// An image where only text can stand, as in a heading or a cell: its description
				$spnsLst[] = Shape::makeASpanArray($matches['alt'][0], $runFormatSet, $linkGoalText);
			}elseif( $matches['ltx'][0]!==null ){
				$linkAdr = Shape::tidyLinkString(preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', trim(strval($matches['lnk'][0]), '<>')));
				$spnsLst = array_merge($spnsLst, $this->inlineSpansArr($matches['ltx'][0], $runFormatSet, ($linkAdr!=='') ? $linkAdr : $linkGoalText));
			}elseif( $matches['tld'][0]!==null ){
				$spnsLst = array_merge($spnsLst, $this->inlineSpansArr($matches['tld'][0], array_merge($runFormatSet, Array('strk')), $linkGoalText));
			}else{
				$markTxt = strval($matches['stm'][0] ?? $matches['unm'][0]);
				$spnsLst = array_merge($spnsLst, $this->inlineSpansArr(strval($matches['stx'][0] ?? $matches['unx'][0]), array_merge($runFormatSet, self::MARK_LIST[strlen($markTxt)]), $linkGoalText));
			}
		}
		$spnsLst[] = Shape::makeASpanArray(html_entity_decode(strval(substr($templateText, $readPos)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $runFormatSet, $linkGoalText);
		return $spnsLst;
	}

}
