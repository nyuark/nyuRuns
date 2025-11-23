<?php

namespace nyu\Data\DBHndlr;

/**
 * Contract of a database handler: opens the connection, sends and reads
 * queries, runs transactions, and gives the quoting and the operators of
 * its SQL type.
 */
interface Handler{
	
	/**
	 * Opens a connection to the database on instantiation.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
	 */
	public function __construct(String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array());
	
	/**
	 * Opens the underlying database connection.
	 *
	 * @param String $serverHost Database host.
	 * @param String $serverUser Database user.
	 * @param String $serverPass Database password.
	 * @param String $serverBase Database name.
	 * @param Array  $moreParams Optional settings: 'port', 'char' (character set) and 'wait' (seconds given to the server to answer).
	 */
	public function openDBase(String $serverHost, String $serverUser, String $serverPass, String $serverBase, Array $moreParams=Array()): Void;
	/**
	 * Sends a built SQL query to the database.
	 *
	 * @param String $builtQuery The SQL query to execute.
	 * @param Bool   $query2Exec Whether the query is a write (INSERT/UPDATE/DELETE) rather than a read.
	 * @param Array  $boundArray Values bound, in order, to the ? placeholders of the query; a flux (nyu\Data\Fluxd) is bound as binary.
	 * @return Bool True on success, false on failure.
	 */
	public function sendQuery(String $builtQuery, Bool $query2Exec=false, Array $boundArray=Array()): Bool;
	/**
	 * Reads the results of the last sent query.
	 *
	 * @param Bool $keepAsList Whether to always return a list of rows, even for a single row.
	 * @return mixed The query results, or false on error.
	 */
	public function readQuery( Bool $keepAsList=false ): Mixed;
	/**
	 * Returns the error of the last query or transaction call. Each of
	 * those calls clears the error of the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function lastError(): String|Bool;
	/**
	 * Returns the key of the last inserted row.
	 *
	 * @return String|Bool The last insert ID, or false when unavailable.
	 */
	public function lastAdded(): String|Bool;
	/**
	 * Returns the number of rows changed by the last write.
	 *
	 * @return Int The number of affected rows, 0 when the last query was not a write.
	 */
	public function lastCount(): Int;
	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure.
	 */
	public function opnTrnsct(): Bool;
	/**
	 * Makes the changes of the transaction permanent. On an inner level,
	 * nothing is written yet: the outermost transaction decides.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function setTrnsct(): Bool;
	/**
	 * Cancels the changes of the whole transaction, outer levels included.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function remTrnsct(): Bool;
	/**
	 * Quotes a table or field name for inclusion in a query.
	 *
	 * @param String $fieldLabel The table or field name, with an optional table prefix (`table.field`).
	 * @return String The quoted name.
	 *
	 * @throws \RuntimeException When the name is not a valid table or field name.
	 */
	public function quoteName(String $fieldLabel): String;
	/**
	 * Builds the WHERE part of a query from a filter array, read by
	 * DBStore\Filter::readWhere().
	 *
	 * @param Array $whereArray Map of field name => value (or array of values) to filter by.
	 * @return Array The clause under 'text', with ? placeholders, and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public function makeWhere( Array $whereArray ): Array;
	/**
	 * Returns the operator of a wildcard search that ignores the case.
	 *
	 * @return String The operator: LIKE, or what the database type uses in its place.
	 */
	public function likeOprtr(): String;
	/**
	 * Describes a table: its field names, its primary key and its binary fields.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool;
	/**
	 * Closes the database connection.
	 */
	public function exitDBase(): Void;
	
}