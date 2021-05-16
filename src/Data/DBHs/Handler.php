<?php

namespace nyu\Data\DBHs;

interface Handler{
	
	/**
	 * Opens a connection to the database on instantiation.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 */
	public function __construct(String $serverHost, String $serverUser, String $serverPass, String $serverBase);
	
	/**
	 * Opens the underlying database connection.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 */
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase): Void;
//	public function makeQuery();
	/**
	 * Sends a built SQL query to the database.
	 *
	 * @param String $builtQuery The SQL query to execute.
	 * @return Bool True on success, false on failure.
	 */
	public function sendQuery(String $builtQuery): Bool;
	/**
	 * Reads the results of the last sent query.
	 *
	 * @param Bool $keepAsList Whether to always return a list of rows, even for a single row.
	 * @return mixed The query results, or false on error.
	 */
	public function readQuery( Bool $keepAsList=false ): Mixed;
	/**
	 * Returns the most recent error message recorded by the handler.
	 *
	 * @return mixed The last error message, or false/null if none.
	 */
	public function lastError(): String|Bool;
	/**
	 * Escapes a string for safe inclusion in a SQL query.
	 *
	 * @param String $str2Escape The raw string to escape.
	 * @return String The escaped string.
	 */
	public function escapeStr(String $str2Escape): String;
	/**
	 * Closes the database connection.
	 */
	public function exitDBase(): Void;
	
}