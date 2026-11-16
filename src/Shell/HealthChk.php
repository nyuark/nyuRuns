<?php

namespace nyu\Shell;

use nyu\Access\Checker;
use nyu\Access\Totp;
use nyu\Access\Auth\UserList;
use nyu\Access\Carr\JWT;
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
	public function treatAllChecks(): Array {
		$this->checkPhpServer();
		$this->checkFldrWrite();
		$this->checkEveryPset();
		$this->checkAllRoutes();
		$this->checkEntryList();
		$this->checkDoorLines();
		$this->checkAccessCfg();
		$this->checkDatabases();
		$this->checkUserStore();
		$this->checkCapsOfDvc();
		$this->checkPrjctKind();
		$this->checkUnsafeCfg();
		return $this->resultRows;
	}

	/**
	 * Gives the rows of the checks run so far.
	 *
	 * @return Array The rows: what was checked, its state and what was found.
	 */
	public function getCheckedRows(): Array {
		return $this->resultRows;
	}

	/**
	 * Tells whether a check failed.
	 *
	 * @return Bool True when at least one row is a 'fail'.
	 */
	public function hasCheckFailed(): Bool {
		return in_array('fail', array_column($this->resultRows, 1), true);
	}

	/**
	 * Checks the server: the version of PHP, the fixes it holds, the
	 * upload progress of the sessions, and its extensions. A release older
	 * than the fix of its branch is a warning only: the distributions that
	 * backport a fix keep the number of the release.
	 */
	public function checkPhpServer(): Void {
		$this->addCheckResult( 'php', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'fine' : 'fail', 'PHP '.PHP_VERSION.', 8.2 at least is needed' );
		$phpVersn = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
		if( isset(self::SAFE_VERS[$phpVersn]) ){
			$isPatchd = version_compare(PHP_VERSION, self::SAFE_VERS[$phpVersn], '>=');
			$this->addCheckResult( 'php patch', $isPatchd ? 'fine' : 'warn', $isPatchd ? 'php2shell fixed' : 'php2shell (CVE-2026-109618, CVE-2026-109619) is fixed from '.self::SAFE_VERS[$phpVersn].': update, unless the fix was backported' );
		}
		$isUpldOn = filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN);
		$this->addCheckResult( 'upload progress', $isUpldOn ? 'warn' : 'fine', $isUpldOn ? 'on: set session.upload_progress.enabled = 0 in php.ini (CVE-2026-115560), the framework does not use it' : 'off' );
		$this->addCheckResult( 'extension json', extension_loaded('json') ? 'fine' : 'fail', extension_loaded('json') ? 'loaded' : 'not loaded: nothing can be read or answered' );
		$lackList = array_values( array_filter(self::MORE_EXTS, function( String $extensionNme ): Bool { return !extension_loaded($extensionNme); }) );
		$this->addCheckResult( 'extensions', empty($lackList) ? 'fine' : 'warn', empty($lackList) ? 'the optional ones are loaded' : 'optional, not loaded: '.implode(', ', $lackList) );
	}

	/**
	 * Checks the folders the framework writes to: they are created when
	 * missing, and must be writable. A config cache older than a file it
	 * comes from (conf, a preset, a route file) was left by a hand edit.
	 */
	public function checkFldrWrite(): Void {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$limitCfg = ($nyuConfg!==null) ? $nyuConfg->getNestedValue('ACCESS', 'TRYLIMIT') : null;
		$limitNme = trim( strval(is_array($limitCfg) ? ($limitCfg['dataPath'] ?? '') : ''), '/\\ ' );
		$fieldLst = Array(
			strval( (new Loader(Array('settings'=>Array('confType'=>'all'))))->logConfigArray()['path'] ),
			TOP_DIR.DS.( $limitNme!='' ? str_replace(Array('/', '\\'), DS, $limitNme) : 'temp'.DS.'limits' ),
			TOP_DIR.DS.'temp'.DS.'cache',
			TOP_DIR.DS.'temp'.DS.'archives'
		);
		foreach( array_unique($fieldLst) as $onePth ){
			if( !is_dir($onePth) ){
				@mkdir($onePth, 0770, true);
			}
			$canWrite = is_dir($onePth) && is_writable($onePth);
			$this->addCheckResult( 'folder '.str_replace(DS, '/', ltrim(substr($onePth, strlen(TOP_DIR)), '/\\')), $canWrite ? 'fine' : 'fail', $canWrite ? 'writable' : 'missing or not writable' );
		}
		// The rights of the secret files are read on Linux only (see chkRsakey) : on Windows they are told to be the host's to set
		if( DS=='\\' ){
			$this->addCheckResult('file rights', 'warn', 'the rights of the secret files are not checked on Windows: limit conf and temp to the web server\'s account (icacls)');
		}
		// The descriptions of the tables are kept in the config cache only
		$iniConfg = ($nyuConfg!==null) ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		if( in_array(strtoupper(strval($iniConfg['TBL_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) && !in_array(strtoupper(strval($iniConfg['CNF_CACH'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true) ){
			$this->addCheckResult('table cache', 'warn', 'TBL_CACH is TRUE, but CNF_CACH is not: the descriptions of the tables are not kept');
		}
		// The oldest cache file, against the newest file the config is read from
		$cacheDte = 0;
		foreach( (array)glob(TOP_DIR.DS.str_replace('/', DS, ConfCache::CACH_PATH).DS.'config-*.cache') as $oneFil ){
			$cacheDte = ($cacheDte==0) ? intval(@filemtime(strval($oneFil))) : min($cacheDte, intval(@filemtime(strval($oneFil))));
		}
		if( $cacheDte==0 ){
			$this->addCheckResult('config cache', 'fine', 'none: the config files are read on each request, or a request will cache them (CNF_CACH)');
			return;
		}
		$srceUnix = 0;
		foreach( array_merge( (array)glob(TOP_DIR.DS.'conf'.DS.'*.ini'), (array)glob(TOP_DIR.DS.'conf'.DS.'*.json'), (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps'),
			(array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*.rte'), (array)glob(TOP_DIR.DS.'conf'.DS.'routes'.DS.'*'.DS.'*.rte'), defined('RUN_DIR') ? (array)glob(RUN_DIR.DS.'*'.DS.'routes.rte') : Array() ) as $oneFil ){
			$srceUnix = max($srceUnix, intval(@filemtime(strval($oneFil))));
		}
		$this->addCheckResult( 'config cache', ($srceUnix>$cacheDte) ? 'warn' : 'fine', ($srceUnix>$cacheDte) ? 'older than a config, preset or route file (a Run\'s routes.rte too) changed since: run "setting clrCache", the next request reads the files' : 'up to date' );
	}

	/**
	 * Checks every preset: it loads, the carrier and the check it names
	 * exist with their blocks, and the roles it asks for can be applied.
	 */
	public function checkEveryPset(): Void {
		$psetList = (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps');
		if( empty($psetList) ){
			$this->addCheckResult('presets', 'fail', 'conf/presets holds no preset');
		}
		foreach( $psetList as $oneFil ){
			$psetName = basename(strval($oneFil), '.rps');
			// The example only explains the keys: they are checked, it is never loaded
			if( strcasecmp($psetName, Preset::XMPL_NAME)==0 ){
				try{
					new Preset($psetName);
					$this->addCheckResult('preset '.$psetName, 'fine', 'its keys are known; never loaded, it only explains them');
				}catch( \Throwable $e ){
					$this->addCheckResult('preset '.$psetName, 'fail', $e->getMessage());
				}
				continue;
			}
			try{
				$setupObj = (new Preset($psetName))->makeConfLoader();
				$accsConf = $setupObj->setAccessArray();
			}catch( \Throwable $e ){
				$this->addCheckResult('preset '.$psetName, 'fail', $e->getMessage());
				continue;
			}
			// A session cookie goes with every request of the browser : any site allowed to call is a risk
			$rndrConf = $setupObj->getRegistryKey('response');
			$headList = array_change_key_case( (array)(is_array($rndrConf) ? ($rndrConf['headList'] ?? Array()) : Array()), CASE_LOWER );
			if( ($accsConf['carrWith'] ?? '')=='phpSessn' && trim(strval($headList['access-control-allow-origin'] ?? ''))=='*' && empty($rndrConf['corsList']) ){
				$this->addCheckResult('origins '.$psetName, 'warn', 'every origin is allowed while the session travels in a cookie: list the origins under "corsList"');
			}elseif( ( ($accsConf['carrWith'] ?? '')=='jwtToken' || strval($accsConf['appsConf'] ?? '')!='' ) && trim(strval($headList['access-control-allow-origin'] ?? ''))=='*' && empty($rndrConf['corsList']) ){
				// A token is not sent by the browser on its own, but any site may then read what it opens
				$this->addCheckResult('origins '.$psetName, 'warn', 'every origin is allowed while the preset reads tokens: list the origins under "corsList"');
			}
			// "public" tells an open preset is meant to be: on one that checks access, it says nothing
			if( ($accsConf['isPublic'] ?? false)===true && ($accsConf['required'] ?? true)!==false ){
				$this->addCheckResult('public '.$psetName, 'warn', '"public" is only read on an open preset ("required": false): this one checks access');
			}
			$flawText = '';
			if( !empty($accsConf) && ($accsConf['required'] ?? true)!==false ){
				$flawText = $this->checkPairFlaws($accsConf);
			}
			// The routes of the preset, for the paths its roles name
			$routeCfg = $setupObj->getRegistryKey('dispatch');
			$reqtInst = ActCaller::makeRequestLst('GET', '/')[0];
			$chkrInst = new Checker($accsConf, $reqtInst, new Registry(Array()), new Router(is_array($routeCfg) ? $routeCfg : Array(), $reqtInst));
			if( $flawText=='' && !empty($accsConf) && ($accsConf['required'] ?? true)!==false ){
				$flawText = $chkrInst->checkPairFlaws();
			}
			if( $flawText=='' ){
				$flawText = $chkrInst->checkRoleFlaws();
			}
			$this->addCheckResult( 'preset '.$psetName, ($flawText=='') ? 'fine' : 'fail', ($flawText=='') ? 'loads' : $flawText );
		}
	}

	/**
	 * Checks the routes each preset serves, the Runs' with their mounts:
	 * two Runs declaring one path ('route clash': fail, the first in name
	 * order answers), a site route over a Run route, on its path or by a
	 * pattern matching it ('route shadow': warn, the site's answers), and an entry of openList or minRoles naming no route
	 * ('access route': warn; fail for a path of minRoles, which refuses every
	 * request of the preset): a path no route declares, a Run that does not
	 * exist, or an action that is not one of the Run, or that a Run
	 * declaring its routes does not list. One row for each, naming the
	 * presets concerned.
	 */
	public function checkAllRoutes(): Void {
		$psetList = (array)glob(TOP_DIR.DS.'conf'.DS.'presets'.DS.'*.rps');
		$runsList = defined('RUN_DIR') ? array_map('basename', array_filter((array)glob(RUN_DIR.DS.'*'), 'is_dir')) : Array();
		$clshList = Array();
		$shdwList = Array();
		$lostList = Array();
		$roleList = Array();
		foreach( $psetList as $oneFil ){
			$psetName = basename(strval($oneFil), '.rps');
			try{
				$setupObj = (new Preset($psetName))->makeConfLoader();
				$accsConf = $setupObj->setAccessArray();
			}catch( \Throwable $e ){
				// A preset that does not load is told by its own row
				continue;
			}
			$routeCfg = $setupObj->getRegistryKey('dispatch');
			$routInst = new Router( is_array($routeCfg) ? $routeCfg : Array(), ActCaller::makeRequestLst('GET', '/')[0] );
			$routeDct = $routInst->loadRoutesList();
			foreach( $routInst->readRunsRoutes() as $oneSet ){
				$ownTitle = strval($routeDct[$oneSet['routPath']]['routOwnr'] ?? '');
				if( $ownTitle=='site' ){
					$shdwList[] = $psetName.': /'.$oneSet['routPath'].' of '.$oneSet['routOwnr'];
				}elseif( $ownTitle!==$oneSet['routOwnr'] ){
					$clshList[] = $psetName.': /'.$oneSet['routPath'].' of '.$ownTitle.' and '.$oneSet['routOwnr'];
				}else{
					// A pattern of the site over it answers first
					foreach( array_keys(array_combine(array_keys($routeDct), array_column($routeDct, 'routOwnr')), 'site', true) as $oneKey ){
						if( $routInst->matchRoutePath(strval($oneKey), strval($oneSet['routPath']))!==null ){
							$shdwList[] = $psetName.': /'.$oneSet['routPath'].' of '.$oneSet['routOwnr'].' under /'.$oneKey;
							break;
						}
					}
				}
			}
			// What openList and minRoles name must be served by this preset
			$roleKeys = array_map('strval', array_keys(is_array($accsConf['minRoles'] ?? null) ? $accsConf['minRoles'] : Array()));
			foreach( array_merge(array_values((array)($accsConf['openList'] ?? Array())), $roleKeys) as $oneKey ){
				$oneKey = trim(strval($oneKey));
				if( substr($oneKey, 0, 1)=='/' ){
					if( !isset($routeDct[trim($oneKey, '/')]) ){
						// A path of minRoles naming no route refuses every request : a failure, not a warning
						if( in_array($oneKey, $roleKeys, true) ){
							$roleList[] = $psetName.': '.$oneKey.' (no route on that path)';
						}else{
							$lostList[] = $psetName.': '.$oneKey.' (no route on that path)';
						}
					}
					continue;
				}
				$actParte = explode('/', trim($oneKey, '/'), 2);
				$ownTitle = strval( current(preg_grep('/^'.preg_quote($actParte[0], '/').'$/i', $runsList)) ?: '' );
				if( $ownTitle=='' ){
					$lostList[] = $psetName.': '.$oneKey.' (no such application)';
					continue;
				}
				if( ($actParte[1] ?? '*')=='*' ){
					continue;
				}
				if( is_file(RUN_DIR.DS.$ownTitle.DS.'routes.rte') ){
					// A Run declaring its routes is reached through them only (or a site alias): one of them names the action
					if( empty(preg_grep('/^'.preg_quote($ownTitle.'/'.$actParte[1], '/').'$/i', array_column($routInst->listAllTargets(), 'routTarg'))) ){
						$lostList[] = $psetName.': '.$oneKey.' (not listed in the routes of '.$ownTitle.')';
					}
					continue;
				}
				$classNme = '\\nyu\\Runs\\'.$ownTitle.'\\Actions';
				if( !class_exists($classNme) || !$classNme::hasActionNamed($actParte[1]) ){
					$lostList[] = $psetName.': '.$oneKey.' (not an action of '.$ownTitle.')';
				}
			}
		}
		$this->addCheckResult( 'route clash', empty($clshList) ? 'fine' : 'fail', empty($clshList) ? 'no two applications declare one path' : 'declared by two applications, the first in name order answers: '.implode('; ', array_unique($clshList)) );
		$this->addCheckResult( 'route shadow', empty($shdwList) ? 'fine' : 'warn', empty($shdwList) ? 'no site route over a route of an application' : 'a site route answers in place of the application\'s: '.implode('; ', array_unique($shdwList)) );
		$totlLost = array_unique( array_merge($roleList, $lostList) );
		$this->addCheckResult( 'access route', !empty($roleList) ? 'fail' : (empty($lostList) ? 'fine' : 'warn'), empty($totlLost) ? 'every entry of openList and minRoles names a route' : 'naming no route: '.implode('; ', $totlLost) );
		// An open Run is reached from its path: a request naming no action runs the one its $dfltAction names, and is a 500 without it.
		// A Run declaring its routes is closed, its "" route saying what its path alone answers: it has no row
		foreach( $runsList as $onePth ){
			$actnPath = RUN_DIR.DS.$onePth.DS.'Actions.php';
			if( is_file(RUN_DIR.DS.$onePth.DS.'routes.rte') || !is_file($actnPath) ){
				continue;
			}
			$classNme = '\\nyu\\Runs\\'.$onePth.'\\Actions';
			try{
				$actnName = class_exists($classNme) ? \nyu\App\Controller::findDfltAction( new \ReflectionClass($classNme) ) : null;
			}catch( \Throwable $e ){
				$this->addCheckResult('default '.$onePth, 'fail', 'Actions.php cannot be loaded: '.$e->getMessage());
				continue;
			}
			if( $actnName===null ){
				continue;
			}
			if( $actnName=='' ){
				$this->addCheckResult('default '.$onePth, 'warn', 'no $dfltAction: a request naming no action is answered 500');
			}elseif( !$classNme::hasActionNamed($actnName) ){
				$this->addCheckResult('default '.$onePth, 'fail', '$dfltAction names no action: '.$actnName);
			}else{
				$this->addCheckResult('default '.$onePth, 'fine', $actnName);
			}
		}
	}

	/**
	 * Checks the entry points of www: the preset each one starts the
	 * Runner with (defaults when it names none) must check access. An open
	 * one ("required": false) fails in PREV and PROD and is told in DEVS and
	 * TEST, unless its security block says it is meant to be ("isPublic": true).
	 * An entry naming its preset at run time cannot be followed: it is told.
	 */
	public function checkEntryList(): Void {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$envrName = strtoupper( strval(($nyuConfg!==null) ? $nyuConfg->getNestedValue('SYSTEM', 'ENV_NAME') : '') );
		$isRemote = in_array($envrName, Array('PREV', 'PROD'), true);
		foreach( (array)glob(TOP_DIR.DS.'www'.DS.'*.php') as $oneFle ){
			$itemText = strval(@file_get_contents(strval($oneFle)));
			$itemName = basename(strval($oneFle), '.php');
			if( !preg_match(\nyu\Shell\Cmds\Config::RUNR_CALL, $itemText, $callPart, PREG_OFFSET_CAPTURE) ){
				continue;
			}
			$psetText = strval($callPart[2][0] ?? '');
			if( $psetText=='' && substr(ltrim(substr($itemText, $callPart[0][1] + strlen($callPart[0][0]))), 0, 1)!==')' ){
				$this->addCheckResult('entry '.$itemName, 'warn', 'the preset is named at run time: check it gives one that checks access');
				continue;
			}
			$psetName = ($psetText=='') ? 'default' : substr($psetText, 1, -1);
			if( strcasecmp($psetName, Preset::XMPL_NAME)==0 ){
				$this->addCheckResult('entry '.$itemName, 'fail', 'starts '.$psetName.', which only explains the keys of a preset and is never loaded: every request is answered 500');
				continue;
			}
			try{
				$accsConf = (new Preset($psetName))->makeConfLoader()->setAccessArray();
			}catch( \Throwable $e ){
				// A preset that does not load is told by its own row
				continue;
			}
			if( ($accsConf['required'] ?? true)!==false ){
				$this->addCheckResult('entry '.$itemName, 'fine', 'starts '.$psetName.', which checks access');
			}elseif( ($accsConf['isPublic'] ?? false)===true ){
				$this->addCheckResult('entry '.$itemName, 'fine', 'starts '.$psetName.', open by intent (isPublic)');
			}else{
				$this->addCheckResult('entry '.$itemName, $isRemote ? 'fail' : 'warn', 'starts '.$psetName.': every request is served without a check; name a preset that checks access, or set "isPublic": true in its security block when the site is meant to be open');
			}
		}
	}

	/**
	 * Checks the map of the presets served, conf/doorways.lst (see
	 * Runner::findPresetName): it fails on a map that cannot be read, a line
	 * naming a preset that does not load or the example, and two lines for
	 * the same host and basePath; it warns on an open preset ("required":
	 * false) whose security block does not say it is meant to be ("isPublic").
	 * Without a map, every request is served by the default preset.
	 */
	public function checkDoorLines(): Void {
		try{
			$rowsList = \nyu\Runner::readDoorwayLst();
		}catch( \RuntimeException $e ){
			$this->addCheckResult('doorways', 'fail', $e->getMessage().': every request is answered 500');
			return;
		}
		if( empty($rowsList) ){
			$this->addCheckResult('doorways', 'fine', 'no map of the presets served: every request goes to the default preset');
			return;
		}
		$hasFlaws = false;
		$seenList = Array();
		foreach( $rowsList as $oneRow ){
			$lineText = $oneRow['hostName'].$oneRow['basePath'].' = '.$oneRow['presetId'];
			$lineClef = $oneRow['hostName'].$oneRow['basePath'];
			if( isset($seenList[$lineClef]) ){
				$this->addCheckResult('doorways', 'fail', $lineText.': a second line for '.$lineClef.', which the first one answers');
				$hasFlaws = true;
				continue;
			}
			$seenList[$lineClef] = true;
			if( strcasecmp($oneRow['presetId'], Preset::XMPL_NAME)==0 ){
				$this->addCheckResult('doorways', 'fail', $lineText.': '.Preset::XMPL_NAME.' only explains the keys of a preset and is never loaded: its requests are answered 500');
				$hasFlaws = true;
				continue;
			}
			try{
				$accsConf = (new Preset($oneRow['presetId']))->makeConfLoader()->setAccessArray();
			}catch( \Throwable $e ){
				$this->addCheckResult('doorways', 'fail', $lineText.': the preset does not load ('.$e->getMessage().')');
				$hasFlaws = true;
				continue;
			}
			if( ($accsConf['required'] ?? true)===false && ($accsConf['isPublic'] ?? false)!==true ){
				$this->addCheckResult('doorways', 'warn', $lineText.': every request is served without a check; set "isPublic": true in its security block when it is meant to be open');
				$hasFlaws = true;
			}
		}
		if( !$hasFlaws ){
			$this->addCheckResult('doorways', 'fine', count($rowsList).' line'.(count($rowsList)>1 ? 's' : '').', each a preset that loads');
		}
	}

	/**
	 * Checks the access config: the login, the secrets signing the
	 * tokens, the API keys, and what is set as unsecure.
	 */
	public function checkAccessCfg(): Void {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$accsVals = ($nyuConfg!==null) ? $nyuConfg->readValueByKey('access') : null;
		if( !is_array($accsVals) || empty($accsVals) ){
			$this->addCheckResult('access', 'fail', 'conf/access.json is missing or empty: "install makeConf" creates it from its example');
			return;
		}
		$loginCfg = $this->findAccessDict('loginSet');
		if( empty($loginCfg) ){
			$this->addCheckResult('access login', 'warn', 'no login block: nobody can sign in through auth/login');
		}else{
			$flawText = $this->checkPairFlaws($loginCfg + Array('authWith'=>'htpasswd', 'carrWith'=>'jwtToken', 'authConf'=>'htpasswd', 'carrConf'=>'jwtToken'));
			// The login hands a name and a password to its check, as the Basic carrier does
			$lognAuth = strval($loginCfg['authWith'] ?? 'htpasswd');
			if( $flawText=='' && isset(Checker::AUTH_LIST[$lognAuth]) && !in_array($lognAuth, Checker::PAIR_LIST['httpBasc'], true) ){
				$flawText = 'the credential check "'.$lognAuth.'" takes no name and password: nobody can sign in through auth/login';
			}
			$this->addCheckResult( 'access login', ($flawText=='') ? 'fine' : 'fail', ($flawText=='') ? strval($loginCfg['authWith'] ?? 'htpasswd').' through '.strval($loginCfg['carrWith'] ?? 'jwtToken') : $flawText );
		}
		// The registry holds the names of the blocks in upper case: they are told as the file writes them
		$blockCas = array_keys( (array)json_decode(strval(@file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json')), true) );
		foreach( $accsVals as $oneKey => $oneVal ){
			if( !is_array($oneVal) ){
				continue;
			}
			$blockNme = strval( array_values(preg_grep('/^'.preg_quote(strval($oneKey), '/').'$/iD', array_map('strval', $blockCas)))[0] ?? strtolower(strval($oneKey)) );
			if( array_key_exists('signAlgo', $oneVal) && !in_array($oneVal['signAlgo'], JWT::ALGO_LIST, true) ){
				$this->addCheckResult('algorithm '.$blockNme, 'fail', 'not one the tokens can be signed with: '.implode(', ', JWT::ALGO_LIST));
			}elseif( ($oneVal['signAlgo'] ?? '')=='RS256' ){
				$this->checkTokenKeys($blockNme, $oneVal);
			}elseif( array_key_exists('hmacSecr', $oneVal) ){
				$secrSize = strlen(strval($oneVal['hmacSecr']));
				$this->addCheckResult( 'secret '.$blockNme, ($secrSize>=self::SECR_SIZE) ? 'fine' : 'fail', ($secrSize>=self::SECR_SIZE) ? 'set' : ( $secrSize==0 ? 'empty' : 'too short' ).': '.self::SECR_SIZE.' characters at least; "setting mkSecret jwtToken" makes one' );
			}
			if( array_key_exists('signAlgo', $oneVal) || array_key_exists('issuerId', $oneVal) && array_key_exists('audience', $oneVal) || array_key_exists('appsList', $oneVal) ){
				$this->checkTknClaims($blockNme, $oneVal);
			}
			if( isset($oneVal['keysList']) && is_array($oneVal['keysList']) && in_array('', array_map('strval', $oneVal['keysList']), true) ){
				$this->addCheckResult('keys '.$blockNme, 'warn', 'an API key of the block is empty; "setting mkSecret keysList" makes one');
			}
			if( ($oneVal['secureOn'] ?? true)===false && array_key_exists('cookName', $oneVal) && strtoupper(strval($nyuConfg->getNestedValue('SYSTEM', 'ENV_NAME')))=='PROD' ){
				$this->addCheckResult('cookie '.$blockNme, 'warn', 'the session cookie also travels over plain HTTP (secureOn is false) in the environment PROD');
			}
			if( array_key_exists('tfaCrypt', $oneVal) ){
				$keyBytes = strlen(strval($oneVal['tfaCrypt']));
				if( $keyBytes==0 ){
					$this->addCheckResult('2FA key '.$blockNme, 'warn', 'empty: the 2FA secrets are stored readable; "setting mkSecret tfaCrypt" makes one');
				}else{
					$this->addCheckResult( '2FA key '.$blockNme, ($keyBytes>=Totp::SEAL_SIZE) ? 'fine' : 'fail', ($keyBytes>=Totp::SEAL_SIZE) ? 'set' : 'too short: '.Totp::SEAL_SIZE.' characters at least; "setting mkSecret tfaCrypt" makes one' );
				}
			}
			if( ($oneVal['weakHash'] ?? false)===true ){
				$this->addCheckResult('weakHash '.$blockNme, 'warn', 'weak password hashes are accepted by this block');
			}
			$this->checkAccsMails($blockNme, $oneVal);
		}
		// The key of the login marks : without one, the hold of an account also stops its owner
		$limitCfg = $this->findAccessDict('tryLimit');
		$markSize = strlen(strval($limitCfg['markSecr'] ?? ''));
		$this->addCheckResult( 'login marks', ($markSize>=\nyu\Access\LoginMark::KEYS_SIZE) ? 'fine' : 'warn', ($markSize>=\nyu\Access\LoginMark::KEYS_SIZE) ? 'set: a client already used logs in while its account is held' : 'no markSecr of '.\nyu\Access\LoginMark::KEYS_SIZE.' characters: the hold of an account also stops its owner; "setting mkSecret markSecr" makes one' );
		// The language of the mails of a user who has none
		$dfaltTag = strtolower( trim(str_replace('_', '-', strval($nyuConfg->getNestedValue('SYSTEM', 'DEF_LANG') ?? ''))) );
		if( $dfaltTag!=='' && !Language::isValidLangTag($dfaltTag) ){
			$this->addCheckResult('mail language', 'fail', 'DEF_LANG is not a language tag (en, fr, fr-ch): the mails are written in en');
		}
	}

	/**
	 * Checks the access mails of a block of the native userbase: the mail
	 * account 'mailAcnt' names, the front-end address of 'linkTmpl', and a
	 * 'signupOn' that no mail can confirm.
	 *
	 * @param String $blockNmdText Name of the block.
	 * @param Array  $blockVlsList Its values.
	 */
	protected function checkAccsMails( String $blockNmdText, Array $blockVlsList ): Void {
		$acctName = $blockVlsList['mailAcnt'] ?? null;
		$hasEmail = is_string($acctName) && $acctName!=='';
		if( $hasEmail ){
			try{
				$acctDict = Mailer::readAccountCfg($acctName);
				$this->addCheckResult( 'access mails '.$blockNmdText, 'fine', 'through the account '.$acctName.' ('.$acctDict['sendWith'].')' );
			}catch( \Throwable $e ){
				$this->addCheckResult('access mails '.$blockNmdText, 'fail', 'mailAcnt names no account of conf/mailer.json: '.$e->getMessage());
			}
		}elseif( $acctName!==null && $acctName!=='' ){
			$this->addCheckResult('access mails '.$blockNmdText, 'fail', 'mailAcnt is the name of a mail account, as a text');
		}
		$linkTmpl = $blockVlsList['linkTmpl'] ?? null;
		if( $linkTmpl!==null && $linkTmpl!=='' && ( !is_string($linkTmpl) || !preg_match('#^https?://[^\s]+$#Di', $linkTmpl) || strpos($linkTmpl, '%sentCode%')===false ) ){
			$this->addCheckResult('access link '.$blockNmdText, 'fail', 'linkTmpl is an http(s) address holding %sentCode% (and %mailKind%, %userName% as needed)');
		}
		if( array_key_exists('codeMins', $blockVlsList) && (!is_int($blockVlsList['codeMins']) || $blockVlsList['codeMins']<1) ){
			$this->addCheckResult('access codes '.$blockNmdText, 'fail', 'codeMins is the minutes a mailed code lasts: a whole number, 1 at least');
		}
		if( ($blockVlsList['signupOn'] ?? false)===true && !$hasEmail ){
			$this->addCheckResult('sign-ups '.$blockNmdText, 'warn', 'signupOn is on, but no mailer confirms the addresses: nobody can sign up');
		}
		// The limits of the sign-ups : how many wait at once, how many one client makes, the counts the managers are told at
		$regrFlaw = Array();
		foreach( Array('maxQueue', 'maxPerIp') as $oneKey ){
			if( array_key_exists($oneKey, $blockVlsList) && (!is_int($blockVlsList[$oneKey]) || $blockVlsList[$oneKey]<1) ){
				$regrFlaw[] = $oneKey.' is a whole number, 1 at least';
			}
		}
		if( array_key_exists('alertsAt', $blockVlsList) && ( !is_array($blockVlsList['alertsAt']) || array_values($blockVlsList['alertsAt'])!==$blockVlsList['alertsAt'] || count(array_filter($blockVlsList['alertsAt'], function( Mixed $warningCount ): Bool { return is_int($warningCount) && $warningCount>0; }))!==count($blockVlsList['alertsAt']) ) ){
			$regrFlaw[] = 'alertsAt is a list of whole numbers';
		}
		if( !empty($regrFlaw) ){
			$this->addCheckResult('sign-up limits '.$blockNmdText, 'fail', implode('; ', $regrFlaw));
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
	 * @param String $blockNmdText Name of the block.
	 * @param Array  $blockVlsList Its values.
	 */
	protected function checkTknClaims( String $blockNmdText, Array $blockVlsList ): Void {
		$applClef = array_key_exists('appsList', $blockVlsList);
		$clmnFlaw = Array();
		$audnVals = $blockVlsList['audience'] ?? Array();
		if( !is_string($audnVals) && !( is_array($audnVals) && array_values($audnVals)===$audnVals && count(array_filter($audnVals, 'is_string'))===count($audnVals) ) ){
			$clmnFlaw[] = 'audience is a text or a list of texts';
		}
		if( !$applClef && !is_string($blockVlsList['issuerId'] ?? '') ){
			$clmnFlaw[] = 'issuer is a text';
		}
		foreach( ($applClef && is_array($blockVlsList['appsList'])) ? $blockVlsList['appsList'] : Array() as $oneKey => $oneSet ){
			if( is_array($oneSet) && array_key_exists('issuerId', $oneSet) && !is_string($oneSet['issuerId']) ){
				$clmnFlaw[] = 'the issuer of the application '.json_encode(strval($oneKey)).' is a text';
			}
		}
		$oldTrail = trim(strval($blockVlsList['prevFile'] ?? ''), '/\\ ');
		if( $oldTrail!='' ){
			$oldStrng = is_file(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $oldTrail)) ? strval(@file_get_contents(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $oldTrail))) : '';
			$prevInst = ($oldStrng!='' && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public($oldStrng) : false;
			$oldArray = ($prevInst!==false) ? openssl_pkey_get_details($prevInst) : false;
			if( !is_array($oldArray) || ($oldArray['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($oldArray['bits'] ?? 0)<JWT::RSAS_BITS ){
				$clmnFlaw[] = 'prevFile is not the public RSA key of '.JWT::RSAS_BITS.' bits at least: empty it, or "setting mkSecret jwtToken" writes it';
			}elseif( ($blockVlsList['signAlgo'] ?? '')!='RS256' ){
				$this->addCheckResult('previous key '.$blockNmdText, 'warn', 'prevFile is only read with RS256');
			}elseif( !is_int($blockVlsList['retireAt'] ?? null) || $blockVlsList['retireAt'] + max(1, intval($blockVlsList['lifeSecs'] ?? 3600))<time() ){
				// Past the time the tokens it signed could last, or never retired : it checks nothing any more
				$this->addCheckResult('previous key '.$blockNmdText, 'warn', 'prevFile checks no token any more (no retire time, or past it by lifeSecs): it can be removed');
			}
		}
		if( !empty($clmnFlaw) ){
			$this->addCheckResult('token claims '.$blockNmdText, 'fail', implode('; ', $clmnFlaw));
			return;
		}
		$hasAudnc = ( is_string($audnVals) ? trim($audnVals)!='' : count(array_filter(array_map('trim', $audnVals)))>0 );
		$hasIssur = $applClef || trim(strval($blockVlsList['issuerId'] ?? ''))!='';
		if( !$hasAudnc || !$hasIssur ){
			$this->addCheckResult('token claims '.$blockNmdText, 'warn', $applClef
				? 'no audience: the tokens of the applications are not bound to this project; "setting setValue accsConf '.$blockNmdText.'.audience" sets it'
				: 'no issuer or no audience: the tokens are not bound to this project; "setting setValue accsConf '.$blockNmdText.'.issuer" and ".audience" set them');
			return;
		}
		$this->addCheckResult('token claims '.$blockNmdText, 'fine', $applClef ? 'audience '.json_encode($audnVals) : 'issuer '.json_encode($blockVlsList['issuerId']).', audience '.json_encode($audnVals));
	}

	/**
	 * Checks the keys of a block signing its tokens with RS256: a private
	 * key that signs and the public key checking them, or the public key
	 * alone when the tokens are issued by another server. A key must be an
	 * RSA key of JWT::RSAS_BITS bits at least, and the public key the one of
	 * the private key; a private key readable by the other users of the
	 * server is told.
	 *
	 * @param String $blockNmdText Name of the block.
	 * @param Array  $blockVlsList Its values.
	 */
	protected function checkTokenKeys( String $blockNmdText, Array $blockVlsList ): Void {
		$keyStrng = Array();
		foreach( Array('privFile', 'publFile') as $oneNme ){
			$keyTrail = trim(strval($blockVlsList[$oneNme] ?? ''), '/\\ ');
			$keyTrail = ($keyTrail=='') ? '' : TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $keyTrail);
			$keyStrng[$oneNme] = ($keyTrail!='' && is_file($keyTrail)) ? strval(@file_get_contents($keyTrail)) : '';
			if( $oneNme=='privFile' && $keyStrng[$oneNme]!='' && DS=='/' && (fileperms($keyTrail) & 0044)!=0 ){
				$this->addCheckResult('key rights '.$blockNmdText, 'warn', 'the private key can be read by other users of the server: chmod 600 '.strval($blockVlsList['privFile']));
			}
		}
		if( !function_exists('openssl_pkey_get_public') ){
			$this->addCheckResult('token keys '.$blockNmdText, 'fail', 'RS256 needs the openssl extension');
			return;
		}
		$privClef = ($keyStrng['privFile']!='') ? @openssl_pkey_get_private($keyStrng['privFile']) : false;
		$publInst = ($keyStrng['publFile']!='') ? @openssl_pkey_get_public($keyStrng['publFile']) : false;
		$clefBits = Array();
		foreach( Array($privClef, $publInst) as $oneObj ){
			$keyArray = ($oneObj!==false) ? openssl_pkey_get_details($oneObj) : false;
			if( is_array($keyArray) ){
				$clefBits[] = (($keyArray['type'] ?? null)===OPENSSL_KEYTYPE_RSA) ? intval($keyArray['bits'] ?? 0) : 0;
			}
		}
		// A public key laid over the server's own (a bundle of another pair) no longer checks what its private key signs
		$isPaired = ($privClef===false || $publInst===false) || strval(openssl_pkey_get_details($privClef)['key'] ?? '')===strval(openssl_pkey_get_details($publInst)['key'] ?? '');
		if( ($keyStrng['privFile']!='' && $privClef===false) || ($keyStrng['publFile']!='' && $publInst===false) ){
			$this->addCheckResult('token keys '.$blockNmdText, 'fail', 'a key file is not a PEM key: "setting mkSecret jwtToken" makes a new pair');
		}elseif( !$isPaired ){
			$this->addCheckResult('token keys '.$blockNmdText, 'fail', 'the public key is not the one of the private key: "setting mkSecret jwtToken" makes a new pair');
		}elseif( empty($clefBits) ){
			$this->addCheckResult('token keys '.$blockNmdText, 'fail', 'missing: "setting mkSecret jwtToken" makes them');
		}elseif( min($clefBits)<JWT::RSAS_BITS ){
			$this->addCheckResult('token keys '.$blockNmdText, 'fail', 'not an RSA key of '.JWT::RSAS_BITS.' bits at least: "setting mkSecret jwtToken" makes a new pair');
		}elseif( $privClef===false && ($blockVlsList['external'] ?? false)!==true && !empty($loginCfg = $this->findAccessDict('loginSet')) && strval($loginCfg['carrWith'] ?? 'jwtToken')=='jwtToken' && strcasecmp(strval($loginCfg['carrConf'] ?? 'jwtToken'), $blockNmdText)==0 ){
			// The login issues tokens with this block, which has no private key : a public key alone, brought by a bundle, would trust the tokens of where it was built
			$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
			$isRemote = in_array(strtoupper(strval(($nyuConfg!==null) ? $nyuConfg->getNestedValue('SYSTEM', 'ENV_NAME') : '')), Array('PREV', 'PROD'), true);
			$this->addCheckResult('token keys '.$blockNmdText, $isRemote ? 'fail' : 'warn', 'the login issues tokens with this block, which has no private key: "setting mkSecret jwtToken" makes a pair; "external": true when the tokens come from another server');
		}else{
			$this->addCheckResult('token keys '.$blockNmdText, 'fine', ($privClef===false) ? 'checks tokens only: they are issued by another server' : 'set, '.max($clefBits).' bits');
		}
	}

	/**
	 * Checks every configured database: its extension is loaded, and it
	 * answers.
	 */
	public function checkDatabases(): Void {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$dtbsName = ($nyuConfg!==null) ? $nyuConfg->readValueByKey('database') : null;
		if( !is_array($dtbsName) || empty($dtbsName) ){
			$needsDbs = strval($this->findAccessDict('loginSet')['authWith'] ?? '')=='nativeDb';
			$this->addCheckResult( 'databases', $needsDbs ? 'fail' : 'fine', $needsDbs ? 'none is configured, and the login uses the native userbase' : 'none is configured' );
			return;
		}
		$dtbsName = array_change_key_case($dtbsName, CASE_LOWER);
		foreach( (array_key_exists('dbengine', $dtbsName) ? Array('') : array_keys($dtbsName)) as $oneNme ){
			$baseName = strval($oneNme);
			$rowTitle = 'database'.( $baseName!='' ? ' '.$baseName : '' );
			try{
				$baseCode = strtolower( strval(DBCon::readDbaseConfg($baseName)['dbEngine']) );
				$extnName = self::BASE_EXTS[$baseCode] ?? '';
				if( $extnName!='' && !extension_loaded($extnName) ){
					$this->addCheckResult($rowTitle, 'fail', 'the extension '.$extnName.' is not loaded');
					continue;
				}
				$dtbsHndr = DBCon::linkNamedDbCon($baseName)->readConnection();
				if( $dtbsHndr===null ){
					$this->addCheckResult($rowTitle, 'fine', 'configured ('.$baseCode.'); not an SQL database, not asked');
					continue;
				}
				$canWrite = $dtbsHndr->sendBuiltQuery('SELECT 1;');
				$this->addCheckResult( $rowTitle, $canWrite ? 'fine' : 'fail', $canWrite ? 'answers ('.$baseCode.')' : 'does not answer: '.strval($dtbsHndr->getLastErrText()) );
			}catch( \Throwable $e ){
				$this->addCheckResult($rowTitle, 'fail', 'does not answer: '.$e->getMessage());
			}
		}
	}

	/**
	 * Checks the store of the users the login names: the users table of
	 * the native userbase, the users file, or the htpasswd file.
	 */
	public function checkUserStore(): Void {
		$loginCfg = $this->findAccessDict('loginSet');
		$authName = strval($loginCfg['authWith'] ?? '');
		$blockCfg = $this->findAccessDict( strval($loginCfg['authConf'] ?? '') );
		if( $authName=='nativeDb' ){
			try{
				$dtbsHndr = DBCon::linkNamedDbCon( strval($blockCfg['linkName'] ?? '') )->readConnection();
				$tableNme = strval($blockCfg['useTable'] ?? 'users');
				$hasTable = ($dtbsHndr!==null) && $dtbsHndr->getInfoOfTable($tableNme)!==false;
				$this->addCheckResult( 'users', $hasTable ? 'fine' : 'fail', $hasTable ? 'the table '.$tableNme.' is there' : 'the table '.$tableNme.' is missing: "install userBase" creates it' );
			}catch( \Throwable $e ){
				$this->addCheckResult('users', 'fail', 'the userbase cannot be read: '.$e->getMessage());
			}
		}elseif( $authName=='userFile' ){
			$checkObj = new UserList($blockCfg);
			$checkObj->readIdntOfUser('-');
			$canWrite = ($checkObj->getLastErrText()!='Credential store unavailable');
			$this->addCheckResult( 'users', $canWrite ? 'fine' : 'fail', $canWrite ? 'the users file is read' : 'the users file is missing or cannot be used: "install userBase" creates it' );
		}elseif( $authName=='htpasswd' ){
			$passFile = trim( strval($blockCfg['passFile'] ?? ''), '/\\ ' );
			$canWrite = ($passFile!='') && is_file(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $passFile));
			$this->addCheckResult( 'users', $canWrite ? 'fine' : 'fail', $canWrite ? 'the htpasswd file is there' : 'the htpasswd file is missing: "setting mkSecret htpasswd <user>" writes it' );
		}
	}

	/**
	 * Checks the caps of the devices (Access\Auth\Device), when the access
	 * config holds a 'dvcToken' block: it fails on a devices table without
	 * the deviceTops column, whose devices are all refused until the last
	 * statement of conf/schema/migrate-devices-1.0.<type>.sql adds it, and
	 * warns about the devices still standing without a cap whose user is
	 * above the first role: they act with their user's full role.
	 */
	public function checkCapsOfDvc(): Void {
		$blockCfg = $this->findAccessDict('dvcToken');
		if( empty($blockCfg) ){
			return;
		}
		$tableNme = strval($blockCfg['useTable'] ?? 'devices');
		try{
			$dtbsHndr = DBCon::linkNamedDbCon( strval($blockCfg['linkName'] ?? '') )->readConnection();
			$tableDct = ($dtbsHndr!==null) ? $dtbsHndr->getInfoOfTable($tableNme) : false;
		}catch( \Throwable $e ){
			$tableDct = false;
		}
		if( $tableDct===false ){
			// No devices table yet : "install userBase" creates it, with the column
			return;
		}
		if( !in_array('deviceTops', (array)$tableDct['flds'], true) ){
			$this->addCheckResult('device caps', 'fail', 'the table '.$tableNme.' has no deviceTops column: every device is refused until the last statement of conf/schema/migrate-devices-1.0.<type>.sql adds it');
			return;
		}
		$dvceNtry = (new \nyu\Access\Auth\Device($blockCfg))->listUsrDevices();
		if( $dvceNtry===false ){
			$this->addCheckResult('device caps', 'fail', 'the devices cannot be read');
			return;
		}
		$openList = Array();
		foreach( $dvceNtry as $oneRow ){
			if( ($oneRow['revokeDate'] ?? null)===null && strval($oneRow['deviceTops'] ?? '')==='' && strval($oneRow['accessRole'] ?? '')!==\nyu\Access\Checker::ROLE_LIST[0] ){
				$openList[] = strval($oneRow['systemName']).'/'.strval($oneRow['deviceName']).' ('.strval($oneRow['accessRole'] ?? '').')';
			}
		}
		if( empty($openList) ){
			$this->addCheckResult('device caps', 'fine', 'every device standing acts within a cap, or for a user of the first role');
		}else{
			$this->addCheckResult('device caps', 'warn', 'without a cap, acting with their user\'s full role: '.implode(', ', $openList).'; give each a cap (devices revokeIt, then newToken --roleTops)');
		}
	}

	/**
	 * Reads the profile of the project from the access config, which no
	 * key keeps: a resource server ('dataServ') checks the tokens with a
	 * public key alone and its login names no credential check; an
	 * authentication server ('authServ') logs its users in and signs its
	 * tokens with RS256, bound to the projects by an issuer or an audience;
	 * a site with nothing to log in to ('openSite') has no users' store and
	 * serves only open presets, through the map of the doorways (the default
	 * preset without one); users, login and data in one ('allInOne')
	 * otherwise. An open site whose presets can reach an action fails: a Run
	 * would be public without anyone deciding it. Blocks making none of the
	 * four are told, and so are an 'authServ' or a 'dataServ' without issuer
	 * or audience, and a 'dataServ' whose public key file is missing.
	 */
	public function checkPrjctKind(): Void {
		$loginCfg = $this->findAccessDict('loginSet');
		$tokenNme = empty($loginCfg) ? '' : ( ( strval($loginCfg['carrWith'] ?? 'jwtToken')=='jwtToken' ) ? strval($loginCfg['carrConf'] ?? 'jwtToken') : '' );
		$tokenCfg = $this->findAccessDict($tokenNme);
		$authName = trim( strval($loginCfg['authWith'] ?? '') );
		$isRsaTkn = ($tokenCfg['signAlgo'] ?? '')=='RS256';
		$publName = trim( strval($tokenCfg['publFile'] ?? ''), '/\\ ' );
		$isNoPriv = $isRsaTkn && trim(strval($tokenCfg['privFile'] ?? ''))=='' && $publName!='';
		$hasIssur = is_string($tokenCfg['issuerId'] ?? null) && trim($tokenCfg['issuerId'])!='';
		$audnVals = $tokenCfg['audience'] ?? Array();
		$hasAudnc = is_string($audnVals) ? trim($audnVals)!='' : ( is_array($audnVals) && count(array_filter($audnVals, function( Mixed $audienceItem ): Bool { return is_string($audienceItem) && trim($audienceItem)!=''; }))>0 );
		$clmnFlaw = Array();
		if( !$hasIssur ){
			$clmnFlaw[] = 'no issuerId';
		}
		if( !$hasAudnc ){
			$clmnFlaw[] = 'no audience';
		}
		if( $isNoPriv && $authName!='' ){
			$this->addCheckResult('profile', 'warn', 'none of allInOne, authServ, dataServ, openSite: the tokens are checked with a public key alone (publFile of '.$tokenNme.'), beside the users\' store of the login ('.$authName.'): give the block its privFile, or take authWith out of loginSet for a resource server');
		}elseif( $isNoPriv ){
			if( !is_file(TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $publName)) ){
				$clmnFlaw[] = 'the public key file '.$publName.' is missing: copy the one of the authentication server';
			}
			$this->addCheckResult( 'profile', empty($clmnFlaw) ? 'fine' : 'warn', 'dataServ'.( empty($clmnFlaw) ? '' : ': '.implode('; ', $clmnFlaw) ) );
		}elseif( $authName=='' ){
			// No users' store : an open site, when every preset of the map is open
			try{
				$psetList = array_values( array_unique(array_column(\nyu\Runner::readDoorwayLst(), 'presetId')) );
			}catch( \RuntimeException $e ){
				// The doorways row tells it
				$psetList = Array();
			}
			$psetList = empty($psetList) ? Array('default') : $psetList;
			$isOpened = true;
			$actnList = Array();
			foreach( $psetList as $oneRps ){
				try{
					$setupObj = (new Preset($oneRps))->makeConfLoader();
					$routeCfg = $setupObj->getRegistryKey('dispatch');
					$isOpened = $isOpened && ($setupObj->setAccessArray()['required'] ?? true)===false;
					if( (new Router( is_array($routeCfg) ? $routeCfg : Array(), ActCaller::makeRequestLst('GET', '/')[0] ))->canReachAction() ){
						$actnList[] = $oneRps;
					}
				}catch( \Throwable $e ){
					// A preset that does not load is told by its own row
					$isOpened = false;
				}
			}
			if( !$isOpened ){
				$this->addCheckResult('profile', 'warn', 'none of allInOne, authServ, dataServ, openSite: the login names no users\' store, the tokens are not checked with a public key alone, and a preset served checks access');
			}elseif( !empty($actnList) ){
				$this->addCheckResult('profile', 'fail', 'openSite, but '.implode(', ', $actnList).' can reach an action: a Run is public without anyone deciding it; serve statics, or a preset that serves no Run, or open the Run on purpose (openList) in a preset that checks access');
			}else{
				$this->addCheckResult('profile', 'fine', 'openSite');
			}
		}elseif( $isRsaTkn && ($hasIssur || $hasAudnc) ){
			$this->addCheckResult( 'profile', empty($clmnFlaw) ? 'fine' : 'warn', 'authServ'.( empty($clmnFlaw) ? '' : ': '.implode('; ', $clmnFlaw).', the tokens are not bound to the projects accepting them' ) );
		}else{
			$this->addCheckResult('profile', 'fine', 'allInOne');
		}
	}

	/**
	 * Checks what must not be left on where real users come: errors
	 * shown, proxies trusted from anywhere, secrets readable by others.
	 */
	public function checkUnsafeCfg(): Void {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$iniConfg = ($nyuConfg!==null) ? ($nyuConfg->readValueByKey('system') ?: Array()) : Array();
		$envrName = strtoupper( strval($iniConfg['ENV_NAME'] ?? '') );
		$showErro = in_array( strtoupper(strval($iniConfg['ERR_SHOW'] ?? '')), Array('1', 'TRUE', 'ON', 'YES'), true );
		$errorStt = ( $showErro && in_array($envrName, Array('PROD', 'PREV'), true) ) ? 'warn' : 'fine';
		// In production the setting is not followed : nothing of an error is shown there
		$errorNte = ($errorStt=='fine') ? '' : ( $envrName=='PROD' ? ', where it is ignored: set it to FALSE' : ': the details of an error are shown to the clients' );
		$this->addCheckResult( 'errors', $errorStt, 'ERR_SHOW is '.( $showErro ? 'on' : 'off' ).' in the environment '.( $envrName!='' ? $envrName : '(not named)' ).$errorNte );
		// A level that cannot be read stops every request at its start
		try{
			$errorLvl = (new Loader(Array('settings'=>Array('confType'=>'all'))))->findErrorLevel();
			$this->addCheckResult( 'error level', 'fine', ($errorLvl==E_ALL) ? 'every error is reported (E_ALL)' : 'ERR_LEVL reports level '.$errorLvl.' of '.E_ALL );
		}catch( \RuntimeException $e ){
			$this->addCheckResult( 'error level', 'fail', $e->getMessage().': no request can be served' );
		}
		if( preg_match('#(^|[\s,])(0\.0\.0\.0|::)/0#', strval($iniConfg['PRX_LIST'] ?? '')) ){
			$this->addCheckResult('proxies', 'fail', 'PRX_LIST trusts every address: any client can then give itself the address it wants');
		}
		if( array_key_exists('REQ_SIZE', $iniConfg) && intval($iniConfg['REQ_SIZE'])<1 ){
			$this->addCheckResult('requests', 'warn', 'REQ_SIZE is 0: a request body of any size is read in memory');
		}
		$envrPath = TOP_DIR.DS.'.env';
		if( DIRECTORY_SEPARATOR=='/' && is_file($envrPath) && (fileperms($envrPath) & 0077)!=0 ){
			$this->addCheckResult('.env', 'warn', 'the file of the secrets can be read by other users of the system: chmod 600 .env');
		}
		// The limiter is checked for what it would do, not for what it holds
		$limitCfg = $this->findAccessDict('tryLimit');
		if( !empty($limitCfg) && intval($limitCfg['maxTries'] ?? 10)<1 ){
			$this->addCheckResult('limits', 'warn', 'the limit by address is off (maxTries 0): wrong credentials can be tried without end');
		}
	}

	/**
	 * Tells what is wrong with the carrier and the check an 'security' or a
	 * 'loginSet' block names: one that does not exist, or a block of
	 * settings the access config does not hold.
	 *
	 * @param Array $accessConfig The block, with its 'carrWith', 'carrConf', 'authWith' and 'authConf' keys.
	 * @return String What is wrong, empty when nothing is.
	 */
	protected function checkPairFlaws( Array $accessConfig ): String {
		$sessName = strval($accessConfig['carrWith'] ?? '');
		if( Checker::findCarrierCls($sessName)===null ){
			return 'unknown carrier "'.$sessName.'"';
		}
		$authName = $accessConfig['authWith'] ?? null;
		if( $authName!==null && Checker::findCheckClass(strval($authName))===null ){
			return 'unknown credential check "'.strval($authName).'"';
		}
		foreach( Array('carrConf', 'authConf') as $oneKey ){
			$blockNme = strval($accessConfig[$oneKey] ?? '');
			if( $blockNme!='' && empty($this->findAccessDict($blockNme)) ){
				return 'conf/access.json has no "'.$blockNme.'" block, named by '.$oneKey;
			}
		}
		return '';
	}

	/**
	 * Reads a block of the ACCESS config.
	 *
	 * @param String $blockNmdText Name of the block.
	 * @return Array Its values, empty when there is no such block.
	 */
	protected function findAccessDict( String $blockNmdText ): Array {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$blockCfg = ($nyuConfg!==null && $blockNmdText!='') ? $nyuConfg->getNestedValue('ACCESS', strtoupper($blockNmdText)) : null;
		return is_array($blockCfg) ? $blockCfg : Array();
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
