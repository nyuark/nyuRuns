<?php

namespace nyu\Data\Writers;

use nyu\Data\Shape;

/**
 * Calendars (ics): the events, the tasks and the notes the calendar
 * parser gives, written back so that a calendar read then written reads
 * back the same.
 *
 * An entry lasting whole days is written with the day after its last
 * one as its end, as the format asks. What the parser kept as written
 * under 'extra' is written back as it is. An entry without id gets one,
 * and one without date of writing gets the present time: the format
 * asks for both.
 */
class ICal extends Base {

	// The kinds of entry, by the key holding them, and the keys an entry may hold other values under
	const KIND_LIST = Array('event'=>'VEVENT', 'tasks'=>'VTODO', 'notes'=>'VJOURNAL');
	// The states of an entry, the replies of a guest, and how often an entry repeats
	const STAT_LIST = Array('cnfrm'=>'CONFIRMED', 'tentv'=>'TENTATIVE', 'cancl'=>'CANCELLED', 'waits'=>'NEEDS-ACTION', 'activ'=>'IN-PROCESS', 'compl'=>'COMPLETED', 'draft'=>'DRAFT', 'final'=>'FINAL');
	const REPL_LIST = Array('accpt'=>'ACCEPTED', 'decln'=>'DECLINED', 'tentv'=>'TENTATIVE', 'deleg'=>'DELEGATED');
	const FREQ_LIST = Array('daily'=>'DAILY', 'wekly'=>'WEEKLY', 'mthly'=>'MONTHLY', 'yerly'=>'YEARLY');
	// The properties the writer writes itself: what is kept under 'extra' never writes them a second time
	const OWNS_LIST = Array('BEGIN', 'END', 'UID', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL', 'STATUS', 'CATEGORIES', 'ORGANIZER', 'ATTENDEE', 'EXDATE', 'DTSTART', 'DTEND', 'DURATION', 'DTSTAMP', 'DUE', 'PERCENT-COMPLETE');

	/**
	 * Writes events, tasks and notes as an iCalendar text.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items', the items holding the events under 'event', the tasks under 'tasks' and the notes under 'notes'.
	 * @param Array $writeOptions Options: none.
	 * @return String The calendar, its lines ending and folded as the format asks.
	 *
	 * @throws WriteError When the shape is not 'nodes', or the content is not the entries of a calendar.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('nodes'), 'a calendar');
		$itemLst = array_change_key_case((array)($dataEnvelope['items'] ?? Array()), CASE_LOWER);
		if( !empty(array_diff_key($itemLst, self::KIND_LIST)) ){
			throw new WriteError('Content that cannot be written as a calendar: it holds something else than events, tasks and notes');
		}
		$infoArr = (array)($dataEnvelope['infos'] ?? Array());
		$lineLst = Array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'.$this->unescapeTxtVal((strval($infoArr['maker'] ?? '')!=='') ? strval($infoArr['maker']) : '-//nyuRuns//Calendar//EN'));
		foreach(Array('title'=>'X-WR-CALNAME', 'tzone'=>'X-WR-TIMEZONE') as $infKey=>$prpNme){
			if( strval($infoArr[$infKey] ?? '')!=='' ){
				$lineLst[] = $prpNme.':'.$this->unescapeTxtVal(strval($infoArr[$infKey]));
			}
		}
		foreach(self::KIND_LIST as $kndKey=>$kndNme){
			foreach((array)($itemLst[$kndKey] ?? Array()) as $entSet){
				$lineLst = array_merge($lineLst, Array('BEGIN:'.$kndNme), $this->entrysOutArray((array)$entSet, $kndKey), Array('END:'.$kndNme));
			}
		}
		$lineLst[] = 'END:VCALENDAR';
		// A line holds 75 bytes at most: a longer one is carried on over the next ones, each starting with a space
		$icsText = '';
		foreach($lineLst as $linTxt){
			while( strlen($linTxt)>75 ){
				$cutPstn = 75;
				// A character written over several bytes is never cut
				while( $cutPstn>1 && (ord($linTxt[$cutPstn]) & 0xC0)==0x80 ){
					$cutPstn--;
				}
				$icsText .= substr($linTxt, 0, $cutPstn)."\r\n";
				$linTxt = ' '.substr($linTxt, $cutPstn);
			}
			$icsText .= $linTxt."\r\n";
		}
		return $icsText;
	}

	/**
	 * Writes a text as the format asks: a backslash before what it gives
	 * a meaning to, a line end as \n, and nothing that could start a line
	 * of its own.
	 *
	 * @param String $templateText The text.
	 * @return String The text, safe to write.
	 */
	protected function unescapeTxtVal(String $templateText): String {
		$safeTxt = str_replace(Array("\r\n", "\r"), "\n", $templateText);
		return strtr(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $safeTxt), Array('\\'=>'\\\\', ';'=>'\\;', ','=>'\\,', "\n"=>'\\n'));
	}

	/**
	 * Writes a date as the property holding it, with its time zone.
	 *
	 * @param String $propertyName Name of the property.
	 * @param String $isoDateStrng The date, as ISO text, with or without a time.
	 * @param String $timeZoneName The time zone it is written in: 'UTC', a name, or '' for none.
	 * @param Int    $daysToMoveNb Days to add to a date without time.
	 * @return Array The line of the property, in a list; an empty list when the date is not one.
	 */
	protected function datesLineArray(String $propertyName, String $isoDateStrng, String $timeZoneName='', Int $daysToMoveNb=0): Array {
		$dateLst = Array();
		if( !preg_match('/^(\d{4})-(\d\d)-(\d\d)(?:[ T](\d\d):(\d\d)(?::(\d\d))?)?$/D', $isoDateStrng, $dateLst) || !checkdate(intval($dateLst[2]), intval($dateLst[3]), intval($dateLst[1])) ){
			return Array();
		}
		if( !isset($dateLst[4]) ){
			$dateObj = new \DateTimeImmutable($dateLst[1].'-'.$dateLst[2].'-'.$dateLst[3], new \DateTimeZone('UTC'));
			return Array($propertyName.';VALUE=DATE:'.$dateObj->modify(sprintf('%+d days', $daysToMoveNb))->format('Ymd'));
		}
		$timeTxt = $dateLst[1].$dateLst[2].$dateLst[3].'T'.$dateLst[4].$dateLst[5].strval($dateLst[6] ?? '00');
		if( strtoupper($timeZoneName)=='UTC' ){
			return Array($propertyName.':'.$timeTxt.'Z');
		}
		// A zone is written when it is a plain name; a time without one is the time of wherever it is read
		return Array($propertyName.(preg_match('/^[\w\/+\-][\w\/+\- ]*$/D', $timeZoneName) ? ';TZID='.$timeZoneName : '').':'.$timeTxt);
	}

	/**
	 * Writes who organises an entry, or who is invited to it.
	 *
	 * @param String $propertyName Name of the property: ORGANIZER or ATTENDEE.
	 * @param Array  $partyDetails The person: 'names', 'email' and, for a guest, 'reply'.
	 * @return Array The line of the property, in a list; an empty list when the person has no address.
	 */
	protected function partyLineArray(String $propertyName, Array $partyDetails): Array {
		$mailAdr = preg_replace('/[\s\x00-\x1F\x7F;,]/', '', strval($partyDetails['email'] ?? ''));
		if( $mailAdr==='' ){
			return Array();
		}
		// A name stands between quotes when it holds what would end it
		$nameTxt = trim(preg_replace('/["\x00-\x1F\x7F]/', '', strval($partyDetails['names'] ?? '')));
		$parmTxt = ($nameTxt==='') ? '' : ';CN='.(preg_match('/[:;,]/', $nameTxt) ? '"'.$nameTxt.'"' : $nameTxt);
		$parmTxt .= isset(self::REPL_LIST[strval($partyDetails['reply'] ?? '')]) ? ';PARTSTAT='.self::REPL_LIST[$partyDetails['reply']] : '';
		return Array($propertyName.$parmTxt.':mailto:'.$mailAdr);
	}

	/**
	 * Writes the rule of a repeating entry.
	 *
	 * @param Array $ruleSettings The rule: 'every', 'steps', 'wdays', 'mdays', 'month', 'until', 'times'.
	 * @return Array The line of the rule, in a list; an empty list when the entry does not repeat.
	 */
	protected function rulesLineArray(Array $ruleSettings): Array {
		if( !isset(self::FREQ_LIST[strval($ruleSettings['every'] ?? '')]) ){
			return Array();
		}
		$partLst = Array('FREQ='.self::FREQ_LIST[$ruleSettings['every']]);
		if( intval($ruleSettings['steps'] ?? 1)>1 ){
			$partLst[] = 'INTERVAL='.intval($ruleSettings['steps']);
		}
		$daysLst = array_filter(array_map('strtoupper', array_map('strval', (array)($ruleSettings['wdays'] ?? Array()))), function($wekDayString){ return preg_match('/^[+\-]?\d{0,2}(MO|TU|WE|TH|FR|SA|SU)$/D', $wekDayString)===1; });
		foreach(Array('BYDAY'=>$daysLst, 'BYMONTHDAY'=>array_map('intval', (array)($ruleSettings['mdays'] ?? Array())), 'BYMONTH'=>array_map('intval', (array)($ruleSettings['month'] ?? Array()))) as $prtNme=>$oneDay){
			if( !empty($oneDay) ){
				$partLst[] = $prtNme.'='.implode(',', $oneDay);
			}
		}
		// The last date is a day, or a time told in universal time
		$lastLin = $this->datesLineArray('UNTIL', strval($ruleSettings['until'] ?? ''), 'UTC');
		if( !empty($lastLin) ){
			$partLst[] = 'UNTIL='.substr($lastLin[0], intval(strrpos($lastLin[0], ':'))+1);
		}
		if( intval($ruleSettings['times'] ?? 0)>0 ){
			$partLst[] = 'COUNT='.intval($ruleSettings['times']);
		}
		return Array('RRULE:'.implode(';', $partLst));
	}

	/**
	 * Writes an entry of a calendar: an event, a task or a note.
	 *
	 * @param Array  $calendarItem The entry, as the calendar parser gives it.
	 * @param String $itemKindsTxt Its kind: event, tasks or notes.
	 * @return Array The lines of the entry, without those that open and close it.
	 */
	protected function entrysOutArray(Array $calendarItem, String $itemKindsTxt): Array {
		$isWhole = !empty($calendarItem['whole']);
		$zoneNme = strval($calendarItem['tzone'] ?? '');
		$strtVal = strval($calendarItem['start'] ?? '');
		$lineLst = Array('UID:'.$this->unescapeTxtVal((strval($calendarItem['ident'] ?? '')!=='') ? strval($calendarItem['ident']) : bin2hex(random_bytes(16)).'@nyuruns'));
		// The date an entry was written is told in universal time; the present one when the entry tells none
		$lineLst = array_merge($lineLst, $this->datesLineArray('DTSTAMP', (strlen(strval($calendarItem['stamp'] ?? ''))>10) ? strval($calendarItem['stamp']) : gmdate('Y-m-d H:i:s'), 'UTC'));
		$strtLin = $this->datesLineArray('DTSTART', $strtVal, $zoneNme);
		$lineLst = array_merge($lineLst, $strtLin);
		if( $itemKindsTxt=='event' && !empty($strtLin) ){
			// Whole days end, as the format writes it, on the day after their last one
			$endValu = (strval($calendarItem['ended'] ?? '')!=='') ? strval($calendarItem['ended']) : $strtVal;
			$lineLst = array_merge($lineLst, $this->datesLineArray('DTEND', $endValu, $zoneNme, $isWhole ? 1 : 0));
		}
		if( $itemKindsTxt=='tasks' ){
			$lineLst = array_merge($lineLst, $this->datesLineArray('DUE', strval($calendarItem['dueby'] ?? ''), $zoneNme));
			if( intval($calendarItem['ratio'] ?? 0)>0 ){
				$lineLst[] = 'PERCENT-COMPLETE:'.max(0, min(100, intval($calendarItem['ratio'])));
			}
		}
		foreach(Array('title'=>'SUMMARY', 'place'=>'LOCATION', 'descr'=>'DESCRIPTION') as $fldKey=>$prpNme){
			if( strval($calendarItem[$fldKey] ?? '')!=='' ){
				$lineLst[] = $prpNme.':'.$this->unescapeTxtVal(strval($calendarItem[$fldKey]));
			}
		}
		$linkAdr = Shape::tidyLinkString(strval($calendarItem['links'] ?? ''));
		if( $linkAdr!=='' ){
			$lineLst[] = 'URL:'.preg_replace('/[\x00-\x20\x7F]/', '', $linkAdr);
		}
		if( isset(self::STAT_LIST[strval($calendarItem['state'] ?? '')]) ){
			$lineLst[] = 'STATUS:'.self::STAT_LIST[$calendarItem['state']];
		}
		$lblList = array_filter(array_map('strval', (array)($calendarItem['label'] ?? Array())), 'strlen');
		if( !empty($lblList) ){
			$lineLst[] = 'CATEGORIES:'.implode(',', array_map(Array($this, 'unescapeTxtVal'), $lblList));
		}
		$lineLst = array_merge($lineLst, $this->partyLineArray('ORGANIZER', (array)($calendarItem['owner'] ?? Array())));
		foreach((array)($calendarItem['guest'] ?? Array()) as $gstSet){
			$lineLst = array_merge($lineLst, $this->partyLineArray('ATTENDEE', (array)$gstSet));
		}
		// A rule kept as written says more than the one that was read: it is the one written back
		$extrLst = (array)($calendarItem['extra'] ?? Array());
		if( !isset(array_change_key_case($extrLst, CASE_UPPER)['RRULE']) ){
			$lineLst = array_merge($lineLst, $this->rulesLineArray((array)($calendarItem['rpeat'] ?? Array())));
		}
		foreach((array)($calendarItem['skips'] ?? Array()) as $skpVal){
			$lineLst = array_merge($lineLst, $this->datesLineArray('EXDATE', strval($skpVal), $zoneNme));
		}
		foreach($extrLst as $prpNme=>$prpVal){
			// What was kept as written is written as it was, when its name is one and it is not a property written above
			if( !preg_match('/^[A-Za-z0-9\-]+$/D', strval($prpNme)) || in_array(strtoupper(strval($prpNme)), self::OWNS_LIST) ){
				continue;
			}
			foreach((array)$prpVal as $oneVal){
				if( is_scalar($oneVal) ){
					$lineLst[] = strtoupper(strval($prpNme)).':'.preg_replace('/[\x00-\x1F\x7F]/', '', strval($oneVal));
				}
			}
		}
		foreach((array)($calendarItem['alarm'] ?? Array()) as $minute){
			// A reminder counts back from the start of the entry
			$minute = intval($minute);
			$lineLst = array_merge($lineLst, Array('BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->unescapeTxtVal((strval($calendarItem['title'] ?? '')!=='') ? strval($calendarItem['title']) : 'Reminder'), 'TRIGGER:'.(($minute>0) ? '-' : '').'PT'.abs($minute).'M', 'END:VALARM'));
		}
		return $lineLst;
	}

}
