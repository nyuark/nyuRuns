<?php

namespace nyu\Access\Auth;

/**
 * Verifies a request against a flat list of valid API keys, using a
 * timing-safe comparison.
 */
class RegKey implements Handler{

	protected $valdKeys;
	protected $lastMssg;
	protected $wrongCrd = false;

	/**
	 * @param Array $confValues Config block with a 'keylst' list of valid API keys.
	 */
	function __construct( Array $confValues ){
		$this->valdKeys = $confValues['keylst'] ?? Array();
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
		$this->wrongCrd = false;
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
		$this->wrongCrd = true;
		return false;
	}

	/**
	 * Tells whether the last refusal was a wrong key, as opposed to a
	 * missing one: only a wrong credential counts against the address that
	 * presented it (see Access\Limiter).
	 *
	 * @return Bool True when a key was presented and refused.
	 */
	public function wrongCred(): Bool {
		return $this->wrongCrd;
	}

	/**
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
