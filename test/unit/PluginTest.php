<?php

// Access plug-ins : a strategy the framework does not know is resolved as a
// class of runs/Shared, when the 'plugin' block of the ACCESS config lists
// its short name. Each case runs in a child process (test/lib/plugChild.php)
// whose applications folder is test/fixtures/runs.

require __DIR__.'/../lib/harness.php';

/**
 * Resolves a strategy in a child process.
 *
 * @param String $kindOfPlug 'auth' for a credential check, 'sess' for a session carrier.
 * @param String $shortNamed Short name to resolve.
 * @param String $listedCods Short names listed in the 'plugin' block, comma separated.
 * @return String The resolved class, or 'null'.
 */
function plugClss( String $kindOfPlug, String $shortNamed, String $listedCods='' ): String {
	return trim( runScript( Array(TST_DIR.DS.'lib'.DS.'plugChild.php', $kindOfPlug, $shortNamed, $listedCods) )['text'] );
}

// Listed plug-ins of both kinds
checkSame('a listed credential check is resolved in runs/Shared', plugClss('auth', 'sample', 'sample'), '\nyu\Runs\Shared\SampleAuth');
checkSame('a listed session carrier is resolved in runs/Shared',  plugClss('sess', 'carier', 'carier'), '\nyu\Runs\Shared\CarierSess');
checkSame('listed among others',                                  plugClss('auth', 'sample', 'wrongi,sample,carier'), '\nyu\Runs\Shared\SampleAuth');

// Not listed, or listed for the other kind of class
checkSame('a plug-in that is not listed is unknown',              plugClss('auth', 'sample'), 'null');
checkSame('listing another name does not help',                   plugClss('auth', 'sample', 'carier'), 'null');
checkSame('a carrier is not a credential check',                  plugClss('auth', 'carier', 'carier'), 'null');
checkSame('a credential check is not a carrier',                  plugClss('sess', 'sample', 'sample'), 'null');

// The framework's own strategies cannot be replaced
checkSame('a built-in credential check stays the framework\'s',   plugClss('auth', 'native', 'native'), '\nyu\Access\Auth\Native');
checkSame('a built-in session carrier stays the framework\'s',    plugClss('sess', 'jtoken', 'jtoken'), '\nyu\Access\Sess\JWT');
checkSame('built-in names need no listing',                       plugClss('auth', 'htpass'), '\nyu\Access\Auth\PassList');

// Short names that are not six lower-case letters or digits
checkSame('upper case is refused',                                plugClss('auth', 'Sample', 'Sample'), 'null');
checkSame('five characters are refused',                          plugClss('auth', 'sampl', 'sampl'), 'null');
checkSame('seven characters are refused',                         plugClss('auth', 'samples', 'samples'), 'null');
checkSame('a path is refused',                                    plugClss('auth', '../sam', '../sam'), 'null');
checkSame('a leading digit is refused',                           plugClss('auth', '1ample', '1ample'), 'null');

// Listed, but not usable
checkSame('no file in runs/Shared',                               plugClss('auth', 'nofile', 'nofile'), 'null');
checkSame('a class that does not implement the interface',        plugClss('auth', 'wrongi', 'wrongi'), 'null');
checkSame('a file that declares another class',                   plugClss('auth', 'noclss', 'noclss'), 'null');
checkSame('a file that does not parse',                           plugClss('auth', 'broken', 'broken'), 'null');

endChecks();
