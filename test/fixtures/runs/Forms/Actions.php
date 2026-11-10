<?php

// Test-only application : actions that answer with the inputs they were
// given, each with or without a definition of its inputs in input/.

namespace nyu\Runs\Forms;

use \nyu\App\Actionset;

class Actions extends Actionset {

	/**
	 * Answers with its inputs. Declared first, so it is also the default
	 * action; input/send.json defines its fields.
	 */
	public function transmit(): Void {
		$this->statusCode = 200;
		$this->replyValue = $this->inputsRgst->readLinesArray();
	}

	/**
	 * Answers with its inputs; no file defines them.
	 */
	public function freeForm(): Void {
		$this->statusCode = 200;
		$this->replyValue = $this->inputsRgst->readLinesArray();
	}

	/**
	 * Answers with its inputs; input/need.json requires some of its fields.
	 */
	public function needForm(): Void {
		$this->statusCode = 200;
		$this->replyValue = $this->inputsRgst->readLinesArray();
	}

	/**
	 * Answers with its inputs; input/wrong.json names a format that does not exist.
	 */
	public function noFormat(): Void {
		$this->statusCode = 200;
		$this->replyValue = $this->inputsRgst->readLinesArray();
	}

	/**
	 * Answers with its inputs; input/broke.json is not a definition.
	 */
	public function badInput(): Void {
		$this->statusCode = 200;
		$this->replyValue = $this->inputsRgst->readLinesArray();
	}
}
