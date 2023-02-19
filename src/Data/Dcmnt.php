<?php

// A basic file object to handle parsing

namespace nyu\Data;

use \nyu\Data\Parsers;

class Dcmnt{
	protected $fileName;
	protected $fileTitl;
	protected $fileExtn;
	protected $fileData;
	protected $finalDat;
	
	/**
	 * Loads a file from disk, extracts its name/title/extension, and
	 * parses its contents according to that extension.
	 *
	 * @param String $fsFilePath Filesystem path of the file to load.
	 *
	 * @todo No error is raised when the file doesn't exist — the object is left uninitialized.
	 */
	function __construct( String $fsFilePath ){
		if(file_exists($fsFilePath)){
			$this->fileName = basename($fsFilePath);
			$nmParts = explode( ".", $this->readValue('nme') );
			$this->fileExtn = end( $nmParts );
			$this->fileTitl = str_replace( '.'.$this->readValue('ext'), '', $this->readValue('nme'));
			$this->fileExtn = strtolower( $this->readValue('ext') );
			$this->fileData = file_get_contents( $fsFilePath );
			$this->finalDat = Array();
			
			$this->fileParse( $this->readValue('ext') );
		}else{
			// Throw Error
		}
	}
	
	/**
	 * Parses the file's raw contents according to the given extension and
	 * stores the result as the file's final data.
	 *
	 * @param String $fileTypExt Extension identifying the parser to use ('ini', 'json'; 'xml' not yet implemented).
	 * @param Bool   $keys2Upper Whether the parser should uppercase resulting keys.
	 */
	function fileParse( String $fileTypExt, Bool $keys2Upper = true ): Void {
		if($fileTypExt=='xml'){
			// TODO, implement XML Parser
		}
		if($fileTypExt=='ini'){
			$this->finalDat = Parsers\Init::parseData( $this->readValue('raw'), $keys2Upper );
		}
		if($fileTypExt=='json'){
			$this->finalDat = Parsers\Json::parseData( $this->readValue('raw'), $keys2Upper );
		}
	}
	
	/**
	 * Returns a detail about the loaded file.
	 *
	 * @param String $fileDetail One of 'ext' (extension), 'nme' (filename), 'tit' (title without extension), 'raw' (raw contents), or 'out' (parsed data, default).
	 * @return mixed The requested detail.
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
		if($fileDetail=='raw'){
			return $this->fileData;
		}
		if($fileDetail=='out'){
			return $this->finalDat;
		}
		return false;
	}
}