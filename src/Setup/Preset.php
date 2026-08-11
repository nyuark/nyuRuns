<?php

namespace nyu\Setup;

use \nyu\Data\Dcmnt;
use \nyu\Data\Parsers\ParseError;

/**
 * A utility object for loading the schemes of a preset during the runs.
 */
class Preset{

	// The keys a preset may hold, block by block; 'input' is a list of sources, each holding those keys
	const KEYSLIST = Array(
		''      => Array('label', 'about', 'setup', 'auths', 'input', 'route', 'rnder'),
		'setup' => Array('ftypes', 'fnames', 'folder', 'envfle'),
		'auths' => Array('needed', 'sessby', 'sesscf', 'authby', 'authcf', 'openrt', 'signin', 'rolert'),
		'input' => Array('naming', 'source', 'fields'),
		'route' => Array('detect', 'mapper', 'folder', 'htroot'),
		'rnder' => Array('format', 'origns', 'headrs')
	);

	protected $rpsArray;

    /**
	 * Loads and parses the named RPS preset file.
	 *
	 * @param String $presetName Name of the preset file (without extension) to load; defaults to 'dfault'.
	 * @throws \RuntimeException When the name is not valid, or the preset file is missing, not valid JSON, or empty.
	 */
    function __construct(String $presetName = 'dfault'){
		if($presetName==''){
			$presetName = 'dfault';
		}
		$this->rpsParser($presetName);
	}

	/**
	 * Reads the .rps file for the given preset name into rpsArray: JSON,
	 * in which a line starting with `#` or `//` is a comment.
	 *
	 * @param String $presetName Name of the preset file (without extension) to parse: letters, digits, `_` and `-`.
	 * @throws \RuntimeException When the name is not valid, or the preset file is missing, not valid JSON, or empty.
	 */
	protected function rpsParser(String $presetName): Void {
		// The name goes into a path : nothing that could lead out of the presets folder
		if( !preg_match('/^[\w\-]+$/', $presetName) ){
			throw new \RuntimeException('Preset "'.$presetName.'" has no valid name');
		}
		$rpsFile = TOP_DIR.DS.'conf'.DS.'presets'.DS.$presetName.'.rps';
		if( !is_file($rpsFile) ){
			throw new \RuntimeException('Preset "'.$presetName.'" is missing');
		}
		try{
			$rpsDocu = new Dcmnt($rpsFile);
			// The names of the blocks are kept as written
			$rpsDocu->fileParse('rps', false);
		}catch( ParseError $e ){
			throw new \RuntimeException('Preset "'.$presetName.'" is not valid JSON: '.$e->getMessage(), 0, $e);
		}
		$this->rpsArray = $rpsDocu->readValue();
		if( !is_array($this->rpsArray) || empty($this->rpsArray) ){
			throw new \RuntimeException('Preset "'.$presetName.'" is empty');
		}
		$this->checkKeys($presetName);
	}

	/**
	 * Checks that the preset holds no key the framework does not know: a
	 * key left from an older version, or misspelt, would otherwise be
	 * ignored without a word.
	 *
	 * @param String $presetName Name of the preset, for the message.
	 * @throws \RuntimeException When a key is unknown, or a block is not a set of keys.
	 */
	protected function checkKeys(String $presetName): Void {
		foreach( $this->rpsArray as $blkKey => $blkVal ){
			if( !in_array($blkKey, self::KEYSLIST[''], true) ){
				throw new \RuntimeException('Preset "'.$presetName.'" holds an unknown key: '.$blkKey);
			}
			if( !isset(self::KEYSLIST[$blkKey]) ){
				continue;
			}
			if( !is_array($blkVal) ){
				throw new \RuntimeException('Preset "'.$presetName.'" holds a block that is not a set of keys: '.$blkKey);
			}
			// The sources of the inputs are a list, each checked as a block
			foreach( ($blkKey=='input' ? $blkVal : Array($blkVal)) as $setVal ){
				foreach( array_keys((array)$setVal) as $setKey ){
					if( !in_array($setKey, self::KEYSLIST[$blkKey], true) ){
						throw new \RuntimeException('Preset "'.$presetName.'" holds an unknown key: '.$blkKey.'.'.$setKey);
					}
				}
			}
		}
		// A format the reply cannot be written in would only fail at the first request
		$frmtVal = $this->rpsArray['rnder']['format'] ?? 'json';
		if( !is_string($frmtVal) || !array_key_exists(strtolower($frmtVal), \nyu\Output\Response::FORMTLST) ){
			throw new \RuntimeException('Preset "'.$presetName.'" holds an unknown output format: '.(is_scalar($frmtVal) ? strval($frmtVal) : gettype($frmtVal)).'; one of '.implode(', ', array_keys(\nyu\Output\Response::FORMTLST)));
		}
	}

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
