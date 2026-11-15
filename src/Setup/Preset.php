<?php

namespace nyu\Setup;

use \nyu\Data\Dcmnt;
use \nyu\Data\Parsers\ParseError;

/**
 * A utility object for loading the schemes of a preset during the runs.
 */
class Preset{

	// The keys a preset may hold, block by block; 'inputSet' is a list of sources, each holding those keys
	const KEYS_LIST = Array(
		''         => Array('presetId', 'synopsis', 'settings', 'security', 'inputSet', 'dispatch', 'response'),
		'settings' => Array('confType', 'confList', 'confPath', 'envrFile'),
		'security' => Array('required', 'carrWith', 'carrConf', 'authWith', 'authConf', 'openList', 'signPath', 'minRoles', 'appsConf', 'isPublic'),
		'inputSet' => Array('sourceId', 'readFrom', 'fieldSet'),
		'dispatch' => Array('detectBy', 'pathForm', 'routeDir', 'fileRoot', 'runMount'),
		'response' => Array('outputAs', 'corsList', 'headList')
	);

	// The preset that only explains the keys of a preset, as an example: read, never loaded
	const XMPL_NAME = 'example';

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
		$psetFile = TOP_DIR.DS.'conf'.DS.'presets'.DS.$nameOfPreset.'.rps';
		if( !is_file($psetFile) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" is missing');
		}
		try{
			// The names of the blocks are kept as written
			$jsonDocu = new Dcmnt($psetFile, false);
		}catch( ParseError $e ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" is not valid JSON: '.$e->getMessage(), 0, $e);
		}
		$this->presetData = $jsonDocu->readFileDetail();
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
		foreach( $this->presetData as $oneKey => $oneVal ){
			if( !in_array($oneKey, self::KEYS_LIST[''], true) ){
				throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds an unknown key: '.$oneKey);
			}
			if( !isset(self::KEYS_LIST[$oneKey]) ){
				continue;
			}
			if( !is_array($oneVal) ){
				throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds a block that is not a set of keys: '.$oneKey);
			}
			// The sources of the inputs are a list, each checked as a block
			foreach( ($oneKey=='inputSet' ? $oneVal : Array($oneVal)) as $oneItm ){
				foreach( array_keys((array)$oneItm) as $oneSet ){
					if( !in_array($oneSet, self::KEYS_LIST[$oneKey], true) ){
						throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds an unknown key: '.$oneKey.'.'.$oneSet);
					}
				}
			}
		}
		// The mounts are an object, Run name => false or a path prefix (empty for the root): anything else would only fail at the first request
		$mountLst = $this->presetData['dispatch']['runMount'] ?? Array();
		if( !is_array($mountLst) || (!empty($mountLst) && array_is_list($mountLst)) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds mounts that are not an object of applications: '.json_encode($mountLst, JSON_UNESCAPED_SLASHES));
		}
		foreach( $mountLst as $oneKey => $oneVal ){
			if( $oneVal!==false && (!is_string($oneVal) || !preg_match('#^[A-Za-z0-9_\-/]*$#D', $oneVal)) ){
				throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds a mount that is not false or a path prefix for the application '.json_encode(strval($oneKey)).': '.json_encode($oneVal, JSON_UNESCAPED_SLASHES));
			}
		}
		// A format the reply cannot be written in would only fail at the first request
		$frmtVals = $this->presetData['response']['outputAs'] ?? 'jsonData';
		if( !is_string($frmtVals) || empty(preg_grep('/^'.preg_quote($frmtVals, '/').'$/iD', array_keys(\nyu\Output\Response::FORM_LIST))) ){
			throw new \RuntimeException('Preset "'.$nameOfPreset.'" holds an unknown output format: '.(is_scalar($frmtVals) ? strval($frmtVals) : gettype($frmtVals)).'; one of '.implode(', ', array_keys(\nyu\Output\Response::FORM_LIST)));
		}
	}

    /**
	 * Instanciates the setup class (Setup\Loader) with the parsed preset
	 * data and returns it. The example preset only explains the keys: it is
	 * read, never loaded.
	 *
	 * @return Loader The configured setup loader.
	 * @throws \RuntimeException When the preset is the example (XMPL_NAME).
	 */
    public function makeConfLoader(): Loader {
		// Whatever its case: a file system that ignores it opens the same file
		if( strcasecmp(strval($this->presetName), self::XMPL_NAME)==0 ){
			throw new \RuntimeException('Preset "'.self::XMPL_NAME.'" only explains the keys of a preset: it is never loaded');
		}
		// Instanciate the setup class with the
		// array of data and return the object
		$nyuSetup = new \nyu\Setup\Loader( $this->presetData, null, $this->presetName );
		return $nyuSetup;
	}
}
