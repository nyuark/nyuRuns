<?php

// Test-only access plug-in : a credential check that test/unit/PluginTest.php resolves as "sample".

namespace nyu\Runs\Shared;

use nyu\Access\Auth\Handler as AuthHndlr;

class SampleAuth implements AuthHndlr{

	/**
	 * @param Array $confValues Unused.
	 */
	public function __construct( Array $confValues ){
	}

	/**
	 * Accepts the key "sample-key" alone.
	 *
	 * @param Array $credsArray Must contain a 'key' entry.
	 * @return Array|Bool An identity array on success, or false on failure.
	 */
	public function checkCred( Array $credsArray ): Array|Bool {
		return ( ($credsArray['key'] ?? '')==='sample-key' ) ? Array('user'=>'sampleUser') : false;
	}

	/**
	 * @return String The last error message.
	 */
	public function lastError(): String {
		return 'Invalid sample key';
	}
}
