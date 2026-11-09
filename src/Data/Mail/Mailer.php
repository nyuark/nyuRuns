<?php

namespace nyu\Data\Mail;

use \nyu\Tools\Logger;

/**
 * Sends mails through the accounts of the 'mailer' config (conf/mailer.json).
 * Each account is a block of settings; the first one is the default. Its
 * type says how it sends: 'native' (the framework's own SMTP client, text
 * and HTML, no attachment), 'phmail' (the PHPMailer library, when it is
 * installed) or 'tofile' (writes each mail as a file and sends nothing).
 * When the account has a 'copy' address, every mail sent is sent again,
 * separately and as it is, to that address.
 */
class Mailer {

	// Driver class of each account type
	const TYPE_LIST = Array('native'=>'Native', 'phmail'=>'PHMail', 'tofile'=>'ToFile');
	// The settings of an account
	const KEYS_LIST = Array('type', 'host', 'port', 'user', 'pass', 'from', 'name', 'wait', 'fold', 'copy');

	// The mailer of each account already asked for, by name in lower case
	protected static $mailerList = Array();

	protected $accountNme;
	protected $setupArray;
	protected $drvrObject;

	/**
	 * Builds the mailer of an account.
	 *
	 * @param String $accountLabel Name of the account, in lower case; '' for the default one.
	 * @param Array  $accontConfig The settings of the account (see acntSetup).
	 *
	 * @throws MailFailure When the type is unknown, the account has no sender, or its driver cannot work.
	 */
	public function __construct( String $accountLabel, Array $accontConfig ){
		$this->accountNme = $accountLabel;
		$this->setupArray = $accontConfig;
		$typeKey = strtolower($accontConfig['type'] ?? '');
		if( !isset(self::TYPE_LIST[$typeKey]) ){
			throw new MailFailure('Unknown mail type: '.json_encode($accontConfig['type'] ?? ''));
		}
		if( ($accontConfig['from'] ?? '')=='' ){
			throw new MailFailure('The mail account '.json_encode($accountLabel).' has no sender address');
		}
		Message::checkEmailAddr($accontConfig['from']);
		$drvrCls = '\\nyu\\Data\\Mail\\Drivers\\'.self::TYPE_LIST[$typeKey];
		$this->drvrObject = new $drvrCls($accontConfig);
	}

	/**
	 * Returns the mailer of a configured account, built the first time it
	 * is asked for.
	 *
	 * @param String $accountLabel Name of the account, whatever its case; '' for the default one.
	 * @return Mailer The mailer of that account.
	 *
	 * @throws MailFailure When no account is configured, none has that name, or its settings cannot work.
	 */
	public static function retrieveMailer( String $accountLabel='' ): Mailer {
		$acntSet = self::acntSetupArray($accountLabel);
		$nameKey = ($accountLabel=='') ? '' : strtolower($accountLabel);
		if( !isset(self::$mailerList[$nameKey]) ){
			self::$mailerList[$nameKey] = new Mailer($nameKey, $acntSet);
		}
		return self::$mailerList[$nameKey];
	}

	/**
	 * Forgets the mailers already built, for a configuration that changed.
	 */
	public static function clearMailCache(): Void {
		self::$mailerList = Array();
	}

	/**
	 * Reads the settings of a configured account. The 'mailer' config
	 * holds either a single block of settings, or several blocks under
	 * names: the first block is then the default account.
	 *
	 * @param String $accountLabel Name of the account, whatever its case; '' for the default one.
	 * @return Array The settings of KEYSLIST, '' for those left out.
	 *
	 * @throws MailFailure When no account is configured, or none has that name.
	 */
	public static function acntSetupArray( String $accountLabel='' ): Array {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$allAcnt = $nyuConf ? $nyuConf->readFileDetail('mailer') : null;
		if( !is_array($allAcnt) || empty($allAcnt) ){
			throw new MailFailure('No mail account is configured');
		}
		$allAcnt = array_change_key_case($allAcnt, CASE_LOWER);
		if( array_key_exists('type', $allAcnt) ){
			// A single block of settings: it has no name, and is the default
			if( $accountLabel!='' ){
				throw new MailFailure('Unknown mail account: '.json_encode($accountLabel));
			}
			$acntSet = $allAcnt;
		}else{
			$nameKey = ($accountLabel=='') ? strval(array_key_first($allAcnt)) : strtolower($accountLabel);
			if( !isset($allAcnt[$nameKey]) || !is_array($allAcnt[$nameKey]) ){
				throw new MailFailure('Unknown mail account: '.json_encode($accountLabel));
			}
			$acntSet = array_change_key_case($allAcnt[$nameKey], CASE_LOWER);
		}
		$dfltSet = array_fill_keys(self::KEYS_LIST, '');
		return array_merge( $dfltSet, array_map('strval', array_filter(array_intersect_key($acntSet, $dfltSet), 'is_scalar')) );
	}

	/**
	 * Sends a mail through the account, then its copy when the account has
	 * a 'copy' address. A mail the server refuses is logged on 'runner' as
	 * a warning, and answered false; a copy that fails is logged only.
	 *
	 * @param Array|String $recipientLst Addresses of the recipients: a list, or one text where they are separated by commas.
	 * @param String       $emailSubject Subject of the mail.
	 * @param String       $mssgBodyText Plain text of the mail.
	 * @param ?String      $htmlMssgText HTML version of the mail, null for a plain text mail.
	 * @param Array        $attachedList Paths of the files attached; a 'native' account sends none.
	 * @return Bool Whether the mail was sent (written, for a 'tofile' account).
	 *
	 * @throws MailFailure When an address is not valid, the subject holds a line break, an attachment cannot be read or the account cannot send attachments.
	 */
	public function deliverEmlFlag( Array|String $recipientLst, String $emailSubject, String $mssgBodyText, ?String $htmlMssgText=null, Array $attachedList=Array() ): Bool {
		$mailObj = new Message( $this->setupArray['from'], $this->setupArray['name'], Message::splitAddresses($recipientLst), $emailSubject, $mssgBodyText, $htmlMssgText, $attachedList );
		$copyLst = Array();
		foreach( Message::splitAddresses($this->setupArray['copy']) as $cpyAdr ){
			$copyLst[] = Message::checkEmailAddr($cpyAdr);
		}
		$channel = Logger::retrieveLogger('runner');
		$acntTxt = json_encode($this->accountNme=='' ? 'default' : $this->accountNme);
		$failTxt = $this->drvrObject->deliverMessage( $mailObj, $mailObj->listRecipients() );
		if( $failTxt!==null ){
			$channel->warning('Mail not sent to '.json_encode($mailObj->listRecipients()).' through account '.$acntTxt.': '.$failTxt.Logger::getActorSuffix());
			return false;
		}
		$channel->info('Mail sent to '.json_encode($mailObj->listRecipients()).' through account '.$acntTxt.Logger::getActorSuffix());
		if( !empty($copyLst) ){
			$failTxt = $this->drvrObject->deliverMessage( $mailObj, $copyLst );
			if( $failTxt!==null ){
				$channel->warning('Copy of a mail not sent to '.json_encode($copyLst).' through account '.$acntTxt.': '.$failTxt);
			}else{
				$channel->info('Copy of a mail sent to '.json_encode($copyLst).' through account '.$acntTxt);
			}
		}
		return true;
	}
}
