<?php

// A basic file object to handle parsing

namespace nyu\Data;

use \nyu\Data\Parsers;

class DFile{
	protected $fileName;
	protected $fileTitl;
	protected $fileExtn;
	protected $fileData;
	protected $finalArr;
	
	function __construct( $fsFilePath ){
		if(file_exists($fsFilePath)){
			$this->fileName = basename($fsFilePath);
			$this->fileExtn = end( explode( ".", $this->get('nme') ) );
			$this->fileTitl = str_replace( '.'.$this->get('ext'), '', $this->get('nme'));
			$this->fileExtn = strtolower( $this->get('ext') );
			$this->fileData = file_get_contents( $fsFilePath );
			$this->finalArr = Array();
			
			$this->fileParse( $this->get('ext') );
		}else{
			// Throw Error
		}
	}
	
	function fileParse( String $fileTypExt, Bool $keys2Upper = true ){
		if($fileTypExt=='xml'){
			// TODO, implement XML Parser
		}
		if($fileTypExt=='ini'){
			$this->finalArr = Parsers\Ini::Parse( $this->get('raw'), $keys2Upper );
		}
		if($fileTypExt=='json'){
			$this->finalArr = Parsers\Json::Parse( $this->get('raw'), $keys2Upper );
		}
	}
	
	function get(String $fileDetail = 'out'){
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
			return $this->finalArr;
		}
	}
}