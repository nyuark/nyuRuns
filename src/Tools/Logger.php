<?php

namespace nyu\Tools;

/**
 * A minimal, dependency-free logger honoring this project's own
 * LOG_MODE/LOG_PATH/LOG_SIZE/LOG_ROTA/LOG_PRFX configuration schema
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
	const LEVL_LIST = Array('error', 'warning', 'notice', 'info', 'debug');
	// How many of them each mode writes, counted from the most serious
	const MODE_LIST = Array('MINI'=>3, 'BASE'=>4, 'FULL'=>5);

	protected $logsFolder;
	protected $channelNme;
	protected $logModeVal;
	protected $logsLength;
	protected $rotateMode;
	protected $logsPrefix;
	protected $nameEndTxt;

	// Log configuration the channels are built with, null until the Runner plugs it
	protected static $confValues = null;
	// The shared logger of each channel
	protected static $channelLst = Array();
	// Who the running request is for
	protected static $actingUser = '';
	protected static $actingAddr = '';
	// A log that cannot be written is told once per process
	protected static $isFailTold = false;

	/**
	 * Builds the logger of one channel.
	 *
	 * @param String $logDirectory Directory where log files are stored; empty for a logger that writes nothing.
	 * @param String $channelLabel Log channel/file name (without extension), e.g. 'error', 'runner', 'access': letters, digits and underscores.
	 * @param String $verboseLevel Verbosity: FULL, BASE or MINI.
	 * @param Int    $loggerLength Max file size in KB before rotation (INDX rotation only); 0 = unlimited.
	 * @param String $rotationMode Rotation mode: INDX, WEEK, MNTH or NONE.
	 * @param String $loggerPrefix date() format of the time written at the start of each entry.
	 *
	 * @throws \RuntimeException When the channel is not a plain name.
	 */
	function __construct( String $logDirectory, String $channelLabel='runner', String $verboseLevel='FULL', Int $loggerLength=0, String $rotationMode='INDX', String $loggerPrefix='Y-m-d H:i' ){
		// The channel names a file : it cannot lead out of the log folder
		if( !preg_match('/^\w+$/D', $channelLabel) ){
			throw new \RuntimeException('Log channel refused: '.json_encode($channelLabel).' is not a plain name');
		}
		$this->logsFolder = rtrim($logDirectory, '/\\');
		$this->channelNme = $channelLabel;
		$this->logModeVal = strtoupper($verboseLevel);
		$this->logsLength = $loggerLength;
		$this->rotateMode = strtoupper($rotationMode);
		$this->logsPrefix = $loggerPrefix;
		// One file per process : named once, whatever the time its entries are written at
		$this->nameEndTxt = '-'.date('Y-m-d_His').'-'.getmypid();
		if( $this->logsFolder!=='' && !is_dir($this->logsFolder) ){
			@mkdir( $this->logsFolder, 0775, true );
		}
	}

	/**
	 * Gives the log configuration every channel is built with. Called
	 * once by the Runner; the channels already handed out are forgotten.
	 *
	 * @param Array $logsSettings Log settings: 'path', 'mode', 'size', 'rota' and 'pref' (see Setup\Loader::logConfigArray).
	 */
	static function loadLogsConfig( Array $logsSettings ): Void {
		self::$confValues = $logsSettings;
		self::$channelLst = Array();
	}

	/**
	 * Returns the shared logger of a channel, built on first use with the
	 * plugged configuration. Without one (tests, command line), the logger
	 * writes nothing: the caller never has to check for it.
	 *
	 * @param String $channelLabel Channel name: 'error', 'access', 'runner', or the lower-case name of an application.
	 * @return Logger The logger of that channel.
	 *
	 * @throws \RuntimeException When the channel is not a plain name.
	 */
	static function getLoggerOfChn( String $channelLabel='runner' ): Logger {
		if( !isset(self::$channelLst[$channelLabel]) ){
			$confVals = self::$confValues;
			if( $confVals===null ){
				self::$channelLst[$channelLabel] = new Logger( '', $channelLabel );
			}else{
				self::$channelLst[$channelLabel] = new Logger( strval($confVals['path'] ?? ''), $channelLabel, strval($confVals['mode'] ?? 'FULL'), intval($confVals['size'] ?? 0), strval($confVals['rota'] ?? 'INDX'), strval($confVals['pref'] ?? 'Y-m-d H:i') );
			}
		}
		return self::$channelLst[$channelLabel];
	}

	/**
	 * Tells who the running request is for, so that an event traced far
	 * from the access check can still be signed (see actorText).
	 *
	 * @param String $signedInUser Name of the identified user, empty when there is none.
	 * @param String $clientIpAddr Address of the client, empty when the request tells none.
	 */
	static function setCurrentUser( String $signedInUser, String $clientIpAddr ): Void {
		self::$actingUser = $signedInUser;
		self::$actingAddr = $clientIpAddr;
	}

	/**
	 * Gives the end of a log message telling who the running request is
	 * for: ' by user "name" from address', each part only when known.
	 *
	 * @return String The text, empty when nothing is known.
	 */
	static function getActorSuffix(): String {
		$actnText = (self::$actingUser!='') ? ' by user '.json_encode(self::$actingUser) : '';
		if( self::$actingAddr!='' ){
			$actnText .= ' from '.self::$actingAddr;
		}
		return $actnText;
	}

	/**
	 * Logs an error: a failure that stopped the current operation.
	 *
	 * @param String $logEntryText The log message.
	 * @param Array  $extraContext Extra structured data (included under FULL mode only).
	 */
	function error( String $logEntryText, Array $extraContext=Array() ): Void { $this->writeLogRecord(self::LEVL_ERROR, $logEntryText, $extraContext); }

	/**
	 * Logs a warning: something unexpected that did not stop the
	 * current operation.
	 *
	 * @param String $logEntryText The log message.
	 * @param Array  $extraContext Extra structured data (included under FULL mode only).
	 */
	function warning( String $logEntryText, Array $extraContext=Array() ): Void { $this->writeLogRecord(self::LEVL_WARNING, $logEntryText, $extraContext); }

	/**
	 * Logs a notice: a normal but noteworthy event.
	 *
	 * @param String $logEntryText The log message.
	 * @param Array  $extraContext Extra structured data (included under FULL mode only).
	 */
	function notice( String $logEntryText, Array $extraContext=Array() ): Void { $this->writeLogRecord(self::LEVL_NOTICE, $logEntryText, $extraContext); }

	/**
	 * Logs an informational message about a routine event. Not written
	 * under MINI mode.
	 *
	 * @param String $logEntryText The log message.
	 * @param Array  $extraContext Extra structured data (included under FULL mode only).
	 */
	function info( String $logEntryText, Array $extraContext=Array() ): Void { $this->writeLogRecord(self::LEVL_INFO, $logEntryText, $extraContext); }

	/**
	 * Logs a debug message: diagnostic detail for development. Written
	 * under FULL mode only.
	 *
	 * @param String $logEntryText The log message.
	 * @param Array  $extraContext Extra structured data (included under FULL mode only).
	 */
	function debug( String $logEntryText, Array $extraContext=Array() ): Void { $this->writeLogRecord(self::LEVL_DEBUG, $logEntryText, $extraContext); }

	/**
	 * Writes a single log entry, applying LOG_MODE verbosity and
	 * triggering rotation when needed. A level the mode does not write
	 * is left out; an entry is always one line.
	 *
	 * @param String $itemLevelTxt One of the LEVL_* constants.
	 * @param String $logEntryText The log message.
	 * @param Array  $extraContext Extra structured data (included under FULL mode only).
	 */
	protected function writeLogRecord( String $itemLevelTxt, String $logEntryText, Array $extraContext=Array() ): Void {
		if( $this->logsFolder==='' ){
			return;
		}
		$levelRnk = array_search($itemLevelTxt, self::LEVL_LIST, true);
		if( $levelRnk!==false && $levelRnk>=(self::MODE_LIST[$this->logModeVal] ?? count(self::LEVL_LIST)) ){
			return;
		}
		// A line break in a message would start an entry nobody wrote : every control character and the Unicode line breaks are written escaped, read as bytes
		$logEntryText = strval( preg_replace_callback('/[\x00-\x1F\x7F]|\xC2\x85|\xE2\x80[\xA8\xA9]/', function( Array $matchedArray ): String {
			$escpList = Array("\r"=>'\\r', "\n"=>'\\n', "\t"=>'\\t', "\xC2\x85"=>'\\u0085', "\xE2\x80\xA8"=>'\\u2028', "\xE2\x80\xA9"=>'\\u2029');
			return $escpList[$matchedArray[0]] ?? sprintf('\\x%02X', ord($matchedArray[0]));
		}, $logEntryText) );
		if( $this->logModeVal=='MINI' ){
			$logLigne = date($this->logsPrefix).' '.$logEntryText;
		}else{
			$logLigne = '['.date($this->logsPrefix).'] ['.strtoupper($itemLevelTxt).'] '.$logEntryText;
			if( $this->logModeVal=='FULL' && !empty($extraContext) ){
				// What cannot be encoded is left out of the context, the rest is kept
				$logLigne .= ' '.json_encode($extraContext, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
			}
		}
		$logFichr = $this->findLogFilePth();
		$this->rotateLogFiles( $logFichr );
		if( @file_put_contents( $logFichr, $logLigne.PHP_EOL, FILE_APPEND | LOCK_EX )===false ){
			$this->logFailureOnce( $logFichr );
		}
	}

	/**
	 * Reports a log file that cannot be written to PHP's own error log,
	 * once per process: the entries are lost, but not silently.
	 *
	 * @param String $logsFilePath Path of the log file.
	 */
	protected function logFailureOnce( String $logsFilePath ): Void {
		if( self::$isFailTold ){
			return;
		}
		self::$isFailTold = true;
		error_log('nyuRuns log not written: '.$logsFilePath);
	}

	/**
	 * Resolves the active log file path for the current rotation mode.
	 *
	 * @return String Full path to the active log file.
	 */
	protected function findLogFilePth(): String {
		$titleEnd = '';
		if( $this->rotateMode=='WEEK' ){
			$titleEnd = '-W'.date('oW');
		}elseif( $this->rotateMode=='MNTH' ){
			$titleEnd = '-'.date('Y-m');
		}elseif( $this->rotateMode=='NONE' ){
			$titleEnd = $this->nameEndTxt;
		}
		return $this->logsFolder.DIRECTORY_SEPARATOR.$this->channelNme.$titleEnd.'.log';
	}

	/**
	 * Rotates the given log file to a numbered backup when it exceeds
	 * the configured LOG_SIZE (INDX rotation mode only). When another
	 * process rotates the file at the same moment, one of the two keeps
	 * writing in it until its next entry.
	 *
	 * @param String $logsFilePath Path to the active log file.
	 */
	protected function rotateLogFiles( String $logsFilePath ): Void {
		if( $this->rotateMode!='INDX' || $this->logsLength<=0 ){
			return;
		}
		// filesize() is cached per process : refresh it to see our own writes
		clearstatcache( true, $logsFilePath );
		if( !file_exists($logsFilePath) || filesize($logsFilePath) < ($this->logsLength*1024) ){
			return;
		}
		$backNumb = 1;
		while( file_exists($logsFilePath.'.'.$backNumb) ){
			$backNumb++;
		}
		@rename( $logsFilePath, $logsFilePath.'.'.$backNumb );
	}
}
