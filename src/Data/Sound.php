<?php

namespace nyu\Data;

// A sound: what it is, told from its content, the tags and the picture
// it carries, and the sounds that can be made of it without unpacking
// what is heard: the same without its tags, or a part of it.
//
// Nothing here unpacks the sound itself. A part is cut where the kind
// lets it be cut as it is written: between two samples of a WAV sound,
// between two frames of an MP3 one. Giving a sound as another kind, or
// cutting the other kinds, takes a program that unpacks it.
class Sound {

	// Greatest size read from a source unless told otherwise: 64 MB
	const MAXBYTES = 67108864;
	// Greatest length kept of a tag, in characters
	const TAGLIMIT = 500;
	// The genres the first tags of MP3 sounds name by a number
	const GENRLIST = Array('Blues', 'Classic Rock', 'Country', 'Dance', 'Disco', 'Funk', 'Grunge', 'Hip-Hop', 'Jazz', 'Metal', 'New Age', 'Oldies', 'Other', 'Pop', 'R&B', 'Rap', 'Reggae', 'Rock', 'Techno', 'Industrial', 'Alternative', 'Ska', 'Death Metal', 'Pranks', 'Soundtrack', 'Euro-Techno', 'Ambient', 'Trip-Hop', 'Vocal', 'Jazz+Funk', 'Fusion', 'Trance', 'Classical', 'Instrumental', 'Acid', 'House', 'Game', 'Sound Clip', 'Gospel', 'Noise', 'AlternRock', 'Bass', 'Soul', 'Punk', 'Space', 'Meditative', 'Instrumental Pop', 'Instrumental Rock', 'Ethnic', 'Gothic', 'Darkwave', 'Techno-Industrial', 'Electronic', 'Pop-Folk', 'Eurodance', 'Dream', 'Southern Rock', 'Comedy', 'Cult', 'Gangsta', 'Top 40', 'Christian Rap', 'Pop/Funk', 'Jungle', 'Native American', 'Cabaret', 'New Wave', 'Psychadelic', 'Rave', 'Showtunes', 'Trailer', 'Lo-Fi', 'Tribal', 'Acid Punk', 'Acid Jazz', 'Polka', 'Retro', 'Musical', 'Rock & Roll', 'Hard Rock');
	// The frames of an MP3 sound: bit rates in thousands of bits a second, by version and layer, then sample rates by version
	const MP3RATES = Array(
		'11'=>Array(0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448), '12'=>Array(0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384), '13'=>Array(0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320),
		'21'=>Array(0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256), '22'=>Array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160), '23'=>Array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160)
	);
	const MP3HERTZ = Array(3=>Array(44100, 48000, 32000), 2=>Array(22050, 24000, 16000), 0=>Array(11025, 12000, 8000));

	protected $rawBytes;
	protected $infoList;
	protected $coverPic;
	// For the kinds cut as they are written: where what is heard starts and ends, and the frames it is made of
	protected $cutsData;
	// The program that unpacks sounds, where the server has one
	protected static $theCodec = null;
	
	/**
	 * Plugs the program that unpacks sounds, for the sounds to be given
	 * as other kinds and every kind to be cut.
	 *
	 * @param ?Codec $soundCodec The program; null to do without.
	 * @return Void
	 */
	public static function plugCodec(?Codec $soundCodec): Void {
		self::$theCodec = $soundCodec;
	}
	
	/**
	 * Tells whether a program that unpacks sounds can be used.
	 *
	 * @return Bool True when one is plugged and can be run on this server.
	 */
	public static function hasACodec(): Bool {
		return ( self::$theCodec!==null && self::$theCodec->canBeUsed() );
	}

	/**
	 * Private: a sound is opened through openFluxd(), or made from
	 * another one.
	 *
	 * @param String $rawContent The bytes of the sound.
	 * @param Array  $infosArray What the sound is, as soundInfo() gives it.
	 * @param String $coverBytes The bytes of the picture it carries, '' when it carries none.
	 * @param Array  $cutsValues What is needed to cut it as it is written.
	 */
	private function __construct(String $rawContent, Array $infosArray, String $coverBytes, Array $cutsValues){
		$this->rawBytes = $rawContent;
		$this->infoList = $infosArray;
		$this->coverPic = $coverBytes;
		$this->cutsData = $cutsValues;
	}

	/**
	 * Opens a sound from a source. What it is is read from its content,
	 * not from its name.
	 *
	 * @param Fluxd $sourceFlux The source.
	 * @param Int   $bytesLimit Greatest size read from the source, in bytes.
	 * @return Sound The opened sound.
	 *
	 * @throws \RuntimeException When the source is larger than the limit, or is not a sound of a known kind.
	 */
	public static function openFluxd(Fluxd $sourceFlux, Int $bytesLimit=self::MAXBYTES): Sound {
		return self::fromBytes($sourceFlux->readWhole($bytesLimit));
	}

	/**
	 * Reads a sound from its bytes.
	 *
	 * @param String $rawContent The bytes.
	 * @return Sound The sound.
	 *
	 * @throws \RuntimeException When the content is not a sound of a known kind.
	 */
	protected static function fromBytes(String $rawContent): Sound {
		// A tag standing before the sound is passed over to tell what follows it
		$bodyPos = 0;
		while( substr($rawContent, $bodyPos, 3)==='ID3' && strlen($rawContent)>=$bodyPos+10 ){
			$bodyPos += 10+self::sizeOfTag(substr($rawContent, $bodyPos+6, 4));
		}
		$headTxt = substr($rawContent, $bodyPos, 12);
		$readSet = null;
		if( substr($headTxt, 0, 4)==='RIFF' && substr($headTxt, 8, 4)==='WAVE' ){
			$readSet = self::readOfWav($rawContent);
		}elseif( substr($headTxt, 0, 4)==='fLaC' ){
			$readSet = self::readOfFla($rawContent, $bodyPos);
		}elseif( substr($headTxt, 0, 4)==='OggS' ){
			$readSet = self::readOfOgg($rawContent);
		}elseif( substr($headTxt, 4, 4)==='ftyp' ){
			$readSet = self::readOfMp4($rawContent);
		}elseif( $rawContent!=='' ){
			$readSet = self::readOfMp3($rawContent);
		}
		if( $readSet===null || $readSet['infos']['secnd']<=0 || $readSet['infos']['rates']<=0 ){
			throw new \RuntimeException('Not a sound of a known kind');
		}
		$infoArr = $readSet['infos'];
		$infoArr['secnd'] = round($infoArr['secnd'], 3);
		$infoArr['bitrt'] = intval(round($infoArr['bitrt']));
		return new Sound($rawContent, Array('kinds'=>$infoArr['kinds'], 'secnd'=>$infoArr['secnd'], 'rates'=>intval($infoArr['rates']), 'chans'=>intval($infoArr['chans']), 'bitrt'=>$infoArr['bitrt'], 'bytes'=>strlen($rawContent), 'tagsl'=>self::cleanTags($readSet['tagsl'])), strval($readSet['cover'] ?? ''), (array)($readSet['cuts'] ?? Array()));
	}

	/**
	 * Reads a size written on four bytes of seven bits each, as the tags
	 * of MP3 sounds write theirs.
	 *
	 * @param String $packedSize The four bytes.
	 * @return Int The size.
	 */
	protected static function sizeOfTag(String $packedSize): Int {
		$byteLst = array_values(unpack('C4', str_pad($packedSize, 4, "\0")));
		return (($byteLst[0] & 0x7F)<<21) | (($byteLst[1] & 0x7F)<<14) | (($byteLst[2] & 0x7F)<<7) | ($byteLst[3] & 0x7F);
	}

	/**
	 * Tidies the tags read from a sound: each brought to UTF-8, without
	 * what cannot be shown, and no longer than a tag has reasons to be.
	 *
	 * @param Array $tagsValues The tags, as read.
	 * @return Array The title under 'title', the artist under 'artst', the album under 'album', the year under 'years', the number of the track under 'track' and the genre under 'genre'; each only when the sound tells it.
	 */
	protected static function cleanTags(Array $tagsValues): Array {
		$tagsLst = Array();
		foreach(Array('title', 'artst', 'album', 'years', 'track', 'genre') as $tagKey){
			$tagText = strval($tagsValues[$tagKey] ?? '');
			// A tag that is not UTF-8 is read as Windows-1252, then kept on one line
			if( !preg_match('//u', $tagText) ){
				$tagText = strval(@iconv('Windows-1252', 'UTF-8//IGNORE', $tagText));
			}
			$tagText = trim(preg_replace('/\s*[\x00-\x1F\x7F]+\s*/', ' ', $tagText));
			$tagPrts = Array();
			// A year is its four digits, a track its number, a genre told by its number is named
			if( $tagKey=='years' ){
				$tagText = preg_match('/\d{4}/', $tagText, $tagPrts) ? $tagPrts[0] : '';
			}elseif( $tagKey=='track' ){
				$tagText = preg_match('/^\d+/', $tagText, $tagPrts) ? strval(intval($tagPrts[0])) : '';
			}elseif( $tagKey=='genre' && preg_match('/^\(?(\d{1,3})\)?$/D', $tagText, $tagPrts) ){
				$tagText = strval(self::GENRLIST[intval($tagPrts[1])] ?? '');
			}
			if( $tagText!=='' ){
				$tagsLst[$tagKey] = function_exists('mb_substr') ? mb_substr($tagText, 0, self::TAGLIMIT, 'UTF-8') : substr($tagText, 0, self::TAGLIMIT);
			}
		}
		return $tagsLst;
	}

	/**
	 * Reads the texts of a tag of an MP3 sound, and the picture it holds.
	 *
	 * @param String $tagContent The tag, from its first byte.
	 * @return Array The tags under 'tagsl', the bytes of the picture under 'cover'.
	 */
	protected static function id3Frames(String $tagContent): Array {
		$tagsLst = Array();
		$covrTxt = '';
		$tagVers = ord($tagContent[3] ?? "\0");
		$tagBody = substr($tagContent, 10, self::sizeOfTag(substr($tagContent, 6, 4)));
		// A tag may be written so that nothing in it looks like the start of a frame of sound: it is brought back as it was
		if( (ord($tagContent[5] ?? "\0") & 0x80) && $tagVers<4 ){
			$tagBody = str_replace("\xFF\x00", "\xFF", $tagBody);
		}
		$nameLen = ($tagVers==2) ? 3 : 4;
		$headLen = ($tagVers==2) ? 6 : 10;
		$keysLst = Array('TIT2'=>'title', 'TT2'=>'title', 'TPE1'=>'artst', 'TP1'=>'artst', 'TALB'=>'album', 'TAL'=>'album', 'TDRC'=>'years', 'TYER'=>'years', 'TYE'=>'years', 'TRCK'=>'track', 'TRK'=>'track', 'TCON'=>'genre', 'TCO'=>'genre');
		$curPosn = 0;
		while( $curPosn+$headLen<=strlen($tagBody) && ord($tagBody[$curPosn])!=0 ){
			$frmName = substr($tagBody, $curPosn, $nameLen);
			if( $tagVers==2 ){
				$frmSize = (ord($tagBody[$curPosn+3])<<16) | (ord($tagBody[$curPosn+4])<<8) | ord($tagBody[$curPosn+5]);
			}else{
				$frmSize = ($tagVers==4) ? self::sizeOfTag(substr($tagBody, $curPosn+4, 4)) : unpack('N', substr($tagBody, $curPosn+4, 4))[1];
			}
			if( $frmSize<1 || $curPosn+$headLen+$frmSize>strlen($tagBody) ){
				break;
			}
			$frmBody = substr($tagBody, $curPosn+$headLen, $frmSize);
			$curPosn += $headLen+$frmSize;
			if( isset($keysLst[$frmName]) && !isset($tagsLst[$keysLst[$frmName]]) ){
				$tagsLst[$keysLst[$frmName]] = self::id3OfText(ord($frmBody[0]), substr($frmBody, 1));
			}elseif( ($frmName=='APIC' || $frmName=='PIC') && $covrTxt==='' ){
				// A picture: how its description is written, its kind, what it shows, its description, then its bytes
				$descEnc = ord($frmBody[0]);
				$afterAt = ($frmName=='PIC') ? 5 : intval(strpos($frmBody, "\0", 1))+2;
				$descEnd = ($descEnc==1 || $descEnc==2) ? self::wideZeros($frmBody, $afterAt) : strpos($frmBody, "\0", $afterAt);
				$covrTxt = ($descEnd===false) ? '' : substr($frmBody, $descEnd+(($descEnc==1 || $descEnc==2) ? 2 : 1));
			}
		}
		return Array('tagsl'=>$tagsLst, 'cover'=>$covrTxt);
	}

	/**
	 * Finds the end of a text written two bytes a character.
	 *
	 * @param String $textString The bytes holding the text.
	 * @param Int    $startsFrom Where the text starts.
	 * @return Int|Bool Where its ending pair of zero bytes stands, false when it has none.
	 */
	protected static function wideZeros(String $textString, Int $startsFrom): Int|Bool {
		for($chrIdx=$startsFrom; $chrIdx+1<strlen($textString); $chrIdx+=2){
			if( $textString[$chrIdx]==="\0" && $textString[$chrIdx+1]==="\0" ){
				return $chrIdx;
			}
		}
		return false;
	}

	/**
	 * Reads a text of a tag of an MP3 sound, whatever way it is written.
	 *
	 * @param Int    $encodingId How it is written: 0 for Latin-1, 1 for UTF-16 with its marker, 2 for UTF-16 without, 3 for UTF-8.
	 * @param String $textString The bytes of the text.
	 * @return String The text, in UTF-8, up to its first end.
	 */
	protected static function id3OfText(Int $encodingId, String $textString): String {
		if( $encodingId==1 || $encodingId==2 ){
			$fromEnc = ($encodingId==2) ? 'UTF-16BE' : ((substr($textString, 0, 2)==="\xFE\xFF") ? 'UTF-16BE' : 'UTF-16LE');
			$bareTxt = (in_array(substr($textString, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true)) ? substr($textString, 2) : $textString;
			$textString = strval(@iconv($fromEnc, 'UTF-8//IGNORE', substr($bareTxt, 0, strlen($bareTxt)-(strlen($bareTxt)%2))));
		}elseif( $encodingId==0 ){
			$textString = strval(@iconv('ISO-8859-1', 'UTF-8//IGNORE', $textString));
		}
		return explode("\0", $textString)[0];
	}

	/**
	 * Reads the comments of a FLAC or Ogg sound: lines of NAME=value.
	 *
	 * @param String $blockBytes The block holding them.
	 * @return Array The tags under 'tagsl', the bytes of the picture under 'cover'.
	 */
	protected static function vorbisTag(String $blockBytes): Array {
		$tagsLst = Array();
		$covrTxt = '';
		$keysLst = Array('TITLE'=>'title', 'ARTIST'=>'artst', 'ALBUM'=>'album', 'DATE'=>'years', 'YEAR'=>'years', 'TRACKNUMBER'=>'track', 'GENRE'=>'genre');
		$curPosn = 4+intval(unpack('V', str_pad(substr($blockBytes, 0, 4), 4, "\0"))[1]);
		$lineNbr = intval(unpack('V', str_pad(substr($blockBytes, $curPosn, 4), 4, "\0"))[1]);
		$curPosn += 4;
		for($lneIdx=0; $lneIdx<$lineNbr && $curPosn+4<=strlen($blockBytes); $lneIdx++){
			$lineLen = intval(unpack('V', substr($blockBytes, $curPosn, 4))[1]);
			$linePrt = explode('=', substr($blockBytes, $curPosn+4, $lineLen), 2);
			$curPosn += 4+$lineLen;
			$lineKey = strtoupper($linePrt[0]);
			if( isset($keysLst[$lineKey]) && !isset($tagsLst[$keysLst[$lineKey]]) ){
				$tagsLst[$keysLst[$lineKey]] = strval($linePrt[1] ?? '');
			}elseif( $lineKey=='METADATA_BLOCK_PICTURE' && $covrTxt==='' ){
				$covrTxt = self::flacPictr(strval(base64_decode(strval($linePrt[1] ?? ''), true)));
			}
		}
		return Array('tagsl'=>$tagsLst, 'cover'=>$covrTxt);
	}

	/**
	 * Gives the bytes of the picture a picture block of a FLAC sound holds.
	 *
	 * @param String $blockBytes The block: what the picture shows, its kind, its description, its size, then its bytes.
	 * @return String The bytes of the picture, '' when the block is not whole.
	 */
	protected static function flacPictr(String $blockBytes): String {
		if( strlen($blockBytes)<32 ){
			return '';
		}
		$curPosn = 8+intval(unpack('N', substr($blockBytes, 4, 4))[1]);
		$curPosn += 4+intval(unpack('N', str_pad(substr($blockBytes, $curPosn, 4), 4, "\0"))[1])+16;
		$pictLen = intval(unpack('N', str_pad(substr($blockBytes, $curPosn, 4), 4, "\0"))[1]);
		return strval(substr($blockBytes, $curPosn+4, $pictLen));
	}

	/**
	 * Reads a WAV sound: the parts it is made of, each named on four
	 * letters and telling its size.
	 *
	 * @param String $rawContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to cut it under 'cuts'; null when it is not a whole WAV sound.
	 */
	protected static function readOfWav(String $rawContent): ?Array {
		$fmtBody = '';
		$dataPos = -1;
		$dataLen = 0;
		$tagsLst = Array();
		$covrTxt = '';
		$curPosn = 12;
		while( $curPosn+8<=strlen($rawContent) ){
			$partNme = substr($rawContent, $curPosn, 4);
			$partLen = intval(unpack('V', substr($rawContent, $curPosn+4, 4))[1]);
			if( $partNme==='fmt ' ){
				$fmtBody = substr($rawContent, $curPosn+8, $partLen);
			}elseif( $partNme==='data' && $dataPos<0 ){
				$dataPos = $curPosn+8;
				$dataLen = min($partLen, strlen($rawContent)-$dataPos);
			}elseif( $partNme==='LIST' && substr($rawContent, $curPosn+8, 4)==='INFO' ){
				// The tags of a WAV sound: each named on four letters too
				$infoKys = Array('INAM'=>'title', 'IART'=>'artst', 'IPRD'=>'album', 'ICRD'=>'years', 'ITRK'=>'track', 'IGNR'=>'genre');
				$infoPos = $curPosn+12;
				while( $infoPos+8<=min($curPosn+8+$partLen, strlen($rawContent)) ){
					$infoLen = intval(unpack('V', substr($rawContent, $infoPos+4, 4))[1]);
					if( isset($infoKys[substr($rawContent, $infoPos, 4)]) ){
						$tagsLst[$infoKys[substr($rawContent, $infoPos, 4)]] = rtrim(substr($rawContent, $infoPos+8, $infoLen), "\0");
					}
					$infoPos += 8+$infoLen+($infoLen%2);
				}
			}elseif( strtolower($partNme)==='id3 ' ){
				$id3Tags = self::id3Frames(substr($rawContent, $curPosn+8, $partLen));
				$tagsLst = $tagsLst + $id3Tags['tagsl'];
				$covrTxt = $id3Tags['cover'];
			}
			// A part of odd size is followed by a byte that only fills
			$curPosn += 8+$partLen+($partLen%2);
		}
		if( strlen($fmtBody)<16 || $dataPos<0 ){
			return null;
		}
		$fmtVals = unpack('vkind/vchans/Vrates/Vbytes/valign/vbits', $fmtBody);
		if( $fmtVals['bytes']<1 ){
			return null;
		}
		return Array(
			'infos'=>Array('kinds'=>'wav', 'secnd'=>$dataLen/$fmtVals['bytes'], 'rates'=>$fmtVals['rates'], 'chans'=>$fmtVals['chans'], 'bitrt'=>$fmtVals['bytes']*8/1000),
			'tagsl'=>$tagsLst, 'cover'=>$covrTxt,
			'cuts'=>Array('start'=>$dataPos, 'bytes'=>$dataLen, 'align'=>max(1, $fmtVals['align']), 'persc'=>$fmtVals['bytes'], 'headr'=>$fmtBody)
		);
	}

	/**
	 * Reads the frames an MP3 sound is made of, from where it starts.
	 *
	 * @param String $rawContent The bytes of the sound.
	 * @param Int    $startsFrom Where to look for its first frame.
	 * @param Int    $endsBefore Where what is heard ends.
	 * @return Array The frames, each with where it stands, its length, the samples it holds, its sample rate, its channels and its bit rate.
	 */
	protected static function mp3Frames(String $rawContent, Int $startsFrom, Int $endsBefore): Array {
		$frmeLst = Array();
		$curPosn = $startsFrom;
		// What stands before the first frame is passed over, within reason
		$scanEnd = min($endsBefore-4, $startsFrom+65536);
		while( $curPosn+4<=$endsBefore ){
			$headVal = unpack('N', substr($rawContent, $curPosn, 4))[1];
			$versBit = ($headVal>>19) & 3;
			$layrBit = ($headVal>>17) & 3;
			$rateIdx = ($headVal>>12) & 15;
			$hrtzIdx = ($headVal>>10) & 3;
			$frmeOky = (($headVal & 0xFFE00000)==0xFFE00000 && $versBit!=1 && $layrBit!=0 && $rateIdx>0 && $rateIdx<15 && $hrtzIdx<3);
			$frmeLen = 0;
			if( $frmeOky ){
				$layrNbr = 4-$layrBit;
				$bitRate = self::MP3RATES[(($versBit==3) ? '1' : '2').$layrNbr][$rateIdx];
				$smplRte = self::MP3HERTZ[$versBit][$hrtzIdx];
				$padByte = ($headVal>>9) & 1;
				$smplNbr = ($layrNbr==1) ? 384 : (($layrNbr==3 && $versBit!=3) ? 576 : 1152);
				$frmeLen = ($layrNbr==1) ? (intdiv(12000*$bitRate, $smplRte)+$padByte)*4 : intdiv(intdiv($smplNbr, 8)*1000*$bitRate, $smplRte)+$padByte;
			}
			// A first frame is one when the next stands where it ends
			$nextOky = ($curPosn+$frmeLen+2<=$endsBefore) && (ord($rawContent[$curPosn+$frmeLen])==0xFF && (ord($rawContent[$curPosn+$frmeLen+1]) & 0xE0)==0xE0);
			if( !$frmeOky || $frmeLen<24 || (!$nextOky && empty($frmeLst)) ){
				if( !empty($frmeLst) || $curPosn>=$scanEnd ){
					break;
				}
				$curPosn++;
				continue;
			}
			if( $curPosn+$frmeLen>$endsBefore && !empty($frmeLst) ){
				break;
			}
			$frmeLst[] = Array('start'=>$curPosn, 'bytes'=>$frmeLen, 'smpls'=>$smplNbr, 'rates'=>$smplRte, 'chans'=>((($headVal>>6) & 3)==3) ? 1 : 2, 'bitrt'=>$bitRate);
			$curPosn += $frmeLen;
		}
		// A first frame may only tell how the sound is laid out, for the players: it holds nothing heard
		if( !empty($frmeLst) && preg_match('/Xing|Info|VBRI/', substr($rawContent, $frmeLst[0]['start']+4, 48)) ){
			array_shift($frmeLst);
		}
		return $frmeLst;
	}

	/**
	 * Reads an MP3 sound: the tags standing before and after it, and the
	 * frames it is made of.
	 *
	 * @param String $rawContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to cut it under 'cuts'; null when no frame is found.
	 */
	protected static function readOfMp3(String $rawContent): ?Array {
		$tagsLst = Array();
		$covrTxt = '';
		$bodyPos = 0;
		while( substr($rawContent, $bodyPos, 3)==='ID3' && strlen($rawContent)>=$bodyPos+10 ){
			$tagSize = 10+self::sizeOfTag(substr($rawContent, $bodyPos+6, 4));
			$id3Tags = self::id3Frames(substr($rawContent, $bodyPos, $tagSize));
			$tagsLst = $tagsLst + $id3Tags['tagsl'];
			$covrTxt = ($covrTxt==='') ? $id3Tags['cover'] : $covrTxt;
			$bodyPos += $tagSize;
		}
		// The first kind of tag stands at the very end, on 128 bytes
		$bodyEnd = strlen($rawContent);
		if( $bodyEnd>=128 && substr($rawContent, -128, 3)==='TAG' ){
			$lastTag = substr($rawContent, -128);
			$bodyEnd -= 128;
			$tagsLst = $tagsLst + array_filter(Array(
				'title'=>rtrim(substr($lastTag, 3, 30), "\0 "), 'artst'=>rtrim(substr($lastTag, 33, 30), "\0 "), 'album'=>rtrim(substr($lastTag, 63, 30), "\0 "), 'years'=>rtrim(substr($lastTag, 93, 4), "\0 "),
				'track'=>(ord($lastTag[125])==0 && ord($lastTag[126])>0) ? strval(ord($lastTag[126])) : '', 'genre'=>(ord($lastTag[127])<80) ? '('.ord($lastTag[127]).')' : ''
			), 'strlen');
			foreach(Array('title', 'artst', 'album') as $tagKey){
				if( isset($tagsLst[$tagKey]) && !preg_match('//u', $tagsLst[$tagKey]) ){
					$tagsLst[$tagKey] = strval(@iconv('ISO-8859-1', 'UTF-8//IGNORE', $tagsLst[$tagKey]));
				}
			}
		}
		$frmeLst = self::mp3Frames($rawContent, $bodyPos, $bodyEnd);
		if( empty($frmeLst) ){
			return null;
		}
		$smplNbr = array_sum(array_column($frmeLst, 'smpls'));
		$byteNbr = array_sum(array_column($frmeLst, 'bytes'));
		$secsNbr = $smplNbr/$frmeLst[0]['rates'];
		return Array(
			'infos'=>Array('kinds'=>'mp3', 'secnd'=>$secsNbr, 'rates'=>$frmeLst[0]['rates'], 'chans'=>$frmeLst[0]['chans'], 'bitrt'=>$byteNbr*8/1000/$secsNbr),
			'tagsl'=>$tagsLst, 'cover'=>$covrTxt, 'cuts'=>Array('frmes'=>$frmeLst)
		);
	}

	/**
	 * Reads a FLAC sound: the blocks standing before what is heard.
	 *
	 * @param String $rawContent The bytes of the sound.
	 * @param Int    $startsFrom Where the sound starts, past a tag that may stand before it.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to give it back without its tags under 'cuts'; null when it is not a whole FLAC sound.
	 */
	protected static function readOfFla(String $rawContent, Int $startsFrom): ?Array {
		$tagsLst = Array();
		$covrTxt = '';
		$infoBlk = '';
		$keptBlk = Array();
		$curPosn = $startsFrom+4;
		do{
			if( $curPosn+4>strlen($rawContent) ){
				return null;
			}
			$blckKnd = ord($rawContent[$curPosn]) & 0x7F;
			$isLastB = (ord($rawContent[$curPosn]) & 0x80)!=0;
			$blckLen = (ord($rawContent[$curPosn+1])<<16) | (ord($rawContent[$curPosn+2])<<8) | ord($rawContent[$curPosn+3]);
			$blckTxt = substr($rawContent, $curPosn+4, $blckLen);
			$curPosn += 4+$blckLen;
			if( $blckKnd==0 ){
				$infoBlk = $blckTxt;
			}elseif( $blckKnd==4 ){
				$cmntSet = self::vorbisTag($blckTxt);
				$tagsLst = $tagsLst + $cmntSet['tagsl'];
				$covrTxt = ($covrTxt==='') ? $cmntSet['cover'] : $covrTxt;
			}elseif( $blckKnd==6 && $covrTxt==='' ){
				$covrTxt = self::flacPictr($blckTxt);
			}
			// What a sound cannot do without, and what lets a player move in it, are kept when its tags are taken off
			if( $blckKnd==0 || $blckKnd==3 ){
				$keptBlk[] = Array($blckKnd, $blckTxt);
			}
		}while( !$isLastB );
		if( strlen($infoBlk)<18 ){
			return null;
		}
		// The sample rate on 20 bits, the channels on 3, the bits of a sample on 5, the number of samples on 36
		$packVal = unpack('J', substr($infoBlk, 10, 8))[1];
		$smplRte = ($packVal>>44) & 0xFFFFF;
		$smplNbr = $packVal & 0xFFFFFFFFF;
		if( $smplRte<1 || $smplNbr<1 ){
			return null;
		}
		return Array(
			'infos'=>Array('kinds'=>'flac', 'secnd'=>$smplNbr/$smplRte, 'rates'=>$smplRte, 'chans'=>(($packVal>>41) & 7)+1, 'bitrt'=>(strlen($rawContent)-$curPosn)*8/1000/($smplNbr/$smplRte)),
			'tagsl'=>$tagsLst, 'cover'=>$covrTxt, 'cuts'=>Array('start'=>$curPosn, 'blcks'=>$keptBlk)
		);
	}

	/**
	 * Reads an Ogg sound, Vorbis or Opus: its first two packets tell
	 * what it is and carry its tags, its last page tells how long it is.
	 *
	 * @param String $rawContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl' and its picture under 'cover'; null when it is not a sound this reads.
	 */
	protected static function readOfOgg(String $rawContent): ?Array {
		// A packet is written over the pieces of one or several pages: a piece shorter than 255 bytes ends it
		$packLst = Array();
		$packBuf = '';
		$curPosn = 0;
		while( count($packLst)<2 && substr($rawContent, $curPosn, 4)==='OggS' && $curPosn+27<=strlen($rawContent) ){
			$segmNbr = ord($rawContent[$curPosn+26]);
			$segmLst = array_values(unpack('C*', substr($rawContent, $curPosn+27, $segmNbr)));
			$curPosn += 27+$segmNbr;
			foreach($segmLst as $segLen){
				$packBuf .= substr($rawContent, $curPosn, $segLen);
				$curPosn += $segLen;
				if( $segLen<255 ){
					$packLst[] = $packBuf;
					$packBuf = '';
				}
			}
			if( strlen($packBuf)>self::MAXBYTES ){
				return null;
			}
		}
		$headPck = strval($packLst[0] ?? '');
		$skipSmp = 0;
		if( substr($headPck, 0, 7)==="\x01vorbis" && strlen($headPck)>=16 ){
			$chanNbr = ord($headPck[11]);
			$smplRte = intval(unpack('V', substr($headPck, 12, 4))[1]);
			$cmntSet = self::vorbisTag(substr(strval($packLst[1] ?? ''), 7));
		}elseif( substr($headPck, 0, 8)==='OpusHead' && strlen($headPck)>=12 ){
			// An Opus sound is always played at 48000 samples a second, after the samples it asks to be passed over
			$chanNbr = ord($headPck[9]);
			$skipSmp = intval(unpack('v', substr($headPck, 10, 2))[1]);
			$smplRte = 48000;
			$cmntSet = self::vorbisTag(substr(strval($packLst[1] ?? ''), 8));
		}else{
			return null;
		}
		// The last page tells the number of the last sample
		$lastPos = strrpos($rawContent, 'OggS');
		if( $lastPos===false || $lastPos+14>strlen($rawContent) || $smplRte<1 ){
			return null;
		}
		$smplNbr = max(0, intval(unpack('P', substr($rawContent, $lastPos+6, 8))[1])-$skipSmp);
		if( $smplNbr<1 ){
			return null;
		}
		return Array('infos'=>Array('kinds'=>'ogg', 'secnd'=>$smplNbr/$smplRte, 'rates'=>$smplRte, 'chans'=>$chanNbr, 'bitrt'=>strlen($rawContent)*8/1000/($smplNbr/$smplRte)), 'tagsl'=>$cmntSet['tagsl'], 'cover'=>$cmntSet['cover']);
	}

	/**
	 * Lists the boxes standing in a part of an MP4 file.
	 *
	 * @param String $rawContent The bytes of the file.
	 * @param Int    $startsFrom Where the part starts.
	 * @param Int    $endsBefore Where it ends.
	 * @return Array The boxes, each with its name, where what it holds starts, and where it ends.
	 */
	protected static function boxesOfIt(String $rawContent, Int $startsFrom, Int $endsBefore): Array {
		$boxLst = Array();
		$curPosn = $startsFrom;
		while( $curPosn+8<=$endsBefore ){
			$boxSize = intval(unpack('N', substr($rawContent, $curPosn, 4))[1]);
			$headLen = 8;
			// A box too large for four bytes tells its size on eight, after its name; one telling none runs to the end
			if( $boxSize==1 && $curPosn+16<=$endsBefore ){
				$boxSize = intval(unpack('J', substr($rawContent, $curPosn+8, 8))[1]);
				$headLen = 16;
			}elseif( $boxSize==0 ){
				$boxSize = $endsBefore-$curPosn;
			}
			if( $boxSize<$headLen || $curPosn+$boxSize>$endsBefore ){
				break;
			}
			$boxLst[] = Array(substr($rawContent, $curPosn+4, 4), $curPosn+$headLen, $curPosn+$boxSize);
			$curPosn += $boxSize;
		}
		return $boxLst;
	}

	/**
	 * Finds a box by the names leading to it.
	 *
	 * @param String $rawContent The bytes of the file.
	 * @param Array  $parentsBox The box to look in: its name, where what it holds starts, where it ends.
	 * @param Array  $boxesNames The names to follow, one per level.
	 * @return ?Array The box reached, null when the names lead nowhere.
	 */
	protected static function findBoxes(String $rawContent, Array $parentsBox, Array $boxesNames): ?Array {
		foreach($boxesNames as $boxNme){
			$foundBx = null;
			// The box of the tags starts with four bytes that are not a box
			foreach(self::boxesOfIt($rawContent, $parentsBox[1]+(($parentsBox[0]==='meta') ? 4 : 0), $parentsBox[2]) as $oneBox){
				if( $oneBox[0]===$boxNme ){
					$foundBx = $oneBox;
					break;
				}
			}
			if( $foundBx===null ){
				return null;
			}
			$parentsBox = $foundBx;
		}
		return $parentsBox;
	}

	/**
	 * Reads an MP4 sound (m4a): a file made of boxes, one of which
	 * describes its tracks. A file holding moving pictures is not a sound.
	 *
	 * @param String $rawContent The bytes of the file.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl' and its picture under 'cover'; null when it is not a sound.
	 */
	protected static function readOfMp4(String $rawContent): ?Array {
		$fileBox = Array('', 0, strlen($rawContent));
		$moovBox = self::findBoxes($rawContent, $fileBox, Array('moov'));
		$headBox = ($moovBox===null) ? null : self::findBoxes($rawContent, $moovBox, Array('mvhd'));
		if( $headBox===null ){
			return null;
		}
		// The length of the whole, counted in the unit the file tells: both on eight bytes in the later way of writing them
		$headTxt = substr($rawContent, $headBox[1], $headBox[2]-$headBox[1]);
		$timeSet = (ord($headTxt[0] ?? "\0")==1) ? unpack('Nunit/Jlong', str_pad(substr($headTxt, 20, 12), 12, "\0")) : unpack('Nunit/Nlong', str_pad(substr($headTxt, 12, 8), 8, "\0"));
		$smplRte = 0;
		$chanNbr = 0;
		foreach(self::boxesOfIt($rawContent, $moovBox[1], $moovBox[2]) as $trkBox){
			$hndlBox = ($trkBox[0]==='trak') ? self::findBoxes($rawContent, $trkBox, Array('mdia', 'hdlr')) : null;
			$trakKnd = ($hndlBox===null) ? '' : substr($rawContent, $hndlBox[1]+8, 4);
			if( $trakKnd==='vide' ){
				return null;
			}
			$descBox = ($trakKnd==='soun') ? self::findBoxes($rawContent, $trkBox, Array('mdia', 'minf', 'stbl', 'stsd')) : null;
			if( $descBox!==null && $smplRte==0 ){
				// The description of the sound: its channels, then its sample rate as a whole part and a part of one
				$descTxt = substr($rawContent, $descBox[1]+16, 28);
				$chanNbr = intval(unpack('n', str_pad(substr($descTxt, 16, 2), 2, "\0"))[1]);
				$smplRte = intval(unpack('N', str_pad(substr($descTxt, 24, 4), 4, "\0"))[1])>>16;
			}
		}
		if( $smplRte<1 || $timeSet['unit']<1 || $timeSet['long']<1 ){
			return null;
		}
		$tagsLst = Array();
		$covrTxt = '';
		$listBox = self::findBoxes($rawContent, $moovBox, Array('udta', 'meta', 'ilst'));
		$keysLst = Array("\xA9nam"=>'title', "\xA9ART"=>'artst', "\xA9alb"=>'album', "\xA9day"=>'years', "\xA9gen"=>'genre', 'trkn'=>'track', 'gnre'=>'genre', 'covr'=>'cover');
		foreach(($listBox===null) ? Array() : self::boxesOfIt($rawContent, $listBox[1], $listBox[2]) as $itmBox){
			$dataBox = isset($keysLst[$itmBox[0]]) ? self::findBoxes($rawContent, $itmBox, Array('data')) : null;
			if( $dataBox===null ){
				continue;
			}
			// What a tag holds stands after eight bytes telling its kind
			$dataTxt = strval(substr($rawContent, $dataBox[1]+8, $dataBox[2]-$dataBox[1]-8));
			if( $itmBox[0]==='covr' ){
				$covrTxt = $dataTxt;
			}elseif( $itmBox[0]==='trkn' ){
				$tagsLst['track'] = strval(unpack('n', str_pad(substr($dataTxt, 2, 2), 2, "\0"))[1]);
			}elseif( $itmBox[0]==='gnre' ){
				$tagsLst['genre'] = '('.(intval(unpack('n', str_pad(substr($dataTxt, 0, 2), 2, "\0"))[1])-1).')';
			}else{
				$tagsLst[$keysLst[$itmBox[0]]] = $dataTxt;
			}
		}
		$secsNbr = $timeSet['long']/$timeSet['unit'];
		return Array('infos'=>Array('kinds'=>'m4a', 'secnd'=>$secsNbr, 'rates'=>$smplRte, 'chans'=>$chanNbr, 'bitrt'=>strlen($rawContent)*8/1000/$secsNbr), 'tagsl'=>$tagsLst, 'cover'=>$covrTxt);
	}

	/**
	 * Tells what the sound is.
	 *
	 * @return Array Its kind under 'kinds' (wav, mp3, ogg, flac or m4a), how long it lasts in seconds under 'secnd', its samples a second under 'rates', its channels under 'chans', its thousands of bits a second under 'bitrt', its size under 'bytes', and its tags under 'tagsl'.
	 */
	public function soundInfo(): Array {
		return $this->infoList;
	}

	/**
	 * Gives the picture the sound carries, such as the cover of its album.
	 *
	 * @param Int $pixelLimit Greatest number of pixels accepted for it.
	 * @return ?Image The picture, null when the sound carries none, or carries what is not a picture.
	 */
	public function giveCover(Int $pixelLimit=Image::MAXPIXEL): ?Image {
		if( $this->coverPic==='' ){
			return null;
		}
		try{
			return Image::openFluxd(Fluxd::openBytes($this->coverPic), $pixelLimit);
		}catch( \RuntimeException $e ){
			return null;
		}
	}

	/**
	 * Gives the sound without its tags nor its picture, what is heard
	 * being left as it is written.
	 *
	 * @param String $soundKinds Kind to give: the kind of the sound when left out. Another kind takes a program that unpacks the sound.
	 * @param Int    $bitrateKbs Thousands of bits a second to pack it with, for the kinds that lose some of the sound; left to the kind when 0. Asking for one packs the sound again.
	 * @return String The bytes.
	 *
	 * @throws \RuntimeException When the kind is unknown, or what is asked takes a program that unpacks sounds and the server has none.
	 */
	public function giveBytes(String $soundKinds='', Int $bitrateKbs=0): String {
		$kindKey = strtolower(($soundKinds==='') ? $this->infoList['kinds'] : $soundKinds);
		if( !isset(Codec::KINDLIST[$kindKey]) ){
			throw new \RuntimeException('Unknown kind of sound: '.substr(preg_replace('/[^a-z0-9]+/', '', $kindKey), 0, 10));
		}
		if( ($kindKey!==$this->infoList['kinds'] || $bitrateKbs>0) && self::hasACodec() ){
			return self::$theCodec->changeSnd($this->rawBytes, $this->infoList['kinds'], $kindKey, Array('kbits'=>$bitrateKbs));
		}
		if( $kindKey!==$this->infoList['kinds'] ){
			throw new \RuntimeException('Giving a sound as another kind takes a program that unpacks it, which this server does not have');
		}
		if( $kindKey=='wav' ){
			return $this->wavOfPart($this->cutsData['start'], $this->cutsData['bytes']);
		}
		if( $kindKey=='mp3' ){
			$frmeLst = $this->cutsData['frmes'];
			$lastFrm = $frmeLst[count($frmeLst)-1];
			return substr($this->rawBytes, $frmeLst[0]['start'], $lastFrm['start']+$lastFrm['bytes']-$frmeLst[0]['start']);
		}
		if( $kindKey=='flac' ){
			// The blocks kept, the last of them marked as such, then what is heard
			$headTxt = 'fLaC';
			foreach($this->cutsData['blcks'] as $blkIdx=>$blkSet){
				$headTxt .= chr($blkSet[0] | (($blkIdx==count($this->cutsData['blcks'])-1) ? 0x80 : 0)).substr(pack('N', strlen($blkSet[1])), 1).$blkSet[1];
			}
			return $headTxt.substr($this->rawBytes, $this->cutsData['start']);
		}
		if( self::hasACodec() ){
			return self::$theCodec->changeSnd($this->rawBytes, $kindKey, $kindKey);
		}
		throw new \RuntimeException('The tags of a sound of this kind cannot be taken off as it is written: '.$kindKey);
	}

	/**
	 * Makes the same sound of another kind, without its tags.
	 *
	 * @param String $soundKinds The kind to make: wav, mp3, flac, ogg or m4a.
	 * @param Int    $bitrateKbs Thousands of bits a second to pack it with; left to the kind when 0.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When the kind is unknown, or the server has no program that unpacks sounds.
	 */
	public function convertTo(String $soundKinds, Int $bitrateKbs=0): Sound {
		return self::fromBytes($this->giveBytes($soundKinds, $bitrateKbs));
	}
	
	/**
	 * Writes a WAV sound from a part of what is heard in this one.
	 *
	 * @param Int $startsFrom Where the part starts in the bytes of the sound.
	 * @param Int $bytesCount Its length.
	 * @return String The bytes of the sound.
	 */
	protected function wavOfPart(Int $startsFrom, Int $bytesCount): String {
		$fmtBody = $this->cutsData['headr'];
		$dataTxt = strval(substr($this->rawBytes, $startsFrom, $bytesCount));
		$bodyTxt = 'WAVE'.'fmt '.pack('V', strlen($fmtBody)).$fmtBody.((strlen($fmtBody)%2) ? "\0" : '').'data'.pack('V', strlen($dataTxt)).$dataTxt.((strlen($dataTxt)%2) ? "\0" : '');
		return 'RIFF'.pack('V', strlen($bodyTxt)).$bodyTxt;
	}

	/**
	 * Makes a sound of a part of this one, without its tags. The part is
	 * cut where the sound lets it be: at a sample of a WAV sound, at the
	 * frame of an MP3 one, some hundredths of a second long.
	 *
	 * @param Float $startSecnd Where the part starts, in seconds from the start of the sound.
	 * @param Float $untilSecnd Where it ends, in seconds; the end of the sound when it is past it.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When the part holds nothing, or the sound is of a kind that cannot be cut as it is written and the server has no program that unpacks sounds.
	 */
	public function trimSound(Float $startSecnd, Float $untilSecnd): Sound {
		$fromSec = max(0.0, $startSecnd);
		$tillSec = min($untilSecnd, $this->infoList['secnd']);
		if( $tillSec<=$fromSec ){
			throw new \RuntimeException('The part asked for holds nothing of the sound');
		}
		if( $this->infoList['kinds']=='wav' ){
			// A sample of every channel is cut whole
			$alignBy = $this->cutsData['align'];
			$fromByt = intdiv(intval(round($fromSec*$this->cutsData['persc'])), $alignBy)*$alignBy;
			$tillByt = min($this->cutsData['bytes'], intdiv(intval(round($tillSec*$this->cutsData['persc'])), $alignBy)*$alignBy);
			if( $tillByt<=$fromByt ){
				throw new \RuntimeException('The part asked for holds nothing of the sound');
			}
			return self::fromBytes($this->wavOfPart($this->cutsData['start']+$fromByt, $tillByt-$fromByt));
		}
		if( $this->infoList['kinds']=='mp3' ){
			// The frames starting inside the part are kept, as they are
			$partTxt = '';
			$timeNow = 0.0;
			foreach($this->cutsData['frmes'] as $frmSet){
				if( $timeNow>=$fromSec && $timeNow<$tillSec ){
					$partTxt .= substr($this->rawBytes, $frmSet['start'], $frmSet['bytes']);
				}
				$timeNow += $frmSet['smpls']/$frmSet['rates'];
			}
			// A sound is told from two frames following each other
			if( strlen($partTxt)<=$this->cutsData['frmes'][0]['bytes']+1 ){
				throw new \RuntimeException('The part asked for holds nothing of the sound');
			}
			return self::fromBytes($partTxt);
		}
		if( self::hasACodec() ){
			return self::fromBytes(self::$theCodec->changeSnd($this->rawBytes, $this->infoList['kinds'], $this->infoList['kinds'], Array('start'=>$fromSec, 'until'=>$tillSec)));
		}
		throw new \RuntimeException('Cutting a sound of this kind takes a program that unpacks it, which this server does not have: '.$this->infoList['kinds']);
	}

	/**
	 * Makes a WAV sound of this one with other samples a second, other
	 * channels or other bits a sample, without a program that unpacks
	 * sounds. A sound of another kind is first given as WAV, which takes
	 * that program.
	 *
	 * Samples a second are changed by drawing a line between two samples:
	 * good for voices and for making a sound lighter, not as fine as what
	 * the program does when a sound is brought to fewer samples a second.
	 *
	 * @param Int $ratesHertz Samples a second to give it, from 8000 to 192000; its own when 0.
	 * @param Int $chansCount Channels to give it, 1 or 2; its own when 0.
	 * @param Int $sampleBits Bits a sample to give it: 8, 16, 24 or 32; its own when 0.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When a value asked for is not one of those accepted, or the sound is packed in a way that is not read here.
	 */
	public function changeWav(Int $ratesHertz=0, Int $chansCount=0, Int $sampleBits=0): Sound {
		if( $this->infoList['kinds']!='wav' ){
			return $this->convertTo('wav')->changeWav($ratesHertz, $chansCount, $sampleBits);
		}
		$fmtVals = unpack('vkind/vchans/Vrates/Vbytes/valign/vbits', $this->cutsData['headr']);
		// Samples written as whole numbers, told so directly or in the longer way of telling it
		$isWhole = ($fmtVals['kind']==1) || ($fmtVals['kind']==0xFFFE && substr($this->cutsData['headr'], 24, 2)==="\x01\x00");
		$fromBit = $fmtVals['bits'];
		$fromChn = $fmtVals['chans'];
		$fromRte = $fmtVals['rates'];
		$frmeLen = $fmtVals['align'];
		if( !$isWhole || !in_array($fromBit, Array(8, 16, 24, 32)) || $fromChn<1 || $fromRte<1 || $frmeLen!=$fromChn*intdiv($fromBit, 8) ){
			throw new \RuntimeException('A WAV sound packed this way is not changed without a program that unpacks sounds');
		}
		$intoRte = ($ratesHertz==0) ? $fromRte : $ratesHertz;
		$intoChn = ($chansCount==0) ? $fromChn : $chansCount;
		$intoBit = ($sampleBits==0) ? $fromBit : $sampleBits;
		if( $intoRte<8000 || $intoRte>192000 ){
			throw new \RuntimeException('Samples a second out of the 8000 to 192000 accepted: '.$intoRte);
		}
		if( $intoChn!=$fromChn && $intoChn!=1 && $intoChn!=2 ){
			throw new \RuntimeException('Channels other than the 1 or 2 accepted: '.$intoChn);
		}
		if( !in_array($intoBit, Array(8, 16, 24, 32)) ){
			throw new \RuntimeException('Bits a sample other than the 8, 16, 24 or 32 accepted: '.$intoBit);
		}
		$fromNbr = intdiv($this->cutsData['bytes'], $frmeLen);
		$intoNbr = intdiv($fromNbr*$intoRte, $fromRte);
		if( $intoNbr<1 ){
			throw new \RuntimeException('The sound holds nothing to change');
		}
		$scaleBy = 2**($intoBit-$fromBit);
		$topsVal = 2**($intoBit-1)-1;
		$maskVal = 2**$intoBit-1;
		$dataTxt = '';
		// The sound is made a block at a time, for long sounds not to take the memory samples by samples would
		for($blkPos=0; $blkPos<$intoNbr; $blkPos+=8192){
			$blckEnd = min($intoNbr, $blkPos+8192);
			$readPos = intdiv($blkPos*$fromRte, $intoRte);
			$readEnd = min($fromNbr-1, intdiv(($blckEnd-1)*$fromRte, $intoRte)+1);
			$readLst = $this->wavValues($this->cutsData['start']+$readPos*$frmeLen, ($readEnd-$readPos+1)*$frmeLen, $fromBit);
			$madeLst = Array();
			for($smpIdx=$blkPos; $smpIdx<$blckEnd; $smpIdx++){
				// Where the sample made stands between two samples read
				$spotVal = $smpIdx*$fromRte/$intoRte;
				$spotInt = intval(floor($spotVal));
				$spotPrt = $spotVal-$spotInt;
				$atFirst = ($spotInt-$readPos)*$fromChn;
				$atAfter = (min($spotInt+1, $readEnd)-$readPos)*$fromChn;
				$chanLst = Array();
				for($chnIdx=0; $chnIdx<$fromChn; $chnIdx++){
					$chanLst[] = $readLst[$atFirst+$chnIdx]+($readLst[$atAfter+$chnIdx]-$readLst[$atFirst+$chnIdx])*$spotPrt;
				}
				// One channel is the middle of all, two of one are twice the same, two of more are the first two
				if( $intoChn==1 && $fromChn>1 ){
					$chanLst = Array(array_sum($chanLst)/$fromChn);
				}elseif( $intoChn==2 && $fromChn==1 ){
					$chanLst[] = $chanLst[0];
				}elseif( $intoChn==2 && $fromChn>2 ){
					$chanLst = Array($chanLst[0], $chanLst[1]);
				}
				foreach($chanLst as $chnVal){
					$madeVal = max(-$topsVal-1, min($topsVal, intval(round($chnVal*$scaleBy))));
					// Samples of 8 bits are written from 0 to 255, the others around 0
					$madeLst[] = ($intoBit==8) ? $madeVal+128 : ($madeVal & $maskVal);
				}
			}
			if( $intoBit==24 ){
				foreach($madeLst as $oneVal){
					$dataTxt .= chr($oneVal & 0xFF).chr(($oneVal>>8) & 0xFF).chr(($oneVal>>16) & 0xFF);
				}
			}else{
				$dataTxt .= pack(Array(8=>'C*', 16=>'v*', 32=>'V*')[$intoBit], ...$madeLst);
			}
		}
		$intoLen = $intoChn*intdiv($intoBit, 8);
		$bodyTxt = 'WAVE'.'fmt '.pack('V', 16).pack('vvVVvv', 1, $intoChn, $intoRte, $intoRte*$intoLen, $intoLen, $intoBit).'data'.pack('V', strlen($dataTxt)).$dataTxt.((strlen($dataTxt)%2) ? "\0" : '');
		return self::fromBytes('RIFF'.pack('V', strlen($bodyTxt)).$bodyTxt);
	}

	/**
	 * Reads samples of a WAV sound as whole numbers around 0.
	 *
	 * @param Int $startsFrom Where the samples start in the bytes of the sound.
	 * @param Int $bytesCount Their length.
	 * @param Int $sampleBits The bits of a sample: 8, 16, 24 or 32.
	 * @return Array The samples, one after the other, channel after channel.
	 */
	protected function wavValues(Int $startsFrom, Int $bytesCount, Int $sampleBits): Array {
		$partTxt = substr($this->rawBytes, $startsFrom, $bytesCount);
		$readLst = Array();
		if( $sampleBits==24 ){
			for($bytIdx=0; $bytIdx+3<=strlen($partTxt); $bytIdx+=3){
				$readVal = ord($partTxt[$bytIdx]) | (ord($partTxt[$bytIdx+1])<<8) | (ord($partTxt[$bytIdx+2])<<16);
				$readLst[] = ($readVal>=0x800000) ? $readVal-0x1000000 : $readVal;
			}
			return $readLst;
		}
		$halfVal = 2**($sampleBits-1);
		foreach(unpack(Array(8=>'C*', 16=>'v*', 32=>'V*')[$sampleBits], $partTxt) as $rawVal){
			$readLst[] = ($sampleBits==8) ? $rawVal-128 : (($rawVal>=$halfVal) ? $rawVal-2*$halfVal : $rawVal);
		}
		return $readLst;
	}

	/**
	 * Writes the sound to a file, without its tags.
	 *
	 * @param String $targetPath Path of the file to write.
	 * @param String $soundKinds Kind to write; the kind of the sound when left out.
	 * @param Int    $bitrateKbs Thousands of bits a second to pack it with; left to the kind when 0.
	 * @return String The path of the file.
	 *
	 * @throws \RuntimeException When the file cannot be written, or the sound cannot be given as asked.
	 */
	public function saveLocal(String $targetPath, String $soundKinds='', Int $bitrateKbs=0): String {
		if( @file_put_contents($targetPath, $this->giveBytes($soundKinds, $bitrateKbs))===false ){
			throw new \RuntimeException('File cannot be written: '.basename($targetPath));
		}
		return $targetPath;
	}

}
