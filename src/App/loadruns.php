<?php

// Registers the loader of the classes of the Runs (nyu\Runs\..., see nyu\App\RunLoader).
// Composer includes this file once its own loader is registered (autoload.files), so the
// loader of the Runs is asked after it, for the classes a class map does not list.

spl_autoload_register( Array('nyu\\App\\RunLoader', 'loadsRunsClass') );
