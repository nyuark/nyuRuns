<?php

namespace nyu\Access\Auth;

/**
 * Contract for a credential-verification strategy: checks raw
 * credentials (username/password, an API key, ...) and returns the
 * matched identity.
 */
interface Handler{

	/**
	 * Prepares the strategy with its named config block (e.g. an
	 * htpasswd file path, or a list of valid API keys).
	 *
	 * @param Array $configValues Strategy-specific configuration values.
	 */
	public function __construct( Array $configValues );

	/**
	 * Verifies a set of raw credentials.
	 *
	 * @param Array $loginDataArr Credential fields to check (e.g. ['user'=>..,'pass'=>..] or ['key'=>..]).
	 * @return Array|Bool The matched identity array, or false on failure.
	 */
	public function checkSecretArr( Array $loginDataArr ): Array|Bool;

	/**
	 * Returns the most recent error message, if any.
	 *
	 * @return String The last error message.
	 */
	public function finalErrorText(): String;
}
