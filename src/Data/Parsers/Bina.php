<?php

namespace nyu\Data\Parsers;

/**
 * Binary reading. For now: telling what a content is from its first
 * bytes, whatever its file name says.
 */
class Bina {

	// Kinds that are text, although they can be told from their first bytes
	const TEXT_LIST = Array('xml', 'svg', 'html', 'ics', 'rtf');
	// Kinds that are zip archives
	const PACK_LIST = Array('zip', 'odt', 'ods', 'epub');

	/**
	 * Tells what a content is from its first bytes. The kind is named by
	 * its usual file extension. Formats that are plain text without a
	 * fixed opening (JSON, INI, YAML, Markdown, CSV) cannot be told apart
	 * and give ''.
	 *
	 * @param String $initialBytes The first bytes of the content, 512 being enough.
	 * @return String The kind: 'zip', 'odt', 'ods', 'epub', 'pdf', 'png', 'jpg', 'gif', 'webp', 'wav', 'mp3', 'ogg', 'flac', 'mp4', 'ole', 'gz', 'rtf', 'svg', 'xml', 'html', 'ics', or '' when unknown.
	 */
	public static function findContentCod(String $initialBytes): String {
		if( substr($initialBytes, 0, 4)==="PK\x03\x04" ){
			// OpenDocument and EPub archives start with an entry naming their type
			if( strpos($initialBytes, 'mimetypeapplication/vnd.oasis.opendocument.text')!==false ){
				return 'odt';
			}
			if( strpos($initialBytes, 'mimetypeapplication/vnd.oasis.opendocument.spreadsheet')!==false ){
				return 'ods';
			}
			if( strpos($initialBytes, 'mimetypeapplication/epub+zip')!==false ){
				return 'epub';
			}
			return 'zip';
		}
		$signList = Array(
			'%PDF-'=>'pdf', "\x89PNG\r\n\x1A\n"=>'png', "\xFF\xD8\xFF"=>'jpg', 'GIF87a'=>'gif', 'GIF89a'=>'gif',
			'OggS'=>'ogg', 'fLaC'=>'flac', 'ID3'=>'mp3', "\xFF\xFB"=>'mp3', "\xFF\xF3"=>'mp3',
			"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"=>'ole', "\x1F\x8B"=>'gz', '{\rtf'=>'rtf'
		);
		foreach($signList as $sgnTxt=>$sgnKnd){
			if( strncmp($initialBytes, strval($sgnTxt), strlen(strval($sgnTxt)))===0 ){
				return $sgnKnd;
			}
		}
		if( substr($initialBytes, 0, 4)==='RIFF' ){
			$riffCode = substr($initialBytes, 8, 4);
			return ($riffCode=='WAVE') ? 'wav' : (($riffCode=='WEBP') ? 'webp' : '');
		}
		if( substr($initialBytes, 4, 4)==='ftyp' ){
			return 'mp4';
		}
		// Text formats with a fixed opening
		$headText = ltrim(Base::castTextToUtf8($initialBytes));
		// A drawing is XML whose first element is svg, after what may stand before it
		if( preg_match('/^(?:\s*(?:<\?[^>]*\?>|<!--.*?-->|<!DOCTYPE\s+svg[^>]*>))*\s*<(?:[\w\-]+:)?svg[\s>\/]/is', $headText) ){
			return 'svg';
		}
		if( strncmp($headText, '<?xml', 5)===0 ){
			return 'xml';
		}
		if( preg_match('/^<(!doctype\s+html|html[\s>])/i', $headText) ){
			return 'html';
		}
		if( strncasecmp($headText, 'BEGIN:VCALENDAR', 15)===0 ){
			return 'ics';
		}
		return '';
	}

}
