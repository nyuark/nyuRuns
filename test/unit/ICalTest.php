<?php

// Data\Parsers\ICal : calendars read into their events, tasks and notes.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Parsers\ICal;
use nyu\Data\Parsers\Index;

/**
 * Wraps the lines of some entries into a calendar.
 *
 * @param Array $linesArray The lines of the entries.
 * @param Array $headsArray The lines of the calendar itself.
 * @return String The calendar.
 */
function makeCal( Array $linesArray, Array $headsArray=Array('VERSION:2.0', 'PRODID:-//nyuArk//Test//EN') ): String {
	return implode("\r\n", array_merge(Array('BEGIN:VCALENDAR'), $headsArray, $linesArray, Array('END:VCALENDAR')))."\r\n";
}

/**
 * Wraps the lines of an event.
 *
 * @param Array $linesArray Its lines.
 * @return Array The lines of the event.
 */
function evnt( Array $linesArray ): Array {
	return array_merge(Array('BEGIN:VEVENT'), $linesArray, Array('END:VEVENT'));
}

/**
 * Parses a calendar and returns its envelope, or the reason it was refused.
 *
 * @param String $rawContent The calendar.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function calRead( String $rawContent, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($rawContent, $parseOptns){
		$prsrObj = new ICal();
		return $prsrObj->parseDataArray($rawContent, $parseOptns);
	});
}

/**
 * Parses a calendar holding one event and returns that event.
 *
 * @param Array $linesArray The lines of the event.
 * @param Array $parseOptns Options of the parser.
 * @return mixed The event, or a 'THROWN ...' string.
 */
function oneEvnt( Array $linesArray, Array $parseOptns=Array() ): Mixed {
	$envlope = calRead(makeCal(evnt($linesArray)), $parseOptns);
	return is_array($envlope) ? $envlope['items']['calEvent'][0] : $envlope;
}

$voidEvt = Array('ident'=>'', 'title'=>'', 'start'=>'', 'ended'=>'', 'whole'=>false, 'tzone'=>'', 'place'=>'', 'descr'=>'', 'links'=>'', 'state'=>'', 'label'=>Array(), 'owner'=>Array('names'=>'', 'email'=>''), 'guest'=>Array(), 'rpeat'=>Array(), 'skips'=>Array(), 'alarm'=>Array(), 'stamp'=>'', 'extra'=>Array());

// A whole event
$fullEvt = evnt(Array(
	'UID:20261228-alpha@nyuark.test', 'DTSTAMP:20261002T140500Z', 'SUMMARY:Alpha Night\, rehearsal', 'DTSTART;TZID=Europe/Paris:20261228T203000', 'DTEND;TZID=Europe/Paris:20261228T220000',
	'LOCATION:Hall A', 'DESCRIPTION:Bring the scripts.\nDoors at 8\; no later.', 'URL:https://nyuark.test/shows/alpha', 'STATUS:CONFIRMED', 'CATEGORIES:Shows,Rehearsal',
	'ORGANIZER;CN=J. J.:mailto:jj@nyuark.test', 'ATTENDEE;CN="Martin, A.";PARTSTAT=ACCEPTED;ROLE=REQ-PARTICIPANT:MAILTO:am@nyuark.test', 'ATTENDEE:mailto:bo@nyuark.test',
	'RRULE:FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,TH;UNTIL=20270301', 'EXDATE;TZID=Europe/Paris:20270104T203000', 'X-MICROSOFT-CDO-BUSYSTATUS:BUSY', 'SEQUENCE:2',
	'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT30M', 'DESCRIPTION:Reminder', 'END:VALARM'
));
checkIdentical('an event with all it can tell, and the envelope', calRead(makeCal($fullEvt, Array('VERSION:2.0', 'PRODID:-//nyuArk//Test//EN', 'X-WR-CALNAME:Les Enchaînés', 'X-WR-TIMEZONE:Europe/Paris'))), Array(
	'shape'=>'nodes',
	'infos'=>Array('title'=>'Les Enchaînés', 'tzone'=>'Europe/Paris', 'maker'=>'-//nyuArk//Test//EN', 'count'=>1),
	'items'=>Array('calEvent'=>Array(Array(
		'ident'=>'20261228-alpha@nyuark.test', 'title'=>'Alpha Night, rehearsal',
		'start'=>'2026-12-28 20:30:00', 'ended'=>'2026-12-28 22:00:00', 'whole'=>false, 'tzone'=>'Europe/Paris',
		'place'=>'Hall A', 'descr'=>"Bring the scripts.\nDoors at 8; no later.", 'links'=>'https://nyuark.test/shows/alpha',
		'state'=>'definite', 'label'=>Array('Shows', 'Rehearsal'),
		'owner'=>Array('names'=>'J. J.', 'email'=>'jj@nyuark.test'),
		'guest'=>Array(Array('names'=>'Martin, A.', 'email'=>'am@nyuark.test', 'reply'=>'accepted'), Array('names'=>'', 'email'=>'bo@nyuark.test', 'reply'=>'awaiting')),
		'rpeat'=>Array('every'=>'eachWeek', 'steps'=>1, 'wdays'=>Array('mo', 'th'), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'2027-03-01', 'times'=>0),
		'skips'=>Array('2027-01-04 20:30:00'), 'alarm'=>Array(30), 'stamp'=>'2026-10-02 14:05:00',
		'extra'=>Array('X-MICROSOFT-CDO-BUSYSTATUS'=>'BUSY', 'SEQUENCE'=>'2')
	)), 'calTodos'=>Array(), 'calNotes'=>Array())
));
checkIdentical('an event telling nothing has every key, empty', oneEvnt(Array()), $voidEvt);
checkIdentical('an empty content, and a calendar without entry', Array(calRead(''), calRead(" \r\n\r\n")['items'], calRead(makeCal(Array()))), Array(
	Array('shape'=>'nodes', 'infos'=>Array('title'=>'', 'tzone'=>'', 'maker'=>'', 'count'=>0), 'items'=>Array('calEvent'=>Array(), 'calTodos'=>Array(), 'calNotes'=>Array())),
	Array('calEvent'=>Array(), 'calTodos'=>Array(), 'calNotes'=>Array()),
	Array('shape'=>'nodes', 'infos'=>Array('title'=>'', 'tzone'=>'', 'maker'=>'-//nyuArk//Test//EN', 'count'=>0), 'items'=>Array('calEvent'=>Array(), 'calTodos'=>Array(), 'calNotes'=>Array()))
));

// Dates
checkIdentical('times: in a zone, in UTC, without zone, without seconds', Array(
	array_intersect_key(oneEvnt(Array('DTSTART;TZID=America/New_York:20261228T203000', 'DTEND;TZID=America/New_York:20261228T220000')), array_flip(Array('start', 'ended', 'whole', 'tzone'))),
	array_intersect_key(oneEvnt(Array('DTSTART:20261228T193000Z', 'DTEND:20261228T210000Z')), array_flip(Array('start', 'ended', 'whole', 'tzone'))),
	array_intersect_key(oneEvnt(Array('DTSTART:20261228T203000', 'DTEND:20261228T2200')), array_flip(Array('start', 'ended', 'whole', 'tzone'))),
), Array(
	Array('start'=>'2026-12-28 20:30:00', 'ended'=>'2026-12-28 22:00:00', 'whole'=>false, 'tzone'=>'America/New_York'),
	Array('start'=>'2026-12-28 19:30:00', 'ended'=>'2026-12-28 21:00:00', 'whole'=>false, 'tzone'=>'UTC'),
	Array('start'=>'2026-12-28 20:30:00', 'ended'=>'2026-12-28 22:00:00', 'whole'=>false, 'tzone'=>'')
));
checkIdentical('whole days end on their last day: one day, several, a month over the new year, and one written without its end', Array(
	array_intersect_key(oneEvnt(Array('DTSTART;VALUE=DATE:20261228', 'DTEND;VALUE=DATE:20261229')), array_flip(Array('start', 'ended', 'whole', 'tzone'))),
	oneEvnt(Array('DTSTART;VALUE=DATE:20261228', 'DTEND;VALUE=DATE:20261231'))['ended'],
	oneEvnt(Array('DTSTART:20261215', 'DTEND:20270101'))['ended'],
	oneEvnt(Array('DTSTART:20261228'))['ended'],
	oneEvnt(Array('DTSTART:20261228', 'DTEND:20261228'))['ended']
), Array(Array('start'=>'2026-12-28', 'ended'=>'2026-12-28', 'whole'=>true, 'tzone'=>''), '2026-12-30', '2026-12-31', '2026-12-28', '2026-12-28'));
checkIdentical('an event written with its length has its end worked out: minutes, hours over midnight, whole days, weeks', Array(
	oneEvnt(Array('DTSTART:20261228T203000Z', 'DURATION:PT90M'))['ended'], oneEvnt(Array('DTSTART;TZID=Europe/Paris:20261228T203000', 'DURATION:PT5H45M30S'))['ended'],
	oneEvnt(Array('DTSTART:20261228T203000', 'DURATION:P1DT2H'))['ended'], oneEvnt(Array('DTSTART:20261228', 'DURATION:P3D'))['ended'], oneEvnt(Array('DTSTART:20261228', 'DURATION:P1W'))['ended'], oneEvnt(Array('DTSTART:20261228', 'DURATION:P1D'))['ended']
), Array('2026-12-28 22:00:00', '2026-12-29 02:15:30', '2026-12-29 22:30:00', '2026-12-30', '2027-01-03', '2026-12-28'));
checkIdentical('a timed event without end nor length ends when it starts; a date that is none is left empty', Array(
	oneEvnt(Array('DTSTART:20261228T203000Z'))['ended'], oneEvnt(Array('DTSTART:20261328T203000Z', 'DTEND:tomorrow'))['start'], oneEvnt(Array('DTSTART:20260230'))['start'], oneEvnt(Array('DTSTART:20261228T203000Z', 'DURATION:soon'))['ended']
), Array('2026-12-28 20:30:00', '', '', '2026-12-28 20:30:00'));

// The time zone asked for
checkIdentical('tzone: times are given in the zone asked for, in winter and in summer, the day changing when it has to', Array(
	array_intersect_key(oneEvnt(Array('DTSTART:20261228T193000Z', 'DTEND:20261228T233000Z', 'DTSTAMP:20261002T140500Z', 'EXDATE:20270104T193000Z'), Array('tzone'=>'Europe/Paris')), array_flip(Array('start', 'ended', 'tzone', 'stamp', 'skips'))),
	oneEvnt(Array('DTSTART:20260628T193000Z'), Array('tzone'=>'Europe/Paris'))['start'],
	array_intersect_key(oneEvnt(Array('DTSTART;TZID=America/New_York:20261228T203000', 'DURATION:PT1H'), Array('tzone'=>'UTC')), array_flip(Array('start', 'ended', 'tzone')))
), Array(
	Array('start'=>'2026-12-28 20:30:00', 'ended'=>'2026-12-29 00:30:00', 'tzone'=>'Europe/Paris', 'skips'=>Array('2027-01-04 20:30:00'), 'stamp'=>'2026-10-02 16:05:00'),
	'2026-06-28 21:30:00',
	Array('start'=>'2026-12-29 01:30:00', 'ended'=>'2026-12-29 02:30:00', 'tzone'=>'UTC')
));
checkIdentical('tzone: whole days, times without zone, and times in a zone PHP does not know are left as written', Array(
	array_intersect_key(oneEvnt(Array('DTSTART:20261228', 'DTEND:20261230'), Array('tzone'=>'Asia/Tokyo')), array_flip(Array('start', 'ended', 'tzone'))),
	array_intersect_key(oneEvnt(Array('DTSTART:20261228T203000'), Array('tzone'=>'Asia/Tokyo')), array_flip(Array('start', 'tzone'))),
	array_intersect_key(oneEvnt(Array('DTSTART;TZID=Romance Standard Time:20261228T203000'), Array('tzone'=>'Asia/Tokyo')), array_flip(Array('start', 'tzone')))
), Array(Array('start'=>'2026-12-28', 'ended'=>'2026-12-29', 'tzone'=>''), Array('start'=>'2026-12-28 20:30:00', 'tzone'=>''), Array('start'=>'2026-12-28 20:30:00', 'tzone'=>'Romance Standard Time')));
checkPregMatch('tzone: a zone that is not a known one is refused', calRead(makeCal(Array()), Array('tzone'=>'Mars/Olympus')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar option: unknown time zone Mars\/Olympus/');

// Texts
checkIdentical('texts: what is written after a backslash, accents, a line written over several, a colon and a semicolon in a value', array_intersect_key(oneEvnt(Array(
	'SUMMARY:Les Enchaînés\, \\\\ soirée d\'été', 'DESCRIPTION:A long text that the format writes over',"  several lines\\, each of the following",'  ones starting with a space.\NNew line: with a colon; and a semicolon', 'LOCATION;LANGUAGE=fr;ALTREP="cid:part1:x":Théâtre', 'CATEGORIES:One\, still one,Two', 'CATEGORIES:Three, ,Four'
)), array_flip(Array('title', 'descr', 'place', 'label'))), Array('title'=>'Les Enchaînés, \\ soirée d\'été', 'place'=>'Théâtre', 'descr'=>"A long text that the format writes over several lines, each of the following ones starting with a space.\nNew line: with a colon; and a semicolon", 'label'=>Array('One, still one', 'Two', 'Three', 'Four')));
checkIdentical('a link that would run a script is left out', Array(oneEvnt(Array('URL:javascript:alert(1)'))['links'], oneEvnt(Array('URL;VALUE=URI:https://nyuark.test/a?b=1'))['links']), Array('', 'https://nyuark.test/a?b=1'));
checkIdentical('names are read whatever their case', array_intersect_key(oneEvnt(Array('summary:lower', 'dtstart;tzid=Europe/Paris:20261228T203000', 'status:tentative')), array_flip(Array('title', 'start', 'tzone', 'state'))), Array('title'=>'lower', 'start'=>'2026-12-28 20:30:00', 'tzone'=>'Europe/Paris', 'state'=>'possible'));

// States, people, reminders
checkIdentical('states, and one that is not a known one', array_map(function($statusText){ return oneEvnt(Array('STATUS:'.$statusText))['state']; }, Array('CONFIRMED', 'TENTATIVE', 'cancelled', 'NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'DRAFT', 'FINAL', 'UNKNOWN')), Array('definite', 'possible', 'canceled', 'awaiting', 'underway', 'finished', 'drafting', 'finalVer', ''));
checkIdentical('guests and their replies', oneEvnt(Array('ORGANIZER:MAILTO:Boss@nyuark.test', 'ATTENDEE;PARTSTAT=DECLINED;CN=No:mailto:a@nyuark.test', 'ATTENDEE;PARTSTAT=TENTATIVE:mailto:b@nyuark.test', 'ATTENDEE;PARTSTAT=DELEGATED:mailto:c@nyuark.test', 'ATTENDEE;PARTSTAT=needs-action:d@nyuark.test', 'ATTENDEE;PARTSTAT=ODD:mailto:e@nyuark.test'))['owner']+Array('guest'=>array_column(oneEvnt(Array('ATTENDEE;PARTSTAT=DECLINED;CN=No:mailto:a@nyuark.test', 'ATTENDEE;PARTSTAT=TENTATIVE:mailto:b@nyuark.test', 'ATTENDEE;PARTSTAT=DELEGATED:mailto:c@nyuark.test', 'ATTENDEE;PARTSTAT=needs-action:d@nyuark.test', 'ATTENDEE;PARTSTAT=ODD:mailto:e@nyuark.test'))['guest'], 'reply', 'email')), Array('names'=>'', 'email'=>'Boss@nyuark.test', 'guest'=>Array('a@nyuark.test'=>'declined', 'b@nyuark.test'=>'possible', 'c@nyuark.test'=>'delegate', 'd@nyuark.test'=>'awaiting', 'e@nyuark.test'=>'awaiting')));
checkIdentical('reminders: minutes before the start, several, one after it, one at the start, one set at a date of its own left out', oneEvnt(Array(
	'BEGIN:VALARM', 'TRIGGER:-PT15M', 'END:VALARM', 'BEGIN:VALARM', 'TRIGGER;RELATED=START:-P1D', 'END:VALARM', 'BEGIN:VALARM', 'TRIGGER:PT10M', 'END:VALARM', 'BEGIN:VALARM', 'TRIGGER:PT0S', 'END:VALARM', 'BEGIN:VALARM', 'TRIGGER;VALUE=DATE-TIME:20261228T190000Z', 'END:VALARM'
))['alarm'], Array(15, 1440, -10, 0));

// Repetition
$ruleOf = function( String $ruleText ){ $evntSet = oneEvnt(Array('RRULE:'.$ruleText)); return Array($evntSet['rpeat'], $evntSet['extra']); };
checkIdentical('rules: every day, every other week on some days, every month on some days, every year in some months', Array($ruleOf('FREQ=DAILY')[0], $ruleOf('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,we,FR;COUNT=10')[0], $ruleOf('FREQ=MONTHLY;BYMONTHDAY=1,15,-1;UNTIL=20270301T000000Z')[0], $ruleOf('FREQ=YEARLY;BYMONTH=1,6;BYDAY=1MO,-1FR')[0]), Array(
	Array('every'=>'eachDays', 'steps'=>1, 'wdays'=>Array(), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'', 'times'=>0),
	Array('every'=>'eachWeek', 'steps'=>2, 'wdays'=>Array('mo', 'we', 'fr'), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'', 'times'=>10),
	Array('every'=>'eachMnth', 'steps'=>1, 'wdays'=>Array(), 'mdays'=>Array(1, 15, -1), 'month'=>Array(), 'until'=>'2027-03-01 00:00:00', 'times'=>0),
	Array('every'=>'eachYear', 'steps'=>1, 'wdays'=>Array('1mo', '-1fr'), 'mdays'=>Array(), 'month'=>Array(1, 6), 'until'=>'', 'times'=>0)
));
checkIdentical('a rule fully read is not kept twice; one saying more than is read is kept as written beside what was read; one that is not read is only kept as written', Array($ruleOf('FREQ=DAILY;COUNT=3')[1], $ruleOf('FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1;WKST=SU'), $ruleOf('FREQ=HOURLY;INTERVAL=4')), Array(
	Array(),
	Array(Array('every'=>'eachMnth', 'steps'=>1, 'wdays'=>Array('mo'), 'mdays'=>Array(), 'month'=>Array(), 'until'=>'', 'times'=>0), Array('RRULE'=>'FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1;WKST=SU')),
	Array(Array(), Array('RRULE'=>'FREQ=HOURLY;INTERVAL=4'))
));
checkIdentical('the dates left out of a repetition: several on a line, several lines, whole days', Array(oneEvnt(Array('EXDATE:20270104T203000Z,20270111T203000Z', 'EXDATE;VALUE=DATE:20270118', 'EXDATE:nonsense'))['skips']), Array(Array('2027-01-04 20:30:00', '2027-01-11 20:30:00', '2027-01-18')));

// What has no key of its own
checkIdentical('extra: what is not read into a key is kept as written, a property given twice with both its values', oneEvnt(Array('CLASS:PRIVATE', 'TRANSP:OPAQUE', 'PRIORITY:5', 'CREATED:20260101T000000Z', 'LAST-MODIFIED:20260102T000000Z', 'RECURRENCE-ID:20270104T203000Z', 'X-NOTE:one\, as written', 'X-NOTE:two', 'GEO:45.76;4.83'))['extra'], Array('CLASS'=>'PRIVATE', 'TRANSP'=>'OPAQUE', 'PRIORITY'=>'5', 'CREATED'=>'20260101T000000Z', 'LAST-MODIFIED'=>'20260102T000000Z', 'RECURRENCE-ID'=>'20270104T203000Z', 'X-NOTE'=>Array('one\, as written', 'two'), 'GEO'=>'45.76;4.83'));

// Tasks and notes
$mixdCal = makeCal(array_merge(
	evnt(Array('UID:e1', 'SUMMARY:First')),
	Array('BEGIN:VTODO', 'UID:t1', 'SUMMARY:Print the programmes', 'DTSTART:20261220', 'DUE;TZID=Europe/Paris:20261227T180000', 'STATUS:IN-PROCESS', 'PERCENT-COMPLETE:40', 'PRIORITY:1', 'BEGIN:VALARM', 'TRIGGER:-PT1H', 'END:VALARM', 'END:VTODO'),
	Array('BEGIN:VTODO', 'UID:t2', 'DUE:20261228', 'PERCENT-COMPLETE:250', 'END:VTODO'),
	Array('BEGIN:VJOURNAL', 'UID:j1', 'SUMMARY:Opening night', 'DTSTART:20261228', 'DESCRIPTION:Full house.', 'STATUS:FINAL', 'CATEGORIES:Diary', 'LOCATION:not kept for a note', 'X-MOOD:good', 'END:VJOURNAL'),
	Array('BEGIN:VFREEBUSY', 'FREEBUSY:20261228T190000Z/PT1H', 'END:VFREEBUSY'),
	Array('BEGIN:VTIMEZONE', 'TZID:Europe/Paris', 'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'END:STANDARD', 'END:VTIMEZONE'),
	evnt(Array('UID:e2', 'SUMMARY:Second'))
));
checkIdentical('events, tasks and notes are told apart; busy times and the zones a calendar defines are not read', runCatchThrown(function() use ($mixdCal){
	$envlope = calRead($mixdCal);
	return Array($envlope['infos']['count'], array_column($envlope['items']['calEvent'], 'ident'), array_column($envlope['items']['calTodos'], 'ident'), array_column($envlope['items']['calNotes'], 'ident'));
}), Array(5, Array('e1', 'e2'), Array('t1', 't2'), Array('j1')));
checkIdentical('a task: the keys of an event, with when it is due and how far it is', calRead($mixdCal)['items']['calTodos'][0], Array(
	'ident'=>'t1', 'title'=>'Print the programmes', 'start'=>'2026-12-20', 'ended'=>'', 'whole'=>true, 'tzone'=>'', 'place'=>'', 'descr'=>'', 'links'=>'', 'state'=>'underway', 'label'=>Array(),
	'owner'=>Array('names'=>'', 'email'=>''), 'guest'=>Array(), 'rpeat'=>Array(), 'skips'=>Array(), 'alarm'=>Array(60), 'stamp'=>'', 'dueby'=>'2026-12-27 18:00:00', 'ratio'=>40, 'extra'=>Array('PRIORITY'=>'1')
));
checkIdentical('a task due on a day, and done more than it can be', array_intersect_key(calRead($mixdCal)['items']['calTodos'][1], array_flip(Array('dueby', 'ratio', 'start'))), Array('start'=>'', 'dueby'=>'2026-12-28', 'ratio'=>100));
checkIdentical('a note: what applies to it', calRead($mixdCal)['items']['calNotes'][0], Array('ident'=>'j1', 'title'=>'Opening night', 'start'=>'2026-12-28', 'whole'=>true, 'tzone'=>'', 'descr'=>'Full house.', 'state'=>'finalVer', 'label'=>Array('Diary'), 'stamp'=>'', 'extra'=>Array('X-MOOD'=>'good')));
checkIdentical('upper: the keys of the first level', array_keys(calRead($mixdCal, Array('upper'=>true))['items']), Array('CALEVENT', 'CALTODOS', 'CALNOTES'));
checkIdentical('several calendars in one content are read as one', runCatchThrown(function(){
	$envlope = calRead(makeCal(evnt(Array('UID:a')), Array('PRODID:first', 'X-WR-CALNAME:One')).makeCal(evnt(Array('UID:b')), Array('PRODID:second')));
	return Array($envlope['infos'], array_column($envlope['items']['calEvent'], 'ident'));
}), Array(Array('title'=>'One', 'tzone'=>'', 'maker'=>'first', 'count'=>2), Array('a', 'b')));

// Line ends and encodings
checkIdentical('any line end, a marker at the start, a calendar saved by an older tool, empty lines', Array(
	calRead(str_replace("\r\n", "\n", makeCal(evnt(Array('SUMMARY:Unix', 'DESCRIPTION:folded', ' here')))))['items']['calEvent'][0]['descr'],
	calRead("\xEF\xBB\xBF".makeCal(evnt(Array('SUMMARY:Été'))))['items']['calEvent'][0]['title'],
	calRead(makeCal(evnt(Array("SUMMARY:\xC9t\xE9 \x80"))))['items']['calEvent'][0]['title'],
	calRead("\r\n\r\n".str_replace("\r\n", "\r\n\r\n", makeCal(evnt(Array('SUMMARY:Spaced')))))['items']['calEvent'][0]['title']
), Array('foldedhere', 'Été', 'Été €', 'Spaced'));

// What is refused
checkPregMatch('a content that is not a calendar', calRead("Title,Seats\nAlpha,120"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: a line that is not a property on line 1/');
checkPregMatch('properties without a calendar around them', calRead("SUMMARY:Lost\r\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: the content does not start a calendar on line 1/');
checkPregMatch('an entry without a calendar around it', calRead("BEGIN:VEVENT\r\nEND:VEVENT\r\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: the content does not start a calendar on line 1/');
checkPregMatch('an entry never closed, with the line it was opened on', calRead("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:Open\r\nEND:VCALENDAR\r\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: VCALENDAR closed where VEVENT is open on line 5/');
checkPregMatch('a calendar cut short', calRead("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Cut"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: VEVENT is never closed, opened on line 2/');
checkPregMatch('something closed that was not opened', calRead("BEGIN:VCALENDAR\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: VEVENT closed where VCALENDAR is open on line 2/');
checkPregMatch('a line that is not a property, with its line counted past the folded ones', calRead(makeCal(evnt(Array('DESCRIPTION:folded', ' once', ' twice', 'no colon here')))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: a line that is not a property on line 8/');
checkPregMatch('entries nested deeper than allowed', calRead(str_repeat("BEGIN:VCALENDAR\r\n", 9)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar content: nested deeper than allowed on line 9/');
checkIdentical('no PHP warning along the way', takeNotesArray(), Array());

// Through the index and Dcmnt
checkIdentical('index: calendars by extension and by content type', Array(get_class(Index::forExtnsParser('ICS')), get_class(Index::findTypeParser('text/calendar; charset=utf-8'))), Array('nyu\Data\Parsers\ICal', 'nyu\Data\Parsers\ICal'));
checkIdentical('a calendar through Dcmnt, with and without its extension: the keys of the first level in upper case, as for the other files of values', runCatchThrown(function() use ($mixdCal){
	$docPath = inTemporaryDir('shows.ics');
	$barePth = inTemporaryDir('calendar');
	file_put_contents($docPath, $mixdCal);
	file_put_contents($barePth, $mixdCal);
	$docFile = new Dcmnt($docPath);
	$bareDoc = new Dcmnt($barePth);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf')['count'], array_keys($docFile->readFileDetail()), $docFile->readFileDetail()['CALEVENT'][1]['title'], $bareDoc->readFileDetail('ext'), count($bareDoc->readFileDetail()['CALTODOS']));
}), Array('ics', 'nodes', 5, Array('CALEVENT', 'CALTODOS', 'CALNOTES'), 'Second', 'ics', 2));
checkPregMatch('an invalid calendar file is refused, and named', runCatchThrown(function(){
	$docPath = inTemporaryDir('broken.ics');
	file_put_contents($docPath, "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n");
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: broken\.ics: Invalid calendar content: VEVENT is never closed/');

finishChecking();
