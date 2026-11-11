# nyuRuns — nyuArk's Rapid Unified Network Services

A lightweight PHP framework for the back end of your application: RESTful
APIs, plus the pages that go with them. You write what your service
actually does, and nyuRuns handles the rest of the request. It:

- works out which action a URL is asking for;
- checks who's calling, and whether they're allowed to;
- keeps only the inputs your action expects, each in the right format;
- writes the reply, as JSON or another format, errors included.

Out of the box, it also comes with users and roles (plus the mails they
need), signed tokens and sessions, two-factor codes, and databases through
models (MySQL, MariaDB, PostgreSQL, SQLite, MongoDB). On top of that you
get documents in the usual text, data and office formats, images, sounds,
mail, logs, and a command line to install, configure, test and ship your
project.

And it stays light. A request costs about as much CPU as with Slim, and
well under half of what Symfony or Laravel need for the same work
([measured on one machine](docs/funcspecs.md#7-performance-results)).

## Requirements

- PHP 8.2 or later, with `json`, `dom`, `iconv`, `zlib`, `mbstring`, plus
  `openssl` for signed tokens
- Composer and Git
- A database extension (`pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`,
  `mongodb`) if your service uses one; you don't need any to get started

The full list is in the [Technical Specifications, chapter 3](docs/techspecs.md#3-system-requirements).

## Installation

```
git clone <repository address> myservice
cd myservice
composer install
php nyuRun installs allSteps
```

`installs allSteps` asks where your users should live (`userFile` for a
file, `nativeDb` for a database), whether to create two basic users or one
per role, and what to call them. It creates your local settings and secrets, adds those users
and shows their passwords once, then finishes with a health check.

Serve it with PHP's built-in web server, from `www`:

```
cd www
php -S localhost:8000 index.php
```

Then sign in:

```
curl -X POST http://localhost:8000/auth/login -d "userName=userDemo&passWord=<its password>"
```

```json
{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIs…"}
```

Step by step: [User Guide, chapter 3](docs/userguide.md#3-installation-processes).

## Configuration

Every setting lives in `conf/` and every secret in `.env`. The command
line changes them one value at a time, checking each one as it goes:

```
php nyuRun settings wwwEntry index restapi           # the profile: an API with tokens
php nyuRun settings fullList restapi                 # who can reach what
php nyuRun settings setValue coreConf LOG_MODE BASE   # a single value
php nyuRun installs checkAll                          # what's fine, worth a look, or failing
```

Profiles (`conf/presets`) decide how a service is reached, who gets in and
how it replies: an open API, an API with keys, a web API with tokens, or a
site with sessions. Each route's minimum role is set in the profile too.
For mails, languages, users, secrets and logs, see the
[User Guide, chapter 4](docs/userguide.md#4-configuration-steps).

## Set up a simple run

A run is one application in your project, living in `runs/<Name>/`. Its
actions are the public methods of its `Actions` class:

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	/**
	 * Shows one note.
	 *
	 * @param String $idOfNoteText Identifier of the note, from the path.
	 */
	public function view( String $idOfNoteText ): Void {
		$noteArr = $this->modelStack->getModelObject('single')->loadRecordList($idOfNoteText);
		if( empty($noteArr) ){
			$this->stopThisAction(404, 'Not Found');
		}
		$this->statusCode = 200;
		$this->replyValue = $noteArr;
	}
}
```

Around it, you add a model for each table (`mods/SingleModel.php`,
extending `nyu\Data\DBRec`), the inputs of each action
(`input/view.json`), and its URLs (`runs/Notes/routes.rte`, one action per
method). The full tutorial, a list of notes everyone can read and only
staff can write, with a page and a test, is in the
[User Guide, chapter 5](docs/userguide.md#5-building-your-first-application-run).

## Further documentation

| Document | Go there to |
|---|---|
| [User Guide](docs/userguide.md) | install nyuRuns and build your first service, step by step |
| [Functional Specifications](docs/funcspecs.md) | find out what it does and how its processes run |
| [Technical Specifications](docs/techspecs.md) | see how it's built: components, files, commands, conventions |
| [Requirements](docs/requirements.md) | learn what it must achieve, and where version 1.0 stands |
| [`HOSTING.md`](HOSTING.md) | put a project on a real server |
| Code reference | look up any class or method, generated from the code |

`pwsh .phpdoc/build.ps1` builds all of it into one site, in the project's
look: open `docs/site/index.html`.

Run the tests with `php test/run.php`.

## Licensing

nyuRuns is released under the [MIT licence](LICENSE), copyright 2021-2026
Jason JOHNSTON. The libraries it uses keep their own licences, listed in
[`NOTICE`](NOTICE).

Contributions are welcome, signed off under the Developer Certificate of
Origin: see [`CONTRIBUTING.md`](CONTRIBUTING.md).
