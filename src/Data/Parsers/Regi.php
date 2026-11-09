<?php

namespace nyu\Data\Parsers;

/**
 * Registry files: INI content whose keys are always read in upper case.
 */
class Regi extends Init {

	/**
	 * Parses INI content into a tree of upper-cased keys and typed values.
	 *
	 * @param String $nakedContent The INI content.
	 * @param Array  $parseOptions Options: none is read, the keys are always upper-cased.
	 * @return Array The envelope, of shape 'nodes'.
	 *
	 * @throws ParseError When the content is not valid INI.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		return parent::parseDataArray($nakedContent, Array('upper'=>true) + $parseOptions);
	}

}
