<?php

namespace nyu\Access;

use nyu\Data\Mail\MailFailure;
use nyu\Data\Mail\Mailer;
use nyu\Data\Mail\Template;
use nyu\Tools\Language;
use nyu\Tools\Logger;

/**
 * Sends the mails of the access flows: the code that activates an account
 * ('activate'), confirms a sign-up ('register') or sets a new password
 * ('password'), the code of a login as a second factor ('passcode'), the
 * notice that a sign-up asked for a name ('usrtaken') or an address
 * already taken ('emltaken'), and the warning to the managers that sign-ups
 * are piling up ('waitlist', with %count% and %limit%).
 *
 * It works from the block of the native userbase: 'mailAcnt' names the
 * account of conf/mailer.json the mails go through (no mail is sent
 * without it: the codes are then given in the replies, as before),
 * 'codeMins' the minutes a mailed code lasts, and 'linkTmpl' the address of
 * the front end the mail links to, where %mailKind%, %userName% and %sentCode% are
 * filled in (no link without it: the code alone is typed).
 *
 * A mail is made of two templates, <name>.txt and <name>.html (see
 * Data\Mail\Template), in one folder per language: <folder>/<language>/.
 * It is written in the language given (the one of the user it goes to),
 * else in the next of its chain (fr-ch, fr, then DEF_LANG; see
 * Tools\Language). For each language of the chain, the mails folder of the
 * application is looked into first, then conf/mails: a language the
 * application lacks is taken from the framework before falling back to
 * another one. Without the .html beside the .txt, the mail is plain text.
 * The first line of the .txt is the subject. %user%, %code%, %link% and
 * %mins% are filled in, HTML-encoded in the .html; what lies between [link]
 * and [/link] is only kept when there is a link.
 *
 * A mail that cannot be sent is logged (runner channel), never told to
 * the client. Sent after the reply, it does not make the reply wait,
 * where PHP can end the reply first (PHP-FPM).
 */
class AccessMail {

	// The mails a code is sent with; the code of a login ('passcode') is typed where it was asked, without a link
	const KIND_LIST = Array('activate', 'register', 'password', 'passcode');
	// The mail of a sign-up that asked for an address taken, sent to its owner
	const TAKE_NAME = 'emltaken';
	// The mail of a sign-up that asked for a name taken, with an address of no account
	const USED_NAME = 'usrtaken';
	// The mail telling the managers that sign-ups are piling up
	const WARN_NAME = 'waitlist';

	protected $accountNme;
	protected $urlTmplTxt;
	protected $codeExpiry;
	protected $folderList;
	protected $isDeferred;
	protected $mailsQueue = Array();

	/**
	 * Builds the sender from the block of the native userbase.
	 *
	 * @param Array  $configValues The block: 'mailAcnt', 'codeMins', 'linkTmpl' (see the class).
	 * @param String $appsFldrText The folder of the application, whose mails folder comes first; '' for conf/mails alone.
	 * @param Bool   $deferSending Whether the mails wait for the end of the request, after the reply.
	 */
	function __construct( Array $configValues, String $appsFldrText='', Bool $deferSending=false ){
		$this->accountNme = is_string($configValues['mailAcnt'] ?? null) ? $configValues['mailAcnt'] : '';
		$this->urlTmplTxt = is_string($configValues['linkTmpl'] ?? null) ? $configValues['linkTmpl'] : '';
		$this->codeExpiry = max( 1, intval($configValues['codeMins'] ?? Auth\UserBase::MAIL_MINS) );
		$this->folderList = Array();
		if( $appsFldrText!='' ){
			$this->folderList[] = rtrim($appsFldrText, '/\\').DS.'mails';
		}
		$this->folderList[] = TOP_DIR.DS.'conf'.DS.'mails';
		$this->isDeferred = $deferSending;
	}

	/**
	 * Builds the sender of the userbase the login uses, when its block
	 * names a mail account.
	 *
	 * @param String $appsFldrText The folder of the application, whose mails folder comes first.
	 * @param Bool   $deferSending Whether the mails wait for the end of the request, after the reply.
	 * @return ?AccessMail The sender, or null when the login does not use the native userbase or sends no mail.
	 */
	public static function fromLoginBlock( String $appsFldrText='', Bool $deferSending=false ): ?AccessMail {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$loginCfg = ($nyuConfg!==null) ? $nyuConfg->getNestedValue('ACCESS', 'LOGINSET') : null;
		if( !is_array($loginCfg) || strval($loginCfg['authWith'] ?? '')!=='nativeDb' ){
			return null;
		}
		$blockCfg = $nyuConfg->getNestedValue('ACCESS', strtoupper(strval($loginCfg['authConf'] ?? 'nativeDb')));
		if( !is_array($blockCfg) || !is_string($blockCfg['mailAcnt'] ?? null) || $blockCfg['mailAcnt']==='' ){
			return null;
		}
		return new self($blockCfg, $appsFldrText, $deferSending);
	}

	/**
	 * Tells whether self-registration is open: the 'signupOn' key of the
	 * block of the userbase the login uses, with a mail account to confirm
	 * the addresses.
	 *
	 * @return Bool Whether users may sign up.
	 */
	public static function signupsAllowed(): Bool {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$loginCfg = ($nyuConfg!==null) ? $nyuConfg->getNestedValue('ACCESS', 'LOGINSET') : null;
		if( !is_array($loginCfg) || strval($loginCfg['authWith'] ?? '')!=='nativeDb' ){
			return false;
		}
		$blockCfg = $nyuConfg->getNestedValue('ACCESS', strtoupper(strval($loginCfg['authConf'] ?? 'nativeDb')));
		return is_array($blockCfg) && ($blockCfg['signupOn'] ?? false)===true && is_string($blockCfg['mailAcnt'] ?? null) && $blockCfg['mailAcnt']!=='';
	}

	/**
	 * Sends a code to a user.
	 *
	 * @param String $mailTypeCode The mail: 'activate', 'register', 'password' or 'passcode' (never with a link).
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $emailAddress Its address.
	 * @param String  $codeAsString The code.
	 * @param ?String $givenLangTag The language of the user; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendCodeToUser( String $mailTypeCode, String $namedUserTxt, String $emailAddress, String $codeAsString, ?String $givenLangTag=null ): Bool {
		if( !in_array($mailTypeCode, self::KIND_LIST, true) ){
			return false;
		}
		$linkText = '';
		if( $this->urlTmplTxt!='' && $mailTypeCode!=='passcode' ){
			$linkText = strtr( $this->urlTmplTxt, Array('%mailKind%'=>rawurlencode($mailTypeCode), '%userName%'=>rawurlencode($namedUserTxt), '%sentCode%'=>rawurlencode($codeAsString)) );
		}
		return $this->sendsEmailFlag( $mailTypeCode, $emailAddress, Array('user'=>$namedUserTxt, 'code'=>$codeAsString, 'link'=>$linkText, 'mins'=>strval($this->codeExpiry)), $givenLangTag );
	}

	/**
	 * Tells the address a sign-up gave that the name it asked for is not
	 * available: the address is the one of no account, so nothing is told
	 * about any account but the name.
	 *
	 * @param String  $emailAddress The address given with the sign-up.
	 * @param String  $namedUserTxt The name asked for.
	 * @param ?String $givenLangTag The language of the request; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendNameExists( String $emailAddress, String $namedUserTxt, ?String $givenLangTag=null ): Bool {
		return $this->sendsEmailFlag( self::USED_NAME, $emailAddress, Array('user'=>$namedUserTxt, 'code'=>'', 'link'=>'', 'mins'=>strval($this->codeExpiry)), $givenLangTag );
	}

	/**
	 * Tells the owner of an address that a sign-up asked for it, the
	 * address being the one of its account already.
	 *
	 * @param String $emailAddress The address given with the sign-up.
	 * @param String  $namedUserTxt The name asked for.
	 * @param ?String $givenLangTag The language of the owner of the address; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendAddrExists( String $emailAddress, String $namedUserTxt, ?String $givenLangTag=null ): Bool {
		return $this->sendsEmailFlag( self::TAKE_NAME, $emailAddress, Array('user'=>$namedUserTxt, 'code'=>'', 'link'=>'', 'mins'=>strval($this->codeExpiry)), $givenLangTag );
	}

	/**
	 * Tells the managers that sign-ups are piling up, each by a mail of
	 * its own: as many are waiting, and as many are allowed before the
	 * sign-ups close.
	 *
	 * @param Array $managersList The addresses of the managers, as a list, or their languages by address.
	 * @param Int   $mailCountVal The sign-ups waiting.
	 * @param Int   $ceilingValue The most allowed at once ('maxQueue').
	 * @return Bool Whether every mail was sent, or is to be sent after the reply.
	 */
	public function sendQueueAlert( Array $managersList, Int $mailCountVal, Int $ceilingValue ): Bool {
		$isSentOk = true;
		foreach( $managersList as $adrKey=>$mgrAdr ){
			$mngrAddr = is_string($adrKey) ? $adrKey : strval($mgrAdr);
			$mngrLang = is_string($adrKey) && is_string($mgrAdr) ? $mgrAdr : null;
			$isSentOk = $this->sendsEmailFlag( self::WARN_NAME, $mngrAddr, Array('count'=>strval($mailCountVal), 'limit'=>strval($ceilingValue), 'link'=>''), $mngrLang ) && $isSentOk;
		}
		return $isSentOk;
	}

	/**
	 * Sends a mail made of its templates, now or after the reply.
	 *
	 * @param String $templateName Name of the templates.
	 * @param String $emailAddress The address.
	 * @param Array   $placeholders Values of the placeholders, by name.
	 * @param ?String $givenLangTag The language of the mail; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	protected function sendsEmailFlag( String $templateName, String $emailAddress, Array $placeholders, ?String $givenLangTag=null ): Bool {
		$mailList = $this->buildMailArray($templateName, $placeholders, $givenLangTag);
		if( $mailList===null ){
			Logger::getLoggerOfChn('runner')->error( 'Access mail '.json_encode($templateName).' not sent: its template cannot be read' );
			return false;
		}
		if( !$this->isDeferred ){
			return $this->postsEmailFlag($emailAddress, $mailList);
		}
		if( empty($this->mailsQueue) ){
			register_shutdown_function( Array($this, 'sendQueuedMail') );
		}
		$this->mailsQueue[] = Array($emailAddress, $mailList);
		return true;
	}

	/**
	 * Ends the reply, then sends the mails kept for after it. Called at
	 * the end of the request.
	 */
	public function sendQueuedMail(): Void {
		while( ob_get_level()>0 ){
			ob_end_flush();
		}
		if( function_exists('fastcgi_finish_request') ){
			fastcgi_finish_request();
		}
		foreach( $this->mailsQueue as $lateMl ){
			$this->postsEmailFlag($lateMl[0], $lateMl[1]);
		}
		$this->mailsQueue = Array();
	}

	/**
	 * Sends a mail through the account of the block.
	 *
	 * @param String $emailAddress The address.
	 * @param Array  $mailPartsLst The subject, the text and the HTML (null for none).
	 * @return Bool Whether the mail was sent.
	 */
	protected function postsEmailFlag( String $emailAddress, Array $mailPartsLst ): Bool {
		try{
			return Mailer::retrieveMailer($this->accountNme)->deliverEmlFlag($emailAddress, $mailPartsLst[0], $mailPartsLst[1], $mailPartsLst[2]);
		}catch( MailFailure $e ){
			Logger::getLoggerOfChn('runner')->warning( 'Access mail not sent through account '.json_encode($this->accountNme).': '.$e->getMessage() );
			return false;
		}
	}

	/**
	 * Makes a mail of its templates.
	 *
	 * @param String $templateName Name of the templates.
	 * @param Array   $placeholders Values of the placeholders, by name.
	 * @param ?String $givenLangTag The language of the mail; the default one when not given.
	 * @return ?Array The subject, the text and the HTML (null for none), or null when the text template cannot be read.
	 */
	protected function buildMailArray( String $templateName, Array $placeholders, ?String $givenLangTag=null ): ?Array {
		$langTool = new Language();
		$textPath = null;
		foreach( $langTool->getLngChainLst($langTool->findPersonLang($givenLangTag, null)) as $oneTag ){
			foreach( $this->folderList as $dirPth ){
				$textPath = $textPath ?? $langTool->findTmplInLang($dirPth, $templateName.'.txt', Array($oneTag));
			}
		}
		if( $textPath===null ){
			return null;
		}
		// The HTML is the one beside the text : an application giving its own text without HTML sends text alone
		$htmlPath = dirname($textPath).DS.$templateName.'.html';
		try{
			$mailTmpl = new Template( $textPath, (is_file($htmlPath) && is_readable($htmlPath)) ? $htmlPath : null );
		}catch( \RuntimeException $e ){
			return null;
		}
		return Array( $mailTmpl->getSubjectText($placeholders), $mailTmpl->simpleBodyText($placeholders), $mailTmpl->getHtmlVersion($placeholders) );
	}
}
