<?php

namespace nyu\Data;

/**
 * A sound: what it is, told from its content, the tags and the picture
 * it carries, and the sounds that can be made of it without unpacking
 * what is heard: the same without its tags, or a part of it.
 *
 * Nothing here unpacks the sound itself. A part is cut where the kind
 * lets it be cut as it is written: between two samples of a WAV sound,
 * between two frames of an MP3 one. Giving a sound as another kind, or
 * cutting the other kinds, takes a program that unpacks it.
 */
class Sound {

	// Greatest size read from a source unless told otherwise: 64 MB
	const MAXI_BYTE = 67108864;
	// Greatest length kept of a tag, in characters
	const TAGS_MAXI = 500;
	// The genres the first tags of MP3 sounds name by a number
	const GENR_LIST = Array('Blues', 'Classic Rock', 'Country', 'Dance', 'Disco', 'Funk', 'Grunge', 'Hip-Hop', 'Jazz', 'Metal', 'New Age', 'Oldies', 'Other', 'Pop', 'R&B', 'Rap', 'Reggae', 'Rock', 'Techno', 'Industrial', 'Alternative', 'Ska', 'Death Metal', 'Pranks', 'Soundtrack', 'Euro-Techno', 'Ambient', 'Trip-Hop', 'Vocal', 'Jazz+Funk', 'Fusion', 'Trance', 'Classical', 'Instrumental', 'Acid', 'House', 'Game', 'Sound Clip', 'Gospel', 'Noise', 'AlternRock', 'Bass', 'Soul', 'Punk', 'Space', 'Meditative', 'Instrumental Pop', 'Instrumental Rock', 'Ethnic', 'Gothic', 'Darkwave', 'Techno-Industrial', 'Electronic', 'Pop-Folk', 'Eurodance', 'Dream', 'Southern Rock', 'Comedy', 'Cult', 'Gangsta', 'Top 40', 'Christian Rap', 'Pop/Funk', 'Jungle', 'Native American', 'Cabaret', 'New Wave', 'Psychadelic', 'Rave', 'Showtunes', 'Trailer', 'Lo-Fi', 'Tribal', 'Acid Punk', 'Acid Jazz', 'Polka', 'Retro', 'Musical', 'Rock & Roll', 'Hard Rock');
	// The frames of an MP3 sound: bit rates in thousands of bits a second, by version and layer, then sample rates by version
	const MP3S_RATE = Array(
		'11'=>Array(0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448), '12'=>Array(0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384), '13'=>Array(0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320),
		'21'=>Array(0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256), '22'=>Array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160), '23'=>Array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160)
	);
	const MP3S_HRTZ = Array(3=>Array(44100, 48000, 32000), 2=>Array(22050, 24000, 16000), 0=>Array(11025, 12000, 8000));

	protected $nakedBytes;
	protected $detailList;
	protected $cvrPicture;
	// For the kinds cut as they are written: where what is heard starts and ends, and the frames it is made of
	protected $trimValues;
	// The program that unpacks sounds, where the server has one
	protected static $soundCodec = null;
	
	/**
	 * Plugs the program that unpacks sounds, for the sounds to be given
	 * as other kinds and every kind to be cut.
	 *
	 * @param ?Codec $codecProgram The program; null to do without.
	 * @return Void
	 */
	public static function plugSoundCodec(?Codec $codecProgram): Void {
		self::$soundCodec = $codecProgram;
	}
	
	/**
	 * Tells whether a program that unpacks sounds can be used.
	 *
	 * @return Bool True when one is plugged and can be run on this server.
	 */
	public static function validateACodec(): Bool {
		return ( self::$soundCodec!==null && self::$soundCodec->isProgramReady() );
	}

	/**
	 * Private: a sound is opened through openFluxdArchv(), or made from
	 * another one.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @param Array  $mediaDetails What the sound is, as soundInfoArray() gives it.
	 * @param String $pictureBytes The bytes of the picture it carries, '' when it carries none.
	 * @param Array  $trimSettings What is needed to cut it as it is written.
	 */
	private function __construct(String $nakedContent, Array $mediaDetails, String $pictureBytes, Array $trimSettings){
		$this->nakedBytes = $nakedContent;
		$this->detailList = $mediaDetails;
		$this->cvrPicture = $pictureBytes;
		$this->trimValues = $trimSettings;
	}

	/**
	 * Opens a sound from a source. What it is is read from its content,
	 * not from its name.
	 *
	 * @param Fluxd $sourceStream The source.
	 * @param Int   $bytesCeiling Greatest size read from the source, in bytes.
	 * @return Sound The opened sound.
	 *
	 * @throws \RuntimeException When the source is larger than the limit, or is not a sound of a known kind.
	 */
	public static function openFluxdArchv(Fluxd $sourceStream, Int $bytesCeiling=self::MAXI_BYTE): Sound {
		return self::fromBytesSound($sourceStream->getWholeString($bytesCeiling));
	}

	/**
	 * Reads a sound from its bytes.
	 *
	 * @param String $nakedContent The bytes.
	 * @return Sound The sound.
	 *
	 * @throws \RuntimeException When the content is not a sound of a known kind.
	 */
	protected static function fromBytesSound(String $nakedContent): Sound {
		// A tag standing before the sound is passed over to tell what follows it
		$bdyPstn = 0;
		while( substr($nakedContent, $bdyPstn, 3)==='ID3' && strlen($nakedContent)>=$bdyPstn+10 ){
			$bdyPstn += 10+self::sizeOfTagCount(substr($nakedContent, $bdyPstn+6, 4));
		}
		$headTxt = substr($nakedContent, $bdyPstn, 12);
		$readSet = null;
		if( substr($headTxt, 0, 4)==='RIFF' && substr($headTxt, 8, 4)==='WAVE' ){
			$readSet = self::readOfWavArray($nakedContent);
		}elseif( substr($headTxt, 0, 4)==='fLaC' ){
			$readSet = self::readOfFlaArray($nakedContent, $bdyPstn);
		}elseif( substr($headTxt, 0, 4)==='OggS' ){
			$readSet = self::readOfOggArray($nakedContent);
		}elseif( substr($headTxt, 4, 4)==='ftyp' ){
			$readSet = self::readOfMp4Array($nakedContent);
		}elseif( $nakedContent!=='' ){
			$readSet = self::readOfMp3Array($nakedContent);
		}
		if( $readSet===null || $readSet['infos']['secnd']<=0 || $readSet['infos']['rates']<=0 ){
			throw new \RuntimeException('Not a sound of a known kind');
		}
		$infoArr = $readSet['infos'];
		$infoArr['secnd'] = round($infoArr['secnd'], 3);
		$infoArr['bitrt'] = intval(round($infoArr['bitrt']));
		return new Sound($nakedContent, Array('kinds'=>$infoArr['kinds'], 'secnd'=>$infoArr['secnd'], 'rates'=>intval($infoArr['rates']), 'chans'=>intval($infoArr['chans']), 'bitrt'=>$infoArr['bitrt'], 'bytes'=>strlen($nakedContent), 'tagsl'=>self::cleanTagsArray($readSet['tagsl'])), strval($readSet['cover'] ?? ''), (array)($readSet['cuts'] ?? Array()));
	}

	/**
	 * Reads a size written on four bytes of seven bits each, as the tags
	 * of MP3 sounds write theirs.
	 *
	 * @param String $packedLength The four bytes.
	 * @return Int The size.
	 */
	protected static function sizeOfTagCount(String $packedLength): Int {
		$byteLst = array_values(unpack('C4', str_pad($packedLength, 4, "\0")));
		return (($byteLst[0] & 0x7F)<<21) | (($byteLst[1] & 0x7F)<<14) | (($byteLst[2] & 0x7F)<<7) | ($byteLst[3] & 0x7F);
	}

	/**
	 * Tidies the tags read from a sound: each brought to UTF-8, without
	 * what cannot be shown, and no longer than a tag has reasons to be.
	 *
	 * @param Array $rawTagValues The tags, as read.
	 * @return Array The title under 'title', the artist under 'artst', the album under 'album', the year under 'years', the number of the track under 'track' and the genre under 'genre'; each only when the sound tells it.
	 */
	protected static function cleanTagsArray(Array $rawTagValues): Array {
		$tagsLst = Array();
		foreach(Array('title', 'artst', 'album', 'years', 'track', 'genre') as $tagKey){
			$tagText = strval($rawTagValues[$tagKey] ?? '');
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
				$tagText = strval(self::GENR_LIST[intval($tagPrts[1])] ?? '');
			}
			if( $tagText!=='' ){
				$tagsLst[$tagKey] = function_exists('mb_substr') ? mb_substr($tagText, 0, self::TAGS_MAXI, 'UTF-8') : substr($tagText, 0, self::TAGS_MAXI);
			}
		}
		return $tagsLst;
	}

	/**
	 * Reads the texts of a tag of an MP3 sound, and the picture it holds.
	 *
	 * @param String $id3TagString The tag, from its first byte.
	 * @return Array The tags under 'tagsl', the bytes of the picture under 'cover'.
	 */
	protected static function id3FramesArray(String $id3TagString): Array {
		$tagsLst = Array();
		$cvrText = '';
		$tagVers = ord($id3TagString[3] ?? "\0");
		$tagBody = substr($id3TagString, 10, self::sizeOfTagCount(substr($id3TagString, 6, 4)));
		// A tag may be written so that nothing in it looks like the start of a frame of sound: it is brought back as it was
		if( (ord($id3TagString[5] ?? "\0") & 0x80) && $tagVers<4 ){
			$tagBody = str_replace("\xFF\x00", "\xFF", $tagBody);
		}
		$nmeSize = ($tagVers==2) ? 3 : 4;
		$hdrSize = ($tagVers==2) ? 6 : 10;
		$keysLst = Array('TIT2'=>'title', 'TT2'=>'title', 'TPE1'=>'artst', 'TP1'=>'artst', 'TALB'=>'album', 'TAL'=>'album', 'TDRC'=>'years', 'TYER'=>'years', 'TYE'=>'years', 'TRCK'=>'track', 'TRK'=>'track', 'TCON'=>'genre', 'TCO'=>'genre');
		$readPos = 0;
		while( $readPos+$hdrSize<=strlen($tagBody) && ord($tagBody[$readPos])!=0 ){
			$frmName = substr($tagBody, $readPos, $nmeSize);
			if( $tagVers==2 ){
				$frmSize = (ord($tagBody[$readPos+3])<<16) | (ord($tagBody[$readPos+4])<<8) | ord($tagBody[$readPos+5]);
			}else{
				$frmSize = ($tagVers==4) ? self::sizeOfTagCount(substr($tagBody, $readPos+4, 4)) : unpack('N', substr($tagBody, $readPos+4, 4))[1];
			}
			if( $frmSize<1 || $readPos+$hdrSize+$frmSize>strlen($tagBody) ){
				break;
			}
			$frmBody = substr($tagBody, $readPos+$hdrSize, $frmSize);
			$readPos += $hdrSize+$frmSize;
			if( isset($keysLst[$frmName]) && !isset($tagsLst[$keysLst[$frmName]]) ){
				$tagsLst[$keysLst[$frmName]] = self::readId3TagText(ord($frmBody[0]), substr($frmBody, 1));
			}elseif( ($frmName=='APIC' || $frmName=='PIC') && $cvrText==='' ){
				// A picture: how its description is written, its kind, what it shows, its description, then its bytes
				$charSet = ord($frmBody[0]);
				$afterAt = ($frmName=='PIC') ? 5 : intval(strpos($frmBody, "\0", 1))+2;
				$textEnd = ($charSet==1 || $charSet==2) ? self::wideZerosCount($frmBody, $afterAt) : strpos($frmBody, "\0", $afterAt);
				$cvrText = ($textEnd===false) ? '' : substr($frmBody, $textEnd+(($charSet==1 || $charSet==2) ? 2 : 1));
			}
		}
		return Array('tagsl'=>$tagsLst, 'cover'=>$cvrText);
	}

	/**
	 * Finds the end of a text written two bytes a character.
	 *
	 * @param String $templateText The bytes holding the text.
	 * @param Int    $startsFromNb Where the text starts.
	 * @return Int|Bool Where its ending pair of zero bytes stands, false when it has none.
	 */
	protected static function wideZerosCount(String $templateText, Int $startsFromNb): Int|Bool {
		for($posInc=$startsFromNb; $posInc+1<strlen($templateText); $posInc+=2){
			if( $templateText[$posInc]==="\0" && $templateText[$posInc+1]==="\0" ){
				return $posInc;
			}
		}
		return false;
	}

	/**
	 * Reads a text of a tag of an MP3 sound, whatever way it is written.
	 *
	 * @param Int    $encodingIdNb How it is written: 0 for Latin-1, 1 for UTF-16 with its marker, 2 for UTF-16 without, 3 for UTF-8.
	 * @param String $templateText The bytes of the text.
	 * @return String The text, in UTF-8, up to its first end.
	 */
	protected static function readId3TagText(Int $encodingIdNb, String $templateText): String {
		if( $encodingIdNb==1 || $encodingIdNb==2 ){
			$fromEnc = ($encodingIdNb==2) ? 'UTF-16BE' : ((substr($templateText, 0, 2)==="\xFE\xFF") ? 'UTF-16BE' : 'UTF-16LE');
			$bareTxt = (in_array(substr($templateText, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true)) ? substr($templateText, 2) : $templateText;
			$templateText = strval(@iconv($fromEnc, 'UTF-8//IGNORE', substr($bareTxt, 0, strlen($bareTxt)-(strlen($bareTxt)%2))));
		}elseif( $encodingIdNb==0 ){
			$templateText = strval(@iconv('ISO-8859-1', 'UTF-8//IGNORE', $templateText));
		}
		return explode("\0", $templateText)[0];
	}

	/**
	 * Reads the comments of a FLAC or Ogg sound: lines of NAME=value.
	 *
	 * @param String $blckBytesTxt The block holding them.
	 * @return Array The tags under 'tagsl', the bytes of the picture under 'cover'.
	 */
	protected static function vorbisTagArray(String $blckBytesTxt): Array {
		$tagsLst = Array();
		$cvrText = '';
		$keysLst = Array('TITLE'=>'title', 'ARTIST'=>'artst', 'ALBUM'=>'album', 'DATE'=>'years', 'YEAR'=>'years', 'TRACKNUMBER'=>'track', 'GENRE'=>'genre');
		$readPos = 4+intval(unpack('V', str_pad(substr($blckBytesTxt, 0, 4), 4, "\0"))[1]);
		$lineNum = intval(unpack('V', str_pad(substr($blckBytesTxt, $readPos, 4), 4, "\0"))[1]);
		$readPos += 4;
		for($linIdx=0; $linIdx<$lineNum && $readPos+4<=strlen($blckBytesTxt); $linIdx++){
			$lineLen = intval(unpack('V', substr($blckBytesTxt, $readPos, 4))[1]);
			$linPart = explode('=', substr($blckBytesTxt, $readPos+4, $lineLen), 2);
			$readPos += 4+$lineLen;
			$lineKey = strtoupper($linPart[0]);
			if( isset($keysLst[$lineKey]) && !isset($tagsLst[$keysLst[$lineKey]]) ){
				$tagsLst[$keysLst[$lineKey]] = strval($linPart[1] ?? '');
			}elseif( $lineKey=='METADATA_BLOCK_PICTURE' && $cvrText==='' ){
				$cvrText = self::flacPictureTxt(strval(base64_decode(strval($linPart[1] ?? ''), true)));
			}
		}
		return Array('tagsl'=>$tagsLst, 'cover'=>$cvrText);
	}

	/**
	 * Gives the bytes of the picture a picture block of a FLAC sound holds.
	 *
	 * @param String $blckBytesTxt The block: what the picture shows, its kind, its description, its size, then its bytes.
	 * @return String The bytes of the picture, '' when the block is not whole.
	 */
	protected static function flacPictureTxt(String $blckBytesTxt): String {
		if( strlen($blckBytesTxt)<32 ){
			return '';
		}
		$readPos = 8+intval(unpack('N', substr($blckBytesTxt, 4, 4))[1]);
		$readPos += 4+intval(unpack('N', str_pad(substr($blckBytesTxt, $readPos, 4), 4, "\0"))[1])+16;
		$picSize = intval(unpack('N', str_pad(substr($blckBytesTxt, $readPos, 4), 4, "\0"))[1]);
		return strval(substr($blckBytesTxt, $readPos+4, $picSize));
	}

	/**
	 * Reads a WAV sound: the parts it is made of, each named on four
	 * letters and telling its size.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to cut it under 'cuts'; null when it is not a whole WAV sound.
	 */
	protected static function readOfWavArray(String $nakedContent): ?Array {
		$frmtBdy = '';
		$dataIdx = -1;
		$dataLen = 0;
		$tagsLst = Array();
		$cvrText = '';
		$readPos = 12;
		while( $readPos+8<=strlen($nakedContent) ){
			$partNme = substr($nakedContent, $readPos, 4);
			$partLen = intval(unpack('V', substr($nakedContent, $readPos+4, 4))[1]);
			if( $partNme==='fmt ' ){
				$frmtBdy = substr($nakedContent, $readPos+8, $partLen);
			}elseif( $partNme==='data' && $dataIdx<0 ){
				$dataIdx = $readPos+8;
				$dataLen = min($partLen, strlen($nakedContent)-$dataIdx);
			}elseif( $partNme==='LIST' && substr($nakedContent, $readPos+8, 4)==='INFO' ){
				// The tags of a WAV sound: each named on four letters too
				$infKeys = Array('INAM'=>'title', 'IART'=>'artst', 'IPRD'=>'album', 'ICRD'=>'years', 'ITRK'=>'track', 'IGNR'=>'genre');
				$infPstn = $readPos+12;
				while( $infPstn+8<=min($readPos+8+$partLen, strlen($nakedContent)) ){
					$infoLen = intval(unpack('V', substr($nakedContent, $infPstn+4, 4))[1]);
					if( isset($infKeys[substr($nakedContent, $infPstn, 4)]) ){
						$tagsLst[$infKeys[substr($nakedContent, $infPstn, 4)]] = rtrim(substr($nakedContent, $infPstn+8, $infoLen), "\0");
					}
					$infPstn += 8+$infoLen+($infoLen%2);
				}
			}elseif( strtolower($partNme)==='id3 ' ){
				$id3Tags = self::id3FramesArray(substr($nakedContent, $readPos+8, $partLen));
				$tagsLst = $tagsLst + $id3Tags['tagsl'];
				$cvrText = $id3Tags['cover'];
			}
			// A part of odd size is followed by a byte that only fills
			$readPos += 8+$partLen+($partLen%2);
		}
		if( strlen($frmtBdy)<16 || $dataIdx<0 ){
			return null;
		}
		$frmtVls = unpack('vkind/vchans/Vrates/Vbytes/valign/vbits', $frmtBdy);
		if( $frmtVls['bytes']<1 ){
			return null;
		}
		return Array(
			'infos'=>Array('kinds'=>'wav', 'secnd'=>$dataLen/$frmtVls['bytes'], 'rates'=>$frmtVls['rates'], 'chans'=>$frmtVls['chans'], 'bitrt'=>$frmtVls['bytes']*8/1000),
			'tagsl'=>$tagsLst, 'cover'=>$cvrText,
			'cuts'=>Array('start'=>$dataIdx, 'bytes'=>$dataLen, 'align'=>max(1, $frmtVls['align']), 'persc'=>$frmtVls['bytes'], 'headr'=>$frmtBdy)
		);
	}

	/**
	 * Reads the frames an MP3 sound is made of, from where it starts.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @param Int    $startsFromNb Where to look for its first frame.
	 * @param Int    $endsBeforeNb Where what is heard ends.
	 * @return Array The frames, each with where it stands, its length, the samples it holds, its sample rate, its channels and its bit rate.
	 */
	protected static function mp3FramesArray(String $nakedContent, Int $startsFromNb, Int $endsBeforeNb): Array {
		$frmeLst = Array();
		$readPos = $startsFromNb;
		// What stands before the first frame is passed over, within reason
		$scanEnd = min($endsBeforeNb-4, $startsFromNb+65536);
		while( $readPos+4<=$endsBeforeNb ){
			$headVal = unpack('N', substr($nakedContent, $readPos, 4))[1];
			$versBit = ($headVal>>19) & 3;
			$layrBit = ($headVal>>17) & 3;
			$rateIdx = ($headVal>>12) & 15;
			$hrtzIdx = ($headVal>>10) & 3;
			$frameOk = (($headVal & 0xFFE00000)==0xFFE00000 && $versBit!=1 && $layrBit!=0 && $rateIdx>0 && $rateIdx<15 && $hrtzIdx<3);
			$frmeLen = 0;
			if( $frameOk ){
				$layerNb = 4-$layrBit;
				$bitRate = self::MP3S_RATE[(($versBit==3) ? '1' : '2').$layerNb][$rateIdx];
				$smplRte = self::MP3S_HRTZ[$versBit][$hrtzIdx];
				$padByte = ($headVal>>9) & 1;
				$smplNum = ($layerNb==1) ? 384 : (($layerNb==3 && $versBit!=3) ? 576 : 1152);
				$frmeLen = ($layerNb==1) ? (intdiv(12000*$bitRate, $smplRte)+$padByte)*4 : intdiv(intdiv($smplNum, 8)*1000*$bitRate, $smplRte)+$padByte;
			}
			// A first frame is one when the next stands where it ends
			$nxtOkay = ($readPos+$frmeLen+2<=$endsBeforeNb) && (ord($nakedContent[$readPos+$frmeLen])==0xFF && (ord($nakedContent[$readPos+$frmeLen+1]) & 0xE0)==0xE0);
			if( !$frameOk || $frmeLen<24 || (!$nxtOkay && empty($frmeLst)) ){
				if( !empty($frmeLst) || $readPos>=$scanEnd ){
					break;
				}
				$readPos++;
				continue;
			}
			if( $readPos+$frmeLen>$endsBeforeNb && !empty($frmeLst) ){
				break;
			}
			$frmeLst[] = Array('start'=>$readPos, 'bytes'=>$frmeLen, 'smpls'=>$smplNum, 'rates'=>$smplRte, 'chans'=>((($headVal>>6) & 3)==3) ? 1 : 2, 'bitrt'=>$bitRate);
			$readPos += $frmeLen;
		}
		// A first frame may only tell how the sound is laid out, for the players: it holds nothing heard
		if( !empty($frmeLst) && preg_match('/Xing|Info|VBRI/', substr($nakedContent, $frmeLst[0]['start']+4, 48)) ){
			array_shift($frmeLst);
		}
		return $frmeLst;
	}

	/**
	 * Reads an MP3 sound: the tags standing before and after it, and the
	 * frames it is made of.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to cut it under 'cuts'; null when no frame is found.
	 */
	protected static function readOfMp3Array(String $nakedContent): ?Array {
		$tagsLst = Array();
		$cvrText = '';
		$bdyPstn = 0;
		while( substr($nakedContent, $bdyPstn, 3)==='ID3' && strlen($nakedContent)>=$bdyPstn+10 ){
			$tagSize = 10+self::sizeOfTagCount(substr($nakedContent, $bdyPstn+6, 4));
			$id3Tags = self::id3FramesArray(substr($nakedContent, $bdyPstn, $tagSize));
			$tagsLst = $tagsLst + $id3Tags['tagsl'];
			$cvrText = ($cvrText==='') ? $id3Tags['cover'] : $cvrText;
			$bdyPstn += $tagSize;
		}
		// The first kind of tag stands at the very end, on 128 bytes
		$bodyEnd = strlen($nakedContent);
		if( $bodyEnd>=128 && substr($nakedContent, -128, 3)==='TAG' ){
			$lastTag = substr($nakedContent, -128);
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
		$frmeLst = self::mp3FramesArray($nakedContent, $bdyPstn, $bodyEnd);
		if( empty($frmeLst) ){
			return null;
		}
		$smplNum = array_sum(array_column($frmeLst, 'smpls'));
		$byteNum = array_sum(array_column($frmeLst, 'bytes'));
		$secsNum = $smplNum/$frmeLst[0]['rates'];
		return Array(
			'infos'=>Array('kinds'=>'mp3', 'secnd'=>$secsNum, 'rates'=>$frmeLst[0]['rates'], 'chans'=>$frmeLst[0]['chans'], 'bitrt'=>$byteNum*8/1000/$secsNum),
			'tagsl'=>$tagsLst, 'cover'=>$cvrText, 'cuts'=>Array('frmes'=>$frmeLst)
		);
	}

	/**
	 * Reads a FLAC sound: the blocks standing before what is heard.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @param Int    $startsFromNb Where the sound starts, past a tag that may stand before it.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to give it back without its tags under 'cuts'; null when it is not a whole FLAC sound.
	 */
	protected static function readOfFlaArray(String $nakedContent, Int $startsFromNb): ?Array {
		$tagsLst = Array();
		$cvrText = '';
		$infBlck = '';
		$kptBlck = Array();
		$readPos = $startsFromNb+4;
		do{
			if( $readPos+4>strlen($nakedContent) ){
				return null;
			}
			$blckKnd = ord($nakedContent[$readPos]) & 0x7F;
			$isLastB = (ord($nakedContent[$readPos]) & 0x80)!=0;
			$blckLen = (ord($nakedContent[$readPos+1])<<16) | (ord($nakedContent[$readPos+2])<<8) | ord($nakedContent[$readPos+3]);
			$blckTxt = substr($nakedContent, $readPos+4, $blckLen);
			$readPos += 4+$blckLen;
			if( $blckKnd==0 ){
				$infBlck = $blckTxt;
			}elseif( $blckKnd==4 ){
				$noteSet = self::vorbisTagArray($blckTxt);
				$tagsLst = $tagsLst + $noteSet['tagsl'];
				$cvrText = ($cvrText==='') ? $noteSet['cover'] : $cvrText;
			}elseif( $blckKnd==6 && $cvrText==='' ){
				$cvrText = self::flacPictureTxt($blckTxt);
			}
			// What a sound cannot do without, and what lets a player move in it, are kept when its tags are taken off
			if( $blckKnd==0 || $blckKnd==3 ){
				$kptBlck[] = Array($blckKnd, $blckTxt);
			}
		}while( !$isLastB );
		if( strlen($infBlck)<18 ){
			return null;
		}
		// The sample rate on 20 bits, the channels on 3, the bits of a sample on 5, the number of samples on 36
		$packVal = unpack('J', substr($infBlck, 10, 8))[1];
		$smplRte = ($packVal>>44) & 0xFFFFF;
		$smplNum = $packVal & 0xFFFFFFFFF;
		if( $smplRte<1 || $smplNum<1 ){
			return null;
		}
		return Array(
			'infos'=>Array('kinds'=>'flac', 'secnd'=>$smplNum/$smplRte, 'rates'=>$smplRte, 'chans'=>(($packVal>>41) & 7)+1, 'bitrt'=>(strlen($nakedContent)-$readPos)*8/1000/($smplNum/$smplRte)),
			'tagsl'=>$tagsLst, 'cover'=>$cvrText, 'cuts'=>Array('start'=>$readPos, 'blcks'=>$kptBlck)
		);
	}

	/**
	 * Reads an Ogg sound, Vorbis or Opus: its first two packets tell
	 * what it is and carry its tags, its last page tells how long it is.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl' and its picture under 'cover'; null when it is not a sound this reads.
	 */
	protected static function readOfOggArray(String $nakedContent): ?Array {
		// A packet is written over the pieces of one or several pages: a piece shorter than 255 bytes ends it
		$packLst = Array();
		$pckBffr = '';
		$readPos = 0;
		while( count($packLst)<2 && substr($nakedContent, $readPos, 4)==='OggS' && $readPos+27<=strlen($nakedContent) ){
			$sgmntNb = ord($nakedContent[$readPos+26]);
			$sizeLst = array_values(unpack('C*', substr($nakedContent, $readPos+27, $sgmntNb)));
			$readPos += 27+$sgmntNb;
			foreach($sizeLst as $oneSeg){
				$pckBffr .= substr($nakedContent, $readPos, $oneSeg);
				$readPos += $oneSeg;
				if( $oneSeg<255 ){
					$packLst[] = $pckBffr;
					$pckBffr = '';
				}
			}
			if( strlen($pckBffr)>self::MAXI_BYTE ){
				return null;
			}
		}
		$hedPack = strval($packLst[0] ?? '');
		$skpSmpl = 0;
		if( substr($hedPack, 0, 7)==="\x01vorbis" && strlen($hedPack)>=16 ){
			$chnnlNb = ord($hedPack[11]);
			$smplRte = intval(unpack('V', substr($hedPack, 12, 4))[1]);
			$noteSet = self::vorbisTagArray(substr(strval($packLst[1] ?? ''), 7));
		}elseif( substr($hedPack, 0, 8)==='OpusHead' && strlen($hedPack)>=12 ){
			// An Opus sound is always played at 48000 samples a second, after the samples it asks to be passed over
			$chnnlNb = ord($hedPack[9]);
			$skpSmpl = intval(unpack('v', substr($hedPack, 10, 2))[1]);
			$smplRte = 48000;
			$noteSet = self::vorbisTagArray(substr(strval($packLst[1] ?? ''), 8));
		}else{
			return null;
		}
		// The last page tells the number of the last sample
		$lastPos = strrpos($nakedContent, 'OggS');
		if( $lastPos===false || $lastPos+14>strlen($nakedContent) || $smplRte<1 ){
			return null;
		}
		$smplNum = max(0, intval(unpack('P', substr($nakedContent, $lastPos+6, 8))[1])-$skpSmpl);
		if( $smplNum<1 ){
			return null;
		}
		return Array('infos'=>Array('kinds'=>'ogg', 'secnd'=>$smplNum/$smplRte, 'rates'=>$smplRte, 'chans'=>$chnnlNb, 'bitrt'=>strlen($nakedContent)*8/1000/($smplNum/$smplRte)), 'tagsl'=>$noteSet['tagsl'], 'cover'=>$noteSet['cover']);
	}

	/**
	 * Lists the boxes standing in a part of an MP4 file.
	 *
	 * @param String $nakedContent The bytes of the file.
	 * @param Int    $startsFromNb Where the part starts.
	 * @param Int    $endsBeforeNb Where it ends.
	 * @return Array The boxes, each with its name, where what it holds starts, and where it ends.
	 */
	protected static function boxesOfItArray(String $nakedContent, Int $startsFromNb, Int $endsBeforeNb): Array {
		$boxList = Array();
		$readPos = $startsFromNb;
		while( $readPos+8<=$endsBeforeNb ){
			$boxSize = intval(unpack('N', substr($nakedContent, $readPos, 4))[1]);
			$hdrSize = 8;
			// A box too large for four bytes tells its size on eight, after its name; one telling none runs to the end
			if( $boxSize==1 && $readPos+16<=$endsBeforeNb ){
				$boxSize = intval(unpack('J', substr($nakedContent, $readPos+8, 8))[1]);
				$hdrSize = 16;
			}elseif( $boxSize==0 ){
				$boxSize = $endsBeforeNb-$readPos;
			}
			if( $boxSize<$hdrSize || $readPos+$boxSize>$endsBeforeNb ){
				break;
			}
			$boxList[] = Array(substr($nakedContent, $readPos+4, 4), $readPos+$hdrSize, $readPos+$boxSize);
			$readPos += $boxSize;
		}
		return $boxList;
	}

	/**
	 * Finds a box by the names leading to it.
	 *
	 * @param String $nakedContent The bytes of the file.
	 * @param Array  $containerBox The box to look in: its name, where what it holds starts, where it ends.
	 * @param Array  $boxNamesPath The names to follow, one per level.
	 * @return ?Array The box reached, null when the names lead nowhere.
	 */
	protected static function findBoxesArray(String $nakedContent, Array $containerBox, Array $boxNamesPath): ?Array {
		foreach($boxNamesPath as $boxNme){
			$fondBox = null;
			// The box of the tags starts with four bytes that are not a box
			foreach(self::boxesOfItArray($nakedContent, $containerBox[1]+(($containerBox[0]==='meta') ? 4 : 0), $containerBox[2]) as $oneBox){
				if( $oneBox[0]===$boxNme ){
					$fondBox = $oneBox;
					break;
				}
			}
			if( $fondBox===null ){
				return null;
			}
			$containerBox = $fondBox;
		}
		return $containerBox;
	}

	/**
	 * Reads an MP4 sound (m4a): a file made of boxes, one of which
	 * describes its tracks. A file holding moving pictures is not a sound.
	 *
	 * @param String $nakedContent The bytes of the file.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl' and its picture under 'cover'; null when it is not a sound.
	 */
	protected static function readOfMp4Array(String $nakedContent): ?Array {
		$fileBox = Array('', 0, strlen($nakedContent));
		$moovBox = self::findBoxesArray($nakedContent, $fileBox, Array('moov'));
		$headBox = ($moovBox===null) ? null : self::findBoxesArray($nakedContent, $moovBox, Array('mvhd'));
		if( $headBox===null ){
			return null;
		}
		// The length of the whole, counted in the unit the file tells: both on eight bytes in the later way of writing them
		$headTxt = substr($nakedContent, $headBox[1], $headBox[2]-$headBox[1]);
		$timeSet = (ord($headTxt[0] ?? "\0")==1) ? unpack('Nunit/Jlong', str_pad(substr($headTxt, 20, 12), 12, "\0")) : unpack('Nunit/Nlong', str_pad(substr($headTxt, 12, 8), 8, "\0"));
		$smplRte = 0;
		$chnnlNb = 0;
		foreach(self::boxesOfItArray($nakedContent, $moovBox[1], $moovBox[2]) as $oneBox){
			$hndlBox = ($oneBox[0]==='trak') ? self::findBoxesArray($nakedContent, $oneBox, Array('mdia', 'hdlr')) : null;
			$trckKnd = ($hndlBox===null) ? '' : substr($nakedContent, $hndlBox[1]+8, 4);
			if( $trckKnd==='vide' ){
				return null;
			}
			$infoBox = ($trckKnd==='soun') ? self::findBoxesArray($nakedContent, $oneBox, Array('mdia', 'minf', 'stbl', 'stsd')) : null;
			if( $infoBox!==null && $smplRte==0 ){
				// The description of the sound: its channels, then its sample rate as a whole part and a part of one
				$infoTxt = substr($nakedContent, $infoBox[1]+16, 28);
				$chnnlNb = intval(unpack('n', str_pad(substr($infoTxt, 16, 2), 2, "\0"))[1]);
				$smplRte = intval(unpack('N', str_pad(substr($infoTxt, 24, 4), 4, "\0"))[1])>>16;
			}
		}
		if( $smplRte<1 || $timeSet['unit']<1 || $timeSet['long']<1 ){
			return null;
		}
		$tagsLst = Array();
		$cvrText = '';
		$listBox = self::findBoxesArray($nakedContent, $moovBox, Array('udta', 'meta', 'ilst'));
		$keysLst = Array("\xA9nam"=>'title', "\xA9ART"=>'artst', "\xA9alb"=>'album', "\xA9day"=>'years', "\xA9gen"=>'genre', 'trkn'=>'track', 'gnre'=>'genre', 'covr'=>'cover');
		foreach(($listBox===null) ? Array() : self::boxesOfItArray($nakedContent, $listBox[1], $listBox[2]) as $itmBox){
			$dataBox = isset($keysLst[$itmBox[0]]) ? self::findBoxesArray($nakedContent, $itmBox, Array('data')) : null;
			if( $dataBox===null ){
				continue;
			}
			// What a tag holds stands after eight bytes telling its kind
			$dataTxt = strval(substr($nakedContent, $dataBox[1]+8, $dataBox[2]-$dataBox[1]-8));
			if( $itmBox[0]==='covr' ){
				$cvrText = $dataTxt;
			}elseif( $itmBox[0]==='trkn' ){
				$tagsLst['track'] = strval(unpack('n', str_pad(substr($dataTxt, 2, 2), 2, "\0"))[1]);
			}elseif( $itmBox[0]==='gnre' ){
				$tagsLst['genre'] = '('.(intval(unpack('n', str_pad(substr($dataTxt, 0, 2), 2, "\0"))[1])-1).')';
			}else{
				$tagsLst[$keysLst[$itmBox[0]]] = $dataTxt;
			}
		}
		$secsNum = $timeSet['long']/$timeSet['unit'];
		return Array('infos'=>Array('kinds'=>'m4a', 'secnd'=>$secsNum, 'rates'=>$smplRte, 'chans'=>$chnnlNb, 'bitrt'=>strlen($nakedContent)*8/1000/$secsNum), 'tagsl'=>$tagsLst, 'cover'=>$cvrText);
	}

	/**
	 * Tells what the sound is.
	 *
	 * @return Array Its kind under 'kinds' (wav, mp3, ogg, flac or m4a), how long it lasts in seconds under 'secnd', its samples a second under 'rates', its channels under 'chans', its thousands of bits a second under 'bitrt', its size under 'bytes', and its tags under 'tagsl'.
	 */
	public function soundInfoArray(): Array {
		return $this->detailList;
	}

	/**
	 * Gives the picture the sound carries, such as the cover of its album.
	 *
	 * @param Int $pixelCeiling Greatest number of pixels accepted for it.
	 * @return ?Image The picture, null when the sound carries none, or carries what is not a picture.
	 */
	public function giveCoverImage(Int $pixelCeiling=Image::MAXI_PIXL): ?Image {
		if( $this->cvrPicture==='' ){
			return null;
		}
		try{
			return Image::openFluxdArchv(Fluxd::openBytesFluxd($this->cvrPicture), $pixelCeiling);
		}catch( \RuntimeException $e ){
			return null;
		}
	}

	/**
	 * Gives the sound without its tags nor its picture, what is heard
	 * being left as it is written.
	 *
	 * @param String $targetFormat Kind to give: the kind of the sound when left out. Another kind takes a program that unpacks the sound.
	 * @param Int    $bitrtKilbyts Thousands of bits a second to pack it with, for the kinds that lose some of the sound; left to the kind when 0. Asking for one packs the sound again.
	 * @return String The bytes.
	 *
	 * @throws \RuntimeException When the kind is unknown, or what is asked takes a program that unpacks sounds and the server has none.
	 */
	public function grantBytesText(String $targetFormat='', Int $bitrtKilbyts=0): String {
		$kindKey = strtolower(($targetFormat==='') ? $this->detailList['kinds'] : $targetFormat);
		if( !isset(Codec::KIND_LIST[$kindKey]) ){
			throw new \RuntimeException('Unknown kind of sound: '.substr(preg_replace('/[^a-z0-9]+/', '', $kindKey), 0, 10));
		}
		if( ($kindKey!==$this->detailList['kinds'] || $bitrtKilbyts>0) && self::validateACodec() ){
			return self::$soundCodec->alterSndString($this->nakedBytes, $this->detailList['kinds'], $kindKey, Array('kbits'=>$bitrtKilbyts));
		}
		if( $kindKey!==$this->detailList['kinds'] ){
			throw new \RuntimeException('Giving a sound as another kind takes a program that unpacks it, which this server does not have');
		}
		if( $kindKey=='wav' ){
			return $this->wavOfPieceText($this->trimValues['start'], $this->trimValues['bytes']);
		}
		if( $kindKey=='mp3' ){
			$frmeLst = $this->trimValues['frmes'];
			$lastFrm = $frmeLst[count($frmeLst)-1];
			return substr($this->nakedBytes, $frmeLst[0]['start'], $lastFrm['start']+$lastFrm['bytes']-$frmeLst[0]['start']);
		}
		if( $kindKey=='flac' ){
			// The blocks kept, the last of them marked as such, then what is heard
			$headTxt = 'fLaC';
			foreach($this->trimValues['blcks'] as $blkIdx=>$oneCut){
				$headTxt .= chr($oneCut[0] | (($blkIdx==count($this->trimValues['blcks'])-1) ? 0x80 : 0)).substr(pack('N', strlen($oneCut[1])), 1).$oneCut[1];
			}
			return $headTxt.substr($this->nakedBytes, $this->trimValues['start']);
		}
		if( self::validateACodec() ){
			return self::$soundCodec->alterSndString($this->nakedBytes, $kindKey, $kindKey);
		}
		throw new \RuntimeException('The tags of a sound of this kind cannot be taken off as it is written: '.$kindKey);
	}

	/**
	 * Makes the same sound of another kind, without its tags.
	 *
	 * @param String $targetFormat The kind to make: wav, mp3, flac, ogg or m4a.
	 * @param Int    $bitrtKilbyts Thousands of bits a second to pack it with; left to the kind when 0.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When the kind is unknown, or the server has no program that unpacks sounds.
	 */
	public function convertToSound(String $targetFormat, Int $bitrtKilbyts=0): Sound {
		return self::fromBytesSound($this->grantBytesText($targetFormat, $bitrtKilbyts));
	}
	
	/**
	 * Writes a WAV sound from a part of what is heard in this one.
	 *
	 * @param Int $startsFromNb Where the part starts in the bytes of the sound.
	 * @param Int $dataByteSize Its length.
	 * @return String The bytes of the sound.
	 */
	protected function wavOfPieceText(Int $startsFromNb, Int $dataByteSize): String {
		$frmtBdy = $this->trimValues['headr'];
		$dataTxt = strval(substr($this->nakedBytes, $startsFromNb, $dataByteSize));
		$bodyTxt = 'WAVE'.'fmt '.pack('V', strlen($frmtBdy)).$frmtBdy.((strlen($frmtBdy)%2) ? "\0" : '').'data'.pack('V', strlen($dataTxt)).$dataTxt.((strlen($dataTxt)%2) ? "\0" : '');
		return 'RIFF'.pack('V', strlen($bodyTxt)).$bodyTxt;
	}

	/**
	 * Makes a sound of a part of this one, without its tags. The part is
	 * cut where the sound lets it be: at a sample of a WAV sound, at the
	 * frame of an MP3 one, some hundredths of a second long.
	 *
	 * @param Float $startSeconds Where the part starts, in seconds from the start of the sound.
	 * @param Float $untilSeconds Where it ends, in seconds; the end of the sound when it is past it.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When the part holds nothing, or the sound is of a kind that cannot be cut as it is written and the server has no program that unpacks sounds.
	 */
	public function makeSoundSlice(Float $startSeconds, Float $untilSeconds): Sound {
		$fromSec = max(0.0, $startSeconds);
		$tillSec = min($untilSeconds, $this->detailList['secnd']);
		if( $tillSec<=$fromSec ){
			throw new \RuntimeException('The part asked for holds nothing of the sound');
		}
		if( $this->detailList['kinds']=='wav' ){
			// A sample of every channel is cut whole
			$alignBy = $this->trimValues['align'];
			$fromByt = intdiv(intval(round($fromSec*$this->trimValues['persc'])), $alignBy)*$alignBy;
			$tllByte = min($this->trimValues['bytes'], intdiv(intval(round($tillSec*$this->trimValues['persc'])), $alignBy)*$alignBy);
			if( $tllByte<=$fromByt ){
				throw new \RuntimeException('The part asked for holds nothing of the sound');
			}
			return self::fromBytesSound($this->wavOfPieceText($this->trimValues['start']+$fromByt, $tllByte-$fromByt));
		}
		if( $this->detailList['kinds']=='mp3' ){
			// The frames starting inside the part are kept, as they are
			$partTxt = '';
			$timeNow = 0.0;
			foreach($this->trimValues['frmes'] as $frmSet){
				if( $timeNow>=$fromSec && $timeNow<$tillSec ){
					$partTxt .= substr($this->nakedBytes, $frmSet['start'], $frmSet['bytes']);
				}
				$timeNow += $frmSet['smpls']/$frmSet['rates'];
			}
			// A sound is told from two frames following each other
			if( strlen($partTxt)<=$this->trimValues['frmes'][0]['bytes']+1 ){
				throw new \RuntimeException('The part asked for holds nothing of the sound');
			}
			return self::fromBytesSound($partTxt);
		}
		if( self::validateACodec() ){
			return self::fromBytesSound(self::$soundCodec->alterSndString($this->nakedBytes, $this->detailList['kinds'], $this->detailList['kinds'], Array('start'=>$fromSec, 'until'=>$tillSec)));
		}
		throw new \RuntimeException('Cutting a sound of this kind takes a program that unpacks it, which this server does not have: '.$this->detailList['kinds']);
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
	 * @param Int $ratesHertzNb Samples a second to give it, from 8000 to 192000; its own when 0.
	 * @param Int $channlsCount Channels to give it, 1 or 2; its own when 0.
	 * @param Int $sampleBitsNb Bits a sample to give it: 8, 16, 24 or 32; its own when 0.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When a value asked for is not one of those accepted, or the sound is packed in a way that is not read here.
	 */
	public function changeWavSound(Int $ratesHertzNb=0, Int $channlsCount=0, Int $sampleBitsNb=0): Sound {
		if( $this->detailList['kinds']!='wav' ){
			return $this->convertToSound('wav')->changeWavSound($ratesHertzNb, $channlsCount, $sampleBitsNb);
		}
		$frmtVls = unpack('vkind/vchans/Vrates/Vbytes/valign/vbits', $this->trimValues['headr']);
		// Samples written as whole numbers, told so directly or in the longer way of telling it
		$isWhole = ($frmtVls['kind']==1) || ($frmtVls['kind']==0xFFFE && substr($this->trimValues['headr'], 24, 2)==="\x01\x00");
		$fromBit = $frmtVls['bits'];
		$fromChn = $frmtVls['chans'];
		$fromRte = $frmtVls['rates'];
		$frmeLen = $frmtVls['align'];
		if( !$isWhole || !in_array($fromBit, Array(8, 16, 24, 32)) || $fromChn<1 || $fromRte<1 || $frmeLen!=$fromChn*intdiv($fromBit, 8) ){
			throw new \RuntimeException('A WAV sound packed this way is not changed without a program that unpacks sounds');
		}
		$intoRte = ($ratesHertzNb==0) ? $fromRte : $ratesHertzNb;
		$intoChn = ($channlsCount==0) ? $fromChn : $channlsCount;
		$intoBit = ($sampleBitsNb==0) ? $fromBit : $sampleBitsNb;
		if( $intoRte<8000 || $intoRte>192000 ){
			throw new \RuntimeException('Samples a second out of the 8000 to 192000 accepted: '.$intoRte);
		}
		if( $intoChn!=$fromChn && $intoChn!=1 && $intoChn!=2 ){
			throw new \RuntimeException('Channels other than the 1 or 2 accepted: '.$intoChn);
		}
		if( !in_array($intoBit, Array(8, 16, 24, 32)) ){
			throw new \RuntimeException('Bits a sample other than the 8, 16, 24 or 32 accepted: '.$intoBit);
		}
		$fromNum = intdiv($this->trimValues['bytes'], $frmeLen);
		$intoNum = intdiv($fromNum*$intoRte, $fromRte);
		if( $intoNum<1 ){
			throw new \RuntimeException('The sound holds nothing to change');
		}
		$scaleBy = 2**($intoBit-$fromBit);
		$topsVal = 2**($intoBit-1)-1;
		$maskVal = 2**$intoBit-1;
		$dataTxt = '';
		// The sound is made a block at a time, for long sounds not to take the memory samples by samples would
		for($blkPos=0; $blkPos<$intoNum; $blkPos+=8192){
			$blckEnd = min($intoNum, $blkPos+8192);
			$redPstn = intdiv($blkPos*$fromRte, $intoRte);
			$readEnd = min($fromNum-1, intdiv(($blckEnd-1)*$fromRte, $intoRte)+1);
			$readLst = $this->wavValuesArray($this->trimValues['start']+$redPstn*$frmeLen, ($readEnd-$redPstn+1)*$frmeLen, $fromBit);
			$madeLst = Array();
			for($smpInc=$blkPos; $smpInc<$blckEnd; $smpInc++){
				// Where the sample made stands between two samples read
				$spotVal = $smpInc*$fromRte/$intoRte;
				$spotInt = intval(floor($spotVal));
				$sptPart = $spotVal-$spotInt;
				$atFirst = ($spotInt-$redPstn)*$fromChn;
				$atAfter = (min($spotInt+1, $readEnd)-$redPstn)*$fromChn;
				$chanLst = Array();
				for($chnIdx=0; $chnIdx<$fromChn; $chnIdx++){
					$chanLst[] = $readLst[$atFirst+$chnIdx]+($readLst[$atAfter+$chnIdx]-$readLst[$atFirst+$chnIdx])*$sptPart;
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
		return self::fromBytesSound('RIFF'.pack('V', strlen($bodyTxt)).$bodyTxt);
	}

	/**
	 * Reads samples of a WAV sound as whole numbers around 0.
	 *
	 * @param Int $startsFromNb Where the samples start in the bytes of the sound.
	 * @param Int $dataByteSize Their length.
	 * @param Int $sampleBitsNb The bits of a sample: 8, 16, 24 or 32.
	 * @return Array The samples, one after the other, channel after channel.
	 */
	protected function wavValuesArray(Int $startsFromNb, Int $dataByteSize, Int $sampleBitsNb): Array {
		$partTxt = substr($this->nakedBytes, $startsFromNb, $dataByteSize);
		$readLst = Array();
		if( $sampleBitsNb==24 ){
			for($bytIdx=0; $bytIdx+3<=strlen($partTxt); $bytIdx+=3){
				$readVal = ord($partTxt[$bytIdx]) | (ord($partTxt[$bytIdx+1])<<8) | (ord($partTxt[$bytIdx+2])<<16);
				$readLst[] = ($readVal>=0x800000) ? $readVal-0x1000000 : $readVal;
			}
			return $readLst;
		}
		$halfVal = 2**($sampleBitsNb-1);
		foreach(unpack(Array(8=>'C*', 16=>'v*', 32=>'V*')[$sampleBitsNb], $partTxt) as $rawVal){
			$readLst[] = ($sampleBitsNb==8) ? $rawVal-128 : (($rawVal>=$halfVal) ? $rawVal-2*$halfVal : $rawVal);
		}
		return $readLst;
	}

	/**
	 * Writes the sound to a file, without its tags.
	 *
	 * @param String $saveFilePath Path of the file to write.
	 * @param String $targetFormat Kind to write; the kind of the sound when left out.
	 * @param Int    $bitrtKilbyts Thousands of bits a second to pack it with; left to the kind when 0.
	 * @return String The path of the file.
	 *
	 * @throws \RuntimeException When the file cannot be written, or the sound cannot be given as asked.
	 */
	public function setLocalString(String $saveFilePath, String $targetFormat='', Int $bitrtKilbyts=0): String {
		if( @file_put_contents($saveFilePath, $this->grantBytesText($targetFormat, $bitrtKilbyts))===false ){
			throw new \RuntimeException('File cannot be written: '.basename($saveFilePath));
		}
		return $saveFilePath;
	}

}
