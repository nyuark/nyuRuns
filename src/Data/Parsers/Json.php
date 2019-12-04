<?php

// A basic file object to handle parsing

namespace nyu\Data\Parsers;

class Json{
	static function Parse( String $fsFileData, Bool $keys2Upper = true ){
		if(!empty($fsFileData)){
			$initArr = json_decode( $fsFileData, true );
			if(is_array($initArr)){
				$respArr = Array();
				foreach($initArr as $resKey => $resVal){
					if($keys2Upper){
						$resKey = strtoupper($resKey);
					}
					$respArr[$resKey] = $resVal;
				}
			}else{
				// Invalid Data
				$error = get_json_error();
			}
			return $respArr;
		}else{
			// Empty file
		}
	}
}

function get_json_error()
{
	// switch and check possible JSON errors
	switch (json_last_error()) {
		case JSON_ERROR_NONE:
			$error = ''; // JSON is valid // No error has occurred
			break;
		case JSON_ERROR_DEPTH:
			$error = 'The maximum stack depth has been exceeded.';
			break;
		case JSON_ERROR_STATE_MISMATCH:
			$error = 'Invalid or malformed JSON.';
			break;
		case JSON_ERROR_CTRL_CHAR:
			$error = 'Control character error, possibly incorrectly encoded.';
			break;
		case JSON_ERROR_SYNTAX:
			$error = 'Syntax error, malformed JSON.';
			break;
		// PHP >= 5.3.3
		case JSON_ERROR_UTF8:
			$error = 'Malformed UTF-8 characters, possibly incorrectly encoded.';
			break;
		// PHP >= 5.5.0
		case JSON_ERROR_RECURSION:
			$error = 'One or more recursive references in the value to be encoded.';
			break;
		// PHP >= 5.5.0
		case JSON_ERROR_INF_OR_NAN:
			$error = 'One or more NAN or INF values in the value to be encoded.';
			break;
		case JSON_ERROR_UNSUPPORTED_TYPE:
			$error = 'A value of a type that cannot be encoded was given.';
			break;
		default:
			$error = 'Unknown JSON error occured.';
			break;
	}

	// throw the Exception or exit // or whatever :)
	   return $error;
}