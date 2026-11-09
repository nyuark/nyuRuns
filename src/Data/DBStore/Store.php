<?php

namespace nyu\Data\DBStore;

/**
 * What a Record and a List need from a database, whatever its kind. The
 * SQL types answer through their handler (nyu\Data\DBHndlr); a document
 * store answers with its own driver.
 *
 * Rows are found, changed and deleted through the filter array of
 * the Filter class: 'field'=>value, with the '!', '<=', '>=', '<', '>'
 * and '?' suffixes on the field name.
 *
 * A binary value (a picture, a sound, any file) is written from a flux
 * (nyu\Data\Fluxd) given as the value of its field, and read back as one.
 * A binary field is only read when it is named among the fields asked
 * for: a reading that names none leaves it out.
 */
interface Store{

	// Greatest size read from a binary field unless told otherwise: 16 MB
	const BLOB_SIZE = 16777216;
	// The functions a list may select (see DBLst::pickReadFields): aggregates every SQL database reads the same way
	const FUNC_LIST = Array('count', 'min', 'max', 'sum', 'avg');

	/**
	 * Checks a table or field name: letters, digits and underscores, with
	 * an optional table prefix (`table.field`).
	 *
	 * @param String $fldLabelText The table or field name.
	 * @return String The name, as given.
	 *
	 * @throws \RuntimeException When the name holds any other character.
	 */
	public function checkNameChars(String $fldLabelText): String;

	/**
	 * Describes a table: its field names, its key field and its binary fields.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @return Array|Bool The field names under 'flds' (empty when the store sets no list of fields) the key field under 'pkey' ('' when the table has none) and the binary fields under 'blob' (empty when the store tells them value by value), or false when the table cannot be described.
	 *
	 * @throws \RuntimeException When the table name is not valid.
	 */
	public function tableInfoArray(String $tbleLabelTxt): Array|Bool;

	/**
	 * Reads the rows of a table matching a filter.
	 *
	 * The options every store honours:
	 *   'field' => list of the fields to read; all of them but the binary ones when empty;
	 *   'order' => list of Array(field, descending) pairs to sort by;
	 *   'limit' => greatest number of rows, null for no limit;
	 *   'offst' => number of rows to pass over first, null for none;
	 *   'bytes' => greatest size read from a binary field, 16 MB when left out.
	 * The options only the SQL types honour, refused by the others:
	 *   'funcs' => list of Array('funct'=>, 'field'=>, 'alias'=>) function selections, such as count(*);
	 *   'joins' => Array(table, field, field) of a joined table;
	 *   'group' => list of the fields to group by;
	 *   'devel' => Array of 'field', 'where', 'order' and 'limit' fragments written as SQL by the developer.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array, empty for every row.
	 * @param Array  $queryOptions Options of the reading, see above.
	 * @return Array|Bool The rows, each an array of its fields, a binary one as a flux (nyu\Data\Fluxd), or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, an option is not supported by the store, or a binary field is larger than accepted.
	 */
	public function findItemsArray(String $tbleLabelTxt, Array $filterFields=Array(), Array $queryOptions=Array()): Array|Bool;

	/**
	 * Adds a row to a table.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $fieldValList Map of field name => value of the new row; a flux (nyu\Data\Fluxd) for a binary field.
	 * @return String|Bool The key the database gave to the row, '' when it gave none, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid.
	 */
	public function insertTableRow(String $tbleLabelTxt, Array $fieldValList): String|Bool;

	/**
	 * Changes the rows of a table matching a filter. An empty filter is
	 * refused: a store never changes a whole table at once.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array of the rows to change.
	 * @param Array  $fieldValList Map of field name => new value; a flux (nyu\Data\Fluxd) for a binary field.
	 * @return Int|Bool The number of rows concerned, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter is empty.
	 */
	public function updateRowCount(String $tbleLabelTxt, Array $filterFields, Array $fieldValList): Int|Bool;

	/**
	 * Deletes the rows of a table matching a filter. An empty filter is
	 * refused: a store never empties a whole table at once.
	 *
	 * @param String $tbleLabelTxt Name of the table.
	 * @param Array  $filterFields Filter array of the rows to delete.
	 * @return Int|Bool The number of rows deleted, which may be 0, or false on failure.
	 *
	 * @throws \RuntimeException When a name is not valid, or the filter is empty.
	 */
	public function deleteRowCount(String $tbleLabelTxt, Array $filterFields): Int|Bool;

	/**
	 * Tells whether the store has transactions. Without them, what needs
	 * several changes to go together is refused.
	 *
	 * @return Bool True when changes can be grouped in a transaction.
	 */
	public function canTransaction(): Bool;
	
	/**
	 * Starts a transaction. Inside an open transaction, this only adds a
	 * level: the changes are written when the outermost one is set.
	 *
	 * @return Bool True when the transaction is open, false on failure or when the store has no transactions.
	 */
	public function opnTransaction(): Bool;

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
	public function remTransaction(): Bool;

	/**
	 * Returns the error of the last call. Each call clears the error of
	 * the one before.
	 *
	 * @return String|Bool The error message, or false when the last call succeeded.
	 */
	public function finalErrorText(): String|Bool;

}
