<?php

namespace nyu\Data;

use \nyu\Data\Parsers;

/**
 * A document: one file of the server, with its name, what its content is,
 * and that content parsed by the parser its extension calls for.
 */
class Dcmnt{
	protected $nameOfFile;
	protected $plainTitle;
	protected $fileSuffix;
	protected $headerType;
	protected $fileSource;
	protected $latestData;
	protected $finalShape;
	protected $dataDetail;

	/**
	 * Opens a file, reads its name, title and extension, tells what its
	 * content is from its first bytes, and parses it according to its
	 * extension. A file without extension takes the kind its content
	 * shows, when it shows one.
	 *
	 * @param String $fileLocation Filesystem path of the file to load.
	 * @param Bool   $upperTopKeys Whether the parser should uppercase the keys of the first level (see parseFileBytes()).
	 *
	 * @throws \RuntimeException When the file cannot be read.
	 * @throws Parsers\ParseError When its content is not valid, or is not what its name announces.
	 */
	function __construct( String $fileLocation, Bool $upperTopKeys = true ){
		$this->fileSource = Fluxd::openLocalFluxd($fileLocation);
		$this->nameOfFile = basename($fileLocation);
		$namePart = pathinfo($this->nameOfFile);
		$this->plainTitle = strval($namePart['filename'] ?? '');
		$this->fileSuffix = strtolower(strval($namePart['extension'] ?? ''));
		$this->headerType = Parsers\Bina::detectFileKind($this->fileSource->getRangeString(0, 511));
		if( $this->fileSuffix=='' && $this->headerType!='' ){
			$this->fileSuffix = $this->headerType;
		}
		$this->parseFileBytes( $this->fileSuffix, $upperTopKeys );
	}

	/**
	 * Parses the file's content with the parser of the given extension,
	 * and stores the result as the file's final data. An extension without
	 * a parser leaves the data empty.
	 *
	 * @param String $extensionTxt Extension identifying the parser to use, as known by Parsers\Index.
	 * @param Bool   $upperTopKeys Whether the parser should uppercase the keys of the first level.
	 *
	 * @throws Parsers\ParseError When the content is not valid, or is not what the extension announces.
	 */
	function parseFileBytes( String $extensionTxt, Bool $upperTopKeys = true ): Void {
		$this->latestData = Array();
		$this->finalShape = '';
		$this->dataDetail = Array();
		$prsrInst = Parsers\Index::forExtnsParser($extensionTxt);
		if( $prsrInst===null ){
			return;
		}
		// A parser tells the kinds of content it reads: a content that is plainly something else is refused
		$kindList = ($prsrInst instanceof Parsers\Base) ? $prsrInst->signsReadArray() : Parsers\Bina::TEXT_LIST;
		if( $this->headerType!='' && !in_array($this->headerType, $kindList) ){
			throw new Parsers\ParseError($this->nameOfFile.': the content is not what the name announces');
		}
		try{
			$envelope = $prsrInst->parseFluxArray( $this->fileSource, Array('upper'=>$upperTopKeys) + Parsers\Index::optionsOfExtns($extensionTxt) );
		}catch( Parsers\ParseError $e ){
			throw new Parsers\ParseError($this->nameOfFile.': '.$e->getMessage(), 0, $e);
		}
		$this->latestData = $envelope['items'];
		$this->finalShape = $envelope['shape'];
		$this->dataDetail = $envelope['infos'];
	}

	/**
	 * Returns a detail about the loaded file.
	 *
	 * @param String $detailWanted One of 'ext' (extension), 'nme' (filename), 'tit' (title without extension), 'sig' (kind told by the first bytes, '' when unknown), 'raw' (raw contents), 'shp' (shape of the parsed data), 'inf' (details given by the parser), or 'out' (parsed data, default).
	 * @return mixed The requested detail, false when it is not one of these.
	 */
	function readFileDetail(String $detailWanted = 'out'): Mixed {
		if($detailWanted=='ext'){
			return $this->fileSuffix;
		}
		if($detailWanted=='nme'){
			return $this->nameOfFile;
		}
		if($detailWanted=='tit'){
			return $this->plainTitle;
		}
		if($detailWanted=='sig'){
			return $this->headerType;
		}
		if($detailWanted=='raw'){
			return $this->fileSource->getWholeString();
		}
		if($detailWanted=='shp'){
			return $this->finalShape;
		}
		if($detailWanted=='inf'){
			return $this->dataDetail;
		}
		if($detailWanted=='out'){
			return $this->latestData;
		}
		return false;
	}
}
