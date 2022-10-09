<?php

namespace nyu\Data\DBStore;

// What a Record and a List need from a database, whatever its kind. The
// SQL types answer through their handler (nyu\Data\DBHndlr); a document
// store answers with its own driver.
//
// Rows are found, changed and deleted through the filter array of
// nyu\Data\DBFilter: 'field'=>value, with the '!', '<=', '>=', '<', '>'
// and '?' suffixes on the field name.
interface Store{

	/**
	 * Checks a table or field name: letters, digits and underscores, with
	 * an optional table prefix (`table.field`).
	 *
	 * @param String $fieldLabel The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function checkName(String $fieldLabel): String;

	/**
	 * Describes a table: its field names and its key field.
	 *
	 * @param String $tableLabel Name of the table.
	 * @return Array|Bool The field names under 'flds' (empty when the store sets no list of fields) and the key field under 'pkey' ('' when the table has none), or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfo(String $tableLabel): Array|Bool;

	/**
	 * Reads the rows of a table matching a filter.
	 *
	 * The options every store honours:
	 *   'field' => list of the fields to read, all of them when empty;
	 *   'order' => list of Array(field, descending) pairs to sort by;
	 *   'limit' => greatest number of rows, null for no limit;
	 *   'offst' => number of rows to pass over first, null for none.
	 * The options only the SQL types honour, refused by the others:
	 *   'funcs' => list of Array('funct'=>, 'field'=>, 'alias'=>) function selections, such as count(*);
	 *   'joins' => Array(table, field, field) of a joined table;
	 *   'group' => list of the fields to group by;
	 *   'devel' => Array of 'field', 'where', 'order' and 'limit' fragments written as SQL by the developer.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array, empty for every row.
	 * @param Array  $findOption Options of the reading, see above.
	 * @return Array|Bool The rows, each an array of its fields, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or an option is not supported by the store.
	 */
	public function findItems(String $tableLabel, Array $whereArray=Array(), Array $findOption=Array()): Array|Bool;

	/**
	 * Adds a row to a table.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $valueArray Map of field name => value of the new row.
	 * @return String|Bool The key the database gave to the row, '' when it gave none, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid.
	 */
	public function insertRow(String $tableLabel, Array $valueArray): String|Bool;

	/**
	 * Changes the rows of a table matching a filter. An empty filter is
	 * refused: a store never changes a whole table at once.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array of the rows to change.
	 * @param Array  $valueArray Map of field name => new value.
	 * @return Int|Bool The number of rows concerned, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter is empty.
	 */
	public function updateRow(String $tableLabel, Array $whereArray, Array $valueArray): Int|Bool;

	/**
	 * Deletes the rows of a table matching a filter. An empty filter is
	 * refused: a store never empties a whole table at once.
	 *
	 * @param String $tableLabel Name of the table.
	 * @param Array  $whereArray Filter array of the rows to delete.
	 * @return Int|Bool The number of rows deleted, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter is empty.
	 */
	public function deleteRow(String $tableLabel, Array $whereArray): Int|Bool;

	/**
	 * Tells whether the store has transactions. Without them, what needs
	 * several changes to go together is refused.
	 *
	 * @return Bool True when changes can be grouped in a transaction.
	 */
	public function canTrnsct(): Bool;
	
	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure or when the store has no transactions.
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
	 * Returns the error of the last call. Each call clears the error of
	 * the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function lastError(): String|Bool;

}
