<?php

namespace nyu\Access\Auth;

/**
 * Verifies a request against a flat list of valid API keys, using a
 * timing-safe comparison.
 */
class RegKey implements Handler{

	protected $valdKeys;
	protected $lastMssg;

	/**
	 * @param Array $confValues Config block with a 'keys' list of valid API keys.
	 */
	function __construct( Array $confValues ){
		$this->valdKeys = $confValues['keys'] ?? Array();
		$this->lastMssg = '';
	}

	/**
	 * Checks the given key against the configured valid-keys list.
	 *
	 * @param Array $credsArray Must contain a 'key' entry.
	 * @return Array|Bool An identity array on success, or false on failure.
	 */
	function checkCred( Array $credsArray ): Array|Bool {
		$givnKey = $credsArray['key'] ?? '';
		if( $givnKey=='' ){
			$this->lastMssg = 'No API key provided';
			return false;
		}
		foreach( $this->valdKeys as $oneKey ){
			if( hash_equals( (string)$oneKey, $givnKey ) ){
				return Array('user'=>'');
			}
		}
		$this->lastMssg = 'Invalid API key';
		return false;
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
