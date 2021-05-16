<?php

// An utility object for formating different type of data
// Formats available:
/*
	- TextStr
	- Integer
	- Numeric
	- PriceNb
	- TODO : Float
	- DateStr
	- WeekDay
	- FullDay
	- TODO: ShortDate
	- TODO: TextDate
	- DateSpn
	- TODO: Email
	- TODO: Phone
	- TODO: URL
	- TODO: URLText
	- TODO: HTMText
*/

namespace nyu\Tools;

class Variable{
	
	var $mainData;
	
	/**
	 * Wraps a raw value for later formatting.
	 *
	 * @param mixed $givenValue The raw value to wrap.
	 */
	function __construct( Mixed $givenValue ){
		$this->saveValue($givenValue);
	}

	/**
	 * Returns the wrapped value formatted as the given type, by calling
	 * the matching to{$respFormat}() method (e.g. 'DateStr' -> toDateStr()).
	 *
	 * @param String $respFormat Name of the format to apply (must match an existing to*() method).
	 * @param mixed  $formatOpts Optional format-specific options passed to the to*() method.
	 * @return mixed The formatted value.
	 */
	function readValue( String $respFormat='TextStr', Mixed $formatOpts=false ): Mixed {
		if( $respFormat!=false && method_exists($this,"to$respFormat") ){
			$methNme = 'to'.$respFormat;
			if(!$formatOpts){
				$out = call_user_func(array($this, $methNme));
			}else{				
				$out = call_user_func(array($this, $methNme), $formatOpts);
			}
			return $this->mainData;
		}
		return $this->mainData;
	}
	
	/**
	 * Replaces the wrapped value.
	 *
	 * @param mixed $givenValue The new raw value to wrap.
	 */
	function saveValue( Mixed $givenValue ): Void {
		$this->mainData = $givenValue;
	}

	/**
	 * Formats the wrapped value as a sanitized string.
	 */
	function toTextStr(): Void {
		$this->mainData = StrKit::stripTags(
			str_replace( Array("'", '"'), Array('&#39;', '&#34;'), $this->mainData.'' )
		);
	}
	
	/**
	 * Formats the wrapped value as a sanitized integer.
	 */
	function toInteger(): Void {
		$this->mainData = intval(filter_var($this->mainData, FILTER_SANITIZE_NUMBER_INT ));
	}
	
	/**
	 * Formats the wrapped value as a number with the given decimal and
	 * thousands separators.
	 *
	 * @param Array $numberOpts Format options: 'decimals', 'decimsep', 'thsndsep'.
	 */
	function toNumeric( Array $numberOpts ): Void {
		$numData = floatval( filter_var( $this->mainData.'', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION ) );
		$this->mainData = number_format($numData, $numberOpts['decimals'], $numberOpts['decimsep'], $numberOpts['thsndsep']);
	}
	
	/**
	 * Formats the wrapped value as a price (2 decimals, '.' decimal
	 * separator, "'" thousands separator).
	 */
	function toPriceNb(): Void {
		$this->toNumeric( Array('decimals'=>2,'decimsep'=>'.','thsndsep'=>"'") );
	}
	
	/**
	 * Formats the wrapped value as a sanitized email address.
	 */
	function toMailAdr(): Void {
		$this->mainData = filter_var($this->mainData.'', FILTER_SANITIZE_EMAIL);
	}
	
	/*
	function toPhone(){
		$firstNum
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
	}
	*/
	
	
	/**
	 * Formats the wrapped value (expected as a 'Y-m-d'-style date string)
	 * as a Unix timestamp and applies the given date() format.
	 *
	 * @param String $dateFormat A date() format string.
	 */
	function toDateStr( String $dateFormat='' ): Void {
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		if( $dateFormat=='' ) $dateFormat='d/m/y';
		$this->mainData = date( $dateFormat, $this->mainData );
	}
	
	/**
	 * Formats the wrapped value (expected as a 'Y-m-d'-style date string)
	 * as the English weekday name it falls on.
	 */
	function toWeekDay( ): Void {
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		if(!defined('LOC_WKDAYS')){
			define('LOC_WKDAYS','Sunday;Monday;Tuesday;Wednesday;Thursday;Friday;Saturday');
		}
		$allDays = explode(';',LOC_WKDAYS);
		$currDay = date('w', $this->mainData);
		$weekDay = $allDays[$currDay];
		
		$this->mainData = $weekDay;
	}
	
	/**
	 * Formats the wrapped value (expected as a 'Y-m-d'-style date string)
	 * as a full localized date: 'Yesterday'/'Today'/'Tomorrow' for nearby
	 * dates, otherwise '<Weekday>, <day> <Month>'.
	 */
	function toFullDay(): Void {
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		$tmeStmp = $this->mainData;
		if(!defined('LOC_YSTRD'))define('LOC_YSTRD','Yesterday');
		if(!defined('LOC_TODAY'))define('LOC_TODAY','Today');
		if(!defined('LOC_TOMRW'))define('LOC_TOMRW','Tomorrow');
		
		if( date('Ymd',( time()-(3600*24) ) ) == date('Ymd',$tmeStmp) ) {
			$this->mainData = LOC_YSTRD;
			return;
		}
		
		if( date('Ymd') == date('Ymd',$tmeStmp) ) {
			$this->mainData = LOC_TODAY;
			return;
		}
		
		if( date('Ymd',( time()+(3600*24) ) ) == date('Ymd',$tmeStmp) ) {
			$this->mainData = LOC_TOMRW;
			return;
		}
		
		if(!defined('LOC_WKDAYS')){
			define('LOC_WKDAYS','Sunday;Monday;Tuesday;Wednesday;Thursday;Friday;Saturday');
		}
		$allDays = explode(';',LOC_WKDAYS);
		$currDay = date('w', $this->mainData);
		$weekDay = $allDays[$currDay];
		
		$dteData = $weekDay.', '.date('j', $tmeStmp).' ';
		
		if(!defined('LOC_MONTHS')){
			define('LOC_MONTHS',';January;February;March;April;May;June;July;August;September;October;November;December');
		}
		$allMnth = explode(';',LOC_MONTHS);
		
		$nowMnth = date('n', $tmeStmp);
		$dteData .= $allMnth[$nowMnth];
		
		$this->mainData = $dteData;
	}
	
	/**
	 * Formats the wrapped value as a date span 'From <date> until <date>'
	 * between the wrapped value and the given end date, collapsing the
	 * month/year when they match on both ends, or as a single date when
	 * both ends fall on the same day.
	 *
	 * @param String|Bool $endingDate End date of the span ('Y-m-d'-style string); required for a result.
	 */
	function toDateSpn( String|Bool $endingDate=false ): Void {
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		$seqlChk = explode('-',$endingDate);
		$tilData = null;
		if( count($seqlChk)==3 ){
			$tilData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		
		$numData = intval($this->mainData);
		$tilData = intval( $tilData );
		
		if($endingDate!=false){
			if(!defined('LOC_MONTHS')){
				define('LOC_MONTHS',';January;February;March;April;May;June;July;August;September;October;November;December');
			}
			$allMnth = explode(';',LOC_MONTHS);
			
			$mthNum1 = date('n', $numData);
			$yerNum1 = date('Y', $numData);
			$mthNum2 = date('n', $tilData);
			$yerNum2 = date('Y', $tilData);
				
			if(!defined('LOC_FROM')){
				define('LOC_FROM','From');
			}	
			if(!defined('LOC_UNTIL')){
				define('LOC_UNTIL','until');
			}
			$dteData = date('j', $numData).' ';
			if( date('Ymd', $numData) == date('Ymd', $tilData) ){
				$dteData = date('d', $numData).' '.$allMnth[$mthNum1].' '.$yerNum1;
			} elseif( $mthNum1 == $mthNum2 && $yerNum1 == $yerNum2 ){
				$dteData = LOC_FROM.' '.date('d', $numData).' '.LOC_UNTIL.' '.date('d', $tilData).' '.$allMnth[$mthNum1].' '.$yerNum1;
			} else {
				if($yerNum1==$yerNum2){
					$dteData = LOC_FROM.' '.date('d', $numData).' '.$allMnth[$mthNum1].' '.LOC_UNTIL.' '.date('d', $tilData).' '.$allMnth[$mthNum2].' '.$yerNum1;
				}else{
					$dteData = LOC_FROM.' '.date('d', $numData).' '.$allMnth[$mthNum1].' '.$yerNum1.' '.LOC_UNTIL.' '.date('d', $tilData).' '.$allMnth[$mthNum2].' '.$yerNum2;
				}
			}
			$this->mainData = $dteData;
		} else {
			$this->mainData = false;
		}
	}
}