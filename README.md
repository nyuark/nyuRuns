# nyuRuns — nyuArk's Rapid Unified Network Services

A lightweight PHP framework for the back end of an application: RESTful
APIs, and the pages beside them. You write what your service does; nyuRuns
handles the rest of the request:

- finds the action an address asks for;
- checks who is asking, and whether they may;
- keeps only the inputs the action declares, each in its form;
- writes the reply as JSON, or another format, errors included.

Out of the box, it also brings users with roles and their access mails,
signed tokens and sessions, two-factor codes, databases reached through
models (MySQL, MariaDB, PostgreSQL, SQLite, MongoDB), documents in the
common formats of text, data and office files, pictures, sounds, mail,
logs, and a command line to install, configure, test and ship a project.

It costs a request about as much CPU as Slim, and less than half of what
Symfony or Laravel cost for the same work
([measures](docs/funcspecs.md#7-performance-results)).

## Requirements

- PHP 8.2 or later, with `json`, `dom`, `iconv`, `zlib`, `mbstring`, and
  `openssl` for signed tokens
- Composer and Git
- A database extension (`pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`,
  `mongodb`) when the service uses one; none is needed to start

The full list: [Technical Specifications, chapter 3](docs/techspecs.md#3-system-requirements).

## Installation

```
git clone <repository address> myservice
cd myservice
composer install
php nyuRun deploy start
```

`deploy start` asks where the users go (`usrlst`, a file; `native`, a
database), makes your local settings and secrets, creates three users, one
for each role, shows their passwords once, and ends with a health check.

Serve it with PHP's own web server, from `www`:

```
cd www
php -S localhost:8000 index.php
```

Then sign in:

```
curl -X POST http://localhost:8000/auth/login -d "user=member&pass=<its password>"
```

```json
{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIs…"}
```

Step by step: [User Guide, chapter 3](docs/userguide.md#3-installation-processes).

## Configuration

Every setting lives in `conf/`, every secret in `.env`, and the command
line changes them one value at a time, checking each:

```
php nyuRun config entry index webapi           # the profile: an API with tokens
php nyuRun config route webapi                 # who reaches what
php nyuRun config write system LOG_MODE BASE   # one value
php nyuRun deploy check                        # what is fine, worth a look, or failing
```

The profiles (`conf/presets`) set how a service is reached, who may
enter and how it answers: an open API, an API with keys, a web API with
tokens, a site with sessions. The least role of each route is set in the
profile. Mails, languages, users, secrets and logs:
[User Guide, chapter 4](docs/userguide.md#4-configuration-steps).

## Setup a simple run

A run is an application of the project, in `runs/<Name>/`. Its actions
are the public methods of its `Actions` class:

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	/**
	 * Shows one note.
	 *
	 * @param String $noteNumber Identifier of the note, from the path.
	 */
	public function view( String $noteNumber ): Void {
		$noteArr = $this->modStack->readModel('single')->loadRecrd($noteNumber);
		if( empty($noteArr) ){
			$this->endAction(404, 'Not Found');
		}
		$this->statCode = 200;
		$this->asOutput = $noteArr;
	}
}
```

Beside it: a model of each table (`mods/SingleModel.php`, extending
`nyu\Data\DBRec`), the inputs of each action (`input/view.json`), and its
addresses (`conf/routes/notes.rte`, one action per method). The whole
tutorial, a list of notes read by every user and written by the staff,
with a page and a test: [User Guide, chapter 5](docs/userguide.md#5-building-your-first-application-run).

## Further documentation

| Document | Read it to |
|---|---|
| [User Guide](docs/userguide.md) | install nyuRuns and build a first service, step by step |
| [Functional Specifications](docs/funcspecs.md) | know what it does, and how its processes go |
| [Technical Specifications](docs/techspecs.md) | know how it is built: components, files, commands, conventions |
| [Requirements](docs/requirements.md) | know what it must achieve, and where version 1.0 stands |
| [`HOSTING.md`](HOSTING.md) | put a project on a real server |
| Code reference | every class and method, made from the code |

`pwsh .phpdoc/build.ps1` makes all of it into one site, in the look of the
project: open `docs/site/index.html`.

Run the tests with `php test/run.php`.

## Licensing

nyuRuns is released under the [MIT licence](LICENSE), copyright 2021-2026
Jason JOHNSTON. The libraries it uses keep their own licences, listed in
[`NOTICE`](NOTICE).

Contributions are welcome, signed off under the Developer Certificate of
Origin: see [`CONTRIBUTING.md`](CONTRIBUTING.md).
