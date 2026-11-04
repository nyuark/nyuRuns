# nyuRuns — User Guide

Version 1.0

## Summary

1. [Product overview](#1-product-overview)
2. [Minimum requirements](#2-minimum-requirements)
3. [Installation processes](#3-installation-processes)
4. [Configuration steps](#4-configuration-steps)
5. [Building your first application (run)](#5-building-your-first-application-run)
6. [Structure of your project](#6-structure-of-your-project)
7. [Code reference](#7-code-reference)
8. [Recommendations](#8-recommendations)

A. [Troubleshooting](#a-troubleshooting)

---

## 1. Product overview

nyuRuns is a PHP framework for the back end of an application: the part
that receives the requests of a web page, a mobile application or another
program, and answers them with data. You write what your service does;
nyuRuns does the rest of the request:

- it finds the action an address asks for;
- it checks who is asking, and whether they may;
- it keeps only the inputs the action declares, each in its form;
- it writes the reply as JSON, or another format, errors included.

It also brings what most services need: users with roles, signed tokens
and sessions, databases reached through models, documents, pictures,
sounds, mail, logs, and a command line, `php nyuRun`, to install,
configure, test and ship a project.

### 1.1 What you build in this guide

Step by step, you install nyuRuns on your machine, then build a small
service, `Notes`:

- a list of notes, which anyone signed in can read and search;
- one note, read, created, changed and removed through its own address;
- writing kept for the staff, through a role;
- a page that shows the notes in a browser.

At the end, you have a service that answers real requests, and you know
where each part of a project lies.

### 1.2 Before you start

You need to know PHP and the basics of HTTP: methods, statuses, headers.
You do not need a database server: the guide uses SQLite, a database held
in one file. The commands are written for a terminal; on Windows, they
work the same in PowerShell.

### 1.3 The other documents

| Document | Read it to |
|---|---|
| [Functional Specifications](funcspecs.md) | know what nyuRuns does and how its processes go |
| [Technical Specifications](techspecs.md) | know how it is built: components, files, commands, conventions |
| [`HOSTING.md`](../HOSTING.md) | put a project on a real server |
| Code reference | find a class or a method (chapter 7) |

---

## 2. Minimum requirements

### 2.1 For this guide

| You need | Why | Check it with |
|---|---|---|
| **PHP 8.2** or later, on the command line | runs nyuRuns and its command line | `php -v` |
| the extensions **json**, **dom**, **iconv**, **zlib**, **mbstring** | the libraries of nyuRuns cannot be installed without them | `php -m` |
| the extension **openssl** | signs the tokens your users sign in with | `php -m` |
| the extension **pdo_sqlite** | the database of the tutorial | `php -m` |
| **Composer** | installs the libraries | `composer --version` |
| **Git** | gets the code | `git --version` |

PHP's own web server is enough to develop: you do not need Apache or
nginx to follow this guide.

Most PHP builds hold these extensions. When one is missing from
`php -m`, enable it in your `php.ini` (`extension=pdo_sqlite`); a PHP
that loads no `php.ini` at all is given one with the `PHPRC` environment
variable, pointing at the folder that holds it. Appendix A tells what
each missing extension looks like.

### 2.2 For a real server

A server that runs a project for its users also needs a web server that
sends every address to nyuRuns, and HTTPS. What else a project may need
(another database, a mail server, ffmpeg for sounds, the extensions for
pictures and office documents) depends on what it does:

- the full list of what each part needs is in the
  [Technical Specifications, chapter 3](techspecs.md#3-system-requirements);
- how to set up the server is in [`HOSTING.md`](../HOSTING.md).

`php nyuRun deploy check` tells, on any machine, what is missing.

---

## 3. Installation processes

### 3.1 Get the code

```
git clone <repository address> notes
cd notes
composer install
```

Composer installs the libraries in `vendor/`. Every command of this guide
is then run from the `notes` folder, the root of your project.

### 3.2 Install the project

```
php nyuRun deploy start
```

The command asks two questions, then a third and fourth for the database,
each with a proposed answer that Enter takes. For this guide, answer:

| Question | Answer | Why |
|---|---|---|
| `Environment (DEV, TEST, PREV, PROD) [DEV]` | Enter | you develop: errors are shown, everything is logged |
| `Store of the users (native, usrlst, none) [usrlst]` | `native` | the users go in a database, which the tutorial needs anyway |
| `Database type (mysql, pgsql, sqlte) [mysql]` | `sqlte` | SQLite: the database is one file, no server needed |
| `Database file [nyuRuns]` | `notes.sqlite` | the file, at the root of the project |

It then does the rest by itself:

```
file              state
.env              created from .env.example
conf/access.json  created from conf/access.json.dist
file                state
conf/database.json  created from conf/database.json.dist
written: conf/keys/dftokn.key, conf/keys/dftokn.pub
written: .env NYU_API_KEY
API key (shown this once): ed80749f471013d0e26bfaf062c00dec…
written: .env NYU_TFA_KEY
Table users created.
Users created. Their passwords are shown this once:
Password of member (member) (shown this once): e8879bcb4ea90a79ad00
Password of system (system) (shown this once): 167222ae0c8cd7c7696b
Password of managr (manage) (shown this once): 3a1bfec2fa3dadd8e7ff
```

- **Your local settings** are made from their examples: `.env` holds the
  secrets, `conf/access.json` how users sign in, `conf/database.json`
  where the database is. None of them is ever committed.
- **The keys of the tokens** are made in `conf/keys`.
- **Three users** are created, one for each role: `member`, `system` and
  `managr` (who holds the `manage` role). **Copy their passwords now**:
  they are shown this once, and you need them in the next steps. Yours
  differ from those above.
- **A health check** ends the command (3.5).

Run again, `deploy start` replaces nothing that exists: a file, a secret,
a table, a user.

> Without a database, answer `usrlst`: the users are then kept in a file,
> `conf/users.lst`, no database settings are made, and the service runs
> with no database at all. You can move them to a database later, in one
> command (chapter 4).

### 3.3 Serve it

PHP has its own web server, enough to develop. Start it from the `www`
folder, in a second terminal, and leave it running:

```
cd www
php -S localhost:8000 index.php
```

Every request is sent to `index.php`, which hands it to nyuRuns. Stop the
server with Ctrl+C.

### 3.4 Send a first request

Sign in as `member`, with its password (in Windows PowerShell 5, type
`curl.exe` rather than `curl`):

```
curl -X POST http://localhost:8000/auth/login -d "user=member&pass=e8879bcb4ea90a79ad00"
```

```json
{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImtpZCI6…"}
```

The service answers with a token, signed with the keys made at
installation: a front end sends it back with each request
(`Authorization: Bearer <token>`). Try what goes wrong as well:

| Request | Reply |
|---|---|
| a wrong password | `401 {"error":"Invalid credentials","code":401}` |
| `curl http://localhost:8000/nothing/here` | `404 {"error":"Not Found","code":404}` |
| `curl http://localhost:8000/auth/login` (a `GET`) | `405 {"error":"Method Not Allowed","code":405}` |

The service starts with the default profile, which asks no credential
anywhere: chapter 4 closes it.

### 3.5 Check it

```
php nyuRun deploy check
```

```
check               state  found
php                 fine   PHP 8.5.12, 8.2 at least is needed
upload progress     warn   on: set session.upload_progress.enabled = 0 in php.ini …
extension json      fine   loaded
folder temp/logs    fine   writable
preset webapi       fine   loads
access login        fine   native through jtoken
token keys dftokn   fine   set, 4096 bits
database nyuruns    fine   answers (sqlte)
users               fine   the table users is there
errors              fine   ERR_SHOW is on in the environment DEV
…
fail: 0
warn: 1
```

Each row is `fine`, `warn` (worth a look) or `fail` (the service cannot
serve, or is exposed). A `warn` on `upload progress` comes from PHP's own
settings: set `session.upload_progress.enabled = 0` in your `php.ini`.
Appendix A tells what to do with the other rows.

### 3.6 On a real server

A project is not copied to a server as it is: it is shipped as a copy
built for that server (`php nyuRun bundle final`), installed there with
`deploy start`, behind a web server that sends every address to
`www/index.php`. Chapter 8 lists what to do before going live, and
[`HOSTING.md`](../HOSTING.md) how to set up the server.

---

## 4. Configuration steps

Every setting lives in a file of `conf/`, or in `.env` for the secrets.
`php nyuRun config` shows them and changes one value at a time, checking
it and keeping the former file in `temp/backup`. Each step below ends with
a way to see that it worked.

### 4.1 Choose the profile

The service starts with the default profile, which asks no credential.
Switch to the profile of a web API, signed in with tokens:

```
php nyuRun config entry index webapi
```

Then look at who reaches what:

```
php nyuRun config route webapi
```

```
preset: webapi
detect: autopath
others: logged
path              methods  kind    target         access
/auth/login       POST     action  auth/login     open
/auth/logout      POST     action  auth/logout    logged
/auth/activate    POST     action  auth/activate  open
…
                  any      action  users/*        manage
```

Everything now asks for a token (`others: logged`), but the sign-in and
the actions of a visitor (activation, sign-up, forgotten password), and
the `Users` run is kept for managers. Try it: without a token,
`curl http://localhost:8000/users/index` answers 401; with the token of
`member`, 404; with the one of `managr`, the list of users.

The other profiles are `servce` (programs with an API key), `websit` (a
site with a session cookie), `static` (pages and files only), and `templt`,
which is not loaded but shows every setting a profile can hold.

### 4.2 Decide who reaches what

The least role of each route is in the profile, under `rolert`. Open
`conf/presets/webapi.rps`, and find:

```json
"rolert": { "users/*": "manage" }
```

A route is named as `run/action`, `run/*` for every action of a run, or
`/path` for a declared route. The roles are ordered (`member` <
`system` < `manage`): a route asking for `system` is reached by the
managers too. Chapter 5 adds the routes of `Notes` here. After an edit,
`config route webapi` shows the result, and `deploy check` tells whether
the profile still loads.

A block like `rolert` is edited in the file: `config write` changes one
value at a time.

### 4.3 Manage the users

```
php nyuRun manage lists
```

```
id_user  userName  userRole  userStat  userMail  userLang  signDate  madeDate             lastSeen
3        managr    manage    active                                  2026-11-05 02:07:51
1        member    member    active                                  2026-11-05 02:07:51
2        system    system    active                                  2026-11-05 02:07:51
```

| To | Run |
|---|---|
| create a user, pending | `php nyuRun manage enrol ann system` (its activation code is shown once) |
| give it an address | `php nyuRun manage email ann ann@example.org` |
| give it a new password | `php nyuRun manage passw ann` (shown once) |
| give it another role | `php nyuRun manage roles ann manage` |
| lock it, unlock it | `php nyuRun manage locks ann`, `php nyuRun manage unlck ann` |
| see what a command takes | `php nyuRun manage --usage` |

The same is done over HTTP by a manager, through the `Users` run
(`users/create`, `users/lock`, …). Every change is traced in
`temp/logs/access.log`.

### 4.4 Send the access mails

Without a mail account, a new user's activation code is handed to the
manager, who passes it on. With one, the user receives it. To try it
without sending anything, use an account that writes the mails into
files:

```
cp conf/mailer.json.dist conf/mailer.json
php nyuRun config write access native.mailer devbox
```

`devbox` is the third account of the example: it writes each mail as a
file in `temp/mails`. Now create a user with an address, signed in as
`managr`:

```
curl -X POST http://localhost:8000/users/create -H "Authorization: Bearer <token of managr>" -d "user=bob&role=member&mail=bob@example.org"
```

```json
{"user":"bob","mailed":true}
```

The mail is in `temp/mails`, with its code; `temp/logs/runner.log` tells
it was sent. For real mails, fill the first account of `mailer.json` with
your SMTP server, its password in `.env` (`NYU_MAIL_PASS`), and name it
in `native.mailer`.

With mails, two more doors can open:

- **forgotten passwords** work at once: `auth/forgot`, then `auth/recovr`;
- **sign-ups** open with `php nyuRun config write access native.signup true`;
  every sign-up then waits for a manager (`manage prove`).

Give `native.lnkurl` the address of the page of your front end that takes
a code (`https://…/activate?code=%code%`), so that the mails hold a link.

### 4.5 Choose the language

The mails go in the language of each user, or in the default one:

```
php nyuRun config write system LNG_DFLT fr
```

nyuRuns brings its mails in English and French. A user's own language is
given with `manage langs ann fr`, or by the user itself (`auth/langs`).

### 4.6 Keep the secrets

`deploy start` made the secrets in `.env`, and the keys of the tokens in
`conf/keys`. Never commit them. To make a new one:

```
php nyuRun config secrt jtoken
```

It asks before replacing keys in use: new keys end every token issued
with the former ones, and the users sign in again.

### 4.7 Logs and environment

| Setting | Command | Effect |
|---|---|---|
| how much is logged | `php nyuRun config write system LOG_MODE BASE` | `FULL` everything, `BASE` without debug, `MINI` errors, warnings and notices |
| whether errors are shown | `php nyuRun config write system ERR_SHOW FALSE` | never shown in production, whatever this says |
| the environment | `php nyuRun config write system ENV_NAME PROD` | `DEV`, `TEST`, `PREV`, `PROD` |

Read a log from the command line:

```
php nyuRun cleans trace access --lasts=3
```

```
[2026-11-05 02:10] [NOTICE] Privileged login granted for user "managr" (manage) from 127.0.0.1
[2026-11-05 02:10] [NOTICE] Privileged access granted for user "managr" (manage) from 127.0.0.1 to users/create
[2026-11-05 02:10] [NOTICE] User "bob" created (member) by user "managr" from 127.0.0.1
```

### 4.8 From the users file to a database

If you answered `usrlst` at installation, your users are in
`conf/users.lst`. To move them to a database:

```
php nyuRun deploy files
php nyuRun deploy store native --agree
```

The first command makes `conf/database.json` from its example; the
second asks where the database is, creates the users table, moves every
user with its password, role and two-factor secret, and points the sign-in
at the database. The users file is kept, so the move can be undone.

---

## 5. Building your first application (run)

You build `Notes`: a list of notes that every signed-in user reads,
written by the staff (`system` role), each note with its own address and
a page for a browser. It takes a few short files.

```
runs/Notes/
  notes.sql                 the table
  mods/ListingModel.php     the list of notes
  mods/SingleModel.php      one note
  Actions.php               what the run does
  input/<action>.json       the inputs of each action
  pages/note.html           the page of a note
conf/routes/notes.rte       the addresses
test/data/NotesTest.php     its test
```

The profile is `webapi` (4.1), the database `notes.sqlite` (3.2).

### 5.1 The table

`runs/Notes/notes.sql`:

```sql
CREATE TABLE notes (
	id_note  INTEGER PRIMARY KEY AUTOINCREMENT,
	noteName VARCHAR(80) NOT NULL,
	noteText TEXT,
	noteUser VARCHAR(40),
	madeDate DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

The identifier is `id_` and the singular of the table; every other column
has 8 characters. Create the table in the database of the project:

```
php -r "(new PDO('sqlite:notes.sqlite'))->exec(file_get_contents('runs/Notes/notes.sql'));"
```

### 5.2 The models

A list of notes, `runs/Notes/mods/ListingModel.php`:

```php
<?php

namespace nyu\Runs\Notes\mods;

use nyu\Data\DBLst;

class Listing extends DBLst {

	/**
	 * Binds the list to the notes table.
	 */
	public function __construct(){
		parent::__construct( 'notes' );
	}
}
```

One note, `runs/Notes/mods/SingleModel.php`: the same, with `Single`
extending `nyu\Data\DBRec`. The actions get them by name,
`readModel('listing')` and `readModel('single')`; the models find the
key and the fields of the table by themselves.

### 5.3 The actions

`runs/Notes/Actions.php`: each public method is an action.

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltActn = 'index';

	/**
	 * Lists the notes, the newest first: those whose name holds the 'name'
	 * input, 'limit' of them at most.
	 */
	public function index(): Void {
		$noteLst = $this->modStack->readModel('listing');
		$nameTxt = strval( $this->inputReg->getDefVal('name', '') );
		if( $nameTxt!='' ){
			$noteLst->lstSearch( Array('noteName~'=>'*'.$nameTxt.'*') );
		}
		$noteLst->listOrder('^id_note');
		$noteLst->listLimit( $this->inputReg->getDefVal('limit', 20) );
		$rowList = $noteLst->readItems();
		if( $rowList===false ){
			$this->endAction(500, 'Notes not read');
		}
		$this->statCode = empty($rowList) ? 204 : 200;
		$this->asOutput = empty($rowList) ? null : $rowList;
	}

	/**
	 * Shows one note.
	 *
	 * @param String $noteNumber Identifier of the note, from the path.
	 */
	public function view( String $noteNumber ): Void {
		$this->statCode = 200;
		$this->asOutput = $this->loadNote($noteNumber)->loadRecrd($noteNumber);
	}

	/**
	 * Creates a note from the 'name' and 'text' inputs, written by the
	 * user signed in.
	 */
	public function create(): Void {
		$noteRec = $this->modStack->readModel('single');
		$noteRec->bindArray( Array(
			'noteName'=>$this->inputReg->getDefVal('name'),
			'noteText'=>$this->inputReg->getDefVal('text', ''),
			'noteUser'=>strval($this->axessReg->readValue('user'))
		) );
		$newKey = $noteRec->saveRecrd();
		if( $newKey===false ){
			$this->endAction(500, 'Note not saved');
		}
		$this->statCode = 201;
		$this->asOutput = Array('id_note'=>$newKey);
	}

	/**
	 * Changes the name or the text of a note, those of the inputs given.
	 *
	 * @param String $noteNumber Identifier of the note, from the path.
	 */
	public function update( String $noteNumber ): Void {
		$noteRec = $this->loadNote($noteNumber);
		$chngArr = Array();
		foreach( Array('name'=>'noteName', 'text'=>'noteText') as $inpNme => $fldNme ){
			$inpVal = $this->inputReg->getDefVal($inpNme);
			if( $inpVal!==null && $inpVal!=='' ){
				$chngArr[$fldNme] = $inpVal;
			}
		}
		$noteRec->bindArray($chngArr);
		if( $noteRec->saveRecrd()===false ){
			$this->endAction(500, 'Note not saved');
		}
		$this->statCode = 200;
		$this->asOutput = $noteRec->loadRecrd($noteNumber);
	}

	/**
	 * Removes a note.
	 *
	 * @param String $noteNumber Identifier of the note, from the path.
	 */
	public function remove( String $noteNumber ): Void {
		if( !$this->loadNote($noteNumber)->remvRecrd() ){
			$this->endAction(500, 'Note not removed');
		}
		$this->statCode = 204;
	}

	/**
	 * Shows a note as a page, for a browser.
	 *
	 * @param String $noteNumber Identifier of the note, from the path.
	 */
	public function card( String $noteNumber ): Void {
		$noteArr = $this->loadNote($noteNumber)->loadRecrd($noteNumber);
		$this->replyPage( 'note', Array('name'=>$noteArr['noteName'], 'text'=>$noteArr['noteText'], 'user'=>$noteArr['noteUser'], 'date'=>$noteArr['madeDate']) );
	}

	/**
	 * Gives the record of a note, or ends the action with a 404.
	 *
	 * @param String $noteNumber Identifier of the note.
	 * @return Object The record, loaded.
	 */
	protected function loadNote( String $noteNumber ): Object {
		$noteRec = $this->modStack->readModel('single');
		if( empty($noteRec->loadRecrd($noteNumber)) ){
			$this->endAction(404, 'Not Found');
		}
		return $noteRec;
	}
}
```

- An action sets its status (`statCode`) and its data (`asOutput`); the
  framework writes the reply.
- `endAction()` ends an action at once, from itself or a helper:
  `loadNote()` answers 404 for every action that needs a note.
- `loadNote()` is protected, so it is not an action: a client cannot call
  it.
- The search `noteName~` with `*` around the text is a match; a value
  alone would be compared exactly.
- The names follow the conventions of the project: 7 characters for a
  local variable, 10 for a parameter, 6 for a loop variable. The audit of
  the tests checks them (5.9).

### 5.4 The inputs

Each action declares what it accepts, in `runs/Notes/input/`:

| File | Content |
|---|---|
| `index.json` | `{ "fields": { "name": "", "limit": "Integer" } }` |
| `create.json` | `{ "fields": { "name!": "", "text": "" } }` |
| `update.json` | `{ "fields": { "name": "", "text": "" } }` |
| `view.json`, `remove.json`, `card.json` | `{ "fields": {} }` |

Only the fields listed reach the action. `name!` is required: a note sent
without one is refused (400) before the action runs. An empty format keeps
a value as it was sent; `Integer` makes `limit` a number. The texts are
kept as sent, and encoded where they are shown: by the page (5.7), or by
the front end.

### 5.5 The addresses

`conf/routes/notes.rte` gives each method of each address its action:

```json
{
	"notes" : { "target": {
		"GET":  { "app": "notes", "act": "index" },
		"POST": { "app": "notes", "act": "create" }
	} },
	"notes/$id" : { "target": {
		"GET":    { "app": "notes", "act": "view" },
		"PATCH":  { "app": "notes", "act": "update" },
		"DELETE": { "app": "notes", "act": "remove" }
	} },
	"note/$id" : { "target": { "app": "notes", "act": "card" } }
}
```

`$id` captures the part of the path, given to the action as its
parameter. Since these routes name their methods, the actions are reached
through them only: `notes/view/1` is not found.

### 5.6 The roles

In `conf/presets/webapi.rps`, add the page to the routes open to all, and
keep writing for the staff:

```json
"openrt": ["auth/login", "auth/activate", "auth/signup", "auth/forgot", "auth/recovr", "/note/$id"],
"rolert": { "users/*": "manage", "notes/create": "system", "notes/update": "system", "notes/remove": "system" }
```

Check it:

```
php nyuRun config route webapi
```

```
/notes          GET      action  notes/index    logged
/notes          POST     action  notes/create   system
/notes/$id      GET      action  notes/view     logged
/notes/$id      PATCH    action  notes/update   system
/notes/$id      DELETE   action  notes/remove   system
/note/$id       GET      action  notes/card     open
```

### 5.7 Try it

Sign in as `member` and as `system` (3.4), and keep each token. Then,
with `-H "Authorization: Bearer <token>"` on each call:

| Request | As | Reply |
|---|---|---|
| `GET /notes` | member | `204`: no note yet |
| `POST /notes` with `name=Shopping` | member | `404 {"error":"Not Found","code":404}`: writing is for the staff |
| `POST /notes` with `name=Shopping&text=Milk, bread` | system | `201 {"id_note":1}` |
| `POST /notes` with `text=No name` | system | `400 {"error":"Missing or invalid input: name","code":400}` |
| `GET /notes` | member | `200 [{"id_note":1,"noteName":"Shopping","noteText":"Milk, bread","noteUser":"system","madeDate":"2026-11-05 02:34:06"}]` |
| `GET /notes?name=shop` | member | the notes whose name holds "shop" |
| `GET /notes/1` | member | `200` the note |
| `PATCH /notes/1` with `text=Milk, bread, eggs` | system | `200` the note, changed |
| `DELETE /notes/1` | system | `204` |
| `GET /notes/1` | member | `404` |
| `GET /notes` | nobody | `401 {"error":"Missing Bearer Authorization header","code":401}` |

For instance:

```
curl -X POST http://localhost:8000/notes -H "Authorization: Bearer <token of system>" -d "name=Shopping&text=Milk, bread"
curl -X PATCH http://localhost:8000/notes/1 -H "Authorization: Bearer <token of system>" -d "text=Milk, bread, eggs"
```

A member refused is told the address is not found, as if it did not
exist: the front end should not offer what a user's role does not reach.

### 5.8 A page

`runs/Notes/pages/note.html`:

```html
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>%name%</title></head>
<body>
	<h1>%name%</h1>
	<p>%text%</p>
	<p><small>Written by %user%, %date%</small></p>
</body>
</html>
```

The action `card` fills each `%name%` with `replyPage()`; every value is
HTML-encoded. Open `http://localhost:8000/note/1` in a browser: the page
needs no sign-in, since its route is open. A note written
`Room "B" <at noon>` shows as such, its characters encoded once.

### 5.9 A test of its own

`test/data/NotesTest.php` runs the actions on a database of its own:

```php
<?php

// runs/Notes : its actions, on a SQLite database of their own made from
// runs/Notes/notes.sql.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Notes', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\App\ModelStack;
use nyu\Tools\Registry;

$basePth = inTempDir('notes.sqlite');
if( is_file($basePth) ){
	unlink($basePth);
}
(new PDO('sqlite:'.$basePth))->exec( strval(file_get_contents(RUN_DIR.DS.'Notes'.DS.'notes.sql')) );
useSqlite($basePth);
require RUN_DIR.DS.'Notes'.DS.'Actions.php';

/**
 * Runs an action of Notes, as a user signed in.
 *
 * @param String  $actionName Action to run.
 * @param ?String $actnOption The identifier of the note, when the action takes one.
 * @param Array   $inputArray Inputs of the action.
 * @return Array The status and the output of the action.
 */
function noteCall( String $actionName, ?String $actnOption=null, Array $inputArray=Array() ): Array {
	$actnSet = new nyu\Runs\Notes\Actions( $actionName, $actnOption, new Registry($inputArray), new ModelStack('Notes'), new Registry(Array('logged'=>true, 'user'=>'tester')) );
	return Array($actnSet->statCode, $actnSet->asOutput);
}

checkSame('an empty list', noteCall('index'), Array(204, null));
checkSame('a note is created', noteCall('create', null, Array('name'=>'Shopping', 'text'=>'Milk'))[0], 201);
checkSame('it is listed, with its author', array_column(noteCall('index')[1], 'noteUser', 'noteName'), Array('Shopping'=>'tester'));
checkSame('it is changed', noteCall('update', '1', Array('text'=>'Milk, eggs'))[1]['noteText'] ?? null, 'Milk, eggs');
checkSame('it is removed, then not found', Array(noteCall('remove', '1')[0], noteCall('view', '1')[0]), Array(204, 404));

endChecks();
```

Run it with the audit of the conventions:

```
php test/run.php Notes audit
```

```
15 checks passed, 0 failed
```

### 5.10 What you built

A service with a resource at its own address, read by every user, written
by the staff, each method its action, each action its inputs, a page for a
browser and a test. From here:

- add a field: a column of 8 characters in the table, and the input that
  fills it;
- link notes to another table with a column `<table>_id`, and declare it
  as a child of the record (Technical Specifications, 10.3);
- send a mail when a note is written, through an account of
  `mailer.json`;
- ship the project to a server (chapter 8).

---

## 6. Structure of your project

Your project, after chapter 5:

```
notes/
  www/index.php               the only file the web server reaches
  runs/
    Notes/                    your run: chapter 5
    Auth/  Users/  Shows/     the runs nyuRuns brings: sign-in, users, an example
    Shared/                   what your runs share: models, credential checks
  conf/
    system.ini                logs, errors, caches, limits
    access.json               how users sign in            (yours, not committed)
    database.json             where the database is        (yours, not committed)
    mailer.json               the mail accounts            (yours, not committed)
    presets/webapi.rps        the profile of the service, its open routes and roles
    routes/notes.rte          your addresses
    keys/                     the keys of the tokens       (yours, not committed)
  .env                        the secrets                  (yours, not committed)
  notes.sqlite                the database of the tutorial
  temp/                       logs, caches, mails written to files
  test/data/NotesTest.php     your test
  src/                        nyuRuns itself
  vendor/                     the libraries
```

What you write lies in `runs/`, `conf/routes`, the profile and `test/`.
What nyuRuns brings lies in `src/` and the runs it delivers. What belongs
to one machine (the files marked "yours", and `temp/`) never leaves it:
git ignores it, and the copy built for a server leaves it out.

`notes.sqlite` is left out of that copy too, but git does not know it:
add it to `.gitignore`, or move the database out of the project and give
its full path in `conf/database.json`.

The full layout, and what a bundle keeps of it, is in the
[Technical Specifications, chapter 11](techspecs.md#11-directory-structure).

---

## 7. Code reference

Every class and method of nyuRuns has a docblock telling what it does,
what it takes and what it gives. The code reference is a site made from
them: one page per class, its methods with their parameters, and a search.

### 7.1 Make it

The reference is made by phpDocumentor 3, which is not one of the
libraries of a project: get its `.phar` from the phpDocumentor site, and
put it in `temp/tools` (or anywhere on your machine). Then, from the root
of the project:

```
php -d memory_limit=1G temp/tools/phpDocumentor.phar run
```

It reads `phpdoc.dist.xml`: the classes of `src`, with their public and
protected methods. The reference is written in `docs/site/reference`, in
about ten seconds; it is not tracked by git. phpDocumentor needs the
`mbstring` extension.

With PowerShell, `pwsh .phpdoc/build.ps1` makes the whole documentation
site at once: the reference, then the pages of the documents you are
reading, written by nyuRuns' own Markdown parser and HTML writer, in the
look of the project. The `.md` files stay the source; `docs/site` is made
from them.

### 7.2 Read it

Open `docs/site/index.html` in a browser, or serve the folder
(`php -S localhost:8790 -t docs/site`); the code reference is the last
entry of its menu, `docs/site/reference/index.html`. The classes are
listed by namespace (chapter 5 of the Technical Specifications tells
what each holds); the ones you use most in a run are:

| Class | For |
|---|---|
| `nyu\App\Actionset` | what an action is given, and its helpers (`replyPage()`, `endAction()`, `needsRole()`, …) |
| `nyu\Data\DBRec`, `nyu\Data\DBLst` | a record and a list of a table |
| `nyu\Data\DBStore\Filter` | how a filter is written |
| `nyu\Tools\Variable` | the formats of the inputs (`TextStr`, `Integer`, `EmlAddr`, …) |
| `nyu\Tools\Request` | the request: headers, cookies, files, client |
| `nyu\Data\Mail\Mailer` | sending a mail from an action |

### 7.3 Write for it

The docblocks of your own runs follow the same rule as the framework's:
every public method has one, with `@param` and `@return`. They are not in
the reference, which covers `src` only; add `<path>runs</path>` beside
`<path>src</path>` in `phpdoc.dist.xml` to have them too.

---

## 8. Recommendations

A checklist for the day your service goes to a real server. Each line
links to where it is told in full: the advice in the
[Technical Specifications, chapter 2](techspecs.md#2-guidelines-and-recommendations),
the design in the
[Functional Specifications, chapter 8](funcspecs.md#8-development-guidelines),
the server in [`HOSTING.md`](../HOSTING.md).

### 8.1 Before you ship

- [ ] The test suite passes, your runs' tests and the audit included:
      `php test/run.php`.
- [ ] Every action has its `input/<action>.json`, and every route its
      least role: `php nyuRun config route <profile>` shows nothing open
      that should not be.
- [ ] The front end hides what a user's role does not reach: the service
      answers it as not found.

### 8.2 Ship a copy, not the folder

- [ ] Build the copy for production: `php nyuRun bundle final --archv`. It
      holds no secret, no local setting, no private key and no test; its
      `system.ini` is set for production (`PROD`, errors hidden, logs in
      `MINI`, the config cache on), and its classes are mapped.
- [ ] On the server, install it with `php nyuRun deploy start`: the
      secrets and the keys are made there, and never leave it.
- [ ] Keep `.env` readable by its owner only (`chmod 600 .env`).

### 8.3 The server

- [ ] The web server serves `www` alone, and sends every address to
      `www/index.php` (`HOSTING.md`, "Web server").
- [ ] HTTPS, ended by the web server or a proxy in front; behind a proxy,
      `PRX_LIST` names it, and only it.
- [ ] A PHP release with its latest security fixes, and
      `session.upload_progress.enabled = 0` in `php.ini`.
- [ ] The user the web server runs as writes in `temp/` only.

### 8.4 The settings

- [ ] A real mail account in `mailer.json`, its password in `.env`, and
      `native.lnkurl` an `https` address of your front end.
- [ ] The limit on wrong attempts kept on (`limits` in `access.json`).
- [ ] Two managers at least: one alone cannot reset its own account.
- [ ] After editing a file of `conf/` by hand, run
      `php nyuRun config cache`: with the cache on, the service reads the
      former settings until then. `deploy check` warns about a cache
      older than its files.

### 8.5 Before it serves, and after

- [ ] `php nyuRun deploy check` ends with `fail: 0`; in a deployment
      script, its exit code stops the script on a failure.
- [ ] Save what cannot be made again: the database, `.env`,
      `conf/keys` and the local files of `conf/`.
- [ ] Read the logs now and then (`php nyuRun cleans trace access`), and
      remove the old ones (`php nyuRun cleans purge logs --older=30`).
- [ ] To update, build a new copy and lay it over the code: `.env`, the
      keys, the local files of `conf/` and the data stay as they are; then
      `php nyuRun config cache`. The copy brings its own `system.ini`, the
      profiles and the routes: a setting changed on the server is changed
      again, or kept in a mode of the project (`bundle mkpak`).

---

## A. Troubleshooting

### A.1 Where to look

1. **The reply.** In development (`ERR_SHOW = TRUE`, not in `PROD`), an
   error in the code is told in the reply, with its file and line.
2. **The logs**, in `temp/logs`, one file per subject: `error.log` (the
   errors of the code), `access.log` (sign-ins, refusals, and why),
   `runner.log` (what was not found and why, mails, removals, uploads),
   `shell.log` (what the commands changed). `php nyuRun cleans trace
   <channel>` shows the last lines.
3. **`php nyuRun deploy check`**, for the settings (A.3).
4. **`php nyuRun config route <profile>`**, for who reaches what.

### A.2 Replies that surprise

| You get | Why | What to do |
|---|---|---|
| `404` on an action you just wrote | the client is never told why: the action is not a public method of `Actions`, its run's folder or class is misnamed, a declared route limits its methods so its path no longer reaches it, or the user lacks its role | read `runner.log` (`Not found: "notes/view": …`) or `access.log` (`role … needed`) |
| `500 Internal Server Error` on a run's address alone | the default action names no action (`$dfltActn`) | `runner.log` names it |
| `500 Access misconfigured` on every request | a setting of access cannot be applied: an unknown role in `rolert`, a route both open and given a role, a carrier and a check that cannot work together, a sign-in route that is not a path of the site | `access.log` tells which (`Access misconfigured: …`) |
| `500` naming `Input misconfigured` | an `input/<action>.json` that is not `{ "fields": … }`, or names a format that does not exist | fix the file; the formats are those of `nyu\Tools\Variable` |
| `400 Missing or invalid input: …` | a required field (`name!`) was missing or empty | send it; check its name |
| `401 Missing Bearer Authorization header` | the profile asks for a token | sign in, and send `Authorization: Bearer <token>` |
| `401 Invalid credentials` with the right password | the account is not active (pending, in review, locked) | `php nyuRun manage lists` shows its status |
| `400 Password sent in the query string` | the password came in the address | send it in the body, with `POST` |
| `405 Method Not Allowed` | the route does not take that method | its `Allow` header lists those it takes |
| `413 Payload Too Large` | the body is larger than `REQ_SIZE`, or has more parts than `REQ_PART` | raise them in `system.ini` if it is meant |
| `429 Too many attempts` | the address sent too many wrong credentials | wait `Retry-After` seconds, or `php nyuRun cleans frees <address>` |
| `501 Tokens are issued by another server` | the profile only checks tokens, signed elsewhere | sign in on the service that issues them |
| a change in `conf/` is not seen | the config cache is on (`CNF_CACH`) | `php nyuRun config cache` |
| `"mailed": true`, but no mail arrives | the mail failed after the reply | `runner.log` tells why; with a `tofile` account, look in `temp/mails` |
| every request: `Class "nyu\Runner" not found` | PHP's own web server started outside `www` | start it from `www` (3.3) |

### A.3 The rows of `deploy check`

A `fail` stops the service, or exposes it; a `warn` deserves a look.

| Row | Says | Do |
|---|---|---|
| `php` | below 8.2 | update PHP |
| `php patch`, `upload progress` | a PHP without its latest fixes, upload progress on | update PHP; `session.upload_progress.enabled = 0` in `php.ini` |
| `extension json`, `extensions` | a required or optional extension missing | A.4 |
| `folder temp/…` | missing or not writable | let the user of the web server write in `temp/` |
| `access` | `conf/access.json` missing | `php nyuRun deploy files` |
| `access login` | no sign-in, or one that names a check or a block that is not there | fix the `login` block of `access.json` |
| `preset <name>` | the profile does not load (a key it does not know, a role that is not one, an open route given a role) | the row tells which; fix the `.rps` file |
| `secret …`, `token keys …`, `2FA key …` | a secret or key missing, too short, or not a key | `php nyuRun config secrt jtoken` (or `apikey`, `tfakey`) |
| `database …` | the database does not answer, or its extension is not loaded | check `conf/database.json`, the server, and A.4 |
| `users` | the table or the users file is missing | `php nyuRun deploy users` |
| `access mails …`, `access link …`, `access codes …` | the mail account, the link or the length of the codes is not one | see 4.4 |
| `sign-ups …` | sign-ups on, but no mail to confirm them | name a mail account in `native.mailer`, or close the sign-ups |
| `config cache` | the cache is older than a file changed since | `php nyuRun config cache` |
| `table cache` | `TBL_CACH` on without `CNF_CACH` | turn both on, or both off |
| `error level` | `ERR_LEVL` names what is not an error level | write `E_*` names (`E_ALL & ~E_DEPRECATED`, between quotes) |
| `mail language` | `LNG_DFLT` is not a language tag | `en`, `fr`, `fr-ch`, … |
| `proxies` | `PRX_LIST` trusts every address | list your proxies only |
| `.env`, `key rights …` | readable by other users of the system | `chmod 600` |
| `cookie …`, `origins …` | a session cookie over plain HTTP in production, every site allowed to call | `secure` true, and list the origins (`origns`) |
| `limits` | the limit on wrong attempts is off | give `limits.maxtry` a number |
| `requests` | `REQ_SIZE` is 0: any body is read | give it a size |

### A.4 A missing extension

| Missing | What you see |
|---|---|
| `json` | nothing is answered; `deploy check` fails at once |
| `dom`, `iconv`, `zlib`, `mbstring` | `composer install` refuses to install the libraries; PDF and HTML documents are not read |
| `openssl` | tokens signed with RS256 cannot be made (`token keys …: RS256 needs the openssl extension`); the 2FA secrets cannot be encrypted |
| `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql` | `database …: the extension … is not loaded`; the `data` and `http` tests are skipped without `pdo_sqlite` |
| `gd`, `imagick`, `zip`, `exif` | the pictures are not changed, SVG not drawn, office documents and photos' details not read: `deploy check` lists them under `extensions` |

Enable an extension in `php.ini` (`extension=openssl`), and check it with
`php -m`. A command-line PHP that loads no `php.ini` is given one through
the `PHPRC` environment variable.

---
