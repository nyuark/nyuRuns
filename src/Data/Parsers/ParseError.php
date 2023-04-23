<?php

namespace nyu\Data\Parsers;

// Raised by a parser when a content is not valid for its format, or does
// not match the format its name announces.
class ParseError extends \RuntimeException {
}
