<?php

// A basic file object to handle parsing

namespace nyu\Data\Parsers;

class Init{
	/**
	 * Parses INI-formatted string data into an associative array, using
	 * PHP's typed INI scanner.
	 *
	 * @param String $fsFileData Raw INI content to parse.
	 * @return Array|Bool The parsed values, or false when the content is not valid INI.
	 */
	static function parseData( String $fsFileData ): Array|Bool {
		$respArr = parse_ini_string( $fsFileData, false, INI_SCANNER_TYPED );
		return $respArr;
	}
}