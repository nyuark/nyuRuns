<?php

namespace nyu\Data\Writers;

/**
 * Which writer writes which format, by file extension, and the content
 * type a reply in that format is sent with. An application can add
 * writers of its own.
 */
class Index {

	// Writer, default options and content type of each file extension
	protected static $extnsArray = Array(
		'html'   => Array('Html', Array(), 'text/html; charset=utf-8'),
		'htm'    => Array('Html', Array(), 'text/html; charset=utf-8'),
		'json'   => Array('Json', Array(), 'application/json; charset=utf-8'),
		'jsonl'  => Array('Json', Array('lines'=>true), 'application/jsonl; charset=utf-8'),
		'ndjson' => Array('Json', Array('lines'=>true), 'application/x-ndjson; charset=utf-8'),
		'yaml'   => Array('Yaml', Array(), 'application/yaml; charset=utf-8'),
		'yml'    => Array('Yaml', Array(), 'application/yaml; charset=utf-8'),
		'csv'    => Array('Tabl', Array(), 'text/csv; charset=utf-8'),
		'tsv'    => Array('Tabl', Array('separ'=>'tabulSep'), 'text/tab-separated-values; charset=utf-8'),
		'tab'    => Array('Tabl', Array('separ'=>'tabulSep'), 'text/tab-separated-values; charset=utf-8'),
		'md'     => Array('Mdwn', Array(), 'text/markdown; charset=utf-8'),
		'markdown' => Array('Mdwn', Array(), 'text/markdown; charset=utf-8'),
		'txt'    => Array('Text', Array('gives'=>'plain'), 'text/plain; charset=utf-8'),
		'ics'    => Array('ICal', Array(), 'text/calendar; charset=utf-8'),
		'pdf'    => Array('Pdfx', Array(), 'application/pdf'),
		'xlsx'   => Array('Xlsx', Array(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
		'ods'    => Array('Odsx', Array(), 'application/vnd.oasis.opendocument.spreadsheet'),
		'docx'   => Array('Docx', Array(), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
		'odt'    => Array('Odtx', Array(), 'application/vnd.oasis.opendocument.text'),
		'epub'   => Array('EPub', Array(), 'application/epub+zip'),
		'xml'    => Array('Xmlx', Array(), 'application/xml; charset=utf-8')
	);

	/**
	 * Gives the writer of a file extension.
	 *
	 * @param String $extensionTxt The extension, without its dot, whatever its case.
	 * @return ?Writer The writer, null when the extension has none.
	 */
	public static function forExtnsWriter(String $extensionTxt): ?Writer {
		$extList = self::$extnsArray[strtolower($extensionTxt)] ?? null;
		if( $extList===null ){
			return null;
		}
		$wrtrCls = (strpos($extList[0], '\\')===false) ? __NAMESPACE__.'\\'.$extList[0] : $extList[0];
		return new $wrtrCls();
	}

	/**
	 * Gives the default options a file extension is written with.
	 *
	 * @param String $extensionTxt The extension, without its dot, whatever its case.
	 * @return Array The options, empty when the extension has none.
	 */
	public static function optionsOfExtns(String $extensionTxt): Array {
		return self::$extnsArray[strtolower($extensionTxt)][1] ?? Array();
	}

	/**
	 * Gives the content type a reply written for a file extension is
	 * sent with.
	 *
	 * @param String $extensionTxt The extension, without its dot, whatever its case.
	 * @return String The content type, with its character set when it is text; '' when the extension has no writer.
	 */
	public static function contentTypeFor(String $extensionTxt): String {
		return strval(self::$extnsArray[strtolower($extensionTxt)][2] ?? '');
	}

	/**
	 * Adds a writer for a file extension, or replaces the one it had.
	 *
	 * @param String $extensionTxt The extension, without its dot.
	 * @param String $writerClsNme Name of a class of this namespace, or full name of a class of the application; it must follow the Writer contract.
	 * @param Array  $writeOptions Default options the extension is written with.
	 * @param String $sentMimeType Content type a reply in that format is sent with.
	 *
	 * @throws \RuntimeException When the extension is not a plain name, the class is not a writer, or the content type is not one.
	 */
	public static function registerWriter(String $extensionTxt, String $writerClsNme, Array $writeOptions=Array(), String $sentMimeType='application/octet-stream'): Void {
		$wrtrCls = (strpos($writerClsNme, '\\')===false) ? __NAMESPACE__.'\\'.$writerClsNme : $writerClsNme;
		if( !preg_match('/^\w+$/D', $extensionTxt) ){
			throw new \RuntimeException('Invalid file extension: '.$extensionTxt);
		}
		if( !class_exists($wrtrCls) || !is_subclass_of($wrtrCls, Writer::class) ){
			throw new \RuntimeException('Not a writer: '.$writerClsNme);
		}
		// A content type goes into a header of the reply: it holds nothing that could start another one
		if( !preg_match('/^[\w.+\-]+\/[\w.+\-]+(?:; ?[\w\-]+=[\w.\-]+)*$/D', $sentMimeType) ){
			throw new \RuntimeException('Invalid content type: '.$sentMimeType);
		}
		self::$extnsArray[strtolower($extensionTxt)] = Array($writerClsNme, $writeOptions, $sentMimeType);
	}

}
