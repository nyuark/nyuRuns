<?php

namespace nyu\Setup;

use \nyu\Data\Dcmnt;
use \nyu\Data\Parsers\ParseError;

/**
 * A utility object for loading the schemes of a preset during the runs.
 */
class Preset{

	// The keys a preset may hold, block by block; 'incoming' is a list of sources, each holding those keys
	const KEYS_LIST = Array(
		''         => Array('presetId', 'synopsis', 'settings', 'security', 'incoming', 'dispatch', 'response'),
		'settings' => Array('confType', 'confList', 'confPath', 'envrFile'),
		'security' => Array('required', 'sessWith', 'sessConf', 'authWith', 'authConf', 'openList', 'signRout', 'rteRoles', 'appsConf', 'isPublic'),
		'incoming' => Array('nameFrom', 'dataFrom', 'fieldSet'),
		'dispatch' => Array('detectBy', 'pathForm', 'routeDir', 'fileRoot', 'runMount'),
		'response' => Array('outputAs', 'corsList', 'headList')
	);

	protected $presetData;
	// Name of the preset, which the config cache is kept under
	protected $presetName;

    /**
	 * Loads and parses the named RPS preset file.
	 *
	 * @param String $nameOfPreset Name of the preset file (without extension) to load; defaults to 'default'.
	 * @throws \RuntimeException When the name is not valid, or the preset file is missing, not valid JSON, or empty.
	 */
    function __construct(String $nameOfPreset = 'default'){
		if($nameOfPreset==''){
			$nameOfPreset = 'default';
		}
		$this->readPresetFile($nameOfPreset);
		$this->presetName = $nameOfPreset;
	}

	/**
	 * Reads the .rps file for the given preset name into rpsArray: JSON,
	 * in which a line starting with `#` or `//` is a comment.
	 *
	 * @param String $nameOfPreset Name of the preset file (without extension) to parse: letters, digits, `_` and `-`.
	 * @throws \RuntimeException When the name is not valid, or the preset file is missing, not valid JSON, or empty.
	 */
	protected function readPresetFile(String $nameOfPreset): Void {
		// The name goes into a path : nothing that could lead out of the presets folder
		if( !preg_match('/^[\w\-]+$/', $nameOfPreset) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" has no valid name');
		}
		$rpsFile = TOP_DIR.DS.'conf'.DS.'presets'.DS.$nameOfPreset.'.rps';
		if( !is_file($rpsFile) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" is missing');
		}
		try{
			// The names of the blocks are kept as written
			$jsonDoc = new Dcmnt($rpsFile, false);
		}catch( ParseError $e ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" is not valid JSON: '.$e->getMessage(), 0, $e);
		}
		$this->presetData = $jsonDoc->readFileDetail();
		if( !is_array($this->presetData) || empty($this->presetData) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" is empty');
		}
		$this->checkKeysArray($nameOfPreset);
	}

	/**
	 * Checks that the preset holds no key the framework does not know: a
	 * key left from an older version, or misspelt, would otherwise be
	 * ignored without a word.
	 *
	 * @param String $nameOfPreset Name of the preset, for the message.
	 * @throws \RuntimeException When a key is unknown, or a block is not a set of keys.
	 */
	protected function checkKeysArray(String $nameOfPreset): Void {
		foreach( $this->presetData as $blkKey => $blkVal ){
			if( !in_array($blkKey, self::KEYS_LIST[''], true) ){
				throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds an unknown key: '.$blkKey);
			}
			if( !isset(self::KEYS_LIST[$blkKey]) ){
				continue;
			}
			if( !is_array($blkVal) ){
				throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds a block that is not a set of keys: '.$blkKey);
			}
			// The sources of the inputs are a list, each checked as a block
			foreach( ($blkKey=='incoming' ? $blkVal : Array($blkVal)) as $setVal ){
				foreach( array_keys((array)$setVal) as $setKey ){
					if( !in_array($setKey, self::KEYS_LIST[$blkKey], true) ){
						throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds an unknown key: '.$blkKey.'.'.$setKey);
					}
				}
			}
		}
		// The mounts are an object, Run name => false or a path prefix (empty for the root): anything else would only fail at the first request
		$mntList = $this->presetData['dispatch']['runMount'] ?? Array();
		if( !is_array($mntList) || (!empty($mntList) && array_is_list($mntList)) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds mounts that are not an object of applications: '.json_encode($mntList, JSON_UNESCAPED_SLASHES));
		}
		foreach( $mntList as $mntKey => $mntVal ){
			if( $mntVal!==false && (!is_string($mntVal) || !preg_match('#^[A-Za-z0-9_\-/]*$#D', $mntVal)) ){
				throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds a mount that is not false or a path prefix for the application '.json_encode(strval($mntKey)).': '.json_encode($mntVal, JSON_UNESCAPED_SLASHES));
			}
		}
		// A format the reply cannot be written in would only fail at the first request
		$frmtVal = $this->presetData['response']['outputAs'] ?? 'jsonData';
		if( !is_string($frmtVal) || empty(preg_grep('/^'.preg_quote($frmtVal, '/').'$/iD', array_keys(\nyu\Output\Response::FORM_LIST))) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds an unknown output format: '.(is_scalar($frmtVal) ? strval($frmtVal) : gettype($frmtVal)).'; one of '.implode(', ', array_keys(\nyu\Output\Response::FORM_LIST)));
		}
	}

    /**
	 * Instanciates the setup class (Setup\Loader) with the parsed preset
	 * data and returns it.
	 *
	 * @return Loader The configured setup loader.
	 */
    public function newSetupLoader(): Loader {
		// Instanciate the setup class with the
		// array of data and return the object
		$nyuSetp = new \nyu\Setup\Loader( $this->presetData, null, $this->presetName );
		return $nyuSetp;
	}
}
