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
use nyu\Tools\Router;

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
	const BASE_EXTS = Array('mysql_db'=>'pdo_mysql', 'postgres'=>'pdo_pgsql', 'sqlitedb'=>'pdo_sqlite', 'mongo_db'=>'mongodb');
	// Extensions the framework works without, each giving it something more
	const MORE_EXTS = Array('openssl', 'mbstring', 'zip', 'gd', 'exif', 'imagick');
	// Shortest secret signing the tokens, in characters
	const SECR_SIZE = 32;
	// First release of each branch of PHP fixing php2shell (CVE-2026-109618, CVE-2026-109619)
	const SAFE_VERS = Array('8.2'=>'8.2.35', '8.3'=>'8.3.36', '8.4'=>'8.4.27', '8.5'=>'8.5.12');

	protected $resultRows = Array();

	/**
	 * Runs every check: server, folders, preset, access, databases, stores and unsafe settings.
	 *
	 * @return Array The rows: what was checked, its state and what was found.
	 */
	public function runChecksArray(): Array {
		$this->validateServer();
		$this->checkFldrWrite();
		$this->validatePreset();
		$this->validateRoutes();
		$this->validateEntrys();
		$this->verifyAccsConf();
		$this->checkDatabases();
		$this->validateStores();
		$this->validateUnsafe();
		return $this->resultRows;
	}

	/**
	 * Gives the rows of the checks run so far.
	 *
	 * @return Array The rows: what was checked, its state and what was found.
	 */
	public function rowsFoundArray(): Array {
		return $this->resultRows;
	}

	/**
	 * Tells whether a check failed.
	 *
	 * @return Bool True when at least one row is a 'fail'.
	 */
	public function validateFailed(): Bool {
		return in_array('fail', array_column($this->resultRows, 1), true);
	}

	/**
	 * Checks the server: the version of PHP, the fixes it holds, the
	 * upload progress of the sessions, and its extensions. A release older
	 * than the fix of its branch is a warning only: the distributions that
	 * backport a fix keep the number of the release.
	 */
	public function validateServer(): Void {
		$this->addCheckResult( 'php', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'fine' : 'fail', 'PHP '.PHP_VERSION.', 8.2 at least is needed' );
		$phpVers = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
		if( isset(self::SAFE_VERS[$phpVers]) ){
			$patched = version_compare(PHP_VERSION, self::SAFE_VERS[$phpVers], '>=');
			$this->addCheckResult( 'php patch', $patched ? 'fine' : 'warn', $patched ? 'php2shell fixed' : 'php2shell (CVE-2026-109618, CVE-2026-109619) is fixed from '.self::SAFE_VERS[$phpVers].': update, unless the fix was backported' );
		}
		$upldPrg = filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN);
		$this->addCheckResult( 'upload progress', $upldPrg ? 'warn' : 'fine', $upldPrg ? 'on: set session.upload_progress.enabled = 0 in php.ini (CVE-2026-115560), the framework does not use it' : 'off' );
		$this->addCheckResult( 'extension json', extension_loaded('json') ? 'fine' : 'fail', extension_loaded('json') ? 'loaded' : 'not loaded: nothing can be read or answered' );
		$lackLst = array_values( array_filter(self::MORE_EXTS, function( String $extensionNme ): Bool { return !extension_loaded($extensionNme); }) );
		$this->addCheckResult( 'extensions', empty($lackLst) ? 'fine' : 'warn', empty($lackLst) ? 'the optional ones are loaded' : 'optional, not loaded: '.implode(', ', $lackLst) );
	}

	/**
	 * Checks the folders the framework writes to: they are created when
	 * missing, and must be writable. A config cache older than a file it
	 * comes from (conf, a preset, a route file) was left by a hand edit.
	 */
	public function checkFldrWrite(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$lmtConf = ($nyuConf!==null) ? $nyuConf->obtainRowValue('ACCESS', 'TRYLIMIT') : null;
		$lmtName = trim( strval(is_array($lmtConf) ? ($lmtConf['dataPath'] ?? '') : ''), '/\\ ' );
		$fldList = Array(
			strval( (new Loader(Array('settings'=>Array('confType'=>'all'))))->logConfigArray()['path'] ),
			TOP_DIR.DS.( $lmtName!='' ? str_replace(Array('/', '\\'), DS, $lmtName) : 'temp'.DS.'limits' ),
			TOP_DIR.DS.'temp'.DS.'cache',
			TOP_DIR.DS.'temp'.DS.'archives'
		);
		foreach( array_unique($fldList) as $fldPth ){
			if( !is_dir($fldPth) ){
				@mkdir($fldPth, 0770, true);
			}
			$isReady = is_dir($fldPth) && is_writable($fldPth);
			$this->addCheckResult( 'folder '.str_replace(DS, '/', ltrim(substr($fldPth, strlen(TOP_DIR)), '/\\')), $isReady ? 'fine' : 'fail', $isReady ? 'writable' : 'missing or not writable' );
		}
		// The rights of the secret files are read on Linux only (see chkRsakey) : on Windows they are told to be the host's to set
		if( DS=='\\' ){
			$this->addCheckResult('file rights', 'warn', 'the rights of the secret files are not checked on Windows: limit conf and temp to the web server\'s account (icacls)');
		}
		// The descriptions of the tables are kept in the config cache only
		$iniConf = ($nyuConf!==null) ? ($nyuConf->readValueByKey('system') ?: Array()) : Array();
		if( in_array(strtoupper(strval($iniConf['TBL_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) && !in_array(strtoupper(strval($iniConf['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) ){
			$this->addCheckResult('table cache', 'warn', 'TBL_CACH is TRUE, but CNF_CACH is not: the descriptions of the tables are not kept');
		}
		// The oldest cache file, against the newest file the config is read from
		$cchTime = 0;
		foreach( (array)glob(TOP_DIR.DS.str_replace('/', DS, ConfCache::CACH_PATH).DS.'config-*.cache') as $cchFil ){
			$cchTime = ($cchTime==0) ? intval(@filemtime(strval($cchFil))) : min($cchTime, intval(@filemtime(strval($cchFil))));
		}
		if( $cchTime==0 ){
			$this->addCheckResult('config cache', 'fine', 'none: the config files are read on each request, or a request will cache them (CNF_CACH)');
			return;
		}
		$srcTime = 0;
		foreach( array_merge( (array)glob(TOP_DIR.DS.'conf'.DS.'*.ini'), (array)glob(TOP_DIR.DS.'conf'.DS.'*.json'), (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps'),
			(array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*.rte'), (array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*'.DS.'*.rte'), defined('RUN_DIR') ? (array)glob(RUN_DIR.DS.'*'.DS.'routes.rte') : Array() ) as $srcFil ){
			$srcTime = max($srcTime, intval(@filemtime(strval($srcFil))));
		}
		$this->addCheckResult( 'config cache', ($srcTime>$cchTime) ? 'warn' : 'fine', ($srcTime>$cchTime) ? 'older than a config, preset or route file (a Run\'s routes.rte too) changed since: run "settings clrCache", the next request reads the files' : 'up to date' );
	}

	/**
	 * Checks every preset: it loads, the carrier and the check it names
	 * exist with their blocks, and the roles it asks for can be applied.
	 */
	public function validatePreset(): Void {
		$rpsList = (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps');
		if( empty($rpsList) ){
			$this->addCheckResult('presets', 'fail', 'conf/presets holds no preset');
		}
		foreach( $rpsList as $rpsFil ){
			$prstNme = basename(strval($rpsFil), '.rps');
			try{
				$setpObj = (new Preset($prstNme))->newSetupLoader();
				$accConf = $setpObj->setAccessArray();
			}catch( \Throwable $e ){
				$this->addCheckResult('preset '.$prstNme, 'fail', $e->getMessage());
				continue;
			}
			// A session cookie goes with every request of the browser : any site allowed to call is a risk
			$rndrCnf = $setpObj->getRegistryKey('response');
			$headLst = array_change_key_case( (array)(is_array($rndrCnf) ? ($rndrCnf['headList'] ?? Array()) : Array()), CASE_LOWER );
			if( ($accConf['sessWith'] ?? '')=='phpSessn' && trim(strval($headLst['access-control-allow-origin'] ?? ''))=='*' && empty($rndrCnf['corsList']) ){
				$this->addCheckResult('origins '.$prstNme, 'warn', 'every origin is allowed while the session travels in a cookie: list the origins under "corsList"');
			}elseif( ( ($accConf['sessWith'] ?? '')=='jwtToken' || strval($accConf['appsConf'] ?? '')!='' ) && trim(strval($headLst['access-control-allow-origin'] ?? ''))=='*' && empty($rndrCnf['corsList']) ){
				// A token is not sent by the browser on its own, but any site may then read what it opens
				$this->addCheckResult('origins '.$prstNme, 'warn', 'every origin is allowed while the preset reads tokens: list the origins under "corsList"');
			}
			// "public" tells an open preset is meant to be: on one that checks access, it says nothing
			if( ($accConf['isPublic'] ?? false)===true && ($accConf['required'] ?? true)!==false ){
				$this->addCheckResult('public '.$prstNme, 'warn', '"public" is only read on an open preset ("required": false): this one checks access');
			}
			$flawTxt = '';
			if( !empty($accConf) && ($accConf['required'] ?? true)!==false ){
				$flawTxt = $this->coupleFlawsTxt($accConf);
			}
			// The routes of the preset, for the paths its roles name
			$rteConf = $setpObj->getRegistryKey('dispatch');
			$rqstObj = ActCaller::makeRequestArr('GET', '/')[0];
			$checker = new Checker($accConf, $rqstObj, new Registry(Array()), new Router(is_array($rteConf) ? $rteConf : Array(), $rqstObj));
			if( $flawTxt=='' && !empty($accConf) && ($accConf['required'] ?? true)!==false ){
				$flawTxt = $checker->checkPairFlaws();
			}
			if( $flawTxt=='' ){
				$flawTxt = $checker->checkRoleFlaws();
			}
			$this->addCheckResult( 'preset '.$prstNme, ($flawTxt=='') ? 'fine' : 'fail', ($flawTxt=='') ? 'loads' : $flawTxt );
		}
	}

	/**
	 * Checks the routes each preset serves, the Runs' with their mounts:
	 * two Runs declaring one path ('route clash': fail, the first in name
	 * order answers), a site route over a Run route, on its path or by a
	 * pattern matching it ('route shadow': warn, the site's answers), and an entry of openList or rteRoles naming no route
	 * ('access route': warn; fail for a path of rteRoles, which refuses every
	 * request of the preset): a path no route declares, a Run that does not
	 * exist, or an action that is not one of the Run, or that a Run
	 * declaring its routes does not list. One row for each, naming the
	 * presets concerned.
	 */
	public function validateRoutes(): Void {
		$rpsList = (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps');
		$runList = defined('RUN_DIR') ? array_map('basename', array_filter((array)glob(RUN_DIR.DS.'*'), 'is_dir')) : Array();
		$clshLst = Array();
		$shdwLst = Array();
		$lostLst = Array();
		$roleLst = Array();
		foreach( $rpsList as $rpsFil ){
			$prstNme = basename(strval($rpsFil), '.rps');
			try{
				$setpObj = (new Preset($prstNme))->newSetupLoader();
				$accConf = $setpObj->setAccessArray();
			}catch( \Throwable $e ){
				// A preset that does not load is told by its own row
				continue;
			}
			$rteConf = $setpObj->getRegistryKey('dispatch');
			$routObj = new Router( is_array($rteConf) ? $rteConf : Array(), ActCaller::makeRequestArr('GET', '/')[0] );
			$rteData = $routObj->retrieveRoutes();
			foreach( $routObj->runRoutesArray() as $rteSet ){
				$ownName = strval($rteData[$rteSet['routPath']]['routOwnr'] ?? '');
				if( $ownName=='site' ){
					$shdwLst[] = $prstNme.': /'.$rteSet['routPath'].' of '.$rteSet['routOwnr'];
				}elseif( $ownName!==$rteSet['routOwnr'] ){
					$clshLst[] = $prstNme.': /'.$rteSet['routPath'].' of '.$ownName.' and '.$rteSet['routOwnr'];
				}else{
					// A pattern of the site over it answers first
					foreach( array_keys(array_combine(array_keys($rteData), array_column($rteData, 'routOwnr')), 'site', true) as $rteKey ){
						if( $routObj->matchRoutePath(strval($rteKey), strval($rteSet['routPath']))!==null ){
							$shdwLst[] = $prstNme.': /'.$rteSet['routPath'].' of '.$rteSet['routOwnr'].' under /'.$rteKey;
							break;
						}
					}
				}
			}
			// What openList and rteRoles name must be served by this preset
			$roleKys = array_map('strval', array_keys(is_array($accConf['rteRoles'] ?? null) ? $accConf['rteRoles'] : Array()));
			foreach( array_merge(array_values((array)($accConf['openList'] ?? Array())), $roleKys) as $rteKey ){
				$rteKey = trim(strval($rteKey));
				if( substr($rteKey, 0, 1)=='/' ){
					if( !isset($rteData[trim($rteKey, '/')]) ){
						// A path of rteRoles naming no route refuses every request : a failure, not a warning
						if( in_array($rteKey, $roleKys, true) ){
							$roleLst[] = $prstNme.': '.$rteKey.' (no route on that path)';
						}else{
							$lostLst[] = $prstNme.': '.$rteKey.' (no route on that path)';
						}
					}
					continue;
				}
				$actPart = explode('/', trim($rteKey, '/'), 2);
				$ownName = strval( current(preg_grep('/^'.preg_quote($actPart[0], '/').'$/i', $runList)) ?: '' );
				if( $ownName=='' ){
					$lostLst[] = $prstNme.': '.$rteKey.' (no such application)';
					continue;
				}
				if( ($actPart[1] ?? '*')=='*' ){
					continue;
				}
				if( is_file(RUN_DIR.DS.$ownName.DS.'routes.rte') ){
					// A Run declaring its routes is reached through them only (or a site alias): one of them names the action
					if( empty(preg_grep('/^'.preg_quote($ownName.'/'.$actPart[1], '/').'$/i', array_column($routObj->allRoutesArray(), 'routTarg'))) ){
						$lostLst[] = $prstNme.': '.$rteKey.' (not listed in the routes of '.$ownName.')';
					}
					continue;
				}
				$actnPth = RUN_DIR.DS.$ownName.DS.'Actions.php';
				$clsName = '\\nyu\\Runs\\'.$ownName.'\\Actions';
				if( is_file($actnPth) ){
					require_once $actnPth;
				}
				if( !class_exists($clsName, false) || !$clsName::hasActionNamed($actPart[1]) ){
					$lostLst[] = $prstNme.': '.$rteKey.' (not an action of '.$ownName.')';
				}
			}
		}
		$this->addCheckResult( 'route clash', empty($clshLst) ? 'fine' : 'fail', empty($clshLst) ? 'no two applications declare one path' : 'declared by two applications, the first in name order answers: '.implode('; ', array_unique($clshLst)) );
		$this->addCheckResult( 'route shadow', empty($shdwLst) ? 'fine' : 'warn', empty($shdwLst) ? 'no site route over a route of an application' : 'a site route answers in place of the application\'s: '.implode('; ', array_unique($shdwLst)) );
		$allLost = array_unique( array_merge($roleLst, $lostLst) );
		$this->addCheckResult( 'access route', !empty($roleLst) ? 'fail' : (empty($lostLst) ? 'fine' : 'warn'), empty($allLost) ? 'every entry of openList and rteRoles names a route' : 'naming no route: '.implode('; ', $allLost) );
	}

	/**
	 * Checks the entry points of www: the preset each one starts the
	 * Runner with (defaults when it names none) must check access. An open
	 * one ("required": false) fails in PREV and PROD and is told in DEVS and
	 * TEST, unless its security block says it is meant to be ("isPublic": true).
	 * An entry naming its preset at run time cannot be followed: it is told.
	 */
	public function validateEntrys(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$envName = strtoupper( strval(($nyuConf!==null) ? $nyuConf->obtainRowValue('SYSTEM', 'ENV_NAME') : '') );
		$isFinal = in_array($envName, Array('PREV', 'PROD'), true);
		foreach( (array)glob(TOP_DIR.DS.'www'.DS.'*.php') as $entFle ){
			$itemTxt = strval(@file_get_contents(strval($entFle)));
			$itemNme = basename(strval($entFle), '.php');
			if( !preg_match(\nyu\Shell\Cmds\Config::RUNR_CALL, $itemTxt, $callPrt, PREG_OFFSET_CAPTURE) ){
				continue;
			}
			$prstTxt = strval($callPrt[2][0] ?? '');
			if( $prstTxt=='' && substr(ltrim(substr($itemTxt, $callPrt[0][1] + strlen($callPrt[0][0]))), 0, 1)!==')' ){
				$this->addCheckResult('entry '.$itemNme, 'warn', 'the preset is named at run time: check it gives one that checks access');
				continue;
			}
			$prstNme = ($prstTxt=='') ? 'default' : substr($prstTxt, 1, -1);
			try{
				$accConf = (new Preset($prstNme))->newSetupLoader()->setAccessArray();
			}catch( \Throwable $e ){
				// A preset that does not load is told by its own row
				continue;
			}
			if( ($accConf['required'] ?? true)!==false ){
				$this->addCheckResult('entry '.$itemNme, 'fine', 'starts '.$prstNme.', which checks access');
			}elseif( ($accConf['isPublic'] ?? false)===true ){
				$this->addCheckResult('entry '.$itemNme, 'fine', 'starts '.$prstNme.', open by intent (isPublic)');
			}else{
				$this->addCheckResult('entry '.$itemNme, $isFinal ? 'fail' : 'warn', 'starts '.$prstNme.': every request is served without a check; name a preset that checks access, or set "isPublic": true in its security block when the site is meant to be open');
			}
		}
	}

	/**
	 * Checks the access config: the login, the secrets signing the
	 * tokens, the API keys, and what is set as unsecure.
	 */
	public function verifyAccsConf(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$accsVls = ($nyuConf!==null) ? $nyuConf->readValueByKey('access') : null;
		if( !is_array($accsVls) || empty($accsVls) ){
			$this->addCheckResult('access', 'fail', 'conf/access.json is missing or empty: "installs makeConf" creates it from its example');
			return;
		}
		$lgnConf = $this->accessSettings('loginSet');
		if( empty($lgnConf) ){
			$this->addCheckResult('access login', 'warn', 'no login block: nobody can sign in through auth/login');
		}else{
			$flawTxt = $this->coupleFlawsTxt($lgnConf + Array('authWith'=>'htpasswd', 'sessWith'=>'jwtToken', 'authConf'=>'htpasswd', 'sessConf'=>'jwtToken'));
			// The login hands a name and a password to its check, as the Basic carrier does
			$lgnAuth = strval($lgnConf['authWith'] ?? 'htpasswd');
			if( $flawTxt=='' && isset(Checker::AUTH_LIST[$lgnAuth]) && !in_array($lgnAuth, Checker::PAIR_LIST['httpBasc'], true) ){
				$flawTxt = 'the credential check "'.$lgnAuth.'" takes no name and password: nobody can sign in through auth/login';
			}
			$this->addCheckResult( 'access login', ($flawTxt=='') ? 'fine' : 'fail', ($flawTxt=='') ? strval($lgnConf['authWith'] ?? 'htpasswd').' through '.strval($lgnConf['sessWith'] ?? 'jwtToken') : $flawTxt );
		}
		// The registry holds the names of the blocks in upper case: they are told as the file writes them
		$blkCase = array_keys( (array)json_decode(strval(@file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json')), true) );
		foreach( $accsVls as $blkKey => $blkVal ){
			if( !is_array($blkVal) ){
				continue;
			}
			$blckNme = strval( array_values(preg_grep('/^'.preg_quote(strval($blkKey), '/').'$/iD', array_map('strval', $blkCase)))[0] ?? strtolower(strval($blkKey)) );
			if( array_key_exists('signAlgo', $blkVal) && !in_array($blkVal['signAlgo'], JWT::ALGO_LIST, true) ){
				$this->addCheckResult('algorithm '.$blckNme, 'fail', 'not one the tokens can be signed with: '.implode(', ', JWT::ALGO_LIST));
			}elseif( ($blkVal['signAlgo'] ?? '')=='RS256' ){
				$this->validateRsakey($blckNme, $blkVal);
			}elseif( array_key_exists('hmacSecr', $blkVal) ){
				$secrLen = strlen(strval($blkVal['hmacSecr']));
				$this->addCheckResult( 'secret '.$blckNme, ($secrLen>=self::SECR_SIZE) ? 'fine' : 'fail', ($secrLen>=self::SECR_SIZE) ? 'set' : ( $secrLen==0 ? 'empty' : 'too short' ).': '.self::SECR_SIZE.' characters at least; "settings makeSecr jwtToken" makes one' );
			}
			if( array_key_exists('signAlgo', $blkVal) || array_key_exists('issuerId', $blkVal) && array_key_exists('audience', $blkVal) || array_key_exists('appsList', $blkVal) ){
				$this->validateClaims($blckNme, $blkVal);
			}
			if( isset($blkVal['keysList']) && is_array($blkVal['keysList']) && in_array('', array_map('strval', $blkVal['keysList']), true) ){
				$this->addCheckResult('keys '.$blckNme, 'warn', 'an API key of the block is empty; "settings makeSecr keysList" makes one');
			}
			if( ($blkVal['secureOn'] ?? true)===false && array_key_exists('cookName', $blkVal) && strtoupper(strval($nyuConf->obtainRowValue('SYSTEM', 'ENV_NAME')))=='PROD' ){
				$this->addCheckResult('cookie '.$blckNme, 'warn', 'the session cookie also travels over plain HTTP (secureOn is false) in the environment PROD');
			}
			if( array_key_exists('tfaCrypt', $blkVal) ){
				$keySize = strlen(strval($blkVal['tfaCrypt']));
				if( $keySize==0 ){
					$this->addCheckResult('2FA key '.$blckNme, 'warn', 'empty: the 2FA secrets are stored readable; "settings makeSecr tfaCrypt" makes one');
				}else{
					$this->addCheckResult( '2FA key '.$blckNme, ($keySize>=Totp::SEAL_SIZE) ? 'fine' : 'fail', ($keySize>=Totp::SEAL_SIZE) ? 'set' : 'too short: '.Totp::SEAL_SIZE.' characters at least; "settings makeSecr tfaCrypt" makes one' );
				}
			}
			if( ($blkVal['weakHash'] ?? false)===true ){
				$this->addCheckResult('weakHash '.$blckNme, 'warn', 'weak password hashes are accepted by this block');
			}
			$this->validateMailer($blckNme, $blkVal);
		}
		// The key of the login marks : without one, the hold of an account also stops its owner
		$lmtConf = $this->accessSettings('tryLimit');
		$mrkSize = strlen(strval($lmtConf['markSecr'] ?? ''));
		$this->addCheckResult( 'login marks', ($mrkSize>=\nyu\Access\LoginMark::KEYS_SIZE) ? 'fine' : 'warn', ($mrkSize>=\nyu\Access\LoginMark::KEYS_SIZE) ? 'set: a client already used logs in while its account is held' : 'no markSecr of '.\nyu\Access\LoginMark::KEYS_SIZE.' characters: the hold of an account also stops its owner; "settings makeSecr markSecr" makes one' );
		// The language of the mails of a user who has none
		$dfltTag = strtolower( trim(str_replace('_', '-', strval($nyuConf->obtainRowValue('SYSTEM', 'DEF_LANG') ?? ''))) );
		if( $dfltTag!=='' && !Language::isValidLangTag($dfltTag) ){
			$this->addCheckResult('mail language', 'fail', 'DEF_LANG is not a language tag (en, fr, fr-ch): the mails are written in en');
		}
	}

	/**
	 * Checks the access mails of a block of the native userbase: the mail
	 * account 'mailAcnt' names, the front-end address of 'linkTmpl', and a
	 * 'signupOn' that no mail can confirm.
	 *
	 * @param String $blckNamedTxt Name of the block.
	 * @param Array  $blckValueArr Its values.
	 */
	protected function validateMailer( String $blckNamedTxt, Array $blckValueArr ): Void {
		$acntNme = $blckValueArr['mailAcnt'] ?? null;
		$hasMail = is_string($acntNme) && $acntNme!=='';
		if( $hasMail ){
			try{
				$acntSet = Mailer::acntSetupArray($acntNme);
				$this->addCheckResult( 'access mails '.$blckNamedTxt, 'fine', 'through the account '.$acntNme.' ('.$acntSet['sendWith'].')' );
			}catch( \Throwable $e ){
				$this->addCheckResult('access mails '.$blckNamedTxt, 'fail', 'mailAcnt names no account of conf/mailer.json: '.$e->getMessage());
			}
		}elseif( $acntNme!==null && $acntNme!=='' ){
			$this->addCheckResult('access mails '.$blckNamedTxt, 'fail', 'mailAcnt is the name of a mail account, as a text');
		}
		$lnkTmpl = $blckValueArr['linkTmpl'] ?? null;
		if( $lnkTmpl!==null && $lnkTmpl!=='' && ( !is_string($lnkTmpl) || !preg_match('#^https?://[^\s]+$#Di', $lnkTmpl) || strpos($lnkTmpl, '%code%')===false ) ){
			$this->addCheckResult('access link '.$blckNamedTxt, 'fail', 'linkTmpl is an http(s) address holding %code% (and %kind%, %user% as needed)');
		}
		if( array_key_exists('codeMins', $blckValueArr) && (!is_int($blckValueArr['codeMins']) || $blckValueArr['codeMins']<1) ){
			$this->addCheckResult('access codes '.$blckNamedTxt, 'fail', 'codeMins is the minutes a mailed code lasts: a whole number, 1 at least');
		}
		if( ($blckValueArr['signupOn'] ?? false)===true && !$hasMail ){
			$this->addCheckResult('sign-ups '.$blckNamedTxt, 'warn', 'signupOn is on, but no mailer confirms the addresses: nobody can sign up');
		}
		// The limits of the sign-ups : how many wait at once, how many one client makes, the counts the managers are told at
		$regFlaw = Array();
		foreach( Array('maxQueue', 'maxPerIp') as $regKey ){
			if( array_key_exists($regKey, $blckValueArr) && (!is_int($blckValueArr[$regKey]) || $blckValueArr[$regKey]<1) ){
				$regFlaw[] = $regKey.' is a whole number, 1 at least';
			}
		}
		if( array_key_exists('alertsAt', $blckValueArr) && ( !is_array($blckValueArr['alertsAt']) || array_values($blckValueArr['alertsAt'])!==$blckValueArr['alertsAt'] || count(array_filter($blckValueArr['alertsAt'], function( Mixed $warningCount ): Bool { return is_int($warningCount) && $warningCount>0; }))!==count($blckValueArr['alertsAt']) ) ){
			$regFlaw[] = 'alertsAt is a list of whole numbers';
		}
		if( !empty($regFlaw) ){
			$this->addCheckResult('sign-up limits '.$blckNamedTxt, 'fail', implode('; ', $regFlaw));
		}
	}

	/**
	 * Checks what binds the tokens of a block to this project: the
	 * 'issuerId' (a text) and the 'audience' (a text or a list of texts) of a
	 * block of tokens, the 'audience' of the application keys and the
	 * 'issuerId' of each application, and the previous public key ('prevFile')
	 * still checking the tokens signed before the keys were replaced.
	 * Tokens bound to no project are told; values of the wrong kind fail.
	 *
	 * @param String $blckNamedTxt Name of the block.
	 * @param Array  $blckValueArr Its values.
	 */
	protected function validateClaims( String $blckNamedTxt, Array $blckValueArr ): Void {
		$appKeys = array_key_exists('appsList', $blckValueArr);
		$clmFlaw = Array();
		$adncVal = $blckValueArr['audience'] ?? Array();
		if( !is_string($adncVal) && !( is_array($adncVal) && array_values($adncVal)===$adncVal && count(array_filter($adncVal, 'is_string'))===count($adncVal) ) ){
			$clmFlaw[] = 'audience is a text or a list of texts';
		}
		if( !$appKeys && !is_string($blckValueArr['issuerId'] ?? '') ){
			$clmFlaw[] = 'issuer is a text';
		}
		foreach( ($appKeys && is_array($blckValueArr['appsList'])) ? $blckValueArr['appsList'] : Array() as $appKey => $appSet ){
			if( is_array($appSet) && array_key_exists('issuerId', $appSet) && !is_string($appSet['issuerId']) ){
				$clmFlaw[] = 'the issuer of the application '.json_encode(strval($appKey)).' is a text';
			}
		}
		$oldPath = trim(strval($blckValueArr['prevFile'] ?? ''), '/\\ ');
		if( $oldPath!='' ){
			$oldText = is_file(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $oldPath)) ? strval(@file_get_contents(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $oldPath))) : '';
			$prevObj = ($oldText!='' && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public($oldText) : false;
			$oldInfo = ($prevObj!==false) ? openssl_pkey_get_details($prevObj) : false;
			if( !is_array($oldInfo) || ($oldInfo['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($oldInfo['bits'] ?? 0)<JWT::RSAS_BITS ){
				$clmFlaw[] = 'prevFile is not the public RSA key of '.JWT::RSAS_BITS.' bits at least: empty it, or "settings makeSecr jwtToken" writes it';
			}elseif( ($blckValueArr['signAlgo'] ?? '')!='RS256' ){
				$this->addCheckResult('previous key '.$blckNamedTxt, 'warn', 'prevFile is only read with RS256');
			}elseif( !is_int($blckValueArr['retireAt'] ?? null) || $blckValueArr['retireAt'] + max(1, intval($blckValueArr['lifeSecs'] ?? 3600))<time() ){
				// Past the time the tokens it signed could last, or never retired : it checks nothing any more
				$this->addCheckResult('previous key '.$blckNamedTxt, 'warn', 'prevFile checks no token any more (no retire time, or past it by lifeSecs): it can be removed');
			}
		}
		if( !empty($clmFlaw) ){
			$this->addCheckResult('token claims '.$blckNamedTxt, 'fail', implode('; ', $clmFlaw));
			return;
		}
		$hasAdnc = ( is_string($adncVal) ? trim($adncVal)!='' : count(array_filter(array_map('trim', $adncVal)))>0 );
		$hasIssr = $appKeys || trim(strval($blckValueArr['issuerId'] ?? ''))!='';
		if( !$hasAdnc || !$hasIssr ){
			$this->addCheckResult('token claims '.$blckNamedTxt, 'warn', $appKeys
				? 'no audience: the tokens of the applications are not bound to this project; "settings setValue accsConf '.$blckNamedTxt.'.audience" sets it'
				: 'no issuer or no audience: the tokens are not bound to this project; "settings setValue accsConf '.$blckNamedTxt.'.issuer" and ".audience" set them');
			return;
		}
		$this->addCheckResult('token claims '.$blckNamedTxt, 'fine', $appKeys ? 'audience '.json_encode($adncVal) : 'issuer '.json_encode($blckValueArr['issuerId']).', audience '.json_encode($adncVal));
	}

	/**
	 * Checks the keys of a block signing its tokens with RS256: a private
	 * key that signs and the public key checking them, or the public key
	 * alone when the tokens are issued by another server. A key must be an
	 * RSA key of JWT::RSAS_BITS bits at least, and the public key the one of
	 * the private key; a private key readable by the other users of the
	 * server is told.
	 *
	 * @param String $blckNamedTxt Name of the block.
	 * @param Array  $blckValueArr Its values.
	 */
	protected function validateRsakey( String $blckNamedTxt, Array $blckValueArr ): Void {
		$keyText = Array();
		foreach( Array('privFile', 'publFile') as $keyNme ){
			$keyPath = trim(strval($blckValueArr[$keyNme] ?? ''), '/\\ ');
			$keyPath = ($keyPath=='') ? '' : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $keyPath);
			$keyText[$keyNme] = ($keyPath!='' && is_file($keyPath)) ? strval(@file_get_contents($keyPath)) : '';
			if( $keyNme=='privFile' && $keyText[$keyNme]!='' && DS=='/' && (fileperms($keyPath) & 0044)!=0 ){
				$this->addCheckResult('key rights '.$blckNamedTxt, 'warn', 'the private key can be read by other users of the server: chmod 600 '.strval($blckValueArr['privFile']));
			}
		}
		if( !function_exists('openssl_pkey_get_public') ){
			$this->addCheckResult('token keys '.$blckNamedTxt, 'fail', 'RS256 needs the openssl extension');
			return;
		}
		$privKey = ($keyText['privFile']!='') ? @openssl_pkey_get_private($keyText['privFile']) : false;
		$publObj = ($keyText['publFile']!='') ? @openssl_pkey_get_public($keyText['publFile']) : false;
		$keyBits = Array();
		foreach( Array($privKey, $publObj) as $keyObj ){
			$keyInfo = ($keyObj!==false) ? openssl_pkey_get_details($keyObj) : false;
			if( is_array($keyInfo) ){
				$keyBits[] = (($keyInfo['type'] ?? null)===OPENSSL_KEYTYPE_RSA) ? intval($keyInfo['bits'] ?? 0) : 0;
			}
		}
		// A public key laid over the server's own (a bundle of another pair) no longer checks what its private key signs
		$pairsOk = ($privKey===false || $publObj===false) || strval(openssl_pkey_get_details($privKey)['key'] ?? '')===strval(openssl_pkey_get_details($publObj)['key'] ?? '');
		if( ($keyText['privFile']!='' && $privKey===false) || ($keyText['publFile']!='' && $publObj===false) ){
			$this->addCheckResult('token keys '.$blckNamedTxt, 'fail', 'a key file is not a PEM key: "settings makeSecr jwtToken" makes a new pair');
		}elseif( !$pairsOk ){
			$this->addCheckResult('token keys '.$blckNamedTxt, 'fail', 'the public key is not the one of the private key: "settings makeSecr jwtToken" makes a new pair');
		}elseif( empty($keyBits) ){
			$this->addCheckResult('token keys '.$blckNamedTxt, 'fail', 'missing: "settings makeSecr jwtToken" makes them');
		}elseif( min($keyBits)<JWT::RSAS_BITS ){
			$this->addCheckResult('token keys '.$blckNamedTxt, 'fail', 'not an RSA key of '.JWT::RSAS_BITS.' bits at least: "settings makeSecr jwtToken" makes a new pair');
		}elseif( $privKey===false && ($blckValueArr['external'] ?? false)!==true && !empty($lgnConf = $this->accessSettings('loginSet')) && strval($lgnConf['sessWith'] ?? 'jwtToken')=='jwtToken' && strcasecmp(strval($lgnConf['sessConf'] ?? 'jwtToken'), $blckNamedTxt)==0 ){
			// The login issues tokens with this block, which has no private key : a public key alone, brought by a bundle, would trust the tokens of where it was built
			$nyuConf = $GLOBALS['nyuConfig'] ?? null;
			$isFinal = in_array(strtoupper(strval(($nyuConf!==null) ? $nyuConf->obtainRowValue('SYSTEM', 'ENV_NAME') : '')), Array('PREV', 'PROD'), true);
			$this->addCheckResult('token keys '.$blckNamedTxt, $isFinal ? 'fail' : 'warn', 'the login issues tokens with this block, which has no private key: "settings makeSecr jwtToken" makes a pair; "external": true when the tokens come from another server');
		}else{
			$this->addCheckResult('token keys '.$blckNamedTxt, 'fine', ($privKey===false) ? 'checks tokens only: they are issued by another server' : 'set, '.max($keyBits).' bits');
		}
	}

	/**
	 * Checks every configured database: its extension is loaded, and it
	 * answers.
	 */
	public function checkDatabases(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$allBase = ($nyuConf!==null) ? $nyuConf->readValueByKey('database') : null;
		if( !is_array($allBase) || empty($allBase) ){
			$needsDb = strval($this->accessSettings('loginSet')['authWith'] ?? '')=='nativeDb';
			$this->addCheckResult( 'databases', $needsDb ? 'fail' : 'fine', $needsDb ? 'none is configured, and the login uses the native userbase' : 'none is configured' );
			return;
		}
		$allBase = array_change_key_case($allBase, CASE_LOWER);
		foreach( (array_key_exists('dbengine', $allBase) ? Array('') : array_keys($allBase)) as $dbName ){
			$baseNme = strval($dbName);
			$rowName = 'database'.( $baseNme!='' ? ' '.$baseNme : '' );
			try{
				$baseTyp = strtolower( strval(DBCon::baseSetupArray($baseNme)['dbEngine']) );
				$extName = self::BASE_EXTS[$baseTyp] ?? '';
				if( $extName!='' && !extension_loaded($extName) ){
					$this->addCheckResult($rowName, 'fail', 'the extension '.$extName.' is not loaded');
					continue;
				}
				$dbHndlr = DBCon::linkNamedDbCon($baseNme)->readConnection();
				if( $dbHndlr===null ){
					$this->addCheckResult($rowName, 'fine', 'configured ('.$baseTyp.'); not an SQL database, not asked');
					continue;
				}
				$isReady = $dbHndlr->sendBuiltQuery('SELECT 1;');
				$this->addCheckResult( $rowName, $isReady ? 'fine' : 'fail', $isReady ? 'answers ('.$baseTyp.')' : 'does not answer: '.strval($dbHndlr->finalErrorText()) );
			}catch( \Throwable $e ){
				$this->addCheckResult($rowName, 'fail', 'does not answer: '.$e->getMessage());
			}
		}
	}

	/**
	 * Checks the store of the users the login names: the users table of
	 * the native userbase, the users file, or the htpasswd file.
	 */
	public function validateStores(): Void {
		$lgnConf = $this->accessSettings('loginSet');
		$authNme = strval($lgnConf['authWith'] ?? '');
		$blckCfg = $this->accessSettings( strval($lgnConf['authConf'] ?? '') );
		if( $authNme=='nativeDb' ){
			try{
				$dbHndlr = DBCon::linkNamedDbCon( strval($blckCfg['linkName'] ?? '') )->readConnection();
				$tblName = strval($blckCfg['useTable'] ?? 'users');
				$hasTble = ($dbHndlr!==null) && $dbHndlr->tableInfoArray($tblName)!==false;
				$this->addCheckResult( 'users', $hasTble ? 'fine' : 'fail', $hasTble ? 'the table '.$tblName.' is there' : 'the table '.$tblName.' is missing: "installs userBase" creates it' );
			}catch( \Throwable $e ){
				$this->addCheckResult('users', 'fail', 'the userbase cannot be read: '.$e->getMessage());
			}
		}elseif( $authNme=='userFile' ){
			$chckObj = new UserList($blckCfg);
			$chckObj->usrIdentityArr('-');
			$isReady = ($chckObj->finalErrorText()!='Credential store unavailable');
			$this->addCheckResult( 'users', $isReady ? 'fine' : 'fail', $isReady ? 'the users file is read' : 'the users file is missing or cannot be used: "installs userBase" creates it' );
		}elseif( $authNme=='htpasswd' ){
			$pwdFile = trim( strval($blckCfg['passFile'] ?? ''), '/\\ ' );
			$isReady = ($pwdFile!='') && is_file(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $pwdFile));
			$this->addCheckResult( 'users', $isReady ? 'fine' : 'fail', $isReady ? 'the htpasswd file is there' : 'the htpasswd file is missing: "settings makeSecr htpasswd <user>" writes it' );
		}
	}

	/**
	 * Checks what must not be left on where real users come: errors
	 * shown, proxies trusted from anywhere, secrets readable by others.
	 */
	public function validateUnsafe(): Void {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$iniConf = ($nyuConf!==null) ? ($nyuConf->readValueByKey('system') ?: Array()) : Array();
		$envName = strtoupper( strval($iniConf['ENV_NAME'] ?? '') );
		$showErr = in_array( strtoupper(strval($iniConf['ERR_SHOW'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true );
		$errStts = ( $showErr && in_array($envName, Array('PROD', 'PREV'), true) ) ? 'warn' : 'fine';
		// In production the setting is not followed : nothing of an error is shown there
		$errNote = ($errStts=='fine') ? '' : ( $envName=='PROD' ? ', where it is ignored: set it to FALSE' : ': the details of an error are shown to the clients' );
		$this->addCheckResult( 'errors', $errStts, 'ERR_SHOW is '.( $showErr ? 'on' : 'off' ).' in the environment '.( $envName!='' ? $envName : '(not named)' ).$errNote );
		// A level that cannot be read stops every request at its start
		try{
			$errrLvl = (new Loader(Array('settings'=>Array('confType'=>'all'))))->errorConfigNum();
			$this->addCheckResult( 'error level', 'fine', ($errrLvl==E_ALL) ? 'every error is reported (E_ALL)' : 'ERR_LEVL reports level '.$errrLvl.' of '.E_ALL );
		}catch( \RuntimeException $e ){
			$this->addCheckResult( 'error level', 'fail', $e->getMessage().': no request can be served' );
		}
		if( preg_match('#(^|[\s,])(0\.0\.0\.0|::)/0#', strval($iniConf['PRX_LIST'] ?? '')) ){
			$this->addCheckResult('proxies', 'fail', 'PRX_LIST trusts every address: any client can then give itself the address it wants');
		}
		if( array_key_exists('REQ_SIZE', $iniConf) && intval($iniConf['REQ_SIZE'])<1 ){
			$this->addCheckResult('requests', 'warn', 'REQ_SIZE is 0: a request body of any size is read in memory');
		}
		$envPath = TOP_DIR.DS.'.env';
		if( DIRECTORY_SEPARATOR=='/' && is_file($envPath) && (fileperms($envPath) & 0077)!=0 ){
			$this->addCheckResult('.env', 'warn', 'the file of the secrets can be read by other users of the system: chmod 600 .env');
		}
		// The limiter is checked for what it would do, not for what it holds
		$lmtConf = $this->accessSettings('tryLimit');
		if( !empty($lmtConf) && intval($lmtConf['maxTries'] ?? 10)<1 ){
			$this->addCheckResult('limits', 'warn', 'the limit by address is off (maxTries 0): wrong credentials can be tried without end');
		}
	}

	/**
	 * Tells what is wrong with the carrier and the check an 'security' or a
	 * 'loginSet' block names: one that does not exist, or a block of
	 * settings the access config does not hold.
	 *
	 * @param Array $accessConfig The block, with its 'sessWith', 'sessConf', 'authWith' and 'authConf' keys.
	 * @return String What is wrong, empty when nothing is.
	 */
	protected function coupleFlawsTxt( Array $accessConfig ): String {
		$sessNme = strval($accessConfig['sessWith'] ?? '');
		if( Checker::sessnClassText($sessNme)===null ){
			return 'unknown session carrier "'.$sessNme.'"';
		}
		$authNme = $accessConfig['authWith'] ?? null;
		if( $authNme!==null && Checker::authClssString(strval($authNme))===null ){
			return 'unknown credential check "'.strval($authNme).'"';
		}
		foreach( Array('sessConf', 'authConf') as $cnfKey ){
			$blckNme = strval($accessConfig[$cnfKey] ?? '');
			if( $blckNme!='' && empty($this->accessSettings($blckNme)) ){
				return 'conf/access.json has no "'.$blckNme.'" block, named by '.$cnfKey;
			}
		}
		return '';
	}

	/**
	 * Reads a block of the ACCESS config.
	 *
	 * @param String $blckNamedTxt Name of the block.
	 * @return Array Its values, empty when there is no such block.
	 */
	protected function accessSettings( String $blckNamedTxt ): Array {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$blckCfg = ($nyuConf!==null && $blckNamedTxt!='') ? $nyuConf->obtainRowValue('ACCESS', strtoupper($blckNamedTxt)) : null;
		return is_array($blckCfg) ? $blckCfg : Array();
	}

	/**
	 * Records the result of a check.
	 *
	 * @param String $checkSubject What was checked.
	 * @param String $checkOutcome 'fine', 'warn' or 'fail'.
	 * @param String $foundDetails What was found.
	 */
	protected function addCheckResult( String $checkSubject, String $checkOutcome, String $foundDetails ): Void {
		$this->resultRows[] = Array($checkSubject, $checkOutcome, $foundDetails);
	}
}
