<?php

namespace nyu\Tools;

/**
 * A utility object for manipulating strings.
 */
class StrKit{
	
	/**
	 * Naively pluralizes an English word: 'y' becomes 'ies', a trailing
	 * 's' gets 'es' appended, otherwise a plain 's' is appended.
	 *
	 * @param String $singleWord Singular word to pluralize.
	 * @return String The pluralized word.
	 */
	public static function pluralize( String $singleWord ): String {
		if(substr($singleWord,-1)=='y'){
			$plurals = substr($singleWord,0,-1).'ies';
		}else{
			if(substr($singleWord,-1)=='s'){
				$plurals = $singleWord.'es';
			}else{
				$plurals = $singleWord.'s';
			}
		}
		return $plurals;
	}
	
	/**
	 * Naively singularizes an English word: a trailing 'ies' becomes 'y',
	 * a trailing 's' is dropped, otherwise the word is returned unchanged.
	 *
	 * @param String $pluralWord Plural word to singularize.
	 * @return String The singularized word.
	 */
	public static function singlrize( String $pluralWord ): String {
		if(substr($pluralWord,-3)=='ies'){
			$singulr = substr($pluralWord,0,-3).'y';
		}else{
			if(substr($pluralWord,-1)=='s'){
				$singulr = substr($pluralWord,0,-1);
			}else{
				$singulr = $pluralWord;
			}
		}
		return $singulr;
	}

	/**
	 * Removes tags from a string the way the deprecated
	 * FILTER_SANITIZE_STRING did: nested brackets, <!-- comments -->,
	 * <!DOCTYPE> and <? ?> blocks are dropped along with NUL bytes.
	 *
	 * @param String $rawContent The string to strip.
	 * @return String The string without its tags.
	 */
	public static function stripTags( String $rawContent ): String {
		$outText = '';
		$tagMode = 0;	// 0: text, 1: tag, 2: <? block, 3: <! block, 4: comment
		$tagDpth = 0;
		$brCount = 0;
		$xmlFlag = false;
		$txtSize = strlen($rawContent);
		for( $pos=0; $pos<$txtSize; $pos++ ){
			$chr = $rawContent[$pos];
			$prv = $pos>0 ? $rawContent[$pos-1] : '';
			if( $tagMode==0 ){
				if( $chr=='<' ){
					$tagMode = 1;
				}elseif( $chr=='>' && $tagDpth>0 ){
					$tagDpth--;
				}elseif( $chr!="\0" ){
					$outText .= $chr;
				}
			}elseif( $tagMode==4 ){
				if( $chr=='>' && $pos>=2 && $prv=='-' && $rawContent[$pos-2]=='-' ){
					$tagMode = 0;
				}
			}elseif( $chr=='>' ){
				if( $tagDpth>0 ){
					$tagDpth--;
				}elseif( $tagMode==1 && !($xmlFlag && $prv=='-') ){
					$tagMode = 0;
					$xmlFlag = false;
				}elseif( $tagMode==2 && $brCount==0 && $prv=='?' ){
					$tagMode = 0;
				}elseif( $tagMode==3 ){
					$tagMode = 0;
				}
			}elseif( $tagMode==1 ){
				if( $chr=='<' ){
					$tagDpth++;
				}elseif( $chr=='!' && $prv=='<' ){
					$tagMode = 3;
				}elseif( $chr=='?' && $prv=='<' ){
					$tagMode = 2;
					$brCount = 0;
				}
			}elseif( $tagMode==2 ){
				if( $chr=='(' ){
					$brCount++;
				}elseif( $chr==')' ){
					$brCount--;
				}elseif( ($chr=='l' || $chr=='L') && $pos>4 && strtolower(substr($rawContent, $pos-4, 4))=='<?xm' ){
					$tagMode = 1;
					$xmlFlag = true;
				}
			}elseif( $tagMode==3 ){
				if( $chr=='-' && $pos>=2 && $prv=='-' && $rawContent[$pos-2]=='!' ){
					$tagMode = 4;
				}elseif( ($chr=='E' || $chr=='e') && $pos>6 && strtolower(substr($rawContent, $pos-6, 6))=='doctyp' ){
					$tagMode = 1;
				}
			}
		}
		return $outText;
	}
}