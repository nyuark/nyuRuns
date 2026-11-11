# Tests

Self-checking PHP scripts, with no dependency beyond PHP itself.

```
php test/run.php              # everything
php test/run.php unit data    # only the named groups
php test/run.php Variable     # only the scripts whose name contains the word
```

The runner exits with a non-zero code when a check fails. Each `*Test.php`
script runs in its own PHP process and can also be run on its own
(`php test/unit/VariableTest.php`).

## Layout

| Folder | What it checks |
|---|---|
| `unit/` | `Variable`, `StrKit`, `Logger`, the request body parser, the Access layer and its plug-in rule, `Response`, the error handler, the model stack, the choice and settings of the database handlers, a List and a Record on a store that is not SQL, the queries of the MongoDB store |
| `data/` | the database handler, the SQL store, the filter builder, `DBLst`, `DBRec`, the native userbase, the device tokens plug-in of `runs/Shared`, several databases at once and the Shows application, on temporary SQLite databases; optionally the dialect checks on real MySQL and PostgreSQL servers, and the MongoDB store on a real MongoDB server |
| `http/` | status codes, content types and bodies over PHP's built-in web server |
| `audit/` | the coding conventions of `CONTRIBUTING.md` across `src/` and `runs/` |
| `lib/` | shared helpers: assertions (`harness.php`), sample data, the optional test servers (`servers.php`), the dialect checks (`dialect.php`), an in-memory store that behaves like a document store (`ArrayStore.php`), the fake `php://input`, the audit, the child scripts |
| `fixtures/` | static files used by the tests |

## Things to know

- **Database.** The data and HTTP tests need the `pdo_sqlite` extension and skip
  themselves without it. Apart from the optional real-server tests below, they
  touch no database server: they select the framework's own SQLite handler
  (`DBHndlr\SQLite`, type code `sqlitedb`) and point it at a SQLite file in the
  system temporary folder.
- **Real servers (optional).** `data/MySQLTest.php` and `data/PgSQLTest.php` run
  the checks of `lib/dialect.php` through the MySQL and PostgreSQL handlers. Each
  skips itself, saying why, when its extension (`pdo_mysql`, `pdo_pgsql`) is not
  loaded, when no account is configured, or when the server does not answer.
  The account comes from the `NYU_TEST_MYSQL_*` / `NYU_TEST_PGSQL_*` names of
  `.env` (see `.env.example`); without them, the account of `conf/database.json`
  is used when it is of the same type. The tests create a database of their own,
  `nyuruns_test` unless another name is given, then drop and recreate the
  `shows`, `events` and `items` tables in it. They refuse a database whose name
  does not contain "test", and never touch any other.
- **MongoDB (optional).** `data/MongoDBTest.php` runs the MongoDB store, a List
  and a Record on a real server. It skips itself when the `mongodb` extension is
  not loaded or when no server answers. A local server asking for no account is
  tried on `127.0.0.1:27017` as it is; otherwise the `NYU_TEST_MONGO_*` names of
  `.env` give the host, port, database and account. It works in the
  `nyuruns_test` database unless another name is given, where it empties and
  refills the `shows` and `events` collections. The store itself is checked
  without any server by `unit/DocStoreTest.php`, on `lib/ArrayStore.php`.
- **Extensions.** A command-line PHP that loads no `php.ini` has none of the
  database extensions (`pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `mongodb`). They can be given for one run:
  `php -d extension_dir=<php>/ext -d extension=pdo_sqlite test/data/HandlerTest.php`.
  The runner and the HTTP test start other PHP processes, which do not receive
  those `-d` options: for them, point the `PHPRC` environment variable at a
  folder holding a `php.ini` that lists the extensions.
- **HTTP.** `http/HttpTest.php` starts `php -S` on a free local port with
  `lib/httpRouter.php` and stops it afterwards. Those requests run through the
  project's real configuration, so they append to the log file set in
  `conf/system.ini`. If the test itself crashes, its server is left running and
  has to be stopped by hand.
- **Credentials.** `fixtures/users.htpasswd` holds four test-only users
  (`alice` / `s3cret`, `erin` / `tr0ub4dor`, and `bob` / `hunter2`, `frank` / `letmein`
  whose weak hashes only pass with `weakHash`). `fixtures/users.lst` is the
  users file (`userFile`): `anna`, `ben`, `cleo`, `dave` as in the sample
  database, and `emma` with a 2FA secret, password `pw-<name>`.
- **PHP notices.** A notice or warning raised by the tested code fails the script
  that triggered it, unless the test collects it with `takePhpNotices()`.
- **Conventions.** `audit/ConventionTest.php` lists the accepted exceptions at its
  top; run it with `--list` to print every finding.

## Adding a test

Create `test/<group>/<Name>Test.php`:

```php
<?php
require __DIR__.'/../lib/harness.php';

checkIdentical('label', $actual, $expected);   // strict comparison
checkTrueValue('label', $condition);
checkPregMatch('label', $string, '/regex/');
checkForThrows('label', function(){ ... }, 'TypeError');

finishChecking();
```
