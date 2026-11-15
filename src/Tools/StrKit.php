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
			$plrlText = substr($eachWordText,0,-1).'ies';
		}else{
			if(substr($eachWordText,-1)=='s'){
				$plrlText = $eachWordText.'es';
			}else{
				$plrlText = $eachWordText.'s';
			}
		}
		return $plrlText;
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
			$snglText = substr($wordInPlural,0,-3).'y';
		}else{
			if(substr($wordInPlural,-1)=='s'){
				$snglText = substr($wordInPlural,0,-1);
			}else{
				$snglText = $wordInPlural;
			}
		}
		return $snglText;
	}

	/**
	 * Removes tags from a string the way the deprecated
	 * FILTER_SANITIZE_STRING did: nested brackets, <!-- comments -->,
	 * <!DOCTYPE> and <? ?> blocks are dropped along with NUL bytes.
	 *
	 * @param String $nakedContent The string to strip.
	 * @return String The string without its tags.
	 */
	public static function wipeTagsString( String $nakedContent ): String {
		$outStrng = '';
		$tagCodig = 0;	// 0: text, 1: tag, 2: <? block, 3: <! block, 4: comment
		$tagDepth = 0;
		$breakCnt = 0;
		$isXmlDoc = false;
		$textSize = strlen($nakedContent);
		for( $nthPos=0; $nthPos<$textSize; $nthPos++ ){
			$charactr = $nakedContent[$nthPos];
			$prevChar = $nthPos>0 ? $nakedContent[$nthPos-1] : '';
			if( $tagCodig==0 ){
				if( $charactr=='<' ){
					$tagCodig = 1;
				}elseif( $charactr=='>' && $tagDepth>0 ){
					$tagDepth--;
				}elseif( $charactr!="\0" ){
					$outStrng .= $charactr;
				}
			}elseif( $tagCodig==4 ){
				if( $charactr=='>' && $nthPos>=2 && $prevChar=='-' && $nakedContent[$nthPos-2]=='-' ){
					$tagCodig = 0;
				}
			}elseif( $charactr=='>' ){
				if( $tagDepth>0 ){
					$tagDepth--;
				}elseif( $tagCodig==1 && !($isXmlDoc && $prevChar=='-') ){
					$tagCodig = 0;
					$isXmlDoc = false;
				}elseif( $tagCodig==2 && $breakCnt==0 && $prevChar=='?' ){
					$tagCodig = 0;
				}elseif( $tagCodig==3 ){
					$tagCodig = 0;
				}
			}elseif( $tagCodig==1 ){
				if( $charactr=='<' ){
					$tagDepth++;
				}elseif( $charactr=='!' && $prevChar=='<' ){
					$tagCodig = 3;
				}elseif( $charactr=='?' && $prevChar=='<' ){
					$tagCodig = 2;
					$breakCnt = 0;
				}
			}elseif( $tagCodig==2 ){
				if( $charactr=='(' ){
					$breakCnt++;
				}elseif( $charactr==')' ){
					$breakCnt--;
				}elseif( ($charactr=='l' || $charactr=='L') && $nthPos>4 && strtolower(substr($nakedContent, $nthPos-4, 4))=='<?xm' ){
					$tagCodig = 1;
					$isXmlDoc = true;
				}
			}elseif( $tagCodig==3 ){
				if( $charactr=='-' && $nthPos>=2 && $prevChar=='-' && $nakedContent[$nthPos-2]=='!' ){
					$tagCodig = 4;
				}elseif( ($charactr=='E' || $charactr=='e') && $nthPos>6 && strtolower(substr($nakedContent, $nthPos-6, 6))=='doctyp' ){
					$tagCodig = 1;
				}
			}
		}
		return $outStrng;
	}
}