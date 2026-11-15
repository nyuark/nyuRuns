<?php

namespace nyu\Data\Parsers;

/**
 * JSON, JSON with comment lines, and JSON Lines.
 */
class Json extends Base {

	/**
	 * Parses JSON content. An empty content gives an empty tree.
	 *
	 * Options:
	 *   'upper' => upper-cases the keys of the first level;
	 *   'cmnts' => leaves out the lines starting with # or //, as the
	 *              preset and route files write their comments;
	 *   'lines' => reads one JSON value per line (JSON Lines), into a list;
	 *   'depth' => deepest nesting accepted, 512 by default.
	 *
	 * @param String $nakedContent The JSON content.
	 * @param Array  $parseOptions Options: 'upper', 'cmnts', 'lines', 'depth'.
	 * @return Array The envelope, of shape 'nodes'.
	 *
	 * @throws ParseError When the content is not valid JSON.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$jsonText = self::castTextToUtf8($nakedContent);
		$maxDepth = max(1, intval($parseOptions['depth'] ?? 512));
		if( !empty($parseOptions['cmnts']) ){
			$keptLnes = Array();
			foreach(preg_split('/\r\n|\r|\n/', $jsonText) as $linTxt){
				$trimLine = ltrim($linTxt);
				if( $trimLine==='' || $trimLine[0]=='#' || substr($trimLine, 0, 2)=='//' ){
					continue;
				}
				$keptLnes[] = $linTxt;
			}
			$jsonText = implode("\n", $keptLnes);
		}
		if( !empty($parseOptions['lines']) ){
			$jsonVals = Array();
			foreach(preg_split('/\r\n|\r|\n/', $jsonText) as $lineNb=>$linTxt){
				if( trim($linTxt)==='' ){
					continue;
				}
				$jsonVals[] = $this->parseJsonValue($linTxt, $maxDepth, ' on line '.($lineNb+1));
			}
		}elseif( trim($jsonText)==='' ){
			$jsonVals = Array();
		}else{
			$jsonVals = $this->parseJsonValue($jsonText, $maxDepth, '');
		}
		if( !empty($parseOptions['upper']) && is_array($jsonVals) ){
			$jsonVals = $this->castKeysInCaps($jsonVals);
		}
		return $this->makeShapeArray('nodes', $jsonVals);
	}

	/**
	 * Decodes one JSON value.
	 *
	 * @param String $jsonDocument The JSON text.
	 * @param Int    $depthCeiling Deepest nesting accepted.
	 * @param String $locationText Where the text stands, added to the error message.
	 * @return mixed The decoded value.
	 *
	 * @throws ParseError When the text is not valid JSON.
	 */
	protected function parseJsonValue(String $jsonDocument, Int $depthCeiling, String $locationText): Mixed {
		$jsonVals = json_decode($jsonDocument, true, $depthCeiling);
		if( json_last_error()!==JSON_ERROR_NONE ){
			throw new ParseError('Invalid JSON content'.$locationText.': '.json_last_error_msg());
		}
		return $jsonVals;
	}

}
