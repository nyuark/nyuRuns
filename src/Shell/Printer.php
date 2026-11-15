<?php

namespace nyu\Shell;

/**
 * Writes what a command tells: as lines of text, or, when a script reads
 * it, as one JSON object written at the end. Questions and errors go to
 * the error stream, so that the output stays what the command produced.
 */
class Printer{

	protected $isJsonFrmt;
	protected $isQuietOut;
	protected $outsStream;
	protected $failStream;
	protected $jsonOutput = Array();

	/**
	 * Builds the printer with its shape, its mode and its streams.
	 *
	 * @param Bool  $printsAsJson Whether the output is one JSON object, for a script to read.
	 * @param Bool  $skipsRemarks Whether the plain remarks are left out; values, tables and errors are still written.
	 * @param mixed $outputStream Stream the output is written to; the standard output when null.
	 * @param mixed $errorsStream Stream the questions and the errors are written to; the standard error when null.
	 */
	function __construct( Bool $printsAsJson=false, Bool $skipsRemarks=false, Mixed $outputStream=null, Mixed $errorsStream=null ){
		$this->isJsonFrmt = $printsAsJson;
		$this->isQuietOut = $skipsRemarks;
		$this->outsStream = $outputStream ?? STDOUT;
		$this->failStream = $errorsStream ?? STDERR;
	}

	/**
	 * Tells whether the output is a JSON object.
	 *
	 * @return Bool True for JSON, false for text.
	 */
	public function jsonShellShape(): Bool {
		return $this->isJsonFrmt;
	}

	/**
	 * Writes a remark: a line of text, kept under 'notes' in JSON.
	 *
	 * @param String $markdownLine The remark.
	 */
	public function printShellLine( String $markdownLine ): Void {
		if( $this->isQuietOut ){
			return;
		}
		if( $this->isJsonFrmt ){
			$this->jsonOutput['notes'][] = $markdownLine;
			return;
		}
		fwrite($this->outsStream, $markdownLine.PHP_EOL);
	}

	/**
	 * Writes text as it is, without a line end: the body of a reply, or
	 * the lines of a log. Kept under 'body' in JSON.
	 *
	 * @param String $markdownLine The text.
	 */
	public function printShellBody( String $markdownLine ): Void {
		if( $this->isJsonFrmt ){
			$this->jsonOutput['body'] = ($this->jsonOutput['body'] ?? '').$markdownLine;
			return;
		}
		fwrite($this->outsStream, $markdownLine);
	}

	/**
	 * Writes an error, on the error stream; kept under 'error' in JSON.
	 *
	 * @param String $markdownLine What went wrong.
	 */
	public function printShellFail( String $markdownLine ): Void {
		if( $this->isJsonFrmt ){
			$this->jsonOutput['error'] = $markdownLine;
			return;
		}
		fwrite($this->failStream, $markdownLine.PHP_EOL);
	}

	/**
	 * Writes a question, on the error stream and without a line end: the
	 * answer is typed after it.
	 *
	 * @param String $markdownLine The question.
	 */
	public function printShellAsks( String $markdownLine ): Void {
		fwrite($this->failStream, $markdownLine);
	}

	/**
	 * Writes a table: its columns aligned in text, a list of rows under
	 * 'rows' in JSON, each value under the name of its column.
	 *
	 * @param Array $hedrRowsList Names of the columns.
	 * @param Array $tableLnsList Rows, each a list of values in the order of the columns.
	 */
	public function printShellRows( Array $hedrRowsList, Array $tableLnsList ): Void {
		if( $this->isJsonFrmt ){
			foreach( $tableLnsList as $rowVal ){
				$this->jsonOutput['rows'][] = array_combine($hedrRowsList, array_values($rowVal));
			}
			if( empty($tableLnsList) && !isset($this->jsonOutput['rows']) ){
				$this->jsonOutput['rows'] = Array();
			}
			return;
		}
		$clmnSize = array_map('strlen', $hedrRowsList);
		$textNtry = Array();
		foreach( $tableLnsList as $rowVal ){
			$textLine = Array();
			foreach( array_values($rowVal) as $colInc => $colVal ){
				$textLine[$colInc] = is_scalar($colVal) ? strval($colVal) : ( $colVal===null ? '' : strval(json_encode($colVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) );
				$clmnSize[$colInc] = max($clmnSize[$colInc] ?? 0, strlen($textLine[$colInc]));
			}
			$textNtry[] = $textLine;
		}
		foreach( array_merge(Array($hedrRowsList), $textNtry) as $rowTxt ){
			$outLigne = '';
			foreach( $rowTxt as $colInc => $colVal ){
				$outLigne .= str_pad($colVal, $clmnSize[$colInc]).'  ';
			}
			fwrite($this->outsStream, rtrim($outLigne).PHP_EOL);
		}
	}

	/**
	 * Writes a named value: 'name: value' in text, a key in JSON.
	 *
	 * @param String $valLabelText Name of the value.
	 * @param mixed  $filePartData The value.
	 */
	public function printShellPair( String $valLabelText, Mixed $filePartData ): Void {
		if( $this->isJsonFrmt ){
			$this->jsonOutput[$valLabelText] = $filePartData;
			return;
		}
		fwrite($this->outsStream, $valLabelText.': '.( (is_string($filePartData) || is_int($filePartData) || is_float($filePartData)) ? strval($filePartData) : strval(json_encode($filePartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ).PHP_EOL);
	}

	/**
	 * Writes a secret that is told this once: it is kept nowhere readable,
	 * and never goes to a log.
	 *
	 * @param String $shellSecrNme What the secret is.
	 * @param String $codeSecrText The secret.
	 */
	public function printShellOnce( String $shellSecrNme, String $codeSecrText ): Void {
		if( $this->isJsonFrmt ){
			$this->jsonOutput['secret'][$shellSecrNme] = $codeSecrText;
			return;
		}
		fwrite($this->outsStream, $shellSecrNme.' (shown this once): '.$codeSecrText.PHP_EOL);
	}

	/**
	 * Ends the output: in JSON, the object is written now, with whether
	 * the command was done ('done') and its exit code ('exit').
	 *
	 * @param Int $shellExitNum Exit code of the command.
	 */
	public function closeShellOuts( Int $shellExitNum ): Void {
		if( !$this->isJsonFrmt ){
			return;
		}
		$outArray = Array('done'=>($shellExitNum==0), 'exit'=>$shellExitNum) + $this->jsonOutput;
		fwrite($this->outsStream, json_encode($outArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
		$this->jsonOutput = Array();
	}
}
