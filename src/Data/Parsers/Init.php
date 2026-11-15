<?php

namespace nyu\Data\Parsers;

/**
 * INI files: keys and typed values, the sections flattened.
 */
class Init extends Base {

	/**
	 * Parses INI content into a tree of keys and typed values.
	 *
	 * The 'upper' option upper-cases the keys.
	 *
	 * @param String $nakedContent The INI content.
	 * @param Array  $parseOptions Options: 'upper'.
	 * @return Array The envelope, of shape 'nodes'.
	 *
	 * @throws ParseError When the content is not valid INI.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		// PHP reports an INI fault as a warning: it is caught here and kept as the reason
		$whyStrng = '';
		set_error_handler(function( Int $errLevelNumb, String $errorMessage ) use (&$whyStrng){
			$whyStrng = $errorMessage;
			return true;
		});
		try{
			$iniArray = parse_ini_string( self::recodeTextUtf8($nakedContent), false, INI_SCANNER_TYPED );
		}finally{
			restore_error_handler();
		}
		if( !is_array($iniArray) ){
			$whyStrng = preg_replace('/ in Unknown/', '', trim($whyStrng!='' ? $whyStrng : 'syntax error'));
			if( preg_match('/BOOL_|NULL/', $whyStrng) ){
				// A reserved word is not accepted as a key
				$whyStrng .= ' (a key cannot be named on, off, yes, no, true, false, null or none)';
			}
			throw new ParseError('Invalid INI content: '.$whyStrng);
		}
		if( !empty($parseOptions['upper']) ){
			$iniArray = $this->upperKeysArray($iniArray);
		}
		return $this->makeShapeArray('nodes', $iniArray);
	}

}
