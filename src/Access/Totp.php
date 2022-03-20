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
		if( !preg_match('/^\d{'.self::CODESIZE.'}$/', $givenDigit) || self::fromBas32($secretText)=='' ){
			return false;
		}
		$nowTime = $unixMoment ?? time();
		$matched = false;
		foreach( Array(-1, 0, 1) as $stp ){
			$matched = hash_equals( self::codeAtTme($secretText, $nowTime + $stp*self::STEPSECS), $givenDigit ) || $matched;
		}
		return $matched;
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
