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

	protected $defaultLng;

	/**
	 * Builds the chooser, with the default language of the project.
	 *
	 * @param ?String $langTagStrng The default language; LNG_DFLT of system.ini when not given.
	 */
	function __construct( ?String $langTagStrng=null ){
		if( $langTagStrng===null ){
			$nyuConf = $GLOBALS['nyuConfig'] ?? null;
			$iniConf = ($nyuConf!==null) ? ($nyuConf->readFileDetail('system') ?: Array()) : Array();
			$langTagStrng = strval($iniConf['LNG_DFLT'] ?? '');
		}
		$langTagStrng = strtolower( trim(str_replace('_', '-', $langTagStrng)) );
		$this->defaultLng = self::isValidLangTag($langTagStrng) ? $langTagStrng : 'en';
	}

	/**
	 * Tells whether a text is a language tag, in lower case: two or three
	 * letters, then parts of 2 to 8 letters or digits, each after a '-'.
	 *
	 * @param String $langTagStrng The text.
	 * @return Bool Whether it is a tag.
	 */
	public static function isValidLangTag( String $langTagStrng ): Bool {
		return strlen($langTagStrng)<=16 && preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/D', $langTagStrng)===1;
	}

	/**
	 * Chooses the language of a text: the one stored for the person, the
	 * one the request asks for, or the default. A value that is not a tag
	 * is passed over; '_' is read as '-', and the case is not kept.
	 *
	 * @param ?String $langOfPerson The language stored for the person, if any.
	 * @param ?String $clntLangText The language of the request, only when the person makes it.
	 * @return String The language chosen.
	 */
	public function chooseLanguage( ?String $langOfPerson, ?String $clntLangText ): String {
		foreach( Array($langOfPerson, $clntLangText) as $oneTag ){
			$tagText = strtolower( trim(str_replace('_', '-', strval($oneTag))) );
			if( self::isValidLangTag($tagText) ){
				return $tagText;
			}
		}
		return $this->defaultLng;
	}

	/**
	 * Gives the languages a text is looked for in, the most precise first:
	 * the tag, the tag cut part by part, then the default, cut the same way.
	 *
	 * @param String $langTagStrng The language chosen.
	 * @return Array The languages, each once ('fr-ch' gives 'fr-ch', 'fr', 'en').
	 */
	public function langChainArray( String $langTagStrng ): Array {
		$chainLs = Array();
		foreach( Array(strtolower($langTagStrng), $this->defaultLng) as $oneTag ){
			if( !self::isValidLangTag($oneTag) ){
				continue;
			}
			$tagPart = explode('-', $oneTag);
			for( $idxInc=count($tagPart); $idxInc>0; $idxInc-- ){
				$chainLs[] = implode('-', array_slice($tagPart, 0, $idxInc));
			}
		}
		return array_values( array_unique($chainLs) );
	}

	/**
	 * Finds the file of a text in the first language of a chain that has it.
	 *
	 * @param String $baseFoldText The folder holding one folder per language.
	 * @param String $templateName The name of the file.
	 * @param Array  $languageList The languages, the most precise first (see langChainArray()).
	 * @return ?String The path of the file, or null when no language has it.
	 */
	public function locateTemplate( String $baseFoldText, String $templateName, Array $languageList ): ?String {
		if( $templateName=='' || basename($templateName)!==$templateName ){
			return null;
		}
		foreach( $languageList as $oneTag ){
			if( !is_string($oneTag) || !self::isValidLangTag($oneTag) ){
				continue;
			}
			$filPath = rtrim($baseFoldText, '/\\').DS.$oneTag.DS.$templateName;
			if( is_file($filPath) && is_readable($filPath) ){
				return $filPath;
			}
		}
		return null;
	}
}
