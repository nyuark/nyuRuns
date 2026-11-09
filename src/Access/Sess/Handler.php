<?php

namespace nyu\Access\Sess;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;

/**
 * Contract for a session-carrier strategy: extracts and verifies a
 * credential from the live request (a header, a bearer token, ...),
 * optionally delegating the actual credential check to an Auth\Handler.
 */
interface Handler{

	/**
	 * Prepares the strategy with its named config block and, for
	 * strategies that delegate credential checking, an Auth handler.
	 *
	 * @param Array      $configValues Strategy-specific configuration values.
	 * @param ?AuthHndlr $authStrategy Credential-verification strategy to delegate to, if any.
	 */
	public function __construct( Array $configValues, ?AuthHndlr $authStrategy = null );

	/**
	 * Extracts and verifies the credential carried by the given request.
	 *
	 * @param Request $requstObject The shared, already-parsed HTTP request.
	 * @return Array|Bool The verified identity array, or false on failure.
	 */
	public function verifyRqstList( Request $requstObject ): Array|Bool;

	/**
	 * Issues a new token for the given identity, for token-based
	 * strategies (e.g. JWT). Not meaningful for header/credential-only
	 * strategies.
	 *
	 * @param Array $identtyArray The identity to encode into the token.
	 * @return String The issued token.
	 */
	public function issueTokenText( Array $identtyArray ): String;

	/**
	 * Returns the most recent error message, if any.
	 *
	 * @return String The last error message.
	 */
	public function finalErrorText(): String;
}
