<?php

// An utility object for manipulating strings

namespace nyu\Tools;

class StrKit{
	
	public static function pluralize( String $singleWord ){
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
	
	public static function singlrize( String $pluralWord ){
		if(substr($pluralWord,-3)=='ies'){
			$singulr = substr($pluralWord,0,-3).'y';
		}else{
			if(substr($pluralWord,-1)=='s'){
				$singulr = substr($pluralWord,0,-1);
			}
		}
		return $singulr;
	}
}