<?php

namespace nyu\Data\Parsers;

/**
 * Which parser reads which content: by file extension, or by the content
 * type of a request. An application can add parsers of its own.
 */
class Index {

	// Parser and default options of each file extension
	protected static $extnsnList = Array(
		'ini'    => Array('Init', Array()),
		'json'   => Array('Json', Array()),
		'jsonl'  => Array('Json', Array('lines'=>true)),
		'ndjson' => Array('Json', Array('lines'=>true)),
		'rps'    => Array('Json', Array('cmnts'=>true)),
		// A route file is plain JSON: no comment
		'rte'    => Array('Json', Array()),
		'xml'    => Array('XHml', Array('reads'=>'xml')),
		'html'   => Array('XHml', Array('reads'=>'html')),
		'htm'    => Array('XHml', Array('reads'=>'html')),
		'xhtml'  => Array('XHml', Array('reads'=>'html')),
		'svg'    => Array('XHml', Array('reads'=>'xml')),
		'rss'    => Array('XHml', Array('reads'=>'xml')),
		'atom'   => Array('XHml', Array('reads'=>'xml')),
		'csv'    => Array('Tabl', Array()),
		'tsv'    => Array('Tabl', Array('separ'=>'tabulSep')),
		'tab'    => Array('Tabl', Array('separ'=>'tabulSep')),
		'txt'    => Array('Text', Array('reads'=>'plain')),
		'md'     => Array('Mdwn', Array()),
		'markdown' => Array('Mdwn', Array()),
		'yaml'   => Array('Yaml', Array()),
		'yml'    => Array('Yaml', Array()),
		'xlsx'   => Array('Xlsx', Array()),
		'ods'    => Array('Odsx', Array()),
		'docx'   => Array('Docx', Array()),
		'odt'    => Array('Odtx', Array()),
		'epub'   => Array('EPub', Array()),
		'ics'    => Array('ICal', Array()),
		'pdf'    => Array('Pdfx', Array())
	);
	// File extension standing for each request content type
	protected static $parserList = Array(
		'application/json'     => 'json',
		'text/json'            => 'json',
		'application/x-ndjson' => 'ndjson',
		'application/jsonl'    => 'jsonl',
		'application/xml'      => 'xml',
		'text/xml'             => 'xml',
		'text/html'            => 'html',
		'application/xhtml+xml'=> 'xhtml',
		'text/csv'             => 'csv',
		'text/tab-separated-values' => 'tsv',
		'text/plain'           => 'txt',
		'text/markdown'        => 'md',
		'application/yaml'     => 'yaml',
		'application/x-yaml'   => 'yaml',
		'text/yaml'            => 'yaml',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.oasis.opendocument.text' => 'odt',
		'application/epub+zip' => 'epub',
		'text/calendar'        => 'ics',
		'application/pdf'      => 'pdf'
	);

	/**
	 * Gives the parser of a file extension.
	 *
	 * @param String $filExtension The extension, without its dot, whatever its case.
	 * @return ?Parser The parser, null when the extension has none.
	 */
	public static function forExtnsParser(String $filExtension): ?Parser {
		$extList = self::$extnsnList[strtolower($filExtension)] ?? null;
		if( $extList===null ){
			return null;
		}
		$prsrCls = (strpos($extList[0], '\\')===false) ? __NAMESPACE__.'\\'.$extList[0] : $extList[0];
		return new $prsrCls();
	}

	/**
	 * Gives the default options a file extension is parsed with.
	 *
	 * @param String $filExtension The extension, without its dot, whatever its case.
	 * @return Array The options, empty when the extension has none.
	 */
	public static function optionsOfExtns(String $filExtension): Array {
		return self::$extnsnList[strtolower($filExtension)][1] ?? Array();
	}

	/**
	 * Reads a content type down to the file extension standing for it. The
	 * parameters after ';' are left out, and a type ending with '+json'
	 * or '+xml' is JSON or XML.
	 *
	 * @param String $sentMimeType The content type, as a request sends it.
	 * @return String The extension, '' when the type is not known.
	 */
	protected static function typeExtensions(String $sentMimeType): String {
		$typeKey = strtolower(trim(explode(';', $sentMimeType)[0]));
		if( isset(self::$parserList[$typeKey]) ){
			return self::$parserList[$typeKey];
		}
		if( substr($typeKey, -5)=='+json' ){
			return 'json';
		}
		return (substr($typeKey, -4)=='+xml') ? 'xml' : '';
	}

	/**
	 * Gives the parser of a request content type.
	 *
	 * @param String $sentMimeType The content type, with or without its parameters.
	 * @return ?Parser The parser, null when the content type has none.
	 */
	public static function findTypeParser(String $sentMimeType): ?Parser {
		$fileExt = self::typeExtensions($sentMimeType);
		return ($fileExt=='') ? null : self::forExtnsParser($fileExt);
	}

	/**
	 * Gives the default options a request content type is parsed with.
	 *
	 * @param String $sentMimeType The content type, with or without its parameters.
	 * @return Array The options, empty when the content type has none.
	 */
	public static function typeOptionsArr(String $sentMimeType): Array {
		$fileExt = self::typeExtensions($sentMimeType);
		return ($fileExt=='') ? Array() : self::optionsOfExtns($fileExt);
	}

	/**
	 * Adds a parser for a file extension, or replaces the one it had.
	 *
	 * @param String $filExtension The extension, without its dot.
	 * @param String $nameOfParser Name of a class of this namespace, or full name of a class of the application; it must follow the Parser contract.
	 * @param Array  $parseOptions Default options the extension is parsed with.
	 *
	 * @throws \RuntimeException When the extension is not a plain name, or the class is not a parser.
	 */
	public static function registerParser(String $filExtension, String $nameOfParser, Array $parseOptions=Array()): Void {
		$prsrCls = (strpos($nameOfParser, '\\')===false) ? __NAMESPACE__.'\\'.$nameOfParser : $nameOfParser;
		if( !preg_match('/^\w+$/D', $filExtension) ){
			throw new \RuntimeException('Invalid file extension: '.$filExtension);
		}
		if( !class_exists($prsrCls) || !is_subclass_of($prsrCls, Parser::class) ){
			throw new \RuntimeException('Not a parser: '.$nameOfParser);
		}
		self::$extnsnList[strtolower($filExtension)] = Array($nameOfParser, $parseOptions);
	}

}
