<?php

// A basic file object to handle parsing

namespace nyu\Data\Chars;

class Chars{
	
	static function Parse( $fsFileData ){
		$initArr = parse_ini_string( $fsFileData, false, INI_SCANNER_TYPED );
		$respArr = Array();
		foreach($initArr as $resKey => $resVal){
			$resKey = strtoupper($resKey);
			$respArr[$resKey] = $resVal;
		}
		return $respArr;
	}
}