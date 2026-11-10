# nyuRuns — Project Requirements

Version 1.0

## Summary

1. [Overview](#1-overview)
2. [Aims and objectives](#2-aims-and-objectives)
3. [Applied principles](#3-applied-principles)
4. [Target audience](#4-target-audience)
5. [Basic features](#5-basic-features)
6. [Detailed requirements](#6-detailed-requirements)
7. [Performance goals](#7-performance-goals)
8. [Additional notes](#8-additional-notes)

---

## 1. Overview

### 1.1 The project

nyuRuns is a micro-framework for building and deploying RESTful API
services. It lets programmers and teams develop and ship the complete back
end of an application.

Its main goal is to provide a base for data-model-driven components, so
developers can focus on the business they're building. The usual
operations on data (create, read, update, delete and list) are given to
every data source, in a configurable way, so any programmer can build web
services on top of a dataset or a set of models. Once a few settings are
in place, a service's users can list, read, write and delete the items of
a data source, usually a database. Other sources, such as files (XML,
JSON, INI and plain text), can be brought in along the way.

The framework is meant to handle the whole HTTP request, cutting down the
work that handling it involves. It also comes with built-in tools that
make development simpler: routing for every incoming request, with
fallbacks; access control and tokens for security; and data handlers for
databases, files, images and sockets. Output comes with cache features and
error handling. In the end, nyuRuns gives developers a multi-tier
architecture for web services, and a base to build back-end APIs in no
time.

### 1.2 The ambition

nyuRuns aims to be as efficient as the micro-frameworks, while offering
the key features, and some of the capabilities, of the heavier ones. It's
meant to build on modern technologies, and to be a complete tool, out of
the box.

### 1.3 What this document covers

This document states what nyuRuns must achieve, and why: its aims, the
principles it applies, who it's for, its features, its detailed
requirements and its performance goals. For each requirement, it says
where version 1.0 stands: met, partly met, left for version 1.1, moved to
nyuTron (the separate project of tools around nyuRuns), or dropped.

What nyuRuns does is covered in the
[Functional Specifications](funcspecs.md), how it's built in the
[Technical Specifications](techspecs.md), and how to use it in the
[User Guide](userguide.md).

---

## 2. Aims and objectives

### 2.1 Aims

The purpose of nyuRuns is to make building APIs simpler by splitting up
the work they take. Complex applications can be built on it, but its
first goal is to keep things simple.

1. **Divide the work.** The framework handles the request, and the
   developer writes only what the business needs.
2. **Keep it simple.** A service starts small, loads only what it uses,
   and grows without being rewritten.
3. **Work through models.** An application reaches its data through
   models of it, whatever the source.
4. **Be efficient.** A request costs no more than with a micro-framework.
5. **Be complete.** The key features of the heavier frameworks, and some
   of their capabilities, come out of the box, built on modern
   technologies.

### 2.2 Objectives

Each aim leads to objectives that can be checked:

| # | Objective | Aim | 1.0 |
|---|---|---|---|
| O1 | An application declares its actions, their inputs and their URLs; it has no code that reads a request, checks access or writes a reply | 1 | Met |
| O2 | Every data source gets create, read, update, delete and list, through the same models | 3 | Met for databases |
| O3 | A table is served by settings alone, with no action written for it | 1, 3 | Moved to nyuTron (its scaffolding writes those actions) |
| O4 | Files are a data source just like databases, set up in the settings | 3 | Version 1.1; for now, files are read and written as documents by an action |
| O5 | A first install takes one command and needs no database | 2 | Met |
| O6 | A service moves from a users file to a database, from one database to several, and from one application to several working together, without rewriting its code | 2 | Met |
| O7 | A request costs no more CPU than with the usual micro-frameworks, and well below the full-stack ones | 4 | Met (chapter 7), measured before the 9 November changes |
| O8 | Signing in, roles, limits on attempts, two-factor codes, signed tokens and the account life cycle come with the framework, with no library to add | 5 | Met |
| O9 | Documents, images, sounds and mail are read, written and sent by the framework itself | 5 | Met |
| O10 | A project is installed, configured, checked, tested and shipped from one command line | 5 | Met |
| O11 | The framework rests on current standards: PHP 8.2 and later, signed tokens (RS256), one-time codes (RFC 6238), version 7 identifiers (RFC 9562), today's office formats | 5 | Met |
| O12 | Errors reach the client in the service's format, and never in detail in production | 1 | Met |

The objectives that 1.0 doesn't fully meet come up again, requirement by
requirement, in chapter 6.

---

## 3. Applied principles

Four principles guide every choice in nyuRuns. Each one is stated here
with what it commits the framework to. How a service shows them is in the
[Functional Specifications, 3.1](funcspecs.md#31-the-principles-as-a-service-shows-them).

### 3.1 MDD: model-driven development

An application is described by its data models, and works through them.

- An application reads and writes its data through models (records and
  lists), never through queries it writes itself.
- A model is declared once, by the table it stands for: its key, fields
  and relations are worked out from the table and its naming.
- Changing the database, or the kind of database, leaves the applications
  untouched, within what that kind of database can do.
- The tools around the framework (nyuTron) build on the models: the
  scaffolding writes a model's actions, so a table can be served without
  hand-written code.

### 3.2 KISS: keep it simple

What a service doesn't need is neither installed, loaded nor set up.

- A first install takes one command and no database.
- A request loads only what it uses: a request that reaches no database
  opens none, and a model is only made when an action asks for it.
- Settings are plain files, changed one value at a time, with each value
  checked as it's written.
- Each part does one thing, and a project can add its own (a credential
  check, a carrier, a command) next to the framework's, without changing
  them.
- A service grows by adding, never by rewriting what it has.

### 3.3 REST

Every resource has its own URL, the request's method says what's done
with it, and the reply's status says how it went.

- The method decides the action: a route can give each method of a URL
  its own action.
- The status is always the one HTTP gives for the case (201 for something
  created, 404 for something not found, 409 for a conflict, …), whatever
  the format of the body.
- A request carries everything the service needs to answer it: a token or
  a key, never a state kept between two requests, except the session a
  site chooses to use.
- A client is told what it needs and nothing more: what it may not reach
  is "not found", just like what doesn't exist.

### 3.4 CRUDL: create, read, update, delete, list

Every data source gets the same five operations, written the same way.

- A record is created, read, updated and deleted; a list is read,
  filtered, sorted, limited and joined.
- A list's filter is written the same way for every kind of database, and
  a value from a request is never read as a pattern.
- The original vision called these operations CRUDI, the I standing for a
  list's index. CRUDL is the name that stuck.

### 3.5 Rules that follow from them

The principles lead to a few rules that hold throughout the framework:

- **Declared, not coded**: routes, inputs, roles and settings are
  declared in files, so you can tell what a service does without reading
  its code. Each run declares its own routes, next to its actions.
- **Closed by default**: in a profile that asks for credentials, every
  route needs one unless it's opened on purpose, and a setting that can't
  be applied refuses every request.
- **One naming**: every name in the code, the settings and the tables has
  the length of its kind, so a name tells you what it is
  ([Technical Specifications, chapter 12](techspecs.md#12-coding-conventions)).

---

## 4. Target audience

### 4.1 Who builds with nyuRuns

| Reader | Needs nyuRuns to |
|---|---|
| **A PHP developer** | write a back end without writing its plumbing: routing, access, inputs, replies, errors |
| **A team** | share one way of writing services: the same layout, naming and conventions in every project, checked by the tests |
| **A small company, or its provider** | run its own back end on an ordinary PHP host, without a heavy stack to install, monitor and update |
| **An analyst or a product owner** | know what a service will do, and what each user can reach, from its specifications and settings rather than its code |
| **The person who hosts it** | install, check and update a project from one command line, with what the server must provide listed in one place |

nyuRuns expects its developers to know PHP and HTTP. It needs no template
language, its models come down to a handful of methods, and its container
stays out of the applications' way.

### 4.2 What gets built with it

- **Business back ends**: a company's records (members, contacts,
  orders, tasks, documents) and the people who work on them, with their
  roles.
- **APIs for front ends**: a web or mobile app, written in any language,
  talking to the service in JSON.
- **Services working together**: several services, each owning its data,
  with one of them signing users in for the others, and all of them
  calling each other with keys.
- **Services for machines**: devices and programs calling with their own
  credentials, with no one signing in.
- **Light sites**: pages and files served next to an API, without a
  content management system.

### 4.3 What it's not for

- **Page-first sites**, with templates, forms and a theme: nyuRuns serves
  pages, but it's built for data and APIs.
- **Real-time exchanges** (chats, live feeds): every exchange is a request
  and its reply, and sockets are left for version 1.1.
- **A service that must handle very heavy load on one server, without
  measurements of its own**: nyuRuns was measured request by request, not
  under load (chapter 7).

---

## 5. Basic features

The features nyuRuns is meant to have, by area. **1.0**: in version 1.0;
**1.1**: left for version 1.1; **nyuTron**: in the separate project of
tools around nyuRuns; **dropped**: abandoned.

### 5.1 Requests and replies

| Feature | Status |
|---|---|
| The whole request handled: route, access, inputs, action, reply | 1.0 |
| Inputs kept by each action's definition, each in its format; required fields | 1.0 |
| Replies in JSON, YAML, XML, CSV, HTML, or raw | 1.0 |
| Errors in the reply's format, logged, never detailed in production | 1.0 |
| Limits on request size | 1.0 |
| Calls from other sites (CORS), for the listed origins | 1.0 |
| Input encoding | 1.1 |
| Inputs from sources other than the request: files, records, other APIs, the session, a stream | 1.1 |
| Sockets | 1.1 |

### 5.2 Routing

| Feature | Status |
|---|---|
| Routes found from the path, or declared in route files, by the site or by each run | 1.0 |
| Runs mounted where the profile says, or not served | 1.0 |
| One action per method of a URL | 1.0 |
| Redirections, static files and folders, HTML pages | 1.0 |
| A site's own error pages | 1.0 |
| Static files cached by clients (version and date, 304) | 1.0 |
| Data replies cached | not planned yet |

### 5.3 Access

| Feature | Status |
|---|---|
| Carriers: Basic authentication, API key in a header, signed token, PHP session, client certificate | 1.0 |
| Checks: password file, API keys, users file, users table, device tokens, a check of the project's own | 1.0 |
| Ranked roles, by route and by action | 1.0 |
| A limit on wrong attempts, by address and by account | 1.0 |
| Two-factor codes, from an authenticator or by mail | 1.0 |
| Tokens signed with a key pair (RS256), checked by other services | 1.0 |
| The tokens' issuer and audience, and the published public keys | 1.0 |
| Applications calling for their users, with a key | 1.0 |
| SSO and LDAP connectors | 1.1 |
| Codes by SMS as a second factor; a login approved from the phone | 1.1 |
| Client certificates presented through a proxy, and a profile for them; certificate pinning | 1.1 |
| Limits by route | 1.1 |
| Digest authentication and a cookie carrier | dropped |

### 5.4 Users

| Feature | Status |
|---|---|
| Users in a file or a database table, moved from one to the other | 1.0 |
| Accounts created, activated with a code, locked, reset, removed; roles | 1.0 |
| Passwords changed and reset by the users themselves | 1.0 |
| Sign-ups, confirmed by mail and approved by a manager | 1.0 |
| Access mails, in each user's language | 1.0 |
| Devices, with their own tokens | 1.0 |
| Users managed through an application and from the command line | 1.0 |

### 5.5 Data

| Feature | Status |
|---|---|
| Databases: MySQL, MariaDB, PostgreSQL, SQLite, MongoDB; several at once | 1.0 |
| Records and lists, with one filter for every kind of database | 1.0 |
| Relations: the children and the parent of a record | 1.0 |
| Transactions | 1.0 |
| Binary values, read and sent piece by piece | 1.0 |
| Identifiers that travel with a record (UUID version 7) | 1.0 |
| Several joins in one list | 1.1 |
| A table served by settings alone | nyuTron (scaffolding) |

### 5.6 Documents and media

| Feature | Status |
|---|---|
| Documents read and written: text, Markdown, HTML, XML, JSON, YAML, INI (read), CSV and TSV, iCalendar, PDF, Word, Excel, OpenDocument, ePub | 1.0 |
| Images: read, resized, cropped, rotated, stripped; drawings turned into images | 1.0 |
| Sounds: read, tags removed, cut; converted through ffmpeg | 1.0 |
| QR codes | 1.0 |
| Rich text and source code read as documents | 1.1 |
| Handwriting and scanned text read | 1.1 |
| Sounds mixed: waveform, loudness, fades, tempo | 1.1 |

### 5.7 Mail

| Feature | Status |
|---|---|
| Mail accounts sending through SMTP, through PHPMailer, or into files | 1.0 |
| Mails written from templates, in several languages | 1.0 |

### 5.8 Command line and tools

| Feature | Status |
|---|---|
| Installation and health check of a project | 1.0 |
| Settings read and changed, secrets made | 1.0 |
| Users, devices and application keys managed | 1.0 |
| A copy of the project built for each environment | 1.0 |
| The test suite and the audit of the conventions | 1.0 |
| Scaffolding of models and code | nyuTron |
| An installation wizard | nyuTron |
| A manager of advanced settings | nyuTron |
| Ready-made data kits: members, clients, orders, tasks, projects | nyuTron |

---

## 6. Detailed requirements

Each requirement is a rule the framework keeps. "Met in" names the
section that explains how: **TS** for the
[Technical Specifications](techspecs.md), **FS** for the
[Functional Specifications](funcspecs.md).

### 6.1 Requests and inputs

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-01 | Every request goes through one entry, in one sequence: route, access, inputs, action, reply | 1.0 | TS 4 |
| R-02 | A body larger than the size set, or holding more parts than set, is refused (413) before it is read | 1.0 | TS 4.2 |
| R-03 | An action receives only the inputs its definition lists, each in its format; a required one missing refuses the request (400) before the action runs | 1.0 | TS 6.9, FS 5.8 |
| R-04 | Server and client values are never inputs; what a client tells of itself never grants access | 1.0 | TS 9.2 |
| R-05 | A value from a request used in a filter is compared as it is, never read as a pattern | 1.0 | TS 10.5 |
| R-06 | Inputs come from other sources than the request: files, records, other APIs, the session, a stream | 1.1 | |
| R-07 | The encoding of the inputs is set, and converted | 1.1 | |

### 6.2 Routing and replies

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-08 | A route is found from the path, or from the routes the site or each run declares (a run's mounted where the profile says); a declared route names an action for each method | 1.0 | TS 4.3 |
| R-09 | A declared route redirects, or serves a file, a folder or a page; a file never leaves its folder | 1.0 | TS 6.8, TS 9.3 |
| R-10 | Whatever a client can't reach gets the same reply, 404, whether it doesn't exist or can't be reached | 1.0 | TS 4.5 |
| R-11 | A reply is written in the format of the profile, or of the action: JSON, YAML, XML, CSV, HTML, raw | 1.0 | TS 4.6 |
| R-12 | An error is written in the format of the reply, logged, and never detailed in production | 1.0 | TS 4.7 |
| R-13 | Every reply forbids content sniffing; other sites call from a browser only from the origins listed | 1.0 | TS 9.3 |
| R-14 | A static file is sent with its version and date, and a client that holds it is answered 304 | 1.0 | TS 1.6 |
| R-15 | A client and the service exchange through sockets | 1.1 | |

### 6.3 Access

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-16 | In a profile that asks for credentials, every route needs one unless the profile opens it on purpose; an access setting that can't be applied refuses every request | 1.0 | TS 4.4 |
| R-17 | A credential is carried by Basic authentication, a key in a header, a signed token, a PHP session or a client certificate, and checked against a password file, a list of keys, a users file, a users table, device tokens, or a check of the project's own | 1.0 | TS 5.3 |
| R-18 | Roles are ordered; a route, or an action, asks for the least role it needs | 1.0 | TS 2.8, FS 5.2 |
| R-19 | Wrong credentials are counted by address, and the address held past a limit (429); an account is held after too many wrong passwords | 1.0 | TS 9.5 |
| R-20 | A wrong name and a wrong password get the same refusal, and, where the passwords are hashed with bcrypt, in the same time | 1.0 | TS 9.4 |
| R-21 | A user with two-factor codes signs in with a one-time code, from an authenticator or sent by mail, each accepted only once | 1.0 | FS 5.2 |
| R-22 | Tokens are signed and end; tokens signed with a key pair are checked by other services with the public key alone | 1.0 | TS 2.5, FS 4.4 |
| R-23 | An application calls for its own users with a key, limited to the routes it is given and to a highest role | 1.0 | FS 5.7 |
| R-24 | Users sign in through SSO or LDAP | 1.1 | |
| R-25 | A second factor is sent by SMS, or a login is approved from the phone | 1.1 | |
| R-26 | Tokens name their issuer and audience, and the keys that check them are published | 1.0 | FS 5.7, FS A.1 |
| R-27 | Client certificates are read through a proxy, with a profile of their own; certificates are pinned | 1.1 | |
| R-28 | Limits are set route by route | 1.1 | |

### 6.4 Users

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-29 | The users are kept in a file or in a table, and moved from one to the other with their passwords, roles and secrets | 1.0 | FS 6.2 |
| R-30 | An account goes through the statuses pending, in review, active and locked; a code activates it | 1.0 | FS 5.3 |
| R-31 | Users change their own password, and reset a forgotten one by mail, their password kept until the code is used | 1.0 | FS 5.5 |
| R-32 | Visitors sign up, confirm their address, and wait for a manager's approval; sign-ups are limited per client and in total | 1.0 | FS 5.4 |
| R-33 | The access mails go in the language of each user | 1.0 | FS 5.10 |
| R-34 | A service is never left without an active manager | 1.0 | FS 5.3 |
| R-35 | A device acts for a user with a token of its own, revoked alone | 1.0 | FS 5.6 |
| R-36 | Keys, tokens and codes are shown once and kept as hashes; the two-factor secrets are kept encrypted | 1.0 | TS 9.4 |

### 6.5 Data

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-37 | An application reaches its data through models, records and lists, never through queries it writes | 1.0 | TS 10 |
| R-38 | The databases are MySQL, MariaDB, PostgreSQL, SQLite and MongoDB, several at once | 1.0 | TS 10.1 |
| R-39 | A filter is written the same way for every kind of database | 1.0 | TS 10.5 |
| R-40 | A record is removed with the children named, in one transaction, or not at all | 1.0 | TS 10.3 |
| R-41 | A binary value is written and read piece by piece, never held whole | 1.0 | TS 10.7 |
| R-42 | A saved record's key has the same type it has when read back: a key the database numbered is a number | 1.0 | TS 10.3 |
| R-43 | A list joins several tables | 1.1 | |
| R-44 | A table is served by settings alone, its actions written by the scaffolding | nyuTron | |

### 6.6 Documents, media and mail

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-45 | A document is read into one of three shapes (a tree, sheets, text blocks), and written from it, in the formats of 5.6 | 1.0 | TS 5.6, FS 5.9 |
| R-46 | A document whose content doesn't match its name is refused | 1.0 | FS 5.9 |
| R-47 | Images are read and edited; sounds are read, cut, and converted through ffmpeg | 1.0 | FS 5.9 |
| R-48 | A mail is sent through SMTP, encrypted and its server's certificate checked, through PHPMailer, or into files, written from its templates | 1.0 | FS 5.10 |
| R-49 | Rich text, source code, handwriting and scanned text are read; sounds are mixed | 1.1 | |

### 6.7 Command line and operation

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-50 | A project is installed in one command, which replaces nothing that already exists; its health check stops a deployment script on a failure | 1.0 | TS 7.2 |
| R-51 | A setting is changed one value at a time, checked, with the previous file kept | 1.0 | TS 7.3 |
| R-52 | A copy of the project is built for each environment, holding no secrets, no local settings and no keys | 1.0 | TS 7.6 |
| R-53 | Users, devices and application keys are managed from the command line, and every change is traced | 1.0 | TS 7.4, TS 7.5 |
| R-54 | Each subject has its log; privileged accesses and refusals are traced; a trace holds no argument of its calls | 1.0 | TS 9.6 |

### 6.8 Quality

| # | Requirement | Status | Met in |
|---|---|---|---|
| R-55 | The framework runs on PHP 8.2 and later; its tests pass on the lowest release supported and the latest | 1.0 | TS 3.1, TS 13.5 |
| R-56 | A test suite checks every part, on SQLite and on real database servers | 1.0 | TS 13 |
| R-57 | Every name has the length of its kind, every public method its docblock, every parameter and return its type; an audit checks them | 1.0 | TS 12 |
| R-58 | A reference of the code is made from its docblocks | 1.0 | TS 12.3 |

---

## 7. Performance goals

### 7.1 The goal

nyuRuns aims to be as efficient as the micro-frameworks, while offering
the key features, and some of the capabilities, of the heavier ones. It
must not pay for that completeness in the cost of each request.

### 7.2 Goals, and where 1.0 stands

| # | Goal | 1.0 |
|---|---|---|
| P-01 | A request costs the server at most 1.2 times the CPU it costs with Slim, on the same machine, for the same work | Met: 1.0 times (2.67 ms each; Flight 1.98 ms) |
| P-02 | A request costs at most half the CPU it costs with Symfony, on the same machine, for the same work | Met: 0.41 times (6.55 ms for Symfony; 8.37 ms for Laravel) |
| P-03 | It sends the database no more statements than they do for the same work | Met: one to read a record, five for a record with its relations in the benchmark's complex case |
| P-04 | A request loads only what it uses: no connection unless data is read, no model unless an action asks for it | Met |
| P-05 | What the framework reads on every request (settings, routes, tables) is read once in production, then from a cache | Met: `CNF_CACH`, `TBL_CACH`, set by the production copy |
| P-06 | Large data is read and sent piece by piece, never held whole in memory | Met for binary values and files sent; a request body is held whole, up to `REQ_SIZE` |
| P-07 | On the reference server (7.3), with 50 clients at once, the benchmark's light reads (a list, a record, a user's projects) handle 800 requests per second or more, with the slowest 1% answered in under 150 ms and none failed | Not measured yet |
| P-08 | On the reference server, with 50 clients at once, the benchmark's writes (a record created, a task, a comment) handle 500 requests per second or more, under the same conditions | Not measured yet |
| P-09 | An API request uses at most 4 MB of memory at its peak | Not measured yet |

The relative goals (P-01, P-02) apply on any machine. The 1.0 figures come
from the run of 7 November 2026, on one machine, one request at a time,
before the 9 November changes. The method, the scenario-by-scenario
results and their limits are in the
[Functional Specifications, chapter 7](funcspecs.md#7-performance-results).

### 7.3 The reference server

A figure in requests per second means nothing without its machine. P-07
to P-09 are set for this one:

| | Reference server |
|---|---|
| Processor | 6 cores, of the class of an AMD Ryzen 5 5500 |
| Memory | 16 GB |
| System | Linux |
| PHP | 8.2 or later, through PHP-FPM, OPcache on without checking the files, JIT off |
| Database | MySQL, on the same machine |
| nyuRuns | its final copy: production settings, config and table caches on |

**Where the figures come from.** A request cost 2.67 ms of CPU in the web
server and 1.47 ms in MySQL, about 4.1 ms in all. Six fully busy cores
would answer some 1,450 requests per second. The load goals take about
half of that, leaving room for what load adds: waiting on locks,
switching between processes, the network. Writes cost about a tenth more
CPU, but they also wait on the database, its locks and its disk, which a
one-request-at-a-time measurement doesn't show, so their goal is set
lower.

### 7.4 What's not set yet

- **Documents and media**: reading, writing and converting large files,
  images and sounds weren't measured. Their goals will be set once a
  first measurement gives a basis.

A goal is set before it's measured, so a measurement can meet it, or not.
The load goals are next in line: the first load test, run on Windows,
failed for lack of connections.

---

## 8. Additional notes

### 8.1 Platform

- **PHP 8.2 or later.** 8.0 and 8.1 no longer get security fixes. A
  release that fixes php2shell (CVE-2026-109618, CVE-2026-109619) is
  recommended, and the health check tells you when it's missing.
- **The extensions** the libraries need (`json`, `dom`, `iconv`, `zlib`,
  `mbstring`) are required. The others are only needed by the parts that
  use them ([Technical Specifications, chapter 3](techspecs.md#3-system-requirements)).
- **Any web server** that hands the request to PHP and sends every URL to
  the entry point. The server itself belongs to its host
  ([`HOSTING.md`](../HOSTING.md)).

### 8.2 Licence and contributions

- nyuRuns is released under the **MIT licence**, copyright 2021-2026
  Jason JOHNSTON (`LICENSE`).
- **Contributions** are signed off under the Developer Certificate of
  Origin (`git commit -s`), as explained in `CONTRIBUTING.md`.
- The **libraries** it uses keep their own licences, listed in `NOTICE`.
  PHPMailer, suggested and not required, is under the LGPL 2.1.

### 8.3 Versions

| Version | Holds |
|---|---|
| 0.88 | the last version before the code was complete, tagged on 21 October 2026 |
| 0.9 | code complete: renamed devices table, RS256 tokens, the profile format, the copy built for each environment, the release settings |
| **1.0** | the first public release: the documentation, the licence, the performance tuning, codes by mail, tokens bound to an issuer and an audience, each run's own routes, the security revision, and the final test pass |
| 1.1 | what chapters 5 and 6 leave for it: SSO and LDAP, a second factor by SMS or approved from the phone, certificates through a proxy, limits by route, other input sources, sockets, several joins, rich text and code, handwriting, sound mixing |
| nyuTron (codename) | a separate project of tools around nyuRuns: the scaffolding of models and code, an installation wizard, a manager of advanced settings, ready-made data kits |

Every version is closed by a full pass of the test suite, on the
supported PHP releases and database servers.

### 8.4 What changed from the original vision

- **CRUDI** is now **CRUDL**: the same operations, with the list named for
  what it is.
- **A table served by settings alone** is written by nyuTron's
  scaffolding, rather than read from settings by the framework.
- **Files as data sources**, **sockets** and **a cache for data replies**
  aren't in 1.0. The first two come with version 1.1, and the last isn't
  planned yet.
- **Digest authentication and a cookie carrier** were dropped.

---
