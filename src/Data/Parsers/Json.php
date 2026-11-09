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
		$jsonTxt = self::recodeTextUtf8($nakedContent);
		$maxDpth = max(1, intval($parseOptions['depth'] ?? 512));
		if( !empty($parseOptions['cmnts']) ){
			$keptLns = Array();
			foreach(preg_split('/\r\n|\r|\n/', $jsonTxt) as $linTxt){
				$trmLine = ltrim($linTxt);
				if( $trmLine==='' || $trmLine[0]=='#' || substr($trmLine, 0, 2)=='//' ){
					continue;
				}
				$keptLns[] = $linTxt;
			}
			$jsonTxt = implode("\n", $keptLns);
		}
		if( !empty($parseOptions['lines']) ){
			$jsonVls = Array();
			foreach(preg_split('/\r\n|\r|\n/', $jsonTxt) as $lineNb=>$linTxt){
				if( trim($linTxt)==='' ){
					continue;
				}
				$jsonVls[] = $this->readFileDetail($linTxt, $maxDpth, ' on line '.($lineNb+1));
			}
		}elseif( trim($jsonTxt)==='' ){
			$jsonVls = Array();
		}else{
			$jsonVls = $this->readFileDetail($jsonTxt, $maxDpth, '');
		}
		if( !empty($parseOptions['upper']) && is_array($jsonVls) ){
			$jsonVls = $this->upperKeysArray($jsonVls);
		}
		return $this->makeShapeArray('nodes', $jsonVls);
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
	protected function readFileDetail(String $jsonDocument, Int $depthCeiling, String $locationText): Mixed {
		$jsonVls = json_decode($jsonDocument, true, $depthCeiling);
		if( json_last_error()!==JSON_ERROR_NONE ){
			throw new ParseError('Invalid JSON content'.$locationText.': '.json_last_error_msg());
		}
		return $jsonVls;
	}

}
