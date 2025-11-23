<?php

namespace nyu\Data\Writers;

/**
 * A content that a writer cannot write: of a shape it does not take, or
 * holding what its format has no way to say.
 */
class WriteError extends \RuntimeException {
}
