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

### 1.1 What this document covers

This document describes what nyuRuns does and how its processes run: who
uses a service built on it, what each of them can do, and the steps of
each process, from signing in to shipping a project. It's about
behaviour, not code. Code names only show up where you need them to find
a setting or a command.

It's written for developers, and for the analysts and product owners who
decide what a service built on nyuRuns should do. How the framework
itself is built is covered in the
[Technical Specifications](techspecs.md).

### 1.2 nyuRuns in a few words

nyuRuns is the back end of an application. A front end (a web page, a
mobile app, another program) sends it requests over HTTP, and nyuRuns
replies with data, a file or a page. In between, it takes care of the
whole request. It:

- works out what's being asked for, from the request's URL;
- checks who's asking, and whether they're allowed to;
- keeps only the values the request may carry, each in the right format;
- runs the application asked for, which reads and writes its data;
- writes the reply in the format the front end expects, errors included.

Around all that, it provides the tools a service needs: users and their
access, databases, documents, images, sounds, mail, logs, and a command
line to install, configure, test and ship a project.

### 1.3 Who uses a service built on nyuRuns

| Actor | Who | What they do |
|---|---|---|
| **Visitor** | anyone not signed in | reaches whatever is open to everyone: a public page, signing in, signing up, resetting a forgotten password |
| **Standard user** | a signed-in user with the `standard` role | uses the service with the rights of the lowest role |
| **Advanced user** | the `advanced` role | gets what the service keeps for its staff |
| **Delegate** | the `delegate` role | someone the managers hand part of their power to; what that covers is up to the project |
| **Manager** | the `managers` role | manages the users: creates, approves, locks, resets and removes them, and assigns roles |
| **Device** | a machine with its own token, acting for a user | talks to the service with no one signing in: a kiosk, a sensor, a home server |
| **Application** | another program holding a key | calls the service on behalf of one of its own users |
| **Administrator** | the person running the server | installs, configures, checks and updates the project from the command line |

Roles are ranked: each one has all the rights of the roles before it, from
`standard` to `advanced`, `delegate` and `managers`. What each role can reach is up to the project,
route by route.

### 1.4 What version 1.0 covers

Version 1.0 handles the whole request and offers several ways to sign in
and to carry an identity. It manages users and their access mails, and
supports five kinds of database, documents in the usual text, data and
office formats, images, sounds, mail, and the command line. Some parts of
the original vision are left for version 1.1, and a few were dropped. The
full list lives in one place, the
[Technical Specifications, 1.4 to 1.6](techspecs.md#14-what-version-10-holds).
Chapter 2 goes through it from the angle of needs.

### 1.5 Terms used in this document

| Term | Means |
|---|---|
| **service** | a project built on nyuRuns, as its users see it |
| **front end** | whatever sends the requests: a web page, a mobile app, a program |
| **run** | one application of the project, such as `Users` or `Shows` |
| **action** | what a run does for one request: list, view, create, update, remove |
| **route** | the URL that leads to an action, a page or a file |
| **preset** | the service's profile: how it's reached, how access is checked, and how it replies |
| **store** | where users are kept: a users file, or a database table |
| **role** | what a user can reach: `standard`, `advanced`, `delegate`, `managers` |
| **status** | where an account stands: `awaiting`, `inReview`, `approved`, `disabled` |

---

## 2. Requirements highlights

### 2.1 The original vision

The nyuRuns Requirements describe it as a micro-framework for building and
deploying the back end of whole applications as RESTful services. The goal
is to make building APIs simpler by splitting the work: developers write
what their business needs, and the framework handles the rest. It rests on
four principles: model-driven development (MDD), keeping it simple (KISS),
REST, and the operations every data source gets (create, read, update,
delete and list, or CRUDL).

This chapter goes through the needs that vision names, and says where
version 1.0 stands on each. The Requirements themselves have their own
document.

### 2.2 The needs, and where 1.0 stands

| Status | Means |
|---|---|
| **Met** | 1.0 does what the need asks |
| **Partly met** | 1.0 does part of it, and the table says which part |
| **Version 1.1** | left for the next version |
| **nyuTron** | moved to nyuTron, the separate project of tools around nyuRuns |

| Need | Status | In 1.0 |
|---|---|---|
| Build and deploy the back end of a whole application, as RESTful services | Met | resources reached by their path, methods that say what's done, statuses that say how it went; a command line installs, checks and ships the project |
| Handle the whole HTTP request, so developers write less | Met | routing, access, inputs, the action, the reply and its errors, in one sequence (5.1) |
| Data-model-driven components (MDD) | Met | an application works through models of its data, records and lists, never through queries |
| CRUD operations out of the box | Partly met | every data source gets its CRUDL operations through the models, but an application still writes a short action for each. Serving a table through configuration alone isn't in 1.0: generating those actions is the job of nyuTron's scaffolding |
| Other data sources than databases: XML, JSON, INI and text files | Partly met | files in those formats, and many more, are read and written as documents by an action. Using them as a configured source of inputs is left for 1.1 |
| Routing of every request, with fallbacks | Met | routes are found from the path, or declared by the site or by each run; a declared route can also redirect, or serve a file, a folder or a page; a site can have its own error pages |
| Access control (ACL) and tokens | Met | ranked roles, asked for by route or by action; signed tokens, sessions, API keys, client certificates; several user stores |
| Data handlers: databases, files, images, sockets | Partly met | five kinds of database, documents, images, plus sounds, which the vision didn't mention. Sockets are left for 1.1 |
| Output with cache features | Partly met | static files are sent with their version and date, and a client that already has them gets a 304. Data replies aren't cached |
| Error handling | Met | errors are written in the reply's format, logged, and never detailed in production |
| A layered (multi-tier) architecture | Met | the request, access, the application, its models and their stores are separate layers (chapter 4) |
| Keep it simple | Met | a first install needs no database, and what a project doesn't use isn't loaded |

### 2.3 What 1.0 adds to the original vision

Building the framework turned up needs the original vision didn't mention.
Version 1.0 covers them:

- **Users and their life cycle**: activation with a code, statuses,
  locking, roles, passwords chosen and reset, sign-ups reviewed by a
  manager, all through an application or the command line (5.3 to 5.5).
- **Access mails**, in each user's language.
- **Two-factor codes**, from an authenticator app, or sent by mail
  (native userbase only).
- **Devices**, and **applications calling for their users**, each with its
  own credential (5.6 and 5.7).
- **A limit on wrong attempts**, by address and by account.
- **Documents** in the usual text, data and office formats, read into one
  shape and written back from it.
- **Images and sounds**, read, edited and converted.
- **Mail**, through an SMTP server, PHPMailer, or into files.
- **The command line**, to install, configure, test, manage and ship a
  project, with a copy built for each environment.

### 2.4 What changed, and why

The parts of the vision that 1.0 doesn't fully meet were set aside to
finish the version sooner, not abandoned. Files as configured sources and
sockets come with version 1.1, and generating CRUDL actions comes with
nyuTron. The cache for data replies is the only need with no version
planned yet. The full list of what's left for later is in the
[Technical Specifications, 1.5 and 1.6](techspecs.md#15-what-is-left-for-later).

---

## 3. Software design overview

### 3.1 The principles, as a service shows them

**Model-driven development (MDD).** A service is described by its data.
Each kind of thing it keeps (a user, a show, an invoice) is a model: a
record of a table, or a list of them. Applications read and write through
the models and never write a query. Switching to another database, or
another kind of database, leaves the applications untouched, within what
that kind can do (MongoDB, for one, has no joins).

**Keep it simple (KISS).** A service starts with what it needs and nothing
more. A first install keeps its users in a file and needs no database, and
what a project doesn't use isn't loaded. Each part does one thing, and the
settings are plain files.

**REST.** Every resource has its own URL, and the request's method says
what's done with it. The reply's status says how it went, whatever the
format of its body:

| Method | Done | Usual statuses |
|---|---|---|
| `GET` | read one resource, or list them | 200 with data, 204 when the list is empty, 404 |
| `POST` | create a resource, or run an operation | 201 created, 202 accepted, 400 |
| `PUT`, `PATCH` | change a resource | 200, 404, 409 when the change conflicts |
| `DELETE` | remove a resource | 200 or 204, 404 |

A route can give each method its own action, so one URL covers a
resource's whole life.

**CRUDL.** Every data source gets the same five operations: create, read,
update, delete and list. A list can be filtered, sorted, limited and
joined to another table, and the filter is written the same way for every
kind of database.

### 3.2 The choices behind the design

- **One entry, one profile.** Every request comes in through one door, and
  the entry says which profile (preset) applies: how the service is
  reached, who gets in, and how it replies. The profile is chosen once for
  the service, and changed with one command.
- **Declared, not coded.** What a service accepts is declared in files:
  its routes, the inputs of each action, the roles of each route, its
  settings. You can tell what a service does without reading its code.
- **Closed by default.** In a profile that asks for credentials, every
  route needs one unless the profile opens it. A setting that can't be
  applied refuses every request rather than letting one slip through.
  Even the profile of a first install asks for a token: only the one for
  a site of pages (`statics`) is open to everyone.
- **Tight-lipped with clients.** A client is told what it needs, and
  nothing more. What it can't reach is "not found", a wrong name and a
  wrong password get the same refusal, and an error in production carries
  no detail. The log has the rest.
- **One shape of reply.** Data comes in the profile's format (JSON unless
  told otherwise), and an error comes as `{"error": ..., "code": ...}` in
  that same format.
- **Start light, grow later.** A service moves from a users file to a
  database, from one database to several, and from one application to
  many, without being rewritten.
- **The host runs the server.** The framework takes over the request the
  moment PHP receives it. The web server, TLS and the firewall belong to
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
| **Route** | the URL that leads to an action, a page, a file or a redirection | `shows/view/12`, `/notes/12` |
| **Preset** | the profile of an entry: access, inputs, routing, replies | `restapi` for programs, `website` for a browser |
| **Inputs** | the values a request carries, kept only when the action declares them, each in the right format | a name, a date, an address |
| **Model** | a record or a list of a table | a show, the list of shows |
| **Store** | where users are kept | the users file, the users table |
| **Role** | what a user can reach, ranked: `standard`, `advanced`, `delegate`, `managers` | a manager has every right of the roles before it |
| **Status** | where an account stands | see below |
| **Reply** | the status, plus data, a file, a page or an error | `200` with the list of shows |

Account statuses:

| Status | Meaning | Can sign in |
|---|---|---|
| `awaiting` | created, waiting for its code to be used | no |
| `inReview` | signed up on their own and confirmed, waiting for a manager | no |
| `approved` | in use | yes |
| `disabled` | stopped by a manager | no |

Chapter 5.3 shows how an account moves from one status to another.

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
| **Entry** | takes every request from the web server and applies the service's profile | 5.1 |
| **Routing** | works out what the URL asks for: an action, a page, a file, a redirection | 5.1 |
| **Access** | decides who's asking and whether they're allowed in: credentials, roles, limits on attempts, two-factor codes | 5.2 |
| **Users** | keeps the accounts and their life cycle: creation, activation, statuses, roles, passwords, sign-ups, access mails | 5.3 to 5.5 |
| **Applications** | the project's runs: their actions, the inputs they accept, the models of their data | 5.8 |
| **Data** | databases through models; documents, images and sounds read, edited and written | 5.8, 5.9 |
| **Mail** | sends mails through the project's accounts | 5.10 |
| **Reply** | writes the answer in its format: data, a file, a page or an error | 5.1, appendix A |
| **Logs** | traces what happens, one file per topic: errors, access, the framework, the command line, each application | 6 |
| **Command line** | installs, configures, manages users and devices, tests and ships the project | 5.11 |

Only what a request needs gets loaded: a request that touches no database
opens none, and the data and mail blocks load only for the applications
that use them.

### 4.3 The outside systems

| System | Needed | For |
|---|---|---|
| **Web server** | always | takes the requests and hands them to PHP; handles TLS, or leaves it to a proxy in front |
| **Reverse proxy, CDN** | optional | sits in front of the web server; the service trusts the address it passes on only when told to |
| **Database servers** | optional | the applications' data, and the users once they leave the users file |
| **Mail server** | optional | the access mails, mailed two-factor codes, and the applications' own mails |
| **ffmpeg** | optional | converting and cutting sounds |
| **Authenticator** | optional, on the user's phone | two-factor codes, unless they're sent by mail |
| **Other applications** | optional | calling the service for their users, or checking the tokens it issues |

### 4.4 Several services together

A project can be a service on its own, or one of several working together:

- **One sign-in for several services.** One service issues the tokens,
  signed with its private key. The others hold only its public key and use
  it to check them. They issue no tokens themselves: their sign-in replies
  that tokens come from another server.
- **An application calling for its users.** A service gives another
  application a key and the routes it may call. That application then
  calls with its key and a token it signs for one of its own users, who
  never signs in to the service directly. The role it can act with is
  capped.
- **One owner per data**, the recommended rule when services share data.
  Each kind of data belongs to one service, and the others reach it
  through that service's routes, never through its database. The
  framework doesn't enforce this, but application keys make it practical.

### 4.5 Environments

A project lives in several places: the developer's machine, a
pre-production server, production, and any server of its own in between
(integration, a preview for a client). It's shipped to each one as a copy
built for that place. The copy holds the code and the example settings,
never the secrets or another place's settings. On each server, the
administrator installs the copy, makes its secrets and keys, and checks it
before it goes live (5.11).

---

## 5. Process implementation

Each process below is described as the steps a client and the service go
through, with the reply for each way out. Appendix A lists the statuses.

### 5.1 A request, from the URL to the reply

```
request
  │
  ├─ body too large ─────────────────────────────────────────▶ 413
  ├─ preflight of another site (OPTIONS) ────────────────────▶ 204, with the allowed headers
  │
  ▼ route
  ├─ nothing answers the URL ────────────────────────────────▶ 404
  ├─ the method is not one the route allows ─────────────────▶ 405, with the methods allowed
  │
  ▼ access
  ├─ the settings cannot be applied ─────────────────────────▶ 500
  ├─ the client is held after too many wrong attempts ───────▶ 429, with the time to wait
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

- A route open to everyone skips the access step, so anyone gets through.
- Whatever the client can't reach is always the same 404, whether the URL
  doesn't exist or the role isn't held. That way, a client can't map out
  what the service holds.
- The reply is written in the profile's format. An error is
  `{"error": "<message>", "code": <status>}`, and a site answers it with
  its own error page when it has one.

### 5.2 Signing in and out

**Signing in** is an action open to everyone, `auth/login`:

1. The front end sends the name and password (`userName`, `passWord`) with
   `POST`, in the request body. A password in the URL is refused (400),
   because URLs end up in logs and browser histories.
2. A client address held after too many wrong attempts is turned away at
   once (429).
3. The name and password are checked against the user store. A wrong
   name, a wrong password and an inactive account all get the same answer,
   `Invalid credentials` (401), and count against the client's address.
   The response time doesn't give away whether the name exists.
4. A user with two-factor codes must also send a code (`sentCode`):
   - **from an authenticator**: without one, the reply is
     `Verification code required` (401);
   - **by mail**: a first attempt without a code mails one to the user's
     address (one mail a minute at most), and gets the same
     `Verification code required` (401). The front end then sends the
     name, the password and that code.

   A wrong or expired code gets `Invalid verification code` (401) and
   counts against the address. Each code is accepted only once.
5. The service then hands over the identity the front end will carry: a
   **token** in the reply body, or a **session**, whose cookie goes out
   with the reply. A service that only checks tokens issued elsewhere
   issues none (501).

In the native userbase, an account whose password is wrong too many
times in a row is held for a while, whatever address the attempts come
from.

**Every request after that** carries the token (`Authorization: Bearer …`)
or the cookie. A token is valid until it expires, an hour in the example
settings. A session ends after a stretch of inactivity, or after a total
time. A user locked by a manager is refused straight away when the
profile checks the store on each request.

**Signing out**, `auth/logout`, ends the session and expires its cookie.
A token can't be withdrawn: the front end just forgets it.

**Signed-in users** can also change their own password (`auth/setpass`,
with the current one) and the language of their mails (`auth/usrlang`).

Signing in with any role above `standard` is traced, and so is each of
their accesses. Every refusal is traced with its reason.

### 5.3 The life of an account

```
     POST users, account makeUser           auth/signup (5.4)
       (by a manager)                        (by the visitor)
               │                                     │
               ▼  code mailed, or given              ▼  code mailed
          ┌──────────┐                          ┌──────────┐
    ┌────▶│ awaiting │                          │ awaiting │── code ended: removed
    │     └──────────┘                          └──────────┘
    │          │ auth/active:                        │ auth/active:
    │          │ code, chosen password               │ code, chosen password
    │          │                                     ▼
    │          │                                ┌──────────┐
    │          │                                │ inReview │── refused: removed
    │          │                                └──────────┘
    │          │                                     │ POST users/<user>/approve, account approves
    │          ▼                                     │ (by a manager)
    │     ┌──────────┐◀──────────────────────────────┘
    └─────│ approved │   lock    ┌──────────┐
   reset  │          │──────────▶│ disabled │
(manager) └──────────┘◀──────────└──────────┘
                         unlock
       remove: from any status, with the devices of the account
```

- **Creation**: a manager creates the account (`POST users`, or
  `account makeUser` on the command line) with its name and role, and
  optionally an email address and a language. It starts out `awaiting`, with
  an activation code. When the service sends mails and the user has an
  address, the code is mailed. Otherwise, the reply gives it once to the
  manager, who passes it on.
- **Sign-up**: a visitor opens an `awaiting` account on their own, when the
  service allows it (5.4).
- **Activation**: the user sends their name, the code and the password
  they choose (`auth/active`). An account created by a manager becomes
  `approved`, and one a visitor signed up for goes to `inReview`. A wrong code
  is refused just like a wrong name, and counted.
- **Review**: a manager approves the account (`POST users/<user>/approve`, or
  `account approves`) and it becomes `approved`, or refuses it by
  removing it. An account in `inReview` can't sign in, and can't be locked.
- **Locking**: a locked user can't sign in, and their session ends at
  their next request when the profile checks the store (5.2). Only an
  `approved` account can be locked.
- **Reset**: for a lost password, a manager puts the account back to
  `awaiting` with a new code. The old password and the personal token stop
  working at once. A locked account has to be unlocked first (409
  otherwise).
- **Roles, address, language**: a manager can change them at any time. A
  name and an address each belong to one account only (409).
- **Removal**: the account goes, along with its devices.
- **Guards**: managers can't lock, reset, remove or change the role of
  their own account, and the last active manager can't lose that
  standing.

**The codes** are ten characters, `XXXX-XXXX-XX`, with no look-alike
characters, and are read whatever their case and dashes. Each one is used
once, and only its hash is kept. A code handed over by a manager lasts 300
minutes. A mailed one lasts as many minutes as the settings say (15 by
default).

### 5.4 Signing up

When the service allows it and sends mails, visitors can open their own
account, and a manager decides whether it stays.

```
visitor                              service                                manager
   │  auth/signup: name, address        │                                      │
   │──────────────────────────────────▶│ 202, always                          │
   │                                    │ free: awaiting account, code mailed  │
   │                                    │ name taken: the address given told   │
   │                                    │ address taken: its owner told        │
   │  auth/active: code, password       │                                      │
   │──────────────────────────────────▶│ the account in review                │
   │                                    │ managers told when sign-ups pile up ─▶│
   │                                    │     POST users/<user>/approve        │
   │                                    │◀─────────────────────────────────────│
   │                                    │ the account approved                 │
```

1. The visitor gives a name and an email address. The answer is 202
   whatever happens, so no one can find out which names or addresses are
   taken. A free name and address create an `awaiting` account, and a code
   goes to the address. If the name is already taken, the address given is
   told so; if the address is, its owner gets a mail saying someone tried
   to use it.
2. The visitor confirms the address with the code and chooses a password
   (`auth/active`), and the account goes to `inReview`.
3. A manager approves it (`POST users/<user>/approve`, or `account approves`), and
   it becomes `approved`. Refusing it means removing it.

**Limits**: a client can only sign up so many times in a given period
(429 beyond that), and only so many sign-ups can wait at once (503 beyond
that). Managers get a mail when the waiting count reaches the set levels.
A sign-up whose code expired unused is removed. Without sign-ups allowed,
or without mail, `auth/signup` is not found (404).

### 5.5 A forgotten password

```
user ── auth/forgot: name or address ──▶ 202, always
                                           an approved account with an address: a code mailed,
                                           the password kept as it is
user ── auth/recover: name, code, new password ──▶ the new password set
```

1. The user gives their name or address (`auth/forgot`). The answer is
   202 whoever the user is, or isn't, and each request counts against the
   client's address.
2. An approved account with an address is sent a code. Its password keeps
   working, so a request made by someone else changes nothing until the
   code is used.
3. The user sends their name, the code and their new password
   (`auth/recover`). A wrong or expired code is refused (401), and counted.

Without mail, `auth/forgot` is not found (404). A manager then resets the
account instead (5.3), which stops the old password at once.

### 5.6 Devices

A device is a machine that calls the service with no one signing in: a
kiosk, a sensor, a home server. It acts on behalf of a user of the native
userbase, with its own token.

1. The administrator gives the device a token, with a name, the label the
   application gives it, and a cap: the highest role it acts with, `standard`
   unless told otherwise, never above its user's
   (`php nyuRun devices newToken <name> <user> <label> --roleTops=<role>`).
   The token is shown once, and only its hash is kept.
2. The device sends its token in a header with each request. It's
   accepted as long as the device isn't revoked and its user is active,
   and the time it was last seen is recorded.
3. On each request, the device acts with the lower of its cap and its
   user's current role, so demoting the user lowers all their devices at
   once. A device made before the caps has none and acts with its user's
   role; `install checkAll` names those whose user is above `standard`
   (`device caps`). What that role can reach is decided by the routes, just
   as for a user.
4. A device never changes its user's account: changing the password, or the
   address of its own user, needs the password login, and a device token is
   refused there with a 403.
5. The administrator lists the devices (`devices fullList`) and can
   revoke one (`devices revokeIt`). A revoked device is refused from its next request,
   and stays in the list with the time it was revoked. A device whose user
   is locked is refused, and one whose user is removed goes with them.

Devices need the native userbase: a service with devices in use can't
move its users to the users file until they're revoked.

### 5.7 An application calling for one of its users

Another application (a mobile app, a partner's portal) calls the
service on behalf of its own users, who never sign in to the service
itself.

```
administrator ── php nyuRun appkeys makeKeys <app> --routeSet=… --publFile=… --roleTops=… ──▶ key, shown once
application   ── request: its key in X-App-Key, and a token it signed for its user ──▶ service
service       ── key known? token signed by that application? route listed? role held? ──▶ action
```

1. **The administrator registers the application**: its name, the
   routes it may call, the public key that checks its tokens, and the
   highest role its users may act with. The service hands over a key,
   shown once, and keeps only its hash.
2. **The application calls** with its key in a header of its own
   (`X-App-Key` by default). As the bearer token, it sends a token it
   signed with its private key for one of its users, carrying that user's
   name and role, and an expiry.
3. **The service checks**, in this order:
   - the key: a wrong one is a 401, counted against the address;
   - the token: it must be signed by that application, not expired, and
     name this project's audience when one is set;
   - the route: one the key doesn't list is a 404;
   - the route's role: a 404 when it isn't held.

   The role is never higher than the cap set for the application.
4. **The action runs** for that user, and is told which application
   called. The user belongs to the application: the service doesn't look
   them up in its own user store.

A write (any method other than a read) is traced as a notice, a read as
information, and a refused route as a warning. The administrator can
change an application's routes, and revoke its key, in which case the
next request using it is refused. A route open to everyone ignores the
key.

### 5.8 Working with data

A run gives its data the five operations through its actions. With a
route for each method, one URL covers a resource:

| The front end sends | The action | The reply |
|---|---|---|
| `GET /shows?filters[search]=jazz&sorting=^date&indexes=20,40` | reads a list, filtered, sorted and cut | 200 with the rows, 204 when none matches |
| `GET /shows/12` | loads a record and its related rows | 200 with the record, 404 when there is none |
| `POST /shows` with the fields | fills a new record and saves it | 201 with its identifier, 400 for a field that is not valid |
| `PATCH /shows/12` with the fields | loads, changes and saves it | 200, 404, 409 when the change conflicts |
| `DELETE /shows/12` | removes it, with the child rows named | 200 or 204, 404 |

What happens along the way:

- **Inputs**: only the fields the action declares reach it, each in the
  right format, and a missing required one is a 400 before the action
  runs. A value that isn't valid for its format is emptied.
- **Filters**: a value from the request is compared as is, never as a
  pattern. A wildcard search is asked for through the field's name, and a
  value sent for it is quoted first, so a client can't widen what it's shown.
- **Lists**: rows are read a set number at a time (a default when none is
  asked for), from an offset, sorted by any field.
- **Records**: their related rows come along with them (a show and its
  events). A removal with its children happens in one transaction: all of
  it, or nothing. Each removal is traced, with the user who asked for it.
- **Files in a record**: an image or a file is stored exactly as sent,
  and sent back the same way, read piece by piece.

The names in this table come from an example: each run names its own
actions, and the routes it declares decide which method reaches which.

### 5.9 Documents and media

**Documents.** An action reads a document (a file sent with the request,
or one on the server) and gets its content in one of three shapes,
whatever its format:

| Shape | For | From |
|---|---|---|
| a tree of keys and values | settings, data exchanges | JSON, YAML, XML, INI |
| tables of rows and cells | sheets | CSV, TSV, Excel, OpenDocument spreadsheets |
| text blocks in order | documents to read | text, Markdown, HTML, Word, OpenDocument texts, PDF, ePub |

A calendar (iCalendar) gives its events, tasks and notes. The action
works on the shape, then writes it out in any format that takes it: a
spreadsheet read from Excel can be written as CSV, a Markdown text as
Word, PDF or ePub. A document whose content doesn't match its name is
refused.

**Images**: their type, size and what a photo records (date, camera,
location) can be read. They can be resized, cropped, rotated, and
stripped of that embedded data, and a drawing (SVG) can be turned into an
image.

**Sounds**: their type, length, tags and cover image can be read, and
their tags removed. A WAV or MP3 sound can be cut. With ffmpeg on the
server, any sound can be converted to another type, or cut.

**QR codes** can be made from a text, such as the address an
authenticator scans.

Every file a request brings is traced, whether it was received or failed,
and whether the request was allowed or not. A body bigger than the
service accepts is refused before it's read (413).

### 5.10 Sending mail

1. **The accounts**: the project lists its mail accounts, and the first
   one is the default. Each sends through an SMTP server, through
   PHPMailer (for attachments), or into files, which keeps every mail on
   the machine during development.
2. **A mail** has a sender, recipients, a subject, a text version and an
   HTML version. It's written from a template: a text file whose first
   line is the subject, plus an HTML file next to it, with their
   placeholders filled in and encoded.
3. **Sending**: the connection is encrypted and the server's certificate
   checked. Only a mail server on the same machine is spoken to
   unencrypted. An account can send a copy of every mail to other
   addresses, and a copy that fails is logged, not reported.
4. **What's traced**: each mail sent, or refused, in the framework's log.

**The access mails** are the ones from 5.2 to 5.5:

| Mail | Sent when | To |
|---|---|---|
| `activate` | an account with an address is created or reset | the user |
| `register` | a visitor signs up | the address given |
| `password` | a user asks for a new password | the user |
| `passcode` | a user with mailed two-factor codes signs in | the user |
| `usrtaken` | a sign-up asks for a name already in use | the address given |
| `emltaken` | a sign-up uses an address already in use | the owner of the address |
| `waitlist` | the number of waiting sign-ups reaches a set level | the managers |

Each one goes out in the user's language. If the user has none, it uses
the request's language when that user is the one asking, and otherwise
the service's default language. The framework ships them in English and
French, and a run can replace them with its own wording and look.

### 5.11 Installing, configuring and shipping a project

```
developer machine                           server
──────────────────                          ──────────────────────────────────────
php nyuRun bundles final ──▶ copy ────────▶ php nyuRun install complete
  (code, examples,                            checks the server, makes the local
   libraries; no secret,                      settings from the examples, makes the
   no local setting)                          secrets and keys, creates the users,
                                              checks all
                                            php nyuRun setting …  (changes a setting)
                                            php nyuRun install checkAll (before serving)
```

1. **Build** a copy for where it's going: the developer's machine, a
   pre-production server, production, or a place the project declares.
   Its settings (environment, errors shown, what gets logged) are those of
   that place.
2. **Install** it: `install complete` first asks what the project is: users,
   sign-in and data in one (`allInOne`), an authentication server issuing
   tokens for other projects (`authServ`), a resource server with no users
   of its own, accepting those tokens (`dataServ`), or a site with nothing to
   sign in to, serving pages and files only (`openSite`). Then it checks PHP and its
   extensions, makes the
   local settings from their examples, makes any missing secrets and token
   keys, creates the users (a users file, or database tables), and runs
   the health check. It asks for what it needs, suggesting a default for
   each question, and a deployment script can skip all of them. Nothing
   that already exists is replaced.
3. **Configure**: `setting` shows and changes the settings. Each value is
   checked, the previous file is kept, and a change that would leave the
   service unusable is undone.
4. **Check**: `install checkAll` says, row by row, what's fine, what's worth a
   look and what fails. It exits with a failure code when a row fails, so
   a deployment script stops there.
5. **Run**: users are managed from the command line or through the
   `Users` run. `showlog` shows the logs; `cleanup` releases a held
   address and clears out old temporary files.
6. **Update**: a new copy replaces the code, while the server's local
   settings, secrets, keys and data stay as they are.

---

## 6. Configuration and scalability

### 6.1 What a project chooses

Every choice below is a setting in a file, changed with
`php nyuRun setting` and checked by `php nyuRun install checkAll`. The files
and their keys are listed in the
[Technical Specifications, chapter 6](techspecs.md#6-configuration-files).

| Choice | Options | Default |
|---|---|---|
| **Profile** of the service | an open API, an API for programs with keys, a web API with tokens, a site with sessions, a site of pages, or a profile of the project's own | tokens, JSON |
| **How a user signs in** | a password checked against a password file, the users file or the users table; an API key; a device token; a client certificate; an application key, for a user of another application | the users file |
| **What the identity travels in** | a token signed by the service, a session cookie, a header, Basic authentication | a token |
| **Who reaches what** | the routes open to everyone, the minimum role of each route, the role an action asks for itself | set by the profile: the default, web API and site profiles close everything except signing in and its related actions, and keep `Users` for managers |
| **Users** | the users file (read only) or a database table; sign-ups open or not; codes mailed or handed over; password length; two-factor codes, by app or by mail | the users file, no sign-ups |
| **Limits** | wrong attempts before an address or an account is held, and for how long; sign-ups per client, and waiting at once; request size | an address held after 10 wrong attempts and an account after 5, for 10 minutes |
| **Replies** | JSON, YAML, XML, CSV, HTML, raw; the sites allowed to call from a browser | JSON, no other site |
| **Languages** | the mail language for users who have none; the templates for each language | English |
| **Logs** | how much is written, where, and when a log is rotated | everything, in `temp/logs` |
| **Environment** | development, test, preview, production: what an error shows | development |

A choice that can't be applied stops the service with an error, rather
than letting it run in a weaker state: an unknown role, a profile with a
key it doesn't know, a sign-in that names a store that isn't there.

### 6.2 How a service grows

A service starts small and grows without being rewritten:

| From | To | How |
|---|---|---|
| users in a file | users in a database, with self-service passwords, sign-ups, mails, devices | one command moves them, with their passwords, roles and two-factor secrets (`install moveUser nativeDb`). The file is left as it was, so sign-in can be pointed back at it, minus anything changed since |
| one database | several, of several kinds | each is named in the settings, and each model names the one it uses |
| one application | many | a folder each in `runs/`, and models shared by all in `runs/Shared` |
| one service | several working together | one issues the tokens and the others check them; applications call each other with keys (4.4) |
| one server | development, preview, production, integration | a copy built for each, with its own settings (5.11) |
| the framework's tools | the project's own | its own credential check or carrier, its own commands, its own mail templates and pages |

### 6.3 Running on several servers

The service keeps no state of its own between two requests, apart from
what's listed below. A project running on several servers behind a load
balancer has to plan for each:

| State | Kept in | On several servers |
|---|---|---|
| **Tokens** | the token itself, signed | any server can check it, as long as all have the same key |
| **Sessions** | PHP's files on the server | a shared folder or store for the sessions, or a balancer that sends each user to the same server |
| **Counts of wrong attempts** | files in `temp/limits` on each server | each server counts its own, so an address is held by the server that saw the attempts |
| **Held accounts, users, devices** | the database | shared by all |
| **Logs** | each server's files | gathered by the host, if wanted |
| **Settings, secrets and token keys** | each server's files | the same on all: made once and copied by the host. Running `install complete` on each server would give each its own keys, and their tokens wouldn't work on the others |
| **Config cache** | `temp/cache` on each server | each server makes its own; after a settings change, run `php nyuRun setting clrCache` on each |

### 6.4 What's not there yet

- **No cache for data replies**: each request reads its data. Only static
  files are cached by clients (304).
- **No limits by route**: the limit on attempts applies by address and by
  account, for the whole service.
- **No sockets**: every exchange is a request and its reply.
- **No forwarded host**: behind a proxy, the host the client asked for
  isn't read. The service knows the host its proxy calls it on, not the
  one the client used.

They're listed with everything else left for later in the
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

**The applications.** A light one, a task list with no sign-in, and a
complex one: projects shared by several users, with every request signed
by a token (HS256). Ten scenarios:

| | Scenario | Does |
|---|---|---|
| L1 | list | 50 tasks, page 10 |
| L2 | read | one task |
| L3 | create | a task, validated, read back |
| L4 | update | a task, read back |
| C1 | my projects | the user's projects, with their role in each |
| C2 | tasks, filtered and sorted | 50 tasks of a project, by status and sort order |
| C3 | project stats | the tasks of a project counted by status and by user |
| C4 | task with comments | a task, its assignee, its sub-tasks, its last 20 comments |
| C5 | create task | in a project, with its fields checked and the assignee a member of it |
| C6 | add comment | to a task, read back |

**The measurement.** Each scenario is called 1,000 times, one request at
a time, after 100 warm-up calls. The figure is the CPU time used by the
web server (which runs PHP) divided by the number of requests: what a
request costs the server, regardless of network wait. MySQL's CPU time is
measured separately.

**The machine.** One computer: AMD Ryzen 5 5500 (6 cores), 16 GB of
memory, Windows 11. PHP 8.5.12 in Apache, OPcache on without checking the
files (as in production), JIT off; MySQL 5.7.24. Every framework ran in
its production mode: optimised class maps, warmed caches, no debug.
nyuRuns ran the way its final bundle builds it: `PROD`, `MINI` logs,
config and table caches on.

### 7.2 Results

CPU time of the web server per request, in milliseconds, from the run of
7 November 2026 (1,000 requests per scenario, none failed):

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

- **nyuRuns costs what a micro-framework costs**: as much as Slim, and
  about a third more than Flight, which does the least around a request.
- **It costs about 2.5 times less than Symfony, and 3 times less than
  Laravel**, while doing what they do for these applications: routing,
  input validation, signed tokens, models instead of queries.
- **The database costs about the same for everyone**, with Laravel a
  little higher. nyuRuns sends the same statements the others send (one to
  read a task, five for a task with its comments).

### 7.3 How nyuRuns got there

The first complete run, on 5 November, put nyuRuns at 6.03 ms per request
on average. Between that run and the one above, the framework was tuned
without changing what it does:

- fewer statements per request: each table described once, and a model
  only made when an action asks for it;
- a complete class map in the stage and final bundles;
- no log line for a member's access in production (sign-ins, sign-outs,
  refusals and privileged accesses are still written);
- the configuration, routes and table descriptions read once, then from a
  cache (`CNF_CACH`, `TBL_CACH`).

The average went from 6.03 ms down to 2.67 ms: less than half.

### 7.4 What these figures don't tell you

- **One machine, one system.** Windows, with PHP running inside Apache.
  Another system, PHP-FPM or another web server would give other figures,
  and the frameworks might rank differently.
- **One request at a time.** There's no load test: the figures are what a
  request costs, not how many requests a server can handle at once. A
  first load test was run on 5 November, but on this machine most of its
  requests failed for lack of connections, so its figures aren't used.
- **The time a client waits** (14 to 28 ms here, depending on the
  framework) is mostly this machine's connection delay, the same for all,
  so it's left out of the comparison.
- **Two applications.** Other kinds of work (large documents, images,
  many joins) weren't measured.
- **Before the latest changes.** Since 7 November, nyuRuns has gained its
  own routes per run, a context for each action, a security revision and
  codes by mail. These figures haven't been measured again since.

---

## 8. Development guidelines

How to design a service on nyuRuns, from what the business needs to what
the front end calls. The technical rules (secrets, bundles, inputs,
naming) are in the [Technical Specifications, chapters 2 and
12](techspecs.md#2-guidelines-and-recommendations).

### 8.1 From the business to the runs

1. **List what the business keeps**: members, orders, shows, invoices.
   Each one becomes a table, and a model of it.
2. **Group them by subject**: what's managed together, by the same
   people, belongs in one run (`Members`, `Orders`, `Shows`). A run
   that grows to hold unrelated things should be split.
3. **Give each thing an owner**: one run writes it (and, in a group of
   services, one service), and the others read it through that owner's
   routes.
4. **Put what every run needs in `runs/Shared`**: a model used by several
   runs, or a credential check of the project's own.

### 8.2 From the runs to the routes

- **One URL per resource**, with the method saying what's done: `GET`
  reads or lists, `POST` creates, `PUT` or `PATCH` changes, `DELETE`
  removes (3.1). Declare a route per resource in the run's own
  `routes.rte`, with a target per method, rather than one URL per verb.
  The run is then reached through those routes only.
- **Name what the front end sees**: the routes are the contract with the
  front end. A run can be reorganised behind them, or mounted somewhere
  else by the profile, without the front end noticing.
- **Answer with the right status**: 201 for something created, 204 for an
  empty list, 404 for something that isn't there, 409 for a change that
  conflicts, 400 for an invalid input. The front end decides based on the
  status, not the message.
- **Return what the front end shows**, and nothing more: a list gives the
  fields of a list, a record those of its page. A password hash, a code or
  a token never leaves the service.

### 8.3 Inputs

- **Declare the inputs of every action**, each with its format. Whatever
  isn't declared doesn't reach the action.
- **Mark as required** what the action can't do without, so the request
  is refused before the action runs.
- **Keep a search a search**: a value from the front end is compared as
  is. Offer a match only on the fields where it makes sense.
- **Send passwords in the body**, never in the URL.

### 8.4 Access

1. **Choose the profile** by who's calling: programs with keys, a web API
   with tokens, or a site with sessions.
2. **Open routes on purpose**: list the routes anyone may reach (signing
   in, signing up, a public page). Every other one needs a credential.
3. **Plan the roles**: `standard` for users, `advanced` for staff,
   `managers` for those who manage the users. Give each route the minimum role it
   needs, in the profile. An action that needs more asks for it itself,
   and a whole run can check it once, in `setupForAction()`.
4. **Let the front end hide what a user can't reach**: the service
   answers 404 for a role not held, just as for a URL that doesn't exist,
   so the front end shouldn't offer it.
5. **Give machines and partners their own credentials**: a device token
   for a machine, an application key for a partner's application. Each
   can be revoked on its own, and none is ever a person's password.

### 8.5 Users

- **Start with the users file** while users are few and fixed. Move to
  the database when they need to change their password, sign up, receive
  mails or hold devices (6.2).
- **Send codes by mail** when the service has users outside the team:
  activation, sign-ups and resets then need no manager in between.
- **Open sign-ups only with a review**: every sign-up waits for a
  manager, so set the limits to what the managers can review.
- **Write the mails in the users' languages**: give each run its own
  templates, with its wording and look, for each language its users have.
- **Keep at least two managers**: the last one can't be locked or removed,
  but managers can't reset their own account either. A lost password then
  needs the forgotten-password mail, another manager, or the command line.

### 8.6 Data

- **Name tables the way the models expect** (10-character columns,
  `id_<singular>` for the identifier, `<singular>_id` for a link, such as
  `user_id`): the relations are then found without being declared.
- **Declare the children** a record is shown with, and those removed with
  it, so a removal is all or nothing.
- **Bound the lists**: always read one page of rows, with a sort the user
  can follow, rather than a whole table.
- **Use a document format for exchanges**: read an import (CSV, Excel)
  into its shape, check it, then save it through the models. Write an
  export the same way.

### 8.7 From development to production

- **Develop with mails written to files**, and errors shown.
- **Test each run**: its actions, its statuses, and the refusals of its
  roles. The framework's own suite shows how (Technical Specifications,
  chapter 13).
- **Ship a copy built for each place**, check it on its server before it
  goes live, and keep the secrets and keys on that server only (5.11).
- **Read the logs**: refusals, removals and mails not sent show what
  users ran into.

---

## A. Replies and statuses

What a client gets back from the framework and the runs it ships. An
error is written `{"error": "<message>", "code": <status>}` in the
service's format, and a site may answer it with its own error page
instead. Messages are in English and meant for developers: a front end
decides based on the status, and writes its own message for the user. A
run adds its own messages to its statuses.

### A.1 Success

| Status | When | Body |
|---|---|---|
| **200** | the request is done | the action's data, or a file or a page |
| **201** | a resource is created | what the action gives back: for a user, their name, plus their code when no mail carries it |
| **202** | a request is accepted, whatever its outcome: a sign-up, a forgotten password | `{"asked": true}` |
| **204** | done, with nothing to send: an empty list, a preflight from another site | none |
| **206** | part of a file, as `Range` asked | that part |

The replies of the `Auth` run:

| Action | Body on success |
|---|---|
| `auth/login` | `{"token": "<the token>"}`, or `{"logged": true}` with the session cookie |
| `auth/logout` | `{"logged": false}` |
| `auth/active` | `{"active": true, "stat": "approved"}`, or `{"active": false, "stat": "inReview"}` for a sign-up |
| `auth/setpass`, `auth/recover` | `{"changed": true}` |
| `auth/usrlang` | `{"lang": "<the language>"}` |
| `auth/keyset`, at `/.well-known/jwks.json` | the public keys that check the tokens (RS256); a 404 when tokens are signed with a secret (HS256) |

### A.2 Redirections

| Status | When |
|---|---|
| **301, 302, 303, 307, 308** | a route or an action sends the client elsewhere (`Location`) |
| **302** | a visitor without a credential asks for a page of a site that has a sign-in page, and is sent there |
| **304** | the client already has the static file it's asking for |

### A.3 Client errors

| Status | Message | When |
|---|---|---|
| **400** | `Missing or invalid input: <fields>` | a required input is missing or invalid |
| | `Password sent in the query string` | a password came in the URL |
| | `Invalid userName or passWord`, `Invalid userName, sentCode or passWord`, `Invalid userName or userMail`, `Invalid oldValue or passWord`, `Invalid userName` | an input of an `Auth` or `Users` action isn't text |
| | `Password too short: …`, `Password too long: …`, `Password holding a control character`, `Password identical to the user name`, `Password identical to the current one` | a new password breaks a rule |
| | `Invalid user name`, `Invalid mail address`, `Invalid role`, `Invalid status`, `Invalid language`, `Invalid filter` | a value given to the `Users` run isn't a valid one |
| **401** | `Invalid credentials` | a wrong name or password at sign-in, or an inactive account |
| | `Verification code required`, `Invalid verification code` | the two-factor code is missing or wrong |
| | `Invalid token`, `Expired token` | the token is forged or has expired |
| | `Account not active` | the account behind a session or token was locked in the meantime |
| | `Invalid activation`, `Invalid code` | a wrong name or code at activation or recovery, or an expired code |
| | `Invalid API key`, `No API key provided`, `Invalid app key`, `Not logged in`, `No active session`, `Missing Bearer Authorization header`, … | the credential the profile asks for is missing or wrong |
| **403** | `Forbidden` | given by an action itself (`stopThisAction(403, …)`); the framework never answers it: what a user, an application key or a command-line call can't reach is a 404 |
| **404** | `Not Found` | nothing answers the URL; the application, action, file or page isn't there; a run with its own routes is called by its plain path; the role isn't held (by a user, an application key or a command-line call); an application key reaches a route it doesn't list; a feature is switched off (sign-ups, forgotten passwords) |
| | `User not found` | the `Users` run is asked for a user that doesn't exist |
| **405** | `Method Not Allowed` | the route or action doesn't take that method; the `Allow` header lists the ones it does |
| **409** | `User name already taken`, `Mail address already used` | the name or address belongs to another account |
| | `A manager cannot do this to its own account` | managers lock, reset or remove their own account, or change their own role |
| | `The last active manager cannot be locked` (… `removed`, `reset`, `given another role`) | the service would be left without a manager |
| | `A locked user is unlocked before it is reset`, `A pending user is neither locked nor unlocked`, `A user in review is approved or removed`, `User not awaiting review`, `User already …`, `No reset for this user` | the change doesn't fit the account's status |
| **413** | `Payload Too Large` | the body is bigger than the service accepts |
| **429** | `Too many attempts` | the address is held after too many wrong attempts, or asked too often (sign-ups, resets); `Retry-After` says how long to wait |

### A.4 Service errors

| Status | Message | When |
|---|---|---|
| **500** | `Internal Server Error` | an error in the code, with no detail in production |
| | `Access misconfigured` | the access settings can't be applied |
| | `Login unavailable`, `User management unavailable`, `Credential store unavailable`, `User cannot be written` | the user store can't be reached or written |
| **501** | `Tokens are issued by another server` | the service only checks tokens, and issues none |
| **503** | `Sign-ups closed for now` | too many sign-ups are waiting for review |

---
