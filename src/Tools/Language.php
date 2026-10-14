<?php

namespace nyu\Tools;

/**
 * Chooses the language a text is written in for one person, and finds the
 * file of that text in that language.
 *
 * The language is a tag in lower case: a language of two or three letters,
 * then its parts joined by '-' ('fr', 'fr-ch', 'zh-hant'). It is chosen in
 * this order: the one stored for the person, the one the request asks for
 * (to be given only when the person making the request is the one the text
 * goes to), then the default of the project, LNG_DFLT in system.ini ('en'
 * when not set or not a tag).
 *
 * A text is looked for along a chain of languages, the most precise first:
 * 'fr-ch', then 'fr', then the default. The files lie in one folder per
 * language: <folder>/<language>/<name>.
 */
class Language {

	protected $dfltLang;

	/**
	 * Builds the chooser, with the default language of the project.
	 *
	 * @param ?String $langTagStr The default language; LNG_DFLT of system.ini when not given.
	 */
	function __construct( ?String $langTagStr=null ){
		if( $langTagStr===null ){
			$nyuConf = $GLOBALS['nyuConfig'] ?? null;
			$sysConf = ($nyuConf!==null) ? ($nyuConf->readValue('system') ?: Array()) : Array();
			$langTagStr = strval($sysConf['LNG_DFLT'] ?? '');
		}
		$langTagStr = strtolower( trim(str_replace('_', '-', $langTagStr)) );
		$this->dfltLang = self::checksTag($langTagStr) ? $langTagStr : 'en';
	}

	/**
	 * Tells whether a text is a language tag, in lower case: two or three
	 * letters, then parts of 2 to 8 letters or digits, each after a '-'.
	 *
	 * @param String $langTagStr The text.
	 * @return Bool Whether it is a tag.
	 */
	public static function checksTag( String $langTagStr ): Bool {
		return strlen($langTagStr)<=16 && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/D', $langTagStr)===1;
	}

	/**
	 * Chooses the language of a text: the one stored for the person, the
	 * one the request asks for, or the default. A value that is not a tag
	 * is passed over; '_' is read as '-', and the case is not kept.
	 *
	 * @param ?String $storedLang The language stored for the person, if any.
	 * @param ?String $clientLang The language of the request, only when the person makes it.
	 * @return String The language chosen.
	 */
	public function chooseLng( ?String $storedLang, ?String $clientLang ): String {
		foreach( Array($storedLang, $clientLang) as $oneTag ){
			$tagText = strtolower( trim(str_replace('_', '-', strval($oneTag))) );
			if( self::checksTag($tagText) ){
				return $tagText;
			}
		}
		return $this->dfltLang;
	}

	/**
	 * Gives the languages a text is looked for in, the most precise first:
	 * the tag, the tag cut part by part, then the default, cut the same way.
	 *
	 * @param String $langTagStr The language chosen.
	 * @return Array The languages, each once ('fr-ch' gives 'fr-ch', 'fr', 'en').
	 */
	public function langChain( String $langTagStr ): Array {
		$chainLs = Array();
		foreach( Array(strtolower($langTagStr), $this->dfltLang) as $oneTag ){
			if( !self::checksTag($oneTag) ){
				continue;
			}
			$tagPart = explode('-', $oneTag);
			for( $idx=count($tagPart); $idx>0; $idx-- ){
				$chainLs[] = implode('-', array_slice($tagPart, 0, $idx));
			}
		}
		return array_values( array_unique($chainLs) );
	}

	/**
	 * Finds the file of a text in the first language of a chain that has it.
	 *
	 * @param String $baseFolder The folder holding one folder per language.
	 * @param String $templtName The name of the file.
	 * @param Array  $langsChain The languages, the most precise first (see langChain()).
	 * @return ?String The path of the file, or null when no language has it.
	 */
	public function findTmplt( String $baseFolder, String $templtName, Array $langsChain ): ?String {
		if( $templtName=='' || basename($templtName)!==$templtName ){
			return null;
		}
		foreach( $langsChain as $oneTag ){
			if( !is_string($oneTag) || !self::checksTag($oneTag) ){
				continue;
			}
			$filePth = rtrim($baseFolder, '/\\').DS.$oneTag.DS.$templtName;
			if( is_file($filePth) && is_readable($filePth) ){
				return $filePth;
			}
		}
		return null;
	}
}
