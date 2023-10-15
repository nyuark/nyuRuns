<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

// Calendars (ics): the events, the tasks and the notes of a calendar,
// each as plain keys and values.
//
// Dates are ISO text, in the time zone they are written in unless the
// 'tzone' option asks for another. An event lasting whole days ends on
// its last day, where the format writes the day after. A repeating
// event gives its rule, not its occurrences. What the parser does not
// read into a key of its own is kept, as written, under 'extra'.
class ICal extends Base {

	// The kinds of entry, by the name the format gives them
	const KINDLIST = Array('VEVENT'=>'event', 'VTODO'=>'tasks', 'VJOURNAL'=>'notes');
	// The states of an entry, the replies of a guest, and how often an entry repeats
	const STATLIST = Array('CONFIRMED'=>'cnfrm', 'TENTATIVE'=>'tentv', 'CANCELLED'=>'cancl', 'NEEDS-ACTION'=>'waits', 'IN-PROCESS'=>'activ', 'COMPLETED'=>'compl', 'DRAFT'=>'draft', 'FINAL'=>'final');
	const REPLLIST = Array('ACCEPTED'=>'accpt', 'DECLINED'=>'decln', 'TENTATIVE'=>'tentv', 'NEEDS-ACTION'=>'waits', 'DELEGATED'=>'deleg');
	const FREQLIST = Array('DAILY'=>'daily', 'WEEKLY'=>'wekly', 'MONTHLY'=>'mthly', 'YEARLY'=>'yerly');
	// The properties read into a key of their own: the others are kept under 'extra'
	const READLIST = Array('UID', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL', 'STATUS', 'CATEGORIES', 'ORGANIZER', 'ATTENDEE', 'RRULE', 'EXDATE', 'DTSTART', 'DTEND', 'DURATION', 'DTSTAMP', 'DUE', 'PERCENT-COMPLETE');

	/**
	 * Parses a calendar. An empty content gives a calendar without entry.
	 *
	 * Options:
	 *   'tzone' => gives the times in this time zone, as 'Europe/Paris'
	 *              or 'UTC'. The entries lasting whole days, and the
	 *              times written without a zone, are left as they are;
	 *   'upper' => upper-cases the keys of the first level.
	 *
	 * @param String $rawContent The calendar.
	 * @param Array  $parseOptns Options: 'tzone', 'upper'.
	 * @return Array The envelope, of shape 'nodes', holding the events under 'event', the tasks under 'tasks' and the notes under 'notes'; 'infos' holds the name of the calendar under 'title', its time zone under 'tzone', the program that wrote it under 'maker' and the number of its entries under 'count'.
	 *
	 * @throws ParseError When the content is not a calendar, an entry is not closed, or the time zone asked for is not a known one.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		$trgtZne = null;
		if( strval($parseOptns['tzone'] ?? '')!=='' ){
			try{
				$trgtZne = new \DateTimeZone(strval($parseOptns['tzone']));
			}catch( \Exception $e ){
				throw new ParseError('Invalid calendar option: unknown time zone '.strval($parseOptns['tzone']), 0, $e);
			}
		}
		$itemLst = Array('event'=>Array(), 'tasks'=>Array(), 'notes'=>Array());
		$infoArr = Array('title'=>'', 'tzone'=>'', 'maker'=>'', 'count'=>0);
		foreach($this->readComps($this->foldLines(self::cleanText($rawContent))) as $cldArr){
			foreach(Array('X-WR-CALNAME'=>'title', 'X-WR-TIMEZONE'=>'tzone', 'PRODID'=>'maker') as $prpNme=>$infKey){
				foreach($cldArr['props'] as $prpSet){
					if( $prpSet['names']==$prpNme && $infoArr[$infKey]==='' ){
						$infoArr[$infKey] = $this->textValue($prpSet['value']);
					}
				}
			}
			foreach($cldArr['comps'] as $cmpArr){
				if( isset(self::KINDLIST[$cmpArr['kinds']]) ){
					$itemLst[self::KINDLIST[$cmpArr['kinds']]][] = $this->entryRead($cmpArr, self::KINDLIST[$cmpArr['kinds']], $trgtZne);
					$infoArr['count']++;
				}
			}
		}
		return $this->makeShape('nodes', !empty($parseOptns['upper']) ? $this->upperKeys($itemLst) : $itemLst, $infoArr);
	}

	/**
	 * Cuts a calendar into its lines. A line too long for the format is
	 * written over several, each of the following ones starting with a
	 * space: they are brought back into one.
	 *
	 * @param String $rawContent The calendar, in UTF-8.
	 * @return Array The lines, each with its text and the number of the line it starts on.
	 */
	protected function foldLines(String $rawContent): Array {
		$lineLst = Array();
		foreach(preg_split('/\r\n|\r|\n/', $rawContent) as $lneIdx=>$lneTxt){
			if( $lneTxt!=='' && ($lneTxt[0]==' ' || $lneTxt[0]=="\t") && !empty($lineLst) ){
				$lineLst[count($lineLst)-1][0] .= substr($lneTxt, 1);
			}elseif( trim($lneTxt)!=='' ){
				$lineLst[] = Array($lneTxt, $lneIdx+1);
			}
		}
		return $lineLst;
	}

	/**
	 * Reads the lines of a calendar into its calendars, their entries
	 * and what these hold.
	 *
	 * @param Array $linesArray The lines, as foldLines() gives them.
	 * @return Array The calendars, each with its kind under 'kinds', its properties under 'props' (each with its name under 'names', what qualifies it under 'parms' and its value under 'value') and what it holds under 'comps'.
	 *
	 * @throws ParseError When the content is not a calendar, or what is opened and what is closed do not match.
	 */
	protected function readComps(Array $linesArray): Array {
		$rootLst = Array();
		// What is being read, from the calendar down to the innermost entry, and the line each was opened on
		$openLst = Array();
		$openLns = Array();
		foreach($linesArray as $lneSet){
			// A line is a name, what qualifies it after semicolons, then its value after the first colon that is not between quotes
			$headPrt = Array();
			if( !preg_match('/^((?:[^":]|"[^"]*")*):(.*)$/Ds', $lneSet[0], $headPrt) || trim($headPrt[1])==='' ){
				throw new ParseError('Invalid calendar content: a line that is not a property on line '.$lneSet[1]);
			}
			$namePrt = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $headPrt[1]);
			$propNme = strtoupper(trim(strval(array_shift($namePrt))));
			$propVal = $headPrt[2];
			if( $propNme=='BEGIN' ){
				if( empty($openLst) && strtoupper(trim($propVal))!='VCALENDAR' ){
					throw new ParseError('Invalid calendar content: the content does not start a calendar on line '.$lneSet[1]);
				}
				if( count($openLst)>=8 ){
					throw new ParseError('Invalid calendar content: nested deeper than allowed on line '.$lneSet[1]);
				}
				$openLst[] = Array('kinds'=>strtoupper(trim($propVal)), 'props'=>Array(), 'comps'=>Array());
				$openLns[] = $lneSet[1];
				continue;
			}
			if( empty($openLst) ){
				throw new ParseError('Invalid calendar content: the content does not start a calendar on line '.$lneSet[1]);
			}
			if( $propNme=='END' ){
				$doneCmp = array_pop($openLst);
				array_pop($openLns);
				if( $doneCmp['kinds']!==strtoupper(trim($propVal)) ){
					throw new ParseError('Invalid calendar content: '.strtoupper(trim($propVal)).' closed where '.$doneCmp['kinds'].' is open on line '.$lneSet[1]);
				}
				if( empty($openLst) ){
					$rootLst[] = $doneCmp;
				}else{
					$openLst[count($openLst)-1]['comps'][] = $doneCmp;
				}
				continue;
			}
			$parmLst = Array();
			foreach($namePrt as $prmTxt){
				$prmPrts = explode('=', $prmTxt, 2);
				$parmLst[strtoupper(trim($prmPrts[0]))] = trim(strval($prmPrts[1] ?? ''), " \t\"");
			}
			$openLst[count($openLst)-1]['props'][] = Array('names'=>$propNme, 'parms'=>$parmLst, 'value'=>$propVal);
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
	 * @param String $valueOfTag The text, as written.
	 * @return String The text.
	 */
	protected function textValue(String $valueOfTag): String {
		return trim(preg_replace_callback('/\\\\(.)/s', function($foundParts){ return (strtolower($foundParts[1])=='n') ? "\n" : $foundParts[1]; }, $valueOfTag));
	}

	/**
	 * Cuts a value holding several texts, separated by commas.
	 *
	 * @param String $valueOfTag The value, as written.
	 * @return Array The texts, none of them empty.
	 */
	protected function listValue(String $valueOfTag): Array {
		$textLst = Array();
		foreach(preg_split('/(?<!\\\\),/', $valueOfTag) as $oneTxt){
			if( trim($oneTxt)!=='' ){
				$textLst[] = $this->textValue($oneTxt);
			}
		}
		return $textLst;
	}

	/**
	 * Reads a date, with or without a time.
	 *
	 * @param String $valueOfTag The date, as written: 20261228, 20261228T203000 or 20261228T203000Z.
	 * @param Array  $parmsArray What qualifies it: its time zone under TZID.
	 * @return Array The date as ISO text under 'value', '' when it cannot be read; whether it has no time under 'whole'; and the time zone it is written in under 'tzone', 'UTC' for a time ending with Z and '' when it tells none.
	 */
	protected function datesRead(String $valueOfTag, Array $parmsArray): Array {
		$datePrt = Array();
		if( !preg_match('/^(\d{4})(\d\d)(\d\d)(?:T(\d\d)(\d\d)(\d\d)?(Z)?)?$/Di', trim($valueOfTag), $datePrt) || !checkdate(intval($datePrt[2]), intval($datePrt[3]), intval($datePrt[1])) ){
			return Array('value'=>'', 'whole'=>false, 'tzone'=>'');
		}
		$dateTxt = $datePrt[1].'-'.$datePrt[2].'-'.$datePrt[3];
		if( !isset($datePrt[4]) || $datePrt[4]==='' ){
			return Array('value'=>$dateTxt, 'whole'=>true, 'tzone'=>'');
		}
		$zoneNme = (($datePrt[7] ?? '')!=='') ? 'UTC' : ltrim(strval($parmsArray['TZID'] ?? ''), '/');
		return Array('value'=>$dateTxt.' '.$datePrt[4].':'.$datePrt[5].':'.((($datePrt[6] ?? '')!=='') ? $datePrt[6] : '00'), 'whole'=>false, 'tzone'=>$zoneNme);
	}

	/**
	 * Reads a length of time into seconds.
	 *
	 * @param String $valueOfTag The length, as written: PT90M, P1DT2H, -PT15M, P2W.
	 * @return ?Int The seconds, negative for a length counted backwards; null when it cannot be read.
	 */
	protected function spanOfDur(String $valueOfTag): ?Int {
		$spanPrt = Array();
		if( !preg_match('/^([+\-]?)P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/Di', trim($valueOfTag), $spanPrt, PREG_UNMATCHED_AS_NULL) || trim($valueOfTag, '+-Pp')==='' ){
			return null;
		}
		$secsNbr = intval($spanPrt[2])*604800+intval($spanPrt[3])*86400+intval($spanPrt[4])*3600+intval($spanPrt[5])*60+intval($spanPrt[6]);
		return ($spanPrt[1]=='-') ? -$secsNbr : $secsNbr;
	}

	/**
	 * Moves a date by a number of seconds, the clock being read as it
	 * stands, without the hour a change of season adds or takes.
	 *
	 * @param String $isoDateStr The date, as ISO text, with or without a time.
	 * @param Int    $secondsAdd The seconds to add, negative to go back.
	 * @return String The date moved, in the form it came in.
	 */
	protected function moveDates(String $isoDateStr, Int $secondsAdd): String {
		$dateObj = new \DateTimeImmutable($isoDateStr, new \DateTimeZone('UTC'));
		return $dateObj->modify(sprintf('%+d seconds', $secondsAdd))->format((strlen($isoDateStr)>10) ? 'Y-m-d H:i:s' : 'Y-m-d');
	}

	/**
	 * Reads who organises an entry, or who is invited to it.
	 *
	 * @param Array $propValues The property: its 'value' and its 'parms'.
	 * @return Array The name of the person under 'names', '' when it is not told, and the address under 'email'.
	 */
	protected function partyRead(Array $propValues): Array {
		return Array('names'=>strval($propValues['parms']['CN'] ?? ''), 'email'=>trim(preg_replace('/^mailto:/i', '', trim($propValues['value']))));
	}

	/**
	 * Reads the rule of a repeating entry.
	 *
	 * @param String $valueOfTag The rule, as written: FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=10.
	 * @param Bool   $allWasRead Receives whether everything the rule says was read; what was not must be kept as written.
	 * @return Array How often under 'every' (daily, wekly, mthly, yerly, '' for a rule that is none of these), every how many under 'steps', the days of the week under 'wdays', of the month under 'mdays', the months under 'month', the last date under 'until' and the number of times under 'times'.
	 */
	protected function ruleParts(String $valueOfTag, Bool &$allWasRead): Array {
		$ruleSet = Array('every'=>'', 'steps'=>1, 'wdays'=>Array(), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'', 'times'=>0);
		$allWasRead = true;
		foreach(explode(';', $valueOfTag) as $prtTxt){
			$prtPair = explode('=', $prtTxt, 2);
			$prtName = strtoupper(trim($prtPair[0]));
			$prtValu = trim(strval($prtPair[1] ?? ''));
			if( $prtName=='FREQ' && isset(self::FREQLIST[strtoupper($prtValu)]) ){
				$ruleSet['every'] = self::FREQLIST[strtoupper($prtValu)];
			}elseif( $prtName=='INTERVAL' ){
				$ruleSet['steps'] = max(1, intval($prtValu));
			}elseif( $prtName=='COUNT' ){
				$ruleSet['times'] = max(0, intval($prtValu));
			}elseif( $prtName=='UNTIL' ){
				$ruleSet['until'] = $this->datesRead($prtValu, Array())['value'];
			}elseif( $prtName=='BYDAY' ){
				$ruleSet['wdays'] = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $prtValu))), function($weekDayStr){ return preg_match('/^[+\-]?\d{0,2}(mo|tu|we|th|fr|sa|su)$/D', $weekDayStr)===1; }));
			}elseif( $prtName=='BYMONTHDAY' ){
				$ruleSet['mdays'] = array_map('intval', array_filter(explode(',', $prtValu), 'is_numeric'));
			}elseif( $prtName=='BYMONTH' ){
				$ruleSet['month'] = array_map('intval', array_filter(explode(',', $prtValu), 'is_numeric'));
			}elseif( $prtName!=='' ){
				$allWasRead = false;
			}
		}
		return $ruleSet;
	}

	/**
	 * Reads an entry of a calendar: an event, a task or a note.
	 *
	 * @param Array          $compValues The entry, as readComps() gives it.
	 * @param String         $entryKinds Its kind: event, tasks or notes.
	 * @param ?\DateTimeZone $targetZone The time zone its times are asked in, null to leave them as written.
	 * @return Array The entry, with every key of its kind.
	 */
	protected function entryRead(Array $compValues, String $entryKinds, ?\DateTimeZone $targetZone): Array {
		$propLst = Array();
		$xtraLst = Array();
		foreach($compValues['props'] as $prpSet){
			$propLst[$prpSet['names']][] = $prpSet;
			// What has no key of its own is kept as written; a property given several times keeps all its values
			if( !in_array($prpSet['names'], self::READLIST) ){
				$xtraLst[$prpSet['names']] = isset($xtraLst[$prpSet['names']]) ? array_merge((array)$xtraLst[$prpSet['names']], Array($prpSet['value'])) : $prpSet['value'];
			}
		}
		$voidDte = Array('value'=>'', 'whole'=>false, 'tzone'=>'');
		$strtSet = isset($propLst['DTSTART']) ? $this->datesRead($propLst['DTSTART'][0]['value'], $propLst['DTSTART'][0]['parms']) : $voidDte;
		$zoneNme = $strtSet['tzone'];
		// The end: written, or the start moved by the length of the entry. Whole days end on the day before the one written
		$endValu = $strtSet['value'];
		$spanSec = isset($propLst['DURATION']) ? $this->spanOfDur($propLst['DURATION'][0]['value']) : null;
		if( isset($propLst['DTEND']) && $this->datesRead($propLst['DTEND'][0]['value'], $propLst['DTEND'][0]['parms'])['value']!=='' ){
			$endValu = $this->datesRead($propLst['DTEND'][0]['value'], $propLst['DTEND'][0]['parms'])['value'];
		}elseif( $spanSec!==null && $strtSet['value']!=='' ){
			$endValu = $this->moveDates($strtSet['value'], $spanSec);
		}elseif( $entryKinds!='event' ){
			$endValu = '';
		}
		if( $strtSet['whole'] && strlen($endValu)==10 && $endValu>$strtSet['value'] ){
			$endValu = $this->moveDates($endValu, -86400);
		}
		$dueSet = isset($propLst['DUE']) ? $this->datesRead($propLst['DUE'][0]['value'], $propLst['DUE'][0]['parms']) : $voidDte;
		$stmpSet = isset($propLst['DTSTAMP']) ? $this->datesRead($propLst['DTSTAMP'][0]['value'], $propLst['DTSTAMP'][0]['parms']) : $voidDte;
		$skipLst = Array();
		foreach($propLst['EXDATE'] ?? Array() as $prpSet){
			foreach(explode(',', $prpSet['value']) as $skpTxt){
				$skipSet = $this->datesRead($skpTxt, $prpSet['parms']);
				if( $skipSet['value']!=='' ){
					$skipLst[] = $this->zoneShift($skipSet, $targetZone)['value'];
				}
			}
		}
		// The times are given in the zone asked for, when they tell the one they are written in
		$strtDne = $this->zoneShift($strtSet, $targetZone);
		$endValu = $this->zoneShift(Array('value'=>$endValu, 'whole'=>$strtSet['whole'], 'tzone'=>$strtSet['tzone']), $targetZone)['value'];
		$ruleSet = Array();
		if( isset($propLst['RRULE']) ){
			$ruleDne = true;
			$ruleSet = $this->ruleParts($propLst['RRULE'][0]['value'], $ruleDne);
			if( !$ruleDne || $ruleSet['every']==='' ){
				$xtraLst['RRULE'] = $propLst['RRULE'][0]['value'];
			}
			if( $ruleSet['every']==='' ){
				$ruleSet = Array();
			}
		}
		$lablLst = Array();
		foreach($propLst['CATEGORIES'] ?? Array() as $prpSet){
			$lablLst = array_merge($lablLst, $this->listValue($prpSet['value']));
		}
		$gestLst = Array();
		foreach($propLst['ATTENDEE'] ?? Array() as $prpSet){
			$gestLst[] = $this->partyRead($prpSet) + Array('reply'=>strval(self::REPLLIST[strtoupper(strval($prpSet['parms']['PARTSTAT'] ?? 'NEEDS-ACTION'))] ?? 'waits'));
		}
		// A reminder counts back from the start of the entry; one set at a date of its own is kept as written
		$alrmLst = Array();
		foreach($compValues['comps'] as $almArr){
			foreach($almArr['props'] as $prpSet){
				$trigSec = ($almArr['kinds']=='VALARM' && $prpSet['names']=='TRIGGER') ? $this->spanOfDur($prpSet['value']) : null;
				if( $trigSec!==null ){
					$alrmLst[] = intdiv(-$trigSec, 60);
				}
			}
		}
		$textOf = function(String $propsNames) use ($propLst){ return isset($propLst[$propsNames]) ? $this->textValue($propLst[$propsNames][0]['value']) : ''; };
		$entrSet = Array(
			'ident'=>$textOf('UID'), 'title'=>$textOf('SUMMARY'),
			'start'=>$strtDne['value'], 'ended'=>$endValu, 'whole'=>$strtSet['whole'], 'tzone'=>$strtDne['tzone'],
			'place'=>$textOf('LOCATION'), 'descr'=>$textOf('DESCRIPTION'), 'links'=>Shape::cleanLink($textOf('URL')),
			'state'=>strval(self::STATLIST[strtoupper($textOf('STATUS'))] ?? ''), 'label'=>$lablLst,
			'owner'=>isset($propLst['ORGANIZER']) ? $this->partyRead($propLst['ORGANIZER'][0]) : Array('names'=>'', 'email'=>''), 'guest'=>$gestLst,
			'rpeat'=>$ruleSet, 'skips'=>$skipLst, 'alarm'=>$alrmLst,
			'stamp'=>$this->zoneShift($stmpSet, $targetZone)['value'], 'extra'=>$xtraLst
		);
		if( $entryKinds=='tasks' ){
			$entrSet = array_slice($entrSet, 0, -1, true) + Array('dueby'=>$this->zoneShift($dueSet, $targetZone)['value'], 'ratio'=>max(0, min(100, intval($propLst['PERCENT-COMPLETE'][0]['value'] ?? 0))), 'extra'=>$xtraLst);
		}elseif( $entryKinds=='notes' ){
			$entrSet = array_intersect_key($entrSet, array_flip(Array('ident', 'title', 'start', 'whole', 'tzone', 'descr', 'state', 'label', 'stamp', 'extra')));
		}
		return $entrSet;
	}

	/**
	 * Gives a date in the time zone asked for. A date without a time, a
	 * time that tells no zone, and a time written in a zone PHP does not
	 * know are left as they are.
	 *
	 * @param Array          $dateValues The date, as datesRead() gives it.
	 * @param ?\DateTimeZone $targetZone The zone asked for, null to leave the date as written.
	 * @return Array The date, as it came in, moved to that zone when it could be.
	 */
	protected function zoneShift(Array $dateValues, ?\DateTimeZone $targetZone): Array {
		if( $targetZone===null || $dateValues['whole'] || $dateValues['tzone']==='' || strlen($dateValues['value'])<=10 ){
			return $dateValues;
		}
		try{
			$dateObj = new \DateTimeImmutable($dateValues['value'], new \DateTimeZone($dateValues['tzone']));
		}catch( \Exception $e ){
			return $dateValues;
		}
		return Array('value'=>$dateObj->setTimezone($targetZone)->format('Y-m-d H:i:s'), 'whole'=>false, 'tzone'=>$targetZone->getName());
	}

}
