<?php

// Data\Codec : the program that unpacks sounds, and what Sound asks of it.
// The program is played here by a script telling what it was given.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Codec;
use nyu\Data\Fluxd;
use nyu\Data\Sound;

// A codec whose program is a script of this test, run by PHP
class TestCodec extends Codec {
	protected function commandOfArray(): Array {
		return Array(PHP_BINARY, '-n', $this->ffmpegPath);
	}
}

/**
 * Opens a sound from its bytes.
 *
 * @param String $rawContent The bytes.
 * @return Sound The sound.
 */
function cdcOpen( String $rawContent ): Sound {
	return Sound::openFluxdSound(Fluxd::openBytesFluxd($rawContent));
}

/**
 * Runs a function with the program behaving a given way, and tells what
 * the program was given.
 *
 * @param String   $standinDir Folder of the script playing the program.
 * @param String   $behaveMode How it behaves: good, fail, slow or none.
 * @param callable $testedFunc The function.
 * @return Array What the function gave, or the reason it failed, then what the program was given.
 */
function cdcRuns( String $standinDir, String $behaveMode, callable $testedFunc ): Array {
	file_put_contents($standinDir.'/mode.txt', $behaveMode);
	@unlink($standinDir.'/args.json');
	$gaveVal = runCatchThrown($testedFunc);
	return Array($gaveVal, is_file($standinDir.'/args.json') ? json_decode(file_get_contents($standinDir.'/args.json'), true) : Array());
}

/**
 * Tells whether values follow each other in what the program was given.
 *
 * @param Array $givenArgs What the program was given.
 * @param Array $wantedSeq The values looked for, in a row.
 * @return Bool True when they are found.
 */
function cdcHas( Array $givenArgs, Array $wantedSeq ): Bool {
	for($argIdx=0; $argIdx+count($wantedSeq)<=count($givenArgs); $argIdx++){
		if( array_slice($givenArgs, $argIdx, count($wantedSeq))===$wantedSeq ){
			return true;
		}
	}
	return false;
}

// The sounds the test is made of
$wavData = pack('v*', ...range(0, 7999));
$wavBody = 'WAVE'.'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($wavData)).$wavData;
$wavFile = 'RIFF'.pack('V', strlen($wavBody)).$wavBody;
$mp3File = str_repeat("\xFF\xFB\x90\x00".str_repeat("\0", 413), 40);
$flacInf = pack('nn', 4096, 4096).str_repeat("\0", 6).pack('J', (44100<<44) | (1<<41) | (15<<36) | 88200).str_repeat("\0", 16);
$flacFle = 'fLaC'."\x80".substr(pack('N', strlen($flacInf)), 1).$flacInf."\xFF\xF8".str_repeat("\x55", 998);
$oggHead = "\x01vorbis".pack('V', 0).chr(2).pack('V', 48000).str_repeat("\0", 14);
$oggTags = "\x03vorbis".pack('V', 4).'test'.pack('V', 1).pack('V', 10).'TITLE=Rain';
$oggFile = 'OggS'."\0\0".pack('P', 0).pack('VVV', 1, 0, 0).chr(1).chr(strlen($oggHead)).$oggHead.'OggS'."\0\0".pack('P', 0).pack('VVV', 1, 1, 0).chr(1).chr(strlen($oggTags)).$oggTags.'OggS'."\0\0".pack('P', 96000).pack('VVV', 1, 2, 0).chr(1).chr(100).str_repeat("\x11", 100);

// Without a program
checkIdentical('no program is plugged at first', Sound::validateACodec(), false);
checkIdentical('a program told to be nowhere cannot be used', Array((new Codec(''))->isProgramReady(), (new Codec(__DIR__.'/no-such-program'))->isProgramReady()), Array(false, false));
checkPregMatch('a program that cannot be run', runCatchThrown(function() use ($wavFile){ return (new Codec(''))->alterSoundText($wavFile, 'wav', 'mp3'); }), '/^THROWN RuntimeException: The program that unpacks sounds cannot be run on this server/');
Sound::plugSoundCodec(new Codec(__DIR__.'/no-such-program'));
checkPregMatch('a program plugged and missing changes nothing', runCatchThrown(function() use ($wavFile){ return cdcOpen($wavFile)->grantBytesText('mp3'); }), '/^THROWN RuntimeException: Giving a sound as another kind takes a program/');

if( !function_exists('proc_open') ){
	recordSkipping('sounds made by the program', 'PHP may not run programs here');
	finishChecking();
}

// The script playing the program: it tells what it was given, then behaves as asked
$toolDir = inTemporaryDir('codec');
@mkdir($toolDir, 0777, true);
file_put_contents($toolDir.'/standin.php', '<?php
$modeTxt = trim(file_get_contents(__DIR__."/mode.txt"));
file_put_contents(__DIR__."/args.json", json_encode(array_slice($argv, 1)));
$sourPth = $argv[array_search("-i", $argv)+1];
file_put_contents(__DIR__."/read.txt", strlen(file_get_contents($sourPth)));
if( $modeTxt=="fail" ){ fwrite(STDERR, $sourPth.": Invalid data found when processing input\nsecond line\n"); exit(1); }
if( $modeTxt=="slow" ){ sleep(8); }
if( $modeTxt=="good" ){ copy(__DIR__."/give.bin", $argv[count($argv)-1]); }
exit(0);
');
$toolObj = new TestCodec($toolDir.'/standin.php', 20);
Sound::plugSoundCodec($toolObj);
checkIdentical('a program plugged and found', Array($toolObj->isProgramReady(), Sound::validateACodec()), Array(true, true));
$tempLst = glob(rtrim(sys_get_temp_dir(), '/\\').'/nyusnd_*');

// Another kind
file_put_contents($toolDir.'/give.bin', $mp3File);
$runsSet = cdcRuns($toolDir, 'good', function() use ($wavFile){ return cdcOpen($wavFile)->convertToSound('mp3')->soundInfoArray()['kinds']; });
checkIdentical('a sound made of another kind', $runsSet[0], 'mp3');
checkIdentical('the program reads the sound as the kind it was told to be', Array(array_slice($runsSet[1], 0, 10), intval(file_get_contents($toolDir.'/read.txt'))), Array(Array('-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-protocol_whitelist', 'file', '-f', 'wav', '-i'), strlen($wavFile)));
checkIdentical('the program writes the first sound only, without what comes with it', cdcHas($runsSet[1], Array('-map', '0:a:0', '-vn', '-sn', '-dn', '-map_metadata', '-1', '-map_chapters', '-1')), true);
checkIdentical('the kind made, and how it is packed', Array(cdcHas($runsSet[1], Array('-c:a', 'libmp3lame', '-b:a', '192k', '-id3v2_version', '0')), cdcHas($runsSet[1], Array('-f', 'mp3')), cdcHas($runsSet[1], Array('-ss'))), Array(true, true, false));
$runsSet = cdcRuns($toolDir, 'good', function() use ($wavFile){ return strlen(cdcOpen($wavFile)->grantBytesText('MP3', 96)); });
checkIdentical('the bits a second asked for', Array($runsSet[0], cdcHas($runsSet[1], Array('-b:a', '96k'))), Array(strlen($mp3File), true));
$runsSet = cdcRuns($toolDir, 'good', function() use ($wavFile){ return strlen(cdcOpen($wavFile)->grantBytesText('mp3', 100000)); });
checkIdentical('the bits a second are kept within reason', cdcHas($runsSet[1], Array('-b:a', '512k')), true);
$runsSet = cdcRuns($toolDir, 'good', function() use ($mp3File){ return strlen(cdcOpen($mp3File)->grantBytesText('mp3', 64)); });
checkIdentical('the same kind packed again when bits a second are asked for', cdcHas($runsSet[1], Array('-c:a', 'libmp3lame', '-b:a', '64k')), true);
$runsSet = cdcRuns($toolDir, 'good', function() use ($mp3File){ return strlen(cdcOpen($mp3File)->grantBytesText()); });
checkIdentical('what is done without the program stays done without it', $runsSet, Array(strlen($mp3File), Array()));
file_put_contents($toolDir.'/give.bin', $wavFile);
$runsSet = cdcRuns($toolDir, 'good', function() use ($mp3File){ return cdcOpen($mp3File)->convertToSound('wav')->soundInfoArray()['secnd']; });
checkIdentical('a kind packed without loss is given no bits a second', Array($runsSet[0], cdcHas($runsSet[1], Array('-c:a', 'pcm_s16le', '-fs')), cdcHas($runsSet[1], Array('-f', 'mp3', '-i'))), Array(1.0, true, true));
checkPregMatch('a kind that is not one', runCatchThrown(function() use ($wavFile){ return cdcOpen($wavFile)->grantBytesText('../exe'); }), '/^THROWN RuntimeException: Unknown kind of sound: exe/');

// A part of the kinds not cut as they are written
file_put_contents($toolDir.'/give.bin', $flacFle);
$runsSet = cdcRuns($toolDir, 'good', function() use ($flacFle){ return cdcOpen($flacFle)->makeSoundSlice(0.5, 1.5)->soundInfoArray()['kinds']; });
checkIdentical('a part of a FLAC sound, packed again without loss', Array($runsSet[0], cdcHas($runsSet[1], Array('-f', 'flac', '-i')), cdcHas($runsSet[1], Array('-ss', '0.500', '-to', '1.500')), cdcHas($runsSet[1], Array('-c:a', 'flac', '-fs'))), Array('flac', true, true, true));
file_put_contents($toolDir.'/give.bin', $oggFile);
$runsSet = cdcRuns($toolDir, 'good', function() use ($oggFile){ return cdcOpen($oggFile)->makeSoundSlice(0, 1)->soundInfoArray()['kinds']; });
checkIdentical('a part of an Ogg sound, taken as it is packed', Array($runsSet[0], cdcHas($runsSet[1], Array('-to', '1.000')), cdcHas($runsSet[1], Array('-ss')), cdcHas($runsSet[1], Array('-c:a', 'copy', '-fs'))), Array('ogg', true, false, true));
$runsSet = cdcRuns($toolDir, 'good', function() use ($oggFile){ return strlen(cdcOpen($oggFile)->grantBytesText()); });
checkIdentical('an Ogg sound given back without its tags', Array($runsSet[0], cdcHas($runsSet[1], Array('-c:a', 'copy')), cdcHas($runsSet[1], Array('-f', 'ogg'))), Array(strlen($oggFile), true, true));
$runsSet = cdcRuns($toolDir, 'good', function() use ($wavFile){ return cdcOpen($wavFile)->makeSoundSlice(0.25, 0.5)->soundInfoArray()['secnd']; });
checkIdentical('a WAV sound is still cut without the program', $runsSet, Array(0.25, Array()));

// When the program does not do what is asked
$runsSet = cdcRuns($toolDir, 'fail', function() use ($wavFile){ return cdcOpen($wavFile)->grantBytesText('mp3'); });
checkPregMatch('a program that fails tells its first complaint, without the folders of the server', $runsSet[0], '/^THROWN RuntimeException: The program that unpacks sounds failed: nyusnd_[0-9a-f]{16}\.src: Invalid data found when processing input( @|$)/');
$runsSet = cdcRuns($toolDir, 'none', function() use ($wavFile){ return cdcOpen($wavFile)->grantBytesText('mp3'); });
checkPregMatch('a program that gives nothing back', $runsSet[0], '/^THROWN RuntimeException: The program that unpacks sounds gave nothing back/');
file_put_contents($toolDir.'/give.bin', 'not a sound at all');
$runsSet = cdcRuns($toolDir, 'good', function() use ($wavFile){ return cdcOpen($wavFile)->convertToSound('mp3'); });
checkPregMatch('what the program gives back is read as any sound is', $runsSet[0], '/^THROWN RuntimeException: Not a sound of a known kind/');
Sound::plugSoundCodec(new TestCodec($toolDir.'/standin.php', 1));
$timeNow = microtime(true);
$runsSet = cdcRuns($toolDir, 'slow', function() use ($wavFile){ return cdcOpen($wavFile)->grantBytesText('mp3'); });
checkPregMatch('a program that takes too long is stopped', $runsSet[0], '/^THROWN RuntimeException: The program that unpacks sounds took more than the 1 seconds allowed/');
checkTrueValue('and is not waited for', microtime(true)-$timeNow<6);
checkIdentical('nothing is left in the temporary store', glob(rtrim(sys_get_temp_dir(), '/\\').'/nyusnd_*'), $tempLst);

Sound::plugSoundCodec(null);
checkIdentical('the program can be unplugged', Sound::validateACodec(), false);
dropTestFolder($toolDir);

finishChecking();
