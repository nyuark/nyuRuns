<?php

namespace nyu\Data\Writers;

/**
 * YAML, the part of it that the parser reads: keys and values, lists,
 * nesting by indentation. A content read then written reads back the
 * same.
 *
 * A text is written bare when nothing else can be read in it, and
 * between double quotes otherwise: one that looks like a number, a date
 * or a word YAML gives a meaning to, one starting with a mark, one
 * holding a colon, a hash or a line end.
 */
class Yaml extends Base {

	// Words a reader of YAML, of this version or of an older one, takes for something else than text
	const WORD_LIST = Array('null', 'true', 'false', 'yes', 'no', 'on', 'off', 'y', 'n', '~');

	/**
	 * Writes a content as YAML.
	 *
	 * Options:
	 *   'multi' => writes each value of the content as a document of its
	 *              own, a line of --- before each; the content must be a
	 *              list.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'multi'.
	 * @return String The YAML.
	 *
	 * @throws WriteError When the shape is not 'nodes', the content holds what YAML cannot say, or is nested too deep.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('nodes'), 'YAML');
		// A content may be nothing at all: that is not the same as a content left out
		$itemVals = array_key_exists('items', $dataEnvelope) ? $dataEnvelope['items'] : Array();
		if( empty($writeOptions['multi']) ){
			return implode("\n", $this->writeYamlLines($itemVals, 0, 64))."\n";
		}
		if( !is_array($itemVals) || !$this->isSequenceList($itemVals) ){
			throw new WriteError('Content that cannot be written as several YAML documents: it is not a list');
		}
		$ymlStrng = '';
		foreach($itemVals as $oneVal){
			$ymlStrng .= "---\n".implode("\n", $this->writeYamlLines($oneVal, 0, 64))."\n";
		}
		return $ymlStrng;
	}

	/**
	 * Writes a value that is not a list nor a map.
	 *
	 * @param mixed $valueToWrite The value.
	 * @return String The value, as YAML writes it.
	 *
	 * @throws WriteError When the value is not one YAML can say.
	 */
	protected function writeYamlValue(Mixed $valueToWrite): String {
		if( $valueToWrite===null ){
			return 'null';
		}
		if( is_bool($valueToWrite) ){
			return $valueToWrite ? 'true' : 'false';
		}
		if( is_int($valueToWrite) ){
			return strval($valueToWrite);
		}
		if( is_float($valueToWrite) ){
			if( is_nan($valueToWrite) ){
				return '.nan';
			}
			if( is_infinite($valueToWrite) ){
				return ($valueToWrite>0) ? '.inf' : '-.inf';
			}
			// A number that is not whole keeps its point, so that it is read back as one,
			// and is written with the digits it needs and no more, whatever the server is set to
			$keptPrec = ini_set('serialize_precision', '-1');
			$numbText = json_encode($valueToWrite, JSON_PRESERVE_ZERO_FRACTION);
			if( $keptPrec!==false ){
				ini_set('serialize_precision', $keptPrec);
			}
			return ($numbText===false) ? strval($valueToWrite) : $numbText;
		}
		if( !is_string($valueToWrite) ){
			throw new WriteError('Content that cannot be written as YAML: a value that is neither text, number, boolean nor nothing');
		}
		// Bare: letters, digits, spaces and a few marks that mean nothing to YAML, starting with a letter and not ending with a space
		if( preg_match('/^[\p{L}_][\p{L}\p{N}_ .\/+\-]*$/Du', $valueToWrite) && substr($valueToWrite, -1)!=' ' && !in_array(strtolower($valueToWrite), self::WORD_LIST) ){
			return $valueToWrite;
		}
		$quoteTxt = strtr($valueToWrite, Array('\\'=>'\\\\', '"'=>'\\"', "\n"=>'\\n', "\t"=>'\\t', "\r"=>'\\r'));
		return '"'.preg_replace_callback('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', function($locatedParts){ return sprintf('\\x%02X', ord($locatedParts[0])); }, $quoteTxt).'"';
	}

	/**
	 * Writes a value as the lines it takes: one for a single value,
	 * several for a list or a map.
	 *
	 * @param mixed $valueToWrite The value.
	 * @param Int   $indentLength Number of spaces the lines start with.
	 * @param Int   $depthsLftNum Levels of nesting still accepted.
	 * @return Array The lines.
	 *
	 * @throws WriteError When the value holds what YAML cannot say, or is nested too deep.
	 */
	protected function writeYamlLines(Mixed $valueToWrite, Int $indentLength, Int $depthsLftNum): Array {
		$padStrng = str_repeat(' ', $indentLength);
		if( !is_array($valueToWrite) ){
			return Array($padStrng.$this->writeYamlValue($valueToWrite));
		}
		if( $depthsLftNum<1 ){
			throw new WriteError('Content that cannot be written as YAML: nested deeper than allowed');
		}
		if( empty($valueToWrite) ){
			return Array($padStrng.'[]');
		}
		$lineList = Array();
		$isAsList = $this->isSequenceList($valueToWrite);
		foreach($valueToWrite as $itmKey=>$itmVal){
			if( $isAsList ){
				// What a dash holds starts on its line: the lines of the value, the first one after the dash
				$subLines = $this->writeYamlLines($itmVal, $indentLength+2, $depthsLftNum-1);
				$subLines[0] = $padStrng.'- '.substr($subLines[0], $indentLength+2);
				$lineList = array_merge($lineList, $subLines);
			}elseif( is_array($itmVal) && !empty($itmVal) ){
				$lineList[] = $padStrng.$this->writeYamlValue(is_int($itmKey) ? $itmKey : strval($itmKey)).':';
				$lineList = array_merge($lineList, $this->writeYamlLines($itmVal, $indentLength+2, $depthsLftNum-1));
			}else{
				$lineList[] = $padStrng.$this->writeYamlValue(is_int($itmKey) ? $itmKey : strval($itmKey)).': '.(is_array($itmVal) ? '[]' : $this->writeYamlValue($itmVal));
			}
		}
		return $lineList;
	}

}
