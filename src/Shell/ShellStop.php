<?php

namespace nyu\Shell;

/**
 * Ends the running command, telling why. Its code is the exit code of the
 * command line (see Command::EXITFAIL and the like).
 */
class ShellStop extends \RuntimeException{
}
