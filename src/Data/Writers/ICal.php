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
	const KIND_LIST = Array('calEvent'=>'VEVENT', 'calTodos'=>'VTODO', 'calNotes'=>'VJOURNAL');
	// The states of an entry, the replies of a guest, and how often an entry repeats
	const STAT_LIST = Array('definite'=>'CONFIRMED', 'possible'=>'TENTATIVE', 'canceled'=>'CANCELLED', 'awaiting'=>'NEEDS-ACTION', 'underway'=>'IN-PROCESS', 'finished'=>'COMPLETED', 'drafting'=>'DRAFT', 'finalVer'=>'FINAL');
	const PART_STAT = Array('accepted'=>'ACCEPTED', 'declined'=>'DECLINED', 'possible'=>'TENTATIVE', 'delegate'=>'DELEGATED');
	const FREQ_LIST = Array('eachDays'=>'DAILY', 'eachWeek'=>'WEEKLY', 'eachMnth'=>'MONTHLY', 'eachYear'=>'YEARLY');
	// The properties the writer writes itself: what is kept under 'extra' never writes them a second time
	const OWNS_LIST = Array('BEGIN', 'END', 'UID', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL', 'STATUS', 'CATEGORIES', 'ORGANIZER', 'ATTENDEE', 'EXDATE', 'DTSTART', 'DTEND', 'DURATION', 'DTSTAMP', 'DUE', 'PERCENT-COMPLETE');

	/**
	 * Writes events, tasks and notes as an iCalendar text.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items', the items holding the events under 'calEvent', the tasks under 'calTodos' and the notes under 'calNotes'.
	 * @param Array $writeOptions Options: none.
	 * @return String The calendar, its lines ending and folded as the format asks.
	 *
	 * @throws WriteError When the shape is not 'nodes', or the content is not the entries of a calendar.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('nodes'), 'a calendar');
		$itemList = Array();
		// The kinds whatever their case (a calendar read with its keys in upper case), as KIND_LIST writes them
		foreach( (array)($dataEnvelope['items'] ?? Array()) as $oneKey => $oneVal ){
			$itemList[ strval(array_values(preg_grep('/^'.preg_quote(strval($oneKey), '/').'$/iD', array_keys(self::KIND_LIST)))[0] ?? $oneKey) ] = $oneVal;
		}
		if( !empty(array_diff_key($itemList, self::KIND_LIST)) ){
			throw new WriteError('Content that cannot be written as a calendar: it holds something else than events, tasks and notes');
		}
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$lineList = Array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'.$this->escapeIcalText((strval($infoList['maker'] ?? '')!=='') ? strval($infoList['maker']) : '-//nyuRuns//Calendar//EN'));
		foreach(Array('title'=>'X-WR-CALNAME', 'tzone'=>'X-WR-TIMEZONE') as $oneKey=>$oneNme){
			if( strval($infoList[$oneKey] ?? '')!=='' ){
				$lineList[] = $oneNme.':'.$this->escapeIcalText(strval($infoList[$oneKey]));
			}
		}
		foreach(self::KIND_LIST as $oneKey=>$oneNme){
			foreach((array)($itemList[$oneKey] ?? Array()) as $oneSet){
				$lineList = array_merge($lineList, Array('BEGIN:'.$oneNme), $this->writeEntryList((array)$oneSet, $oneKey), Array('END:'.$oneNme));
			}
		}
		$lineList[] = 'END:VCALENDAR';
		// A line holds 75 bytes at most: a longer one is carried on over the next ones, each starting with a space
		$icsStrng = '';
		foreach($lineList as $oneTxt){
			while( strlen($oneTxt)>75 ){
				$breakPos = 75;
				// A character written over several bytes is never cut
				while( $breakPos>1 && (ord($oneTxt[$breakPos]) & 0xC0)==0x80 ){
					$breakPos--;
				}
				$icsStrng .= substr($oneTxt, 0, $breakPos)."\r\n";
				$oneTxt = ' '.substr($oneTxt, $breakPos);
			}
			$icsStrng .= $oneTxt."\r\n";
		}
		return $icsStrng;
	}

	/**
	 * Writes a text as the format asks: a backslash before what it gives
	 * a meaning to, a line end as \n, and nothing that could start a line
	 * of its own.
	 *
	 * @param String $templateText The text.
	 * @return String The text, safe to write.
	 */
	protected function escapeIcalText(String $templateText): String {
		$safeText = str_replace(Array("\r\n", "\r"), "\n", $templateText);
		return strtr(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $safeText), Array('\\'=>'\\\\', ';'=>'\\;', ','=>'\\,', "\n"=>'\\n'));
	}

	/**
	 * Writes a date as the property holding it, with its time zone.
	 *
	 * @param String $propertyName Name of the property.
	 * @param String $givenIsoDate The date, as ISO text, with or without a time.
	 * @param String $timeZoneName The time zone it is written in: 'UTC', a name, or '' for none.
	 * @param Int    $offsetInDays Days to add to a date without time.
	 * @return Array The line of the property, in a list; an empty list when the date is not one.
	 */
	protected function writeDateAsLne(String $propertyName, String $givenIsoDate, String $timeZoneName='', Int $offsetInDays=0): Array {
		$dateList = Array();
		if( !preg_match('/^(\d{4})-(\d\d)-(\d\d)(?:[ T](\d\d):(\d\d)(?::(\d\d))?)?$/D', $givenIsoDate, $dateList) || !checkdate(intval($dateList[2]), intval($dateList[3]), intval($dateList[1])) ){
			return Array();
		}
		if( !isset($dateList[4]) ){
			$dateInst = new \DateTimeImmutable($dateList[1].'-'.$dateList[2].'-'.$dateList[3], new \DateTimeZone('UTC'));
			return Array($propertyName.';VALUE=DATE:'.$dateInst->modify(sprintf('%+d days', $offsetInDays))->format('Ymd'));
		}
		$timeText = $dateList[1].$dateList[2].$dateList[3].'T'.$dateList[4].$dateList[5].strval($dateList[6] ?? '00');
		if( strtoupper($timeZoneName)=='UTC' ){
			return Array($propertyName.':'.$timeText.'Z');
		}
		// A zone is written when it is a plain name; a time without one is the time of wherever it is read
		return Array($propertyName.(preg_match('/^[\w\/+\-][\w\/+\- ]*$/D', $timeZoneName) ? ';TZID='.$timeZoneName : '').':'.$timeText);
	}

	/**
	 * Writes who organises an entry, or who is invited to it.
	 *
	 * @param String $propertyName Name of the property: ORGANIZER or ATTENDEE.
	 * @param Array  $partyDetails The person: 'names', 'email' and, for a guest, 'reply'.
	 * @return Array The line of the property, in a list; an empty list when the person has no address.
	 */
	protected function writePartyLine(String $propertyName, Array $partyDetails): Array {
		$mailAddr = preg_replace('/[\s\x00-\x1F\x7F;,]/', '', strval($partyDetails['email'] ?? ''));
		if( $mailAddr==='' ){
			return Array();
		}
		// A name stands between quotes when it holds what would end it
		$nameText = trim(preg_replace('/["\x00-\x1F\x7F]/', '', strval($partyDetails['names'] ?? '')));
		$parmText = ($nameText==='') ? '' : ';CN='.(preg_match('/[:;,]/', $nameText) ? '"'.$nameText.'"' : $nameText);
		$parmText .= isset(self::PART_STAT[strval($partyDetails['reply'] ?? '')]) ? ';PARTSTAT='.self::PART_STAT[$partyDetails['reply']] : '';
		return Array($propertyName.$parmText.':mailto:'.$mailAddr);
	}

	/**
	 * Writes the rule of a repeating entry.
	 *
	 * @param Array $ruleSettings The rule: 'every', 'steps', 'wdays', 'mdays', 'month', 'until', 'times'.
	 * @return Array The line of the rule, in a list; an empty list when the entry does not repeat.
	 */
	protected function writeRuleAsLne(Array $ruleSettings): Array {
		if( !isset(self::FREQ_LIST[strval($ruleSettings['every'] ?? '')]) ){
			return Array();
		}
		$partList = Array('FREQ='.self::FREQ_LIST[$ruleSettings['every']]);
		if( intval($ruleSettings['steps'] ?? 1)>1 ){
			$partList[] = 'INTERVAL='.intval($ruleSettings['steps']);
		}
		$daysList = array_filter(array_map('strtoupper', array_map('strval', (array)($ruleSettings['wdays'] ?? Array()))), function($rruleDayCode){ return preg_match('/^[+\-]?\d{0,2}(MO|TU|WE|TH|FR|SA|SU)$/D', $rruleDayCode)===1; });
		foreach(Array('BYDAY'=>$daysList, 'BYMONTHDAY'=>array_map('intval', (array)($ruleSettings['mdays'] ?? Array())), 'BYMONTH'=>array_map('intval', (array)($ruleSettings['month'] ?? Array()))) as $oneNme=>$oneDay){
			if( !empty($oneDay) ){
				$partList[] = $oneNme.'='.implode(',', $oneDay);
			}
		}
		// The last date is a day, or a time told in universal time
		$lastLine = $this->writeDateAsLne('UNTIL', strval($ruleSettings['until'] ?? ''), 'UTC');
		if( !empty($lastLine) ){
			$partList[] = 'UNTIL='.substr($lastLine[0], intval(strrpos($lastLine[0], ':'))+1);
		}
		if( intval($ruleSettings['times'] ?? 0)>0 ){
			$partList[] = 'COUNT='.intval($ruleSettings['times']);
		}
		return Array('RRULE:'.implode(';', $partList));
	}

	/**
	 * Writes an entry of a calendar: an event, a task or a note.
	 *
	 * @param Array  $calendarItem The entry, as the calendar parser gives it.
	 * @param String $itemTypesTxt Its kind: event, tasks or notes.
	 * @return Array The lines of the entry, without those that open and close it.
	 */
	protected function writeEntryList(Array $calendarItem, String $itemTypesTxt): Array {
		$isAllDay = !empty($calendarItem['whole']);
		$zoneName = strval($calendarItem['tzone'] ?? '');
		$startVal = strval($calendarItem['start'] ?? '');
		$lineList = Array('UID:'.$this->escapeIcalText((strval($calendarItem['ident'] ?? '')!=='') ? strval($calendarItem['ident']) : bin2hex(random_bytes(16)).'@nyuruns'));
		// The date an entry was written is told in universal time; the present one when the entry tells none
		$lineList = array_merge($lineList, $this->writeDateAsLne('DTSTAMP', (strlen(strval($calendarItem['stamp'] ?? ''))>10) ? strval($calendarItem['stamp']) : gmdate('Y-m-d H:i:s'), 'UTC'));
		$startLne = $this->writeDateAsLne('DTSTART', $startVal, $zoneName);
		$lineList = array_merge($lineList, $startLne);
		if( $itemTypesTxt=='calEvent' && !empty($startLne) ){
			// Whole days end, as the format writes it, on the day after their last one
			$endValue = (strval($calendarItem['ended'] ?? '')!=='') ? strval($calendarItem['ended']) : $startVal;
			$lineList = array_merge($lineList, $this->writeDateAsLne('DTEND', $endValue, $zoneName, $isAllDay ? 1 : 0));
		}
		if( $itemTypesTxt=='calTodos' ){
			$lineList = array_merge($lineList, $this->writeDateAsLne('DUE', strval($calendarItem['dueby'] ?? ''), $zoneName));
			if( intval($calendarItem['ratio'] ?? 0)>0 ){
				$lineList[] = 'PERCENT-COMPLETE:'.max(0, min(100, intval($calendarItem['ratio'])));
			}
		}
		foreach(Array('title'=>'SUMMARY', 'place'=>'LOCATION', 'descr'=>'DESCRIPTION') as $oneKey=>$oneNme){
			if( strval($calendarItem[$oneKey] ?? '')!=='' ){
				$lineList[] = $oneNme.':'.$this->escapeIcalText(strval($calendarItem[$oneKey]));
			}
		}
		$linkAddr = Shape::wipeUnsafeAddr(strval($calendarItem['links'] ?? ''));
		if( $linkAddr!=='' ){
			$lineList[] = 'URL:'.preg_replace('/[\x00-\x20\x7F]/', '', $linkAddr);
		}
		if( isset(self::STAT_LIST[strval($calendarItem['state'] ?? '')]) ){
			$lineList[] = 'STATUS:'.self::STAT_LIST[$calendarItem['state']];
		}
		$labelLst = array_filter(array_map('strval', (array)($calendarItem['label'] ?? Array())), 'strlen');
		if( !empty($labelLst) ){
			$lineList[] = 'CATEGORIES:'.implode(',', array_map(Array($this, 'escapeIcalText'), $labelLst));
		}
		$lineList = array_merge($lineList, $this->writePartyLine('ORGANIZER', (array)($calendarItem['owner'] ?? Array())));
		foreach((array)($calendarItem['guest'] ?? Array()) as $oneSet){
			$lineList = array_merge($lineList, $this->writePartyLine('ATTENDEE', (array)$oneSet));
		}
		// A rule kept as written says more than the one that was read: it is the one written back
		$extraLst = (array)($calendarItem['extra'] ?? Array());
		if( !isset(array_change_key_case($extraLst, CASE_UPPER)['RRULE']) ){
			$lineList = array_merge($lineList, $this->writeRuleAsLne((array)($calendarItem['rpeat'] ?? Array())));
		}
		foreach((array)($calendarItem['skips'] ?? Array()) as $oneVal){
			$lineList = array_merge($lineList, $this->writeDateAsLne('EXDATE', strval($oneVal), $zoneName));
		}
		foreach($extraLst as $oneNme=>$onePrp){
			// What was kept as written is written as it was, when its name is one and it is not a property written above
			if( !preg_match('/^[A-Za-z0-9\-]+$/D', strval($oneNme)) || in_array(strtoupper(strval($oneNme)), self::OWNS_LIST) ){
				continue;
			}
			foreach((array)$onePrp as $oneVal){
				if( is_scalar($oneVal) ){
					$lineList[] = strtoupper(strval($oneNme)).':'.preg_replace('/[\x00-\x1F\x7F]/', '', strval($oneVal));
				}
			}
		}
		foreach((array)($calendarItem['alarm'] ?? Array()) as $oneTme){
			// A reminder counts back from the start of the entry
			$oneTme = intval($oneTme);
			$lineList = array_merge($lineList, Array('BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->escapeIcalText((strval($calendarItem['title'] ?? '')!=='') ? strval($calendarItem['title']) : 'Reminder'), 'TRIGGER:'.(($oneTme>0) ? '-' : '').'PT'.abs($oneTme).'M', 'END:VALARM'));
		}
		return $lineList;
	}

}
