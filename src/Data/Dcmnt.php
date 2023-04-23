<?php

// A document: one file of the server, with its name, what its content is,
// and that content parsed by the parser its extension calls for.

namespace nyu\Data;

use \nyu\Data\Parsers;

class Dcmnt{
	protected $fileName;
	protected $fileTitl;
	protected $fileExtn;
	protected $fileSign;
	protected $fileFlux;
	protected $finalDat;
	protected $dataShpe;
	protected $dataInfo;

	/**
	 * Opens a file, reads its name, title and extension, tells what its
	 * content is from its first bytes, and parses it according to its
	 * extension. A file without extension takes the kind its content
	 * shows, when it shows one.
	 *
	 * @param String $fsFilePath Filesystem path of the file to load.
	 *
	 * @throws \RuntimeException When the file cannot be read.
	 * @throws Parsers\ParseError When its content is not valid, or is not what its name announces.
	 */
	function __construct( String $fsFilePath ){
		$this->fileFlux = Fluxd::openLocal($fsFilePath);
		$this->fileName = basename($fsFilePath);
		$namePrt = pathinfo($this->fileName);
		$this->fileTitl = strval($namePrt['filename'] ?? '');
		$this->fileExtn = strtolower(strval($namePrt['extension'] ?? ''));
		$this->fileSign = Parsers\Bina::signature($this->fileFlux->readRange(0, 511));
		if( $this->fileExtn=='' && $this->fileSign!='' ){
			$this->fileExtn = $this->fileSign;
		}
		$this->fileParse( $this->fileExtn );
	}

	/**
	 * Parses the file's content with the parser of the given extension,
	 * and stores the result as the file's final data. An extension without
	 * a parser leaves the data empty.
	 *
	 * @param String $fileTypExt Extension identifying the parser to use, as known by Parsers\Index.
	 * @param Bool   $keys2Upper Whether the parser should uppercase the keys of the first level.
	 *
	 * @throws Parsers\ParseError When the content is not valid, or is not what the extension announces.
	 */
	function fileParse( String $fileTypExt, Bool $keys2Upper = true ): Void {
		$this->finalDat = Array();
		$this->dataShpe = '';
		$this->dataInfo = Array();
		$prsrObj = Parsers\Index::forExtnsn($fileTypExt);
		if( $prsrObj===null ){
			return;
		}
		// The parsers known so far read text: a content that is plainly something else is refused
		if( $this->fileSign!='' && !in_array($this->fileSign, Parsers\Bina::TEXTLIST) ){
			throw new Parsers\ParseError($this->fileName.': the content is not what the name announces');
		}
		try{
			$envlope = $prsrObj->parseFlux( $this->fileFlux, Array('upper'=>$keys2Upper) + Parsers\Index::extnOptns($fileTypExt) );
		}catch( Parsers\ParseError $e ){
			throw new Parsers\ParseError($this->fileName.': '.$e->getMessage(), 0, $e);
		}
		$this->finalDat = $envlope['items'];
		$this->dataShpe = $envlope['shape'];
		$this->dataInfo = $envlope['infos'];
	}

	/**
	 * Returns a detail about the loaded file.
	 *
	 * @param String $fileDetail One of 'ext' (extension), 'nme' (filename), 'tit' (title without extension), 'sig' (kind told by the first bytes, '' when unknown), 'raw' (raw contents), 'shp' (shape of the parsed data), 'inf' (details given by the parser), or 'out' (parsed data, default).
	 * @return mixed The requested detail, false when it is not one of these.
	 */
	function readValue(String $fileDetail = 'out'): Mixed {
		if($fileDetail=='ext'){
			return $this->fileExtn;
		}
		if($fileDetail=='nme'){
			return $this->fileName;
		}
		if($fileDetail=='tit'){
			return $this->fileTitl;
		}
		if($fileDetail=='sig'){
			return $this->fileSign;
		}
		if($fileDetail=='raw'){
			return $this->fileFlux->readWhole();
		}
		if($fileDetail=='shp'){
			return $this->dataShpe;
		}
		if($fileDetail=='inf'){
			return $this->dataInfo;
		}
		if($fileDetail=='out'){
			return $this->finalDat;
		}
		return false;
	}
}
