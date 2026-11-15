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
	 * @param ?Int   $rghtModeNumb Rights of a file that does not exist yet (0600 for a secret); those the umask gives when null.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function saveFileAtomic( String $filePathName, String $freshContent, ?Int $rghtModeNumb=null ): Void {
		$wasFichr = is_file($filePathName);
		$this->backupList[$filePathName] = $wasFichr ? $this->makeBackupCopy($filePathName) : '';
		$tempPath = $filePathName.'.tmp'.getmypid();
		$prevMask = umask(0077);
		try{
			$wasWrttn = @file_put_contents($tempPath, $freshContent, LOCK_EX);
		}finally{
			umask($prevMask);
		}
		if( $wasWrttn===false ){
			throw new \RuntimeException('File not written: '.basename($filePathName).' cannot be written in its folder');
		}
		@chmod( $tempPath, $wasFichr ? (fileperms($filePathName) & 0777) : ($rghtModeNumb ?? (0666 & ~$prevMask)) );
		// On Windows a file being read cannot be replaced : tried again for a moment
		for( $idxInc=0; $idxInc<10; $idxInc++ ){
			if( @rename($tempPath, $filePathName) ){
				clearstatcache(true, $filePathName);
				if( strpos(str_replace('/', DS, $filePathName), str_replace('/', DS, TOP_DIR.DS.'conf'.DS))===0 ){
					\nyu\Setup\ConfCache::clearConfCache();
				}
				return;
			}
			usleep(50000);
		}
		$isCopied = @copy($tempPath, $filePathName);
		@unlink($tempPath);
		if( !$isCopied ){
			throw new \RuntimeException('File not written: '.basename($filePathName).' cannot be replaced');
		}
		if( strpos(str_replace('/', DS, $filePathName), str_replace('/', DS, TOP_DIR.DS.'conf'.DS))===0 ){
			\nyu\Setup\ConfCache::clearConfCache();
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
		$copyPath = $this->backFolder.DIRECTORY_SEPARATOR.ltrim(basename($filePathName), '.').'.'.date('Ymd-His');
		// Numbered after the last copy of the same second: a name freed by pruneBack is never taken again, so the newest copy sorts last
		$copyNumb = 0;
		foreach( (array)scandir($this->backFolder) as $oneNme ){
			if( preg_match('/^'.preg_quote(basename($copyPath), '/').'(?:-(\d+))?$/D', strval($oneNme), $namePart) ){
				$copyNumb = max($copyNumb, intval($namePart[1] ?? 1));
			}
		}
		$fullPath = ($copyNumb==0) ? $copyPath : $copyPath.'-'.($copyNumb + 1);
		while( file_exists($fullPath) ){
			$copyNumb++;
			$fullPath = $copyPath.'-'.($copyNumb + 1);
		}
		// The copy holds what the file holds : readable by its owner only, from its first byte
		$prevMask = umask(0077);
		try{
			$isCopied = @copy($filePathName, $fullPath);
		}finally{
			umask($prevMask);
		}
		if( !$isCopied ){
			throw new \RuntimeException('File not written: '.basename($filePathName).' cannot be copied to the backup folder first');
		}
		@chmod($fullPath, 0600);
		$this->pruneOldBackup($filePathName);
		return $fullPath;
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
		$baseName = ltrim(basename($filePathName), '.');
		$copyList = Array();
		foreach( (array)scandir($this->backFolder) as $oneNme ){
			if( preg_match('/^'.preg_quote($baseName, '/').'\.(\d{8}-\d{6})(?:-(\d+))?$/D', strval($oneNme), $namePart) ){
				$copyList[strval($oneNme)] = $namePart[1].'-'.str_pad(strval($namePart[2] ?? '1'), 6, '0', STR_PAD_LEFT);
			}
		}
		asort($copyList);
		foreach( array_slice(array_keys($copyList), 0, max(0, count($copyList) - self::KEEP_COPY)) as $oldNme ){
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
		$copyPath = $this->backupList[$filePathName];
		unset($this->backupList[$filePathName]);
		return ($copyPath=='') ? @unlink($filePathName) : @copy($copyPath, $filePathName);
	}

	/**
	 * Reads the value of a key of an .ini file, as it is written.
	 *
	 * @param String $filePathName Full path of the file.
	 * @param String $iniEntryName Name of the key.
	 * @return ?String The value, without the quotes around it; null when the file has no such key.
	 */
	public function retrieveIniKey( String $filePathName, String $iniEntryName ): ?String {
		$valuePar = Array();
		if( !preg_match('/^[ \t]*'.preg_quote($iniEntryName, '/').'[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePathName)), $valuePar) ){
			return null;
		}
		return trim($valuePar[1], '"');
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
		$valueTxt = preg_match('/^[\w.\/:\-]+( [\w.\/:\-]+)*$/D', $newIniValTxt) ? $newIniValTxt : '"'.$newIniValTxt.'"';
		$swapNumb = 0;
		$newStrng = preg_replace_callback('/^([ \t]*'.preg_quote($iniEntryName, '/').'[ \t]*=[ \t]*)(.*?)([ \t]*\r?)$/m', function( Array $matchedArray ) use ( $valueTxt ): String {
			return $matchedArray[1].$valueTxt.$matchedArray[3];
		}, strval(@file_get_contents($filePathName)), 1, $swapNumb);
		if( $swapNumb<1 || !is_string($newStrng) ){
			return false;
		}
		$this->saveFileAtomic($filePathName, $newStrng);
		return true;
	}

	/**
	 * Reads a value of an environment file (NAME=value lines).
	 *
	 * @param String $filePathName Full path of the file.
	 * @param String $envValueName Name of the value.
	 * @return ?String The value, without the quotes around it; null when the file does not hold the name.
	 */
	public function retrieveEnvKey( String $filePathName, String $envValueName ): ?String {
		$valuePar = Array();
		if( !preg_match('/^[ \t]*'.preg_quote($envValueName, '/').'[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePathName)), $valuePar) ){
			return null;
		}
		return trim($valuePar[1], '"\'');
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
	public function changeEnvValue( String $filePathName, String $envValueName, String $envValueText ): Void {
		if( !preg_match('/^\w+$/D', $envValueName) || preg_match('/[\r\n]/', $envValueText) ){
			throw new \RuntimeException('Value refused: a plain name, and no line break in the value');
		}
		$wasFichr = is_file($filePathName);
		$fileText = $wasFichr ? strval(file_get_contents($filePathName)) : '';
		$swapNumb = 0;
		$newStrng = preg_replace_callback('/^([ \t]*'.preg_quote($envValueName, '/').'[ \t]*=)(.*?)(\r?)$/m', function( Array $matchedArray ) use ( $envValueText ): String {
			return $matchedArray[1].$envValueText.$matchedArray[3];
		}, $fileText, 1, $swapNumb);
		if( $swapNumb<1 ){
			$ligneEnd = (strpos($fileText, "\r\n")!==false) ? "\r\n" : "\n";
			$newStrng = $fileText.( ($fileText!='' && substr($fileText, -1)!="\n") ? $ligneEnd : '' ).$envValueName.'='.$envValueText.$ligneEnd;
		}
		$this->saveFileAtomic($filePathName, strval($newStrng), 0600);
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
		$jsonVals = is_file($filePathName) ? json_decode( strval(file_get_contents($filePathName)) ) : null;
		if( !is_object($jsonVals) ){
			throw new \RuntimeException('Config not read: '.basename($filePathName).' is missing or is not a JSON object');
		}
		return $jsonVals;
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
		$fileText = is_file($filePathName) ? strval(file_get_contents($filePathName)) : '';
		$ligneEnd = (strpos($fileText, "\r\n")!==false) ? "\r\n" : "\n";
		$jsonFlag = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if( preg_match('/^\s*"[^"]+":\s*\{[^\r\n]*\}\s*,?\s*$/m', $fileText) ){
			// One block per line, the blocks aligned on the longest name
			$nameSize = 0;
			foreach( array_keys(get_object_vars($jsonDocument)) as $blkKey ){
				$nameSize = max( $nameSize, strlen(strval(json_encode(strval($blkKey), $jsonFlag))) );
			}
			$lineList = Array();
			foreach( get_object_vars($jsonDocument) as $blkKey => $blkVal ){
				$lineList[] = "\t".str_pad(json_encode(strval($blkKey), $jsonFlag).':', $nameSize+1).' '.$this->inlineJsonText($blkVal);
			}
			$newStrng = '{'."\n".implode(','."\n", $lineList)."\n".'}'."\n";
		}else{
			$newStrng = strval( preg_replace_callback('/^(?: {4})+/m', function( Array $matchedArray ): String {
				return str_repeat("\t", intdiv(strlen($matchedArray[0]), 4));
			}, strval(json_encode($jsonDocument, JSON_PRETTY_PRINT | $jsonFlag))) )."\n";
		}
		$this->saveFileAtomic( $filePathName, ($ligneEnd=="\n") ? $newStrng : str_replace("\n", $ligneEnd, $newStrng) );
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
			$partList = Array();
			foreach( get_object_vars($filePartData) as $subKey => $subVal ){
				$partList[] = json_encode(strval($subKey), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).': '.$this->inlineJsonText($subVal);
			}
			return empty($partList) ? '{}' : '{ '.implode(', ', $partList).' }';
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
	 * @param String $blockNmdText Name of the block holding the key; empty for a key of the preset itself (label, about).
	 * @param String $presetKeyRef Name of the key.
	 * @param mixed  $newPresetVal Its new value: a text, a number, true, false or null.
	 *
	 * @throws \RuntimeException When the preset has no such key, when its value is a list or a block, or when the file cannot be written.
	 */
	public function setPresetValue( String $filePathName, String $blockNmdText, String $presetKeyRef, Mixed $newPresetVal ): Void {
		if( !is_scalar($newPresetVal) && $newPresetVal!==null ){
			throw new \RuntimeException('Value refused: a list or a block of a preset is shown, not written');
		}
		$fileText = strval(@file_get_contents($filePathName));
		$textSize = strlen($fileText);
		$depthCnt = 0;
		// The key that opened each depth : $keyStack[1] is the block being read
		$keyStack = Array();
		$lastClef = '';
		$needDpth = ($blockNmdText=='') ? 1 : 2;
		for( $idxInc=0; $idxInc<$textSize; $idxInc++ ){
			$oneChrct = $fileText[$idxInc];
			// A line starting with # or // is a comment
			if( ($oneChrct=='#' || ($oneChrct=='/' && ($fileText[$idxInc+1] ?? '')=='/')) && trim(substr($fileText, intval(strrpos(substr($fileText, 0, $idxInc), "\n")), $idxInc-intval(strrpos(substr($fileText, 0, $idxInc), "\n"))))=='' ){
				$ligneEnd = strpos($fileText, "\n", $idxInc);
				$idxInc = ($ligneEnd===false) ? $textSize : $ligneEnd;
				continue;
			}
			if( $oneChrct=='{' || $oneChrct=='[' ){
				$depthCnt++;
				$keyStack[$depthCnt-1] = $lastClef;
				$lastClef = '';
				continue;
			}
			if( $oneChrct=='}' || $oneChrct==']' ){
				unset($keyStack[$depthCnt-1]);
				$depthCnt--;
				continue;
			}
			if( $oneChrct!='"' ){
				continue;
			}
			$strnEnds = $this->terminateOfTxt($fileText, $idxInc);
			$strnText = json_decode( substr($fileText, $idxInc, $strnEnds-$idxInc) );
			$tokenPos = $strnEnds + strspn($fileText, " \t\r\n", $strnEnds);
			$idxInc = $strnEnds-1;
			if( ($fileText[$tokenPos] ?? '')!=':' ){
				continue;
			}
			$lastClef = strval($strnText);
			if( $depthCnt!=$needDpth || $lastClef!==$presetKeyRef || ($needDpth==2 && ($keyStack[1] ?? '')!==$blockNmdText) ){
				continue;
			}
			// The key is found : its value is replaced where it stands
			$valuFrom = $tokenPos+1 + strspn($fileText, " \t\r\n", $tokenPos+1);
			$valueChr = $fileText[$valuFrom] ?? '';
			if( $valueChr=='{' || $valueChr=='[' ){
				throw new \RuntimeException('Value refused: '.$presetKeyRef.' is a list or a block of the preset, shown and not written');
			}
			$valueEnd = ($valueChr=='"') ? $this->terminateOfTxt($fileText, $valuFrom) : $valuFrom + strcspn($fileText, ",}] \t\r\n", $valuFrom);
			$this->saveFileAtomic( $filePathName, substr($fileText, 0, $valuFrom).json_encode($newPresetVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).substr($fileText, $valueEnd) );
			return;
		}
		throw new \RuntimeException('Value refused: the preset has no key '.( $blockNmdText!='' ? $blockNmdText.'.' : '' ).$presetKeyRef.', and none is added');
	}

	/**
	 * Finds the end of a JSON text between quotes.
	 *
	 * @param String $fullTextsTxt The text it stands in.
	 * @param Int    $fromPosition Position of its opening quote.
	 * @return Int Position after its closing quote.
	 */
	protected function terminateOfTxt( String $fullTextsTxt, Int $fromPosition ): Int {
		$textSize = strlen($fullTextsTxt);
		$crsorPos = $fromPosition+1;
		while( $crsorPos<$textSize && $fullTextsTxt[$crsorPos]!='"' ){
			$crsorPos += ($fullTextsTxt[$crsorPos]=='\\') ? 2 : 1;
		}
		return min($textSize, $crsorPos+1);
	}
}
