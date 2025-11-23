<?php

namespace nyu\Data\Writers;

/**
 * What the writers share: telling the shape of the content they are
 * given, and refusing the ones they do not take.
 */
abstract class Base implements Writer {

	/**
	 * Gives the shape of a content, or refuses it when the writer does
	 * not take that shape.
	 *
	 * @param Array  $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array  $shapesList The shapes the writer takes.
	 * @param String $formatName Name of the format, for the message of the refusal.
	 * @return String The shape.
	 *
	 * @throws WriteError When the shape is not one of those.
	 */
	protected function checkShpe(Array $shapedData, Array $shapesList, String $formatName): String {
		$shapeAs = strval($shapedData['shape'] ?? '');
		if( !in_array($shapeAs, $shapesList, true) ){
			throw new WriteError('Shape that cannot be written as '.$formatName.': '.$shapeAs);
		}
		return $shapeAs;
	}

	/**
	 * Writes a text for a part of a document made of XML: without what
	 * such a part cannot hold, and with its signs written so that they
	 * stay text.
	 *
	 * @param String $plainTexts The text.
	 * @return String The text, ready to stand in a part.
	 */
	protected function escapeTxt(String $plainTexts): String {
		if( !preg_match('//u', $plainTexts) ){
			$plainTexts = strval(@iconv('Windows-1252', 'UTF-8//IGNORE', $plainTexts));
		}
		return htmlspecialchars(strval(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $plainTexts)), ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * Writes a number with the digits it needs and no more, whatever the
	 * server is set to.
	 *
	 * @param Int|Float $numbrToPut The number.
	 * @return String The number, as text.
	 */
	protected function numbrText(Int|Float $numbrToPut): String {
		$keptPrc = ini_set('serialize_precision', '-1');
		$nmbrTxt = strval(json_encode($numbrToPut));
		if( $keptPrc!==false ){
			ini_set('serialize_precision', $keptPrc);
		}
		return $nmbrTxt;
	}

	/**
	 * Tells whether an array is a list: its keys are 0, 1, 2, and so on.
	 *
	 * @param Array $valueArray The array.
	 * @return Bool True for a list, an empty one included.
	 */
	protected function isAListOf(Array $valueArray): Bool {
		return (empty($valueArray) || array_keys($valueArray)===range(0, count($valueArray)-1));
	}

}
