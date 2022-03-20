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
checkSame('code two steps ahead',         Totp::checkCode($rfcScrt, '287082', 59-60), false);
checkSame('wrong code',                   Totp::checkCode($rfcScrt, '000000', 59), false);
checkSame('code that is too short',       Totp::checkCode($rfcScrt, '87082', 59), false);
checkSame('code with a letter',           Totp::checkCode($rfcScrt, '28708a', 59), false);
checkSame('empty code',                   Totp::checkCode($rfcScrt, '', 59), false);
checkSame('secret that is not base32',    Totp::checkCode('not base32 !', '287082', 59), false);
checkSame('empty secret',                 Totp::checkCode('', Totp::codeAtTme('', 59), 59), false);

// New secrets
$newScrt = Totp::makeSecrt();
checkLike('makeSecrt gives 32 base32 characters', $newScrt, '/^[A-Z2-7]{32}$/');
checkTrue('makeSecrt gives another secret each time', $newScrt!==Totp::makeSecrt());
checkSame('a new secret checks its own code, at the current time', Totp::checkCode($newScrt, Totp::codeAtTme($newScrt, time())), true);

endChecks();
