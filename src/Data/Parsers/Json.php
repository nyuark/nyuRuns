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
	 * @param String $rawContent The JSON content.
	 * @param Array  $parseOptns Options: 'upper', 'cmnts', 'lines', 'depth'.
	 * @return Array The envelope, of shape 'nodes'.
	 *
	 * @throws ParseError When the content is not valid JSON.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		$jsnText = self::cleanText($rawContent);
		$maxDpth = max(1, intval($parseOptns['depth'] ?? 512));
		if( !empty($parseOptns['cmnts']) ){
			$keptLns = Array();
			foreach(preg_split('/\r\n|\r|\n/', $jsnText) as $lneTxt){
				$trimLn = ltrim($lneTxt);
				if( $trimLn==='' || $trimLn[0]=='#' || substr($trimLn, 0, 2)=='//' ){
					continue;
				}
				$keptLns[] = $lneTxt;
			}
			$jsnText = implode("\n", $keptLns);
		}
		if( !empty($parseOptns['lines']) ){
			$jsnVals = Array();
			foreach(preg_split('/\r\n|\r|\n/', $jsnText) as $lneNum=>$lneTxt){
				if( trim($lneTxt)==='' ){
					continue;
				}
				$jsnVals[] = $this->readValue($lneTxt, $maxDpth, ' on line '.($lneNum+1));
			}
		}elseif( trim($jsnText)==='' ){
			$jsnVals = Array();
		}else{
			$jsnVals = $this->readValue($jsnText, $maxDpth, '');
		}
		if( !empty($parseOptns['upper']) && is_array($jsnVals) ){
			$jsnVals = $this->upperKeys($jsnVals);
		}
		return $this->makeShape('nodes', $jsnVals);
	}

	/**
	 * Decodes one JSON value.
	 *
	 * @param String $jsonString The JSON text.
	 * @param Int    $depthLimit Deepest nesting accepted.
	 * @param String $placeLabel Where the text stands, added to the error message.
	 * @return mixed The decoded value.
	 *
	 * @throws ParseError When the text is not valid JSON.
	 */
	protected function readValue(String $jsonString, Int $depthLimit, String $placeLabel): Mixed {
		$jsnVals = json_decode($jsonString, true, $depthLimit);
		if( json_last_error()!==JSON_ERROR_NONE ){
			throw new ParseError('Invalid JSON content'.$placeLabel.': '.json_last_error_msg());
		}
		return $jsnVals;
	}

}
