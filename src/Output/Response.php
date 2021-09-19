<?php

namespace nyu\Output;

class Response{

	protected $dataFrmt;
	protected $tmpltNme;
	protected $sections;
	protected $headrArr;

	protected $finalStr;
	protected $dataOuts;
	
	/**
	 * Configures the response from the 'rnder' preset section.
	 *
	 * @param Array $renderVals Render configuration with 'headrs' (response headers), 'tmplte' (template name), and 'return' (output format, e.g. 'json') keys.
	 */
	public function __construct( Array $renderVals=Array() ){
		$this->headrArr = $renderVals['headrs'] ?? Array();
		$this->tmpltNme = $renderVals['tmplte'] ?? 'plain';
		$this->dataFrmt = $renderVals['return'] ?? 'json';
	}

	/**
	 * Adds or overrides a single response header, on top of the
	 * preset-configured ones.
	 *
	 * @param String $headersKey Header name.
	 * @param String $headersVal Header value.
	 */
	public function addHeader( String $headersKey, String $headersVal ): Void {
		if(!is_array($this->headrArr)){
			$this->headrArr = Array();
		}
		$this->headrArr[$headersKey] = $headersVal;
	}

	/**
	 * Encodes the given data as the configured output format (JSON, or
	 * PHP-serialized as a fallback).
	 *
	 * @param Array $dataValues The data to encode.
	 */
	public function formatOut( Array $dataValues = Array() ): Void {
		if(strtoupper($this->dataFrmt)=='JSON'){
			$this->dataOuts = json_encode($dataValues);
		}else{
			$this->dataOuts = serialize($dataValues);
		}
	}
	
	
	/**
	 * Formats a set of output sections (currently identical to
	 * formatOut's encoding logic).
	 *
	 * @param Array $sectionArr Sections to format.
	 */
	public function sectsList( Array $sectionArr = Array() ): Void {
		if(strtoupper($this->dataFrmt)=='JSON'){
			$this->dataOuts = json_encode($sectionArr);
		}else{
			$this->dataOuts = serialize($sectionArr);
		}
	}
	
	/**
	 * Loads the configured output template file and substitutes the
	 * formatted data into its `%data%` placeholder. An unknown template
	 * raises a warning and the 'plain' one is used instead.
	 */
	public function buildTmpl( ): Void {
		$tplFile = realpath(dirname(__FILE__)).DS.'Format'.DS.$this->tmpltNme.'.php';
		if(!file_exists($tplFile)){
			// Unknown template : the data is sent as is, and the mistake is reported
			trigger_error('Unknown output template "'.$this->tmpltNme.'", using "plain"', E_USER_WARNING);
			$this->tmpltNme = 'plain';
			$tplFile = realpath(dirname(__FILE__)).DS.'Format'.DS.'plain.php';
		}
		require($tplFile);
		$parsStr = str_replace('%data%',$this->dataOuts,$tplData);
		$this->finalStr = $parsStr;
	}
	
	/**
	 * Sends each configured response header, a default Content-Type when
	 * the preset sets none, and the HTTP status code when one is given.
	 *
	 * @param Int $statusCode HTTP status code to send; omitted for the default 200.
	 */
	public function buildHead( ?Int $statusCode=null ): Void {
		if($statusCode!=null){
			http_response_code($statusCode);
		}
		if(!is_array($this->headrArr)){
			$this->headrArr = Array();
		}
		// Default Content-Type, unless the preset sets its own
		$hasType = in_array('content-type', array_map('strtolower', array_keys($this->headrArr)));
		if( !$hasType ){
			$this->headrArr['Content-Type'] = $this->contnType();
		}
		foreach($this->headrArr as $hedKey => $hedVal){
			header($hedKey.': '.$hedVal);
		}
	}

	/**
	 * Resolves the Content-Type matching the configured template and
	 * output format.
	 *
	 * @return String The Content-Type header value.
	 */
	protected function contnType(): String {
		$tmplTyp = Array(
			'excel'=>'application/vnd.ms-excel',
			'mswrd'=>'application/msword',
			'optab'=>'application/vnd.oasis.opendocument.spreadsheet'
		);
		if( isset($tmplTyp[$this->tmpltNme]) ){
			return $tmplTyp[$this->tmpltNme];
		}
		if(strtoupper($this->dataFrmt)=='JSON'){
			return 'application/json; charset=utf-8';
		}
		return 'text/plain; charset=utf-8';
	}

	/**
	 * Prints the final formatted response string to the client.
	 */
	public function sendOutpt( ): Void {
		print($this->finalStr);
	}
}