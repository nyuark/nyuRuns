<?php

// Test-only access plug-in : a session carrier that test/unit/PluginTest.php resolves as "carier".

namespace nyu\Runs\Shared;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;
use nyu\Access\Sess\Handler as SessHndlr;

class CarierSess implements SessHndlr{

	/**
	 * @param Array      $configValues Unused.
	 * @param ?AuthHndlr $authStrategy Unused.
	 */
	public function __construct( Array $configValues, ?AuthHndlr $authStrategy = null ){
	}

	/**
	 * Identifies whoever sends an X-Carier header, by its value.
	 *
	 * @param Request $givenRequest The shared, already-parsed HTTP request.
	 * @return Array|Bool The identity array, or false on failure.
	 */
	public function verifyRqstList( Request $givenRequest ): Array|Bool {
		$headVal = $givenRequest->retrieveHeader('X-Carier');
		return ( $headVal!==null && $headVal!='' ) ? Array('userName'=>$headVal) : false;
	}

	/**
	 * @param Array $identityList Unused.
	 * @return String Always an empty string.
	 */
	public function issueTokenText( Array $identityList ): String {
		return '';
	}

	/**
	 * @return String The last error message.
	 */
	public function finalErrorText(): String {
		return 'Missing X-Carier header';
	}
}
