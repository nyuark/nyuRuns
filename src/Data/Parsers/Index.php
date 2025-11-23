<?php

namespace nyu\Data\Parsers;

/**
 * Which parser reads which content: by file extension, or by the content
 * type of a request. An application can add parsers of its own.
 */
class Index {

	// Parser and default options of each file extension
	protected static $extnList = Array(
		'ini'    => Array('Init', Array()),
		'json'   => Array('Json', Array()),
		'jsonl'  => Array('Json', Array('lines'=>true)),
		'ndjson' => Array('Json', Array('lines'=>true)),
		'rps'    => Array('Json', Array('cmnts'=>true)),
		'rte'    => Array('Json', Array('cmnts'=>true)),
		'xml'    => Array('XHml', Array('reads'=>'xml')),
		'html'   => Array('XHml', Array('reads'=>'html')),
		'htm'    => Array('XHml', Array('reads'=>'html')),
		'xhtml'  => Array('XHml', Array('reads'=>'html')),
		'svg'    => Array('XHml', Array('reads'=>'xml')),
		'rss'    => Array('XHml', Array('reads'=>'xml')),
		'atom'   => Array('XHml', Array('reads'=>'xml')),
		'csv'    => Array('Tabl', Array()),
		'tsv'    => Array('Tabl', Array('separ'=>'tabul')),
		'tab'    => Array('Tabl', Array('separ'=>'tabul')),
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
	protected static $typeList = Array(
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
	 * @param String $fileExtnsn The extension, without its dot, whatever its case.
	 * @return ?Parser The parser, null when the extension has none.
	 */
	public static function forExtnsn(String $fileExtnsn): ?Parser {
		$extnSet = self::$extnList[strtolower($fileExtnsn)] ?? null;
		if( $extnSet===null ){
			return null;
		}
		$prsrCls = (strpos($extnSet[0], '\\')===false) ? __NAMESPACE__.'\\'.$extnSet[0] : $extnSet[0];
		return new $prsrCls();
	}

	/**
	 * Gives the default options a file extension is parsed with.
	 *
	 * @param String $fileExtnsn The extension, without its dot, whatever its case.
	 * @return Array The options, empty when the extension has none.
	 */
	public static function extnOptns(String $fileExtnsn): Array {
		return self::$extnList[strtolower($fileExtnsn)][1] ?? Array();
	}

	/**
	 * Reads a content type down to the file extension standing for it. The
	 * parameters after ';' are left out, and a type ending with '+json'
	 * or '+xml' is JSON or XML.
	 *
	 * @param String $contntType The content type, as a request sends it.
	 * @return String The extension, '' when the type is not known.
	 */
	protected static function typeExtns(String $contntType): String {
		$typeKey = strtolower(trim(explode(';', $contntType)[0]));
		if( isset(self::$typeList[$typeKey]) ){
			return self::$typeList[$typeKey];
		}
		if( substr($typeKey, -5)=='+json' ){
			return 'json';
		}
		return (substr($typeKey, -4)=='+xml') ? 'xml' : '';
	}

	/**
	 * Gives the parser of a request content type.
	 *
	 * @param String $contntType The content type, with or without its parameters.
	 * @return ?Parser The parser, null when the content type has none.
	 */
	public static function forCnType(String $contntType): ?Parser {
		$typeExt = self::typeExtns($contntType);
		return ($typeExt=='') ? null : self::forExtnsn($typeExt);
	}

	/**
	 * Gives the default options a request content type is parsed with.
	 *
	 * @param String $contntType The content type, with or without its parameters.
	 * @return Array The options, empty when the content type has none.
	 */
	public static function typeOptns(String $contntType): Array {
		$typeExt = self::typeExtns($contntType);
		return ($typeExt=='') ? Array() : self::extnOptns($typeExt);
	}

	/**
	 * Adds a parser for a file extension, or replaces the one it had.
	 *
	 * @param String $fileExtnsn The extension, without its dot.
	 * @param String $parserName Name of a class of this namespace, or full name of a class of the application; it must follow the Parser contract.
	 * @param Array  $parseOptns Default options the extension is parsed with.
	 *
	 * @throws \RuntimeException When the extension is not a plain name, or the class is not a parser.
	 */
	public static function addParser(String $fileExtnsn, String $parserName, Array $parseOptns=Array()): Void {
		$prsrCls = (strpos($parserName, '\\')===false) ? __NAMESPACE__.'\\'.$parserName : $parserName;
		if( !preg_match('/^\w+$/D', $fileExtnsn) ){
			throw new \RuntimeException('Invalid file extension: '.$fileExtnsn);
		}
		if( !class_exists($prsrCls) || !is_subclass_of($prsrCls, Parser::class) ){
			throw new \RuntimeException('Not a parser: '.$parserName);
		}
		self::$extnList[strtolower($fileExtnsn)] = Array($parserName, $parseOptns);
	}

}
