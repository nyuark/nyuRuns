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
	const KIND_LIST = Array('VEVENT'=>'event', 'VTODO'=>'tasks', 'VJOURNAL'=>'notes');
	// The states of an entry, the replies of a guest, and how often an entry repeats
	const STAT_LIST = Array('CONFIRMED'=>'cnfrm', 'TENTATIVE'=>'tentv', 'CANCELLED'=>'cancl', 'NEEDS-ACTION'=>'waits', 'IN-PROCESS'=>'activ', 'COMPLETED'=>'compl', 'DRAFT'=>'draft', 'FINAL'=>'final');
	const REPL_LIST = Array('ACCEPTED'=>'accpt', 'DECLINED'=>'decln', 'TENTATIVE'=>'tentv', 'NEEDS-ACTION'=>'waits', 'DELEGATED'=>'deleg');
	const FREQ_LIST = Array('DAILY'=>'daily', 'WEEKLY'=>'wekly', 'MONTHLY'=>'mthly', 'YEARLY'=>'yerly');
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
	 * @return Array The envelope, of shape 'nodes', holding the events under 'event', the tasks under 'tasks' and the notes under 'notes'; 'infos' holds the name of the calendar under 'title', its time zone under 'tzone', the program that wrote it under 'maker' and the number of its entries under 'count'.
	 *
	 * @throws ParseError When the content is not a calendar, an entry is not closed, or the time zone asked for is not a known one.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$trgtZon = null;
		if( strval($parseOptions['tzone'] ?? '')!=='' ){
			try{
				$trgtZon = new \DateTimeZone(strval($parseOptions['tzone']));
			}catch( \Exception $e ){
				throw new ParseError('Invalid calendar option: unknown time zone '.strval($parseOptions['tzone']), 0, $e);
			}
		}
		$itemLst = Array('event'=>Array(), 'tasks'=>Array(), 'notes'=>Array());
		$infoArr = Array('title'=>'', 'tzone'=>'', 'maker'=>'', 'count'=>0);
		foreach($this->readComponents($this->foldLinesArray(self::recodeTextUtf8($nakedContent))) as $cldArr){
			foreach(Array('X-WR-CALNAME'=>'title', 'X-WR-TIMEZONE'=>'tzone', 'PRODID'=>'maker') as $prpNme=>$infKey){
				foreach($cldArr['props'] as $prpSet){
					if( $prpSet['names']==$prpNme && $infoArr[$infKey]==='' ){
						$infoArr[$infKey] = $this->unescapeTxtVal($prpSet['value']);
					}
				}
			}
			foreach($cldArr['comps'] as $cmpArr){
				if( isset(self::KIND_LIST[$cmpArr['kinds']]) ){
					$itemLst[self::KIND_LIST[$cmpArr['kinds']]][] = $this->entryReadArray($cmpArr, self::KIND_LIST[$cmpArr['kinds']], $trgtZon);
					$infoArr['count']++;
				}
			}
		}
		return $this->makeShapeArray('nodes', !empty($parseOptions['upper']) ? $this->upperKeysArray($itemLst) : $itemLst, $infoArr);
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
		$lineLst = Array();
		foreach(preg_split('/\r\n|\r|\n/', $nakedContent) as $linIdx=>$linTxt){
			if( $linTxt!=='' && ($linTxt[0]==' ' || $linTxt[0]=="\t") && !empty($lineLst) ){
				$lineLst[count($lineLst)-1][0] .= substr($linTxt, 1);
			}elseif( trim($linTxt)!=='' ){
				$lineLst[] = Array($linTxt, $linIdx+1);
			}
		}
		return $lineLst;
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
		$rootLst = Array();
		// What is being read, from the calendar down to the innermost entry, and the line each was opened on
		$openLst = Array();
		$openLns = Array();
		foreach($linesToParse as $linSet){
			// A line is a name, what qualifies it after semicolons, then its value after the first colon that is not between quotes
			$hedPart = Array();
			if( !preg_match('/^((?:[^":]|"[^"]*")*):(.*)$/Ds', $linSet[0], $hedPart) || trim($hedPart[1])==='' ){
				throw new ParseError('Invalid calendar content: a line that is not a property on line '.$linSet[1]);
			}
			$nmePart = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $hedPart[1]);
			$propNme = strtoupper(trim(strval(array_shift($nmePart))));
			$propVal = $hedPart[2];
			if( $propNme=='BEGIN' ){
				if( empty($openLst) && strtoupper(trim($propVal))!='VCALENDAR' ){
					throw new ParseError('Invalid calendar content: the content does not start a calendar on line '.$linSet[1]);
				}
				if( count($openLst)>=8 ){
					throw new ParseError('Invalid calendar content: nested deeper than allowed on line '.$linSet[1]);
				}
				$openLst[] = Array('kinds'=>strtoupper(trim($propVal)), 'props'=>Array(), 'comps'=>Array());
				$openLns[] = $linSet[1];
				continue;
			}
			if( empty($openLst) ){
				throw new ParseError('Invalid calendar content: the content does not start a calendar on line '.$linSet[1]);
			}
			if( $propNme=='END' ){
				$doneCmp = array_pop($openLst);
				array_pop($openLns);
				if( $doneCmp['kinds']!==strtoupper(trim($propVal)) ){
					throw new ParseError('Invalid calendar content: '.strtoupper(trim($propVal)).' closed where '.$doneCmp['kinds'].' is open on line '.$linSet[1]);
				}
				if( empty($openLst) ){
					$rootLst[] = $doneCmp;
				}else{
					$openLst[count($openLst)-1]['comps'][] = $doneCmp;
				}
				continue;
			}
			$argList = Array();
			foreach($nmePart as $argTxt){
				$prmPrts = explode('=', $argTxt, 2);
				$argList[strtoupper(trim($prmPrts[0]))] = trim(strval($prmPrts[1] ?? ''), " \t\"");
			}
			$openLst[count($openLst)-1]['props'][] = Array('names'=>$propNme, 'parms'=>$argList, 'value'=>$propVal);
		}
		if( !empty($openLst) ){
			throw new ParseError('Invalid calendar content: '.$openLst[count($openLst)-1]['kinds'].' is never closed, opened on line '.$openLns[count($openLns)-1]);
		}
		return $rootLst;
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
		$textLst = Array();
		foreach(preg_split('/(?<!\\\\),/', $valOfTagText) as $oneTxt){
			if( trim($oneTxt)!=='' ){
				$textLst[] = $this->unescapeTxtVal($oneTxt);
			}
		}
		return $textLst;
	}

	/**
	 * Reads a date, with or without a time.
	 *
	 * @param String $valOfTagText The date, as written: 20261228, 20261228T203000 or 20261228T203000Z.
	 * @param Array  $paramtrsArry What qualifies it: its time zone under TZID.
	 * @return Array The date as ISO text under 'value', '' when it cannot be read; whether it has no time under 'whole'; and the time zone it is written in under 'tzone', 'UTC' for a time ending with Z and '' when it tells none.
	 */
	protected function datesReadArray(String $valOfTagText, Array $paramtrsArry): Array {
		$dateLst = Array();
		if( !preg_match('/^(\d{4})(\d\d)(\d\d)(?:T(\d\d)(\d\d)(\d\d)?(Z)?)?$/Di', trim($valOfTagText), $dateLst) || !checkdate(intval($dateLst[2]), intval($dateLst[3]), intval($dateLst[1])) ){
			return Array('value'=>'', 'whole'=>false, 'tzone'=>'');
		}
		$dateTxt = $dateLst[1].'-'.$dateLst[2].'-'.$dateLst[3];
		if( !isset($dateLst[4]) || $dateLst[4]==='' ){
			return Array('value'=>$dateTxt, 'whole'=>true, 'tzone'=>'');
		}
		$zoneNme = (($dateLst[7] ?? '')!=='') ? 'UTC' : ltrim(strval($paramtrsArry['TZID'] ?? ''), '/');
		return Array('value'=>$dateTxt.' '.$dateLst[4].':'.$dateLst[5].':'.((($dateLst[6] ?? '')!=='') ? $dateLst[6] : '00'), 'whole'=>false, 'tzone'=>$zoneNme);
	}

	/**
	 * Reads a length of time into seconds.
	 *
	 * @param String $valOfTagText The length, as written: PT90M, P1DT2H, -PT15M, P2W.
	 * @return ?Int The seconds, negative for a length counted backwards; null when it cannot be read.
	 */
	protected function spanOfDuration(String $valOfTagText): ?Int {
		$spnPart = Array();
		if( !preg_match('/^([+\-]?)P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/Di', trim($valOfTagText), $spnPart, PREG_UNMATCHED_AS_NULL) || trim($valOfTagText, '+-Pp')==='' ){
			return null;
		}
		$secsNum = intval($spnPart[2])*604800+intval($spnPart[3])*86400+intval($spnPart[4])*3600+intval($spnPart[5])*60+intval($spnPart[6]);
		return ($spnPart[1]=='-') ? -$secsNum : $secsNum;
	}

	/**
	 * Moves a date by a number of seconds, the clock being read as it
	 * stands, without the hour a change of season adds or takes.
	 *
	 * @param String $isoDateStrng The date, as ISO text, with or without a time.
	 * @param Int    $secondsAddNb The seconds to add, negative to go back.
	 * @return String The date moved, in the form it came in.
	 */
	protected function switchDatesTxt(String $isoDateStrng, Int $secondsAddNb): String {
		$dateObj = new \DateTimeImmutable($isoDateStrng, new \DateTimeZone('UTC'));
		return $dateObj->modify(sprintf('%+d seconds', $secondsAddNb))->format((strlen($isoDateStrng)>10) ? 'Y-m-d H:i:s' : 'Y-m-d');
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
	 * @return Array How often under 'every' (daily, wekly, mthly, yerly, '' for a rule that is none of these), every how many under 'steps', the days of the week under 'wdays', of the month under 'mdays', the months under 'month', the last date under 'until' and the number of times under 'times'.
	 */
	protected function rulePartsArray(String $valOfTagText, Bool &$everyWasRead): Array {
		$ruleSet = Array('every'=>'', 'steps'=>1, 'wdays'=>Array(), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'', 'times'=>0);
		$everyWasRead = true;
		foreach(explode(';', $valOfTagText) as $subTxt){
			$keyPair = explode('=', $subTxt, 2);
			$partNme = strtoupper(trim($keyPair[0]));
			$partVal = trim(strval($keyPair[1] ?? ''));
			if( $partNme=='FREQ' && isset(self::FREQ_LIST[strtoupper($partVal)]) ){
				$ruleSet['every'] = self::FREQ_LIST[strtoupper($partVal)];
			}elseif( $partNme=='INTERVAL' ){
				$ruleSet['steps'] = max(1, intval($partVal));
			}elseif( $partNme=='COUNT' ){
				$ruleSet['times'] = max(0, intval($partVal));
			}elseif( $partNme=='UNTIL' ){
				$ruleSet['until'] = $this->datesReadArray($partVal, Array())['value'];
			}elseif( $partNme=='BYDAY' ){
				$ruleSet['wdays'] = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $partVal))), function($wekDayString){ return preg_match('/^[+\-]?\d{0,2}(mo|tu|we|th|fr|sa|su)$/D', $wekDayString)===1; }));
			}elseif( $partNme=='BYMONTHDAY' ){
				$ruleSet['mdays'] = array_map('intval', array_filter(explode(',', $partVal), 'is_numeric'));
			}elseif( $partNme=='BYMONTH' ){
				$ruleSet['month'] = array_map('intval', array_filter(explode(',', $partVal), 'is_numeric'));
			}elseif( $partNme!=='' ){
				$everyWasRead = false;
			}
		}
		return $ruleSet;
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
	 * @param Array  $calendarItem The entry, as parseDataArray() gives it under 'event' or 'tasks'.
	 * @param String $startsFromNb First date of the window, as '2026-10-01' or '2026-10-01 08:00:00', in the time zone of the entry.
	 * @param String $windowFinish Last date of the window, the whole day when it tells no time.
	 * @param Int    $countCeiling Greatest number of dates given.
	 * @return Array The dates, in order, each with its 'start' and its 'ended', written as those of the entry.
	 *
	 * @throws ParseError When the entry has no start, a date cannot be read, or its rule is not one of those covered.
	 */
	public static function listEntryDates(Array $calendarItem, String $startsFromNb, String $windowFinish, Int $countCeiling=500): Array {
		$isWhole = !empty($calendarItem['whole']);
		$dateFmt = $isWhole ? 'Y-m-d' : 'Y-m-d H:i:s';
		try{
			$zoneObj = new \DateTimeZone(($isWhole || strval($calendarItem['tzone'] ?? '')==='') ? 'UTC' : strval($calendarItem['tzone']));
			$strtObj = new \DateTimeImmutable(strval($calendarItem['start'] ?? ''), $zoneObj);
			$endsObj = (strval($calendarItem['ended'] ?? '')==='') ? null : new \DateTimeImmutable(strval($calendarItem['ended']), $zoneObj);
			$fromObj = new \DateTimeImmutable($startsFromNb, $zoneObj);
			$tillObj = new \DateTimeImmutable($windowFinish.((strlen(trim($windowFinish))<=10) ? ' 23:59:59' : ''), $zoneObj);
		}catch( \Exception $e ){
			throw new ParseError('Invalid calendar date: '.$e->getMessage(), 0, $e);
		}
		if( strval($calendarItem['start'] ?? '')==='' ){
			throw new ParseError('Invalid calendar entry: it has no start');
		}
		// How long the entry lasts, as the clock shows it; whole days end with their last day
		$spanObj = ($endsObj===null || $endsObj<$strtObj) ? null : $strtObj->diff($endsObj);
		$ruleSet = (array)($calendarItem['rpeat'] ?? Array());
		// What the rule says and was not read into the entry is kept as written: only the first day of the week is made use of
		$weekDay = 0;
		foreach(explode(';', strval(((array)($calendarItem['extra'] ?? Array()))['RRULE'] ?? '')) as $subTxt){
			$keyPair = explode('=', strtoupper(trim($subTxt)), 2);
			if( $keyPair[0]=='WKST' && in_array(strtolower(strval($keyPair[1] ?? '')), self::DAYS_LIST) ){
				$weekDay = array_search(strtolower($keyPair[1]), self::DAYS_LIST);
			}elseif( $keyPair[0]!=='' && !in_array($keyPair[0], Array('FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH')) || ($keyPair[0]=='FREQ' && empty($ruleSet)) ){
				throw new ParseError('Calendar rule not covered: '.substr(preg_replace('/[^A-Z0-9=,+\-]+/', '', implode('=', $keyPair)), 0, 40));
			}
		}
		$evryKey = strval($ruleSet['every'] ?? '');
		if( !empty($ruleSet) && !in_array($evryKey, Array('daily', 'wekly', 'mthly', 'yerly')) ){
			throw new ParseError('Calendar rule not covered: '.substr(preg_replace('/[^a-z]+/', '', $evryKey), 0, 10));
		}
		$stepNum = max(1, intval($ruleSet['steps'] ?? 1));
		$timeMax = max(0, intval($ruleSet['times'] ?? 0));
		$wkdyLst = array_map('strval', (array)($ruleSet['wdays'] ?? Array()));
		$mdayLst = array_map('intval', (array)($ruleSet['mdays'] ?? Array()));
		$mnthLst = array_map('intval', (array)($ruleSet['month'] ?? Array()));
		// The last date of the rule: a day is taken whole, a time written for an entry that has a time zone is a time of the world clock
		$lastObj = null;
		$lastTxt = strval($ruleSet['until'] ?? '');
		if( $lastTxt!=='' ){
			try{
				$lastObj = (strlen($lastTxt)<=10) ? new \DateTimeImmutable($lastTxt.' 23:59:59', $zoneObj) : new \DateTimeImmutable($lastTxt, (!$isWhole && strval($calendarItem['tzone'] ?? '')!=='') ? new \DateTimeZone('UTC') : $zoneObj);
			}catch( \Exception $e ){
				throw new ParseError('Invalid calendar date: '.$e->getMessage(), 0, $e);
			}
		}
		$skipLst = array_map('strval', (array)($calendarItem['skips'] ?? Array()));
		$dayFrst = $strtObj->setTime(0, 0, 0);
		// Without a number of times to count, the spans that end before the window are passed over
		$spanIdx = 0;
		if( $timeMax==0 && !empty($ruleSet) && $fromObj>$strtObj ){
			$daysGap = intval($strtObj->diff($fromObj)->days)-(($spanObj===null) ? 0 : intval($spanObj->days))-1;
			$spanIdx = max(0, intdiv($daysGap, Array('daily'=>1, 'wekly'=>7, 'mthly'=>31, 'yerly'=>366)[$evryKey]*$stepNum)-1);
		}
		$dateLst = Array();
		$doneNum = 0;
		for($spnEnd=$spanIdx+(empty($ruleSet) ? 1 : self::SPAN_MAXI); $spanIdx<$spnEnd; $spanIdx++){
			// The days of this span: a day, a week, a month or a year
			$daysLst = Array();
			if( empty($ruleSet) ){
				$daysLst[] = $dayFrst->format('Y-m-d');
			}elseif( $evryKey=='daily' ){
				$daysLst[] = $dayFrst->modify('+'.($spanIdx*$stepNum).' days')->format('Y-m-d');
			}elseif( $evryKey=='wekly' ){
				$weekOne = $dayFrst->modify('-'.((intval($dayFrst->format('N'))-1-$weekDay+7)%7).' days')->modify('+'.($spanIdx*$stepNum*7).' days');
				foreach(empty($wkdyLst) ? Array(self::DAYS_LIST[intval($dayFrst->format('N'))-1]) : $wkdyLst as $dayKey){
					$daysLst[] = $weekOne->modify('+'.((array_search(substr($dayKey, -2), self::DAYS_LIST)-$weekDay+7)%7).' days')->format('Y-m-d');
				}
			}elseif( $evryKey=='mthly' ){
				$monthNb = intval($dayFrst->format('Y'))*12+intval($dayFrst->format('n'))-1+$spanIdx*$stepNum;
				$daysLst = self::ruleDaysOfMnth(intdiv($monthNb, 12), $monthNb%12+1, $wkdyLst, $mdayLst, intval($dayFrst->format('j')));
			}else{
				$yearNum = intval($dayFrst->format('Y'))+$spanIdx*$stepNum;
				if( !empty($wkdyLst) && empty($mnthLst) ){
					// Days of the week placed in the year: the 20th Monday, the last Sunday
					$daysLst = self::ruleDaysOfMnth($yearNum, 0, $wkdyLst, Array(), 0);
					if( !empty($mdayLst) ){
						$daysLst = array_values(array_filter($daysLst, function(String $dayOfTheYear) use ($yearNum, $mdayLst){ return in_array($dayOfTheYear, self::ruleDaysOfMnth($yearNum, intval(substr($dayOfTheYear, 5, 2)), Array(), $mdayLst, 0)); }));
					}
				}else{
					// In the months told, or in every month for days of the month told alone, or in the month of the start
					foreach(!empty($mnthLst) ? $mnthLst : (!empty($mdayLst) ? range(1, 12) : Array(intval($dayFrst->format('n')))) as $mthNum){
						if( $mthNum>=1 && $mthNum<=12 ){
							$daysLst = array_merge($daysLst, self::ruleDaysOfMnth($yearNum, $mthNum, $wkdyLst, $mdayLst, intval($dayFrst->format('j'))));
						}
					}
				}
			}
			// What a rule of days or weeks adds to the days it gives: the months, the days of the month, the days of the week
			if( $evryKey=='daily' || $evryKey=='wekly' ){
				$daysLst = array_values(array_filter($daysLst, function(String $dayOfASpnTxt) use ($evryKey, $wkdyLst, $mdayLst, $mnthLst, $zoneObj){
					$dateObj = new \DateTimeImmutable($dayOfASpnTxt, $zoneObj);
					$wkdyKys = array_map(function(String $wekDayString){ return substr($wekDayString, -2); }, $wkdyLst);
					return (empty($mnthLst) || in_array(intval($dateObj->format('n')), $mnthLst))
						&& ($evryKey=='wekly' || empty($wkdyKys) || in_array(self::DAYS_LIST[intval($dateObj->format('N'))-1], $wkdyKys))
						&& ($evryKey=='wekly' || empty($mdayLst) || in_array($dayOfASpnTxt, self::ruleDaysOfMnth(intval($dateObj->format('Y')), intval($dateObj->format('n')), Array(), $mdayLst, 0)));
				}));
			}elseif( $evryKey=='mthly' && !empty($mnthLst) ){
				$daysLst = array_values(array_filter($daysLst, function(String $dayOfASpnTxt) use ($mnthLst){ return in_array(intval(substr($dayOfASpnTxt, 5, 2)), $mnthLst); }));
			}
			// The start of the entry is its first date, whatever its rule says
			if( $spanIdx==0 ){
				$daysLst[] = $dayFrst->format('Y-m-d');
			}
			$daysLst = array_unique($daysLst);
			sort($daysLst);
			foreach($daysLst as $dayTxt){
				$dayPrts = array_map('intval', explode('-', $dayTxt));
				$onceObj = $strtObj->setDate($dayPrts[0], $dayPrts[1], $dayPrts[2]);
				if( $onceObj<$strtObj ){
					continue;
				}
				$doneNum++;
				if( $onceObj>$tillObj || ($lastObj!==null && $onceObj>$lastObj) || ($timeMax>0 && $doneNum>$timeMax) ){
					break 2;
				}
				$overObj = ($spanObj===null) ? null : $onceObj->add($spanObj);
				$onceTxt = $onceObj->format($dateFmt);
				if( (($overObj===null) ? $onceObj : ($isWhole ? $overObj->setTime(23, 59, 59) : $overObj))<$fromObj || in_array($onceTxt, $skipLst) || in_array(substr($onceTxt, 0, 10), $skipLst) ){
					continue;
				}
				$dateLst[] = Array('start'=>$onceTxt, 'ended'=>($overObj===null) ? '' : $overObj->format($dateFmt));
				if( count($dateLst)>=max(1, $countCeiling) ){
					break 2;
				}
			}
		}
		return $dateLst;
	}

	/**
	 * Gives the days of a month, or of a year, a rule falls on.
	 *
	 * @param Int   $calendarYear The year.
	 * @param Int   $monthIndxNum The month, 1 to 12; 0 for the days of the week to be placed in the whole year.
	 * @param Array $weekDaysList Days of the week, each with its place when it has one: 'fr', '2tu', '-1su'.
	 * @param Array $mnthDayLsArr Days of the month, from its start (1, 15) or its end (-1 for the last).
	 * @param Int   $dfltDayCount Day of the month taken when neither is told; 0 for none.
	 * @return Array The days, as '2026-10-25', in order: those on a day of the week told, among the days of the month told when both are.
	 */
	protected static function ruleDaysOfMnth(Int $calendarYear, Int $monthIndxNum, Array $weekDaysList, Array $mnthDayLsArr, Int $dfltDayCount): Array {
		$zoneObj = new \DateTimeZone('UTC');
		$firstOf = (new \DateTimeImmutable('now', $zoneObj))->setDate($calendarYear, max(1, $monthIndxNum), 1)->setTime(0, 0, 0);
		$daysNum = ($monthIndxNum==0) ? 365+intval($firstOf->format('L')) : intval($firstOf->format('t'));
		$numbLst = Array();
		foreach($mnthDayLsArr as $dayNum){
			$dayNum = intval($dayNum);
			$numbLst[] = ($dayNum<0) ? $daysNum+1+$dayNum : $dayNum;
		}
		$foundLs = Array();
		foreach($weekDaysList as $dayKey){
			$dayPrts = Array();
			if( !preg_match('/^([+\-]?\d*)(mo|tu|we|th|fr|sa|su)$/D', strtolower(strval($dayKey)), $dayPrts) ){
				continue;
			}
			// Every such day of the span, then the one at the place told, counted from its start or its end
			$sameLst = range(1+((array_search($dayPrts[2], self::DAYS_LIST)-(intval($firstOf->format('N'))-1)+7)%7), $daysNum, 7);
			$placeNb = intval($dayPrts[1]);
			if( $placeNb!=0 ){
				$placeAt = ($placeNb>0) ? $placeNb-1 : count($sameLst)+$placeNb;
				$sameLst = isset($sameLst[$placeAt]) ? Array($sameLst[$placeAt]) : Array();
			}
			$foundLs = array_merge($foundLs, empty($numbLst) ? $sameLst : array_intersect($sameLst, $numbLst));
		}
		if( empty($weekDaysList) ){
			$foundLs = empty($numbLst) ? (($dfltDayCount>0) ? Array($dfltDayCount) : Array()) : $numbLst;
		}
		$daysLst = Array();
		foreach(array_unique($foundLs) as $dayNum){
			if( $dayNum>=1 && $dayNum<=$daysNum ){
				$daysLst[] = $firstOf->modify('+'.($dayNum-1).' days')->format('Y-m-d');
			}
		}
		sort($daysLst);
		return $daysLst;
	}

	/**
	 * Reads an entry of a calendar: an event, a task or a note.
	 *
	 * @param Array          $cmpnntValues The entry, as readComponents() gives it.
	 * @param String         $itemKindsTxt Its kind: event, tasks or notes.
	 * @param ?\DateTimeZone $zoneAskedFor The time zone its times are asked in, null to leave them as written.
	 * @return Array The entry, with every key of its kind.
	 */
	protected function entryReadArray(Array $cmpnntValues, String $itemKindsTxt, ?\DateTimeZone $zoneAskedFor): Array {
		$propLst = Array();
		$extrLst = Array();
		foreach($cmpnntValues['props'] as $prpSet){
			$propLst[$prpSet['names']][] = $prpSet;
			// What has no key of its own is kept as written; a property given several times keeps all its values
			if( !in_array($prpSet['names'], self::READ_LIST) ){
				$extrLst[$prpSet['names']] = isset($extrLst[$prpSet['names']]) ? array_merge((array)$extrLst[$prpSet['names']], Array($prpSet['value'])) : $prpSet['value'];
			}
		}
		$vodDate = Array('value'=>'', 'whole'=>false, 'tzone'=>'');
		$strtSet = isset($propLst['DTSTART']) ? $this->datesReadArray($propLst['DTSTART'][0]['value'], $propLst['DTSTART'][0]['parms']) : $vodDate;
		$zoneNme = $strtSet['tzone'];
		// The end: written, or the start moved by the length of the entry. Whole days end on the day before the one written
		$endValu = $strtSet['value'];
		$spanSec = isset($propLst['DURATION']) ? $this->spanOfDuration($propLst['DURATION'][0]['value']) : null;
		if( isset($propLst['DTEND']) && $this->datesReadArray($propLst['DTEND'][0]['value'], $propLst['DTEND'][0]['parms'])['value']!=='' ){
			$endValu = $this->datesReadArray($propLst['DTEND'][0]['value'], $propLst['DTEND'][0]['parms'])['value'];
		}elseif( $spanSec!==null && $strtSet['value']!=='' ){
			$endValu = $this->switchDatesTxt($strtSet['value'], $spanSec);
		}elseif( $itemKindsTxt!='event' ){
			$endValu = '';
		}
		if( $strtSet['whole'] && strlen($endValu)==10 && $endValu>$strtSet['value'] ){
			$endValu = $this->switchDatesTxt($endValu, -86400);
		}
		$dueDate = isset($propLst['DUE']) ? $this->datesReadArray($propLst['DUE'][0]['value'], $propLst['DUE'][0]['parms']) : $vodDate;
		$stmpSet = isset($propLst['DTSTAMP']) ? $this->datesReadArray($propLst['DTSTAMP'][0]['value'], $propLst['DTSTAMP'][0]['parms']) : $vodDate;
		$skipLst = Array();
		foreach($propLst['EXDATE'] ?? Array() as $prpSet){
			foreach(explode(',', $prpSet['value']) as $skpTxt){
				$skipSet = $this->datesReadArray($skpTxt, $prpSet['parms']);
				if( $skipSet['value']!=='' ){
					$skipLst[] = $this->zoneShiftArray($skipSet, $zoneAskedFor)['value'];
				}
			}
		}
		// The times are given in the zone asked for, when they tell the one they are written in
		$strtDon = $this->zoneShiftArray($strtSet, $zoneAskedFor);
		$endValu = $this->zoneShiftArray(Array('value'=>$endValu, 'whole'=>$strtSet['whole'], 'tzone'=>$strtSet['tzone']), $zoneAskedFor)['value'];
		$ruleSet = Array();
		if( isset($propLst['RRULE']) ){
			$rulDone = true;
			$ruleSet = $this->rulePartsArray($propLst['RRULE'][0]['value'], $rulDone);
			if( !$rulDone || $ruleSet['every']==='' ){
				$extrLst['RRULE'] = $propLst['RRULE'][0]['value'];
			}
			if( $ruleSet['every']==='' ){
				$ruleSet = Array();
			}
		}
		$lblList = Array();
		foreach($propLst['CATEGORIES'] ?? Array() as $prpSet){
			$lblList = array_merge($lblList, $this->splitListValue($prpSet['value']));
		}
		$gestLst = Array();
		foreach($propLst['ATTENDEE'] ?? Array() as $prpSet){
			$gestLst[] = $this->partyReadArray($prpSet) + Array('reply'=>strval(self::REPL_LIST[strtoupper(strval($prpSet['parms']['PARTSTAT'] ?? 'NEEDS-ACTION'))] ?? 'waits'));
		}
		// A reminder counts back from the start of the entry; one set at a date of its own is kept as written
		$alrmLst = Array();
		foreach($cmpnntValues['comps'] as $almArr){
			foreach($almArr['props'] as $prpSet){
				$trigSec = ($almArr['kinds']=='VALARM' && $prpSet['names']=='TRIGGER') ? $this->spanOfDuration($prpSet['value']) : null;
				if( $trigSec!==null ){
					$alrmLst[] = intdiv(-$trigSec, 60);
				}
			}
		}
		$getText = function(String $propertyName) use ($propLst){ return isset($propLst[$propertyName]) ? $this->unescapeTxtVal($propLst[$propertyName][0]['value']) : ''; };
		$itemSet = Array(
			'ident'=>$getText('UID'), 'title'=>$getText('SUMMARY'),
			'start'=>$strtDon['value'], 'ended'=>$endValu, 'whole'=>$strtSet['whole'], 'tzone'=>$strtDon['tzone'],
			'place'=>$getText('LOCATION'), 'descr'=>$getText('DESCRIPTION'), 'links'=>Shape::tidyLinkString($getText('URL')),
			'state'=>strval(self::STAT_LIST[strtoupper($getText('STATUS'))] ?? ''), 'label'=>$lblList,
			'owner'=>isset($propLst['ORGANIZER']) ? $this->partyReadArray($propLst['ORGANIZER'][0]) : Array('names'=>'', 'email'=>''), 'guest'=>$gestLst,
			'rpeat'=>$ruleSet, 'skips'=>$skipLst, 'alarm'=>$alrmLst,
			'stamp'=>$this->zoneShiftArray($stmpSet, $zoneAskedFor)['value'], 'extra'=>$extrLst
		);
		if( $itemKindsTxt=='tasks' ){
			$itemSet = array_slice($itemSet, 0, -1, true) + Array('dueby'=>$this->zoneShiftArray($dueDate, $zoneAskedFor)['value'], 'ratio'=>max(0, min(100, intval($propLst['PERCENT-COMPLETE'][0]['value'] ?? 0))), 'extra'=>$extrLst);
		}elseif( $itemKindsTxt=='notes' ){
			$itemSet = array_intersect_key($itemSet, array_flip(Array('ident', 'title', 'start', 'whole', 'tzone', 'descr', 'state', 'label', 'stamp', 'extra')));
		}
		return $itemSet;
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
			$dateObj = new \DateTimeImmutable($dateProperty['value'], new \DateTimeZone($dateProperty['tzone']));
		}catch( \Exception $e ){
			return $dateProperty;
		}
		return Array('value'=>$dateObj->setTimezone($zoneAskedFor)->format('Y-m-d H:i:s'), 'whole'=>false, 'tzone'=>$zoneAskedFor->getName());
	}

}
