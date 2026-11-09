<?php

namespace nyu\Runs\Clashed;

use nyu\App\Actionset;

/**
 * Test application whose route clashes with one of Mounted when it is
 * mounted under mounted (test/unit/RouterTest.php).
 */
class Actions extends Actionset {

	/**
	 * Replies with its name.
	 */
	public function clashed(): Void {
		$this->statusCode = 200;
		$this->replyValue = 'clashed/clashed';
	}
}
