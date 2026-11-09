<?php

namespace nyu\Runs\Mounted;

use nyu\App\Actionset;

/**
 * Test application declaring its own routes (routes.rte): listed and the
 * root path /rooted are reached through them; unlisted is in no route, so
 * this closed Run never runs it (test/unit/RouterTest.php).
 */
class Actions extends Actionset {

	/**
	 * Replies with its name.
	 */
	public function listed(): Void {
		$this->statCode = 200;
		$this->asOutput = 'mounted/listed';
	}

	/**
	 * Replies with its name; no route of the Run lists it.
	 */
	public function unlisted(): Void {
		$this->statCode = 200;
		$this->asOutput = 'mounted/unlisted';
	}

	/**
	 * Replies with the link to the route its option names (see routeLink), its $id given as 7.
	 *
	 * @param String $routeShape The path of the route: as this Run declares it, or its final path.
	 */
	public function linking( String $routeShape ): Void {
		$this->statCode = 200;
		$this->asOutput = $this->routeLink($routeShape, Array('id'=>'7'));
	}

	/**
	 * Replies with its name; its route is from the root of the site.
	 */
	public function rooted(): Void {
		$this->statCode = 200;
		$this->asOutput = 'mounted/rooted';
	}
}
