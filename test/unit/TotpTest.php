<?php

// Access\Totp : one-time codes, checked against the SHA-1 test values of
// RFC 6238 (secret "12345678901234567890", here in base32).

require __DIR__.'/../lib/harness.php';

use nyu\Access\Totp;

$rfcScrt = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
foreach( Array(59=>'287082', 1111111109=>'081804', 1111111111=>'050471', 1234567890=>'005924', 2000000000=>'279037', 20000000000=>'353130') as $rfcTme=>$rfcCod ){
	checkSame('RFC 6238 value at '.$rfcTme, Totp::codeAtTme($rfcScrt, $rfcTme), $rfcCod);
}
checkSame('secret in lower case, with spaces and padding', Totp::codeAtTme('gezd gnbv gy3t qojq gezd gnbv gy3t qojq====', 59), '287082');

// Checking : the current step, the previous and the next one
checkSame('code of the current step',     Totp::checkCode($rfcScrt, '287082', 59), true);
checkSame('code of the previous step',    Totp::checkCode($rfcScrt, '287082', 59+30), true);
checkSame('code of the next step',        Totp::checkCode($rfcScrt, '287082', 59-30), true);
checkSame('code two steps old',           Totp::checkCode($rfcScrt, '287082', 59+60), false);
checkSame('code two steps ahead',         Totp::checkCode($rfcScrt, '081804', 1111111109-60), false);
checkSame('wrong code',                   Totp::checkCode($rfcScrt, '000000', 59), false);
checkSame('code that is too short',       Totp::checkCode($rfcScrt, '87082', 59), false);
checkSame('code with a letter',           Totp::checkCode($rfcScrt, '28708a', 59), false);
checkSame('empty code',                   Totp::checkCode($rfcScrt, '', 59), false);
checkSame('secret that is not base32',    Totp::checkCode('not base32 !', '287082', 59), false);
checkSame('empty secret',                 Totp::checkCode('', Totp::codeAtTme('', 59), 59), false);

// The step a code is the one of : time 59 is in step 1
checkSame('matchStep: the current step',  Totp::matchStep($rfcScrt, '287082', 59), 1);
checkSame('matchStep: the previous step', Totp::matchStep($rfcScrt, '287082', 59+30), 1);
checkSame('matchStep: the next step',     Totp::matchStep($rfcScrt, '287082', 59-30), 1);
checkSame('matchStep: two steps old',     Totp::matchStep($rfcScrt, '287082', 59+60), null);
checkSame('matchStep: wrong code',        Totp::matchStep($rfcScrt, '000000', 59), null);
checkSame('matchStep: code with a letter', Totp::matchStep($rfcScrt, '28708a', 59), null);
checkSame('matchStep: secret that is not base32', Totp::matchStep('not base32 !', '287082', 59), null);
checkSame('matchStep: a step of today',   Totp::matchStep($rfcScrt, '005924', 1234567890), intdiv(1234567890, 30));

// Storage : a secret is encrypted with a key of 32 characters at least, and read back with it
$sealKey = str_repeat('k', 32);
$sealTxt = Totp::sealSecrt($rfcScrt, $sealKey);
checkLike('sealSecrt gives a marked text that fits the field', $sealTxt, '/^enc1:[A-Za-z0-9+\/=]{80}$/D');
checkSame('openSecrt reads it back with the key', Totp::openSecrt($sealTxt, $sealKey), $rfcScrt);
checkTrue('sealSecrt gives another text each time', $sealTxt!==Totp::sealSecrt($rfcScrt, $sealKey));
checkSame('openSecrt with another key', Totp::openSecrt($sealTxt, str_repeat('x', 32)), null);
checkSame('openSecrt without a key', Totp::openSecrt($sealTxt, ''), null);
checkSame('openSecrt of a changed text', Totp::openSecrt(substr($sealTxt, 0, -6).(substr($sealTxt, -6, 1)=='A' ? 'B' : 'A').substr($sealTxt, -5), $sealKey), null);
checkSame('openSecrt of a marked text that is not one', Array(Totp::openSecrt('enc1:', $sealKey), Totp::openSecrt('enc1:not base64 !', $sealKey), Totp::openSecrt('enc1:'.base64_encode('short'), $sealKey)), Array(null, null, null));
checkSame('openSecrt gives a readable secret as it is, with or without a key', Array(Totp::openSecrt($rfcScrt, $sealKey), Totp::openSecrt($rfcScrt, '')), Array($rfcScrt, $rfcScrt));
checkSame('sealSecrt leaves the secret readable without a key, or with a short one', Array(Totp::sealSecrt($rfcScrt, ''), Totp::sealSecrt($rfcScrt, str_repeat('k', 31))), Array($rfcScrt, $rfcScrt));

// New secrets
$newScrt = Totp::makeSecrt();
checkLike('makeSecrt gives 32 base32 characters', $newScrt, '/^[A-Z2-7]{32}$/');
checkTrue('makeSecrt gives another secret each time', $newScrt!==Totp::makeSecrt());
checkSame('a new secret checks its own code, at the current time', Totp::checkCode($newScrt, Totp::codeAtTme($newScrt, time())), true);

endChecks();
