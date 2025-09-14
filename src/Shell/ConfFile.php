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

	protected $backFldr;
	// The copy kept of each file written, by its path; empty for a file that did not exist
	protected $copyList = Array();

	/**
	 * @param String $backupFldr Full path of the folder the replaced files are copied to.
	 */
	function __construct( String $backupFldr ){
		$this->backFldr = rtrim($backupFldr, '/\\');
	}

	/**
	 * Writes a file as a whole: a reader sees the former content or the
	 * new one, never a part of it. The file replaced is copied to the
	 * backup folder first, and its permissions are kept.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @param String $newContent Its new content.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function writeFile( String $filePathNm, String $newContent ): Void {
		$wasFile = is_file($filePathNm);
		$this->copyList[$filePathNm] = $wasFile ? $this->keepsCopy($filePathNm) : '';
		$tempPth = $filePathNm.'.tmp'.getmypid();
		if( @file_put_contents($tempPth, $newContent, LOCK_EX)===false ){
			throw new \RuntimeException('File not written: '.basename($filePathNm).' cannot be written in its folder');
		}
		if( $wasFile ){
			@chmod($tempPth, fileperms($filePathNm) & 0777);
		}
		// On Windows a file being read cannot be replaced : tried again for a moment
		for( $idx=0; $idx<10; $idx++ ){
			if( @rename($tempPth, $filePathNm) ){
				clearstatcache(true, $filePathNm);
				return;
			}
			usleep(50000);
		}
		$isCopyd = @copy($tempPth, $filePathNm);
		@unlink($tempPth);
		if( !$isCopyd ){
			throw new \RuntimeException('File not written: '.basename($filePathNm).' cannot be replaced');
		}
	}

	/**
	 * Copies a file to the backup folder, under its name followed by the
	 * time of the copy.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @return String Full path of the copy.
	 *
	 * @throws \RuntimeException When the copy cannot be made: nothing is written without one.
	 */
	protected function keepsCopy( String $filePathNm ): String {
		if( !is_dir($this->backFldr) ){
			@mkdir($this->backFldr, 0700, true);
		}
		$copyPth = $this->backFldr.DIRECTORY_SEPARATOR.ltrim(basename($filePathNm), '.').'.'.date('Ymd-His');
		$copyNum = 1;
		$fullPth = $copyPth;
		while( file_exists($fullPth) ){
			$copyNum++;
			$fullPth = $copyPth.'-'.$copyNum;
		}
		if( !@copy($filePathNm, $fullPth) ){
			throw new \RuntimeException('File not written: '.basename($filePathNm).' cannot be copied to the backup folder first');
		}
		return $fullPth;
	}

	/**
	 * Puts a file back as it was before its last write by this object.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @return Bool Whether it was put back.
	 */
	public function undoWrite( String $filePathNm ): Bool {
		if( !isset($this->copyList[$filePathNm]) ){
			return false;
		}
		$copyPth = $this->copyList[$filePathNm];
		unset($this->copyList[$filePathNm]);
		return ($copyPth=='') ? @unlink($filePathNm) : @copy($copyPth, $filePathNm);
	}

	/**
	 * Reads the value of a key of an .ini file, as it is written.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @param String $iniKeyName Name of the key.
	 * @return ?String The value, without the quotes around it; null when the file has no such key.
	 */
	public function getIniKey( String $filePathNm, String $iniKeyName ): ?String {
		$valPart = Array();
		if( !preg_match('/^[ \t]*'.preg_quote($iniKeyName, '/').'[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePathNm)), $valPart) ){
			return null;
		}
		return trim($valPart[1], '"');
	}

	/**
	 * Changes the value of a key of an .ini file, on its own line:
	 * comments, blank lines and the other keys stay as they are.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @param String $iniKeyName Name of the key.
	 * @param String $newIniValu Its new value.
	 * @return Bool False when the file has no such key: none is added.
	 *
	 * @throws \RuntimeException When the value cannot be written in an .ini file, or the file cannot be written.
	 */
	public function setIniKey( String $filePathNm, String $iniKeyName, String $newIniValu ): Bool {
		if( preg_match('/["\r\n]/', $newIniValu) ){
			throw new \RuntimeException('Value refused: no quote and no line break in an .ini value');
		}
		// A value of plain words is written as it is, any other between quotes
		$wrtnVal = preg_match('/^[\w.\/:\-]+( [\w.\/:\-]+)*$/D', $newIniValu) ? $newIniValu : '"'.$newIniValu.'"';
		$swapNum = 0;
		$newText = preg_replace_callback('/^([ \t]*'.preg_quote($iniKeyName, '/').'[ \t]*=[ \t]*)(.*?)([ \t]*\r?)$/m', function( Array $matchedArr ) use ( $wrtnVal ): String {
			return $matchedArr[1].$wrtnVal.$matchedArr[3];
		}, strval(@file_get_contents($filePathNm)), 1, $swapNum);
		if( $swapNum<1 || !is_string($newText) ){
			return false;
		}
		$this->writeFile($filePathNm, $newText);
		return true;
	}

	/**
	 * Reads a value of an environment file (NAME=value lines).
	 *
	 * @param String $filePathNm Full path of the file.
	 * @param String $envKeyName Name of the value.
	 * @return ?String The value, without the quotes around it; null when the file does not hold the name.
	 */
	public function getEnvKey( String $filePathNm, String $envKeyName ): ?String {
		$valPart = Array();
		if( !preg_match('/^[ \t]*'.preg_quote($envKeyName, '/').'[ \t]*=[ \t]*(.*?)[ \t]*\r?$/m', strval(@file_get_contents($filePathNm)), $valPart) ){
			return null;
		}
		return trim($valPart[1], '"\'');
	}

	/**
	 * Writes a value of an environment file: on the line of its name when
	 * the file holds it, on a new line at its end otherwise. A file that
	 * does not exist is created, readable by its owner only.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @param String $envKeyName Name of the value.
	 * @param String $newEnvValu The value.
	 *
	 * @throws \RuntimeException When the name or the value cannot be written in such a file, or the file cannot be written.
	 */
	public function setEnvKey( String $filePathNm, String $envKeyName, String $newEnvValu ): Void {
		if( !preg_match('/^\w+$/D', $envKeyName) || preg_match('/[\r\n]/', $newEnvValu) ){
			throw new \RuntimeException('Value refused: a plain name, and no line break in the value');
		}
		$wasFile = is_file($filePathNm);
		$fileTxt = $wasFile ? strval(file_get_contents($filePathNm)) : '';
		$swapNum = 0;
		$newText = preg_replace_callback('/^([ \t]*'.preg_quote($envKeyName, '/').'[ \t]*=)(.*?)(\r?)$/m', function( Array $matchedArr ) use ( $newEnvValu ): String {
			return $matchedArr[1].$newEnvValu.$matchedArr[3];
		}, $fileTxt, 1, $swapNum);
		if( $swapNum<1 ){
			$lineEnd = (strpos($fileTxt, "\r\n")!==false) ? "\r\n" : "\n";
			$newText = $fileTxt.( ($fileTxt!='' && substr($fileTxt, -1)!="\n") ? $lineEnd : '' ).$envKeyName.'='.$newEnvValu.$lineEnd;
		}
		$this->writeFile($filePathNm, strval($newText));
		if( !$wasFile ){
			@chmod($filePathNm, 0600);
		}
	}

	/**
	 * Reads a JSON config file as it is written: nothing of it is
	 * resolved, a value written env:NAME stays so.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @return Object Its values, each block an object.
	 *
	 * @throws \RuntimeException When the file is missing or is not a JSON object.
	 */
	public function loadJsons( String $filePathNm ): Object {
		$jsnVals = is_file($filePathNm) ? json_decode( strval(file_get_contents($filePathNm)) ) : null;
		if( !is_object($jsnVals) ){
			throw new \RuntimeException('Config not read: '.basename($filePathNm).' is missing or is not a JSON object');
		}
		return $jsnVals;
	}

	/**
	 * Writes a JSON config file in the layout it has: one block per line
	 * (access.json), or one value per line (database.json). A new file is
	 * written one value per line.
	 *
	 * @param String $filePathNm Full path of the file.
	 * @param Object $jsonValues Its values.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function saveJsons( String $filePathNm, Object $jsonValues ): Void {
		$fileTxt = is_file($filePathNm) ? strval(file_get_contents($filePathNm)) : '';
		$lineEnd = (strpos($fileTxt, "\r\n")!==false) ? "\r\n" : "\n";
		$jsnFlag = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if( preg_match('/^\s*"[^"]+":\s*\{[^\r\n]*\}\s*,?\s*$/m', $fileTxt) ){
			// One block per line, the blocks aligned on the longest name
			$nameLen = 0;
			foreach( array_keys(get_object_vars($jsonValues)) as $blkKey ){
				$nameLen = max( $nameLen, strlen(strval(json_encode(strval($blkKey), $jsnFlag))) );
			}
			$lineLst = Array();
			foreach( get_object_vars($jsonValues) as $blkKey => $blkVal ){
				$lineLst[] = "\t".str_pad(json_encode(strval($blkKey), $jsnFlag).':', $nameLen+1).' '.$this->inlineJsn($blkVal);
			}
			$newText = '{'."\n".implode(','."\n", $lineLst)."\n".'}'."\n";
		}else{
			$newText = strval( preg_replace_callback('/^(?: {4})+/m', function( Array $matchedArr ): String {
				return str_repeat("\t", intdiv(strlen($matchedArr[0]), 4));
			}, strval(json_encode($jsonValues, JSON_PRETTY_PRINT | $jsnFlag))) )."\n";
		}
		$this->writeFile( $filePathNm, ($lineEnd=="\n") ? $newText : str_replace("\n", $lineEnd, $newText) );
	}

	/**
	 * Writes a value as JSON on one line, with a space after each colon
	 * and comma, and inside the braces of a block.
	 *
	 * @param mixed $valueDatas The value.
	 * @return String Its JSON text.
	 */
	protected function inlineJsn( Mixed $valueDatas ): String {
		if( is_object($valueDatas) ){
			$partLst = Array();
			foreach( get_object_vars($valueDatas) as $subKey => $subVal ){
				$partLst[] = json_encode(strval($subKey), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).': '.$this->inlineJsn($subVal);
			}
			return empty($partLst) ? '{}' : '{ '.implode(', ', $partLst).' }';
		}
		if( is_array($valueDatas) ){
			return '['.implode(', ', array_map(Array($this, 'inlineJsn'), $valueDatas)).']';
		}
		return strval( json_encode($valueDatas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) );
	}

	/**
	 * Changes a plain value of a preset (.rps), in its place: the comments
	 * and the alignment of the file stay as they are. A value that is a
	 * list or a block is not written.
	 *
	 * @param String $filePathNm Full path of the preset file.
	 * @param String $blockNamed Name of the block holding the key; empty for a key of the preset itself (label, about).
	 * @param String $rpsKeyName Name of the key.
	 * @param mixed  $newRpsValu Its new value: a text, a number, true, false or null.
	 *
	 * @throws \RuntimeException When the preset has no such key, when its value is a list or a block, or when the file cannot be written.
	 */
	public function setRpsKey( String $filePathNm, String $blockNamed, String $rpsKeyName, Mixed $newRpsValu ): Void {
		if( !is_scalar($newRpsValu) && $newRpsValu!==null ){
			throw new \RuntimeException('Value refused: a list or a block of a preset is shown, not written');
		}
		$fileTxt = strval(@file_get_contents($filePathNm));
		$txtSize = strlen($fileTxt);
		$depthNb = 0;
		// The key that opened each depth : $keyStck[1] is the block being read
		$keyStck = Array();
		$lastKey = '';
		$needDep = ($blockNamed=='') ? 1 : 2;
		for( $idx=0; $idx<$txtSize; $idx++ ){
			$oneChar = $fileTxt[$idx];
			// A line starting with # or // is a comment
			if( ($oneChar=='#' || ($oneChar=='/' && ($fileTxt[$idx+1] ?? '')=='/')) && trim(substr($fileTxt, intval(strrpos(substr($fileTxt, 0, $idx), "\n")), $idx-intval(strrpos(substr($fileTxt, 0, $idx), "\n"))))=='' ){
				$lineEnd = strpos($fileTxt, "\n", $idx);
				$idx = ($lineEnd===false) ? $txtSize : $lineEnd;
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
			$strEnds = $this->endOfText($fileTxt, $idx);
			$strText = json_decode( substr($fileTxt, $idx, $strEnds-$idx) );
			$nextPos = $strEnds + strspn($fileTxt, " \t\r\n", $strEnds);
			$idx = $strEnds-1;
			if( ($fileTxt[$nextPos] ?? '')!=':' ){
				continue;
			}
			$lastKey = strval($strText);
			if( $depthNb!=$needDep || $lastKey!==$rpsKeyName || ($needDep==2 && ($keyStck[1] ?? '')!==$blockNamed) ){
				continue;
			}
			// The key is found : its value is replaced where it stands
			$valFrom = $nextPos+1 + strspn($fileTxt, " \t\r\n", $nextPos+1);
			$valChar = $fileTxt[$valFrom] ?? '';
			if( $valChar=='{' || $valChar=='[' ){
				throw new \RuntimeException('Value refused: '.$rpsKeyName.' is a list or a block of the preset, shown and not written');
			}
			$valEnds = ($valChar=='"') ? $this->endOfText($fileTxt, $valFrom) : $valFrom + strcspn($fileTxt, ",}] \t\r\n", $valFrom);
			$this->writeFile( $filePathNm, substr($fileTxt, 0, $valFrom).json_encode($newRpsValu, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).substr($fileTxt, $valEnds) );
			return;
		}
		throw new \RuntimeException('Value refused: the preset has no key '.( $blockNamed!='' ? $blockNamed.'.' : '' ).$rpsKeyName.', and none is added');
	}

	/**
	 * Finds the end of a JSON text between quotes.
	 *
	 * @param String $wholeTexts The text it stands in.
	 * @param Int    $startsFrom Position of its opening quote.
	 * @return Int Position after its closing quote.
	 */
	protected function endOfText( String $wholeTexts, Int $startsFrom ): Int {
		$txtSize = strlen($wholeTexts);
		$readPos = $startsFrom+1;
		while( $readPos<$txtSize && $wholeTexts[$readPos]!='"' ){
			$readPos += ($wholeTexts[$readPos]=='\\') ? 2 : 1;
		}
		return min($txtSize, $readPos+1);
	}
}
