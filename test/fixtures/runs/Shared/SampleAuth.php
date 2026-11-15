<?php

// Test-only access plug-in : a credential check that test/unit/PluginTest.php resolves as "sample".

namespace nyu\Runs\Shared;

use nyu\Access\Auth\Handler as AuthHndlr;

class SampleAuth implements AuthHndlr{

	/**
	 * @param Array $configValues Unused.
	 */
	public function __construct( Array $configValues ){
	}

	/**
	 * Accepts the key "sample-key" alone.
	 *
	 * @param Array $loginDataArr Must contain a 'key' entry.
	 * @return Array|Bool An identity array on success, or false on failure.
	 */
	public function checkSecretDct( Array $loginDataArr ): Array|Bool {
		return ( ($loginDataArr['key'] ?? '')==='sample-key' ) ? Array('userName'=>'sampleUser') : false;
	}

	/**
	 * @return String The last error message.
	 */
	public function getLastErrText(): String {
		return 'Invalid sample key';
	}
}
