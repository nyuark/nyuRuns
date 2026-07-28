<?php

namespace nyu\Shell\Cmds;

use nyu\Access\Checker;
use nyu\Data\Dcmnt;
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
 *   php nyuRun config shows system              the keys of conf/system.ini
 *   php nyuRun config shows access login        a block of conf/access.json, secrets masked
 *   php nyuRun config shows preset webapi.auths a block of a preset
 *   php nyuRun config write system LOG_MODE BASE
 *   php nyuRun config write access native.minlen 12
 *   php nyuRun config write dbases nyuRuns.host db.local
 *   php nyuRun config write preset webapi.auths.authby native
 *   php nyuRun config entry index webapi        the preset www/index.php starts the Runner with
 *   php nyuRun config secrt jtoken              a new secret, written where the config reads it
 *   php nyuRun config route webapi              the routes of a preset, and who they are served to
 *
 * A file is written as ConfFile does: comments and layout kept, the
 * former file copied to temp/backup. A change that leaves the file
 * unusable is undone.
 */
class Config extends Command{

	// The config files that are read and written, by the name they are asked under
	const TRGTLIST = Array('system'=>'conf/system.ini', 'access'=>'conf/access.json', 'dbases'=>'conf/database.json');
	// Size of the RSA keys made for the tokens, in bits
	const KEYSBITS = 4096;
	// Keys whose value is not shown, unless it only names where the secret is (env:NAME)
	const SECRLIST = Array('secret', 'pass', 'passwd', 'keylst', 'tfakey');
	// What the keys of system.ini that are checked may hold
	const RULELIST = Array(
		'ENV_NAME'=>'/^(DEV|TEST|PREV|PROD)$/D', 'LOG_MODE'=>'/^(MINI|BASE|FULL)$/D', 'LOG_ROTA'=>'/^(NUMBER|WEEKLY|MONTHS|UNIQUE)$/D',
		'ERR_SHOW'=>'/^(TRUE|FALSE)$/D', 'LOG_SIZE'=>'/^\d{1,9}$/D', 'LST_ROWS'=>'/^[1-9]\d{0,8}$/D', 'SND_TIME'=>'/^[1-9]\d{0,8}$/D',
		'REQ_SIZE'=>'/^\d{1,9}$/D', 'REQ_PART'=>'/^\d{1,9}$/D'
	);
	// The call that starts the Runner in an entry point, and the preset it names
	const RUNRCALL = '/(new\s+\\\\?nyu\\\\Runner\s*\(\s*)(([\'"])[\w\-]*\3)?/';

	/**
	 * Tells in one line what the command does.
	 *
	 * @return String The line shown in the list of commands.
	 */
	public function aboutText(): String {
		return 'Reads and changes the configuration: system, access, databases, presets, secrets, routes';
	}

	/**
	 * Lists the sub-commands of the command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	public function subsTable(): Array {
		return Array(
			'shows'=>Array('args'=>'<system|access|dbases|preset> [path]', 'about'=>'shows a config file, one of its blocks or keys; secrets are masked'),
			'write'=>Array('args'=>'<system|access|dbases|preset> <path> <value> [--force]', 'about'=>'changes a value: KEY, block.key, or preset.block.key'),
			'entry'=>Array('args'=>'[file] [preset]', 'about'=>'shows or changes the preset an entry point of www starts the Runner with'),
			'secrt'=>Array('args'=>'<jtoken|apikey|tfakey|htpass> [user] [--block=<name>] [--shown] [--typed]', 'about'=>'makes a secret and writes it where the config reads it'),
			'route'=>Array('args'=>'[preset]', 'about'=>'lists the routes of a preset: path, methods, kind, target, access')
		);
	}

	/**
	 * Shows a config file, or the part of it a path names.
	 *
	 * @return Int The exit code.
	 */
	public function callShows(): Int {
		$tgtName = $this->needsWord('what to show (system, access, dbases, preset)');
		$pathTxt = strval($this->optnObjc->shiftWord() ?? '');
		if( $tgtName=='preset' ){
			return $this->showPrset($pathTxt);
		}
		if( !isset(self::TRGTLIST[$tgtName]) ){
			$this->stopShell('Nothing is shown under that name: '.$tgtName, self::EXITUSGE);
		}
		$filePth = $this->pathOfCnf(self::TRGTLIST[$tgtName]);
		$rowList = Array();
		if( $tgtName=='system' ){
			$lineLst = Array();
			preg_match_all('/^[ \t]*([A-Z0-9_]+)[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePth)), $lineLst, PREG_SET_ORDER);
			foreach( $lineLst as $lineVl ){
				if( $pathTxt=='' || $pathTxt==$lineVl[1] ){
					$rowList[] = Array($lineVl[1], trim($lineVl[2], '"'));
				}
			}
			if( empty($rowList) ){
				$this->stopShell('Nothing under '.( $pathTxt!='' ? $pathTxt.' in ' : '' ).self::TRGTLIST[$tgtName], self::EXITDENY);
			}
		}else{
			$treeArr = json_decode( strval(json_encode($this->makeWritr()->loadJsons($filePth))), true );
			$rowList = $this->plainRows( $this->walkPaths($treeArr, $pathTxt), $pathTxt );
		}
		$this->prntObjc->printRows( Array('key', 'value'), $rowList );
		return self::EXITDONE;
	}

	/**
	 * Lists the presets, or shows one, or the part of it a path names.
	 *
	 * @param String $pathsTexts Empty for the list; otherwise the name of a preset, followed or not by .block and .key.
	 * @return Int The exit code.
	 */
	protected function showPrset( String $pathsTexts ): Int {
		if( $pathsTexts=='' ){
			$rowList = Array();
			foreach( (array)glob($this->pathOfCnf('conf/presets').DS.'*.rps') as $rpsFle ){
				$prstNme = basename(strval($rpsFle), '.rps');
				try{
					$rpsVals = $this->readPrset($prstNme);
					$rowList[] = Array($prstNme, strval($rpsVals['label'] ?? ''), strval($rpsVals['about'] ?? ''));
				}catch( \RuntimeException $e ){
					$rowList[] = Array($prstNme, '', 'cannot be loaded: '.$e->getMessage());
				}
			}
			$this->prntObjc->printRows( Array('preset', 'label', 'about'), $rowList );
			return self::EXITDONE;
		}
		$pathPrt = explode('.', $pathsTexts, 2);
		$this->prntObjc->printRows( Array('key', 'value'), $this->plainRows( $this->walkPaths($this->readPrset($pathPrt[0]), $pathPrt[1] ?? ''), $pathPrt[1] ?? '' ) );
		return self::EXITDONE;
	}

	/**
	 * Changes a value of a config file.
	 *
	 * @return Int The exit code.
	 */
	public function callWrite(): Int {
		$tgtName = $this->needsWord('what to write in (system, access, dbases, preset)');
		$pathTxt = $this->needsWord('the key to write (KEY, block.key, or preset.block.key)');
		$gvnText = $this->optnObjc->shiftWord();
		if( $gvnText===null ){
			$this->stopShell('Missing argument: the value', self::EXITUSGE);
		}
		$pathPrt = explode('.', $pathTxt);
		$wrtrObj = $this->makeWritr();
		$isForcd = $this->optnObjc->hasOption('force');
		try{
			if( $tgtName=='system' ){
				$this->writesIni($wrtrObj, $pathTxt, $gvnText);
			}elseif( $tgtName=='access' || $tgtName=='dbases' ){
				if( count($pathPrt)>2 || ($tgtName=='access' && count($pathPrt)!=2) || in_array('', $pathPrt, true) ){
					$this->stopShell('A value of '.self::TRGTLIST[$tgtName].' is named as block.key', self::EXITUSGE);
				}
				$filePth = $this->pathOfCnf(self::TRGTLIST[$tgtName]);
				$treeObj = $wrtrObj->loadJsons($filePth);
				$nodeObj = $treeObj;
				if( count($pathPrt)==2 ){
					$blkName = $pathPrt[0];
					if( !isset($treeObj->$blkName) || !is_object($treeObj->$blkName) ){
						if( !$isForcd ){
							$this->stopShell('Nothing was changed: no block '.$blkName.' in '.self::TRGTLIST[$tgtName].'; --force adds it', self::EXITDENY);
						}
						$treeObj->$blkName = new \stdClass();
					}
					$nodeObj = $treeObj->$blkName;
				}
				$keyName = strval(end($pathPrt));
				if( !property_exists($nodeObj, $keyName) && !$isForcd ){
					$this->stopShell('Nothing was changed: no key '.$pathTxt.' in '.self::TRGTLIST[$tgtName].'; --force adds it', self::EXITDENY);
				}
				$newValu = $this->readTyped($gvnText);
				// A check or a carrier that does not exist would refuse every request
				if( $tgtName=='access' && ( ($keyName=='authby' && Checker::authClass(strval($gvnText))===null) || ($keyName=='sessby' && Checker::sessClass(strval($gvnText))===null) ) ){
					$this->stopShell('Nothing was changed: '.$gvnText.' is not a known '.( $keyName=='authby' ? 'credential check' : 'session carrier' ), self::EXITDENY);
				}
				$nodeObj->$keyName = $newValu;
				$wrtrObj->saveJsons($filePth, $treeObj);
			}elseif( $tgtName=='preset' ){
				if( count($pathPrt)<2 || count($pathPrt)>3 || in_array('', $pathPrt, true) || !preg_match('/^[\w\-]+$/D', $pathPrt[0]) ){
					$this->stopShell('A value of a preset is named as preset.block.key, or preset.key', self::EXITUSGE);
				}
				$filePth = $this->pathOfCnf('conf/presets/'.$pathPrt[0].'.rps');
				if( !is_file($filePth) ){
					$this->stopShell('Nothing was changed: no preset '.$pathPrt[0], self::EXITDENY);
				}
				$wrtrObj->setRpsKey( $filePth, (count($pathPrt)==3) ? $pathPrt[1] : '', strval(end($pathPrt)), $this->readTyped($gvnText) );
				try{
					new Preset($pathPrt[0]);
				}catch( \RuntimeException $e ){
					$wrtrObj->undoWrite($filePth);
					$this->stopShell('Nothing was changed: '.$e->getMessage(), self::EXITDENY);
				}
			}else{
				$this->stopShell('Nothing is written under that name: '.$tgtName, self::EXITUSGE);
			}
		}catch( ShellStop $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			$this->stopShell( $e->getMessage(), self::EXITDENY );
		}
		Console::loadConfg();
		$this->traceDone( 'Config: '.$tgtName.' '.$pathTxt.' written' );
		$this->prntObjc->printPair('written', $tgtName.' '.$pathTxt);
		return self::EXITDONE;
	}

	/**
	 * Changes a key of conf/system.ini, checking the values of the keys
	 * the framework reads as codes or numbers.
	 *
	 * @param ConfFile $writerObjc The writer of config files.
	 * @param String   $iniKeyName Name of the key.
	 * @param String   $newIniValu Its new value.
	 *
	 * @throws ShellStop When the key or its value is refused, or the file is left unusable.
	 */
	protected function writesIni( ConfFile $writerObjc, String $iniKeyName, String $newIniValu ): Void {
		if( !preg_match('/^[A-Z0-9]{3}_[A-Z0-9]{4}$/D', $iniKeyName) ){
			$this->stopShell('A key of conf/system.ini is written XXX_YYYY', self::EXITUSGE);
		}
		if( isset(self::RULELIST[$iniKeyName]) && !preg_match(self::RULELIST[$iniKeyName], $newIniValu) ){
			$this->stopShell('Nothing was changed: '.$iniKeyName.' does not take the value '.$newIniValu, self::EXITDENY);
		}
		$filePth = $this->pathOfCnf(self::TRGTLIST['system']);
		if( !$writerObjc->setIniKey($filePth, $iniKeyName, $newIniValu) ){
			$this->stopShell('Nothing was changed: no key '.$iniKeyName.' in conf/system.ini, and none is added', self::EXITDENY);
		}
		if( @parse_ini_file($filePth)===false ){
			$writerObjc->undoWrite($filePth);
			$this->stopShell('Nothing was changed: conf/system.ini could no longer be read with that value', self::EXITDENY);
		}
	}

	/**
	 * Shows the preset each entry point of www starts the Runner with, or
	 * changes the one of an entry point.
	 *
	 * @return Int The exit code.
	 */
	public function callEntry(): Int {
		$fileNme = $this->optnObjc->shiftWord();
		$prstNme = $this->optnObjc->shiftWord();
		if( $fileNme===null ){
			$rowList = Array();
			foreach( (array)glob(WEB_DIR.DS.'*.php') as $phpFle ){
				$fndList = Array();
				if( preg_match(self::RUNRCALL, strval(file_get_contents(strval($phpFle))), $fndList) ){
					$rowList[] = Array( basename(strval($phpFle), '.php'), trim(strval($fndList[2] ?? ''), '\'"') ?: 'dfault' );
				}
			}
			$this->prntObjc->printRows( Array('entry', 'preset'), $rowList );
			return self::EXITDONE;
		}
		$filePth = WEB_DIR.DS.$fileNme.'.php';
		if( !preg_match('/^[\w\-]+$/D', $fileNme) || !is_file($filePth) ){
			$this->stopShell('No entry point www/'.$fileNme.'.php', self::EXITDENY);
		}
		$fileTxt = strval(file_get_contents($filePth));
		$fndList = Array();
		$fndNmbr = preg_match_all(self::RUNRCALL, $fileTxt, $fndList, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		if( $fndNmbr<1 ){
			$this->stopShell('www/'.$fileNme.'.php does not start a Runner', self::EXITDENY);
		}
		$nowPrst = trim(strval($fndList[0][2][0] ?? ''), '\'"') ?: 'dfault';
		if( $prstNme===null ){
			$this->prntObjc->printPair($fileNme, $nowPrst);
			return self::EXITDONE;
		}
		$fullOff = intval($fndList[0][0][1]);
		$fullEnd = $fullOff + strlen($fndList[0][0][0]);
		$hasName = isset($fndList[0][2]) && $fndList[0][2][0]!=='';
		if( $fndNmbr!=1 || (!$hasName && ($fileTxt[$fullEnd] ?? '')!=')') ){
			$this->stopShell('Nothing was changed: www/'.$fileNme.'.php does not name its preset in a way that can be rewritten; change it by hand', self::EXITDENY);
		}
		try{
			new Preset($prstNme);
		}catch( \RuntimeException $e ){
			$this->stopShell('Nothing was changed: '.$e->getMessage(), self::EXITDENY);
		}
		$this->makeWritr()->writeFile( $filePth, substr($fileTxt, 0, $fullOff).$fndList[0][1][0]."'".$prstNme."'".substr($fileTxt, $fullEnd) );
		$this->traceDone( 'Config: entry point '.$fileNme.' set to the preset '.$prstNme.' (was '.$nowPrst.')' );
		$this->prntObjc->printPair($fileNme, $prstNme);
		return self::EXITDONE;
	}

	/**
	 * Makes a secret and writes it where the config reads it.
	 *
	 * @return Int The exit code.
	 */
	public function callSecrt(): Int {
		$kindNme = $this->needsWord('the secret to make (jtoken, apikey, tfakey, htpass)');
		if( !in_array($kindNme, Array('jtoken', 'apikey', 'tfakey', 'htpass'), true) ){
			$this->stopShell('No secret is made under that name: '.$kindNme, self::EXITUSGE);
		}
		try{
			if( $kindNme=='htpass' ){
				$this->makeHtpwd( $this->needsWord('the user of the htpasswd line') );
			}else{
				$this->makeSecrt($kindNme);
			}
		}catch( ShellStop $e ){
			throw $e;
		}catch( \RuntimeException $e ){
			$this->stopShell( $e->getMessage(), self::EXITDENY );
		}
		return self::EXITDONE;
	}

	/**
	 * Makes the secret signing the tokens ('jtoken'), an API key
	 * ('apikey') or the key the 2FA secrets are stored encrypted with
	 * ('tfakey', in the block of the store the login uses), and writes it
	 * where the block of conf/access.json reads
	 * it: in the environment file when the block names it as env:NAME, in
	 * the block otherwise. The secret of the tokens and the key of the 2FA
	 * secrets are only shown with
	 * --shown; an API key is shown once, since its client needs it.
	 *
	 * @param String $secretKind 'jtoken', 'apikey' or 'tfakey'.
	 * @param Bool   $onlyIfVoid Whether a secret already set is left as it is, without a question.
	 * @return Bool Whether a secret was written.
	 *
	 * @throws ShellStop When no block holds such a secret, or replacing it is not confirmed.
	 * @throws \RuntimeException When a file cannot be read or written.
	 */
	public function makeSecrt( String $secretKind, Bool $onlyIfVoid=false ): Bool {
		$wrtrObj = $this->makeWritr();
		$filePth = $this->pathOfCnf(self::TRGTLIST['access']);
		$treeObj = $wrtrObj->loadJsons($filePth);
		$blkName = strval($this->optnObjc->getOption('block', ''));
		if( $secretKind=='jtoken' ){
			$labelTx = 'Secret of the tokens';
			$blkName = $blkName ?: strval($treeObj->login->sesscf ?? 'dftokn');
			// With RS256 the tokens are signed by a key pair, not a secret
			if( isset($treeObj->$blkName) && is_object($treeObj->$blkName) && strval($treeObj->$blkName->algrtm ?? '')=='RS256' ){
				return $this->makePairs($blkName, $onlyIfVoid);
			}
			$rawValu = $treeObj->$blkName->secret ?? null;
			$newScrt = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}elseif( $secretKind=='tfakey' ){
			$labelTx = 'Key of the 2FA secrets';
			$blkName = $blkName ?: strval($treeObj->login->authcf ?? 'native');
			$rawValu = $treeObj->$blkName->tfakey ?? null;
			$newScrt = rtrim( strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=' );
		}else{
			$labelTx = 'API key';
			foreach( get_object_vars($treeObj) as $blkKey => $blkVal ){
				if( $blkName=='' && is_object($blkVal) && isset($blkVal->keylst) && is_array($blkVal->keylst) ){
					$blkName = strval($blkKey);
				}
			}
			$rawValu = ($blkName!='' && isset($treeObj->$blkName->keylst) && is_array($treeObj->$blkName->keylst)) ? ($treeObj->$blkName->keylst[0] ?? '') : null;
			$newScrt = bin2hex(random_bytes(32));
		}
		if( !is_string($rawValu) ){
			$whatLst = Array('jtoken'=>'the secret of the tokens', 'apikey'=>'a list of API keys', 'tfakey'=>'the key of the 2FA secrets (tfakey)');
			$this->stopShell('Nothing was written: no block of conf/access.json holds '.$whatLst[$secretKind].( $blkName!='' ? ' under '.$blkName : '' ), self::EXITDENY);
		}
		$envPath = TOP_DIR.DS.'.env';
		$envName = (strpos($rawValu, 'env:')===0) ? substr($rawValu, 4) : '';
		$nowScrt = ($envName!='') ? strval($wrtrObj->getEnvKey($envPath, $envName) ?? '') : $rawValu;
		if( $nowScrt!='' && $onlyIfVoid ){
			return false;
		}
		$lossTxt = ($secretKind=='tfakey') ? 'key of the 2FA secrets in use: every secret stored with it stops being readable' : strtolower($labelTx).' in use: what was issued with it stops being accepted';
		if( $nowScrt!='' && !$this->askAgreed('Replace the '.$lossTxt) ){
			$this->stopShell('Nothing was written: not confirmed', self::EXITDENY);
		}
		if( $envName!='' ){
			$wrtrObj->setEnvKey($envPath, $envName, $newScrt);
			$whereTx = '.env '.$envName;
		}else{
			if( $secretKind=='jtoken' ){
				$treeObj->$blkName->secret = $newScrt;
			}elseif( $secretKind=='tfakey' ){
				$treeObj->$blkName->tfakey = $newScrt;
			}else{
				$treeObj->$blkName->keylst[0] = $newScrt;
			}
			$wrtrObj->saveJsons($filePth, $treeObj);
			$whereTx = 'conf/access.json '.$blkName;
		}
		Console::loadConfg();
		$this->traceDone( $labelTx.' made, written in '.$whereTx );
		$this->prntObjc->printPair('written', $whereTx);
		if( $secretKind=='apikey' || $this->optnObjc->hasOption('shown') ){
			$this->prntObjc->printOnce($labelTx, $newScrt);
		}
		return true;
	}

	/**
	 * Makes the RSA key pair of a block whose algorithm is RS256: the
	 * private key signs the tokens, the public key checks them. Both are
	 * PEM files, in conf/keys under the name of the block unless it names
	 * others; the private one is readable by its owner only, and no copy of
	 * a key replaced is kept. The block is given the paths it lacks. A new
	 * pair ends every token signed with the former one.
	 *
	 * @param String $blockNamed Block of conf/access.json.
	 * @param Bool   $onlyIfVoid Whether keys already there are left as they are, without a question: a public key copied from the server issuing the tokens is one.
	 * @return Bool Whether a pair was written.
	 *
	 * @throws ShellStop When replacing the keys is not confirmed, or they cannot be made or written.
	 */
	protected function makePairs( String $blockNamed, Bool $onlyIfVoid ): Bool {
		$wrtrObj = $this->makeWritr();
		$filePth = $this->pathOfCnf(self::TRGTLIST['access']);
		$treeObj = $wrtrObj->loadJsons($filePth);
		$blkNode = $treeObj->$blockNamed;
		$prvName = trim(strval($blkNode->prvkey ?? ''), '/\\ ');
		$pubName = trim(strval($blkNode->pubkey ?? ''), '/\\ ');
		$prvName = ($prvName!='') ? $prvName : 'conf/keys/'.$blockNamed.'.key';
		$pubName = ($pubName!='') ? $pubName : 'conf/keys/'.$blockNamed.'.pub';
		$prvPath = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $prvName);
		$pubPath = TOP_DIR.DS.str_replace(Array('/', '\\'), DS, $pubName);
		$hasKeys = is_file($prvPath) || is_file($pubPath);
		if( $hasKeys && $onlyIfVoid ){
			return false;
		}
		if( $hasKeys && !$this->askAgreed('Replace the keys of the tokens in use: what was issued with them stops being accepted') ){
			$this->stopShell('Nothing was written: not confirmed', self::EXITDENY);
		}
		if( !function_exists('openssl_pkey_new') ){
			$this->stopShell('No keys made: the openssl extension is not loaded', self::EXITFAIL);
		}
		$cnfOpts = Array('config'=>dirname(__DIR__).DS.'keygen.cnf');
		$keyObjc = @openssl_pkey_new( Array('private_key_bits'=>self::KEYSBITS, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $cnfOpts );
		$prvText = '';
		if( $keyObjc===false || !@openssl_pkey_export($keyObjc, $prvText, null, $cnfOpts) ){
			$this->stopShell('No keys made: OpenSSL refused: '.strval(openssl_error_string()), self::EXITFAIL);
		}
		$pubText = strval(openssl_pkey_get_details($keyObjc)['key'] ?? '');
		foreach( Array($prvPath=>Array($prvText, 0600), $pubPath=>Array($pubText, 0644)) as $keyFle => $keyDat ){
			if( !is_dir(dirname($keyFle)) ){
				@mkdir(dirname($keyFle), 0700, true);
			}
			// Written aside, given its rights, then put in place : no copy of the former key is kept
			$tempPth = $keyFle.'.tmp'.getmypid();
			if( @file_put_contents($tempPth, $keyDat[0], LOCK_EX)===false ){
				$this->stopShell('No keys written: '.dirname($keyFle).' cannot be written', self::EXITFAIL);
			}
			@chmod($tempPth, $keyDat[1]);
			if( !@rename($tempPth, $keyFle) ){
				@unlink($tempPth);
				$this->stopShell('No keys written: '.basename($keyFle).' cannot be replaced', self::EXITFAIL);
			}
		}
		if( !isset($blkNode->prvkey) || !isset($blkNode->pubkey) ){
			$blkNode->prvkey = $prvName;
			$blkNode->pubkey = $pubName;
			$wrtrObj->saveJsons($filePth, $treeObj);
		}
		Console::loadConfg();
		$this->traceDone( 'Keys of the tokens made ('.self::KEYSBITS.' bits): '.$prvName.' and '.$pubName );
		$this->prntObjc->printPair('written', $prvName.', '.$pubName);
		return true;
	}

	/**
	 * Writes the line of a user in the htpasswd file the block names
	 * ('dfpass' unless --block names another): its password is made and
	 * shown once, or typed with --typed, and kept as a bcrypt hash.
	 *
	 * @param String $userString Name of the user.
	 *
	 * @throws ShellStop When the name is not one of a htpasswd file, or replacing its line is not confirmed.
	 */
	protected function makeHtpwd( String $userString ): Void {
		if( !preg_match('/^[^:\s]+$/D', $userString) ){
			$this->stopShell('A user of a htpasswd file has no colon and no space in its name', self::EXITUSGE);
		}
		$blkConf = $this->accessCnf( strval($this->optnObjc->getOption('block', 'dfpass')) );
		$htpName = trim( strval($blkConf['htpasswd'] ?? 'conf/.htpasswd'), '/\\ ' );
		$filePth = $this->pathOfCnf($htpName);
		$lineLst = is_file($filePth) ? array_values(array_filter(preg_split('/\r?\n/', strval(file_get_contents($filePth))), 'strlen')) : Array();
		$lineIdx = null;
		foreach( $lineLst as $lstIdx => $lstLin ){
			if( strpos($lstLin, $userString.':')===0 ){
				$lineIdx = $lstIdx;
			}
		}
		if( $lineIdx!==null && !$this->askAgreed('Replace the password of user '.$userString.' in '.$htpName) ){
			$this->stopShell('Nothing was written: not confirmed', self::EXITDENY);
		}
		$isTyped = $this->optnObjc->hasOption('typed');
		$passTxt = $isTyped ? $this->askSecret('Password of '.$userString) : bin2hex(random_bytes(10));
		if( $passTxt=='' ){
			$this->stopShell('Nothing was written: no password given', self::EXITDENY);
		}
		$newLine = $userString.':'.password_hash($passTxt, PASSWORD_BCRYPT);
		if( $lineIdx===null ){
			$lineLst[] = $newLine;
		}else{
			$lineLst[$lineIdx] = $newLine;
		}
		$this->makeWritr()->writeFile( $filePth, implode("\n", $lineLst)."\n" );
		$this->traceDone( 'Password of the htpasswd user '.json_encode($userString).' written in '.$htpName );
		$this->prntObjc->printPair('written', $htpName.' '.$userString);
		if( !$isTyped ){
			$this->prntObjc->printOnce('Password of '.$userString, $passTxt);
		}
	}

	/**
	 * Lists the routes of a preset: those its route files declare, then
	 * the actions its 'auths' block names, each with who it is served to:
	 * open (anyone), logged (any logged user), or the least role asked.
	 *
	 * @return Int The exit code: EXITFLAW when the roles of the routes cannot be applied.
	 */
	public function callRoute(): Int {
		$prstNme = strval($this->optnObjc->shiftWord() ?? 'dfault');
		try{
			$loadObj = (new Preset($prstNme))->makeSetup();
		}catch( \RuntimeException $e ){
			$this->stopShell( $e->getMessage(), self::EXITDENY );
		}
		$rteConf = $loadObj->getRegKey('route');
		$rteConf = is_array($rteConf) ? $rteConf : Array();
		$axsConf = $loadObj->setAccess();
		$rqstObj = ActCaller::makeReqst('GET', '/')[0];
		$rowList = Array();
		foreach( (new Router($rteConf, $rqstObj))->allRoutes() as $rteVal ){
			$rowList[] = Array( '/'.$rteVal['rtePath'], implode(', ', $rteVal['methods']), $rteVal['rteKind'], $rteVal['rteGoal'], $this->axessText($axsConf, $rqstObj, $rteVal) );
		}
		// The actions the access block names, which the path alone reaches in autopath mode
		$roleMap = is_array($axsConf['rolert'] ?? null) ? $axsConf['rolert'] : Array();
		$actList = Array();
		foreach( array_merge(array_values((array)($axsConf['openrt'] ?? Array())), array_keys($roleMap)) as $rteKey ){
			$rteKey = strtolower( trim(strval($rteKey)) );
			if( $rteKey!='' && substr($rteKey, 0, 1)!='/' && !in_array($rteKey, $actList, true) ){
				$actList[] = $rteKey;
			}
		}
		foreach( $actList as $actKey ){
			$actPart = explode('/', $actKey, 2);
			$rowList[] = Array( '', 'any', 'action', $actKey, $this->axessText($axsConf, $rqstObj, Array('app'=>$actPart[0], 'action'=>$actPart[1] ?? '', 'rtePath'=>'', 'rteKind'=>'action')) );
		}
		$this->prntObjc->printPair('preset', $prstNme);
		$this->prntObjc->printPair('detect', strval($rteConf['detect'] ?? ''));
		$this->prntObjc->printPair('others', ( empty($axsConf) || ($axsConf['needed'] ?? true)===false ) ? 'open' : 'logged');
		$this->prntObjc->printRows( Array('path', 'methods', 'kind', 'target', 'access'), $rowList );
		$roleFlw = (new Checker($axsConf, $rqstObj, new Registry(Array())))->rolesFlaw();
		if( $roleFlw!='' ){
			$this->prntObjc->printFail('Every request of this preset is refused: '.$roleFlw);
			return self::EXITFLAW;
		}
		return self::EXITDONE;
	}

	/**
	 * Tells who a route is served to under an 'auths' block.
	 *
	 * @param Array   $accessConf The 'auths' block of the preset.
	 * @param Request $requestObj A request to build the access check with.
	 * @param Array   $routesVals The route: its 'app', 'action', 'rtePath' and 'rteKind'.
	 * @return String 'open', 'logged', or the least role asked for.
	 */
	protected function axessText( Array $accessConf, Request $requestObj, Array $routesVals ): String {
		if( empty($accessConf) || ($accessConf['needed'] ?? true)===false ){
			return 'open';
		}
		$chkObjc = new Checker( $accessConf, $requestObj, new Registry(Array('app'=>$routesVals['app'] ?? null, 'action'=>$routesVals['action'] ?? null, 'rtePath'=>$routesVals['rtePath'] ?? '', 'rteKind'=>$routesVals['rteKind'] ?? '')) );
		if( $chkObjc->openRoute() ){
			return 'open';
		}
		return $chkObjc->routeRole() ?? 'logged';
	}

	/**
	 * Reads a preset as it is written, once the framework accepts it.
	 *
	 * @param String $presetName Name of the preset.
	 * @return Array Its blocks and keys.
	 *
	 * @throws \RuntimeException When the preset is missing or cannot be loaded.
	 */
	protected function readPrset( String $presetName ): Array {
		new Preset($presetName);
		$rpsDocu = new Dcmnt( $this->pathOfCnf('conf/presets/'.$presetName.'.rps') );
		$rpsDocu->fileParse('rps', false);
		return (array)$rpsDocu->readValue();
	}

	/**
	 * Follows a path of names written with dots into the values of a
	 * config file.
	 *
	 * @param Array  $configVals The values of the file.
	 * @param String $pathsTexts The path; empty for the whole file.
	 * @return mixed What stands at the end of the path.
	 *
	 * @throws ShellStop When nothing stands there.
	 */
	protected function walkPaths( Array $configVals, String $pathsTexts ): Mixed {
		$nodeVal = $configVals;
		foreach( ($pathsTexts=='' ? Array() : explode('.', $pathsTexts)) as $pthKey ){
			if( !is_array($nodeVal) || !array_key_exists($pthKey, $nodeVal) ){
				$this->stopShell('Nothing under '.$pathsTexts, self::EXITDENY);
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
	 * @param mixed  $valueDatas The part of the file.
	 * @param String $prefixText The path it stands at.
	 * @return Array Rows of path and value.
	 */
	protected function plainRows( Mixed $valueDatas, String $prefixText ): Array {
		if( is_array($valueDatas) && !empty($valueDatas) && $valueDatas!==array_values($valueDatas) ){
			$rowList = Array();
			foreach( $valueDatas as $subKey => $subVal ){
				$rowList = array_merge( $rowList, $this->plainRows($subVal, ($prefixText=='') ? strval($subKey) : $prefixText.'.'.$subKey) );
			}
			return $rowList;
		}
		$pathPrt = explode('.', $prefixText);
		if( in_array(end($pathPrt), self::SECRLIST, true) ){
			$maskFnc = function( Mixed $secretText ): Mixed {
				return ( is_string($secretText) && $secretText!='' && strpos($secretText, 'env:')!==0 ) ? '********' : $secretText;
			};
			$valueDatas = is_array($valueDatas) ? array_map($maskFnc, $valueDatas) : $maskFnc($valueDatas);
		}
		return Array( Array($prefixText, $valueDatas) );
	}

	/**
	 * Reads a value typed on the command line: true, false, null, a
	 * number, a JSON list or block, or a text.
	 *
	 * @param String $givenTexts The value, as typed.
	 * @return mixed The value.
	 */
	protected function readTyped( String $givenTexts ): Mixed {
		if( in_array($givenTexts, Array('true', 'false', 'null'), true) || preg_match('/^-?\d{1,15}(\.\d+)?$/D', $givenTexts) ){
			return json_decode($givenTexts);
		}
		if( in_array(substr($givenTexts, 0, 1), Array('[', '{'), true) ){
			$jsnValu = json_decode($givenTexts);
			if( $jsnValu!==null ){
				return $jsnValu;
			}
		}
		return $givenTexts;
	}

	/**
	 * Gives the full path of a file of the project.
	 *
	 * @param String $insidePath Its path from the root of the project, written with slashes.
	 * @return String The full path.
	 */
	protected function pathOfCnf( String $insidePath ): String {
		return TOP_DIR.DS.str_replace('/', DS, $insidePath);
	}

	/**
	 * Gives a writer of config files keeping its copies in temp/backup.
	 *
	 * @return ConfFile The writer.
	 */
	protected function makeWritr(): ConfFile {
		return new ConfFile( TOP_DIR.DS.'temp'.DS.'backup' );
	}
}
