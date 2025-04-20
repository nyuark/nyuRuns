<?php

// An utility object for formating different type of data
// Formats available:
/*
	- TextStr
	- Integer
	- Numeric
	- PriceNb
	- FloatNb
	- DateStr
	- WeekDay
	- FullDay
	- ShrDate
	- TxtDate
	- DateSpn
	- EmlAddr
	- PhoneNb
	- UrlAddr
	- UrlText
	- HtmText
	FloatNb gives a number; the date formats, EmlAddr, PhoneNb, UrlAddr,
	UrlText and HtmText give an empty string for a value that is not valid.
	DateSpn gives one too when it is given no end date. A timestamp is
	read as a date when it is an Int; a text of digits is no date.
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
	 * Formats the wrapped value as a number: what Numeric reads, given as
	 * a Float instead of a formatted text.
	 */
	function toFloatNb(): Void {
		$this->mainData = floatval( filter_var( $this->scalarStr(), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION ) );
	}

	/**
	 * Formats the wrapped value as an email address: the characters an
	 * address cannot hold are removed, and what is still not an address
	 * gives an empty string.
	 */
	function toEmlAddr(): Void {
		$emlText = strval( filter_var($this->scalarStr(), FILTER_SANITIZE_EMAIL) );
		$this->mainData = (filter_var($emlText, FILTER_VALIDATE_EMAIL)!==false) ? $emlText : '';
	}

	/**
	 * Formats the wrapped value as a phone number: its digits, after a
	 * '+' when the number is international ('+41 22 345 67 89',
	 * '0041 22 345 67 89' and '+41 (0)22 345 67 89' all give
	 * '+41223456789'). A value holding letters, or fewer than 6 or more
	 * than 15 digits, gives an empty string.
	 */
	function toPhoneNb(): Void {
		$rawText = trim($this->scalarStr());
		$this->mainData = '';
		if( preg_match('/[a-z]/i', $rawText) ){
			return;
		}
		$plusSgn = (preg_match('/^(\+|00)/', $rawText)===1);
		if( $plusSgn ){
			// The (0) written after a country code is the national prefix, not dialled from abroad
			$rawText = str_replace('(0)', '', preg_replace('/^00/', '', $rawText));
		}
		$digtStr = preg_replace('/\D/', '', $rawText);
		if( strlen($digtStr)>=6 && strlen($digtStr)<=15 ){
			$this->mainData = ($plusSgn ? '+' : '').$digtStr;
		}
	}

	/**
	 * Formats the wrapped value as a web address: kept as it is when it is
	 * a valid http or https address, an empty string otherwise. Any other
	 * scheme (javascript:, data:, file:) is refused, and so is an address
	 * holding a quote, an angle bracket or a space, which could close the
	 * attribute it is written in.
	 */
	function toUrlAddr(): Void {
		$urlText = trim($this->scalarStr());
		$urlSchm = strtolower( strval(parse_url($urlText, PHP_URL_SCHEME)) );
		$isValid = filter_var($urlText, FILTER_VALIDATE_URL)!==false && in_array($urlSchm, Array('http', 'https'), true)
			&& !preg_match('/["\'<>`\s\x00-\x1F]/', $urlText);
		$this->mainData = $isValid ? $urlText : '';
	}

	/**
	 * Formats the wrapped value as a text that can stand in a web address:
	 * lower case, accents removed, anything that is not a letter or a
	 * digit turned into a single dash ('Avenue des Rêves' gives
	 * 'avenue-des-reves').
	 */
	function toUrlText(): Void {
		$accnMap = Array(
			'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'ae', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
			'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'œ'=>'oe',
			'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ÿ'=>'y', 'ß'=>'ss',
			'À'=>'a', 'Á'=>'a', 'Â'=>'a', 'Ã'=>'a', 'Ä'=>'a', 'Å'=>'a', 'Æ'=>'ae', 'Ç'=>'c', 'È'=>'e', 'É'=>'e', 'Ê'=>'e', 'Ë'=>'e',
			'Ì'=>'i', 'Í'=>'i', 'Î'=>'i', 'Ï'=>'i', 'Ñ'=>'n', 'Ò'=>'o', 'Ó'=>'o', 'Ô'=>'o', 'Õ'=>'o', 'Ö'=>'o', 'Ø'=>'o', 'Œ'=>'oe',
			'Ù'=>'u', 'Ú'=>'u', 'Û'=>'u', 'Ü'=>'u', 'Ý'=>'y', 'Ÿ'=>'y'
		);
		$slgText = strtolower( strtr($this->scalarStr(), $accnMap) );
		$this->mainData = trim( preg_replace('/[^a-z0-9]+/', '-', $slgText), '-' );
	}

	/**
	 * Formats the wrapped value as HTML reduced to what is safe to show:
	 * text tags, lists, headings and links are kept without any of their
	 * attributes, a link keeping only an http, https or mailto address.
	 * Scripts, styles and embedded content are removed with what they
	 * hold; any other tag is removed and its text kept.
	 */
	function toHtmText(): Void {
		if( !class_exists('DOMDocument') ){
			// Without the reader, no tag is kept at all
			$this->toTextStr();
			return;
		}
		$domDocu = new \DOMDocument();
		$oldMode = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$domDocu->loadHTML('<?xml encoding="UTF-8"><body>'.str_replace("\0", '', $this->scalarStr()).'</body>', LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($oldMode);
		// The whole document is written, so that a text set outside its body is not lost
		$rootNde = $domDocu->documentElement;
		$this->mainData = ($rootNde!==null) ? $this->htmlNodes($rootNde) : '';
	}

	/**
	 * Writes what an element holds as safe HTML (see toHtmText).
	 *
	 * @param \DOMNode $parentNode The element whose content is written.
	 * @return String The content, reduced to the tags kept.
	 */
	protected function htmlNodes( \DOMNode $parentNode ): String {
		$keepTag = Array('p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code', 'pre', 'a');
		$dropTag = Array('script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'head', 'title', 'textarea', 'select', 'svg', 'math');
		$outText = '';
		foreach( $parentNode->childNodes as $kidNde ){
			if( $kidNde->nodeType==XML_TEXT_NODE || $kidNde->nodeType==XML_CDATA_SECTION_NODE ){
				$outText .= htmlspecialchars($kidNde->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
				continue;
			}
			if( $kidNde->nodeType!=XML_ELEMENT_NODE ){
				continue;
			}
			$tagName = strtolower($kidNde->nodeName);
			if( in_array($tagName, $dropTag, true) ){
				continue;
			}
			$inrText = $this->htmlNodes($kidNde);
			if( !in_array($tagName, $keepTag, true) ){
				$outText .= $inrText;
			}elseif( $tagName=='br' || $tagName=='hr' ){
				$outText .= '<'.$tagName.'>';
			}elseif( $tagName!='a' ){
				$outText .= '<'.$tagName.'>'.$inrText.'</'.$tagName.'>';
			}else{
				// A link is kept only with an address of a scheme that runs nothing
				$linkUrl = trim($kidNde->getAttribute('href'));
				if( preg_match('#^(https?://|mailto:)#i', $linkUrl) ){
					$outText .= '<a href="'.htmlspecialchars($linkUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$inrText.'</a>';
				}else{
					$outText .= $inrText;
				}
			}
		}
		return $outText;
	}

	/**
	 * Gives the wrapped value as a text, an empty one for a value that is
	 * not a single value (array, object, null).
	 *
	 * @return String The value as a text.
	 */
	protected function scalarStr(): String {
		return is_scalar($this->mainData) ? strval($this->mainData) : '';
	}

	/**
	 * Reads the wrapped value as a date: a 'Y-m-d' date, with or without
	 * a time after it, or a timestamp given as an Int. A text of digits
	 * is no date: '2026' would be read as a second of 1970.
	 *
	 * @return ?Int The timestamp of the date at noon, the timestamp given, or null when the value is no date.
	 */
	protected function dateStamp(): ?Int {
		if( is_int($this->mainData) ){
			return ($this->mainData>=0) ? $this->mainData : null;
		}
		$dteText = trim($this->scalarStr());
		$dtePrts = Array();
		if( preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $dteText, $dtePrts) ){
			if( !checkdate(intval($dtePrts[2]), intval($dtePrts[3]), intval($dtePrts[1])) ){
				return null;
			}
			return mktime(12, 0, 0, intval($dtePrts[2]), intval($dtePrts[3]), intval($dtePrts[1]));
		}
		return null;
	}

	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as a short
	 * date, the month abbreviated: '1 Oct 2026'. A value that is no date
	 * gives an empty string.
	 */
	function toShrDate(): Void {
		if(!defined('LOC_MTHSHT')){
			define('LOC_MTHSHT',';Jan;Feb;Mar;Apr;May;Jun;Jul;Aug;Sep;Oct;Nov;Dec');
		}
		$tmeStmp = $this->dateStamp();
		$allMnth = explode(';',LOC_MTHSHT);
		$this->mainData = ($tmeStmp===null) ? '' : date('j', $tmeStmp).' '.$allMnth[intval(date('n', $tmeStmp))].' '.date('Y', $tmeStmp);
	}

	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as a date
	 * in words: '1 October 2026'. A value that is no date gives an empty
	 * string.
	 */
	function toTxtDate(): Void {
		if(!defined('LOC_MONTHS')){
			define('LOC_MONTHS',';January;February;March;April;May;June;July;August;September;October;November;December');
		}
		$tmeStmp = $this->dateStamp();
		$allMnth = explode(';',LOC_MONTHS);
		$this->mainData = ($tmeStmp===null) ? '' : date('j', $tmeStmp).' '.$allMnth[intval(date('n', $tmeStmp))].' '.date('Y', $tmeStmp);
	}


	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) with the
	 * given date() format. A value that is no date gives an empty string.
	 *
	 * @param String $dateFormat A date() format string ('d/m/y' when empty).
	 */
	function toDateStr( String $dateFormat='' ): Void {
		$tmeStmp = $this->dateStamp();
		if( $dateFormat=='' ) $dateFormat='d/m/y';
		$this->mainData = ($tmeStmp===null) ? '' : date( $dateFormat, $tmeStmp );
	}
	
	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as the
	 * English weekday name it falls on. A value that is no date gives an
	 * empty string.
	 */
	function toWeekDay( ): Void {
		$tmeStmp = $this->dateStamp();
		if( $tmeStmp===null ){
			$this->mainData = '';
			return;
		}
		if(!defined('LOC_WKDAYS')){
			define('LOC_WKDAYS','Sunday;Monday;Tuesday;Wednesday;Thursday;Friday;Saturday');
		}
		$allDays = explode(';',LOC_WKDAYS);
		$currDay = date('w', $tmeStmp);
		$weekDay = $allDays[$currDay];

		$this->mainData = $weekDay;
	}
	
	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as a full
	 * localized date: 'Yesterday'/'Today'/'Tomorrow' for nearby dates,
	 * otherwise '<Weekday>, <day> <Month>'. A value that is no date gives
	 * an empty string.
	 */
	function toFullDay(): Void {
		$tmeStmp = $this->dateStamp();
		if( $tmeStmp===null ){
			$this->mainData = '';
			return;
		}
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
		$currDay = date('w', $tmeStmp);
		$weekDay = $allDays[$currDay];

		$dteData =$weekDay.', '.date('j', $tmeStmp).' ';
		
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
	 * both ends fall on the same day. Without an end date, or with a
	 * start or an end that is no date, the result is an empty string.
	 *
	 * @param String|Int|Bool $endingDate End date of the span (a 'Y-m-d' date or a timestamp); required for a result.
	 */
	function toDateSpn( String|Int|Bool $endingDate=false ): Void {
		$numData = $this->dateStamp();
		$tilVarb = new Variable($endingDate);
		$tilData = $tilVarb->dateStamp();

		if($endingDate!=false){
			if( $numData===null || $tilData===null ){
				$this->mainData = '';
				return;
			}
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
			$this->mainData = '';
		}
	}
}