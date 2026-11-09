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
	public function alterSndString(String $nakedContent, String $sourceFormat, String $goalKindText, Array $codecOptions=Array()): String {
		if( !$this->isProgramReady() ){
			throw new \RuntimeException('The program that unpacks sounds cannot be run on this server');
		}
		if( !isset(self::KIND_LIST[$sourceFormat]) || !isset(self::KIND_LIST[$goalKindText]) ){
			throw new \RuntimeException('Unknown kind of sound: '.(isset(self::KIND_LIST[$sourceFormat]) ? $goalKindText : $sourceFormat));
		}
		$kindSet = self::KIND_LIST[$goalKindText];
		$tempDir = rtrim(sys_get_temp_dir(), '/\\');
		$fileKey = $tempDir.DIRECTORY_SEPARATOR.'nyusnd_'.bin2hex(random_bytes(8));
		$srcPath = $fileKey.'.src';
		$trgtPth = $fileKey.'.out';
		$lgsPath = $fileKey.'.log';
		try{
			if( @file_put_contents($srcPath, $nakedContent)===false ){
				throw new \RuntimeException('The temporary store cannot be made');
			}
			// What is read: that file only, as the kind it was told to be
			$cmdArgs = Array('-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-protocol_whitelist', 'file', '-f', self::KIND_LIST[$sourceFormat][0], '-i', $srcPath);
			if( isset($codecOptions['start']) && floatval($codecOptions['start'])>0 ){
				array_push($cmdArgs, '-ss', number_format(floatval($codecOptions['start']), 3, '.', ''));
			}
			if( isset($codecOptions['until']) && floatval($codecOptions['until'])>0 ){
				array_push($cmdArgs, '-to', number_format(floatval($codecOptions['until']), 3, '.', ''));
			}
			// What is written: the first sound of the file, nothing of what comes with it
			array_push($cmdArgs, '-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1', '-map_chapters', '-1', '-fflags', '+bitexact');
			$klbtNum = intval($codecOptions['kbits'] ?? 0);
			if( $sourceFormat===$goalKindText && $klbtNum<1 && in_array($goalKindText, self::COPY_LIST) ){
				// The same kind: what is heard is taken as it is packed, nothing is lost
				array_push($cmdArgs, '-c:a', 'copy');
			}else{
				array_push($cmdArgs, '-c:a', $kindSet[2]);
				if( $kindSet[3]>0 ){
					array_push($cmdArgs, '-b:a', max(8, min(512, ($klbtNum<1) ? $kindSet[3] : $klbtNum)).'k');
				}
			}
			if( $goalKindText=='mp3' ){
				array_push($cmdArgs, '-id3v2_version', '0');
			}
			array_push($cmdArgs, '-fs', strval(self::MAXI_BYTE), '-f', $kindSet[1], $trgtPth);
			$this->runWithTimeout($cmdArgs, $lgsPath);
			// The size is read first : PHP 8.2 reserves the whole length asked of file_get_contents() before reading
			clearstatcache(true, $trgtPth);
			$madSize = is_file($trgtPth) ? filesize($trgtPth) : false;
			if( $madSize!==false && $madSize>=self::MAXI_BYTE ){
				throw new \RuntimeException('Sound made larger than the '.self::MAXI_BYTE.' bytes allowed');
			}
			$madeTxt = ($madSize!==false && $madSize>0) ? file_get_contents($trgtPth) : false;
			if( $madeTxt===false || $madeTxt==='' ){
				throw new \RuntimeException('The program that unpacks sounds gave nothing back');
			}
			if( strlen($madeTxt)>=self::MAXI_BYTE ){
				throw new \RuntimeException('Sound made larger than the '.self::MAXI_BYTE.' bytes allowed');
			}
			return $madeTxt;
		}finally{
			foreach(Array($srcPath, $trgtPth, $lgsPath) as $onePth){
				if( is_file($onePth) ){
					@unlink($onePth);
				}
			}
		}
	}

	/**
	 * Runs the program, and waits for it no longer than allowed.
	 *
	 * @param Array  $argmntsOfTol What the program is given, one value each.
	 * @param String $errorLogPath Path of the file it writes its complaints to.
	 * @return Void
	 *
	 * @throws \RuntimeException When the program cannot be started, fails, or takes too long.
	 */
	protected function runWithTimeout(Array $argmntsOfTol, String $errorLogPath): Void {
		$nullDev = (PHP_OS_FAMILY=='Windows') ? 'NUL' : '/dev/null';
		$pipeLst = Array();
		// Given as a list, the command is run as it is: no shell reads it
		$process = @proc_open(array_merge($this->commandOfArray(), $argmntsOfTol), Array(0=>Array('file', $nullDev, 'r'), 1=>Array('file', $nullDev, 'w'), 2=>Array('file', $errorLogPath, 'w')), $pipeLst);
		if( !is_resource($process) ){
			throw new \RuntimeException('The program that unpacks sounds cannot be started');
		}
		$endTime = microtime(true)+$this->maxSeconds;
		do{
			usleep(20000);
			$runInfo = proc_get_status($process);
		}while( $runInfo['running'] && microtime(true)<$endTime );
		if( $runInfo['running'] ){
			proc_terminate($process);
			proc_close($process);
			throw new \RuntimeException('The program that unpacks sounds took more than the '.$this->maxSeconds.' seconds allowed');
		}
		proc_close($process);
		if( $runInfo['exitcode']!==0 ){
			// Its first complaint, without the paths of this server
			$logsTxt = is_file($errorLogPath) ? strval(file_get_contents($errorLogPath, false, null, 0, 2000)) : '';
			$logLine = trim(strval(strtok(str_replace(dirname($errorLogPath).DIRECTORY_SEPARATOR, '', $logsTxt), "\r\n")));
			throw new \RuntimeException('The program that unpacks sounds failed'.(($logLine==='') ? '' : ': '.substr(preg_replace(Array('/^\[[^\]]*\]\s*/', '/[^\x20-\x7E]+/'), Array('', ' '), $logLine), 0, 200)));
		}
	}

}
