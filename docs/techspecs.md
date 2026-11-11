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
back end of an application, from the HTTP request it receives to the reply
it sends.

The idea is that developers write what their application does, and leave
the handling of the request to the framework. For each request, nyuRuns
finds the route, checks who's asking and what they're allowed to do, keeps
only the inputs the action declares (each in its format), calls the
action, and writes the reply in the format asked for, errors included.
Around that, it brings what most services need: databases through models,
documents read and written in many formats, images, sounds, mail, logs,
and a command line to install, configure, test and ship a project.

A few principles shape it:

- **MDD** (model-driven development): an application works through models
  of its data, records and lists, rather than through queries.
- **KISS** (keep it simple): nothing is loaded until it's needed, and a
  first install needs no database.
- **REST**: resources are reached by their path, the method says what's
  done, and the status says how it went.
- **CRUDL**: create, read, update, delete and list, the operations every
  data source gets.

### 1.2 Who this document is for

It's written for developers: those who build applications on nyuRuns, and
those who work on nyuRuns itself. It describes how the framework is built:
its components, the files that configure it, and its conventions.

The other documents cover the rest:

| Document | Covers | For |
|---|---|---|
| Requirements | what nyuRuns must achieve, and why | developers, analysts, product owners |
| Functional Specifications | what nyuRuns does, and how its processes run | developers, analysts |
| User Guide | installing it and building a first application | developers |
| Code reference | every class and method, generated from the code | developers |
| `HOSTING.md` | what the server must provide, and how to set it up | whoever hosts nyuRuns |

When a topic is covered in full elsewhere, this document links to it
instead of repeating it.

### 1.3 Words used in this document

- **Run**: an application built on nyuRuns, in a folder of `runs/`.
- **Action**: what a run does for one request, a public method of its
  `Actions.php`, reached by a path such as `shows/view/12` or by a
  route the run declares.
- **Preset**: a profile of the framework (how requests are checked, routed
  and answered), chosen by the entry point of the site.
- **Store**: where data is kept: a database, or a users file.
- **Bundle**: a copy of a project made for one environment.
- Names written `like this` are names from the code, the configuration or
  the command line, exactly as they're written there.

### 1.4 What version 1.0 holds

**Requests and replies.** The whole lifecycle, from the entry point to the
reply. Routes are found from the path, or declared by the site and by each
run, and can lead to an action, a redirection, a static file or folder,
or an HTML page. Each action's definition keeps and formats its inputs,
and a missing required field gets a 400. Replies come as JSON, YAML, XML,
CSV, HTML or raw output. Errors are written in the reply's format, logged,
and never detailed in production.

**Configuration.** Presets, each a profile of the framework; a system file
for logs, errors, caches, proxies and limits; secrets kept in an
environment file, away from the configuration.

**Access.**
- Ways to carry an identity: Basic authentication, an API key, a signed
  token, a PHP session, a client certificate.
- Ways to check it: a password file, API keys, a users table, a users
  file, device tokens, application keys, or a check the project brings.
- Roles, and a limit on wrong attempts by address and by account.
- Two-factor codes, from an authenticator or by mail.
- Tokens signed with a key pair, bound to an issuer and an audience once
  they're set, with their public keys published (`/.well-known/jwks.json`).
- Users managed through an application or from the command line, sign-ups
  approved by a manager, and passwords reset by the users themselves.

**Data.** Named databases on MySQL, MariaDB, PostgreSQL, SQLite and
MongoDB; records and lists, with filters written the same way for all of
them; transactions; binary values read and sent piece by piece;
identifiers that sort by time (UUID v7).

**Documents and media.** Reading and writing of text, Markdown, HTML, XML,
JSON, YAML, CSV, iCalendar, PDF, Word, Excel, OpenDocument and ePub
documents; images, drawings and the images inside documents; sounds, cut
and converted through ffmpeg; QR codes.

**Mail.** Mail accounts that send through SMTP, through PHPMailer, or into
files; the access mails (activation, sign-up, reset, login codes), written
from templates in each user's language.

**Command line.** `php nyuRun`, to install a project on its server, change
its configuration, run the tests, clean up what it leaves behind, manage
its users, devices and application keys, and build a copy of it for each
environment.

**Quality.** A test suite of about 4,500 checks, run against SQLite and
against real MySQL, MariaDB, PostgreSQL and MongoDB servers, and an audit
of the coding conventions.

### 1.5 What is left for later

**Version 1.1:**
- SSO and LDAP connectors, and an authentication server;
- codes by SMS as a second factor, and a login approved from the phone;
- client certificates presented through a proxy, a preset for them, and
  certificate pinning;
- limits by route;
- input sources other than the request: files, records, other APIs, the
  session, a stream;
- sockets;
- several joins in one list, and the encoding of inputs;
- reading rich text and source code, handwriting and scanned text;
- mixing sounds: waveform, loudness, fades, tempo.

**A separate project, codename nyuTron:** the scaffolding of models and
code, an installation wizard, a manager of advanced settings, and
ready-made data kits (members, clients, orders, tasks, projects). These
are tools around nyuRuns, not part of it.

### 1.6 Where 1.0 differs from the first vision

The first description of nyuRuns, in its Requirements, promised more in
some places than 1.0 delivers. What changed:

- **CRUDL out of the box.** The models give every data source its CRUDL
  operations, but a run still declares its actions: no endpoint serves a
  table from configuration alone.
- **Files as data sources.** An action reads and writes files as
  documents. Using them as a configured source of inputs, next to the
  request, is left for version 1.1.
- **Sockets.** They were listed among the data handlers at first, and are
  left for version 1.1. Large data is still read and sent piece by piece.
- **Cache.** Static files go out with their version and date, and a client
  that already holds one gets a 304. Data replies aren't cached: presets
  send them with no-cache headers. What gets cached instead is what the
  framework reads on each request: the configuration, the routes and the
  descriptions of the tables (6.2, `CNF_CACH`, `TBL_CACH`).

---

## 2. Guidelines and recommendations

What a developer should do, and avoid, when building and shipping a
project on nyuRuns. What the server itself must provide (web server,
reverse proxy, TLS, `php.ini`) is covered in `HOSTING.md`.

### 2.1 Start light, grow when needed

A new project needs no database. Its example access config signs users in
through a users file (`conf/users.lst`), which `php nyuRun installs userBase`
creates with its basic users: two (the lowest role and the managers), or one per role. The application only ever
reads that file. When users need to change their own password, receive
their codes by mail, sign up on their own, or hold devices, one command
moves them to a users table in a database:

```
php nyuRun installs moveUser nativeDb
```

Every user keeps their identifier, password, role, status, address,
language and 2FA secret. The users file is left as it was, so the move can
be undone. Devices, and their table, only come with a `dvcToken` block in
`conf/access.json`.

### 2.2 One bundle per environment

Never copy a project to a server by hand: the project folder holds its
secrets, its tests and its documents. Ship a bundle instead, built for the
environment it's going to:

```
php nyuRun bundling final
```

A bundle holds the code, the configuration examples and the libraries, and
nothing else, and its `system.ini` is set for its environment. On the
server, `php nyuRun installs allSteps` makes its local files, secrets and
users, and `php nyuRun installs checkAll` tells what isn't ready. The check
ends with a failure code when a row fails, so a deployment script can stop
there.

The framework knows four environments, set by `ENV_NAME`. Its own bundle
modes set them this way:

| Mode | `ENV_NAME` | `ERR_LEVL` | `ERR_SHOW` | `LOG_MODE` | `CNF_CACH` | Class map |
|---|---|---|---|---|---|---|
| `local` | `DEVS` | `E_ALL` | `TRUE` | `FULL` | `FALSE` | Composer's, as installed |
| `stage` | `PREV` | `E_ALL` | `FALSE` | `BASE` | `TRUE` | complete, authoritative |
| `final` | `PROD` | `E_ALL` | `FALSE` | `MINI` | `TRUE` | complete, authoritative |

In production, the logs keep errors, warnings and notices: sign-ins,
sign-outs and refusals, but no line for each access of a standard user.

A project with servers of its own in between (a test or integration
server, a preview for a client) declares their modes with
`php nyuRun bundling makeMode`, and builds them like the others.

### 2.3 Keep the secrets in one place

Every secret lives in `.env`: the token secret or keys, the API keys, the
key of the 2FA secrets, the database and mail passwords. The configuration
files only name them (`env:NYU_DB_PASS`, `env:NYU_MAIL_PASS`).

- Never commit `.env`, nor the local configuration made from its examples:
  `conf/access.json`, `conf/database.json`, `conf/mailer.json`,
  `conf/users.lst`, `conf/keys/`. Git ignores them, and bundles leave them
  out.
- Make the secrets with the command line rather than by hand. It makes
  them long enough, and writes them where the configuration reads them
  (`php nyuRun settings makeSecr jwtToken`, `keysList`, `tfaCrypt`,
  `markSecr`).
- Give the users store a `tfaCrypt`. Without one, the 2FA secrets are
  stored readable, and the health check says so.

### 2.4 Mail the codes rather than hand them over

A user activated or reset by a manager needs a code to choose a password.
Without a mail account, the code comes back in the reply to the manager
(through the application or the command line), who passes it on. Sign-ups
and the resets users ask for themselves are then closed (404). With a mail
account, users of the native userbase get their codes directly:

- Name an account of `conf/mailer.json` in the `mailAcnt` key of the
  `nativeDb` block. A mailed code lasts `codeMins` minutes (15). One handed
  over by a manager lasts longer, since it goes through a person.
- Set `linkTmpl` to the page of the front end that takes the code
  (`%sentCode%`, plus `%mailKind%` and `%userName%` as needed), so the mail holds a
  link as well as the code. Make it an `https` address.
- In development, send through an `emlFiles` account: mails are written to
  `temp/mails`, and none leaves the machine.
- Open sign-ups (`signupOn`) only with a mail account: the address confirms
  the sign-up, and a manager then approves it. The `tryLimit` block bounds
  them per client (`maxPerIp`) and in all (`maxQueue`), and mails the
  managers when they pile up (`alertsAt`). Open `auth/signup`,
  `auth/active`, `auth/forgot` and `auth/recover` in `openList`, and
  no more of the `Auth` run than the front end needs.
- The replies to a sign-up and to a forgotten password are the same
  whoever the user is. Keep it that way in the front end, which shouldn't
  reveal whether a name or an address is known.
- Each mail goes out in its user's language, or else in `DEF_LANG`. The
  framework ships its templates in English and French
  (`conf/mails/<language>/`). A run writes its own, with its wording and
  look, in `runs/<App>/mails/<language>/`, for each language its users
  speak.

### 2.5 Sign the tokens with a key pair

The example configuration signs tokens with RS256: a private key signs
them, and its public key checks them. Keep the private key on the server
that issues the tokens, and nowhere else. An application that only checks
tokens gets the public key alone: it issues none, and its login answers
501. HS256, one secret shared by everyone who checks the tokens, is still
there for a project made of a single application.

A token stays valid until it expires, and can't be withdrawn before then.
Keep its life short (`lifeSecs`, an hour in the example configuration), or
name a store in the preset, so a user locked in the store is refused at
once.

### 2.6 Show errors where they help, never in production

In development, errors are shown in the reply and logged in full. In
production, a client never sees anything of an error, whatever `ERR_SHOW`
says, and the log keeps the details. Two settings decide what gets
reported at all:

- `ERR_LEVL` chooses the errors PHP reports. What it leaves out is neither
  shown nor logged. Keep `E_ALL` in development, and in production leave
  out only what isn't a fault (`"E_ALL & ~E_DEPRECATED"`).
- `LOG_MODE` chooses how much of what's reported gets written: `FULL` adds
  the debug lines and their context, `BASE` stops at information, and
  `MINI` keeps errors, warnings and notices.

### 2.7 Declare the inputs of every action

An action receives only the fields its definition lists, in
`runs/<App>/input/<action>.json`, each with its format; the others are
left out. Give every action a definition, even one without inputs.

- Mark a field as required with a `!` at the end of its name (`"user!"`)
  when the action can't work without it: a request without it gets a 400.
- List a password with an empty format, so it's kept exactly as sent.
- `OutText` is meant for what gets shown, not for what goes into a query:
  it encodes quotes and removes tags.
- A filter compares a value as it is. A wildcard match is asked for
  through the field's name (`title~`), and only `*` is a wildcard.

### 2.8 Give each route the least access it needs

- Open a route to everyone only on purpose, in the preset's `openList`.
  Every other route asks for a credential.
- Ask for a role by route in the preset (`minRoles`), or in the action
  itself (`needsActionRle()`). Both must pass. A user without the role gets
  a 404, as for a route that doesn't exist, so a standard user can't map
  out the managers' routes. Keep the front end from offering them.
- Keep the limit by address on (the `tryLimit` block): wrong passwords,
  keys and codes from one address then get it held for a while.
- Never base an access decision on what the client says about itself (its
  agent, its system, its language). Only its address is something the
  server reads for itself.

### 2.9 Choose the format of the replies

A preset writes its replies in one format (`jsonData` unless told
otherwise). An action that needs another, a CSV export for instance,
chooses the format of its own reply (`useReplyFormat('csvTable')`). Name
the sites allowed to call from a browser (`corsList`) rather than allowing
all of them.

---

## 3. System requirements

What a server needs to run a nyuRuns project, and what each optional part
adds. On the server itself, `php nyuRun installs checkAll` tells what's
missing.

### 3.1 PHP

PHP 8.2 or later, on the web server and on the command line, both the same
version. The test suite runs on the lowest supported release and on the
latest one (13.5).

A release that fixes php2shell (CVE-2026-109618, CVE-2026-109619) is
expected: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later, or a distribution's
package with the fix backported. `session.upload_progress.enabled` is best
turned off in `php.ini`: the framework doesn't use it, and it carries
CVE-2026-115560. `installs checkAll` warns about both.

The command line runs other programs (ffmpeg, the tests) through
`proc_open()`. A server that disables it in `php.ini`
(`disable_functions`) loses sounds and the test runner, and nothing else.

### 3.2 PHP extensions

| Extension | Needed for | Without it |
|---|---|---|
| `json` | everything: configuration, inputs, replies | nothing is read or answered |
| `dom` | HTML and XML documents, PDF writing | those documents can't be read or written |
| `iconv` | the text encodings of documents and sounds | the libraries can't be installed |
| `zlib` | the compressed parts of PDF documents | the libraries can't be installed |
| `mbstring` | reading and writing PDF documents | PDF documents can't be read or written |
| `openssl` | tokens signed with RS256, encrypted 2FA secrets, encrypted mail | the example configuration can't sign tokens; 2FA secrets are stored readable |
| `pdo_mysql` | MySQL and MariaDB stores | those stores can't be used |
| `pdo_pgsql` | PostgreSQL stores | same |
| `pdo_sqlite` | SQLite stores, and the `data` and `http` test groups | same, and those tests are skipped |
| `mongodb` | MongoDB stores | same |
| `zip` | Office, OpenDocument and ePub documents | those documents can't be read or written |
| `gd` | changing images: resize, crop, rotate, strip | images are read, not changed |
| `imagick` | drawing SVG images as PNG, JPEG or WebP | SVG images aren't drawn |
| `exif` | what a photo records: date, camera, location | that information isn't read |

`json`, `iconv`, `zlib` and `dom` come with most PHP builds. The
framework's libraries require `dom`, `mbstring`, `iconv` and `zlib`, so
Composer installs them only on a PHP that has all four. A bundle carries
its libraries, so the server it goes to never runs Composer.

### 3.3 Libraries

Installed by Composer in `vendor/`, and carried by every bundle:

| Library | Version | Used for |
|---|---|---|
| `level-2/dice` | 4.0 | the dependency injection container |
| `firebase/php-jwt` | 7.2 | signed tokens |
| `dompdf/dompdf` | 3.1 | writing PDF documents |
| `smalot/pdfparser` | 2.12 | reading PDF documents |

`phpmailer/phpmailer` is suggested, not required: a `phpMailr` mail
account needs it to send attachments. The `selfSmtp` and `emlFiles`
accounts need nothing more. The libraries and their licences are listed
in `NOTICE`.

### 3.4 Other programs

- **ffmpeg**, for sounds: unpacking, cutting and converting them. Its full
  path is given by `SND_TOOL` (in `.env`, through `env:NYU_SND_TOOL`), and
  `SND_SECS` limits how long it may run (60 seconds). Without it, a sound
  is still read (its type, tags and cover image), its tags can be removed,
  and a WAV or MP3 sound can be cut; other types can't be converted or
  cut.
- **A mail server** that speaks SMTP, for the `selfSmtp` and `phpMailr`
  accounts: on port 465 with TLS, or on another port with STARTTLS. Only a
  server on the same machine is spoken to unencrypted.

### 3.5 Databases

None is needed: a project signs its users in from the users file until it
moves them to a database (2.1). The 1.0 test pass ran on these servers:

| Server | Versions |
|---|---|
| MySQL | 5.7, 8.4 |
| MariaDB | 13.0 |
| PostgreSQL | 18 |
| SQLite | the one in PHP's `pdo_sqlite` |
| MongoDB | 9.0 |

The delivered tables (`conf/schema`) are written for MySQL (MariaDB
included), PostgreSQL and SQLite. The migrations of the users and devices
tables rename columns with `RENAME COLUMN`, which MySQL 5.7 doesn't have:
each MySQL migration file carries a variant for it.

### 3.6 Web server

Any web server that hands the request to PHP will do: Apache, nginx with
PHP-FPM, or another. It must:

- serve the `www` folder only, and nothing above it (`conf`, `runs`,
  `src`, `temp`, `test`, `vendor`);
- send every path to `www/index.php`, which reads the route from the whole
  path of the URL. A project is served at the root of its host, not in a
  sub-folder of another site (the Apache and nginx settings are in
  `HOSTING.md`);
- handle TLS itself, or leave it to a proxy in front of it (see
  `HOSTING.md`).

### 3.7 Folders written

The framework writes in `temp/`: the logs (`temp/logs`, or `LOG_PATH`),
the counts of the limit on attempts (`temp/limits`), the config cache
(`temp/cache`), the previous versions of the configuration files the
command line changes (`temp/archives`), and the mails of an `emlFiles`
account (`temp/mails`). PHP sessions are kept wherever `php.ini` puts
them, unless the `phpSessn` block names a folder (`dataPath`). The command
line writes in `conf/` and `.env` when it changes the configuration. The
user the web server runs as only needs to write in `temp/`.

---

## 4. The request lifecycle

Every request goes through one object, the Runner (`src/Runner.php`),
always in the same order. Any step can end the request with its own
reply, and the later steps then never run.

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

The entry point, `www/index.php`, defines the project's folders and
creates the Runner, naming its preset (`default` when it names none).

1. **The preset** (`conf/presets/<name>.rps`) is read and checked: a key
   it doesn't know stops it from loading (`Preset::KEYS_LIST`).
2. **The configuration** is loaded by the Loader: `conf/system.ini`, every
   `.json` file of `conf/`, and the values of `.env` they name. With
   `CNF_CACH`, the preset, those files and the routes are read from
   `temp/cache` after the first request; `.env` is still read every time.
   Values the caller gives the Runner are laid on top.
3. **The shared objects** are built by the container (`Setup\Builder`, on
   Dice): the server registry (`$_SERVER`, emptied once read), the client
   registry (its address, seen through the trusted proxies), the response
   and the request.
4. **The logs** are plugged in with the configuration (`LOG_PATH`,
   `LOG_MODE`), along with the program that unpacks sounds, when
   `SND_TOOL` names one.
5. **The error handler** is installed, at the level of `ERR_LEVL`.

A failure before the error handler is in place can't be formatted or
logged by the framework: it goes to PHP's own error log, and the client
gets a plain JSON 500.

### 4.2 Reading the request

The request is read once, when it's built: its method, URL, headers, query
and body (JSON, a form, or a multipart body with files, for every method).

- A body larger than `REQ_SIZE`, or a multipart body with more parts than
  `REQ_PART`, isn't read: the request gets a 413, and a warning is logged.
- The preset's `inputSet` block says which sources the inputs come from.
  Server and client values are never inputs: an action reads them through
  the request.

### 4.3 Finding the route

The Router (`src/Tools/Router.php`) turns the path into a route, in this
order:

1. **A CORS preflight**, an `OPTIONS` request asking about a method, gets
   a 204 with the preset's headers, on any path.
2. **The routes of the entry point**, in a `mapping` preset.
3. **The declared routes**: first the site's, in `conf/routes/*.rte` (or
   the preset's own folder), then those each run declares in
   `runs/<App>/routes.rte`, mounted under the run's name or where the
   preset's `runMount` says. A route equal to the path wins, then the
   first pattern that matches it. An empty path is looked up as `index`.
4. **The path itself**, in an `autopath` preset: its parts get the names
   of the preset's `pathForm`, by position (`$appToRun/$doAction/$argument`,
   so `shows/view/12`). A run that declares its own routes isn't
   reached this way: its path gets a 404, logged on `runner`.

A declared route names what answers: an action, a redirection, a static
file or folder, or an HTML page. A method it doesn't allow gets a 405 with
its `Allow` header, and an `OPTIONS` request gets the methods it allows.

### 4.4 Checking access

The Checker (`src/Access/Checker.php`) decides whether the request goes
on. It fails closed: a configuration it can't apply refuses every request,
and it never falls back to a weaker check.

1. **The roles of the routes** (`minRoles`) are read first. One that can't
   be applied gets a 500 (`Access misconfigured`).
2. **A route open to all** (`openList`), or a preset that asks for no
   access, lets the request through, with nobody logged. An open route
   that also asks for a role is a 500.
3. **The carrier and the check** (`carrWith`, `authWith`) must exist and
   work together, and the sign-in route must be a path of the site;
   otherwise, a 500.
4. **An address held** for too many wrong credentials gets a 429, with
   `Retry-After`, before any credential is read.
5. **The call of an application** for one of its users is checked through
   its own key and token, when the preset names the application keys.
6. **The credential** is read by the carrier and checked. A missing or
   refused one gets a 401, with `WWW-Authenticate` for Basic and Bearer; a
   visitor asking for a page is sent to the sign-in route instead (302).
   Only a credential that was presented and wrong counts against the
   address.
7. **The role** of the route is compared with the user's. A user without
   it gets a 404, as if the route didn't exist, and the refusal is logged
   on `access` with the role needed.

From then on, the events traced in the logs carry the user and the
address of the request, and every file received is traced, whether access
was granted or not. A request refused at this step never loads the
application.

### 4.5 Running the action

A route that answers by itself is answered here: a redirection goes out
with its status, a static file with its version and date (a 304 when the
client already holds it, a part of it when `Range` asks), a page with its
placeholders filled. A file or page that can't be found is a 404, with the
same `Not Found` as every other.

Otherwise the route names an application, and its Controller
(`src/App/Controller.php`) takes over:

1. **The application** must be a folder of `runs/` named with letters and
   digits only, holding an `Actions.php` that declares its actionset.
2. **The action** is the one the route names, or the application's default
   when it names none: the `$dfltAction` of its actionset, or else its
   first action. It must be one of the application's actions: a public
   method of its actionset, not a helper or a method of the framework. An
   action that a declared route limits isn't reached by its path.
3. **The inputs** get the action's definition (`input/<action>.json`): the
   fields listed are kept, each in its format, and the others are left
   out. A required field that's missing or empty is a 400, and the action
   doesn't run.
4. **The models** of the application are loaded (`ModelStack`).
5. **The actionset** is created with a read-only `RunContext` (what it
   needs for this request), runs its `setupForAction()` hook, then runs
   the action, with the route's parameter when there is one.

Whatever fails in steps 1 and 2 gets the same reply, `404 Not Found`, as a
path no route answers: a client can't tell an application that doesn't
exist from an action it may not reach. The reason is logged on `runner`. A
default action that names no action is the application's mistake, not the
client's: it's a 500, logged as an error.

An action sets its status and its output. It can stop at any point, from
itself, from a helper or from `setupForAction()`, with `stopThisAction()`:
stopping isn't an error, and its status becomes the reply's.
`needsActionRle()` stops it with a 404, just as the role of a route does,
and `redirectClient()` with a redirection.

### 4.6 Writing the reply

The status decides the body:

| Status | Body |
|---|---|
| 204 without output | none |
| 204 with an output | the output, sent as a 200 |
| 2xx | the action's output |
| 3xx | none: the reply is its `Location` header |
| 4xx, 5xx | `{"error": <message>, "code": <status>}` |

The warnings PHP raised while the request ran are added to the body
(`data` and `warnings`) when errors may be shown, except in a CSV or raw
reply, where they stay in the log. Output that can't be written in the
reply's format turns the reply into a 500.

An error on a page, on a static file, or on a path no route answers in a
preset that serves pages, is answered with the site's error page
(`<fileRoot>/errors/<status>.html`) when it has one. An action keeps its
preset's format.

The response then writes its headers: the preset's, the allowed origin
when the preset lists it, and `X-Content-Type-Options: nosniff` on every
reply. A `HEAD` request gets the headers alone. The body is sent, and the
request ends.

### 4.7 Errors on the way

An exception no code catches, or a fatal error, is caught by the error
handler (`src/Error/handling.php`): it's logged on the `error` channel,
and answered with a 500 in the reply's format. Its details are shown only
when `ERR_SHOW` allows it, and never in production. What `ERR_LEVL` leaves
out is neither shown nor logged.

---

## 5. Components

The framework lives in `src/`, under the `nyu\` namespace, loaded by
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

A request goes from the top of this table to the bottom. `Data` is only
loaded when a run or the access layer asks for it.

### 5.1 Runner and setup

- **`Runner`**: the sequence of chapter 4, from the preset to the reply.
- **`Setup\Preset`**: reads a preset (`conf/presets/<name>.rps`), checks
  its keys (`Preset::KEYS_LIST`), and hands its blocks to the Loader.
- **`Setup\Loader`**: the configuration of the request: the files of
  `conf/`, the values of `.env` they name, and the settings of each part
  (logs, errors, request limits, proxies, sounds).
- **`Setup\ConfCache`**: the config cache: the checked preset, the
  configuration files, the routes and the descriptions of the tables, kept
  in `temp/cache/config-<preset>.cache` and read in their place.
- **`Setup\Builder`**: the dependency injection container, on Dice. It
  builds the objects the whole request shares: the server and client
  registries, the request and the response.

### 5.2 Tools

| Class | Role |
|---|---|
| `Registry` | a dictionary of values, the base of every registry |
| `ServerReg` | `$_SERVER`, read once and then emptied, so no code reads it except through the request |
| `ClientReg` | what the request says about its client: its address (through the trusted proxies), its agent, system, kind of device and language; only the address is the server's own reading |
| `Request` | the request as sent: method, path, headers, query, body, files, within the size limits |
| `Router` | the route of the request (4.3), the links to the declared routes, the error pages |
| `Input` | the inputs of an action, kept and formatted by its definition |
| `Variable` | the formats a value can be given: texts, numbers, prices, dates, addresses, phone numbers, links, HTML |
| `Logger` | one log file per channel (`error`, `access`, `runner`, `shell`, one per run), PSR-3 levels, rotation |
| `Language` | the language a text is written in for one person, and the file found for it |
| `StrKit` | string helpers |
| `UniqueId` | identifiers a record keeps wherever it's copied: UUIDs of version 7 |

### 5.3 Access

**`Access\Checker`** decides whether a request goes on (4.4). It pairs a
**carrier**, which reads the credential from the request (`carrWith`),
with a **check**, which verifies it (`authWith`):

| Carrier | Class | Carries |
|---|---|---|
| `httpBasc` | `Sess\Basic` | a name and a password, through HTTP Basic |
| `apiHeadr` | `Sess\Header` | a key in a header |
| `jwtToken` | `Sess\JWT` | a signed token (HS256, RS256) |
| `phpSessn` | `Sess\PHPSess` | a PHP session, through its cookie |
| `clntCert` | `Sess\ClCert` | a client certificate, checked by the web server |

| Check | Class | Verifies against |
|---|---|---|
| `htpasswd` | `Auth\PassList` | a password file in the Apache format |
| `regdKeys` | `Auth\RegKey` | a list of API keys |
| `userFile` | `Auth\UserList` | the users file, read only |
| `nativeDb` | `Auth\Native` | the users table of a database |
| `dvcToken` | `Auth\Device` | the tokens of the devices |

A carrier and a check that can't work together refuse every request
(`Checker::PAIR_LIST`). Alongside them:

- **`Limiter`**: counts the wrong credentials of each address, and holds
  it for a while once it's past the limit.
- **`Totp`**: the two-factor codes (RFC 6238), and the encryption of their
  secrets.
- **`AppKey`**: the calls an application makes for one of its users
  (`appsKeys`).
- **`Auth\UserBase`**: managing the native userbase: creation, activation,
  roles, statuses, passwords, tokens, sign-ups, resets.
- **`AccessMail`**: the mails of the access flows (activation, sign-up,
  reset, login code), from their templates, in their user's language.

### 5.4 Applications

- **`App\Controller`**: finds the application and its action, applies the
  definition of its inputs, loads its models, builds its `RunContext`, and
  runs it (4.5).
- **`App\RunContext`**: what an actionset is given for one request, read
  only: the action and its parameter, the inputs, the access result, the
  request, the response, the router, the models, the application's name
  and folder.
- **`App\Actionset`**: the parent of every run's `Actions` class. Each of
  the run's own public methods is an action. The actionset hands them
  their inputs, models, access, request and response, runs the
  `setupForAction()` hook first, and offers the helpers
  `serveFileReply()`, `answerPageFlag()`, `redirectClient()`,
  `routLinkString()`, `useReplyFormat()`, `getLoggerOfApp()`,
  `needsActionRle()`, `holdsActionRle()` and `stopThisAction()`.
- **`App\ModelStack`**: the models of a run, every `*Model.php` of its
  `mods/` folder, then those shared in `runs/Shared`.
- **`App\StopRun`**: what `stopThisAction()` throws to stop an action on
  purpose, with its status and its output.

### 5.5 Output and errors

- **`Output\Response`**: the reply: its status, its headers (the preset's,
  the allowed origin, `nosniff`), and its body in its format (`jsonData`,
  `yamlData`, `xmlNodes`, `csvTable`, `htmlView`, `rawBytes`), a file sent
  in parts, or a page with its placeholders filled.
- **`Error/handling.php`**: the error, exception and shutdown handlers:
  they log on `error`, collect the warnings, and answer a 500 through the
  Runner.

### 5.6 Data

**Databases.** A run works through models, not queries:

- **`DBRec`**: one record of a table, loaded by its key or found by its
  fields, saved, or deleted with its children. **`DBLst`**: a list of
  records read through a filter, its fields chosen, grouped, sorted,
  limited, joined to another table, or the children of a record.
- **`DBStore\Store`**: what a record and a list need from a database,
  provided by **`SqlStore`** for the SQL types and **`MongoStore`** for
  MongoDB. **`DBStore\Filter`** reads the filter, written the same way for
  all of them (`field`, `field!`, `field<=`, `field~`, …).
- **`DBCon`**: one connection per configured database, opened on first
  use, through its handler: **`DBHndlr\MySQL`** (MariaDB included),
  **`PgSQL`** and **`SQLite`**, all on PDO.

**Documents.** A document is read into one of three shapes, and written
from one (`Data\Shape`): `nodes` (a tree of keys and values), `sheet`
(named tables of rows and cells) and `block` (text blocks in reading
order).

- **`Dcmnt`**: a file on the server, its type told from its first bytes,
  read by the parser its extension calls for.
- **`Parsers\*`**, chosen by `Parsers\Index`: text, Markdown, HTML, XML,
  JSON, YAML, INI, CSV and TSV, iCalendar, PDF, Word, Excel, OpenDocument
  text and spreadsheet, ePub.
- **`Writers\*`**, chosen by `Writers\Index`: the same formats except INI,
  with PDF written through dompdf.
- **`Fluxd`**: a source read piece by piece (a file, the request body, a
  binary value of a table), never held whole unless asked.
- **`Archv`**: a zip archive read entry by entry, in memory.

**Media.**

- **`Image`**: an image, its type, its size and what a photo records;
  resized, cropped, rotated, stripped (`gd`); a drawing turned into an
  image (`imagick`).
- **`Sound`**: a sound, its type, length, tags and cover image; its tags
  removed, a WAV or MP3 cut. **`Codec`** runs ffmpeg for the rest.
- **`QrCode`**: a QR code of a text, drawn as SVG or as text for a
  terminal.

**Mail.** **`Mail\Mailer`** sends a **`Message`** through an account of
`conf/mailer.json`, using its driver: **`Drivers\Native`** (the
framework's own SMTP client, `selfSmtp`), **`PHMail`** (PHPMailer, for
attachments, `phpMailr`) or **`ToFile`** (`.eml` files, `emlFiles`).
**`Template`** builds a message from its text and HTML files, with each
`%name%` filled.

### 5.7 Command line

- **`Shell\Console`**: reads the arguments of `php nyuRun`, finds the
  command, and runs it.
- **`Shell\Command`**: the parent of every command, with its sub-commands
  (`subsTableArray()`), along with **`Options`**, **`Printer`** (text, or
  JSON for a script) and **`ShellStop`** (the exit code).
- **`Shell\Cmds\*`**: `installs`, `settings`, `runTests`, `maintain`,
  `accounts`, `machines`, `bundling`, `appsKeys` (chapter 7).
- **`HealthChk`** runs the checks of `installs checkAll`. **`ConfFile`**
  writes the configuration files, each in its own way, and **`UserFile`**
  the users file. **`ActCaller`** calls an action of a run from the
  command line, with the preset's roles.

### 5.8 Where the framework is extended

| To add | Write | Declared in |
|---|---|---|
| a credential check | `runs/Shared/<Name>Auth.php`, implementing `Access\Auth\Handler` | the `plugLibs` block of `access.json`, under `authWith` |
| a carrier | `runs/Shared/<Name>Carr.php`, implementing `Access\Carr\Handler` | the `plugLibs` block, under `carrWith` |
| a model shared by runs | `runs/Shared/<Name>Model.php` | nothing: found by name |
| a preset | `conf/presets/<name>.rps` | the entry point that names it |
| a mail template | `runs/<App>/mails/<language>/<kind>.txt` and `.html` | nothing: replaces the framework's |
| a command of the command line | `runs/<App>/Shell.php`, the class `Shell` extending `Shell\Command` | nothing: the command is the run's name in lower case |

A plug-in's name is six characters long, letters and digits, starting
with a letter. One that's listed but can't be used is logged, and the
request is refused as misconfigured.

---

## 6. Configuration files

### 6.1 How the configuration is read

On each request, the Loader reads every `.json` and `.ini` file of
`conf/` (or of the folder the preset names), and keeps each one under the
name of its file in capitals: `system.ini` is `SYSTEM`, `access.json` is
`ACCESS`. With `CNF_CACH`, the first request of a preset writes what it
read to `temp/cache`, and the next ones read that file instead. Every
command that writes in `conf/` removes the cache, and so do
`installs allSteps` and `settings clrCache`. After a hand edit,
`php nyuRun settings clrCache` removes it, and `installs checkAll` warns
about a cache older than its files. No secret goes into the cache: `.env`
is read on each request. So a file of `conf/` that isn't a configuration
must not end in `.json` or `.ini`: the examples end in `.dist`, the lists
in `.lst`.

A value written `env:NAME` is replaced by the value of `NAME` in `.env`
(or the file the preset names), or else in the process environment.
Secrets are only ever written this way (2.3).

The files made from an example (`access.json`, `database.json`,
`mailer.json`, `users.lst`) and `.env` are local to each installation:
git ignores them, and bundles leave them out. `php nyuRun installs
allSteps` makes them from their examples, and `php nyuRun settings` reads
and changes them, checking each value.

All names follow the same rule: the keys of one block share one length,
8 characters for the JSON files, and 8 in the `XXX_YYYY` form for
`system.ini`.

### 6.2 `system.ini`

| Key | Value | Default |
|---|---|---|
| `ENV_NAME` | the environment: `DEVS`, `TEST`, `PREV`, `PROD` | `DEVS` |
| `LOG_MODE` | the levels written: `MINI` (errors, warnings, notices), `BASE` (plus information), `FULL` (plus debug, with the context) | `FULL` |
| `LOG_PATH` | the folder of the logs, from the root of the project | `temp/logs` |
| `LOG_SIZE` | the size of a log, in KB, before it's rotated; 0 for no limit | 1024 |
| `LOG_ROTA` | the rotation: `INDX` (numbered copies past `LOG_SIZE`), `WEEK`, `MNTH` (dated file names), `NONE` | `INDX` |
| `LOG_PRFX` | the `date()` format at the start of each line | `Y-m-d H:i` |
| `ERR_LEVL` | the errors PHP reports, `E_*` names joined by `\|`, `&` and `~` | `E_ALL` |
| `ERR_SHOW` | whether errors are shown in the reply, `TRUE` or `FALSE`; never in `PROD` | |
| `DEF_ROWS` | the rows a list reads when it isn't told how many | 100 |
| `DEF_LANG` | the language of the mails of a user who has none | `en` |
| `REQ_SIZE` | the largest body read, in KB; 0 for no limit | |
| `REQ_PART` | the most parts of a multipart body; 0 for no limit | |
| `CNF_CACH` | whether the preset, the configuration files and the routes are cached (`TRUE`, `FALSE`) | `FALSE`; `TRUE` in the `stage` and `final` bundles |
| `TBL_CACH` | whether the descriptions of the tables are cached too, with `CNF_CACH`; a query failing on a cached table removes the cache | `FALSE` |
| `SND_TOOL` | the full path of ffmpeg, empty without it | |
| `SND_SECS` | the seconds ffmpeg may run | 60 |
| `PRX_LIST` | the addresses or ranges of the trusted reverse proxies | empty |
| `PRX_HEAD` | the header in which they give the client's address | |
| `PRX_SCHM` | the header in which they give its scheme | |

### 6.3 `.env`

`.env.example` lists the names the configuration examples use:

| Name | Holds |
|---|---|
| `NYU_JWT_SECRET` | the secret of HS256 tokens (32 characters at least); empty with RS256 |
| `NYU_API_KEY` | an API key of the `regdKeys` block |
| `NYU_TFA_KEY` | the key the 2FA secrets are encrypted with (32 characters at least) |
| `NYU_MARK_KEY` | the key the login marks are signed with (`tryLimit.markSecr`) |
| `NYU_DB_PASS` | the password of the database |
| `NYU_MAIL_PASS` | the password of the mail account |
| `NYU_SND_TOOL` | the path of ffmpeg |
| `NYU_TEST_*` | the test servers for MySQL, PostgreSQL and MongoDB (chapter 13) |

Any name can be added: the configuration just has to write it
`env:NAME`.

### 6.4 Presets

A preset (`conf/presets/<name>.rps`, JSON with comment lines) is a profile
of the framework, chosen by the entry point. Its blocks and their keys:

| Block | Keys | Sets |
|---|---|---|
| (top) | `presetId`, `synopsis` | its name, and what it's for |
| `settings` | `confType`, `confList`, `confPath`, `envrFile` | the configuration files read: their types (`all`, `ini`, `json`), their names (`any` or a list), their folder, the file of the secrets |
| `security` | `required`, `carrWith`, `carrConf`, `authWith`, `authConf`, `openList`, `signPath`, `minRoles`, `appsConf`, `isPublic` | the access check: whether one is needed, the carrier and the check with the blocks of `access.json` they read, the routes open to all, a site's sign-in route, the roles of the routes, the block of the application keys, and a site meant to be open |
| `inputSet` | `sourceId`, `readFrom`, `fieldSet` | the sources of the inputs (`QUERY`), and the fields kept |
| `dispatch` | `detectBy`, `pathForm`, `routeDir`, `fileRoot`, `runMount` | the routing: `autopath` or `manually`, the names of the parts of a path, the folder of the route files, the folder of the pages and static files, where each run's routes are mounted (or `false` to stop serving it) |
| `response` | `outputAs`, `corsList`, `headList` | the reply: its format, the sites allowed to call, the headers sent |

A key a preset doesn't know stops it from loading. The framework ships
seven presets:

| Preset | For |
|---|---|
| `default` | the entry point that names none: closed, Bearer tokens, JSON |
| `mapping` | an entry point that declares its own routes: closed, Bearer tokens |
| `service` | a service called by other programs: an API key in a header |
| `restapi` | a web API: Bearer tokens, signing in left open |
| `website` | a site used from a browser: a session cookie, the users table, its own routes |
| `statics` | a site of pages, files and redirections: open, no inputs, its own routes |
| `example` | never loaded: every setting of a preset, with what it does |

### 6.5 `access.json`

The blocks a preset names through `carrConf`, `authConf` and `appsConf`,
and the settings of the access layer. A block is named after its check or
carrier, so a value naming a block reads on its own
(`"carrConf": "jwtToken"`):

| Block | Keys | Sets |
|---|---|---|
| `loginSet` | `authWith`, `authConf`, `carrWith`, `carrConf` | how the `Auth` run signs a user in, and what it issues |
| `plugLibs` | `authWith`, `carrWith` | the plug-in checks and carriers allowed (5.8) |
| `htpasswd` | `passFile`, `weakHash`, `maxTries`, `holdSecs` | the password file of the `htpasswd` check, and its limit by account |
| `nativeDb` | `linkName`, `useTable`, `idleSecs`, `maxTries`, `holdSecs`, `minChars`, `tfaCrypt`, `mailAcnt`, `signupOn`, `codeMins`, `linkTmpl` | the native userbase: its database and table, the time after which a user is no longer online, the wrong passwords before the account is held and for how long, the shortest password, the key of the 2FA secrets, the access mails |
| `userFile` | `listFile`, `tfaCrypt`, `maxTries`, `holdSecs` | the users file, the key of its 2FA secrets, and its limit by account |
| `dvcToken` | `useTable`, `usersTbl`, `seenSecs` | the table of the devices, the one of their users, and how often a device's last use is recorded |
| `tryLimit` | `maxTries`, `holdSecs`, `dataPath`, `maxQueue`, `maxPerIp`, `alertsAt`, `markSecr`, `markDays` | the limit by address, the bounds of the sign-ups, and the login marks |
| `regdKeys` | `headName`, `keysList` | the header of the API keys, and the keys |
| `jwtToken` | `hmacSecr`, `lifeSecs`, `signAlgo`, `privFile`, `publFile`, `prevFile`, `issuerId`, `audience` | the tokens: their algorithm, secret or keys, the previous public key, their life, issuer and audience |
| `phpSessn` | `cookName`, `lifeSecs`, `fullSecs`, `sameSite`, `secureOn`, `dataPath` | the PHP sessions: the cookie, the time unused and in all |
| `clntCert` | `checkVar`, `ownerVar`, `issueVar`, `issuerId` | the server values a client certificate is read from, and the issuer accepted |
| `appsKeys` | `headName`, `appsList`, `audience` | the keys of the applications calling for their users (`keyDigst`, `routeSet`, `publFile`, `roleTops`, `madeDate` for each) |

A project can hold several blocks of one kind under names of its own, with
each preset naming the one it uses.

### 6.6 `database.json`

One database, or several by name, the first being the default:

| Key | Value |
|---|---|
| `dbEngine` | `mysql_db` (MariaDB included), `postgres`, `sqlitedb`, `mongo_db` |
| `hostName`, `portNumb` | the server; empty for SQLite |
| `baseName` | the database, or the file of an SQLite one |
| `userName`, `passWord` | the account, with the password as `env:NAME` |
| `encoding` | the character set (`utf8`) |
| `authBase` | the database that holds the account, when it's another one |
| `waitSecs` | the seconds the server is given to answer |

### 6.7 `mailer.json`

The mail accounts by name, the first being the default, each with the
keys `sendWith` (`selfSmtp`, `phpMailr`, `emlFiles`), `hostName`,
`portNumb`, `userName`, `passWord`, `fromAddr`, `fromName`, `waitSecs`
(the seconds a server may take), `mailsDir` (the folder of an `emlFiles`
account) and `copiesTo` (addresses every mail is sent again to).

### 6.8 Route files

The site's routes are declared in `conf/routes/*.rte` (or the preset's
folder), JSON with comment lines, by their path:

```json
{
    "profiles/$id": {
        "mthdList": "GET, POST",
        "pointsTo": { "appToRun": "client", "doAction": "edit" }
    },
    "assets/*": {
        "pointsTo": { "fldrPath": "assets" }
    }
}
```

- A part written `$name` captures a value: the first one is the action's
  parameter, and all of them fill the placeholders of a page. A final `*`
  takes the rest of the path.
- `mthdList` lists the route's methods (`GET` alone otherwise); a
  `pointsTo` block can also hold one block per method.
- A target names one kind: an action (`appToRun`, `doAction`), a
  redirection (`redirect`, with its status in `httpCode`), a static file
  (`filePath`) or folder (`fldrPath`), or an HTML page (`pageFile`).

A run declares its own routes the same way, in `runs/<App>/routes.rte`,
with a target that names one of its actions through `doAction` alone. Its
paths are mounted under the run's name in lower case (`""` is `/notes`,
`$id` is `/notes/$id`), a path with a leading slash starts from the site
root, and the preset's `runMount` can mount the run elsewhere or not at
all. A key that nothing reads is logged as a warning. A route file holds
no access setting: the roles of the routes are in the preset.

### 6.9 Input definitions

The inputs of an action are declared in `runs/<App>/input/<action>.json`:

```json
{ "fieldSet": { "user!": "OutText", "pass": "", "mail": "EmlAddr" } }
```

Each field is named with its format (one of `Tools\Variable`). A `!` after
the name makes it required, and an empty format keeps it as sent. An
unknown format stops the request with a 500 (400 for a required field).

### 6.10 Other files

| File | Holds |
|---|---|
| `conf/users.lst` | the users file: `{ "users": [ ... ] }`, with the column names of the users table |
| `conf/bundle.lst` | the project's bundle modes (chapter 7) |
| `conf/.htpasswd` | the password file of the `htpasswd` check |
| `conf/keys/` | the token keys (`.key`, `.pub`), made by `settings makeSecr jwtToken` |
| `conf/schema/` | the delivered tables, one file per table and database type (`users.sqlitedb.sql`), and their migrations |
| `conf/mails/<language>/` | the templates of the access mails |

---

## 7. Command line

### 7.1 Usage

```
php nyuRun <command> <sub-command> [arguments] [--options]
```

Every command and sub-command is a word of eight letters, matched whatever
its case. A command without a sub-command, or with `--helpText`, lists
what it does.

| Option | Effect |
|---|---|
| `--noPrompt` | no question is asked: the default answers are taken |
| `--yesToAll` | whatever removes or replaces is confirmed beforehand; with `--noPrompt` alone, it's declined |
| `--outputAs=json` | the result as one JSON object, for a script to read |
| `--silently` | no remark, only the result |
| `--helpText` | what the command does |
| `--versions` | the version of the framework |

| Exit code | Meaning |
|---|---|
| 0 | done |
| 1 | failed |
| 2 | wrong usage: an unknown word, a missing argument |
| 3 | refused or declined |
| 4 | done, with problems found (`installs checkAll`) |

What a command changes is traced on the `shell` log channel, signed
`cli:<system user>`. What it changes in the users is traced on `access`,
like the same change made over HTTP.

### 7.2 `installs`: install and check

| Sub-command | Does |
|---|---|
| `allSteps` | the whole installation: the server checked, the local files made, the secrets made, the users created (their names asked), the health check; it asks for what it needs, suggesting a default (`--noPrompt` takes them all, `--useStore` picks the store) |
| `testHost` | checks the server: PHP, its extensions, the folders written |
| `makeConf` | makes `.env`, `access.json` and `database.json` from their examples |
| `userBase` | creates the users table and the basic users, or the users file; the devices table with a `dvcToken` block |
| `checkAll` | the health check: each row `fine`, `warn` or `fail`; exit code 4 on a `fail` |
| `moveUser <nativeDb\|userFile>` | moves the users to the other store, and the login with them (`--dropsAll`, `--yesToAll`) |

Nothing that exists is replaced: a file, a secret, a table, a store that
holds users.

### 7.3 `settings`: read and change the configuration

| Sub-command | Does |
|---|---|
| `showConf <coreConf\|accsConf\|dataConf\|preset> [path]` | shows a file, one of its blocks or keys, with secrets masked |
| `setValue <coreConf\|accsConf\|dataConf\|preset> <path> <value>` | changes a value (`KEY`, `block.key`, `preset.block.key`), checked by its rule (`--overrule`) |
| `wwwEntry [file] [preset]` | the preset an entry point of `www` starts with, shown or changed |
| `makeSecr <jwtToken\|keysList\|tfaCrypt\|markSecr\|htpasswd>` | makes a secret, the RS256 key pair, or a password of the password file, and writes it where the configuration reads it (`--useBlock`, `--showSecr`, `--typeItIn`) |
| `fullList [preset]` | the routes of a preset: path, methods, kind, target, owner, and who they're served to |
| `clrCache` | removes the config cache: the next request of each preset reads its files again |

A file is written with its comments and layout kept, the previous version
copied to `temp/archives`. A change that leaves it unusable is undone.

### 7.4 `accounts`: users and actions

The users of the store the login uses (`--useStore`, or `--useBlock` for
another block):

| Sub-command | Does |
|---|---|
| `fullList`, `viewUser` | lists the users (`--statusIs`, `--roleName`, `--rowCount`, `--startRow`), shows one |
| `makeUser <user> [role]` | creates a user awaiting activation, and shows its activation code once (`--noExpiry`, `--language`) |
| `activate <user> <code>` | activates a user with its code, and gives it a password |
| `lockUser`, `freeUser` | locks a user, unlocks it |
| `giveRole <user> <role>` | gives another role |
| `dropUser <user>` | removes a user |
| `resetPwd <user>` | a new activation code, for a lost password |
| `password <user>` | a new password, made and shown once (`--typeItIn` to type it) |
| `apiToken <user>` | a personal API token, shown once (`--removeIt` takes it away) |
| `setTwoFa <user>` | a 2FA secret, with the authenticator's QR code (`--noQrCode` without it, `--emailOtp` for codes by mail, `--removeIt` takes it away) |
| `setEmail <user> [address]` | sets or removes the address |
| `language <user> [language]` | shows, sets or removes the language |
| `approves [user]` | lists the sign-ups to review, or approves one |
| `doAction <app/action> [field=value]` | calls an action of a run, for automated tasks, with the roles of a preset (`--presetId`, `--roleName`, `--bodyFrom`, `--httpVerb`, `--saveInto`) |

### 7.5 `machines`, `appsKeys`: devices and applications

| Command | Sub-commands |
|---|---|
| `machines` | `newToken <name> <user> <role>` gives a device a token, shown once; `fullList [user]`; `revokeIt <name> <user>` |
| `appsKeys` | `makeKeys <app>` with `--routeSet`, `--publFile`, `--roleTops`, gives a key shown once; `fullList`; `setRoute <app>` replaces its routes; `revokeIt <app>` |

Only the hash of a token or a key is stored. A revoked device stays in the
list with the time it was revoked; a revoked key is refused from the next
request.

### 7.6 `bundling`: a copy for each environment

| Sub-command | Does |
|---|---|
| `local`, `stage`, `final` | builds the copy for development, for a server before production, for production |
| `<mode>` | builds a mode of the project |
| `fullList` | lists the modes, the framework's and the project's |
| `makeMode <mode>` | declares a mode of the project in `conf/bundle.lst`, or changes it |
| `dropMode <mode>` | removes one |

A bundle is written to `dist/<mode>`, or to the empty folder `--destPath`
names. `--archived` adds its `.tar.gz`, and `--planOnly` lists what would
be copied. Only what `Bundle::KEEP_LIST` names is copied, minus what
`DROP_LIST` names: no secret, no local setting, no key. The mode's
settings are written in the copy's `system.ini`. A copy for `stage` or
`final` gets a complete, authoritative class map, made in it by
Composer (`--composer` names it, or else `composer.phar` at the root, or
the one on the path). Without Composer, the copy keeps the project's
autoloader, and says so.

A mode of the project, in `conf/bundle.lst`, holds `settings` (keys of
`system.ini`) and `addPaths` (paths copied beyond the allow-list).

### 7.7 `maintain`: temporary files and logs

| Sub-command | Does |
|---|---|
| `cleanDir <cacheDir\|logFiles\|tryLimit\|archives\|all>` | removes the files of those folders of `temp` (`--keepDays=<days>`) |
| `tailLogs <channel>` | the last lines of a log (`--numLines`, `--watching` to follow it) |
| `fullList` | the addresses the limit on attempts counts or holds |
| `freeAddr <address\|all>` | releases an address |

Nothing is removed outside the project's `temp` folder.

### 7.8 `runTests`: the test suite

`php nyuRun runTests` runs the `unit`, `audit` and `data` groups. A group
or a word runs those scripts, and `all` runs every group. The extensions
the tests need and PHP doesn't load are given for the run
(`--addExtns`). Chapter 13 describes the suite.

### 7.9 Commands of a run

A run brings its own command with `runs/<App>/Shell.php`, which declares
the class `Shell` of its namespace, extending `Shell\Command`. The command
is the run's name in lower case, and each sub-command runs a method named
`invoke` plus its name (`invokeSayHello` for `sayHello`), listed in its
`subsTableArray()`.

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
  routes.rte                  its own routes
  pages/<name>.html           the pages it replies with
  mails/<language>/<kind>.*   its own mail templates
  Shell.php                   its own command of php nyuRun
```

Without a `routes.rte`, the run is reached by its name in lower case
(`notes/view/12`), and by the routes the site declares for it. With one,
it's reached through its own routes only (6.8).

### 8.2 The actions

`Actions.php` declares the class `Actions` of the run's namespace,
extending `nyu\App\Actionset`. Each of its public methods is an action,
reached by its name; the framework's methods, static ones and protected
ones aren't.

```php
<?php

namespace nyu\Runs\Notes;

use nyu\App\Actionset;

class Actions extends Actionset {

	protected $dfltAction = 'index';

	/**
	 * Lists the notes, those whose name holds the text asked for.
	 */
	public function index(): Void {
		$noteLst = $this->modelStack->getModelObject('listing');
		$nameTxt = strval( $this->inputsRgst->obtainDefValue('name', '') );
		if( $nameTxt!='' ){
			$noteLst->replaceFilters( Array('noteName~'=>'*'.$nameTxt.'*') );
		}
		$this->statusCode = 200;
		$this->replyValue = $noteLst->readRowsOfList();
	}

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

- **`$dfltAction`** names the action that runs when the path names none;
  without it, the first action declared runs.
- **`setupForAction()`**, when the run declares it, runs before every
  action: a check the whole run shares (a role, a setting) goes there,
  and it can stop the request with `stopThisAction()`.
- **The parameter** of an action is the part of the path the `pathForm`
  names `$argument` (the third in the framework's presets:
  `notes/view/12`), or the first value a declared route captures. An
  action that needs one declares it; one that takes none ignores it.
- An action follows the project's conventions (chapter 12), apart from its
  name, which is the word of the path. The delivered runs name theirs with
  8 characters; a project's own runs are free to choose.

### 8.3 What an action is given

| Property | Holds |
|---|---|
| `$this->inputsRgst` | the inputs, defined by `input/<action>.json`: `obtainDefValue('name', default)`, `readLinesArray()` |
| `$this->modelStack` | the models of the run: `getModelObject('name')` |
| `$this->accessRgst` | the access result: `loggedIn`, `userName`, `userRole`, `identity` (what the store says about the user), `appsName` for an application's call |
| `$this->requestObj` | the request: `usedMethodFlag()`, `retrieveHeader()`, `retrieveCookie()`, `getUploadFiles()`, `retrieveInFile()`, `getServerValue()`, `retrieveClient()` |
| `$this->respondObj` | the response, for its headers |
| `$this->routingObj` | the router, for links to the declared routes |
| `$this->runContext` | the `RunContext` all of these come from, read only |

The inputs are the only values sent by a client that an action should use
as data. Server values, and what the client says about itself, are read
through the request, and never decide an access (2.8).

### 8.4 Replying

An action sets three properties:

- **`statusCode`**: the status of the reply (200 when it sets none);
- **`statusMssg`**: the message of an error status;
- **`replyValue`**: the data of the reply, written in the preset's format:
  an array, a record, a list, a text, a number.

The actionset's helpers:

| Helper | Does |
|---|---|
| `stopThisAction($code, $message, $output)` | stops the action at once, from itself or a helper, with that status |
| `serveFileReply($path, $type)` | replies with a file on the server, in parts when `Range` asks |
| `answerPageFlag($name, $values)` | replies with `pages/<name>.html`, its `%name%` placeholders filled and encoded |
| `redirectClient($address, $code)` | redirects to a path of the site or an `http(s)` address |
| `routLinkString($route, $values, $query)` | the link to a declared route |
| `useReplyFormat($format)` | another format for this reply: `jsonData`, `yamlData`, `xmlNodes`, `csvTable`, `htmlView`, `rawBytes` |
| `getLoggerOfApp()` | the run's log, on its own channel |
| `needsActionRle($role)` | stops the action with a 404 unless the user holds the role |
| `holdsActionRle($role)` | tells whether the user holds the role |

An exception the action doesn't catch is a 500, logged with its trace
(4.7); in production, the client is told nothing more.

### 8.5 Models

A model is a record or a list of a table, declared once and fetched by
name. `mods/SingleModel.php` declares the class `Single` of
`nyu\Runs\Notes\mods`, fetched as `getModelObject('single')`:

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
  tables. It's loaded by its key (`loadRecordList()`) or found by its
  fields (`findRecordList()`), with its relations loaded along; it's
  filled (`bindFieldArray()`), saved (`insertOrUpdate()`) and removed
  (`removeRecdFlag()`).
- **A list** (`DBLst`) names its table. It's filtered (`replaceFilters()`),
  its fields chosen (`pickReadFields()`), grouped (`setGroupFields()`),
  sorted (`setOrderFields()`), limited (`setLimitOffset()`), joined
  (`joinOtherTable()`), then read (`readRowsOfList()`).
- A model can name another database than the default one.
- The framework's tables follow the naming of chapter 10: columns of 10
  characters, `id_<table>` for the identifier, `<table>_id` for a link.

The models of `runs/Shared` are given to every run that has none of that
name. A model is made the first time an action asks for it, then kept for
the request: a run only pays for the models its action uses.

### 8.6 Inputs

Each action declares its inputs in `input/<action>.json` (6.9). An action
without a definition receives the inputs as the preset built them, so
declare every action, even one that takes none (`{ "fieldSet": {} }`).

### 8.7 The runs delivered

| Run | Holds |
|---|---|
| `Auth` | signing in and out, activation, sign-ups, forgotten passwords, changing a password or a language, the published keys: `login`, `logout`, `active`, `signup`, `forgot`, `recover`, `setpass`, `usrlang`, `keyset` |
| `Users` | managing the users, for a manager: `index`, `view`, `create`, `approve`, `setmail`, `langs`, `lock`, `unlock`, `role`, `remove`, `reset` |
| `Shows` | an example: a list of shows filtered, sorted and limited (`index`), and one show with its events and items (`view`) |
| `Shared` | empty: the models and plug-ins the runs share |

`Auth` declares its routes in its own `routes.rte` (`/auth/login`, …,
and `/.well-known/jwks.json`). `Auth` and `Users` work on the store the
`loginSet` block names, and every action of `Users` asks for the
`managers` role itself.

---

## 9. Security

What the framework does to protect a project, in brief. The settings are
covered in [`HOSTING.md`](../HOSTING.md), the advice in chapter 2, and the
order of the checks in chapter 4.

### 9.1 Principles

- **Fail closed.** A configuration that can't be applied refuses every
  request with a 500 (`Access misconfigured`); it never falls back to a
  weaker check.
- **Tell the client nothing it doesn't need to know.** Whatever it can't
  reach is the same 404; a wrong name and a wrong password get the same
  refusal; the reply to a sign-up or a forgotten password is the same
  whoever asks; production shows no detail of an error.
- **Trust only what the server reads.** The only inputs are those an
  action declares. What the client says about itself is a hint, never a
  reason to grant access. A client's address is read through the proxies
  the configuration trusts, and no others.
- **Keep secrets out of the files.** Secrets live in `.env`; keys, tokens
  and codes are only stored as hashes; a bundle carries none of them.
- **Split the duties.** The framework handles the request from the moment
  PHP receives it. The web server, TLS, the firewall and `php.ini` belong
  to the host ([`HOSTING.md` §1](../HOSTING.md#1-purpose-and-split-of-duties)).

### 9.2 Requests and inputs

| Risk | What the framework does | Covered in |
|---|---|---|
| a body too large to hold | refused with a 413 past `REQ_SIZE` or `REQ_PART`, before it's read | [`HOSTING.md` §11](../HOSTING.md#11-request-sizes) |
| values the action didn't expect | only the fields of `input/<action>.json` are kept, each in its format | 6.9 |
| a forged client address | forwarding headers are read only from the proxies of `PRX_LIST` | [`HOSTING.md` §3](../HOSTING.md#3-reverse-proxy) |
| a password in a link, kept in logs and histories | the `Auth` actions refuse a password sent in the query string (400) | |
| a filter value used as a pattern | compared as it is; a match is asked for through the field's name (`field~`) | 2.7 |
| SQL injection | the stores send values as parameters, and accept only names made of letters, digits and underscores | 10 |

### 9.3 Routes and replies

| Risk | What the framework does | Covered in |
|---|---|---|
| mapping what exists | one 404 `Not Found` for an unknown application or action, a closed route, a run reached by its plain path, a missing file, a role not held | 4.5 |
| leaving a folder | a file asked for in a folder never leaves it; only the extensions of `Response::TYPE_LIST` are served | [`HOSTING.md` §13](../HOSTING.md#13-routing-notes) |
| scripts in a page | the placeholders of pages and mails are HTML-encoded | 8.4 |
| a redirection elsewhere | a sign-in route must be a path of the site; `redirectClient()` takes a path or an `http(s)` address only | |
| content sniffing | `X-Content-Type-Options: nosniff` on every reply | 4.6 |
| calls from other sites | `Access-Control-Allow-Origin` names only an origin `corsList` lists | [`HOSTING.md` §10](../HOSTING.md#10-other-sites-calling-in) |

### 9.4 Credentials

- **Passwords** are hashed with bcrypt. An unknown name still costs one
  hash, so the time a refusal takes doesn't reveal which names exist. A
  password file accepts weak formats only when told to (`weakHash`,
  [`HOSTING.md` §6](../HOSTING.md#6-password-files)).
- **Accounts that aren't active** are refused just like a wrong password;
  the reason is in the log only.
- **Tokens** carry an expiry (`exp`) and a user, or they're refused; once
  set, their issuer (`iss`) and audience (`aud`) are checked too. RS256
  keys under 2048 bits are refused, and a token names its key (`kid`)
  ([`HOSTING.md` §8](../HOSTING.md#8-tokens)).
- **Sessions** end after `lifeSecs` unused or `fullSecs` in all. A login
  gets a new session id and closes the one it came with, and the cookie is
  `HttpOnly`, `Secure` and `SameSite` as set
  ([`HOSTING.md` §9](../HOSTING.md#9-php-sessions)).
- **Two-factor codes** are accepted once each, and their secrets are
  stored encrypted with `tfaCrypt`
  ([`HOSTING.md` §7](../HOSTING.md#7-two-factor-codes)). A code mailed for
  a login goes out once a minute at most.
- **Keys, personal tokens, device tokens, application keys, activation
  and reset codes** are shown once, stored as hashes, and compared by
  their hash.
- **Codes sent by mail** last `codeMins` minutes, and a reset keeps the
  password until its code is used
  ([`HOSTING.md` §15](../HOSTING.md#15-access-mails)).
- **Mail** is sent encrypted, with the server's certificate verified.

### 9.5 Attempts

The limit by address (`tryLimit`) counts wrong passwords, keys and codes,
forged tokens, and every sign-up and reset asked for: past `maxTries`,
the address is held for `holdSecs` seconds and gets a 429. An expired
token, a missing credential or a role not held aren't counted. The stores
that check passwords (`nativeDb`, `userFile`, `htpasswd`) also hold an
account after its own `maxTries` wrong passwords. A client that already
signed in successfully carries a login mark, signed with `markSecr`, and
still gets in while its account is held, so an attacker can't lock the
owner out. Sign-ups are bounded per client and in
all ([`HOSTING.md` §5](../HOSTING.md#5-limit-by-address-and-account-holds)).

### 9.6 Errors and logs

- In production, nothing of an error is shown, whatever `ERR_SHOW` says.
- A trace is logged without the arguments of its calls, so no password
  ends up in a log.
- Names given by a client are written in the logs as JSON strings, so no
  line can be forged.
- Users holding `advanced` or `managers` are traced on every access, and
  every refusal is traced with its reason
  ([`HOSTING.md` §12](../HOSTING.md#12-errors-and-logs)).

### 9.7 Shipping

A bundle only copies what `Bundle::KEEP_LIST` names: no `.env`, no local
configuration, no key, no test (7.6). `php nyuRun installs checkAll`
fails on what would expose a server or stop it (a secret or a 2FA key too
short, a proxy list open to all, access settings or a preset that can't
be applied, token keys that can't be used or don't pair up), and warns on
what deserves a look (errors shown on a preview server, a PHP release
without its latest security fixes, a password file accepting weak hashes,
origins open to all, tokens bound to no issuer or audience).

### 9.8 Left to the host, or to later

- The web server, TLS, the firewall and `php.ini`
  ([`HOSTING.md`](../HOSTING.md)).
- The forwarded host (`X-Forwarded-Host`) isn't read.
- Client certificates whose TLS a proxy handles, certificate pinning, and
  limits by route are left for version 1.1 (1.5).

---

## 10. Data layer

A run reaches its data through models, records and lists (8.5), never
through queries. This chapter describes what lies underneath.

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
it's used, and kept for the request: `DBCon::linkNamedDbCon('name')` gives
it, and `''` gives the default database. A model names another database
than the default one through its connection.

Each SQL type has its handler, on PDO, which writes the conditions in its
own dialect: MySQL (MariaDB included), PostgreSQL and SQLite. The SQLite
handler turns foreign keys on for each connection, as the other databases
have them. MongoDB is a store of its own (10.8).

### 10.2 Naming

The framework's tables follow one naming scheme, which records and lists
rely on to find their relations without being told:

| Element | Rule | Example |
|---|---|---|
| table | a plural name | `shows`, `events` |
| column | 10 characters | `systemName`, `createDate` |
| identifier | `id_` and the table's name in the singular | `id_show` |
| link to another table (one to many) | that table's name in the singular, and `_id` | `show_id` |
| link to a parent in the same table | `parent_id` | |

The column length is the framework's convention: a project's own tables
may use other lengths, as long as the identifiers and links follow the
naming. A relation can always name its fields when a table doesn't follow
it.

### 10.3 Records

A record (`DBRec`) is bound to its table when it's created: its key and
fields are read from the store, once per table and request, or from the
config cache with `TBL_CACH`.

- **Loading**: `loadRecordList($key)` by its key, `findRecordList($filter)`
  for the first that matches a filter (10.5). Both give the record's
  fields, or `false`, and load its declared relations.
- **Relations**: the child tables a record declares are loaded with it,
  each under its table's name with a capital first letter (`Events`); a
  parent, under its singular name (`Show`). A child can be declared with
  its sort order.
- **Saving**: `bindFieldArray($values)` takes the values of the fields the
  table holds and leaves the others. `insertOrUpdate()` updates the row of
  the key, or inserts a new one, with the key given or made by the
  database. The key itself is never updated. It gives back the key, or
  `false`: as the database gave it for an update, as a number when the
  database numbered it, or as the application bound it otherwise.
- **Removing**: `removeRecdFlag($children)` removes the record and the rows
  of the child tables named in the call, in one transaction, and nothing
  else. The removal is traced on `runner`, with the user and address of
  the request. A database without transactions removes a record alone,
  and refuses a removal that names children.

### 10.4 Lists

A list (`DBLst`) is bound to its table, set up, then read:

| Method | Sets |
|---|---|
| `replaceFilters($filter)` | the filter (10.5), in place of the previous one |
| `pickReadFields('a, b, count(*) as n')` | the fields read, and functions on them, with aliases |
| `setGroupFields($fields)` | the fields the rows are grouped by |
| `setOrderFields($fields)` | the order; a field written `^name` is sorted descending |
| `setLimitOffset($count)` | how many rows, from a count or `"count,offset"`; `DEF_ROWS` when the count isn't a number |
| `joinOtherTable([table, field, field])` | one table joined (`LEFT JOIN`), SQL only |
| `readRowsOfList()` | reads the rows; `flatItemsArray('field')` reads one field of each |
| `attachChildren($table)` | attaches to each row read its rows in a child table |

### 10.5 Filters

A filter is an array of field names and values. It's read the same way
for every database (`DBStore\Filter`), and each store writes it in its
own language:

| Written | Selects the rows where the field |
|---|---|
| `'field'=>value` or `'field='=>value` | equals the value |
| `'field!'=>value` | differs from it |
| `'field<'`, `'field<='`, `'field>'`, `'field>='` | is below or above it |
| `'field'=>[a, b]`, `'field!'=>[a, b]` | is one of the values, or none of them |
| `'field~'=>'*text*'`, `'field!~'=>'*text*'` | matches the text, or doesn't; `*` is any text |
| `'field?'=>false`, `'field?'=>true` | holds no value, or holds one |

A row must meet every condition of the filter. A value is compared as it
is: none of its characters is a wildcard, so a value taken from a request
can't widen a search. Only a field named with `~` matches, and only `*`
is a wildcard there. A `null` value matches nothing, so an empty input
can't select the rows left unset. A field name is letters, digits and
underscores, with an optional table (`shows.title`); anything else is
refused.

### 10.6 Transactions

The store of a database tells whether it has transactions
(`canTransaction()`), and opens (`newTransaction()`), commits
(`setTransaction()`) or cancels (`delTransaction()`) one. An action
reaches them through the connection:

```php
$dbStore = DBCon::linkNamedDbCon()->getStoreObject();
$dbStore->newTransaction();
// records saved and removed
$dbStore->setTransaction();
```

The SQL types have transactions; MongoDB, as nyuRuns uses it, has none.

### 10.7 Binary values

An image, a sound or any file is stored in a field as a binary value:

- it's written from a flux (`Data\Fluxd`) given as the field's value: a
  file on the server, the request body, bytes in memory. It's read piece
  by piece, never held whole;
- it's read back as a flux, only when the field is named among the fields
  asked for: a read that names none leaves it out;
- an action sends it as it is with the `rawBytes` reply format.

### 10.8 MongoDB

MongoDB is reached through PHP's `mongodb` extension, as the store
`MongoStore`. A table is a collection, a row a document, and every table's
key is the `_id` MongoDB gives. What differs from the SQL types:

- a collection has no fixed list of fields;
- values are compared with their type: the text `'2'` isn't the number
  `2`;
- functions, joins and groups are refused;
- there are no transactions (they would need a replica set), so a record
  is removed alone, and naming its children in the removal is refused.

### 10.9 Delivered tables

`conf/schema/` holds the tables the framework uses, one file per table and
database type: `users` and `devices`, in `.mysql_db.sql` (MariaDB
included), `.postgres.sql` and `.sqlitedb.sql`. `php nyuRun installs
userBase` creates them; a table that exists is left as it is. The files
`migrate-<table>-<version>.<type>.sql` move a table made by an earlier
version, renaming its columns when needed: they're applied by hand, after
a backup.

### 10.10 Identifiers that travel

A record copied to another database or another application keeps its
identity with a UUID of version 7 (`Tools\UniqueId`, RFC 9562). It starts
with the time it was made, so the identifiers sort in that order, and ends
with random bits. In 1.0 the framework makes them, but doesn't give one
to a record on its own: a table that needs one holds it in a field of its
own, next to its key, filled by the action that creates the record.

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
    routes/               the site's declared routes (.rte), a folder per preset that has its own
    schema/               the delivered tables and their migrations (.sql)
    mails/<language>/     the templates of the access mails
    keys/                 the token keys (local)
  pages/                  the pages and static files of the sites (dispatch.fileRoot)
  media/                  the project's media files, kept out of git
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
| the token keys | `conf/keys/` | no | no, unless a mode's `addPaths` names a file |
| the modes of the project's bundles | `conf/bundle.lst` | yes | no |
| the libraries | `vendor/` | no | yes |
| what the framework writes | `temp/` | no | no |
| the tests, the documents | `test/`, `docs/` | yes | no |
| the documentation site and the code reference | `docs/site/`, made by `.phpdoc/build.ps1` | no | no |
| the bundles | `dist/` | no | |

A bundle only copies the paths of `Bundle::KEEP_LIST` (`src`, `runs`,
`www`, `pages`, `conf`, `vendor`, the Composer files, `nyuRun`, `LICENSE`,
`NOTICE`, `.htaccess`), minus those of `DROP_LIST` (the local
configuration, every file of `conf/keys`, `bundle.lst`), plus the paths
its mode adds. Each server makes its own keys with `installs allSteps`.

### 11.3 `temp/`

| Folder | Holds | Removed by |
|---|---|---|
| `logs/` | the logs, one file per channel (`LOG_PATH`) | `maintain cleanDir logFiles` |
| `limits/` | the counts of the limit by address (`tryLimit.dataPath`) | `maintain cleanDir tryLimit`, `maintain freeAddr` |
| `archives/` | the configuration files as they were before a command changed them | `maintain cleanDir archives` |
| `cache/` | the config cache, one file per preset (`CNF_CACH`) | `maintain cleanDir cacheDir`, `settings clrCache`, any command that writes in `conf/` |
| `mails/` | the mails of an `emlFiles` account | by hand |

Only the user the web server runs as needs to write there (3.7).

### 11.4 A run

```
runs/<App>/
  Actions.php             the actions, class Actions of nyu\Runs\<App>
  mods/<Name>Model.php    the models, class <Name> of nyu\Runs\<App>\mods
  input/<action>.json     the inputs of each action
  routes.rte              its own routes
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

nyuRuns follows one set of conventions, in the framework and in the runs
it ships. A contribution follows them too, and the `audit` test group
checks most of them (12.5).

### 12.1 Names of fixed length

Every name is `camelCase` and has the length of its kind, so a name tells
at a glance what it is. The lengths leave room for whole short words
rather than one word squeezed down. Classes are the exception:
`PascalCase`, any length.

| Element | Length | Example |
|---|---|---|
| method | 14 | `stopThisAction()`, `getModelObject()` |
| parameter | 12 | `$requiredRole`, `$appNameToRun` |
| property | 10 | `$requestObj`, `$modelStack` |
| local variable | 7 | `$endDate`, `$noteLst` |
| loop variable | 6 | `$oneRow`, `$rowIdx` |
| constant | 9, in the form `XXXX_YYYY` | `KEYS_LIST`, `TYPE_LIST` |
| action of a delivered run | 8 | `login`, `view` |

Exempt from the lengths:

- the actions in a project's own runs (`index`, `view`, `card`): their
  name is the word of the path, and the audit leaves every method of
  `runs/*/Actions.php` alone;
- the PSR-3 methods of `Tools\Logger` (`error`, `warning`, `notice`,
  `info`, `debug`) and its level constants (`LEVL_ERROR`, …);
- the variable of a `catch`, `$e`;
- PHP's magic methods, and the methods a PHP interface imposes
  (`jsonSerialize`, `getIterator`, `count`, …);
- standard names, whatever their length: HTTP methods, file extensions,
  MIME types, `E_*` names, header names;
- for now, the loop variables of 3 letters listed in
  `test/audit/looplabels.txt`.

A name is built in this order, stopping at the first rule that gives the
length:

1. whole words, if they fit exactly (`stopThisAction`);
2. too long: shorten the verb before the noun, since what a name works on
   matters more than what it does;
3. shorten a noun only with its usual short form, by dropping inner vowels
   (`Rqst`, `Mssg`, `Mthd`); letters are never swapped or replaced;
4. too short: add a word that says more, the type of the value, or the
   word of its owner (`fetchUserArray`, `$requiredRole`), never filler.

A name says the role first, then what the value is (`List`, `Arr`, `Obj`,
`Text`, `Flag`, `Numb`…). A name already chosen for one concept is reused
for it everywhere (`$requestObj` is the request in every class).

The same rule holds beyond the code:

| Element | Rule | Example |
|---|---|---|
| keys of a configuration block | 8, in the JSON files and in a route target | `carrWith`, `appToRun` |
| keys of `system.ini` | 8, in the form `XXX_YYYY`; codes among its values are 4, in capitals | `LOG_MODE`, `DEVS`, `INDX` |
| codes made at run time | 8; in lower case when they name a file (a preset, a mail kind, a database type, a bundle mode) | `standard`, `advanced`, `delegate`, `managers` |
| columns of a table | 10 | `systemName` |
| commands and sub-commands of `php nyuRun` | 8 | `installs allSteps`, `maintain cleanDir` |
| options of `php nyuRun` | 8 | `--noPrompt`, `--yesToAll` |
| formats of `Tools\Variable` | 7, so each `formats…()` method is 14 | `OutText`, `formatsOutText()` |

### 12.2 Types

- Every parameter and every return value is typed, the types written with
  a capital: `String`, `Array`, `Bool`, `Int`, `Float`, `Mixed`, `Void`,
  `Never`.
- A value of several types gets its precise union (`Array|Bool`,
  `?String`); `Mixed` is kept for what can really be anything.
- A parameter whose default is `null` is declared nullable
  (`?String $userNameText=null`).
- `declare(strict_types=1)` isn't used.

### 12.3 Documentation

- Every public method has a docblock: what it does, then `@param` for each
  parameter, `@return`, and `@throws` for what it lets through.
- A class opens with a docblock saying what it is and how it's used.
- A comment says why, not what, and is written in English, like the names.
- The code reference is built from the docblocks by phpDocumentor
  (`phpdoc.dist.xml`), in `docs/site/reference`. `pwsh .phpdoc/build.ps1`
  builds it along with the pages of these documents, written by the
  framework's own Markdown parser and HTML writer (`.phpdoc/site`): one
  site in `docs/site`, which git doesn't track.

### 12.4 Errors

- An error of the framework is a `RuntimeException`, or a class that
  extends it (`StopRun`, `ShellStop`, `ParseError`, `WriteError`,
  `MailFailure`, `QrCodeError`).
- A message says what went wrong in words a developer reads in a log. It
  never holds a secret, and names given by a client are written as JSON
  strings.
- What a client may be told is decided by the status, not by the message
  of an exception (4.7).

### 12.5 The audit

`php test/run.php audit` reads `src/` and `runs/` with PHP's tokenizer,
and fails on:

- a file that doesn't parse;
- a class that isn't `PascalCase`, or a constant that isn't `XXXX_YYYY`;
- a method, parameter, property, local or loop variable that doesn't have
  its length;
- a public method without a docblock;
- a parameter or a return value without a type.

`php test/audit/ConventionTest.php --list` prints every finding, the
accepted ones included. The lengths of configuration keys, columns and
codes aren't audited: they're checked when a change is reviewed.

### 12.6 Contributions

A contribution is signed off under the Developer Certificate of Origin
(`git commit -s`), brings its tests, and passes the suite and the audit,
as [`CONTRIBUTING.md`](../CONTRIBUTING.md) explains. A new or renamed name
is proposed before it's written, since it changes how the code reads.

---

## 13. Testing

The suite is made of self-checking PHP scripts, with no dependency beyond
PHP and its extensions: there's no test framework to install. It holds
about 4,500 checks, and [`test/README.md`](../test/README.md) has the
details.

### 13.1 Running it

```
php test/run.php                 every group
php test/run.php unit audit      the named groups
php test/run.php Variable        the scripts whose name holds the word
php nyuRun runTests              unit, audit and data, from the command line
```

Each `*Test.php` runs in its own PHP process, and can be run alone
(`php test/unit/VariableTest.php`). The runner prints each script, then
the total of checks passed, failed and skipped, and exits with a failure
code when a check fails.

### 13.2 The groups

| Group | Scripts | Checks |
|---|---|---|
| `unit` | 88 | each part on its own: formats, request, router, access, carriers and checks, tokens, limits, logs, response, error handler, documents, images, sounds, mail, the command line |
| `data` | 24 | the databases: handlers, stores, filters, records, lists, the userbase, the devices, the moves between stores, the runs that use them; on temporary SQLite databases, and on real servers when they're configured |
| `http` | 1 | whole requests through PHP's built-in web server: statuses, headers, bodies, routes, access, pages, files |
| `audit` | 1 | the coding conventions across `src/` and `runs/` (12.5) |

### 13.3 What the tests need

- **PHP and its extensions.** The `data` and `http` groups need
  `pdo_sqlite`, and skip themselves without it; the 2FA checks need
  `openssl`; images, sounds and documents skip what their extension
  (`gd`, `imagick`, `zip`) or ffmpeg doesn't allow. A command-line PHP
  without a `php.ini` gets its extensions through `PHPRC`, pointing at a
  folder whose `php.ini` lists them: the runner and the `http` test start
  other PHP processes, which receive no `-d` option.
- **Real database servers, optionally.** `MySQLTest`, `PgSQLTest` and
  `MongoDBTest` run the same checks on real servers, from the
  `NYU_TEST_*` names of `.env`. Each works in a database of its own whose
  name holds `test` (`nyuruns_test`), refuses any other, and skips itself
  when its server isn't configured or doesn't answer.
- **Nothing outside the project.** The tests write in the system's
  temporary folder or in their own folders; the `http` group writes in
  the project's `temp/logs`. A test that counts wrong credentials sets its
  own limits, so the project's `temp/limits` is left alone.

### 13.4 Writing a test

```php
<?php
require __DIR__.'/../lib/harness.php';

checkIdentical('label', $actual, $expected);          // strict comparison
checkTrueValue('label', $condition);
checkPregMatch('label', $text, '/pattern/');
checkForThrows('label', function(){ ... }, 'RuntimeException');

finishChecking();
```

- A script lives in `test/<group>/<Name>Test.php`, and follows the
  conventions of chapter 12.
- A notice or a warning raised by the code under test fails the script,
  unless the test collects it (`takePhpNotices()`).
- `recordSkipping()` says why a check can't run here; `inTemporaryDir()`
  gives a path in the temporary folder; `mockRequestObj()` and
  `makeFakeServer()` build a request without a web server.
- The fixtures (configurations, routes, pages, a test run named `Probe`,
  a users file, documents) live in `test/fixtures`.
- A test that reads the logs plugs in its own folder
  (`Logger::plugLogsConfig()`), and unplugs it at the end.

### 13.5 Before a release

A version is closed by a full pass of the suite: every group, `http`
included, on the lowest supported PHP release (8.2) and the latest, with
the SQL servers the version supports (chapter 3) and MongoDB.

---
