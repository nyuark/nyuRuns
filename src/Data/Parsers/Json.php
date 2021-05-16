<?php

// A basic file object to handle parsing

namespace nyu\Data\Parsers;

class Json{
	/**
	 * Parses a JSON string into an associative array, optionally
	 * uppercasing top-level keys.
	 *
	 * @param String $jsonValues Raw JSON content to parse.
	 * @param Bool   $keys2Upper Whether to uppercase the resulting top-level keys.
	 * @return mixed The parsed values, or a JSON error message string if decoding failed.
	 */
	static function parseData( String $jsonValues, Bool $keys2Upper = true ): Array|String|Null {
		if(!empty($jsonValues)){
			$initArr = json_decode( $jsonValues, true );
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
				$errMssg = jsonError();
				return $errMssg;
			}
			return $respArr;
		}else{
			// Empty file
			return null;
		}
	}
}

/**
 * Returns a human-readable message describing the most recent
 * json_decode()/json_encode() error, if any.
 *
 * @return String The error message, or an empty string if the last JSON operation succeeded.
 */
function jsonError(): String
{
	// switch and check possible JSON errors
	switch (json_last_error()) {
		case JSON_ERROR_NONE:
			$errMssg = ''; // JSON is valid // No error has occurred
			break;
		case JSON_ERROR_DEPTH:
			$errMssg = 'The maximum stack depth has been exceeded.';
			break;
		case JSON_ERROR_STATE_MISMATCH:
			$errMssg = 'Invalid or malformed JSON.';
			break;
		case JSON_ERROR_CTRL_CHAR:
			$errMssg = 'Control character error, possibly incorrectly encoded.';
			break;
		case JSON_ERROR_SYNTAX:
			$errMssg = 'Syntax error, malformed JSON.';
			break;
		// PHP >= 5.3.3
		case JSON_ERROR_UTF8:
			$errMssg = 'Malformed UTF-8 characters, possibly incorrectly encoded.';
			break;
		// PHP >= 5.5.0
		case JSON_ERROR_RECURSION:
			$errMssg = 'One or more recursive references in the value to be encoded.';
			break;
		// PHP >= 5.5.0
		case JSON_ERROR_INF_OR_NAN:
			$errMssg = 'One or more NAN or INF values in the value to be encoded.';
			break;
		case JSON_ERROR_UNSUPPORTED_TYPE:
			$errMssg = 'A value of a type that cannot be encoded was given.';
			break;
		default:
			$errMssg = 'Unknown JSON error occured.';
			break;
	}

	// throw the Exception or exit // or whatever :)
	return $errMssg;
}
