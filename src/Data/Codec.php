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
	const MAXBYTES = 268435456;
	// The kinds read and written, with the name the program gives each when reading, when writing, and the way it packs it
	const KINDLIST = Array(
		'wav'=>Array('wav', 'wav', 'pcm_s16le', 0),
		'mp3'=>Array('mp3', 'mp3', 'libmp3lame', 192),
		'flac'=>Array('flac', 'flac', 'flac', 0),
		'ogg'=>Array('ogg', 'ogg', 'libvorbis', 160),
		'm4a'=>Array('mov', 'ipod', 'aac', 160)
	);
	// The kinds a part of which, or the whole without its tags, is taken as it is packed
	const COPYLIST = Array('mp3', 'ogg', 'm4a');

	protected $toolPath;
	protected $timeSecs;

	/**
	 * Tells where the program is, and how long it may run.
	 *
	 * @param String $ffmpegPath Full path of the ffmpeg program; '' where there is none.
	 * @param Int    $secndLimit Seconds a run may take before it is stopped.
	 */
	public function __construct(String $ffmpegPath, Int $secndLimit=60){
		$this->toolPath = $ffmpegPath;
		$this->timeSecs = max(1, $secndLimit);
	}

	/**
	 * Tells whether the program can be run on this server.
	 *
	 * @return Bool True when the program is where it is said to be, and PHP may run programs.
	 */
	public function canBeUsed(): Bool {
		return ( $this->toolPath!=='' && function_exists('proc_open') && is_file($this->toolPath) );
	}

	/**
	 * Gives the start of the command running the program.
	 *
	 * @return Array The program, and what it is always given first.
	 */
	protected function commandOf(): Array {
		return Array($this->toolPath);
	}

	/**
	 * Makes a sound from another one: of another kind, or a part of it,
	 * always without its tags nor its picture.
	 *
	 * @param String $rawContent The bytes of the sound.
	 * @param String $sourceKind Its kind, as told from its content: wav, mp3, flac, ogg or m4a.
	 * @param String $targetKind The kind to make.
	 * @param Array  $codecOptns Where the part starts under 'start' and ends under 'until', in seconds; the thousands of bits a second to pack it with under 'kbits'.
	 * @return String The bytes of the sound made.
	 *
	 * @throws \RuntimeException When the program cannot be run, a kind is unknown, or the program fails or takes too long.
	 */
	public function changeSnd(String $rawContent, String $sourceKind, String $targetKind, Array $codecOptns=Array()): String {
		if( !$this->canBeUsed() ){
			throw new \RuntimeException('The program that unpacks sounds cannot be run on this server');
		}
		if( !isset(self::KINDLIST[$sourceKind]) || !isset(self::KINDLIST[$targetKind]) ){
			throw new \RuntimeException('Unknown kind of sound: '.(isset(self::KINDLIST[$sourceKind]) ? $targetKind : $sourceKind));
		}
		$kindSet = self::KINDLIST[$targetKind];
		$tempDir = rtrim(sys_get_temp_dir(), '/\\');
		$fileKey = $tempDir.DIRECTORY_SEPARATOR.'nyusnd_'.bin2hex(random_bytes(8));
		$sourPth = $fileKey.'.src';
		$targPth = $fileKey.'.out';
		$logsPth = $fileKey.'.log';
		try{
			if( @file_put_contents($sourPth, $rawContent)===false ){
				throw new \RuntimeException('The temporary store cannot be made');
			}
			// What is read: that file only, as the kind it was told to be
			$argsLst = Array('-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-protocol_whitelist', 'file', '-f', self::KINDLIST[$sourceKind][0], '-i', $sourPth);
			if( isset($codecOptns['start']) && floatval($codecOptns['start'])>0 ){
				array_push($argsLst, '-ss', number_format(floatval($codecOptns['start']), 3, '.', ''));
			}
			if( isset($codecOptns['until']) && floatval($codecOptns['until'])>0 ){
				array_push($argsLst, '-to', number_format(floatval($codecOptns['until']), 3, '.', ''));
			}
			// What is written: the first sound of the file, nothing of what comes with it
			array_push($argsLst, '-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1', '-map_chapters', '-1', '-fflags', '+bitexact');
			$kbitNbr = intval($codecOptns['kbits'] ?? 0);
			if( $sourceKind===$targetKind && $kbitNbr<1 && in_array($targetKind, self::COPYLIST) ){
				// The same kind: what is heard is taken as it is packed, nothing is lost
				array_push($argsLst, '-c:a', 'copy');
			}else{
				array_push($argsLst, '-c:a', $kindSet[2]);
				if( $kindSet[3]>0 ){
					array_push($argsLst, '-b:a', max(8, min(512, ($kbitNbr<1) ? $kindSet[3] : $kbitNbr)).'k');
				}
			}
			if( $targetKind=='mp3' ){
				array_push($argsLst, '-id3v2_version', '0');
			}
			array_push($argsLst, '-fs', strval(self::MAXBYTES), '-f', $kindSet[1], $targPth);
			$this->launchCmd($argsLst, $logsPth);
			// The size is read first : PHP 8.2 reserves the whole length asked of file_get_contents() before reading
			clearstatcache(true, $targPth);
			$madeSze = is_file($targPth) ? filesize($targPth) : false;
			if( $madeSze!==false && $madeSze>=self::MAXBYTES ){
				throw new \RuntimeException('Sound made larger than the '.self::MAXBYTES.' bytes allowed');
			}
			$madeTxt = ($madeSze!==false && $madeSze>0) ? file_get_contents($targPth) : false;
			if( $madeTxt===false || $madeTxt==='' ){
				throw new \RuntimeException('The program that unpacks sounds gave nothing back');
			}
			if( strlen($madeTxt)>=self::MAXBYTES ){
				throw new \RuntimeException('Sound made larger than the '.self::MAXBYTES.' bytes allowed');
			}
			return $madeTxt;
		}finally{
			foreach(Array($sourPth, $targPth, $logsPth) as $onePth){
				if( is_file($onePth) ){
					@unlink($onePth);
				}
			}
		}
	}

	/**
	 * Runs the program, and waits for it no longer than allowed.
	 *
	 * @param Array  $argsOfTool What the program is given, one value each.
	 * @param String $logsOfPath Path of the file it writes its complaints to.
	 * @return Void
	 *
	 * @throws \RuntimeException When the program cannot be started, fails, or takes too long.
	 */
	protected function launchCmd(Array $argsOfTool, String $logsOfPath): Void {
		$nullDev = (PHP_OS_FAMILY=='Windows') ? 'NUL' : '/dev/null';
		$pipeLst = Array();
		// Given as a list, the command is run as it is: no shell reads it
		$procHdl = @proc_open(array_merge($this->commandOf(), $argsOfTool), Array(0=>Array('file', $nullDev, 'r'), 1=>Array('file', $nullDev, 'w'), 2=>Array('file', $logsOfPath, 'w')), $pipeLst);
		if( !is_resource($procHdl) ){
			throw new \RuntimeException('The program that unpacks sounds cannot be started');
		}
		$endTime = microtime(true)+$this->timeSecs;
		do{
			usleep(20000);
			$procSts = proc_get_status($procHdl);
		}while( $procSts['running'] && microtime(true)<$endTime );
		if( $procSts['running'] ){
			proc_terminate($procHdl);
			proc_close($procHdl);
			throw new \RuntimeException('The program that unpacks sounds took more than the '.$this->timeSecs.' seconds allowed');
		}
		proc_close($procHdl);
		if( $procSts['exitcode']!==0 ){
			// Its first complaint, without the paths of this server
			$logsTxt = is_file($logsOfPath) ? strval(file_get_contents($logsOfPath, false, null, 0, 2000)) : '';
			$logLine = trim(strval(strtok(str_replace(dirname($logsOfPath).DIRECTORY_SEPARATOR, '', $logsTxt), "\r\n")));
			throw new \RuntimeException('The program that unpacks sounds failed'.(($logLine==='') ? '' : ': '.substr(preg_replace(Array('/^\[[^\]]*\]\s*/', '/[^\x20-\x7E]+/'), Array('', ' '), $logLine), 0, 200)));
		}
	}

}
