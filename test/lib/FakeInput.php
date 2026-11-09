<?php

// Test-only replacement for the php:// stream wrapper, so that reading
// php://input returns a body chosen by the test:
//
//   FakeInput::plugFakeStream();
//   FakeInput::$bodyText = '{"a":1}';

class FakeInput {

	public static $bodyString = '';
	public $context;
	protected $readOffset = 0;

	/**
	 * Replaces the php:// wrapper with this class.
	 */
	public static function plugFakeStream(): Void {
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', 'FakeInput');
	}

	/**
	 * Stream wrapper hook: opens the fake stream.
	 *
	 * @param String  $pathAskedFor Path being opened.
	 * @param String  $accsModeText Mode it is opened with.
	 * @param Int     $optionBitsNb Stream options.
	 * @param ?String $pathReturned Path actually opened, set by the wrapper.
	 * @return Bool Always true.
	 */
	public function stream_open( String $pathAskedFor, String $accsModeText, Int $optionBitsNb, ?String &$pathReturned ): Bool {
		$this->readOffset = 0;
		return true;
	}

	/**
	 * Stream wrapper hook: reads the next chunk of the chosen body.
	 *
	 * @param Int $dataByteSize Number of bytes asked for.
	 * @return String The chunk read.
	 */
	public function stream_read( Int $dataByteSize ): String {
		$chunkTx = substr(self::$bodyString, $this->readOffset, $dataByteSize);
		$this->readOffset += strlen($chunkTx);
		return $chunkTx;
	}

	/**
	 * Stream wrapper hook: tells whether the body was read entirely.
	 *
	 * @return Bool True at the end of the body.
	 */
	public function stream_eof(): Bool {
		return $this->readOffset >= strlen(self::$bodyString);
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
