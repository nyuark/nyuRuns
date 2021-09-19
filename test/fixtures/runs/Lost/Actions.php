<?php

// Test-only application : its default action names a method that does not exist.

namespace nyu\Runs\Lost;

use \nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltActn = 'nowhere';

	/**
	 * Answers normally.
	 */
	public function here(): Void {
		$this->statCode = 200;
		$this->asOutput = Array('found'=>true);
	}
}
