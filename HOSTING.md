# Hosting nyuRuns

What the person who puts a nyuRuns project on a server has to set, and the
limits to know before real users come. It goes with the security review of
version 1.0.

## 1. Purpose and split of duties

The framework handles a request from the moment PHP receives it: routing,
access, inputs, the reply. Everything before that belongs to the host, and the
framework never edits it:

- the configuration of the web server (virtual host, TLS, client certificates);
- the firewall and what can reach the PHP server;
- `php.ini`;
- the reverse proxy or CDN, when there is one.

The framework's own settings are in `conf/` and `.env`, and are read and
written with `php nyuRun settings`.

## 2. Going live

```bash
php nyuRun installs allSteps
```

It checks the server, creates the local files from their examples (`.env`,
`conf/access.json`, `conf/database.json`), makes the secrets that are still
empty, creates the users, and ends on the health check. It asks the name of
each basic account, one per role (`teammate`, `engineer` and `sysAgent` are
proposed; `--noPrompt` takes them). Run again, it replaces nothing that exists.

```bash
php nyuRun installs checkAll
```

Each row is `fine`, `warn` (deserves a look) or `fail` (requests cannot be
served, or are exposed). The command exits with a failure code when a row is a
`fail`, so a deployment script can stop on it.

Before real users come:

- Set `ENV_NAME = PROD` in `conf/system.ini`. In PROD nothing of an error is
  shown to a client, whatever `ERR_SHOW` says.
- Keep `.env` readable by its owner only (`chmod 600 .env`). It holds every
  secret; the config files only name them (`env:NAME`).
- Serve the `www` folder and nothing else. The project carries an `.htaccess`
  at its root and in every folder outside `www` that denies everything, and
  one in `www` that sends every path to `index.php`. Apache reads them only
  when `AllowOverride` lets it (`All`, or `FileInfo Options AuthConfig Limit`).
  nginx never reads them: its config below is what protects the project there.
- Remove from `www` what your site does not use.
- Run PHP 8.2 at least, in a release that fixes php2shell (CVE-2026-109618,
  CVE-2026-109619): 8.2.35, 8.3.36, 8.4.27, 8.5.12 or later. PHP 8.0 and 8.1
  have no release fixing it. A distribution that backports the fix keeps the
  number of its release, so the check gives a `warn` (`php patch`), not a
  `fail`: ask the host whether the fix is in.
- Upload progress must be off (`session.upload_progress.enabled = 0`): it
  closes CVE-2026-115560 until PHP fixes it, and the framework does not use it.
  With `mod_php`, `www/.htaccess` turns it off. With PHP-FPM, set it in
  `php.ini` or in a `.user.ini` in `www`. It cannot be set at run time. The
  check warns while it is on (`upload progress`).

### Web server

The route is read from the whole path of the address (`REQUEST_URI`): the
web server sends every path to `www/index.php`, and the project is served
at the root of its host, not in a sub-folder of another site.

Apache, with `www` as the `DocumentRoot`:

```apache
<Directory /var/www/project/www>
    AllowOverride None
    Require all granted
    FallbackResource /index.php
</Directory>
```

With `AllowOverride None`, as above, the `.htaccess` files are not read: the
`<Directory>` block and `FallbackResource` do their work.

nginx, with PHP-FPM:

```nginx
root /var/www/project/www;

location / {
    try_files $uri /index.php$is_args$args;
}

location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

Keep no other `.php` file in `www`: Apache would run it, and the nginx
setup above would send its source as a file.

## 3. Reverse proxy

Without a proxy, leave `PRX_LIST` empty: the address of a client is the one of
its connection, and no header is read.

Behind a proxy, a load balancer or a CDN, three keys of `conf/system.ini` tell
the framework whom to believe:

```ini
; Addresses or CIDR ranges of your proxies
PRX_LIST = "10.0.0.5, 192.168.0.0/24, fd00::/8"
; Header in which they give the address of the client
PRX_HEAD = X-Forwarded-For
; Header in which they give the scheme the client used
PRX_SCHM = X-Forwarded-Proto
```

Only a request whose connection comes from an address of `PRX_LIST` has those
headers read. The address header is read from right to left, and the first
address that is not one of your proxies is the client.

On the proxy itself, with nginx:

```nginx
location / {
    proxy_pass http://10.0.0.20:8080;
    proxy_set_header X-Forwarded-For   $remote_addr;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

With Apache (`mod_proxy` adds `X-Forwarded-For` by itself):

```apache
ProxyPass        / http://10.0.0.20:8080/
ProxyPassReverse / http://10.0.0.20:8080/
RequestHeader set X-Forwarded-Proto "https"
```

Rules:

- List only proxies you operate. Never a whole range of a provider you do not
  control, and never `0.0.0.0/0`: any client could then give itself the address
  it wants. The health check fails on it.
- The proxy must write the header itself, or add to it. One that passes the
  client's own header through untouched lets the client choose its address.
- The PHP server must not be reachable around the proxy: bind it to the private
  interface or close it at the firewall. Otherwise listing the proxy protects
  nothing.
- A CDN publishes its ranges, and they change. The list needs upkeep.

The client address matters: the limit on wrong credentials (section 5) and the
access log use it. A wrong `PRX_LIST` makes every client one address, or lets a
client pick its own.

Not understood by the framework:

- An address written with its port (`203.0.113.7:51234`), or the `Forwarded`
  header of RFC 7239. The address then falls back to the one of the connection.
  Configure the proxy to send a plain address in `X-Forwarded-For`.
- `X-Forwarded-Host`. The name of the site comes from the web server's own
  configuration, so set the server name on the PHP server to the public one.

## 4. Client certificates

The `clntCert` carrier does no TLS itself. The web server checks the certificate
during the handshake and reports the outcome in server variables; the carrier
reads them. Its block in `conf/access.json` names those variables:

```json
"clntCert": { "checkVar": "SSL_CLIENT_VERIFY", "ownerVar": "SSL_CLIENT_S_DN_CN", "issueVar": "SSL_CLIENT_I_DN_CN", "issuerId": "" }
```

- `checkVar` must hold `SUCCESS`;
- `ownerVar` gives the name of the user;
- `issueVar` gives the name of the issuer, compared with `issuerId` when one is set.

A variable whose name starts with `HTTP_` is a request header, which anyone can
send: the carrier refuses it.

Apache:

```apache
SSLCACertificateFile /etc/apache2/ca/clients-ca.pem
SSLVerifyClient      require
SSLVerifyDepth       2
SSLOptions           +StdEnvVars
```

nginx, with PHP-FPM. nginx gives the whole subject, so the common name is taken
out of it:

```nginx
map $ssl_client_s_dn $client_cn { default ""; "~(^|,)CN=(?<cn>[^,]+)" $cn; }
map $ssl_client_i_dn $issuer_cn { default ""; "~(^|,)CN=(?<cn>[^,]+)" $cn; }

server {
    ssl_client_certificate /etc/nginx/ca/clients-ca.pem;
    ssl_verify_client      on;

    location ~ \.php$ {
        fastcgi_param SSL_CLIENT_VERIFY  $ssl_client_verify;
        fastcgi_param SSL_CLIENT_S_DN_CN $client_cn;
        fastcgi_param SSL_CLIENT_I_DN_CN $issuer_cn;
        # ... the usual fastcgi settings
    }
}
```

A manual check, with and without a certificate:

```bash
curl --cert client.pem --key client.key https://your-site.example/your/route
```

```bash
curl https://your-site.example/your/route
```

The second must be refused: by the web server when it requires a certificate,
or by the framework with a 401 "No verified client certificate".

Limits:

- TLS ended by a separate proxy is not supported in 1.0. The certificate would
  only reach PHP as request headers, which the carrier refuses.
- The issuer is matched by its common name only. Trust is decided by the
  authority file given to the web server, not by this name.
- Revocation (CRL, OCSP) is the web server's to check.
- A certificate is a factor of its own: a user with a 2FA secret is let in by
  its certificate alone.

## 5. Limit by address and account holds

Wrong credentials are counted per client address. The `tryLimit` block sets it:

```json
"tryLimit": { "maxTries": 10, "holdSecs": 600, "dataPath": "temp/limits" }
```

After `maxTries` wrong credentials within `holdSecs` seconds, the address is
answered 429 with `Retry-After` for `holdSecs` seconds, whatever it sends. An
IPv6 address is counted with its whole /64 network. `maxTries` 0 turns the limit
off, which the health check reports.

```bash
php nyuRun maintain fullList
```

```bash
php nyuRun maintain freeAddr 203.0.113.7
```

The first lists the addresses counted and held, the second releases one.

To know:

- If the counters cannot be written, nothing is limited: the mistake is logged
  on `access`, and requests are answered as without a limit. Keep the folder
  writable.
- Counted: a wrong password, API key, 2FA code or activation code, a forged
  token, and a right password on an account that is not approved.
- Not counted: a token that ended, an unknown or expired session id, a request
  without credentials.
- The native userbase also holds an account after `maxTries` failures (5 by
  default, in the `nativeDb` block). Someone who knows a user name can therefore
  keep that account held by failing on purpose. The limit by address slows it
  from one address; it does not prevent it from many.
- Several attempts sent at the very same moment can pass the count of an
  account by a few. The limit by address still applies.

## 6. Password files

The `htpasswd` check reads an htpasswd file.

- bcrypt, and SHA-256 or SHA-512 crypt, are accepted.
- apr1 (Apache's MD5) is accepted too, because it is what Apache's tool writes
  by default. It is weak by today's measure: write new lines with bcrypt
  (`php nyuRun settings makeSecr htpasswd <user>` does).
- `{SHA}`, DES crypt and MD5 crypt are refused unless the block sets
  `"weakHash": true`, which the health check reports.
- The time of a refusal does not tell whether a name exists only when the
  file's hashes are bcrypt. With other formats, a careful measure could tell.

## 7. Two-factor codes

A code is accepted once: its 30-second step and those before it are closed for
that user.

The secrets are stored encrypted when the block of the store gives a key:

```json
"nativeDb": { ..., "tfaCrypt": "env:NYU_TFA_KEY" }
```

```bash
php nyuRun settings makeSecr tfaCrypt --useBlock=nativeDb
```

- Without a key of 32 characters, secrets are stored readable, with a warning
  on `access` each time one is made.
- Keep a copy of the key. If it is lost or changed, every encrypted secret is
  unreadable and those users are refused. Each then needs
  `php nyuRun accounts setTwoFa <user> --removeIt` and a new secret.
- A secret stored readable before a key was set keeps working, and is not
  encrypted until a new one is made.
- The key is not rotated in place.
- With the users file (`userFile`), the last step is not remembered, because
  requests only read that file: a code can be used again within about 90
  seconds.
- A personal API token is a factor of its own, as a certificate is: it is not
  asked for a code.

## 8. Tokens

- A token (JWT) cannot be taken back. It is accepted until it ends, unless the
  preset names a store of users (`"authWith": "nativeDb"` or `"userFile"`): the
  user is then confirmed on every request, and a lock or a role change applies
  at once.
- Keep `lifeSecs` short where there is no store.
- Replacing the secret (`php nyuRun settings makeSecr jwtToken`) ends every token
  at once.
- A client is only told "Invalid token" or "Expired token". Why a token was
  refused is in the `access` log, in `FULL` mode.

## 9. PHP sessions

```json
"phpSessn": { "cookName": "nyuSessn", "lifeSecs": 3600, "fullSecs": 43200, "sameSite": "strict", "secureOn": true, "dataPath": "" }
```

- `secureOn` true sends the cookie over HTTPS only. Keep it true outside a local
  machine; behind a proxy that ends TLS, set `PRX_SCHM` (section 3).
- `sameSite` is the only defence against a request forged from another site.
  `strict` is the safe value. With `lax`, never change anything on a GET.
- `lifeSecs` ends a session left unused; `fullSecs` ends it whatever its use.
- `dataPath` empty keeps the sessions in PHP's own folder, which other PHP
  applications of the same server may share. On a shared server, give it a
  folder of the project, such as `temp/sessns`.
- An unknown or expired session id is not counted against an address.

Credentials written in an address (`https://name:password@host/...`) are turned
into a header by the client and never reach the server's logs, but they stay in
the client's own history and scripts. The framework cannot see or prevent that.

## 10. Other sites calling in

A preset decides which sites' pages may read its replies, in its `response`
block:

```json
"corsList": ["https://app.example.org"]
```

With a list, only a listed origin is named in `Access-Control-Allow-Origin`.
Without one, the headers of the preset are sent as written, and several shipped
presets write `*`.

`*` is acceptable for an API whose clients send a key or a token in a header:
another site's page does not hold it. It is not acceptable with a session
cookie, which the browser sends by itself. The health check warns on that case.

## 11. Request sizes

```ini
REQ_SIZE = 8192
REQ_PART = 200
```

- `REQ_SIZE` is the largest body the framework reads, in KB. A larger one is
  answered 413, and is not kept in memory.
- `REQ_PART` bounds the parts of a multipart body sent with `PUT` or `PATCH`.
- PHP parses `POST` forms and uploads itself, before the framework: those are
  bounded by `php.ini` (`post_max_size`, `upload_max_filesize`,
  `max_file_uploads`, `max_input_vars`).
- Set the web server's own limit too (`client_max_body_size` in nginx,
  `LimitRequestBody` in Apache): it refuses a large body before PHP starts.

## 12. Errors and logs

- `ERR_SHOW` shows the details of an error to the client. It is ignored in
  PROD; keep it off in PREV too.
- `LOG_MODE`: `MINI` writes errors, warnings and notices; `BASE` adds info;
  `FULL` adds debug and the context of an error (its stack, without the
  arguments of the calls). `BASE` is enough in production.
- The log folder (`LOG_PATH`, `temp/logs` by default) must be writable by PHP
  and not served.
- The framework never logs a password, a code or a token. Keep
  `zend.exception_ignore_args = On` in `php.ini` in production anyway: it is
  PHP's own guard for what other code logs.

## 13. Routing notes

- A declared route that limits its methods (`mthdList`, or one target per
  method) limits that route only. The action it points to can still be reached
  with any method by its other paths: `/app/action`, a route of its Run, or
  another alias. Method limits aren't an access control, so an action that
  cares about the method checks it itself.
- A role given to a declared path in `minRoles` (`"/panel": "sysadmin"`) goes
  with the action that path points to, for every method's target and the default
  action included, whatever path reaches it. A `/path` there that no route
  declares, written exactly as the route is, makes every request of the preset
  fail with a 500, and `installs checkAll` fails on it (`access route`).
- An application is named with letters and digits only.
- A key that nothing reads in a route file is told on the `runner` log at each
  request.
- A site can answer an error with its own page: `errors/<status>.html` in the
  folder of its pages (`fileRoot`), with `%code%` and `%error%` filled in. It is
  used for a page or a static file, and for a path no route answers in a preset
  that finds its routes `manually`. A request for an action keeps the format of
  the preset; so does an unknown path in an `autopath` preset.

## 14. Applications calling for their users

Another application (a mobile app, a partner's portal) can call this project for
one of its own users. It sends two things: its key, in the header of the
`appsKeys` block, and the user's token, signed with its own private key. A
preset reads the keys when its `security` block names that block:
`"appsConf": "appsKeys"`.

```json
"appsKeys": { "headName": "X-App-Key", "appsList": { "portal": { "keyDigst": "…", "routeSet": ["contacts/*"], "publFile": "conf/keys/portal.pub", "roleTops": "internal", "madeDate": "…" } } }
```

- Keys are made, given routes and revoked from the command line only
  (`php nyuRun appsKeys makeKeys|fullList|setRoute|revokeIt`). A key is shown
  once; the file holds its SHA-256 hash. A revoked key is refused from the next
  request.
- Copy the application's public key into `conf/keys` and give its path. Never
  copy its private key: holding it would let this project forge that
  application's tokens.
- A token of the application is only valid with its key. Sent without it, it
  goes to the preset's own carrier, where it is not valid.
- The user is taken from the token, as the application issued it: this project
  does not look it up in its own store, so a lock in the application applies
  only when its token ends. Keep the application's `lifeSecs` short.
- The role of that user is never above `roleTops` (`internal` by default), and
  the role a route asks for (`minRoles`, `needsActionRle()`) still applies.
- An application only reaches its `routeSet`, written as `openList`; any other
  route is a 403. A route open to everyone ignores the key.
- A wrong key and a forged token count against the address. Every write is a
  notice on the `access` log, naming the application, the user and the route;
  a read is an info. The record itself is the action's to log.
- Until a sign-in server issues every token (version 1.1, with `iss` and
  `aud`), each application signs its own and each project it calls holds its
  public key.

## 15. Access mails

The native userbase sends its codes by mail when its block names an account of
`conf/mailer.json`. Without `mailAcnt`, nothing is sent: the codes are given in
the replies, to be handed over, as before.

```json
"nativeDb": { ..., "mailAcnt": "nyuRuns", "signupOn": false, "codeMins": 15, "linkTmpl": "https://app.example.ch/access?do=%kind%&user=%user%&code=%code%" }
```

- `codeMins` is the minutes a mailed code lasts (15 by default). A code handed
  over by a manager lasts 300 minutes; `accounts makeUser` and
  `accounts resetPwd` take `--noExpiry` for one with no end, on the command
  line only.
- `linkTmpl` is the page of the front end the mail links to. `%kind%` tells it
  which form to show: `activate` and `register` post to `auth/active`,
  `password` to `auth/recover`. Without it, the mail carries the code alone.
  The code is bound to the user, not to the device: it can be typed on another
  one.
- `signupOn: true` opens `auth/signup`. A sign-up confirms its address with
  the code, then waits in `inReview` for a manager (`users/approve`,
  `php nyuRun accounts approves`); refusing it is removing it. Sign-ups never
  confirmed are removed once their code ends.
- The `tryLimit` block bounds the sign-ups: `maxQueue` waiting at once (256),
  beyond which `auth/signup` answers 503 until some are approved or removed;
  `maxPerIp` sign-ups from one client within `holdSecs` (16), counted apart
  from its wrong credentials (an address held for those is refused too); and
  `alertsAt`, the counts at which every approved manager with an address is
  told by mail (`backlogs`) and the `access` log notes it (128, 200, 256).
- `auth/signup` and `auth/forgot` answer 202 whoever the name or address
  is. Each reset asked counts against the address of the client, as a wrong
  password does (`maxTries`). Under PHP-FPM the reply ends before the mail is
  sent, so its time tells nothing either; under another server API the client
  waits for the mail, and the time can tell whether it went.
- A reset asked by mail keeps the password until the code is used, so nobody
  can lock a user out by asking. A reset by a manager voids it at once.
- An address belongs to one account, compared in lower case. The users file
  (`userFile`) holds addresses too, but cannot send codes: it is only read.
- The mails are `conf/mails/<language>/<kind>.txt` (its first line is the
  subject) and `.html`, in English (`en`) and French (`fr`). A mail is written
  in the language of the user it goes to (`userLocale`), else in `DEF_LANG` of
  `system.ini` (`en` when not set); `fr-ch` falls back to `fr`, then to the
  default. Another language is a folder beside them. An application replaces
  them with its own in `runs/Auth/mails/<language>/`.
- A table made by 0.9 needs `conf/schema/migrate-users-1.0.<type>.sql` first,
  then `migrate-devices-1.0.<type>.sql` when it has devices (`<type>` is
  `mysql_db`, `postgres` or `sqlitedb`). They rename the columns and give the
  roles and statuses their codes of 1.0. On MySQL 8 and MariaDB, the parts 2
  and 4 of the users file (the checks of the roles and statuses) are run by
  hand.
