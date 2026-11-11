<?php

// Access plug-ins : a strategy the framework does not know is resolved as a
// class of runs/Shared, when the 'plugin' block of the ACCESS config lists
// its short name. Each case runs in a child process (test/lib/plugChild.php)
// whose applications folder is test/fixtures/runs.

require __DIR__.'/../lib/harness.php';

/**
 * Resolves a strategy in a child process.
 *
 * @param String $kindOfPlug 'auth' for a credential check, 'sess' for a carrier.
 * @param String $shortNamed Short name to resolve.
 * @param String $listedCods Short names listed in the 'plugin' block, comma separated.
 * @return String The resolved class, or 'null'.
 */
function plugClss( String $kindOfPlug, String $shortNamed, String $listedCods='' ): String {
	return trim( runScriptArray( Array(TST_DIR.DS.'lib'.DS.'plugChild.php', $kindOfPlug, $shortNamed, $listedCods) )['text'] );
}

// Listed plug-ins of both kinds
checkIdentical('a listed credential check is resolved in runs/Shared', plugClss('auth', 'sample', 'sample'), '\nyu\Runs\Shared\SampleAuth');
checkIdentical('a listed carrier is resolved in runs/Shared',  plugClss('sess', 'carier', 'carier'), '\nyu\Runs\Shared\CarierCarr');
checkIdentical('listed among others',                                  plugClss('auth', 'sample', 'wrongi,sample,carier'), '\nyu\Runs\Shared\SampleAuth');

// Not listed, or listed for the other kind of class
checkIdentical('a plug-in that is not listed is unknown',              plugClss('auth', 'sample'), 'null');
checkIdentical('listing another name does not help',                   plugClss('auth', 'sample', 'carier'), 'null');
checkIdentical('a carrier is not a credential check',                  plugClss('auth', 'carier', 'carier'), 'null');
checkIdentical('a credential check is not a carrier',                  plugClss('sess', 'sample', 'sample'), 'null');

// The framework's own strategies cannot be replaced
checkIdentical('a built-in credential check stays the framework\'s',   plugClss('auth', 'nativeDb', 'nativeDb'), '\nyu\Access\Auth\Native');
checkIdentical('a built-in carrier stays the framework\'s',    plugClss('sess', 'jwtToken', 'jwtToken'), '\nyu\Access\Carr\JWT');
checkIdentical('built-in names need no listing',                       plugClss('auth', 'htpasswd'), '\nyu\Access\Auth\PassList');

// Short names that are not six lower-case letters or digits
checkIdentical('upper case is refused',                                plugClss('auth', 'Sample', 'Sample'), 'null');
checkIdentical('five characters are refused',                          plugClss('auth', 'sampl', 'sampl'), 'null');
checkIdentical('seven characters are refused',                         plugClss('auth', 'samples', 'samples'), 'null');
checkIdentical('a path is refused',                                    plugClss('auth', '../sam', '../sam'), 'null');
checkIdentical('a leading digit is refused',                           plugClss('auth', '1ample', '1ample'), 'null');

// Listed, but not usable
checkIdentical('no file in runs/Shared',                               plugClss('auth', 'nofile', 'nofile'), 'null');
checkIdentical('a class that does not implement the interface',        plugClss('auth', 'wrongi', 'wrongi'), 'null');
checkIdentical('a file that declares another class',                   plugClss('auth', 'noclss', 'noclss'), 'null');
checkIdentical('a file that does not parse',                           plugClss('auth', 'broken', 'broken'), 'null');

finishChecking();
