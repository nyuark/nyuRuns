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
		$docuList = $this->splitDocuments(str_replace(Array("\r\n", "\r"), "\n", self::castTextToUtf8($nakedContent)));
		$maxDepth = max(1, intval($parseOptions['depth'] ?? 64));
		$valsList = Array();
		foreach($docuList as $oneDoc){
			$this->inputLines = $oneDoc;
			$this->lineCursor = 0;
			$docuDict = $this->readNodeAtLine(0, $maxDepth);
			$leftLine = $this->findLineToRead();
			if( $leftLine!==null ){
				$this->stopYamlAtLine('unexpected content', $leftLine[2]);
			}
			$valsList[] = (!empty($parseOptions['upper']) && is_array($docuDict)) ? $this->castKeysInCaps($docuDict) : $docuDict;
			if( empty($parseOptions['multi']) ){
				break;
			}
		}
		$this->inputLines = Array();
		return $this->makeShapeArray('nodes', !empty($parseOptions['multi']) ? $valsList : ($valsList[0] ?? Array()), Array('docus'=>count($docuList)));
	}

	/**
	 * Refuses the content, telling what is wrong and on which line.
	 *
	 * @param String $errorMessage What is wrong.
	 * @param Int    $sourceLneNum The line it stands on.
	 *
	 * @throws ParseError Always.
	 */
	protected function stopYamlAtLine(String $errorMessage, Int $sourceLneNum): Void {
		throw new ParseError('Invalid YAML content: '.$errorMessage.' on line '.$sourceLneNum);
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
		$docuList = Array();
		$docuLnes = Array();
		$hasStrng = false;
		$linePart = Array();
		foreach(explode("\n", $nakedContent) as $linIdx=>$linTxt){
			$markLine = preg_match('/^(?:---|\.\.\.)(?:[ \t]+(.*))?$/D', $linTxt, $linePart);
			if( $markLine && $this->wipeCommentTxt(strval($linePart[1] ?? ''))!=='' ){
				$this->stopYamlAtLine('a value on the line of a document mark is not supported', $linIdx+1);
			}
			if( $markLine ){
				if( $hasStrng ){
					$docuList[] = $docuLnes;
				}
				$docuLnes = Array();
				$hasStrng = false;
				continue;
			}
			// Before a document starts, a line of % only tells the version of YAML
			if( !$hasStrng && substr($linTxt, 0, 1)=='%' ){
				continue;
			}
			$docuLnes[] = Array($linTxt, $linIdx+1);
			$hasStrng = ($hasStrng || (trim($linTxt)!=='' && ltrim($linTxt)[0]!='#'));
		}
		if( $hasStrng ){
			$docuList[] = $docuLnes;
		}
		return $docuList;
	}

	/**
	 * Finds the next line holding something, past the empty lines and the
	 * comments, without reading it.
	 *
	 * @return ?Array Its indentation, its text without that indentation, and its number; null at the end of the document.
	 *
	 * @throws ParseError When the line is indented with a tabulation.
	 */
	protected function findLineToRead(): ?Array {
		while( isset($this->inputLines[$this->lineCursor]) ){
			$lineText = $this->inputLines[$this->lineCursor][0];
			$bareText = ltrim($lineText, ' ');
			if( trim($lineText)==='' || $bareText[0]=='#' ){
				$this->lineCursor++;
				continue;
			}
			if( $bareText[0]=="\t" ){
				$this->stopYamlAtLine('tabulation used to indent', $this->inputLines[$this->lineCursor][1]);
			}
			return Array(strlen($lineText)-strlen($bareText), rtrim($bareText), $this->inputLines[$this->lineCursor][1]);
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
	protected function wipeCommentTxt(String $markdownLine): String {
		$quotChar = '';
		$textSize = strlen($markdownLine);
		for($posInc=0; $posInc<$textSize; $posInc++){
			$oneChrct = $markdownLine[$posInc];
			$prevChar = ($posInc>0) ? $markdownLine[$posInc-1] : ' ';
			if( $quotChar!=='' ){
				// Inside quotes: a backslash protects the next character of a "text", '' is a quote of a 'text'
				if( $quotChar=='"' && $oneChrct=='\\' ){
					$posInc++;
				}elseif( $oneChrct===$quotChar ){
					$quotChar = '';
				}
			}elseif( ($oneChrct=='"' || $oneChrct=="'") && strpos(" \t[{,:", $prevChar)!==false ){
				$quotChar = $oneChrct;
			}elseif( $oneChrct=='#' && ($prevChar==' ' || $prevChar=="\t") ){
				return trim(substr($markdownLine, 0, $posInc));
			}
		}
		return trim($markdownLine);
	}

	/**
	 * Reads what stands at the next line: a list, a map, or a value.
	 *
	 * @param Int $leastIndtSze Least indentation the line must have to belong here.
	 * @param Int $depthsLftNum Levels of nesting still accepted.
	 * @return mixed What was read; null when nothing belongs here.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readNodeAtLine(Int $leastIndtSze, Int $depthsLftNum): Mixed {
		$nextLine = $this->findLineToRead();
		if( $nextLine===null || $nextLine[0]<$leastIndtSze ){
			return null;
		}
		$isDashed = (preg_match('/^-(?:[ \t]|$)/D', $nextLine[1])===1);
		$isKeyRow = (!$isDashed && preg_match(self::KEYS_PATT, $nextLine[1])===1);
		if( ($isDashed || $isKeyRow) && $depthsLftNum<1 ){
			$this->stopYamlAtLine('nested deeper than allowed', $nextLine[2]);
		}
		if( $isDashed ){
			return $this->readDashedList($nextLine[0], $depthsLftNum);
		}
		if( $isKeyRow ){
			return $this->readMappingDct($nextLine[0], $depthsLftNum);
		}
		if( preg_match('/^\?(?:[ \t]|$)/D', $nextLine[1]) ){
			$this->stopYamlAtLine('a key that is not plain text is not supported', $nextLine[2]);
		}
		$this->lineCursor++;
		return $this->parseLineValue($this->wipeCommentTxt($nextLine[1]), $leastIndtSze-1, $nextLine[2], $depthsLftNum);
	}

	/**
	 * Reads a list: the lines starting with a dash, at one indentation.
	 *
	 * @param Int $blockIndtSze Indentation of the list.
	 * @param Int $depthsLftNum Levels of nesting still accepted.
	 * @return Array The values of the list.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readDashedList(Int $blockIndtSze, Int $depthsLftNum): Array {
		$sqncVals = Array();
		$dashPart = Array();
		while( ($nextLine = $this->findLineToRead())!==null && $nextLine[0]==$blockIndtSze && preg_match('/^-(?:([ \t]+)(.*))?$/Ds', $nextLine[1], $dashPart) ){
			if( $this->wipeCommentTxt(strval($dashPart[2] ?? ''))==='' ){
				// Nothing after the dash: the value is what follows, further in
				$this->lineCursor++;
				$peekLine = $this->findLineToRead();
				$sqncVals[] = ($peekLine!==null && $peekLine[0]>$blockIndtSze) ? $this->readNodeAtLine($blockIndtSze+1, $depthsLftNum-1) : null;
				continue;
			}
			// The value starts on the line of the dash: the dash gives way to a space, and the line is read again
			$this->inputLines[$this->lineCursor][0] = str_repeat(' ', $blockIndtSze+1+strlen($dashPart[1])).$dashPart[2];
			$sqncVals[] = $this->readNodeAtLine($blockIndtSze+1, $depthsLftNum-1);
		}
		if( $nextLine!==null && $nextLine[0]>$blockIndtSze ){
			$this->stopYamlAtLine('unexpected indentation', $nextLine[2]);
		}
		return $sqncVals;
	}

	/**
	 * Reads a map: the lines holding a key, at one indentation.
	 *
	 * @param Int $blockIndtSze Indentation of the map.
	 * @param Int $depthsLftNum Levels of nesting still accepted.
	 * @return Array The values of the map, under their keys.
	 *
	 * @throws ParseError When the content is not valid.
	 */
	protected function readMappingDct(Int $blockIndtSze, Int $depthsLftNum): Array {
		$mapsList = Array();
		$keyParte = Array();
		while( ($nextLine = $this->findLineToRead())!==null && $nextLine[0]==$blockIndtSze ){
			if( !preg_match(self::KEYS_PATT, $nextLine[1], $keyParte, PREG_UNMATCHED_AS_NULL) ){
				$this->stopYamlAtLine('a key was expected', $nextLine[2]);
			}
			if( $keyParte[1]!==null ){
				$keyTitle = $this->unescapeDouble($keyParte[1], $nextLine[2]);
			}else{
				$keyTitle = ($keyParte[2]!==null) ? str_replace("''", "'", $keyParte[2]) : rtrim($keyParte[3]);
			}
			if( $keyTitle==='<<' ){
				$this->stopYamlAtLine('merging a map into another is not supported', $nextLine[2]);
			}
			if( array_key_exists($keyTitle, $mapsList) ){
				$this->stopYamlAtLine('key "'.$keyTitle.'" met twice', $nextLine[2]);
			}
			$this->lineCursor++;
			$valueTxt = $this->wipeCommentTxt(strval($keyParte[4] ?? ''));
			if( $valueTxt!=='' ){
				$mapsList[$keyTitle] = $this->parseLineValue($valueTxt, $blockIndtSze, $nextLine[2], $depthsLftNum-1);
				continue;
			}
			// Nothing after the colon: the value is what follows, further in; a list may stand at the indentation of its key
			$peekLine = $this->findLineToRead();
			if( $peekLine!==null && $peekLine[0]>$blockIndtSze ){
				$mapsList[$keyTitle] = $this->readNodeAtLine($blockIndtSze+1, $depthsLftNum-1);
			}elseif( $peekLine!==null && $peekLine[0]==$blockIndtSze && preg_match('/^-(?:[ \t]|$)/D', $peekLine[1]) ){
				if( $depthsLftNum<2 ){
					$this->stopYamlAtLine('nested deeper than allowed', $peekLine[2]);
				}
				$mapsList[$keyTitle] = $this->readDashedList($blockIndtSze, $depthsLftNum-1);
			}else{
				$mapsList[$keyTitle] = null;
			}
		}
		if( $nextLine!==null && $nextLine[0]>$blockIndtSze ){
			$this->stopYamlAtLine('unexpected indentation', $nextLine[2]);
		}
		return $mapsList;
	}

	/**
	 * Reads a value written on a line: quoted or bare, between brackets,
	 * after a cast, or announcing a text over several lines.
	 *
	 * @param String $scalarString The value, without comment nor spaces around it.
	 * @param Int    $parentIndent Indentation of the key or of the dash it belongs to.
	 * @param Int    $sourceLneNum The line it stands on.
	 * @param Int    $depthsLftNum Levels of nesting still accepted.
	 * @return mixed The value.
	 *
	 * @throws ParseError When the value is not valid, or uses a part of YAML that is not read.
	 */
	protected function parseLineValue(String $scalarString, Int $parentIndent, Int $sourceLneNum, Int $depthsLftNum): Mixed {
		$frstChar = $scalarString[0] ?? '';
		$valuePar = Array();
		if( $frstChar=='&' || $frstChar=='*' ){
			$this->stopYamlAtLine('anchors and aliases are not supported', $sourceLneNum);
		}
		if( $frstChar=='!' ){
			if( !preg_match('/^!!(str|int|float|bool)[ \t]+(\S.*)$/Ds', $scalarString, $valuePar) ){
				$this->stopYamlAtLine('tag '.explode(' ', $scalarString)[0].' is not supported', $sourceLneNum);
			}
			return $this->parseValueText($valuePar[1], $valuePar[2], $sourceLneNum);
		}
		if( $frstChar=='|' || $frstChar=='>' ){
			return $this->readMultiLines($scalarString, $parentIndent, $sourceLneNum);
		}
		if( $frstChar=='[' || $frstChar=='{' ){
			$parsePos = 0;
			$flowVals = $this->readFlowsArray($scalarString, $parsePos, $sourceLneNum, $depthsLftNum);
			if( trim(substr($scalarString, $parsePos))!=='' ){
				$this->stopYamlAtLine('unexpected text after a closing bracket', $sourceLneNum);
			}
			return $flowVals;
		}
		if( $frstChar=='"' ){
			if( !preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/Ds', $scalarString, $valuePar) ){
				$this->stopYamlAtLine('quoted text not closed on its line', $sourceLneNum);
			}
			return $this->unescapeDouble($valuePar[1], $sourceLneNum);
		}
		if( $frstChar=="'" ){
			if( !preg_match('/^\'((?:[^\']|\'\')*)\'$/D', $scalarString, $valuePar) ){
				$this->stopYamlAtLine('quoted text not closed on its line', $sourceLneNum);
			}
			return str_replace("''", "'", $valuePar[1]);
		}
		// A bare value may carry on over the lines that follow it, further in than its key
		$moreLnes = false;
		while( ($nextLine = $this->findLineToRead())!==null && $nextLine[0]>$parentIndent ){
			if( preg_match(self::KEYS_PATT, $nextLine[1]) || preg_match('/^-(?:[ \t]|$)/D', $nextLine[1]) ){
				$this->stopYamlAtLine('unexpected indentation', $nextLine[2]);
			}
			$scalarString .= ' '.$this->wipeCommentTxt($nextLine[1]);
			$moreLnes = true;
			$this->lineCursor++;
		}
		return $moreLnes ? $scalarString : $this->castYamlScalar($scalarString);
	}

	/**
	 * Gives a bare value as what it writes: nothing, a boolean, a number,
	 * or its text. A number starting with a zero stays text.
	 *
	 * @param String $plainValText The value, as written.
	 * @return String|Int|Float|Bool|Null The value.
	 */
	protected function castYamlScalar(String $plainValText): String|Int|Float|Bool|Null {
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
		if( preg_match('/^([\-+]?)\.(inf|Inf|INF)$/D', $plainValText, $infoPart) ){
			return ($infoPart[1]=='-') ? -INF : INF;
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
	 * @param Int    $sourceLneNum The line it stands on.
	 * @return String|Int|Float|Bool The value, of the type asked for.
	 *
	 * @throws ParseError When the value cannot be of that type.
	 */
	protected function parseValueText(String $castTypeName, String $scalarString, Int $sourceLneNum): String|Int|Float|Bool {
		$valuePar = Array();
		if( preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/Ds', $scalarString, $valuePar) ){
			$bareText = $this->unescapeDouble($valuePar[1], $sourceLneNum);
		}elseif( preg_match('/^\'((?:[^\']|\'\')*)\'$/D', $scalarString, $valuePar) ){
			$bareText = str_replace("''", "'", $valuePar[1]);
		}elseif( strpos('[{&*!|>"\'', $scalarString[0])!==false ){
			$this->stopYamlAtLine('a cast applies to a single value', $sourceLneNum);
		}else{
			$bareText = $scalarString;
		}
		if( $castTypeName=='str' ){
			return $bareText;
		}
		if( $castTypeName=='int' && preg_match('/^[\-+]?\d{1,18}$/D', $bareText) ){
			return intval($bareText);
		}
		if( $castTypeName=='float' && is_numeric($bareText) ){
			return floatval($bareText);
		}
		if( $castTypeName=='bool' && in_array(strtolower($bareText), Array('true', 'false')) ){
			return (strtolower($bareText)=='true');
		}
		$this->stopYamlAtLine('"'.$bareText.'" cannot be read as '.$castTypeName, $sourceLneNum);
	}

	/**
	 * Reads the characters written after a backslash, between double quotes.
	 *
	 * @param String $quotedString What stands between the quotes.
	 * @param Int    $sourceLneNum The line it stands on.
	 * @return String The text.
	 *
	 * @throws ParseError When a backslash is followed by something unknown.
	 */
	protected function unescapeDouble(String $quotedString, Int $sourceLneNum): String {
		return preg_replace_callback('/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s', function($locatedParts) use ($sourceLneNum){
			$escpText = $locatedParts[1];
			if( strlen($escpText)>1 && strpos('xuU', $escpText[0])!==false ){
				$charText = @iconv('UCS-4BE', 'UTF-8', pack('N', hexdec(substr($escpText, 1))));
				if( $charText===false ){
					$this->stopYamlAtLine('\\'.$escpText.' is not a character', $sourceLneNum);
				}
				return $charText;
			}
			if( !isset(self::ESCP_LIST[$escpText]) ){
				$this->stopYamlAtLine('\\'.$escpText.' is not a known escape', $sourceLneNum);
			}
			return self::ESCP_LIST[$escpText];
		}, $quotedString);
	}

	/**
	 * Reads a list or a map written between brackets, or one of the
	 * values it holds.
	 *
	 * @param String $streamString The text holding it.
	 * @param Int    $streamOffset Where it starts in that text; receives where it ends.
	 * @param Int    $sourceLneNum The line it stands on.
	 * @param Int    $depthsLftNum Levels of nesting still accepted.
	 * @return mixed The value.
	 *
	 * @throws ParseError When a bracket or a quote is left open, or the value uses a part of YAML that is not read.
	 */
	protected function readFlowsArray(String $streamString, Int &$streamOffset, Int $sourceLneNum, Int $depthsLftNum): Mixed {
		$streamOffset += strspn($streamString, " \t", $streamOffset);
		$frstChar = $streamString[$streamOffset] ?? '';
		$flowPart = Array();
		if( $frstChar=='[' || $frstChar=='{' ){
			if( $depthsLftNum<1 ){
				$this->stopYamlAtLine('nested deeper than allowed', $sourceLneNum);
			}
			$endChrct = ($frstChar=='[') ? ']' : '}';
			$flowVals = Array();
			$streamOffset++;
			while( true ){
				$streamOffset += strspn($streamString, " \t", $streamOffset);
				if( ($streamString[$streamOffset] ?? '')===$endChrct ){
					$streamOffset++;
					return $flowVals;
				}
				if( !isset($streamString[$streamOffset]) ){
					$this->stopYamlAtLine('bracket not closed on its line', $sourceLneNum);
				}
				if( $frstChar=='[' ){
					$flowVals[] = $this->readFlowsArray($streamString, $streamOffset, $sourceLneNum, $depthsLftNum-1);
				}else{
					// A key: quoted, or bare up to its colon
					if( preg_match('/\G(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\'|([^,:{}\[\]"\'&*!]+))[ \t]*/s', $streamString, $flowPart, PREG_UNMATCHED_AS_NULL, $streamOffset)!==1 ){
						$this->stopYamlAtLine('a key was expected between braces', $sourceLneNum);
					}
					$streamOffset += strlen($flowPart[0]);
					$keyTitle = ($flowPart[1]!==null) ? $this->unescapeDouble($flowPart[1], $sourceLneNum) : (($flowPart[2]!==null) ? str_replace("''", "'", $flowPart[2]) : trim($flowPart[3]));
					if( array_key_exists($keyTitle, $flowVals) ){
						$this->stopYamlAtLine('key "'.$keyTitle.'" met twice', $sourceLneNum);
					}
					$flowVals[$keyTitle] = null;
					if( ($streamString[$streamOffset] ?? '')==':' ){
						$streamOffset++;
						$streamOffset += strspn($streamString, " \t", $streamOffset);
						if( strpos(',}', $streamString[$streamOffset] ?? ' ')===false ){
							$flowVals[$keyTitle] = $this->readFlowsArray($streamString, $streamOffset, $sourceLneNum, $depthsLftNum-1);
						}
					}
				}
				$streamOffset += strspn($streamString, " \t", $streamOffset);
				$nextChar = $streamString[$streamOffset] ?? '';
				if( $nextChar==',' ){
					$streamOffset++;
				}elseif( $nextChar!==$endChrct ){
					$this->stopYamlAtLine('bracket not closed on its line', $sourceLneNum);
				}
			}
		}
		if( $frstChar=='"' || $frstChar=="'" ){
			if( preg_match('/\G(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\')/s', $streamString, $flowPart, PREG_UNMATCHED_AS_NULL, $streamOffset)!==1 ){
				$this->stopYamlAtLine('quoted text not closed on its line', $sourceLneNum);
			}
			$streamOffset += strlen($flowPart[0]);
			return ($flowPart[1]!==null) ? $this->unescapeDouble($flowPart[1], $sourceLneNum) : str_replace("''", "'", $flowPart[2]);
		}
		if( $frstChar=='&' || $frstChar=='*' ){
			$this->stopYamlAtLine('anchors and aliases are not supported', $sourceLneNum);
		}
		if( $frstChar=='!' ){
			$this->stopYamlAtLine('a tag between brackets is not supported', $sourceLneNum);
		}
		// A bare value: up to the comma or the bracket that follows it
		preg_match('/\G[^,\[\]{}]*/', $streamString, $flowPart, 0, $streamOffset);
		$bareText = trim($flowPart[0]);
		if( $bareText==='' ){
			$this->stopYamlAtLine('a value was expected between brackets', $sourceLneNum);
		}
		if( preg_match('/:(?:[ \t]|$)/D', $bareText) ){
			$this->stopYamlAtLine('a key and its value inside a list need their own braces', $sourceLneNum);
		}
		$streamOffset += strlen($flowPart[0]);
		return $this->castYamlScalar($bareText);
	}

	/**
	 * Reads a text written over several lines: kept line by line after |,
	 * its lines joined into paragraphs after >. A - after the mark takes
	 * the line end off the end of the text, a + keeps every one of them,
	 * and a digit tells how far in the text is written.
	 *
	 * @param String $textOfHeader The mark and what follows it: |, >, |-, >+, |2, ...
	 * @param Int    $parentIndent Indentation of the key or of the dash the text belongs to.
	 * @param Int    $sourceLneNum The line of the mark.
	 * @return String The text.
	 *
	 * @throws ParseError When the mark is followed by something unknown.
	 */
	protected function readMultiLines(String $textOfHeader, Int $parentIndent, Int $sourceLneNum): String {
		$headPart = Array();
		if( !preg_match('/^([|>])(?:([+\-])([1-9])?|([1-9])([+\-])?)?$/D', $textOfHeader, $headPart, PREG_UNMATCHED_AS_NULL) ){
			$this->stopYamlAtLine('unknown mark "'.$textOfHeader.'" for a text over several lines', $sourceLneNum);
		}
		$chmpCode = strval($headPart[2] ?? $headPart[5] ?? '');
		$digitTxt = strval($headPart[3] ?? $headPart[4] ?? '');
		$textIndt = ($digitTxt!=='') ? max(0, $parentIndent)+intval($digitTxt) : -1;
		$textLnes = Array();
		while( isset($this->inputLines[$this->lineCursor]) ){
			$lineText = $this->inputLines[$this->lineCursor][0];
			if( trim($lineText)!=='' ){
				$lineIndt = strspn($lineText, ' ');
				// The first line of the text tells how far in it is written
				if( $textIndt<0 && $lineIndt>$parentIndent ){
					$textIndt = $lineIndt;
				}
				if( $textIndt<0 || $lineIndt<$textIndt ){
					break;
				}
			}
			$textLnes[] = (trim($lineText)==='') ? '' : substr($lineText, $textIndt);
			$this->lineCursor++;
		}
		// The empty lines ending the text only count when they are asked for
		$tailNumb = 0;
		while( !empty($textLnes) && end($textLnes)==='' ){
			array_pop($textLnes);
			$tailNumb++;
		}
		if( empty($textLnes) ){
			return ($chmpCode=='+') ? str_repeat("\n", $tailNumb) : '';
		}
		$fullText = '';
		if( $headPart[1]=='|' ){
			$fullText = implode("\n", $textLnes);
		}else{
			// Joined: a line end between two lines of text is a space, an empty line is a line end,
			// and a line written further in keeps the line ends around it
			$prevCode = '';
			$blankCnt = 0;
			foreach($textLnes as $linTxt){
				if( $linTxt==='' ){
					$blankCnt++;
					continue;
				}
				$lineCode = ($linTxt[0]==' ' || $linTxt[0]=="\t") ? 'more' : 'text';
				if( $prevCode!=='' ){
					$hasTrail = ($lineCode=='more' || $prevCode=='more');
					$fullText .= ($blankCnt>0 || $hasTrail) ? str_repeat("\n", $blankCnt+($hasTrail ? 1 : 0)) : ' ';
				}else{
					$fullText .= str_repeat("\n", $blankCnt);
				}
				$fullText .= $linTxt;
				$prevCode = $lineCode;
				$blankCnt = 0;
			}
		}
		if( $chmpCode=='-' ){
			return $fullText;
		}
		return $fullText."\n".(($chmpCode=='+') ? str_repeat("\n", $tailNumb) : '');
	}

}
