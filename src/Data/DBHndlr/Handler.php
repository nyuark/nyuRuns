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
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'encoding' (character set) and 'waitSecs' (seconds given to the server to answer).
	 */
	public function __construct(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array());
	
	/**
	 * Opens the underlying database connection.
	 *
	 * @param String $databaseHost Database host.
	 * @param String $serverUsrTxt Database user.
	 * @param String $hostPassText Database password.
	 * @param String $hostBaseText Database name.
	 * @param Array  $settingsList Optional settings: 'portNumb', 'encoding' (character set) and 'waitSecs' (seconds given to the server to answer).
	 */
	public function openConnection(String $databaseHost, String $serverUsrTxt, String $hostPassText, String $hostBaseText, Array $settingsList=Array()): Void;
	/**
	 * Sends a built SQL query to the database.
	 *
	 * @param String $createdQuery The SQL query to execute.
	 * @param Bool   $isWriteQuery Whether the query is a write (INSERT/UPDATE/DELETE) rather than a read.
	 * @param Array  $valuesToBind Values bound, in order, to the ? placeholders of the query; a flux (nyu\Data\Fluxd) is bound as binary.
	 * @return Bool True on success, false on failure.
	 */
	public function sendBuiltQuery(String $createdQuery, Bool $isWriteQuery=false, Array $valuesToBind=Array()): Bool;
	/**
	 * Reads the results of the last sent query.
	 *
	 * @param Bool $alwaysAsList Whether to always return a list of rows, even for a single row.
	 * @return mixed The query results, or false on error.
	 */
	public function readLastResult( Bool $alwaysAsList=false ): Mixed;
	/**
	 * Returns the error of the last query or transaction call. Each of
	 * those calls clears the error of the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function getLastErrText(): String|Bool;
	/**
	 * Returns the key of the last inserted row.
	 *
	 * @return String|Bool The last insert ID, or false when unavailable.
	 */
	public function getLastAddedId(): String|Bool;
	/**
	 * Returns the number of rows changed by the last write.
	 *
	 * @return Int The number of affected rows, 0 when the last query was not a write.
	 */
	public function countLastWrite(): Int;
	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure.
	 */
	public function addTransaction(): Bool;
	/**
	 * Makes the changes of the transaction permanent. On an inner level,
	 * nothing is written yet: the outermost transaction decides.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function setTransaction(): Bool;
	/**
	 * Cancels the changes of the whole transaction, outer levels included.
	 *
	 * @return Bool True on success, false on failure or when no transaction is open.
	 */
	public function dropWholeTrnsc(): Bool;
	/**
	 * Quotes a table or field name for inclusion in a query.
	 *
	 * @param String $fldLabelText The table or field name, with an optional table prefix (`table.field`).
	 * @return String The quoted name.
	 *
	 * @throws \RuntimeException When the name is not a valid table or field name.
	 */
	public function quoteFieldName(String $fldLabelText): String;
	/**
	 * Builds the WHERE part of a query from a filter array, read by
	 * DBStore\Filter::readWhereArray().
	 *
	 * @param Array $filterFields Map of field name => value (or array of values) to filter by.
	 * @return Array The clause under 'text', with ? placeholders, and its values under 'vals'.
	 *
	 * @throws \RuntimeException When a field name is not valid, or a '?' entry is not true or false.
	 */
	public function makeWhereArray( Array $filterFields ): Array;
	/**
	 * Returns the operator of a wildcard search that ignores the case.
	 *
	 * @return String The operator: LIKE, or what the database type uses in its place.
	 */
	public function getLikeKeyword(): String;
	/**
	 * Describes a table: its field names, its primary key and its binary fields.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds', the primary key field under 'pkey' ('' when the table has none) and the binary fields under 'blob', or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getInfoOfTable(String $tbleLabelTxt): Array|Bool;
	/**
	 * Gives the type of each column of a table, as the database writes it.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The types by column name, in the order of the table, or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getColTypeList(String $tbleLabelTxt): Array|Bool;
	/**
	 * Lists the columns of a table a record cannot be written without: those
	 * that take no NULL, have no default value and are not filled by the database.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The names of those columns, in the order of the table (none: an empty list), or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function getColNeedList(String $tbleLabelTxt): Array|Bool;
	/**
	 * Lists the tables of the database, views and the database's own tables left out.
	 *
	 * @return Array|Bool The names of the tables, in name order, or false when they cannot be read.
	 */
	public function listTableNames(): Array|Bool;
	/**
	 * Closes the database connection.
	 */
	public function shutConnection(): Void;
	
}