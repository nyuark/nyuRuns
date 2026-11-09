<?php

namespace nyu\Data\Mail;

/**
 * A mail that cannot be sent as it is asked for: an unknown account or
 * type, an account without a sender, a library that is not installed, or
 * an address, subject or attachment refused. A server that refuses the
 * mail is not one: deliverEmlFlag() then answers false.
 */
class MailFailure extends \RuntimeException {
}
