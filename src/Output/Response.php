<?php

namespace nyu\Output;

class Response{

	protected $dataFrmt;
	protected $tmpltNme;
	protected $headrArr;

	protected $finalStr;
	protected $dataOuts;
	
	public function __construct( Array $renderVals=Array() ){
		$this->headrArr = $renderVals['headrs'];
		$this->tmpltNme = $renderVals['tmplte'];
		$this->dataFrmt = $renderVals['return'];
	}
	
	public function formatOut( Array $dataValues = Array() ){
		if(strtoupper($this->dataFrmt)=='JSON'){
			$this->dataOuts = json_encode($dataValues);
		}else{
			$this->dataOuts = serialize($dataValues);
		}
	}
	
	public function buildTmpl( ){
		$tmplFile = realpath(dirname(__FILE__)).DS.'Format'.DS.$this->tmpltNme.'.php';
		if(file_exists($tmplFile)){
			require_once($tmplFile);
			$parseStr = str_replace('%data%',$this->dataOuts,$tmplData);
			$this->finalStr = $parseStr;
		}else{
			// TODO : Raise error
		}
	}
	
	public function buildHead( ){
		if(is_array($headrArr)){
			foreach($this->headrArr as $hedKey => $hedVal){
				header($hedKey.': '.$hedVal);
			}
		}
	}

	public function sendOutpt( ){
		print($this->finalStr);
	}
}