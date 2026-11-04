# nyuRuns — Functional Specifications

Version 1.0

## Summary

1. [Introduction](#1-introduction)
2. [Requirements highlights](#2-requirements-highlights)
3. [Software design overview](#3-software-design-overview)
4. [System architecture](#4-system-architecture)
5. [Process implementation](#5-process-implementation)
6. [Configuration and scalability](#6-configuration-and-scalability)
7. [Performance results](#7-performance-results)
8. [Development guidelines](#8-development-guidelines)

A. [Replies and statuses](#a-replies-and-statuses)

---

## 1. Introduction

### 1.1 What this document tells

This document tells what nyuRuns does, and how its processes go: who
uses a service built on it, what each of them can do, and the steps of
each process, from a sign-in to the shipping of a project. It describes
behaviour, not code: the names of the code appear only where a reader
needs them to find a setting or a command.

It is written for developers, and for the analysts and product owners
who decide what a service built on nyuRuns should do. How the framework
is built is told in the [Technical Specifications](techspecs.md).

### 1.2 nyuRuns in a few words

nyuRuns is the back end of an application. A front end (a web page, a
mobile application, another program) sends it requests over HTTP; it
answers with data, a file or a page. Between the two, it takes the whole
request in charge:

- it finds what is asked for, from the address of the request;
- it checks who is asking, and whether they may;
- it keeps only the values the request may carry, each in its form;
- it runs the application asked for, which reads and writes its data;
- it writes the reply in the format the front end expects, errors
  included.

Around that, it gives the tools a service needs: users and their access,
databases, documents, pictures, sounds, mail, logs, and a command line
to install, configure, test and ship a project.

### 1.3 Who uses a service built on nyuRuns

| Actor | Who | What they do |
|---|---|---|
| **Visitor** | anyone, not signed in | reaches what is open to all: a public page, the sign-in, the sign-up, the reset of a forgotten password |
| **Member** | a signed-in user, role `member` | uses the service with the rights of the least role |
| **System user** | role `system` | uses what the service keeps for its staff |
| **Manager** | role `manage` | manages the users: creates, approves, locks, resets and removes them, gives roles |
| **Device** | a machine with a token of its own, in the name of a user | calls the service without a person signing in: a kiosk, a sensor, a home server |
| **Application** | another program, holding a key | calls the service for one of its own users |
| **Administrator** | the person who runs the server | installs, configures, checks and updates the project from the command line |

The roles are ordered: a `system` user holds the rights of a `member`, a
manager those of both. What each role may reach is decided by the
project, route by route.

### 1.4 What version 1.0 covers

Version 1.0 holds the whole handling of a request, several ways to sign
in and to carry an identity, the management of users and their access
mails, five kinds of database, documents in the common formats of text, data and office files, pictures,
sounds, mail, and the command line. Some parts of the first vision are
left for version 1.1, and a few were dropped. The list is told once, in
the [Technical Specifications, 1.4 to 1.6](techspecs.md#14-what-version-10-holds);
chapter 2 tells it from the side of the needs.

### 1.5 Words used in this document

| Word | Means |
|---|---|
| **service** | a project built on nyuRuns, as its users see it |
| **front end** | what sends the requests: a web page, a mobile application, a program |
| **run** | an application of the project, such as `Users` or `Shows` |
| **action** | what a run does for one request: list, view, create, update, remove |
| **route** | the address that leads to an action, a page or a file |
| **preset** | the profile of the service: how it is reached, checked and answered |
| **store** | where the users are kept: a users file, or a table of a database |
| **role** | what a user may reach: `member`, `system`, `manage` |
| **status** | where an account stands: `pendng`, `review`, `active`, `locked` |

---

## 2. Requirements highlights

### 2.1 The first vision

The Requirements of nyuRuns describe it as a micro-framework to build and
deploy the back end of whole applications as RESTful services. Its aim is
to simplify the making of APIs by dividing the work: developers write what
their business needs, and the framework does the rest. It rests on four
principles: model-driven development (MDD), keeping things simple (KISS),
REST, and the operations every data source is given, create, read,
update, delete and list (CRUDL).

This chapter takes the needs that vision names, and tells where version
1.0 stands on each. The Requirements themselves are told in their own
document.

### 2.2 The needs, and where 1.0 stands

| Status | Means |
|---|---|
| **Met** | 1.0 does what the need asks |
| **Partly met** | 1.0 does part of it; the rest is told |
| **Version 1.1** | left for the next version |
| **nyuTron** | moved to the separate project of tools around nyuRuns |

| Need | Status | In 1.0 |
|---|---|---|
| Build and deploy the back end of a whole application, as RESTful services | Met | resources reached by their path, methods that tell what is done, statuses that tell how it went; a command line installs, checks and ships the project |
| Handle the whole HTTP request, so that developers write less | Met | routing, access, inputs, the action, the reply and its errors, in one sequence (chapter 5.1) |
| Data-model-driven components (MDD) | Met | an application works through models of its data, records and lists, never through queries |
| CRUD operations out of the box | Partly met | every data source is given its CRUDL operations through the models; an application still writes a short action for each. A table served by configuration alone is not in 1.0: generating those actions is the work of nyuTron's scaffolding |
| Other data sources than databases: XML, JSON, INI and text files | Partly met | files of those formats, and many more, are read and written as documents by an action. Their use as a configured source of inputs is left for version 1.1 |
| Routing of every request, with fallbacks | Met | routes found from the path, or declared; a declared route can also redirect, serve a file, a folder or a page; a site can have its own error pages |
| Access control (ACL) and tokens | Met | ordered roles asked for by route or by action; signed tokens, sessions, API keys, client certificates; several stores of users |
| Data handlers: databases, files, images, sockets | Partly met | five kinds of database, documents, pictures, and sounds, which the vision did not name. Sockets are left for version 1.1 |
| Output with cache features | Partly met | static files are sent with their version and date, and a client that holds them is answered 304. Data replies are not cached |
| Error handling | Met | errors are written in the format of the reply, logged, and never detailed in production |
| A layered (multi-tier) architecture | Met | the request, the access, the application, its models and their stores are separate layers (chapter 4) |
| Keep it simple | Met | a first install needs no database; what a project does not use is not loaded |

### 2.3 What 1.0 adds to the first vision

Building the framework brought needs the first vision did not name. Version
1.0 answers them:

- **Users and their life**: activation with a code, statuses, locking,
  roles, passwords chosen and reset, sign-ups reviewed by a manager, all
  through an application or the command line (chapter 5.3 to 5.5).
- **Access mails**, in the language of each user.
- **Two-factor codes**, with an authenticator.
- **Devices**, and **applications calling for their users**, each with a
  credential of its own (chapters 5.6 and 5.7).
- **A limit on wrong attempts**, by address and by account.
- **Documents** in the common formats of text, data and office files,
  read into one shape and written from it.
- **Pictures and sounds**, read, changed and converted.
- **Mail**, through an SMTP server, PHPMailer, or into files.
- **The command line**, to install, configure, test, manage and ship a
  project, with a copy built for each environment.

### 2.4 What changed, and why

The parts of the vision that 1.0 does not fully meet were left aside to
finish the version sooner, not given up: the files as configured sources
and the sockets come with version 1.1, and the generation of CRUDL actions
with nyuTron. The cache of data replies is the one need for which no
version is planned yet. The full list of what is left for later is in the
[Technical Specifications, 1.5 and 1.6](techspecs.md#15-what-is-left-for-later).

---

## 3. Software design overview

### 3.1 The principles, as a service shows them

**Model-driven development (MDD).** A service is described by its data:
each kind of thing it keeps (a user, a show, an invoice) is a model, a
record of a table or a list of them. The applications read and write
through the models; they never write a query. Moving to another database,
or another kind of database, leaves the applications as they are, within
what that kind can do (MongoDB, for one, has no joins).

**Keep it simple (KISS).** A service starts with what it needs and nothing
more: a first install holds its users in a file and needs no database;
what a project does not use is not loaded. Each part does one thing, and
the settings are plain files.

**REST.** Every resource has its address, and the method of the request
tells what is done with it. The status of the reply tells how it went,
whatever the format of its body:

| Method | Done | Usual statuses |
|---|---|---|
| `GET` | read one resource, or list them | 200 with data, 204 when the list is empty, 404 |
| `POST` | create a resource, or run an operation | 201 created, 202 accepted, 400 |
| `PUT`, `PATCH` | change a resource | 200, 404, 409 when the change conflicts |
| `DELETE` | remove a resource | 200 or 204, 404 |

A route can give each method its own action, so that one address serves
the whole life of a resource.

**CRUDL.** Every source of data is given the same five operations:
create, read, update, delete and list. A list can be filtered, sorted,
limited and joined to another table; the filter is written the same way
for every kind of database.

### 3.2 The choices behind the design

- **One entry, one profile.** Every request enters through one door, and
  the entry tells which profile (preset) applies: how the service is
  reached, who may enter, how it answers. The profile is chosen once for
  the service, and changed with one command.
- **Declared, not coded.** What a service accepts is declared in files:
  its routes, the inputs of each action, the roles of each route, its
  settings. A reader finds what a service does without reading its code.
- **Closed by default.** Every route asks for a credential unless the
  profile opens it. A setting that cannot be applied refuses every
  request rather than letting one through.
- **Silent towards the client.** A client is told what it needs, and no
  more: what it cannot reach is not found, a wrong name and a wrong
  password are the same refusal, and an error in production carries no
  detail. The log tells the rest.
- **One shape of reply.** Data comes in the format of the profile (JSON
  unless told otherwise); an error comes as `{"error": ..., "code": ...}`
  in that same format.
- **Light first, grown later.** A service moves from a users file to a
  database, from one database to several, from one application to many,
  without being rewritten.
- **The host keeps the server.** The framework takes the request from the
  moment PHP receives it; the web server, TLS and the firewall belong to
  whoever runs the server.

### 3.3 The concepts

```
  front end ──request──▶ entry ──▶ preset ──▶ route ──▶ run / action ──▶ models ──▶ store
                                     │          │            │
                                   access     inputs       reply ──▶ front end
                                (roles, users)
```

| Concept | What it is | Example |
|---|---|---|
| **Project** | one service: its applications, its settings, its data | a booking service |
| **Run** | an application of the project, grouping the actions on one subject | `Shows`, `Users` |
| **Action** | one thing a run does for one request | list the shows, view one, create a user |
| **Route** | the address that leads to an action, or to a page, a file, a redirection | `shows/view/12`, `/login` |
| **Preset** | the profile of an entry: access, inputs, routing, replies | `webapi` for programs, `websit` for a browser |
| **Inputs** | the values a request carries, kept only when the action declares them, each in its form | a name, a date, an address |
| **Model** | a record or a list of a table | a show, the list of shows |
| **Store** | where the users are kept | the users file, the users table |
| **Role** | what a user may reach, in order: `member`, `system`, `manage` | a manager holds every right of a member |
| **Status** | where an account stands | see below |
| **Reply** | the status, and data, a file, a page or an error | `200` with the list of shows |

The status of an account:

| Status | Meaning | Can sign in |
|---|---|---|
| `pendng` (pending) | created, waiting for its code to be used | no |
| `review` (in review) | signed up by itself and confirmed, waiting for a manager | no |
| `active` | in use | yes |
| `locked` | stopped by a manager | no |

How an account moves from one status to another is told in chapter 5.3.

---

## 4. System architecture

### 4.1 The service and what surrounds it

```
                    ┌───────────────────────────────────────────────────────────┐
  front ends        │                     nyuRuns service                       │
  (web, mobile,     │                                                           │
   programs)        │  entry ─▶ routing ─▶ access ─▶ applications ─▶ reply      │
      │             │              │          │           │                     │
      ▼             │              │      users, roles   models, documents,     │
  web server ──────▶│              │      limits, 2FA    media, mail            │
  (TLS, proxy)      │              ▼          │           │                     │
                    │       pages, files      ▼           ▼                     │
                    │                       logs ◀──────────                    │
                    │                                                           │
                    │  command line: install, configure, manage, test, ship     │
                    └──────┬──────────────┬──────────────┬──────────────┬───────┘
                           ▼              ▼              ▼              ▼
                       databases     mail server       ffmpeg     other applications
                  (MySQL, MariaDB,    (SMTP)         (sounds)     (keys, tokens)
                   PostgreSQL,
                   SQLite, MongoDB)
```

### 4.2 The functional blocks

| Block | Does | Chapter |
|---|---|---|
| **Entry** | receives every request from the web server, and applies the profile of the service | 5.1 |
| **Routing** | finds what the address asks for: an action, a page, a file, a redirection | 5.1 |
| **Access** | tells who is asking and whether they may: credentials, roles, limits on attempts, two-factor codes | 5.2 |
| **Users** | keeps the accounts and their life: creation, activation, statuses, roles, passwords, sign-ups, access mails | 5.3 to 5.5 |
| **Applications** | the runs of the project: their actions, the inputs they accept, the models of their data | 5.8 |
| **Data** | databases through models; documents, pictures and sounds read, changed and written | 5.8, 5.9 |
| **Mail** | sends mails through the accounts of the project | 5.10 |
| **Reply** | writes the answer in its format, a file, a page or an error | 5.1, appendix A |
| **Logs** | traces what happens, one file per subject: errors, access, the framework, the command line, each application | 6 |
| **Command line** | installs, configures, manages users and devices, tests and ships the project | 5.11 |

Only what a request needs is loaded: a request that touches no database
opens none, and the data and mail blocks are loaded by the applications
that use them.

### 4.3 The outside systems

| System | Needed | For |
|---|---|---|
| **Web server** | always | receives the requests and hands them to PHP; holds TLS, or leaves it to a proxy in front |
| **Reverse proxy, CDN** | optional | stands in front of the web server; the service trusts the address it gives only when told to |
| **Database servers** | optional | the data of the applications, and the users once they leave the users file |
| **Mail server** | optional | the access mails and the mails of the applications |
| **ffmpeg** | optional | converting and cutting sounds |
| **Authenticator** | optional, on the user's phone | the two-factor codes |
| **Other applications** | optional | calling the service for their users, or checking the tokens it issues |

### 4.4 Several services together

A project can be one service, or one of several that work together:

- **One sign-in for several services.** One service issues the tokens,
  signed with its private key; the others hold only its public key and
  check them. They issue none themselves: their sign-in answers that the
  tokens come from another server.
- **An application calling for its users.** A service gives another
  application a key and the routes it may call; that application then
  calls with its key and a token it signs for one of its own users, who
  never signs in to the service directly. The role it may act with is
  capped.
- **One owner per data**, the rule recommended when services share data:
  each kind of data belongs to one service, and the others reach it
  through that service's routes, never through its database. The
  framework does not enforce it; the application keys make it practical.

### 4.5 Environments

A project lives in several places: the developer's machine, a server
before production, production, and any server of its own between them
(integration, a preview for a client). It is shipped to each as a copy
built for it, holding the code and the examples of the settings, never the
secrets nor the settings of another place. On each server the
administrator installs the copy, makes its secrets, and checks it before
it serves (chapter 5.11).

---

## 5. Process implementation

Each process is told as the steps a client and the service go through,
with the reply of each way out. The statuses are listed in appendix A.

### 5.1 A request, from the address to the reply

```
request
  │
  ├─ body too large ─────────────────────────────────────────▶ 413
  ├─ preflight of another site (OPTIONS) ────────────────────▶ 204, with the allowed headers
  │
  ▼ route
  ├─ nothing answers the address ────────────────────────────▶ 404
  ├─ the method is not one the route allows ─────────────────▶ 405, with the methods allowed
  │
  ▼ access
  ├─ the settings cannot be applied ─────────────────────────▶ 500
  ├─ the address is held after too many wrong attempts ──────▶ 429, with the time to wait
  ├─ no credential, or a wrong one ──────────────────────────▶ 401 (a page: sent to the sign-in)
  ├─ the role of the route is not held ──────────────────────▶ 404
  │
  ▼ the route answers by itself, or runs an action
  ├─ redirection ────────────────────────────────────────────▶ 301 to 308
  ├─ file or page ───────────────────────────────────────────▶ 200, 206 (a part), 304 (held), 404
  ├─ the application or the action cannot be reached ───────▶ 404
  ├─ a required input is missing or wrong ───────────────────▶ 400
  │
  ▼ action
  └─ the status and the data the action gives ───────────────▶ 2xx with data, or an error
```

- A route open to all skips the access step; anyone reaches it.
- What the client cannot reach is always the same 404, whether the
  address does not exist or the role is not held: a client cannot map
  what the service holds.
- The reply is written in the format of the profile. An error is
  `{"error": "<message>", "code": <status>}`; a site answers it with its
  own error page when it has one.

### 5.2 Signing in and out

**Signing in** is an action open to all, `auth/login`:

1. The front end sends the name and the password (`user`, `pass`) with
   `POST`, in the body of the request. A password in the address is
   refused (400): addresses are kept in logs and histories.
2. An address held after too many wrong attempts is refused at once (429).
3. The name and the password are checked against the store of users. A
   wrong name, a wrong password, or an account that is not active get the
   same answer, `Invalid credentials` (401), and count against the
   address. The time of the answer does not tell whether the name exists.
4. A user with a two-factor secret must also send the code of its
   authenticator (`code`): without one, `Verification code required`
   (401); a wrong one, `Invalid verification code` (401), counted. Each
   code is accepted once.
5. The service then gives the identity the front end will carry: a
   **token** in the body of the reply, or a **session**, whose cookie
   goes out with the reply. A service that only checks tokens issued
   elsewhere issues none (501).

In the native userbase, an account whose password is wrong too many
times in a row is held for a while, whatever the address it comes from.

**Each request after** carries the token (`Authorization: Bearer …`) or
the cookie. A token is valid until its end, an hour in the example
settings; a session ends after a time unused, or a time in all. A user
locked by a manager is refused at once when the profile checks the store
on each request.

**Signing out**, `auth/logout`, ends the session and expires its cookie.
A token cannot be withdrawn: the front end forgets it.

**A signed-in user** also changes its own password (`auth/password`,
with the current one) and the language of its mails (`auth/langs`).

The sign-in of a user of the `system` or `manage` role, and each of its
accesses, are traced; every refusal is traced with its reason.

### 5.3 The life of an account

```
     users/create, manage enrol            auth/signup (5.4)
       (by a manager)                        (by the visitor)
              │                                     │
              ▼  code mailed, or given              ▼  code mailed
          ┌────────┐                            ┌────────┐
    ┌────▶│ pendng │                            │ pendng │── code ended: removed
    │     └────────┘                            └────────┘
    │          │ auth/activate:                      │ auth/activate:
    │          │ code, chosen password               │ code, chosen password
    │          │                                     ▼
    │          │                                ┌────────┐
    │          │                                │ review │── refused: removed
    │          │                                └────────┘
    │          │                                     │ users/approve, manage prove
    │          ▼                                     │ (by a manager)
    │     ┌────────┐◀────────────────────────────────┘
    └─────│ active │   lock    ┌────────┐
   reset  │        │──────────▶│ locked │
(manager) └────────┘◀──────────└────────┘
                       unlock
       remove: from any status, with the devices of the account
```

- **Creation**: a manager creates the account (`users/create`, or
  `manage enrol` on the command line) with its name, its role, and
  optionally its address and language. It is pending, with an activation
  code. When the service sends mails and the user has an address, the
  code is mailed; otherwise the reply gives it, once, to the manager, who
  passes it on.
- **Sign-up**: a visitor opens a pending account itself, when the
  service allows it (5.4).
- **Activation**: the user sends its name, the code and the password it
  chooses (`auth/activate`). An account a manager created becomes active;
  one the visitor signed up for goes into review. A wrong code is refused
  as a wrong name is, and counted.
- **Review**: a manager approves the account (`users/approve`, or
  `manage prove`), which becomes active, or refuses it by removing it. An
  account in review cannot sign in, nor be locked.
- **Locking**: a locked user cannot sign in, and its session ends at its
  next request when the profile checks the store (5.2). Only an active
  account is locked.
- **Reset**: for a lost password, a manager puts the account back to
  pending with a new code; the former password and personal token stop
  working at once. A locked account is unlocked first.
- **Roles, address, language**: a manager changes them at any time. A
  name and an address belong to one account only (409).
- **Removal**: the account goes, with its devices.
- **Guards**: a manager cannot lock, reset, remove or change the role of
  itself, and the last active manager cannot lose that standing.

**The codes** are ten characters, `XXXX-XXXX-XX`, without characters that
look alike, read whatever their case and dashes. Each is used once and
only its hash is kept. A code handed over by a manager lasts 300 minutes;
a mailed one lasts the minutes the settings give (15 by default).

### 5.4 Signing up

When the service allows it, and sends mails, a visitor opens its own
account; a manager decides whether it stays.

```
visitor                              service                                manager
   │  auth/signup: name, address        │                                      │
   │──────────────────────────────────▶│ 202, always                          │
   │                                    │ free: pending account, code mailed   │
   │                                    │ taken: the owner of the address told │
   │  auth/activate: code, password     │                                      │
   │──────────────────────────────────▶│ the account in review                │
   │                                    │ managers told when sign-ups pile up ─▶│
   │                                    │                users/approve         │
   │                                    │◀─────────────────────────────────────│
   │                                    │ the account active                   │
```

1. The visitor gives a name and an address. The answer is 202 whatever
   happens, so that nobody learns which names or addresses are taken.
   A free name and address make a pending account, and the address is
   sent a code. A taken address is told by mail that someone tried to
   use it.
2. The visitor confirms the address with the code and chooses a password
   (`auth/activate`): the account is in review.
3. A manager approves it (`users/approve`, or `manage prove`): it is
   active. Refusing it is removing it.

**Bounds**: a client may sign up a limited number of times in a while
(429 past it); a limited number of sign-ups wait at once (503 past it);
the managers are mailed when the waiting count reaches the levels set. A
sign-up whose code ended without being used is removed. Without sign-ups
allowed, or without mail, `auth/signup` is not found (404).

### 5.5 A forgotten password

```
user ── auth/forgot: name or address ──▶ 202, always
                                         an active account with an address: a code mailed,
                                         the password kept as it is
user ── auth/recovr: name, code, new password ──▶ the new password set
```

1. The user gives its name or its address (`auth/forgot`). The answer is
   202 whoever the user is, or is not; each request counts against the
   address of the client.
2. An active account with an address is sent a code. Its password still
   works: a request made by someone else changes nothing until the code is
   used.
3. The user sends its name, the code and its new password
   (`auth/recovr`). A wrong or ended code is refused (401), and counted.

Without mail, `auth/forgot` is not found (404); a manager then resets the
account (5.3), which stops the former password at once.

### 5.6 Devices

A device is a machine that calls the service without a person signing
in: a kiosk, a sensor, a server of the household. It acts in the name of a
user of the native userbase, with a token of its own.

1. The administrator gives the device a token, with a name and a role
   (`php nyuRun device token <name> <user> <role>`). The token is shown
   once; only its hash is kept.
2. The device sends its token in a header with each request. It is
   accepted while the device is not revoked and its user is active; the
   time it was last seen is kept.
3. The device holds the role it was given, when it is one of the
   service's roles, else the least one. What that role reaches is decided
   by the routes, as for a user.
4. The administrator lists the devices, and revokes one: it is refused
   from the next request, and stays in the list with the time of its
   revocation. A device whose user is locked is refused; one whose user
   is removed goes with it.

Devices need the native userbase: a service with devices in use cannot
move its users to the users file until they are revoked.

### 5.7 An application calling for one of its users

Another application (a mobile app, a partner's portal) calls the
service for its own users, who never sign in to the service itself.

```
administrator ── php nyuRun appkey makes <app> --routs=… --pubky=… --urole=… ──▶ key, shown once
application   ── request: its key in X-App-Key, and a token it signed for its user ──▶ service
service       ── key known? token signed by that application? route listed? role held? ──▶ action
```

1. **The administrator registers the application**: its name, the
   routes it may call, the public key that checks its tokens, and the
   highest role its users may act with. The service gives a key, shown
   once; only its hash is kept.
2. **The application calls** with its key in a header of its own
   (`X-App-Key` by default) and, as the bearer token, a token it signs
   with its private key for one of its users: the name and the role of
   that user, and an end.
3. **The service checks**, in order: the key (a wrong one is 401, and
   counted against the address), the token (signed by that application
   and not ended), the route (one the key does not list is 403), the role
   of the route (403 when not held). The role is never above the highest
   one set for the application.
4. **The action runs** for that user, told which application called. The
   user is the application's own: the service does not look it up in its
   store of users.

A write (any method but a read) is traced as a notice, a read as
information, a route refused as a warning. The administrator changes the
routes of an application, and revokes its key: the next request with it
is refused. A route open to all ignores the key.

### 5.8 Working with data

A run gives its data the five operations through its actions. With a
route for each method, one address serves a resource:

| The front end sends | The action | The reply |
|---|---|---|
| `GET shows?filters[search]=jazz&sorting=^date&indexes=20,40` | reads a list, filtered, sorted and cut | 200 with the rows, 204 when none matches |
| `GET shows/view/12` | loads a record and its related rows | 200 with the record, 404 when there is none |
| `POST shows/create` with the fields | fills a new record and saves it | 201 with its identifier, 400 for a field that is not valid |
| `PATCH shows/edit/12` with the fields | loads, changes and saves it | 200, 404, 409 when the change conflicts |
| `DELETE shows/remove/12` | removes it, with the child rows named | 200 or 204, 404 |

What happens on the way:

- **Inputs**: only the fields the action declares reach it, each in its
  form; a required one missing is 400 before the action runs. A value
  that is not valid in its form is emptied.
- **Filters**: a value from the request is compared as it is, never as a
  pattern; a search with wildcards is asked for by the name of the field,
  so a client cannot widen what it is shown.
- **Lists**: read a number of rows at a time (a default when none is
  asked), from an offset, sorted by any field.
- **Records**: their related rows come with them (a show and its events).
  A removal with its children is done in one transaction: all of it, or
  nothing. Each removal is traced, with the user who asked.
- **Files in a record**: a picture or a file is stored as it is sent,
  and sent back as it is, read piece by piece.

The names in this table are those of an example: each run names its
actions, and the routes it declares decide which method reaches which.

### 5.9 Documents and media

**Documents.** An action reads a document (a file sent with the request,
or one of the server) and gets its content in one of three shapes,
whatever its format:

| Shape | For | From |
|---|---|---|
| a tree of keys and values | settings, data exchanges | JSON, YAML, XML, INI |
| tables of rows and cells | sheets | CSV, TSV, Excel, OpenDocument spreadsheets |
| text blocks in order | documents to read | text, Markdown, HTML, Word, OpenDocument texts, PDF, ePub |

A calendar (iCalendar) gives its events, tasks and notes. The action
works on the shape, then writes it in any format that takes it: a
spreadsheet read from Excel is written as CSV, a Markdown text as Word,
PDF or ePub. A document whose content does not match its name is refused.

**Pictures**: their kind, size and what a photo tells (date, camera,
position) are read; they are resized, cropped, turned, and stripped of
what they carry; a drawing (SVG) is turned into a picture.

**Sounds**: their kind, length, tags and picture are read; their tags are
removed; a WAV or MP3 sound is cut. With ffmpeg on the server, any sound
is converted to another kind or cut.

**QR codes** are made from a text, such as the address an authenticator
reads.

Every file a request brings is traced, received or failed, whether the
request is allowed or not; a body larger than the service accepts is
refused before it is read (413).

### 5.10 Sending mail

1. **The accounts**: the project lists its mail accounts, the first
   being the default. Each sends through an SMTP server, through
   PHPMailer (for attachments), or into files, which keeps every mail on
   the machine during development.
2. **A mail** has a sender, recipients, a subject, a text and an HTML
   version. It is written from a template: a text file whose first line is
   the subject, and an HTML file beside it, their placeholders filled and
   encoded.
3. **Sending**: the connection is encrypted, and the server's certificate
   checked; only a mail server on the same machine is spoken to in clear. An account can send a copy of every mail to other addresses;
   a copy that fails is logged, not reported.
4. **What is traced**: each mail sent, or refused, on the log of the
   framework.

**The access mails** are those of chapter 5.3 to 5.5:

| Mail | Sent when | To |
|---|---|---|
| `actvte` | an account is created or reset, with an address | the user |
| `signup` | a visitor signs up | the address given |
| `pwdset` | a user asks for a new password | the user |
| `taken` | a sign-up asks for a name or an address already in use | the owner of the address |
| `onhold` | the sign-ups waiting reach a level set | the managers |

Each goes in the language of its user, else in the language of the
request when the user is the one asking, else in the default language of
the service. The framework brings them in English and French; a run
replaces them with its own words and look.

### 5.11 Installing, configuring and shipping a project

```
developer machine                           server
──────────────────                          ──────────────────────────────────────
php nyuRun bundle final ──▶ copy ─────────▶ php nyuRun deploy start
  (code, examples,                            checks the server, makes the local
   libraries; no secret,                      settings from the examples, makes the
   no local setting)                          secrets, creates the users, checks all
                                            php nyuRun config …   (changes a setting)
                                            php nyuRun deploy check (before serving)
```

1. **Build** a copy for the place it goes to: the developer's machine,
   a server before production, production, or a place the project
   declares. Its settings (environment, errors shown, what is logged) are
   those of that place.
2. **Install** it: `deploy start` checks PHP and its extensions, makes
   the local settings from their examples, makes the secrets that are
   missing, creates the users (a users file, or the tables of a
   database), and runs the health check. It asks what it needs, each
   question with a default; a deployment script answers none. Nothing
   that exists is replaced.
3. **Configure**: `config` shows and changes the settings, each value
   checked, the former file kept, and a change that leaves the service
   unusable undone.
4. **Check**: `deploy check` tells, row by row, what is fine, what deserves
   a look and what fails; it ends with a failure code when a row fails, so
   that a deployment script stops.
5. **Run**: the users are managed from the command line or the `Users`
   run; `cleans` shows the logs, releases a held address, and removes old
   temporary files.
6. **Update**: a new copy replaces the code; the local settings, the
   secrets and the data of the server stay as they are.

---

## 6. Configuration and scalability

### 6.1 What a project chooses

Every choice below is a setting in a file, changed with `php nyuRun
config` and checked by `php nyuRun deploy check`. The files and their keys
are listed in the [Technical Specifications, chapter 6](techspecs.md#6-configuration-files).

| Choice | Options | Default |
|---|---|---|
| **Profile** of the service | an open API, an API for programs with keys, a web API with tokens, a site with sessions, a site of pages, or a profile of the project's own | open, JSON |
| **How a user signs in** | a password checked against a password file, the users file or the users table; an API key; a device token; a client certificate | the users file |
| **What the identity travels in** | a token signed by the service, a session cookie, a header, Basic authentication | a token |
| **Who reaches what** | the routes open to all, the least role of each route, the role an action asks for itself | set by the profile: the default one asks for no credential; the web API and site profiles close everything but the sign-in and its kin, and keep `Users` for managers |
| **Users** | the users file (read only) or a table of a database; sign-ups open or not; mails sent or codes handed over; length of passwords; two-factor codes | the users file, no sign-up |
| **Limits** | wrong attempts before an address or an account is held, and for how long; sign-ups per client and waiting at once; size of a request | held after 10 wrong attempts, for 10 minutes |
| **Replies** | JSON, YAML, XML, CSV, HTML, raw; the sites allowed to call from a browser | JSON, no other site |
| **Languages** | the language of the mails of a user who has none; the templates of each language | English |
| **Logs** | how much is written, where, and when a log is rotated | everything, in `temp/logs` |
| **Environment** | development, test, preview, production: what an error shows | development |

A choice that cannot be applied stops the service with an error, rather
than running in a weaker way: an unknown role, a profile with a key it
does not know, a sign-in that names a store that is not there.

### 6.2 How a service grows

A service starts small and grows without being rewritten:

| From | To | How |
|---|---|---|
| users in a file | users in a database, with self-service passwords, sign-ups, mails, devices | one command moves them, with their passwords, roles and two-factor secrets (`deploy store native`); the file is kept, so the move can be undone |
| one database | several, of several kinds | each named in the settings; each model names the one it uses |
| one application | many | a folder each in `runs/`; models shared by all in `runs/Shared` |
| one service | several working together | one issues the tokens, the others check them; applications call each other with keys (4.4) |
| one server | development, preview, production, integration | a copy built for each, its settings its own (5.11) |
| the framework's tools | the project's own | a credential check or a carrier of its own, a command of its own, its own mail templates and pages |

### 6.3 Running on several servers

The service holds no state of its own between two requests, except what
follows. A project that runs on several servers behind a load balancer
plans for each:

| State | Kept in | On several servers |
|---|---|---|
| **Tokens** | the token itself, signed | any server checks it, with the same key |
| **Sessions** | the files of PHP on the server | a shared folder or store for the sessions, or the balancer sends a user to the same server |
| **Counts of wrong attempts** | files in `temp/limits` of each server | each server counts its own: an address is held by the server that saw the attempts |
| **Accounts held, users, devices** | the database | shared by all |
| **Logs** | the files of each server | gathered by the host, if wanted |
| **Settings and secrets** | the files of each server | the same on all, made once and copied by the host |
| **Config cache** | `temp/cache` of each server | each server makes its own; after a change of the settings, `php nyuRun config cache` on each |

### 6.4 What is not there yet

- **No cache of data replies**: each request reads its data. Static
  files only are cached by the clients (304).
- **No limits by route**: the limit on attempts is by address and by
  account, for the whole service.
- **No sockets**: every exchange is a request and its reply.
- **The forwarded host** is not read behind a proxy: the service knows
  the host its proxy calls it on, not the one the client asked for.

They are told with the rest of what is left for later in the
[Technical Specifications, 1.5](techspecs.md#15-what-is-left-for-later).

---

## 7. Performance results

### 7.1 What was measured

Five frameworks were given the same two applications, each written the
way that framework is normally used: its own router, validation, database
layer and production settings. nyuRuns is the reference: every other one
gives the same statuses and the same data for the same requests.

| Framework | Version | Database layer |
|---|---|---|
| nyuRuns | 1.0 | its models, `DBLst` and `DBRec` |
| Slim | 4.15.3 | plain PDO, PHP-DI |
| Flight | 3.19.5 | its own PDO wrapper |
| Laravel | 13.40.0 | Eloquent models |
| Symfony | 8.1.11 | Doctrine ORM entities and repositories |

**The applications.** A light one, a list of tasks without sign-in, and a
complex one, projects of several users, each request signed with a token
(HS256). Ten scenarios:

| | Scenario | Does |
|---|---|---|
| L1 | list | 50 tasks, page 10 |
| L2 | read | one task |
| L3 | create | a task, validated, read back |
| L4 | update | a task, read back |
| C1 | my projects | the projects of the user, with its role in each |
| C2 | tasks, filtered and sorted | 50 tasks of a project, by status and sort order |
| C3 | project stats | the tasks of a project counted by status and by user |
| C4 | task with comments | a task, its assignee, its sub-tasks, its last 20 comments |
| C5 | create task | in a project, its fields checked, the assignee a member |
| C6 | add comment | to a task, read back |

**The measure.** Each scenario is called 1000 times, one request at a
time, after 100 calls to warm up. The figure is the CPU time used by the
web server, in which PHP runs, divided by the number of requests: what a
request costs the server, whatever the waiting of the network. The CPU
time of MySQL is measured apart.

**The machine.** One computer: AMD Ryzen 5 5500 (6 cores), 16 GB of
memory, Windows 11. PHP 8.5.12 in Apache, OPcache on without checking the
files (as in production), JIT off; MySQL 5.7.24. Every framework in its
production mode: optimised class maps, caches warmed, no debug. nyuRuns
ran as its final bundle builds it: `PROD`, logs in `MINI`, the config and
table caches on.

### 7.2 Results

CPU time of the web server per request, in milliseconds, run of
7 November 2026 (1000 requests per scenario, none failed):

| Scenario | Flight | nyuRuns | Slim | Symfony | Laravel |
|---|---|---|---|---|---|
| L1 list | 1.92 | 2.50 | 2.09 | 5.20 | 7.92 |
| L2 read | 1.84 | 2.13 | 2.38 | 4.80 | 6.61 |
| L3 create | 1.89 | 3.20 | 3.05 | 6.78 | 8.06 |
| L4 update | 2.16 | 2.67 | 3.17 | 6.95 | 7.86 |
| C1 my projects | 1.70 | 2.45 | 2.53 | 5.52 | 7.39 |
| C2 tasks, filtered | 1.95 | 2.73 | 2.56 | 8.73 | 9.91 |
| C3 project stats | 2.13 | 2.83 | 2.59 | 6.14 | 7.70 |
| C4 task with comments | 1.98 | 2.92 | 2.42 | 6.39 | 9.63 |
| C5 create task | 2.20 | 2.58 | 3.11 | 7.81 | 8.94 |
| C6 add comment | 2.02 | 2.66 | 2.84 | 7.19 | 9.69 |
| **Average** | **1.98** | **2.67** | **2.67** | **6.55** | **8.37** |
| MySQL, average | 1.34 | 1.47 | 1.30 | 1.45 | 1.83 |

What it shows:

- **nyuRuns costs what a micro-framework costs**: as much as Slim, about a
  third more than Flight, which does the least around a request.
- **It costs about 2.5 times less than Symfony, and 3 times less than
  Laravel**, though it does what they do for these applications: routing,
  validation of the inputs, signed tokens, models instead of queries.
- **The database costs the same for all**: nyuRuns sends the statements
  the others send (one to read a task, five for a task with its comments).

### 7.3 How nyuRuns got there

The first complete run, on 5 November, gave nyuRuns 6.03 ms per request on
average. Between that run and the one above, the framework was tuned
without changing what it does:

- fewer statements per request: a table described once, a model made only
  when an action asks for it;
- a complete class map in the stage and final bundles;
- no log line for each access in production; the sign-ins, sign-outs and
  refusals are still written;
- the configuration, the routes and the descriptions of the tables read
  once, then from a cache (`CNF_CACH`, `TBL_CACH`).

The average went from 6.03 ms to 2.67 ms: less than half.

### 7.4 What these figures do not tell

- **One machine, one system.** Windows and Apache with PHP inside it;
  another system, PHP-FPM or another web server would give other figures,
  and the order between the frameworks may differ.
- **One request at a time.** No load test: the figures are the cost of a
  request, not the number of requests a server holds at once. A first
  load test was run on 5 November, but on this machine most of its
  requests failed for lack of connections, so its figures are not used.
- **The time a client waits** (14 to 28 ms here, depending on the
  framework) is mostly the connection delay of the machine, the same for
  all, and is left out of the comparison.
- **Two applications.** Other kinds of work (large documents, pictures,
  many joins) were not measured.

---

## 8. Development guidelines

How to design a service on nyuRuns, from what the business needs to what
the front end calls. The technical rules (secrets, bundles, inputs,
naming) are in the [Technical Specifications, chapters 2 and
12](techspecs.md#2-guidelines-and-recommendations).

### 8.1 From the business to the runs

1. **List the things the business keeps**: members, orders, shows,
   invoices. Each becomes a table, and a model of it.
2. **Group them by subject**: what is managed together, by the same
   people, belongs to one run (`Members`, `Orders`, `Shows`). A run
   that grows to hold unrelated things is split.
3. **Give each thing its owner**: one run, and in a group of services one
   service, writes it; the others read it through that owner's routes.
4. **Put what every run needs in `runs/Shared`**: a model used by
   several runs, a credential check of the project's own.

### 8.2 From the runs to the routes

- **One address per resource**, the method telling what is done:
  `GET` reads or lists, `POST` creates, `PUT` or `PATCH` changes,
  `DELETE` removes (3.1). Declare a route per resource, with a target per
  method, rather than one address per verb.
- **Name what the front end sees**: the routes are the contract with the
  front end; a run can be reorganised behind them without the front end
  knowing.
- **Answer with the right status**: 201 for what is created, 204 for an
  empty list, 404 for what is not there, 409 for a change that conflicts,
  400 for an input that is not valid. The front end decides on the
  status, not on the message.
- **Return what the front end shows**, and no more: a list gives the
  fields of a list, a record those of its page; a password hash, a code
  or a token never leave the service.

### 8.3 Inputs

- **Declare the inputs of every action**, each with its form; what is not
  declared does not reach the action.
- **Mark as required** what the action cannot do without: the request is
  refused before the action runs.
- **Keep a search a search**: a value from the front end is compared as
  it is; offer a match only on the fields where it makes sense.
- **Send passwords in the body**, never in the address.

### 8.4 Access

1. **Choose the profile** by who calls: programs with keys, a web API
   with tokens, a site with sessions.
2. **Open on purpose**: list the routes anyone may reach (the sign-in,
   the sign-up, a public page); every other one asks for a credential.
3. **Plan the roles**: `member` for the users, `system` for the staff,
   `manage` for those who manage the users. Give each route the least
   role it needs, in the profile; an action that needs more asks for it
   itself.
4. **Let the front end hide what a user cannot reach**: the service
   answers 404 to a role not held, as to an address that does not exist,
   so the front end should not offer it.
5. **Give machines and partners their own credentials**: a device token
   for a machine, an application key for a partner's application, each
   revocable alone, never a person's password.

### 8.5 Users

- **Start with the users file** while the users are few and fixed; move
  to the database when they must change their password, sign up, receive
  mails or hold devices (6.2).
- **Send the codes by mail** when the service has users outside the
  team: activation, sign-up and resets then need no manager in between.
- **Open the sign-ups only with a review**: every sign-up waits for a
  manager; set the bounds to what the managers can review.
- **Write the mails in the users' languages**: give each run its own
  templates, with its words and look, for each language its users hold.
- **Keep two managers at least**: the last one cannot be locked or
  removed, but a manager cannot reset itself either; a lost password then
  needs the mail of a forgotten password, another manager, or the command
  line.

### 8.6 Data

- **Name the tables as the models expect** (columns of 8 characters,
  `id_<table>`, `<table>_id`): the relations are then found without being
  told.
- **Declare the children** a record is shown with, and those removed with
  it; the removal is then whole or nothing.
- **Bound the lists**: always read a page of rows, with a sort the user
  can follow, rather than a whole table.
- **Use a document format for exchanges**: read an import (CSV, Excel)
  into its shape, check it, then save it through the models; write an
  export the same way.

### 8.7 From development to production

- **Develop with the mails written into files**, and the errors shown.
- **Test each run**: its actions, its statuses, the refusals of its
  roles; the framework's own suite shows how (Technical Specifications,
  chapter 13).
- **Ship a copy built for each place**, check it on its server before it
  serves, and keep the secrets on that server only (5.11).
- **Read the logs**: the refusals, the removals, the mails not sent tell
  what the users met.

---

## A. Replies and statuses

What a client receives from the framework and its delivered runs. An
error is written `{"error": "<message>", "code": <status>}` in the format
of the service; a site may answer it with its own error page. The
messages are in English and meant for developers: a front end decides on
the status, and words its own message for the user. A run adds its own
messages to its statuses.

### A.1 Success

| Status | When | Body |
|---|---|---|
| **200** | the request is done | the data of the action; a file or a page |
| **201** | a resource is created | what the action gives: for a user, its name, and its code when no mail carries it |
| **202** | a request is accepted, whatever its outcome: a sign-up, a forgotten password | `{"asked": true}` |
| **204** | done, with nothing to send: an empty list, a preflight from another site | none |
| **206** | a part of a file, as `Range` asked | that part |

The replies of the `Auth` run:

| Action | Body on success |
|---|---|
| `auth/login` | `{"token": "<the token>"}`, or `{"logged": true}` with the session cookie |
| `auth/logout` | `{"logged": false}` |
| `auth/activate` | `{"active": true, "stat": "active"}`, or `{"active": false, "stat": "review"}` for a sign-up |
| `auth/password`, `auth/recovr` | `{"changed": true}` |
| `auth/langs` | `{"lang": "<the language>"}` |

### A.2 Redirections

| Status | When |
|---|---|
| **301, 302, 303, 307, 308** | a route or an action sends the client elsewhere (`Location`) |
| **302** | a visitor without a credential asks for a page of a site that has a sign-in page: sent to it |
| **304** | the client already holds the static file it asks for |

### A.3 Errors of the client

| Status | Message | When |
|---|---|---|
| **400** | `Missing or invalid input: <fields>` | a required input is missing or not valid |
| | `Password sent in the query string` | a password came in the address |
| | `Invalid user or pass`, `Invalid user, code or pass`, `Invalid user or mail`, `Invalid former or pass`, `Invalid user` | an input of an `Auth` or `Users` action is not a text |
| | `Password too short: …`, `Password too long: …`, `Password holding a control character`, `Password identical to the user name`, `Password identical to the current one` | a new password breaks a rule |
| | `Invalid user name`, `Invalid mail address`, `Invalid role`, `Invalid status`, `Invalid language`, `Invalid filter` | a value given to the `Users` run is not one |
| **401** | `Invalid credentials` | a wrong name or password, or an account that is not active, at sign-in |
| | `Verification code required`, `Invalid verification code` | the two-factor code is missing or wrong |
| | `Invalid token`, `Expired token` | the token is forged or ended |
| | `Account not active` | the account behind a session or a token was locked meanwhile |
| | `Invalid activation`, `Invalid code` | a wrong name or code at activation or recovery, or one that ended |
| | `Invalid API key`, `No API key provided`, `Invalid app key`, `Not logged in`, `No active session`, `Missing Bearer Authorization header`, … | the credential the profile asks for is missing or wrong |
| **403** | `Forbidden` | an application key reaches a route it does not list, or a role above its cap; a call from the command line without the role |
| **404** | `Not Found` | nothing answers the address; the application, the action, the file or the page is not there; the role is not held; a feature that is off (sign-ups, forgotten passwords) |
| | `User not found` | the `Users` run is asked for a user that does not exist |
| **405** | `Method Not Allowed` | the route or the action does not accept the method; the reply tells the ones it does (`Allow`) |
| **409** | `User name already taken`, `Mail address already used` | the name or the address belongs to another account |
| | `A manager cannot do this to its own account` | a manager locks, resets, removes itself, or changes its own role |
| | `The last active manager cannot be locked` (… `removed`, `reset`, `given another role`) | the service would be left without a manager |
| | `A locked user is unlocked before it is reset`, `A pending user is neither locked nor unlocked`, `A user in review is approved or removed`, `User not awaiting review`, `User already …`, `No reset for this user` | the change does not fit the status of the account |
| **413** | `Payload Too Large` | the body is larger than the service accepts |
| **429** | `Too many attempts` | the address is held after too many wrong attempts, or asked too often (sign-ups, resets); `Retry-After` tells how long to wait |

### A.4 Errors of the service

| Status | Message | When |
|---|---|---|
| **500** | `Internal Server Error` | an error in the code; in production, never more |
| | `Access misconfigured` | the access settings cannot be applied |
| | `Login unavailable`, `User management unavailable`, `Credential store unavailable`, `User cannot be written` | the store of users cannot be reached or written |
| **501** | `Tokens are issued by another server` | the service only checks tokens, and issues none |
| **503** | `Sign-ups closed for now` | too many sign-ups are waiting for review |

---
