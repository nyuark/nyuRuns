<?php

// Data\Mail\Template : a mail made of its templates, the subject on the
// first line of the text, placeholders filled, [name] parts kept or dropped.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Mail\Template;

$tplDir = inTemporaryDir('mailtpl');
if( !is_dir($tplDir) ){
	mkdir($tplDir, 0777, true);
}
file_put_contents($tplDir.DS.'code.txt', "\r\nYour code, %user%\r\nHello %user%,\r\n\r\nThe code: %code%\r\n[link]Or open %link%\r\n[/link]Bye %none%\r\n");
file_put_contents($tplDir.DS.'code.html', "<p>Hello %user%,</p>\n[link]<a href=\"%link%\">link</a>\n[/link]<p>%code%</p>\n");

$mailTpl = new Template( $tplDir.DS.'code.txt', $tplDir.DS.'code.html' );
$valList = Array('user'=>'<Ann>', 'code'=>'AB-12', 'link'=>'https://x.ch/?a=1&b=2');
checkIdentical('the subject: the first line, filled, as it is', $mailTpl->getSubjectText($valList), 'Your code, <Ann>');
checkIdentical('the text: filled as it is, the line breaks made plain, a placeholder without value left', $mailTpl->simpleBodyText($valList), "Hello <Ann>,\n\nThe code: AB-12\nOr open https://x.ch/?a=1&b=2\nBye %none%\n");
checkIdentical('the HTML: filled and encoded', $mailTpl->getHtmlVersion($valList), "<p>Hello &lt;Ann&gt;,</p>\n<a href=\"https://x.ch/?a=1&amp;b=2\">link</a>\n<p>AB-12</p>\n");
$valList['link'] = '';
checkIdentical('an empty value drops its part, with its line break', Array($mailTpl->simpleBodyText($valList), $mailTpl->getHtmlVersion($valList)), Array("Hello <Ann>,\n\nThe code: AB-12\nBye %none%\n", "<p>Hello &lt;Ann&gt;,</p>\n<p>AB-12</p>\n"));
checkIdentical('a subject on one line, whatever its values hold', $mailTpl->getSubjectText(Array('user'=>"A\r\nBcc: x@y.ch")), 'Your code, A  Bcc: x@y.ch');
checkIdentical('no HTML file, no HTML', (new Template($tplDir.DS.'code.txt'))->getHtmlVersion($valList), null);
checkForThrows('a file that cannot be read', function() use ( $tplDir ){ new Template($tplDir.DS.'none.txt'); }, \RuntimeException::class);
checkForThrows('an HTML file that cannot be read', function() use ( $tplDir ){ new Template($tplDir.DS.'code.txt', $tplDir.DS.'none.html'); }, \RuntimeException::class);

// The placeholders alone, as the pages fill them
checkIdentical('fillPlace: encoded or not; a value that is not a text or a number is left', Array(Template::fillNamedSlots('%a% %b% %c%', Array('a'=>'<i>', 'b'=>7, 'c'=>Array(1)), true), Template::fillNamedSlots('%a%', Array('a'=>'<i>'), false)), Array('&lt;i&gt; 7 %c%', '<i>'));

finishChecking();
