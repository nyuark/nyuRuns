<?php

namespace nyu\Shell;

/**
 * Writes what a command tells: as lines of text, or, when a script reads
 * it, as one JSON object written at the end. Questions and errors go to
 * the error stream, so that the output stays what the command produced.
 */
class Printer{

	protected $jsonMode;
	protected $quietOut;
	protected $outsStrm;
	protected $errsStrm;
	protected $jsonData = Array();

	/**
	 * Builds the printer with its shape, its mode and its streams.
	 *
	 * @param Bool  $jsonsShape Whether the output is one JSON object, for a script to read.
	 * @param Bool  $quietsMode Whether the plain remarks are left out; values, tables and errors are still written.
	 * @param mixed $outsStream Stream the output is written to; the standard output when null.
	 * @param mixed $errsStream Stream the questions and the errors are written to; the standard error when null.
	 */
	function __construct( Bool $jsonsShape=false, Bool $quietsMode=false, Mixed $outsStream=null, Mixed $errsStream=null ){
		$this->jsonMode = $jsonsShape;
		$this->quietOut = $quietsMode;
		$this->outsStrm = $outsStream ?? STDOUT;
		$this->errsStrm = $errsStream ?? STDERR;
	}

	/**
	 * Tells whether the output is a JSON object.
	 *
	 * @return Bool True for JSON, false for text.
	 */
	public function jsonShape(): Bool {
		return $this->jsonMode;
	}

	/**
	 * Writes a remark: a line of text, kept under 'notes' in JSON.
	 *
	 * @param String $lineString The remark.
	 */
	public function printLine( String $lineString ): Void {
		if( $this->quietOut ){
			return;
		}
		if( $this->jsonMode ){
			$this->jsonData['notes'][] = $lineString;
			return;
		}
		fwrite($this->outsStrm, $lineString.PHP_EOL);
	}

	/**
	 * Writes text as it is, without a line end: the body of a reply, or
	 * the lines of a log. Kept under 'body' in JSON.
	 *
	 * @param String $lineString The text.
	 */
	public function printBody( String $lineString ): Void {
		if( $this->jsonMode ){
			$this->jsonData['body'] = ($this->jsonData['body'] ?? '').$lineString;
			return;
		}
		fwrite($this->outsStrm, $lineString);
	}

	/**
	 * Writes an error, on the error stream; kept under 'error' in JSON.
	 *
	 * @param String $lineString What went wrong.
	 */
	public function printFail( String $lineString ): Void {
		if( $this->jsonMode ){
			$this->jsonData['error'] = $lineString;
			return;
		}
		fwrite($this->errsStrm, $lineString.PHP_EOL);
	}

	/**
	 * Writes a question, on the error stream and without a line end: the
	 * answer is typed after it.
	 *
	 * @param String $lineString The question.
	 */
	public function printAsks( String $lineString ): Void {
		fwrite($this->errsStrm, $lineString);
	}

	/**
	 * Writes a table: its columns aligned in text, a list of rows under
	 * 'rows' in JSON, each value under the name of its column.
	 *
	 * @param Array $headerRows Names of the columns.
	 * @param Array $tableLines Rows, each a list of values in the order of the columns.
	 */
	public function printRows( Array $headerRows, Array $tableLines ): Void {
		if( $this->jsonMode ){
			foreach( $tableLines as $rowVal ){
				$this->jsonData['rows'][] = array_combine($headerRows, array_values($rowVal));
			}
			if( empty($tableLines) && !isset($this->jsonData['rows']) ){
				$this->jsonData['rows'] = Array();
			}
			return;
		}
		$colSize = array_map('strlen', $headerRows);
		$txtRows = Array();
		foreach( $tableLines as $rowVal ){
			$txtLine = Array();
			foreach( array_values($rowVal) as $colIdx => $colVal ){
				$txtLine[$colIdx] = is_scalar($colVal) ? strval($colVal) : ( $colVal===null ? '' : strval(json_encode($colVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) );
				$colSize[$colIdx] = max($colSize[$colIdx] ?? 0, strlen($txtLine[$colIdx]));
			}
			$txtRows[] = $txtLine;
		}
		foreach( array_merge(Array($headerRows), $txtRows) as $rowTxt ){
			$outLine = '';
			foreach( $rowTxt as $colIdx => $colVal ){
				$outLine .= str_pad($colVal, $colSize[$colIdx]).'  ';
			}
			fwrite($this->outsStrm, rtrim($outLine).PHP_EOL);
		}
	}

	/**
	 * Writes a named value: 'name: value' in text, a key in JSON.
	 *
	 * @param String $valueLabel Name of the value.
	 * @param mixed  $valueDatas The value.
	 */
	public function printPair( String $valueLabel, Mixed $valueDatas ): Void {
		if( $this->jsonMode ){
			$this->jsonData[$valueLabel] = $valueDatas;
			return;
		}
		fwrite($this->outsStrm, $valueLabel.': '.( (is_string($valueDatas) || is_int($valueDatas) || is_float($valueDatas)) ? strval($valueDatas) : strval(json_encode($valueDatas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ).PHP_EOL);
	}

	/**
	 * Writes a secret that is told this once: it is kept nowhere readable,
	 * and never goes to a log.
	 *
	 * @param String $secretName What the secret is.
	 * @param String $secretText The secret.
	 */
	public function printOnce( String $secretName, String $secretText ): Void {
		if( $this->jsonMode ){
			$this->jsonData['secret'][$secretName] = $secretText;
			return;
		}
		fwrite($this->outsStrm, $secretName.' (shown this once): '.$secretText.PHP_EOL);
	}

	/**
	 * Ends the output: in JSON, the object is written now, with whether
	 * the command was done ('done') and its exit code ('exit').
	 *
	 * @param Int $exitNumber Exit code of the command.
	 */
	public function closeOuts( Int $exitNumber ): Void {
		if( !$this->jsonMode ){
			return;
		}
		$outVals = Array('done'=>($exitNumber==0), 'exit'=>$exitNumber) + $this->jsonData;
		fwrite($this->outsStrm, json_encode($outVals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
		$this->jsonData = Array();
	}
}
