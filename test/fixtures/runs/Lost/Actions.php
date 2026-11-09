<?php

// Test-only application : its default action names a method that does not exist.

namespace nyu\Runs\Lost;

use \nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltAction = 'nowhere';

	/**
	 * Answers normally.
	 */
	public function hereLost(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array('found'=>true);
	}
}
