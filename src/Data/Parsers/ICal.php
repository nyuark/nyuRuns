<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * Calendars (ics): the events, the tasks and the notes of a calendar,
 * each as plain keys and values.
 *
 * Dates are ISO text, in the time zone they are written in unless the
 * 'tzone' option asks for another. An event lasting whole days ends on
 * its last day, where the format writes the day after. A repeating
 * event gives its rule, not its occurrences. What the parser does not
 * read into a key of its own is kept, as written, under 'extra'.
 */
class ICal extends Base {

	// The kinds of entry, by the name the format gives them
	const KIND_LIST = Array('VEVENT'=>'calEvent', 'VTODO'=>'calTodos', 'VJOURNAL'=>'calNotes');
	// The states of an entry, the replies of a guest, and how often an entry repeats
	const STAT_LIST = Array('CONFIRMED'=>'definite', 'TENTATIVE'=>'possible', 'CANCELLED'=>'canceled', 'NEEDS-ACTION'=>'awaiting', 'IN-PROCESS'=>'underway', 'COMPLETED'=>'finished', 'DRAFT'=>'drafting', 'FINAL'=>'finalVer');
	const PART_STAT = Array('ACCEPTED'=>'accepted', 'DECLINED'=>'declined', 'TENTATIVE'=>'possible', 'NEEDS-ACTION'=>'awaiting', 'DELEGATED'=>'delegate');
	const FREQ_LIST = Array('DAILY'=>'eachDays', 'WEEKLY'=>'eachWeek', 'MONTHLY'=>'eachMnth', 'YEARLY'=>'eachYear');
	// The days of the week, as rules write them, from Monday
	const DAYS_LIST = Array('mo', 'tu', 'we', 'th', 'fr', 'sa', 'su');
	// Greatest number of spans (days, weeks, months or years) a rule is followed over
	const SPAN_MAXI = 20000;
	// The properties read into a key of their own: the others are kept under 'extra'
	const READ_LIST = Array('UID', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL', 'STATUS', 'CATEGORIES', 'ORGANIZER', 'ATTENDEE', 'RRULE', 'EXDATE', 'DTSTART', 'DTEND', 'DURATION', 'DTSTAMP', 'DUE', 'PERCENT-COMPLETE');

	/**
	 * Parses a calendar. An empty content gives a calendar without entry.
	 *
	 * Options:
	 *   'tzone' => gives the times in this time zone, as 'Europe/Paris'
	 *              or 'UTC'. The entries lasting whole days, and the
	 *              times written without a zone, are left as they are;
	 *   'upper' => upper-cases the keys of the first level.
	 *
	 * @param String $nakedContent The calendar.
	 * @param Array  $parseOptions Options: 'tzone', 'upper'.
	 * @return Array The envelope, of shape 'nodes', holding the events under 'calEvent', the tasks under 'calTodos' and the notes under 'calNotes'; 'infos' holds the name of the calendar under 'title', its time zone under 'tzone', the program that wrote it under 'maker' and the number of its entries under 'count'.
	 *
	 * @throws ParseError When the content is not a calendar, an entry is not closed, or the time zone asked for is not a known one.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$trgtZone = null;
		if( strval($parseOptions['tzone'] ?? '')!=='' ){
			try{
				$trgtZone = new \DateTimeZone(strval($parseOptions['tzone']));
			}catch( \Exception $e ){
				throw new ParseError('Invalid calendar option: unknown time zone '.strval($parseOptions['tzone']), 0, $e);
			}
		}
		$itemList = Array('calEvent'=>Array(), 'calTodos'=>Array(), 'calNotes'=>Array());
		$infoList = Array('title'=>'', 'tzone'=>'', 'maker'=>'', 'count'=>0);
		foreach($this->readComponents($this->foldLinesArray(self::recodeTextUtf8($nakedContent))) as $cldArr){
			foreach(Array('X-WR-CALNAME'=>'title', 'X-WR-TIMEZONE'=>'tzone', 'PRODID'=>'maker') as $prpNme=>$infKey){
				foreach($cldArr['props'] as $prpSet){
					if( $prpSet['names']==$prpNme && $infoList[$infKey]==='' ){
						$infoList[$infKey] = $this->unescapeTxtVal($prpSet['value']);
					}
				}
			}
			foreach($cldArr['comps'] as $cmpArr){
				if( isset(self::KIND_LIST[$cmpArr['kinds']]) ){
					$itemList[self::KIND_LIST[$cmpArr['kinds']]][] = $this->entryReadArray($cmpArr, self::KIND_LIST[$cmpArr['kinds']], $trgtZone);
					$infoList['count']++;
				}
			}
		}
		return $this->makeShapeArray('nodes', !empty($parseOptions['upper']) ? $this->upperKeysArray($itemList) : $itemList, $infoList);
	}

	/**
	 * Cuts a calendar into its lines. A line too long for the format is
	 * written over several, each of the following ones starting with a
	 * space: they are brought back into one.
	 *
	 * @param String $nakedContent The calendar, in UTF-8.
	 * @return Array The lines, each with its text and the number of the line it starts on.
	 */
	protected function foldLinesArray(String $nakedContent): Array {
		$lineList = Array();
		foreach(preg_split('/\r\n|\r|\n/', $nakedContent) as $linIdx=>$linTxt){
			if( $linTxt!=='' && ($linTxt[0]==' ' || $linTxt[0]=="\t") && !empty($lineList) ){
				$lineList[count($lineList)-1][0] .= substr($linTxt, 1);
			}elseif( trim($linTxt)!=='' ){
				$lineList[] = Array($linTxt, $linIdx+1);
			}
		}
		return $lineList;
	}

	/**
	 * Reads the lines of a calendar into its calendars, their entries
	 * and what these hold.
	 *
	 * @param Array $linesToParse The lines, as foldLinesArray() gives them.
	 * @return Array The calendars, each with its kind under 'kinds', its properties under 'props' (each with its name under 'names', what qualifies it under 'parms' and its value under 'value') and what it holds under 'comps'.
	 *
	 * @throws ParseError When the content is not a calendar, or what is opened and what is closed do not match.
	 */
	protected function readComponents(Array $linesToParse): Array {
		$rootList = Array();
		// What is being read, from the calendar down to the innermost entry, and the line each was opened on
		$openList = Array();
		$openLnes = Array();
		foreach($linesToParse as $linSet){
			// A line is a name, what qualifies it after semicolons, then its value after the first colon that is not between quotes
			$headPart = Array();
			if( !preg_match('/^((?:[^":]|"[^"]*")*):(.*)$/Ds', $linSet[0], $headPart) || trim($headPart[1])==='' ){
				throw new ParseError('Invalid calendar content: a line that is not a property on line '.$linSet[1]);
			}
			$namePart = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $headPart[1]);
			$propName = strtoupper(trim(strval(array_shift($namePart))));
			$propVals = $headPart[2];
			if( $propName=='BEGIN' ){
				if( empty($openList) && strtoupper(trim($propVals))!='VCALENDAR' ){
					throw new ParseError('Invalid calendar content: the content does not start a calendar on line '.$linSet[1]);
				}
				if( count($openList)>=8 ){
					throw new ParseError('Invalid calendar content: nested deeper than allowed on line '.$linSet[1]);
				}
				$openList[] = Array('kinds'=>strtoupper(trim($propVals)), 'props'=>Array(), 'comps'=>Array());
				$openLnes[] = $linSet[1];
				continue;
			}
			if( empty($openList) ){
				throw new ParseError('Invalid calendar content: the content does not start a calendar on line '.$linSet[1]);
			}
			if( $propName=='END' ){
				$doneCmpl = array_pop($openList);
				array_pop($openLnes);
				if( $doneCmpl['kinds']!==strtoupper(trim($propVals)) ){
					throw new ParseError('Invalid calendar content: '.strtoupper(trim($propVals)).' closed where '.$doneCmpl['kinds'].' is open on line '.$linSet[1]);
				}
				if( empty($openList) ){
					$rootList[] = $doneCmpl;
				}else{
					$openList[count($openList)-1]['comps'][] = $doneCmpl;
				}
				continue;
			}
			$argsList = Array();
			foreach($namePart as $argTxt){
				$prmtPart = explode('=', $argTxt, 2);
				$argsList[strtoupper(trim($prmtPart[0]))] = trim(strval($prmtPart[1] ?? ''), " \t\"");
			}
			$openList[count($openList)-1]['props'][] = Array('names'=>$propName, 'parms'=>$argsList, 'value'=>$propVals);
		}
		if( !empty($openList) ){
			throw new ParseError('Invalid calendar content: '.$openList[count($openList)-1]['kinds'].' is never closed, opened on line '.$openLnes[count($openLnes)-1]);
		}
		return $rootList;
	}

	/**
	 * Gives a text as it reads: the characters the format writes after a
	 * backslash are brought back.
	 *
	 * @param String $valOfTagText The text, as written.
	 * @return String The text.
	 */
	protected function unescapeTxtVal(String $valOfTagText): String {
		return trim(preg_replace_callback('/\\\\(.)/s', function($locatedParts){ return (strtolower($locatedParts[1])=='n') ? "\n" : $locatedParts[1]; }, $valOfTagText));
	}

	/**
	 * Cuts a value holding several texts, separated by commas.
	 *
	 * @param String $valOfTagText The value, as written.
	 * @return Array The texts, none of them empty.
	 */
	protected function splitListValue(String $valOfTagText): Array {
		$textList = Array();
		foreach(preg_split('/(?<!\\\\),/', $valOfTagText) as $oneTxt){
			if( trim($oneTxt)!=='' ){
				$textList[] = $this->unescapeTxtVal($oneTxt);
			}
		}
		return $textList;
	}

	/**
	 * Reads a date, with or without a time.
	 *
	 * @param String $valOfTagText The date, as written: 20261228, 20261228T203000 or 20261228T203000Z.
	 * @param Array  $parameterLst What qualifies it: its time zone under TZID.
	 * @return Array The date as ISO text under 'value', '' when it cannot be read; whether it has no time under 'whole'; and the time zone it is written in under 'tzone', 'UTC' for a time ending with Z and '' when it tells none.
	 */
	protected function datesReadArray(String $valOfTagText, Array $parameterLst): Array {
		$dateList = Array();
		if( !preg_match('/^(\d{4})(\d\d)(\d\d)(?:T(\d\d)(\d\d)(\d\d)?(Z)?)?$/Di', trim($valOfTagText), $dateList) || !checkdate(intval($dateList[2]), intval($dateList[3]), intval($dateList[1])) ){
			return Array('value'=>'', 'whole'=>false, 'tzone'=>'');
		}
		$dateText = $dateList[1].'-'.$dateList[2].'-'.$dateList[3];
		if( !isset($dateList[4]) || $dateList[4]==='' ){
			return Array('value'=>$dateText, 'whole'=>true, 'tzone'=>'');
		}
		$zoneName = (($dateList[7] ?? '')!=='') ? 'UTC' : ltrim(strval($parameterLst['TZID'] ?? ''), '/');
		return Array('value'=>$dateText.' '.$dateList[4].':'.$dateList[5].':'.((($dateList[6] ?? '')!=='') ? $dateList[6] : '00'), 'whole'=>false, 'tzone'=>$zoneName);
	}

	/**
	 * Reads a length of time into seconds.
	 *
	 * @param String $valOfTagText The length, as written: PT90M, P1DT2H, -PT15M, P2W.
	 * @return ?Int The seconds, negative for a length counted backwards; null when it cannot be read.
	 */
	protected function spanOfDuration(String $valOfTagText): ?Int {
		$spanPart = Array();
		if( !preg_match('/^([+\-]?)P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/Di', trim($valOfTagText), $spanPart, PREG_UNMATCHED_AS_NULL) || trim($valOfTagText, '+-Pp')==='' ){
			return null;
		}
		$secsNumb = intval($spanPart[2])*604800+intval($spanPart[3])*86400+intval($spanPart[4])*3600+intval($spanPart[5])*60+intval($spanPart[6]);
		return ($spanPart[1]=='-') ? -$secsNumb : $secsNumb;
	}

	/**
	 * Moves a date by a number of seconds, the clock being read as it
	 * stands, without the hour a change of season adds or takes.
	 *
	 * @param String $givenIsoDate The date, as ISO text, with or without a time.
	 * @param Int    $scndsAddNumb The seconds to add, negative to go back.
	 * @return String The date moved, in the form it came in.
	 */
	protected function switchDatesTxt(String $givenIsoDate, Int $scndsAddNumb): String {
		$dateInst = new \DateTimeImmutable($givenIsoDate, new \DateTimeZone('UTC'));
		return $dateInst->modify(sprintf('%+d seconds', $scndsAddNumb))->format((strlen($givenIsoDate)>10) ? 'Y-m-d H:i:s' : 'Y-m-d');
	}

	/**
	 * Reads who organises an entry, or who is invited to it.
	 *
	 * @param Array $propertyData The property: its 'value' and its 'parms'.
	 * @return Array The name of the person under 'names', '' when it is not told, and the address under 'email'.
	 */
	protected function partyReadArray(Array $propertyData): Array {
		return Array('names'=>strval($propertyData['parms']['CN'] ?? ''), 'email'=>trim(preg_replace('/^mailto:/i', '', trim($propertyData['value']))));
	}

	/**
	 * Reads the rule of a repeating entry.
	 *
	 * @param String $valOfTagText The rule, as written: FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=10.
	 * @param Bool   $everyWasRead Receives whether everything the rule says was read; what was not must be kept as written.
	 * @return Array How often under 'every' (eachDays, eachWeek, eachMnth, eachYear, '' for a rule that is none of these), every how many under 'steps', the days of the week under 'wdays', of the month under 'mdays', the months under 'month', the last date under 'until' and the number of times under 'times'.
	 */
	protected function rulePartsArray(String $valOfTagText, Bool &$everyWasRead): Array {
		$ruleDict = Array('every'=>'', 'steps'=>1, 'wdays'=>Array(), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'', 'times'=>0);
		$everyWasRead = true;
		foreach(explode(';', $valOfTagText) as $subTxt){
			$clefPair = explode('=', $subTxt, 2);
			$partName = strtoupper(trim($clefPair[0]));
			$partVals = trim(strval($clefPair[1] ?? ''));
			if( $partName=='FREQ' && isset(self::FREQ_LIST[strtoupper($partVals)]) ){
				$ruleDict['every'] = self::FREQ_LIST[strtoupper($partVals)];
			}elseif( $partName=='INTERVAL' ){
				$ruleDict['steps'] = max(1, intval($partVals));
			}elseif( $partName=='COUNT' ){
				$ruleDict['times'] = max(0, intval($partVals));
			}elseif( $partName=='UNTIL' ){
				$ruleDict['until'] = $this->datesReadArray($partVals, Array())['value'];
			}elseif( $partName=='BYDAY' ){
				$ruleDict['wdays'] = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $partVals))), function($rruleDayCode){ return preg_match('/^[+\-]?\d{0,2}(mo|tu|we|th|fr|sa|su)$/D', $rruleDayCode)===1; }));
			}elseif( $partName=='BYMONTHDAY' ){
				$ruleDict['mdays'] = array_map('intval', array_filter(explode(',', $partVals), 'is_numeric'));
			}elseif( $partName=='BYMONTH' ){
				$ruleDict['month'] = array_map('intval', array_filter(explode(',', $partVals), 'is_numeric'));
			}elseif( $partName!=='' ){
				$everyWasRead = false;
			}
		}
		return $ruleDict;
	}

	/**
	 * Lists the dates an entry falls on between two dates: its own, and
	 * those its rule repeats it on, without the dates it skips. The times
	 * are kept as the clock of its time zone shows them: an event at 20:00
	 * stays at 20:00 when the clocks change.
	 *
	 * The rules covered: every day, week, month or year, every so many;
	 * on days of the week, with their place in the month or the year (the
	 * last Friday of the month, the last Sunday of October); on days of
	 * the month, counted from its start or its end; in given months; until
	 * a date, or a number of times. Any other rule is refused, never
	 * guessed.
	 *
	 * @param Array  $calendarItem The entry, as parseDataArray() gives it under 'calEvent' or 'calTodos'.
	 * @param String $windowStarts First date of the window, as '2026-10-01' or '2026-10-01 08:00:00', in the time zone of the entry.
	 * @param String $windowFinish Last date of the window, the whole day when it tells no time.
	 * @param Int    $countCeiling Greatest number of dates given.
	 * @return Array The dates, in order, each with its 'start' and its 'ended', written as those of the entry.
	 *
	 * @throws ParseError When the entry has no start, a date cannot be read, or its rule is not one of those covered.
	 */
	public static function listEntryDates(Array $calendarItem, String $windowStarts, String $windowFinish, Int $countCeiling=500): Array {
		$isAllDay = !empty($calendarItem['whole']);
		$dateFrmt = $isAllDay ? 'Y-m-d' : 'Y-m-d H:i:s';
		try{
			$zoneInst = new \DateTimeZone(($isAllDay || strval($calendarItem['tzone'] ?? '')==='') ? 'UTC' : strval($calendarItem['tzone']));
			$startObj = new \DateTimeImmutable(strval($calendarItem['start'] ?? ''), $zoneInst);
			$endsInst = (strval($calendarItem['ended'] ?? '')==='') ? null : new \DateTimeImmutable(strval($calendarItem['ended']), $zoneInst);
			$fromInst = new \DateTimeImmutable($windowStarts, $zoneInst);
			$tillInst = new \DateTimeImmutable($windowFinish.((strlen(trim($windowFinish))<=10) ? ' 23:59:59' : ''), $zoneInst);
		}catch( \Exception $e ){
			throw new ParseError('Invalid calendar date: '.$e->getMessage(), 0, $e);
		}
		if( strval($calendarItem['start'] ?? '')==='' ){
			throw new ParseError('Invalid calendar entry: it has no start');
		}
		// How long the entry lasts, as the clock shows it; whole days end with their last day
		$spanInst = ($endsInst===null || $endsInst<$startObj) ? null : $startObj->diff($endsInst);
		$ruleDict = (array)($calendarItem['rpeat'] ?? Array());
		// What the rule says and was not read into the entry is kept as written: only the first day of the week is made use of
		$wdayName = 0;
		foreach(explode(';', strval(((array)($calendarItem['extra'] ?? Array()))['RRULE'] ?? '')) as $subTxt){
			$clefPair = explode('=', strtoupper(trim($subTxt)), 2);
			if( $clefPair[0]=='WKST' && in_array(strtolower(strval($clefPair[1] ?? '')), self::DAYS_LIST) ){
				$wdayName = array_search(strtolower($clefPair[1]), self::DAYS_LIST);
			}elseif( $clefPair[0]!=='' && !in_array($clefPair[0], Array('FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH')) || ($clefPair[0]=='FREQ' && empty($ruleDict)) ){
				throw new ParseError('Calendar rule not covered: '.substr(preg_replace('/[^A-Z0-9=,+\-]+/', '', implode('=', $clefPair)), 0, 40));
			}
		}
		$everyKey = strval($ruleDict['every'] ?? '');
		if( !empty($ruleDict) && !in_array($everyKey, Array('eachDays', 'eachWeek', 'eachMnth', 'eachYear')) ){
			throw new ParseError('Calendar rule not covered: '.substr(preg_replace('/[^a-z]+/', '', $everyKey), 0, 10));
		}
		$stepNumb = max(1, intval($ruleDict['steps'] ?? 1));
		$maxTimes = max(0, intval($ruleDict['times'] ?? 0));
		$wdayList = array_map('strval', (array)($ruleDict['wdays'] ?? Array()));
		$mdaysLst = array_map('intval', (array)($ruleDict['mdays'] ?? Array()));
		$monthLst = array_map('intval', (array)($ruleDict['month'] ?? Array()));
		// The last date of the rule: a day is taken whole, a time written for an entry that has a time zone is a time of the world clock
		$lastInst = null;
		$lastText = strval($ruleDict['until'] ?? '');
		if( $lastText!=='' ){
			try{
				$lastInst = (strlen($lastText)<=10) ? new \DateTimeImmutable($lastText.' 23:59:59', $zoneInst) : new \DateTimeImmutable($lastText, (!$isAllDay && strval($calendarItem['tzone'] ?? '')!=='') ? new \DateTimeZone('UTC') : $zoneInst);
			}catch( \Exception $e ){
				throw new ParseError('Invalid calendar date: '.$e->getMessage(), 0, $e);
			}
		}
		$skipList = array_map('strval', (array)($calendarItem['skips'] ?? Array()));
		$dayFirst = $startObj->setTime(0, 0, 0);
		// Without a number of times to count, the spans that end before the window are passed over
		$spanIndx = 0;
		if( $maxTimes==0 && !empty($ruleDict) && $fromInst>$startObj ){
			$daysSpan = intval($startObj->diff($fromInst)->days)-(($spanInst===null) ? 0 : intval($spanInst->days))-1;
			$spanIndx = max(0, intdiv($daysSpan, Array('eachDays'=>1, 'eachWeek'=>7, 'eachMnth'=>31, 'eachYear'=>366)[$everyKey]*$stepNumb)-1);
		}
		$dateList = Array();
		$doneNumb = 0;
		for($spnEnd=$spanIndx+(empty($ruleDict) ? 1 : self::SPAN_MAXI); $spanIndx<$spnEnd; $spanIndx++){
			// The days of this span: a day, a week, a month or a year
			$daysList = Array();
			if( empty($ruleDict) ){
				$daysList[] = $dayFirst->format('Y-m-d');
			}elseif( $everyKey=='eachDays' ){
				$daysList[] = $dayFirst->modify('+'.($spanIndx*$stepNumb).' days')->format('Y-m-d');
			}elseif( $everyKey=='eachWeek' ){
				$frstWeek = $dayFirst->modify('-'.((intval($dayFirst->format('N'))-1-$wdayName+7)%7).' days')->modify('+'.($spanIndx*$stepNumb*7).' days');
				foreach(empty($wdayList) ? Array(self::DAYS_LIST[intval($dayFirst->format('N'))-1]) : $wdayList as $dayKey){
					$daysList[] = $frstWeek->modify('+'.((array_search(substr($dayKey, -2), self::DAYS_LIST)-$wdayName+7)%7).' days')->format('Y-m-d');
				}
			}elseif( $everyKey=='eachMnth' ){
				$monthCnt = intval($dayFirst->format('Y'))*12+intval($dayFirst->format('n'))-1+$spanIndx*$stepNumb;
				$daysList = self::ruleDaysOfMnth(intdiv($monthCnt, 12), $monthCnt%12+1, $wdayList, $mdaysLst, intval($dayFirst->format('j')));
			}else{
				$yearNumb = intval($dayFirst->format('Y'))+$spanIndx*$stepNumb;
				if( !empty($wdayList) && empty($monthLst) ){
					// Days of the week placed in the year: the 20th Monday, the last Sunday
					$daysList = self::ruleDaysOfMnth($yearNumb, 0, $wdayList, Array(), 0);
					if( !empty($mdaysLst) ){
						$daysList = array_values(array_filter($daysList, function(String $windowDayDte) use ($yearNumb, $mdaysLst){ return in_array($windowDayDte, self::ruleDaysOfMnth($yearNumb, intval(substr($windowDayDte, 5, 2)), Array(), $mdaysLst, 0)); }));
					}
				}else{
					// In the months told, or in every month for days of the month told alone, or in the month of the start
					foreach(!empty($monthLst) ? $monthLst : (!empty($mdaysLst) ? range(1, 12) : Array(intval($dayFirst->format('n')))) as $mthNum){
						if( $mthNum>=1 && $mthNum<=12 ){
							$daysList = array_merge($daysList, self::ruleDaysOfMnth($yearNumb, $mthNum, $wdayList, $mdaysLst, intval($dayFirst->format('j'))));
						}
					}
				}
			}
			// What a rule of days or weeks adds to the days it gives: the months, the days of the month, the days of the week
			if( $everyKey=='eachDays' || $everyKey=='eachWeek' ){
				$daysList = array_values(array_filter($daysList, function(String $windowDayDte) use ($everyKey, $wdayList, $mdaysLst, $monthLst, $zoneInst){
					$dateInst = new \DateTimeImmutable($windowDayDte, $zoneInst);
					$wdayKeys = array_map(function(String $rruleDayCode){ return substr($rruleDayCode, -2); }, $wdayList);
					return (empty($monthLst) || in_array(intval($dateInst->format('n')), $monthLst))
						&& ($everyKey=='eachWeek' || empty($wdayKeys) || in_array(self::DAYS_LIST[intval($dateInst->format('N'))-1], $wdayKeys))
						&& ($everyKey=='eachWeek' || empty($mdaysLst) || in_array($windowDayDte, self::ruleDaysOfMnth(intval($dateInst->format('Y')), intval($dateInst->format('n')), Array(), $mdaysLst, 0)));
				}));
			}elseif( $everyKey=='eachMnth' && !empty($monthLst) ){
				$daysList = array_values(array_filter($daysList, function(String $windowDayDte) use ($monthLst){ return in_array(intval(substr($windowDayDte, 5, 2)), $monthLst); }));
			}
			// The start of the entry is its first date, whatever its rule says
			if( $spanIndx==0 ){
				$daysList[] = $dayFirst->format('Y-m-d');
			}
			$daysList = array_unique($daysList);
			sort($daysList);
			foreach($daysList as $dayTxt){
				$dayParte = array_map('intval', explode('-', $dayTxt));
				$onceInst = $startObj->setDate($dayParte[0], $dayParte[1], $dayParte[2]);
				if( $onceInst<$startObj ){
					continue;
				}
				$doneNumb++;
				if( $onceInst>$tillInst || ($lastInst!==null && $onceInst>$lastInst) || ($maxTimes>0 && $doneNumb>$maxTimes) ){
					break 2;
				}
				$overInst = ($spanInst===null) ? null : $onceInst->add($spanInst);
				$onceText = $onceInst->format($dateFrmt);
				if( (($overInst===null) ? $onceInst : ($isAllDay ? $overInst->setTime(23, 59, 59) : $overInst))<$fromInst || in_array($onceText, $skipList) || in_array(substr($onceText, 0, 10), $skipList) ){
					continue;
				}
				$dateList[] = Array('start'=>$onceText, 'ended'=>($overInst===null) ? '' : $overInst->format($dateFrmt));
				if( count($dateList)>=max(1, $countCeiling) ){
					break 2;
				}
			}
		}
		return $dateList;
	}

	/**
	 * Gives the days of a month, or of a year, a rule falls on.
	 *
	 * @param Int   $calendarYear The year.
	 * @param Int   $monthIndxNum The month, 1 to 12; 0 for the days of the week to be placed in the whole year.
	 * @param Array $weekDaysList Days of the week, each with its place when it has one: 'fr', '2tu', '-1su'.
	 * @param Array $monthDayList Days of the month, from its start (1, 15) or its end (-1 for the last).
	 * @param Int   $dfltDayCount Day of the month taken when neither is told; 0 for none.
	 * @return Array The days, as '2026-10-25', in order: those on a day of the week told, among the days of the month told when both are.
	 */
	protected static function ruleDaysOfMnth(Int $calendarYear, Int $monthIndxNum, Array $weekDaysList, Array $monthDayList, Int $dfltDayCount): Array {
		$zoneInst = new \DateTimeZone('UTC');
		$frstDate = (new \DateTimeImmutable('now', $zoneInst))->setDate($calendarYear, max(1, $monthIndxNum), 1)->setTime(0, 0, 0);
		$daysNumb = ($monthIndxNum==0) ? 365+intval($frstDate->format('L')) : intval($frstDate->format('t'));
		$numbList = Array();
		foreach($monthDayList as $dayNum){
			$dayNum = intval($dayNum);
			$numbList[] = ($dayNum<0) ? $daysNumb+1+$dayNum : $dayNum;
		}
		$foundLst = Array();
		foreach($weekDaysList as $dayKey){
			$dayParte = Array();
			if( !preg_match('/^([+\-]?\d*)(mo|tu|we|th|fr|sa|su)$/D', strtolower(strval($dayKey)), $dayParte) ){
				continue;
			}
			// Every such day of the span, then the one at the place told, counted from its start or its end
			$sameList = range(1+((array_search($dayParte[2], self::DAYS_LIST)-(intval($frstDate->format('N'))-1)+7)%7), $daysNumb, 7);
			$placeCnt = intval($dayParte[1]);
			if( $placeCnt!=0 ){
				$placeIdx = ($placeCnt>0) ? $placeCnt-1 : count($sameList)+$placeCnt;
				$sameList = isset($sameList[$placeIdx]) ? Array($sameList[$placeIdx]) : Array();
			}
			$foundLst = array_merge($foundLst, empty($numbList) ? $sameList : array_intersect($sameList, $numbList));
		}
		if( empty($weekDaysList) ){
			$foundLst = empty($numbList) ? (($dfltDayCount>0) ? Array($dfltDayCount) : Array()) : $numbList;
		}
		$daysList = Array();
		foreach(array_unique($foundLst) as $dayNum){
			if( $dayNum>=1 && $dayNum<=$daysNumb ){
				$daysList[] = $frstDate->modify('+'.($dayNum-1).' days')->format('Y-m-d');
			}
		}
		sort($daysList);
		return $daysList;
	}

	/**
	 * Reads an entry of a calendar: an event, a task or a note.
	 *
	 * @param Array          $componentDct The entry, as readComponents() gives it.
	 * @param String         $itemTypesTxt Its kind: event, tasks or notes.
	 * @param ?\DateTimeZone $zoneAskedFor The time zone its times are asked in, null to leave them as written.
	 * @return Array The entry, with every key of its kind.
	 */
	protected function entryReadArray(Array $componentDct, String $itemTypesTxt, ?\DateTimeZone $zoneAskedFor): Array {
		$propList = Array();
		$extraLst = Array();
		foreach($componentDct['props'] as $prpSet){
			$propList[$prpSet['names']][] = $prpSet;
			// What has no key of its own is kept as written; a property given several times keeps all its values
			if( !in_array($prpSet['names'], self::READ_LIST) ){
				$extraLst[$prpSet['names']] = isset($extraLst[$prpSet['names']]) ? array_merge((array)$extraLst[$prpSet['names']], Array($prpSet['value'])) : $prpSet['value'];
			}
		}
		$voidDate = Array('value'=>'', 'whole'=>false, 'tzone'=>'');
		$startDct = isset($propList['DTSTART']) ? $this->datesReadArray($propList['DTSTART'][0]['value'], $propList['DTSTART'][0]['parms']) : $voidDate;
		$zoneName = $startDct['tzone'];
		// The end: written, or the start moved by the length of the entry. Whole days end on the day before the one written
		$endValue = $startDct['value'];
		$spanSecs = isset($propList['DURATION']) ? $this->spanOfDuration($propList['DURATION'][0]['value']) : null;
		if( isset($propList['DTEND']) && $this->datesReadArray($propList['DTEND'][0]['value'], $propList['DTEND'][0]['parms'])['value']!=='' ){
			$endValue = $this->datesReadArray($propList['DTEND'][0]['value'], $propList['DTEND'][0]['parms'])['value'];
		}elseif( $spanSecs!==null && $startDct['value']!=='' ){
			$endValue = $this->switchDatesTxt($startDct['value'], $spanSecs);
		}elseif( $itemTypesTxt!='calEvent' ){
			$endValue = '';
		}
		if( $startDct['whole'] && strlen($endValue)==10 && $endValue>$startDct['value'] ){
			$endValue = $this->switchDatesTxt($endValue, -86400);
		}
		$dueStamp = isset($propList['DUE']) ? $this->datesReadArray($propList['DUE'][0]['value'], $propList['DUE'][0]['parms']) : $voidDate;
		$stampDct = isset($propList['DTSTAMP']) ? $this->datesReadArray($propList['DTSTAMP'][0]['value'], $propList['DTSTAMP'][0]['parms']) : $voidDate;
		$skipList = Array();
		foreach($propList['EXDATE'] ?? Array() as $prpSet){
			foreach(explode(',', $prpSet['value']) as $skpTxt){
				$skipDict = $this->datesReadArray($skpTxt, $prpSet['parms']);
				if( $skipDict['value']!=='' ){
					$skipList[] = $this->zoneShiftArray($skipDict, $zoneAskedFor)['value'];
				}
			}
		}
		// The times are given in the zone asked for, when they tell the one they are written in
		$startDun = $this->zoneShiftArray($startDct, $zoneAskedFor);
		$endValue = $this->zoneShiftArray(Array('value'=>$endValue, 'whole'=>$startDct['whole'], 'tzone'=>$startDct['tzone']), $zoneAskedFor)['value'];
		$ruleDict = Array();
		if( isset($propList['RRULE']) ){
			$ruleDone = true;
			$ruleDict = $this->rulePartsArray($propList['RRULE'][0]['value'], $ruleDone);
			if( !$ruleDone || $ruleDict['every']==='' ){
				$extraLst['RRULE'] = $propList['RRULE'][0]['value'];
			}
			if( $ruleDict['every']==='' ){
				$ruleDict = Array();
			}
		}
		$labelLst = Array();
		foreach($propList['CATEGORIES'] ?? Array() as $prpSet){
			$labelLst = array_merge($labelLst, $this->splitListValue($prpSet['value']));
		}
		$guestLst = Array();
		foreach($propList['ATTENDEE'] ?? Array() as $prpSet){
			$guestLst[] = $this->partyReadArray($prpSet) + Array('reply'=>strval(self::PART_STAT[strtoupper(strval($prpSet['parms']['PARTSTAT'] ?? 'NEEDS-ACTION'))] ?? 'awaiting'));
		}
		// A reminder counts back from the start of the entry; one set at a date of its own is kept as written
		$alarmLst = Array();
		foreach($componentDct['comps'] as $almArr){
			foreach($almArr['props'] as $prpSet){
				$trigSecs = ($almArr['kinds']=='VALARM' && $prpSet['names']=='TRIGGER') ? $this->spanOfDuration($prpSet['value']) : null;
				if( $trigSecs!==null ){
					$alarmLst[] = intdiv(-$trigSecs, 60);
				}
			}
		}
		$getStrng = function(String $propertyName) use ($propList){ return isset($propList[$propertyName]) ? $this->unescapeTxtVal($propList[$propertyName][0]['value']) : ''; };
		$itemDict = Array(
			'ident'=>$getStrng('UID'), 'title'=>$getStrng('SUMMARY'),
			'start'=>$startDun['value'], 'ended'=>$endValue, 'whole'=>$startDct['whole'], 'tzone'=>$startDun['tzone'],
			'place'=>$getStrng('LOCATION'), 'descr'=>$getStrng('DESCRIPTION'), 'links'=>Shape::tidyLinkString($getStrng('URL')),
			'state'=>strval(self::STAT_LIST[strtoupper($getStrng('STATUS'))] ?? ''), 'label'=>$labelLst,
			'owner'=>isset($propList['ORGANIZER']) ? $this->partyReadArray($propList['ORGANIZER'][0]) : Array('names'=>'', 'email'=>''), 'guest'=>$guestLst,
			'rpeat'=>$ruleDict, 'skips'=>$skipList, 'alarm'=>$alarmLst,
			'stamp'=>$this->zoneShiftArray($stampDct, $zoneAskedFor)['value'], 'extra'=>$extraLst
		);
		if( $itemTypesTxt=='calTodos' ){
			$itemDict = array_slice($itemDict, 0, -1, true) + Array('dueby'=>$this->zoneShiftArray($dueStamp, $zoneAskedFor)['value'], 'ratio'=>max(0, min(100, intval($propList['PERCENT-COMPLETE'][0]['value'] ?? 0))), 'extra'=>$extraLst);
		}elseif( $itemTypesTxt=='calNotes' ){
			$itemDict = array_intersect_key($itemDict, array_flip(Array('ident', 'title', 'start', 'whole', 'tzone', 'descr', 'state', 'label', 'stamp', 'extra')));
		}
		return $itemDict;
	}

	/**
	 * Gives a date in the time zone asked for. A date without a time, a
	 * time that tells no zone, and a time written in a zone PHP does not
	 * know are left as they are.
	 *
	 * @param Array          $dateProperty The date, as datesReadArray() gives it.
	 * @param ?\DateTimeZone $zoneAskedFor The zone asked for, null to leave the date as written.
	 * @return Array The date, as it came in, moved to that zone when it could be.
	 */
	protected function zoneShiftArray(Array $dateProperty, ?\DateTimeZone $zoneAskedFor): Array {
		if( $zoneAskedFor===null || $dateProperty['whole'] || $dateProperty['tzone']==='' || strlen($dateProperty['value'])<=10 ){
			return $dateProperty;
		}
		try{
			$dateInst = new \DateTimeImmutable($dateProperty['value'], new \DateTimeZone($dateProperty['tzone']));
		}catch( \Exception $e ){
			return $dateProperty;
		}
		return Array('value'=>$dateInst->setTimezone($zoneAskedFor)->format('Y-m-d H:i:s'), 'whole'=>false, 'tzone'=>$zoneAskedFor->getName());
	}

}
