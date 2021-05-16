<?php

// A basic file object to handle parsing

namespace nyu\Data\Parsers;

class Regi{
	/**
	 * Parses INI-formatted string data into an associative array with
	 * uppercased keys, using PHP's typed INI scanner.
	 *
	 * @param String $fsFileData Raw INI content to parse.
	 * @return Array The parsed values, keyed in uppercase.
	 */
	static function parseData( String $fsFileData ): Array {
		$initArr = parse_ini_string( $fsFileData, false, INI_SCANNER_TYPED );
		$respArr = Array();
		foreach($initArr as $resKey => $resVal){
			$resKey = strtoupper($resKey);
			$respArr[$resKey] = $resVal;
		}
		return $respArr;
	}
}