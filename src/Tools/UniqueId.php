<?php

namespace nyu\Tools;

/**
 * Makes the identifiers a record keeps wherever it is copied: UUIDs of
 * version 7 (RFC 9562), written in lower case.
 *
 * A UUID v7 starts with the time it was made, in milliseconds, so that the
 * identifiers sort in the order they were made; the 74 bits that follow are
 * random. Two identifiers made in the same millisecond are not ordered.
 */
class UniqueId {

	/**
	 * Makes a new UUID of version 7.
	 *
	 * @return String The identifier, as 0192f0c4-8b5e-7a3d-9f21-6c0e4b7d2a18.
	 */
	public static function newUuid7String(): String {
		$msecNumb = intval( floor(microtime(true)*1000) );
		$timeHxdc = str_pad(dechex($msecNumb), 12, '0', STR_PAD_LEFT);
		$rndmHexa = bin2hex( random_bytes(10) );
		// The variant takes the two first bits of the fourth group: 10
		$variaTxt = dechex( (hexdec($rndmHexa[3]) & 0x3) | 0x8 );
		return substr($timeHxdc, 0, 8).'-'.substr($timeHxdc, 8, 4).'-7'.substr($rndmHexa, 0, 3)
			.'-'.$variaTxt.substr($rndmHexa, 4, 3).'-'.substr($rndmHexa, 7, 12);
	}

	/**
	 * Tells whether a text is a well-formed UUID, of any version, in any case.
	 *
	 * @param String $textToVerify The text.
	 * @return Bool Whether it is a UUID.
	 */
	public static function verifyUuidFlag( String $textToVerify ): Bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $textToVerify)===1;
	}

}
