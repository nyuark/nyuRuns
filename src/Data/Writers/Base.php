<?php

namespace nyu\Data\Writers;

// What the writers share: telling the shape of the content they are
// given, and refusing the ones they do not take.
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
	 * Tells whether an array is a list: its keys are 0, 1, 2, and so on.
	 *
	 * @param Array $valueArray The array.
	 * @return Bool True for a list, an empty one included.
	 */
	protected function isAListOf(Array $valueArray): Bool {
		return (empty($valueArray) || array_keys($valueArray)===range(0, count($valueArray)-1));
	}

}
