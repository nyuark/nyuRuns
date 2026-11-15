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
 *   php nyuRun setting showConf coreConf          the keys of conf/system.ini
 *   php nyuRun setting showConf accsConf loginSet  a block of conf/access.json, secrets masked
 *   php nyuRun setting showConf psetConf restapi.security  a block of a preset
 *   php nyuRun setting setValue coreConf LOG_MODE BASE
 *   php nyuRun setting setValue accsConf nativeDb.minChars 12
 *   php nyuRun setting setValue dataConf nyuRuns.hostName db.local
 *   php nyuRun setting setValue psetConf restapi.security.authWith nativeDb
 *   php nyuRun setting entryPts index restapi  the preset www/index.php starts the Runner with
 *   php nyuRun setting mkSecret jwtToken        a new secret, written where the config reads it
 *   php nyuRun setting fullList restapi        the routes of a preset, and who they are served to
 *   php nyuRun setting clrCache               removes the config cache, after conf was edited by hand
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
	const SECR_LIST = Array('hmacSecr', 'passWord', 'passwd', 'keysList', 'tfaCrypt');
	// What the keys of system.ini that are checked may hold
	const RULE_LIST = Array(
		'ENV_NAME'=>'/^(DEVS|TEST|PREV|PROD)$/D', 'LOG_MODE'=>'/^(MINI|BASE|FULL)$/D', 'LOG_ROTA'=>'/^(INDX|WEEK|MNTH|NONE)$/D',
		'ERR_SHOW'=>'/^(TRUE|FALSE)$/D', 'CNF_CACH'=>'/^(TRUE|FALSE)$/D', 'TBL_CACH'=>'/^(TRUE|FALSE)$/D','LOG_SIZE'=>'/^\d{1,9}$/D', 'DEF_ROWS'=>'/^[1-9]\d{0,8}$/D', 'SND_SECS'=>'/^[1-9]\d{0,8}$/D',
		'REQ_SIZE'=>'/^\d{1,9}$/D', 'REQ_PART'=>'/^\d{1,9}$/D', 'DEF_LANG'=>'/^[a-z]{2,3}([-_][a-z0-9]{2,8})*$/Di', 'ERR_LEVL'=>'/^(\d{1,9}|~?\s*E_[A-Z_]+(\s*[|&]\s*~?\s*E_[A-Z_]+)*)$/D'
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
			'showConf'=>Array('args'=>'<coreConf|accsConf|dataConf|psetConf> [path]', 'about'=>'shows a config file, one of its blocks or keys; secrets are masked'),
			'setValue'=>Array('args'=>'<coreConf|accsConf|dataConf|psetConf> <path> <value> [--overrule]', 'about'=>'changes a value: KEY, block.key, or preset.block.key'),
			'entryPts'=>Array('args'=>'[basePath] [preset] [--hostName=<host>] [--removeIt]', 'about'=>'lists the presets served (conf/doorways.lst), or sets or removes the one answering a host and the start of a path'),
			'mkSecret'=>Array('args'=>'<jwtToken|keysList|tfaCrypt|markSecr|htpasswd> [user] [--useBlock=<name>] [--showSecr] [--typeItIn] [--dropsAll]', 'about'=>'makes a secret and writes it where the config reads it'),
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
		$dropNumb = ConfCache::clearConfCache();
		$this->traceShellDone( 'Config: cache removed, '.$dropNumb.' files' );
		$this->printerObj->printShellPair('cache files removed', $dropNumb);
		return self::EXIT_DONE;
	}

	/**
	 * Shows a config file, or the part of it a path names.
	 *
	 * @return Int The exit code.
	 */
	public function invokeShowConf(): Int {
		$trgtName = $this->needsShellWord('what to show (coreConf, accsConf, dataConf, psetConf)');
		// Matched whatever its letter case, kept as TRGT_LIST writes it
		foreach( array_merge(array_keys(self::TRGT_LIST), Array('psetConf')) as $oneCod ){
			$trgtName = (strcasecmp($oneCod, $trgtName)==0) ? $oneCod : $trgtName;
		}
		$pathText = strval($this->optionsObj->shiftFirstWord() ?? '');
		if( $trgtName=='psetConf' ){
			return $this->showPresetNumb($pathText);
		}
		if( !isset(self::TRGT_LIST[$trgtName]) ){
			$this->terminateShell('Nothing is shown under that name: '.$trgtName, self::EXIT_HELP);
		}
		$filePath = $this->pathOfSettings(self::TRGT_LIST[$trgtName]);
		$rowsList = Array();
		if( $trgtName=='coreConf' ){
			$lineList = Array();
			preg_match_all('/^[ \t]*([A-Z0-9_]+)[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePath)), $lineList, PREG_SET_ORDER);
			foreach( $lineList as $linVal ){
				if( $pathText=='' || $pathText==$linVal[1] ){
					$rowsList[] = Array($linVal[1], trim($linVal[2], '"'));
				}
			}
			if( empty($rowsList) ){
				$this->terminateShell('Nothing under '.( $pathText!='' ? $pathText.' in ' : '' ).self::TRGT_LIST[$trgtName], self::EXIT_DENY);
			}
		}else{
			$treeList = json_decode( strval(json_encode($this->generateWriter()->importJsonsObj($filePath))), true );
			$rowsList = $this->plainRowsArray( $this->followDotPaths($treeList, $pathText), $pathText );
		}
		$this->printerObj->printShellRows( Array('key', 'value'), $rowsList );
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
			$rowsList = Array();
			foreach( (array)glob($this->pathOfSettings('conf/presets').DS.'*.rps') as $rpsFil ){
				$psetName = basename(strval($rpsFil), '.rps');
				try{
					$confVals = $this->retrievePreset($psetName);
					$rowsList[] = Array($psetName, strval($confVals['presetId'] ?? ''), strval($confVals['synopsis'] ?? ''));
				}catch( \RuntimeException $e ){
					$rowsList[] = Array($psetName, '', 'cannot be loaded: '.$e->getMessage());
				}
			}
			$this->printerObj->printShellRows( Array('preset', 'label', 'about'), $rowsList );
			return self::EXIT_DONE;
		}
		$pathPart = explode('.', $dotNamesPath, 2);
		$this->printerObj->printShellRows( Array('key', 'value'), $this->plainRowsArray( $this->followDotPaths($this->retrievePreset($pathPart[0]), $pathPart[1] ?? ''), $pathPart[1] ?? '' ) );
		return self::EXIT_DONE;
	}

	/**
	 * Changes a value of a config file.
	 *
	 * @return Int The exit code.
	 */
	public function invokeSetValue(): Int {
		$trgtName = $this->needsShellWord('what to write in (coreConf, accsConf, dataConf, psetConf)');
		// Matched whatever its letter case, kept as TRGT_LIST writes it
		foreach( array_merge(array_keys(self::TRGT_LIST), Array('psetConf')) as $oneCod ){
			$trgtName = (strcasecmp($oneCod, $trgtName)==0) ? $oneCod : $trgtName;
		}
		$pathText = $this->needsShellWord('the key to write (KEY, block.key, or preset.block.key)');
		$givenTxt = $this->optionsObj->shiftFirstWord();
		if( $givenTxt===null ){
			$this->terminateShell('Missing argument: the value', self::EXIT_HELP);
		}
		$pathPart = explode('.', $pathText);
		$wrtrInst = $this->generateWriter();
		$isForced = $this->optionsObj->validateOption('overrule');
		try{
			if( $trgtName=='coreConf' ){
				$this->writeSystemIni($wrtrInst, $pathText, $givenTxt);
			}elseif( $trgtName=='accsConf' || $trgtName=='dataConf' ){
				if( count($pathPart)>2 || ($trgtName=='accsConf' && count($pathPart)!=2) || in_array('', $pathPart, true) ){
					$this->terminateShell('A value of '.self::TRGT_LIST[$trgtName].' is named as block.key', self::EXIT_HELP);
				}
				$filePath = $this->pathOfSettings(self::TRGT_LIST[$trgtName]);
				$treeInst = $wrtrInst->importJsonsObj($filePath);
				$nodeInst = $treeInst;
				if( count($pathPart)==2 ){
					$blockNme = $pathPart[0];
					if( !isset($treeInst->$blockNme) || !is_object($treeInst->$blockNme) ){
						if( !$isForced ){
							$this->terminateShell('Nothing was changed: no block '.$blockNme.' in '.self::TRGT_LIST[$trgtName].'; --overrule adds it', self::EXIT_DENY);
						}
						$treeInst->$blockNme = new \stdClass();
					}
					$nodeInst = $treeInst->$blockNme;
				}
				$keyTitle = strval(end($pathPart));
				if( !property_exists($nodeInst, $keyTitle) && !$isForced ){
					$this->terminateShell('Nothing was changed: no key '.$pathText.' in '.self::TRGT_LIST[$trgtName].'; --overrule adds it', self::EXIT_DENY);
				}
				$newValue = $this->parseTypedText($givenTxt);
				// A check or a carrier that does not exist would refuse every request
				if( $trgtName=='accsConf' && ( ($keyTitle=='authWith' && Checker::authClssString(strval($givenTxt))===null) || ($keyTitle=='carrWith' && Checker::sessnClassText(strval($givenTxt))===null) ) ){
					$this->terminateShell('Nothing was changed: '.$givenTxt.' is not a known '.( $keyTitle=='authWith' ? 'credential check' : 'carrier' ), self::EXIT_DENY);
				}
				// The issuer, the audiences and the previous key of the tokens are refused when they cannot be read
				$toknFlaw = ($trgtName=='accsConf') ? $this->toknFlawString($keyTitle, $newValue) : '';
				if( $toknFlaw!='' ){
					$this->terminateShell('Nothing was changed: '.$toknFlaw, self::EXIT_DENY);
				}
				$nodeInst->$keyTitle = $newValue;
				$wrtrInst->saveJsonConfig($filePath, $treeInst);
			}elseif( $trgtName=='psetConf' ){
				if( count($pathPart)<2 || count($pathPart)>3 || in_array('', $pathPart, true) || !preg_match('/^[\w\-]+$/D', $pathPart[0]) ){
					$this->terminateShell('A value of a preset is named as preset.block.key, or preset.key', self::EXIT_HELP);
				}
				$filePath = $this->pathOfSettings('conf/presets/'.$pathPart[0].'.rps');
				if( !is_file($filePath) ){
					$this->terminateShell('Nothing was changed: no preset '.$pathPart[0], self::EXIT_DENY);
				}
				$wrtrInst->setPresetValue( $filePath, (count($pathPart)==3) ? $pathPart[1] : '', strval(end($pathPart)), $this->parseTypedText($givenTxt) );
				try{
					new Preset($pathPart[0]);
				}catch( \RuntimeException $e ){
					$wrtrInst->revertWriteFlg($filePath);
					$this->terminateShell('Nothing was changed: '.$e->getMessage(), self::EXIT_DENY);
				}
			}else{
				$this->terminateShell('Nothing is written under that name: '.$trgtName, self::EXIT_HELP);
			}
		}catch( ShellStop $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			$this->terminateShell( $e->getMessage(), self::EXIT_DENY );
		}
		Console::loadConfigLogs();
		$this->traceShellDone( 'Config: '.$trgtName.' '.$pathText.' written' );
		$this->printerObj->printShellPair('written', $trgtName.' '.$pathText);
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
			$keyTrail = trim($valueAsGiven, '/\\ ');
			if( $keyTrail=='' ){
				return '';
			}
			$keyStrng = is_file($this->pathOfSettings($keyTrail)) ? strval(@file_get_contents($this->pathOfSettings($keyTrail))) : '';
			$keyInstn = ($keyStrng!='' && function_exists('openssl_pkey_get_public')) ? @openssl_pkey_get_public($keyStrng) : false;
			$keyArray = ($keyInstn!==false) ? openssl_pkey_get_details($keyInstn) : false;
			if( !is_array($keyArray) || ($keyArray['type'] ?? null)!==OPENSSL_KEYTYPE_RSA || intval($keyArray['bits'] ?? 0)<\nyu\Access\Carr\JWT::RSAS_BITS ){
				return json_encode($valueAsGiven).' is not a public RSA key of '.\nyu\Access\Carr\JWT::RSAS_BITS.' bits at least';
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
		$filePath = $this->pathOfSettings(self::TRGT_LIST['coreConf']);
		if( !$writerObject->changeIniValue($filePath, $iniEntryName, $newIniValTxt) ){
			$this->terminateShell('Nothing was changed: no key '.$iniEntryName.' in conf/system.ini, and none is added', self::EXIT_DENY);
		}
		if( @parse_ini_file($filePath)===false ){
			$writerObject->revertWriteFlg($filePath);
			$this->terminateShell('Nothing was changed: conf/system.ini could no longer be read with that value', self::EXIT_DENY);
		}
	}

	/**
	 * Lists the lines of the map of the presets served (Runner::DOOR_FILE,
	 * conf/doorways.lst), adds one, changes the preset of one, or removes one
	 * with --removeIt. A line is named by its basePath and its host
	 * (--hostName, any host when left out). A preset that does not load is
	 * refused, and so is the example, which is never loaded. Every change is
	 * traced on 'shell'; the config cache is cleared as by every write in conf.
	 *
	 * @return Int The exit code.
	 */
	public function invokeEntryPts(): Int {
		$baseText = $this->optionsObj->shiftFirstWord();
		$psetName = $this->optionsObj->shiftFirstWord();
		try{
			$rowsList = \nyu\Runner::readDoorwayLst();
		}catch( \RuntimeException $e ){
			$this->terminateShell( 'Nothing was changed: '.$e->getMessage(), self::EXIT_DENY );
		}
		if( $baseText===null ){
			$shownRow = Array();
			foreach( $rowsList as $oneRow ){
				$shownRow[] = Array( ($oneRow['hostName']==='') ? '(any)' : $oneRow['hostName'], $oneRow['basePath'], $oneRow['presetId'] );
			}
			$this->printerObj->printShellRows( Array('host', 'basePath', 'preset'), $shownRow );
			return self::EXIT_DONE;
		}
		$baseText = '/'.trim($baseText, '/');
		$hostText = strtolower( trim(strval($this->optionsObj->retrieveOption('hostName', ''))) );
		if( !preg_match('#^/[\w.\-/]*$#D', $baseText) || !preg_match('/^[a-z0-9.\-]*$/D', $hostText) ){
			$this->terminateShell( 'Invalid line: a basePath of letters, digits, dots, dashes and slashes, a host name of letters, digits, dots and dashes', self::EXIT_HELP );
		}
		$lineText = ($hostText==='' ? '' : $hostText).$baseText;
		$findIndx = null;
		foreach( $rowsList as $rowIdx => $oneRow ){
			if( $oneRow['hostName']===$hostText && $oneRow['basePath']===$baseText ){
				$findIndx = $rowIdx;
			}
		}
		if( $this->optionsObj->validateOption('removeIt') ){
			if( $findIndx===null ){
				$this->terminateShell( 'Nothing was changed: no line for '.$lineText, self::EXIT_DENY );
			}
			$wasPrset = $rowsList[$findIndx]['presetId'];
			unset($rowsList[$findIndx]);
			$this->writeDoorsList( array_values($rowsList) );
			$this->traceShellDone( 'Config: the line '.$lineText.' of the presets served removed (was '.$wasPrset.')' );
			$this->printerObj->printShellPair( 'removed', $lineText );
			return self::EXIT_DONE;
		}
		if( $psetName===null || $psetName==='' ){
			$this->terminateShell( 'Missing argument: the preset that answers '.$lineText, self::EXIT_HELP );
		}
		// The example only explains the keys: it never answers a request
		if( strcasecmp($psetName, Preset::XMPL_NAME)==0 ){
			$this->terminateShell( 'Nothing was changed: the preset '.Preset::XMPL_NAME.' only explains the keys of a preset, and is never loaded', self::EXIT_DENY );
		}
		try{
			new Preset($psetName);
		}catch( \RuntimeException $e ){
			$this->terminateShell( 'Nothing was changed: '.$e->getMessage(), self::EXIT_DENY );
		}
		$wasPrset = ($findIndx===null) ? '' : $rowsList[$findIndx]['presetId'];
		if( $findIndx===null ){
			$rowsList[] = Array('hostName'=>$hostText, 'basePath'=>$baseText, 'presetId'=>$psetName);
		}else{
			$rowsList[$findIndx]['presetId'] = $psetName;
		}
		$this->writeDoorsList( $rowsList );
		$this->traceShellDone( 'Config: '.$lineText.' served by the preset '.$psetName.( ($wasPrset==='') ? ', a line added' : ' (was '.$wasPrset.')' ) );
		$this->printerObj->printShellPair( $lineText, $psetName );
		return self::EXIT_DONE;
	}

	/**
	 * Writes the map of the presets served, conf/doorways.lst, whole: a line
	 * for any host is written without its hostName.
	 *
	 * @param Array $doorwaysList The lines, each with 'hostName', 'basePath' and 'presetId', as Runner::readDoorwayLst() gives them.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function writeDoorsList( Array $doorwaysList ): Void {
		$jsonList = Array();
		foreach( $doorwaysList as $oneRow ){
			$jsonList[] = ( (strval($oneRow['hostName'] ?? '')==='') ? Array() : Array('hostName'=>strval($oneRow['hostName'])) ) + Array('basePath'=>strval($oneRow['basePath']), 'presetId'=>strval($oneRow['presetId']));
		}
		$this->generateWriter()->saveFileAtomic( TOP_DIR.DS.str_replace('/', DS, \nyu\Runner::DOOR_FILE), \nyu\Shell\RunMaker::tabbedJsonText(Array('doorways'=>$jsonList)) );
	}
	/**
	 * Makes a secret and writes it where the config reads it.
	 *
	 * @return Int The exit code.
	 */
	public function invokeMkSecret(): Int {
		$kindName = $this->needsShellWord('the secret to make (jwtToken, keysList, tfaCrypt, markSecr, htpasswd)');
		if( !in_array($kindName, Array('jwtToken', 'keysList', 'tfaCrypt', 'markSecr', 'htpasswd'), true) ){
			$this->terminateShell('No secret is made under that name: '.$kindName, self::EXIT_HELP);
		}
		try{
			if( $kindName=='htpasswd' ){
				$this->createHtpasswd( $this->needsShellWord('the user of the htpasswd line') );
			}else{
				$this->generateSecret($kindName);
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
	 * ('tfaCrypt', in the block of the store the login uses), and writes it
	 * where the block of conf/access.json reads
	 * it: in the environment file when the block names it as env:NAME, in
	 * the block otherwise. The secret of the tokens and the key of the 2FA
	 * secrets are only shown with
	 * --showSecr; an API key is shown once, since its client needs it.
	 *
	 * @param String $secrTypeText 'jwtToken', 'keysList' or 'tfaCrypt'.
	 * @param Bool   $keepExisting Whether a secret already set is left as it is, without a question.
	 * @return Bool Whether a secret was written.
	 *
	 * @throws ShellStop When no block holds such a secret, or replacing it is not confirmed.
	 * @throws \RuntimeException When a file cannot be read or written.
	 */
	public function generateSecret( String $secrTypeText, Bool $keepExisting=false ): Bool {
		$wrtrInst = $this->generateWriter();
		$filePath = $this->pathOfSettings(self::TRGT_LIST['accsConf']);
		$treeInst = $wrtrInst->importJsonsObj($filePath);
		$blockNme = strval($this->optionsObj->retrieveOption('useBlock', ''));
		if( $secrTypeText=='jwtToken' ){
			$labelTxt = 'Secret of the tokens';
			$blockNme = $blockNme ?: strval($treeInst->loginSet->carrConf ?? 'jwtToken');
			// With RS256 the tokens are signed by a key pair, not a secret
			if( isset($treeInst->$blockNme) && is_object($treeInst->$blockNme) && strval($treeInst->$blockNme->signAlgo ?? '')=='RS256' ){
				return $this->buildPairsFlag($blockNme, $keepExisting);
			}
			$rawValue = $treeInst->$blockNme->hmacSecr ?? null;
			$newSecrt = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}elseif( $secrTypeText=='tfaCrypt' ){
			$labelTxt = 'Key of the 2FA secrets';
			$blockNme = $blockNme ?: strval($treeInst->loginSet->authConf ?? 'nativeDb');
			$rawValue = $treeInst->$blockNme->tfaCrypt ?? null;
			$newSecrt = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}elseif( $secrTypeText=='markSecr' ){
			// The key the login marks are signed with, in the limits block
			$labelTxt = 'Key of the login marks';
			$blockNme = $blockNme ?: 'tryLimit';
			$rawValue = $treeInst->$blockNme->markSecr ?? null;
			$newSecrt = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}else{
			$labelTxt = 'API key';
			foreach( get_object_vars($treeInst) as $blkKey => $blkVal ){
				if( $blockNme=='' && is_object($blkVal) && isset($blkVal->keysList) && is_array($blkVal->keysList) ){
					$blockNme = strval($blkKey);
				}
			}
			$rawValue = ($blockNme!='' && isset($treeInst->$blockNme->keysList) && is_array($treeInst->$blockNme->keysList)) ? ($treeInst->$blockNme->keysList[0] ?? '') : null;
			$newSecrt = bin2hex(random_bytes(32));
		}
		if( !is_string($rawValue) ){
			$whatList = Array('jwtToken'=>'the secret of the tokens', 'keysList'=>'a list of API keys', 'tfaCrypt'=>'the key of the 2FA secrets (tfaCrypt)', 'markSecr'=>'the key of the login marks (markSecr)');
			$this->terminateShell('Nothing was written: no block of conf/access.json holds '.$whatList[$secrTypeText].( $blockNme!='' ? ' under '.$blockNme : '' ), self::EXIT_DENY);
		}
		$envrPath = TOP_DIR.DS.'.env';
		$envrName = (strpos($rawValue, 'env:')===0) ? substr($rawValue, 4) : '';
		$nowSecrt = ($envrName!='') ? strval($wrtrInst->retrieveEnvKey($envrPath, $envrName) ?? '') : $rawValue;
		if( $nowSecrt!='' && $keepExisting ){
			return false;
		}
		$lossText = ($secrTypeText=='tfaCrypt') ? 'key of the 2FA secrets in use: every secret stored with it stops being readable' : strtolower($labelTxt).' in use: what was issued with it stops being accepted';
		if( $nowSecrt!='' && !$this->queryAgreedFlg('Replace the '.$lossText) ){
			$this->terminateShell('Nothing was written: not confirmed', self::EXIT_DENY);
		}
		if( $envrName!='' ){
			$wrtrInst->changeEnvValue($envrPath, $envrName, $newSecrt);
			$whereTxt = '.env '.$envrName;
		}else{
			if( $secrTypeText=='jwtToken' ){
				$treeInst->$blockNme->hmacSecr = $newSecrt;
			}elseif( $secrTypeText=='tfaCrypt' ){
				$treeInst->$blockNme->tfaCrypt = $newSecrt;
			}elseif( $secrTypeText=='markSecr' ){
				$treeInst->$blockNme->markSecr = $newSecrt;
			}else{
				$treeInst->$blockNme->keysList[0] = $newSecrt;
			}
			$wrtrInst->saveJsonConfig($filePath, $treeInst);
			$whereTxt = 'conf/access.json '.$blockNme;
		}
		Console::loadConfigLogs();
		$this->traceShellDone( $labelTxt.' made, written in '.$whereTxt );
		$this->printerObj->printShellPair('written', $whereTxt);
		if( $secrTypeText=='keysList' || $this->optionsObj->validateOption('showSecr') ){
			$this->printerObj->printShellOnce($labelTxt, $newSecrt);
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
	 * @param String $blockNmdText Block of conf/access.json.
	 * @param Bool   $keepExisting Whether a private key already there is left as it is, without a question; a public key alone is too when the block says the tokens come from another server ('external'), and is replaced by a pair otherwise.
	 * @return Bool Whether a pair was written.
	 *
	 * @throws ShellStop When replacing the keys is not confirmed, or they cannot be made or written.
	 */
	protected function buildPairsFlag( String $blockNmdText, Bool $keepExisting ): Bool {
		$wrtrInst = $this->generateWriter();
		$filePath = $this->pathOfSettings(self::TRGT_LIST['accsConf']);
		$treeInst = $wrtrInst->importJsonsObj($filePath);
		$blockNde = $treeInst->$blockNmdText;
		$privName = trim(strval($blockNde->privFile ?? ''), '/\\ ');
		$publName = trim(strval($blockNde->publFile ?? ''), '/\\ ');
		$privName = ($privName!='') ? $privName : 'conf/keys/'.$blockNmdText.'.key';
		$publName = ($publName!='') ? $publName : 'conf/keys/'.$blockNmdText.'.pub';
		$privPath = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $privName);
		$publPath = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $publName);
		$hasPairs = is_file($privPath) || is_file($publPath);
		// A public key found alone is the one of another server only when the block says so : a bundle may have brought it from where it was built
		if( $keepExisting && ( is_file($privPath) || (is_file($publPath) && ($blockNde->external ?? false)===true) ) ){
			return false;
		}
		if( !function_exists('openssl_pkey_new') ){
			$this->terminateShell('No keys made: the openssl extension is not loaded', self::EXIT_FAIL);
		}
		// The public key of the pair replaced keeps checking the tokens it signed, until the next pair
		$oldTitle = 'conf/keys/'.$blockNmdText.'.old.pub';
		$oldPrivt = is_file($privPath) ? @openssl_pkey_get_private(strval(@file_get_contents($privPath))) : false;
		// A key that leaked keeps nothing : the tokens it signed end with it
		$hasDrops = $this->optionsObj->validateOption('dropsAll');
		$oldStrng = ($oldPrivt!==false && !$hasDrops) ? strval(openssl_pkey_get_details($oldPrivt)['key'] ?? '') : '';
		$lossText = ($oldStrng!='') ? 'the tokens issued with them are still accepted until they end, checked with '.$oldTitle : ( $hasDrops ? 'every token in use ends now' : 'what was issued with them stops being accepted' );
		// install complete replaces a public key found alone without a question : it is not one of this server's
		if( $hasPairs && !$keepExisting && !$this->queryAgreedFlg('Replace the keys of the tokens in use: '.$lossText) ){
			$this->terminateShell('Nothing was written: not confirmed', self::EXIT_DENY);
		}
		$confOpts = Array('config'=>dirname(__DIR__).DS.'keygen.cnf');
		$keyInstn = @openssl_pkey_new( Array('private_key_bits'=>self::KEYS_BITS, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $confOpts );
		$privText = '';
		if( $keyInstn===false || !@openssl_pkey_export($keyInstn, $privText, null, $confOpts) ){
			$this->terminateShell('No keys made: OpenSSL refused: '.strval(openssl_error_string()), self::EXIT_FAIL);
		}
		$publText = strval(openssl_pkey_get_details($keyInstn)['key'] ?? '');
		$pemsList = Array($privPath=>Array($privText, 0600), $publPath=>Array($publText, 0644));
		if( $oldStrng!='' ){
			$pemsList[TOP_DIR.DS.str_replace('/', DS, $oldTitle)] = Array($oldStrng, 0644);
		}
		foreach( $pemsList as $keyFil => $onePem ){
			if( !is_dir(dirname($keyFil)) ){
				@mkdir(dirname($keyFil), 0700, true);
			}
			// Written aside, given its rights, then put in place : no copy of the former key is kept
			$tempPath = $keyFil.'.tmp'.getmypid();
			// Readable by its owner only from its first byte, then given its rights
			$prevMask = umask(0077);
			try{
				$wasWrttn = @file_put_contents($tempPath, $onePem[0], LOCK_EX);
			}finally{
				umask($prevMask);
			}
			if( $wasWrttn===false ){
				$this->terminateShell('No keys written: '.dirname($keyFil).' cannot be written', self::EXIT_FAIL);
			}
			@chmod($tempPath, $onePem[1]);
			if( !@rename($tempPath, $keyFil) ){
				@unlink($tempPath);
				$this->terminateShell('No keys written: '.basename($keyFil).' cannot be replaced', self::EXIT_FAIL);
			}
		}
		$blockNde->privFile = $privName;
		$blockNde->publFile = $publName;
		if( $oldStrng!='' ){
			// The time the former key was retired : what it signs after, or beyond lifeSecs, is refused
			$blockNde->prevFile = $oldTitle;
			$blockNde->retireAt = time();
		}elseif( $hasDrops || isset($blockNde->prevFile) ){
			if( is_file(TOP_DIR.DS.str_replace('/', DS, $oldTitle)) ){
				@unlink(TOP_DIR.DS.str_replace('/', DS, $oldTitle));
			}
			$blockNde->prevFile = '';
			unset($blockNde->retireAt);
		}
		$wrtrInst->saveJsonConfig($filePath, $treeInst);
		Console::loadConfigLogs();
		$prevNote = ($oldStrng!='') ? ', the former public key kept as '.$oldTitle : ( $hasDrops ? ', no former key kept' : '' );
		$this->traceShellDone( 'Keys of the tokens made ('.self::KEYS_BITS.' bits): '.$privName.' and '.$publName.$prevNote );
		$this->printerObj->printShellPair('written', $privName.', '.$publName.( $oldStrng!='' ? ', '.$oldTitle : '' ));
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
		$blockCfg = $this->accessSettings( strval($this->optionsObj->retrieveOption('useBlock', 'htpasswd')) );
		$passFile = trim( strval($blockCfg['passFile'] ?? 'conf/.htpasswd'), '/\\ ' );
		$filePath = $this->pathOfSettings($passFile);
		$lineList = is_file($filePath) ? array_values(array_filter(preg_split('/\r?\n/', strval(file_get_contents($filePath))), 'strlen')) : Array();
		$lineIndx = null;
		foreach( $lineList as $lstIdx => $lstLin ){
			if( strpos($lstLin, $namedUserTxt.':')===0 ){
				$lineIndx = $lstIdx;
			}
		}
		if( $lineIndx!==null && !$this->queryAgreedFlg('Replace the password of user '.$namedUserTxt.' in '.$passFile) ){
			$this->terminateShell('Nothing was written: not confirmed', self::EXIT_DENY);
		}
		$isTypeIn = $this->optionsObj->validateOption('typeItIn');
		$passText = $isTypeIn ? $this->querySecretTxt('Password of '.$namedUserTxt) : bin2hex(random_bytes(10));
		if( $passText=='' ){
			$this->terminateShell('Nothing was written: no password given', self::EXIT_DENY);
		}
		$newLigne = $namedUserTxt.':'.password_hash($passText, PASSWORD_BCRYPT);
		if( $lineIndx===null ){
			$lineList[] = $newLigne;
		}else{
			$lineList[$lineIndx] = $newLigne;
		}
		$this->generateWriter()->saveFileAtomic( $filePath, implode("\n", $lineList)."\n" );
		$this->traceShellDone( 'Password of the htpasswd user '.json_encode($namedUserTxt).' written in '.$passFile );
		$this->printerObj->printShellPair('written', $passFile.' '.$namedUserTxt);
		if( !$isTypeIn ){
			$this->printerObj->printShellOnce('Password of '.$namedUserTxt, $passText);
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
		$psetName = strval($this->optionsObj->shiftFirstWord() ?? 'default');
		try{
			$loadInst = (new Preset($psetName))->makeConfLoader();
		}catch( \RuntimeException $e ){
			$this->terminateShell( $e->getMessage(), self::EXIT_DENY );
		}
		$routeCfg = $loadInst->getRegistryKey('dispatch');
		$routeCfg = is_array($routeCfg) ? $routeCfg : Array();
		$accsConf = $loadInst->setAccessArray();
		$reqtInst = ActCaller::makeRequestArr('GET', '/')[0];
		$rowsList = Array();
		$routInst = new Router($routeCfg, $reqtInst);
		foreach( $routInst->allRoutesArray() as $rteVal ){
			$rowsList[] = Array( '/'.$rteVal['routPath'], implode(', ', $rteVal['mthdList']), $rteVal['routKind'], $rteVal['routTarg'], $rteVal['routOwnr'], $this->describeAccess($accsConf, $reqtInst, $rteVal, $routInst) );
		}
		// The actions the access block names, which the path alone reaches in autopath mode
		$roleDict = is_array($accsConf['minRoles'] ?? null) ? $accsConf['minRoles'] : Array();
		$actnList = Array();
		foreach( array_merge(array_values((array)($accsConf['openList'] ?? Array())), array_keys($roleDict)) as $rteKey ){
			$rteKey = strtolower( trim(strval($rteKey)) );
			if( $rteKey!='' && substr($rteKey, 0, 1)!='/' && !in_array($rteKey, $actnList, true) ){
				$actnList[] = $rteKey;
			}
		}
		foreach( $actnList as $action ){
			$actParte = explode('/', $action, 2);
			$rowsList[] = Array( '', 'any', 'toAction', $action, '', $this->describeAccess($accsConf, $reqtInst, Array('appToRun'=>$actParte[0], 'doAction'=>$actParte[1] ?? '', 'routPath'=>'', 'routKind'=>'toAction'), $routInst) );
		}
		$this->printerObj->printShellPair('preset', $psetName);
		$this->printerObj->printShellPair('detectBy', strval($routeCfg['detectBy'] ?? ''));
		$this->printerObj->printShellPair('others', ( empty($accsConf) || ($accsConf['required'] ?? true)===false ) ? 'open' : 'logged');
		$this->printerObj->printShellRows( Array('path', 'methods', 'kind', 'target', 'owner', 'access'), $rowsList );
		$roleFlow = (new Checker($accsConf, $reqtInst, new Registry(Array()), $routInst))->checkRoleFlaws();
		if( $roleFlow!='' ){
			$this->printerObj->printShellFail('Every request of this preset is refused: '.$roleFlow);
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
	 * @param Router  $routerObject The router of the preset, for the roles a declared path carries to its action.
	 * @return String 'open', 'logged', or the least role asked for.
	 */
	protected function describeAccess( Array $accessConfig, Request $givenRequest, Array $routesValues, Router $routerObject ): String {
		if( empty($accessConfig) || ($accessConfig['required'] ?? true)===false ){
			return 'open';
		}
		$checkObj = new Checker( $accessConfig, $givenRequest, new Registry(Array('appToRun'=>$routesValues['appToRun'] ?? null, 'doAction'=>$routesValues['doAction'] ?? null, 'routPath'=>$routesValues['routPath'] ?? '', 'routKind'=>$routesValues['routKind'] ?? '')), $routerObject );
		if( $checkObj->openAccessRout() ){
			return 'open';
		}
		return $checkObj->routRoleString() ?? 'logged';
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
		$jsonDocu = new Dcmnt( $this->pathOfSettings('conf/presets/'.$nameOfPreset.'.rps'), false );
		return (array)$jsonDocu->readFileDetail();
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
		$nodeVals = $configValues;
		foreach( ($dotNamesPath=='' ? Array() : explode('.', $dotNamesPath)) as $pthKey ){
			if( !is_array($nodeVals) || !array_key_exists($pthKey, $nodeVals) ){
				$this->terminateShell('Nothing under '.$dotNamesPath, self::EXIT_DENY);
			}
			$nodeVals = $nodeVals[$pthKey];
		}
		return $nodeVals;
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
			$rowsList = Array();
			foreach( $filePartData as $subKey => $subVal ){
				$rowsList = array_merge( $rowsList, $this->plainRowsArray($subVal, ($prefixString=='') ? strval($subKey) : $prefixString.'.'.$subKey) );
			}
			return $rowsList;
		}
		$pathPart = explode('.', $prefixString);
		if( in_array(end($pathPart), self::SECR_LIST, true) ){
			$maskFunc = function( Mixed $codeSecrText ): Mixed {
				return ( is_string($codeSecrText) && $codeSecrText!='' && strpos($codeSecrText, 'env:')!==0 ) ? '********' : $codeSecrText;
			};
			$filePartData = is_array($filePartData) ? array_map($maskFunc, $filePartData) : $maskFunc($filePartData);
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
			$jsonVals = json_decode($valueAsTyped);
			if( $jsonVals!==null ){
				return $jsonVals;
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
