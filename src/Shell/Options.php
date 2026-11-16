<?php

namespace nyu\Shell;

/**
 * What a command line holds after the name of its script: plain words,
 * read in order, and options written --name or --name=value. A lone --
 * ends the options: what follows is words, whatever it starts with.
 */
class Options{

	protected $shellWords = Array();
	protected $optionList = Array();
	// For each option written without a value, the plain word that follows it directly: a value written after a space
	protected $wordsAfter = Array();

	/**
	 * Reads the words and options of a command line.
	 *
	 * @param Array $commandWords The arguments of the command line, without the name of the script.
	 */
	function __construct( Array $commandWords ){
		$optiDone = false;
		$lastOpti = '';
		foreach( $commandWords as $oneWrd ){
			$oneWrd = strval($oneWrd);
			if( !$optiDone && $oneWrd==='--' ){
				$optiDone = true;
				continue;
			}
			if( !$optiDone && strlen($oneWrd)>2 && substr($oneWrd, 0, 2)==='--' ){
				$optiWord = explode('=', substr($oneWrd, 2), 2);
				$this->optionList[$optiWord[0]] = $optiWord[1] ?? true;
				$lastOpti = isset($optiWord[1]) ? '' : $optiWord[0];
				continue;
			}
			if( $lastOpti!=='' && !$optiDone ){
				$this->wordsAfter[$lastOpti] = $oneWrd;
			}
			$lastOpti = '';
			$this->shellWords[] = $oneWrd;
		}
	}

	/**
	 * Takes the next word off the command line.
	 *
	 * @return ?String The word, or null when none is left.
	 */
	public function shiftFirstWord(): ?String {
		return array_shift($this->shellWords);
	}

	/**
	 * Gives the words not taken yet, and leaves them.
	 *
	 * @return Array The words, in order.
	 */
	public function getRestOfWords(): Array {
		return $this->shellWords;
	}

	/**
	 * Tells whether an option was written, with or without a value.
	 *
	 * @param String $nameOfOption Name of the option, without its dashes.
	 * @return Bool True when it was written.
	 */
	public function isOptionPassed( String $nameOfOption ): Bool {
		return isset($this->optionList[$nameOfOption]);
	}

	/**
	 * Gives the value of an option.
	 *
	 * @param String  $nameOfOption Name of the option, without its dashes.
	 * @param ?String $defaultValue Value given when the option was not written, or written without a value.
	 * @return ?String The value.
	 *
	 * @throws ShellStop When the option was written without a value and a plain word follows it: a value given after a space, which would be ignored without a word.
	 */
	public function getOptionValue( String $nameOfOption, ?String $defaultValue=null ): ?String {
		$optsVals = $this->optionList[$nameOfOption] ?? null;
		if( $optsVals===true && isset($this->wordsAfter[$nameOfOption]) ){
			throw new ShellStop('The option --'.$nameOfOption.' takes its value after an equals sign: --'.$nameOfOption.'=<value>', Command::EXIT_HELP);
		}
		return is_string($optsVals) ? $optsVals : $defaultValue;
	}
}
