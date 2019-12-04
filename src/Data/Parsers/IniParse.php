<?php

// A basic file object to handle parsing

namespace nyu\Data\Parsers;

class Ini( ){
	static function Parse( $fsFileData ){
		$respArr = parse_ini_string( $fsFileData, false, INI_SCANNER_TYPED );
		return $respArr;
	}
}