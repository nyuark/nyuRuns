<?php

namespace nyu\Data\Mail\Drivers;

use \nyu\Data\Mail\Message;

/**
 * How a mail account sends: one driver per account type (see Mail\Mailer).
 */
abstract class Driver {

	protected $setupArray;

	/**
	 * Builds the driver of an account.
	 *
	 * @param Array $acctSetupLst The settings of the account (see Mail\Mailer::readAccountCfg).
	 */
	public function __construct( Array $acctSetupLst ){
		$this->setupArray = $acctSetupLst;
	}

	/**
	 * Sends a mail, as it is, to the given addresses: those of the mail
	 * itself, or those of a copy.
	 *
	 * @param Message $emailMessage The mail.
	 * @param Array   $recipientLst Checked addresses it is delivered to.
	 * @return ?String Null when it was sent, or why it was not.
	 *
	 * @throws \nyu\Data\Mail\MailFailure When the account cannot send this mail at all (attachments, for a 'selfSmtp' account).
	 */
	abstract public function sendRawMessage( Message $emailMessage, Array $recipientLst ): ?String;

	/**
	 * Tells whether a server is this machine, the only one a mail may be
	 * sent to without encryption.
	 *
	 * @param String $databaseHost Name or address of the server.
	 * @return Bool Whether it is this machine.
	 */
	public static function isLocalMachine( String $databaseHost ): Bool {
		return in_array( strtolower(trim($databaseHost, '[] ')), Array('localhost', '127.0.0.1', '::1'), true );
	}
}
