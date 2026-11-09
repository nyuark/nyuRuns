<?php

namespace nyu\Access;

use nyu\Data\Mail\MailFailure;
use nyu\Data\Mail\Mailer;
use nyu\Data\Mail\Template;
use nyu\Tools\Language;
use nyu\Tools\Logger;

/**
 * Sends the mails of the access flows: the code that activates an account
 * ('actvte'), confirms a sign-up ('signup') or sets a new password
 * ('pwdset'), the code of a login as a second factor ('lgcode'), the
 * notice that a sign-up asked for a name ('nmused') or an address
 * already taken ('taken'), and the warning to the managers that sign-ups
 * are piling up ('onhold', with %count% and %limit%).
 *
 * It works from the block of the native userbase: 'mailAcnt' names the
 * account of conf/mailer.json the mails go through (no mail is sent
 * without it: the codes are then given in the replies, as before),
 * 'codeMins' the minutes a mailed code lasts, and 'linkTmpl' the address of
 * the front end the mail links to, where %kind%, %user% and %code% are
 * filled in (no link without it: the code alone is typed).
 *
 * A mail is made of two templates, <name>.txt and <name>.html (see
 * Data\Mail\Template), in one folder per language: <folder>/<language>/.
 * It is written in the language given (the one of the user it goes to),
 * else in the next of its chain (fr-ch, fr, then LNG_DFLT; see
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

	// The mails a code is sent with; the code of a login ('lgcode') is typed where it was asked, without a link
	const KIND_LIST = Array('actvte', 'signup', 'pwdset', 'lgcode');
	// The mail of a sign-up that asked for an address taken, sent to its owner
	const TAKE_NAME = 'taken';
	// The mail of a sign-up that asked for a name taken, with an address of no account
	const USED_NAME = 'nmused';
	// The mail telling the managers that sign-ups are piling up
	const WTCH_NAME = 'onhold';

	protected $accountNme;
	protected $linkTemplt;
	protected $codeExpiry;
	protected $folderList;
	protected $deferMails;
	protected $mailsQueue = Array();

	/**
	 * Builds the sender from the block of the native userbase.
	 *
	 * @param Array  $configValues The block: 'mailAcnt', 'codeMins', 'linkTmpl' (see the class).
	 * @param String $appsFoldText The folder of the application, whose mails folder comes first; '' for conf/mails alone.
	 * @param Bool   $deferSending Whether the mails wait for the end of the request, after the reply.
	 */
	function __construct( Array $configValues, String $appsFoldText='', Bool $deferSending=false ){
		$this->accountNme = is_string($configValues['mailAcnt'] ?? null) ? $configValues['mailAcnt'] : '';
		$this->linkTemplt = is_string($configValues['linkTmpl'] ?? null) ? $configValues['linkTmpl'] : '';
		$this->codeExpiry = max( 1, intval($configValues['codeMins'] ?? Auth\UserBase::MAIL_MINS) );
		$this->folderList = Array();
		if( $appsFoldText!='' ){
			$this->folderList[] = rtrim($appsFoldText, '/\\').DS.'mails';
		}
		$this->folderList[] = TOP_DIR.DS.'conf'.DS.'mails';
		$this->deferMails = $deferSending;
	}

	/**
	 * Builds the sender of the userbase the login uses, when its block
	 * names a mail account.
	 *
	 * @param String $appsFoldText The folder of the application, whose mails folder comes first.
	 * @param Bool   $deferSending Whether the mails wait for the end of the request, after the reply.
	 * @return ?AccessMail The sender, or null when the login does not use the native userbase or sends no mail.
	 */
	public static function fromLoginBlock( String $appsFoldText='', Bool $deferSending=false ): ?AccessMail {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lgnConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'LOGINSET') : null;
		if( !is_array($lgnConf) || strval($lgnConf['authWith'] ?? '')!=='nativeDb' ){
			return null;
		}
		$blckCfg = $nyuConf->obtainRowValue('ACCESS', strtoupper(strval($lgnConf['authConf'] ?? 'nativeDb')));
		if( !is_array($blckCfg) || !is_string($blckCfg['mailAcnt'] ?? null) || $blckCfg['mailAcnt']==='' ){
			return null;
		}
		return new self($blckCfg, $appsFoldText, $deferSending);
	}

	/**
	 * Tells whether self-registration is open: the 'signupOn' key of the
	 * block of the userbase the login uses, with a mail account to confirm
	 * the addresses.
	 *
	 * @return Bool Whether users may sign up.
	 */
	public static function signupsAllowed(): Bool {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lgnConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'LOGINSET') : null;
		if( !is_array($lgnConf) || strval($lgnConf['authWith'] ?? '')!=='nativeDb' ){
			return false;
		}
		$blckCfg = $nyuConf->obtainRowValue('ACCESS', strtoupper(strval($lgnConf['authConf'] ?? 'nativeDb')));
		return is_array($blckCfg) && ($blckCfg['signupOn'] ?? false)===true && is_string($blckCfg['mailAcnt'] ?? null) && $blckCfg['mailAcnt']!=='';
	}

	/**
	 * Sends a code to a user.
	 *
	 * @param String $mailKindCode The mail: 'actvte', 'signup', 'pwdset' or 'lgcode' (never with a link).
	 * @param String $namedUserTxt Login name of the user.
	 * @param String $emailAddress Its address.
	 * @param String  $codeAsString The code.
	 * @param ?String $langTagStrng The language of the user; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendCodeToUser( String $mailKindCode, String $namedUserTxt, String $emailAddress, String $codeAsString, ?String $langTagStrng=null ): Bool {
		if( !in_array($mailKindCode, self::KIND_LIST, true) ){
			return false;
		}
		$linkTxt = '';
		if( $this->linkTemplt!='' && $mailKindCode!=='lgcode' ){
			$linkTxt = strtr( $this->linkTemplt, Array('%kind%'=>rawurlencode($mailKindCode), '%user%'=>rawurlencode($namedUserTxt), '%code%'=>rawurlencode($codeAsString)) );
		}
		return $this->sendsEmailFlag( $mailKindCode, $emailAddress, Array('user'=>$namedUserTxt, 'code'=>$codeAsString, 'link'=>$linkTxt, 'mins'=>strval($this->codeExpiry)), $langTagStrng );
	}

	/**
	 * Tells the address a sign-up gave that the name it asked for is not
	 * available: the address is the one of no account, so nothing is told
	 * about any account but the name.
	 *
	 * @param String  $emailAddress The address given with the sign-up.
	 * @param String  $namedUserTxt The name asked for.
	 * @param ?String $langTagStrng The language of the request; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendNameExists( String $emailAddress, String $namedUserTxt, ?String $langTagStrng=null ): Bool {
		return $this->sendsEmailFlag( self::USED_NAME, $emailAddress, Array('user'=>$namedUserTxt, 'code'=>'', 'link'=>'', 'mins'=>strval($this->codeExpiry)), $langTagStrng );
	}

	/**
	 * Tells the owner of an address that a sign-up asked for it, the
	 * address being the one of its account already.
	 *
	 * @param String $emailAddress The address given with the sign-up.
	 * @param String  $namedUserTxt The name asked for.
	 * @param ?String $langTagStrng The language of the owner of the address; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendAddrExists( String $emailAddress, String $namedUserTxt, ?String $langTagStrng=null ): Bool {
		return $this->sendsEmailFlag( self::TAKE_NAME, $emailAddress, Array('user'=>$namedUserTxt, 'code'=>'', 'link'=>'', 'mins'=>strval($this->codeExpiry)), $langTagStrng );
	}

	/**
	 * Tells the managers that sign-ups are piling up, each by a mail of
	 * its own: as many are waiting, and as many are allowed before the
	 * sign-ups close.
	 *
	 * @param Array $mailMailsArr The addresses of the managers, as a list, or their languages by address.
	 * @param Int   $mailCountVal The sign-ups waiting.
	 * @param Int   $ceilingValue The most allowed at once ('maxQueue').
	 * @return Bool Whether every mail was sent, or is to be sent after the reply.
	 */
	public function sendQueueAlert( Array $mailMailsArr, Int $mailCountVal, Int $ceilingValue ): Bool {
		$allSent = true;
		foreach( $mailMailsArr as $adrKey=>$mgrAdr ){
			$mgrAddr = is_string($adrKey) ? $adrKey : strval($mgrAdr);
			$mgrLang = is_string($adrKey) && is_string($mgrAdr) ? $mgrAdr : null;
			$allSent = $this->sendsEmailFlag( self::WTCH_NAME, $mgrAddr, Array('count'=>strval($mailCountVal), 'limit'=>strval($ceilingValue), 'link'=>''), $mgrLang ) && $allSent;
		}
		return $allSent;
	}

	/**
	 * Sends a mail made of its templates, now or after the reply.
	 *
	 * @param String $templateName Name of the templates.
	 * @param String $emailAddress The address.
	 * @param Array   $placeholders Values of the placeholders, by name.
	 * @param ?String $langTagStrng The language of the mail; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	protected function sendsEmailFlag( String $templateName, String $emailAddress, Array $placeholders, ?String $langTagStrng=null ): Bool {
		$mailArr = $this->buildMailArray($templateName, $placeholders, $langTagStrng);
		if( $mailArr===null ){
			Logger::retrieveLogger('runner')->error( 'Access mail '.json_encode($templateName).' not sent: its template cannot be read' );
			return false;
		}
		if( !$this->deferMails ){
			return $this->postsEmailFlag($emailAddress, $mailArr);
		}
		if( empty($this->mailsQueue) ){
			register_shutdown_function( Array($this, 'sendQueuedMail') );
		}
		$this->mailsQueue[] = Array($emailAddress, $mailArr);
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
	 * @param Array  $mailMailsArr The subject, the text and the HTML (null for none).
	 * @return Bool Whether the mail was sent.
	 */
	protected function postsEmailFlag( String $emailAddress, Array $mailMailsArr ): Bool {
		try{
			return Mailer::retrieveMailer($this->accountNme)->deliverEmlFlag($emailAddress, $mailMailsArr[0], $mailMailsArr[1], $mailMailsArr[2]);
		}catch( MailFailure $e ){
			Logger::retrieveLogger('runner')->warning( 'Access mail not sent through account '.json_encode($this->accountNme).': '.$e->getMessage() );
			return false;
		}
	}

	/**
	 * Makes a mail of its templates.
	 *
	 * @param String $templateName Name of the templates.
	 * @param Array   $placeholders Values of the placeholders, by name.
	 * @param ?String $langTagStrng The language of the mail; the default one when not given.
	 * @return ?Array The subject, the text and the HTML (null for none), or null when the text template cannot be read.
	 */
	protected function buildMailArray( String $templateName, Array $placeholders, ?String $langTagStrng=null ): ?Array {
		$lngTool = new Language();
		$txtPath = null;
		foreach( $lngTool->langChainArray($lngTool->chooseLanguage($langTagStrng, null)) as $oneTag ){
			foreach( $this->folderList as $dirPth ){
				$txtPath = $txtPath ?? $lngTool->locateTemplate($dirPth, $templateName.'.txt', Array($oneTag));
			}
		}
		if( $txtPath===null ){
			return null;
		}
		// The HTML is the one beside the text : an application giving its own text without HTML sends text alone
		$htmPath = dirname($txtPath).DS.$templateName.'.html';
		try{
			$malTmpl = new Template( $txtPath, (is_file($htmPath) && is_readable($htmPath)) ? $htmPath : null );
		}catch( \RuntimeException $e ){
			return null;
		}
		return Array( $malTmpl->getSubjectText($placeholders), $malTmpl->simpleBodyText($placeholders), $malTmpl->getHtmlVersion($placeholders) );
	}
}
