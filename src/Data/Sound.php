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
	protected $coverBytes;
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
	 * Private: a sound is opened through openFluxdSound(), or made from
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
		$this->coverBytes = $pictureBytes;
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
	public static function openFluxdSound(Fluxd $sourceStream, Int $bytesCeiling=self::MAXI_BYTE): Sound {
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
		$innerPos = 0;
		while( substr($nakedContent, $innerPos, 3)==='ID3' && strlen($nakedContent)>=$innerPos+10 ){
			$innerPos += 10+self::sizeOfTagCount(substr($nakedContent, $innerPos+6, 4));
		}
		$headText = substr($nakedContent, $innerPos, 12);
		$readDict = null;
		if( substr($headText, 0, 4)==='RIFF' && substr($headText, 8, 4)==='WAVE' ){
			$readDict = self::readOfWavArray($nakedContent);
		}elseif( substr($headText, 0, 4)==='fLaC' ){
			$readDict = self::readOfFlaArray($nakedContent, $innerPos);
		}elseif( substr($headText, 0, 4)==='OggS' ){
			$readDict = self::readOfOggArray($nakedContent);
		}elseif( substr($headText, 4, 4)==='ftyp' ){
			$readDict = self::readOfMp4Array($nakedContent);
		}elseif( $nakedContent!=='' ){
			$readDict = self::readOfMp3Array($nakedContent);
		}
		if( $readDict===null || $readDict['infos']['secnd']<=0 || $readDict['infos']['rates']<=0 ){
			throw new \RuntimeException('Not a sound of a known kind');
		}
		$infoList = $readDict['infos'];
		$infoList['secnd'] = round($infoList['secnd'], 3);
		$infoList['bitrt'] = intval(round($infoList['bitrt']));
		return new Sound($nakedContent, Array('kinds'=>$infoList['kinds'], 'secnd'=>$infoList['secnd'], 'rates'=>intval($infoList['rates']), 'chans'=>intval($infoList['chans']), 'bitrt'=>$infoList['bitrt'], 'bytes'=>strlen($nakedContent), 'tagsl'=>self::cleanTagsArray($readDict['tagsl'])), strval($readDict['cover'] ?? ''), (array)($readDict['cuts'] ?? Array()));
	}

	/**
	 * Reads a size written on four bytes of seven bits each, as the tags
	 * of MP3 sounds write theirs.
	 *
	 * @param String $packedLength The four bytes.
	 * @return Int The size.
	 */
	protected static function sizeOfTagCount(String $packedLength): Int {
		$byteList = array_values(unpack('C4', str_pad($packedLength, 4, "\0")));
		return (($byteList[0] & 0x7F)<<21) | (($byteList[1] & 0x7F)<<14) | (($byteList[2] & 0x7F)<<7) | ($byteList[3] & 0x7F);
	}

	/**
	 * Tidies the tags read from a sound: each brought to UTF-8, without
	 * what cannot be shown, and no longer than a tag has reasons to be.
	 *
	 * @param Array $rawTagValues The tags, as read.
	 * @return Array The title under 'title', the artist under 'artst', the album under 'album', the year under 'years', the number of the track under 'track' and the genre under 'genre'; each only when the sound tells it.
	 */
	protected static function cleanTagsArray(Array $rawTagValues): Array {
		$tagsList = Array();
		foreach(Array('title', 'artst', 'album', 'years', 'track', 'genre') as $tagKey){
			$tagStrng = strval($rawTagValues[$tagKey] ?? '');
			// A tag that is not UTF-8 is read as Windows-1252, then kept on one line
			if( !preg_match('//u', $tagStrng) ){
				$tagStrng = strval(@iconv('Windows-1252', 'UTF-8//IGNORE', $tagStrng));
			}
			$tagStrng = trim(preg_replace('/\s*[\x00-\x1F\x7F]+\s*/', ' ', $tagStrng));
			$tagParte = Array();
			// A year is its four digits, a track its number, a genre told by its number is named
			if( $tagKey=='years' ){
				$tagStrng = preg_match('/\d{4}/', $tagStrng, $tagParte) ? $tagParte[0] : '';
			}elseif( $tagKey=='track' ){
				$tagStrng = preg_match('/^\d+/', $tagStrng, $tagParte) ? strval(intval($tagParte[0])) : '';
			}elseif( $tagKey=='genre' && preg_match('/^\(?(\d{1,3})\)?$/D', $tagStrng, $tagParte) ){
				$tagStrng = strval(self::GENR_LIST[intval($tagParte[1])] ?? '');
			}
			if( $tagStrng!=='' ){
				$tagsList[$tagKey] = function_exists('mb_substr') ? mb_substr($tagStrng, 0, self::TAGS_MAXI, 'UTF-8') : substr($tagStrng, 0, self::TAGS_MAXI);
			}
		}
		return $tagsList;
	}

	/**
	 * Reads the texts of a tag of an MP3 sound, and the picture it holds.
	 *
	 * @param String $id3TagString The tag, from its first byte.
	 * @return Array The tags under 'tagsl', the bytes of the picture under 'cover'.
	 */
	protected static function id3FramesArray(String $id3TagString): Array {
		$tagsList = Array();
		$coverTxt = '';
		$tagVersn = ord($id3TagString[3] ?? "\0");
		$tagCrude = substr($id3TagString, 10, self::sizeOfTagCount(substr($id3TagString, 6, 4)));
		// A tag may be written so that nothing in it looks like the start of a frame of sound: it is brought back as it was
		if( (ord($id3TagString[5] ?? "\0") & 0x80) && $tagVersn<4 ){
			$tagCrude = str_replace("\xFF\x00", "\xFF", $tagCrude);
		}
		$nameSize = ($tagVersn==2) ? 3 : 4;
		$hedrSize = ($tagVersn==2) ? 6 : 10;
		$keysList = Array('TIT2'=>'title', 'TT2'=>'title', 'TPE1'=>'artst', 'TP1'=>'artst', 'TALB'=>'album', 'TAL'=>'album', 'TDRC'=>'years', 'TYER'=>'years', 'TYE'=>'years', 'TRCK'=>'track', 'TRK'=>'track', 'TCON'=>'genre', 'TCO'=>'genre');
		$readIndx = 0;
		while( $readIndx+$hedrSize<=strlen($tagCrude) && ord($tagCrude[$readIndx])!=0 ){
			$formName = substr($tagCrude, $readIndx, $nameSize);
			if( $tagVersn==2 ){
				$formSize = (ord($tagCrude[$readIndx+3])<<16) | (ord($tagCrude[$readIndx+4])<<8) | ord($tagCrude[$readIndx+5]);
			}else{
				$formSize = ($tagVersn==4) ? self::sizeOfTagCount(substr($tagCrude, $readIndx+4, 4)) : unpack('N', substr($tagCrude, $readIndx+4, 4))[1];
			}
			if( $formSize<1 || $readIndx+$hedrSize+$formSize>strlen($tagCrude) ){
				break;
			}
			$formBrut = substr($tagCrude, $readIndx+$hedrSize, $formSize);
			$readIndx += $hedrSize+$formSize;
			if( isset($keysList[$formName]) && !isset($tagsList[$keysList[$formName]]) ){
				$tagsList[$keysList[$formName]] = self::readId3TagText(ord($formBrut[0]), substr($formBrut, 1));
			}elseif( ($formName=='APIC' || $formName=='PIC') && $coverTxt==='' ){
				// A picture: how its description is written, its kind, what it shows, its description, then its bytes
				$encoding = ord($formBrut[0]);
				$afterPos = ($formName=='PIC') ? 5 : intval(strpos($formBrut, "\0", 1))+2;
				$strngEnd = ($encoding==1 || $encoding==2) ? self::wideZerosCount($formBrut, $afterPos) : strpos($formBrut, "\0", $afterPos);
				$coverTxt = ($strngEnd===false) ? '' : substr($formBrut, $strngEnd+(($encoding==1 || $encoding==2) ? 2 : 1));
			}
		}
		return Array('tagsl'=>$tagsList, 'cover'=>$coverTxt);
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
			$fromEncd = ($encodingIdNb==2) ? 'UTF-16BE' : ((substr($templateText, 0, 2)==="\xFE\xFF") ? 'UTF-16BE' : 'UTF-16LE');
			$bareText = (in_array(substr($templateText, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true)) ? substr($templateText, 2) : $templateText;
			$templateText = strval(@iconv($fromEncd, 'UTF-8//IGNORE', substr($bareText, 0, strlen($bareText)-(strlen($bareText)%2))));
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
		$tagsList = Array();
		$coverTxt = '';
		$keysList = Array('TITLE'=>'title', 'ARTIST'=>'artst', 'ALBUM'=>'album', 'DATE'=>'years', 'YEAR'=>'years', 'TRACKNUMBER'=>'track', 'GENRE'=>'genre');
		$readIndx = 4+intval(unpack('V', str_pad(substr($blckBytesTxt, 0, 4), 4, "\0"))[1]);
		$lineNumb = intval(unpack('V', str_pad(substr($blckBytesTxt, $readIndx, 4), 4, "\0"))[1]);
		$readIndx += 4;
		for($linIdx=0; $linIdx<$lineNumb && $readIndx+4<=strlen($blckBytesTxt); $linIdx++){
			$lineSize = intval(unpack('V', substr($blckBytesTxt, $readIndx, 4))[1]);
			$linePart = explode('=', substr($blckBytesTxt, $readIndx+4, $lineSize), 2);
			$readIndx += 4+$lineSize;
			$lineClef = strtoupper($linePart[0]);
			if( isset($keysList[$lineClef]) && !isset($tagsList[$keysList[$lineClef]]) ){
				$tagsList[$keysList[$lineClef]] = strval($linePart[1] ?? '');
			}elseif( $lineClef=='METADATA_BLOCK_PICTURE' && $coverTxt==='' ){
				$coverTxt = self::flacPictureTxt(strval(base64_decode(strval($linePart[1] ?? ''), true)));
			}
		}
		return Array('tagsl'=>$tagsList, 'cover'=>$coverTxt);
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
		$readIndx = 8+intval(unpack('N', substr($blckBytesTxt, 4, 4))[1]);
		$readIndx += 4+intval(unpack('N', str_pad(substr($blckBytesTxt, $readIndx, 4), 4, "\0"))[1])+16;
		$pictSize = intval(unpack('N', str_pad(substr($blckBytesTxt, $readIndx, 4), 4, "\0"))[1]);
		return strval(substr($blckBytesTxt, $readIndx+4, $pictSize));
	}

	/**
	 * Reads a WAV sound: the parts it is made of, each named on four
	 * letters and telling its size.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to cut it under 'cuts'; null when it is not a whole WAV sound.
	 */
	protected static function readOfWavArray(String $nakedContent): ?Array {
		$frmtBody = '';
		$dataIndx = -1;
		$dataSize = 0;
		$tagsList = Array();
		$coverTxt = '';
		$readIndx = 12;
		while( $readIndx+8<=strlen($nakedContent) ){
			$partName = substr($nakedContent, $readIndx, 4);
			$partSize = intval(unpack('V', substr($nakedContent, $readIndx+4, 4))[1]);
			if( $partName==='fmt ' ){
				$frmtBody = substr($nakedContent, $readIndx+8, $partSize);
			}elseif( $partName==='data' && $dataIndx<0 ){
				$dataIndx = $readIndx+8;
				$dataSize = min($partSize, strlen($nakedContent)-$dataIndx);
			}elseif( $partName==='LIST' && substr($nakedContent, $readIndx+8, 4)==='INFO' ){
				// The tags of a WAV sound: each named on four letters too
				$infoClef = Array('INAM'=>'title', 'IART'=>'artst', 'IPRD'=>'album', 'ICRD'=>'years', 'ITRK'=>'track', 'IGNR'=>'genre');
				$chunkPos = $readIndx+12;
				while( $chunkPos+8<=min($readIndx+8+$partSize, strlen($nakedContent)) ){
					$infoSize = intval(unpack('V', substr($nakedContent, $chunkPos+4, 4))[1]);
					if( isset($infoClef[substr($nakedContent, $chunkPos, 4)]) ){
						$tagsList[$infoClef[substr($nakedContent, $chunkPos, 4)]] = rtrim(substr($nakedContent, $chunkPos+8, $infoSize), "\0");
					}
					$chunkPos += 8+$infoSize+($infoSize%2);
				}
			}elseif( strtolower($partName)==='id3 ' ){
				$id3Frmes = self::id3FramesArray(substr($nakedContent, $readIndx+8, $partSize));
				$tagsList = $tagsList + $id3Frmes['tagsl'];
				$coverTxt = $id3Frmes['cover'];
			}
			// A part of odd size is followed by a byte that only fills
			$readIndx += 8+$partSize+($partSize%2);
		}
		if( strlen($frmtBody)<16 || $dataIndx<0 ){
			return null;
		}
		$frmtVals = unpack('vkind/vchans/Vrates/Vbytes/valign/vbits', $frmtBody);
		if( $frmtVals['bytes']<1 ){
			return null;
		}
		return Array(
			'infos'=>Array('kinds'=>'wav', 'secnd'=>$dataSize/$frmtVals['bytes'], 'rates'=>$frmtVals['rates'], 'chans'=>$frmtVals['chans'], 'bitrt'=>$frmtVals['bytes']*8/1000),
			'tagsl'=>$tagsList, 'cover'=>$coverTxt,
			'cuts'=>Array('start'=>$dataIndx, 'bytes'=>$dataSize, 'align'=>max(1, $frmtVals['align']), 'persc'=>$frmtVals['bytes'], 'headr'=>$frmtBody)
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
		$frameLst = Array();
		$readIndx = $startsFromNb;
		// What stands before the first frame is passed over, within reason
		$scanLimt = min($endsBeforeNb-4, $startsFromNb+65536);
		while( $readIndx+4<=$endsBeforeNb ){
			$headVals = unpack('N', substr($nakedContent, $readIndx, 4))[1];
			$versnBit = ($headVals>>19) & 3;
			$layrBits = ($headVals>>17) & 3;
			$rateIndx = ($headVals>>12) & 15;
			$hrtzIndx = ($headVals>>10) & 3;
			$isMp3Frm = (($headVals & 0xFFE00000)==0xFFE00000 && $versnBit!=1 && $layrBits!=0 && $rateIndx>0 && $rateIndx<15 && $hrtzIndx<3);
			$frameSze = 0;
			if( $isMp3Frm ){
				$layerCnt = 4-$layrBits;
				$bitsRate = self::MP3S_RATE[(($versnBit==3) ? '1' : '2').$layerCnt][$rateIndx];
				$smplRate = self::MP3S_HRTZ[$versnBit][$hrtzIndx];
				$paddByte = ($headVals>>9) & 1;
				$smplNumb = ($layerCnt==1) ? 384 : (($layerCnt==3 && $versnBit!=3) ? 576 : 1152);
				$frameSze = ($layerCnt==1) ? (intdiv(12000*$bitsRate, $smplRate)+$paddByte)*4 : intdiv(intdiv($smplNumb, 8)*1000*$bitsRate, $smplRate)+$paddByte;
			}
			// A first frame is one when the next stands where it ends
			$nextOkay = ($readIndx+$frameSze+2<=$endsBeforeNb) && (ord($nakedContent[$readIndx+$frameSze])==0xFF && (ord($nakedContent[$readIndx+$frameSze+1]) & 0xE0)==0xE0);
			if( !$isMp3Frm || $frameSze<24 || (!$nextOkay && empty($frameLst)) ){
				if( !empty($frameLst) || $readIndx>=$scanLimt ){
					break;
				}
				$readIndx++;
				continue;
			}
			if( $readIndx+$frameSze>$endsBeforeNb && !empty($frameLst) ){
				break;
			}
			$frameLst[] = Array('start'=>$readIndx, 'bytes'=>$frameSze, 'smpls'=>$smplNumb, 'rates'=>$smplRate, 'chans'=>((($headVals>>6) & 3)==3) ? 1 : 2, 'bitrt'=>$bitsRate);
			$readIndx += $frameSze;
		}
		// A first frame may only tell how the sound is laid out, for the players: it holds nothing heard
		if( !empty($frameLst) && preg_match('/Xing|Info|VBRI/', substr($nakedContent, $frameLst[0]['start']+4, 48)) ){
			array_shift($frameLst);
		}
		return $frameLst;
	}

	/**
	 * Reads an MP3 sound: the tags standing before and after it, and the
	 * frames it is made of.
	 *
	 * @param String $nakedContent The bytes of the sound.
	 * @return ?Array What it is under 'infos', its tags under 'tagsl', its picture under 'cover' and what is needed to cut it under 'cuts'; null when no frame is found.
	 */
	protected static function readOfMp3Array(String $nakedContent): ?Array {
		$tagsList = Array();
		$coverTxt = '';
		$innerPos = 0;
		while( substr($nakedContent, $innerPos, 3)==='ID3' && strlen($nakedContent)>=$innerPos+10 ){
			$tagBytes = 10+self::sizeOfTagCount(substr($nakedContent, $innerPos+6, 4));
			$id3Frmes = self::id3FramesArray(substr($nakedContent, $innerPos, $tagBytes));
			$tagsList = $tagsList + $id3Frmes['tagsl'];
			$coverTxt = ($coverTxt==='') ? $id3Frmes['cover'] : $coverTxt;
			$innerPos += $tagBytes;
		}
		// The first kind of tag stands at the very end, on 128 bytes
		$bodySize = strlen($nakedContent);
		if( $bodySize>=128 && substr($nakedContent, -128, 3)==='TAG' ){
			$id3v1Tag = substr($nakedContent, -128);
			$bodySize -= 128;
			$tagsList = $tagsList + array_filter(Array(
				'title'=>rtrim(substr($id3v1Tag, 3, 30), "\0 "), 'artst'=>rtrim(substr($id3v1Tag, 33, 30), "\0 "), 'album'=>rtrim(substr($id3v1Tag, 63, 30), "\0 "), 'years'=>rtrim(substr($id3v1Tag, 93, 4), "\0 "),
				'track'=>(ord($id3v1Tag[125])==0 && ord($id3v1Tag[126])>0) ? strval(ord($id3v1Tag[126])) : '', 'genre'=>(ord($id3v1Tag[127])<80) ? '('.ord($id3v1Tag[127]).')' : ''
			), 'strlen');
			foreach(Array('title', 'artst', 'album') as $tagKey){
				if( isset($tagsList[$tagKey]) && !preg_match('//u', $tagsList[$tagKey]) ){
					$tagsList[$tagKey] = strval(@iconv('ISO-8859-1', 'UTF-8//IGNORE', $tagsList[$tagKey]));
				}
			}
		}
		$frameLst = self::mp3FramesArray($nakedContent, $innerPos, $bodySize);
		if( empty($frameLst) ){
			return null;
		}
		$smplNumb = array_sum(array_column($frameLst, 'smpls'));
		$byteNumb = array_sum(array_column($frameLst, 'bytes'));
		$secsNumb = $smplNumb/$frameLst[0]['rates'];
		return Array(
			'infos'=>Array('kinds'=>'mp3', 'secnd'=>$secsNumb, 'rates'=>$frameLst[0]['rates'], 'chans'=>$frameLst[0]['chans'], 'bitrt'=>$byteNumb*8/1000/$secsNumb),
			'tagsl'=>$tagsList, 'cover'=>$coverTxt, 'cuts'=>Array('frmes'=>$frameLst)
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
		$tagsList = Array();
		$coverTxt = '';
		$infoBloc = '';
		$keptBloc = Array();
		$readIndx = $startsFromNb+4;
		do{
			if( $readIndx+4>strlen($nakedContent) ){
				return null;
			}
			$blockCod = ord($nakedContent[$readIndx]) & 0x7F;
			$isFinBlc = (ord($nakedContent[$readIndx]) & 0x80)!=0;
			$blockSze = (ord($nakedContent[$readIndx+1])<<16) | (ord($nakedContent[$readIndx+2])<<8) | ord($nakedContent[$readIndx+3]);
			$blockTxt = substr($nakedContent, $readIndx+4, $blockSze);
			$readIndx += 4+$blockSze;
			if( $blockCod==0 ){
				$infoBloc = $blockTxt;
			}elseif( $blockCod==4 ){
				$noteDict = self::vorbisTagArray($blockTxt);
				$tagsList = $tagsList + $noteDict['tagsl'];
				$coverTxt = ($coverTxt==='') ? $noteDict['cover'] : $coverTxt;
			}elseif( $blockCod==6 && $coverTxt==='' ){
				$coverTxt = self::flacPictureTxt($blockTxt);
			}
			// What a sound cannot do without, and what lets a player move in it, are kept when its tags are taken off
			if( $blockCod==0 || $blockCod==3 ){
				$keptBloc[] = Array($blockCod, $blockTxt);
			}
		}while( !$isFinBlc );
		if( strlen($infoBloc)<18 ){
			return null;
		}
		// The sample rate on 20 bits, the channels on 3, the bits of a sample on 5, the number of samples on 36
		$packVals = unpack('J', substr($infoBloc, 10, 8))[1];
		$smplRate = ($packVals>>44) & 0xFFFFF;
		$smplNumb = $packVals & 0xFFFFFFFFF;
		if( $smplRate<1 || $smplNumb<1 ){
			return null;
		}
		return Array(
			'infos'=>Array('kinds'=>'flac', 'secnd'=>$smplNumb/$smplRate, 'rates'=>$smplRate, 'chans'=>(($packVals>>41) & 7)+1, 'bitrt'=>(strlen($nakedContent)-$readIndx)*8/1000/($smplNumb/$smplRate)),
			'tagsl'=>$tagsList, 'cover'=>$coverTxt, 'cuts'=>Array('start'=>$readIndx, 'blcks'=>$keptBloc)
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
		$packList = Array();
		$packBuff = '';
		$readIndx = 0;
		while( count($packList)<2 && substr($nakedContent, $readIndx, 4)==='OggS' && $readIndx+27<=strlen($nakedContent) ){
			$sgmtNumb = ord($nakedContent[$readIndx+26]);
			$sizeList = array_values(unpack('C*', substr($nakedContent, $readIndx+27, $sgmtNumb)));
			$readIndx += 27+$sgmtNumb;
			foreach($sizeList as $oneSeg){
				$packBuff .= substr($nakedContent, $readIndx, $oneSeg);
				$readIndx += $oneSeg;
				if( $oneSeg<255 ){
					$packList[] = $packBuff;
					$packBuff = '';
				}
			}
			if( strlen($packBuff)>self::MAXI_BYTE ){
				return null;
			}
		}
		$headPack = strval($packList[0] ?? '');
		$skipSmpl = 0;
		if( substr($headPack, 0, 7)==="\x01vorbis" && strlen($headPack)>=16 ){
			$chnlNumb = ord($headPack[11]);
			$smplRate = intval(unpack('V', substr($headPack, 12, 4))[1]);
			$noteDict = self::vorbisTagArray(substr(strval($packList[1] ?? ''), 7));
		}elseif( substr($headPack, 0, 8)==='OpusHead' && strlen($headPack)>=12 ){
			// An Opus sound is always played at 48000 samples a second, after the samples it asks to be passed over
			$chnlNumb = ord($headPack[9]);
			$skipSmpl = intval(unpack('v', substr($headPack, 10, 2))[1]);
			$smplRate = 48000;
			$noteDict = self::vorbisTagArray(substr(strval($packList[1] ?? ''), 8));
		}else{
			return null;
		}
		// The last page tells the number of the last sample
		$lastIndx = strrpos($nakedContent, 'OggS');
		if( $lastIndx===false || $lastIndx+14>strlen($nakedContent) || $smplRate<1 ){
			return null;
		}
		$smplNumb = max(0, intval(unpack('P', substr($nakedContent, $lastIndx+6, 8))[1])-$skipSmpl);
		if( $smplNumb<1 ){
			return null;
		}
		return Array('infos'=>Array('kinds'=>'ogg', 'secnd'=>$smplNumb/$smplRate, 'rates'=>$smplRate, 'chans'=>$chnlNumb, 'bitrt'=>strlen($nakedContent)*8/1000/($smplNumb/$smplRate)), 'tagsl'=>$noteDict['tagsl'], 'cover'=>$noteDict['cover']);
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
		$boxsList = Array();
		$readIndx = $startsFromNb;
		while( $readIndx+8<=$endsBeforeNb ){
			$boxBytes = intval(unpack('N', substr($nakedContent, $readIndx, 4))[1]);
			$hedrSize = 8;
			// A box too large for four bytes tells its size on eight, after its name; one telling none runs to the end
			if( $boxBytes==1 && $readIndx+16<=$endsBeforeNb ){
				$boxBytes = intval(unpack('J', substr($nakedContent, $readIndx+8, 8))[1]);
				$hedrSize = 16;
			}elseif( $boxBytes==0 ){
				$boxBytes = $endsBeforeNb-$readIndx;
			}
			if( $boxBytes<$hedrSize || $readIndx+$boxBytes>$endsBeforeNb ){
				break;
			}
			$boxsList[] = Array(substr($nakedContent, $readIndx+4, 4), $readIndx+$hedrSize, $readIndx+$boxBytes);
			$readIndx += $boxBytes;
		}
		return $boxsList;
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
			$foundBox = null;
			// The box of the tags starts with four bytes that are not a box
			foreach(self::boxesOfItArray($nakedContent, $containerBox[1]+(($containerBox[0]==='meta') ? 4 : 0), $containerBox[2]) as $oneBox){
				if( $oneBox[0]===$boxNme ){
					$foundBox = $oneBox;
					break;
				}
			}
			if( $foundBox===null ){
				return null;
			}
			$containerBox = $foundBox;
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
		$fichrBox = Array('', 0, strlen($nakedContent));
		$moovAtom = self::findBoxesArray($nakedContent, $fichrBox, Array('moov'));
		$headAtom = ($moovAtom===null) ? null : self::findBoxesArray($nakedContent, $moovAtom, Array('mvhd'));
		if( $headAtom===null ){
			return null;
		}
		// The length of the whole, counted in the unit the file tells: both on eight bytes in the later way of writing them
		$headText = substr($nakedContent, $headAtom[1], $headAtom[2]-$headAtom[1]);
		$timeDict = (ord($headText[0] ?? "\0")==1) ? unpack('Nunit/Jlong', str_pad(substr($headText, 20, 12), 12, "\0")) : unpack('Nunit/Nlong', str_pad(substr($headText, 12, 8), 8, "\0"));
		$smplRate = 0;
		$chnlNumb = 0;
		foreach(self::boxesOfItArray($nakedContent, $moovAtom[1], $moovAtom[2]) as $oneBox){
			$handlBox = ($oneBox[0]==='trak') ? self::findBoxesArray($nakedContent, $oneBox, Array('mdia', 'hdlr')) : null;
			$trckCode = ($handlBox===null) ? '' : substr($nakedContent, $handlBox[1]+8, 4);
			if( $trckCode==='vide' ){
				return null;
			}
			$infoAtom = ($trckCode==='soun') ? self::findBoxesArray($nakedContent, $oneBox, Array('mdia', 'minf', 'stbl', 'stsd')) : null;
			if( $infoAtom!==null && $smplRate==0 ){
				// The description of the sound: its channels, then its sample rate as a whole part and a part of one
				$infoText = substr($nakedContent, $infoAtom[1]+16, 28);
				$chnlNumb = intval(unpack('n', str_pad(substr($infoText, 16, 2), 2, "\0"))[1]);
				$smplRate = intval(unpack('N', str_pad(substr($infoText, 24, 4), 4, "\0"))[1])>>16;
			}
		}
		if( $smplRate<1 || $timeDict['unit']<1 || $timeDict['long']<1 ){
			return null;
		}
		$tagsList = Array();
		$coverTxt = '';
		$lstngBox = self::findBoxesArray($nakedContent, $moovAtom, Array('udta', 'meta', 'ilst'));
		$keysList = Array("\xA9nam"=>'title', "\xA9ART"=>'artst', "\xA9alb"=>'album', "\xA9day"=>'years', "\xA9gen"=>'genre', 'trkn'=>'track', 'gnre'=>'genre', 'covr'=>'cover');
		foreach(($lstngBox===null) ? Array() : self::boxesOfItArray($nakedContent, $lstngBox[1], $lstngBox[2]) as $itmBox){
			$dataAtom = isset($keysList[$itmBox[0]]) ? self::findBoxesArray($nakedContent, $itmBox, Array('data')) : null;
			if( $dataAtom===null ){
				continue;
			}
			// What a tag holds stands after eight bytes telling its kind
			$dataText = strval(substr($nakedContent, $dataAtom[1]+8, $dataAtom[2]-$dataAtom[1]-8));
			if( $itmBox[0]==='covr' ){
				$coverTxt = $dataText;
			}elseif( $itmBox[0]==='trkn' ){
				$tagsList['track'] = strval(unpack('n', str_pad(substr($dataText, 2, 2), 2, "\0"))[1]);
			}elseif( $itmBox[0]==='gnre' ){
				$tagsList['genre'] = '('.(intval(unpack('n', str_pad(substr($dataText, 0, 2), 2, "\0"))[1])-1).')';
			}else{
				$tagsList[$keysList[$itmBox[0]]] = $dataText;
			}
		}
		$secsNumb = $timeDict['long']/$timeDict['unit'];
		return Array('infos'=>Array('kinds'=>'m4a', 'secnd'=>$secsNumb, 'rates'=>$smplRate, 'chans'=>$chnlNumb, 'bitrt'=>strlen($nakedContent)*8/1000/$secsNumb), 'tagsl'=>$tagsList, 'cover'=>$coverTxt);
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
		if( $this->coverBytes==='' ){
			return null;
		}
		try{
			return Image::openFluxdImage(Fluxd::openBytesFluxd($this->coverBytes), $pixelCeiling);
		}catch( \RuntimeException $e ){
			return null;
		}
	}

	/**
	 * Gives the sound without its tags nor its picture, what is heard
	 * being left as it is written.
	 *
	 * @param String $targetFormat Kind to give: the kind of the sound when left out. Another kind takes a program that unpacks the sound.
	 * @param Int    $kilobitsRate Thousands of bits a second to pack it with, for the kinds that lose some of the sound; left to the kind when 0. Asking for one packs the sound again.
	 * @return String The bytes.
	 *
	 * @throws \RuntimeException When the kind is unknown, or what is asked takes a program that unpacks sounds and the server has none.
	 */
	public function grantBytesText(String $targetFormat='', Int $kilobitsRate=0): String {
		$kindClef = strtolower(($targetFormat==='') ? $this->detailList['kinds'] : $targetFormat);
		if( !isset(Codec::KIND_LIST[$kindClef]) ){
			throw new \RuntimeException('Unknown kind of sound: '.substr(preg_replace('/[^a-z0-9]+/', '', $kindClef), 0, 10));
		}
		if( ($kindClef!==$this->detailList['kinds'] || $kilobitsRate>0) && self::validateACodec() ){
			return self::$soundCodec->alterSoundText($this->nakedBytes, $this->detailList['kinds'], $kindClef, Array('kbits'=>$kilobitsRate));
		}
		if( $kindClef!==$this->detailList['kinds'] ){
			throw new \RuntimeException('Giving a sound as another kind takes a program that unpacks it, which this server does not have');
		}
		if( $kindClef=='wav' ){
			return $this->wavOfPieceText($this->trimValues['start'], $this->trimValues['bytes']);
		}
		if( $kindClef=='mp3' ){
			$frameLst = $this->trimValues['frmes'];
			$lastForm = $frameLst[count($frameLst)-1];
			return substr($this->nakedBytes, $frameLst[0]['start'], $lastForm['start']+$lastForm['bytes']-$frameLst[0]['start']);
		}
		if( $kindClef=='flac' ){
			// The blocks kept, the last of them marked as such, then what is heard
			$headText = 'fLaC';
			foreach($this->trimValues['blcks'] as $blkIdx=>$oneCut){
				$headText .= chr($oneCut[0] | (($blkIdx==count($this->trimValues['blcks'])-1) ? 0x80 : 0)).substr(pack('N', strlen($oneCut[1])), 1).$oneCut[1];
			}
			return $headText.substr($this->nakedBytes, $this->trimValues['start']);
		}
		if( self::validateACodec() ){
			return self::$soundCodec->alterSoundText($this->nakedBytes, $kindClef, $kindClef);
		}
		throw new \RuntimeException('The tags of a sound of this kind cannot be taken off as it is written: '.$kindClef);
	}

	/**
	 * Makes the same sound of another kind, without its tags.
	 *
	 * @param String $targetFormat The kind to make: wav, mp3, flac, ogg or m4a.
	 * @param Int    $kilobitsRate Thousands of bits a second to pack it with; left to the kind when 0.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When the kind is unknown, or the server has no program that unpacks sounds.
	 */
	public function convertToSound(String $targetFormat, Int $kilobitsRate=0): Sound {
		return self::fromBytesSound($this->grantBytesText($targetFormat, $kilobitsRate));
	}
	
	/**
	 * Writes a WAV sound from a part of what is heard in this one.
	 *
	 * @param Int $startsFromNb Where the part starts in the bytes of the sound.
	 * @param Int $dataByteSize Its length.
	 * @return String The bytes of the sound.
	 */
	protected function wavOfPieceText(Int $startsFromNb, Int $dataByteSize): String {
		$frmtBody = $this->trimValues['headr'];
		$dataText = strval(substr($this->nakedBytes, $startsFromNb, $dataByteSize));
		$bodyText = 'WAVE'.'fmt '.pack('V', strlen($frmtBody)).$frmtBody.((strlen($frmtBody)%2) ? "\0" : '').'data'.pack('V', strlen($dataText)).$dataText.((strlen($dataText)%2) ? "\0" : '');
		return 'RIFF'.pack('V', strlen($bodyText)).$bodyText;
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
		$fromSecs = max(0.0, $startSeconds);
		$tillSecs = min($untilSeconds, $this->detailList['secnd']);
		if( $tillSecs<=$fromSecs ){
			throw new \RuntimeException('The part asked for holds nothing of the sound');
		}
		if( $this->detailList['kinds']=='wav' ){
			// A sample of every channel is cut whole
			$alignVal = $this->trimValues['align'];
			$fromByts = intdiv(intval(round($fromSecs*$this->trimValues['persc'])), $alignVal)*$alignVal;
			$tellsByt = min($this->trimValues['bytes'], intdiv(intval(round($tillSecs*$this->trimValues['persc'])), $alignVal)*$alignVal);
			if( $tellsByt<=$fromByts ){
				throw new \RuntimeException('The part asked for holds nothing of the sound');
			}
			return self::fromBytesSound($this->wavOfPieceText($this->trimValues['start']+$fromByts, $tellsByt-$fromByts));
		}
		if( $this->detailList['kinds']=='mp3' ){
			// The frames starting inside the part are kept, as they are
			$partText = '';
			$currTime = 0.0;
			foreach($this->trimValues['frmes'] as $frmSet){
				if( $currTime>=$fromSecs && $currTime<$tillSecs ){
					$partText .= substr($this->nakedBytes, $frmSet['start'], $frmSet['bytes']);
				}
				$currTime += $frmSet['smpls']/$frmSet['rates'];
			}
			// A sound is told from two frames following each other
			if( strlen($partText)<=$this->trimValues['frmes'][0]['bytes']+1 ){
				throw new \RuntimeException('The part asked for holds nothing of the sound');
			}
			return self::fromBytesSound($partText);
		}
		if( self::validateACodec() ){
			return self::fromBytesSound(self::$soundCodec->alterSoundText($this->nakedBytes, $this->detailList['kinds'], $this->detailList['kinds'], Array('start'=>$fromSecs, 'until'=>$tillSecs)));
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
	 * @param Int $channelCount Channels to give it, 1 or 2; its own when 0.
	 * @param Int $sampleBitsNb Bits a sample to give it: 8, 16, 24 or 32; its own when 0.
	 * @return Sound The sound made.
	 *
	 * @throws \RuntimeException When a value asked for is not one of those accepted, or the sound is packed in a way that is not read here.
	 */
	public function changeWavSound(Int $ratesHertzNb=0, Int $channelCount=0, Int $sampleBitsNb=0): Sound {
		if( $this->detailList['kinds']!='wav' ){
			return $this->convertToSound('wav')->changeWavSound($ratesHertzNb, $channelCount, $sampleBitsNb);
		}
		$frmtVals = unpack('vkind/vchans/Vrates/Vbytes/valign/vbits', $this->trimValues['headr']);
		// Samples written as whole numbers, told so directly or in the longer way of telling it
		$isAllDay = ($frmtVals['kind']==1) || ($frmtVals['kind']==0xFFFE && substr($this->trimValues['headr'], 24, 2)==="\x01\x00");
		$fromBits = $frmtVals['bits'];
		$fromChnl = $frmtVals['chans'];
		$fromRout = $frmtVals['rates'];
		$frameSze = $frmtVals['align'];
		if( !$isAllDay || !in_array($fromBits, Array(8, 16, 24, 32)) || $fromChnl<1 || $fromRout<1 || $frameSze!=$fromChnl*intdiv($fromBits, 8) ){
			throw new \RuntimeException('A WAV sound packed this way is not changed without a program that unpacks sounds');
		}
		$intoRate = ($ratesHertzNb==0) ? $fromRout : $ratesHertzNb;
		$intoChnl = ($channelCount==0) ? $fromChnl : $channelCount;
		$intoBits = ($sampleBitsNb==0) ? $fromBits : $sampleBitsNb;
		if( $intoRate<8000 || $intoRate>192000 ){
			throw new \RuntimeException('Samples a second out of the 8000 to 192000 accepted: '.$intoRate);
		}
		if( $intoChnl!=$fromChnl && $intoChnl!=1 && $intoChnl!=2 ){
			throw new \RuntimeException('Channels other than the 1 or 2 accepted: '.$intoChnl);
		}
		if( !in_array($intoBits, Array(8, 16, 24, 32)) ){
			throw new \RuntimeException('Bits a sample other than the 8, 16, 24 or 32 accepted: '.$intoBits);
		}
		$fromNumb = intdiv($this->trimValues['bytes'], $frameSze);
		$intoNumb = intdiv($fromNumb*$intoRate, $fromRout);
		if( $intoNumb<1 ){
			throw new \RuntimeException('The sound holds nothing to change');
		}
		$scalFact = 2**($intoBits-$fromBits);
		$topsVals = 2**($intoBits-1)-1;
		$maskVals = 2**$intoBits-1;
		$dataText = '';
		// The sound is made a block at a time, for long sounds not to take the memory samples by samples would
		for($blkPos=0; $blkPos<$intoNumb; $blkPos+=8192){
			$blockEnd = min($intoNumb, $blkPos+8192);
			$crsorPos = intdiv($blkPos*$fromRout, $intoRate);
			$readLimt = min($fromNumb-1, intdiv(($blockEnd-1)*$fromRout, $intoRate)+1);
			$readList = $this->wavValuesArray($this->trimValues['start']+$crsorPos*$frameSze, ($readLimt-$crsorPos+1)*$frameSze, $fromBits);
			$madeList = Array();
			for($smpInc=$blkPos; $smpInc<$blockEnd; $smpInc++){
				// Where the sample made stands between two samples read
				$spotVals = $smpInc*$fromRout/$intoRate;
				$spotIntg = intval(floor($spotVals));
				$spotFrac = $spotVals-$spotIntg;
				$frstIndx = ($spotIntg-$crsorPos)*$fromChnl;
				$nextIndx = (min($spotIntg+1, $readLimt)-$crsorPos)*$fromChnl;
				$chnlList = Array();
				for($chnIdx=0; $chnIdx<$fromChnl; $chnIdx++){
					$chnlList[] = $readList[$frstIndx+$chnIdx]+($readList[$nextIndx+$chnIdx]-$readList[$frstIndx+$chnIdx])*$spotFrac;
				}
				// One channel is the middle of all, two of one are twice the same, two of more are the first two
				if( $intoChnl==1 && $fromChnl>1 ){
					$chnlList = Array(array_sum($chnlList)/$fromChnl);
				}elseif( $intoChnl==2 && $fromChnl==1 ){
					$chnlList[] = $chnlList[0];
				}elseif( $intoChnl==2 && $fromChnl>2 ){
					$chnlList = Array($chnlList[0], $chnlList[1]);
				}
				foreach($chnlList as $chnVal){
					$madeVals = max(-$topsVals-1, min($topsVals, intval(round($chnVal*$scalFact))));
					// Samples of 8 bits are written from 0 to 255, the others around 0
					$madeList[] = ($intoBits==8) ? $madeVals+128 : ($madeVals & $maskVals);
				}
			}
			if( $intoBits==24 ){
				foreach($madeList as $oneVal){
					$dataText .= chr($oneVal & 0xFF).chr(($oneVal>>8) & 0xFF).chr(($oneVal>>16) & 0xFF);
				}
			}else{
				$dataText .= pack(Array(8=>'C*', 16=>'v*', 32=>'V*')[$intoBits], ...$madeList);
			}
		}
		$intoSize = $intoChnl*intdiv($intoBits, 8);
		$bodyText = 'WAVE'.'fmt '.pack('V', 16).pack('vvVVvv', 1, $intoChnl, $intoRate, $intoRate*$intoSize, $intoSize, $intoBits).'data'.pack('V', strlen($dataText)).$dataText.((strlen($dataText)%2) ? "\0" : '');
		return self::fromBytesSound('RIFF'.pack('V', strlen($bodyText)).$bodyText);
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
		$partText = substr($this->nakedBytes, $startsFromNb, $dataByteSize);
		$readList = Array();
		if( $sampleBitsNb==24 ){
			for($bytIdx=0; $bytIdx+3<=strlen($partText); $bytIdx+=3){
				$readVals = ord($partText[$bytIdx]) | (ord($partText[$bytIdx+1])<<8) | (ord($partText[$bytIdx+2])<<16);
				$readList[] = ($readVals>=0x800000) ? $readVals-0x1000000 : $readVals;
			}
			return $readList;
		}
		$halfVals = 2**($sampleBitsNb-1);
		foreach(unpack(Array(8=>'C*', 16=>'v*', 32=>'V*')[$sampleBitsNb], $partText) as $rawVal){
			$readList[] = ($sampleBitsNb==8) ? $rawVal-128 : (($rawVal>=$halfVals) ? $rawVal-2*$halfVals : $rawVal);
		}
		return $readList;
	}

	/**
	 * Writes the sound to a file, without its tags.
	 *
	 * @param String $saveFilePath Path of the file to write.
	 * @param String $targetFormat Kind to write; the kind of the sound when left out.
	 * @param Int    $kilobitsRate Thousands of bits a second to pack it with; left to the kind when 0.
	 * @return String The path of the file.
	 *
	 * @throws \RuntimeException When the file cannot be written, or the sound cannot be given as asked.
	 */
	public function writeLocalFile(String $saveFilePath, String $targetFormat='', Int $kilobitsRate=0): String {
		if( @file_put_contents($saveFilePath, $this->grantBytesText($targetFormat, $kilobitsRate))===false ){
			throw new \RuntimeException('File cannot be written: '.basename($saveFilePath));
		}
		return $saveFilePath;
	}

}
