<?php

namespace nyu\Runs\Hooked;

use nyu\App\Actionset;

/**
 * Test application for the hook of the actionsets: beforeAct() writes its
 * step before the action does, and refuses the request when the input
 * 'refuse' is 'yes' (test/unit/ActionHookTest.php).
 */
class Actions extends Actionset {

	// Steps run, in their order
	protected $stepList = Array();

	/**
	 * Writes its step; refuses with a 403 when asked to.
	 */
	protected function beforeAct(): Void {
		$this->stepList[] = 'beforeAct';
		if( $this->inputReg->readValue('refuse')==='yes' ){
			$this->asOutput = $this->stepList;
			$this->endAction(403, 'Refused before the action');
		}
	}

	/**
	 * Writes its step and replies with the steps run.
	 */
	public function ordered(): Void {
		$this->stepList[] = 'ordered';
		$this->statCode = 200;
		$this->asOutput = $this->stepList;
	}

	/**
	 * Replies with what the actionset was given: its context and the properties filled from it.
	 */
	public function context(): Void {
		$this->statCode = 200;
		$this->asOutput = Array(
			'actnName'=>$this->actnName,
			'actnOptn'=>$this->runCntxt->actnOptn,
			'appIdent'=>$this->runCntxt->appIdent,
			'appFoldr'=>$this->runCntxt->appFoldr,
			'inputReg'=>$this->inputReg===$this->runCntxt->inputReg,
			'modStack'=>$this->modStack===$this->runCntxt->modStack
		);
	}
}
