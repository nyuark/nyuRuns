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
		$typeKey = strval( array_values(preg_grep('/^'.preg_quote(strval($acntSetupArr['sendWith'] ?? ''), '/').'$/iD', array_keys(self::TYPE_LIST)))[0] ?? '' );
		if( !isset(self::TYPE_LIST[$typeKey]) ){
			throw new MailFailure('Unknown mail type: '.json_encode($acntSetupArr['sendWith'] ?? ''));
		}
		if( ($acntSetupArr['fromAddr'] ?? '')=='' ){
			throw new MailFailure('The mail account '.json_encode($accountLabel).' has no sender address');
		}
		Message::checkEmailAddr($acntSetupArr['fromAddr']);
		$drvrCls = '\\nyu\\Data\\Mail\\Drivers\\'.self::TYPE_LIST[$typeKey];
		$this->mailDriver = new $drvrCls($acntSetupArr);
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
	 * @return Array The settings of KEYS_LIST, under the names it writes whatever their case in the file, '' for those left out.
	 *
	 * @throws MailFailure When no account is configured, or none has that name.
	 */
	public static function acntSetupArray( String $accountLabel='' ): Array {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$allAcnt = $nyuConf ? $nyuConf->readValueByKey('mailer') : null;
		if( !is_array($allAcnt) || empty($allAcnt) ){
			throw new MailFailure('No mail account is configured');
		}
		$allAcnt = array_change_key_case($allAcnt, CASE_LOWER);
		if( array_key_exists('sendwith', $allAcnt) ){
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
		$dfltSet = Array();
		foreach( self::KEYS_LIST as $keyNme ){
			$dfltSet[$keyNme] = is_scalar($acntSet[strtolower($keyNme)] ?? null) ? strval($acntSet[strtolower($keyNme)]) : '';
		}
		return $dfltSet;
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
		$mailObj = new Message( $this->setupArray['fromAddr'], $this->setupArray['fromName'], Message::splitAddresses($recipientLst), $emailSubject, $mssgBodyText, $htmlMssgText, $attachedList );
		$copyLst = Array();
		foreach( Message::splitAddresses($this->setupArray['copiesTo']) as $cpyAdr ){
			$copyLst[] = Message::checkEmailAddr($cpyAdr);
		}
		$channel = Logger::retrieveLogger('runner');
		$acntTxt = json_encode($this->accountNme=='' ? 'default' : $this->accountNme);
		$failTxt = $this->mailDriver->deliverMessage( $mailObj, $mailObj->listRecipients() );
		if( $failTxt!==null ){
			$channel->warning('Mail not sent to '.json_encode($mailObj->listRecipients()).' through account '.$acntTxt.': '.$failTxt.Logger::getActorSuffix());
			return false;
		}
		$channel->info('Mail sent to '.json_encode($mailObj->listRecipients()).' through account '.$acntTxt.Logger::getActorSuffix());
		if( !empty($copyLst) ){
			$failTxt = $this->mailDriver->deliverMessage( $mailObj, $copyLst );
			if( $failTxt!==null ){
				$channel->warning('Copy of a mail not sent to '.json_encode($copyLst).' through account '.$acntTxt.': '.$failTxt);
			}else{
				$channel->info('Copy of a mail sent to '.json_encode($copyLst).' through account '.$acntTxt);
			}
		}
		return true;
	}
}
