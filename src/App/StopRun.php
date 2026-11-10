<?php

namespace nyu\App;

/**
 * Thrown to end the running action on purpose: it carries the status, the
 * message and the output of the reply. It is not an error.
 */
class StopRun extends \RuntimeException {

	protected $stopOutput;

	/**
	 * Builds a deliberate stop of the running action: the reply uses the
	 * status, message and output it carries. It is not an error.
	 *
	 * @param Int    $responseCode HTTP status to reply with.
	 * @param String $statusReason Message describing a non-2xx status.
	 * @param Array|Object|String|Int|Float|Bool|Null $outputToSend Output to send; null keeps what the action had already set.
	 */
	public function __construct( Int $responseCode, String $statusReason='', Array|Object|String|Int|Float|Bool|Null $outputToSend=null ){
		parent::__construct($statusReason, $responseCode);
		$this->stopOutput = $outputToSend;
	}

	/**
	 * Returns the output carried by the stop.
	 *
	 * @return Array|Object|String|Int|Float|Bool|Null The output, or null when the stop carries none.
	 */
	public function retrieveOutput(): Array|Object|String|Int|Float|Bool|Null {
		return $this->stopOutput;
	}
}
