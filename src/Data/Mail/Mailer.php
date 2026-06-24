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
	const TYPELIST = Array('native'=>'Native', 'phmail'=>'PHMail', 'tofile'=>'ToFile');
	// The settings of an account
	const KEYSLIST = Array('type', 'host', 'port', 'user', 'pass', 'from', 'name', 'wait', 'fold', 'copy');

	// The mailer of each account already asked for, by name in lower case
	protected static $mailList = Array();

	protected $acntName;
	protected $setupArr;
	protected $drvrObjt;

	/**
	 * Builds the mailer of an account.
	 *
	 * @param String $accountKey Name of the account, in lower case; '' for the default one.
	 * @param Array  $acntConfig The settings of the account (see acntSetup).
	 *
	 * @throws MailFailure When the type is unknown, the account has no sender, or its driver cannot work.
	 */
	public function __construct( String $accountKey, Array $acntConfig ){
		$this->acntName = $accountKey;
		$this->setupArr = $acntConfig;
		$typeKey = strtolower($acntConfig['type'] ?? '');
		if( !isset(self::TYPELIST[$typeKey]) ){
			throw new MailFailure('Unknown mail type: '.json_encode($acntConfig['type'] ?? ''));
		}
		if( ($acntConfig['from'] ?? '')=='' ){
			throw new MailFailure('The mail account '.json_encode($accountKey).' has no sender address');
		}
		Message::checkAddr($acntConfig['from']);
		$drvClss = '\\nyu\\Data\\Mail\\Drivers\\'.self::TYPELIST[$typeKey];
		$this->drvrObjt = new $drvClss($acntConfig);
	}

	/**
	 * Returns the mailer of a configured account, built the first time it
	 * is asked for.
	 *
	 * @param String $accountKey Name of the account, whatever its case; '' for the default one.
	 * @return Mailer The mailer of that account.
	 *
	 * @throws MailFailure When no account is configured, none has that name, or its settings cannot work.
	 */
	public static function getMailer( String $accountKey='' ): Mailer {
		$acntSet = self::acntSetup($accountKey);
		$nameKey = ($accountKey=='') ? '' : strtolower($accountKey);
		if( !isset(self::$mailList[$nameKey]) ){
			self::$mailList[$nameKey] = new Mailer($nameKey, $acntSet);
		}
		return self::$mailList[$nameKey];
	}

	/**
	 * Forgets the mailers already built, for a configuration that changed.
	 */
	public static function dropCache(): Void {
		self::$mailList = Array();
	}

	/**
	 * Reads the settings of a configured account. The 'mailer' config
	 * holds either a single block of settings, or several blocks under
	 * names: the first block is then the default account.
	 *
	 * @param String $accountKey Name of the account, whatever its case; '' for the default one.
	 * @return Array The settings of KEYSLIST, '' for those left out.
	 *
	 * @throws MailFailure When no account is configured, or none has that name.
	 */
	public static function acntSetup( String $accountKey='' ): Array {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$allAcnt = $nyuConf ? $nyuConf->readValue('mailer') : null;
		if( !is_array($allAcnt) || empty($allAcnt) ){
			throw new MailFailure('No mail account is configured');
		}
		$allAcnt = array_change_key_case($allAcnt, CASE_LOWER);
		if( array_key_exists('type', $allAcnt) ){
			// A single block of settings: it has no name, and is the default
			if( $accountKey!='' ){
				throw new MailFailure('Unknown mail account: '.json_encode($accountKey));
			}
			$acntSet = $allAcnt;
		}else{
			$nameKey = ($accountKey=='') ? strval(array_key_first($allAcnt)) : strtolower($accountKey);
			if( !isset($allAcnt[$nameKey]) || !is_array($allAcnt[$nameKey]) ){
				throw new MailFailure('Unknown mail account: '.json_encode($accountKey));
			}
			$acntSet = array_change_key_case($allAcnt[$nameKey], CASE_LOWER);
		}
		$dfltSet = array_fill_keys(self::KEYSLIST, '');
		return array_merge( $dfltSet, array_map('strval', array_filter(array_intersect_key($acntSet, $dfltSet), 'is_scalar')) );
	}

	/**
	 * Sends a mail through the account, then its copy when the account has
	 * a 'copy' address. A mail the server refuses is logged on 'runner' as
	 * a warning, and answered false; a copy that fails is logged only.
	 *
	 * @param Array|String $recipients Addresses of the recipients: a list, or one text where they are separated by commas.
	 * @param String       $subjctLine Subject of the mail.
	 * @param String       $messgeBody Plain text of the mail.
	 * @param ?String      $htmlMessge HTML version of the mail, null for a plain text mail.
	 * @param Array        $attachList Paths of the files attached; a 'native' account sends none.
	 * @return Bool Whether the mail was sent (written, for a 'tofile' account).
	 *
	 * @throws MailFailure When an address is not valid, the subject holds a line break, an attachment cannot be read or the account cannot send attachments.
	 */
	public function sendEmail( Array|String $recipients, String $subjctLine, String $messgeBody, ?String $htmlMessge=null, Array $attachList=Array() ): Bool {
		$mailObj = new Message( $this->setupArr['from'], $this->setupArr['name'], Message::addrsList($recipients), $subjctLine, $messgeBody, $htmlMessge, $attachList );
		$copyLst = Array();
		foreach( Message::addrsList($this->setupArr['copy']) as $cpyAdr ){
			$copyLst[] = Message::checkAddr($cpyAdr);
		}
		$logChan = Logger::getLogger('runner');
		$acntTxt = json_encode($this->acntName=='' ? 'default' : $this->acntName);
		$failTxt = $this->drvrObjt->sendMssge( $mailObj, $mailObj->recipList() );
		if( $failTxt!==null ){
			$logChan->warning('Mail not sent to '.json_encode($mailObj->recipList()).' through account '.$acntTxt.': '.$failTxt.Logger::actorText());
			return false;
		}
		$logChan->info('Mail sent to '.json_encode($mailObj->recipList()).' through account '.$acntTxt.Logger::actorText());
		if( !empty($copyLst) ){
			$failTxt = $this->drvrObjt->sendMssge( $mailObj, $copyLst );
			if( $failTxt!==null ){
				$logChan->warning('Copy of a mail not sent to '.json_encode($copyLst).' through account '.$acntTxt.': '.$failTxt);
			}else{
				$logChan->info('Copy of a mail sent to '.json_encode($copyLst).' through account '.$acntTxt);
			}
		}
		return true;
	}
}
