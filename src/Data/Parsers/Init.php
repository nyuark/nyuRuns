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
	 * @param String $rawContent The INI content.
	 * @param Array  $parseOptns Options: 'upper'.
	 * @return Array The envelope, of shape 'nodes'.
	 *
	 * @throws ParseError When the content is not valid INI.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		// PHP reports an INI fault as a warning: it is caught here and kept as the reason
		$whyText = '';
		set_error_handler(function( Int $errorLevel, String $errMessage ) use (&$whyText){
			$whyText = $errMessage;
			return true;
		});
		try{
			$iniVals = parse_ini_string( self::cleanText($rawContent), false, INI_SCANNER_TYPED );
		}finally{
			restore_error_handler();
		}
		if( !is_array($iniVals) ){
			$whyText = preg_replace('/ in Unknown/', '', trim($whyText!='' ? $whyText : 'syntax error'));
			if( preg_match('/BOOL_|NULL/', $whyText) ){
				// A reserved word is not accepted as a key
				$whyText .= ' (a key cannot be named on, off, yes, no, true, false, null or none)';
			}
			throw new ParseError('Invalid INI content: '.$whyText);
		}
		if( !empty($parseOptns['upper']) ){
			$iniVals = $this->upperKeys($iniVals);
		}
		return $this->makeShape('nodes', $iniVals);
	}

}
