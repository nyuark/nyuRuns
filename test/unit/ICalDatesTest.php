<?php

// Data\Parsers\ICal::listEntryDates : the dates a calendar entry falls on.
//
// The calendar the checks stand on: 1 October 2026 is a Thursday; the
// Fridays of October 2026 are 2, 9, 16, 23, 30; the last Sundays of October
// are 25 (2026), 31 (2027) and 29 (2028); in Paris the clocks go back on
// 25 October 2026.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Parsers\ICal;

/**
 * Reads an event from its lines, and lists the dates it falls on.
 *
 * @param Array  $linesArray The lines of the event.
 * @param String $startsFrom First date of the window.
 * @param String $untilDate  Last date of the window.
 * @param Int    $countLimit Greatest number of dates.
 * @param String $entryKinds The kind of entry: VEVENT or VTODO.
 * @return mixed The dates, or a 'THROWN ...' string.
 */
function calDates( Array $linesArray, String $startsFrom, String $untilDate, Int $countLimit=500, String $entryKinds='VEVENT' ): Mixed {
	return runCatchThrown(function() use ($linesArray, $startsFrom, $untilDate, $countLimit, $entryKinds){
		$calText = implode("\r\n", array_merge(Array('BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:'.$entryKinds, 'UID:one'), $linesArray, Array('END:'.$entryKinds, 'END:VCALENDAR')))."\r\n";
		$readSet = (new ICal())->parseDataArray($calText)['items'];
		return ICal::listEntryDates($readSet[($entryKinds=='VEVENT') ? 'calEvent' : 'calTodos'][0], $startsFrom, $untilDate, $countLimit);
	});
}

/**
 * Gives only where each date starts.
 *
 * @param mixed $datesArray What calDates() gave.
 * @return mixed The starts, or what was given when it is not a list.
 */
function calStart( Mixed $datesArray ): Mixed {
	return is_array($datesArray) ? array_column($datesArray, 'start') : $datesArray;
}

// An entry that does not repeat
checkIdentical('an entry that does not repeat, in the window', calDates(Array('DTSTART:20261005T090000', 'DTEND:20261005T103000'), '2026-10-01', '2026-10-31'), Array(Array('start'=>'2026-10-05 09:00:00', 'ended'=>'2026-10-05 10:30:00')));
checkIdentical('out of the window', Array(calDates(Array('DTSTART:20261005T090000'), '2026-10-06', '2026-10-31'), calDates(Array('DTSTART:20261005T090000'), '2026-09-01', '2026-10-04')), Array(Array(), Array()));
checkIdentical('the last day of the window is taken whole, a time is taken as told', Array(count(calDates(Array('DTSTART:20261005T090000'), '2026-10-01', '2026-10-05')), count(calDates(Array('DTSTART:20261005T090000'), '2026-10-01', '2026-10-05 08:00:00'))), Array(1, 0));

// Every day
checkIdentical('every day, five times', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=DAILY;COUNT=5'), '2026-01-01', '2027-01-01')), Array('2026-10-01 09:00:00', '2026-10-02 09:00:00', '2026-10-03 09:00:00', '2026-10-04 09:00:00', '2026-10-05 09:00:00'));
checkIdentical('every other day, until a day taken whole', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=DAILY;INTERVAL=2;UNTIL=20261007'), '2026-01-01', '2027-01-01')), Array('2026-10-01 09:00:00', '2026-10-03 09:00:00', '2026-10-05 09:00:00', '2026-10-07 09:00:00'));
checkIdentical('the times are counted from the start, not from the window', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=DAILY;COUNT=3'), '2026-10-03', '2026-10-31')), Array('2026-10-03 09:00:00'));
checkIdentical('every day of some days of the week', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=DAILY;BYDAY=SA,SU;COUNT=4'), '2026-10-01', '2026-10-31')), Array('2026-10-01 09:00:00', '2026-10-03 09:00:00', '2026-10-04 09:00:00', '2026-10-10 09:00:00'));
checkIdentical('a rule followed for many years reaches a far window', calStart(calDates(Array('DTSTART:20000101T120000', 'RRULE:FREQ=DAILY'), '2026-10-10', '2026-10-12')), Array('2026-10-10 12:00:00', '2026-10-11 12:00:00', '2026-10-12 12:00:00'));
checkIdentical('no more dates than asked', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=DAILY'), '2026-10-01', '2030-01-01', 3)), Array('2026-10-01 09:00:00', '2026-10-02 09:00:00', '2026-10-03 09:00:00'));

// Every week
checkIdentical('every week, on the day of the start', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=WEEKLY'), '2026-10-01', '2026-10-20')), Array('2026-10-01 09:00:00', '2026-10-08 09:00:00', '2026-10-15 09:00:00'));
checkIdentical('every week, on two days', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=WEEKLY;BYDAY=MO,TH'), '2026-10-01', '2026-10-13')), Array('2026-10-01 09:00:00', '2026-10-05 09:00:00', '2026-10-08 09:00:00', '2026-10-12 09:00:00'));
checkIdentical('every other week, weeks starting on Monday', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH'), '2026-10-01', '2026-10-31')), Array('2026-10-01 09:00:00', '2026-10-12 09:00:00', '2026-10-15 09:00:00', '2026-10-26 09:00:00', '2026-10-29 09:00:00'));
checkIdentical('every other week, weeks starting on Thursday', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;WKST=TH'), '2026-10-01', '2026-10-31')), Array('2026-10-01 09:00:00', '2026-10-05 09:00:00', '2026-10-15 09:00:00', '2026-10-19 09:00:00', '2026-10-29 09:00:00'));
checkIdentical('the start is the first date, whatever the rule says', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=WEEKLY;BYDAY=MO'), '2026-10-01', '2026-10-13')), Array('2026-10-01 09:00:00', '2026-10-05 09:00:00', '2026-10-12 09:00:00'));

// Every month
checkIdentical('the last Friday of the month', calStart(calDates(Array('DTSTART:20261030T180000', 'RRULE:FREQ=MONTHLY;BYDAY=-1FR'), '2026-10-01', '2026-12-31')), Array('2026-10-30 18:00:00', '2026-11-27 18:00:00', '2026-12-25 18:00:00'));
checkIdentical('the second Tuesday of the month', calStart(calDates(Array('DTSTART:20261013T180000', 'RRULE:FREQ=MONTHLY;BYDAY=2TU'), '2026-10-01', '2026-12-31')), Array('2026-10-13 18:00:00', '2026-11-10 18:00:00', '2026-12-08 18:00:00'));
checkIdentical('every Friday of every other month', calStart(calDates(Array('DTSTART:20261002T180000', 'RRULE:FREQ=MONTHLY;INTERVAL=2;BYDAY=FR;COUNT=7'), '2026-10-01', '2027-12-31')), Array('2026-10-02 18:00:00', '2026-10-09 18:00:00', '2026-10-16 18:00:00', '2026-10-23 18:00:00', '2026-10-30 18:00:00', '2026-12-04 18:00:00', '2026-12-11 18:00:00'));
checkIdentical('the last day of the month', calStart(calDates(Array('DTSTART:20261031T180000', 'RRULE:FREQ=MONTHLY;BYMONTHDAY=-1'), '2026-10-01', '2027-02-28')), Array('2026-10-31 18:00:00', '2026-11-30 18:00:00', '2026-12-31 18:00:00', '2027-01-31 18:00:00', '2027-02-28 18:00:00'));
checkIdentical('the 31st: the months that have none are passed over', calStart(calDates(Array('DTSTART:20261031T180000', 'RRULE:FREQ=MONTHLY'), '2026-10-01', '2027-03-31')), Array('2026-10-31 18:00:00', '2026-12-31 18:00:00', '2027-01-31 18:00:00', '2027-03-31 18:00:00'));
checkIdentical('the 1st and the 15th', calStart(calDates(Array('DTSTART:20261001T180000', 'RRULE:FREQ=MONTHLY;BYMONTHDAY=1,15;COUNT=4'), '2026-10-01', '2027-03-31')), Array('2026-10-01 18:00:00', '2026-10-15 18:00:00', '2026-11-01 18:00:00', '2026-11-15 18:00:00'));
checkIdentical('a Friday the 13th', calStart(calDates(Array('DTSTART:20261113T180000', 'RRULE:FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13'), '2026-10-01', '2027-12-31')), Array('2026-11-13 18:00:00', '2027-08-13 18:00:00'));
checkIdentical('every month of some months', calStart(calDates(Array('DTSTART:20261005T180000', 'RRULE:FREQ=MONTHLY;BYMONTH=10,12'), '2026-10-01', '2027-11-30')), Array('2026-10-05 18:00:00', '2026-12-05 18:00:00', '2027-10-05 18:00:00'));

// Every year
checkIdentical('the last Sunday of October, a whole day', calDates(Array('DTSTART;VALUE=DATE:20261025', 'DTEND;VALUE=DATE:20261026', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU'), '2026-01-01', '2028-12-31'), Array(Array('start'=>'2026-10-25', 'ended'=>'2026-10-25'), Array('start'=>'2027-10-31', 'ended'=>'2027-10-31'), Array('start'=>'2028-10-29', 'ended'=>'2028-10-29')));
checkIdentical('every year, on the date of the start', calStart(calDates(Array('DTSTART:20261224T190000', 'RRULE:FREQ=YEARLY'), '2026-01-01', '2028-12-31')), Array('2026-12-24 19:00:00', '2027-12-24 19:00:00', '2028-12-24 19:00:00'));
checkIdentical('a 29 February: the years that have none are passed over', calStart(calDates(Array('DTSTART;VALUE=DATE:20280229', 'RRULE:FREQ=YEARLY'), '2028-01-01', '2036-12-31')), Array('2028-02-29', '2032-02-29', '2036-02-29'));
checkIdentical('the 20th Monday of the year', calStart(calDates(Array('DTSTART;VALUE=DATE:20260518', 'RRULE:FREQ=YEARLY;BYDAY=20MO'), '2026-01-01', '2027-12-31')), Array('2026-05-18', '2027-05-17'));
checkIdentical('the first day of two months, every other year', calStart(calDates(Array('DTSTART;VALUE=DATE:20260301', 'RRULE:FREQ=YEARLY;INTERVAL=2;BYMONTH=3,9;BYMONTHDAY=1'), '2026-01-01', '2028-12-31')), Array('2026-03-01', '2026-09-01', '2028-03-01', '2028-09-01'));
checkIdentical('the first Monday of September', calStart(calDates(Array('DTSTART;VALUE=DATE:20260907', 'RRULE:FREQ=YEARLY;BYMONTH=9;BYDAY=1MO'), '2026-01-01', '2028-12-31')), Array('2026-09-07', '2027-09-06', '2028-09-04'));

// What is skipped, and how long an entry lasts
checkIdentical('the dates skipped', calStart(calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=DAILY;COUNT=5', 'EXDATE:20261002T090000,20261004T090000'), '2026-10-01', '2026-10-31')), Array('2026-10-01 09:00:00', '2026-10-03 09:00:00', '2026-10-05 09:00:00'));
checkIdentical('a day skipped whole', calStart(calDates(Array('DTSTART;VALUE=DATE:20261001', 'RRULE:FREQ=DAILY;COUNT=3', 'EXDATE;VALUE=DATE:20261002'), '2026-10-01', '2026-10-31')), Array('2026-10-01', '2026-10-03'));
checkIdentical('three whole days, every week: the last day is included', calDates(Array('DTSTART;VALUE=DATE:20261005', 'DTEND;VALUE=DATE:20261008', 'RRULE:FREQ=WEEKLY;COUNT=2'), '2026-10-01', '2026-10-31'), Array(Array('start'=>'2026-10-05', 'ended'=>'2026-10-07'), Array('start'=>'2026-10-12', 'ended'=>'2026-10-14')));
checkIdentical('a date started before the window and ending in it is given', calStart(calDates(Array('DTSTART;VALUE=DATE:20261005', 'DTEND;VALUE=DATE:20261008', 'RRULE:FREQ=WEEKLY'), '2026-10-07', '2026-10-13')), Array('2026-10-05', '2026-10-12'));
checkIdentical('an entry lasting over midnight', calDates(Array('DTSTART:20261002T220000', 'DURATION:PT4H', 'RRULE:FREQ=WEEKLY;COUNT=2'), '2026-10-01', '2026-10-31'), Array(Array('start'=>'2026-10-02 22:00:00', 'ended'=>'2026-10-03 02:00:00'), Array('start'=>'2026-10-09 22:00:00', 'ended'=>'2026-10-10 02:00:00')));
checkIdentical('a task without an end', calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=WEEKLY;COUNT=2'), '2026-10-01', '2026-10-31', 500, 'VTODO'), Array(Array('start'=>'2026-10-01 09:00:00', 'ended'=>''), Array('start'=>'2026-10-08 09:00:00', 'ended'=>'')));

// Time zones
checkIdentical('the clock is kept when it changes', calDates(Array('DTSTART;TZID=Europe/Paris:20261024T200000', 'DTEND;TZID=Europe/Paris:20261024T213000', 'RRULE:FREQ=DAILY;COUNT=3'), '2026-10-01', '2026-10-31'), Array(Array('start'=>'2026-10-24 20:00:00', 'ended'=>'2026-10-24 21:30:00'), Array('start'=>'2026-10-25 20:00:00', 'ended'=>'2026-10-25 21:30:00'), Array('start'=>'2026-10-26 20:00:00', 'ended'=>'2026-10-26 21:30:00')));
checkIdentical('a last time is one of the world clock: 18:00 there is 20:00 in Paris', Array(count(calDates(Array('DTSTART;TZID=Europe/Paris:20261001T200000', 'RRULE:FREQ=DAILY;UNTIL=20261003T180000Z'), '2026-10-01', '2026-10-31')), count(calDates(Array('DTSTART;TZID=Europe/Paris:20261001T200000', 'RRULE:FREQ=DAILY;UNTIL=20261003T175959Z'), '2026-10-01', '2026-10-31'))), Array(3, 2));

// What is refused
checkPregMatch('a place among the dates of a rule is not covered', calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1'), '2026-10-01', '2026-12-31'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Calendar rule not covered: BYSETPOS=-1/');
checkPregMatch('a week of the year is not covered', calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO'), '2026-10-01', '2027-12-31'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Calendar rule not covered: BYWEEKNO=20/');
checkPregMatch('every hour is not covered', calDates(Array('DTSTART:20261001T090000', 'RRULE:FREQ=HOURLY;COUNT=3'), '2026-10-01', '2026-12-31'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Calendar rule not covered: FREQ=HOURLY/');
checkPregMatch('a window that is not a date', calDates(Array('DTSTART:20261001T090000'), 'not a date', '2026-12-31'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar date/');
checkPregMatch('an entry without a start', runCatchThrown(function(){ return ICal::listEntryDates(Array('title'=>'No start', 'start'=>'', 'ended'=>'', 'whole'=>false, 'tzone'=>'', 'rpeat'=>Array(), 'skips'=>Array()), '2026-10-01', '2026-10-31'); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid calendar entry: it has no start/');
checkPregMatch('a rule that is none of those known', runCatchThrown(function(){ return ICal::listEntryDates(Array('start'=>'2026-10-01', 'whole'=>true, 'rpeat'=>Array('every'=>'hourly')), '2026-10-01', '2026-10-31'); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Calendar rule not covered: hourly/');
checkIdentical('a rule that never falls on a date gives the start alone, and ends', calStart(calDates(Array('DTSTART;VALUE=DATE:20261001', 'RRULE:FREQ=MONTHLY;BYMONTH=2;BYMONTHDAY=30'), '2026-10-01', '2099-12-31')), Array('2026-10-01'));
checkIdentical('an entry written by hand, with the keys it needs', ICal::listEntryDates(Array('start'=>'2026-10-30 18:00:00', 'ended'=>'2026-10-30 19:00:00', 'whole'=>false, 'tzone'=>'Europe/Paris', 'rpeat'=>Array('every'=>'eachMnth', 'wdays'=>Array('-1fr'))), '2026-11-01', '2026-11-30'), Array(Array('start'=>'2026-11-27 18:00:00', 'ended'=>'2026-11-27 19:00:00')));

finishChecking();
