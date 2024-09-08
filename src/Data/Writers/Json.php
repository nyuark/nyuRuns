<?php

namespace nyu\Data\Writers;

// JSON, and JSON Lines: a content of any shape, written as its values.
class Json extends Base {

	/**
	 * Writes a content as JSON.
	 *
	 * Options:
	 *   'prety' => writes the values over several lines, indented;
	 *   'lines' => writes one value per line (JSON Lines); the content
	 *              must be a list;
	 *   'whole' => writes the envelope, with its shape and its details,
	 *              instead of the content alone.
	 *
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'prety', 'lines', 'whole'.
	 * @return String The JSON.
	 *
	 * @throws WriteError When the shape is not a known one, the content holds what JSON cannot say, or lines are asked of what is not a list.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('nodes', 'sheet', 'block'), 'JSON');
		// A content may be nothing at all: that is not the same as a content left out
		$itemVal = array_key_exists('items', $shapedData) ? $shapedData['items'] : Array();
		if( empty($writeOptns['lines']) ){
			$wholeIt = !empty($writeOptns['whole']) ? Array('shape'=>$shapedData['shape'], 'infos'=>$shapedData['infos'] ?? Array(), 'items'=>$itemVal) : $itemVal;
			return $this->jsonTexts($wholeIt, !empty($writeOptns['prety'])).(!empty($writeOptns['prety']) ? "\n" : '');
		}
		if( !is_array($itemVal) || !$this->isAListOf($itemVal) ){
			throw new WriteError('Content that cannot be written as JSON Lines: it is not a list');
		}
		$jsnText = '';
		foreach($itemVal as $oneVal){
			$jsnText .= $this->jsonTexts($oneVal, false)."\n";
		}
		return $jsnText;
	}

	/**
	 * Writes a value as JSON: accents and slashes as they are, a number
	 * that is not whole with its decimal point.
	 *
	 * @param mixed $valueToPut The value.
	 * @param Bool  $onSeveralL Whether the value is written over several lines.
	 * @return String The JSON.
	 *
	 * @throws WriteError When the value holds what JSON cannot say.
	 */
	protected function jsonTexts(Mixed $valueToPut, Bool $onSeveralL): String {
		// Numbers are written with the digits they need and no more, whatever the server is set to
		$keptPrc = ini_set('serialize_precision', '-1');
		// A binary value cannot be written as text: it is given as null
		$jsnText = json_encode(\nyu\Data\Fluxd::dropFluxd($valueToPut), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | ($onSeveralL ? JSON_PRETTY_PRINT : 0));
		if( $keptPrc!==false ){
			ini_set('serialize_precision', $keptPrc);
		}
		if( $jsnText===false ){
			throw new WriteError('Content that cannot be written as JSON: '.json_last_error_msg());
		}
		return $jsnText;
	}

}
