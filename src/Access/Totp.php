<?php

namespace nyu\Access;

/**
 * Time-based one-time codes (TOTP, RFC 6238), as produced by
 * authenticator applications: 6 digits, 30 second steps, HMAC-SHA1.
 */
class Totp{

	const STEPSECS = 30;
	const CODESIZE = 6;
	const B32CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	// Mark of a secret stored encrypted
	const SEALMARK = 'enc1:';
	// Shortest key a secret is encrypted with, in characters
	const SEALSIZE = 32;

	/**
	 * Makes a new random secret, in the base32 form authenticator
	 * applications take.
	 *
	 * @return String The 32 character secret.
	 */
	public static function makeSecrt(): String {
		$bitText = '';
		foreach( str_split(random_bytes(20)) as $oneByt ){
			$bitText .= str_pad(decbin(ord($oneByt)), 8, '0', STR_PAD_LEFT);
		}
		$secrTxt = '';
		foreach( str_split($bitText, 5) as $bitGrp ){
			$secrTxt .= self::B32CHARS[bindec($bitGrp)];
		}
		return $secrTxt;
	}

	/**
	 * Computes the code of a secret at a given time.
	 *
	 * @param String $secretText The base32 secret.
	 * @param Int    $unixMoment Unix time the code is for.
	 * @return String The code, with its leading zeros.
	 */
	public static function codeAtTme( String $secretText, Int $unixMoment ): String {
		$hmacBin = hash_hmac('sha1', pack('J', intdiv($unixMoment, self::STEPSECS)), self::fromBas32($secretText), true);
		$startAt = ord($hmacBin[19]) & 0x0F;
		$codeNum = unpack('N', substr($hmacBin, $startAt, 4))[1] & 0x7FFFFFFF;
		return str_pad(strval($codeNum % (10 ** self::CODESIZE)), self::CODESIZE, '0', STR_PAD_LEFT);
	}

	/**
	 * Checks a code against a secret, accepting the previous and the
	 * next step to allow for clock differences.
	 *
	 * @param String $secretText The base32 secret.
	 * @param String $givenDigit The code given by the user.
	 * @param ?Int   $unixMoment Unix time to check at; now when not given.
	 * @return Bool Whether the code is valid.
	 */
	public static function checkCode( String $secretText, String $givenDigit, ?Int $unixMoment = null ): Bool {
		return self::matchStep($secretText, $givenDigit, $unixMoment)!==null;
	}

	/**
	 * Finds the step a code is the one of, among the current step, the
	 * previous and the next one, to allow for clock differences.
	 *
	 * @param String $secretText The base32 secret.
	 * @param String $givenDigit The code given by the user.
	 * @param ?Int   $unixMoment Unix time to check at; now when not given.
	 * @return ?Int The number of the step (the Unix time divided by STEPSECS), the latest when several match; null when the code is not valid.
	 */
	public static function matchStep( String $secretText, String $givenDigit, ?Int $unixMoment = null ): ?Int {
		if( !preg_match('/^\d{'.self::CODESIZE.'}$/', $givenDigit) || self::fromBas32($secretText)=='' ){
			return null;
		}
		$nowStep = intdiv($unixMoment ?? time(), self::STEPSECS);
		$fndStep = null;
		foreach( Array(-1, 0, 1) as $stp ){
			if( hash_equals( self::codeAtTme($secretText, ($nowStep + $stp)*self::STEPSECS), $givenDigit ) ){
				$fndStep = $nowStep + $stp;
			}
		}
		return $fndStep;
	}

	/**
	 * Encrypts a secret for its storage (AES-256-GCM), with a key drawn
	 * from the given text. Without a key of SEALSIZE characters, or
	 * without the openssl extension, the secret is given back as it is.
	 *
	 * @param String $secretText The base32 secret.
	 * @param String $cipherPass The text the key is drawn from.
	 * @return String The secret to store: encrypted behind SEALMARK, or unchanged.
	 */
	public static function sealSecrt( String $secretText, String $cipherPass ): String {
		if( strlen($cipherPass)<self::SEALSIZE || !function_exists('openssl_encrypt') ){
			return $secretText;
		}
		$nonceBn = random_bytes(12);
		$tagByts = '';
		$ciphrBn = openssl_encrypt($secretText, 'aes-256-gcm', hash('sha256', $cipherPass, true), OPENSSL_RAW_DATA, $nonceBn, $tagByts);
		return ($ciphrBn===false) ? $secretText : self::SEALMARK.base64_encode($nonceBn.$tagByts.$ciphrBn);
	}

	/**
	 * Reads a stored secret: one stored readable is given as it is, an
	 * encrypted one is decrypted with the key drawn from the given text.
	 *
	 * @param String $storedText The secret, as stored.
	 * @param String $cipherPass The text the key is drawn from.
	 * @return ?String The base32 secret; null when it is encrypted and cannot be read (no key, another key, or a changed value).
	 */
	public static function openSecrt( String $storedText, String $cipherPass ): ?String {
		if( strpos($storedText, self::SEALMARK)!==0 ){
			return $storedText;
		}
		$sealBin = base64_decode(substr($storedText, strlen(self::SEALMARK)), true);
		if( $sealBin===false || strlen($sealBin)<29 || strlen($cipherPass)<self::SEALSIZE || !function_exists('openssl_decrypt') ){
			return null;
		}
		$plainTx = openssl_decrypt(substr($sealBin, 28), 'aes-256-gcm', hash('sha256', $cipherPass, true), OPENSSL_RAW_DATA, substr($sealBin, 0, 12), substr($sealBin, 12, 16));
		return ($plainTx===false) ? null : $plainTx;
	}

	/**
	 * Decodes a base32 secret into its bytes.
	 *
	 * @param String $secretText The base32 secret; spaces and padding are ignored.
	 * @return String The bytes, empty when the secret is not base32.
	 */
	protected static function fromBas32( String $secretText ): String {
		$bitText = '';
		foreach( str_split(strtoupper(rtrim(str_replace(' ', '', $secretText), '='))) as $oneChr ){
			$chrPosn = strpos(self::B32CHARS, $oneChr);
			if( $chrPosn===false ){
				return '';
			}
			$bitText .= str_pad(decbin($chrPosn), 5, '0', STR_PAD_LEFT);
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
