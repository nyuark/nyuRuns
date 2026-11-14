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
	 * @param String $eachWordText Singular word to pluralize.
	 * @return String The pluralized word.
	 */
	public static function makePluralWord( String $eachWordText ): String {
		if(substr($eachWordText,-1)=='y'){
			$plurals = substr($eachWordText,0,-1).'ies';
		}else{
			if(substr($eachWordText,-1)=='s'){
				$plurals = $eachWordText.'es';
			}else{
				$plurals = $eachWordText.'s';
			}
		}
		return $plurals;
	}
	
	/**
	 * Naively singularizes an English word: a trailing 'ies' becomes 'y',
	 * a trailing 's' is dropped, otherwise the word is returned unchanged.
	 *
	 * @param String $wordInPlural Plural word to singularize.
	 * @return String The singularized word.
	 */
	public static function singularizeTxt( String $wordInPlural ): String {
		if(substr($wordInPlural,-3)=='ies'){
			$singulr = substr($wordInPlural,0,-3).'y';
		}else{
			if(substr($wordInPlural,-1)=='s'){
				$singulr = substr($wordInPlural,0,-1);
			}else{
				$singulr = $wordInPlural;
			}
		}
		return $singulr;
	}

	/**
	 * Removes tags from a string the way the deprecated
	 * FILTER_SANITIZE_STRING did: nested brackets, <!-- comments -->,
	 * <!DOCTYPE> and <? ?> blocks are dropped along with NUL bytes.
	 *
	 * @param String $nakedContent The string to strip.
	 * @return String The string without its tags.
	 */
	public static function trimTagsString( String $nakedContent ): String {
		$outText = '';
		$tagMode = 0;	// 0: text, 1: tag, 2: <? block, 3: <! block, 4: comment
		$tagDpth = 0;
		$brCount = 0;
		$xmlFlag = false;
		$txtSize = strlen($nakedContent);
		for( $posInc=0; $posInc<$txtSize; $posInc++ ){
			$charctr = $nakedContent[$posInc];
			$prevChr = $posInc>0 ? $nakedContent[$posInc-1] : '';
			if( $tagMode==0 ){
				if( $charctr=='<' ){
					$tagMode = 1;
				}elseif( $charctr=='>' && $tagDpth>0 ){
					$tagDpth--;
				}elseif( $charctr!="\0" ){
					$outText .= $charctr;
				}
			}elseif( $tagMode==4 ){
				if( $charctr=='>' && $posInc>=2 && $prevChr=='-' && $nakedContent[$posInc-2]=='-' ){
					$tagMode = 0;
				}
			}elseif( $charctr=='>' ){
				if( $tagDpth>0 ){
					$tagDpth--;
				}elseif( $tagMode==1 && !($xmlFlag && $prevChr=='-') ){
					$tagMode = 0;
					$xmlFlag = false;
				}elseif( $tagMode==2 && $brCount==0 && $prevChr=='?' ){
					$tagMode = 0;
				}elseif( $tagMode==3 ){
					$tagMode = 0;
				}
			}elseif( $tagMode==1 ){
				if( $charctr=='<' ){
					$tagDpth++;
				}elseif( $charctr=='!' && $prevChr=='<' ){
					$tagMode = 3;
				}elseif( $charctr=='?' && $prevChr=='<' ){
					$tagMode = 2;
					$brCount = 0;
				}
			}elseif( $tagMode==2 ){
				if( $charctr=='(' ){
					$brCount++;
				}elseif( $charctr==')' ){
					$brCount--;
				}elseif( ($charctr=='l' || $charctr=='L') && $posInc>4 && strtolower(substr($nakedContent, $posInc-4, 4))=='<?xm' ){
					$tagMode = 1;
					$xmlFlag = true;
				}
			}elseif( $tagMode==3 ){
				if( $charctr=='-' && $posInc>=2 && $prevChr=='-' && $nakedContent[$posInc-2]=='!' ){
					$tagMode = 4;
				}elseif( ($charctr=='E' || $charctr=='e') && $posInc>6 && strtolower(substr($nakedContent, $posInc-6, 6))=='doctyp' ){
					$tagMode = 1;
				}
			}
		}
		return $outText;
	}
}