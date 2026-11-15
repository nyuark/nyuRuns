<?php

// Data\Sound : what a sound is, its tags and picture, and the sounds made from it.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Sound;

// A picture of one pixel, as PNG: the cover the sounds carry
$dotPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

/**
 * Opens a sound from its bytes, or returns the reason it was refused.
 *
 * @param String $rawContent The bytes.
 * @return mixed The sound, or a 'THROWN ...' string.
 */
function sndOpen( String $rawContent ): Mixed {
	return runCatchThrown(function() use ($rawContent){
		return Sound::openFluxdSound(Fluxd::openBytesFluxd($rawContent));
	});
}

/**
 * Writes a size on four bytes of seven bits each.
 *
 * @param Int $sizeValue The size.
 * @return String The four bytes.
 */
function sndSafe( Int $sizeValue ): String {
	return chr(($sizeValue>>21) & 0x7F).chr(($sizeValue>>14) & 0x7F).chr(($sizeValue>>7) & 0x7F).chr($sizeValue & 0x7F);
}

/**
 * Writes a tag as MP3 sounds carry it before what is heard (ID3v2.3).
 *
 * @param Array $framesArr The frames: name => content.
 * @return String The tag.
 */
function sndId3( Array $framesArr ): String {
	$bodyTxt = '';
	foreach($framesArr as $frmName=>$frmBody){
		$bodyTxt .= $frmName.pack('N', strlen($frmBody))."\0\0".$frmBody;
	}
	return "ID3\x03\x00\x00".sndSafe(strlen($bodyTxt)).$bodyTxt;
}

/**
 * Writes the frames of an MP3 sound: 128 kbit/s, 44100 Hz, two channels,
 * each frame of 417 bytes holding 1152 samples.
 *
 * @param Int $framesNbr How many frames.
 * @return String The frames, each telling its number in its fifth byte.
 */
function sndMp3( Int $framesNbr ): String {
	$bodyTxt = '';
	for($frmIdx=0; $frmIdx<$framesNbr; $frmIdx++){
		$bodyTxt .= "\xFF\xFB\x90\x00".chr($frmIdx).str_repeat("\0", 412);
	}
	return $bodyTxt;
}

/**
 * Writes a WAV sound: 8000 Hz, one channel, 16 bits, each sample telling its number.
 *
 * @param Int    $samplesNbr How many samples.
 * @param String $extraParts Parts written after what is heard.
 * @return String The sound.
 */
function sndWav( Int $samplesNbr, String $extraParts='' ): String {
	$dataTxt = pack('v*', ...range(0, $samplesNbr-1));
	$bodyTxt = 'WAVE'.'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($dataTxt)).$dataTxt.$extraParts;
	return 'RIFF'.pack('V', strlen($bodyTxt)).$bodyTxt;
}

/**
 * Writes the comments of a FLAC or Ogg sound.
 *
 * @param Array $linesArray The lines, each NAME=value.
 * @return String The block.
 */
function sndCmt( Array $linesArray ): String {
	$blckTxt = pack('V', 4).'test'.pack('V', count($linesArray));
	foreach($linesArray as $oneLine){
		$blckTxt .= pack('V', strlen($oneLine)).$oneLine;
	}
	return $blckTxt;
}

/**
 * Writes a picture block of a FLAC sound.
 *
 * @param String $pictrBytes The bytes of the picture.
 * @return String The block.
 */
function sndPic( String $pictrBytes ): String {
	return pack('N', 3).pack('N', 9).'image/png'.pack('N', 5).'cover'.pack('N4', 1, 1, 32, 0).pack('N', strlen($pictrBytes)).$pictrBytes;
}

/**
 * Writes a page of an Ogg sound.
 *
 * @param Array $packetsArr The packets it holds.
 * @param Int   $lastSample The number of the last sample it ends.
 * @return String The page.
 */
function sndOgg( Array $packetsArr, Int $lastSample ): String {
	$segmTxt = '';
	foreach($packetsArr as $onePck){
		$segmTxt .= str_repeat("\xFF", intdiv(strlen($onePck), 255)).chr(strlen($onePck)%255);
	}
	return 'OggS'."\0\0".pack('P', $lastSample).pack('VVV', 1, 0, 0).chr(strlen($segmTxt)).$segmTxt.implode('', $packetsArr);
}

/**
 * Writes a box of an MP4 file.
 *
 * @param String $boxesName Its name, on four letters.
 * @param String $boxContent What it holds.
 * @return String The box.
 */
function sndBox( String $boxesName, String $boxContent ): String {
	return pack('N', 8+strlen($boxContent)).$boxesName.$boxContent;
}

/**
 * Writes an MP4 file of one track, two seconds long.
 *
 * @param String $trackKinds The kind of the track: 'soun' or 'vide'.
 * @param String $tagsBoxes  The boxes of its tags.
 * @return String The file.
 */
function sndMp4( String $trackKinds, String $tagsBoxes='' ): String {
	$descTxt = "\0\0\0\0".pack('N', 1).sndBox('mp4a', str_repeat("\0", 6).pack('n', 1).str_repeat("\0", 8).pack('nn', 2, 16)."\0\0\0\0".pack('N', 44100<<16));
	$trakTxt = sndBox('trak', sndBox('mdia', sndBox('hdlr', "\0\0\0\0\0\0\0\0".$trackKinds.str_repeat("\0", 13)).sndBox('minf', sndBox('stbl', sndBox('stsd', $descTxt)))));
	$userTxt = ($tagsBoxes==='') ? '' : sndBox('udta', sndBox('meta', "\0\0\0\0".sndBox('ilst', $tagsBoxes)));
	return sndBox('ftyp', 'M4A '."\0\0\0\0".'M4A mp42').sndBox('moov', sndBox('mvhd', str_repeat("\0", 12).pack('NN', 1000, 2000).str_repeat("\0", 80)).$trakTxt.$userTxt).sndBox('mdat', str_repeat("\x21", 400));
}

// What is not a sound
checkPregMatch('an empty content', sndOpen(''), '/^THROWN RuntimeException: Not a sound of a known kind/');
checkPregMatch('a content that is not a sound', sndOpen('<html><body><p>not a sound</p></body></html>'), '/^THROWN RuntimeException: Not a sound of a known kind/');
checkPregMatch('a picture is not a sound', sndOpen($dotPng), '/^THROWN RuntimeException: Not a sound of a known kind/');
checkPregMatch('bytes that only look like the start of a frame', sndOpen(str_repeat("\xFF\xFB\x90\x00junk", 50)), '/^THROWN RuntimeException: Not a sound of a known kind/');
checkPregMatch('a tag claiming more than the file holds', sndOpen("ID3\x03\x00\x00\x7F\x7F\x7F\x7F".sndMp3(10)), '/^THROWN RuntimeException: Not a sound of a known kind/');
checkPregMatch('a source larger than the size accepted', runCatchThrown(function(){ return Sound::openFluxdSound(Fluxd::openBytesFluxd(sndWav(8000)), 100); }), '/^THROWN RuntimeException: Content larger than the 100 bytes allowed/');

// WAV
$wavTags = 'LIST'.pack('V', 4+8+10+8+6).'INFO'.'INAM'.pack('V', 10)."Morning\0\0\0".'IART'.pack('V', 6)."Alice\0";
$wavFile = sndWav(16000, $wavTags);
$wavSnd = sndOpen($wavFile);
checkIdentical('a WAV sound', $wavSnd->getInfoOfSound(), Array('kinds'=>'wav', 'secnd'=>2.0, 'rates'=>8000, 'chans'=>1, 'bitrt'=>128, 'bytes'=>strlen($wavFile), 'tagsl'=>Array('title'=>'Morning', 'artst'=>'Alice')));
checkIdentical('a WAV sound carrying no picture', $wavSnd->readCoverImage(), null);
checkIdentical('a WAV sound given back without its tags', Array(strlen($wavSnd->grantBytesText()), sndOpen($wavSnd->grantBytesText())->getInfoOfSound()['tagsl'], strpos($wavSnd->grantBytesText(), 'Alice')), Array(44+32000, Array(), false));
$wavPart = $wavSnd->makeSoundSlice(0.5, 1.25);
checkIdentical('a part of a WAV sound', Array($wavPart->getInfoOfSound()['secnd'], $wavPart->getInfoOfSound()['bytes'], unpack('v2', substr($wavPart->grantBytesText(), 44, 4)), unpack('v', substr($wavPart->grantBytesText(), -2))[1]), Array(0.75, 44+12000, Array(1=>4000, 2=>4001), 9999));
checkIdentical('a part running past the end stops at the end', $wavSnd->makeSoundSlice(1.5, 60)->getInfoOfSound()['secnd'], 0.5);
checkIdentical('a part starting before the start starts at the start', $wavSnd->makeSoundSlice(-3, 0.25)->getInfoOfSound()['secnd'], 0.25);
checkPregMatch('a part holding nothing', runCatchThrown(function() use ($wavSnd){ return $wavSnd->makeSoundSlice(1.5, 1.5); }), '/^THROWN RuntimeException: The part asked for holds nothing of the sound/');
checkPregMatch('a part past the end', runCatchThrown(function() use ($wavSnd){ return $wavSnd->makeSoundSlice(5, 6); }), '/^THROWN RuntimeException: The part asked for holds nothing of the sound/');
checkPregMatch('another kind is not given without a program', runCatchThrown(function() use ($wavSnd){ return $wavSnd->grantBytesText('mp3'); }), '/^THROWN RuntimeException: Giving a sound as another kind takes a program/');
checkPregMatch('a WAV sound cut short', sndOpen(substr($wavFile, 0, 30)), '/^THROWN RuntimeException: Not a sound of a known kind/');
// A tag written as MP3 sounds write theirs, in a WAV sound
$wavId3 = sndId3(Array('TIT2'=>"\x03Evening", 'APIC'=>"\x00image/png\x00\x03\x00".$dotPng));
$wavSnd = sndOpen(sndWav(8000, 'id3 '.pack('V', strlen($wavId3)).$wavId3));
checkIdentical('a WAV sound tagged as MP3 sounds are', Array($wavSnd->getInfoOfSound()['tagsl'], $wavSnd->readCoverImage()->getInfoOfImage()['kinds']), Array(Array('title'=>'Evening'), 'png'));

// MP3
$mp3Tags = sndId3(Array(
	'TIT2'=>"\x03Caf\xC3\xA9 du matin", 'TPE1'=>"\x01\xFF\xFE".iconv('UTF-8', 'UTF-16LE', 'Zoé'), 'TALB'=>"\x00\xC9t\xE9\x00ignored", 'TYER'=>"\x002019", 'TRCK'=>"\x003/12", 'TCON'=>"\x00(17)",
	'APIC'=>"\x01image/png\x00\x03\xFF\xFE".iconv('UTF-8', 'UTF-16LE', 'front')."\x00\x00".$dotPng, 'TXXX'=>"\x00other\x00thing"
));
$mp3File = $mp3Tags.sndMp3(100);
$mp3Snd = sndOpen($mp3File);
checkIdentical('an MP3 sound', $mp3Snd->getInfoOfSound(), Array('kinds'=>'mp3', 'secnd'=>2.612, 'rates'=>44100, 'chans'=>2, 'bitrt'=>128, 'bytes'=>strlen($mp3File), 'tagsl'=>Array('title'=>'Café du matin', 'artst'=>'Zoé', 'album'=>'Été', 'years'=>'2019', 'track'=>'3', 'genre'=>'Rock')));
checkIdentical('the picture an MP3 sound carries', Array($mp3Snd->readCoverImage()->getInfoOfImage()['kinds'], $mp3Snd->readCoverImage()->getInfoOfImage()['width']), Array('png', 1));
checkIdentical('an MP3 sound given back without its tags', Array($mp3Snd->grantBytesText()===sndMp3(100), sndOpen($mp3Snd->grantBytesText())->getInfoOfSound()['tagsl'], sndOpen($mp3Snd->grantBytesText())->readCoverImage()), Array(true, Array(), null));
// Frames of 1152 samples at 44100 Hz: the frames 39 to 76 start between the first and the second second
$mp3Part = $mp3Snd->makeSoundSlice(1.0, 2.0);
checkIdentical('a part of an MP3 sound, cut at its frames', Array($mp3Part->getInfoOfSound()['secnd'], $mp3Part->getInfoOfSound()['bytes'], ord($mp3Part->grantBytesText()[4]), ord($mp3Part->grantBytesText()[37*417+4])), Array(0.993, 38*417, 39, 76));
// The first kind of tag, at the very end
$oldTags = 'TAG'.str_pad('Old title', 30, "\0").str_pad("Ren\xE9", 30, "\0").str_pad('Old album', 30, ' ').'1987'.str_repeat("\0", 28)."\0\x07\x08";
$mp3Snd = sndOpen(sndMp3(50).$oldTags);
checkIdentical('the tags at the end of an MP3 sound', Array($mp3Snd->getInfoOfSound()['tagsl'], strlen($mp3Snd->grantBytesText())), Array(Array('title'=>'Old title', 'artst'=>'René', 'album'=>'Old album', 'years'=>'1987', 'track'=>'7', 'genre'=>'Jazz'), 50*417));
checkIdentical('the tags before the sound win over the tags at its end', sndOpen(sndId3(Array('TIT2'=>"\x00New title")).sndMp3(50).$oldTags)->getInfoOfSound()['tagsl']['title'], 'New title');
// A first frame only telling how the sound is laid out is not counted
$layFrme = "\xFF\xFB\x90\x00".str_repeat("\0", 32).'Xing'.str_repeat("\0", 377);
checkIdentical('a frame holding nothing heard', Array(sndOpen($layFrme.sndMp3(100))->getInfoOfSound()['secnd'], sndOpen($layFrme.sndMp3(100))->grantBytesText()===sndMp3(100)), Array(2.612, true));
// What a tag may hold
$badTags = sndId3(Array('TIT2'=>"\x03Line one\nLine\x07 two\x1B[31m", 'TPE1'=>"\x03".str_repeat('é', 700), 'TALB'=>"\x03\xFF\xFEnot utf-8", 'TYER'=>"\x00year 1999!", 'TRCK'=>"\x00none", 'TCON'=>"\x00(200)", 'APIC'=>"\x00text/plain\x00\x03\x00not a picture"));
$mp3Snd = sndOpen($badTags.sndMp3(20));
checkIdentical('a tag is one line, without what cannot be shown', $mp3Snd->getInfoOfSound()['tagsl']['title'], 'Line one Line two [31m');
checkIdentical('a tag is no longer than 500 characters', mb_strlen($mp3Snd->getInfoOfSound()['tagsl']['artst'], 'UTF-8'), 500);
checkIdentical('a tag is always UTF-8', preg_match('//u', $mp3Snd->getInfoOfSound()['tagsl']['album']), 1);
checkIdentical('a year is four digits, a track a number, a genre unknown is not told', Array($mp3Snd->getInfoOfSound()['tagsl']['years'], isset($mp3Snd->getInfoOfSound()['tagsl']['track']), isset($mp3Snd->getInfoOfSound()['tagsl']['genre'])), Array('1999', false, false));
checkIdentical('what is carried as a picture and is not one', $mp3Snd->readCoverImage(), null);
// A tag whose frames run past its end
checkIdentical('a tag whose frame claims more than it holds', sndOpen("ID3\x03\x00\x00".sndSafe(20).'TIT2'.pack('N', 5000)."\0\0".'abcdefghij'.sndMp3(20))->getInfoOfSound()['tagsl'], Array());

// FLAC
$flacInf = pack('nn', 4096, 4096).str_repeat("\0", 6).pack('J', (44100<<44) | (1<<41) | (15<<36) | 88200).str_repeat("\0", 16);
$flacCmt = sndCmt(Array('TITLE=Nocturne', 'artist=Frédéric', 'ALBUM=Piano', 'DATE=1832-05-01', 'TRACKNUMBER=02', 'GENRE=Classical', 'TITLE=second title', 'COMMENT=nothing'));
$flacPic = sndPic($dotPng);
$flacBdy = "\xFF\xF8".str_repeat("\x55", 998);
$flacFle = 'fLaC'."\x00".substr(pack('N', strlen($flacInf)), 1).$flacInf."\x04".substr(pack('N', strlen($flacCmt)), 1).$flacCmt."\x86".substr(pack('N', strlen($flacPic)), 1).$flacPic.$flacBdy;
$flacSnd = sndOpen($flacFle);
checkIdentical('a FLAC sound', $flacSnd->getInfoOfSound(), Array('kinds'=>'flac', 'secnd'=>2.0, 'rates'=>44100, 'chans'=>2, 'bitrt'=>4, 'bytes'=>strlen($flacFle), 'tagsl'=>Array('title'=>'Nocturne', 'artst'=>'Frédéric', 'album'=>'Piano', 'years'=>'1832', 'track'=>'2', 'genre'=>'Classical')));
checkIdentical('the picture a FLAC sound carries', $flacSnd->readCoverImage()->getInfoOfImage()['kinds'], 'png');
$flacOut = $flacSnd->grantBytesText();
checkIdentical('a FLAC sound given back without its tags', Array($flacOut==='fLaC'."\x80".substr(pack('N', strlen($flacInf)), 1).$flacInf.$flacBdy, sndOpen($flacOut)->getInfoOfSound()['tagsl'], sndOpen($flacOut)->getInfoOfSound()['secnd'], sndOpen($flacOut)->readCoverImage()), Array(true, Array(), 2.0, null));
checkPregMatch('a FLAC sound is not cut without a program', runCatchThrown(function() use ($flacSnd){ return $flacSnd->makeSoundSlice(0, 1); }), '/^THROWN RuntimeException: Cutting a sound of this kind takes a program that unpacks it, which this server does not have: flac/');
checkPregMatch('a FLAC sound cut short', sndOpen(substr($flacFle, 0, 20)), '/^THROWN RuntimeException: Not a sound of a known kind/');
checkIdentical('a FLAC sound standing after a tag of MP3 sounds', sndOpen(sndId3(Array('TIT2'=>"\x00Ignored")).$flacFle)->getInfoOfSound()['kinds'], 'flac');

// Ogg
$oggCmt = sndCmt(Array('TITLE=Rain', 'ARTIST=Bob', 'METADATA_BLOCK_PICTURE='.base64_encode(sndPic($dotPng))));
$oggFile = sndOgg(Array("\x01vorbis".pack('V', 0).chr(2).pack('V', 48000).str_repeat("\0", 14)), 0).sndOgg(Array("\x03vorbis".$oggCmt, "\x05vorbis".str_repeat("\0", 20)), 0).sndOgg(Array(str_repeat("\x11", 300)), 96000);
$oggSnd = sndOpen($oggFile);
checkIdentical('an Ogg Vorbis sound', $oggSnd->getInfoOfSound(), Array('kinds'=>'ogg', 'secnd'=>2.0, 'rates'=>48000, 'chans'=>2, 'bitrt'=>intval(round(strlen($oggFile)*8/2000)), 'bytes'=>strlen($oggFile), 'tagsl'=>Array('title'=>'Rain', 'artst'=>'Bob')));
checkIdentical('the picture an Ogg sound carries', $oggSnd->readCoverImage()->getInfoOfImage()['kinds'], 'png');
checkPregMatch('the tags of an Ogg sound are not taken off as it is written', runCatchThrown(function() use ($oggSnd){ return $oggSnd->grantBytesText(); }), '/^THROWN RuntimeException: The tags of a sound of this kind cannot be taken off as it is written: ogg/');
$opusFle = sndOgg(Array('OpusHead'.chr(1).chr(1).pack('v', 312).pack('V', 44100)."\0\0\0"), 0).sndOgg(Array('OpusTags'.sndCmt(Array('TITLE=Voice'))), 0).sndOgg(Array(str_repeat("\x11", 300)), 96312);
checkIdentical('an Ogg Opus sound', array_slice(sndOpen($opusFle)->getInfoOfSound(), 0, 4)+Array('tagsl'=>sndOpen($opusFle)->getInfoOfSound()['tagsl']), Array('kinds'=>'ogg', 'secnd'=>2.0, 'rates'=>48000, 'chans'=>1, 'tagsl'=>Array('title'=>'Voice')));
checkPregMatch('an Ogg file that holds no sound this reads', sndOpen(sndOgg(Array("\x80theora".str_repeat("\0", 30)), 0).sndOgg(Array('x'), 500)), '/^THROWN RuntimeException: Not a sound of a known kind/');

// MP4
$mp4Tags = sndBox("\xA9nam", sndBox('data', pack('NN', 1, 0).'Sunrise')).sndBox("\xA9ART", sndBox('data', pack('NN', 1, 0).'Carol')).sndBox("\xA9day", sndBox('data', pack('NN', 1, 0).'2021-03-04')).sndBox('trkn', sndBox('data', pack('NN', 0, 0).pack('nnnn', 0, 5, 9, 0))).sndBox('gnre', sndBox('data', pack('NN', 0, 0).pack('n', 9))).sndBox('covr', sndBox('data', pack('NN', 14, 0).$dotPng));
$mp4File = sndMp4('soun', $mp4Tags);
$mp4Snd = sndOpen($mp4File);
checkIdentical('an MP4 sound', $mp4Snd->getInfoOfSound(), Array('kinds'=>'m4a', 'secnd'=>2.0, 'rates'=>44100, 'chans'=>2, 'bitrt'=>intval(round(strlen($mp4File)*8/2000)), 'bytes'=>strlen($mp4File), 'tagsl'=>Array('title'=>'Sunrise', 'artst'=>'Carol', 'years'=>'2021', 'track'=>'5', 'genre'=>'Jazz')));
checkIdentical('the picture an MP4 sound carries', $mp4Snd->readCoverImage()->getInfoOfImage()['kinds'], 'png');
checkIdentical('an MP4 sound without tags', sndOpen(sndMp4('soun'))->getInfoOfSound()['tagsl'], Array());
checkPregMatch('an MP4 file holding moving pictures is not a sound', sndOpen(sndMp4('vide')), '/^THROWN RuntimeException: Not a sound of a known kind/');
checkPregMatch('an MP4 sound is not cut without a program', runCatchThrown(function() use ($mp4Snd){ return $mp4Snd->makeSoundSlice(0, 1); }), '/^THROWN RuntimeException: Cutting a sound of this kind takes a program/');

// A WAV sound changed without a program
/**
 * Writes a WAV sound from its samples.
 *
 * @param Array $samplesArr The samples, as written (0 to 255 on 8 bits, 0 to 65535 on 16).
 * @param Int   $ratesHertz Samples a second.
 * @param Int   $chansCount Channels.
 * @param Int   $sampleBits Bits a sample: 8 or 16.
 * @param Int   $packedKind How the samples are packed: 1 for whole numbers.
 * @return String The sound.
 */
function sndRaw( Array $samplesArr, Int $ratesHertz, Int $chansCount, Int $sampleBits, Int $packedKind=1 ): String {
	$dataTxt = pack(($sampleBits==8) ? 'C*' : 'v*', ...$samplesArr);
	$frmeLen = $chansCount*intdiv($sampleBits, 8);
	$bodyTxt = 'WAVE'.'fmt '.pack('V', 16).pack('vvVVvv', $packedKind, $chansCount, $ratesHertz, $ratesHertz*$frmeLen, $frmeLen, $sampleBits).'data'.pack('V', strlen($dataTxt)).$dataTxt;
	return 'RIFF'.pack('V', strlen($bodyTxt)).$bodyTxt;
}
/**
 * Gives what a WAV sound is, and its first samples as written.
 *
 * @param Sound $soundToSee The sound.
 * @param Int   $samplesNbr How many samples to give.
 * @return Array Its samples a second, channels, bits a sample, then the samples.
 */
function sndSee( Sound $soundToSee, Int $samplesNbr ): Array {
	$wavBytes = $soundToSee->grantBytesText();
	$bitsNbr = unpack('v', substr($wavBytes, 34, 2))[1];
	$bytesNbr = intdiv($bitsNbr, 8);
	$smplLst = Array();
	for($smpIdx=0; $smpIdx<$samplesNbr; $smpIdx++){
		$smplLst[] = unpack('V', str_pad(substr($wavBytes, 44+$smpIdx*$bytesNbr, $bytesNbr), 4, "\0"))[1];
	}
	return Array($soundToSee->getInfoOfSound()['rates'], $soundToSee->getInfoOfSound()['chans'], $bitsNbr, $smplLst);
}
$wavSnd = sndOpen(sndRaw(array_merge(Array(0, 100, 200, 65436, 1000, 3000), array_fill(0, 15994, 0)), 8000, 1, 16));
checkIdentical('one channel made two', sndSee($wavSnd->castIntoWavFmt(0, 2), 8), Array(8000, 2, 16, Array(0, 0, 100, 100, 200, 200, 65436, 65436)));
checkIdentical('twice the samples a second: a line drawn between two samples', sndSee($wavSnd->castIntoWavFmt(16000), 8), Array(16000, 1, 16, Array(0, 50, 100, 150, 200, 50, 65436, 450)));
checkIdentical('the sound lasts as long, whatever is changed', Array($wavSnd->getInfoOfSound()['secnd'], $wavSnd->castIntoWavFmt(16000)->getInfoOfSound()['secnd'], $wavSnd->castIntoWavFmt(11025, 2, 8)->getInfoOfSound()['secnd'], $wavSnd->castIntoWavFmt(48000, 2, 24)->getInfoOfSound()['bytes']), Array(2.0, 2.0, 2.0, 44+96000*6));
checkIdentical('16 bits made 8, written from 0 to 255', sndSee(sndOpen(sndRaw(Array(0, 16384, 49152, 32767, 32768), 8000, 1, 16))->castIntoWavFmt(0, 0, 8), 5), Array(8000, 1, 8, Array(128, 192, 64, 255, 0)));
checkIdentical('8 bits made 16, written around 0', sndSee(sndOpen(sndRaw(Array(128, 192, 64, 255, 0, 128), 8000, 1, 8))->castIntoWavFmt(0, 0, 16), 5), Array(8000, 1, 16, Array(0, 16384, 49152, 32512, 32768)));
checkIdentical('16 bits made 24 and 32, and back', Array(sndSee(sndOpen(sndRaw(Array(1, 65535), 8000, 1, 16))->castIntoWavFmt(0, 0, 24), 2)[3], sndSee(sndOpen(sndRaw(Array(1, 65535), 8000, 1, 16))->castIntoWavFmt(0, 0, 32), 2)[3], sndSee(sndOpen(sndRaw(Array(1, 65535), 8000, 1, 16))->castIntoWavFmt(0, 0, 24)->castIntoWavFmt(0, 0, 16), 2)[3]), Array(Array(256, 0xFFFF00), Array(65536, 0xFFFF0000), Array(1, 65535)));
$twoSnd = sndOpen(sndRaw(Array(1000, 3000, 65436, 65336, 10, 20, 30, 40), 16000, 2, 16));
checkIdentical('two channels made one: the middle of both', sndSee($twoSnd->castIntoWavFmt(0, 1), 4), Array(16000, 1, 16, Array(2000, 65386, 15, 35)));
checkIdentical('half the samples a second: one sample out of two', sndSee($twoSnd->castIntoWavFmt(8000), 4), Array(8000, 2, 16, Array(1000, 3000, 10, 20)));
checkIdentical('nothing asked gives the same sound', $wavSnd->castIntoWavFmt()->grantBytesText()===$wavSnd->grantBytesText(), true);
checkIdentical('four channels made two are the first two, made one the middle of all', Array(sndSee(sndOpen(sndRaw(Array(100, 200, 300, 400, 1, 2, 3, 4), 8000, 4, 16))->castIntoWavFmt(0, 2), 4)[3], sndSee(sndOpen(sndRaw(Array(100, 200, 300, 400, 1, 2, 3, 6), 8000, 4, 16))->castIntoWavFmt(0, 1), 2)[3]), Array(Array(100, 200, 1, 2), Array(250, 3)));
checkPregMatch('samples a second out of what is accepted', runCatchThrown(function() use ($wavSnd){ return $wavSnd->castIntoWavFmt(100); }), '/^THROWN RuntimeException: Samples a second out of the 8000 to 192000 accepted: 100/');
checkPregMatch('channels out of what is accepted', runCatchThrown(function() use ($wavSnd){ return $wavSnd->castIntoWavFmt(0, 6); }), '/^THROWN RuntimeException: Channels other than the 1 or 2 accepted: 6/');
checkPregMatch('bits a sample out of what is accepted', runCatchThrown(function() use ($wavSnd){ return $wavSnd->castIntoWavFmt(0, 0, 12); }), '/^THROWN RuntimeException: Bits a sample other than the 8, 16, 24 or 32 accepted: 12/');
checkPregMatch('a WAV sound whose samples are not whole numbers', runCatchThrown(function(){ return sndOpen(sndRaw(array_fill(0, 8000, 0), 8000, 1, 16, 3))->castIntoWavFmt(16000); }), '/^THROWN RuntimeException: A WAV sound packed this way is not changed without a program/');
checkPregMatch('a sound of another kind is not changed without a program', runCatchThrown(function(){ return sndOpen(sndMp3(50))->castIntoWavFmt(22050); }), '/^THROWN RuntimeException: Giving a sound as another kind takes a program/');

// Written to a file
$tempDir = inTemporaryDir('sound');
@mkdir($tempDir, 0777, true);
$mp3Snd = sndOpen($mp3File);
$savePth = $mp3Snd->makeSoundSlice(0, 1)->writeLocalFile($tempDir.'/part.mp3');
checkIdentical('a part of a sound written to a file', Array(basename($savePth), Sound::openFluxdSound(Fluxd::openLocalFluxd($savePth))->getInfoOfSound()['secnd'], Sound::openFluxdSound(Fluxd::openLocalFluxd($savePth))->getInfoOfSound()['tagsl']), Array('part.mp3', 1.019, Array()));
checkPregMatch('a file that cannot be written', runCatchThrown(function() use ($mp3Snd, $tempDir){ return $mp3Snd->writeLocalFile($tempDir.'/none/part.mp3'); }), '/^THROWN RuntimeException: File cannot be written: part.mp3/');
dropTestFolder($tempDir);

finishChecking();
