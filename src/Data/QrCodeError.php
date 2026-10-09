<?php

namespace nyu\Data;

/**
 * A QR code that cannot be made: a text too long for the largest version
 * made, an unknown level of error correction, or a size that is not one.
 */
class QrCodeError extends \RuntimeException {
}
