<?php

// Test-only replacement for the php:// stream wrapper, so that reading
// php://input returns a body chosen by the test:
//
//   FakeInput::plugInput();
//   FakeInput::$bodyText = '{"a":1}';

class FakeInput {

	public static $bodyText = '';
	public $context;
	protected $readPosn = 0;

	/**
	 * Replaces the php:// wrapper with this class.
	 */
	public static function plugInput(): Void {
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', 'FakeInput');
	}

	/**
	 * Stream wrapper hook: opens the fake stream.
	 *
	 * @param String  $streamPath Path being opened.
	 * @param String  $accessMode Mode it is opened with.
	 * @param Int     $optionBits Stream options.
	 * @param ?String $openedPath Path actually opened, set by the wrapper.
	 * @return Bool Always true.
	 */
	public function stream_open( String $streamPath, String $accessMode, Int $optionBits, ?String &$openedPath ): Bool {
		$this->readPosn = 0;
		return true;
	}

	/**
	 * Stream wrapper hook: reads the next chunk of the chosen body.
	 *
	 * @param Int $bytesCount Number of bytes asked for.
	 * @return String The chunk read.
	 */
	public function stream_read( Int $bytesCount ): String {
		$chunkTx = substr(self::$bodyText, $this->readPosn, $bytesCount);
		$this->readPosn += strlen($chunkTx);
		return $chunkTx;
	}

	/**
	 * Stream wrapper hook: tells whether the body was read entirely.
	 *
	 * @return Bool True at the end of the body.
	 */
	public function stream_eof(): Bool {
		return $this->readPosn >= strlen(self::$bodyText);
	}

	/**
	 * Stream wrapper hook: reports no file statistics.
	 *
	 * @return Array An empty array.
	 */
	public function stream_stat(): Array {
		return Array();
	}
}
