<?php

// An utility object for formating different type of data
// Formats available:
/*
	- String
	- Integer
	- Number
	- Price
	- Date
	- WeekDay
	- FullDate
	- TODO: ShortDate
	- DateSpan
	- TODO: Email
	- TODO: Phone
	- TODO: URL
	- TODO: URLText
	- TODO: HTMText
*/

namespace nyu\Tools;

class Variable{
	
	var $mainData;
	
	function __construct($data){
		$this->set($data);
	}

	function get( String $respFormat='String', $formatOpts=false ){
		if( $format!=false && method_exists($this,"to$format") ){
			if(!$formatOpts){
				$formatOpts = false;
			}
			$meth = 'to'.$format;
			$out = call_user_func(array($this, $meth),$param);
			return $this->mainData;
		}
		return $this->mainData;
	}
	
	function set( $data ){
		$this->mainData = $data;
	}

	function toString(){
		$this->mainData = filter_var($this->mainData.'', FILTER_SANITIZE_STRING);
	}
	
	function toInteger(){
		$this->mainData = intval(filter_var($this->mainData, FILTER_SANITIZE_NUMBER_INT )+0);
	}
	
	function toNumber( $options ){
		$this->mainData = number_format(($this->mainData+0), $options['decimals'], $options['decimsep'], $options['thsndsep']);
	}
	
	function toPrice(){
		$this->toNumber( Array('decimals'=>2,'decimsep'=>'.','thsndsep'=>"'") );
		$this->mainData = number_format($this->mainData,'2','.',"'");
	}
	
	function toDate( $format='' ){
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		if( $format=='' ) $format=='d/m/y';
		$this->mainData = date( $format, $this->mainData );
	}
	
	function toWeekDay( ){
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		$this->toNumber();
		
		if(!defined('LOC_WKDAYS')){
			define('LOC_WKDAYS','Sunday;Monday;Tuesday;Wednesday;Thursday;Friday;Saturday');
		}
		$allDays = explode(';',LOC_WKDAYS);
		$currDay = date('w', $this->mainData);
		$weekDay = $allDays[$currDay];
		
		$this->mainData = $weekDay;
	}
	
	function toFullDate(){
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		$this->toNumber();
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
	
	function toDateSpan($until=false){
		$seqlChk = explode('-',$this->mainData);
		if( count($seqlChk)==3 ){
			$this->mainData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		$seqlChk = explode('-',$until);
		if( count($seqlChk)==3 ){
			$tilData = mktime(12,0,0,$seqlChk[1],$seqlChk[2],$seqlChk[0]);
		}
		
		$numData = intval($this->mainData);
		$tilData = intval( $tilData );
		
		if($until!=false){
			if(!defined('LOC_MONTHS')){
				define('LOC_MONTHS',';January;February;March;April;May;June;July;August;September;October;November;December');
			}
			$allMnth = explode(';',LOC_MONTHS);
			
			$mthNum1 = date('n', $numData);
			$yerNum1 = date('Y', $numData);
			$mthNum2 = date('n', $tilData);
			$yerNum2 = date('Y', $tilData);
						
			$dteData = date('j', $numData).' ';
			if( $mthNum1 == $mthNum2 ){
				$dteData = LOC_FROM.' '.date('d', $numData).' '.LOC_UNTIL.' '.date('d', $tilData).' '.$months[$mthNum2].' '.$yerNum1;
			} else {
				if($yer==$yer2){
					$dteData = LOC_FROM.' '.date('d', $numData).' '.$allMnth[$mthNum1].' '.LOC_UNTIL.' '.date('d', $tilData).' '.$allMnth[$mthNum2].' '.$yerNum1;
				}else{
					$dteData = LOC_FROM.' '.date('d', $numData).' '.$allMnth[$mthNum1].' '.$yerNum1.' '.LOC_UNTIL.' '.date('d', $tilData).' '.$months[$mthNum2].' '.$yerNum2;
				}
			}
			
			return $dteData;
		} else {
			return false;
		}
	}
	
	function toEMail(){
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
}