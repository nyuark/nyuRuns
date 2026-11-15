<?php

namespace nyu\Tools;

/**
 * A utility object for formatting different types of data.
 *
 * Formats available:
 * - OutText
 * - Integer
 * - Numeric
 * - PriceNb
 * - FloatNb
 * - CustDte
 * - WeekDay
 * - FullDay
 * - ShrtDte
 * - LongDte
 * - SpanDte
 * - EmlAddr
 * - PhoneNb
 * - UrlAddr
 * - UrlText
 * - HtmText
 *
 * FloatNb gives a number; the date formats, EmlAddr, PhoneNb, UrlAddr,
 * UrlText and HtmText give an empty string for a value that is not valid.
 * SpanDte gives one too when it is given no end date. A timestamp is
 * read as a date when it is an Int; a text of digits is no date.
 */
class Variable{
	
	var $wrappedVal;
	
	/**
	 * Wraps a raw value for later formatting.
	 *
	 * @param mixed $valueAsGiven The raw value to wrap.
	 */
	function __construct( Mixed $valueAsGiven ){
		$this->alterHeldValue($valueAsGiven);
	}

	/**
	 * Returns the wrapped value formatted as the given type, by calling
	 * the matching to{$wantedFormat}() method (e.g. 'CustDte' -> formatsCustDte()).
	 *
	 * @param String $wantedFormat Name of the format to apply (must match an existing to*() method).
	 * @param mixed  $formatOption Optional format-specific options passed to the to*() method.
	 * @return mixed The formatted value.
	 */
	function castWrappedVal( String $wantedFormat='OutText', Mixed $formatOption=false ): Mixed {
		if( $wantedFormat!=false && method_exists($this,"formats$wantedFormat") ){
			$mthdName = 'formats'.$wantedFormat;
			if(!$formatOption){
				$rsltVals = call_user_func(array($this, $mthdName));
			}else{				
				$rsltVals = call_user_func(array($this, $mthdName), $formatOption);
			}
			return $this->wrappedVal;
		}
		return $this->wrappedVal;
	}
	
	/**
	 * Replaces the wrapped value.
	 *
	 * @param mixed $valueAsGiven The new raw value to wrap.
	 */
	function alterHeldValue( Mixed $valueAsGiven ): Void {
		$this->wrappedVal = $valueAsGiven;
	}

	/**
	 * Formats the wrapped value as a sanitized string.
	 */
	function formatsOutText(): Void {
		$this->wrappedVal = StrKit::wipeTagsString(
			str_replace( Array("'", '"'), Array('&#39;', '&#34;'), $this->wrappedVal.'' )
		);
	}
	
	/**
	 * Formats the wrapped value as a sanitized integer.
	 */
	function formatsInteger(): Void {
		$this->wrappedVal = intval(filter_var($this->wrappedVal, FILTER_SANITIZE_NUMBER_INT ));
	}
	
	/**
	 * Formats the wrapped value as a number with the given decimal and
	 * thousands separators.
	 *
	 * @param Array $optionsArray Format options: 'decimals', 'decimsep', 'thsndsep'.
	 */
	function formatsNumeric( Array $optionsArray ): Void {
		$numbDict = floatval( filter_var( $this->wrappedVal.'', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION ) );
		$this->wrappedVal = number_format($numbDict, $optionsArray['decimals'], $optionsArray['decimsep'], $optionsArray['thsndsep']);
	}
	
	/**
	 * Formats the wrapped value as a price (2 decimals, '.' decimal
	 * separator, "'" thousands separator).
	 */
	function formatsPriceNb(): Void {
		$this->formatsNumeric( Array('decimals'=>2,'decimsep'=>'.','thsndsep'=>"'") );
	}
	
	/**
	 * Formats the wrapped value as a number: what Numeric reads, given as
	 * a Float instead of a formatted text.
	 */
	function formatsFloatNb(): Void {
		$this->wrappedVal = floatval( filter_var( $this->castScalarText(), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION ) );
	}

	/**
	 * Formats the wrapped value as an email address: the characters an
	 * address cannot hold are removed, and what is still not an address
	 * gives an empty string.
	 */
	function formatsEmlAddr(): Void {
		$emailTxt = strval( filter_var($this->castScalarText(), FILTER_SANITIZE_EMAIL) );
		$this->wrappedVal = (filter_var($emailTxt, FILTER_VALIDATE_EMAIL)!==false) ? $emailTxt : '';
	}

	/**
	 * Formats the wrapped value as a phone number: its digits, after a
	 * '+' when the number is international ('+41 22 345 67 89',
	 * '0041 22 345 67 89' and '+41 (0)22 345 67 89' all give
	 * '+41223456789'). A value holding letters, or fewer than 6 or more
	 * than 15 digits, gives an empty string.
	 */
	function formatsPhoneNb(): Void {
		$rawStrng = trim($this->castScalarText());
		$this->wrappedVal = '';
		if( preg_match('/[a-z]/i', $rawStrng) ){
			return;
		}
		$isGlobal = (preg_match('/^(\+|00)/', $rawStrng)===1);
		if( $isGlobal ){
			// The (0) written after a country code is the national prefix, not dialled from abroad
			$rawStrng = str_replace('(0)', '', preg_replace('/^00/', '', $rawStrng));
		}
		$digtText = preg_replace('/\D/', '', $rawStrng);
		if( strlen($digtText)>=6 && strlen($digtText)<=15 ){
			$this->wrappedVal = ($isGlobal ? '+' : '').$digtText;
		}
	}

	/**
	 * Formats the wrapped value as a web address: kept as it is when it is
	 * a valid http or https address, an empty string otherwise. Any other
	 * scheme (javascript:, data:, file:) is refused, and so is an address
	 * holding a quote, an angle bracket or a space, which could close the
	 * attribute it is written in.
	 */
	function formatsUrlAddr(): Void {
		$urlStrng = trim($this->castScalarText());
		$urlSchme = strtolower( strval(parse_url($urlStrng, PHP_URL_SCHEME)) );
		$isAddrOk = filter_var($urlStrng, FILTER_VALIDATE_URL)!==false && in_array($urlSchme, Array('http', 'https'), true)
			&& !preg_match('/["\'<>`\s\x00-\x1F]/', $urlStrng);
		$this->wrappedVal = $isAddrOk ? $urlStrng : '';
	}

	/**
	 * Formats the wrapped value as a text that can stand in a web address:
	 * lower case, accents removed, anything that is not a letter or a
	 * digit turned into a single dash ('Avenue des Rêves' gives
	 * 'avenue-des-reves').
	 */
	function formatsUrlText(): Void {
		$acctDict = Array(
			'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'ae', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e',
			'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'œ'=>'oe',
			'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ÿ'=>'y', 'ß'=>'ss',
			'À'=>'a', 'Á'=>'a', 'Â'=>'a', 'Ã'=>'a', 'Ä'=>'a', 'Å'=>'a', 'Æ'=>'ae', 'Ç'=>'c', 'È'=>'e', 'É'=>'e', 'Ê'=>'e', 'Ë'=>'e',
			'Ì'=>'i', 'Í'=>'i', 'Î'=>'i', 'Ï'=>'i', 'Ñ'=>'n', 'Ò'=>'o', 'Ó'=>'o', 'Ô'=>'o', 'Õ'=>'o', 'Ö'=>'o', 'Ø'=>'o', 'Œ'=>'oe',
			'Ù'=>'u', 'Ú'=>'u', 'Û'=>'u', 'Ü'=>'u', 'Ý'=>'y', 'Ÿ'=>'y'
		);
		$slugText = strtolower( strtr($this->castScalarText(), $acctDict) );
		$this->wrappedVal = trim( preg_replace('/[^a-z0-9]+/', '-', $slugText), '-' );
	}

	/**
	 * Formats the wrapped value as HTML reduced to what is safe to show:
	 * text tags, lists, headings and links are kept without any of their
	 * attributes, a link keeping only an http, https or mailto address.
	 * Scripts, styles and embedded content are removed with what they
	 * hold; any other tag is removed and its text kept.
	 */
	function formatsHtmText(): Void {
		if( !class_exists('DOMDocument') ){
			// Without the reader, no tag is kept at all
			$this->formatsOutText();
			return;
		}
		$domDcmnt = new \DOMDocument();
		$oldCodig = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$domDcmnt->loadHTML('<?xml encoding="UTF-8"><body>'.str_replace("\0", '', $this->castScalarText()).'</body>', LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($oldCodig);
		// The whole document is written, so that a text set outside its body is not lost
		$rootNode = $domDcmnt->documentElement;
		$this->wrappedVal = ($rootNode!==null) ? $this->renderSafeHtml($rootNode) : '';
	}

	/**
	 * Writes what an element holds as safe HTML (see toHtmText).
	 *
	 * @param \DOMNode $outerElement The element whose content is written.
	 * @return String The content, reduced to the tags kept.
	 */
	protected function renderSafeHtml( \DOMNode $outerElement ): String {
		$keepTags = Array('p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code', 'pre', 'a');
		$dropTags = Array('script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'head', 'title', 'textarea', 'select', 'svg', 'math');
		$outStrng = '';
		foreach( $outerElement->childNodes as $oneNde ){
			if( $oneNde->nodeType==XML_TEXT_NODE || $oneNde->nodeType==XML_CDATA_SECTION_NODE ){
				$outStrng .= htmlspecialchars($oneNde->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
				continue;
			}
			if( $oneNde->nodeType!=XML_ELEMENT_NODE ){
				continue;
			}
			$tagTitle = strtolower($oneNde->nodeName);
			if( in_array($tagTitle, $dropTags, true) ){
				continue;
			}
			$innerTxt = $this->renderSafeHtml($oneNde);
			if( !in_array($tagTitle, $keepTags, true) ){
				$outStrng .= $innerTxt;
			}elseif( $tagTitle=='br' || $tagTitle=='hr' ){
				$outStrng .= '<'.$tagTitle.'>';
			}elseif( $tagTitle!='a' ){
				$outStrng .= '<'.$tagTitle.'>'.$innerTxt.'</'.$tagTitle.'>';
			}else{
				// A link is kept only with an address of a scheme that runs nothing
				$nlaceUrl = trim($oneNde->getAttribute('href'));
				if( preg_match('#^(https?://|mailto:)#i', $nlaceUrl) ){
					$outStrng .= '<a href="'.htmlspecialchars($nlaceUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$innerTxt.'</a>';
				}else{
					$outStrng .= $innerTxt;
				}
			}
		}
		return $outStrng;
	}

	/**
	 * Gives the wrapped value as a text, an empty one for a value that is
	 * not a single value (array, object, null).
	 *
	 * @return String The value as a text.
	 */
	protected function castScalarText(): String {
		return is_scalar($this->wrappedVal) ? strval($this->wrappedVal) : '';
	}

	/**
	 * Reads the wrapped value as a date: a 'Y-m-d' date, with or without
	 * a time after it, or a timestamp given as an Int. A text of digits
	 * is no date: '2026' would be read as a second of 1970.
	 *
	 * @return ?Int The timestamp of the date at noon, the timestamp given, or null when the value is no date.
	 */
	protected function castDateToUnix(): ?Int {
		if( is_int($this->wrappedVal) ){
			return ($this->wrappedVal>=0) ? $this->wrappedVal : null;
		}
		$dateText = trim($this->castScalarText());
		$dateList = Array();
		if( preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $dateText, $dateList) ){
			if( !checkdate(intval($dateList[2]), intval($dateList[3]), intval($dateList[1])) ){
				return null;
			}
			return mktime(12, 0, 0, intval($dateList[2]), intval($dateList[3]), intval($dateList[1]));
		}
		return null;
	}

	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as a short
	 * date, the month abbreviated: '1 Oct 2026'. A value that is no date
	 * gives an empty string.
	 */
	function formatsShrtDte(): Void {
		if(!defined('LOC_MTHSHT')){
			define('LOC_MTHSHT',';Jan;Feb;Mar;Apr;May;Jun;Jul;Aug;Sep;Oct;Nov;Dec');
		}
		$timeStmp = $this->castDateToUnix();
		$allMonth = explode(';',LOC_MTHSHT);
		$this->wrappedVal = ($timeStmp===null) ? '' : date('j', $timeStmp).' '.$allMonth[intval(date('n', $timeStmp))].' '.date('Y', $timeStmp);
	}

	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as a date
	 * in words: '1 October 2026'. A value that is no date gives an empty
	 * string.
	 */
	function formatsLongDte(): Void {
		if(!defined('LOC_MONTHS')){
			define('LOC_MONTHS',';January;February;March;April;May;June;July;August;September;October;November;December');
		}
		$timeStmp = $this->castDateToUnix();
		$allMonth = explode(';',LOC_MONTHS);
		$this->wrappedVal = ($timeStmp===null) ? '' : date('j', $timeStmp).' '.$allMonth[intval(date('n', $timeStmp))].' '.date('Y', $timeStmp);
	}


	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) with the
	 * given date() format. A value that is no date gives an empty string.
	 *
	 * @param String $dateTemplate A date() format string ('d/m/y' when empty).
	 */
	function formatsCustDte( String $dateTemplate='' ): Void {
		$timeStmp = $this->castDateToUnix();
		if( $dateTemplate=='' ) $dateTemplate='d/m/y';
		$this->wrappedVal = ($timeStmp===null) ? '' : date( $dateTemplate, $timeStmp );
	}
	
	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as the
	 * English weekday name it falls on. A value that is no date gives an
	 * empty string.
	 */
	function formatsWeekDay( ): Void {
		$timeStmp = $this->castDateToUnix();
		if( $timeStmp===null ){
			$this->wrappedVal = '';
			return;
		}
		if(!defined('LOC_WKDAYS')){
			define('LOC_WKDAYS','Sunday;Monday;Tuesday;Wednesday;Thursday;Friday;Saturday');
		}
		$wdayList = explode(';',LOC_WKDAYS);
		$wdayNumb = date('w', $timeStmp);
		$wdayName = $wdayList[$wdayNumb];

		$this->wrappedVal = $wdayName;
	}
	
	/**
	 * Formats the wrapped value (a 'Y-m-d' date or a timestamp) as a full
	 * localized date: 'Yesterday'/'Today'/'Tomorrow' for nearby dates,
	 * otherwise '<Weekday>, <day> <Month>'. A value that is no date gives
	 * an empty string.
	 */
	function formatsFullDay(): Void {
		$timeStmp = $this->castDateToUnix();
		if( $timeStmp===null ){
			$this->wrappedVal = '';
			return;
		}
		if(!defined('LOC_YSTRD'))define('LOC_YSTRD','Yesterday');
		if(!defined('LOC_TODAY'))define('LOC_TODAY','Today');
		if(!defined('LOC_TOMRW'))define('LOC_TOMRW','Tomorrow');
		
		if( date('Ymd',( time()-(3600*24) ) ) == date('Ymd',$timeStmp) ) {
			$this->wrappedVal = LOC_YSTRD;
			return;
		}
		
		if( date('Ymd') == date('Ymd',$timeStmp) ) {
			$this->wrappedVal = LOC_TODAY;
			return;
		}
		
		if( date('Ymd',( time()+(3600*24) ) ) == date('Ymd',$timeStmp) ) {
			$this->wrappedVal = LOC_TOMRW;
			return;
		}
		
		if(!defined('LOC_WKDAYS')){
			define('LOC_WKDAYS','Sunday;Monday;Tuesday;Wednesday;Thursday;Friday;Saturday');
		}
		$wdayList = explode(';',LOC_WKDAYS);
		$wdayNumb = date('w', $timeStmp);
		$wdayName = $wdayList[$wdayNumb];

		$dateDict =$wdayName.', '.date('j', $timeStmp).' ';
		
		if(!defined('LOC_MONTHS')){
			define('LOC_MONTHS',';January;February;March;April;May;June;July;August;September;October;November;December');
		}
		$allMonth = explode(';',LOC_MONTHS);
		
		$nowMonth = date('n', $timeStmp);
		$dateDict .= $allMonth[$nowMonth];
		
		$this->wrappedVal = $dateDict;
	}
	
	/**
	 * Formats the wrapped value as a date span 'From <date> until <date>'
	 * between the wrapped value and the given end date, collapsing the
	 * month/year when they match on both ends, or as a single date when
	 * both ends fall on the same day. Without an end date, or with a
	 * start or an end that is no date, the result is an empty string.
	 *
	 * @param String|Int|Bool $spanLastDate End date of the span (a 'Y-m-d' date or a timestamp); required for a result.
	 */
	function formatsSpanDte( String|Int|Bool $spanLastDate=false ): Void {
		$numbDict = $this->castDateToUnix();
		$tellsVar = new Variable($spanLastDate);
		$tellsDct = $tellsVar->castDateToUnix();

		if($spanLastDate!=false){
			if( $numbDict===null || $tellsDct===null ){
				$this->wrappedVal = '';
				return;
			}
			if(!defined('LOC_MONTHS')){
				define('LOC_MONTHS',';January;February;March;April;May;June;July;August;September;October;November;December');
			}
			$allMonth = explode(';',LOC_MONTHS);
			
			$mnthNum1 = date('n', $numbDict);
			$yearNum1 = date('Y', $numbDict);
			$mnthNum2 = date('n', $tellsDct);
			$yearNum2 = date('Y', $tellsDct);
				
			if(!defined('LOC_FROM')){
				define('LOC_FROM','From');
			}	
			if(!defined('LOC_UNTIL')){
				define('LOC_UNTIL','until');
			}
			$dateDict = date('j', $numbDict).' ';
			if( date('Ymd', $numbDict) == date('Ymd', $tellsDct) ){
				$dateDict = date('d', $numbDict).' '.$allMonth[$mnthNum1].' '.$yearNum1;
			} elseif( $mnthNum1 == $mnthNum2 && $yearNum1 == $yearNum2 ){
				$dateDict = LOC_FROM.' '.date('d', $numbDict).' '.LOC_UNTIL.' '.date('d', $tellsDct).' '.$allMonth[$mnthNum1].' '.$yearNum1;
			} else {
				if($yearNum1==$yearNum2){
					$dateDict = LOC_FROM.' '.date('d', $numbDict).' '.$allMonth[$mnthNum1].' '.LOC_UNTIL.' '.date('d', $tellsDct).' '.$allMonth[$mnthNum2].' '.$yearNum1;
				}else{
					$dateDict = LOC_FROM.' '.date('d', $numbDict).' '.$allMonth[$mnthNum1].' '.$yearNum1.' '.LOC_UNTIL.' '.date('d', $tellsDct).' '.$allMonth[$mnthNum2].' '.$yearNum2;
				}
			}
			$this->wrappedVal = $dateDict;
		} else {
			$this->wrappedVal = '';
		}
	}
}