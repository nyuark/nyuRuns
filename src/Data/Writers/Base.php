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
	 * @param Array  $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array  $acceptShapes The shapes the writer takes.
	 * @param String $nameOfFormat Name of the format, for the message of the refusal.
	 * @return String The shape.
	 *
	 * @throws WriteError When the shape is not one of those.
	 */
	protected function checkShapeText(Array $dataEnvelope, Array $acceptShapes, String $nameOfFormat): String {
		$shapeNme = strval($dataEnvelope['shape'] ?? '');
		if( !in_array($shapeNme, $acceptShapes, true) ){
			throw new WriteError('Shape that cannot be written as '.$nameOfFormat.': '.$shapeNme);
		}
		return $shapeNme;
	}

	/**
	 * Writes a text for a part of a document made of XML: without what
	 * such a part cannot hold, and with its signs written so that they
	 * stay text.
	 *
	 * @param String $sourceString The text.
	 * @return String The text, ready to stand in a part.
	 */
	protected function escapeXmlValue(String $sourceString): String {
		if( !preg_match('//u', $sourceString) ){
			$sourceString = strval(@iconv('Windows-1252', 'UTF-8//IGNORE', $sourceString));
		}
		return htmlspecialchars(strval(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $sourceString)), ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * Writes a number with the digits it needs and no more, whatever the
	 * server is set to.
	 *
	 * @param Int|Float $numberToShow The number.
	 * @return String The number, as text.
	 */
	protected function writeNumberTxt(Int|Float $numberToShow): String {
		$keptPrec = ini_set('serialize_precision', '-1');
		$numbText = strval(json_encode($numberToShow));
		if( $keptPrec!==false ){
			ini_set('serialize_precision', $keptPrec);
		}
		return $numbText;
	}

	/**
	 * Tells whether an array is a list: its keys are 0, 1, 2, and so on.
	 *
	 * @param Array $fieldValList The array.
	 * @return Bool True for a list, an empty one included.
	 */
	protected function validateALstOf(Array $fieldValList): Bool {
		return (empty($fieldValList) || array_keys($fieldValList)===range(0, count($fieldValList)-1));
	}

}
