<?php

namespace nyu\Access\Auth;

/**
 * Verifies a request against a flat list of valid API keys, using a
 * timing-safe comparison.
 */
class RegKey implements Handler{

	protected $acceptKeys;
	protected $errMessage;
	protected $isWrongTry = false;

	/**
	 * Builds the check against the list of API keys from its config block.
	 *
	 * @param Array $configValues Config block with a 'keysList' of valid API keys: a plain list, or the keys by the name
	 *                          of their holder, which then identifies it.
	 */
	function __construct( Array $configValues ){
		$this->acceptKeys = is_array($configValues['keysList'] ?? null) ? $configValues['keysList'] : Array();
		$this->errMessage = '';
	}

	/**
	 * Checks the given key against the configured valid-keys list.
	 *
	 * @param Array $loginDataLst Must contain a 'key' entry.
	 * @return Array|Bool An identity array on success, its 'userName' being the name the key is written under ('' in a plain list), or false on failure.
	 */
	function checkSecretDct( Array $loginDataLst ): Array|Bool {
		$sentClef = $loginDataLst['key'] ?? '';
		$this->isWrongTry = false;
		if( $sentClef=='' ){
			$this->errMessage = 'No API key provided';
			return false;
		}
		foreach( $this->acceptKeys as $keyNme => $oneKey ){
			// An empty key, as a secret left unset gives, is the key of nobody
			if( is_scalar($oneKey) && strval($oneKey)!=='' && hash_equals( strval($oneKey), $sentClef ) ){
				// A key written under a name identifies its holder
				return Array('userName'=>is_string($keyNme) ? $keyNme : '');
			}
		}
		$this->errMessage = 'Invalid API key';
		$this->isWrongTry = true;
		return false;
	}

	/**
	 * Tells whether the last refusal was a wrong key, as opposed to a
	 * missing one: only a wrong credential counts against the address that
	 * presented it (see Access\Limiter).
	 *
	 * @return Bool True when a key was presented and refused.
	 */
	public function hasWrongSecret(): Bool {
		return $this->isWrongTry;
	}

	/**
	 * Gives the message of the last error.
	 *
	 * @return String The last error message.
	 */
	function getLastErrText(): String {
		return $this->errMessage;
	}
}
