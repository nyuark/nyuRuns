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

nyuRuns is a PHP framework for the back end of your application: the part
that gets requests from a web page, a mobile app or another program, and
sends back data. You write what your service actually does; nyuRuns takes
care of the rest. It:

- works out which action a URL is asking for;
- checks who's calling, and whether they're allowed to;
- keeps only the inputs your action expects, each in the right format;
- writes the reply, as JSON or another format, errors included.

It also comes with the things most services end up needing anyway: users
and roles, signed tokens and sessions, database models, documents, images,
sounds, mail, logs, and a command line (`php nyuRun`) to install,
configure, test and ship your project.

### 1.1 What you'll build

Step by step, you'll install nyuRuns on your machine and build a small
service called `Notes`:

- notes that anyone signed in can read and search;
- each note at its own URL, where you can read, create, edit and delete it;
- writing reserved for staff, through a role;
- a browser page showing a note.

By the end, you'll have a service that answers real requests, and you'll
know where every part of a project lives.

### 1.2 Before you start

You'll need to know PHP and the basics of HTTP: methods, status codes,
headers. You don't need a database server, because the guide uses SQLite,
which keeps the whole database in a single file. Commands are written for
a terminal and work just the same in PowerShell on Windows.

### 1.3 The other documents

| Document | Go there for |
|---|---|
| [Functional Specifications](funcspecs.md) | what nyuRuns does, and how its processes run |
| [Technical Specifications](techspecs.md) | how it's built: components, files, commands, conventions |
| [`HOSTING.md`](../HOSTING.md) | putting a project on a real server |
| Code reference | any class or method (chapter 7) |

---

## 2. Minimum requirements

### 2.1 For this guide

| You need | Why | Check with |
|---|---|---|
| **PHP 8.2** or later, on the command line | it runs nyuRuns and its command line | `php -v` |
| the extensions **json**, **dom**, **iconv**, **zlib**, **mbstring** | Composer won't install nyuRuns' libraries without them | `php -m` |
| the extension **openssl** | signs the tokens your users sign in with | `php -m` |
| the extension **pdo_sqlite** | the tutorial's database | `php -m` |
| **Composer** | installs the libraries | `composer --version` |
| **Git** | fetches the code | `git --version` |

PHP's built-in web server is all you need for development, so no Apache
or nginx for this guide.

Most PHP builds already include these extensions. If one is missing from
`php -m`, enable it in your `php.ini` (`extension=pdo_sqlite`). If your
PHP doesn't load a `php.ini` at all, point the `PHPRC` environment
variable at the folder that holds one. Appendix A shows what each missing
extension looks like.

### 2.2 For a real server

A server that runs your project for real users also needs HTTPS, and a
web server that sends every URL to nyuRuns. Anything beyond that depends
on what your project does: another database, a mail server, ffmpeg for
sounds, or extensions for images and office documents.

- [Technical Specifications, chapter 3](techspecs.md#3-system-requirements)
  lists what each part needs.
- [`HOSTING.md`](../HOSTING.md) walks you through setting up the server.

On any machine, `php nyuRun installs checkAll` tells you what's missing.

---

## 3. Installation processes

### 3.1 Get the code

```
git clone <repository address> notes
cd notes
composer install
```

Composer puts the libraries in `vendor/`. From here on, run every command
from the `notes` folder, the root of your project.

### 3.2 Install the project

```
php nyuRun installs allSteps
```

The command asks a few questions: the environment, where your users
live, the database, and the names of three basic accounts. Each one
suggests an answer, and Enter accepts it. For this guide, answer:

| Question | Answer | Why |
|---|---|---|
| `Environment (DEVS, TEST, PREV, PROD) [DEVS]` | Enter | you're developing: errors are shown and everything is logged |
| `Store of the users (nativeDb, userFile, none) [userFile]` | `nativeDb` | users go in a database, which the tutorial needs anyway |
| `Database type (mysql_db, postgres, sqlitedb) [mysql_db]` | `sqlitedb` | SQLite: the database is just a file, no server needed |
| `Database file [nyuRuns]` | `notes.sqlite` | the file, at the root of your project |
| `Name of the internal account [teammate]`, then the `operator` and `sysadmin` ones | Enter, three times | the names this guide uses |

Then it handles everything else by itself:

```
check            state  found
php              fine   PHP 8.5.12, 8.2 at least is needed
…
file              state
.env              created from .env.example
conf/access.json  created from conf/access.json.dist
file                state
conf/database.json  created from conf/database.json.dist
written: conf/keys/jwtToken.key, conf/keys/jwtToken.pub
written: .env NYU_API_KEY
API key (shown this once): df28c6bde54149d839d52e751165268d…
written: .env NYU_MARK_KEY
written: .env NYU_TFA_KEY
Table users created.
Users created. Their passwords are shown this once:
Password of teammate (internal) (shown this once): 501dfc2edfd1d66323e4
Password of engineer (operator) (shown this once): f5f01d6b34b3427a48b6
Password of sysAgent (sysadmin) (shown this once): 586182c0d12ce1893394
```

Here's what just happened:

- **PHP is checked first**, before anything gets written.
- **Your local settings** were made from their examples. `.env` holds the
  secrets, `conf/access.json` sets how users sign in, and
  `conf/database.json` says where the database is. None of them ever gets
  committed.
- **Token keys** were generated in `conf/keys`.
- **Three users** were created, one per role: `teammate` (`internal`),
  `engineer` (`operator`) and `sysAgent` (`sysadmin`). **Copy their passwords now**:
  you won't see them again, and you'll need them in the next steps. Yours
  will differ from the ones above.
- **A health check** wraps it all up (3.5).

Run it again and nothing breaks: `installs allSteps` never replaces what
already exists, be it a file, a secret, a table or a user.

> Don't want a database? Answer `userFile`. Your users are then kept in a
> file, `conf/users.lst`, no database settings are made, and the service
> runs without any database at all. You can move them into one later with
> a single command (4.8).

### 3.3 Serve it

PHP comes with its own web server, which is plenty for development. Start
it from the `www` folder in a second terminal, and leave it running:

```
cd www
php -S localhost:8000 index.php
```

Every request goes to `index.php`, which hands it over to nyuRuns. Press
Ctrl+C to stop the server.

### 3.4 Send a first request

Sign in as `teammate` with its password (on Windows PowerShell 5, type
`curl.exe` instead of `curl`):

```
curl -X POST http://localhost:8000/auth/openSess -d "user=teammate&pass=501dfc2edfd1d66323e4"
```

```json
{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImtpZCI6…"}
```

The service replies with a token, signed with the keys made during
installation. Out of the box, every URL wants that token, except signing
in and what visitors do before they have an account. Your front end sends
it back with each request, in an `Authorization: Bearer <token>` header.
While you're at it, try breaking things:

| Request | Reply |
|---|---|
| a wrong password | `401 {"error":"Invalid credentials","code":401}` |
| `curl http://localhost:8000/nothing/here` | `401 {"error":"Missing Bearer Authorization header","code":401}` |
| the same, with `-H "Authorization: Bearer <token>"` | `404 {"error":"Not Found","code":404}` |
| `curl http://localhost:8000/auth/openSess` with the token (a `GET`) | `405 {"error":"Method Not Allowed","code":405}` |

Chapter 4 shows how to decide who gets in where.

### 3.5 Check it

```
php nyuRun installs checkAll
```

```
check                  state  found
php                    fine   PHP 8.5.12, 8.2 at least is needed
upload progress        fine   off
extension json         fine   loaded
extensions             warn   optional, not loaded: zip, gd, exif, imagick
folder temp/logs       fine   writable
origins default       warn   every origin is allowed while the preset reads tokens: list the origins under "corsList"
preset default        fine   loads
route clash            fine   no two applications declare one path
access route           warn   naming no route: example: /login (no route on that path)
entry index            fine   starts default, which checks access
access login           fine   nativeDb through jwtToken
token keys jwtToken    fine   set, 4096 bits
token claims jwtToken  warn   no issuer or no audience: the tokens are not bound to this project; …
database nyuruns       fine   answers (sqlitedb)
users                  fine   the table users is there
errors                 fine   ERR_SHOW is on in the environment DEVS
…
fail: 0
warn: 7
```

Each row is `fine`, `warn` (worth a look) or `fail` (the service can't
serve, or is exposed). On a fresh install a few warnings are normal:
`extensions` (optional ones you may not need), `origins` for each profile
that reads tokens (any site may call the API from a browser),
`token claims` (your tokens aren't bound to your project yet),
`access route` (the `example` profile, which never gets loaded, still
names an old path) and, on Windows, `file rights`. A `warn` on `upload progress` comes from PHP's own
settings: set `session.upload_progress.enabled = 0` in your `php.ini`.
Appendix A explains the other rows.

### 3.6 On a real server

You don't copy a project to a server as is. You build a copy made for
that server (`php nyuRun bundling releases`), install it there with
`installs allSteps`, and put it behind a web server that sends every URL to
`www/index.php`. Chapter 8 has the checklist for going live, and
[`HOSTING.md`](../HOSTING.md) covers the server setup.

---

## 4. Configuration steps

Every setting lives in a file in `conf/`, except the secrets, which go in
`.env`. `php nyuRun settings` shows them and changes one value at a time:
it checks the new value first, and keeps the previous file in
`temp/archives`. Each step below ends with a way to check that it worked.

### 4.1 Choose the profile

Out of the box, every request starts the `default` profile: tokens, with
signing in left open. The `restapi` profile works the same way, and it's
the one meant for a web API, so give it to your entry point:

```
php nyuRun settings wwwEntry index restapi
```

Then see who can reach what:

```
php nyuRun settings fullList restapi
```

```
preset: restapi
detectBy: autopath
others: logged
path                    methods  kind      target          owner  access
/index                  GET      toAction  shows/overview  site   logged
/auth/openSess          POST     toAction  Auth/openSess   Auth   open
/auth/quitSess          POST     toAction  Auth/quitSess   Auth   logged
/auth/activate          POST     toAction  Auth/activate   Auth   open
…
                        any      toAction  users/*                sysadmin
```

The `owner` column says who declares each route: the site
(`conf/routes`) or a run (its own `routes.rte`). Everything needs a
token (`others: logged`). The exceptions are
signing in and what visitors do before they have an account: activating
it, signing up, recovering a forgotten password. The `Users` run is for
managers (the `sysadmin` role) only. Try it on `curl http://localhost:8000/users/fullList`:
without a token you get a 401, with `teammate`'s token a 404, and with
`sysAgent`'s the list of users.

The other profiles are `service` (programs calling with an API key),
`website` (a site with a session cookie), `statics` (pages and files
only), `mapping` (only the routes you declare), and `example`, which never
gets loaded but shows every setting a profile can hold.

### 4.2 Decide who gets in where

The minimum role for each route is set in the profile, under `rteRoles`.
Open `conf/presets/restapi.rps` and look for:

```json
"rteRoles": { "users/*": "sysadmin" }
```

Name a route as `run/action`, as `run/*` for all the actions of a run, or
as `/path` for a declared route, mount included. Roles are ranked
(`internal` < `operator` < `sysadmin`), so a route asking for `operator`
lets managers in too. Chapter 5 adds the `Notes` routes here. After an
edit, `settings fullList restapi` shows the result, and
`installs checkAll` tells you whether the profile still loads.

Blocks like `rteRoles` are edited by hand in the file, since
`settings setValue` only changes single values.

### 4.3 Manage the users

```
php nyuRun accounts fullList
```

```
id_user  userHandle  accessRole  userStatus  mailboxAdr  userLocale  signupDate  createDate           accessDate
2        engineer    operator    approved                                        2026-11-10 01:13:00
3        sysAgent    sysadmin    approved                                        2026-11-10 01:13:00
1        teammate    internal    approved                                        2026-11-10 01:13:00
```

| To | Run |
|---|---|
| create a pending user | `php nyuRun accounts makeUser ann operator` (the activation code is shown once) |
| set their email address | `php nyuRun accounts setEmail ann ann@example.org` |
| reset their password | `php nyuRun accounts password ann` (shown once) |
| change their role | `php nyuRun accounts giveRole ann sysadmin` |
| lock or unlock them | `php nyuRun accounts lockUser ann`, `php nyuRun accounts freeUser ann` |
| list what a command takes | `php nyuRun accounts --helpText` |

A manager can do all of this over HTTP too, through the `Users` run
(`users/makeUser`, `users/lockUser`, …). Every change is traced in
`temp/logs/access.log`.

### 4.4 Send the access mails

Without a mail account, a new user's activation code goes to the manager,
who passes it on. With one, the user gets it directly. To try this without
sending anything for real, use an account that writes mails to files:

```
cp conf/mailer.json.dist conf/mailer.json
php nyuRun settings setValue accsConf nativeDb.mailAcnt devbox
```

`devbox` is the third account in the example, and it saves each mail as a
file in `temp/mails`. Now, signed in as `sysAgent`, create a user with an
email address:

```
curl -X POST http://localhost:8000/users/makeUser -H "Authorization: Bearer <token of sysAgent>" -d "user=bob&role=internal&mail=bob@example.org"
```

```json
{"user":"bob","mailed":true}
```

The mail is waiting in `temp/mails`, code included, and
`temp/logs/runner.log` confirms it was sent. For real mails, fill in the
first account of `mailer.json` with your SMTP server, put its password in
`.env` (`NYU_MAIL_PASS`), and name that account in `nativeDb.mailAcnt`.

Once mails work, two more doors open:

- **Forgotten passwords** work right away: `auth/askReset`, then
  `auth/useReset`.
- **Sign-ups** open with
  `php nyuRun settings setValue accsConf nativeDb.signupOn true`. Each one
  then waits for a manager's approval (`accounts approves`).

Set `nativeDb.linkTmpl` to the page of your front end that takes a code
(`https://…/activate?code=%code%`), and the mails will include a clickable
link.

### 4.5 Choose the language

Mails go out in each user's language, or else in the default one:

```
php nyuRun settings setValue coreConf DEF_LANG fr
```

nyuRuns ships its mails in English and French. Set a user's language with
`accounts language ann fr`, or let users set their own (`auth/language`).

### 4.6 Keep the secrets

`installs allSteps` put the secrets in `.env` and the token keys in
`conf/keys`. Never commit them. To make new keys:

```
php nyuRun settings makeSecr jwtToken
```

It asks before replacing keys in use, because new keys void every token
signed with the old ones, and everyone has to sign in again.

### 4.7 Logs and environment

| Setting | Command | Effect |
|---|---|---|
| how much gets logged | `php nyuRun settings setValue coreConf LOG_MODE BASE` | `FULL` everything, `BASE` all but debug, `MINI` errors, warnings and notices |
| whether errors are shown | `php nyuRun settings setValue coreConf ERR_SHOW FALSE` | never shown in production, whatever this says |
| the environment | `php nyuRun settings setValue coreConf ENV_NAME PROD` | `DEVS`, `TEST`, `PREV`, `PROD` |

To read a log from the command line:

```
php nyuRun maintain tailLogs access --numLines=3
```

```
[2026-11-10 01:16] [NOTICE] Privileged login granted for user "sysAgent" (sysadmin) from 127.0.0.1
[2026-11-10 01:16] [NOTICE] Privileged access granted for user "sysAgent" (sysadmin) from 127.0.0.1 to users/makeUser
[2026-11-10 01:16] [NOTICE] User "bob" created (internal) by user "sysAgent" from 127.0.0.1
```

### 4.8 From the users file to a database

If you answered `userFile` during installation, your users live in
`conf/users.lst`. To move them into a database:

```
php nyuRun installs makeConf
php nyuRun installs moveUser nativeDb --yesToAll
```

The first command creates `conf/database.json` from its example. The
second asks where the database is, creates the users table, and moves
every user over, along with their password, role and two-factor secret.
Then it switches sign-in to the database. The users file is left
untouched, so you can point sign-in back at it: set `authWith` and
`authConf` to `userFile` in the `loginSet` block of `access.json`. Just keep in mind that
anything changed in the database since then won't be in the file.

---

## 5. Building your first application (run)

Time to build `Notes`: a list of notes that every signed-in user can read
and only staff (the `operator` role) can write, with each note at its own
URL and a page for browsers. It only takes a handful of short files:

```
runs/Notes/
  notes.sql                 the table
  mods/ListingModel.php     the list of notes
  mods/SingleModel.php      one note
  Actions.php               what the run does
  input/<action>.json       the inputs of each action
  pages/note.html           the page of a note
  routes.rte                its URLs
test/data/NotesTest.php     its test
```

The profile is `restapi` (4.1) and the database is `notes.sqlite` (3.2).

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

The identifier is `id_` plus the table name in the singular. nyuRuns
gives its own columns names of exactly 10 characters; yours are up to
you, and nothing checks them, so here they're 8. Create the table in your
project's database:

```
php -r "(new PDO('sqlite:notes.sqlite'))->exec(file_get_contents('runs/Notes/notes.sql'));"
```

### 5.2 The models

First, the list of notes, `runs/Notes/mods/ListingModel.php`:

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

Then a single note, `runs/Notes/mods/SingleModel.php`: the same thing,
except that `Single` extends `nyu\Data\DBRec`. Actions get them by name,
`getModelObject('listing')` and `getModelObject('single')`, and the models work out
the table's key and fields on their own.

### 5.3 The actions

`runs/Notes/Actions.php`: every public method is an action.

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltAction = 'index';

	/**
	 * Lists the notes, the newest first: those whose name holds the 'name'
	 * input, 'limit' of them at most.
	 */
	public function index(): Void {
		$noteLst = $this->modelStack->getModelObject('listing');
		$nameTxt = strval( $this->inputsRgst->obtainDefValue('name', '') );
		if( $nameTxt!='' ){
			$noteLst->replaceFilters( Array('noteName~'=>'*'.$nameTxt.'*') );
		}
		$noteLst->setOrderFields('^id_note');
		$noteLst->setLimitOffset( $this->inputsRgst->obtainDefValue('limit', 20) );
		$rowList = $noteLst->readRowsOfList();
		if( $rowList===false ){
			$this->stopThisAction(500, 'Notes not read');
		}
		$this->statusCode = empty($rowList) ? 204 : 200;
		$this->replyValue = empty($rowList) ? null : $rowList;
	}

	/**
	 * Shows one note.
	 *
	 * @param String $idOfNoteText Identifier of the note, from the path.
	 */
	public function view( String $idOfNoteText ): Void {
		$this->statusCode = 200;
		$this->replyValue = $this->loadNote($idOfNoteText)->loadRecordList($idOfNoteText);
	}

	/**
	 * Creates a note from the 'name' and 'text' inputs, written by the
	 * user signed in.
	 */
	public function create(): Void {
		$noteRec = $this->modelStack->getModelObject('single');
		$noteRec->bindFieldArray( Array(
			'noteName'=>$this->inputsRgst->obtainDefValue('name'),
			'noteText'=>$this->inputsRgst->obtainDefValue('text', ''),
			'noteUser'=>strval($this->accessRgst->readValueByKey('userName'))
		) );
		$noteKey = $noteRec->insertOrUpdate();
		if( $noteKey===false ){
			$this->stopThisAction(500, 'Note not saved');
		}
		$this->statusCode = 201;
		$this->replyValue = Array('id_note'=>$noteKey);
	}

	/**
	 * Changes the name or the text of a note, those of the inputs given.
	 *
	 * @param String $idOfNoteText Identifier of the note, from the path.
	 */
	public function update( String $idOfNoteText ): Void {
		$noteRec = $this->loadNote($idOfNoteText);
		$chngArr = Array();
		foreach( Array('name'=>'noteName', 'text'=>'noteText') as $inpNme => $fldNme ){
			$sentVal = $this->inputsRgst->obtainDefValue($inpNme);
			if( $sentVal!==null && $sentVal!=='' ){
				$chngArr[$fldNme] = $sentVal;
			}
		}
		$noteRec->bindFieldArray($chngArr);
		if( $noteRec->insertOrUpdate()===false ){
			$this->stopThisAction(500, 'Note not saved');
		}
		$this->statusCode = 200;
		$this->replyValue = $noteRec->loadRecordList($idOfNoteText);
	}

	/**
	 * Removes a note.
	 *
	 * @param String $idOfNoteText Identifier of the note, from the path.
	 */
	public function remove( String $idOfNoteText ): Void {
		if( !$this->loadNote($idOfNoteText)->removeRecdFlag() ){
			$this->stopThisAction(500, 'Note not removed');
		}
		$this->statusCode = 204;
	}

	/**
	 * Shows a note as a page, for a browser.
	 *
	 * @param String $idOfNoteText Identifier of the note, from the path.
	 */
	public function card( String $idOfNoteText ): Void {
		$noteArr = $this->loadNote($idOfNoteText)->loadRecordList($idOfNoteText);
		$this->answerPageFlag( 'note', Array('name'=>$noteArr['noteName'], 'text'=>$noteArr['noteText'], 'user'=>$noteArr['noteUser'], 'date'=>$noteArr['madeDate']) );
	}

	/**
	 * Gives the record of a note, or ends the action with a 404.
	 *
	 * @param String $idOfNoteText Identifier of the note.
	 * @return Object The record, loaded.
	 */
	protected function loadNote( String $idOfNoteText ): Object {
		$noteRec = $this->modelStack->getModelObject('single');
		if( empty($noteRec->loadRecordList($idOfNoteText)) ){
			$this->stopThisAction(404, 'Not Found');
		}
		return $noteRec;
	}
}
```

A few things worth noticing:

- An action sets its status (`statusCode`) and its data (`replyValue`), and
  the framework writes the reply.
- `stopThisAction()` stops an action on the spot, whether it's called from the
  action itself or from a helper. That's how `loadNote()` answers 404 for
  every action that needs a note.
- `loadNote()` is protected, so it isn't an action and no client can call
  it.
- `noteName~` with `*` on each side asks for a match. A plain value would
  be compared exactly.
- Names follow the project's conventions: 7 characters for a local
  variable, 12 for a parameter, 6 for a loop variable. `idOfNoteText`
  is named the way the `Shows` run names its own. The test audit checks
  them in your runs too (5.9).

### 5.4 The inputs

Each action declares what it accepts, in `runs/Notes/input/`:

| File | Content |
|---|---|
| `index.json` | `{ "fieldSet": { "name": "", "limit": "Integer" } }` |
| `create.json` | `{ "fieldSet": { "name!": "", "text": "" } }` |
| `update.json` | `{ "fieldSet": { "name": "", "text": "" } }` |
| `view.json`, `remove.json`, `card.json` | `{ "fieldSet": {} }` |

Only the listed fields reach the action. `name!` is required, so a note
sent without a name is turned away (400) before the action even runs. An
empty format keeps the value exactly as sent, while `Integer` turns
`limit` into a number. Texts are stored as sent and encoded where they're
displayed: by the page (5.8), or by your front end.

### 5.5 The URLs

`runs/Notes/routes.rte` maps each method of each URL to its action:

```json
{
	"" : { "pointsTo": {
		"GET":  { "doAction": "index" },
		"POST": { "doAction": "create" }
	} },
	"$id" : { "pointsTo": {
		"GET":    { "doAction": "view" },
		"PATCH":  { "doAction": "update" },
		"DELETE": { "doAction": "remove" }
	} },
	"/note/$id" : { "pointsTo": { "doAction": "card" } }
}
```

A run's routes are mounted under its name in lower case, so `""` becomes
`/notes` and `$id` becomes `/notes/$id`. A path with a leading slash
starts from the site root instead: `/note/$id`. Each target names one of
the run's actions with `doAction` alone, and `$id` captures that part of the
path and hands it to the action as its parameter.

Once a run has its own routes file, that file is the only way in:
`notes/view/1` gets a 404. The profile can mount a run elsewhere
(`"mountSet": { "notes": "api/notes" }` in its `routings` block), or stop
serving it (`false`).

### 5.6 The roles

In `conf/presets/restapi.rps`, open the page route to everyone, and keep
writing for staff:

```json
"openList": ["auth/openSess", "auth/activate", "auth/register", "auth/askReset", "auth/useReset", "/.well-known/jwks.json", "notes/card"],
"rteRoles": { "users/*": "sysadmin", "notes/create": "operator", "notes/update": "operator", "notes/remove": "operator" }
```

Naming the page by its action keeps it open wherever the run is mounted.
Check it:

```
php nyuRun settings fullList restapi
```

```
/notes                  GET      toAction  Notes/index     Notes  logged
/notes                  POST     toAction  Notes/create    Notes  operator
/notes/$id              GET      toAction  Notes/view      Notes  logged
/notes/$id              PATCH    toAction  Notes/update    Notes  operator
/notes/$id              DELETE   toAction  Notes/remove    Notes  operator
/note/$id               GET      toAction  Notes/card      Notes  open
```

### 5.7 Try it

Sign in as `teammate` and as `engineer` (3.4), and keep both tokens. Then add
`-H "Authorization: Bearer <token>"` to each call:

| Request | As | Reply |
|---|---|---|
| `GET /notes` | teammate | `204`: no note yet |
| `POST /notes` with `name=Shopping` | teammate | `404 {"error":"Not Found","code":404}`: writing is for the staff |
| `POST /notes` with `name=Shopping&text=Milk, bread` | engineer | `201 {"id_note":1}` |
| `POST /notes` with `text=No name` | engineer | `400 {"error":"Missing or invalid input: name","code":400}` |
| `GET /notes` | teammate | `200 [{"id_note":1,"noteName":"Shopping","noteText":"Milk, bread","noteUser":"engineer","madeDate":"2026-11-10 01:14:55"}]` |
| `GET /notes?name=shop` | teammate | the notes whose name holds "shop" |
| `GET /notes/1` | teammate | `200` the note |
| `PATCH /notes/1` with `text=Milk, bread, eggs` | engineer | `200` the note, changed |
| `DELETE /notes/1` | engineer | `204` |
| `GET /notes/1` | teammate | `404` |
| `GET /notes` | nobody | `401 {"error":"Missing Bearer Authorization header","code":401}` |

For instance:

```
curl -X POST http://localhost:8000/notes -H "Authorization: Bearer <token of engineer>" -d "name=Shopping&text=Milk, bread"
curl -X PATCH http://localhost:8000/notes/1 -H "Authorization: Bearer <token of engineer>" -d "text=Milk, bread, eggs"
```

When a user without the role is refused, they're told the URL doesn't exist, as if it
really didn't. So your front end shouldn't offer what a user's role can't
reach.

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

The `card` action fills each `%name%` placeholder through `answerPageFlag()`,
and every value is HTML-encoded. You removed note 1 at the end of 5.7, so
create a new one first, then open `http://localhost:8000/note/<its number>`
in a browser. No sign-in needed, since that route is open. A note named
`Room "B" <at noon>` shows up exactly like that, its characters encoded
once and only once.

### 5.9 A test of its own

`test/data/NotesTest.php` runs the actions against a database of its own.
A run is given a `RunContext`, what it needs for one request, and
`runAskedAction()` runs the action:

```php
<?php

// runs/Notes : its actions, on a SQLite database of their own made from
// runs/Notes/notes.sql.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Notes', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\App\ModelStack;
use nyu\App\RunContext;
use nyu\Tools\Registry;

$basePth = inTemporaryDir('notes.sqlite');
if( is_file($basePth) ){
	unlink($basePth);
}
(new PDO('sqlite:'.$basePth))->exec( strval(file_get_contents(RUN_DIR.DS.'Notes'.DS.'notes.sql')) );
switchToSqlite($basePth);
require RUN_DIR.DS.'Notes'.DS.'Actions.php';

/**
 * Runs an action of Notes, as a user signed in.
 *
 * @param String  $actionName Action to run.
 * @param ?String $idOfNoteText The identifier of the note, when the action takes one.
 * @param Array   $inputArray Inputs of the action.
 * @return Array The status and the output of the action.
 */
function noteCall( String $actionName, ?String $idOfNoteText=null, Array $inputArray=Array() ): Array {
	$runCntxt = new RunContext( $actionName, $idOfNoteText, new Registry($inputArray), new Registry(Array('loggedIn'=>true, 'userName'=>'tester')), null, null, null, new ModelStack('Notes'), 'Notes', RUN_DIR.DS.'Notes' );
	$actnSet = new nyu\Runs\Notes\Actions($runCntxt);
	$actnSet->runAskedAction();
	return Array($actnSet->statusCode, $actnSet->replyValue);
}

checkIdentical('an empty list', noteCall('index'), Array(204, null));
checkIdentical('a note is created', noteCall('create', null, Array('name'=>'Shopping', 'text'=>'Milk'))[0], 201);
checkIdentical('it is listed, with its author', array_column(noteCall('index')[1], 'noteUser', 'noteName'), Array('Shopping'=>'tester'));
checkIdentical('it is changed', noteCall('update', '1', Array('text'=>'Milk, eggs'))[1]['noteText'] ?? null, 'Milk, eggs');
checkIdentical('it is removed, then not found', Array(noteCall('remove', '1')[0], noteCall('view', '1')[0]), Array(204, 404));

finishChecking();
```

Run it along with the conventions audit:

```
php test/run.php Notes audit
```

```
17 checks passed, 0 failed
```

### 5.10 What you built

You now have a service with a resource at its own URL, read by every user
and written by staff. Each method has its action, each action declares its
inputs, and there's a browser page and a test on top. Where to go from
here:

- add a field: a column in the table, and the input that
  fills it;
- link notes to another table with a column named after it in the
  singular plus `_id` (such as `user_id`), and declare it as a child of
  the record (Technical Specifications, 10.3);
- send a mail whenever a note is written, through an account in
  `mailer.json`;
- ship the project to a server (chapter 8).

---

## 6. Structure of your project

Here's your project once chapter 5 is done:

```
notes/
  www/index.php               the only file the web server can reach
  nyuRun                      the command line
  runs/
    Notes/                    your run, with its own routes (chapter 5)
    Auth/  Users/  Shows/     the runs nyuRuns ships: sign-in, users, an example
    Shared/                   what your runs share: models, credential checks
  conf/
    system.ini                logs, errors, caches, limits
    access.json               how users sign in            (yours, not committed)
    database.json             where the database is        (yours, not committed)
    mailer.json               the mail accounts            (yours, not committed)
    presets/restapi.rps      the service's profile: its open routes and roles
    routes/                   the routes of the whole site
    keys/                     the token keys               (yours, not committed)
  .env                        the secrets                  (yours, not committed)
  notes.sqlite                the tutorial's database
  temp/                       logs, caches, mails written to files
  test/data/NotesTest.php     your test
  src/                        nyuRuns itself
  vendor/                     the libraries
```

What you write goes in `runs/`, the profile and `test/`, plus
`conf/routes` for routes of the whole site.
What nyuRuns brings lives in `src/` and the runs it ships with. Whatever
belongs to one machine (the files marked "yours", plus `temp/`) stays
there: git ignores it, and the copy built for a server leaves it out.
Nothing in `conf/keys` goes along, not even a public key, because the
server makes its own. If your server checks tokens issued by another one,
name that server's public key in a project mode (its `addPaths` paths, see
`bundling makeMode`) to bring it along.

`notes.sqlite` is left out of that copy too, but git doesn't know that.
Add it to `.gitignore`, or move the database out of the project and give
its full path in `conf/database.json`.

The full layout, and what a bundle keeps of it, is in the
[Technical Specifications, chapter 11](techspecs.md#11-directory-structure).

---

## 7. Code reference

Every class and method in nyuRuns has a docblock saying what it does,
what it takes and what it gives back. The code reference is a site built
from those docblocks: one page per class, its methods with their
parameters, and a search box.

### 7.1 Build it

The reference is built by phpDocumentor 3. It isn't one of a project's
libraries, so you fetch it yourself: download its `.phar` from the
phpDocumentor site and put it in `temp/tools`. Then, from the project
root:

```
php -d memory_limit=1G temp/tools/phpDocumentor.phar run
```

It reads `phpdoc.dist.xml`, which covers the classes in `src` with their
public and protected methods, and writes the reference to
`docs/site/reference`, which git doesn't track. phpDocumentor needs the
`mbstring` extension.

With PowerShell, `pwsh .phpdoc/build.ps1` builds the whole documentation
site in one go. First comes the reference, then the pages of the
documents you're reading right now, rendered in the project's look by
nyuRuns' own Markdown parser and HTML writer. The `.md` files stay the
source, and `docs/site` is built from them.

### 7.2 Read it

Open `docs/site/index.html` in a browser, or serve the folder
(`php -S localhost:8790 -t docs/site`). The code reference is the last
entry in the menu, `docs/site/reference/index.html`. Classes are listed by
namespace, and chapter 5 of the Technical Specifications says what each
one holds. The ones you'll use most in a run are:

| Class | For |
|---|---|
| `nyu\App\Actionset` | what an action is given, and its helpers (`answerPageFlag()`, `stopThisAction()`, `needsActionRle()`, …) |
| `nyu\Data\DBRec`, `nyu\Data\DBLst` | a record and a list of a table |
| `nyu\Data\DBStore\Filter` | how a filter is written |
| `nyu\Tools\Variable` | the formats of the inputs (`OutText`, `Integer`, `EmlAddr`, …) |
| `nyu\Tools\Request` | the request: headers, cookies, files, client |
| `nyu\Data\Mail\Mailer` | sending a mail from an action |

### 7.3 Write for it

Your own runs' docblocks follow the same rule as the framework's: every
public method gets one, with `@param` and `@return`. They don't show up in
the reference, which covers `src` only. To include them, add
`<path>runs</path>` next to `<path>src</path>` in `phpdoc.dist.xml`.

---

## 8. Recommendations

A checklist for the day your service goes live on a real server. Each
item points to where it's covered in full: the advice in the
[Technical Specifications, chapter 2](techspecs.md#2-guidelines-and-recommendations),
the design in the
[Functional Specifications, chapter 8](funcspecs.md#8-development-guidelines),
and the server in [`HOSTING.md`](../HOSTING.md).

### 8.1 Before you ship

- [ ] The test suite passes, including your runs' tests and the audit:
      `php test/run.php`.
- [ ] Every action has its `input/<action>.json` and every route its
      minimum role: `php nyuRun settings fullList <profile>` shows nothing open
      that shouldn't be.
- [ ] Your front end hides whatever a user's role can't reach, since the
      service answers it as "not found".

### 8.2 Ship a copy, not the folder

- [ ] Build the production copy: `php nyuRun bundling releases --archived`. It
      holds no secrets, no local settings, no private key and no tests.
      Its `system.ini` is set for production (`PROD`, errors hidden,
      `MINI` logs, config cache on), and its classes are mapped when
      Composer is available.
- [ ] Install it on the server with `php nyuRun installs allSteps`: secrets
      and keys are made there and never leave.
- [ ] Make `.env` readable by its owner only (`chmod 600 .env`).

### 8.3 The server

- [ ] The web server serves `www` and nothing else, and sends every URL to
      `www/index.php` (`HOSTING.md`, "Web server").
- [ ] HTTPS is handled by the web server or by a proxy in front of it.
      Behind a proxy, `PRX_LIST` names that proxy, and only that one.
- [ ] PHP is a release with its latest security fixes, and `php.ini` has
      `session.upload_progress.enabled = 0`.
- [ ] Whatever user the web server runs as can write to `temp/`, and
      nowhere else.

### 8.4 The settings

- [ ] A real mail account sits in `mailer.json`, with its password in
      `.env`, and `nativeDb.linkTmpl` is an `https` URL of your front end.
- [ ] The limit on wrong attempts stays on (`tryLimit` in `access.json`).
- [ ] You have at least two managers, since a manager can't reset, lock or
      remove their own account.
- [ ] After editing a file in `conf/` by hand, you've run
      `php nyuRun settings clrCache`. With the cache on, the service keeps
      reading the old settings until you do. `installs checkAll` warns about a
      cache older than its files.

### 8.5 Before it goes live, and after

- [ ] `php nyuRun installs checkAll` ends with `fail: 0`. On a failure it exits
      with an error code, so a deployment script stops right there.
- [ ] Back up what can't be rebuilt: the database, `.env`, `conf/keys`
      and your local files in `conf/`.
- [ ] Read the logs now and then (`php nyuRun maintain tailLogs access`),
      and clear out the old ones
      (`php nyuRun maintain cleanDir logFiles --keepDays=30`).
- [ ] To update, build a new copy and lay it over the code. `.env`, the
      keys, your local files in `conf/` and the data stay as they are;
      then run `php nyuRun settings clrCache`. The copy brings its own
      `system.ini`, profiles and routes, so a setting you changed on the
      server has to be changed again, or kept in a project mode
      (`bundling makeMode`).

---

## A. Troubleshooting

### A.1 Where to look

1. **The reply.** In development (`ERR_SHOW = TRUE`, and never in
   `PROD`), a code error shows up right in the reply, with its file and
   line.
2. **The logs** in `temp/logs`, one file per topic: `error.log` (code
   errors), `access.log` (sign-ins, refusals and why), `runner.log` (what
   wasn't found and why, mails, removals, uploads), `shell.log` (what
   commands changed). `php nyuRun maintain tailLogs <channel>` shows the latest
   lines.
3. **`php nyuRun installs checkAll`** for the settings (A.3).
4. **`php nyuRun settings fullList <profile>`** for who can reach what.

### A.2 Replies that surprise you

| You get | Why | What to do |
|---|---|---|
| `404` on an action you just wrote | the client is never told why. Maybe the action isn't a public method of `Actions`, its run's folder or class is misnamed, its run has its own routes file, so only those routes reach it, or the user lacks the role | check `runner.log` (`Not found: "notes/view": …`) or `access.log` (`role … needed`) |
| `500 Internal Server Error` on a run's URL with no action | the default action (`$dfltAction`) names no action | `runner.log` names it |
| `500 Access misconfigured` on every request | an access setting can't be applied: an unknown role in `rteRoles`, a route both open and given a role, a carrier and a check that don't work together, or a sign-in route that isn't a path of the site | `access.log` says which (`Access misconfigured: …`) |
| `500` naming `Input misconfigured` | an `input/<action>.json` that isn't `{ "fieldSet": … }`, or that names a format that doesn't exist | fix the file; formats come from `nyu\Tools\Variable` |
| `400 Missing or invalid input: …` | a required field (`name!`) was missing or empty | send it, and check its name |
| `401 Missing Bearer Authorization header` | the profile wants a token | sign in and send `Authorization: Bearer <token>` |
| `401 Invalid credentials` with the right password | the account isn't active (`awaiting`, `checking` or `disabled`) | `php nyuRun accounts fullList` shows its status |
| `400 Password sent in the query string` | the password came in the URL | send it in the body, with `POST` |
| `405 Method Not Allowed` | the route doesn't take that method | its `Allow` header lists the ones it does |
| `413 Payload Too Large` | the body is bigger than `REQ_SIZE`, or has more parts than `REQ_PART` | raise them in `system.ini` if that's intended |
| `429 Too many attempts` | that address sent too many wrong credentials | wait `Retry-After` seconds, or run `php nyuRun maintain freeAddr <address>` |
| `501 Tokens are issued by another server` | the profile only checks tokens signed elsewhere | sign in on the service that issues them |
| a change in `conf/` doesn't show | the config cache is on (`CNF_CACH`) | `php nyuRun settings clrCache` |
| `"mailed": true`, but nothing arrives | the mail failed after the reply went out | `runner.log` says why; with an `emlFiles` account, look in `temp/mails` |
| every request: `Class "nyu\Runner" not found` | PHP's built-in server was started outside `www` | start it from `www` (3.3) |

### A.3 The rows of `installs checkAll`

A `fail` means the service can't serve, or is exposed. A `warn` is worth a
look.

| Row | Says | Do |
|---|---|---|
| `php` | older than 8.2 | update PHP |
| `php patch`, `upload progress` | PHP is missing its latest fixes, or upload progress is on | update PHP; set `session.upload_progress.enabled = 0` in `php.ini` |
| `extension json`, `extensions` | a required or optional extension is missing | see A.4 |
| `folder temp/…` | missing or not writable | let the web server's user write to `temp/` |
| `access` | `conf/access.json` is missing | `php nyuRun installs makeConf` |
| `access login` | no sign-in set up, or one naming a check or block that isn't there | fix the `loginSet` block of `access.json` |
| `preset <name>` | the profile won't load (an unknown key, a role that isn't one, an open route given a role) | the row says which; fix the `.rps` file |
| `entry index` | the entry starts a profile that asks for no credentials | name a profile that checks access (4.1), or set `"isPublic": true` in its `security` block if the site is meant to be open |
| `origins <profile>` | any site may call the API from a browser | list the allowed origins under `corsList` |
| `route clash`, `route shadow` | two runs declare the same path, or a site route hides a run's route | rename one of the paths, or change a run's mount |
| `access route` | `openList` or `rteRoles` names a route that doesn't exist | fix the entry in the profile |
| `token claims …` | the tokens carry no issuer or audience, so they aren't bound to this project | `php nyuRun settings setValue accsConf jwtToken.issuer …` and `.audience` |
| `login marks` | no key for the login marks, so holding an account also locks out its owner | `php nyuRun settings makeSecr markSecr` |
| `file rights` | the rights of the secret files can't be checked (Windows) | limit `conf` and `temp` to the web server's account (`icacls`) |
| `secret …`, `token keys …`, `2FA key …` | a secret or key is missing, too short, not a valid key, or a public key that doesn't pair with its private key | `php nyuRun settings makeSecr jwtToken` (or `keysList`, `tfaCrypt`) |
| `database …` | the database doesn't answer, or its extension isn't loaded | check `conf/database.json`, the server, and A.4 |
| `users` | the users table or file is missing | `php nyuRun installs userBase` |
| `access mails …`, `access link …`, `access codes …` | the mail account, the link or the code length isn't valid | see 4.4 |
| `sign-ups …` | sign-ups are open, but there's no mail to confirm them | name a mail account in `nativeDb.mailAcnt`, or close sign-ups |
| `config cache` | the cache is older than a file changed since | `php nyuRun settings clrCache` |
| `table cache` | `TBL_CACH` is on without `CNF_CACH` | turn both on, or both off |
| `error level` | `ERR_LEVL` holds something that isn't an error level | use `E_*` names (`E_ALL & ~E_DEPRECATED`, in quotes) |
| `mail language` | `DEF_LANG` isn't a language tag | `en`, `fr`, `fr-ch`, … |
| `proxies` | `PRX_LIST` trusts every address | list only your proxies |
| `.env`, `key rights …` | other users on the system can read them | `chmod 600` |
| `cookie …`, `origins …` | a session cookie over plain HTTP in production, or every site allowed to call | set `secureOn` to true, and list the origins (`corsList`) |
| `limits` | the limit on wrong attempts is off | give `tryLimit.maxTries` a number |
| `requests` | `REQ_SIZE` is 0, so a body of any size is read | give it a size |

### A.4 A missing extension

| Missing | What you see |
|---|---|
| `json` | nothing gets answered, and `installs checkAll` fails right away |
| `dom`, `iconv`, `zlib`, `mbstring` | `composer install` refuses to install the libraries, and PDF and HTML documents can't be read |
| `openssl` | RS256 tokens can't be made (`token keys …: RS256 needs the openssl extension`), and 2FA secrets can't be encrypted |
| `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql` | `database …: the extension … is not loaded`; without `pdo_sqlite`, the `data` and `http` tests are skipped |
| `gd`, `imagick`, `zip`, `exif` | images can't be changed, SVG isn't drawn, and office documents and photo details can't be read; `installs checkAll` lists them under `extensions` |

Enable an extension in `php.ini` (`extension=openssl`) and check it with
`php -m`. A command-line PHP that loads no `php.ini` can be given one
through the `PHPRC` environment variable.

---
