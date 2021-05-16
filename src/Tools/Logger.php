<?php

namespace nyu\Tools;

/**
 * A minimal, dependency-free logger honoring this project's own
 * LOG_MODE/LOG_PATH/LOG_SIZE/LOG_ROTA/LOG_PREF configuration schema
 * (see conf/system.ini).
 */
class Logger{

	const LEVL_ERROR   = 'error';
	const LEVL_WARNING = 'warning';
	const LEVL_NOTICE  = 'notice';
	const LEVL_INFO    = 'info';
	const LEVL_DEBUG   = 'debug';

	protected $logsPath;
	protected $chanName;
	protected $logsMode;
	protected $logsSize;
	protected $logsRota;
	protected $logsPref;

	/**
	 * @param String $loggerPath Directory where log files are stored.
	 * @param String $loggerChan Log channel/file name (without extension), e.g. 'error', 'runner', 'access'.
	 * @param String $loggerMode Verbosity: FULL, BASE or MINI.
	 * @param Int    $loggerSize Max file size in KB before rotation; 0 = unlimited.
	 * @param String $loggerRota Rotation mode: NUMBER, WEEKLY, MONTHS or UNIQUE.
	 * @param String $loggerPref date() format used for entry timestamps and rotated filenames.
	 */
	function __construct( String $loggerPath, String $loggerChan='runner', String $loggerMode='FULL', Int $loggerSize=0, String $loggerRota='NUMBER', String $loggerPref='Y-m-d H:i' ){
		$this->logsPath = rtrim($loggerPath, '/\\');
		$this->chanName = $loggerChan;
		$this->logsMode = strtoupper($loggerMode);
		$this->logsSize = $loggerSize;
		$this->logsRota = strtoupper($loggerRota);
		$this->logsPref = $loggerPref;
		if( !is_dir($this->logsPath) ){
			@mkdir( $this->logsPath, 0775, true );
		}
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
	 * Logs an informational message about a routine event.
	 *
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	function info( String $logMessage, Array $logContext=Array() ): Void { $this->writeLine(self::LEVL_INFO, $logMessage, $logContext); }

	/**
	 * Logs a debug message: diagnostic detail for development.
	 *
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	function debug( String $logMessage, Array $logContext=Array() ): Void { $this->writeLine(self::LEVL_DEBUG, $logMessage, $logContext); }

	/**
	 * Writes a single log entry, applying LOG_MODE verbosity and
	 * triggering rotation when needed.
	 *
	 * @param String $entryLevel One of the LEVL_* constants.
	 * @param String $logMessage The log message.
	 * @param Array  $logContext Extra structured data (included under FULL mode only).
	 */
	protected function writeLine( String $entryLevel, String $logMessage, Array $logContext=Array() ): Void {
		if( $this->logsMode=='MINI' ){
			$logLine = date($this->logsPref).' '.$logMessage;
		}else{
			$logLine = '['.date($this->logsPref).'] ['.strtoupper($entryLevel).'] '.$logMessage;
			if( $this->logsMode=='FULL' && !empty($logContext) ){
				$logLine .= ' '.json_encode($logContext);
			}
		}
		$logFile = $this->buildPath();
		$this->rotateLog( $logFile );
		@file_put_contents( $logFile, $logLine.PHP_EOL, FILE_APPEND | LOCK_EX );
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
			$suffix = '-'.date('Y-m-d_His').'-'.getmypid();
		}
		return $this->logsPath.DIRECTORY_SEPARATOR.$this->chanName.$suffix.'.log';
	}

	/**
	 * Rotates the given log file to a numbered backup when it exceeds
	 * the configured LOG_SIZE (NUMBER rotation mode only).
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
		rename( $activePath, $activePath.'.'.$backNum );
	}
}
