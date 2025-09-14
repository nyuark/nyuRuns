<?php

namespace nyu\Shell;

/**
 * What a command line holds after the name of its script: plain words,
 * read in order, and options written --name or --name=value. A lone --
 * ends the options: what follows is words, whatever it starts with.
 */
class Options{

	protected $wordList = Array();
	protected $optnList = Array();

	/**
	 * @param Array $givenWords The arguments of the command line, without the name of the script.
	 */
	function __construct( Array $givenWords ){
		$optsEnd = false;
		foreach( $givenWords as $gvnWrd ){
			$gvnWrd = strval($gvnWrd);
			if( !$optsEnd && $gvnWrd==='--' ){
				$optsEnd = true;
				continue;
			}
			if( !$optsEnd && strlen($gvnWrd)>2 && substr($gvnWrd, 0, 2)==='--' ){
				$optPart = explode('=', substr($gvnWrd, 2), 2);
				$this->optnList[$optPart[0]] = $optPart[1] ?? true;
				continue;
			}
			$this->wordList[] = $gvnWrd;
		}
	}

	/**
	 * Takes the next word off the command line.
	 *
	 * @return ?String The word, or null when none is left.
	 */
	public function shiftWord(): ?String {
		return array_shift($this->wordList);
	}

	/**
	 * Gives the words not taken yet, and leaves them.
	 *
	 * @return Array The words, in order.
	 */
	public function restWords(): Array {
		return $this->wordList;
	}

	/**
	 * Tells whether an option was written, with or without a value.
	 *
	 * @param String $optionName Name of the option, without its dashes.
	 * @return Bool True when it was written.
	 */
	public function hasOption( String $optionName ): Bool {
		return isset($this->optnList[$optionName]);
	}

	/**
	 * Gives the value of an option.
	 *
	 * @param String  $optionName Name of the option, without its dashes.
	 * @param ?String $defaultVal Value given when the option was not written, or written without a value.
	 * @return ?String The value.
	 */
	public function getOption( String $optionName, ?String $defaultVal=null ): ?String {
		$optnVal = $this->optnList[$optionName] ?? null;
		return is_string($optnVal) ? $optnVal : $defaultVal;
	}
}
