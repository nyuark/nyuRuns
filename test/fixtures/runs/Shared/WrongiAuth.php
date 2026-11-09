<?php

// Test-only : named like an access plug-in, but it does not implement the framework's Auth\Handler interface.

namespace nyu\Runs\Shared;

class WrongiAuth{

	/**
	 * Would accept anyone, if the framework ever called it.
	 *
	 * @param Array $crdntlsArray Unused.
	 * @return Array|Bool Always an identity.
	 */
	public function chckCredential( Array $crdntlsArray ): Array|Bool {
		return Array('user'=>'anyone');
	}
}
