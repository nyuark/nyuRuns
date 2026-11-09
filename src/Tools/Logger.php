<?php

namespace nyu\Tools;

/**
 * A minimal, dependency-free logger honoring this project's own
 * LOG_MODE/LOG_PATH/LOG_SIZE/LOG_ROTA/LOG_PREF configuration schema
 * (see conf/system.ini). Each channel is a file of its own: the
 * framework writes on 'error', 'access' and 'runner', an application
 * on the one of its name (see getLogger).
 */
class Logger{

	const LEVL_ERROR   = 'error';
	const LEVL_WARNING = 'warning';
	const LEVL_NOTICE  = 'notice';
	const LEVL_INFO    = 'info';
	const LEVL_DEBUG   = 'debug';

	// The levels, from the most to the least serious
	const LEVLLIST = Array('error', 'warning', 'notice', 'info', 'debug');
	// How many of them each mode writes, counted from the most serious
	const MODELIST = Array('MINI'=>3, 'BASE'=>4, 'FULL'=>5);

	protected $logsPath;
	protected $chanName;
	protected $logsMode;
	protected $logsSize;
	protected $logsRota;
	protected $logsPref;
	protected $uniqSufx;

	// Log configuration the channels are built with, null until the Runner plugs it
	protected static $confVals = null;
	// The shared logger of each channel
	protected static $chanList = Array();
	// Who the running request is for
	protected static $actrUser = '';
	protected static $actrAddr = '';
	// A log that cannot be written is told once per process
	protected static $failTold = false;

	/**
	 * Builds the logger of one channel.
	 *
	 * @param String $loggerPath Directory where log files are stored; empty for a logger that writes nothing.
	 * @param String $loggerChan Log channel/file name (without extension), e.g. 'error', 'runner', 'access': letters, digits and underscores.
	 * @param String $loggerMode Verbosity: FULL, BASE or MINI.
	 * @param Int    $loggerSize Max file size in KB before rotation (NUMBER rotation only); 0 = unlimited.
	 * @param String $loggerRota Rotation mode: NUMBER, WEEKLY, MONTHS or UNIQUE.
	 * @param String $loggerPref date() format of the time written at the start of each entry.
	 *
	 * @throws \RuntimeException When the channel is not a plain name.
	 */
	function __construct( String $loggerPath, String $loggerChan='runner', String $loggerMode='FULL', Int $loggerSize=0, String $loggerRota='NUMBER', String $loggerPref='Y-m-d H:i' ){
		// The channel names a file : it cannot lead out of the log folder
		if( !preg_match('/^\w+$/D', $loggerChan) ){
			throw new \RuntimeException('Log channel refused: '.json_encode($loggerChan).' is not a plain name');
		}
		$this->logsPath = rtrim($loggerPath, '/\\');
		$this->chanName = $loggerChan;
		$this->logsMode = strtoupper($loggerMode);
		$this->logsSize = $loggerSize;
		$this->logsRota = strtoupper($loggerRota);
		$this->logsPref = $loggerPref;
		// One file per process : named once, whatever the time its entries are written at
		$this->uniqSufx = '-'.date('Y-m-d_His').'-'.getmypid();
		if( $this->logsPath!=='' && !is_dir($this->logsPath) ){
			@mkdir( $this->logsPath, 0775, true );
		}
	}

	/**
	 * Gives the log configuration every channel is built with. Called
	 * once by the Runner; the channels already handed out are forgotten.
	 *
	 * @param Array $loggerConf Log settings: 'path', 'mode', 'size', 'rota' and 'pref' (see Setup\Loader::logConfig).
	 */
	static function plugSetup( Array $loggerConf ): Void {
		self::$confVals = $loggerConf;
		self::$chanList = Array();
	}

	/**
	 * Returns the shared logger of a channel, built on first use with the
	 * plugged configuration. Without one (tests, command line), the logger
	 * writes nothing: the caller never has to check for it.
	 *
	 * @param String $loggerChan Channel name: 'error', 'access', 'runner', or the lower-case name of an application.
	 * @return Logger The logger of that channel.
	 *
	 * @throws \RuntimeException When the channel is not a plain name.
	 */
	static function getLogger( String $loggerChan='runner' ): Logger {
		if( !isset(self::$chanList[$loggerChan]) ){
			$cnfVals = self::$confVals;
			if( $cnfVals===null ){
				self::$chanList[$loggerChan] = new Logger( '', $loggerChan );
			}else{
				self::$chanList[$loggerChan] = new Logger( strval($cnfVals['path'] ?? ''), $loggerChan, strval($cnfVals['mode'] ?? 'FULL'), intval($cnfVals['size'] ?? 0), strval($cnfVals['rota'] ?? 'NUMBER'), strval($cnfVals['pref'] ?? 'Y-m-d H:i') );
			}
		}
		return self::$chanList[$loggerChan];
	}

	/**
	 * Tells who the running request is for, so that an event traced far
	 * from the access check can still be signed (see actorText).
	 *
	 * @param String $loggedUser Name of the identified user, empty when there is none.
	 * @param String $clientAddr Address of the client, empty when the request tells none.
	 */
	static function plugActor( String $loggedUser, String $clientAddr ): Void {
		self::$actrUser = $loggedUser;
		self::$actrAddr = $clientAddr;
	}

	/**
	 * Gives the end of a log message telling who the running request is
	 * for: ' by user "name" from address', each part only when known.
	 *
	 * @return String The text, empty when nothing is known.
	 */
	static function actorText(): String {
		$actText = (self::$actrUser!='') ? ' by user '.json_encode(self::$actrUser) : '';
		if( self::$actrAddr!='' ){
			$actText .= ' from '.self::$actrAddr;
		}
		return $actText;
	}

	/**
	 * Logs an error: a failure that stopped the current operation.
	 *
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	function error( String $logMessage, Array $logContext=Array() ): Void { $this->writeLine(self::LEVL_ERROR, $logMessage, $logContext); }

	/**
	 * Logs a warning: something unexpected that did not stop the
	 * current operation.
	 *
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	function warning( String $logMessage, Array $logContext=Array() ): Void { $this->writeLine(self::LEVL_WARNING, $logMessage, $logContext); }

	/**
	 * Logs a notice: a normal but noteworthy event.
	 *
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	function notice( String $logMessage, Array $logContext=Array() ): Void { $this->writeLine(self::LEVL_NOTICE, $logMessage, $logContext); }

	/**
	 * Logs an informational message about a routine event. Not written
	 * under MINI mode.
	 *
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	function info( String $logMessage, Array $logContext=Array() ): Void { $this->writeLine(self::LEVL_INFO, $logMessage, $logContext); }

	/**
	 * Logs a debug message: diagnostic detail for development. Written
	 * under FULL mode only.
	 *
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	function debug( String $logMessage, Array $logContext=Array() ): Void { $this->writeLine(self::LEVL_DEBUG, $logMessage, $logContext); }

	/**
	 * Writes a single log entry, applying LOG_MODE verbosity and
	 * triggering rotation when needed. A level the mode does not write
	 * is left out; an entry is always one line.
	 *
	 * @param String $entryLevel One of the LEVL_* constants.
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	protected function writeLine( String $entryLevel, String $logMessage, Array $logContext=Array() ): Void {
		if( $this->logsPath==='' ){
			return;
		}
		$lvlRank = array_search($entryLevel, self::LEVLLIST, true);
		if( $lvlRank!==false && $lvlRank>=(self::MODELIST[$this->logsMode] ?? count(self::LEVLLIST)) ){
			return;
		}
		// A line break in a message would start an entry nobody wrote : every control character and the Unicode line breaks are written escaped, read as bytes
		$logMessage = strval( preg_replace_callback('/[\x00-\x1F\x7F]|\xC2\x85|\xE2\x80[\xA8\xA9]/', function( Array $matchedArr ): String {
			$escList = Array("\r"=>'\\r', "\n"=>'\\n', "\t"=>'\\t', "\xC2\x85"=>'\\u0085', "\xE2\x80\xA8"=>'\\u2028', "\xE2\x80\xA9"=>'\\u2029');
			return $escList[$matchedArr[0]] ?? sprintf('\\x%02X', ord($matchedArr[0]));
		}, $logMessage) );
		if( $this->logsMode=='MINI' ){
			$logLine = date($this->logsPref).' '.$logMessage;
		}else{
			$logLine = '['.date($this->logsPref).'] ['.strtoupper($entryLevel).'] '.$logMessage;
			if( $this->logsMode=='FULL' && !empty($logContext) ){
				// What cannot be encoded is left out of the context, the rest is kept
				$logLine .= ' '.json_encode($logContext, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
			}
		}
		$logFile = $this->buildPath();
		$this->rotateLog( $logFile );
		if( @file_put_contents( $logFile, $logLine.PHP_EOL, FILE_APPEND | LOCK_EX )===false ){
			$this->tellsFail( $logFile );
		}
	}

	/**
	 * Reports a log file that cannot be written to PHP's own error log,
	 * once per process: the entries are lost, but not silently.
	 *
	 * @param String $activePath Path of the log file.
	 */
	protected function tellsFail( String $activePath ): Void {
		if( self::$failTold ){
			return;
		}
		self::$failTold = true;
		error_log('nyuRuns log not written: '.$activePath);
	}

	/**
	 * Resolves the active log file path for the current rotation mode.
	 *
	 * @return String Full path to the active log file.
	 */
	protected function buildPath(): String {
		$suffix = '';
		if( $this->logsRota=='WEEKLY' ){
			$suffix = '-W'.date('oW');
		}elseif( $this->logsRota=='MONTHS' ){
			$suffix = '-'.date('Y-m');
		}elseif( $this->logsRota=='UNIQUE' ){
			$suffix = $this->uniqSufx;
		}
		return $this->logsPath.DIRECTORY_SEPARATOR.$this->chanName.$suffix.'.log';
	}

	/**
	 * Rotates the given log file to a numbered backup when it exceeds
	 * the configured LOG_SIZE (NUMBER rotation mode only). When another
	 * process rotates the file at the same moment, one of the two keeps
	 * writing in it until its next entry.
	 *
	 * @param String $activePath Path to the active log file.
	 */
	protected function rotateLog( String $activePath ): Void {
		if( $this->logsRota!='NUMBER' || $this->logsSize<=0 ){
			return;
		}
		// filesize() is cached per process : refresh it to see our own writes
		clearstatcache( true, $activePath );
		if( !file_exists($activePath) || filesize($activePath) < ($this->logsSize*1024) ){
			return;
		}
		$backNum = 1;
		while( file_exists($activePath.'.'.$backNum) ){
			$backNum++;
		}
		@rename( $activePath, $activePath.'.'.$backNum );
	}
}
