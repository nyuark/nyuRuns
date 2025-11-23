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
	const KINDLIST = Array('event'=>'VEVENT', 'tasks'=>'VTODO', 'notes'=>'VJOURNAL');
	// The states of an entry, the replies of a guest, and how often an entry repeats
	const STATLIST = Array('cnfrm'=>'CONFIRMED', 'tentv'=>'TENTATIVE', 'cancl'=>'CANCELLED', 'waits'=>'NEEDS-ACTION', 'activ'=>'IN-PROCESS', 'compl'=>'COMPLETED', 'draft'=>'DRAFT', 'final'=>'FINAL');
	const REPLLIST = Array('accpt'=>'ACCEPTED', 'decln'=>'DECLINED', 'tentv'=>'TENTATIVE', 'deleg'=>'DELEGATED');
	const FREQLIST = Array('daily'=>'DAILY', 'wekly'=>'WEEKLY', 'mthly'=>'MONTHLY', 'yerly'=>'YEARLY');
	// The properties the writer writes itself: what is kept under 'extra' never writes them a second time
	const OWNSLIST = Array('BEGIN', 'END', 'UID', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL', 'STATUS', 'CATEGORIES', 'ORGANIZER', 'ATTENDEE', 'EXDATE', 'DTSTART', 'DTEND', 'DURATION', 'DTSTAMP', 'DUE', 'PERCENT-COMPLETE');

	/**
	 * Writes events, tasks and notes as an iCalendar text.
	 *
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items', the items holding the events under 'event', the tasks under 'tasks' and the notes under 'notes'.
	 * @param Array $writeOptns Options: none.
	 * @return String The calendar, its lines ending and folded as the format asks.
	 *
	 * @throws WriteError When the shape is not 'nodes', or the content is not the entries of a calendar.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('nodes'), 'a calendar');
		$itemLst = array_change_key_case((array)($shapedData['items'] ?? Array()), CASE_LOWER);
		if( !empty(array_diff_key($itemLst, self::KINDLIST)) ){
			throw new WriteError('Content that cannot be written as a calendar: it holds something else than events, tasks and notes');
		}
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$lineLst = Array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'.$this->textValue((strval($infoArr['maker'] ?? '')!=='') ? strval($infoArr['maker']) : '-//nyuRuns//Calendar//EN'));
		foreach(Array('title'=>'X-WR-CALNAME', 'tzone'=>'X-WR-TIMEZONE') as $infKey=>$prpNme){
			if( strval($infoArr[$infKey] ?? '')!=='' ){
				$lineLst[] = $prpNme.':'.$this->textValue(strval($infoArr[$infKey]));
			}
		}
		foreach(self::KINDLIST as $kndKey=>$kndNme){
			foreach((array)($itemLst[$kndKey] ?? Array()) as $entSet){
				$lineLst = array_merge($lineLst, Array('BEGIN:'.$kndNme), $this->entrysOut((array)$entSet, $kndKey), Array('END:'.$kndNme));
			}
		}
		$lineLst[] = 'END:VCALENDAR';
		// A line holds 75 bytes at most: a longer one is carried on over the next ones, each starting with a space
		$calText = '';
		foreach($lineLst as $lneTxt){
			while( strlen($lneTxt)>75 ){
				$cutPosn = 75;
				// A character written over several bytes is never cut
				while( $cutPosn>1 && (ord($lneTxt[$cutPosn]) & 0xC0)==0x80 ){
					$cutPosn--;
				}
				$calText .= substr($lneTxt, 0, $cutPosn)."\r\n";
				$lneTxt = ' '.substr($lneTxt, $cutPosn);
			}
			$calText .= $lneTxt."\r\n";
		}
		return $calText;
	}

	/**
	 * Writes a text as the format asks: a backslash before what it gives
	 * a meaning to, a line end as \n, and nothing that could start a line
	 * of its own.
	 *
	 * @param String $textString The text.
	 * @return String The text, safe to write.
	 */
	protected function textValue(String $textString): String {
		$safeTxt = str_replace(Array("\r\n", "\r"), "\n", $textString);
		return strtr(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $safeTxt), Array('\\'=>'\\\\', ';'=>'\\;', ','=>'\\,', "\n"=>'\\n'));
	}

	/**
	 * Writes a date as the property holding it, with its time zone.
	 *
	 * @param String $propsNames Name of the property.
	 * @param String $isoDateStr The date, as ISO text, with or without a time.
	 * @param String $zoneOfDate The time zone it is written in: 'UTC', a name, or '' for none.
	 * @param Int    $daysToMove Days to add to a date without time.
	 * @return Array The line of the property, in a list; an empty list when the date is not one.
	 */
	protected function datesLine(String $propsNames, String $isoDateStr, String $zoneOfDate='', Int $daysToMove=0): Array {
		$datePrt = Array();
		if( !preg_match('/^(\d{4})-(\d\d)-(\d\d)(?:[ T](\d\d):(\d\d)(?::(\d\d))?)?$/D', $isoDateStr, $datePrt) || !checkdate(intval($datePrt[2]), intval($datePrt[3]), intval($datePrt[1])) ){
			return Array();
		}
		if( !isset($datePrt[4]) ){
			$dateObj = new \DateTimeImmutable($datePrt[1].'-'.$datePrt[2].'-'.$datePrt[3], new \DateTimeZone('UTC'));
			return Array($propsNames.';VALUE=DATE:'.$dateObj->modify(sprintf('%+d days', $daysToMove))->format('Ymd'));
		}
		$timeTxt = $datePrt[1].$datePrt[2].$datePrt[3].'T'.$datePrt[4].$datePrt[5].strval($datePrt[6] ?? '00');
		if( strtoupper($zoneOfDate)=='UTC' ){
			return Array($propsNames.':'.$timeTxt.'Z');
		}
		// A zone is written when it is a plain name; a time without one is the time of wherever it is read
		return Array($propsNames.(preg_match('/^[\w\/+\-][\w\/+\- ]*$/D', $zoneOfDate) ? ';TZID='.$zoneOfDate : '').':'.$timeTxt);
	}

	/**
	 * Writes who organises an entry, or who is invited to it.
	 *
	 * @param String $propsNames Name of the property: ORGANIZER or ATTENDEE.
	 * @param Array  $partyArray The person: 'names', 'email' and, for a guest, 'reply'.
	 * @return Array The line of the property, in a list; an empty list when the person has no address.
	 */
	protected function partyLine(String $propsNames, Array $partyArray): Array {
		$mailAdr = preg_replace('/[\s\x00-\x1F\x7F;,]/', '', strval($partyArray['email'] ?? ''));
		if( $mailAdr==='' ){
			return Array();
		}
		// A name stands between quotes when it holds what would end it
		$nameTxt = trim(preg_replace('/["\x00-\x1F\x7F]/', '', strval($partyArray['names'] ?? '')));
		$parmTxt = ($nameTxt==='') ? '' : ';CN='.(preg_match('/[:;,]/', $nameTxt) ? '"'.$nameTxt.'"' : $nameTxt);
		$parmTxt .= isset(self::REPLLIST[strval($partyArray['reply'] ?? '')]) ? ';PARTSTAT='.self::REPLLIST[$partyArray['reply']] : '';
		return Array($propsNames.$parmTxt.':mailto:'.$mailAdr);
	}

	/**
	 * Writes the rule of a repeating entry.
	 *
	 * @param Array $rulesArray The rule: 'every', 'steps', 'wdays', 'mdays', 'month', 'until', 'times'.
	 * @return Array The line of the rule, in a list; an empty list when the entry does not repeat.
	 */
	protected function rulesLine(Array $rulesArray): Array {
		if( !isset(self::FREQLIST[strval($rulesArray['every'] ?? '')]) ){
			return Array();
		}
		$partLst = Array('FREQ='.self::FREQLIST[$rulesArray['every']]);
		if( intval($rulesArray['steps'] ?? 1)>1 ){
			$partLst[] = 'INTERVAL='.intval($rulesArray['steps']);
		}
		$daysLst = array_filter(array_map('strtoupper', array_map('strval', (array)($rulesArray['wdays'] ?? Array()))), function($weekDayStr){ return preg_match('/^[+\-]?\d{0,2}(MO|TU|WE|TH|FR|SA|SU)$/D', $weekDayStr)===1; });
		foreach(Array('BYDAY'=>$daysLst, 'BYMONTHDAY'=>array_map('intval', (array)($rulesArray['mdays'] ?? Array())), 'BYMONTH'=>array_map('intval', (array)($rulesArray['month'] ?? Array()))) as $prtNme=>$prtVal){
			if( !empty($prtVal) ){
				$partLst[] = $prtNme.'='.implode(',', $prtVal);
			}
		}
		// The last date is a day, or a time told in universal time
		$lastLne = $this->datesLine('UNTIL', strval($rulesArray['until'] ?? ''), 'UTC');
		if( !empty($lastLne) ){
			$partLst[] = 'UNTIL='.substr($lastLne[0], intval(strrpos($lastLne[0], ':'))+1);
		}
		if( intval($rulesArray['times'] ?? 0)>0 ){
			$partLst[] = 'COUNT='.intval($rulesArray['times']);
		}
		return Array('RRULE:'.implode(';', $partLst));
	}

	/**
	 * Writes an entry of a calendar: an event, a task or a note.
	 *
	 * @param Array  $entryArray The entry, as the calendar parser gives it.
	 * @param String $entryKinds Its kind: event, tasks or notes.
	 * @return Array The lines of the entry, without those that open and close it.
	 */
	protected function entrysOut(Array $entryArray, String $entryKinds): Array {
		$isWhole = !empty($entryArray['whole']);
		$zoneNme = strval($entryArray['tzone'] ?? '');
		$strtVal = strval($entryArray['start'] ?? '');
		$lineLst = Array('UID:'.$this->textValue((strval($entryArray['ident'] ?? '')!=='') ? strval($entryArray['ident']) : bin2hex(random_bytes(16)).'@nyuruns'));
		// The date an entry was written is told in universal time; the present one when the entry tells none
		$lineLst = array_merge($lineLst, $this->datesLine('DTSTAMP', (strlen(strval($entryArray['stamp'] ?? ''))>10) ? strval($entryArray['stamp']) : gmdate('Y-m-d H:i:s'), 'UTC'));
		$strtLne = $this->datesLine('DTSTART', $strtVal, $zoneNme);
		$lineLst = array_merge($lineLst, $strtLne);
		if( $entryKinds=='event' && !empty($strtLne) ){
			// Whole days end, as the format writes it, on the day after their last one
			$endValu = (strval($entryArray['ended'] ?? '')!=='') ? strval($entryArray['ended']) : $strtVal;
			$lineLst = array_merge($lineLst, $this->datesLine('DTEND', $endValu, $zoneNme, $isWhole ? 1 : 0));
		}
		if( $entryKinds=='tasks' ){
			$lineLst = array_merge($lineLst, $this->datesLine('DUE', strval($entryArray['dueby'] ?? ''), $zoneNme));
			if( intval($entryArray['ratio'] ?? 0)>0 ){
				$lineLst[] = 'PERCENT-COMPLETE:'.max(0, min(100, intval($entryArray['ratio'])));
			}
		}
		foreach(Array('title'=>'SUMMARY', 'place'=>'LOCATION', 'descr'=>'DESCRIPTION') as $entKey=>$prpNme){
			if( strval($entryArray[$entKey] ?? '')!=='' ){
				$lineLst[] = $prpNme.':'.$this->textValue(strval($entryArray[$entKey]));
			}
		}
		$linkAdr = Shape::cleanLink(strval($entryArray['links'] ?? ''));
		if( $linkAdr!=='' ){
			$lineLst[] = 'URL:'.preg_replace('/[\x00-\x20\x7F]/', '', $linkAdr);
		}
		if( isset(self::STATLIST[strval($entryArray['state'] ?? '')]) ){
			$lineLst[] = 'STATUS:'.self::STATLIST[$entryArray['state']];
		}
		$lablLst = array_filter(array_map('strval', (array)($entryArray['label'] ?? Array())), 'strlen');
		if( !empty($lablLst) ){
			$lineLst[] = 'CATEGORIES:'.implode(',', array_map(Array($this, 'textValue'), $lablLst));
		}
		$lineLst = array_merge($lineLst, $this->partyLine('ORGANIZER', (array)($entryArray['owner'] ?? Array())));
		foreach((array)($entryArray['guest'] ?? Array()) as $gstSet){
			$lineLst = array_merge($lineLst, $this->partyLine('ATTENDEE', (array)$gstSet));
		}
		// A rule kept as written says more than the one that was read: it is the one written back
		$xtraLst = (array)($entryArray['extra'] ?? Array());
		if( !isset(array_change_key_case($xtraLst, CASE_UPPER)['RRULE']) ){
			$lineLst = array_merge($lineLst, $this->rulesLine((array)($entryArray['rpeat'] ?? Array())));
		}
		foreach((array)($entryArray['skips'] ?? Array()) as $skpVal){
			$lineLst = array_merge($lineLst, $this->datesLine('EXDATE', strval($skpVal), $zoneNme));
		}
		foreach($xtraLst as $prpNme=>$prpVal){
			// What was kept as written is written as it was, when its name is one and it is not a property written above
			if( !preg_match('/^[A-Za-z0-9\-]+$/D', strval($prpNme)) || in_array(strtoupper(strval($prpNme)), self::OWNSLIST) ){
				continue;
			}
			foreach((array)$prpVal as $oneVal){
				if( is_scalar($oneVal) ){
					$lineLst[] = strtoupper(strval($prpNme)).':'.preg_replace('/[\x00-\x1F\x7F]/', '', strval($oneVal));
				}
			}
		}
		foreach((array)($entryArray['alarm'] ?? Array()) as $almMin){
			// A reminder counts back from the start of the entry
			$almMin = intval($almMin);
			$lineLst = array_merge($lineLst, Array('BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->textValue((strval($entryArray['title'] ?? '')!=='') ? strval($entryArray['title']) : 'Reminder'), 'TRIGGER:'.(($almMin>0) ? '-' : '').'PT'.abs($almMin).'M', 'END:VALARM'));
		}
		return $lineLst;
	}

}
