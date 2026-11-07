<?php

namespace nyu\Shell;

use nyu\Access\Checker;
use nyu\Access\Totp;
use nyu\Access\Auth\UserList;
use nyu\Access\Sess\JWT;
use nyu\Data\DBCon;
use nyu\Data\Mail\Mailer;
use nyu\Setup\ConfCache;
use nyu\Setup\Loader;
use nyu\Setup\Preset;
use nyu\Tools\Language;
use nyu\Tools\Registry;

/**
 * Checks that a project can serve requests, and that nothing unsafe is
 * left on: the server, the folders written to, the presets, the access
 * config and its secrets, the databases, the store of the users. Each
 * check gives a row: what was checked, its state ('fine', 'warn' for
 * what deserves a look, 'fail' for what keeps requests from being
 * served or exposes them) and what was found.
 */
class HealthChk{

	// Extension each type of database needs
	const BASEEXTS = Array('mysql'=>'pdo_mysql', 'pgsql'=>'pdo_pgsql', 'sqlte'=>'pdo_sqlite', 'mongo'=>'mongodb');
	// Extensions the framework works without, each giving it something more
	const MOREEXTS = Array('openssl', 'mbstring', 'zip', 'gd', 'exif', 'imagick');
	// Shortest secret signing the tokens, in characters
	const SECRSIZE = 32;
	// First release of each branch of PHP fixing php2shell (CVE-2026-109618, CVE-2026-109619)
	const PTCHLIST = Array('8.2'=>'8.2.35', '8.3'=>'8.3.36', '8.4'=>'8.4.27', '8.5'=>'8.5.12');

	protected $rowsList = Array();

	/**
	 * Runs every check: server, folders, preset, access, databases, stores and unsafe settings.
	 *
	 * @return Array The rows: what was checked, its state and what was found.
	 */
	public function runChecks(): Array {
		$this->chkServer();
		$this->chkFoldrs();
		$this->chkPreset();
		$this->chkAccess();
		$this->chkDbases();
		$this->chkStores();
		$this->chkUnsafe();
		return $this->rowsList;
	}

	/**
	 * Gives the rows of the checks run so far.
	 *
	 * @return Array The rows: what was checked, its state and what was found.
	 */
	public function rowsFound(): Array {
		return $this->rowsList;
	}

	/**
	 * Tells whether a check failed.
	 *
	 * @return Bool True when at least one row is a 'fail'.
	 */
	public function hasFailed(): Bool {
		return in_array('fail', array_column($this->rowsList, 1), true);
	}

	/**
	 * Checks the server: the version of PHP, the fixes it holds, the
	 * upload progress of the sessions, and its extensions. A release older
	 * than the fix of its branch is a warning only: the distributions that
	 * backport a fix keep the number of the release.
	 */
	public function chkServer(): Void {
		$this->addResult( 'php', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'fine' : 'fail', 'PHP '.PHP_VERSION.', 8.2 at least is needed' );
		$phpBrch = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
		if( isset(self::PTCHLIST[$phpBrch]) ){
			$patched = version_compare(PHP_VERSION, self::PTCHLIST[$phpBrch], '>=');
			$this->addResult( 'php patch', $patched ? 'fine' : 'warn', $patched ? 'php2shell fixed' : 'php2shell (CVE-2026-109618, CVE-2026-109619) is fixed from '.self::PTCHLIST[$phpBrch].': update, unless the fix was backported' );
		}
		$upldPrg = filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN);
		$this->addResult( 'upload progress', $upldPrg ? 'warn' : 'fine', $upldPrg ? 'on: set session.upload_progress.enabled = 0 in php.ini (CVE-2026-115560), the framework does not use it' : 'off' );
		$this->addResult( 'extension json', extension_loaded('json') ? 'fine' : 'fail', extension_loaded('json') ? 'loaded' : 'not loaded: nothing can be read or answered' );
		$lackLst = array_values( array_filter(self::MOREEXTS, function( String $extnsnName ): Bool { return !extension_loaded($extnsnName); }) );
		$this->addResult( 'extensions', empty($lackLst) ? 'fine' : 'warn', empty($lackLst) ? 'the optional ones are loaded' : 'optional, not loaded: '.implode(', ', $lackLst) );
	}

	/**
	 * Checks the folders the framework writes to: they are created when
	 * missing, and must be writable. A config cache older than a file it
	 * comes from (conf, a preset, a route file) was left by a hand edit.
	 */
	public function chkFoldrs(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->getRowVal('ACCESS', 'LIMITS') : null;
		$lmtName = trim( strval(is_array($lmtConf) ? ($lmtConf['folder'] ?? '') : ''), '/\\ ' );
		$fldList = Array(
			strval( (new Loader(Array('setup'=>Array('ftypes'=>'all'))))->logConfig()['path'] ),
			TOP_DIR.DS.( $lmtName!='' ? str_replace(Array('/', '\\'), DS, $lmtName) : 'temp'.DS.'limits' ),
			TOP_DIR.DS.'temp'.DS.'cache',
			TOP_DIR.DS.'temp'.DS.'backup'
		);
		foreach( array_unique($fldList) as $fldPth ){
			if( !is_dir($fldPth) ){
				@mkdir($fldPth, 0770, true);
			}
			$isReady = is_dir($fldPth) && is_writable($fldPth);
			$this->addResult( 'folder '.str_replace(DS, '/', ltrim(substr($fldPth, strlen(TOP_DIR)), '/\\')), $isReady ? 'fine' : 'fail', $isReady ? 'writable' : 'missing or not writable' );
		}
		// The descriptions of the tables are kept in the config cache only
		$sysConf = ($nyuConf!==null) ? ($nyuConf->readValue('system') ?: Array()) : Array();
		if( in_array(strtoupper(strval($sysConf['TBL_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) && !in_array(strtoupper(strval($sysConf['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) ){
			$this->addResult('table cache', 'warn', 'TBL_CACH is TRUE, but CNF_CACH is not: the descriptions of the tables are not kept');
		}
		// The oldest cache file, against the newest file the config is read from
		$cchTime = 0;
		foreach( (array)glob(TOP_DIR.DS.str_replace('/', DS, ConfCache::CACHPATH).DS.'config-*.cache') as $cchFle ){
			$cchTime = ($cchTime==0) ? intval(@filemtime(strval($cchFle))) : min($cchTime, intval(@filemtime(strval($cchFle))));
		}
		if( $cchTime==0 ){
			$this->addResult('config cache', 'fine', 'none: the config files are read on each request, or a request will cache them (CNF_CACH)');
			return;
		}
		$srcTime = 0;
		foreach( array_merge( (array)glob(TOP_DIR.DS.'conf'.DS.'*.ini'), (array)glob(TOP_DIR.DS.'conf'.DS.'*.json'), (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps'),
			(array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*.rte'), (array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*'.DS.'*.rte') ) as $srcFle ){
			$srcTime = max($srcTime, intval(@filemtime(strval($srcFle))));
		}
		$this->addResult( 'config cache', ($srcTime>$cchTime) ? 'warn' : 'fine', ($srcTime>$cchTime) ? 'older than a config, preset or route file changed since: run "config cache", the next request reads the files' : 'up to date' );
	}

	/**
	 * Checks every preset: it loads, the carrier and the check it names
	 * exist with their blocks, and the roles it asks for can be applied.
	 */
	public function chkPreset(): Void {
		$rpsList = (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps');
		if( empty($rpsList) ){
			$this->addResult('presets', 'fail', 'conf/presets holds no preset');
		}
		foreach( $rpsList as $rpsFle ){
			$prstNme = basename(strval($rpsFle), '.rps');
			try{
				$stupObj = (new Preset($prstNme))->makeSetup();
				$axsConf = $stupObj->setAccess();
			}catch( \Throwable $e ){
				$this->addResult('preset '.$prstNme, 'fail', $e->getMessage());
				continue;
			}
			// A session cookie goes with every request of the browser : any site allowed to call is a risk
			$rndrCnf = $stupObj->getRegKey('rnder');
			$headLst = array_change_key_case( (array)(is_array($rndrCnf) ? ($rndrCnf['headrs'] ?? Array()) : Array()), CASE_LOWER );
			if( ($axsConf['sessby'] ?? '')=='phsess' && trim(strval($headLst['access-control-allow-origin'] ?? ''))=='*' && empty($rndrCnf['origns']) ){
				$this->addResult('origins '.$prstNme, 'warn', 'every origin is allowed while the session travels in a cookie: list the origins under "origns"');
			}
			$flawTxt = '';
			if( !empty($axsConf) && ($axsConf['needed'] ?? true)!==false ){
				$flawTxt = $this->pairFlaws($axsConf);
			}
			$chkrObj = new Checker($axsConf, ActCaller::makeReqst('GET', '/')[0], new Registry(Array()));
			if( $flawTxt=='' && !empty($axsConf) && ($axsConf['needed'] ?? true)!==false ){
				$flawTxt = $chkrObj->pairsFlaw();
			}
			if( $flawTxt=='' ){
				$flawTxt = $chkrObj->rolesFlaw();
			}
			$this->addResult( 'preset '.$prstNme, ($flawTxt=='') ? 'fine' : 'fail', ($flawTxt=='') ? 'loads' : $flawTxt );
		}
	}

	/**
	 * Checks the access config: the login, the secrets signing the
	 * tokens, the API keys, and what is set as unsecure.
	 */
	public function chkAccess(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$axsVals = ($nyuConf!==null) ? $nyuConf->readValue('access') : null;
		if( !is_array($axsVals) || empty($axsVals) ){
			$this->addResult('access', 'fail', 'conf/access.json is missing or empty: "deploy files" creates it from its example');
			return;
		}
		$lgnConf = $this->accessCnf('login');
		if( empty($lgnConf) ){
			$this->addResult('access login', 'warn', 'no login block: nobody can sign in through auth/login');
		}else{
			$flawTxt = $this->pairFlaws($lgnConf + Array('authby'=>'htpass', 'sessby'=>'jtoken', 'authcf'=>'dfpass', 'sesscf'=>'dftokn'));
			// The login hands a name and a password to its check, as the Basic carrier does
			$lgnAuth = strval($lgnConf['authby'] ?? 'htpass');
			if( $flawTxt=='' && isset(Checker::AUTHLIST[$lgnAuth]) && !in_array($lgnAuth, Checker::PAIRLIST['hbasic'], true) ){
				$flawTxt = 'the credential check "'.$lgnAuth.'" takes no name and password: nobody can sign in through auth/login';
			}
			$this->addResult( 'access login', ($flawTxt=='') ? 'fine' : 'fail', ($flawTxt=='') ? strval($lgnConf['authby'] ?? 'htpass').' through '.strval($lgnConf['sessby'] ?? 'jtoken') : $flawTxt );
		}
		foreach( $axsVals as $blkKey => $blkVal ){
			if( !is_array($blkVal) ){
				continue;
			}
			$blkName = strtolower(strval($blkKey));
			if( array_key_exists('algrtm', $blkVal) && !in_array($blkVal['algrtm'], JWT::ALGOLIST, true) ){
				$this->addResult('algorithm '.$blkName, 'fail', 'not one the tokens can be signed with: '.implode(', ', JWT::ALGOLIST));
			}elseif( ($blkVal['algrtm'] ?? '')=='RS256' ){
				$this->chkRsakey($blkName, $blkVal);
			}elseif( array_key_exists('secret', $blkVal) ){
				$scrtLen = strlen(strval($blkVal['secret']));
				$this->addResult( 'secret '.$blkName, ($scrtLen>=self::SECRSIZE) ? 'fine' : 'fail', ($scrtLen>=self::SECRSIZE) ? 'set' : ( $scrtLen==0 ? 'empty' : 'too short' ).': '.self::SECRSIZE.' characters at least; "config secrt jtoken" makes one' );
			}
			if( array_key_exists('algrtm', $blkVal) || array_key_exists('issuer', $blkVal) && array_key_exists('audnce', $blkVal) || array_key_exists('applst', $blkVal) ){
				$this->chkClaims($blkName, $blkVal);
			}
			if( isset($blkVal['keylst']) && is_array($blkVal['keylst']) && in_array('', array_map('strval', $blkVal['keylst']), true) ){
				$this->addResult('keys '.$blkName, 'warn', 'an API key of the block is empty; "config secrt apikey" makes one');
			}
			if( ($blkVal['secure'] ?? true)===false && array_key_exists('cookie', $blkVal) && strtoupper(strval($nyuConf->getRowVal('SYSTEM', 'ENV_NAME')))=='PROD' ){
				$this->addResult('cookie '.$blkName, 'warn', 'the session cookie also travels over plain HTTP (secure is false) in the environment PROD');
			}
			if( array_key_exists('tfakey', $blkVal) ){
				$keyLeng = strlen(strval($blkVal['tfakey']));
				if( $keyLeng==0 ){
					$this->addResult('2FA key '.$blkName, 'warn', 'empty: the 2FA secrets are stored readable; "config secrt tfakey" makes one');
				}else{
					$this->addResult( '2FA key '.$blkName, ($keyLeng>=Totp::SEALSIZE) ? 'fine' : 'fail', ($keyLeng>=Totp::SEALSIZE) ? 'set' : 'too short: '.Totp::SEALSIZE.' characters at least; "config secrt tfakey" makes one' );
				}
			}
			if( ($blkVal['unsecure'] ?? false)===true ){
				$this->addResult('unsecure '.$blkName, 'warn', 'weak password hashes are accepted by this block');
			}
			$this->chkMailer($blkName, $blkVal);
		}
		// The language of the mails of a user who has none
		$dfltTag = strtolower( trim(str_replace('_', '-', strval($nyuConf->getRowVal('SYSTEM', 'LNG_DFLT') ?? ''))) );
		if( $dfltTag!=='' && !Language::checksTag($dfltTag) ){
			$this->addResult('mail language', 'fail', 'LNG_DFLT is not a language tag (en, fr, fr-ch): the mails are written in en');
		}
	}

	/**
	 * Checks the access mails of a block of the native userbase: the mail
	 * account 'mailer' names, the front-end address of 'lnkurl', and a
	 * 'signup' that no mail can confirm.
	 *
	 * @param String $blockNamed Name of the block.
	 * @param Array  $blockValue Its values.
	 */
	protected function chkMailer( String $blockNamed, Array $blockValue ): Void {
		$acntNme = $blockValue['mailer'] ?? null;
		$hasMail = is_string($acntNme) && $acntNme!=='';
		if( $hasMail ){
			try{
				$acntSet = Mailer::acntSetup($acntNme);
				$this->addResult( 'access mails '.$blockNamed, 'fine', 'through the account '.$acntNme.' ('.$acntSet['type'].')' );
			}catch( \Throwable $e ){
				$this->addResult('access mails '.$blockNamed, 'fail', 'mailer names no account of conf/mailer.json: '.$e->getMessage());
			}
		}elseif( $acntNme!==null && $acntNme!=='' ){
			$this->addResult('access mails '.$blockNamed, 'fail', 'mailer is the name of a mail account, as a text');
		}
		$linkTpl = $blockValue['lnkurl'] ?? null;
		if( $linkTpl!==null && $linkTpl!=='' && ( !is_string($linkTpl) || !preg_match('#^https?://[^\s]+$#Di', $linkTpl) || strpos($linkTpl, '%code%')===false ) ){
			$this->addResult('access link '.$blockNamed, 'fail', 'lnkurl is an http(s) address holding %code% (and %kind%, %user% as needed)');
		}
		if( array_key_exists('codexp', $blockValue) && (!is_int($blockValue['codexp']) || $blockValue['codexp']<1) ){
			$this->addResult('access codes '.$blockNamed, 'fail', 'codexp is the minutes a mailed code lasts: a whole number, 1 at least');
		}
		if( ($blockValue['signup'] ?? false)===true && !$hasMail ){
			$this->addResult('sign-ups '.$blockNamed, 'warn', 'signup is on, but no mailer confirms the addresses: nobody can sign up');
		}
		// The limits of the sign-ups : how many wait at once, how many one client makes, the counts the managers are told at
		$regFlaw = Array();
		foreach( Array('regtop', 'regmax') as $regKey ){
			if( array_key_exists($regKey, $blockValue) && (!is_int($blockValue[$regKey]) || $blockValue[$regKey]<1) ){
				$regFlaw[] = $regKey.' is a whole number, 1 at least';
			}
		}
		if( array_key_exists('regwrn', $blockValue) && ( !is_array($blockValue['regwrn']) || array_values($blockValue['regwrn'])!==$blockValue['regwrn'] || count(array_filter($blockValue['regwrn'], function( Mixed $warnNumber ): Bool { return is_int($warnNumber) && $warnNumber>0; }))!==count($blockValue['regwrn']) ) ){
			$regFlaw[] = 'regwrn is a list of whole numbers';
		}
		if( !empty($regFlaw) ){
			$this->addResult('sign-up limits '.$blockNamed, 'fail', implode('; ', $regFlaw));
		}
	}

	/**
	 * Checks what binds the tokens of a block to this project: the
	 * 'issuer' (a text) and the 'audnce' (a text or a list of texts) of a
	 * block of tokens, the 'audnce' of the application keys and the
	 * 'issuer' of each application, and the previous public key ('oldpub')
	 * still checking the tokens signed before the keys were replaced.
	 * Tokens bound to no project are told; values of the wrong kind fail.
	 *
	 * @param String $blockNamed Name of the block.
	 * @param Array  $blockValue Its values.
	 */
	protected function chkClaims( String $blockNamed, Array $blockValue ): Void {
		$appsBlk = array_key_exists('applst', $blockValue);
		$clmFlaw = Array();
		$audnVal = $blockValue['audnce'] ?? Array();
		if( !is_string($audnVal) && !( is_array($audnVal) && array_values($audnVal)===$audnVal && count(array_filter($audnVal, 'is_string'))===count($audnVal) ) ){
			$clmFlaw[] = 'audnce is a text or a list of texts';
		}
		if( !$appsBlk && !is_string($blockValue['issuer'] ?? '') ){
			$clmFlaw[] = 'issuer is a text';
		}
		foreach( ($appsBlk && is_array($blockValue['applst'])) ? $blockValue['applst'] : Array() as $appKey => $appSet ){
			if( is_array($appSet) && array_key_exists('issuer', $appSet) && !is_string($appSet['issuer']) ){
				$clmFlaw[] = 'the issuer of the application '.json_encode(strval($appKey)).' is a text';
			}
		}
		$oldPath = trim(strval($blockValue['oldpub'] ?? ''), '/\\ ');
		if( $oldPath!='' ){
			$oldText = is_file(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $oldPath)) ? strval(@file_get_contents(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $oldPath))) : '';
			$oldObjc = ($oldText!='' && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public($oldText) : false;
			$oldInfo = ($oldObjc!==false) ? openssl_pkey_get_details($oldObjc) : false;
			if( !is_array($oldInfo) || ($oldInfo['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($oldInfo['bits'] ?? 0)<JWT::RSAMINIM ){
				$clmFlaw[] = 'oldpub is not the public RSA key of '.JWT::RSAMINIM.' bits at least: empty it, or "config secrt jtoken" writes it';
			}elseif( ($blockValue['algrtm'] ?? '')!='RS256' ){
				$this->addResult('previous key '.$blockNamed, 'warn', 'oldpub is only read with RS256');
			}
		}
		if( !empty($clmFlaw) ){
			$this->addResult('token claims '.$blockNamed, 'fail', implode('; ', $clmFlaw));
			return;
		}
		$hasAudn = ( is_string($audnVal) ? trim($audnVal)!='' : count(array_filter(array_map('trim', $audnVal)))>0 );
		$hasIssr = $appsBlk || trim(strval($blockValue['issuer'] ?? ''))!='';
		if( !$hasAudn || !$hasIssr ){
			$this->addResult('token claims '.$blockNamed, 'warn', $appsBlk
				? 'no audnce: the tokens of the applications are not bound to this project; "config write access '.$blockNamed.'.audnce" sets it'
				: 'no issuer or no audnce: the tokens are not bound to this project; "config write access '.$blockNamed.'.issuer" and ".audnce" set them');
			return;
		}
		$this->addResult('token claims '.$blockNamed, 'fine', $appsBlk ? 'audience '.json_encode($audnVal) : 'issuer '.json_encode($blockValue['issuer']).', audience '.json_encode($audnVal));
	}

	/**
	 * Checks the keys of a block signing its tokens with RS256: a private
	 * key that signs and the public key checking them, or the public key
	 * alone when the tokens are issued by another server. A key must be an
	 * RSA key of JWT::RSAMINIM bits at least; a private key readable by the
	 * other users of the server is told.
	 *
	 * @param String $blockNamed Name of the block.
	 * @param Array  $blockValue Its values.
	 */
	protected function chkRsakey( String $blockNamed, Array $blockValue ): Void {
		$keyText = Array();
		foreach( Array('prvkey', 'pubkey') as $keyNme ){
			$keyPath = trim(strval($blockValue[$keyNme] ?? ''), '/\\ ');
			$keyPath = ($keyPath=='') ? '' : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $keyPath);
			$keyText[$keyNme] = ($keyPath!='' && is_file($keyPath)) ? strval(@file_get_contents($keyPath)) : '';
			if( $keyNme=='prvkey' && $keyText[$keyNme]!='' && DS=='/' && (fileperms($keyPath) & 0044)!=0 ){
				$this->addResult('key rights '.$blockNamed, 'warn', 'the private key can be read by other users of the server: chmod 600 '.strval($blockValue['prvkey']));
			}
		}
		if( !function_exists('openssl_pkey_get_public') ){
			$this->addResult('token keys '.$blockNamed, 'fail', 'RS256 needs the openssl extension');
			return;
		}
		$prvObjc = ($keyText['prvkey']!='') ? @openssl_pkey_get_private($keyText['prvkey']) : false;
		$pubObjc = ($keyText['pubkey']!='') ? @openssl_pkey_get_public($keyText['pubkey']) : false;
		$keyBits = Array();
		foreach( Array($prvObjc, $pubObjc) as $keyObj ){
			$keyInfo = ($keyObj!==false) ? openssl_pkey_get_details($keyObj) : false;
			if( is_array($keyInfo) ){
				$keyBits[] = (($keyInfo['type'] ?? null)===OPENSSL_KEYTYPE_RSA) ? intval($keyInfo['bits'] ?? 0) : 0;
			}
		}
		if( ($keyText['prvkey']!='' && $prvObjc===false) || ($keyText['pubkey']!='' && $pubObjc===false) ){
			$this->addResult('token keys '.$blockNamed, 'fail', 'a key file is not a PEM key: "config secrt jtoken" makes a new pair');
		}elseif( empty($keyBits) ){
			$this->addResult('token keys '.$blockNamed, 'fail', 'missing: "config secrt jtoken" makes them');
		}elseif( min($keyBits)<JWT::RSAMINIM ){
			$this->addResult('token keys '.$blockNamed, 'fail', 'not an RSA key of '.JWT::RSAMINIM.' bits at least: "config secrt jtoken" makes a new pair');
		}else{
			$this->addResult('token keys '.$blockNamed, 'fine', ($prvObjc===false) ? 'checks tokens only: they are issued by another server' : 'set, '.max($keyBits).' bits');
		}
	}

	/**
	 * Checks every configured database: its extension is loaded, and it
	 * answers.
	 */
	public function chkDbases(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$allBase = ($nyuConf!==null) ? $nyuConf->readValue('database') : null;
		if( !is_array($allBase) || empty($allBase) ){
			$needsDb = strval($this->accessCnf('login')['authby'] ?? '')=='native';
			$this->addResult( 'databases', $needsDb ? 'fail' : 'fine', $needsDb ? 'none is configured, and the login uses the native userbase' : 'none is configured' );
			return;
		}
		$allBase = array_change_key_case($allBase, CASE_LOWER);
		foreach( (array_key_exists('type', $allBase) ? Array('') : array_keys($allBase)) as $basNme ){
			$basName = strval($basNme);
			$rowName = 'database'.( $basName!='' ? ' '.$basName : '' );
			try{
				$baseTyp = strtolower( strval(DBCon::baseSetup($basName)['type']) );
				$extName = self::BASEEXTS[$baseTyp] ?? '';
				if( $extName!='' && !extension_loaded($extName) ){
					$this->addResult($rowName, 'fail', 'the extension '.$extName.' is not loaded');
					continue;
				}
				$dbHandl = DBCon::linkNamed($basName)->getConnex();
				if( $dbHandl===null ){
					$this->addResult($rowName, 'fine', 'configured ('.$baseTyp.'); not an SQL database, not asked');
					continue;
				}
				$isReady = $dbHandl->sendQuery('SELECT 1;');
				$this->addResult( $rowName, $isReady ? 'fine' : 'fail', $isReady ? 'answers ('.$baseTyp.')' : 'does not answer: '.strval($dbHandl->lastError()) );
			}catch( \Throwable $e ){
				$this->addResult($rowName, 'fail', 'does not answer: '.$e->getMessage());
			}
		}
	}

	/**
	 * Checks the store of the users the login names: the users table of
	 * the native userbase, the users file, or the htpasswd file.
	 */
	public function chkStores(): Void {
		$lgnConf = $this->accessCnf('login');
		$authNme = strval($lgnConf['authby'] ?? '');
		$blkConf = $this->accessCnf( strval($lgnConf['authcf'] ?? '') );
		if( $authNme=='native' ){
			try{
				$dbHandl = DBCon::linkNamed( strval($blkConf['basnme'] ?? '') )->getConnex();
				$tblName = strval($blkConf['tblnme'] ?? 'users');
				$hasTble = ($dbHandl!==null) && $dbHandl->tableInfo($tblName)!==false;
				$this->addResult( 'users', $hasTble ? 'fine' : 'fail', $hasTble ? 'the table '.$tblName.' is there' : 'the table '.$tblName.' is missing: "deploy users" creates it' );
			}catch( \Throwable $e ){
				$this->addResult('users', 'fail', 'the userbase cannot be read: '.$e->getMessage());
			}
		}elseif( $authNme=='usrlst' ){
			$chkObjc = new UserList($blkConf);
			$chkObjc->userIdent('-');
			$isReady = ($chkObjc->lastError()!='Credential store unavailable');
			$this->addResult( 'users', $isReady ? 'fine' : 'fail', $isReady ? 'the users file is read' : 'the users file is missing or cannot be used: "deploy users" creates it' );
		}elseif( $authNme=='htpass' ){
			$htpName = trim( strval($blkConf['htpasswd'] ?? ''), '/\\ ' );
			$isReady = ($htpName!='') && is_file(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $htpName));
			$this->addResult( 'users', $isReady ? 'fine' : 'fail', $isReady ? 'the htpasswd file is there' : 'the htpasswd file is missing: "config secrt htpass <user>" writes it' );
		}
	}

	/**
	 * Checks what must not be left on where real users come: errors
	 * shown, proxies trusted from anywhere, secrets readable by others.
	 */
	public function chkUnsafe(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$sysConf = ($nyuConf!==null) ? ($nyuConf->readValue('system') ?: Array()) : Array();
		$envName = strtoupper( strval($sysConf['ENV_NAME'] ?? '') );
		$showErr = in_array( strtoupper(strval($sysConf['ERR_SHOW'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true );
		$errStat = ( $showErr && in_array($envName, Array('PROD', 'PREV'), true) ) ? 'warn' : 'fine';
		// In production the setting is not followed : nothing of an error is shown there
		$errNote = ($errStat=='fine') ? '' : ( $envName=='PROD' ? ', where it is ignored: set it to FALSE' : ': the details of an error are shown to the clients' );
		$this->addResult( 'errors', $errStat, 'ERR_SHOW is '.( $showErr ? 'on' : 'off' ).' in the environment '.( $envName!='' ? $envName : '(not named)' ).$errNote );
		// A level that cannot be read stops every request at its start
		try{
			$errLevl = (new Loader(Array('setup'=>Array('ftypes'=>'all'))))->errConfig();
			$this->addResult( 'error level', 'fine', ($errLevl==E_ALL) ? 'every error is reported (E_ALL)' : 'ERR_LEVL reports level '.$errLevl.' of '.E_ALL );
		}catch( \RuntimeException $e ){
			$this->addResult( 'error level', 'fail', $e->getMessage().': no request can be served' );
		}
		if( preg_match('#(^|[\s,])(0\.0\.0\.0|::)/0#', strval($sysConf['PRX_LIST'] ?? '')) ){
			$this->addResult('proxies', 'fail', 'PRX_LIST trusts every address: any client can then give itself the address it wants');
		}
		if( array_key_exists('REQ_SIZE', $sysConf) && intval($sysConf['REQ_SIZE'])<1 ){
			$this->addResult('requests', 'warn', 'REQ_SIZE is 0: a request body of any size is read in memory');
		}
		$envPath = TOP_DIR.DS.'.env';
		if( DIRECTORY_SEPARATOR=='/' && is_file($envPath) && (fileperms($envPath) & 0077)!=0 ){
			$this->addResult('.env', 'warn', 'the file of the secrets can be read by other users of the system: chmod 600 .env');
		}
		// The limiter is checked for what it would do, not for what it holds
		$lmtConf = $this->accessCnf('limits');
		if( !empty($lmtConf) && intval($lmtConf['maxtry'] ?? 10)<1 ){
			$this->addResult('limits', 'warn', 'the limit by address is off (maxtry 0): wrong credentials can be tried without end');
		}
	}

	/**
	 * Tells what is wrong with the carrier and the check an 'auths' or a
	 * 'login' block names: one that does not exist, or a block of
	 * settings the access config does not hold.
	 *
	 * @param Array $accessConf The block, with its 'sessby', 'sesscf', 'authby' and 'authcf' keys.
	 * @return String What is wrong, empty when nothing is.
	 */
	protected function pairFlaws( Array $accessConf ): String {
		$sessNme = strval($accessConf['sessby'] ?? '');
		if( Checker::sessClass($sessNme)===null ){
			return 'unknown session carrier "'.$sessNme.'"';
		}
		$authNme = $accessConf['authby'] ?? null;
		if( $authNme!==null && Checker::authClass(strval($authNme))===null ){
			return 'unknown credential check "'.strval($authNme).'"';
		}
		foreach( Array('sesscf', 'authcf') as $cnfKey ){
			$blkName = strval($accessConf[$cnfKey] ?? '');
			if( $blkName!='' && empty($this->accessCnf($blkName)) ){
				return 'conf/access.json has no "'.$blkName.'" block, named by '.$cnfKey;
			}
		}
		return '';
	}

	/**
	 * Reads a block of the ACCESS config.
	 *
	 * @param String $blockNamed Name of the block.
	 * @return Array Its values, empty when there is no such block.
	 */
	protected function accessCnf( String $blockNamed ): Array {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$blkConf = ($nyuConf!==null && $blockNamed!='') ? $nyuConf->getRowVal('ACCESS', strtoupper($blockNamed)) : null;
		return is_array($blkConf) ? $blkConf : Array();
	}

	/**
	 * Records the result of a check.
	 *
	 * @param String $checkLabel What was checked.
	 * @param String $checkState 'fine', 'warn' or 'fail'.
	 * @param String $detailText What was found.
	 */
	protected function addResult( String $checkLabel, String $checkState, String $detailText ): Void {
		$this->rowsList[] = Array($checkLabel, $checkState, $detailText);
	}
}
