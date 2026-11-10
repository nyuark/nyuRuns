<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Checker;
use nyu\Data\Dcmnt;
use nyu\Setup\ConfCache;
use nyu\Setup\Preset;
use nyu\Shell\ActCaller;
use nyu\Shell\Command;
use nyu\Shell\ConfFile;
use nyu\Shell\Console;
use nyu\Shell\ShellStop;
use nyu\Tools\Registry;
use nyu\Tools\Request;
use nyu\Tools\Router;

/**
 * Reads and changes the configuration of the project:
 *
 *   php nyuRun settings showConf coreConf          the keys of conf/system.ini
 *   php nyuRun settings showConf accsConf loginSet  a block of conf/access.json, secrets masked
 *   php nyuRun settings showConf preset restapis.security  a block of a preset
 *   php nyuRun settings setValue coreConf LOG_MODE BASE
 *   php nyuRun settings setValue accsConf nativeDb.minChars 12
 *   php nyuRun settings setValue dataConf nyuRuns.hostName db.local
 *   php nyuRun settings setValue preset restapis.security.authWith nativeDb
 *   php nyuRun settings wwwEntry index restapis  the preset www/index.php starts the Runner with
 *   php nyuRun settings makeSecr jwtToken        a new secret, written where the config reads it
 *   php nyuRun settings fullList restapis        the routes of a preset, and who they are served to
 *   php nyuRun settings clrCache               removes the config cache, after conf was edited by hand
 *
 * A file is written as ConfFile does: comments and layout kept, the
 * former file copied to temp/archives. A change that leaves the file
 * unusable is undone, and every change removes the config cache.
 */
class Config extends Command{

	// The config files that are read and written, by the name they are asked under
	const TRGT_LIST = Array('coreConf'=>'conf/system.ini', 'accsConf'=>'conf/access.json', 'dataConf'=>'conf/database.json');
	// Size of the RSA keys made for the tokens, in bits
	const KEYS_BITS = 4096;
	// Keys whose value is not shown, unless it only names where the secret is (env:NAME)
	const SECR_LIST = Array('hmacSecr', 'password', 'passwd', 'keysList', 'cryptKey');
	// What the keys of system.ini that are checked may hold
	const RULE_LIST = Array(
		'ENV_NAME'=>'/^(DEVS|TEST|PREV|PROD)$/D', 'LOG_MODE'=>'/^(MINI|BASE|FULL)$/D', 'LOG_ROTA'=>'/^(NUMB|WEEK|MNTH|NONE)$/D',
		'ERR_SHOW'=>'/^(TRUE|FALSE)$/D', 'CNF_CACH'=>'/^(TRUE|FALSE)$/D', 'TBL_CACH'=>'/^(TRUE|FALSE)$/D','LOG_SIZE'=>'/^\d{1,9}$/D', 'LST_ROWS'=>'/^[1-9]\d{0,8}$/D', 'SND_TIME'=>'/^[1-9]\d{0,8}$/D',
		'REQ_SIZE'=>'/^\d{1,9}$/D', 'REQ_PART'=>'/^\d{1,9}$/D', 'LNG_DFLT'=>'/^[a-z]{2,3}([-_][a-z0-9]{2,8})*$/Di', 'ERR_LEVL'=>'/^(\d{1,9}|~?\s*E_[A-Z_]+(\s*[|&]\s*~?\s*E_[A-Z_]+)*)$/D'
	);
	// The call that starts the Runner in an entry point, and the preset it names
	const RUNR_CALL = '/(new\s+\\\\?nyu\\\\Runner\s*\(\s*)(([\'"])[\w\-]*\3)?/';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function describeInLine(): String {
		return 'Reads and changes the configuration: system, access, databases, presets, secrets, routes';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTableArray(): Array {
		return Array(
			'showConf'=>Array('args'=>'<coreConf|accsConf|dataConf|preset> [path]', 'about'=>'shows a config file, one of its blocks or keys; secrets are masked'),
			'setValue'=>Array('args'=>'<coreConf|accsConf|dataConf|preset> <path> <value> [--overrule]', 'about'=>'changes a value: KEY, block.key, or preset.block.key'),
			'wwwEntry'=>Array('args'=>'[file] [preset]', 'about'=>'shows or changes the preset an entry point of www starts the Runner with'),
			'makeSecr'=>Array('args'=>'<jwtToken|keysList|cryptKey|markSecr|htpasswd> [user] [--useBlock=<name>] [--showSecr] [--typeItIn] [--dropsAll]', 'about'=>'makes a secret and writes it where the config reads it'),
			'fullList'=>Array('args'=>'[preset]', 'about'=>'lists the routes of a preset: path, methods, kind, target, access'),
			'clrCache'=>Array('args'=>'', 'about'=>'removes the config cache: the next request of each preset reads its files, and caches them when CNF_CACH is TRUE')
		);
	}

	/**
	 * Removes the config cache (ConfCache), as anything written in conf by
	 * this command does: to be run after conf, a preset or a route file
	 * was edited by hand, CNF_CACH included.
	 *
	 * @return Int The exit code.
	 */
	public function invokeClrCache(): Int {
		$dropNum = ConfCache::clearConfCache();
		$this->traceShellDone( 'Config: cache removed, '.$dropNum.' files' );
		$this->printerObj->printShellPair('cache files removed', $dropNum);
		return self::EXIT_DONE;
	}

	/**
	 * Shows a config file, or the part of it a path names.
	 *
	 * @return Int The exit code.
	 */
	public function invokeShowConf(): Int {
		$trgtNme = $this->needsShellWord('what to show (coreConf, accsConf, dataConf, preset)');
		// Matched whatever its letter case, kept as TRGT_LIST writes it
		foreach( array_merge(array_keys(self::TRGT_LIST), Array('preset')) as $oneCod ){
			$trgtNme = (strcasecmp($oneCod, $trgtNme)==0) ? $oneCod : $trgtNme;
		}
		$pathTxt = strval($this->optionsObj->shiftFirstWord() ?? '');
		if( $trgtNme=='preset' ){
			return $this->showPresetNumb($pathTxt);
		}
		if( !isset(self::TRGT_LIST[$trgtNme]) ){
			$this->terminateShell('Nothing is shown under that name: '.$trgtNme, self::EXIT_HELP);
		}
		$filPath = $this->pathOfSettings(self::TRGT_LIST[$trgtNme]);
		$rowList = Array();
		if( $trgtNme=='coreConf' ){
			$lineLst = Array();
			preg_match_all('/^[ \t]*([A-Z0-9_]+)[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filPath)), $lineLst, PREG_SET_ORDER);
			foreach( $lineLst as $linVal ){
				if( $pathTxt=='' || $pathTxt==$linVal[1] ){
					$rowList[] = Array($linVal[1], trim($linVal[2], '"'));
				}
			}
			if( empty($rowList) ){
				$this->terminateShell('Nothing under '.( $pathTxt!='' ? $pathTxt.' in ' : '' ).self::TRGT_LIST[$trgtNme], self::EXIT_DENY);
			}
		}else{
			$treeArr = json_decode( strval(json_encode($this->generateWriter()->importJsonsObj($filPath))), true );
			$rowList = $this->plainRowsArray( $this->followDotPaths($treeArr, $pathTxt), $pathTxt );
		}
		$this->printerObj->printShellRows( Array('key', 'value'), $rowList );
		return self::EXIT_DONE;
	}

	/**
	 * Lists the presets, or shows one, or the part of it a path names.
	 *
	 * @param String $dotNamesPath Empty for the list; otherwise the name of a preset, followed or not by .block and .key.
	 * @return Int The exit code.
	 */
	protected function showPresetNumb( String $dotNamesPath ): Int {
		if( $dotNamesPath=='' ){
			$rowList = Array();
			foreach( (array)glob($this->pathOfSettings('conf/presets').DS.'*.rps') as $rpsFil ){
				$prstNme = basename(strval($rpsFil), '.rps');
				try{
					$cfgVals = $this->retrievePreset($prstNme);
					$rowList[] = Array($prstNme, strval($cfgVals['presetId'] ?? ''), strval($cfgVals['describe'] ?? ''));
				}catch( \RuntimeException $e ){
					$rowList[] = Array($prstNme, '', 'cannot be loaded: '.$e->getMessage());
				}
			}
			$this->printerObj->printShellRows( Array('preset', 'label', 'about'), $rowList );
			return self::EXIT_DONE;
		}
		$pthPart = explode('.', $dotNamesPath, 2);
		$this->printerObj->printShellRows( Array('key', 'value'), $this->plainRowsArray( $this->followDotPaths($this->retrievePreset($pthPart[0]), $pthPart[1] ?? ''), $pthPart[1] ?? '' ) );
		return self::EXIT_DONE;
	}

	/**
	 * Changes a value of a config file.
	 *
	 * @return Int The exit code.
	 */
	public function invokeSetValue(): Int {
		$trgtNme = $this->needsShellWord('what to write in (coreConf, accsConf, dataConf, preset)');
		// Matched whatever its letter case, kept as TRGT_LIST writes it
		foreach( array_merge(array_keys(self::TRGT_LIST), Array('preset')) as $oneCod ){
			$trgtNme = (strcasecmp($oneCod, $trgtNme)==0) ? $oneCod : $trgtNme;
		}
		$pathTxt = $this->needsShellWord('the key to write (KEY, block.key, or preset.block.key)');
		$gvnText = $this->optionsObj->shiftFirstWord();
		if( $gvnText===null ){
			$this->terminateShell('Missing argument: the value', self::EXIT_HELP);
		}
		$pthPart = explode('.', $pathTxt);
		$wrtrObj = $this->generateWriter();
		$isForcd = $this->optionsObj->validateOption('overrule');
		try{
			if( $trgtNme=='coreConf' ){
				$this->writeSystemIni($wrtrObj, $pathTxt, $gvnText);
			}elseif( $trgtNme=='accsConf' || $trgtNme=='dataConf' ){
				if( count($pthPart)>2 || ($trgtNme=='accsConf' && count($pthPart)!=2) || in_array('', $pthPart, true) ){
					$this->terminateShell('A value of '.self::TRGT_LIST[$trgtNme].' is named as block.key', self::EXIT_HELP);
				}
				$filPath = $this->pathOfSettings(self::TRGT_LIST[$trgtNme]);
				$treeObj = $wrtrObj->importJsonsObj($filPath);
				$nodeObj = $treeObj;
				if( count($pthPart)==2 ){
					$blckNme = $pthPart[0];
					if( !isset($treeObj->$blckNme) || !is_object($treeObj->$blckNme) ){
						if( !$isForcd ){
							$this->terminateShell('Nothing was changed: no block '.$blckNme.' in '.self::TRGT_LIST[$trgtNme].'; --overrule adds it', self::EXIT_DENY);
						}
						$treeObj->$blckNme = new \stdClass();
					}
					$nodeObj = $treeObj->$blckNme;
				}
				$keyName = strval(end($pthPart));
				if( !property_exists($nodeObj, $keyName) && !$isForcd ){
					$this->terminateShell('Nothing was changed: no key '.$pathTxt.' in '.self::TRGT_LIST[$trgtNme].'; --overrule adds it', self::EXIT_DENY);
				}
				$newValu = $this->parseTypedText($gvnText);
				// A check or a carrier that does not exist would refuse every request
				if( $trgtNme=='accsConf' && ( ($keyName=='authWith' && Checker::authClssString(strval($gvnText))===null) || ($keyName=='sessWith' && Checker::sessnClassText(strval($gvnText))===null) ) ){
					$this->terminateShell('Nothing was changed: '.$gvnText.' is not a known '.( $keyName=='authWith' ? 'credential check' : 'session carrier' ), self::EXIT_DENY);
				}
				// The issuer, the audiences and the previous key of the tokens are refused when they cannot be read
				$tknFlaw = ($trgtNme=='accsConf') ? $this->toknFlawString($keyName, $newValu) : '';
				if( $tknFlaw!='' ){
					$this->terminateShell('Nothing was changed: '.$tknFlaw, self::EXIT_DENY);
				}
				$nodeObj->$keyName = $newValu;
				$wrtrObj->saveJsonConfig($filPath, $treeObj);
			}elseif( $trgtNme=='preset' ){
				if( count($pthPart)<2 || count($pthPart)>3 || in_array('', $pthPart, true) || !preg_match('/^[\w\-]+$/D', $pthPart[0]) ){
					$this->terminateShell('A value of a preset is named as preset.block.key, or preset.key', self::EXIT_HELP);
				}
				$filPath = $this->pathOfSettings('conf/presets/'.$pthPart[0].'.rps');
				if( !is_file($filPath) ){
					$this->terminateShell('Nothing was changed: no preset '.$pthPart[0], self::EXIT_DENY);
				}
				$wrtrObj->setPresetValue( $filPath, (count($pthPart)==3) ? $pthPart[1] : '', strval(end($pthPart)), $this->parseTypedText($gvnText) );
				try{
					new Preset($pthPart[0]);
				}catch( \RuntimeException $e ){
					$wrtrObj->revertWriteFlg($filPath);
					$this->terminateShell('Nothing was changed: '.$e->getMessage(), self::EXIT_DENY);
				}
			}else{
				$this->terminateShell('Nothing is written under that name: '.$trgtNme, self::EXIT_HELP);
			}
		}catch( ShellStop $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			$this->terminateShell( $e->getMessage(), self::EXIT_DENY );
		}
		Console::loadConfigLogs();
		$this->traceShellDone( 'Config: '.$trgtNme.' '.$pathTxt.' written' );
		$this->printerObj->printShellPair('written', $trgtNme.' '.$pathTxt);
		return self::EXIT_DONE;
	}

	/**
	 * Tells why a value cannot be written under a key binding the tokens
	 * to this project: 'issuerId' is a text, 'audience' a text or a list of
	 * texts, 'prevFile' empty or the path, from the project root, of a public
	 * RSA key of JWT::RSAS_BITS bits at least.
	 *
	 * @param String $keyAsWritten The key written.
	 * @param Mixed  $valueAsGiven The value, as parseTypedText() gives it.
	 * @return String The reason; empty when the value can be written, or the key is none of those.
	 */
	protected function toknFlawString( String $keyAsWritten, Mixed $valueAsGiven ): String {
		if( $keyAsWritten=='issuerId' && !is_string($valueAsGiven) ){
			return 'issuer is a text';
		}
		if( $keyAsWritten=='audience' && !is_string($valueAsGiven) && !( is_array($valueAsGiven) && count(array_filter($valueAsGiven, 'is_string'))===count($valueAsGiven) ) ){
			return 'audience is a text or a list of texts: \'["api.example.org"]\'';
		}
		if( $keyAsWritten=='prevFile' ){
			if( !is_string($valueAsGiven) ){
				return 'prevFile is the path of a public key, from the project root';
			}
			$keyPath = trim($valueAsGiven, '/\\ ');
			if( $keyPath=='' ){
				return '';
			}
			$keyText = is_file($this->pathOfSettings($keyPath)) ? strval(@file_get_contents($this->pathOfSettings($keyPath))) : '';
			$keyInst = ($keyText!='' && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public($keyText) : false;
			$keyInfo = ($keyInst!==false) ? openssl_pkey_get_details($keyInst) : false;
			if( !is_array($keyInfo) || ($keyInfo['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($keyInfo['bits'] ?? 0)<\nyu\Access\Sess\JWT::RSAS_BITS ){
				return json_encode($valueAsGiven).' is not a public RSA key of '.\nyu\Access\Sess\JWT::RSAS_BITS.' bits at least';
			}
		}
		return '';
	}

	/**
	 * Changes a key of conf/system.ini, checking the values of the keys
	 * the framework reads as codes or numbers.
	 *
	 * @param ConfFile $writerObject The writer of config files.
	 * @param String   $iniEntryName Name of the key.
	 * @param String   $newIniValTxt Its new value.
	 *
	 * @throws ShellStop When the key or its value is refused, or the file is left unusable.
	 */
	protected function writeSystemIni( ConfFile $writerObject, String $iniEntryName, String $newIniValTxt ): Void {
		if( !preg_match('/^[A-Z0-9]{3}_[A-Z0-9]{4}$/D', $iniEntryName) ){
			$this->terminateShell('A key of conf/system.ini is written XXX_YYYY', self::EXIT_HELP);
		}
		if( isset(self::RULE_LIST[$iniEntryName]) && !preg_match(self::RULE_LIST[$iniEntryName], $newIniValTxt) ){
			$this->terminateShell('Nothing was changed: '.$iniEntryName.' does not take the value '.$newIniValTxt, self::EXIT_DENY);
		}
		$filPath = $this->pathOfSettings(self::TRGT_LIST['coreConf']);
		if( !$writerObject->changeIniValue($filPath, $iniEntryName, $newIniValTxt) ){
			$this->terminateShell('Nothing was changed: no key '.$iniEntryName.' in conf/system.ini, and none is added', self::EXIT_DENY);
		}
		if( @parse_ini_file($filPath)===false ){
			$writerObject->revertWriteFlg($filPath);
			$this->terminateShell('Nothing was changed: conf/system.ini could no longer be read with that value', self::EXIT_DENY);
		}
	}

	/**
	 * Shows the preset each entry point of www starts the Runner with, or
	 * changes the one of an entry point.
	 *
	 * @return Int The exit code.
	 */
	public function invokeWwwEntry(): Int {
		$fileNme = $this->optionsObj->shiftFirstWord();
		$prstNme = $this->optionsObj->shiftFirstWord();
		if( $fileNme===null ){
			$rowList = Array();
			foreach( (array)glob(WEB_DIR.DS.'*.php') as $phpFil ){
				$hitList = Array();
				if( preg_match(self::RUNR_CALL, strval(file_get_contents(strval($phpFil))), $hitList) ){
					$rowList[] = Array( basename(strval($phpFil), '.php'), trim(strval($hitList[2] ?? ''), '\'"') ?: 'defaults' );
				}
			}
			$this->printerObj->printShellRows( Array('entry', 'preset'), $rowList );
			return self::EXIT_DONE;
		}
		$filPath = WEB_DIR.DS.$fileNme.'.php';
		if( !preg_match('/^[\w\-]+$/D', $fileNme) || !is_file($filPath) ){
			$this->terminateShell('No entry point www/'.$fileNme.'.php', self::EXIT_DENY);
		}
		$fileTxt = strval(file_get_contents($filPath));
		$hitList = Array();
		$fndngNb = preg_match_all(self::RUNR_CALL, $fileTxt, $hitList, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		if( $fndngNb<1 ){
			$this->terminateShell('www/'.$fileNme.'.php does not start a Runner', self::EXIT_DENY);
		}
		$nowPrst = trim(strval($hitList[0][2][0] ?? ''), '\'"') ?: 'defaults';
		if( $prstNme===null ){
			$this->printerObj->printShellPair($fileNme, $nowPrst);
			return self::EXIT_DONE;
		}
		$fullOff = intval($hitList[0][0][1]);
		$fullEnd = $fullOff + strlen($hitList[0][0][0]);
		$hasName = isset($hitList[0][2]) && $hitList[0][2][0]!=='';
		if( $fndngNb!=1 || (!$hasName && ($fileTxt[$fullEnd] ?? '')!=')') ){
			$this->terminateShell('Nothing was changed: www/'.$fileNme.'.php does not name its preset in a way that can be rewritten; change it by hand', self::EXIT_DENY);
		}
		try{
			new Preset($prstNme);
		}catch( \RuntimeException $e ){
			$this->terminateShell('Nothing was changed: '.$e->getMessage(), self::EXIT_DENY);
		}
		$this->generateWriter()->saveFileAtomic( $filPath, substr($fileTxt, 0, $fullOff).$hitList[0][1][0]."'".$prstNme."'".substr($fileTxt, $fullEnd) );
		$this->traceShellDone( 'Config: entry point '.$fileNme.' set to the preset '.$prstNme.' (was '.$nowPrst.')' );
		$this->printerObj->printShellPair($fileNme, $prstNme);
		return self::EXIT_DONE;
	}

	/**
	 * Makes a secret and writes it where the config reads it.
	 *
	 * @return Int The exit code.
	 */
	public function invokeMakeSecr(): Int {
		$kindNme = $this->needsShellWord('the secret to make (jwtToken, keysList, cryptKey, markSecr, htpasswd)');
		if( !in_array($kindNme, Array('jwtToken', 'keysList', 'cryptKey', 'markSecr', 'htpasswd'), true) ){
			$this->terminateShell('No secret is made under that name: '.$kindNme, self::EXIT_HELP);
		}
		try{
			if( $kindNme=='htpasswd' ){
				$this->createHtpasswd( $this->needsShellWord('the user of the htpasswd line') );
			}else{
				$this->generateSecret($kindNme);
			}
		}catch( ShellStop $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			$this->terminateShell( $e->getMessage(), self::EXIT_DENY );
		}
		return self::EXIT_DONE;
	}

	/**
	 * Makes the secret signing the tokens ('jwtToken'), an API key
	 * ('keysList') or the key the 2FA secrets are stored encrypted with
	 * ('cryptKey', in the block of the store the login uses), and writes it
	 * where the block of conf/access.json reads
	 * it: in the environment file when the block names it as env:NAME, in
	 * the block otherwise. The secret of the tokens and the key of the 2FA
	 * secrets are only shown with
	 * --showSecr; an API key is shown once, since its client needs it.
	 *
	 * @param String $secrKindText 'jwtToken', 'keysList' or 'cryptKey'.
	 * @param Bool   $keepExisting Whether a secret already set is left as it is, without a question.
	 * @return Bool Whether a secret was written.
	 *
	 * @throws ShellStop When no block holds such a secret, or replacing it is not confirmed.
	 * @throws \RuntimeException When a file cannot be read or written.
	 */
	public function generateSecret( String $secrKindText, Bool $keepExisting=false ): Bool {
		$wrtrObj = $this->generateWriter();
		$filPath = $this->pathOfSettings(self::TRGT_LIST['accsConf']);
		$treeObj = $wrtrObj->importJsonsObj($filPath);
		$blckNme = strval($this->optionsObj->retrieveOption('useBlock', ''));
		if( $secrKindText=='jwtToken' ){
			$labelTx = 'Secret of the tokens';
			$blckNme = $blckNme ?: strval($treeObj->loginSet->sessConf ?? 'jwtToken');
			// With RS256 the tokens are signed by a key pair, not a secret
			if( isset($treeObj->$blckNme) && is_object($treeObj->$blckNme) && strval($treeObj->$blckNme->signAlgo ?? '')=='RS256' ){
				return $this->buildPairsFlag($blckNme, $keepExisting);
			}
			$rawValu = $treeObj->$blckNme->hmacSecr ?? null;
			$newSecr = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}elseif( $secrKindText=='cryptKey' ){
			$labelTx = 'Key of the 2FA secrets';
			$blckNme = $blckNme ?: strval($treeObj->loginSet->authConf ?? 'nativeDb');
			$rawValu = $treeObj->$blckNme->cryptKey ?? null;
			$newSecr = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}elseif( $secrKindText=='markSecr' ){
			// The key the login marks are signed with, in the limits block
			$labelTx = 'Key of the login marks';
			$blckNme = $blckNme ?: 'tryLimit';
			$rawValu = $treeObj->$blckNme->markSecr ?? null;
			$newSecr = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}else{
			$labelTx = 'API key';
			foreach( get_object_vars($treeObj) as $blkKey => $blkVal ){
				if( $blckNme=='' && is_object($blkVal) && isset($blkVal->keysList) && is_array($blkVal->keysList) ){
					$blckNme = strval($blkKey);
				}
			}
			$rawValu = ($blckNme!='' && isset($treeObj->$blckNme->keysList) && is_array($treeObj->$blckNme->keysList)) ? ($treeObj->$blckNme->keysList[0] ?? '') : null;
			$newSecr = bin2hex(random_bytes(32));
		}
		if( !is_string($rawValu) ){
			$whatLst = Array('jwtToken'=>'the secret of the tokens', 'keysList'=>'a list of API keys', 'cryptKey'=>'the key of the 2FA secrets (cryptKey)', 'markSecr'=>'the key of the login marks (markSecr)');
			$this->terminateShell('Nothing was written: no block of conf/access.json holds '.$whatLst[$secrKindText].( $blckNme!='' ? ' under '.$blckNme : '' ), self::EXIT_DENY);
		}
		$envPath = TOP_DIR.DS.'.env';
		$envName = (strpos($rawValu, 'env:')===0) ? substr($rawValu, 4) : '';
		$nowSecr = ($envName!='') ? strval($wrtrObj->retrieveEnvKey($envPath, $envName) ?? '') : $rawValu;
		if( $nowSecr!='' && $keepExisting ){
			return false;
		}
		$lossTxt = ($secrKindText=='cryptKey') ? 'key of the 2FA secrets in use: every secret stored with it stops being readable' : strtolower($labelTx).' in use: what was issued with it stops being accepted';
		if( $nowSecr!='' && !$this->queryAgreedFlg('Replace the '.$lossTxt) ){
			$this->terminateShell('Nothing was written: not confirmed', self::EXIT_DENY);
		}
		if( $envName!='' ){
			$wrtrObj->changeEnvValue($envPath, $envName, $newSecr);
			$whereTx = '.env '.$envName;
		}else{
			if( $secrKindText=='jwtToken' ){
				$treeObj->$blckNme->hmacSecr = $newSecr;
			}elseif( $secrKindText=='cryptKey' ){
				$treeObj->$blckNme->cryptKey = $newSecr;
			}elseif( $secrKindText=='markSecr' ){
				$treeObj->$blckNme->markSecr = $newSecr;
			}else{
				$treeObj->$blckNme->keysList[0] = $newSecr;
			}
			$wrtrObj->saveJsonConfig($filPath, $treeObj);
			$whereTx = 'conf/access.json '.$blckNme;
		}
		Console::loadConfigLogs();
		$this->traceShellDone( $labelTx.' made, written in '.$whereTx );
		$this->printerObj->printShellPair('written', $whereTx);
		if( $secrKindText=='keysList' || $this->optionsObj->validateOption('showSecr') ){
			$this->printerObj->printShellOnce($labelTx, $newSecr);
		}
		return true;
	}

	/**
	 * Makes the RSA key pair of a block whose algorithm is RS256: the
	 * private key signs the tokens, the public key checks them. Both are
	 * PEM files, in conf/keys under the name of the block unless it names
	 * others; the private one is readable by its owner only, and no copy of
	 * a private key replaced is kept. The block is given the paths it lacks.
	 * The public key of a pair replaced is kept as conf/keys/<block>.old.pub
	 * and named by 'prevFile', with the time it was retired ('retireAt'): the
	 * tokens it signed before are accepted until they end, 'lifeSecs' at most,
	 * and the next pair replaces it. With --dropsAll (a key that leaked), none
	 * is kept: every token in use ends at once. Without a former private
	 * key, a new pair ends every token checked with the former one.
	 *
	 * @param String $blckNamedTxt Block of conf/access.json.
	 * @param Bool   $keepExisting Whether a private key already there is left as it is, without a question; a public key alone is too when the block says the tokens come from another server ('external'), and is replaced by a pair otherwise.
	 * @return Bool Whether a pair was written.
	 *
	 * @throws ShellStop When replacing the keys is not confirmed, or they cannot be made or written.
	 */
	protected function buildPairsFlag( String $blckNamedTxt, Bool $keepExisting ): Bool {
		$wrtrObj = $this->generateWriter();
		$filPath = $this->pathOfSettings(self::TRGT_LIST['accsConf']);
		$treeObj = $wrtrObj->importJsonsObj($filPath);
		$blckNod = $treeObj->$blckNamedTxt;
		$prvName = trim(strval($blckNod->privFile ?? ''), '/\\ ');
		$publNme = trim(strval($blckNod->publFile ?? ''), '/\\ ');
		$prvName = ($prvName!='') ? $prvName : 'conf/keys/'.$blckNamedTxt.'.key';
		$publNme = ($publNme!='') ? $publNme : 'conf/keys/'.$blckNamedTxt.'.pub';
		$prvPath = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $prvName);
		$publPth = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $publNme);
		$hasKeys = is_file($prvPath) || is_file($publPth);
		// A public key found alone is the one of another server only when the block says so : a bundle may have brought it from where it was built
		if( $keepExisting && ( is_file($prvPath) || (is_file($publPth) && ($blckNod->external ?? false)===true) ) ){
			return false;
		}
		if( !function_exists('openssl_pkey_new') ){
			$this->terminateShell('No keys made: the openssl extension is not loaded', self::EXIT_FAIL);
		}
		// The public key of the pair replaced keeps checking the tokens it signed, until the next pair
		$oldName = 'conf/keys/'.$blckNamedTxt.'.old.pub';
		$oldPriv = is_file($prvPath) ? @openssl_pkey_get_private(strval(@file_get_contents($prvPath))) : false;
		// A key that leaked keeps nothing : the tokens it signed end with it
		$isDrops = $this->optionsObj->validateOption('dropsAll');
		$oldText = ($oldPriv!==false && !$isDrops) ? strval(openssl_pkey_get_details($oldPriv)['key'] ?? '') : '';
		$lossTxt = ($oldText!='') ? 'the tokens issued with them are still accepted until they end, checked with '.$oldName : ( $isDrops ? 'every token in use ends now' : 'what was issued with them stops being accepted' );
		// installs allSteps replaces a public key found alone without a question : it is not one of this server's
		if( $hasKeys && !$keepExisting && !$this->queryAgreedFlg('Replace the keys of the tokens in use: '.$lossTxt) ){
			$this->terminateShell('Nothing was written: not confirmed', self::EXIT_DENY);
		}
		$cfgOpts = Array('config'=>dirname(__DIR__).DS.'keygen.cnf');
		$keyInst = @openssl_pkey_new( Array('private_key_bits'=>self::KEYS_BITS, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $cfgOpts );
		$prvText = '';
		if( $keyInst===false || !@openssl_pkey_export($keyInst, $prvText, null, $cfgOpts) ){
			$this->terminateShell('No keys made: OpenSSL refused: '.strval(openssl_error_string()), self::EXIT_FAIL);
		}
		$publTxt = strval(openssl_pkey_get_details($keyInst)['key'] ?? '');
		$pemList = Array($prvPath=>Array($prvText, 0600), $publPth=>Array($publTxt, 0644));
		if( $oldText!='' ){
			$pemList[TOP_DIR.DS.str_replace('/', DS, $oldName)] = Array($oldText, 0644);
		}
		foreach( $pemList as $keyFil => $onePem ){
			if( !is_dir(dirname($keyFil)) ){
				@mkdir(dirname($keyFil), 0700, true);
			}
			// Written aside, given its rights, then put in place : no copy of the former key is kept
			$tmpPath = $keyFil.'.tmp'.getmypid();
			// Readable by its owner only from its first byte, then given its rights
			$prevMsk = umask(0077);
			try{
				$isWrttn = @file_put_contents($tmpPath, $onePem[0], LOCK_EX);
			}finally{
				umask($prevMsk);
			}
			if( $isWrttn===false ){
				$this->terminateShell('No keys written: '.dirname($keyFil).' cannot be written', self::EXIT_FAIL);
			}
			@chmod($tmpPath, $onePem[1]);
			if( !@rename($tmpPath, $keyFil) ){
				@unlink($tmpPath);
				$this->terminateShell('No keys written: '.basename($keyFil).' cannot be replaced', self::EXIT_FAIL);
			}
		}
		$blckNod->privFile = $prvName;
		$blckNod->publFile = $publNme;
		if( $oldText!='' ){
			// The time the former key was retired : what it signs after, or beyond lifeSecs, is refused
			$blckNod->prevFile = $oldName;
			$blckNod->retireAt = time();
		}elseif( $isDrops || isset($blckNod->prevFile) ){
			if( is_file(TOP_DIR.DS.str_replace('/', DS, $oldName)) ){
				@unlink(TOP_DIR.DS.str_replace('/', DS, $oldName));
			}
			$blckNod->prevFile = '';
			unset($blckNod->retireAt);
		}
		$wrtrObj->saveJsonConfig($filPath, $treeObj);
		Console::loadConfigLogs();
		$oldNote = ($oldText!='') ? ', the former public key kept as '.$oldName : ( $isDrops ? ', no former key kept' : '' );
		$this->traceShellDone( 'Keys of the tokens made ('.self::KEYS_BITS.' bits): '.$prvName.' and '.$publNme.$oldNote );
		$this->printerObj->printShellPair('written', $prvName.', '.$publNme.( $oldText!='' ? ', '.$oldName : '' ));
		return true;
	}

	/**
	 * Writes the line of a user in the htpasswd file the block names
	 * ('htpasswd' unless --useBlock names another): its password is made and
	 * shown once, or typed with --typeItIn, and kept as a bcrypt hash.
	 *
	 * @param String $namedUserTxt Name of the user.
	 *
	 * @throws ShellStop When the name is not one of a htpasswd file, or replacing its line is not confirmed.
	 */
	protected function createHtpasswd( String $namedUserTxt ): Void {
		if( !preg_match('/^[^:\s]+$/D', $namedUserTxt) ){
			$this->terminateShell('A user of a htpasswd file has no colon and no space in its name', self::EXIT_HELP);
		}
		$blckCfg = $this->accessSettings( strval($this->optionsObj->retrieveOption('useBlock', 'htpasswd')) );
		$pwdFile = trim( strval($blckCfg['passFile'] ?? 'conf/.htpasswd'), '/\\ ' );
		$filPath = $this->pathOfSettings($pwdFile);
		$lineLst = is_file($filPath) ? array_values(array_filter(preg_split('/\r?\n/', strval(file_get_contents($filPath))), 'strlen')) : Array();
		$lineIdx = null;
		foreach( $lineLst as $lstIdx => $lstLin ){
			if( strpos($lstLin, $namedUserTxt.':')===0 ){
				$lineIdx = $lstIdx;
			}
		}
		if( $lineIdx!==null && !$this->queryAgreedFlg('Replace the password of user '.$namedUserTxt.' in '.$pwdFile) ){
			$this->terminateShell('Nothing was written: not confirmed', self::EXIT_DENY);
		}
		$isTyped = $this->optionsObj->validateOption('typeItIn');
		$passTxt = $isTyped ? $this->querySecretTxt('Password of '.$namedUserTxt) : bin2hex(random_bytes(10));
		if( $passTxt=='' ){
			$this->terminateShell('Nothing was written: no password given', self::EXIT_DENY);
		}
		$newLine = $namedUserTxt.':'.password_hash($passTxt, PASSWORD_BCRYPT);
		if( $lineIdx===null ){
			$lineLst[] = $newLine;
		}else{
			$lineLst[$lineIdx] = $newLine;
		}
		$this->generateWriter()->saveFileAtomic( $filPath, implode("\n", $lineLst)."\n" );
		$this->traceShellDone( 'Password of the htpasswd user '.json_encode($namedUserTxt).' written in '.$pwdFile );
		$this->printerObj->printShellPair('written', $pwdFile.' '.$namedUserTxt);
		if( !$isTyped ){
			$this->printerObj->printShellOnce('Password of '.$namedUserTxt, $passTxt);
		}
	}

	/**
	 * Lists the routes of a preset: those its route files declare and those
	 * of the Runs it mounts, each with its owner ('site' or the Run), then
	 * the actions its 'security' block names, each with who it is served to:
	 * open (anyone), logged (any logged user), or the least role asked.
	 *
	 * @return Int The exit code: EXITFLAW when the roles of the routes cannot be applied.
	 */
	public function invokeFullList(): Int {
		$prstNme = strval($this->optionsObj->shiftFirstWord() ?? 'defaults');
		try{
			$loadObj = (new Preset($prstNme))->newSetupLoader();
		}catch( \RuntimeException $e ){
			$this->terminateShell( $e->getMessage(), self::EXIT_DENY );
		}
		$rteConf = $loadObj->getRegistryKey('routings');
		$rteConf = is_array($rteConf) ? $rteConf : Array();
		$accConf = $loadObj->setAccessArray();
		$rqstObj = ActCaller::makeRequestArr('GET', '/')[0];
		$rowList = Array();
		foreach( (new Router($rteConf, $rqstObj))->allRoutesArray() as $rteVal ){
			$rowList[] = Array( '/'.$rteVal['routPath'], implode(', ', $rteVal['mthdList']), $rteVal['routKind'], $rteVal['routGoal'], $rteVal['routOwnr'], $this->describeAccess($accConf, $rqstObj, $rteVal) );
		}
		// The actions the access block names, which the path alone reaches in autopath mode
		$roleMap = is_array($accConf['rteRoles'] ?? null) ? $accConf['rteRoles'] : Array();
		$actnLst = Array();
		foreach( array_merge(array_values((array)($accConf['openList'] ?? Array())), array_keys($roleMap)) as $rteKey ){
			$rteKey = strtolower( trim(strval($rteKey)) );
			if( $rteKey!='' && substr($rteKey, 0, 1)!='/' && !in_array($rteKey, $actnLst, true) ){
				$actnLst[] = $rteKey;
			}
		}
		foreach( $actnLst as $action ){
			$actPart = explode('/', $action, 2);
			$rowList[] = Array( '', 'any', 'toAction', $action, '', $this->describeAccess($accConf, $rqstObj, Array('appToRun'=>$actPart[0], 'doAction'=>$actPart[1] ?? '', 'routPath'=>'', 'routKind'=>'toAction')) );
		}
		$this->printerObj->printShellPair('preset', $prstNme);
		$this->printerObj->printShellPair('detectBy', strval($rteConf['detectBy'] ?? ''));
		$this->printerObj->printShellPair('others', ( empty($accConf) || ($accConf['required'] ?? true)===false ) ? 'open' : 'logged');
		$this->printerObj->printShellRows( Array('path', 'methods', 'kind', 'target', 'owner', 'access'), $rowList );
		$rleFlow = (new Checker($accConf, $rqstObj, new Registry(Array())))->checkRoleFlaws();
		if( $rleFlow!='' ){
			$this->printerObj->printShellFail('Every request of this preset is refused: '.$rleFlow);
			return self::EXIT_FLAW;
		}
		return self::EXIT_DONE;
	}

	/**
	 * Tells who a route is served to under an 'security' block.
	 *
	 * @param Array   $accessConfig The 'security' block of the preset.
	 * @param Request $givenRequest A request to build the access check with.
	 * @param Array   $routesValues The route: its 'appToRun', 'doAction', 'routPath' and 'routKind'.
	 * @return String 'open', 'logged', or the least role asked for.
	 */
	protected function describeAccess( Array $accessConfig, Request $givenRequest, Array $routesValues ): String {
		if( empty($accessConfig) || ($accessConfig['required'] ?? true)===false ){
			return 'open';
		}
		$chckObj = new Checker( $accessConfig, $givenRequest, new Registry(Array('appToRun'=>$routesValues['appToRun'] ?? null, 'doAction'=>$routesValues['doAction'] ?? null, 'routPath'=>$routesValues['routPath'] ?? '', 'routKind'=>$routesValues['routKind'] ?? '')) );
		if( $chckObj->openAccessRout() ){
			return 'open';
		}
		return $chckObj->routRoleString() ?? 'logged';
	}

	/**
	 * Reads a preset as it is written, once the framework accepts it.
	 *
	 * @param String $nameOfPreset Name of the preset.
	 * @return Array Its blocks and keys.
	 *
	 * @throws \RuntimeException When the preset is missing or cannot be loaded.
	 */
	protected function retrievePreset( String $nameOfPreset ): Array {
		new Preset($nameOfPreset);
		$jsonDoc = new Dcmnt( $this->pathOfSettings('conf/presets/'.$nameOfPreset.'.rps'), false );
		return (array)$jsonDoc->readFileDetail();
	}

	/**
	 * Follows a path of names written with dots into the values of a
	 * config file.
	 *
	 * @param Array  $configValues The values of the file.
	 * @param String $dotNamesPath The path; empty for the whole file.
	 * @return mixed What stands at the end of the path.
	 *
	 * @throws ShellStop When nothing stands there.
	 */
	protected function followDotPaths( Array $configValues, String $dotNamesPath ): Mixed {
		$nodeVal = $configValues;
		foreach( ($dotNamesPath=='' ? Array() : explode('.', $dotNamesPath)) as $pthKey ){
			if( !is_array($nodeVal) || !array_key_exists($pthKey, $nodeVal) ){
				$this->terminateShell('Nothing under '.$dotNamesPath, self::EXIT_DENY);
			}
			$nodeVal = $nodeVal[$pthKey];
		}
		return $nodeVal;
	}

	/**
	 * Gives the values of a part of a config file as rows of a table,
	 * each named by its path. A secret is masked, unless it only names
	 * where it is kept (env:NAME).
	 *
	 * @param mixed  $filePartData The part of the file.
	 * @param String $prefixString The path it stands at.
	 * @return Array Rows of path and value.
	 */
	protected function plainRowsArray( Mixed $filePartData, String $prefixString ): Array {
		if( is_array($filePartData) && !empty($filePartData) && $filePartData!==array_values($filePartData) ){
			$rowList = Array();
			foreach( $filePartData as $subKey => $subVal ){
				$rowList = array_merge( $rowList, $this->plainRowsArray($subVal, ($prefixString=='') ? strval($subKey) : $prefixString.'.'.$subKey) );
			}
			return $rowList;
		}
		$pthPart = explode('.', $prefixString);
		if( in_array(end($pthPart), self::SECR_LIST, true) ){
			$masking = function( Mixed $codeSecrText ): Mixed {
				return ( is_string($codeSecrText) && $codeSecrText!='' && strpos($codeSecrText, 'env:')!==0 ) ? '********' : $codeSecrText;
			};
			$filePartData = is_array($filePartData) ? array_map($masking, $filePartData) : $masking($filePartData);
		}
		return Array( Array($prefixString, $filePartData) );
	}

	/**
	 * Reads a value typed on the command line: true, false, null, a
	 * number, a JSON list or block, or a text.
	 *
	 * @param String $valueAsTyped The value, as typed.
	 * @return mixed The value.
	 */
	protected function parseTypedText( String $valueAsTyped ): Mixed {
		if( in_array($valueAsTyped, Array('true', 'false', 'null'), true) || preg_match('/^-?\d{1,15}(\.\d+)?$/D', $valueAsTyped) ){
			return json_decode($valueAsTyped);
		}
		if( in_array(substr($valueAsTyped, 0, 1), Array('[', '{'), true) ){
			$jsonVal = json_decode($valueAsTyped);
			if( $jsonVal!==null ){
				return $jsonVal;
			}
		}
		return $valueAsTyped;
	}

	/**
	 * Gives the full path of a file of the project.
	 *
	 * @param String $pathFromRoot Its path from the root of the project, written with slashes.
	 * @return String The full path.
	 */
	protected function pathOfSettings( String $pathFromRoot ): String {
		return TOP_DIR.DS.str_replace('/', DS, $pathFromRoot);
	}

	/**
	 * Gives a writer of config files keeping its copies in temp/archives.
	 *
	 * @return ConfFile The writer.
	 */
	protected function generateWriter(): ConfFile {
		return new ConfFile( TOP_DIR.DS.'temp'.DS.'archives' );
	}
}
