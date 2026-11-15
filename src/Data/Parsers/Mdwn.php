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
	const MARK_LIST = Array(1=>Array('emphasis'), 2=>Array('boldFace'), 3=>Array('boldFace', 'emphasis'));
	// A line that is a rule
	const RULE_PATT = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/D';
	// The line under the names of the columns of a table
	const TBLE_PATT = '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/D';
	// An address between the brackets of a link or of an image, and the title that may follow it
	const ADDR_PATT = '(?:<[^>\n]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)';
	const TITL_PATT = '(?:[ \t]+(?:"[^"]*"|\'[^\']*\'))?[ \t]*';
	// An image, alone or inside a link
	const IMGE_PATT = '/\[!\[(?<la>[^\]]*)\]\([ \t]*(?<ls>'.self::ADDR_PATT.')'.self::TITL_PATT.'\)\]\([ \t]*'.self::ADDR_PATT.self::TITL_PATT.'\)|!\[(?<ia>[^\]]*)\]\([ \t]*(?<is>'.self::ADDR_PATT.')'.self::TITL_PATT.'\)/';

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
		$fullText = str_replace(Array("\r\n", "\r"), "\n", self::castTextToUtf8($nakedContent));
		$infoList = Array('title'=>'', 'front'=>Array());
		$frontPar = Array();
		if( preg_match('/^---[ \t]*\n((?=[\w\-]+[ \t]*:).*?)\n(?:---|\.\.\.)[ \t]*(?:\n|$)/s', $fullText, $frontPar) ){
			// The settings are YAML; their lines are numbered as in the text, after the line of ---
			try{
				$frontVal = (new Yaml())->parseDataArray("\n".$frontPar[1])['items'];
			}catch( ParseError $e ){
				throw new ParseError(str_replace('Invalid YAML content', 'Invalid Markdown settings', $e->getMessage()), 0, $e);
			}
			$infoList['front'] = is_array($frontVal) ? $frontVal : Array();
			$fullText = substr($fullText, strlen($frontPar[0]));
		}
		// A tabulation starting a line stands for four spaces
		$fullText = preg_replace_callback('/^[ \t]*\t[ \t]*/m', function($locatedParts){ return str_replace("\t", '    ', $locatedParts[0]); }, $fullText);
		$blockLst = $this->readLineBlocks(explode("\n", $fullText));
		foreach($blockLst as $oneBlk){
			if( $oneBlk['block']=='headline' && $oneBlk['attrs']['level']==1 ){
				$infoList['title'] = $oneBlk['plain'];
				break;
			}
		}
		return $this->makeShapeArray('block', $blockLst, $infoList);
	}

	/**
	 * Tells whether a line starts a block of its own: code between marks,
	 * a heading, a rule, a quote or a list.
	 *
	 * @param String $markdownLine The line.
	 * @param Bool   $insidePrgrph Whether the line follows the lines of a paragraph, which only a list that clearly is one may cut.
	 * @return Bool True when it does.
	 */
	protected function isStartOfBlock(String $markdownLine, Bool $insidePrgrph): Bool {
		if( preg_match('/^ {0,3}(?:`{3,}[^`]*$|~{3,}|#{1,6}(?:[ \t]|$)|>)/D', $markdownLine) || preg_match(self::RULE_PATT, $markdownLine) ){
			return true;
		}
		$listMark = $this->readListMarker($markdownLine);
		if( $listMark===null ){
			return false;
		}
		return (!$insidePrgrph || ($listMark['texts']!=='' && in_array($listMark['numbr'], Array('', '1'), true)));
	}

	/**
	 * Reads the mark of a list at the start of a line.
	 *
	 * @param String $markdownLine The line.
	 * @return ?Array The mark: its kind under 'kinds' (-, *, +, . or )), its number under 'numbr' ('' for a list without numbers), the width its entry is indented by under 'indnt', and the text following it under 'texts'. Null when the line does not start an entry.
	 */
	protected function readListMarker(String $markdownLine): ?Array {
		$markPart = Array();
		if( !preg_match('/^( {0,3})(?:([-*+])|(\d{1,9})([.)]))(?:([ \t]+)(.*))?$/D', $markdownLine, $markPart) ){
			return null;
		}
		$markSize = strlen($markPart[1])+strlen($markPart[2] ?? '')+strlen($markPart[3] ?? '')+strlen($markPart[4] ?? '');
		$gapBytes = strlen(str_replace("\t", ' ', strval($markPart[5] ?? '')));
		$bodyText = strval($markPart[6] ?? '');
		// More than four spaces after the mark: the entry starts one space in, and holds indented code
		if( $gapBytes>4 ){
			$bodyText = str_repeat(' ', $gapBytes-1).$bodyText;
			$gapBytes = 1;
		}
		return Array('kinds'=>($markPart[2]!=='') ? $markPart[2] : $markPart[4], 'numbr'=>strval($markPart[3] ?? ''), 'indnt'=>$markSize+(($bodyText==='') ? 1 : $gapBytes), 'texts'=>$bodyText);
	}

	/**
	 * Reads lines into blocks.
	 *
	 * @param Array $linesToParse The lines, without their line ends.
	 * @return Array The blocks.
	 */
	protected function readLineBlocks(Array $linesToParse): Array {
		$blockLst = Array();
		$prgrLnes = Array();
		$linePart = Array();
		$lineNumb = count($linesToParse);
		$lineIndx = 0;
		while( $lineIndx<$lineNumb ){
			$lineText = $linesToParse[$lineIndx];
			// A line of = or - under a paragraph makes a heading of it
			if( !empty($prgrLnes) && preg_match('/^ {0,3}(=+|-+)[ \t]*$/D', $lineText, $linePart) ){
				$blockLst[] = $this->makeHeadingBlc($this->joinLinesAsTxt($prgrLnes), ($linePart[1][0]=='=') ? 1 : 2);
				$prgrLnes = Array();
				$lineIndx++;
				continue;
			}
			$tbleStrt = (strpos($lineText, '|')!==false && isset($linesToParse[$lineIndx+1]) && preg_match(self::TBLE_PATT, $linesToParse[$lineIndx+1]) && count($this->splitCellArray($lineText))==count($this->splitCellArray($linesToParse[$lineIndx+1])));
			$codeIndt = (empty($prgrLnes) && substr($lineText, 0, 4)==='    ');
			if( trim($lineText)!=='' && !$tbleStrt && !$codeIndt && !$this->isStartOfBlock($lineText, !empty($prgrLnes)) ){
				$prgrLnes[] = $lineText;
				$lineIndx++;
				continue;
			}
			// Anything else ends the paragraph being gathered
			$blockLst = array_merge($blockLst, $this->readFlowBlocks($prgrLnes));
			$prgrLnes = Array();
			if( trim($lineText)==='' ){
				$lineIndx++;
			}elseif( $tbleStrt ){
				$blockLst[] = $this->readTableArray($linesToParse, $lineIndx);
			}elseif( $codeIndt ){
				$codeLnes = Array();
				while( $lineIndx<$lineNumb && (substr($linesToParse[$lineIndx], 0, 4)==='    ' || trim($linesToParse[$lineIndx])==='') ){
					$codeLnes[] = strval(substr($linesToParse[$lineIndx], 4));
					$lineIndx++;
				}
				$blockLst[] = Shape::makeBlockArray('codeBlck', Array(Shape::makeSpanOfText(rtrim(implode("\n", $codeLnes)))));
			}elseif( preg_match('/^( {0,3})(`{3,}|~{3,})[ \t]*([^\s`]*)/', $lineText, $linePart) ){
				$codeLnes = Array();
				$lineIndx++;
				// The code runs to a line of the same marks, or to the end of the text
				while( $lineIndx<$lineNumb && !preg_match('/^ {0,3}'.preg_quote($linePart[2][0], '/').'{'.strlen($linePart[2]).',}[ \t]*$/D', $linesToParse[$lineIndx]) ){
					$codeLnes[] = preg_replace('/^ {0,'.strlen($linePart[1]).'}/', '', $linesToParse[$lineIndx]);
					$lineIndx++;
				}
				$lineIndx++;
				$blockLst[] = Shape::makeBlockArray('codeBlck', Array(Shape::makeSpanOfText(implode("\n", $codeLnes))), Array(), preg_match('/^[\w+#.\-]+$/D', $linePart[3]) ? Array('langg'=>$linePart[3]) : Array());
			}elseif( preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/D', $lineText, $linePart) ){
				$blockLst[] = $this->makeHeadingBlc(strval($linePart[2] ?? ''), strlen($linePart[1]));
				$lineIndx++;
			}elseif( preg_match(self::RULE_PATT, $lineText) ){
				$blockLst[] = Shape::makeBlockArray('ruleLine');
				$lineIndx++;
			}elseif( preg_match('/^ {0,3}>/', $lineText) ){
				$heldLnes = Array();
				// The quote runs over the lines starting with >, and the lines that carry on its last paragraph
				while( $lineIndx<$lineNumb && (preg_match('/^ {0,3}>/', $linesToParse[$lineIndx]) || (trim($linesToParse[$lineIndx])!=='' && trim(strval(end($heldLnes)))!=='' && !$this->isStartOfBlock($linesToParse[$lineIndx], true))) ){
					$heldLnes[] = preg_replace('/^ {0,3}> ?/', '', $linesToParse[$lineIndx]);
					$lineIndx++;
				}
				$blockLst[] = $this->makeOuterBlock('citation', $this->readLineBlocks($heldLnes));
			}else{
				$blockLst[] = $this->readListsArray($linesToParse, $lineIndx);
			}
		}
		return array_merge($blockLst, $this->readFlowBlocks($prgrLnes));
	}

	/**
	 * Builds a heading. An id written after its text as {#name} is the
	 * id of the block.
	 *
	 * @param String $templateText The text of the heading.
	 * @param Int    $levelValNumb Its level, from 1 to 6.
	 * @return Array The block.
	 */
	protected function makeHeadingBlc(String $templateText, Int $levelValNumb): Array {
		$attrDict = Array('level'=>$levelValNumb);
		$tgidMatx = Array();
		if( preg_match('/^(.*?)[ \t]*\{#([\w\-:.]+)\}$/Ds', trim($templateText), $tgidMatx) ){
			$templateText = $tgidMatx[1];
			$attrDict['tagid'] = $tgidMatx[2];
		}
		return Shape::makeBlockArray('headline', $this->readSpansOfTxt($templateText), Array(), $attrDict);
	}

	/**
	 * Reads a list, from the line of its first entry.
	 *
	 * @param Array $linesToParse The lines being read.
	 * @param Int   $linesIndxNum The line of the first entry; receives the line following the list.
	 * @return Array The list, as a block.
	 */
	protected function readListsArray(Array $linesToParse, Int &$linesIndxNum): Array {
		$lineNumb = count($linesToParse);
		$firstMrk = $this->readListMarker($linesToParse[$linesIndxNum]);
		$itemList = Array();
		while( $linesIndxNum<$lineNumb ){
			$itemMark = $this->readListMarker($linesToParse[$linesIndxNum]);
			$itemLnes = Array($itemMark['texts']);
			$linesIndxNum++;
			while( $linesIndxNum<$lineNumb ){
				$lineText = $linesToParse[$linesIndxNum];
				$nextIndx = $linesIndxNum;
				while( $nextIndx<$lineNumb && trim($linesToParse[$nextIndx])==='' ){
					$nextIndx++;
				}
				if( $nextIndx>$linesIndxNum ){
					// Blank lines belong to the entry when what follows them is indented as it is
					if( $nextIndx>=$lineNumb || strspn($linesToParse[$nextIndx], ' ')<$itemMark['indnt'] ){
						break;
					}
					$itemLnes = array_merge($itemLnes, array_fill(0, $nextIndx-$linesIndxNum, ''));
					$linesIndxNum = $nextIndx;
					continue;
				}
				if( strspn($lineText, ' ')>=$itemMark['indnt'] ){
					$itemLnes[] = substr($lineText, $itemMark['indnt']);
				}elseif( trim(strval(end($itemLnes)))!=='' && !$this->isStartOfBlock($lineText, false) ){
					// A line that is not indented carries on the paragraph of the entry
					$itemLnes[] = ltrim($lineText);
				}else{
					break;
				}
				$linesIndxNum++;
			}
			// A box at the start of an entry makes it a task, done or not
			$attrDict = Array();
			$taskPart = Array();
			if( preg_match('/^\[([ xX])\][ \t]+(.*)$/Ds', $itemLnes[0], $taskPart) ){
				$attrDict['check'] = ($taskPart[1]!==' ');
				$itemLnes[0] = $taskPart[2];
			}
			$itemList[] = $this->makeOuterBlock('listItem', $this->readLineBlocks($itemLnes), $attrDict);
			// The list carries on when its next entry follows, blank lines apart
			$nextIndx = $linesIndxNum;
			while( $nextIndx<$lineNumb && trim($linesToParse[$nextIndx])==='' ){
				$nextIndx++;
			}
			$nextMark = ($nextIndx<$lineNumb && !preg_match(self::RULE_PATT, $linesToParse[$nextIndx])) ? $this->readListMarker($linesToParse[$nextIndx]) : null;
			if( $nextMark===null || $nextMark['kinds']!==$firstMrk['kinds'] ){
				break;
			}
			$linesIndxNum = $nextIndx;
		}
		return Shape::makeBlockArray('itemList', Array(), $itemList, Array('order'=>($firstMrk['numbr']!=='')));
	}

	/**
	 * Cuts a line of a table into its cells.
	 *
	 * @param String $markdownLine The line.
	 * @return Array The text of each cell.
	 */
	protected function splitCellArray(String $markdownLine): Array {
		$lineText = preg_replace('/^\||(?<!\\\\)\|$/', '', trim($markdownLine));
		$cellList = Array();
		foreach(preg_split('/(?<!\\\\)\|/', $lineText) as $oneTxt){
			$cellList[] = trim(str_replace('\\|', '|', $oneTxt));
		}
		return $cellList;
	}

	/**
	 * Reads a table, from the line naming its columns.
	 *
	 * @param Array $linesToParse The lines being read.
	 * @param Int   $linesIndxNum The line naming the columns; receives the line following the table.
	 * @return Array The table, as a block.
	 */
	protected function readTableArray(Array $linesToParse, Int &$linesIndxNum): Array {
		$headText = $this->splitCellArray($linesToParse[$linesIndxNum]);
		$rowsList = Array();
		$linesIndxNum += 2;
		$cellText = $headText;
		while( true ){
			$cellBloc = Array();
			// Every row has the cells of the header: no more, no less
			foreach(array_keys($headText) as $nthCol){
				$cellBloc[] = Shape::makeBlockArray('cellText', $this->readSpansOfTxt(strval($cellText[$nthCol] ?? '')));
			}
			$rowsList[] = Shape::makeBlockArray(empty($rowsList) ? 'tableTop' : 'tableRow', Array(), $cellBloc);
			if( !isset($linesToParse[$linesIndxNum]) || trim($linesToParse[$linesIndxNum])==='' || $this->isStartOfBlock($linesToParse[$linesIndxNum], true) ){
				break;
			}
			$cellText = $this->splitCellArray($linesToParse[$linesIndxNum]);
			$linesIndxNum++;
		}
		return Shape::makeBlockArray('tableSet', Array(), $rowsList);
	}

	/**
	 * Joins the lines of a paragraph: a line end is a space, unless the
	 * line ends with two spaces or a backslash, which ask for a line break.
	 *
	 * @param Array $linesToParse The lines.
	 * @return String The text.
	 */
	protected function joinLinesAsTxt(Array $linesToParse): String {
		$fullText = '';
		$lastIndx = count($linesToParse)-1;
		foreach(array_values($linesToParse) as $nthLne=>$oneTxt){
			$breakLne = ($nthLne<$lastIndx && (substr($oneTxt, -2)==='  ' || (substr($oneTxt, -1)==='\\' && substr($oneTxt, -2)!=='\\\\')));
			$fullText .= trim(($breakLne && substr($oneTxt, -1)==='\\') ? substr($oneTxt, 0, -1) : $oneTxt).(($nthLne<$lastIndx) ? ($breakLne ? "\n" : ' ') : '');
		}
		return $fullText;
	}

	/**
	 * Reads the lines of a paragraph into blocks: the paragraph, cut in
	 * two around each image it holds.
	 *
	 * @param Array $linesToParse The lines.
	 * @return Array The blocks; none when there is no line.
	 */
	protected function readFlowBlocks(Array $linesToParse): Array {
		$blockLst = Array();
		$fullText = $this->joinLinesAsTxt($linesToParse);
		$imagePar = Array();
		$readIndx = 0;
		while( preg_match(self::IMGE_PATT, $fullText, $imagePar, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $readIndx) ){
			$spansLst = $this->readSpansOfTxt(substr($fullText, $readIndx, $imagePar[0][1]-$readIndx));
			if( !empty($spansLst) ){
				$blockLst[] = Shape::makeBlockArray('paragrph', $spansLst);
			}
			$imageLnk = Shape::wipeUnsafeAddr(trim(strval($imagePar['ls'][0] ?? $imagePar['is'][0]), '<>'));
			$imageTxt = trim(strval($imagePar['la'][0] ?? $imagePar['ia'][0]));
			if( $imageLnk!=='' ){
				$blockLst[] = Shape::makeBlockArray('imageBox', ($imageTxt!=='') ? Array(Shape::makeSpanOfText($imageTxt)) : Array(), Array(), Array('sourc'=>$imageLnk));
			}
			$readIndx = $imagePar[0][1]+strlen($imagePar[0][0]);
		}
		$spansLst = $this->readSpansOfTxt(strval(substr($fullText, $readIndx)));
		if( !empty($spansLst) ){
			$blockLst[] = Shape::makeBlockArray('paragrph', $spansLst);
		}
		return $blockLst;
	}

	/**
	 * Reads a text into tidied spans.
	 *
	 * @param String $templateText The text.
	 * @return Array The spans; none when there is no text.
	 */
	protected function readSpansOfTxt(String $templateText): Array {
		return Shape::joinSpansArray($this->readInlineSpns(trim($templateText), Array(), ''));
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
	protected function readInlineSpns(String $templateText, Array $runFormatSet, String $linkGoalText): Array {
		$escpPtrn = '/\\\\(?<esc>[!-\/:-@\[-`{-~])'
			.'|(?<!`)(?<tck>`++)(?<cod>.+?)(?<!`)\k<tck>(?!`)'
			.'|<(?<aut>https?:\/\/[^\s<>]+|mailto:[^\s<>]+|[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+)>'
			.'|!\[(?<alt>[^\]]*)\]\([ \t]*'.self::ADDR_PATT.self::TITL_PATT.'\)'
			.'|\[(?<ltx>(?:[^\[\]\\\\]|\\\\.|\[[^\[\]]*\])*)\]\([ \t]*(?<lnk>'.self::ADDR_PATT.')'.self::TITL_PATT.'\)'
			.'|(?<!\*)(?<stm>\*{1,3})(?![\s*])(?<stx>.+?)(?<![\s*])\k<stm>(?!\*)'
			.'|(?<![\p{L}\p{N}_])(?<unm>_{1,3})(?![\s_])(?<unx>.+?)(?<![\s_])\k<unm>(?![\p{L}\p{N}_])'
			.'|(?<!~)~~(?![\s~])(?<tld>.+?)(?<![\s~])~~(?!~)/us';
		$spansLst = Array();
		$readIndx = 0;
		$matxRows = Array();
		while( preg_match($escpPtrn, $templateText, $matxRows, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $readIndx) ){
			$spansLst[] = Shape::makeSpanOfText(html_entity_decode(substr($templateText, $readIndx, $matxRows[0][1]-$readIndx), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $runFormatSet, $linkGoalText);
			$readIndx = $matxRows[0][1]+strlen($matxRows[0][0]);
			if( $matxRows['esc'][0]!==null ){
				$spansLst[] = Shape::makeSpanOfText($matxRows['esc'][0], $runFormatSet, $linkGoalText);
			}elseif( $matxRows['cod'][0]!==null ){
				// Code is given as written; one space on each side of it is only there to hold a backtick
				$codeText = $matxRows['cod'][0];
				if( strlen($codeText)>2 && $codeText[0]==' ' && substr($codeText, -1)==' ' && trim($codeText)!=='' ){
					$codeText = substr($codeText, 1, -1);
				}
				$spansLst[] = Shape::makeSpanOfText($codeText, array_merge($runFormatSet, Array('codeSpan')), $linkGoalText);
			}elseif( $matxRows['aut'][0]!==null ){
				$autoAddr = $matxRows['aut'][0];
				$spansLst[] = Shape::makeSpanOfText($autoAddr, $runFormatSet, Shape::wipeUnsafeAddr((strpos($autoAddr, ':')===false) ? 'mailto:'.$autoAddr : $autoAddr));
			}elseif( $matxRows['alt'][0]!==null ){
				// An image where only text can stand, as in a heading or a cell: its description
				$spansLst[] = Shape::makeSpanOfText($matxRows['alt'][0], $runFormatSet, $linkGoalText);
			}elseif( $matxRows['ltx'][0]!==null ){
				$linkAddr = Shape::wipeUnsafeAddr(preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', trim(strval($matxRows['lnk'][0]), '<>')));
				$spansLst = array_merge($spansLst, $this->readInlineSpns($matxRows['ltx'][0], $runFormatSet, ($linkAddr!=='') ? $linkAddr : $linkGoalText));
			}elseif( $matxRows['tld'][0]!==null ){
				$spansLst = array_merge($spansLst, $this->readInlineSpns($matxRows['tld'][0], array_merge($runFormatSet, Array('crossOut')), $linkGoalText));
			}else{
				$markText = strval($matxRows['stm'][0] ?? $matxRows['unm'][0]);
				$spansLst = array_merge($spansLst, $this->readInlineSpns(strval($matxRows['stx'][0] ?? $matxRows['unx'][0]), array_merge($runFormatSet, self::MARK_LIST[strlen($markText)]), $linkGoalText));
			}
		}
		$spansLst[] = Shape::makeSpanOfText(html_entity_decode(strval(substr($templateText, $readIndx)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $runFormatSet, $linkGoalText);
		return $spansLst;
	}

}
