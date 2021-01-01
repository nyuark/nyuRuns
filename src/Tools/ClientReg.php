<?php

namespace nyu\Tools;

class ClientReg extends Registry{
	
	protected $userHost;
	protected $usrAgent;
	protected $clSystem;
	protected $clDevice;
	protected $language;
	protected $humanSys;

	function __construct( ServerReg $serverVals, Bool $lowCaseKey = true ){
		
		//$this->clientVals( $serverVals );
		// Should read all values for client exposition,
		// essentially from server Registry values

		parent::__construct( $serverVals, $lowCaseKey );
	}
}

?> 