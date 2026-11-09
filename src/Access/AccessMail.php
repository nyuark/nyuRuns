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
 * It works from the block of the native userbase: 'mailer' names the
 * account of conf/mailer.json the mails go through (no mail is sent
 * without it: the codes are then given in the replies, as before),
 * 'codexp' the minutes a mailed code lasts, and 'lnkurl' the address of
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
	const KINDLIST = Array('actvte', 'signup', 'pwdset', 'lgcode');
	// The mail of a sign-up that asked for an address taken, sent to its owner
	const TAKENNME = 'taken';
	// The mail of a sign-up that asked for a name taken, with an address of no account
	const NAMEDNME = 'nmused';
	// The mail telling the managers that sign-ups are piling up
	const WATCHNME = 'onhold';

	protected $acntName;
	protected $linkTmpl;
	protected $codeMins;
	protected $mailDirs;
	protected $sendLate;
	protected $lateList = Array();

	/**
	 * Builds the sender from the block of the native userbase.
	 *
	 * @param Array  $confValues The block: 'mailer', 'codexp', 'lnkurl' (see the class).
	 * @param String $appsFolder The folder of the application, whose mails folder comes first; '' for conf/mails alone.
	 * @param Bool   $afterReply Whether the mails wait for the end of the request, after the reply.
	 */
	function __construct( Array $confValues, String $appsFolder='', Bool $afterReply=false ){
		$this->acntName = is_string($confValues['mailer'] ?? null) ? $confValues['mailer'] : '';
		$this->linkTmpl = is_string($confValues['lnkurl'] ?? null) ? $confValues['lnkurl'] : '';
		$this->codeMins = max( 1, intval($confValues['codexp'] ?? Auth\UserBase::MAILMINS) );
		$this->mailDirs = Array();
		if( $appsFolder!='' ){
			$this->mailDirs[] = rtrim($appsFolder, '/\\').DS.'mails';
		}
		$this->mailDirs[] = TOP_DIR.DS.'conf'.DS.'mails';
		$this->sendLate = $afterReply;
	}

	/**
	 * Builds the sender of the userbase the login uses, when its block
	 * names a mail account.
	 *
	 * @param String $appsFolder The folder of the application, whose mails folder comes first.
	 * @param Bool   $afterReply Whether the mails wait for the end of the request, after the reply.
	 * @return ?AccessMail The sender, or null when the login does not use the native userbase or sends no mail.
	 */
	public static function fromLogin( String $appsFolder='', Bool $afterReply=false ): ?AccessMail {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lgnConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'LOGIN') : null;
		if( !is_array($lgnConf) || strval($lgnConf['authby'] ?? '')!=='native' ){
			return null;
		}
		$blkConf = $nyuConf->getRowVal('ACCESS', strtoupper(strval($lgnConf['authcf'] ?? 'native')));
		if( !is_array($blkConf) || !is_string($blkConf['mailer'] ?? null) || $blkConf['mailer']==='' ){
			return null;
		}
		return new self($blkConf, $appsFolder, $afterReply);
	}

	/**
	 * Tells whether self-registration is open: the 'signup' key of the
	 * block of the userbase the login uses, with a mail account to confirm
	 * the addresses.
	 *
	 * @return Bool Whether users may sign up.
	 */
	public static function signupsOn(): Bool {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lgnConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'LOGIN') : null;
		if( !is_array($lgnConf) || strval($lgnConf['authby'] ?? '')!=='native' ){
			return false;
		}
		$blkConf = $nyuConf->getRowVal('ACCESS', strtoupper(strval($lgnConf['authcf'] ?? 'native')));
		return is_array($blkConf) && ($blkConf['signup'] ?? false)===true && is_string($blkConf['mailer'] ?? null) && $blkConf['mailer']!=='';
	}

	/**
	 * Sends a code to a user.
	 *
	 * @param String $mailedKind The mail: 'actvte', 'signup', 'pwdset' or 'lgcode' (never with a link).
	 * @param String $userString Login name of the user.
	 * @param String $mailString Its address.
	 * @param String  $codeString The code.
	 * @param ?String $langTagStr The language of the user; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendCodes( String $mailedKind, String $userString, String $mailString, String $codeString, ?String $langTagStr=null ): Bool {
		if( !in_array($mailedKind, self::KINDLIST, true) ){
			return false;
		}
		$linkTxt = '';
		if( $this->linkTmpl!='' && $mailedKind!=='lgcode' ){
			$linkTxt = strtr( $this->linkTmpl, Array('%kind%'=>rawurlencode($mailedKind), '%user%'=>rawurlencode($userString), '%code%'=>rawurlencode($codeString)) );
		}
		return $this->sendsMail( $mailedKind, $mailString, Array('user'=>$userString, 'code'=>$codeString, 'link'=>$linkTxt, 'mins'=>strval($this->codeMins)), $langTagStr );
	}

	/**
	 * Tells the address a sign-up gave that the name it asked for is not
	 * available: the address is the one of no account, so nothing is told
	 * about any account but the name.
	 *
	 * @param String  $mailString The address given with the sign-up.
	 * @param String  $userString The name asked for.
	 * @param ?String $langTagStr The language of the request; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendNamed( String $mailString, String $userString, ?String $langTagStr=null ): Bool {
		return $this->sendsMail( self::NAMEDNME, $mailString, Array('user'=>$userString, 'code'=>'', 'link'=>'', 'mins'=>strval($this->codeMins)), $langTagStr );
	}

	/**
	 * Tells the owner of an address that a sign-up asked for it, the
	 * address being the one of its account already.
	 *
	 * @param String $mailString The address given with the sign-up.
	 * @param String  $userString The name asked for.
	 * @param ?String $langTagStr The language of the owner of the address; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	public function sendTaken( String $mailString, String $userString, ?String $langTagStr=null ): Bool {
		return $this->sendsMail( self::TAKENNME, $mailString, Array('user'=>$userString, 'code'=>'', 'link'=>'', 'mins'=>strval($this->codeMins)), $langTagStr );
	}

	/**
	 * Tells the managers that sign-ups are piling up, each by a mail of
	 * its own: as many are waiting, and as many are allowed before the
	 * sign-ups close.
	 *
	 * @param Array $mailsArray The addresses of the managers, as a list, or their languages by address.
	 * @param Int   $countValue The sign-ups waiting.
	 * @param Int   $limitValue The most allowed at once ('regtop').
	 * @return Bool Whether every mail was sent, or is to be sent after the reply.
	 */
	public function sendWatch( Array $mailsArray, Int $countValue, Int $limitValue ): Bool {
		$allSent = true;
		foreach( $mailsArray as $adrKey=>$mngVal ){
			$mngAddr = is_string($adrKey) ? $adrKey : strval($mngVal);
			$mngLang = is_string($adrKey) && is_string($mngVal) ? $mngVal : null;
			$allSent = $this->sendsMail( self::WATCHNME, $mngAddr, Array('count'=>strval($countValue), 'limit'=>strval($limitValue), 'link'=>''), $mngLang ) && $allSent;
		}
		return $allSent;
	}

	/**
	 * Sends a mail made of its templates, now or after the reply.
	 *
	 * @param String $templtName Name of the templates.
	 * @param String $mailString The address.
	 * @param Array   $fillValues Values of the placeholders, by name.
	 * @param ?String $langTagStr The language of the mail; the default one when not given.
	 * @return Bool Whether the mail was sent, or is to be sent after the reply.
	 */
	protected function sendsMail( String $templtName, String $mailString, Array $fillValues, ?String $langTagStr=null ): Bool {
		$mailArr = $this->buildMail($templtName, $fillValues, $langTagStr);
		if( $mailArr===null ){
			Logger::getLogger('runner')->error( 'Access mail '.json_encode($templtName).' not sent: its template cannot be read' );
			return false;
		}
		if( !$this->sendLate ){
			return $this->postsMail($mailString, $mailArr);
		}
		if( empty($this->lateList) ){
			register_shutdown_function( Array($this, 'sendLater') );
		}
		$this->lateList[] = Array($mailString, $mailArr);
		return true;
	}

	/**
	 * Ends the reply, then sends the mails kept for after it. Called at
	 * the end of the request.
	 */
	public function sendLater(): Void {
		while( ob_get_level()>0 ){
			ob_end_flush();
		}
		if( function_exists('fastcgi_finish_request') ){
			fastcgi_finish_request();
		}
		foreach( $this->lateList as $lateMl ){
			$this->postsMail($lateMl[0], $lateMl[1]);
		}
		$this->lateList = Array();
	}

	/**
	 * Sends a mail through the account of the block.
	 *
	 * @param String $mailString The address.
	 * @param Array  $mailsArray The subject, the text and the HTML (null for none).
	 * @return Bool Whether the mail was sent.
	 */
	protected function postsMail( String $mailString, Array $mailsArray ): Bool {
		try{
			return Mailer::getMailer($this->acntName)->sendEmail($mailString, $mailsArray[0], $mailsArray[1], $mailsArray[2]);
		}catch( MailFailure $e ){
			Logger::getLogger('runner')->warning( 'Access mail not sent through account '.json_encode($this->acntName).': '.$e->getMessage() );
			return false;
		}
	}

	/**
	 * Makes a mail of its templates.
	 *
	 * @param String $templtName Name of the templates.
	 * @param Array   $fillValues Values of the placeholders, by name.
	 * @param ?String $langTagStr The language of the mail; the default one when not given.
	 * @return ?Array The subject, the text and the HTML (null for none), or null when the text template cannot be read.
	 */
	protected function buildMail( String $templtName, Array $fillValues, ?String $langTagStr=null ): ?Array {
		$lngTool = new Language();
		$txtPath = null;
		foreach( $lngTool->langChain($lngTool->chooseLng($langTagStr, null)) as $oneTag ){
			foreach( $this->mailDirs as $dirPth ){
				$txtPath = $txtPath ?? $lngTool->findTmplt($dirPth, $templtName.'.txt', Array($oneTag));
			}
		}
		if( $txtPath===null ){
			return null;
		}
		// The HTML is the one beside the text : an application giving its own text without HTML sends text alone
		$htmPath = dirname($txtPath).DS.$templtName.'.html';
		try{
			$mailTpl = new Template( $txtPath, (is_file($htmPath) && is_readable($htmPath)) ? $htmPath : null );
		}catch( \RuntimeException $e ){
			return null;
		}
		return Array( $mailTpl->getSubjct($fillValues), $mailTpl->plainBody($fillValues), $mailTpl->markupTxt($fillValues) );
	}
}
