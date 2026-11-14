<?php

namespace nyu\Data;

/**
 * The program that unpacks sounds and packs them again: ffmpeg, run on
 * the server where it is installed. It is what gives a sound as another
 * kind, and cuts the kinds that cannot be cut as they are written.
 *
 * The program is run by its full path, without a shell, on a file whose
 * kind has been told from its content: it is never left to guess what it
 * reads, nor to read anything but that file.
 */
class Codec {

	// Greatest size of a sound made, in bytes: 256 MB
	const MAXI_BYTE = 268435456;
	// The kinds read and written, with the name the program gives each when reading, when writing, and the way it packs it
	const KIND_LIST = Array(
		'wav'=>Array('wav', 'wav', 'pcm_s16le', 0),
		'mp3'=>Array('mp3', 'mp3', 'libmp3lame', 192),
		'flac'=>Array('flac', 'flac', 'flac', 0),
		'ogg'=>Array('ogg', 'ogg', 'libvorbis', 160),
		'm4a'=>Array('mov', 'ipod', 'aac', 160)
	);
	// The kinds a part of which, or the whole without its tags, is taken as it is packed
	const COPY_LIST = Array('mp3', 'ogg', 'm4a');

	protected $ffmpegPath;
	protected $maxSeconds;

	/**
	 * Tells where the program is, and how long it may run.
	 *
	 * @param String $ffmpegBinary Full path of the ffmpeg program; '' where there is none.
	 * @param Int    $secondCapNum Seconds a run may take before it is stopped.
	 */
	public function __construct(String $ffmpegBinary, Int $secondCapNum=60){
		$this->ffmpegPath = $ffmpegBinary;
		$this->maxSeconds = max(1, $secondCapNum);
	}

	/**
	 * Tells whether the program can be run on this server.
	 *
	 * @return Bool True when the program is where it is said to be, and PHP may run programs.
	 */
	public function isProgramReady(): Bool {
		return ( $this->ffmpegPath!=='' && function_exists('proc_open') && is_file($this->ffmpegPath) );
	}

	/**
	 * Gives the start of the command running the program.
	 *
	 * @return Array The program, and what it is always given first.
	 */
	protected function commandOfArray(): Array {
		return Array($this->ffmpegPath);
	}

	/**
	 * Makes a sound from another one: of another kind, or a part of it,
	 * always without its tags nor its picture.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @param String $sourceFormat Its kind, as told from its content: wav, mp3, flac, ogg or m4a.
	 * @param String $goalKindText The kind to make.
	 * @param Array  $codecOptions Where the part starts under 'start' and ends under 'until', in seconds; the thousands of bits a second to pack it with under 'kbits'.
	 * @return String The bytes of the sound made.
	 *
	 * @throws \RuntimeException When the program cannot be run, a kind is unknown, or the program fails or takes too long.
	 */
	public function alterSoundText(String $nakedContent, String $sourceFormat, String $goalKindText, Array $codecOptions=Array()): String {
		if( !$this->isProgramReady() ){
			throw new \RuntimeException('The program that unpacks sounds cannot be run on this server');
		}
		if( !isset(self::KIND_LIST[$sourceFormat]) || !isset(self::KIND_LIST[$goalKindText]) ){
			throw new \RuntimeException('Unknown kind of sound: '.(isset(self::KIND_LIST[$sourceFormat]) ? $goalKindText : $sourceFormat));
		}
		$kindDict = self::KIND_LIST[$goalKindText];
		$tempPath = rtrim(sys_get_temp_dir(), '/\\');
		$fileClef = $tempPath.DIRECTORY_SEPARATOR.'nyusnd_'.bin2hex(random_bytes(8));
		$srcePath = $fileClef.'.src';
		$trgtPath = $fileClef.'.out';
		$logsPath = $fileClef.'.log';
		try{
			if( @file_put_contents($srcePath, $nakedContent)===false ){
				throw new \RuntimeException('The temporary store cannot be made');
			}
			// What is read: that file only, as the kind it was told to be
			$cmmnArgs = Array('-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-protocol_whitelist', 'file', '-f', self::KIND_LIST[$sourceFormat][0], '-i', $srcePath);
			if( isset($codecOptions['start']) && floatval($codecOptions['start'])>0 ){
				array_push($cmmnArgs, '-ss', number_format(floatval($codecOptions['start']), 3, '.', ''));
			}
			if( isset($codecOptions['until']) && floatval($codecOptions['until'])>0 ){
				array_push($cmmnArgs, '-to', number_format(floatval($codecOptions['until']), 3, '.', ''));
			}
			// What is written: the first sound of the file, nothing of what comes with it
			array_push($cmmnArgs, '-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1', '-map_chapters', '-1', '-fflags', '+bitexact');
			$kbitNumb = intval($codecOptions['kbits'] ?? 0);
			if( $sourceFormat===$goalKindText && $kbitNumb<1 && in_array($goalKindText, self::COPY_LIST) ){
				// The same kind: what is heard is taken as it is packed, nothing is lost
				array_push($cmmnArgs, '-c:a', 'copy');
			}else{
				array_push($cmmnArgs, '-c:a', $kindDict[2]);
				if( $kindDict[3]>0 ){
					array_push($cmmnArgs, '-b:a', max(8, min(512, ($kbitNumb<1) ? $kindDict[3] : $kbitNumb)).'k');
				}
			}
			if( $goalKindText=='mp3' ){
				array_push($cmmnArgs, '-id3v2_version', '0');
			}
			array_push($cmmnArgs, '-fs', strval(self::MAXI_BYTE), '-f', $kindDict[1], $trgtPath);
			$this->runWithTimeout($cmmnArgs, $logsPath);
			// The size is read first : PHP 8.2 reserves the whole length asked of file_get_contents() before reading
			clearstatcache(true, $trgtPath);
			$madeSize = is_file($trgtPath) ? filesize($trgtPath) : false;
			if( $madeSize!==false && $madeSize>=self::MAXI_BYTE ){
				throw new \RuntimeException('Sound made larger than the '.self::MAXI_BYTE.' bytes allowed');
			}
			$madeText = ($madeSize!==false && $madeSize>0) ? file_get_contents($trgtPath) : false;
			if( $madeText===false || $madeText==='' ){
				throw new \RuntimeException('The program that unpacks sounds gave nothing back');
			}
			if( strlen($madeText)>=self::MAXI_BYTE ){
				throw new \RuntimeException('Sound made larger than the '.self::MAXI_BYTE.' bytes allowed');
			}
			return $madeText;
		}finally{
			foreach(Array($srcePath, $trgtPath, $logsPath) as $onePth){
				if( is_file($onePth) ){
					@unlink($onePth);
				}
			}
		}
	}

	/**
	 * Runs the program, and waits for it no longer than allowed.
	 *
	 * @param Array  $toolArgsList What the program is given, one value each.
	 * @param String $errorLogPath Path of the file it writes its complaints to.
	 * @return Void
	 *
	 * @throws \RuntimeException When the program cannot be started, fails, or takes too long.
	 */
	protected function runWithTimeout(Array $toolArgsList, String $errorLogPath): Void {
		$nullDvce = (PHP_OS_FAMILY=='Windows') ? 'NUL' : '/dev/null';
		$pipeList = Array();
		// Given as a list, the command is run as it is: no shell reads it
		$procHndl = @proc_open(array_merge($this->commandOfArray(), $toolArgsList), Array(0=>Array('file', $nullDvce, 'r'), 1=>Array('file', $nullDvce, 'w'), 2=>Array('file', $errorLogPath, 'w')), $pipeList);
		if( !is_resource($procHndl) ){
			throw new \RuntimeException('The program that unpacks sounds cannot be started');
		}
		$endStamp = microtime(true)+$this->maxSeconds;
		do{
			usleep(20000);
			$runArray = proc_get_status($procHndl);
		}while( $runArray['running'] && microtime(true)<$endStamp );
		if( $runArray['running'] ){
			proc_terminate($procHndl);
			proc_close($procHndl);
			throw new \RuntimeException('The program that unpacks sounds took more than the '.$this->maxSeconds.' seconds allowed');
		}
		proc_close($procHndl);
		if( $runArray['exitcode']!==0 ){
			// Its first complaint, without the paths of this server
			$logsText = is_file($errorLogPath) ? strval(file_get_contents($errorLogPath, false, null, 0, 2000)) : '';
			$logLigne = trim(strval(strtok(str_replace(dirname($errorLogPath).DIRECTORY_SEPARATOR, '', $logsText), "\r\n")));
			throw new \RuntimeException('The program that unpacks sounds failed'.(($logLigne==='') ? '' : ': '.substr(preg_replace(Array('/^\[[^\]]*\]\s*/', '/[^\x20-\x7E]+/'), Array('', ' '), $logLigne), 0, 200)));
		}
	}

}
