<?php

namespace nyu\Tools;

class ClientReg extends Registry{
	
	protected $userHost;
	protected $usrAgent;
	protected $clSystem;
	protected $clDevice;
	protected $language;
	protected $humanSys;

	/**
	 * Builds the client registry from the server values registry.
	 *
	 * @param ServerReg $serverVals Registry of server values to derive client info from.
	 * @param Bool      $lowCaseKey Whether to store keys in lowercase instead of uppercase.
	 *
	 * @todo Should extract client-specific values (agent, device, language, etc.) instead of passing server values through unchanged.
	 */
	function __construct( ServerReg $serverVals, Bool $lowCaseKey = true ){
		
		//$this->clientVals( $serverVals );
		// Should read all values for client exposition,
		// essentially from server Registry values

		parent::__construct( $serverVals->readLines(), $lowCaseKey );
	}
}
