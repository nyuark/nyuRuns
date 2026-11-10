# nyuRuns — Technical Specifications

Version 1.0

## Summary

1. [Introduction](#1-introduction)
2. [Guidelines and recommendations](#2-guidelines-and-recommendations)
3. [System requirements](#3-system-requirements)
4. [The request lifecycle](#4-the-request-lifecycle)
5. [Components](#5-components)
6. [Configuration files](#6-configuration-files)
7. [Command line](#7-command-line)
8. [Runs construction (applications)](#8-runs-construction-applications)
9. [Security](#9-security)
10. [Data layer](#10-data-layer)
11. [Directory structure](#11-directory-structure)
12. [Coding conventions](#12-coding-conventions)
13. [Testing](#13-testing)

---

## 1. Introduction

### 1.1 What nyuRuns is

nyuRuns is a PHP micro-framework for building RESTful API services: the
back-end of an application, from the HTTP request it receives to the reply
it sends.

Its aim is to let developers work on what their application does, and not
on the handling of requests. The framework takes the whole request in
charge: it finds the route, checks who is asking and what they may do,
keeps only the inputs an action declares and gives each its format, calls
the action, and writes the reply in the format asked for, errors included.
Around this sequence it gives the tools most services need: access to
databases through models, documents read and written in many formats,
pictures, sounds, mail, logs, and a command line to install, configure,
test and ship a project.

nyuRuns is built on a few principles:

- **MDD** (model-driven development): an application works through models
  of its data, records and lists, rather than through queries;
- **KISS** (keep it simple): what is not needed is not loaded, and a first
  install needs no database;
- **REST**: resources reached by their path, methods that say what is done,
  replies that say how it went with their status;
- **CRUDL**: create, read, update, delete and list, the operations every
  data source is given.

### 1.2 Who this document is for

This document is written for developers: those who build applications on
nyuRuns, and those who work on nyuRuns itself. It tells how the framework
is built: its components, the files that configure it, its conventions.

Other documents tell the rest:

| Document | Tells | For |
|---|---|---|
| Requirements | what nyuRuns must achieve, and why | developers, analysts, product owners |
| Functional Specifications | what nyuRuns does, and how its processes go | developers, analysts |
| User Guide | how to install it and build a first application | developers |
| Code reference | every class and method, from the code itself | developers |
| `HOSTING.md` | what the server must provide and how to set it | those who host nyuRuns |

Where a topic is fully told in another document, this one links to it
rather than repeating it.

### 1.3 Words used in this document

- **Run**: an application built on nyuRuns, in a folder of `runs/`.
- **Action**: what a run does for one request, a method of its
  `Actions.php`, reached by a path such as `shows/view/12`.
- **Preset**: a profile of the framework (how requests are checked, routed
  and answered), chosen by the entry point of the site.
- **Store**: where data is kept: a database, a users file.
- **Bundle**: a copy of a project made for one environment.
- Names written `like this` are names of the code, the configuration or
  the command line, as they are written there.

### 1.4 What version 1.0 holds

**Requests and replies.** The request lifecycle from the entry point to
the reply; routes found from the path or declared in route files, with
redirections, static files and folders, and HTML pages; inputs kept and
formatted by the definition each action gives, required fields refused
with a 400; replies written as JSON, YAML, XML, CSV, HTML or raw output;
errors written in the format of the reply, logged, and never detailed in
production.

**Configuration.** Presets, each a profile of the framework; a system file
for logs, errors, proxies and limits; secrets kept in an environment file,
outside the configuration.

**Access.** Several ways to carry an identity (Basic authentication, API
key, signed token, PHP session, client certificate) and to check it (a
password file, API keys, a users table, a users file, device tokens, or
a check an application brings); roles; a limit on wrong attempts by
address; two-factor codes; tokens signed with a key pair; users managed
through an application and from the command line; sign-ups approved by a
manager, and passwords reset by the users themselves.

**Data.** Named databases on MySQL, MariaDB, PostgreSQL, SQLite and
MongoDB; records and lists with a filter written the same way for all of
them; transactions; binary values read and sent piece by piece.

**Documents and media.** Reading and writing of text, Markdown, HTML, XML,
JSON, YAML, CSV, iCalendar, PDF, Word, Excel, OpenDocument and ePub
documents; pictures, drawings and the pictures inside documents; sounds,
cut and converted through ffmpeg.

**Mail.** Mail accounts that send through SMTP, through PHPMailer, or into
files; the access mails (activation, sign-up, reset), written from
templates in each user's language.

**Command line.** `php nyuRun`, to install a project on its server, change
its configuration, run the tests, clean what it leaves, manage its users
and devices, and build a copy of it for each environment.

**Quality.** A test suite of about 4 500 checks, run against SQLite and
against real MySQL, MariaDB, PostgreSQL and MongoDB servers, and an audit
of the coding conventions.

### 1.5 What is left for later

**Version 1.1:** SSO and LDAP connectors; codes by mail or SMS as a
second factor, and a login approved from the phone; the token issuer and audience,
the published keys and a UUID generator, which come with an authentication
server; client certificates presented through a proxy, and a preset for
them; certificate pinning; limits by route; input sources other than the request (files,
records, other APIs, the session, a stream); sockets; several joins in one
list; the encoding of inputs; reading of rich text and source code;
reading of handwriting and scanned text; mixing of sounds (waveform,
loudness, fades, tempo).

**A separate project, codename nyuTron:** the scaffolding of models and
code, an installation wizard, a manager of advanced settings, and
ready-made data kits (members, clients, orders, tasks, projects). They
are tools around nyuRuns, not part of it.

### 1.6 Where 1.0 differs from the first vision

The first description of nyuRuns, in its Requirements, promised more in
some places than 1.0 holds. What changed:

- **CRUDL out of the box.** The models give every data source its CRUDL
  operations, but a run still declares its actions: there is no endpoint
  that serves a table by configuration alone.
- **Files as data sources.** Files are read and written as documents by
  an action. Their use as a configured source of inputs, beside the
  request, is left for version 1.1.
- **Sockets.** Listed among the data handlers at first, they are left for
  version 1.1. Large data is still read and sent piece by piece.
- **Cache.** Static files are sent with their version and date, and a
  client that holds them is answered 304. Data replies are not cached:
  presets send them with no-cache headers. What the framework itself
  reads on each request is cached instead: the configuration, the routes
  and the descriptions of the tables (6.2, `CNF_CACH`, `TBL_CACH`).

---

## 2. Guidelines and recommendations

What a developer should do, and avoid, when building and shipping a
project on nyuRuns. What the server itself must provide (web server,
reverse proxy, TLS, `php.ini`) is told in `HOSTING.md`.

### 2.1 Start light, grow when needed

A new project needs no database. Its example access config signs users in
through a users file (`conf/users.lst`), which `php nyuRun deploy users`
creates with three basic users, one per role. The application only reads
that file. A project whose users must change their own password, receive
their codes by mail, sign up by themselves, or hold devices moves them to
the users table of a database in one command:

```
php nyuRun deploy store native
```

Every user keeps its identifier, password, role, status, address, language
and 2FA secret; the users file is left as it was, so the move can be undone. Devices, and their
table, only come with a `device` block in `conf/access.json`.

### 2.2 One bundle per environment

A project is never copied to a server by hand: a copy of the project
folder holds its secrets, its tests and its documents. It is shipped as a
bundle, built for the environment it goes to:

```
php nyuRun bundle final
```

A bundle holds the code, the configuration examples and the libraries,
and nothing else; its `system.ini` is set for its environment. On the
server, `php nyuRun deploy start` makes its local files, secrets and
users, and `php nyuRun deploy check` tells what is not ready. The check
ends with a failure code when a row fails, so a deployment script can
stop on it.

The framework knows four environments, set by `ENV_NAME`. The bundles of
the framework set them this way:

| Bundle | `ENV_NAME` | `ERR_LEVL` | `ERR_SHOW` | `LOG_MODE` | `CNF_CACH` | Class map |
|---|---|---|---|---|---|---|
| `local` | `DEV` | `E_ALL` | `TRUE` | `FULL` | `FALSE` | Composer's, as installed |
| `stage` | `PREV` | `E_ALL` | `FALSE` | `BASE` | `TRUE` | complete, authoritative |
| `final` | `PROD` | `E_ALL` | `FALSE` | `MINI` | `TRUE` | complete, authoritative |

In production the logs keep errors, warnings and notices: the sign-ins,
sign-outs and refusals, but no line for each access of a member.

A project with servers of its own between them (a test or integration
server, a preview for a client) declares their modes with
`php nyuRun bundle mkpak`, and builds them as the others.

### 2.3 Keep the secrets in one place

Every secret lives in `.env`: the secret or the keys of the tokens, the API
keys, the key of the 2FA secrets, the database and mail passwords. The
configuration files only name them (`env:NYU_DB_PASS`, `env:NYU_MAIL_PASS`).

- Never commit `.env`, nor the local configuration made from its
  examples: `conf/access.json`, `conf/database.json`, `conf/mailer.json`,
  `conf/users.lst`, `conf/keys/`. They are ignored by git, and left out of
  bundles.
- Make the secrets with the command line rather than by hand: it makes them
  long enough, and writes them where the configuration reads them
  (`php nyuRun config secrt jtoken`, `apikey`, `tfakey`).
- Give the users store a `tfakey`: without it, the 2FA secrets are stored
  readable, and the health check says so.

### 2.4 Mail the codes rather than hand them over

A user activated or reset by a manager needs a code to choose a password.
Without a mail account, the code is given in the reply to the manager,
through the application or the command line, who passes it on; the
sign-ups and the resets a user asks for are then closed (404). With one,
the users of the native userbase receive their codes themselves:

- Name an account of `conf/mailer.json` in the `mailer` key of the
  `native` block. A mailed code lasts `codexp` minutes (15); one handed
  over by a manager lasts longer, as it goes through a person.
- Give `lnkurl`, the address of the page of the front end that takes the
  code (`%code%`, and `%kind%`, `%user%` as needed), so that the mail
  holds a link as well as the code. Make it an `https` address.
- In development, send through a `tofile` account: the mails are written
  in `temp/mails`, and none leaves the machine.
- Open the sign-ups (`signup`) only with a mail account: the address
  confirms the sign-up, and a manager then approves it. The `limits` block
  bounds them by client (`regmax`) and in all (`regtop`), and mails the
  managers when they pile up (`regwrn`). Open `auth/signup`,
  `auth/activate`, `auth/forgot` and `auth/recovr` in `openrt`, and no more
  of the `Auth` run than the front end needs.
- The replies to a sign-up and to a forgotten password are the same
  whoever the user is: keep them so in the front end, which should not
  tell whether a name or an address is known.
- Each mail goes in the language of its user, else in `LNG_DFLT`. The
  framework brings its templates in English and French
  (`conf/mails/<language>/`); a run writes its own, with its words and its
  look, in `runs/<App>/mails/<language>/`, for each language its users
  hold.

### 2.5 Sign the tokens with a key pair

The example configuration signs the tokens with RS256: a private key signs
them, its public key checks them. Keep the private key only on the server
that issues the tokens. An application that only checks them is given the
public key alone: it issues none, and its login answers 501. HS256, one
secret shared by everyone who checks the tokens, remains for a project
made of one application.

A token stays valid until it ends: it cannot be withdrawn before. Keep
its life short (`lifetm`, an hour in the example configuration), or name a
store in the preset, so that a user locked in the store is refused at once.

### 2.6 Show errors where they help, never in production

In development, errors are shown in the reply and logged in full; in
production nothing of an error is shown to a client, whatever `ERR_SHOW`
says, and the log keeps the details. Two settings decide what is reported
at all:

- `ERR_LEVL` chooses the errors PHP reports. What it leaves out is neither
  shown nor logged: keep `E_ALL` in development, and in production leave
  out only what is not a fault (`"E_ALL & ~E_DEPRECATED"`).
- `LOG_MODE` chooses how much of what is reported is written: `FULL` adds
  the debug lines and their context, `BASE` stops at information, `MINI`
  keeps the errors, warnings and notices.

### 2.7 Declare the inputs of every action

An action receives only the fields its definition lists, in
`runs/<App>/input/<action>.json`, each given its format; the others are
left out. Declare every action, even one without inputs.

- Mark a field required, by a `!` at the end of its name (`"user!"`), when
  the action cannot work without it: a request without it is refused with
  a 400.
- List a password with an empty format, so that it is kept as sent.
- `TextStr` is meant for what is shown, not for what goes into a query:
  it encodes quotes and removes tags.
- A filter compares a value as it is. A match with a wildcard is asked for
  by the name of the field (`title~`), where only `*` is a wildcard.

### 2.8 Give each route the least access it needs

- Open a route to everyone only on purpose, in the `openrt` list of the
  preset; every other route asks for a credential.
- Ask for a role by route in the preset (`rolert`), or in the action
  itself (`needsRole()`); both must pass. A user without the role is
  answered 404, as for a route that does not exist, so a member cannot map
  the routes of the managers; keep the front end from offering them.
- Keep the limit by address on (the `limits` block): wrong passwords, keys
  and codes from one address are then held for a while.
- Never decide an access on what the client tells of itself (its agent,
  its system, its language): only its address is the server's own reading.

### 2.9 Choose the format of the replies

A preset writes its replies in one format (`json` unless told otherwise).
An action that answers otherwise, a CSV export for one, chooses its own
format for its reply (`useFormat('tabl')`). Name the sites allowed to call
from a browser (`origns`) rather than allowing every one of them.

---

## 3. System requirements

What a server needs to run a nyuRuns project, and what each optional part
adds. `php nyuRun deploy check` tells, on the server itself, what is
missing.

### 3.1 PHP

PHP 8.2 at least, on the web server and on the command line, both of the
same version. The test suite is run on the lowest release supported and
the latest (13.5).

A release fixing php2shell (CVE-2026-109618, CVE-2026-109619) is expected:
8.2.35, 8.3.36, 8.4.27, 8.5.12 or later, or a distribution's package with the
fix backported. `session.upload_progress.enabled` is best turned off in
`php.ini`: the framework does not use it, and it carries CVE-2026-115560.
`deploy check` warns about both.

The command line runs other programs (ffmpeg, the tests) through
`proc_open()`: a server that disables it in `php.ini` (`disable_functions`)
loses the sounds and the test runner, nothing else.

### 3.2 PHP extensions

| Extension | Needed for | Without it |
|---|---|---|
| `json` | everything: configuration, inputs, replies | nothing is read nor answered |
| `dom` | the HTML and XML documents, PDF writing | those documents are not read nor written |
| `iconv` | the text encodings of documents and sounds | the libraries cannot be installed |
| `zlib` | the compressed parts of PDF documents | the libraries cannot be installed |
| `mbstring` | reading and writing PDF documents | PDF documents are not read nor written |
| `openssl` | tokens signed with RS256, encrypted 2FA secrets, encrypted mail | the example configuration cannot sign tokens; 2FA secrets are stored readable |
| `pdo_mysql` | MySQL and MariaDB stores | those stores cannot be used |
| `pdo_pgsql` | PostgreSQL stores | idem |
| `pdo_sqlite` | SQLite stores, and the `data` and `http` test groups | idem; those tests are skipped |
| `mongodb` | MongoDB stores | idem |
| `zip` | Office, OpenDocument and ePub documents | those documents are not read nor written |
| `gd` | changing pictures: resize, crop, turn, strip | pictures are read, not changed |
| `imagick` | drawing SVG pictures as PNG, JPEG or WebP | SVG pictures are not drawn |
| `exif` | what a photo tells: date, camera, position | that information is not read |

`json`, `iconv`, `zlib` and `dom` are part of most PHP builds. The
libraries of the framework ask for `dom`, `mbstring`, `iconv` and `zlib`:
Composer installs them only on a PHP that has those four. A bundle carries
its libraries, so the server it goes to does not run Composer.

### 3.3 Libraries

Installed by Composer, in `vendor/`, and carried by every bundle:

| Library | Version | Used for |
|---|---|---|
| `level-2/dice` | 4.0 | the dependency injection container |
| `firebase/php-jwt` | 7.2 | signed tokens |
| `dompdf/dompdf` | 3.1 | writing PDF documents |
| `smalot/pdfparser` | 2.12 | reading PDF documents |

`phpmailer/phpmailer` is suggested, not required: a `phmail` mail account
needs it, to send attachments. The `native` and `tofile` accounts need
nothing more. The libraries and their licences are listed in `NOTICE`.

### 3.4 Other programs

- **ffmpeg**, for sounds: unpacking, cutting and converting them. Its full
  path is given by `SND_TOOL` (in `.env` through `env:NYU_SND_TOOL`), and
  `SND_TIME` bounds how long it may run (60 seconds). Without it, a sound
  is still read (its kind, its tags, its picture), its tags removed, and a
  WAV or MP3 sound cut; it is neither converted nor cut in other kinds.
- **A mail server** that speaks SMTP, for the `native` and `phmail`
  accounts: on port 465 with TLS, or on another port with STARTTLS. Only a
  server on the same machine is spoken to unencrypted.

### 3.5 Databases

None is needed: a project signs its users in from the users file until it
moves them to a database (2.1). The database servers the 1.0 test pass was
run on:

| Server | Versions |
|---|---|
| MySQL | 5.7, 8.4 |
| MariaDB | 13.0 |
| PostgreSQL | 18 |
| SQLite | the one of PHP's `pdo_sqlite` |
| MongoDB | 9.0 |

The delivered tables (`conf/schema`) are written for MySQL (MariaDB
included), PostgreSQL and SQLite. MySQL 5.7 lacks a statement the
migration of the devices uses: it has a variant of its own.

### 3.6 Web server

Any web server that hands the request to PHP: Apache, nginx with PHP-FPM,
or another. It must:

- serve the `www` folder only, and nothing above it (`conf`, `runs`,
  `src`, `temp`, `test`, `vendor`);
- send every path to `www/index.php`, which reads the route from the
  whole path of the address: a project is served at the root of its host,
  not in a sub-folder of another site (the Apache and nginx settings are in
  `HOSTING.md`);
- end TLS, or leave it to a proxy in front of it (see `HOSTING.md`).

### 3.7 Folders written

The framework writes in `temp/`: the logs (`temp/logs`, or `LOG_PATH`),
the counts of the limit by address (`temp/limits`), the config cache
(`temp/cache`), the mails of a `tofile` account (`temp/mails`). The PHP sessions are kept where
`php.ini` puts them, unless `dfsess` names a folder. The command line
writes in `conf/` and in `.env` when it changes the configuration. The
user the web server runs as needs to write in `temp/` only.

---

## 4. The request lifecycle

Every request goes through one object, the Runner (`src/Runner.php`), in
the same order. Each step can end the request with its own reply; the
later steps then never run.

```
www/index.php
  1. Start-up        preset, configuration, shared objects, logs, errors
  2. Request         size limits, inputs
  3. Route           preflight, declared routes, path
  4. Access          configuration, address held, credential, role
  5. Action          route answer, or application, inputs, models, action
  6. Reply           status, body, format, headers
```

### 4.1 Start-up

The entry point, `www/index.php`, defines the folders of the project and
creates the Runner, naming its preset (`dfault` when it names none).

1. **The preset** (`conf/presets/<name>.rps`) is read and checked: a key it
   does not know stops it from loading (`Preset::KEYSLIST`).
2. **The configuration** is loaded by the Loader: `conf/system.ini`, every
   `.json` file of `conf/`, and the values of `.env` that they name. With
   `CNF_CACH`, the preset, those files and the routes are read from
   `temp/cache` after the first request; `.env` is still read each time.
   Values given to the Runner by its caller are laid over them.
3. **The shared objects** are built by the container (`Setup\Builder`, on
   Dice): the server registry (`$_SERVER`, emptied once read), the client
   registry (its address, seen through the trusted proxies), the response
   and the request.
4. **The logs** are plugged with the configuration (`LOG_PATH`,
   `LOG_MODE`), and the program that unpacks sounds when `SND_TOOL` names
   one.
5. **The error handler** is installed, with the level of `ERR_LEVL`.

A failure before the error handler is in place cannot be formatted nor
logged by the framework: it goes to PHP's own error log, and the client
gets a plain JSON 500.

### 4.2 Reading the request

The request is read once, when it is built: its method, its address, its
headers, its query and its body (JSON, a form, or a multipart body with
files, for every method).

- A body larger than `REQ_SIZE`, or a multipart body of more parts than
  `REQ_PART`, is not read: the request is answered 413, and a warning is
  logged.
- The `input` block of the preset tells which sources the inputs are taken
  from. Server and client values are never inputs: an action reads them
  through the request.

### 4.3 Finding the route

The Router (`src/Tools/Router.php`) turns the path into a route, in this
order:

1. **A CORS preflight**, an `OPTIONS` request asking for a method, is
   answered 204 with the headers of the preset, on any path.
2. **The routes of the entry point**, in a `manually` preset.
3. **The declared routes**, in `conf/routes/*.rte` (or the folder of the
   preset): a route equal to the path first, then the first pattern that
   matches it. An empty path is looked up as `index`.
4. **The path itself**, in an `autopath` preset: its parts are given the
   names of the `mapper` of the preset, by position
   (`$appToRun/$doAction/$argument`, so `shows/view/12`).

A declared route names what answers: an action, a redirection, a static
file or folder, or an HTML page. A method it does not allow is answered
405 with its `Allow` header, and an `OPTIONS` request with the methods it
allows.

### 4.4 Checking access

The Checker (`src/Access/Checker.php`) decides whether the request goes
on. It fails closed: a configuration it cannot apply refuses every
request, and never falls back to a weaker check.

1. **The roles of the routes** (`rolert`) are read first. One that cannot
   be applied answers 500 (`Access misconfigured`).
2. **A route open to all** (`openrt`), or a preset that asks for no access,
   lets the request through, nobody logged. An open route that also asks
   for a role is a 500.
3. **The carrier and the check** (`sessby`, `authby`) must exist and work
   together, and the sign-in route must be a path of the site: otherwise
   500.
4. **An address held** for too many wrong credentials is answered 429,
   with `Retry-After`, before any credential is read.
5. **The call of an application** for one of its users is checked by its
   own key and token, when the preset names the application keys.
6. **The credential** is read by the carrier and checked. A missing or
   refused one is answered 401, with `WWW-Authenticate` for Basic and
   Bearer; a visitor asking for a page is sent to the sign-in route
   instead (302). Only a credential presented and wrong counts against the
   address.
7. **The role** of the route is compared with the user's: a user without
   it is answered 404, as if the route did not exist, and the refusal is
   logged on `access` with the role needed.

From there on, the events traced in the logs carry the user and the
address of the request, and every file received is traced, whether access
was granted or not. A request refused at this step never loads the
application.

### 4.5 Running the action

A route that answers by itself is answered here: the redirection is sent
with its status, the static file with its version and date (304 when the
client holds it, a part of it when `Range` asks), the page with its
placeholders filled. A file or a page that cannot be found is a 404, with
the same `Not Found` as every other.

Otherwise the route names an application, and its Controller
(`src/App/Controller.php`) takes over:

1. **The application** must be a folder of `runs/` named with letters and
   digits only, holding an `Actions.php` that declares its actionset.
2. **The action** is the one the route names, or the default of the
   application when it names none: the `$dfltActn` of its actionset, else
   its first action. It must be one of the actions of the application: a
   public method of its actionset, not a helper nor a method of the
   framework. An action a declared route limits is not reached by its
   path.
3. **The inputs** are given the definition of the action
   (`input/<action>.json`): the fields listed are kept, each in its format,
   the others are left out. A required field missing or empty is a 400,
   and the action is not run.
4. **The models** of the application are loaded (`ModelStack`).
5. **The actionset** is created, and runs the action, with the parameter
   of the route when there is one.

Whatever fails in steps 1 and 2 is the same reply, `404 Not Found`, as a
path no route answers: the client cannot tell an application that does
not exist from an action it may not reach. The reason is logged on
`runner`. A default action that names no action is the application's
mistake, not the client's: it is a 500, logged as an error.

An action sets its status and its output. It can end at any point, from
itself or a helper, with `endAction()`: ending is not an error, its status
is the reply's. `needsRole()` ends it with a 404, as the role of a route
does, and `replyMove()` with a redirection.

### 4.6 Writing the reply

The status decides the body:

| Status | Body |
|---|---|
| 204 without output | none |
| 204 with an output | the output, sent as a 200 |
| 2xx | the output of the action |
| 3xx | none: the reply is its `Location` header |
| 4xx, 5xx | `{"error": <message>, "code": <status>}` |

The warnings PHP raised while the request ran are added to the body
(`data` and `warnings`) when errors may be shown, except in a CSV or raw
reply: there they stay in the log. Output that cannot be written in the
format of the reply turns the reply into a 500.

An error on a page, on a static file, or on a path no route answers in a
preset that serves pages, is answered with the error page of the site
(`<htroot>/errors/<status>.html`) when it has one. An action keeps the
format of its preset.

The response then writes its headers: those of the preset, the origin
allowed when the preset lists it, `X-Content-Type-Options: nosniff` on
every reply. A `HEAD` request is answered by the headers alone. The body
is sent, and the request ends.

### 4.7 Errors on the way

An exception that no code catches, or a fatal error, is caught by the
error handler (`src/Error/handling.php`): it is logged on the `error`
channel, and answered 500 in the format of the reply. Its details are
shown only when `ERR_SHOW` allows it, and never in production. What
`ERR_LEVL` leaves out is neither shown nor logged.

---

## 5. Components

The framework lies in `src/`, under the namespace `nyu\`, loaded by
Composer (PSR-4). Its parts are grouped by namespace:

| Namespace | Holds | Used by |
|---|---|---|
| `nyu\` | the Runner | the entry point |
| `Setup` | the preset, the configuration, the container | the Runner, the command line |
| `Tools` | the request, the route, the inputs, the formats, the logs | every part |
| `Access` | the access check, its carriers and checks, the userbase | the Runner, the `Auth` and `Users` runs |
| `App` | the controller and the actionset of the runs | the Runner, the runs |
| `Output` | the response | the Runner, the actions |
| `Error` | the error handler | the Runner |
| `Data` | databases, documents, media, mail | the runs, the access layer |
| `Shell` | the command line | `php nyuRun` |

The request goes from the top of this table to the bottom; `Data` is
loaded only when a run or the access layer asks for it.

### 5.1 Runner and setup

- **`Runner`**: the sequence of chapter 4, from the preset to the reply.
- **`Setup\Preset`**: reads a preset (`conf/presets/<name>.rps`), checks
  its keys (`Preset::KEYSLIST`), and gives its blocks to the Loader.
- **`Setup\Loader`**: the configuration of the request: the files of
  `conf/`, the values of `.env` they name, and the settings of each part
  (logs, errors, request limits, proxies, sounds).
- **`Setup\ConfCache`**: the config cache: the preset as checked, the
  configuration files, the routes and the descriptions of the tables, kept
  in `temp/cache/config-<preset>.cache` and read in their place.
- **`Setup\Builder`**: the dependency injection container, on Dice. It
  builds the objects shared by the whole request: the server and client
  registries, the request and the response.

### 5.2 Tools

| Class | Role |
|---|---|
| `Registry` | a dictionary of values, the base of the registries |
| `ServerReg` | `$_SERVER`, read once and then emptied, so that no code reads it beside the request |
| `ClientReg` | what the request tells of its client: its address (through the trusted proxies), its agent, system, kind of device and language; only the address is the server's own reading |
| `Request` | the request as sent: method, path, headers, query, body, files, within the size limits |
| `Router` | the route of the request (4.3), the links to the declared routes, the error pages |
| `Input` | the inputs of an action, kept and formatted by its definition |
| `Variable` | the formats a value is given: texts, numbers, prices, dates, addresses, phone numbers, links, HTML |
| `Logger` | one log file per channel (`error`, `access`, `runner`, `shell`, one per run), PSR-3 levels, rotation |
| `Language` | the language a text is written in for one person, and the file found for it |
| `StrKit` | string helpers |
| `UniqueId` | the identifiers a record keeps wherever it is copied: UUIDs of version 7 |

### 5.3 Access

**`Access\Checker`** decides whether a request goes on (4.4). It pairs a
**carrier**, which reads the credential from the request (`sessby`), with
a **check**, which verifies it (`authby`):

| Carrier | Class | Carries |
|---|---|---|
| `hbasic` | `Sess\Basic` | a name and a password, by HTTP Basic |
| `header` | `Sess\Header` | a key in a header |
| `jtoken` | `Sess\JWT` | a signed token (HS256, RS256) |
| `phsess` | `Sess\PHPSess` | a PHP session, by its cookie |
| `clcert` | `Sess\ClCert` | a client certificate, checked by the web server |

| Check | Class | Verifies against |
|---|---|---|
| `htpass` | `Auth\PassList` | a password file in the Apache format |
| `regkey` | `Auth\RegKey` | a list of API keys |
| `usrlst` | `Auth\UserList` | the users file, read only |
| `native` | `Auth\Native` | the users table of a database |
| `device` | `Auth\Device` | the tokens of the devices |

A carrier and a check that cannot work together refuse every request
(`Checker::PAIRLIST`). Beside them:

- **`Limiter`**: counts the wrong credentials of each address, and holds
  it for a while past the limit.
- **`Totp`**: the two-factor codes (RFC 6238), and the encryption of their
  secrets.
- **`AppKey`**: the calls an application makes for one of its users.
- **`Auth\UserBase`**: the management of the native userbase: creation,
  activation, roles, statuses, passwords, tokens, sign-ups, resets.
- **`AccessMail`**: the mails of the access flows (activation, sign-up,
  reset), from their templates, in the language of their user.

### 5.4 Applications

- **`App\Controller`**: finds the application and its action, applies the
  definition of its inputs, loads its models, and runs it (4.5).
- **`App\Actionset`**: the parent of every run's `Actions` class. Each of
  the run's own public methods is an action; the actionset gives them
  their inputs, models, access, request and response, and the helpers
  `replyFile()`, `replyPage()`, `replyMove()`, `routeLink()`,
  `useFormat()`, `appLogger()`, `needsRole()`, `holdsRole()` and
  `endAction()`.
- **`App\ModelStack`**: the models of a run, every `*Model.php` of its
  `mods/` folder, then those shared in `runs/Shared`.
- **`App\StopRun`**: what `endAction()` throws to end an action on
  purpose, with its status and its output.

### 5.5 Output and errors

- **`Output\Response`**: the reply: its status, its headers (those of the
  preset, the allowed origin, `nosniff`), and its body in its format
  (`json`, `yaml`, `xmlx`, `tabl`, `html`, `flux`), a file sent in parts,
  or a page with its placeholders filled.
- **`Error/handling.php`**: the error, exception and shutdown handlers:
  they log on `error`, collect the warnings, and answer 500 through the
  Runner.

### 5.6 Data

**Databases.** A run works through models, not through queries:

- **`DBRec`**, one record of a table, loaded by its key or found by its
  fields, saved, deleted with its children; **`DBLst`**, a list of records
  read by a filter, its fields chosen, grouped, sorted, limited, joined to
  another table, or the children of a record.
- **`DBStore\Store`**, what a record and a list need from a database,
  answered by **`SqlStore`** for the SQL types and **`MongoStore`** for
  MongoDB; **`DBStore\Filter`** reads the filter, written the same way for
  all of them (`field`, `field!`, `field<=`, `field~`, ...).
- **`DBCon`**, one connection per configured database, opened on first
  use, through its handler: **`DBHndlr\MySQL`** (MariaDB included),
  **`PgSQL`**, **`SQLite`**, all on PDO.

**Documents.** A document is read into one of three shapes, and written
from one (`Data\Shape`): `nodes` (a tree of keys and values), `sheet`
(named tables of rows and cells) and `block` (text blocks in reading
order).

- **`Dcmnt`**: a file of the server, its kind told from its first bytes,
  read by the parser its extension calls for.
- **`Parsers\*`**, chosen by `Parsers\Index`: text, Markdown, HTML, XML,
  JSON, YAML, INI, CSV and TSV, iCalendar, PDF, Word, Excel, OpenDocument
  text and spreadsheet, ePub.
- **`Writers\*`**, chosen by `Writers\Index`: the same formats but INI,
  the PDF written through dompdf.
- **`Fluxd`**: a source read piece by piece (a file, the body of the
  request, a binary value of a table), never held whole unless asked.
- **`Archv`**: a zip archive read entry by entry, in memory.

**Media.**

- **`Image`**: a picture, its kind, size and what a photo tells; resized,
  cropped, turned, stripped (`gd`); a drawing made a picture (`imagick`).
- **`Sound`**: a sound, its kind, length, tags and picture; its tags
  removed, a WAV or MP3 cut. **`Codec`** runs ffmpeg for the rest.
- **`QrCode`**: a QR code of a text, drawn as SVG or as text for a
  terminal.

**Mail.** **`Mail\Mailer`** sends a **`Message`** through an account of
`conf/mailer.json`, by its driver: **`Drivers\Native`** (the framework's
own SMTP client), **`PHMail`** (PHPMailer, for attachments), **`ToFile`**
(`.eml` files). **`Template`** makes a message from its text and HTML
files, `%name%` filled.

### 5.7 Command line

- **`Shell\Console`**: reads the arguments of `php nyuRun`, finds the
  command, runs it.
- **`Shell\Command`**: the parent of every command, with its
  sub-commands (`subsTable()`), and **`Options`**, **`Printer`** (text, or
  JSON for a script), **`ShellStop`** (the exit code).
- **`Shell\Cmds\*`**: `deploy`, `config`, `tester`, `cleans`, `manage`,
  `device`, `bundle`, `appkey` (chapter 7).
- **`HealthChk`**: the checks of `deploy check`. **`ConfFile`** writes the
  configuration files, each in its own way; **`UserFile`** the users file;
  **`ActCaller`** calls an action of a run from the command line, with the
  roles of the preset.

### 5.8 Where the framework is extended

| To add | Write | Declared in |
|---|---|---|
| a credential check | `runs/Shared/<Name>Auth.php`, implementing `Access\Auth\Handler` | the `plugin` block of `access.json`, under `authby` |
| a carrier | `runs/Shared/<Name>Sess.php`, implementing `Access\Sess\Handler` | the `plugin` block, under `sessby` |
| a model shared by runs | `runs/Shared/<Name>Model.php` | nothing: found by name |
| a preset | `conf/presets/<name>.rps` | the entry point that names it |
| a mail template | `runs/<App>/mails/<language>/<kind>.txt` and `.html` | nothing: replaces the framework's |
| a command of the command line | `runs/<App>/Shell.php`, the class `Shell` extending `Shell\Command` | nothing: the command is the name of the run in lower case |

A plug-in is named with six characters, letters and digits, starting
with a letter. One that is listed but cannot be used is logged, and the
request is refused as misconfigured.

---

## 6. Configuration files

### 6.1 How the configuration is read

At each request the Loader reads every `.json` and `.ini` file of
`conf/` (or of the folder the preset names), and keeps each under the
name of its file in capitals: `system.ini` is `SYSTEM`, `access.json` is
`ACCESS`. With `CNF_CACH`, the first request of a preset writes what it
read in `temp/cache`, and the next ones read that file instead. The cache
is removed by every command that writes in `conf/`, by `deploy start`
and by `config cache`; after an edit by hand, `php nyuRun config cache`
removes it, and `deploy check` warns about a cache older than its files.
No secret goes into it: `.env` is read on each request. A file of `conf/` that is not a configuration must therefore not
end in `.json` or `.ini`: the examples end in `.dist`, the lists in
`.lst`.

A value written `env:NAME` is replaced by the value of `NAME` in `.env`
(or the file the preset names), else in the environment of the process.
Secrets are only ever written this way (2.3).

The files made from an example (`access.json`, `database.json`,
`mailer.json`, `users.lst`) and `.env` are local to each installation:
they are ignored by git and left out of bundles. `php nyuRun deploy
start` makes them from their examples, and `php nyuRun config` reads and
changes them, checking each value.

All names follow the same rule: the keys of one block share one length,
6 characters for the JSON files, 8 in the `XXX_YYYY` form for
`system.ini`.

### 6.2 `system.ini`

| Key | Value | Default |
|---|---|---|
| `ENV_NAME` | the environment: `DEV`, `TEST`, `PREV`, `PROD` | `DEV` |
| `LOG_MODE` | the levels written: `MINI` (errors, warnings, notices), `BASE` (and information), `FULL` (and debug, with the context) | `FULL` |
| `LOG_PATH` | the folder of the logs, from the root of the project | `temp/logs` |
| `LOG_SIZE` | the size of a log, in KB, before it is rotated; 0 for no limit | 1024 |
| `LOG_ROTA` | the rotation: `NUMBER`, `WEEKLY`, `MONTHS`, `UNIQUE` | `NUMBER` |
| `LOG_PREF` | the `date()` format at the start of each line | `Y-m-d H:i` |
| `ERR_LEVL` | the errors PHP reports, `E_*` names joined by `\|`, `&` and `~` | `E_ALL` |
| `ERR_SHOW` | whether errors are shown in the reply, `TRUE` or `FALSE`; never in `PROD` | |
| `LST_ROWS` | the rows a list reads when it is not told how many | 100 |
| `LNG_DFLT` | the language of the mails of a user who has none | `en` |
| `REQ_SIZE` | the largest body read, in KB; 0 for no limit | |
| `REQ_PART` | the most parts of a multipart body; 0 for no limit | |
| `CNF_CACH` | whether the preset, the configuration files and the routes are cached (`TRUE`, `FALSE`) | `FALSE`; `TRUE` in the stage and final bundles |
| `TBL_CACH` | whether the descriptions of the tables are cached too, with `CNF_CACH`; a query failing on a cached table removes the cache | `FALSE` |
| `SND_TOOL` | the full path of ffmpeg, empty without it | |
| `SND_TIME` | the seconds ffmpeg may run | 60 |
| `PRX_LIST` | the addresses or ranges of the trusted reverse proxies | empty |
| `PRX_HEAD` | the header in which they give the address of the client | |
| `PRX_SCHM` | the header in which they give its scheme | |

### 6.3 `.env`

`.env.example` lists the names the configuration examples use:

| Name | Holds |
|---|---|
| `NYU_JWT_SECRET` | the secret of HS256 tokens (32 characters at least); empty with RS256 |
| `NYU_API_KEY` | an API key of the `dfkeys` block |
| `NYU_TFA_KEY` | the key the 2FA secrets are encrypted with (32 characters at least) |
| `NYU_DB_PASS` | the password of the database |
| `NYU_MAIL_PASS` | the password of the mail account |
| `NYU_SND_TOOL` | the path of ffmpeg |
| `NYU_TEST_*` | the test servers of MySQL, PostgreSQL and MongoDB (chapter 13) |

A name can be added freely: the configuration only has to write it
`env:NAME`.

### 6.4 Presets

A preset (`conf/presets/<name>.rps`, JSON with comment lines) is a
profile of the framework, chosen by the entry point. Its blocks, and
their keys:

| Block | Keys | Sets |
|---|---|---|
| (top) | `label`, `about` | its name and what it is for |
| `setup` | `ftypes`, `fnames`, `folder`, `envfle` | the configuration files read: their types (`all`, `ini`, `json`), their names (`any` or a list), their folder, the file of the secrets |
| `auths` | `needed`, `sessby`, `sesscf`, `authby`, `authcf`, `openrt`, `signin`, `rolert`, `appkey` | the access check: whether one is needed, the carrier and the check with the blocks of `access.json` they read, the routes open to all, the sign-in route of a site, the roles of the routes, the block of the application keys |
| `input` | `naming`, `source`, `fields` | the sources of the inputs (`QUERY`) and the fields kept |
| `route` | `detect`, `mapper`, `folder`, `htroot` | the routing: `autopath` or `manually`, the names of the parts of a path, the folder of the route files, the folder of the pages and static files |
| `rnder` | `format`, `origns`, `headrs` | the reply: its format, the sites allowed to call, the headers sent |

A key a preset does not know stops it from loading. The framework brings
seven presets:

| Preset | For |
|---|---|
| `dfault` | the entry point that names none: open access, JSON |
| `manual` | an entry point that declares its own routes |
| `servce` | a service called by other programs: an API key in a header |
| `webapi` | a web API: Bearer tokens, the login open |
| `websit` | a site used from a browser: a session cookie, the users table, its own routes |
| `static` | a site of pages, files and redirections: open, no inputs |
| `templt` | not loaded: every setting of a preset, with what it does |

### 6.5 `access.json`

The blocks a preset names by `sesscf`, `authcf` and `appkey`, and the
settings of the access layer:

| Block | Keys | Sets |
|---|---|---|
| `login` | `authby`, `authcf`, `sessby`, `sesscf` | how the `Auth` run signs a user in, and what it issues |
| `plugin` | `authby`, `sessby` | the plug-in checks and carriers allowed (5.8) |
| `dfpass` | `htpasswd`, `unsecure` | the password file of `htpass` |
| `native` | `basnme`, `tblnme`, `idletm`, `maxtry`, `holdtm`, `minlen`, `tfakey`, `mailer`, `signup`, `codexp`, `lnkurl` | the native userbase: its database and table, the time after which a user is no longer online, the wrong passwords before the account is held and for how long, the shortest password, the key of the 2FA secrets, the access mails |
| `usrlst` | `usrfle`, `tfakey` | the users file and the key of its 2FA secrets |
| `device` | `tblnme`, `usrtbl` | the table of the devices and the one of their users |
| `limits` | `maxtry`, `holdtm`, `folder`, `regtop`, `regmax`, `regwrn` | the limit by address, and the bounds of the sign-ups |
| `dfkeys` | `header`, `keylst` | the header of the API keys, and the keys |
| `dftokn` | `secret`, `lifetm`, `algrtm`, `prvkey`, `pubkey` | the tokens: their algorithm, secret or keys, and life |
| `dfsess` | `cookie`, `lifetm`, `fulltm`, `samest`, `secure`, `folder` | the PHP sessions: the cookie, the time unused and in all |
| `dfcert` | `chkvar`, `usrvar`, `issvar`, `issuer` | the server values a client certificate is read from, and the issuer accepted |
| `apkeys` | `header`, `applst` | the keys of the applications calling for their users |

The blocks whose name starts with `df` are examples: a project can hold
several blocks of one kind under names of its own, each preset naming the
one it uses.

### 6.6 `database.json`

One database, or several by name, the first being the default:

| Key | Value |
|---|---|
| `type` | `mysql` (MariaDB included), `pgsql`, `sqlte`, `mongo` |
| `host`, `port` | the server; empty for SQLite |
| `base` | the database, or the file of an SQLite one |
| `user`, `pass` | the account; the password as `env:NAME` |
| `char` | the character set (`utf8`) |
| `auth` | the database holding the account, when it is another one |
| `wait` | the seconds the server is given to answer |

### 6.7 `mailer.json`

The mail accounts by name, the first being the default, each with the
keys `type` (`native`, `phmail`, `tofile`), `host`, `port`, `user`,
`pass`, `from`, `name`, `wait` (the seconds a server may take), `fold`
(the folder of a `tofile` account) and `copy` (addresses every mail is
sent again to).

### 6.8 Route files

The routes are declared in `conf/routes/*.rte` (or the folder of the
preset), JSON with comment lines, by their path:

```json
{
    "profiles/$id": {
        "allows": "GET, POST",
        "pointsTo": { "appToRun": "client", "doAction": "edit" }
    },
    "assets/*": {
        "target": { "dir": "assets" }
    }
}
```

- A part written `$name` captures a value: the first one is the parameter
  of the action, and all of them fill the placeholders of a page. A final
  `*` takes the rest of the path.
- `allows` lists the methods of the route (`GET` alone otherwise); a
  `target` can also hold one block per method.
- A target names one kind: an action (`appToRun`, `doAction`), a redirection
  (`url`, with its status in `cod`), a static file (`fle`) or folder
  (`dir`), or an HTML page (`pge`).

A key that nothing reads is logged as a warning. A route file holds no
access setting: the roles of the routes are in the preset.

### 6.9 Input definitions

The inputs of an action are declared in
`runs/<App>/input/<action>.json`:

```json
{ "fields": { "user!": "TextStr", "pass": "", "mail": "EmlAddr" } }
```

Each field is named with its format (one of `Tools\Variable`); a `!`
after the name makes it required, an empty format keeps it as sent. An
unknown format stops the request with a 500 (400 for a required field).

### 6.10 Other files

| File | Holds |
|---|---|
| `conf/users.lst` | the users file: `{ "users": [ ... ] }`, with the names of the users table |
| `conf/bundle.lst` | the bundle modes of the project (chapter 7) |
| `conf/.htpasswd` | the password file of `htpass` |
| `conf/keys/` | the keys of the tokens (`.key`, `.pub`), made by `config secrt jtoken` |
| `conf/schema/` | the delivered tables, one file per table and database type, and their migrations |
| `conf/mails/<language>/` | the templates of the access mails |

---

## 7. Command line

### 7.1 Usage

```
php nyuRun <command> <sub-command> [arguments] [--options]
```

Every command and sub-command is a word of five or six letters. A command
without a sub-command, or with `--usage`, lists what it does.

| Option | Effect |
|---|---|
| `--noask` | no question is asked: the default answers are taken |
| `--agree` | what removes or replaces is confirmed beforehand; with `--noask` alone, it is declined |
| `--shape=json` | the result as one JSON object, for a script to read |
| `--quiet` | no remark, only the result |
| `--usage` | what the command does |
| `--versn` | the version of the framework |

| Exit code | Meaning |
|---|---|
| 0 | done |
| 1 | failed |
| 2 | wrong usage: an unknown word, a missing argument |
| 3 | refused or declined |
| 4 | done, and problems found (`deploy check`) |

What a command changes is traced on the `shell` log channel, signed
`cli:<system user>`; what it changes in the users, on `access`, as the
same change made over HTTP.

### 7.2 `deploy`: install and check

| Sub-command | Does |
|---|---|
| `start` | the whole installation: the server checked, the local files made, the secrets made, the users created, the health check; it asks what it needs, with a default (`--noask` takes them all) |
| `servr` | checks the server: PHP, its extensions, the folders written |
| `files` | makes `.env`, `access.json` and `database.json` from their examples |
| `users` | creates the users table and the basic users, or the users file; the devices table with a `device` block |
| `check` | the health check: each row `fine`, `warn` or `fail`; exit code 4 on a `fail` |
| `store <native\|usrlst>` | moves the users to the other store, the login with them (`--drops`, `--agree`) |

Nothing that exists is replaced: a file, a secret, a table, a store that
holds users.

### 7.3 `config`: read and change the configuration

| Sub-command | Does |
|---|---|
| `shows <system\|access\|dbases\|preset> [path]` | shows a file, one of its blocks or keys; secrets masked |
| `write <system\|access\|dbases\|preset> <path> <value>` | changes a value (`KEY`, `block.key`, `preset.block.key`), checked by its rule |
| `entry [preset]` | the preset `www/index.php` starts with, shown or changed |
| `secrt <jtoken\|apikey\|tfakey\|htpass>` | makes a secret, the RS256 key pair, or a password of the password file, and writes it where the configuration reads it |
| `route <preset>` | the routes of a preset, and who they are served to |
| `cache` | removes the config cache: the next request of each preset reads its files again |

A file is written with its comments and layout kept, the former one
copied to `temp/backup`; a change that leaves it unusable is undone.

### 7.4 `manage`: users and actions

The users of the store the login uses (`--store`, `--block` for another):

| Sub-command | Does |
|---|---|
| `lists`, `views` | lists the users (by role, by status), shows one |
| `enrol <user> <role>` | creates a pending user, and shows its activation code once |
| `actvt <user> <code>` | activates a user with its code, and gives it a password |
| `locks`, `unlck` | locks a user, unlocks it |
| `roles <user> <role>` | gives another role |
| `remov <user>` | removes a user |
| `reset <user>` | a new activation code for a lost password |
| `passw <user>` | a new password, made and shown once (`--typed` to type it) |
| `token <user>` | a personal API token, shown once (`--clear` takes it away) |
| `twofa <user>` | a 2FA secret, with the QR code of the authenticator (`--clear` takes it away) |
| `email <user> [address]` | sets or removes the address |
| `langs <user> [language]` | shows, sets or removes the language |
| `prove [user]` | lists the sign-ups to review, or approves one |
| `calls <app/action> [field=value]` | calls an action of a run, for automated tasks, with the roles of a preset (`--prset`, `--urole`) |

### 7.5 `device`, `appkey`: machines and applications

| Command | Sub-commands |
|---|---|
| `device` | `token <name> <user> <role>` gives a device a token, shown once; `lists [user]`; `revok <name> <user>` |
| `appkey` | `makes <app>` with `--routs`, `--pubky`, `--urole`, gives a key shown once; `lists`; `route <app>` replaces its routes; `revok <app>` |

Only the hash of a token or a key is stored. A revoked device stays in
the list with the time of its revocation; a revoked key is refused from
the next request.

### 7.6 `bundle`: a copy for each environment

| Sub-command | Does |
|---|---|
| `local`, `stage`, `final` | builds the copy for development, a server before production, production |
| `<mode>` | builds a mode of the project |
| `packs` | lists the modes, those of the framework and those of the project |
| `mkpak <mode>` | declares a mode of the project in `conf/bundle.lst`, or changes it |
| `rmpak <mode>` | removes one |

A bundle is written in `dist/<mode>`, or in the empty folder `--place`
names; `--archv` adds its `.tar.gz`, `--plans` lists what would be copied.
Only what `Bundle::KEEPLIST` names is copied, without what `DROPLIST`
names: no secret, no local setting, no private key. The settings of the
mode are written in the `system.ini` of the copy. A copy for stage or
production gets a complete, authoritative class map, made in it by
Composer (`--cmpsr` names it, else `composer.phar` at the root or the one
on the path); without Composer, the copy keeps the project's autoloader,
and says so.

### 7.7 `cleans`: temporary files and logs

| Sub-command | Does |
|---|---|
| `purge <cache\|logs\|limits\|backup\|all>` | removes the files of those folders of `temp` (`--older=<days>`) |
| `trace <channel>` | the last lines of a log (`--lasts`, `--watch` to follow it) |
| `holds` | the addresses the limit by address counts or holds |
| `frees <address\|all>` | releases an address |

Nothing is removed outside the `temp` folder of the project.

### 7.8 `tester`: the test suite

`php nyuRun tester` runs the `unit`, `audit` and `data` groups; a group
or a word runs those scripts, `all` every group. The extensions the
tests need and PHP does not load are given for the run (`--addon`).
Chapter 13 tells the suite.

### 7.9 Commands of a run

A run brings its own command with `runs/<App>/Shell.php`, declaring the
class `Shell` of its namespace, which extends `Shell\Command`: the
command is the name of the run in lower case, and each sub-command a
method `call<Name>` listed in its `subsTable()`.

---

## 8. Runs construction (applications)

### 8.1 What a run is made of

A run is a folder of `runs/`, named with letters and digits, its first
letter in capitals. Only `Actions.php` is required:

```
runs/Notes/
  Actions.php                 the actions                  namespace nyu\Runs\Notes
  mods/<Name>Model.php        the models                   namespace nyu\Runs\Notes\mods
  input/<action>.json         the inputs of each action
  pages/<name>.html           the pages it replies with
  mails/<language>/<kind>.*   its own mail templates
  Shell.php                   its own command of php nyuRun
```

The run is reached by its name in lower case (`notes/view/12`), or by
the routes declared for it.

### 8.2 The actions

`Actions.php` declares the class `Actions` of the namespace of the run,
extending `nyu\App\Actionset`. Each of its public methods is an action,
reached by its name; the methods of the framework, the static ones and
the protected ones are not.

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltActn = 'index';

	/**
	 * Lists the notes, those whose name holds the text asked for.
	 */
	public function index(): Void {
		$noteLst = $this->modStack->readModel('listing');
		$nameTxt = strval( $this->inputReg->getDefVal('name', '') );
		if( $nameTxt!='' ){
			$noteLst->lstSearch( Array('noteName~'=>'*'.$nameTxt.'*') );
		}
		$this->statCode = 200;
		$this->asOutput = $noteLst->readItems();
	}

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

- **`$dfltActn`** names the action run when the path names none; without
  it, the first action declared runs.
- **The parameter** of an action is the part of the path the `mapper`
  names `$argument` (the third in the presets of the framework:
  `notes/view/12`), or the first value a declared route captures. An
  action that needs one declares it; one that takes none ignores it.
- An action follows the conventions of the project (chapter 12), except
  its name, which is the word of the path.

### 8.3 What an action is given

| Property | Holds |
|---|---|
| `$this->inputReg` | the inputs, defined by `input/<action>.json`: `getDefVal('name', default)`, `readLines()` |
| `$this->modStack` | the models of the run: `readModel('name')` |
| `$this->axessReg` | the access result: `loggedIn`, `userName`, `userRole`, `identity` (what the store tells of the user), `appsName` for the call of an application |
| `$this->httpRqst` | the request: `usedMethd()`, `getHeader()`, `getCookie()`, `listFiles()`, `getInFile()`, `getSrvVar()`, `getClient()` |
| `$this->httpResp` | the response, for its headers |
| `$this->routrObj` | the router, for the links to the declared routes |

The inputs are the only values a client sends that an action should use
as data. The server values and what the client tells of itself are read
through the request, and never decide an access (2.8).

### 8.4 Replying

An action sets three properties:

- **`statCode`**: the status of the reply (200 when it sets none);
- **`statMssg`**: the message of an error status;
- **`asOutput`**: the data of the reply, written in the format of the
  preset: an array, a record, a list, a text, a number.

The helpers of the actionset:

| Helper | Does |
|---|---|
| `endAction($code, $message, $output)` | ends the action at once, from itself or a helper, with that status |
| `replyFile($path, $type)` | replies with a file of the server, in parts when `Range` asks |
| `replyPage($name, $values)` | replies with `pages/<name>.html`, its `%name%` placeholders filled and encoded |
| `replyMove($address, $code)` | redirects to a path of the site or an `http(s)` address |
| `routeLink($route, $values, $query)` | the link to a declared route |
| `useFormat($format)` | another format for this reply: `json`, `yaml`, `xmlx`, `tabl`, `html`, `flux` |
| `appLogger()` | the log of the run, on its own channel |
| `needsRole($role)` | ends the action with a 404 unless the user holds the role |
| `holdsRole($role)` | tells whether the user holds the role |

An exception that the action does not catch is a 500, logged with its
trace (4.7); a client is told nothing more in production.

### 8.5 Models

A model is a record or a list of a table, declared once and given by
name. `mods/SingleModel.php` declares the class `Single` of
`nyu\Runs\Notes\mods`, read as `readModel('single')`:

```php
<?php

namespace nyu\Runs\Notes\mods;

use nyu\Data\DBRec;

class Single extends DBRec {

	/**
	 * Binds the record to the notes table, with its tags.
	 */
	public function __construct(){
		parent::__construct( 'notes', Array('tags') );
	}
}
```

- **A record** (`DBRec`) names its table, its child tables and its parent
  tables. It is loaded by its key (`loadRecrd()`) or found by its fields
  (`findRecrd()`), its relations loaded with it; it is filled
  (`bindArray()`), saved (`saveRecrd()`) and removed (`remvRecrd()`).
- **A list** (`DBLst`) names its table. It is filtered (`lstSearch()`),
  its fields chosen (`fieldScop()`), grouped, sorted (`listOrder()`),
  limited (`listLimit()`), joined (`tableJoin()`), then read
  (`readItems()`).
- A model can name another database than the default one.
- The tables follow the naming of chapter 10: columns of 8 characters,
  `id_<table>` for the identifier, `<table>_id` for a link.

The models of `runs/Shared` are given to every run that has none of that
name. A model is made the first time an action asks for it, then kept for
the request: a run pays only for the models its action uses.

### 8.6 Inputs

Each action declares its inputs in `input/<action>.json` (6.9). An action
without a definition receives the inputs as the preset built them: declare
every action, even one that takes none (`{ "fields": {} }`).

### 8.7 The runs delivered

| Run | Holds |
|---|---|
| `Auth` | signing in and out, activation, sign-ups, forgotten passwords, the change of a password and of a language: `login`, `logout`, `activate`, `signup`, `forgot`, `recovr`, `password`, `langs` |
| `Users` | the management of the users by a manager: `index`, `view`, `create`, `approve`, `email`, `langs`, `lock`, `unlock`, `role`, `remove`, `reset` |
| `Shows` | an example: a list of shows filtered, sorted and limited, and one show with its events and items |
| `Shared` | empty: the models and plug-ins shared by the runs |

`Auth` and `Users` work on the store the `login` block names; every
action of `Users` asks for the `manage` role itself.

---

## 9. Security

What the framework does to protect a project, in brief. The settings are
told in [`HOSTING.md`](../HOSTING.md), the advice in chapter 2, the order
of the checks in chapter 4.

### 9.1 Principles

- **Fail closed.** A configuration that cannot be applied refuses every
  request with a 500 (`Access misconfigured`); it never falls back to a
  weaker check.
- **Tell the client nothing it should not know.** Whatever it cannot
  reach is the same 404; a wrong name and a wrong password are the same
  refusal; the reply to a sign-up or a forgotten password is the same
  whoever asks; production shows no detail of an error.
- **Trust only what the server reads.** The only inputs are those an
  action declares; what the client tells of itself is a hint, never a
  reason to grant access; the address of a client is read through the
  proxies the configuration trusts, and no others.
- **Keep secrets out of the files.** Secrets live in `.env`; only hashes
  of keys, tokens and codes are stored; a bundle carries none.
- **Split the duties.** The framework handles the request from the moment
  PHP receives it; the web server, TLS, the firewall and `php.ini` belong
  to the host ([`HOSTING.md` §1](../HOSTING.md#1-purpose-and-split-of-duties)).

### 9.2 Requests and inputs

| Risk | What the framework does | Told in |
|---|---|---|
| a body too large to hold | refused 413 past `REQ_SIZE` or `REQ_PART`, before it is read | [`HOSTING.md` §11](../HOSTING.md#11-request-sizes) |
| values the action did not expect | only the fields of `input/<action>.json` are kept, each in its format | 6.9 |
| a forged client address | forwarding headers read only from the proxies of `PRX_LIST` | [`HOSTING.md` §3](../HOSTING.md#3-reverse-proxy) |
| a password in a link, kept in logs and histories | the `Auth` actions refuse a password sent in the query string (400) | |
| a filter value used as a pattern | compared as it is; a match is asked by the name of the field (`field~`) | 2.7 |
| SQL injection | the stores send values as parameters, and accept only names of letters, digits and underscores | 10 |

### 9.3 Routes and replies

| Risk | What the framework does | Told in |
|---|---|---|
| mapping what exists | one 404 `Not Found` for an unknown application or action, a closed route, a missing file, a role not held | 4.5 |
| leaving a folder | a file asked for in a folder never leaves it; only the extensions of `Response::TYPELIST` are served | [`HOSTING.md` §13](../HOSTING.md#13-routing-notes) |
| script in a page | the placeholders of pages and mails are HTML-encoded | 8.4 |
| a redirection elsewhere | a sign-in route must be a path of the site; `replyMove()` takes a path or an `http(s)` address only | |
| content sniffing | `X-Content-Type-Options: nosniff` on every reply | 4.6 |
| calls from other sites | `Access-Control-Allow-Origin` names only an origin `origns` lists | [`HOSTING.md` §10](../HOSTING.md#10-other-sites-calling-in) |

### 9.4 Credentials

- **Passwords** are hashed with bcrypt. An unknown name still costs one
  hash, so the time of a refusal does not tell which names exist. A
  password file accepts weak formats only when told to
  ([`HOSTING.md` §6](../HOSTING.md#6-password-files)).
- **Accounts that are not active** are refused as a wrong password would
  be; the reason is in the log only.
- **Tokens** carry an end (`exp`) and a user, or are refused; RS256 keys
  under 2048 bits are refused, and a token names its key (`kid`)
  ([`HOSTING.md` §8](../HOSTING.md#8-tokens)).
- **Sessions** end after `lifetm` unused or `fulltm` in all; a login
  gets a new session id and closes the one it came with; the cookie is
  `HttpOnly`, `Secure` and `SameSite` as set
  ([`HOSTING.md` §9](../HOSTING.md#9-php-sessions)).
- **Two-factor codes** are accepted once each; their secrets are stored
  encrypted with `tfakey` ([`HOSTING.md` §7](../HOSTING.md#7-two-factor-codes)).
- **Keys, personal tokens, device tokens, application keys, activation
  and reset codes** are shown once and stored as hashes, and compared by
  their hash.
- **Codes sent by mail** last `codexp` minutes, and a reset keeps the
  password until its code is used
  ([`HOSTING.md` §15](../HOSTING.md#15-access-mails)).
- **Mail** is sent encrypted, the certificate of the server verified.

### 9.5 Attempts

The limit by address (`limits`) counts the wrong passwords, keys and
codes, the forged tokens, every sign-up and every reset asked: past
`maxtry`, the address is held for `holdtm` seconds and answered 429. A
token that ended, a missing credential or a role not held are not counted.
The native userbase also holds an account after `maxtry` wrong
passwords. Sign-ups are bounded per client and in all
([`HOSTING.md` §5](../HOSTING.md#5-limit-by-address-and-account-holds)).

### 9.6 Errors and logs

- In production nothing of an error is shown, whatever `ERR_SHOW` says.
- A trace is logged without the arguments of its calls, so that no
  password reaches a log.
- Names given by a client are written in the logs as JSON texts, so that
  no line can be forged.
- The users holding `system` or `manage` are traced at each access; every
  refusal is traced with its reason
  ([`HOSTING.md` §12](../HOSTING.md#12-errors-and-logs)).

### 9.7 Shipping

A bundle copies only what `Bundle::KEEPLIST` names: no `.env`, no local
configuration, no private key, no test (7.6). `php nyuRun deploy check`
fails on what would expose a server or stop it (a secret or a 2FA key too
short, a proxy list open to all, an access or a preset that cannot be
applied, keys of tokens that cannot be used), and warns on what deserves a
look (errors shown on a preview server, a PHP release without its latest
security fixes, a password file accepting weak hashes, origins open to
all).

### 9.8 Left to the host, or to later

- The web server, TLS and the firewall, and `php.ini`
  ([`HOSTING.md`](../HOSTING.md)).
- The forwarded host (`X-Forwarded-Host`) is not read.
- Client certificates whose TLS a proxy ends, certificate pinning, the
  issuer and the audience of tokens, and limits by route are left for
  version 1.1 (1.5).

---

## 10. Data layer

A run reaches its data through models, records and lists (8.5), never
through queries. This chapter tells what lies under them.

```
DBRec, DBLst            the models of the runs
   |
DBStore\Store           what a record and a list need: find, insert, update, delete, transactions
   |-- SqlStore         the SQL types, through their handler
   |      |-- DBHndlr\MySQL, PgSQL, SQLite (PDO)
   |-- MongoStore       MongoDB, through its driver
   |
DBCon                   one connection per configured database
```

### 10.1 Connections

The databases are those of `conf/database.json` (6.6): one, or several by
name, the first being the default. A connection is opened the first time
it is used, and kept for the request: `DBCon::linkNamed('name')` gives
it, `''` for the default database. A model names another database than
the default one by its connection.

Each SQL type has its handler, on PDO, which writes the conditions in its
own dialect: MySQL (MariaDB included), PostgreSQL and SQLite. The SQLite
handler turns the foreign keys on for each connection, as the other
databases have them. MongoDB is a store of its own (10.8).

### 10.2 Naming

The tables follow one naming, which the records and lists rely on to find
their relations without being told:

| Element | Rule | Example |
|---|---|---|
| table | a plural name | `shows`, `events` |
| column | 8 characters | `userName`, `madeDate` |
| identifier | `id_` and the singular of the table | `id_show` |
| link to another table (one to many) | the singular of that table and `_id` | `show_id` |
| link to a parent of the same table | `parent_id` | |

A relation can always name its fields when a table does not follow the
naming.

### 10.3 Records

A record (`DBRec`) is bound to its table when it is created: its key and
fields are read from the store, once per table and request, or from the
config cache with `TBL_CACH`.

- **Loading**: `loadRecrd($key)` by its key, `findRecrd($filter)` the
  first that matches a filter (10.5). Both give the fields of the record,
  or `false`, and load its declared relations.
- **Relations**: the child tables a record declares are loaded with it,
  each under the name of its table, first letter in capitals
  (`Events`); a parent, under its singular name (`Show`). A child can be
  declared with its sort order.
- **Saving**: `bindArray($values)` takes the values of the fields the
  table holds and leaves the others; `saveRecrd()` updates the row of the
  key, or inserts a new one, the key given or made by the database. The
  key is never updated. It gives back the key, or `false`: as the
  database gave it for an update, as a number when the database numbered
  it, as the application bound it otherwise.
- **Removing**: `remvRecrd($children)` removes the record and the rows of
  the child tables named in the call, in one transaction, and nothing
  else. The removal is traced on `runner`, with the user and the address
  of the request. A database without transactions removes a record alone,
  and refuses a removal that names children.

### 10.4 Lists

A list (`DBLst`) is bound to its table, set, then read:

| Method | Sets |
|---|---|
| `lstSearch($filter)` | the filter (10.5), in place of the one before |
| `fieldScop('a, b, count(*) as n')` | the fields read, and functions on them, with aliases |
| `grpFields($fields)` | the fields the rows are grouped by |
| `listOrder($fields)` | the order; a field written `^name` is sorted descending |
| `listLimit($count)` | how many rows, from a count or `"count,offset"`; `LST_ROWS` when the count is not a number |
| `tableJoin([table, field, field])` | one table joined (`LEFT JOIN`), SQL only |
| `readItems()` | reads the rows; `flatItems('field')` reads one field of each |
| `childList($table)` | attaches to each row read its rows in a child table |

### 10.5 Filters

A filter is an array of field names and values. It is read the same way
for every database (`DBStore\Filter`), and each store writes it in its
own language:

| Written | Selects the rows where the field |
|---|---|
| `'field'=>value` or `'field='=>value` | equals the value |
| `'field!'=>value` | differs from it |
| `'field<'`, `'field<='`, `'field>'`, `'field>='` | is under or above it |
| `'field'=>[a, b]`, `'field!'=>[a, b]` | is one of the values, or none of them |
| `'field~'=>'*text*'`, `'field!~'=>'*text*'` | matches the text, or does not; `*` is any text |
| `'field?'=>false`, `'field?'=>true` | holds no value, or holds one |

A row meets every condition of the filter. A value is compared as it is:
no character of it is a wildcard, so that a value taken from a request
cannot widen a search; only a field named with `~` matches, and only `*`
is a wildcard there. A `null` value matches nothing, so that an empty
input cannot select the rows left unset. A field name is letters, digits
and underscores, with an optional table (`shows.title`); any other is
refused.

### 10.6 Transactions

The store of a database tells whether it has transactions
(`canTrnsct()`), and opens (`opnTrnsct()`), commits (`setTrnsct()`) or
cancels (`remTrnsct()`) one. An action reaches them through the
connection:

```php
$dbStore = DBCon::linkNamed()->dataStore();
$dbStore->opnTrnsct();
// records saved and removed
$dbStore->setTrnsct();
```

The SQL types have transactions; MongoDB, as nyuRuns uses it, has none.

### 10.7 Binary values

A picture, a sound or any file is stored in a field as a binary value:

- it is written from a flux (`Data\Fluxd`) given as the value of the
  field: a file of the server, the body of the request, bytes in memory;
  it is read piece by piece, never held whole;
- it is read back as a flux, only when the field is named among the
  fields asked for: a reading that names none leaves it out;
- an action sends it as it is with the `flux` format of the reply.

### 10.8 MongoDB

MongoDB is reached through the `mongodb` extension of PHP, as the store
`MongoStore`. A table is a collection, a row a document, and the key of
every table is the `_id` MongoDB gives. What differs from the SQL types:

- a collection has no fixed list of fields;
- values are compared with their type: the text `'2'` is not the number
  `2`;
- functions, joins and groups are refused;
- there are no transactions (they would need a replica set), so a record
  is removed alone: naming its children in the removal is refused.

### 10.9 Delivered tables

`conf/schema/` holds the tables the framework uses, one file per table
and database type: `users` and `devices`, in `.mysql.sql` (MariaDB
included), `.pgsql.sql` and `.sqlte.sql`. `php nyuRun deploy users`
creates them; a table that exists is left as it is. The files
`migrate-<table>-<version>.<type>.sql` move a table made by an earlier
version: they are applied by hand, after a backup.

### 10.10 Identifiers that travel

A record copied to another database or another application keeps its
identity with a UUID of version 7 (`Tools\UniqueId`, RFC 9562): it starts
with the time it was made, so that the identifiers sort in that order,
and ends with random bits. In 1.0 the framework makes them, and nothing
gives them yet to a record by itself: a table that needs one holds it in a
field of its own, beside its key, filled by the action that creates the
record.

---

## 11. Directory structure

### 11.1 The project

```
nyuRuns/
  www/                    the only folder the web server serves
    index.php             the entry point: starts the Runner with its preset
  src/                    the framework, namespace nyu\ (chapter 5)
  runs/                   the applications (chapter 8)
    Auth/  Users/  Shows/
    Shared/               models and plug-ins shared by the runs
  conf/                   the configuration (chapter 6)
    presets/              the presets (.rps)
    routes/               the declared routes (.rte), a folder per preset that has its own
    schema/               the delivered tables and their migrations (.sql)
    mails/<language>/     the templates of the access mails
    keys/                 the keys of the tokens (local)
  pages/                  the pages and static files of the sites (route.htroot)
  media/                  media files of the project, kept out of git
  temp/                   what the framework writes (local)
  test/                   the test suite (chapter 13)
  vendor/                 the libraries, installed by Composer (local)
  docs/                   the documentation; docs/site, its pages and the code reference (made, not tracked)
  nyuRun                  the command line: php nyuRun <command>
  composer.json, composer.lock
  .env.example            the names of the secrets
  README.md, HOSTING.md, CONTRIBUTING.md, LICENSE, NOTICE
```

The web server serves `www` and nothing above it: the configuration, the
code, the logs and the tests are never reachable over HTTP. A site's pages
and files are served by the framework from `pages/` (or the folder its
preset names), only through the routes declared for them.

### 11.2 Tracked, local and made

| Kind | Where | In git | In a bundle |
|---|---|---|---|
| the framework and its runs | `src/`, `runs/`, `www/`, `nyuRun` | yes | yes |
| the configuration examples and the presets, routes, schema, templates | `conf/` | yes | yes |
| the pages of the sites | `pages/` | yes | yes |
| the local configuration | `.env`, `conf/access.json`, `database.json`, `mailer.json`, `users.lst`, `.htpasswd` | no | no |
| the keys of the tokens | `conf/keys/` | no | the public key only |
| the modes of the project's bundles | `conf/bundle.lst` | yes | no |
| the libraries | `vendor/` | no | yes |
| what the framework writes | `temp/` | no | no |
| the tests, the documents | `test/`, `docs/` | yes | no |
| the documentation site and the code reference | `docs/site/`, made by `.phpdoc/build.ps1` | no | no |
| the bundles | `dist/` | no | |

A bundle copies only the paths of `Bundle::KEEPLIST` (`src`, `runs`,
`www`, `pages`, `conf`, `vendor`, the Composer files, `nyuRun`,
`LICENSE`, `NOTICE`), without those of `DROPLIST` (the local
configuration, the private keys, `bundle.lst`), and with the paths its
mode adds.

### 11.3 `temp/`

| Folder | Holds | Removed by |
|---|---|---|
| `logs/` | the logs, one file per channel (`LOG_PATH`) | `cleans purge logs` |
| `limits/` | the counts of the limit by address (`limits.folder`) | `cleans purge limits`, `cleans frees` |
| `backup/` | the configuration files as they were before `config write` | `cleans purge backup` |
| `cache/` | the config cache, one file per preset (`CNF_CACH`) | `cleans purge cache`, `config cache`, any command that writes in `conf/` |
| `mails/` | the mails of a `tofile` account | by hand |

Only the user the web server runs as needs to write there (3.7).

### 11.4 A run

```
runs/<App>/
  Actions.php             the actions, class Actions of nyu\Runs\<App>
  mods/<Name>Model.php    the models, class <Name> of nyu\Runs\<App>\mods
  input/<action>.json     the inputs of each action
  pages/<name>.html       the pages it replies with
  mails/<language>/       its own mail templates
  Shell.php               its own command
```

### 11.5 The tests

```
test/
  run.php                 runs the suite, or named groups
  unit/  audit/  data/  http/   the groups (chapter 13)
  lib/                    the helpers of the tests
  fixtures/               the configurations, routes, pages, runs and files the tests use
```

---

## 12. Coding conventions

nyuRuns is written to one set of conventions, in the framework and in its
runs alike. A contribution follows them; the `audit` group of the tests
checks most of them (12.5).

### 12.1 Names of fixed length

Every name is `camelCase` and has the length of its kind, so that a name
tells at a glance what it is and the code lines up. Classes are the
exception: `PascalCase`, of any length.

| Element | Length | Example |
|---|---|---|
| method | 9 | `overLoads()`, `readModel()` |
| parameter | 10 | `$addedArray`, `$runAppName` |
| property | 8 | `$fieldSet`, `$httpRqst` |
| local variable | 7 | `$baseVar`, `$noteLst` |
| loop variable | 6 or 3 | `$incVar`, `$key` |
| constant | 8 | `KEYSLIST`, `MAXBYTES` |

Exempt from the lengths:

- the actions of a run (`index`, `view`, `login`): their name is the word
  of the path;
- the PSR-3 methods of `Tools\Logger` (`error`, `warning`, `notice`,
  `info`, `debug`);
- the variable of a `catch`, `$e`.

To fit a length, a name stays close to its word: vowels are dropped
(`Headr`, `Recrd`, `Mssg`), or a prefix such as `get` gives a whole word.
A name already chosen for one concept is reused for it everywhere
(`$httpRqst` is the request in every class).

The same rule holds beyond the code:

| Element | Rule | Example |
|---|---|---|
| keys of a configuration block | one length per block: 8 in the JSON files and in a route target | `sessWith`, `appToRun` |
| keys of `system.ini` | 8, in the form `XXX_YYYY` | `LOG_MODE` |
| codes made at run time | one length per list | `member`, `system`, `manage` |
| columns of a table | 8 | `userName` |
| commands and sub-commands of `php nyuRun` | 6 and 5 | `deploy start`, `cleans purge` |
| formats of `Tools\Variable` | 7, so that each `to…()` method is 9 | `TextStr`, `toTextStr()` |

### 12.2 Types

- Every parameter and every return value is typed, the types written
  with a capital: `String`, `Array`, `Bool`, `Int`, `Float`, `Mixed`,
  `Void`, `Never`.
- A value of several types is given its precise union (`Array|Bool`,
  `?String`); `Mixed` is kept for what can really be anything.
- A parameter whose default is `null` is declared nullable
  (`?String $userName=null`).
- `declare(strict_types=1)` is not used.

### 12.3 Documentation

- Every public method has a docblock: what it does, then `@param` for
  each parameter, `@return`, and `@throws` for what it lets out.
- A class opens with a docblock telling what it is and how it is used.
- A comment tells why, not what; it is written in English, as the names.
- The code reference is made from the docblocks by phpDocumentor
  (`phpdoc.dist.xml`), in `docs/site/reference`. `pwsh .phpdoc/build.ps1`
  makes it with the pages of these documents, written by the framework's
  own Markdown parser and HTML writer (`.phpdoc/site`): one site in
  `docs/site`, which git does not track.

### 12.4 Errors

- An error of the framework is a `RuntimeException`, or a class that
  extends it (`StopRun`, `ShellStop`, `ParseError`, `WriteError`,
  `MailFailure`, `QrCodeError`).
- A message tells what went wrong in words a developer reads in a log;
  it never holds a secret, and names given by a client are written as
  JSON texts.
- What a client may be told is decided by the status, not by the
  message of an exception (4.7).

### 12.5 The audit

`php test/run.php audit` reads `src/` and `runs/` with PHP's tokenizer
and fails on:

- a file that does not parse;
- a class that is not `PascalCase`;
- a method, parameter, property, local or loop variable that does not
  have its length;
- a public method without a docblock;
- a parameter or a return value without a type.

`php test/audit/ConventionTest.php --list` prints every finding, those
accepted included. The lengths of configuration keys, columns and codes
are not audited: they are checked when a change is reviewed.

### 12.6 Contributions

A contribution is signed off under the Developer Certificate of Origin
(`git commit -s`), brings its tests, and passes the suite and the audit,
as [`CONTRIBUTING.md`](../CONTRIBUTING.md) tells. A new or renamed name is proposed before
it is written, since it changes how the code reads.

---

## 13. Testing

The suite is made of self-checking PHP scripts, with no dependency beyond
PHP and its extensions: no test framework to install. It holds about
4 500 checks. [`test/README.md`](../test/README.md) tells the details.

### 13.1 Running it

```
php test/run.php                 every group
php test/run.php unit audit      the named groups
php test/run.php Variable        the scripts whose name holds the word
php nyuRun tester                unit, audit and data, from the command line
```

Each `*Test.php` runs in its own PHP process, and can be run alone
(`php test/unit/VariableTest.php`). The runner prints each script, then
the total of the checks passed, failed and skipped, and exits with a
failure code when a check fails.

### 13.2 The groups

| Group | Scripts | Checks |
|---|---|---|
| `unit` | 78 | each part on its own: formats, request, router, access, carriers and checks, tokens, limits, logs, response, error handler, documents, pictures, sounds, mail, the command line |
| `data` | 20 | the databases: handlers, stores, filters, records, lists, the userbase, the devices, the moves between stores, the runs that use them; on temporary SQLite databases, and on real servers when they are configured |
| `http` | 1 | whole requests through PHP's built-in web server: statuses, headers, bodies, routes, access, pages, files |
| `audit` | 1 | the coding conventions across `src/` and `runs/` (12.5) |

### 13.3 What the tests need

- **PHP and its extensions.** The `data` and `http` groups need
  `pdo_sqlite`, and skip themselves without it; the 2FA checks need
  `openssl`; pictures, sounds and documents skip what their extension
  (`gd`, `imagick`, `zip`) or ffmpeg does not allow. A command-line PHP
  without a `php.ini` is given its extensions through `PHPRC`, pointing
  at a folder whose `php.ini` lists them: the runner and the `http` test
  start other PHP processes, which receive no `-d` option.
- **Real database servers, optionally.** `MySQLTest`, `PgSQLTest` and
  `MongoDBTest` run the same checks on real servers, from the
  `NYU_TEST_*` names of `.env`. Each works in a database of its own whose
  name holds `test` (`nyuruns_test`), refuses any other, and skips itself
  when its server is not configured or does not answer.
- **Nothing outside the project.** The tests write in the system's
  temporary folder or in their own folders; the `http` group writes in
  the project's `temp/logs`. A test that counts wrong credentials sets its
  own limits, so that the project's `temp/limits` is left alone.

### 13.4 Writing a test

```php
<?php
require __DIR__.'/../lib/harness.php';

checkSame('label', $actual, $expected);          // strict comparison
checkTrue('label', $condition);
checkLike('label', $text, '/pattern/');
checkThrw('label', function(){ ... }, 'RuntimeException');

endChecks();
```

- A script lies in `test/<group>/<Name>Test.php`, and follows the
  conventions of chapter 12.
- A notice or a warning raised by the code tested fails the script,
  unless the test collects it (`takeNotes()`).
- `skipCheck()` tells why a check cannot run here; `inTempDir()` gives a
  path in the temporary folder; `fakeReqst()` and `fakeServr()` build a
  request without a web server.
- The fixtures (configurations, routes, pages, a test run named `Probe`,
  a users file, documents) lie in `test/fixtures`.
- A test that reads the logs plugs its own folder
  (`Logger::plugSetup()`), and unplugs it at its end.

### 13.5 Before a release

A version is closed by a full pass of the suite: every group, the `http`
one included, on the lowest PHP release supported (8.2) and the latest,
with the SQL servers the version supports (chapter 3) and MongoDB.

---
