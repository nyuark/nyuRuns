<?php

namespace nyu\Shell;

use nyu\Tools\Logger;

/**
 * A command of the command line (php nyuRun <command> <sub> ...). A
 * command tells what it does and lists its sub-commands; each of them is
 * the method named 'call' followed by its name (callPurge for purge).
 * The framework's commands are listed in Console::CMDSLIST; an
 * application brings its own with runs/<App>/Shell.php, declaring the
 * class Shell of its namespace, which extends this one.
 */
abstract class Command{

	// Exit codes: done, failed, wrong usage, refused or declined, problems found
	const EXITDONE = 0;
	const EXITFAIL = 1;
	const EXITUSGE = 2;
	const EXITDENY = 3;
	const EXITFLAW = 4;

	protected $optnObjc;
	protected $prntObjc;
	protected $inptStrm;

	/**
	 * @param Options $optionsObj The words and options of the command line, after the name of the command.
	 * @param Printer $printerObj Where the command writes.
	 * @param mixed   $inptStream Stream the answers are read from; the standard input when null.
	 */
	function __construct( Options $optionsObj, Printer $printerObj, Mixed $inptStream=null ){
		$this->optnObjc = $optionsObj;
		$this->prntObjc = $printerObj;
		$this->inptStrm = $inptStream ?? STDIN;
	}

	/**
	 * Tells in one line what the command is for.
	 *
	 * @return String The line shown in the list of commands.
	 */
	abstract public function aboutText(): String;

	/**
	 * Lists the sub-commands: for each name, its arguments under 'args'
	 * and what it does under 'about'. A command without sub-commands has
	 * one entry, under an empty name, with the method to run under 'call'.
	 *
	 * @return Array The sub-commands, by name.
	 */
	abstract public function subsTable(): Array;

	/**
	 * Runs the sub-command the command line names. Without one, or with
	 * --usage, the sub-commands are listed.
	 *
	 * @return Int The exit code.
	 *
	 * @throws ShellStop When the sub-command is unknown, or stops itself.
	 */
	public function runCalled(): Int {
		$subList = $this->subsTable();
		if( $this->optnObjc->hasOption('usage') ){
			$this->prntObjc->printRows( Array('sub-command', 'arguments', 'does'), $this->usageRows() );
			return self::EXITDONE;
		}
		if( isset($subList['']) ){
			$callNme = strval($subList['']['call'] ?? '');
		}else{
			$subName = $this->optnObjc->shiftWord();
			if( $subName===null ){
				$this->prntObjc->printRows( Array('sub-command', 'arguments', 'does'), $this->usageRows() );
				return self::EXITDONE;
			}
			if( !isset($subList[$subName]) ){
				$this->stopShell( 'Unknown sub-command: '.$subName, self::EXITUSGE );
			}
			$callNme = 'call'.ucfirst($subName);
		}
		if( !method_exists($this, $callNme) ){
			$this->stopShell( 'The sub-command is declared but not written: '.$callNme, self::EXITFAIL );
		}
		return intval( $this->$callNme() );
	}

	/**
	 * Gives the sub-commands as the rows of a table.
	 *
	 * @return Array Rows of name, arguments and description.
	 */
	public function usageRows(): Array {
		$rowList = Array();
		foreach( $this->subsTable() as $subKey => $subVal ){
			$rowList[] = Array( strval($subKey), strval($subVal['args'] ?? ''), strval($subVal['about'] ?? '') );
		}
		return $rowList;
	}

	/**
	 * Ends the command at once, telling why.
	 *
	 * @param String $stopReason What went wrong.
	 * @param Int    $exitNumber Exit code: one of the EXIT* constants.
	 *
	 * @throws ShellStop Always.
	 */
	protected function stopShell( String $stopReason, Int $exitNumber=self::EXITFAIL ): Never {
		throw new ShellStop($stopReason, $exitNumber);
	}

	/**
	 * Takes the next word of the command line, which the sub-command needs.
	 *
	 * @param String $wordsLabel What the word is, for the message.
	 * @return String The word.
	 *
	 * @throws ShellStop When no word is left.
	 */
	protected function needsWord( String $wordsLabel ): String {
		$gvnWord = $this->optnObjc->shiftWord();
		if( $gvnWord===null || $gvnWord==='' ){
			$this->stopShell( 'Missing argument: '.$wordsLabel, self::EXITUSGE );
		}
		return $gvnWord;
	}

	/**
	 * Asks for a value, proposing a default one: Enter alone takes it.
	 * With --noask nothing is asked, and the default is the answer.
	 *
	 * @param String $askedLabel The question.
	 * @param String $defaultVal Value proposed, taken on Enter.
	 * @return String The answer, without the spaces around it.
	 */
	protected function askAnswer( String $askedLabel, String $defaultVal='' ): String {
		if( $this->optnObjc->hasOption('noask') ){
			return $defaultVal;
		}
		$this->prntObjc->printAsks( $askedLabel.( $defaultVal!=='' ? ' ['.$defaultVal.']' : '' ).': ' );
		$gvnLine = fgets($this->inptStrm);
		$gvnText = ($gvnLine===false) ? '' : trim($gvnLine);
		return ($gvnText==='') ? $defaultVal : $gvnText;
	}

	/**
	 * Asks to confirm something that removes or replaces. --agree is the
	 * yes given beforehand; with --noask and no --agree, the answer is no.
	 *
	 * @param String $askedLabel What is about to be done.
	 * @return Bool Whether it is confirmed.
	 */
	protected function askAgreed( String $askedLabel ): Bool {
		if( $this->optnObjc->hasOption('agree') ){
			return true;
		}
		if( $this->optnObjc->hasOption('noask') ){
			return false;
		}
		$this->prntObjc->printAsks( $askedLabel.' [y/N]: ' );
		$gvnLine = fgets($this->inptStrm);
		return in_array( strtolower(trim(strval($gvnLine))), Array('y', 'yes'), true );
	}

	/**
	 * Asks for a secret, typed and not shown: a star is written for each
	 * character where the terminal allows it. When the answers do not come
	 * from a terminal (a pipe, a file), the line is read as it is.
	 *
	 * @param String $askedLabel The question.
	 * @return String The secret, as typed.
	 */
	protected function askSecret( String $askedLabel ): String {
		$this->prntObjc->printAsks( $askedLabel.': ' );
		$gvnText = null;
		if( $this->inptStrm===STDIN && @stream_isatty(STDIN) ){
			$gvnText = (DIRECTORY_SEPARATOR=='\\') ? $this->readSecur() : $this->readStars();
		}
		if( $gvnText===null ){
			$gvnLine = fgets($this->inptStrm);
			$gvnText = ($gvnLine===false) ? '' : rtrim($gvnLine, "\r\n");
		}
		return $gvnText;
	}

	/**
	 * Reads a secret from a terminal that takes the stty program: each
	 * character is read as it is typed, and answered by a star.
	 *
	 * @return ?String The secret, or null when the terminal cannot be set for it.
	 */
	protected function readStars(): ?String {
		$sttySav = trim( strval(@shell_exec('stty -g 2>/dev/null')) );
		if( $sttySav=='' ){
			return null;
		}
		@shell_exec('stty -icanon -echo 2>/dev/null');
		$gvnText = '';
		while( true ){
			$oneChar = fgetc(STDIN);
			if( $oneChar===false || $oneChar==="\n" || $oneChar==="\r" ){
				break;
			}
			if( $oneChar==="\x7F" || $oneChar==="\x08" ){
				if( $gvnText!=='' ){
					$gvnText = substr($gvnText, 0, -1);
					$this->prntObjc->printAsks("\x08 \x08");
				}
				continue;
			}
			$gvnText .= $oneChar;
			$this->prntObjc->printAsks('*');
		}
		@shell_exec('stty '.escapeshellarg($sttySav).' 2>/dev/null');
		$this->prntObjc->printAsks(PHP_EOL);
		return $gvnText;
	}

	/**
	 * Reads a secret from a Windows console, through PowerShell, which
	 * writes a star for each character typed.
	 *
	 * @return ?String The secret, or null when PowerShell cannot be used: the line is then read as it is typed.
	 */
	protected function readSecur(): ?String {
		$psScrpt = '[Console]::OutputEncoding=[Text.Encoding]::UTF8;$p=Read-Host -AsSecureString;'
			.'$b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p);'
			.'[Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b))';
		$pipeArr = Array();
		$process = @proc_open( Array('powershell', '-NoProfile', '-Command', $psScrpt), Array(0=>STDIN, 1=>Array('pipe', 'w'), 2=>Array('pipe', 'w')), $pipeArr );
		if( !is_resource($process) ){
			return null;
		}
		$gvnText = stream_get_contents($pipeArr[1]);
		fclose($pipeArr[1]);
		fclose($pipeArr[2]);
		return ( proc_close($process)===0 && is_string($gvnText) ) ? $gvnText : null;
	}

	/**
	 * Traces what the command changed, on the 'shell' log channel, signed
	 * with the user of the system who ran it. Secrets never go there.
	 *
	 * @param String $eventMssge What was done.
	 */
	protected function traceDone( String $eventMssge ): Void {
		Logger::getLogger('shell')->notice( $eventMssge.Logger::actorText() );
	}

	/**
	 * Reads a block of the ACCESS config.
	 *
	 * @param String $blockNamed Name of the block.
	 * @return Array Its values, empty when there is no such block.
	 */
	protected function accessCnf( String $blockNamed ): Array {
		$nyuConf = $GLOBALS['nyuConfig'] ?? null;
		$blkConf = ($nyuConf!==null && $blockNamed!='') ? $nyuConf->getRowVal('ACCESS', strtoupper($blockNamed)) : null;
		return is_array($blkConf) ? $blkConf : Array();
	}
}
