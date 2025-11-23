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
	 * Builds the check against the list of API keys from its config block.
	 *
	 * @param Array $confValues Config block with a 'keylst' of valid API keys: a plain list, or the keys by the name
	 *                          of their holder, which then identifies it.
	 */
	function __construct( Array $confValues ){
		$this->valdKeys = is_array($confValues['keylst'] ?? null) ? $confValues['keylst'] : Array();
		$this->lastMssg = '';
	}

	/**
	 * Checks the given key against the configured valid-keys list.
	 *
	 * @param Array $credsArray Must contain a 'key' entry.
	 * @return Array|Bool An identity array on success, its 'user' being the name the key is written under ('' in a plain list), or false on failure.
	 */
	function checkCred( Array $credsArray ): Array|Bool {
		$givnKey = $credsArray['key'] ?? '';
		$this->wrongCrd = false;
		if( $givnKey=='' ){
			$this->lastMssg = 'No API key provided';
			return false;
		}
		foreach( $this->valdKeys as $keyNme => $oneKey ){
			// An empty key, as a secret left unset gives, is the key of nobody
			if( is_scalar($oneKey) && strval($oneKey)!=='' && hash_equals( strval($oneKey), $givnKey ) ){
				// A key written under a name identifies its holder
				return Array('user'=>is_string($keyNme) ? $keyNme : '');
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
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	function lastError(): String {
		return $this->lastMssg;
	}
}
