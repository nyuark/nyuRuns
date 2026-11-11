# Security policy

## Supported versions

Only the latest release receives security fixes.

| Version        | Security fixes |
|----------------|----------------|
| 1.0.x          | yes            |
| 0.x and before | no             |

## Reporting a vulnerability

Report it privately, by mail to dev@nyuark.com, never in a public issue,
pull request or discussion.

A useful report gives:

- the version of nyuRuns, or the commit;
- the PHP version and its server API (PHP-FPM, Apache module, CLI);
- the preset and the blocks of `conf/access.json` involved, without their
  secrets;
- the steps that show the problem, and what an attacker gains with it.

What follows:

- the report is acknowledged within 7 days;
- once the problem is confirmed, a fix is prepared and released, and the
  report is credited in the release notes, unless you would rather not be
  named;
- please keep the problem private until the fix is released, or for 90 days
  if no fix has come by then.

## What belongs to the framework

The framework handles a request from the moment PHP receives it: routing,
access, inputs, the reply. Everything before that belongs to the host: the
web server and its TLS, the firewall, `php.ini`, the reverse proxy or CDN
(`HOSTING.md`, "Purpose and split of duties").

- A vulnerability of PHP itself goes to the PHP project.
- A vulnerability of a library goes to that library's project (`NOTICE`
  lists them). Tell us too when nyuRuns exposes it.
- A setting that the health check reports as a `fail` or a `warn` is not a
  vulnerability of the framework: `php nyuRun install checkAll` (for example
  `maxTries` 0, an htpasswd file with `"weakHash": true`, `PRX_LIST` open to
  every address).

## Known limits of 1.0

These are documented in `HOSTING.md` and need no report:

- A token cannot be taken back before it ends, whoever issued it, unless the
  preset confirms its user in a store of users on every request ("Tokens").
- A user calling through another application is taken from that
  application's token: a lock in the application applies only when its token
  ends ("Applications calling for their users").
- Someone who knows a user name can keep that account held by failing on
  purpose; the limit by address slows it from one address only ("Limit by
  address and account holds").
- Attempts sent at the very same moment can pass the count of an account by
  a few (same section).
- With the users file (`userFile`), a 2FA code can be used again within about
  90 seconds ("Two-factor codes").
- A code sent by mail is only as safe as the mailbox it goes to.
- With htpasswd hashes other than bcrypt, a careful measure of time can tell
  whether a name exists ("Password files").
- Under a server API other than PHP-FPM, the time of a reply to
  `auth/forgot` or `auth/signup` can tell whether a mail went ("Access
  mails").
- Client certificates checked by a proxy that ends TLS are not supported, and
  `X-Forwarded-Host` and `Forwarded` are not read ("Reverse proxy", "Client
  certificates").

## Running it safely

Before real users come, follow `HOSTING.md`, "Going live", and run
`php nyuRun install checkAll`. In particular:

- PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later;
- `ENV_NAME = PROD` in `conf/system.ini`;
- the `www` folder served, and nothing else;
- `.env` readable by its owner only;
- `session.upload_progress.enabled = 0` in `php.ini`.
