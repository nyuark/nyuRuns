<?php

// Test-only : named like an access plug-in, but it does not implement the framework's Auth\Handler interface.

namespace nyu\Runs\Shared;

class WrongiAuth{

	/**
	 * Would accept anyone, if the framework ever called it.
	 *
	 * @param Array $loginDataArr Unused.
	 * @return Array|Bool Always an identity.
	 */
	public function checkSecretDct( Array $loginDataArr ): Array|Bool {
		return Array('userName'=>'anyone');
	}
}
