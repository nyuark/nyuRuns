<?php

namespace nyu\Runs\Hooked;

use nyu\App\Actionset;

/**
 * Test application for the hook of the actionsets: setupForAction() writes its
 * step before the action does, and refuses the request when the input
 * 'refuse' is 'yes' (test/unit/ActionHookTest.php).
 */
class Actions extends Actionset {

	// Steps run, in their order
	protected $stepsTaken = Array();

	/**
	 * Writes its step; refuses with a 403 when asked to.
	 */
	protected function setupForAction(): Void {
		$this->stepsTaken[] = 'setupForAction';
		if( $this->inptRgstry->readFileDetail('refuse')==='yes' ){
			$this->replyValue = $this->stepsTaken;
			$this->stopThisAction(403, 'Refused before the action');
		}
	}

	/**
	 * Writes its step and replies with the steps run.
	 */
	public function sequence(): Void {
		$this->stepsTaken[] = 'ordered';
		$this->statusCode = 200;
		$this->replyValue = $this->stepsTaken;
	}

	/**
	 * Replies with what the actionset was given: its context and the properties filled from it.
	 */
	public function context(): Void {
		$this->statusCode = 200;
		$this->replyValue = Array(
			'actnName'=>$this->actionName,
			'actnOptn'=>$this->runContext->actnOption,
			'appIdent'=>$this->runContext->appIdentty,
			'appFoldr'=>$this->runContext->appsFolder,
			'inputReg'=>$this->inptRgstry===$this->runContext->inptRgstry,
			'modStack'=>$this->modelStack===$this->runContext->modelStack
		);
	}
}
