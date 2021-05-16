<?php

// An utility object for loading schemes of preset during the runs

namespace nyu\Setup;

use \nyu\Setup;

class Preset{

	protected $rpsArray;

    /**
	 * Loads and parses the named RPS preset file.
	 *
	 * @param String $presetName Name of the preset file (without extension) to load; defaults to 'default'.
	 */
    function __construct(String $presetName = 'default'){
		if($presetName==''){
			$presetName = 'default';
		}
		$this->parseFile($presetName);
	}

	/**
	 * Reads the .rps file for the given preset name, strips out `#` and
	 * `//` comment lines, and decodes the remaining JSON into rpsArray.
	 *
	 * @param String $presetName Name of the preset file (without extension) to parse.
	 * @return Bool True if the preset was parsed into a non-empty array, false otherwise.
	 */
	protected function parseFile(String $presetName): Bool {
		$rpsFile = TOP_DIR.DS.'conf'.DS.'presets'.DS.$presetName.'.rps';
		if(file_exists($rpsFile)){
			$jsnLine = file($rpsFile);
			// Strip comments from RPS file
			foreach($jsnLine as $lneNum=>$lneTxt){
				$lneTxt = trim($lneTxt);
				if($lneTxt=='' || $lneTxt[0]=='#' || substr($lneTxt,0,2)=='//'){
					unset($jsnLine[$lneNum]);
				}
			}
			$jsnData = implode("", $jsnLine);
			$this->rpsArray = json_decode($jsnData,true);
			// TODO : Throw Error if no data returned
			if(is_array($this->rpsArray) && !empty($this->rpsArray)){
				return true;
			}else{
				return false;
			}
		}else{
			// Raise exception if file not found
			return false;
		}
	}

 //   public function genSetup(){
    /**
	 * Instanciates the setup class (Setup\Loader) with the parsed preset
	 * data and returns it.
	 *
	 * @return Loader The configured setup loader.
	 */
    public function makeSetup(): Loader {
		// Instanciate the setup class with the
		// array of data and return the object
		$nyuStup = new \nyu\Setup\Loader( $this->rpsArray );
		return $nyuStup;
	}
}
