<?php

// An utility object for loading schemes of preset values in the application

namespace nyu\Tools;

use \nyu\Setup;

class Preset{
	
	protected $rpsArray;
	
    function __construct(String $presetName = 'default'){
		if($presetName==''){
			$presetName = 'default';
		}
		$this->parseRPS($presetName);
	}
	
	protected function parseRPS(String $presetName){
		$fileName = TOP_DIR.DS.'conf'.DS.'presets'.DS.$presetName.'.rps';
		if(file_exists($fileName)){
			$jsonLine = file($fileName);
			// Strip comments from RPS file
			foreach($jsonLine as $lneNum=>$lneTxt){
				$lneTxt = trim($lneTxt);
				if($lneTxt[0]=='#' || $lneTxt[0].$lneTxt[1]=='//'){
					unset($jsonLine[$lneNum]);
				}
			}
			$jsonData = implode("\n", $jsonLine);
			$this->rpsArray = json_decode($jsonData,true);
			if(is_array($this->rpsArray) && !empty($this->rpsArray)){
				return true;
			}else{
				return false;
			}
		}else{
			return false;
		}
	}
	
 //   public function genSetup(){
    public function generate(){
		// Instanciate the setup class with the 
		// array of data and return the object
		$nyuSetup = new \nyu\Setup\Loader( $this->rpsArray );
		return $nyuSetup;
	}
}