<?php

namespace nyu\Data\Writers;

/**
 * JSON, and JSON Lines: a content of any shape, written as its values.
 */
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
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'prety', 'lines', 'whole'.
	 * @return String The JSON.
	 *
	 * @throws WriteError When the shape is not a known one, the content holds what JSON cannot say, or lines are asked of what is not a list.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('nodes', 'sheet', 'block'), 'JSON');
		// A content may be nothing at all: that is not the same as a content left out
		$itemVals = array_key_exists('items', $dataEnvelope) ? $dataEnvelope['items'] : Array();
		if( empty($writeOptions['lines']) ){
			$wholDict = !empty($writeOptions['whole']) ? Array('shape'=>$dataEnvelope['shape'], 'infos'=>$dataEnvelope['infos'] ?? Array(), 'items'=>$itemVals) : $itemVals;
			return $this->writeJsonValue($wholDict, !empty($writeOptions['prety'])).(!empty($writeOptions['prety']) ? "\n" : '');
		}
		if( !is_array($itemVals) || !$this->isSequenceList($itemVals) ){
			throw new WriteError('Content that cannot be written as JSON Lines: it is not a list');
		}
		$jsonText = '';
		foreach($itemVals as $oneVal){
			$jsonText .= $this->writeJsonValue($oneVal, false)."\n";
		}
		return $jsonText;
	}

	/**
	 * Writes a value as JSON: accents and slashes as they are, a number
	 * that is not whole with its decimal point.
	 *
	 * @param mixed $valueToWrite The value.
	 * @param Bool  $multiLineOut Whether the value is written over several lines.
	 * @return String The JSON.
	 *
	 * @throws WriteError When the value holds what JSON cannot say.
	 */
	protected function writeJsonValue(Mixed $valueToWrite, Bool $multiLineOut): String {
		// Numbers are written with the digits they need and no more, whatever the server is set to
		$keptPrec = ini_set('serialize_precision', '-1');
		// A binary value cannot be written as text: it is given as null
		$jsonText = json_encode(\nyu\Data\Fluxd::dropAllSources($valueToWrite), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | ($multiLineOut ? JSON_PRETTY_PRINT : 0));
		if( $keptPrec!==false ){
			ini_set('serialize_precision', $keptPrec);
		}
		if( $jsonText===false ){
			throw new WriteError('Content that cannot be written as JSON: '.json_last_error_msg());
		}
		return $jsonText;
	}

}
