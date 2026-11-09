<?php

namespace nyu\Shell;

/**
 * Writes the config files of a project, each in its own way so that
 * what a person wrote in it stays: only the value changes in an .ini, an
 * .env or a preset (.rps), comments and alignment kept; a .json file,
 * which holds no comment, is written again in the layout it has.
 * Every write is whole or not at all (a temporary file, then renamed),
 * and the file replaced is first copied to the backup folder.
 */
class ConfFile{

	// Copies kept of each file in the backup folder : the older ones are removed, with the secrets they hold
	const KEEP_COPY = 5;

	protected $backFolder;
	// The copy kept of each file written, by its path; empty for a file that did not exist
	protected $backupList = Array();

	/**
	 * Builds the writer of config files, with the folder that keeps what it replaces.
	 *
	 * @param String $backupFolder Full path of the folder the replaced files are copied to.
	 */
	function __construct( String $backupFolder ){
		$this->backFolder = rtrim($backupFolder, '/\\');
	}

	/**
	 * Writes a file as a whole: a reader sees the former content or the
	 * new one, never a part of it. The file replaced is copied to the
	 * backup folder first, and its permissions are kept. The content is
	 * written readable by its owner only, and given its rights before it
	 * takes the place of the file: a secret is never readable by others,
	 * even for a moment. A file of the project's conf folder written
	 * removes the config cache (ConfCache): the next request reads the files.
	 *
	 * @param String $filePathName Full path of the file.
	 * @param String $freshContent Its new content.
	 * @param ?Int   $rightsModeNb Rights of a file that does not exist yet (0600 for a secret); those the umask gives when null.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function saveFileAtomic( String $filePathName, String $freshContent, ?Int $rightsModeNb=null ): Void {
		$wasFile = is_file($filePathName);
		$this->backupList[$filePathName] = $wasFile ? $this->makeBackupCopy($filePathName) : '';
		$tmpPath = $filePathName.'.tmp'.getmypid();
		$prevMsk = umask(0077);
		try{
			$isWrttn = @file_put_contents($tmpPath, $freshContent, LOCK_EX);
		}finally{
			umask($prevMsk);
		}
		if( $isWrttn===false ){
			throw new \RuntimeException('File not written: '.basename($filePathName).' cannot be written in its folder');
		}
		@chmod( $tmpPath, $wasFile ? (fileperms($filePathName) & 0777) : ($rightsModeNb ?? (0666 & ~$prevMsk)) );
		// On Windows a file being read cannot be replaced : tried again for a moment
		for( $idxInc=0; $idxInc<10; $idxInc++ ){
			if( @rename($tmpPath, $filePathName) ){
				clearstatcache(true, $filePathName);
				if( strpos(str_replace('/', DS, $filePathName), str_replace('/', DS, TOP_DIR.DS.'conf'.DS))===0 ){
					\nyu\Setup\ConfCache::clearMailCache();
				}
				return;
			}
			usleep(50000);
		}
		$isCopid = @copy($tmpPath, $filePathName);
		@unlink($tmpPath);
		if( !$isCopid ){
			throw new \RuntimeException('File not written: '.basename($filePathName).' cannot be replaced');
		}
		if( strpos(str_replace('/', DS, $filePathName), str_replace('/', DS, TOP_DIR.DS.'conf'.DS))===0 ){
			\nyu\Setup\ConfCache::clearMailCache();
		}
	}

	/**
	 * Copies a file to the backup folder, under its name followed by the
	 * time of the copy.
	 *
	 * @param String $filePathName Full path of the file.
	 * @return String Full path of the copy.
	 *
	 * @throws \RuntimeException When the copy cannot be made: nothing is written without one.
	 */
	protected function makeBackupCopy( String $filePathName ): String {
		if( !is_dir($this->backFolder) ){
			@mkdir($this->backFolder, 0700, true);
		}
		$cpyPath = $this->backFolder.DIRECTORY_SEPARATOR.ltrim(basename($filePathName), '.').'.'.date('Ymd-His');
		// Numbered after the last copy of the same second: a name freed by pruneBack is never taken again, so the newest copy sorts last
		$copyNum = 0;
		foreach( (array)scandir($this->backFolder) as $oneNme ){
			if( preg_match('/^'.preg_quote(basename($cpyPath), '/').'(?:-(\d+))?$/D', strval($oneNme), $nmePart) ){
				$copyNum = max($copyNum, intval($nmePart[1] ?? 1));
			}
		}
		$fullPth = ($copyNum==0) ? $cpyPath : $cpyPath.'-'.($copyNum + 1);
		while( file_exists($fullPth) ){
			$copyNum++;
			$fullPth = $cpyPath.'-'.($copyNum + 1);
		}
		// The copy holds what the file holds : readable by its owner only, from its first byte
		$prevMsk = umask(0077);
		try{
			$isCopid = @copy($filePathName, $fullPth);
		}finally{
			umask($prevMsk);
		}
		if( !$isCopid ){
			throw new \RuntimeException('File not written: '.basename($filePathName).' cannot be copied to the backup folder first');
		}
		@chmod($fullPth, 0600);
		$this->pruneOldBackup($filePathName);
		return $fullPth;
	}

	/**
	 * Removes the oldest copies of a file from the backup folder, keeping
	 * the KEEPCOPY newest: a secret rotated away does not stay there. The
	 * copies are found by their exact name, the file's followed by the time
	 * of the copy, so that those of another file sharing its first letters
	 * are left alone.
	 *
	 * @param String $filePathName Full path of the file.
	 */
	protected function pruneOldBackup( String $filePathName ): Void {
		$baseNme = ltrim(basename($filePathName), '.');
		$copyLst = Array();
		foreach( (array)scandir($this->backFolder) as $oneNme ){
			if( preg_match('/^'.preg_quote($baseNme, '/').'\.(\d{8}-\d{6})(?:-(\d+))?$/D', strval($oneNme), $nmePart) ){
				$copyLst[strval($oneNme)] = $nmePart[1].'-'.str_pad(strval($nmePart[2] ?? '1'), 6, '0', STR_PAD_LEFT);
			}
		}
		asort($copyLst);
		foreach( array_slice(array_keys($copyLst), 0, max(0, count($copyLst) - self::KEEP_COPY)) as $oldNme ){
			@unlink($this->backFolder.DIRECTORY_SEPARATOR.$oldNme);
		}
	}

	/**
	 * Puts a file back as it was before its last write by this object.
	 *
	 * @param String $filePathName Full path of the file.
	 * @return Bool Whether it was put back.
	 */
	public function revertWriteFlg( String $filePathName ): Bool {
		if( !isset($this->backupList[$filePathName]) ){
			return false;
		}
		$cpyPath = $this->backupList[$filePathName];
		unset($this->backupList[$filePathName]);
		return ($cpyPath=='') ? @unlink($filePathName) : @copy($cpyPath, $filePathName);
	}

	/**
	 * Reads the value of a key of an .ini file, as it is written.
	 *
	 * @param String $filePathName Full path of the file.
	 * @param String $iniEntryName Name of the key.
	 * @return ?String The value, without the quotes around it; null when the file has no such key.
	 */
	public function retrieveIniKey( String $filePathName, String $iniEntryName ): ?String {
		$valPart = Array();
		if( !preg_match('/^[ \t]*'.preg_quote($iniEntryName, '/').'[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePathName)), $valPart) ){
			return null;
		}
		return trim($valPart[1], '"');
	}

	/**
	 * Changes the value of a key of an .ini file, on its own line:
	 * comments, blank lines and the other keys stay as they are.
	 *
	 * @param String $filePathName Full path of the file.
	 * @param String $iniEntryName Name of the key.
	 * @param String $newIniValTxt Its new value.
	 * @return Bool False when the file has no such key: none is added.
	 *
	 * @throws \RuntimeException When the value cannot be written in an .ini file, or the file cannot be written.
	 */
	public function changeIniValue( String $filePathName, String $iniEntryName, String $newIniValTxt ): Bool {
		if( preg_match('/["\r\n]/', $newIniValTxt) ){
			throw new \RuntimeException('Value refused: no quote and no line break in an .ini value');
		}
		// A value of plain words is written as it is, any other between quotes
		$valText = preg_match('/^[\w.\/:\-]+( [\w.\/:\-]+)*$/D', $newIniValTxt) ? $newIniValTxt : '"'.$newIniValTxt.'"';
		$swapNum = 0;
		$newText = preg_replace_callback('/^([ \t]*'.preg_quote($iniEntryName, '/').'[ \t]*=[ \t]*)(.*?)([ \t]*\r?)$/m', function( Array $matchedArray ) use ( $valText ): String {
			return $matchedArray[1].$valText.$matchedArray[3];
		}, strval(@file_get_contents($filePathName)), 1, $swapNum);
		if( $swapNum<1 || !is_string($newText) ){
			return false;
		}
		$this->saveFileAtomic($filePathName, $newText);
		return true;
	}

	/**
	 * Reads a value of an environment file (NAME=value lines).
	 *
	 * @param String $filePathName Full path of the file.
	 * @param String $envValueName Name of the value.
	 * @return ?String The value, without the quotes around it; null when the file does not hold the name.
	 */
	public function getEnvrnmntKey( String $filePathName, String $envValueName ): ?String {
		$valPart = Array();
		if( !preg_match('/^[ \t]*'.preg_quote($envValueName, '/').'[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePathName)), $valPart) ){
			return null;
		}
		return trim($valPart[1], '"\'');
	}

	/**
	 * Writes a value of an environment file: on the line of its name when
	 * the file holds it, on a new line at its end otherwise. A file that
	 * does not exist is created, readable by its owner only.
	 *
	 * @param String $filePathName Full path of the file.
	 * @param String $envValueName Name of the value.
	 * @param String $envValueText The value.
	 *
	 * @throws \RuntimeException When the name or the value cannot be written in such a file, or the file cannot be written.
	 */
	public function setEnvrnmntKey( String $filePathName, String $envValueName, String $envValueText ): Void {
		if( !preg_match('/^\w+$/D', $envValueName) || preg_match('/[\r\n]/', $envValueText) ){
			throw new \RuntimeException('Value refused: a plain name, and no line break in the value');
		}
		$wasFile = is_file($filePathName);
		$fileTxt = $wasFile ? strval(file_get_contents($filePathName)) : '';
		$swapNum = 0;
		$newText = preg_replace_callback('/^([ \t]*'.preg_quote($envValueName, '/').'[ \t]*=)(.*?)(\r?)$/m', function( Array $matchedArray ) use ( $envValueText ): String {
			return $matchedArray[1].$envValueText.$matchedArray[3];
		}, $fileTxt, 1, $swapNum);
		if( $swapNum<1 ){
			$lineEnd = (strpos($fileTxt, "\r\n")!==false) ? "\r\n" : "\n";
			$newText = $fileTxt.( ($fileTxt!='' && substr($fileTxt, -1)!="\n") ? $lineEnd : '' ).$envValueName.'='.$envValueText.$lineEnd;
		}
		$this->saveFileAtomic($filePathName, strval($newText), 0600);
	}

	/**
	 * Reads a JSON config file as it is written: nothing of it is
	 * resolved, a value written env:NAME stays so.
	 *
	 * @param String $filePathName Full path of the file.
	 * @return Object Its values, each block an object.
	 *
	 * @throws \RuntimeException When the file is missing or is not a JSON object.
	 */
	public function importJsonsObj( String $filePathName ): Object {
		$jsonVls = is_file($filePathName) ? json_decode( strval(file_get_contents($filePathName)) ) : null;
		if( !is_object($jsonVls) ){
			throw new \RuntimeException('Config not read: '.basename($filePathName).' is missing or is not a JSON object');
		}
		return $jsonVls;
	}

	/**
	 * Writes a JSON config file in the layout it has: one block per line
	 * (access.json), or one value per line (database.json). A new file is
	 * written one value per line.
	 *
	 * @param String $filePathName Full path of the file.
	 * @param Object $jsonDocument Its values.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function saveJsonConfig( String $filePathName, Object $jsonDocument ): Void {
		$fileTxt = is_file($filePathName) ? strval(file_get_contents($filePathName)) : '';
		$lineEnd = (strpos($fileTxt, "\r\n")!==false) ? "\r\n" : "\n";
		$jsonFlg = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if( preg_match('/^\s*"[^"]+":\s*\{[^\r\n]*\}\s*,?\s*$/m', $fileTxt) ){
			// One block per line, the blocks aligned on the longest name
			$nmeSize = 0;
			foreach( array_keys(get_object_vars($jsonDocument)) as $blkKey ){
				$nmeSize = max( $nmeSize, strlen(strval(json_encode(strval($blkKey), $jsonFlg))) );
			}
			$lineLst = Array();
			foreach( get_object_vars($jsonDocument) as $blkKey => $blkVal ){
				$lineLst[] = "\t".str_pad(json_encode(strval($blkKey), $jsonFlg).':', $nmeSize+1).' '.$this->inlineJsonText($blkVal);
			}
			$newText = '{'."\n".implode(','."\n", $lineLst)."\n".'}'."\n";
		}else{
			$newText = strval( preg_replace_callback('/^(?: {4})+/m', function( Array $matchedArray ): String {
				return str_repeat("\t", intdiv(strlen($matchedArray[0]), 4));
			}, strval(json_encode($jsonDocument, JSON_PRETTY_PRINT | $jsonFlg))) )."\n";
		}
		$this->saveFileAtomic( $filePathName, ($lineEnd=="\n") ? $newText : str_replace("\n", $lineEnd, $newText) );
	}

	/**
	 * Writes a value as JSON on one line, with a space after each colon
	 * and comma, and inside the braces of a block.
	 *
	 * @param mixed $filePartData The value.
	 * @return String Its JSON text.
	 */
	protected function inlineJsonText( Mixed $filePartData ): String {
		if( is_object($filePartData) ){
			$partLst = Array();
			foreach( get_object_vars($filePartData) as $subKey => $subVal ){
				$partLst[] = json_encode(strval($subKey), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).': '.$this->inlineJsonText($subVal);
			}
			return empty($partLst) ? '{}' : '{ '.implode(', ', $partLst).' }';
		}
		if( is_array($filePartData) ){
			return '['.implode(', ', array_map(Array($this, 'inlineJsonText'), $filePartData)).']';
		}
		return strval( json_encode($filePartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) );
	}

	/**
	 * Changes a plain value of a preset (.rps), in its place: the comments
	 * and the alignment of the file stay as they are. A value that is a
	 * list or a block is not written.
	 *
	 * @param String $filePathName Full path of the preset file.
	 * @param String $blckNamedTxt Name of the block holding the key; empty for a key of the preset itself (label, about).
	 * @param String $presetKeyRef Name of the key.
	 * @param mixed  $newPresetVal Its new value: a text, a number, true, false or null.
	 *
	 * @throws \RuntimeException When the preset has no such key, when its value is a list or a block, or when the file cannot be written.
	 */
	public function setPresetValue( String $filePathName, String $blckNamedTxt, String $presetKeyRef, Mixed $newPresetVal ): Void {
		if( !is_scalar($newPresetVal) && $newPresetVal!==null ){
			throw new \RuntimeException('Value refused: a list or a block of a preset is shown, not written');
		}
		$fileTxt = strval(@file_get_contents($filePathName));
		$txtSize = strlen($fileTxt);
		$depthNb = 0;
		// The key that opened each depth : $keyStck[1] is the block being read
		$keyStck = Array();
		$lastKey = '';
		$needDep = ($blckNamedTxt=='') ? 1 : 2;
		for( $idxInc=0; $idxInc<$txtSize; $idxInc++ ){
			$oneChar = $fileTxt[$idxInc];
			// A line starting with # or // is a comment
			if( ($oneChar=='#' || ($oneChar=='/' && ($fileTxt[$idxInc+1] ?? '')=='/')) && trim(substr($fileTxt, intval(strrpos(substr($fileTxt, 0, $idxInc), "\n")), $idxInc-intval(strrpos(substr($fileTxt, 0, $idxInc), "\n"))))=='' ){
				$lineEnd = strpos($fileTxt, "\n", $idxInc);
				$idxInc = ($lineEnd===false) ? $txtSize : $lineEnd;
				continue;
			}
			if( $oneChar=='{' || $oneChar=='[' ){
				$depthNb++;
				$keyStck[$depthNb-1] = $lastKey;
				$lastKey = '';
				continue;
			}
			if( $oneChar=='}' || $oneChar==']' ){
				unset($keyStck[$depthNb-1]);
				$depthNb--;
				continue;
			}
			if( $oneChar!='"' ){
				continue;
			}
			$strEnds = $this->terminateOfTxt($fileTxt, $idxInc);
			$strText = json_decode( substr($fileTxt, $idxInc, $strEnds-$idxInc) );
			$nxtPstn = $strEnds + strspn($fileTxt, " \t\r\n", $strEnds);
			$idxInc = $strEnds-1;
			if( ($fileTxt[$nxtPstn] ?? '')!=':' ){
				continue;
			}
			$lastKey = strval($strText);
			if( $depthNb!=$needDep || $lastKey!==$presetKeyRef || ($needDep==2 && ($keyStck[1] ?? '')!==$blckNamedTxt) ){
				continue;
			}
			// The key is found : its value is replaced where it stands
			$valFrom = $nxtPstn+1 + strspn($fileTxt, " \t\r\n", $nxtPstn+1);
			$valChar = $fileTxt[$valFrom] ?? '';
			if( $valChar=='{' || $valChar=='[' ){
				throw new \RuntimeException('Value refused: '.$presetKeyRef.' is a list or a block of the preset, shown and not written');
			}
			$valEnds = ($valChar=='"') ? $this->terminateOfTxt($fileTxt, $valFrom) : $valFrom + strcspn($fileTxt, ",}] \t\r\n", $valFrom);
			$this->saveFileAtomic( $filePathName, substr($fileTxt, 0, $valFrom).json_encode($newPresetVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).substr($fileTxt, $valEnds) );
			return;
		}
		throw new \RuntimeException('Value refused: the preset has no key '.( $blckNamedTxt!='' ? $blckNamedTxt.'.' : '' ).$presetKeyRef.', and none is added');
	}

	/**
	 * Finds the end of a JSON text between quotes.
	 *
	 * @param String $fullTextsTxt The text it stands in.
	 * @param Int    $startsFromNb Position of its opening quote.
	 * @return Int Position after its closing quote.
	 */
	protected function terminateOfTxt( String $fullTextsTxt, Int $startsFromNb ): Int {
		$txtSize = strlen($fullTextsTxt);
		$redPstn = $startsFromNb+1;
		while( $redPstn<$txtSize && $fullTextsTxt[$redPstn]!='"' ){
			$redPstn += ($fullTextsTxt[$redPstn]=='\\') ? 2 : 1;
		}
		return min($txtSize, $redPstn+1);
	}
}
