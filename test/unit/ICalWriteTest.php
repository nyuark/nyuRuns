<?php

// Data\Writers\ICal : the events, the tasks and the notes of a calendar
// written back as a calendar.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Parsers\ICal as ICalParser;
use nyu\Data\Writers\ICal;
use nyu\Data\Writers\Index;

/**
 * Writes entries as a calendar, or returns the reason they were refused.
 *
 * @param Array $itemsArray The entries: 'event', 'tasks', 'notes'.
 * @param Array $infosArray Details of the calendar.
 * @return String The calendar, or a 'THROWN ...' string.
 */
function calOut( Array $itemsArray, Array $infosArray=Array() ): String {
	return runCatchThrown(function() use ($itemsArray, $infosArray){
		return (new ICal())->renderDocument(Array('shape'=>'nodes', 'infos'=>$infosArray, 'items'=>$itemsArray));
	});
}

/**
 * Writes one event and returns its lines, without those of the calendar
 * around it nor those that open and close it.
 *
 * @param Array $entryArray The event.
 * @return Array The lines.
 */
function evntOut( Array $entryArray ): Array {
	$lineLst = explode("\r\n", calOut(Array('event'=>Array($entryArray + Array('ident'=>'e1', 'stamp'=>'2026-10-02 14:05:00')))));
	return array_values(array_diff(array_slice($lineLst, 4, -3), Array('UID:e1', 'DTSTAMP:20261002T140500Z')));
}

// The calendar
checkIdentical('a calendar without entry: what the format asks for, its lines ending as it asks', calOut(Array()), "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//nyuRuns//Calendar//EN\r\nEND:VCALENDAR\r\n");
checkIdentical('the details of the calendar: the program that wrote it, its name, its time zone', calOut(Array('event'=>Array(), 'tasks'=>Array(), 'notes'=>Array()), Array('maker'=>'-//nyuArk//Test//EN', 'title'=>'Les Enchaînés, saison', 'tzone'=>'Europe/Paris', 'count'=>0)), "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//nyuArk//Test//EN\r\nX-WR-CALNAME:Les Enchaînés\\, saison\r\nX-WR-TIMEZONE:Europe/Paris\r\nEND:VCALENDAR\r\n");

// An event
$fullEvt = Array(
	'ident'=>'20261228-alpha@nyuark.test', 'title'=>'Alpha Night, rehearsal', 'start'=>'2026-12-28 20:30:00', 'ended'=>'2026-12-28 22:00:00', 'whole'=>false, 'tzone'=>'Europe/Paris',
	'place'=>'Hall A', 'descr'=>"Bring the scripts.\nDoors at 8; no later.", 'links'=>'https://nyuark.test/shows/alpha', 'state'=>'cnfrm', 'label'=>Array('Shows', 'Rehearsal, dress'),
	'owner'=>Array('names'=>'J. J.', 'email'=>'jj@nyuark.test'), 'guest'=>Array(Array('names'=>'Martin, A.', 'email'=>'am@nyuark.test', 'reply'=>'accpt'), Array('names'=>'', 'email'=>'bo@nyuark.test', 'reply'=>'waits')),
	'rpeat'=>Array('every'=>'wekly', 'steps'=>2, 'wdays'=>Array('mo', 'th'), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'2027-03-01', 'times'=>0),
	'skips'=>Array('2027-01-04 20:30:00'), 'alarm'=>Array(30), 'stamp'=>'2026-10-02 14:05:00', 'extra'=>Array('X-MICROSOFT-CDO-BUSYSTATUS'=>'BUSY', 'SEQUENCE'=>'2')
);
checkIdentical('an event with all it can tell', explode("\r\n", calOut(Array('event'=>Array($fullEvt)))), Array(
	'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//nyuRuns//Calendar//EN', 'BEGIN:VEVENT',
	'UID:20261228-alpha@nyuark.test', 'DTSTAMP:20261002T140500Z', 'DTSTART;TZID=Europe/Paris:20261228T203000', 'DTEND;TZID=Europe/Paris:20261228T220000',
	'SUMMARY:Alpha Night\, rehearsal', 'LOCATION:Hall A', 'DESCRIPTION:Bring the scripts.\nDoors at 8\; no later.', 'URL:https://nyuark.test/shows/alpha', 'STATUS:CONFIRMED', 'CATEGORIES:Shows,Rehearsal\, dress',
	'ORGANIZER;CN=J. J.:mailto:jj@nyuark.test', 'ATTENDEE;CN="Martin, A.";PARTSTAT=ACCEPTED:mailto:am@nyuark.test', 'ATTENDEE:mailto:bo@nyuark.test',
	'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20270301', 'EXDATE;TZID=Europe/Paris:20270104T203000', 'X-MICROSOFT-CDO-BUSYSTATUS:BUSY', 'SEQUENCE:2',
	'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Alpha Night\, rehearsal', 'TRIGGER:-PT30M', 'END:VALARM',
	'END:VEVENT', 'END:VCALENDAR', ''
));
checkIdentical('an event telling nothing gets an id and the present time, which the format asks for', runCatchThrown(function(){
	$lineLst = explode("\r\n", calOut(Array('event'=>Array(Array()))));
	$nextLst = explode("\r\n", calOut(Array('event'=>Array(Array()))));
	return Array(count($lineLst), preg_match('/^UID:[0-9a-f]{32}@nyuruns$/D', $lineLst[4]), preg_match('/^DTSTAMP:'.gmdate('Ymd').'T\d{6}Z$/D', $lineLst[5]), $lineLst[6], $lineLst[4]!==$nextLst[4]);
}), Array(9, 1, 1, 'END:VEVENT', true));

// Dates
checkIdentical('times: in a zone, in universal time, without zone; a zone that is not a plain name is left out', Array(
	evntOut(Array('start'=>'2026-12-28 20:30:00', 'ended'=>'2026-12-28 22:00:00', 'tzone'=>'America/New_York')), evntOut(Array('start'=>'2026-12-28 19:30:00', 'ended'=>'2026-12-28 21:00:00', 'tzone'=>'UTC')),
	evntOut(Array('start'=>'2026-12-28 20:30', 'ended'=>'2026-12-28T22:00:00', 'tzone'=>'')), evntOut(Array('start'=>'2026-12-28 20:30:00', 'tzone'=>"Europe/Paris:x\r\nATTENDEE"))
), Array(
	Array('DTSTART;TZID=America/New_York:20261228T203000', 'DTEND;TZID=America/New_York:20261228T220000'), Array('DTSTART:20261228T193000Z', 'DTEND:20261228T210000Z'),
	Array('DTSTART:20261228T203000', 'DTEND:20261228T220000'), Array('DTSTART:20261228T203000', 'DTEND:20261228T203000')
));
checkIdentical('whole days are written with the day after their last one: one day, several, over the new year, and one without end', Array(
	evntOut(Array('start'=>'2026-12-28', 'ended'=>'2026-12-28', 'whole'=>true)), evntOut(Array('start'=>'2026-12-28', 'ended'=>'2026-12-30', 'whole'=>true)), evntOut(Array('start'=>'2026-12-15', 'ended'=>'2026-12-31', 'whole'=>true))[1], evntOut(Array('start'=>'2026-02-28', 'whole'=>true))
), Array(Array('DTSTART;VALUE=DATE:20261228', 'DTEND;VALUE=DATE:20261229'), Array('DTSTART;VALUE=DATE:20261228', 'DTEND;VALUE=DATE:20261231'), 'DTEND;VALUE=DATE:20270101', Array('DTSTART;VALUE=DATE:20260228', 'DTEND;VALUE=DATE:20260301')));
checkIdentical('a date that is none is left out, with what depends on it', Array(evntOut(Array('start'=>'tomorrow', 'ended'=>'2026-12-28')), evntOut(Array('start'=>'2026-02-30')), evntOut(Array('start'=>'2026-12-28 20:30:00', 'ended'=>'later', 'skips'=>Array('never', '2027-01-04')))), Array(Array(), Array(), Array('DTSTART:20261228T203000', 'EXDATE;VALUE=DATE:20270104')));

// Texts and people
checkIdentical('texts: what the format gives a meaning to stands after a backslash; nothing can start a line of its own', evntOut(Array('title'=>"Les Enchaînés, \\ soirée; d'été\r\nBEGIN:VEVENT", 'place'=>"Hall\x00 A\tB", 'descr'=>"one\rtwo\nthree")), Array('SUMMARY:Les Enchaînés\, \\\\ soirée\; d\'été\nBEGIN:VEVENT', 'LOCATION:Hall AB', 'DESCRIPTION:one\ntwo\nthree'));
checkIdentical('a link that would run a script is left out; states, and one that is not a known one', Array(evntOut(Array('links'=>'javascript:alert(1)')), evntOut(Array('links'=>"https://nyuark.test/a b\r\nX:y")), array_map(function($stateCode){ return evntOut(Array('state'=>$stateCode)); }, Array('tentv', 'cancl', 'waits', 'activ', 'compl', 'draft', 'final', 'odd', ''))), Array(Array(), Array('URL:https://nyuark.test/abX:y'), Array(Array('STATUS:TENTATIVE'), Array('STATUS:CANCELLED'), Array('STATUS:NEEDS-ACTION'), Array('STATUS:IN-PROCESS'), Array('STATUS:COMPLETED'), Array('STATUS:DRAFT'), Array('STATUS:FINAL'), Array(), Array())));
checkIdentical('people: a name is quoted when it has to be; a reply; one without address is left out; nothing of a person can start a property', evntOut(Array(
	'owner'=>Array('names'=>'Boss: "the" one', 'email'=>" boss@nyuark.test\r\nATTENDEE:mailto:evil@x"),
	'guest'=>Array(Array('names'=>'No', 'email'=>'a@nyuark.test', 'reply'=>'decln'), Array('email'=>'b@nyuark.test', 'reply'=>'tentv'), Array('email'=>'c@nyuark.test', 'reply'=>'deleg'), Array('email'=>'d@nyuark.test', 'reply'=>'odd'), Array('names'=>'Nobody', 'email'=>''), 'not a person')
)), Array('ORGANIZER;CN="Boss: the one":mailto:boss@nyuark.testATTENDEE:mailto:evil@x', 'ATTENDEE;CN=No;PARTSTAT=DECLINED:mailto:a@nyuark.test', 'ATTENDEE;PARTSTAT=TENTATIVE:mailto:b@nyuark.test', 'ATTENDEE;PARTSTAT=DELEGATED:mailto:c@nyuark.test', 'ATTENDEE:mailto:d@nyuark.test'));

// Repetition, reminders, what was kept as written
checkIdentical('rules: every day, every month on some days until a time, every year in some months a number of times; one that is none is left out', Array(
	evntOut(Array('rpeat'=>Array('every'=>'daily'))), evntOut(Array('rpeat'=>Array('every'=>'mthly', 'steps'=>1, 'mdays'=>Array(1, 15, -1), 'until'=>'2027-03-01 00:00:00'))),
	evntOut(Array('rpeat'=>Array('every'=>'yerly', 'wdays'=>Array('1mo', '-1FR', 'xx'), 'month'=>Array(1, 6), 'times'=>10))), evntOut(Array('rpeat'=>Array('every'=>'hourly'))), evntOut(Array('rpeat'=>Array()))
), Array(Array('RRULE:FREQ=DAILY'), Array('RRULE:FREQ=MONTHLY;BYMONTHDAY=1,15,-1;UNTIL=20270301T000000Z'), Array('RRULE:FREQ=YEARLY;BYDAY=1MO,-1FR;BYMONTH=1,6;COUNT=10'), Array(), Array()));
checkIdentical('a rule kept as written is the one written back, once', evntOut(Array('rpeat'=>Array('every'=>'mthly', 'wdays'=>Array('mo')), 'extra'=>Array('RRULE'=>'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1'))), Array('RRULE:FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1'));
checkIdentical('reminders: before the start, after it, at it', array_values(array_filter(evntOut(Array('alarm'=>Array(15, 1440, -10, 0))), function($lineString){ return strpos($lineString, 'TRIGGER')===0; })), Array('TRIGGER:-PT15M', 'TRIGGER:-PT1440M', 'TRIGGER:PT10M', 'TRIGGER:PT0M'));
checkIdentical('extra: what was kept as written is written as it was; a property given twice twice; what the writer writes itself, or what is not a name, is left out', evntOut(Array('extra'=>Array('CLASS'=>'PRIVATE', 'X-NOTE'=>Array('one\, as written', 'two'), 'geo'=>'45.76;4.83', 'SUMMARY'=>'Not twice', 'uid'=>'nor this', "X-BAD\r\nBEGIN"=>'x', 'X-LINES'=>"a\r\nBEGIN:VEVENT", 'X-DEEP'=>Array(Array('no'))))), Array('CLASS:PRIVATE', 'X-NOTE:one\, as written', 'X-NOTE:two', 'GEO:45.76;4.83', 'X-LINES:aBEGIN:VEVENT'));

// Long lines
checkIdentical('a line longer than the format allows is carried on over the next ones, a character never cut in two', runCatchThrown(function(){
	$longTxt = str_repeat('Les Enchaînés été ', 12);
	$lineLst = explode("\r\n", calOut(Array('event'=>Array(Array('ident'=>'e1', 'stamp'=>'2026-10-02 14:05:00', 'title'=>$longTxt)))));
	$foldLst = array_slice($lineLst, 6, -3);
	return Array(max(array_map('strlen', $lineLst))<=75, count($foldLst), array_unique(array_map(function($lineString){ return $lineString[0]; }, array_slice($foldLst, 1))), array_filter($foldLst, function($lineString){ return !preg_match('//u', $lineString); }), (new ICalParser())->parseDataArray(implode("\r\n", $lineLst))['items']['event'][0]['title']===trim($longTxt));
}), Array(true, 4, Array(' '), Array(), true));

// Tasks and notes
checkIdentical('a task and a note, each with the keys of its kind', array_slice(explode("\r\n", calOut(Array(
	'tasks'=>Array(Array('ident'=>'t1', 'stamp'=>'2026-10-02 14:05:00', 'title'=>'Print the programmes', 'start'=>'2026-12-20', 'whole'=>true, 'ended'=>'2026-12-21', 'dueby'=>'2026-12-27 18:00:00', 'tzone'=>'Europe/Paris', 'ratio'=>40, 'state'=>'activ')),
	'notes'=>Array(Array('ident'=>'j1', 'stamp'=>'2026-10-02 14:05:00', 'title'=>'Opening night', 'start'=>'2026-12-28', 'whole'=>true, 'descr'=>'Full house.', 'state'=>'final', 'label'=>Array('Diary'), 'extra'=>Array('X-MOOD'=>'good')))
))), 3, -2), Array(
	'BEGIN:VTODO', 'UID:t1', 'DTSTAMP:20261002T140500Z', 'DTSTART;VALUE=DATE:20261220', 'DUE;TZID=Europe/Paris:20261227T180000', 'PERCENT-COMPLETE:40', 'SUMMARY:Print the programmes', 'STATUS:IN-PROCESS', 'END:VTODO',
	'BEGIN:VJOURNAL', 'UID:j1', 'DTSTAMP:20261002T140500Z', 'DTSTART;VALUE=DATE:20261228', 'SUMMARY:Opening night', 'DESCRIPTION:Full house.', 'STATUS:FINAL', 'CATEGORIES:Diary', 'X-MOOD:good', 'END:VJOURNAL'
));
checkIdentical('the keys of the first level are read whatever their case, as a file read through Dcmnt has them', substr_count(calOut(Array('EVENT'=>Array(Array('title'=>'a')), 'Tasks'=>Array(Array('title'=>'b')), 'NOTES'=>Array())), 'SUMMARY:'), 2);

// Read back
$srceCal = implode("\r\n", Array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//nyuArk//Test//EN', 'X-WR-CALNAME:Les Enchaînés', 'X-WR-TIMEZONE:Europe/Paris',
	'BEGIN:VEVENT', 'UID:20261228-alpha@nyuark.test', 'DTSTAMP:20261002T140500Z', 'SUMMARY:Alpha Night\, rehearsal', 'DTSTART;TZID=Europe/Paris:20261228T203000', 'DTEND;TZID=Europe/Paris:20261228T220000', 'LOCATION:Hall A', 'DESCRIPTION:Bring the scripts.\nDoors at 8\; no later.', 'URL:https://nyuark.test/shows/alpha', 'STATUS:CONFIRMED', 'CATEGORIES:Shows,Rehearsal',
	'ORGANIZER;CN=J. J.:mailto:jj@nyuark.test', 'ATTENDEE;CN="Martin, A.";PARTSTAT=ACCEPTED:mailto:am@nyuark.test', 'ATTENDEE:mailto:bo@nyuark.test', 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;UNTIL=20270301', 'EXDATE;TZID=Europe/Paris:20270104T203000', 'X-MICROSOFT-CDO-BUSYSTATUS:BUSY', 'SEQUENCE:2', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT30M', 'END:VALARM', 'BEGIN:VALARM', 'TRIGGER:PT10M', 'END:VALARM', 'END:VEVENT',
	'BEGIN:VEVENT', 'UID:whole', 'DTSTAMP:20261002T140500Z', 'SUMMARY:Festival', 'DTSTART;VALUE=DATE:20261215', 'DTEND;VALUE=DATE:20270101', 'RRULE:FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1', 'EXDATE;VALUE=DATE:20270118', 'X-NOTE:one', 'X-NOTE:two', 'END:VEVENT',
	'BEGIN:VEVENT', 'UID:utc', 'DTSTAMP:20261002T140500Z', 'DTSTART:20261228T193000Z', 'DURATION:PT90M', 'STATUS:TENTATIVE', 'END:VEVENT',
	'BEGIN:VTODO', 'UID:t1', 'DTSTAMP:20261002T140500Z', 'SUMMARY:Print the programmes', 'DTSTART:20261220', 'DUE;TZID=Europe/Paris:20261227T180000', 'STATUS:IN-PROCESS', 'PERCENT-COMPLETE:40', 'PRIORITY:1', 'END:VTODO',
	'BEGIN:VJOURNAL', 'UID:j1', 'DTSTAMP:20261002T140500Z', 'SUMMARY:Opening night', 'DTSTART:20261228', 'DESCRIPTION:Full house.', 'STATUS:FINAL', 'CATEGORIES:Diary', 'END:VJOURNAL',
	'END:VCALENDAR', ''));
checkIdentical('a calendar read, written, then read again gives the same entries and the same details', runCatchThrown(function() use ($srceCal){
	$prsrObj = new ICalParser();
	$frstEnv = $prsrObj->parseDataArray($srceCal);
	$scndEnv = $prsrObj->parseDataArray((new ICal())->renderDocument($frstEnv));
	foreach( $frstEnv['items'] as $kndKey=>$entrLst ){
		foreach( $entrLst as $entIdx=>$entrSet ){
			if( ($scndEnv['items'][$kndKey][$entIdx] ?? null)!==$entrSet ){
				return Array($kndKey.' '.$entIdx, $entrSet, $scndEnv['items'][$kndKey][$entIdx] ?? null);
			}
		}
	}
	return Array($frstEnv['infos']===$scndEnv['infos'], $frstEnv['infos']['count']);
}), Array(true, 5));
checkIdentical('what is written once is written the same again', runCatchThrown(function() use ($srceCal){
	$prsrObj = new ICalParser();
	$wrtrObj = new ICal();
	$frstTxt = $wrtrObj->renderDocument($prsrObj->parseDataArray($srceCal));
	return $wrtrObj->renderDocument($prsrObj->parseDataArray($frstTxt))===$frstTxt;
}), true);
checkIdentical('a calendar read in another time zone is written in that zone', runCatchThrown(function() use ($srceCal){
	$outText = (new ICal())->renderDocument((new ICalParser())->parseDataArray($srceCal, Array('tzone'=>'UTC')));
	return Array(strpos($outText, 'DTSTART:20261228T193000Z')!==false, strpos($outText, 'TZID=Europe/Paris:20261228'), strpos($outText, 'DTSTART;VALUE=DATE:20261215')!==false);
}), Array(true, false, true));

// What is refused
checkPregMatch('a content that is not values', runCatchThrown(function(){ return (new ICal())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as a calendar: block/');
checkPregMatch('values that are not the entries of a calendar', calOut(Array('name'=>'Alpha', 'seats'=>120)), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Content that cannot be written as a calendar: it holds something else than events, tasks and notes/');

// Through the index
checkIdentical('index: calendars by extension, with their content type', Array(get_class(Index::forExtnsParser('ICS')), Index::contentTypeFor('ics')), Array('nyu\Data\Writers\ICal', 'text/calendar; charset=utf-8'));
checkIdentical('no PHP warning along the way', takeNotesArray(), Array());

finishChecking();
