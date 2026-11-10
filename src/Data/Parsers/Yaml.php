<?php

namespace nyu\Data\Parsers;

/**
 * YAML, the part of it that settings files use: keys and values, lists,
 * nesting by indentation, comments, quoted and bare values, text over
 * several lines after | or >, and lists and maps written on one line
 * between brackets.
 *
 * What is outside that part is refused, with the line it stands on,
 * rather than read loosely: anchors and aliases (& and *), tags other
 * than the four plain casts (!!str, !!int, !!float, !!bool), tabulations
 * used to indent, brackets or quotes left open at the end of their line,
 * and keys that are not plain text.
 */
class Yaml extends Base {

	// A line holding a key: quoted with ", quoted with ', or bare; then what follows its colon
	const KEYS_PATT = '/^(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\'|((?:[^\s"\'\[\]{}#&*!|>%@`,?\-]|[\-?](?=\S))(?:(?![ \t]#).)*?))[ \t]*:(?:[ \t]+(.*))?$/Ds';
	// Characters written after a backslash, between double quotes
	const ESCP_LIST = Array('0'=>"\0", 'a'=>"\x07", 'b'=>"\x08", 't'=>"\t", "\t"=>"\t", 'n'=>"\n", 'v'=>"\x0B", 'f'=>"\x0C", 'r'=>"\r", 'e'=>"\x1B", ' '=>' ', '"'=>'"', '/'=>'/', '\\'=>'\\', 'N'=>"\u{85}", '_'=>"\u{A0}", 'L'=>"\u{2028}", 'P'=>"\u{2029}");

	// The lines of the document being read, each with its text and its number
	protected $inputLines = Array();
	// The line being read
	protected $lineCursor = 0;

	/**
	 * Parses YAML content. An empty content gives an empty tree.
	 *
	 * Options:
	 *   'upper' => upper-cases the keys of the first level;
	 *   'multi' => reads every document of the content, into a list;
	 *              only the first one is read when left out;
	 *   'depth' => deepest nesting accepted, 64 by default.
	 *
	 * @param String $nakedContent The YAML content.
	 * @param Array  $parseOptions Options: 'upper', 'multi', 'depth'.
	 * @return Array The envelope, of shape 'nodes'; 'infos' holds the number of documents the content has under 'docus'.
	 *
	 * @throws ParseError When the content is not valid, or uses a part of YAML that is not read.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$docList = $this->splitDocuments(str_replace(Array("\r\n", "\r"), "\n", self::recodeTextUtf8($nakedContent)));
		$maxDpth = max(1, intval($parseOptions['depth'] ?? 64));
		$vlsList = Array();
		foreach($docList as $oneDoc){
			$this->inputLines = $oneDoc;
			$this->lineCursor = 0;
			$docData = $this->readNodeAtLine(0, $maxDpth);
			$lftLine = $this->peekALineArray();
			if( $lftLine!==null ){
				$this->throwYamlError('unexpected content', $lftLine[2]);
			}
			$vlsList[] = (!empty($parseOptions['upper']) && is_array($docData)) ? $this->upperKeysArray($docData) : $docData;
			if( empty($parseOptions['multi']) ){
				break;
			}
		}
		$this->inputLines = Array();
		return $this->makeShapeArray('nodes', !empty($parseOptions['multi']) ? $vlsList : ($vlsList[0] ?? Array()), Array('docus'=>count($docList)));
	}

	/**
	 * Refuses the content, telling what is wrong and on which line.
	 *
	 * @param String $errorMessage What is wrong.
	 * @param Int    $sourceLineNb The line it stands on.
	 *
	 * @throws ParseError Always.
	 */
	protected function throwYamlError(String $errorMessage, Int $sourceLineNb): Void {
		throw new ParseError('Invalid YAML content: '.$errorMessage.' on line '.$sourceLineNb);
	}

	/**
	 * Cuts a content into its documents: a line of --- starts one, a line
	 * of ... ends one. A document holding nothing is left out.
	 *
	 * @param String $nakedContent The content, its lines ending with \n.
	 * @return Array The documents, each a list of lines with their text and their number.
	 *
	 * @throws ParseError When a value follows --- on its line.
	 */
	protected function splitDocuments(String $nakedContent): Array {
		$docList = Array();
		$docLnes = Array();
		$hasText = false;
		$linPart = Array();
		foreach(explode("\n", $nakedContent) as $linIdx=>$linTxt){
			$mrkLine = preg_match('/^(?:---|\.\.\.)(?:[ \t]+(.*))?$/D', $linTxt, $linPart);
			if( $mrkLine && $this->trimCommentTxt(strval($linPart[1] ?? ''))!=='' ){
				$this->throwYamlError('a value on the line of a document mark is not supported', $linIdx+1);
			}
			if( $mrkLine ){
				if( $hasText ){
					$docList[] = $docLnes;
				}
				$docLnes = Array();
				$hasText = false;
				continue;
			}
			// Before a document starts, a line of % only tells the version of YAML
			if( !$hasText && substr($linTxt, 0, 1)=='%' ){
				continue;
			}
			$docLnes[] = Array($linTxt, $linIdx+1);
			$hasText = ($hasText || (trim($linTxt)!=='' && ltrim($linTxt)[0]!='#'));
		}
		if( $hasText ){
			$docList[] = $docLnes;
		}
		return $docList;
	}

	/**
	 * Finds the next line holding something, past the empty lines and the
	 * comments, without reading it.
	 *
	 * @return ?Array Its indentation, its text without that indentation, and its number; null at the end of the document.
	 *
	 * @throws ParseError When the line is indented with a tabulation.
	 */
	protected function peekALineArray(): ?Array {
		while( isset($this->inputLines[$this->lineCursor]) ){
			$lineTxt = $this->inputLines[$this->lineCursor][0];
			$bareTxt = ltrim($lineTxt, ' ');
			if( trim($lineTxt)==='' || $bareTxt[0]=='#' ){
				$this->lineCursor++;
				continue;
			}
			if( $bareTxt[0]=="\t" ){
				$this->throwYamlError('tabulation used to indent', $this->inputLines[$this->lineCursor][1]);
			}
			return Array(strlen($lineTxt)-strlen($bareTxt), rtrim($bareTxt), $this->inputLines[$this->lineCursor][1]);
		}
		return null;
	}

	/**
	 * Takes the comment off the end of a line. A # starts a comment when
	 * it starts the line or follows a space, outside quotes.
	 *
	 * @param String $markdownLine The line, or what follows a key on it.
	 * @return String The line without its comment, nor the spaces around it.
	 */
	protected function trimCommentTxt(String $markdownLine): String {
		$quoteOn = '';
		$txtSize = strlen($markdownLine);
		for($posInc=0; $posInc<$txtSize; $posInc++){
			$oneChar = $markdownLine[$posInc];
			$prevChr = ($posInc>0) ? $markdownLine[$posInc-1] : ' ';
			if( $quoteOn!=='' ){
				// Inside quotes: a backslash protects the next character of a "text", '' is a quote of a 'text'
				if( $quoteOn=='"' && $oneChar=='\\' ){
					$posInc++;
				}elseif( $oneChar===$quoteOn ){
					$quoteOn = '';
				}
			}elseif( ($oneChar=='"' || $oneChar=="'") && strpos(" \t[{,:", $prevChr)!==false ){
				$quoteOn = $oneChar;
			}elseif( $oneChar=='#' && ($prevChr==' ' || $prevChr=="\t") ){
				return trim(substr($markdownLine, 0, $posInc));
			}
		}
		return trim($markdownLine);
	}

	/**
	 * Reads what stands at the next line: a list, a map, or a value.
	 *
	 * @param Int $minIndentLen Least indentation the line must have to belong here.
	 * @param Int $depthsLftNum Levels of nesting still accepted.
	 * @return mixed What was read; null when nothing belongs here.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readNodeAtLine(Int $minIndentLen, Int $depthsLftNum): Mixed {
		$nxtLine = $this->peekALineArray();
		if( $nxtLine===null || $nxtLine[0]<$minIndentLen ){
			return null;
		}
		$isSeqnc = (preg_match('/^-(?:[ \t]|$)/D', $nxtLine[1])===1);
		$isMppng = (!$isSeqnc && preg_match(self::KEYS_PATT, $nxtLine[1])===1);
		if( ($isSeqnc || $isMppng) && $depthsLftNum<1 ){
			$this->throwYamlError('nested deeper than allowed', $nxtLine[2]);
		}
		if( $isSeqnc ){
			return $this->obtainSequence($nxtLine[0], $depthsLftNum);
		}
		if( $isMppng ){
			return $this->readMappingArr($nxtLine[0], $depthsLftNum);
		}
		if( preg_match('/^\?(?:[ \t]|$)/D', $nxtLine[1]) ){
			$this->throwYamlError('a key that is not plain text is not supported', $nxtLine[2]);
		}
		$this->lineCursor++;
		return $this->parseLineValue($this->trimCommentTxt($nxtLine[1]), $minIndentLen-1, $nxtLine[2], $depthsLftNum);
	}

	/**
	 * Reads a list: the lines starting with a dash, at one indentation.
	 *
	 * @param Int $blckIndentNb Indentation of the list.
	 * @param Int $depthsLftNum Levels of nesting still accepted.
	 * @return Array The values of the list.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function obtainSequence(Int $blckIndentNb, Int $depthsLftNum): Array {
		$sqncVls = Array();
		$dshPart = Array();
		while( ($nxtLine = $this->peekALineArray())!==null && $nxtLine[0]==$blckIndentNb && preg_match('/^-(?:([ \t]+)(.*))?$/Ds', $nxtLine[1], $dshPart) ){
			if( $this->trimCommentTxt(strval($dshPart[2] ?? ''))==='' ){
				// Nothing after the dash: the value is what follows, further in
				$this->lineCursor++;
				$follows = $this->peekALineArray();
				$sqncVls[] = ($follows!==null && $follows[0]>$blckIndentNb) ? $this->readNodeAtLine($blckIndentNb+1, $depthsLftNum-1) : null;
				continue;
			}
			// The value starts on the line of the dash: the dash gives way to a space, and the line is read again
			$this->inputLines[$this->lineCursor][0] = str_repeat(' ', $blckIndentNb+1+strlen($dshPart[1])).$dshPart[2];
			$sqncVls[] = $this->readNodeAtLine($blckIndentNb+1, $depthsLftNum-1);
		}
		if( $nxtLine!==null && $nxtLine[0]>$blckIndentNb ){
			$this->throwYamlError('unexpected indentation', $nxtLine[2]);
		}
		return $sqncVls;
	}

	/**
	 * Reads a map: the lines holding a key, at one indentation.
	 *
	 * @param Int $blckIndentNb Indentation of the map.
	 * @param Int $depthsLftNum Levels of nesting still accepted.
	 * @return Array The values of the map, under their keys.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readMappingArr(Int $blckIndentNb, Int $depthsLftNum): Array {
		$mapping = Array();
		$keyPrts = Array();
		while( ($nxtLine = $this->peekALineArray())!==null && $nxtLine[0]==$blckIndentNb ){
			if( !preg_match(self::KEYS_PATT, $nxtLine[1], $keyPrts, PREG_UNMATCHED_AS_NULL) ){
				$this->throwYamlError('a key was expected', $nxtLine[2]);
			}
			if( $keyPrts[1]!==null ){
				$keyName = $this->unescapeDouble($keyPrts[1], $nxtLine[2]);
			}else{
				$keyName = ($keyPrts[2]!==null) ? str_replace("''", "'", $keyPrts[2]) : rtrim($keyPrts[3]);
			}
			if( $keyName==='<<' ){
				$this->throwYamlError('merging a map into another is not supported', $nxtLine[2]);
			}
			if( array_key_exists($keyName, $mapping) ){
				$this->throwYamlError('key "'.$keyName.'" met twice', $nxtLine[2]);
			}
			$this->lineCursor++;
			$valText = $this->trimCommentTxt(strval($keyPrts[4] ?? ''));
			if( $valText!=='' ){
				$mapping[$keyName] = $this->parseLineValue($valText, $blckIndentNb, $nxtLine[2], $depthsLftNum-1);
				continue;
			}
			// Nothing after the colon: the value is what follows, further in; a list may stand at the indentation of its key
			$follows = $this->peekALineArray();
			if( $follows!==null && $follows[0]>$blckIndentNb ){
				$mapping[$keyName] = $this->readNodeAtLine($blckIndentNb+1, $depthsLftNum-1);
			}elseif( $follows!==null && $follows[0]==$blckIndentNb && preg_match('/^-(?:[ \t]|$)/D', $follows[1]) ){
				if( $depthsLftNum<2 ){
					$this->throwYamlError('nested deeper than allowed', $follows[2]);
				}
				$mapping[$keyName] = $this->obtainSequence($blckIndentNb, $depthsLftNum-1);
			}else{
				$mapping[$keyName] = null;
			}
		}
		if( $nxtLine!==null && $nxtLine[0]>$blckIndentNb ){
			$this->throwYamlError('unexpected indentation', $nxtLine[2]);
		}
		return $mapping;
	}

	/**
	 * Reads a value written on a line: quoted or bare, between brackets,
	 * after a cast, or announcing a text over several lines.
	 *
	 * @param String $scalarString The value, without comment nor spaces around it.
	 * @param Int    $parentIndent Indentation of the key or of the dash it belongs to.
	 * @param Int    $sourceLineNb The line it stands on.
	 * @param Int    $depthsLftNum Levels of nesting still accepted.
	 * @return mixed The value.
	 *
	 * @throws ParseError When the value is not valid, or uses a part of YAML that is not read.
	 */
	protected function parseLineValue(String $scalarString, Int $parentIndent, Int $sourceLineNb, Int $depthsLftNum): Mixed {
		$leading = $scalarString[0] ?? '';
		$valPrts = Array();
		if( $leading=='&' || $leading=='*' ){
			$this->throwYamlError('anchors and aliases are not supported', $sourceLineNb);
		}
		if( $leading=='!' ){
			if( !preg_match('/^!!(str|int|float|bool)[ \t]+(\S.*)$/Ds', $scalarString, $valPrts) ){
				$this->throwYamlError('tag '.explode(' ', $scalarString)[0].' is not supported', $sourceLineNb);
			}
			return $this->parseValueText($valPrts[1], $valPrts[2], $sourceLineNb);
		}
		if( $leading=='|' || $leading=='>' ){
			return $this->readMultiLines($scalarString, $parentIndent, $sourceLineNb);
		}
		if( $leading=='[' || $leading=='{' ){
			$flwPstn = 0;
			$flowVal = $this->readFlowsArray($scalarString, $flwPstn, $sourceLineNb, $depthsLftNum);
			if( trim(substr($scalarString, $flwPstn))!=='' ){
				$this->throwYamlError('unexpected text after a closing bracket', $sourceLineNb);
			}
			return $flowVal;
		}
		if( $leading=='"' ){
			if( !preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/Ds', $scalarString, $valPrts) ){
				$this->throwYamlError('quoted text not closed on its line', $sourceLineNb);
			}
			return $this->unescapeDouble($valPrts[1], $sourceLineNb);
		}
		if( $leading=="'" ){
			if( !preg_match('/^\'((?:[^\']|\'\')*)\'$/D', $scalarString, $valPrts) ){
				$this->throwYamlError('quoted text not closed on its line', $sourceLineNb);
			}
			return str_replace("''", "'", $valPrts[1]);
		}
		// A bare value may carry on over the lines that follow it, further in than its key
		$moreLns = false;
		while( ($nxtLine = $this->peekALineArray())!==null && $nxtLine[0]>$parentIndent ){
			if( preg_match(self::KEYS_PATT, $nxtLine[1]) || preg_match('/^-(?:[ \t]|$)/D', $nxtLine[1]) ){
				$this->throwYamlError('unexpected indentation', $nxtLine[2]);
			}
			$scalarString .= ' '.$this->trimCommentTxt($nxtLine[1]);
			$moreLns = true;
			$this->lineCursor++;
		}
		return $moreLns ? $scalarString : $this->simpleCastText($scalarString);
	}

	/**
	 * Gives a bare value as what it writes: nothing, a boolean, a number,
	 * or its text. A number starting with a zero stays text.
	 *
	 * @param String $plainValText The value, as written.
	 * @return String|Int|Float|Bool|Null The value.
	 */
	protected function simpleCastText(String $plainValText): String|Int|Float|Bool|Null {
		if( in_array($plainValText, Array('', '~', 'null', 'Null', 'NULL'), true) ){
			return null;
		}
		if( in_array($plainValText, Array('true', 'True', 'TRUE', 'false', 'False', 'FALSE'), true) ){
			return (strtolower($plainValText)=='true');
		}
		if( preg_match('/^[\-+]?(0|[1-9]\d{0,17})$/D', $plainValText) ){
			return intval($plainValText);
		}
		if( preg_match('/^0x[0-9a-fA-F]{1,15}$/D', $plainValText) ){
			return intval(hexdec(substr($plainValText, 2)));
		}
		if( preg_match('/^0o[0-7]{1,20}$/D', $plainValText) ){
			return intval(octdec(substr($plainValText, 2)));
		}
		if( preg_match('/^[\-+]?(\.\d+|(0|[1-9]\d*)(\.\d*)?)([eE][\-+]?\d+)?$/D', $plainValText) && !preg_match('/^[\-+]?\d+$/D', $plainValText) ){
			return floatval($plainValText);
		}
		if( preg_match('/^([\-+]?)\.(inf|Inf|INF)$/D', $plainValText, $infPrts) ){
			return ($infPrts[1]=='-') ? -INF : INF;
		}
		if( in_array($plainValText, Array('.nan', '.NaN', '.NAN'), true) ){
			return NAN;
		}
		return $plainValText;
	}

	/**
	 * Reads a value written after one of the four plain casts.
	 *
	 * @param String $castTypeName The cast: str, int, float or bool.
	 * @param String $scalarString The value following it.
	 * @param Int    $sourceLineNb The line it stands on.
	 * @return String|Int|Float|Bool The value, of the type asked for.
	 *
	 * @throws ParseError When the value cannot be of that type.
	 */
	protected function parseValueText(String $castTypeName, String $scalarString, Int $sourceLineNb): String|Int|Float|Bool {
		$valPrts = Array();
		if( preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/Ds', $scalarString, $valPrts) ){
			$bareTxt = $this->unescapeDouble($valPrts[1], $sourceLineNb);
		}elseif( preg_match('/^\'((?:[^\']|\'\')*)\'$/D', $scalarString, $valPrts) ){
			$bareTxt = str_replace("''", "'", $valPrts[1]);
		}elseif( strpos('[{&*!|>"\'', $scalarString[0])!==false ){
			$this->throwYamlError('a cast applies to a single value', $sourceLineNb);
		}else{
			$bareTxt = $scalarString;
		}
		if( $castTypeName=='str' ){
			return $bareTxt;
		}
		if( $castTypeName=='int' && preg_match('/^[\-+]?\d{1,18}$/D', $bareTxt) ){
			return intval($bareTxt);
		}
		if( $castTypeName=='float' && is_numeric($bareTxt) ){
			return floatval($bareTxt);
		}
		if( $castTypeName=='bool' && in_array(strtolower($bareTxt), Array('true', 'false')) ){
			return (strtolower($bareTxt)=='true');
		}
		$this->throwYamlError('"'.$bareTxt.'" cannot be read as '.$castTypeName, $sourceLineNb);
	}

	/**
	 * Reads the characters written after a backslash, between double quotes.
	 *
	 * @param String $quotedString What stands between the quotes.
	 * @param Int    $sourceLineNb The line it stands on.
	 * @return String The text.
	 *
	 * @throws ParseError When a backslash is followed by something unknown.
	 */
	protected function unescapeDouble(String $quotedString, Int $sourceLineNb): String {
		return preg_replace_callback('/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s', function($locatedParts) use ($sourceLineNb){
			$escpTxt = $locatedParts[1];
			if( strlen($escpTxt)>1 && strpos('xuU', $escpTxt[0])!==false ){
				$charTxt = @iconv('UCS-4BE', 'UTF-8', pack('N', hexdec(substr($escpTxt, 1))));
				if( $charTxt===false ){
					$this->throwYamlError('\\'.$escpTxt.' is not a character', $sourceLineNb);
				}
				return $charTxt;
			}
			if( !isset(self::ESCP_LIST[$escpTxt]) ){
				$this->throwYamlError('\\'.$escpTxt.' is not a known escape', $sourceLineNb);
			}
			return self::ESCP_LIST[$escpTxt];
		}, $quotedString);
	}

	/**
	 * Reads a list or a map written between brackets, or one of the
	 * values it holds.
	 *
	 * @param String $streamString The text holding it.
	 * @param Int    $streamOffset Where it starts in that text; receives where it ends.
	 * @param Int    $sourceLineNb The line it stands on.
	 * @param Int    $depthsLftNum Levels of nesting still accepted.
	 * @return mixed The value.
	 *
	 * @throws ParseError When a bracket or a quote is left open, or the value uses a part of YAML that is not read.
	 */
	protected function readFlowsArray(String $streamString, Int &$streamOffset, Int $sourceLineNb, Int $depthsLftNum): Mixed {
		$streamOffset += strspn($streamString, " \t", $streamOffset);
		$leading = $streamString[$streamOffset] ?? '';
		$flwPrts = Array();
		if( $leading=='[' || $leading=='{' ){
			if( $depthsLftNum<1 ){
				$this->throwYamlError('nested deeper than allowed', $sourceLineNb);
			}
			$endChar = ($leading=='[') ? ']' : '}';
			$flowVal = Array();
			$streamOffset++;
			while( true ){
				$streamOffset += strspn($streamString, " \t", $streamOffset);
				if( ($streamString[$streamOffset] ?? '')===$endChar ){
					$streamOffset++;
					return $flowVal;
				}
				if( !isset($streamString[$streamOffset]) ){
					$this->throwYamlError('bracket not closed on its line', $sourceLineNb);
				}
				if( $leading=='[' ){
					$flowVal[] = $this->readFlowsArray($streamString, $streamOffset, $sourceLineNb, $depthsLftNum-1);
				}else{
					// A key: quoted, or bare up to its colon
					if( preg_match('/\G(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\'|([^,:{}\[\]"\'&*!]+))[ \t]*/s', $streamString, $flwPrts, PREG_UNMATCHED_AS_NULL, $streamOffset)!==1 ){
						$this->throwYamlError('a key was expected between braces', $sourceLineNb);
					}
					$streamOffset += strlen($flwPrts[0]);
					$keyName = ($flwPrts[1]!==null) ? $this->unescapeDouble($flwPrts[1], $sourceLineNb) : (($flwPrts[2]!==null) ? str_replace("''", "'", $flwPrts[2]) : trim($flwPrts[3]));
					if( array_key_exists($keyName, $flowVal) ){
						$this->throwYamlError('key "'.$keyName.'" met twice', $sourceLineNb);
					}
					$flowVal[$keyName] = null;
					if( ($streamString[$streamOffset] ?? '')==':' ){
						$streamOffset++;
						$streamOffset += strspn($streamString, " \t", $streamOffset);
						if( strpos(',}', $streamString[$streamOffset] ?? ' ')===false ){
							$flowVal[$keyName] = $this->readFlowsArray($streamString, $streamOffset, $sourceLineNb, $depthsLftNum-1);
						}
					}
				}
				$streamOffset += strspn($streamString, " \t", $streamOffset);
				$nextChr = $streamString[$streamOffset] ?? '';
				if( $nextChr==',' ){
					$streamOffset++;
				}elseif( $nextChr!==$endChar ){
					$this->throwYamlError('bracket not closed on its line', $sourceLineNb);
				}
			}
		}
		if( $leading=='"' || $leading=="'" ){
			if( preg_match('/\G(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\')/s', $streamString, $flwPrts, PREG_UNMATCHED_AS_NULL, $streamOffset)!==1 ){
				$this->throwYamlError('quoted text not closed on its line', $sourceLineNb);
			}
			$streamOffset += strlen($flwPrts[0]);
			return ($flwPrts[1]!==null) ? $this->unescapeDouble($flwPrts[1], $sourceLineNb) : str_replace("''", "'", $flwPrts[2]);
		}
		if( $leading=='&' || $leading=='*' ){
			$this->throwYamlError('anchors and aliases are not supported', $sourceLineNb);
		}
		if( $leading=='!' ){
			$this->throwYamlError('a tag between brackets is not supported', $sourceLineNb);
		}
		// A bare value: up to the comma or the bracket that follows it
		preg_match('/\G[^,\[\]{}]*/', $streamString, $flwPrts, 0, $streamOffset);
		$bareTxt = trim($flwPrts[0]);
		if( $bareTxt==='' ){
			$this->throwYamlError('a value was expected between brackets', $sourceLineNb);
		}
		if( preg_match('/:(?:[ \t]|$)/D', $bareTxt) ){
			$this->throwYamlError('a key and its value inside a list need their own braces', $sourceLineNb);
		}
		$streamOffset += strlen($flwPrts[0]);
		return $this->simpleCastText($bareTxt);
	}

	/**
	 * Reads a text written over several lines: kept line by line after |,
	 * its lines joined into paragraphs after >. A - after the mark takes
	 * the line end off the end of the text, a + keeps every one of them,
	 * and a digit tells how far in the text is written.
	 *
	 * @param String $textOfHeader The mark and what follows it: |, >, |-, >+, |2, ...
	 * @param Int    $parentIndent Indentation of the key or of the dash the text belongs to.
	 * @param Int    $sourceLineNb The line of the mark.
	 * @return String The text.
	 *
	 * @throws ParseError When the mark is followed by something unknown.
	 */
	protected function readMultiLines(String $textOfHeader, Int $parentIndent, Int $sourceLineNb): String {
		$hedPart = Array();
		if( !preg_match('/^([|>])(?:([+\-])([1-9])?|([1-9])([+\-])?)?$/D', $textOfHeader, $hedPart, PREG_UNMATCHED_AS_NULL) ){
			$this->throwYamlError('unknown mark "'.$textOfHeader.'" for a text over several lines', $sourceLineNb);
		}
		$chompAs = strval($hedPart[2] ?? $hedPart[5] ?? '');
		$digitTx = strval($hedPart[3] ?? $hedPart[4] ?? '');
		$textInd = ($digitTx!=='') ? max(0, $parentIndent)+intval($digitTx) : -1;
		$textLns = Array();
		while( isset($this->inputLines[$this->lineCursor]) ){
			$lineTxt = $this->inputLines[$this->lineCursor][0];
			if( trim($lineTxt)!=='' ){
				$lineInd = strspn($lineTxt, ' ');
				// The first line of the text tells how far in it is written
				if( $textInd<0 && $lineInd>$parentIndent ){
					$textInd = $lineInd;
				}
				if( $textInd<0 || $lineInd<$textInd ){
					break;
				}
			}
			$textLns[] = (trim($lineTxt)==='') ? '' : substr($lineTxt, $textInd);
			$this->lineCursor++;
		}
		// The empty lines ending the text only count when they are asked for
		$tailNum = 0;
		while( !empty($textLns) && end($textLns)==='' ){
			array_pop($textLns);
			$tailNum++;
		}
		if( empty($textLns) ){
			return ($chompAs=='+') ? str_repeat("\n", $tailNum) : '';
		}
		$fullTxt = '';
		if( $hedPart[1]=='|' ){
			$fullTxt = implode("\n", $textLns);
		}else{
			// Joined: a line end between two lines of text is a space, an empty line is a line end,
			// and a line written further in keeps the line ends around it
			$prevKnd = '';
			$blankNb = 0;
			foreach($textLns as $linTxt){
				if( $linTxt==='' ){
					$blankNb++;
					continue;
				}
				$linKind = ($linTxt[0]==' ' || $linTxt[0]=="\t") ? 'more' : 'text';
				if( $prevKnd!=='' ){
					$keepEnd = ($linKind=='more' || $prevKnd=='more');
					$fullTxt .= ($blankNb>0 || $keepEnd) ? str_repeat("\n", $blankNb+($keepEnd ? 1 : 0)) : ' ';
				}else{
					$fullTxt .= str_repeat("\n", $blankNb);
				}
				$fullTxt .= $linTxt;
				$prevKnd = $linKind;
				$blankNb = 0;
			}
		}
		if( $chompAs=='-' ){
			return $fullTxt;
		}
		return $fullTxt."\n".(($chompAs=='+') ? str_repeat("\n", $tailNum) : '');
	}

}
