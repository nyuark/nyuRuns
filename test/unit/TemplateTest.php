<?php

// Data\Mail\Template : a mail made of its templates, the subject on the
// first line of the text, placeholders filled, [name] parts kept or dropped.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Mail\Template;

$tplDir = inTempDir('mailtpl');
if( !is_dir($tplDir) ){
	mkdir($tplDir, 0777, true);
}
file_put_contents($tplDir.DS.'code.txt', "\r\nYour code, %user%\r\nHello %user%,\r\n\r\nThe code: %code%\r\n[link]Or open %link%\r\n[/link]Bye %none%\r\n");
file_put_contents($tplDir.DS.'code.html', "<p>Hello %user%,</p>\n[link]<a href=\"%link%\">link</a>\n[/link]<p>%code%</p>\n");

$mailTpl = new Template( $tplDir.DS.'code.txt', $tplDir.DS.'code.html' );
$valList = Array('user'=>'<Ann>', 'code'=>'AB-12', 'link'=>'https://x.ch/?a=1&b=2');
checkSame('the subject: the first line, filled, as it is', $mailTpl->getSubjct($valList), 'Your code, <Ann>');
checkSame('the text: filled as it is, the line breaks made plain, a placeholder without value left', $mailTpl->plainBody($valList), "Hello <Ann>,\n\nThe code: AB-12\nOr open https://x.ch/?a=1&b=2\nBye %none%\n");
checkSame('the HTML: filled and encoded', $mailTpl->markupTxt($valList), "<p>Hello &lt;Ann&gt;,</p>\n<a href=\"https://x.ch/?a=1&amp;b=2\">link</a>\n<p>AB-12</p>\n");
$valList['link'] = '';
checkSame('an empty value drops its part, with its line break', Array($mailTpl->plainBody($valList), $mailTpl->markupTxt($valList)), Array("Hello <Ann>,\n\nThe code: AB-12\nBye %none%\n", "<p>Hello &lt;Ann&gt;,</p>\n<p>AB-12</p>\n"));
checkSame('a subject on one line, whatever its values hold', $mailTpl->getSubjct(Array('user'=>"A\r\nBcc: x@y.ch")), 'Your code, A  Bcc: x@y.ch');
checkSame('no HTML file, no HTML', (new Template($tplDir.DS.'code.txt'))->markupTxt($valList), null);
checkThrw('a file that cannot be read', function() use ( $tplDir ){ new Template($tplDir.DS.'none.txt'); }, \RuntimeException::class);
checkThrw('an HTML file that cannot be read', function() use ( $tplDir ){ new Template($tplDir.DS.'code.txt', $tplDir.DS.'none.html'); }, \RuntimeException::class);

// The placeholders alone, as the pages fill them
checkSame('fillPlace: encoded or not; a value that is not a text or a number is left', Array(Template::fillPlace('%a% %b% %c%', Array('a'=>'<i>', 'b'=>7, 'c'=>Array(1)), true), Template::fillPlace('%a%', Array('a'=>'<i>'), false)), Array('&lt;i&gt; 7 %c%', '<i>'));

endChecks();
