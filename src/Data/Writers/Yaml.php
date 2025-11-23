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
	const WORDLIST = Array('null', 'true', 'false', 'yes', 'no', 'on', 'off', 'y', 'n', '~');

	/**
	 * Writes a content as YAML.
	 *
	 * Options:
	 *   'multi' => writes each value of the content as a document of its
	 *              own, a line of --- before each; the content must be a
	 *              list.
	 *
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'multi'.
	 * @return String The YAML.
	 *
	 * @throws WriteError When the shape is not 'nodes', the content holds what YAML cannot say, or is nested too deep.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('nodes'), 'YAML');
		// A content may be nothing at all: that is not the same as a content left out
		$itemVal = array_key_exists('items', $shapedData) ? $shapedData['items'] : Array();
		if( empty($writeOptns['multi']) ){
			return implode("\n", $this->linesOfIt($itemVal, 0, 64))."\n";
		}
		if( !is_array($itemVal) || !$this->isAListOf($itemVal) ){
			throw new WriteError('Content that cannot be written as several YAML documents: it is not a list');
		}
		$ymlText = '';
		foreach($itemVal as $oneVal){
			$ymlText .= "---\n".implode("\n", $this->linesOfIt($oneVal, 0, 64))."\n";
		}
		return $ymlText;
	}

	/**
	 * Writes a value that is not a list nor a map.
	 *
	 * @param mixed $valueToPut The value.
	 * @return String The value, as YAML writes it.
	 *
	 * @throws WriteError When the value is not one YAML can say.
	 */
	protected function scalarOut(Mixed $valueToPut): String {
		if( $valueToPut===null ){
			return 'null';
		}
		if( is_bool($valueToPut) ){
			return $valueToPut ? 'true' : 'false';
		}
		if( is_int($valueToPut) ){
			return strval($valueToPut);
		}
		if( is_float($valueToPut) ){
			if( is_nan($valueToPut) ){
				return '.nan';
			}
			if( is_infinite($valueToPut) ){
				return ($valueToPut>0) ? '.inf' : '-.inf';
			}
			// A number that is not whole keeps its point, so that it is read back as one,
			// and is written with the digits it needs and no more, whatever the server is set to
			$keptPrc = ini_set('serialize_precision', '-1');
			$nmbrTxt = json_encode($valueToPut, JSON_PRESERVE_ZERO_FRACTION);
			if( $keptPrc!==false ){
				ini_set('serialize_precision', $keptPrc);
			}
			return ($nmbrTxt===false) ? strval($valueToPut) : $nmbrTxt;
		}
		if( !is_string($valueToPut) ){
			throw new WriteError('Content that cannot be written as YAML: a value that is neither text, number, boolean nor nothing');
		}
		// Bare: letters, digits, spaces and a few marks that mean nothing to YAML, starting with a letter and not ending with a space
		if( preg_match('/^[\p{L}_][\p{L}\p{N}_ .\/+\-]*$/Du', $valueToPut) && substr($valueToPut, -1)!=' ' && !in_array(strtolower($valueToPut), self::WORDLIST) ){
			return $valueToPut;
		}
		$quotTxt = strtr($valueToPut, Array('\\'=>'\\\\', '"'=>'\\"', "\n"=>'\\n', "\t"=>'\\t', "\r"=>'\\r'));
		return '"'.preg_replace_callback('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', function($foundParts){ return sprintf('\\x%02X', ord($foundParts[0])); }, $quotTxt).'"';
	}

	/**
	 * Writes a value as the lines it takes: one for a single value,
	 * several for a list or a map.
	 *
	 * @param mixed $valueToPut The value.
	 * @param Int   $indentSize Number of spaces the lines start with.
	 * @param Int   $depthsLeft Levels of nesting still accepted.
	 * @return Array The lines.
	 *
	 * @throws WriteError When the value holds what YAML cannot say, or is nested too deep.
	 */
	protected function linesOfIt(Mixed $valueToPut, Int $indentSize, Int $depthsLeft): Array {
		$padText = str_repeat(' ', $indentSize);
		if( !is_array($valueToPut) ){
			return Array($padText.$this->scalarOut($valueToPut));
		}
		if( $depthsLeft<1 ){
			throw new WriteError('Content that cannot be written as YAML: nested deeper than allowed');
		}
		if( empty($valueToPut) ){
			return Array($padText.'[]');
		}
		$lineLst = Array();
		$isAList = $this->isAListOf($valueToPut);
		foreach($valueToPut as $itmKey=>$itmVal){
			if( $isAList ){
				// What a dash holds starts on its line: the lines of the value, the first one after the dash
				$subLnes = $this->linesOfIt($itmVal, $indentSize+2, $depthsLeft-1);
				$subLnes[0] = $padText.'- '.substr($subLnes[0], $indentSize+2);
				$lineLst = array_merge($lineLst, $subLnes);
			}elseif( is_array($itmVal) && !empty($itmVal) ){
				$lineLst[] = $padText.$this->scalarOut(is_int($itmKey) ? $itmKey : strval($itmKey)).':';
				$lineLst = array_merge($lineLst, $this->linesOfIt($itmVal, $indentSize+2, $depthsLeft-1));
			}else{
				$lineLst[] = $padText.$this->scalarOut(is_int($itmKey) ? $itmKey : strval($itmKey)).': '.(is_array($itmVal) ? '[]' : $this->scalarOut($itmVal));
			}
		}
		return $lineLst;
	}

}
