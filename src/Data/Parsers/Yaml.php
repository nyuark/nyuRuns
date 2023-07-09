<?php

namespace nyu\Data\Parsers;

// YAML, the part of it that settings files use: keys and values, lists,
// nesting by indentation, comments, quoted and bare values, text over
// several lines after | or >, and lists and maps written on one line
// between brackets.
//
// What is outside that part is refused, with the line it stands on,
// rather than read loosely: anchors and aliases (& and *), tags other
// than the four plain casts (!!str, !!int, !!float, !!bool), tabulations
// used to indent, brackets or quotes left open at the end of their line,
// and keys that are not plain text.
class Yaml extends Base {

	// A line holding a key: quoted with ", quoted with ', or bare; then what follows its colon
	const KEYSPATT = '/^(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\'|((?:[^\s"\'\[\]{}#&*!|>%@`,?\-]|[\-?](?=\S))(?:(?![ \t]#).)*?))[ \t]*:(?:[ \t]+(.*))?$/Ds';
	// Characters written after a backslash, between double quotes
	const ESCPLIST = Array('0'=>"\0", 'a'=>"\x07", 'b'=>"\x08", 't'=>"\t", "\t"=>"\t", 'n'=>"\n", 'v'=>"\x0B", 'f'=>"\x0C", 'r'=>"\r", 'e'=>"\x1B", ' '=>' ', '"'=>'"', '/'=>'/', '\\'=>'\\', 'N'=>"\u{85}", '_'=>"\u{A0}", 'L'=>"\u{2028}", 'P'=>"\u{2029}");

	// The lines of the document being read, each with its text and its number
	protected $lineList = Array();
	// The line being read
	protected $linePosn = 0;

	/**
	 * Parses YAML content. An empty content gives an empty tree.
	 *
	 * Options:
	 *   'upper' => upper-cases the keys of the first level;
	 *   'multi' => reads every document of the content, into a list;
	 *              only the first one is read when left out;
	 *   'depth' => deepest nesting accepted, 64 by default.
	 *
	 * @param String $rawContent The YAML content.
	 * @param Array  $parseOptns Options: 'upper', 'multi', 'depth'.
	 * @return Array The envelope, of shape 'nodes'; 'infos' holds the number of documents the content has under 'docus'.
	 *
	 * @throws ParseError When the content is not valid, or uses a part of YAML that is not read.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		$docsLst = $this->splitDocs(str_replace(Array("\r\n", "\r"), "\n", self::cleanText($rawContent)));
		$maxDpth = max(1, intval($parseOptns['depth'] ?? 64));
		$valsLst = Array();
		foreach($docsLst as $docLns){
			$this->lineList = $docLns;
			$this->linePosn = 0;
			$docVals = $this->readNodes(0, $maxDpth);
			$leftLne = $this->peekALine();
			if( $leftLne!==null ){
				$this->failParse('unexpected content', $leftLne[2]);
			}
			$valsLst[] = (!empty($parseOptns['upper']) && is_array($docVals)) ? $this->upperKeys($docVals) : $docVals;
			if( empty($parseOptns['multi']) ){
				break;
			}
		}
		$this->lineList = Array();
		return $this->makeShape('nodes', !empty($parseOptns['multi']) ? $valsLst : ($valsLst[0] ?? Array()), Array('docus'=>count($docsLst)));
	}

	/**
	 * Refuses the content.
	 *
	 * @param String $failReason What is wrong.
	 * @param Int    $lineNumber The line it stands on.
	 *
	 * @throws ParseError Always.
	 */
	protected function failParse(String $failReason, Int $lineNumber): Void {
		throw new ParseError('Invalid YAML content: '.$failReason.' on line '.$lineNumber);
	}

	/**
	 * Cuts a content into its documents: a line of --- starts one, a line
	 * of ... ends one. A document holding nothing is left out.
	 *
	 * @param String $rawContent The content, its lines ending with \n.
	 * @return Array The documents, each a list of lines with their text and their number.
	 *
	 * @throws ParseError When a value follows --- on its line.
	 */
	protected function splitDocs(String $rawContent): Array {
		$docsLst = Array();
		$docLnes = Array();
		$hasText = false;
		$linePrt = Array();
		foreach(explode("\n", $rawContent) as $lneIdx=>$lneTxt){
			$markLne = preg_match('/^(?:---|\.\.\.)(?:[ \t]+(.*))?$/D', $lneTxt, $linePrt);
			if( $markLne && $this->stripCmnt(strval($linePrt[1] ?? ''))!=='' ){
				$this->failParse('a value on the line of a document mark is not supported', $lneIdx+1);
			}
			if( $markLne ){
				if( $hasText ){
					$docsLst[] = $docLnes;
				}
				$docLnes = Array();
				$hasText = false;
				continue;
			}
			// Before a document starts, a line of % only tells the version of YAML
			if( !$hasText && substr($lneTxt, 0, 1)=='%' ){
				continue;
			}
			$docLnes[] = Array($lneTxt, $lneIdx+1);
			$hasText = ($hasText || (trim($lneTxt)!=='' && ltrim($lneTxt)[0]!='#'));
		}
		if( $hasText ){
			$docsLst[] = $docLnes;
		}
		return $docsLst;
	}

	/**
	 * Finds the next line holding something, past the empty lines and the
	 * comments, without reading it.
	 *
	 * @return ?Array Its indentation, its text without that indentation, and its number; null at the end of the document.
	 *
	 * @throws ParseError When the line is indented with a tabulation.
	 */
	protected function peekALine(): ?Array {
		while( isset($this->lineList[$this->linePosn]) ){
			$lineTxt = $this->lineList[$this->linePosn][0];
			$bareTxt = ltrim($lineTxt, ' ');
			if( trim($lineTxt)==='' || $bareTxt[0]=='#' ){
				$this->linePosn++;
				continue;
			}
			if( $bareTxt[0]=="\t" ){
				$this->failParse('tabulation used to indent', $this->lineList[$this->linePosn][1]);
			}
			return Array(strlen($lineTxt)-strlen($bareTxt), rtrim($bareTxt), $this->lineList[$this->linePosn][1]);
		}
		return null;
	}

	/**
	 * Takes the comment off the end of a line. A # starts a comment when
	 * it starts the line or follows a space, outside quotes.
	 *
	 * @param String $lineString The line, or what follows a key on it.
	 * @return String The line without its comment, nor the spaces around it.
	 */
	protected function stripCmnt(String $lineString): String {
		$quoteOn = '';
		$textLen = strlen($lineString);
		for($chrIdx=0; $chrIdx<$textLen; $chrIdx++){
			$oneChar = $lineString[$chrIdx];
			$prevChr = ($chrIdx>0) ? $lineString[$chrIdx-1] : ' ';
			if( $quoteOn!=='' ){
				// Inside quotes: a backslash protects the next character of a "text", '' is a quote of a 'text'
				if( $quoteOn=='"' && $oneChar=='\\' ){
					$chrIdx++;
				}elseif( $oneChar===$quoteOn ){
					$quoteOn = '';
				}
			}elseif( ($oneChar=='"' || $oneChar=="'") && strpos(" \t[{,:", $prevChr)!==false ){
				$quoteOn = $oneChar;
			}elseif( $oneChar=='#' && ($prevChr==' ' || $prevChr=="\t") ){
				return trim(substr($lineString, 0, $chrIdx));
			}
		}
		return trim($lineString);
	}

	/**
	 * Reads what stands at the next line: a list, a map, or a value.
	 *
	 * @param Int $leastIndnt Least indentation the line must have to belong here.
	 * @param Int $depthsLeft Levels of nesting still accepted.
	 * @return mixed What was read; null when nothing belongs here.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readNodes(Int $leastIndnt, Int $depthsLeft): Mixed {
		$nextLne = $this->peekALine();
		if( $nextLne===null || $nextLne[0]<$leastIndnt ){
			return null;
		}
		$isSeqnc = (preg_match('/^-(?:[ \t]|$)/D', $nextLne[1])===1);
		$isMappg = (!$isSeqnc && preg_match(self::KEYSPATT, $nextLne[1])===1);
		if( ($isSeqnc || $isMappg) && $depthsLeft<1 ){
			$this->failParse('nested deeper than allowed', $nextLne[2]);
		}
		if( $isSeqnc ){
			return $this->readSeqnc($nextLne[0], $depthsLeft);
		}
		if( $isMappg ){
			return $this->readMappg($nextLne[0], $depthsLeft);
		}
		if( preg_match('/^\?(?:[ \t]|$)/D', $nextLne[1]) ){
			$this->failParse('a key that is not plain text is not supported', $nextLne[2]);
		}
		$this->linePosn++;
		return $this->readValue($this->stripCmnt($nextLne[1]), $leastIndnt-1, $nextLne[2], $depthsLeft);
	}

	/**
	 * Reads a list: the lines starting with a dash, at one indentation.
	 *
	 * @param Int $blockIndnt Indentation of the list.
	 * @param Int $depthsLeft Levels of nesting still accepted.
	 * @return Array The values of the list.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readSeqnc(Int $blockIndnt, Int $depthsLeft): Array {
		$seqVals = Array();
		$dashPrt = Array();
		while( ($nextLne = $this->peekALine())!==null && $nextLne[0]==$blockIndnt && preg_match('/^-(?:([ \t]+)(.*))?$/Ds', $nextLne[1], $dashPrt) ){
			if( $this->stripCmnt(strval($dashPrt[2] ?? ''))==='' ){
				// Nothing after the dash: the value is what follows, further in
				$this->linePosn++;
				$follows = $this->peekALine();
				$seqVals[] = ($follows!==null && $follows[0]>$blockIndnt) ? $this->readNodes($blockIndnt+1, $depthsLeft-1) : null;
				continue;
			}
			// The value starts on the line of the dash: the dash gives way to a space, and the line is read again
			$this->lineList[$this->linePosn][0] = str_repeat(' ', $blockIndnt+1+strlen($dashPrt[1])).$dashPrt[2];
			$seqVals[] = $this->readNodes($blockIndnt+1, $depthsLeft-1);
		}
		if( $nextLne!==null && $nextLne[0]>$blockIndnt ){
			$this->failParse('unexpected indentation', $nextLne[2]);
		}
		return $seqVals;
	}

	/**
	 * Reads a map: the lines holding a key, at one indentation.
	 *
	 * @param Int $blockIndnt Indentation of the map.
	 * @param Int $depthsLeft Levels of nesting still accepted.
	 * @return Array The values of the map, under their keys.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readMappg(Int $blockIndnt, Int $depthsLeft): Array {
		$mapVals = Array();
		$keyPrts = Array();
		while( ($nextLne = $this->peekALine())!==null && $nextLne[0]==$blockIndnt ){
			if( !preg_match(self::KEYSPATT, $nextLne[1], $keyPrts, PREG_UNMATCHED_AS_NULL) ){
				$this->failParse('a key was expected', $nextLne[2]);
			}
			if( $keyPrts[1]!==null ){
				$keyName = $this->dblQuoted($keyPrts[1], $nextLne[2]);
			}else{
				$keyName = ($keyPrts[2]!==null) ? str_replace("''", "'", $keyPrts[2]) : rtrim($keyPrts[3]);
			}
			if( $keyName==='<<' ){
				$this->failParse('merging a map into another is not supported', $nextLne[2]);
			}
			if( array_key_exists($keyName, $mapVals) ){
				$this->failParse('key "'.$keyName.'" met twice', $nextLne[2]);
			}
			$this->linePosn++;
			$valText = $this->stripCmnt(strval($keyPrts[4] ?? ''));
			if( $valText!=='' ){
				$mapVals[$keyName] = $this->readValue($valText, $blockIndnt, $nextLne[2], $depthsLeft-1);
				continue;
			}
			// Nothing after the colon: the value is what follows, further in; a list may stand at the indentation of its key
			$follows = $this->peekALine();
			if( $follows!==null && $follows[0]>$blockIndnt ){
				$mapVals[$keyName] = $this->readNodes($blockIndnt+1, $depthsLeft-1);
			}elseif( $follows!==null && $follows[0]==$blockIndnt && preg_match('/^-(?:[ \t]|$)/D', $follows[1]) ){
				if( $depthsLeft<2 ){
					$this->failParse('nested deeper than allowed', $follows[2]);
				}
				$mapVals[$keyName] = $this->readSeqnc($blockIndnt, $depthsLeft-1);
			}else{
				$mapVals[$keyName] = null;
			}
		}
		if( $nextLne!==null && $nextLne[0]>$blockIndnt ){
			$this->failParse('unexpected indentation', $nextLne[2]);
		}
		return $mapVals;
	}

	/**
	 * Reads a value written on a line: quoted or bare, between brackets,
	 * after a cast, or announcing a text over several lines.
	 *
	 * @param String $scalarText The value, without comment nor spaces around it.
	 * @param Int    $ownerIndnt Indentation of the key or of the dash it belongs to.
	 * @param Int    $lineNumber The line it stands on.
	 * @param Int    $depthsLeft Levels of nesting still accepted.
	 * @return mixed The value.
	 *
	 * @throws ParseError When the value is not valid, or uses a part of YAML that is not read.
	 */
	protected function readValue(String $scalarText, Int $ownerIndnt, Int $lineNumber, Int $depthsLeft): Mixed {
		$frstChr = $scalarText[0] ?? '';
		$valPrts = Array();
		if( $frstChr=='&' || $frstChr=='*' ){
			$this->failParse('anchors and aliases are not supported', $lineNumber);
		}
		if( $frstChr=='!' ){
			if( !preg_match('/^!!(str|int|float|bool)[ \t]+(\S.*)$/Ds', $scalarText, $valPrts) ){
				$this->failParse('tag '.explode(' ', $scalarText)[0].' is not supported', $lineNumber);
			}
			return $this->castValue($valPrts[1], $valPrts[2], $lineNumber);
		}
		if( $frstChr=='|' || $frstChr=='>' ){
			return $this->blockText($scalarText, $ownerIndnt, $lineNumber);
		}
		if( $frstChr=='[' || $frstChr=='{' ){
			$flowPos = 0;
			$flowVal = $this->readFlows($scalarText, $flowPos, $lineNumber, $depthsLeft);
			if( trim(substr($scalarText, $flowPos))!=='' ){
				$this->failParse('unexpected text after a closing bracket', $lineNumber);
			}
			return $flowVal;
		}
		if( $frstChr=='"' ){
			if( !preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/Ds', $scalarText, $valPrts) ){
				$this->failParse('quoted text not closed on its line', $lineNumber);
			}
			return $this->dblQuoted($valPrts[1], $lineNumber);
		}
		if( $frstChr=="'" ){
			if( !preg_match('/^\'((?:[^\']|\'\')*)\'$/D', $scalarText, $valPrts) ){
				$this->failParse('quoted text not closed on its line', $lineNumber);
			}
			return str_replace("''", "'", $valPrts[1]);
		}
		// A bare value may carry on over the lines that follow it, further in than its key
		$moreLns = false;
		while( ($nextLne = $this->peekALine())!==null && $nextLne[0]>$ownerIndnt ){
			if( preg_match(self::KEYSPATT, $nextLne[1]) || preg_match('/^-(?:[ \t]|$)/D', $nextLne[1]) ){
				$this->failParse('unexpected indentation', $nextLne[2]);
			}
			$scalarText .= ' '.$this->stripCmnt($nextLne[1]);
			$moreLns = true;
			$this->linePosn++;
		}
		return $moreLns ? $scalarText : $this->plainCast($scalarText);
	}

	/**
	 * Gives a bare value as what it writes: nothing, a boolean, a number,
	 * or its text. A number starting with a zero stays text.
	 *
	 * @param String $plainValue The value, as written.
	 * @return String|Int|Float|Bool|Null The value.
	 */
	protected function plainCast(String $plainValue): String|Int|Float|Bool|Null {
		if( in_array($plainValue, Array('', '~', 'null', 'Null', 'NULL'), true) ){
			return null;
		}
		if( in_array($plainValue, Array('true', 'True', 'TRUE', 'false', 'False', 'FALSE'), true) ){
			return (strtolower($plainValue)=='true');
		}
		if( preg_match('/^[\-+]?(0|[1-9]\d{0,17})$/D', $plainValue) ){
			return intval($plainValue);
		}
		if( preg_match('/^0x[0-9a-fA-F]{1,15}$/D', $plainValue) ){
			return intval(hexdec(substr($plainValue, 2)));
		}
		if( preg_match('/^0o[0-7]{1,20}$/D', $plainValue) ){
			return intval(octdec(substr($plainValue, 2)));
		}
		if( preg_match('/^[\-+]?(\.\d+|(0|[1-9]\d*)(\.\d*)?)([eE][\-+]?\d+)?$/D', $plainValue) && !preg_match('/^[\-+]?\d+$/D', $plainValue) ){
			return floatval($plainValue);
		}
		if( preg_match('/^([\-+]?)\.(inf|Inf|INF)$/D', $plainValue, $infPrts) ){
			return ($infPrts[1]=='-') ? -INF : INF;
		}
		if( in_array($plainValue, Array('.nan', '.NaN', '.NAN'), true) ){
			return NAN;
		}
		return $plainValue;
	}

	/**
	 * Reads a value written after one of the four plain casts.
	 *
	 * @param String $castsLabel The cast: str, int, float or bool.
	 * @param String $scalarText The value following it.
	 * @param Int    $lineNumber The line it stands on.
	 * @return String|Int|Float|Bool The value, of the type asked for.
	 *
	 * @throws ParseError When the value cannot be of that type.
	 */
	protected function castValue(String $castsLabel, String $scalarText, Int $lineNumber): String|Int|Float|Bool {
		$valPrts = Array();
		if( preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/Ds', $scalarText, $valPrts) ){
			$bareTxt = $this->dblQuoted($valPrts[1], $lineNumber);
		}elseif( preg_match('/^\'((?:[^\']|\'\')*)\'$/D', $scalarText, $valPrts) ){
			$bareTxt = str_replace("''", "'", $valPrts[1]);
		}elseif( strpos('[{&*!|>"\'', $scalarText[0])!==false ){
			$this->failParse('a cast applies to a single value', $lineNumber);
		}else{
			$bareTxt = $scalarText;
		}
		if( $castsLabel=='str' ){
			return $bareTxt;
		}
		if( $castsLabel=='int' && preg_match('/^[\-+]?\d{1,18}$/D', $bareTxt) ){
			return intval($bareTxt);
		}
		if( $castsLabel=='float' && is_numeric($bareTxt) ){
			return floatval($bareTxt);
		}
		if( $castsLabel=='bool' && in_array(strtolower($bareTxt), Array('true', 'false')) ){
			return (strtolower($bareTxt)=='true');
		}
		$this->failParse('"'.$bareTxt.'" cannot be read as '.$castsLabel, $lineNumber);
	}

	/**
	 * Reads the characters written after a backslash, between double quotes.
	 *
	 * @param String $quotedText What stands between the quotes.
	 * @param Int    $lineNumber The line it stands on.
	 * @return String The text.
	 *
	 * @throws ParseError When a backslash is followed by something unknown.
	 */
	protected function dblQuoted(String $quotedText, Int $lineNumber): String {
		return preg_replace_callback('/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s', function($foundParts) use ($lineNumber){
			$escpTxt = $foundParts[1];
			if( strlen($escpTxt)>1 && strpos('xuU', $escpTxt[0])!==false ){
				$charTxt = @iconv('UCS-4BE', 'UTF-8', pack('N', hexdec(substr($escpTxt, 1))));
				if( $charTxt===false ){
					$this->failParse('\\'.$escpTxt.' is not a character', $lineNumber);
				}
				return $charTxt;
			}
			if( !isset(self::ESCPLIST[$escpTxt]) ){
				$this->failParse('\\'.$escpTxt.' is not a known escape', $lineNumber);
			}
			return self::ESCPLIST[$escpTxt];
		}, $quotedText);
	}

	/**
	 * Reads a list or a map written between brackets, or one of the
	 * values it holds.
	 *
	 * @param String $flowString The text holding it.
	 * @param Int    $flowOffset Where it starts in that text; receives where it ends.
	 * @param Int    $lineNumber The line it stands on.
	 * @param Int    $depthsLeft Levels of nesting still accepted.
	 * @return mixed The value.
	 *
	 * @throws ParseError When a bracket or a quote is left open, or the value uses a part of YAML that is not read.
	 */
	protected function readFlows(String $flowString, Int &$flowOffset, Int $lineNumber, Int $depthsLeft): Mixed {
		$flowOffset += strspn($flowString, " \t", $flowOffset);
		$frstChr = $flowString[$flowOffset] ?? '';
		$flwPrts = Array();
		if( $frstChr=='[' || $frstChr=='{' ){
			if( $depthsLeft<1 ){
				$this->failParse('nested deeper than allowed', $lineNumber);
			}
			$endChar = ($frstChr=='[') ? ']' : '}';
			$flowVal = Array();
			$flowOffset++;
			while( true ){
				$flowOffset += strspn($flowString, " \t", $flowOffset);
				if( ($flowString[$flowOffset] ?? '')===$endChar ){
					$flowOffset++;
					return $flowVal;
				}
				if( !isset($flowString[$flowOffset]) ){
					$this->failParse('bracket not closed on its line', $lineNumber);
				}
				if( $frstChr=='[' ){
					$flowVal[] = $this->readFlows($flowString, $flowOffset, $lineNumber, $depthsLeft-1);
				}else{
					// A key: quoted, or bare up to its colon
					if( preg_match('/\G(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\'|([^,:{}\[\]"\'&*!]+))[ \t]*/s', $flowString, $flwPrts, PREG_UNMATCHED_AS_NULL, $flowOffset)!==1 ){
						$this->failParse('a key was expected between braces', $lineNumber);
					}
					$flowOffset += strlen($flwPrts[0]);
					$keyName = ($flwPrts[1]!==null) ? $this->dblQuoted($flwPrts[1], $lineNumber) : (($flwPrts[2]!==null) ? str_replace("''", "'", $flwPrts[2]) : trim($flwPrts[3]));
					if( array_key_exists($keyName, $flowVal) ){
						$this->failParse('key "'.$keyName.'" met twice', $lineNumber);
					}
					$flowVal[$keyName] = null;
					if( ($flowString[$flowOffset] ?? '')==':' ){
						$flowOffset++;
						$flowOffset += strspn($flowString, " \t", $flowOffset);
						if( strpos(',}', $flowString[$flowOffset] ?? ' ')===false ){
							$flowVal[$keyName] = $this->readFlows($flowString, $flowOffset, $lineNumber, $depthsLeft-1);
						}
					}
				}
				$flowOffset += strspn($flowString, " \t", $flowOffset);
				$nextChr = $flowString[$flowOffset] ?? '';
				if( $nextChr==',' ){
					$flowOffset++;
				}elseif( $nextChr!==$endChar ){
					$this->failParse('bracket not closed on its line', $lineNumber);
				}
			}
		}
		if( $frstChr=='"' || $frstChr=="'" ){
			if( preg_match('/\G(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\')/s', $flowString, $flwPrts, PREG_UNMATCHED_AS_NULL, $flowOffset)!==1 ){
				$this->failParse('quoted text not closed on its line', $lineNumber);
			}
			$flowOffset += strlen($flwPrts[0]);
			return ($flwPrts[1]!==null) ? $this->dblQuoted($flwPrts[1], $lineNumber) : str_replace("''", "'", $flwPrts[2]);
		}
		if( $frstChr=='&' || $frstChr=='*' ){
			$this->failParse('anchors and aliases are not supported', $lineNumber);
		}
		if( $frstChr=='!' ){
			$this->failParse('a tag between brackets is not supported', $lineNumber);
		}
		// A bare value: up to the comma or the bracket that follows it
		preg_match('/\G[^,\[\]{}]*/', $flowString, $flwPrts, 0, $flowOffset);
		$bareTxt = trim($flwPrts[0]);
		if( $bareTxt==='' ){
			$this->failParse('a value was expected between brackets', $lineNumber);
		}
		if( preg_match('/:(?:[ \t]|$)/D', $bareTxt) ){
			$this->failParse('a key and its value inside a list need their own braces', $lineNumber);
		}
		$flowOffset += strlen($flwPrts[0]);
		return $this->plainCast($bareTxt);
	}

	/**
	 * Reads a text written over several lines: kept line by line after |,
	 * its lines joined into paragraphs after >. A - after the mark takes
	 * the line end off the end of the text, a + keeps every one of them,
	 * and a digit tells how far in the text is written.
	 *
	 * @param String $headerText The mark and what follows it: |, >, |-, >+, |2, ...
	 * @param Int    $ownerIndnt Indentation of the key or of the dash the text belongs to.
	 * @param Int    $lineNumber The line of the mark.
	 * @return String The text.
	 *
	 * @throws ParseError When the mark is followed by something unknown.
	 */
	protected function blockText(String $headerText, Int $ownerIndnt, Int $lineNumber): String {
		$headPrt = Array();
		if( !preg_match('/^([|>])(?:([+\-])([1-9])?|([1-9])([+\-])?)?$/D', $headerText, $headPrt, PREG_UNMATCHED_AS_NULL) ){
			$this->failParse('unknown mark "'.$headerText.'" for a text over several lines', $lineNumber);
		}
		$chompAs = strval($headPrt[2] ?? $headPrt[5] ?? '');
		$digitTx = strval($headPrt[3] ?? $headPrt[4] ?? '');
		$textInd = ($digitTx!=='') ? max(0, $ownerIndnt)+intval($digitTx) : -1;
		$textLns = Array();
		while( isset($this->lineList[$this->linePosn]) ){
			$lineTxt = $this->lineList[$this->linePosn][0];
			if( trim($lineTxt)!=='' ){
				$lineInd = strspn($lineTxt, ' ');
				// The first line of the text tells how far in it is written
				if( $textInd<0 && $lineInd>$ownerIndnt ){
					$textInd = $lineInd;
				}
				if( $textInd<0 || $lineInd<$textInd ){
					break;
				}
			}
			$textLns[] = (trim($lineTxt)==='') ? '' : substr($lineTxt, $textInd);
			$this->linePosn++;
		}
		// The empty lines ending the text only count when they are asked for
		$tailNbr = 0;
		while( !empty($textLns) && end($textLns)==='' ){
			array_pop($textLns);
			$tailNbr++;
		}
		if( empty($textLns) ){
			return ($chompAs=='+') ? str_repeat("\n", $tailNbr) : '';
		}
		$fullTxt = '';
		if( $headPrt[1]=='|' ){
			$fullTxt = implode("\n", $textLns);
		}else{
			// Joined: a line end between two lines of text is a space, an empty line is a line end,
			// and a line written further in keeps the line ends around it
			$prevKnd = '';
			$blankNb = 0;
			foreach($textLns as $lneTxt){
				if( $lneTxt==='' ){
					$blankNb++;
					continue;
				}
				$lineKnd = ($lneTxt[0]==' ' || $lneTxt[0]=="\t") ? 'more' : 'text';
				if( $prevKnd!=='' ){
					$keepEnd = ($lineKnd=='more' || $prevKnd=='more');
					$fullTxt .= ($blankNb>0 || $keepEnd) ? str_repeat("\n", $blankNb+($keepEnd ? 1 : 0)) : ' ';
				}else{
					$fullTxt .= str_repeat("\n", $blankNb);
				}
				$fullTxt .= $lneTxt;
				$prevKnd = $lineKnd;
				$blankNb = 0;
			}
		}
		if( $chompAs=='-' ){
			return $fullTxt;
		}
		return $fullTxt."\n".(($chompAs=='+') ? str_repeat("\n", $tailNbr) : '');
	}

}
