<?php

namespace nyu\Data\Mail\Drivers;

use \nyu\Data\Mail\Message;

/**
 * How a mail account sends: one driver per account type (see Mail\Mailer).
 */
abstract class Driver {

	protected $setupArr;

	/**
	 * Builds the driver of an account.
	 *
	 * @param Array $acntConfig The settings of the account (see Mail\Mailer::acntSetup).
	 */
	public function __construct( Array $acntConfig ){
		$this->setupArr = $acntConfig;
	}

	/**
	 * Sends a mail, as it is, to the given addresses: those of the mail
	 * itself, or those of a copy.
	 *
	 * @param Message $mailMessge The mail.
	 * @param Array   $recipients Checked addresses it is delivered to.
	 * @return ?String Null when it was sent, or why it was not.
	 *
	 * @throws \nyu\Data\Mail\MailFailure When the account cannot send this mail at all (attachments, for a 'native' account).
	 */
	abstract public function sendMssge( Message $mailMessge, Array $recipients ): ?String;

	/**
	 * Tells whether a server is this machine, the only one a mail may be
	 * sent to without encryption.
	 *
	 * @param String $serverHost Name or address of the server.
	 * @return Bool Whether it is this machine.
	 */
	public static function localHost( String $serverHost ): Bool {
		return in_array( strtolower(trim($serverHost, '[] ')), Array('localhost', '127.0.0.1', '::1'), true );
	}
}
