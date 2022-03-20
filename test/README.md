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
| `unit/` | `Variable`, `StrKit`, `Logger`, the request body parser, the Access layer, `Response`, the error handler |
| `data/` | `DBList`, `DBRecord` and the Shows application, on a temporary SQLite database |
| `http/` | status codes, content types and bodies over PHP's built-in web server |
| `audit/` | the coding conventions of the project across `src/` and `runs/` |
| `lib/` | shared helpers: assertions (`harness.php`), sample data, the SQLite handler, the fake `php://input`, the audit, the child scripts |
| `fixtures/` | static files used by the tests |

## Things to know

- **Database.** The data and HTTP tests need the `pdo_sqlite` extension and skip
  themselves without it. They never touch MySQL: `lib/LiteHandler.php` extends the
  framework's own `DBHs\PDO`, points it at a SQLite file in the system temporary
  folder, and only translates the two MySQL-only statements `DBRecord` sends
  (`EXPLAIN table` and `UPDATE ... LIMIT 1`).
- **HTTP.** `http/HttpTest.php` starts `php -S` on a free local port with
  `lib/httpRouter.php` and stops it afterwards. Those requests run through the
  project's real configuration, so they append to the log file set in
  `conf/system.ini`.
- **Credentials.** `fixtures/users.htpasswd` holds four test-only users
  (`alice` / `s3cret`, `erin` / `tr0ub4dor`, and `bob` / `hunter2`, `frank` / `letmein`
  whose weak hashes only pass in unsecure mode).
- **PHP notices.** A notice or warning raised by the tested code fails the script
  that triggered it, unless the test collects it with `takeNotes()`.
- **Conventions.** `audit/ConventionTest.php` lists the accepted exceptions at its
  top; run it with `--list` to print every finding.

## Adding a test

Create `test/<group>/<Name>Test.php`:

```php
<?php
require __DIR__.'/../lib/harness.php';

checkSame('label', $actual, $expected);   // strict comparison
checkTrue('label', $condition);
checkLike('label', $string, '/regex/');
checkThrw('label', function(){ ... }, 'TypeError');

endChecks();
```
