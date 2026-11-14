<?php

namespace nyu\Data\Mail;

use \nyu\Tools\Logger;

/**
 * Sends mails through the accounts of the 'mailer' config (conf/mailer.json).
 * Each account is a block of settings; the first one is the default. Its
 * sendWith says how it sends: 'selfSmtp' (the framework's own SMTP client, text
 * and HTML, no attachment), 'phpMailr' (the PHPMailer library, when it is
 * installed) or 'emlFiles' (writes each mail as a file and sends nothing).
 * When the account has a 'copiesTo' address, every mail sent is sent again,
 * separately and as it is, to that address.
 */
class Mailer {

	// Driver class of each account type
	const TYPE_LIST = Array('selfSmtp'=>'Native', 'phpMailr'=>'PHMail', 'emlFiles'=>'ToFile');
	// The settings of an account
	const KEYS_LIST = Array('sendWith', 'hostName', 'portNumb', 'userName', 'passWord', 'fromAddr', 'fromName', 'waitSecs', 'mailsDir', 'copiesTo');

	// The mailer of each account already asked for, by name in lower case
	protected static $mailerList = Array();

	protected $accountNme;
	protected $setupArray;
	protected $mailDriver;
	// Why the last mail was not sent, '' when it was
	protected $failReason = '';

	/**
	 * Builds the mailer of an account.
	 *
	 * @param String $accountLabel Name of the account, in lower case; '' for the default one.
	 * @param Array  $acntSetupArr The settings of the account (see acntSetup).
	 *
	 * @throws MailFailure When the type is unknown, the account has no sender, or its driver cannot work.
	 */
	public function __construct( String $accountLabel, Array $acntSetupArr ){
		$this->accountNme = $accountLabel;
		$this->setupArray = $acntSetupArr;
		// The type whatever its case, as TYPE_LIST writes it
		$typeClef = strval( array_values(preg_grep('/^'.preg_quote(strval($acntSetupArr['sendWith'] ?? ''), '/').'$/iD', array_keys(self::TYPE_LIST)))[0] ?? '' );
		if( !isset(self::TYPE_LIST[$typeClef]) ){
			throw new MailFailure('Unknown mail type: '.json_encode($acntSetupArr['sendWith'] ?? ''));
		}
		if( ($acntSetupArr['fromAddr'] ?? '')=='' ){
			throw new MailFailure('The mail account '.json_encode($accountLabel).' has no sender address');
		}
		Message::checkEmailAddr($acntSetupArr['fromAddr']);
		$drvrClss = '\\nyu\\Data\\Mail\\Drivers\\'.self::TYPE_LIST[$typeClef];
		$this->mailDriver = new $drvrClss($acntSetupArr);
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
		$acctDict = self::acntSetupArray($accountLabel);
		$nameClef = ($accountLabel=='') ? '' : strtolower($accountLabel);
		if( !isset(self::$mailerList[$nameClef]) ){
			self::$mailerList[$nameClef] = new Mailer($nameClef, $acctDict);
		}
		return self::$mailerList[$nameClef];
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
	 * @return Array The settings of KEYS_LIST, under the names it writes whatever their case in the file, '' for those left out.
	 *
	 * @throws MailFailure When no account is configured, or none has that name.
	 */
	public static function acntSetupArray( String $accountLabel='' ): Array {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$allAccnt = $nyuConfg ? $nyuConfg->readValueByKey('mailer') : null;
		if( !is_array($allAccnt) || empty($allAccnt) ){
			throw new MailFailure('No mail account is configured');
		}
		$allAccnt = array_change_key_case($allAccnt, CASE_LOWER);
		if( array_key_exists('sendwith', $allAccnt) ){
			// A single block of settings: it has no name, and is the default
			if( $accountLabel!='' ){
				throw new MailFailure('Unknown mail account: '.json_encode($accountLabel));
			}
			$acctDict = $allAccnt;
		}else{
			$nameClef = ($accountLabel=='') ? strval(array_key_first($allAccnt)) : strtolower($accountLabel);
			if( !isset($allAccnt[$nameClef]) || !is_array($allAccnt[$nameClef]) ){
				throw new MailFailure('Unknown mail account: '.json_encode($accountLabel));
			}
			$acctDict = array_change_key_case($allAccnt[$nameClef], CASE_LOWER);
		}
		$dfltDict = Array();
		foreach( self::KEYS_LIST as $keyNme ){
			$dfltDict[$keyNme] = is_scalar($acctDict[strtolower($keyNme)] ?? null) ? strval($acctDict[strtolower($keyNme)]) : '';
		}
		return $dfltDict;
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
	 * @param Array        $attachedList Paths of the files attached; a 'selfSmtp' account sends none.
	 * @return Bool Whether the mail was sent (written, for an 'emlFiles' account).
	 *
	 * @throws MailFailure When an address is not valid, the subject holds a line break, an attachment cannot be read or the account cannot send attachments.
	 */
	public function deliverEmlFlag( Array|String $recipientLst, String $emailSubject, String $mssgBodyText, ?String $htmlMssgText=null, Array $attachedList=Array() ): Bool {
		$mailInst = new Message( $this->setupArray['fromAddr'], $this->setupArray['fromName'], Message::splitAddresses($recipientLst), $emailSubject, $mssgBodyText, $htmlMssgText, $attachedList );
		$copyList = Array();
		foreach( Message::splitAddresses($this->setupArray['copiesTo']) as $cpyAdr ){
			$copyList[] = Message::checkEmailAddr($cpyAdr);
		}
		$logrInst = Logger::retrieveLogger('runner');
		$acctText = json_encode($this->accountNme=='' ? 'default' : $this->accountNme);
		$failText = $this->mailDriver->deliverMessage( $mailInst, $mailInst->listRecipients() );
		$this->failReason = strval($failText ?? '');
		if( $failText!==null ){
			$logrInst->warning('Mail not sent to '.json_encode($mailInst->listRecipients()).' through account '.$acctText.': '.$failText.Logger::getActorSuffix());
			return false;
		}
		$logrInst->info('Mail sent to '.json_encode($mailInst->listRecipients()).' through account '.$acctText.Logger::getActorSuffix());
		if( !empty($copyList) ){
			$failText = $this->mailDriver->deliverMessage( $mailInst, $copyList );
			if( $failText!==null ){
				$logrInst->warning('Copy of a mail not sent to '.json_encode($copyList).' through account '.$acctText.': '.$failText);
			}else{
				$logrInst->info('Copy of a mail sent to '.json_encode($copyList).' through account '.$acctText);
			}
		}
		return true;
	}

	/**
	 * Tells why the last mail was not sent.
	 *
	 * @return String The reason the mail server or the driver gave, '' when the last mail was sent.
	 */
	public function finalErrorText(): String {
		return $this->failReason;
	}
}
