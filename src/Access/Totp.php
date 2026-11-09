<?php

namespace nyu\Access;

/**
 * Time-based one-time codes (TOTP, RFC 6238), as produced by
 * authenticator applications: 6 digits, 30 second steps, HMAC-SHA1.
 */
class Totp{

	const STEP_SECS = 30;
	const CODE_SIZE = 6;
	const BASE_CHRS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	// Mark of a secret stored encrypted
	const SEAL_MARK = 'enc1:';
	// Shortest key a secret is encrypted with, in characters
	const SEAL_SIZE = 32;

	/**
	 * Makes a new random secret, in the base32 form authenticator
	 * applications take.
	 *
	 * @return String The 32 character secret.
	 */
	public static function generateSecret(): String {
		$bitText = '';
		foreach( str_split(random_bytes(20)) as $oneByt ){
			$bitText .= str_pad(decbin(ord($oneByt)), 8, '0', STR_PAD_LEFT);
		}
		$secrTxt = '';
		foreach( str_split($bitText, 5) as $bitGrp ){
			$secrTxt .= self::BASE_CHRS[bindec($bitGrp)];
		}
		return $secrTxt;
	}

	/**
	 * Computes the code of a secret at a given time.
	 *
	 * @param String $codeSecrText The base32 secret.
	 * @param Int    $unixMomentNb Unix time the code is for.
	 * @return String The code, with its leading zeros.
	 */
	public static function codeAtTimeText( String $codeSecrText, Int $unixMomentNb ): String {
		$hmacRaw = hash_hmac('sha1', pack('J', intdiv($unixMomentNb, self::STEP_SECS)), self::fromBase32Text($codeSecrText), true);
		$startAt = ord($hmacRaw[19]) & 0x0F;
		$codeNum = unpack('N', substr($hmacRaw, $startAt, 4))[1] & 0x7FFFFFFF;
		return str_pad(strval($codeNum % (10 ** self::CODE_SIZE)), self::CODE_SIZE, '0', STR_PAD_LEFT);
	}

	/**
	 * Checks a code against a secret, accepting the previous and the
	 * next step to allow for clock differences.
	 *
	 * @param String $codeSecrText The base32 secret.
	 * @param String $codeFromUser The code given by the user.
	 * @param ?Int   $unixMomentNb Unix time to check at; now when not given.
	 * @return Bool Whether the code is valid.
	 */
	public static function verifyTotpCode( String $codeSecrText, String $codeFromUser, ?Int $unixMomentNb = null ): Bool {
		return self::matchStepCount($codeSecrText, $codeFromUser, $unixMomentNb)!==null;
	}

	/**
	 * Finds the step a code is the one of, among the current step, the
	 * previous and the next one, to allow for clock differences.
	 *
	 * @param String $codeSecrText The base32 secret.
	 * @param String $codeFromUser The code given by the user.
	 * @param ?Int   $unixMomentNb Unix time to check at; now when not given.
	 * @return ?Int The number of the step (the Unix time divided by STEPSECS), the latest when several match; null when the code is not valid.
	 */
	public static function matchStepCount( String $codeSecrText, String $codeFromUser, ?Int $unixMomentNb = null ): ?Int {
		if( !preg_match('/^\d{'.self::CODE_SIZE.'}$/', $codeFromUser) || self::fromBase32Text($codeSecrText)=='' ){
			return null;
		}
		$nowStep = intdiv($unixMomentNb ?? time(), self::STEP_SECS);
		$hitStep = null;
		foreach( Array(-1, 0, 1) as $stpInc ){
			if( hash_equals( self::codeAtTimeText($codeSecrText, ($nowStep + $stpInc)*self::STEP_SECS), $codeFromUser ) ){
				$hitStep = $nowStep + $stpInc;
			}
		}
		return $hitStep;
	}

	/**
	 * Encrypts a secret for its storage (AES-256-GCM), with a key drawn
	 * from the given text. Without a key of SEALSIZE characters, or
	 * without the openssl extension, the secret is given back as it is.
	 *
	 * @param String $codeSecrText The base32 secret.
	 * @param String $sealPassword The text the key is drawn from.
	 * @return String The secret to store: encrypted behind SEALMARK, or unchanged.
	 */
	public static function sealSecretText( String $codeSecrText, String $sealPassword ): String {
		if( strlen($sealPassword)<self::SEAL_SIZE || !function_exists('openssl_encrypt') ){
			return $codeSecrText;
		}
		$nonceBn = random_bytes(12);
		$tagByts = '';
		$ciphrBn = openssl_encrypt($codeSecrText, 'aes-256-gcm', hash('sha256', $sealPassword, true), OPENSSL_RAW_DATA, $nonceBn, $tagByts);
		return ($ciphrBn===false) ? $codeSecrText : self::SEAL_MARK.base64_encode($nonceBn.$tagByts.$ciphrBn);
	}

	/**
	 * Reads a stored secret: one stored readable is given as it is, an
	 * encrypted one is decrypted with the key drawn from the given text.
	 *
	 * @param String $storedSecret The secret, as stored.
	 * @param String $sealPassword The text the key is drawn from.
	 * @return ?String The base32 secret; null when it is encrypted and cannot be read (no key, another key, or a changed value).
	 */
	public static function openSecretText( String $storedSecret, String $sealPassword ): ?String {
		if( strpos($storedSecret, self::SEAL_MARK)!==0 ){
			return $storedSecret;
		}
		$selBnry = base64_decode(substr($storedSecret, strlen(self::SEAL_MARK)), true);
		if( $selBnry===false || strlen($selBnry)<29 || strlen($sealPassword)<self::SEAL_SIZE || !function_exists('openssl_decrypt') ){
			return null;
		}
		$plainTx = openssl_decrypt(substr($selBnry, 28), 'aes-256-gcm', hash('sha256', $sealPassword, true), OPENSSL_RAW_DATA, substr($selBnry, 0, 12), substr($selBnry, 12, 16));
		return ($plainTx===false) ? null : $plainTx;
	}

	/**
	 * Decodes a base32 secret into its bytes.
	 *
	 * @param String $codeSecrText The base32 secret; spaces and padding are ignored.
	 * @return String The bytes, empty when the secret is not base32.
	 */
	protected static function fromBase32Text( String $codeSecrText ): String {
		$bitText = '';
		foreach( str_split(strtoupper(rtrim(str_replace(' ', '', $codeSecrText), '='))) as $letter ){
			$charPos = strpos(self::BASE_CHRS, $letter);
			if( $charPos===false ){
				return '';
			}
			$bitText .= str_pad(decbin($charPos), 5, '0', STR_PAD_LEFT);
		}
		$keyByts = '';
		foreach( str_split($bitText, 8) as $bitGrp ){
			if( strlen($bitGrp)==8 ){
				$keyByts .= chr(bindec($bitGrp));
			}
		}
		return $keyByts;
	}
}
