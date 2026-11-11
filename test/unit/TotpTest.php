<?php

// Access\Totp : one-time codes, checked against the SHA-1 test values of
// RFC 6238 (secret "12345678901234567890", here in base32).

require __DIR__.'/../lib/harness.php';

use nyu\Access\Totp;

$rfcScrt = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
foreach( Array(59=>'287082', 1111111109=>'081804', 1111111111=>'050471', 1234567890=>'005924', 2000000000=>'279037', 20000000000=>'353130') as $rfcTme=>$rfcCod ){
	checkIdentical('RFC 6238 value at '.$rfcTme, Totp::codeAtTimeText($rfcScrt, $rfcTme), $rfcCod);
}
checkIdentical('secret in lower case, with spaces and padding', Totp::codeAtTimeText('gezd gnbv gy3t qojq gezd gnbv gy3t qojq====', 59), '287082');

// Checking : the current step, the previous and the next one
checkIdentical('code of the current step',     Totp::verifyTotpCode($rfcScrt, '287082', 59), true);
checkIdentical('code of the previous step',    Totp::verifyTotpCode($rfcScrt, '287082', 59+30), true);
checkIdentical('code of the next step',        Totp::verifyTotpCode($rfcScrt, '287082', 59-30), true);
checkIdentical('code two steps old',           Totp::verifyTotpCode($rfcScrt, '287082', 59+60), false);
checkIdentical('code two steps ahead',         Totp::verifyTotpCode($rfcScrt, '081804', 1111111109-60), false);
checkIdentical('wrong code',                   Totp::verifyTotpCode($rfcScrt, '000000', 59), false);
checkIdentical('code that is too short',       Totp::verifyTotpCode($rfcScrt, '87082', 59), false);
checkIdentical('code with a letter',           Totp::verifyTotpCode($rfcScrt, '28708a', 59), false);
checkIdentical('empty code',                   Totp::verifyTotpCode($rfcScrt, '', 59), false);
checkIdentical('secret that is not base32',    Totp::verifyTotpCode('not base32 !', '287082', 59), false);
checkIdentical('empty secret',                 Totp::verifyTotpCode('', Totp::codeAtTimeText('', 59), 59), false);

// The step a code is the one of : time 59 is in step 1
checkIdentical('matchStep: the current step',  Totp::matchStepCount($rfcScrt, '287082', 59), 1);
checkIdentical('matchStep: the previous step', Totp::matchStepCount($rfcScrt, '287082', 59+30), 1);
checkIdentical('matchStep: the next step',     Totp::matchStepCount($rfcScrt, '287082', 59-30), 1);
checkIdentical('matchStep: two steps old',     Totp::matchStepCount($rfcScrt, '287082', 59+60), null);
checkIdentical('matchStep: wrong code',        Totp::matchStepCount($rfcScrt, '000000', 59), null);
checkIdentical('matchStep: code with a letter', Totp::matchStepCount($rfcScrt, '28708a', 59), null);
checkIdentical('matchStep: secret that is not base32', Totp::matchStepCount('not base32 !', '287082', 59), null);
checkIdentical('matchStep: a step of today',   Totp::matchStepCount($rfcScrt, '005924', 1234567890), intdiv(1234567890, 30));

// Storage : a secret is encrypted with a key of 32 characters at least, and read back with it
$sealKey = str_repeat('k', 32);
if( function_exists('openssl_encrypt') ){
	$sealTxt = Totp::sealSecretText($rfcScrt, $sealKey);
	checkPregMatch('sealSecrt gives a marked text that fits the field', $sealTxt, '/^enc1:[A-Za-z0-9+\/=]{80}$/D');
	checkIdentical('openSecrt reads it back with the key', Totp::openSecretText($sealTxt, $sealKey), $rfcScrt);
	checkTrueValue('sealSecrt gives another text each time', $sealTxt!==Totp::sealSecretText($rfcScrt, $sealKey));
	checkIdentical('openSecrt with another key', Totp::openSecretText($sealTxt, str_repeat('x', 32)), null);
	checkIdentical('openSecrt without a key', Totp::openSecretText($sealTxt, ''), null);
	checkIdentical('openSecrt of a changed text', Totp::openSecretText(substr($sealTxt, 0, -6).(substr($sealTxt, -6, 1)=='A' ? 'B' : 'A').substr($sealTxt, -5), $sealKey), null);
	checkIdentical('openSecrt of a marked text that is not one', Array(Totp::openSecretText('enc1:', $sealKey), Totp::openSecretText('enc1:not base64 !', $sealKey), Totp::openSecretText('enc1:'.base64_encode('short'), $sealKey)), Array(null, null, null));
}else{
	recordSkipping('Totp: secrets stored encrypted', 'the openssl extension is not loaded');
}
checkIdentical('openSecrt gives a readable secret as it is, with or without a key', Array(Totp::openSecretText($rfcScrt, $sealKey), Totp::openSecretText($rfcScrt, '')), Array($rfcScrt, $rfcScrt));
checkIdentical('sealSecrt leaves the secret readable without a key, or with a short one', Array(Totp::sealSecretText($rfcScrt, ''), Totp::sealSecretText($rfcScrt, str_repeat('k', 31))), Array($rfcScrt, $rfcScrt));

// New secrets
$newScrt = Totp::generateSecret();
checkPregMatch('mkSecrett gives 32 base32 characters', $newScrt, '/^[A-Z2-7]{32}$/');
checkTrueValue('mkSecrett gives another secret each time', $newScrt!==Totp::generateSecret());
checkIdentical('a new secret checks its own code, at the current time', Totp::verifyTotpCode($newScrt, Totp::codeAtTimeText($newScrt, time())), true);

finishChecking();
