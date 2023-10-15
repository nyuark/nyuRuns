<?php

// Test-only access plug-in : a session carrier that test/unit/PluginTest.php resolves as "carier".

namespace nyu\Runs\Shared;

use nyu\Tools\Request;
use nyu\Access\Auth\Handler as AuthHndlr;
use nyu\Access\Sess\Handler as SessHndlr;

class CarierSess implements SessHndlr{

	/**
	 * @param Array      $confValues Unused.
	 * @param ?AuthHndlr $authHandlr Unused.
	 */
	public function __construct( Array $confValues, ?AuthHndlr $authHandlr = null ){
	}

	/**
	 * Identifies whoever sends an X-Carier header, by its value.
	 *
	 * @param Request $requestObj The shared, already-parsed HTTP request.
	 * @return Array|Bool The identity array, or false on failure.
	 */
	public function verifyReq( Request $requestObj ): Array|Bool {
		$headVal = $requestObj->getHeader('X-Carier');
		return ( $headVal!==null && $headVal!='' ) ? Array('user'=>$headVal) : false;
	}

	/**
	 * @param Array $identArray Unused.
	 * @return String Always an empty string.
	 */
	public function issueTokn( Array $identArray ): String {
		return '';
	}

	/**
	 * @return String The last error message.
	 */
	public function lastError(): String {
		return 'Missing X-Carier header';
	}
}
