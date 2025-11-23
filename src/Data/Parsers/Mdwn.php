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
	const MARKLIST = Array(1=>Array('ital'), 2=>Array('bold'), 3=>Array('bold', 'ital'));
	// A line that is a rule
	const RULEPATT = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/D';
	// The line under the names of the columns of a table
	const TBLEPATT = '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/D';
	// An address between the brackets of a link or of an image, and the title that may follow it
	const ADDRPATT = '(?:<[^>\n]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)';
	const TITLPATT = '(?:[ \t]+(?:"[^"]*"|\'[^\']*\'))?[ \t]*';
	// An image, alone or inside a link
	const IMGEPATT = '/\[!\[(?<la>[^\]]*)\]\([ \t]*(?<ls>'.self::ADDRPATT.')'.self::TITLPATT.'\)\]\([ \t]*'.self::ADDRPATT.self::TITLPATT.'\)|!\[(?<ia>[^\]]*)\]\([ \t]*(?<is>'.self::ADDRPATT.')'.self::TITLPATT.'\)/';

	/**
	 * Parses a Markdown text into blocks. An empty content gives no block.
	 *
	 * @param String $rawContent The Markdown text.
	 * @param Array  $parseOptns Options: none.
	 * @return Array The envelope, of shape 'block'; 'infos' holds the first heading of level 1 under 'title' and, when the text starts with a block of settings between two lines of ---, the values of that block, read as YAML, under 'front'.
	 *
	 * @throws ParseError When that block of settings is not valid.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		$fullTxt = str_replace(Array("\r\n", "\r"), "\n", self::cleanText($rawContent));
		$infoArr = Array('title'=>'', 'front'=>Array());
		$frntPrt = Array();
		if( preg_match('/^---[ \t]*\n((?=[\w\-]+[ \t]*:).*?)\n(?:---|\.\.\.)[ \t]*(?:\n|$)/s', $fullTxt, $frntPrt) ){
			// The settings are YAML; their lines are numbered as in the text, after the line of ---
			try{
				$frntVal = (new Yaml())->parseData("\n".$frntPrt[1])['items'];
			}catch( ParseError $e ){
				throw new ParseError(str_replace('Invalid YAML content', 'Invalid Markdown settings', $e->getMessage()), 0, $e);
			}
			$infoArr['front'] = is_array($frntVal) ? $frntVal : Array();
			$fullTxt = substr($fullTxt, strlen($frntPrt[0]));
		}
		// A tabulation starting a line stands for four spaces
		$fullTxt = preg_replace_callback('/^[ \t]*\t[ \t]*/m', function($foundParts){ return str_replace("\t", '    ', $foundParts[0]); }, $fullTxt);
		$blckLst = $this->readBlcks(explode("\n", $fullTxt));
		foreach($blckLst as $oneBlk){
			if( $oneBlk['block']=='headr' && $oneBlk['attrs']['level']==1 ){
				$infoArr['title'] = $oneBlk['plain'];
				break;
			}
		}
		return $this->makeShape('block', $blckLst, $infoArr);
	}

	/**
	 * Tells whether a line starts a block of its own: code between marks,
	 * a heading, a rule, a quote or a list.
	 *
	 * @param String $lineString The line.
	 * @param Bool   $insidePara Whether the line follows the lines of a paragraph, which only a list that clearly is one may cut.
	 * @return Bool True when it does.
	 */
	protected function startsBlk(String $lineString, Bool $insidePara): Bool {
		if( preg_match('/^ {0,3}(?:`{3,}[^`]*$|~{3,}|#{1,6}(?:[ \t]|$)|>)/D', $lineString) || preg_match(self::RULEPATT, $lineString) ){
			return true;
		}
		$listMrk = $this->markOfLst($lineString);
		if( $listMrk===null ){
			return false;
		}
		return (!$insidePara || ($listMrk['texts']!=='' && in_array($listMrk['numbr'], Array('', '1'), true)));
	}

	/**
	 * Reads the mark of a list at the start of a line.
	 *
	 * @param String $lineString The line.
	 * @return ?Array The mark: its kind under 'kinds' (-, *, +, . or )), its number under 'numbr' ('' for a list without numbers), the width its entry is indented by under 'indnt', and the text following it under 'texts'. Null when the line does not start an entry.
	 */
	protected function markOfLst(String $lineString): ?Array {
		$markPrt = Array();
		if( !preg_match('/^( {0,3})(?:([-*+])|(\d{1,9})([.)]))(?:([ \t]+)(.*))?$/D', $lineString, $markPrt) ){
			return null;
		}
		$markLen = strlen($markPrt[1])+strlen($markPrt[2] ?? '')+strlen($markPrt[3] ?? '')+strlen($markPrt[4] ?? '');
		$spceLen = strlen(str_replace("\t", ' ', strval($markPrt[5] ?? '')));
		$textStr = strval($markPrt[6] ?? '');
		// More than four spaces after the mark: the entry starts one space in, and holds indented code
		if( $spceLen>4 ){
			$textStr = str_repeat(' ', $spceLen-1).$textStr;
			$spceLen = 1;
		}
		return Array('kinds'=>($markPrt[2]!=='') ? $markPrt[2] : $markPrt[4], 'numbr'=>strval($markPrt[3] ?? ''), 'indnt'=>$markLen+(($textStr==='') ? 1 : $spceLen), 'texts'=>$textStr);
	}

	/**
	 * Reads lines into blocks.
	 *
	 * @param Array $linesArray The lines, without their line ends.
	 * @return Array The blocks.
	 */
	protected function readBlcks(Array $linesArray): Array {
		$blckLst = Array();
		$paraLns = Array();
		$linePrt = Array();
		$lineNbr = count($linesArray);
		$lineIdx = 0;
		while( $lineIdx<$lineNbr ){
			$lineTxt = $linesArray[$lineIdx];
			// A line of = or - under a paragraph makes a heading of it
			if( !empty($paraLns) && preg_match('/^ {0,3}(=+|-+)[ \t]*$/D', $lineTxt, $linePrt) ){
				$blckLst[] = $this->headBlock($this->joinLines($paraLns), ($linePrt[1][0]=='=') ? 1 : 2);
				$paraLns = Array();
				$lineIdx++;
				continue;
			}
			$tblStrt = (strpos($lineTxt, '|')!==false && isset($linesArray[$lineIdx+1]) && preg_match(self::TBLEPATT, $linesArray[$lineIdx+1]) && count($this->splitCell($lineTxt))==count($this->splitCell($linesArray[$lineIdx+1])));
			$codeInd = (empty($paraLns) && substr($lineTxt, 0, 4)==='    ');
			if( trim($lineTxt)!=='' && !$tblStrt && !$codeInd && !$this->startsBlk($lineTxt, !empty($paraLns)) ){
				$paraLns[] = $lineTxt;
				$lineIdx++;
				continue;
			}
			// Anything else ends the paragraph being gathered
			$blckLst = array_merge($blckLst, $this->flowBlcks($paraLns));
			$paraLns = Array();
			if( trim($lineTxt)==='' ){
				$lineIdx++;
			}elseif( $tblStrt ){
				$blckLst[] = $this->readTable($linesArray, $lineIdx);
			}elseif( $codeInd ){
				$codeLns = Array();
				while( $lineIdx<$lineNbr && (substr($linesArray[$lineIdx], 0, 4)==='    ' || trim($linesArray[$lineIdx])==='') ){
					$codeLns[] = strval(substr($linesArray[$lineIdx], 4));
					$lineIdx++;
				}
				$blckLst[] = Shape::makeBlock('codes', Array(Shape::makeASpan(rtrim(implode("\n", $codeLns)))));
			}elseif( preg_match('/^( {0,3})(`{3,}|~{3,})[ \t]*([^\s`]*)/', $lineTxt, $linePrt) ){
				$codeLns = Array();
				$lineIdx++;
				// The code runs to a line of the same marks, or to the end of the text
				while( $lineIdx<$lineNbr && !preg_match('/^ {0,3}'.preg_quote($linePrt[2][0], '/').'{'.strlen($linePrt[2]).',}[ \t]*$/D', $linesArray[$lineIdx]) ){
					$codeLns[] = preg_replace('/^ {0,'.strlen($linePrt[1]).'}/', '', $linesArray[$lineIdx]);
					$lineIdx++;
				}
				$lineIdx++;
				$blckLst[] = Shape::makeBlock('codes', Array(Shape::makeASpan(implode("\n", $codeLns))), Array(), preg_match('/^[\w+#.\-]+$/D', $linePrt[3]) ? Array('langg'=>$linePrt[3]) : Array());
			}elseif( preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/D', $lineTxt, $linePrt) ){
				$blckLst[] = $this->headBlock(strval($linePrt[2] ?? ''), strlen($linePrt[1]));
				$lineIdx++;
			}elseif( preg_match(self::RULEPATT, $lineTxt) ){
				$blckLst[] = Shape::makeBlock('rules');
				$lineIdx++;
			}elseif( preg_match('/^ {0,3}>/', $lineTxt) ){
				$heldLns = Array();
				// The quote runs over the lines starting with >, and the lines that carry on its last paragraph
				while( $lineIdx<$lineNbr && (preg_match('/^ {0,3}>/', $linesArray[$lineIdx]) || (trim($linesArray[$lineIdx])!=='' && trim(strval(end($heldLns)))!=='' && !$this->startsBlk($linesArray[$lineIdx], true))) ){
					$heldLns[] = preg_replace('/^ {0,3}> ?/', '', $linesArray[$lineIdx]);
					$lineIdx++;
				}
				$blckLst[] = $this->holdBlock('quote', $this->readBlcks($heldLns));
			}else{
				$blckLst[] = $this->readLists($linesArray, $lineIdx);
			}
		}
		return array_merge($blckLst, $this->flowBlcks($paraLns));
	}

	/**
	 * Builds a heading. An id written after its text as {#name} is the
	 * id of the block.
	 *
	 * @param String $textString The text of the heading.
	 * @param Int    $levelValue Its level, from 1 to 6.
	 * @return Array The block.
	 */
	protected function headBlock(String $textString, Int $levelValue): Array {
		$attrLst = Array('level'=>$levelValue);
		$idntPrt = Array();
		if( preg_match('/^(.*?)[ \t]*\{#([\w\-:.]+)\}$/Ds', trim($textString), $idntPrt) ){
			$textString = $idntPrt[1];
			$attrLst['tagid'] = $idntPrt[2];
		}
		return Shape::makeBlock('headr', $this->textSpans($textString), Array(), $attrLst);
	}

	/**
	 * Reads a list, from the line of its first entry.
	 *
	 * @param Array $linesArray The lines being read.
	 * @param Int   $linesIndex The line of the first entry; receives the line following the list.
	 * @return Array The list, as a block.
	 */
	protected function readLists(Array $linesArray, Int &$linesIndex): Array {
		$lineNbr = count($linesArray);
		$frstMrk = $this->markOfLst($linesArray[$linesIndex]);
		$entrLst = Array();
		while( $linesIndex<$lineNbr ){
			$itemMrk = $this->markOfLst($linesArray[$linesIndex]);
			$itemLns = Array($itemMrk['texts']);
			$linesIndex++;
			while( $linesIndex<$lineNbr ){
				$lineTxt = $linesArray[$linesIndex];
				$nextIdx = $linesIndex;
				while( $nextIdx<$lineNbr && trim($linesArray[$nextIdx])==='' ){
					$nextIdx++;
				}
				if( $nextIdx>$linesIndex ){
					// Blank lines belong to the entry when what follows them is indented as it is
					if( $nextIdx>=$lineNbr || strspn($linesArray[$nextIdx], ' ')<$itemMrk['indnt'] ){
						break;
					}
					$itemLns = array_merge($itemLns, array_fill(0, $nextIdx-$linesIndex, ''));
					$linesIndex = $nextIdx;
					continue;
				}
				if( strspn($lineTxt, ' ')>=$itemMrk['indnt'] ){
					$itemLns[] = substr($lineTxt, $itemMrk['indnt']);
				}elseif( trim(strval(end($itemLns)))!=='' && !$this->startsBlk($lineTxt, false) ){
					// A line that is not indented carries on the paragraph of the entry
					$itemLns[] = ltrim($lineTxt);
				}else{
					break;
				}
				$linesIndex++;
			}
			// A box at the start of an entry makes it a task, done or not
			$attrLst = Array();
			$taskPrt = Array();
			if( preg_match('/^\[([ xX])\][ \t]+(.*)$/Ds', $itemLns[0], $taskPrt) ){
				$attrLst['check'] = ($taskPrt[1]!==' ');
				$itemLns[0] = $taskPrt[2];
			}
			$entrLst[] = $this->holdBlock('entry', $this->readBlcks($itemLns), $attrLst);
			// The list carries on when its next entry follows, blank lines apart
			$nextIdx = $linesIndex;
			while( $nextIdx<$lineNbr && trim($linesArray[$nextIdx])==='' ){
				$nextIdx++;
			}
			$nextMrk = ($nextIdx<$lineNbr && !preg_match(self::RULEPATT, $linesArray[$nextIdx])) ? $this->markOfLst($linesArray[$nextIdx]) : null;
			if( $nextMrk===null || $nextMrk['kinds']!==$frstMrk['kinds'] ){
				break;
			}
			$linesIndex = $nextIdx;
		}
		return Shape::makeBlock('lists', Array(), $entrLst, Array('order'=>($frstMrk['numbr']!=='')));
	}

	/**
	 * Cuts a line of a table into its cells.
	 *
	 * @param String $lineString The line.
	 * @return Array The text of each cell.
	 */
	protected function splitCell(String $lineString): Array {
		$lineTxt = preg_replace('/^\||(?<!\\\\)\|$/', '', trim($lineString));
		$cellLst = Array();
		foreach(preg_split('/(?<!\\\\)\|/', $lineTxt) as $celTxt){
			$cellLst[] = trim(str_replace('\\|', '|', $celTxt));
		}
		return $cellLst;
	}

	/**
	 * Reads a table, from the line naming its columns.
	 *
	 * @param Array $linesArray The lines being read.
	 * @param Int   $linesIndex The line naming the columns; receives the line following the table.
	 * @return Array The table, as a block.
	 */
	protected function readTable(Array $linesArray, Int &$linesIndex): Array {
		$headTxt = $this->splitCell($linesArray[$linesIndex]);
		$rowsLst = Array();
		$linesIndex += 2;
		$cellTxt = $headTxt;
		while( true ){
			$cellBlk = Array();
			// Every row has the cells of the header: no more, no less
			foreach(array_keys($headTxt) as $colIdx){
				$cellBlk[] = Shape::makeBlock('tcell', $this->textSpans(strval($cellTxt[$colIdx] ?? '')));
			}
			$rowsLst[] = Shape::makeBlock(empty($rowsLst) ? 'thead' : 'tbrow', Array(), $cellBlk);
			if( !isset($linesArray[$linesIndex]) || trim($linesArray[$linesIndex])==='' || $this->startsBlk($linesArray[$linesIndex], true) ){
				break;
			}
			$cellTxt = $this->splitCell($linesArray[$linesIndex]);
			$linesIndex++;
		}
		return Shape::makeBlock('table', Array(), $rowsLst);
	}

	/**
	 * Joins the lines of a paragraph: a line end is a space, unless the
	 * line ends with two spaces or a backslash, which ask for a line break.
	 *
	 * @param Array $linesArray The lines.
	 * @return String The text.
	 */
	protected function joinLines(Array $linesArray): String {
		$fullTxt = '';
		$lastIdx = count($linesArray)-1;
		foreach(array_values($linesArray) as $lneIdx=>$lneTxt){
			$brkLine = ($lneIdx<$lastIdx && (substr($lneTxt, -2)==='  ' || (substr($lneTxt, -1)==='\\' && substr($lneTxt, -2)!=='\\\\')));
			$fullTxt .= trim(($brkLine && substr($lneTxt, -1)==='\\') ? substr($lneTxt, 0, -1) : $lneTxt).(($lneIdx<$lastIdx) ? ($brkLine ? "\n" : ' ') : '');
		}
		return $fullTxt;
	}

	/**
	 * Reads the lines of a paragraph into blocks: the paragraph, cut in
	 * two around each image it holds.
	 *
	 * @param Array $linesArray The lines.
	 * @return Array The blocks; none when there is no line.
	 */
	protected function flowBlcks(Array $linesArray): Array {
		$blckLst = Array();
		$fullTxt = $this->joinLines($linesArray);
		$imgPrts = Array();
		$curPosn = 0;
		while( preg_match(self::IMGEPATT, $fullTxt, $imgPrts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $curPosn) ){
			$spnsLst = $this->textSpans(substr($fullTxt, $curPosn, $imgPrts[0][1]-$curPosn));
			if( !empty($spnsLst) ){
				$blckLst[] = Shape::makeBlock('parag', $spnsLst);
			}
			$imgSrce = Shape::cleanLink(trim(strval($imgPrts['ls'][0] ?? $imgPrts['is'][0]), '<>'));
			$imgText = trim(strval($imgPrts['la'][0] ?? $imgPrts['ia'][0]));
			if( $imgSrce!=='' ){
				$blckLst[] = Shape::makeBlock('image', ($imgText!=='') ? Array(Shape::makeASpan($imgText)) : Array(), Array(), Array('sourc'=>$imgSrce));
			}
			$curPosn = $imgPrts[0][1]+strlen($imgPrts[0][0]);
		}
		$spnsLst = $this->textSpans(strval(substr($fullTxt, $curPosn)));
		if( !empty($spnsLst) ){
			$blckLst[] = Shape::makeBlock('parag', $spnsLst);
		}
		return $blckLst;
	}

	/**
	 * Reads a text into tidied spans.
	 *
	 * @param String $textString The text.
	 * @return Array The spans; none when there is no text.
	 */
	protected function textSpans(String $textString): Array {
		return Shape::joinSpans($this->inlnSpans(trim($textString), Array(), ''));
	}

	/**
	 * Reads the marks of a text into spans: code between backticks,
	 * links, and what is bold, italic or struck. A mark after a backslash
	 * is text, and so is a mark that is never closed.
	 *
	 * @param String $textString The text.
	 * @param Array  $marksArray Formatting given by the marks around it.
	 * @param String $linkTarget Address given by a link around it, '' when there is none.
	 * @return Array The spans.
	 */
	protected function inlnSpans(String $textString, Array $marksArray, String $linkTarget): Array {
		$inlnPtn = '/\\\\(?<esc>[!-\/:-@\[-`{-~])'
			.'|(?<!`)(?<tck>`++)(?<cod>.+?)(?<!`)\k<tck>(?!`)'
			.'|<(?<aut>https?:\/\/[^\s<>]+|mailto:[^\s<>]+|[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+)>'
			.'|!\[(?<alt>[^\]]*)\]\([ \t]*'.self::ADDRPATT.self::TITLPATT.'\)'
			.'|\[(?<ltx>(?:[^\[\]\\\\]|\\\\.|\[[^\[\]]*\])*)\]\([ \t]*(?<lnk>'.self::ADDRPATT.')'.self::TITLPATT.'\)'
			.'|(?<!\*)(?<stm>\*{1,3})(?![\s*])(?<stx>.+?)(?<![\s*])\k<stm>(?!\*)'
			.'|(?<![\p{L}\p{N}_])(?<unm>_{1,3})(?![\s_])(?<unx>.+?)(?<![\s_])\k<unm>(?![\p{L}\p{N}_])'
			.'|(?<!~)~~(?![\s~])(?<tld>.+?)(?<![\s~])~~(?!~)/us';
		$spnsLst = Array();
		$curPosn = 0;
		$fndPrts = Array();
		while( preg_match($inlnPtn, $textString, $fndPrts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $curPosn) ){
			$spnsLst[] = Shape::makeASpan(html_entity_decode(substr($textString, $curPosn, $fndPrts[0][1]-$curPosn), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $marksArray, $linkTarget);
			$curPosn = $fndPrts[0][1]+strlen($fndPrts[0][0]);
			if( $fndPrts['esc'][0]!==null ){
				$spnsLst[] = Shape::makeASpan($fndPrts['esc'][0], $marksArray, $linkTarget);
			}elseif( $fndPrts['cod'][0]!==null ){
				// Code is given as written; one space on each side of it is only there to hold a backtick
				$codeTxt = $fndPrts['cod'][0];
				if( strlen($codeTxt)>2 && $codeTxt[0]==' ' && substr($codeTxt, -1)==' ' && trim($codeTxt)!=='' ){
					$codeTxt = substr($codeTxt, 1, -1);
				}
				$spnsLst[] = Shape::makeASpan($codeTxt, array_merge($marksArray, Array('code')), $linkTarget);
			}elseif( $fndPrts['aut'][0]!==null ){
				$autoAdr = $fndPrts['aut'][0];
				$spnsLst[] = Shape::makeASpan($autoAdr, $marksArray, Shape::cleanLink((strpos($autoAdr, ':')===false) ? 'mailto:'.$autoAdr : $autoAdr));
			}elseif( $fndPrts['alt'][0]!==null ){
				// An image where only text can stand, as in a heading or a cell: its description
				$spnsLst[] = Shape::makeASpan($fndPrts['alt'][0], $marksArray, $linkTarget);
			}elseif( $fndPrts['ltx'][0]!==null ){
				$linkAdr = Shape::cleanLink(preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', trim(strval($fndPrts['lnk'][0]), '<>')));
				$spnsLst = array_merge($spnsLst, $this->inlnSpans($fndPrts['ltx'][0], $marksArray, ($linkAdr!=='') ? $linkAdr : $linkTarget));
			}elseif( $fndPrts['tld'][0]!==null ){
				$spnsLst = array_merge($spnsLst, $this->inlnSpans($fndPrts['tld'][0], array_merge($marksArray, Array('strk')), $linkTarget));
			}else{
				$markTxt = strval($fndPrts['stm'][0] ?? $fndPrts['unm'][0]);
				$spnsLst = array_merge($spnsLst, $this->inlnSpans(strval($fndPrts['stx'][0] ?? $fndPrts['unx'][0]), array_merge($marksArray, self::MARKLIST[strlen($markTxt)]), $linkTarget));
			}
		}
		$spnsLst[] = Shape::makeASpan(html_entity_decode(strval(substr($textString, $curPosn)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $marksArray, $linkTarget);
		return $spnsLst;
	}

}
