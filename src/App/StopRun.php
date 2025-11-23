<?php

namespace nyu\App;

/**
 * Thrown to end the running action on purpose: it carries the status, the
 * message and the output of the reply. It is not an error.
 */
class StopRun extends \RuntimeException {

	protected $stopData;

	/**
	 * Builds a deliberate stop of the running action: the reply uses the
	 * status, message and output it carries. It is not an error.
	 *
	 * @param Int    $statusCode HTTP status to reply with.
	 * @param String $statusMssg Message describing a non-2xx status.
	 * @param Array|Object|String|Int|Float|Bool|Null $dataValues Output to send; null keeps what the action had already set.
	 */
	public function __construct( Int $statusCode, String $statusMssg='', Array|Object|String|Int|Float|Bool|Null $dataValues=null ){
		parent::__construct($statusMssg, $statusCode);
		$this->stopData = $dataValues;
	}

	/**
	 * Returns the output carried by the stop.
	 *
	 * @return Array|Object|String|Int|Float|Bool|Null The output, or null when the stop carries none.
	 */
	public function getOutput(): Array|Object|String|Int|Float|Bool|Null {
		return $this->stopData;
	}
}
