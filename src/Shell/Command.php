<?php

namespace nyu\Shell;

use nyu\Tools\Logger;

/**
 * A command of the command line (php nyuRun <command> <sub> ...). A
 * command tells what it does and lists its sub-commands; each of them is
 * the method named 'invoke' followed by its name (invokeCleanDir for cleanDir).
 * The framework's commands are listed in Console::CMDS_LIST; an
 * application brings its own with runs/<App>/Shell.php, declaring the
 * class Shell of its namespace, which extends this one.
 */
abstract class Command{

	// Exit codes: done, failed, wrong usage, refused or declined, problems found
	const EXIT_DONE = 0;
	const EXIT_FAIL = 1;
	const EXIT_HELP = 2;
	const EXIT_DENY = 3;
	const EXIT_FLAW = 4;

	protected $optionsObj;
	protected $printerObj;
	protected $readStream;

	/**
	 * Builds the command with its command line and where it writes.
	 *
	 * @param Options $givenOptions The words and options of the command line, after the name of the command.
	 * @param Printer $givenPrinter Where the command writes.
	 * @param mixed   $answerStream Stream the answers are read from; the standard input when null.
	 */
	function __construct( Options $givenOptions, Printer $givenPrinter, Mixed $answerStream=null ){
		$this->optionsObj = $givenOptions;
		$this->printerObj = $givenPrinter;
		$this->readStream = $answerStream ?? STDIN;
	}

	/**
	 * Tells in one line what the command is for.
	 *
	 * @return String The line shown in the list of commands.
	 */
	abstract public function describeInLine(): String;

	/**
	 * Lists the sub-commands: for each name, its arguments under 'args'
	 * and what it does under 'about'. A command without sub-commands has
	 * one entry, under an empty name, with the method to run under 'call';
	 * a sub-command may name its method under 'call' too, which is then
	 * given the word of the sub-command.
	 *
	 * @return Array The sub-commands, by name.
	 */
	abstract public function subsTableArray(): Array;

	/**
	 * Runs the sub-command the command line names. Without one, or with
	 * --helpText, the sub-commands are listed.
	 *
	 * @return Int The exit code.
	 *
	 * @throws ShellStop When the sub-command is unknown, or stops itself.
	 */
	public function runCalledCount(): Int {
		$subLstng = $this->subsTableArray();
		if( $this->optionsObj->validateOption('helpText') ){
			$this->printerObj->printShellRows( Array('sub-command', 'arguments', 'does'), $this->usageRowsArray() );
			return self::EXIT_DONE;
		}
		if( isset($subLstng['']) ){
			$callName = strval($subLstng['']['call'] ?? '');
		}else{
			$subTitle = $this->optionsObj->shiftFirstWord();
			if( $subTitle===null ){
				$this->printerObj->printShellRows( Array('sub-command', 'arguments', 'does'), $this->usageRowsArray() );
				return self::EXIT_DONE;
			}
			if( !isset($subLstng[$subTitle]) ){
				$this->terminateShell( 'Unknown sub-command: '.$subTitle, self::EXIT_HELP );
			}
			// A sub-command the command lists as it runs (one a project declared) names the method it shares with others
			$callName = strval( $subLstng[$subTitle]['call'] ?? 'invoke'.ucfirst($subTitle) );
		}
		if( !method_exists($this, $callName) ){
			$this->terminateShell( 'The sub-command is declared but not written: '.$callName, self::EXIT_FAIL );
		}
		// The method is given the word of its sub-command, which a shared method needs to know which one it runs
		return intval( $this->$callName( $subTitle ?? '' ) );
	}

	/**
	 * Gives the sub-commands as the rows of a table.
	 *
	 * @return Array Rows of name, arguments and description.
	 */
	public function usageRowsArray(): Array {
		$rowsList = Array();
		foreach( $this->subsTableArray() as $subKey => $subVal ){
			$rowsList[] = Array( strval($subKey), strval($subVal['args'] ?? ''), strval($subVal['about'] ?? '') );
		}
		return $rowsList;
	}

	/**
	 * Ends the command at once, telling why.
	 *
	 * @param String $reasonToStop What went wrong.
	 * @param Int    $shellExitNum Exit code: one of the EXIT* constants.
	 *
	 * @throws ShellStop Always.
	 */
	protected function terminateShell( String $reasonToStop, Int $shellExitNum=self::EXIT_FAIL ): Never {
		throw new ShellStop($reasonToStop, $shellExitNum);
	}

	/**
	 * Takes the next word of the command line, which the sub-command needs.
	 *
	 * @param String $wordTypeText What the word is, for the message.
	 * @return String The word.
	 *
	 * @throws ShellStop When no word is left.
	 */
	protected function needsShellWord( String $wordTypeText ): String {
		$givenWrd = $this->optionsObj->shiftFirstWord();
		if( $givenWrd===null || $givenWrd==='' ){
			$this->terminateShell( 'Missing argument: '.$wordTypeText, self::EXIT_HELP );
		}
		return $givenWrd;
	}

	/**
	 * Asks for a value, proposing a default one: Enter alone takes it.
	 * With --noPrompt nothing is asked, and the default is the answer.
	 *
	 * @param String $questionText The question.
	 * @param String $defaultValue Value proposed, taken on Enter.
	 * @return String The answer, without the spaces around it.
	 */
	protected function queryAnswerTxt( String $questionText, String $defaultValue='' ): String {
		if( $this->optionsObj->validateOption('noPrompt') ){
			return $defaultValue;
		}
		$this->printerObj->printShellAsks( $questionText.( $defaultValue!=='' ? ' ['.$defaultValue.']' : '' ).': ' );
		$givenLne = fgets($this->readStream);
		$givenTxt = ($givenLne===false) ? '' : trim($givenLne);
		return ($givenTxt==='') ? $defaultValue : $givenTxt;
	}

	/**
	 * Asks to confirm something that removes or replaces. --yesToAll is the
	 * yes given beforehand; with --noPrompt and no --yesToAll, the answer is no.
	 *
	 * @param String $questionText What is about to be done.
	 * @return Bool Whether it is confirmed.
	 */
	protected function queryAgreedFlg( String $questionText ): Bool {
		if( $this->optionsObj->validateOption('yesToAll') ){
			return true;
		}
		if( $this->optionsObj->validateOption('noPrompt') ){
			return false;
		}
		$this->printerObj->printShellAsks( $questionText.' [y/N]: ' );
		$givenLne = fgets($this->readStream);
		return in_array( strtolower(trim(strval($givenLne))), Array('y', 'yes'), true );
	}

	/**
	 * Asks for a secret, typed and not shown: a star is written for each
	 * character where the terminal allows it. When the answers do not come
	 * from a terminal (a pipe, a file), the line is read as it is.
	 *
	 * @param String $questionText The question.
	 * @return String The secret, as typed.
	 */
	protected function querySecretTxt( String $questionText ): String {
		$this->printerObj->printShellAsks( $questionText.': ' );
		$givenTxt = null;
		if( $this->readStream===STDIN && @stream_isatty(STDIN) ){
			$givenTxt = (DIRECTORY_SEPARATOR=='\\') ? $this->obtainSecurity() : $this->getStarsString();
		}
		if( $givenTxt===null ){
			$givenLne = fgets($this->readStream);
			$givenTxt = ($givenLne===false) ? '' : rtrim($givenLne, "\r\n");
		}
		return $givenTxt;
	}

	/**
	 * Reads a secret from a terminal that takes the stty program: each
	 * character is read as it is typed, and answered by a star.
	 *
	 * @return ?String The secret, or null when the terminal cannot be set for it.
	 */
	protected function getStarsString(): ?String {
		$prevStty = trim( strval(@shell_exec('stty -g 2>/dev/null')) );
		if( $prevStty=='' ){
			return null;
		}
		@shell_exec('stty -icanon -echo 2>/dev/null');
		$givenTxt = '';
		while( true ){
			$oneChrct = fgetc(STDIN);
			if( $oneChrct===false || $oneChrct==="\n" || $oneChrct==="\r" ){
				break;
			}
			if( $oneChrct==="\x7F" || $oneChrct==="\x08" ){
				if( $givenTxt!=='' ){
					$givenTxt = substr($givenTxt, 0, -1);
					$this->printerObj->printShellAsks("\x08 \x08");
				}
				continue;
			}
			$givenTxt .= $oneChrct;
			$this->printerObj->printShellAsks('*');
		}
		@shell_exec('stty '.escapeshellarg($prevStty).' 2>/dev/null');
		$this->printerObj->printShellAsks(PHP_EOL);
		return $givenTxt;
	}

	/**
	 * Reads a secret from a Windows console, through PowerShell, which
	 * writes a star for each character typed.
	 *
	 * @return ?String The secret, or null when PowerShell cannot be used: the line is then read as it is typed.
	 */
	protected function obtainSecurity(): ?String {
		$psScript = '[Console]::OutputEncoding=[Text.Encoding]::UTF8;$p=Read-Host -AsSecureString;'
			.'$b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p);'
			.'[Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b))';
		$pipeList = Array();
		$procHndl = @proc_open( Array('powershell', '-NoProfile', '-Command', $psScript), Array(0=>STDIN, 1=>Array('pipe', 'w'), 2=>Array('pipe', 'w')), $pipeList );
		if( !is_resource($procHndl) ){
			return null;
		}
		$givenTxt = stream_get_contents($pipeList[1]);
		fclose($pipeList[1]);
		fclose($pipeList[2]);
		return ( proc_close($procHndl)===0 && is_string($givenTxt) ) ? $givenTxt : null;
	}

	/**
	 * Traces what the command changed, on the 'shell' log channel, signed
	 * with the user of the system who ran it. Secrets never go there.
	 *
	 * @param String $eventMessage What was done.
	 */
	protected function traceShellDone( String $eventMessage ): Void {
		Logger::getLoggerOfChn('shell')->notice( $eventMessage.Logger::getActorSuffix() );
	}

	/**
	 * Reads a block of the ACCESS config.
	 *
	 * @param String $blockNmdText Name of the block.
	 * @return Array Its values, empty when there is no such block.
	 */
	protected function accessSettings( String $blockNmdText ): Array {
		$nyuConfg = $GLOBALS['nyuConfig'] ?? null;
		$blockCfg = ($nyuConfg!==null && $blockNmdText!='') ? $nyuConfg->getNestedValue('ACCESS', strtoupper($blockNmdText)) : null;
		return is_array($blockCfg) ? $blockCfg : Array();
	}
}
